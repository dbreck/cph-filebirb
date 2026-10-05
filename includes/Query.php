<?php
/**
 * Folder filtering for attachment queries (grid, list, REST) and the JS attachment model.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird;

use CPH\FileBird\Model\Assignment;
use CPH\FileBird\Model\Folder;
use CPH\FileBird\Model\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Query var `fbv`: -1 all, 0 uncategorized, N folder (plus descendants when enabled).
 */
final class Query {

	/**
	 * Query var name, shared with FileBird.
	 */
	public const VAR = 'fbv';

	/**
	 * Singleton instance.
	 *
	 * @var Query|null
	 */
	private static ?Query $instance = null;

	/**
	 * Per-request attachment ID => folder ID cache for wp_prepare_attachment_for_js.
	 *
	 * @var array<int,int>
	 */
	private array $folder_cache = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return Query
	 */
	public static function get_instance(): Query {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'ajax_query_attachments_args', array( $this, 'ajax_query_args' ), 20 );
		add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 10, 2 );
		add_filter( 'rest_attachment_query', array( $this, 'rest_query' ), 10, 2 );
		add_filter( 'the_posts', array( $this, 'prime_cache' ), 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_for_js' ), 10, 2 );
		add_action( 'cphfb_after_assign_folder', array( $this, 'flush_cache' ) );
		add_action( 'delete_attachment', array( $this, 'flush_cache' ) );
	}

	/**
	 * Normalise a raw `fbv` value to an int, or null when absent/invalid.
	 *
	 * Arrays (FileBird sends `query[fbv][]` in some builds) use their first element.
	 *
	 * @param mixed $raw Raw value.
	 * @return int|null
	 */
	public static function parse( $raw ): ?int {
		if ( is_array( $raw ) ) {
			$raw = reset( $raw );
		}
		if ( null === $raw || '' === $raw || false === $raw ) {
			return null;
		}
		if ( is_int( $raw ) ) {
			return $raw;
		}
		$raw = trim( (string) $raw );
		return preg_match( '/^-?\d+$/', $raw ) ? (int) $raw : null;
	}

	/**
	 * Copy `query[fbv]` from the media modal request into the query args.
	 *
	 * @param array $query Query args.
	 * @return array
	 */
	public function ajax_query_args( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- query-attachments is read-only and capability-checked by core.
		$fbv = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) && isset( $_REQUEST['query'][ self::VAR ] ) ? self::parse( wp_unslash( $_REQUEST['query'][ self::VAR ] ) ) : null;
		if ( null !== $fbv && is_array( $query ) ) {
			$query[ self::VAR ] = $fbv;
		}
		return $query;
	}

	/**
	 * Honour `fbv` on `/wp/v2/media`.
	 *
	 * @param array            $args    WP_Query args.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function rest_query( $args, $request ) {
		$fbv = $request instanceof \WP_REST_Request ? self::parse( $request->get_param( self::VAR ) ) : null;
		if ( null !== $fbv ) {
			$args[ self::VAR ] = $fbv;
		}
		return $args;
	}

	/**
	 * Restrict attachment queries carrying `fbv`.
	 *
	 * @param array     $clauses Query clauses.
	 * @param \WP_Query $query   Query.
	 * @return array
	 */
	public function posts_clauses( $clauses, $query ) {
		if ( ! $query instanceof \WP_Query ) {
			return $clauses;
		}
		$fbv = $this->folder_for( $query );
		if ( null === $fbv || Folder::ALL === $fbv || $fbv < 0 ) {
			return $clauses;
		}

		global $wpdb;
		$assignments = Assignment::get_instance();

		if ( Folder::UNCATEGORIZED === $fbv ) {
			$clauses['where'] .= ' AND ' . $assignments->uncategorized_where( "{$wpdb->posts}.ID" );
			return $clauses;
		}

		$folders = array( $fbv );
		if ( Hooks::filter( 'query_include_subfolders', (bool) Settings::get_instance()->get( 'include_subfolders_in_query' ), $fbv ) ) {
			$folders = array_merge( $folders, Folder::get_instance()->descendant_ids( $fbv ) );
		}
		$placeholders = implode( ',', array_fill( 0, count( $folders ), '%d' ) );
		// IN (subquery) rather than a JOIN, so an attachment can never appear twice.
		$clauses['where'] .= $wpdb->prepare( " AND {$wpdb->posts}.ID IN (SELECT cphfb_q.attachment_id FROM {$assignments->table()} AS cphfb_q WHERE cphfb_q.folder_id IN ({$placeholders}))", $folders ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $clauses;
	}

	/**
	 * Prime the folder cache for attachments returned by a query.
	 *
	 * @param \WP_Post[] $posts Posts.
	 * @param \WP_Query  $query Query.
	 * @return \WP_Post[]
	 */
	public function prime_cache( $posts, $query ) {
		if ( ! is_array( $posts ) || ! $query instanceof \WP_Query || 'attachment' !== $query->get( 'post_type' ) ) {
			return $posts;
		}
		$ids = array();
		foreach ( $posts as $post ) {
			$id = is_object( $post ) ? (int) $post->ID : (int) $post;
			if ( $id > 0 && ! isset( $this->folder_cache[ $id ] ) ) {
				$ids[] = $id;
			}
		}
		if ( $ids ) {
			$this->folder_cache = Assignment::get_instance()->get_folder_ids_for( $ids ) + $this->folder_cache;
		}
		return $posts;
	}

	/**
	 * Add `fbv` and `folder_id` to the JS attachment model.
	 *
	 * @param array    $response   Attachment data.
	 * @param \WP_Post $attachment Attachment.
	 * @return array
	 */
	public function prepare_for_js( $response, $attachment ) {
		if ( ! is_array( $response ) || ! is_object( $attachment ) ) {
			return $response;
		}
		$folder                = $this->folder_of( (int) $attachment->ID );
		$response[ self::VAR ] = $folder;
		$response['folder_id'] = $folder;
		return $response;
	}

	/**
	 * Folder ID of an attachment, through the per-request cache.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	public function folder_of( int $attachment_id ): int {
		if ( ! isset( $this->folder_cache[ $attachment_id ] ) ) {
			$this->folder_cache[ $attachment_id ] = Assignment::get_instance()->get_folder_id( $attachment_id );
		}
		return $this->folder_cache[ $attachment_id ];
	}

	/**
	 * Empty the per-request cache.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->folder_cache = array();
	}

	/**
	 * The `fbv` value that applies to a query, or null.
	 *
	 * @param \WP_Query $query Query.
	 * @return int|null
	 */
	private function folder_for( \WP_Query $query ): ?int {
		$post_type     = $query->get( 'post_type' );
		$is_attachment = 'attachment' === $post_type || ( is_array( $post_type ) && array( 'attachment' ) === array_values( $post_type ) );

		$fbv = self::parse( $query->get( self::VAR, null ) );
		if ( null !== $fbv ) {
			return $is_attachment ? $fbv : null;
		}

		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		if ( $is_attachment && is_admin() && 'upload.php' === $pagenow && $query->is_main_query() && isset( $_GET[ self::VAR ] ) ) {
			return self::parse( wp_unslash( $_GET[ self::VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return null;
	}
}
