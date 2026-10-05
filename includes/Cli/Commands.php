<?php
/**
 * WP-CLI root command: `wp cphfb`.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Cli;

use CPH\FileBird\Csv;
use CPH\FileBird\Install;
use CPH\FileBird\Model\Assignment;
use CPH\FileBird\Model\Folder;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level `wp cphfb` subcommands. Folder CRUD lives in FolderCommand (`wp cphfb folder`).
 *
 * Subcommands are registered one callable at a time so `get_instance()` never shows up as a command.
 */
final class Commands {

	/**
	 * FileBird's schema: table suffix => [ column => [ type, nullable ] ].
	 *
	 * Integer display widths are dropped before comparing, since MySQL 8 no longer reports them.
	 */
	public const SCHEMA = array(
		'fbv'                   => array(
			'id'         => array( 'int unsigned', 'NO' ),
			'name'       => array( 'varchar(250)', 'NO' ),
			'parent'     => array( 'int', 'NO' ),
			'type'       => array( 'int', 'NO' ),
			'ord'        => array( 'int', 'YES' ),
			'created_by' => array( 'int', 'YES' ),
		),
		'fbv_attachment_folder' => array(
			'folder_id'     => array( 'int unsigned', 'NO' ),
			'attachment_id' => array( 'bigint unsigned', 'NO' ),
		),
	);

	/**
	 * Keys written by `verify --snapshot` and checked by `verify --compare`.
	 */
	private const SNAPSHOT_KEYS = array( 'folders', 'assignment_rows', 'assigned_attachments' );

