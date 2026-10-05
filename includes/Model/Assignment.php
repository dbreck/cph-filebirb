<?php
/**
 * Attachment ↔ folder assignments over `{prefix}fbv_attachment_folder`.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Model;

use CPH\FileBirb\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * An attachment lives in at most one folder. Assigning replaces existing rows.
 */
final class Assignment {

	/**
	 * Transient caching counts().
	 */
	public const COUNTS_TRANSIENT = 'cphfb_counts';

	/**
	 * Singleton instance.
	 *
	 * @var Assignment|null
	 */
	private static ?Assignment $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Assignment
	 */
	public static function get_instance(): Assignment {
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
	 * Relation table name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fbv_attachment_folder';
	}

	/**
	 * Put attachments in a folder, replacing any existing assignment. Folder 0 unassigns.
	 *
	 * @param int   $folder_id      Folder ID (0 = uncategorized).
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return bool|\WP_Error True on success; `folder_not_found` if the folder is missing.
	 */
	public function assign( int $folder_id, array $attachment_ids ): bool|\WP_Error {
		global $wpdb;

		if ( $folder_id < 0 || ( $folder_id > 0 && ! Folder::get_instance()->exists( $folder_id ) ) ) {
			return new \WP_Error( 'folder_not_found', __( 'Folder not found.', 'cph-filebirb' ), array( 'status' => 404 ) );
		}

		$ids = $this->clean_ids( $attachment_ids );
		$ids = $this->clean_ids( (array) Hooks::filter( 'ids_assigned_to_folder', $ids ) );
		if ( empty( $ids ) ) {
			return true;
		}

		foreach ( $ids as $id ) {
			Hooks::action( 'before_setting_folder', $id, $folder_id );
		}

		$table        = $this->table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE attachment_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from $wpdb->prefix; placeholders built per ID.

		if ( $folder_id > 0 ) {
			$values = array();
			foreach ( $ids as $id ) {
				array_push( $values, $folder_id, $id );
			}
			$rows = implode( ',', array_fill( 0, count( $ids ), '(%d,%d)' ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (folder_id, attachment_id) VALUES {$rows}", $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from $wpdb->prefix; placeholders built per ID.
		}

		foreach ( $ids as $id ) {
			Hooks::action( 'after_set_folder', $id, $folder_id );
		}
		Hooks::action( 'after_assign_folder', $folder_id, $ids );

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
		$this->invalidate_counts();

		return true;
	}

	/**
	 * Remove attachments from any folder.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return bool Always true.
	 */
	public function unassign( array $attachment_ids ): bool {
		$this->assign( 0, $attachment_ids );
		return true;
	}

	/**
	 * Folder ID of one attachment (0 if none).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	public function get_folder_id( int $attachment_id ): int {
		return $this->get_folder_ids_for( array( $attachment_id ) )[ $attachment_id ] ?? 0;
	}

	/**
	 * Folder IDs for many attachments in one query.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return array<int,int> attachment ID => folder ID (0 if none).
	 */
	public function get_folder_ids_for( array $attachment_ids ): array {
		global $wpdb;

		$ids = $this->clean_ids( $attachment_ids );
		if ( empty( $ids ) ) {
			return array();
		}
		$out          = array_fill_keys( $ids, 0 );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fbva.attachment_id, MIN(fbva.folder_id) AS folder_id FROM {$this->table()} AS fbva INNER JOIN {$wpdb->prefix}fbv AS fbv ON fbv.id = fbva.folder_id WHERE fbva.attachment_id IN ({$placeholders}) GROUP BY fbva.attachment_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from $wpdb->prefix; placeholders built per ID.
				$ids
			)
		);
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->attachment_id ] = (int) $row->folder_id;
		}
		return $out;
	}

	/**
	 * Drop all rows for an attachment (on delete).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function delete_for_attachment( int $attachment_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
		$this->invalidate_counts();
	}

	/**
	 * Attachment IDs in a folder. 0 = uncategorized, -1 = all (attachments with status inherit/private).
	 *
	 * @param int  $folder_id          Folder ID.
	 * @param bool $include_subfolders Include descendants' attachments.
	 * @return int[]
	 */
	public function attachment_ids_in( int $folder_id, bool $include_subfolders = false ): array {
		global $wpdb;

		if ( $folder_id <= 0 ) {
			$where = Folder::ALL === $folder_id ? '' : ' AND ' . $this->uncategorized_where( 'p.ID' );
			return array_map( 'intval', (array) $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} AS p WHERE p.post_type = 'attachment' AND p.post_status IN ('inherit','private'){$where} ORDER BY p.ID ASC" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$folders = array( $folder_id );
		if ( $include_subfolders ) {
			$folders = array_merge( $folders, Folder::get_instance()->descendant_ids( $folder_id ) );
		}
		$placeholders = implode( ',', array_fill( 0, count( $folders ), '%d' ) );
		return array_map(
			'intval',
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT attachment_id FROM {$this->table()} WHERE folder_id IN ({$placeholders}) ORDER BY attachment_id ASC", $folders ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from $wpdb->prefix; placeholders built per ID.
		);
	}

	/**
	 * Attachment counts: all, uncategorized, and per folder (direct, or including
	 * descendants when the `include_subfolders_in_count` setting is on). Cached.
	 *
	 * @return array{all:int,uncategorized:int,folders:array<int,int>}
	 */
	public function counts(): array {
		$cached = get_transient( self::COUNTS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$status = "posts.post_type = 'attachment' AND posts.post_status IN ('inherit','private')";
		$sql    = "SELECT fbva.folder_id, COUNT(fbva.attachment_id) AS count FROM {$this->table()} AS fbva INNER JOIN {$wpdb->prefix}fbv AS fbv ON fbv.id = fbva.folder_id INNER JOIN {$wpdb->posts} AS posts ON posts.ID = fbva.attachment_id WHERE {$status} GROUP BY fbva.folder_id";
		$sql    = (string) Hooks::filter( 'all_folders_and_count', $sql, null );

		$direct = array();
		foreach ( (array) $wpdb->get_results( $sql ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$direct[ (int) $row->folder_id ] = (int) $row->count;
		}

		$rows    = Folder::get_instance()->all();
		$folders = array();
		foreach ( $rows as $row ) {
			$folders[ $row->id ] = $direct[ $row->id ] ?? 0;
		}

		if ( Settings::get_instance()->get( 'include_subfolders_in_count' ) ) {
			$children = array();
			foreach ( $rows as $row ) {
				$children[ $row->parent ][] = $row->id;
			}
			$totals = array();
			$sum    = function ( int $id, array $path ) use ( &$sum, &$totals, $children, $folders ): int {
				if ( isset( $totals[ $id ] ) ) {
					return $totals[ $id ];
				}
				$total = $folders[ $id ] ?? 0;
				foreach ( $children[ $id ] ?? array() as $child ) {
					if ( ! isset( $path[ $child ] ) ) {
						$total += $sum( $child, $path + array( $child => true ) );
					}
				}
				$totals[ $id ] = $total;
				return $total;
			};
			foreach ( array_keys( $folders ) as $id ) {
				$folders[ $id ] = $sum( $id, array( $id => true ) );
			}
		}

		$counts = array(
			'all'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} AS posts WHERE {$status}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'uncategorized' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} AS posts WHERE {$status} AND " . $this->uncategorized_where( 'posts.ID' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL built from $wpdb table names, no user input.
			'folders'       => $folders,
		);

		set_transient( self::COUNTS_TRANSIENT, $counts, DAY_IN_SECONDS );
		return $counts;
	}

	/**
	 * Drop the cached counts.
	 *
	 * @return void
	 */
	public function invalidate_counts(): void {
		delete_transient( self::COUNTS_TRANSIENT );
	}

	/**
	 * Remove rows whose attachment or folder no longer exists.
	 *
	 * @return int Rows removed.
	 */
	public function cleanup_orphans(): int {
		global $wpdb;

		$table    = $this->table();
		$removed  = (int) $wpdb->query( "DELETE fbva FROM {$table} AS fbva LEFT JOIN {$wpdb->posts} AS posts ON posts.ID = fbva.attachment_id WHERE posts.ID IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed += (int) $wpdb->query( "DELETE fbva FROM {$table} AS fbva LEFT JOIN {$wpdb->prefix}fbv AS fbv ON fbv.id = fbva.folder_id WHERE fbv.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->invalidate_counts();
		return $removed;
	}

	/**
	 * SQL fragment true when the post has no folder: `NOT EXISTS (…)`.
	 *
	 * @param string $posts_alias_id Column expression for the post ID, e.g. `wp_posts.ID`.
	 * @return string
	 */
	public function uncategorized_where( string $posts_alias_id ): string {
		global $wpdb;

		if ( ! preg_match( '/^[A-Za-z0-9_`.]+$/', $posts_alias_id ) ) {
			$posts_alias_id = "{$wpdb->posts}.ID";
		}
		return "NOT EXISTS (SELECT 1 FROM {$this->table()} AS cphfb_u INNER JOIN {$wpdb->prefix}fbv AS cphfb_f ON cphfb_f.id = cphfb_u.folder_id WHERE cphfb_u.attachment_id = {$posts_alias_id})";
	}

	/**
	 * Positive, unique ints.
	 *
	 * @param array $ids Raw IDs.
	 * @return int[]
	 */
	private function clean_ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ) => $id > 0 ) ) );
	}
}
