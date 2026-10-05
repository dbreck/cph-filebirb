<?php
/**
 * WP-CLI `wp cphfb folder` subcommands.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Cli;

use CPH\FileBird\Model\Assignment;
use CPH\FileBird\Model\Folder;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Folder CRUD from the command line. All logic lives in Model\Folder.
 */
final class FolderCommand {

	/**
	 * Singleton instance.
	 *
	 * @var FolderCommand|null
	 */
	private static ?FolderCommand $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return FolderCommand
	 */
	public static function get_instance(): FolderCommand {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Register `wp cphfb folder …`. Called by Commands.
	 *
	 * @return void
	 */
	public function register(): void {
		$commands = array(
			'list'      => 'list_folders',
			'create'    => 'create',
			'rename'    => 'rename',
			'delete'    => 'delete',
			'move'      => 'move',
			'duplicate' => 'duplicate',
		);
		foreach ( $commands as $name => $method ) {
			WP_CLI::add_command( 'cphfb folder ' . $name, array( $this, $method ) );
		}
	}

	/**
	 * List folders.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. `ids` prints space-separated folder IDs.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * [--tree]
	 * : Indent names by depth (depth-first order).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb folder list --tree
	 *     wp cphfb folder list --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function list_folders( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$tree   = (bool) Utils\get_flag_value( $assoc_args, 'tree', false );
		$nodes  = Folder::get_instance()->flat();

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', array_column( $nodes, 'id' ) ) );
			return;
		}

		$assignments = Assignment::get_instance();
		$assignments->invalidate_counts();
		$counts = $assignments->counts()['folders'];

		$items = array();
		foreach ( $nodes as $node ) {
			$items[] = array(
				'id'     => $node['id'],
				'name'   => $tree ? str_repeat( '  ', $node['depth'] ) . $node['name'] : $node['name'],
				'parent' => $node['parent'],
				'ord'    => $node['ord'],
				'count'  => $counts[ $node['id'] ] ?? 0,
			);
		}

		Utils\format_items( $format, $items, array( 'id', 'name', 'parent', 'ord', 'count' ) );
	}

	/**
	 * Create a folder. A sibling with the same name gets " (1)" appended.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Folder name.
	 *
	 * [--parent=<id>]
	 * : Parent folder ID. Default 0 (root).
	 *
	 * [--porcelain]
	 * : Print only the new folder ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb folder create Branding
	 *     wp cphfb folder create Logos --parent=12 --porcelain
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function create( array $args, array $assoc_args ): void {
		$node = Commands::check( Folder::get_instance()->create( (string) $args[0], (int) ( $assoc_args['parent'] ?? 0 ) ) );
		if ( Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( (string) $node['id'] );
			return;
		}
		WP_CLI::success( sprintf( 'Created folder %d "%s".', $node['id'], $node['name'] ) );
	}

	/**
	 * Rename a folder.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Folder ID.
	 *
	 * <name>
	 * : New name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb folder rename 12 "Brand Assets"
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function rename( array $args, array $assoc_args ): void {
		Commands::check( Folder::get_instance()->rename( (int) $args[0], (string) $args[1] ) );
		WP_CLI::success( sprintf( 'Renamed folder %d.', (int) $args[0] ) );
	}

	/**
	 * Delete a folder. Attachments in deleted folders become uncategorized.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Folder ID.
	 *
	 * [--mode=<mode>]
	 * : `subtree` deletes the folder and its descendants; `children-up` moves its
	 * direct children up to its parent first.
	 * ---
	 * default: subtree
	 * options:
	 *   - subtree
	 *   - children-up
	 * ---
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb folder delete 12 --yes
	 *     wp cphfb folder delete 12 --mode=children-up
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function delete( array $args, array $assoc_args ): void {
		$id     = (int) $args[0];
		$mode   = $assoc_args['mode'] ?? 'subtree';
		$folder = Folder::get_instance()->get( $id );
		if ( ! $folder ) {
			WP_CLI::error( sprintf( 'Folder %d not found.', $id ) );
		}

		$what = 'subtree' === $mode ? 'and all its subfolders' : 'and move its subfolders up';
		WP_CLI::confirm( sprintf( 'Delete folder %d "%s" %s?', $id, $folder->name, $what ), $assoc_args );

		Commands::check( Folder::get_instance()->delete( $id, $mode ) );
		WP_CLI::success( sprintf( 'Deleted folder %d.', $id ) );
	}

	/**
	 * Move a folder under a new parent.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Folder ID.
	 *
	 * <parent>
	 * : New parent folder ID (0 = root).
	 *
	 * [--ord=<n>]
	 * : Position among its new siblings. Default: last.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb folder move 14 12
	 *     wp cphfb folder move 14 0 --ord=0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function move( array $args, array $assoc_args ): void {
		$ord = isset( $assoc_args['ord'] ) ? (int) $assoc_args['ord'] : null;
		Commands::check( Folder::get_instance()->move( (int) $args[0], (int) $args[1], $ord ) );
		WP_CLI::success( sprintf( 'Moved folder %d under %d.', (int) $args[0], (int) $args[1] ) );
	}

	/**
	 * Duplicate a folder and its subfolders (not its attachments).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Folder ID.
	 *
	 * [--porcelain]
	 * : Print only the new folder ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cphfb folder duplicate 12
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function duplicate( array $args, array $assoc_args ): void {
		$node = Commands::check( Folder::get_instance()->duplicate( (int) $args[0] ) );
		if ( Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( (string) $node['id'] );
			return;
		}
		WP_CLI::success( sprintf( 'Duplicated folder %d as %d "%s".', (int) $args[0], $node['id'], $node['name'] ) );
	}
}
