<?php
/**
 * CSV export / import, compatible with FileBird's format.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb;

use CPH\FileBirb\Model\Assignment;
use CPH\FileBirb\Model\Folder;

defined( 'ABSPATH' ) || exit;

/**
 * Columns: id,name,parent,type,ord,created_by,attachment_ids (pipe-separated).
 * Import also accepts FileBird's optional `post_type` column and skips non-attachment rows.
 */
final class Csv {

	/**
	 * Export columns, in order.
	 */
	public const COLUMNS = array( 'id', 'name', 'parent', 'type', 'ord', 'created_by', 'attachment_ids' );

	/**
	 * Singleton instance.
	 *
	 * @var Csv|null
	 */
	private static ?Csv $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Csv
	 */
	public static function get_instance(): Csv {
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
	 * Strip leading `= + - @ |`, tab and CR so a value can't run as a spreadsheet formula (FileBird's rule).
	 *
	 * @param mixed $input Raw value.
	 * @return string
	 */
	public static function sanitize_for_excel( $input ): string {
		if ( ! is_string( $input ) || '' === $input ) {
			return '';
		}
		return ltrim( $input, "=+-@|\t\r" );
	}

	/**
	 * Export every folder and its attachment IDs as CSV text.
	 *
	 * @return string
	 */
	public function export(): string {
		global $wpdb;

		$attachments = array();
		$rows        = $wpdb->get_results( "SELECT folder_id, attachment_id FROM {$wpdb->prefix}fbv_attachment_folder ORDER BY attachment_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$attachments[ (int) $row->folder_id ][] = (int) $row->attachment_id;
		}

		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, self::COLUMNS, ',', '"', '\\' );
		foreach ( Folder::get_instance()->all() as $folder ) {
			fputcsv(
				$handle,
				array(
					$folder->id,
					self::sanitize_for_excel( $folder->name ),
					$folder->parent,
					$folder->type,
					$folder->ord,
					$folder->created_by,
					implode( '|', $attachments[ $folder->id ] ?? array() ),
				),
				',',
				'"',
				'\\'
			);
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * Import CSV text. Folders merge by name under the same parent (like FileBird), old IDs
	 * are remapped, hierarchy and sibling order are kept. Missing attachments are skipped.
	 *
	 * @param string $csv CSV text.
	 * @return array|\WP_Error `[ 'folders' => int, 'assignments' => int ]`.
	 */
	public function import( string $csv ): array|\WP_Error {
		global $wpdb;

		if ( strlen( $csv ) > self::max_bytes() ) {
			return new \WP_Error( 'invalid_csv_size', __( 'That CSV file is too large.', 'cph-filebirb' ), array( 'status' => 413 ) );
		}

		$csv    = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, (string) $csv );
		rewind( $handle );

		$columns = fgetcsv( $handle, 0, ',', '"', '\\' );
		if ( ! is_array( $columns ) ) {
			fclose( $handle );
			return $this->invalid();
		}
		$columns = array_map( 'trim', $columns );
		$allowed = array_merge( self::COLUMNS, array( 'post_type' ) );
		if ( array_diff( $columns, $allowed ) || array_diff( array( 'id', 'name', 'parent' ), $columns ) ) {
			fclose( $handle );
			return $this->invalid();
		}

		$rows = array();
		while ( ( $line = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
			if ( array( null ) === $line ) {
				continue;
			}
			$row = array();
			foreach ( $columns as $i => $col ) {
				$row[ $col ] = $line[ $i ] ?? '';
			}
			if ( '' !== ( $row['post_type'] ?? '' ) && 'attachment' !== $row['post_type'] ) {
				continue;
			}
			$rows[ (int) $row['id'] ] = $row;
		}
		fclose( $handle );

		// Children by old parent; rows whose parent isn't in the file go to the root.
		$children = array();
		foreach ( $rows as $old_id => $row ) {
			$parent = (int) $row['parent'];
			if ( $parent === $old_id || ! isset( $rows[ $parent ] ) ) {
				$parent = 0;
			}
			$children[ $parent ][] = $old_id;
		}
		foreach ( $children as &$list ) {
			usort( $list, static fn( $a, $b ) => ( (int) ( $rows[ $a ]['ord'] ?? 0 ) <=> (int) ( $rows[ $b ]['ord'] ?? 0 ) ) ?: $a <=> $b ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- falls back to ID order on equal ord.
		}
		unset( $list );

		$all_ids = array();
		foreach ( $rows as $row ) {
			foreach ( explode( '|', (string) ( $row['attachment_ids'] ?? '' ) ) as $id ) {
				if ( (int) $id > 0 ) {
					$all_ids[] = (int) $id;
				}
			}
		}
		$existing = array();
		if ( $all_ids ) {
			$all_ids      = array_values( array_unique( $all_ids ) );
			$placeholders = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
			$existing     = array_flip(
				array_map(
					'intval',
					(array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN ({$placeholders})", $all_ids ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from $wpdb->prefix; placeholders built per ID.
				)
			);
		}

		$folders     = Folder::get_instance();
		$assignments = Assignment::get_instance();
		$counts      = array(
			'folders'     => 0,
			'assignments' => 0,
		);
		$queue       = array( array( 0, 0 ) ); // [ old parent, new parent ].
		$seen        = array();

		while ( $queue ) {
			[ $old_parent, $new_parent ] = array_shift( $queue );
			foreach ( $children[ $old_parent ] ?? array() as $old_id ) {
				if ( isset( $seen[ $old_id ] ) ) {
					continue;
				}
				$seen[ $old_id ] = true;

				$new_id = $folders->get_or_create( (string) $rows[ $old_id ]['name'], $new_parent );
				if ( is_wp_error( $new_id ) ) {
					continue;
				}
				++$counts['folders'];

				$ids = array();
				foreach ( explode( '|', (string) ( $rows[ $old_id ]['attachment_ids'] ?? '' ) ) as $id ) {
					if ( isset( $existing[ (int) $id ] ) ) {
						$ids[] = (int) $id;
					}
				}
				if ( $ids && true === $assignments->assign( $new_id, $ids ) ) {
					$counts['assignments'] += count( $ids );
				}

				$queue[] = array( $old_id, $new_id );
			}
		}

		return $counts;
	}

	/**
	 * Largest CSV accepted for import, in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes(): int {
		/**
		 * Filters the largest CSV import accepted, in bytes. Default 10 MB.
		 *
		 * @param int $bytes Maximum size.
		 */
		return (int) apply_filters( 'cphfb_csv_import_max_bytes', 10 * MB_IN_BYTES );
	}

	/**
	 * Invalid-file error.
	 *
	 * @return \WP_Error
	 */
	private function invalid(): \WP_Error {
		return new \WP_Error( 'invalid_csv', __( 'This file is not a FileBird or CPH FileBirb folder export.', 'cph-filebirb' ), array( 'status' => 400 ) );
	}
}