	/**
	 * Singleton instance.
	 *
	 * @var Commands|null
	 */
	private static ?Commands $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Commands
	 */
	public static function get_instance(): Commands {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers the commands.
	 */
	private function __construct() {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		$commands = array(
			'assign'          => 'assign',
			'counts'          => 'counts',
			'export-csv'      => 'export_csv',
			'import-csv'      => 'import_csv',
			'import-filebird' => 'import_filebird',
			'verify'          => 'verify',
			'cleanup'         => 'cleanup',
		);
		foreach ( $commands as $name => $method ) {
			WP_CLI::add_command( 'cphfb ' . $name, array( $this, $method ) );
		}

		FolderCommand::get_instance()->register();
	}

	/**
	 * Put attachments in a folder, replacing any folder they were in.
	 *
	 * ## OPTIONS
	 *
	 * <folder>
	 * : Folder ID, or a path from the root like `Branding/Logos`. `0` unassigns.
	 *
	 * <ids>...
	 * : Attachment IDs.
	 *
	 * [--create]
	 * : Create missing folders in a path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb assign 12 101 102
	 *     wp cphfb assign "Branding/Logos" 101 --create
	 *     wp cphfb assign 0 101
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function assign( array $args, array $assoc_args ): void {
		$target    = (string) array_shift( $args );
		$folder_id = self::resolve_folder( $target, (bool) Utils\get_flag_value( $assoc_args, 'create', false ) );
		$ids       = array_values( array_filter( array_map( 'intval', $args ), static fn( $id ) => $id > 0 ) );

		$attachments = array();
		foreach ( $ids as $id ) {
			if ( 'attachment' === get_post_type( $id ) ) {
				$attachments[] = $id;
			} else {
				WP_CLI::warning( sprintf( 'Skipping %d: not an attachment.', $id ) );
			}
		}
		if ( empty( $attachments ) ) {
			WP_CLI::error( 'No valid attachment IDs given.' );
		}

		self::check( Assignment::get_instance()->assign( $folder_id, $attachments ) );

		if ( 0 === $folder_id ) {
			WP_CLI::success( sprintf( 'Unassigned %d attachment(s).', count( $attachments ) ) );
		} else {
			WP_CLI::success( sprintf( 'Assigned %d attachment(s) to folder %d.', count( $attachments ), $folder_id ) );
		}
	}

	/**
	 * Show attachment counts per folder, plus All and Uncategorized.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb counts
	 *     wp cphfb counts --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function counts( array $args, array $assoc_args ): void {
		$assignments = Assignment::get_instance();
		$assignments->invalidate_counts();
		$counts = $assignments->counts();

		$items = array(
			array(
				'id'    => Folder::ALL,
				'name'  => 'All',
				'count' => $counts['all'],
			),
			array(
				'id'    => Folder::UNCATEGORIZED,
				'name'  => 'Uncategorized',
				'count' => $counts['uncategorized'],
			),
		);
		foreach ( Folder::get_instance()->flat() as $node ) {
			$items[] = array(
				'id'    => $node['id'],
				'name'  => $node['name'],
				'count' => $counts['folders'][ $node['id'] ] ?? 0,
			);
		}

		Utils\format_items( $assoc_args['format'] ?? 'table', $items, array( 'id', 'name', 'count' ) );
	}

	/**
	 * Export folders and assignments as CSV (FileBird-compatible).
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : File to write. Prints to stdout when omitted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb export-csv folders.csv
	 *     wp cphfb export-csv > folders.csv
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function export_csv( array $args, array $assoc_args ): void {
		$csv = Csv::get_instance()->export();
		if ( empty( $args[0] ) ) {
			WP_CLI::line( rtrim( $csv, "\n" ) );
			return;
		}
		if ( false === file_put_contents( $args[0], $csv ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			WP_CLI::error( sprintf( 'Could not write %s.', $args[0] ) );
		}
		WP_CLI::success( sprintf( 'Exported to %s.', $args[0] ) );
	}

	/**
	 * Import a FileBird or CPH FileBird CSV export. Folders merge by name under the same parent.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file to read.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb import-csv folders.csv
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function import_csv( array $args, array $assoc_args ): void {
		if ( ! is_readable( $args[0] ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $args[0] ) );
		}
		$result = self::check( Csv::get_instance()->import( (string) file_get_contents( $args[0] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		WP_CLI::success( sprintf( 'Imported %d folder(s) and %d assignment(s).', $result['folders'], $result['assignments'] ) );
	}

	/**
	 * Check FileBird's tables and seed settings. A no-op for data, since the tables are shared.
	 *
	 * Exists so a future schema change has a home. Today it confirms the tables and columns
	 * match FileBird's, reports what's in them, and seeds `cphfb_settings` from `fbv_settings`
	 * if that hasn't happened yet.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb import-filebird
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function import_filebird( array $args, array $assoc_args ): void {
		$problems = self::schema_problems();
		if ( $problems ) {
			foreach ( $problems as $problem ) {
				WP_CLI::warning( $problem );
			}
			WP_CLI::error( 'FileBird tables are missing or do not match the expected schema. Nothing imported.' );
		}

		Install::seed_settings();

		$stats = self::stats();
		WP_CLI::log( sprintf( 'Folders: %d', $stats['folders'] ) );
		WP_CLI::log( sprintf( 'Assignment rows: %d', $stats['assignment_rows'] ) );
		WP_CLI::success( 'Tables already match FileBird. Nothing to import; settings seeded.' );
	}

	/**
	 * Cutover check: schema, counts, and data oddities.
	 *
	 * Exits 1 if a table is missing or its columns don't match FileBird's schema, or if
	 * `--compare` finds a count mismatch. Data oddities (orphan rows, missing parents,
	 * multi-folder attachments) are warnings and exit 0.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--snapshot=<file>]
	 * : Write folder / assignment counts to this file as JSON.
	 *
	 * [--compare=<file>]
	 * : Compare counts against an earlier snapshot; exit 1 on mismatch.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb verify --snapshot=pre.json
	 *     wp cphfb verify --compare=pre.json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function verify( array $args, array $assoc_args ): void {
		$format   = $assoc_args['format'] ?? 'table';
		$problems = self::schema_problems();
		if ( $problems ) {
			foreach ( $problems as $problem ) {
				WP_CLI::warning( $problem );
			}
			WP_CLI::error( 'Schema check failed.' );
		}

		$stats = self::stats();

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $stats + array( 'schema' => 'ok' ), JSON_PRETTY_PRINT ) );
		} else {
			$items = array(
				array(
					'check' => 'schema',
					'value' => 'ok',
				),
			);
			foreach ( $stats as $key => $value ) {
				$items[] = array(
					'check' => $key,
					'value' => $value,
				);
			}
			Utils\format_items( 'table', $items, array( 'check', 'value' ) );
		}

		$oddities = array(
			'orphan_rows'            => 'assignment row(s) point at a missing folder or attachment. Run `wp cphfb cleanup`.',
			'missing_parent_folders' => 'folder(s) have a parent that does not exist (shown at the root).',
			'multi_folder'           => 'attachment(s) are in more than one folder.',
		);
		foreach ( $oddities as $key => $message ) {
			if ( $stats[ $key ] > 0 ) {
				WP_CLI::warning( $stats[ $key ] . ' ' . $message );
			}
		}

		$snapshot = array_intersect_key( $stats, array_flip( self::SNAPSHOT_KEYS ) );

		if ( ! empty( $assoc_args['snapshot'] ) ) {
			if ( false === file_put_contents( $assoc_args['snapshot'], (string) wp_json_encode( $snapshot, JSON_PRETTY_PRINT ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				WP_CLI::error( sprintf( 'Could not write %s.', $assoc_args['snapshot'] ) );
			}
			WP_CLI::log( sprintf( 'Snapshot written to %s.', $assoc_args['snapshot'] ) );
		}

		if ( ! empty( $assoc_args['compare'] ) ) {
			$file = $assoc_args['compare'];
			$old  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $old ) ) {
				WP_CLI::error( sprintf( 'Cannot read snapshot %s.', $file ) );
			}
			$mismatch = false;
			foreach ( self::SNAPSHOT_KEYS as $key ) {
				$before = (int) ( $old[ $key ] ?? -1 );
				if ( $before !== $snapshot[ $key ] ) {
					WP_CLI::warning( sprintf( '%s: was %d, now %d.', $key, $before, $snapshot[ $key ] ) );
					$mismatch = true;
				}
			}
			if ( $mismatch ) {
				WP_CLI::error( 'Counts do not match the snapshot.' );
			}
			WP_CLI::success( 'Counts match the snapshot.' );
			return;
		}

		WP_CLI::success( 'Verify complete.' );
	}

	/**
	 * Remove assignment rows whose folder or attachment no longer exists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb cleanup
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$removed = Assignment::get_instance()->cleanup_orphans();
		WP_CLI::success( sprintf( 'Removed %d orphaned row(s).', $removed ) );
	}

	/**
	 * Resolve a folder ID or `A/B/C` path to an ID. Errors out if not found.
	 *
	 * @param string $target ID or path.
	 * @param bool   $create Create missing path segments.
	 * @return int
	 */
	public static function resolve_folder( string $target, bool $create = false ): int {
		$folders = Folder::get_instance();

		if ( preg_match( '/^\d+$/', $target ) ) {
			$id = (int) $target;
			if ( $id > 0 && ! $folders->exists( $id ) ) {
				WP_CLI::error( sprintf( 'Folder %d not found.', $id ) );
			}
			return $id;
		}

		$parent = 0;
		foreach ( array_filter( array_map( 'trim', explode( '/', $target ) ), 'strlen' ) as $segment ) {
			$found = null;
			foreach ( $folders->all() as $row ) {
				if ( $row->parent === $parent && $row->name === $folders->sanitize_name( $segment ) ) {
					$found = $row->id;
					break;
				}
			}
			if ( null === $found ) {
				if ( ! $create ) {
					WP_CLI::error( sprintf( 'Folder "%s" not found in path "%s". Use --create to make it.', $segment, $target ) );
				}
				$found = self::check( $folders->get_or_create( $segment, $parent ) );
			}
			$parent = (int) $found;
		}

		if ( 0 === $parent ) {
			WP_CLI::error( sprintf( 'Invalid folder "%s".', $target ) );
		}
		return $parent;
	}

	/**
	 * Return the value, or exit with the WP_Error message.
	 *
	 * @param mixed $result Model result.
	 * @return mixed
	 */
	public static function check( $result ) {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result );
		}
		return $result;
	}

	/**
	 * Differences between the live tables and FileBird's schema. Empty when they match.
	 *
	 * @return string[]
	 */
	private static function schema_problems(): array {
		global $wpdb;

		$problems = array();
		foreach ( self::SCHEMA as $suffix => $expected ) {
			$table = $wpdb->prefix . $suffix;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				$problems[] = sprintf( 'Table %s is missing.', $table );
				continue;
			}

			$actual = array();
			foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ) as $column ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type                      = preg_replace( '/^((?:big|tiny|small|medium)?int)\(\d+\)/', '$1', strtolower( (string) $column->Type ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$actual[ $column->Field ] = array( $type, $column->Null ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}

			if ( array_keys( $actual ) !== array_keys( $expected ) ) {
				$problems[] = sprintf( 'Table %s has columns (%s), expected (%s).', $table, implode( ', ', array_keys( $actual ) ), implode( ', ', array_keys( $expected ) ) );
				continue;
			}
			foreach ( $expected as $name => $definition ) {
				if ( $actual[ $name ] !== $definition ) {
					$problems[] = sprintf( 'Column %s.%s is %s %s, expected %s %s.', $table, $name, $actual[ $name ][0], 'NO' === $actual[ $name ][1] ? 'NOT NULL' : 'NULL', $definition[0], 'NO' === $definition[1] ? 'NOT NULL' : 'NULL' );
				}
			}
		}
		return $problems;
	}

	/**
	 * Counts used by verify and import-filebird.
	 *
	 * @return array<string,int>
	 */
	private static function stats(): array {
		global $wpdb;

		$fbv = $wpdb->prefix . 'fbv';
		$af  = $wpdb->prefix . 'fbv_attachment_folder';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'folders'                => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$fbv}" ),
			'assignment_rows'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$af}" ),
			'assigned_attachments'   => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT attachment_id) FROM {$af}" ),
			'orphan_rows'            => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$af} AS af LEFT JOIN {$fbv} AS f ON f.id = af.folder_id LEFT JOIN {$wpdb->posts} AS p ON p.ID = af.attachment_id AND p.post_type = 'attachment' WHERE f.id IS NULL OR p.ID IS NULL" ),
			'missing_parent_folders' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$fbv} AS f LEFT JOIN {$fbv} AS pf ON pf.id = f.parent WHERE f.parent <> 0 AND pf.id IS NULL" ),
			'multi_folder'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT attachment_id FROM {$af} GROUP BY attachment_id HAVING COUNT(*) > 1 ) AS m" ),
		);
		// phpcs:enable
	}
}
