<?php
/**
 * Query tests.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Tests;

use CPH\FileBird\Model\Settings;
use CPH\FileBird\Query;

/**
 * Folder filtering through WP_Query, ajax args, REST and the JS model.
 */
class QueryTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Query::get_instance()->flush_cache();
		unset( $_REQUEST['query'] );
	}

	public function tear_down(): void {
		unset( $_REQUEST['query'] );
		parent::tear_down();
	}

	/**
	 * Attachment IDs for an `fbv` value, sorted.
	 *
	 * @return int[]
	 */
	private function ids_for( int $fbv ): array {
		$q   = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'fbv'            => $fbv,
			)
		);
		$ids = array_map( 'intval', $q->posts );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'No duplicate rows.' );
		sort( $ids );
		return $ids;
	}

	public function test_all_uncategorized_folder_and_subfolders(): void {
		$ids    = $this->attachments( 4 );
		$parent = $this->make( 'Parent' );
		$child  = $this->make( 'Child', $parent );
		$this->assignments()->assign( $parent, array( $ids[0] ) );
		$this->assignments()->assign( $child, array( $ids[1], $ids[2] ) );

		$this->assertSame( $ids, $this->ids_for( -1 ) );
		$this->assertSame( array( $ids[3] ), $this->ids_for( 0 ) );
		$this->assertSame( array( $ids[0] ), $this->ids_for( $parent ) );
		$this->assertSame( array( $ids[1], $ids[2] ), $this->ids_for( $child ) );

		Settings::get_instance()->set( 'include_subfolders_in_query', true );
		$this->assertSame( array( $ids[0], $ids[1], $ids[2] ), $this->ids_for( $parent ) );

		$off = static fn() => false;
		add_filter( 'fbv_query_include_subfolders', $off );
		$this->assertSame( array( $ids[0] ), $this->ids_for( $parent ) );
		remove_filter( 'fbv_query_include_subfolders', $off );
	}

	public function test_duplicate_rows_do_not_duplicate_results(): void {
		global $wpdb;
		$ids = $this->attachments( 1 );
		$a   = $this->make( 'A' );
		$b   = $this->make( 'B', $a );
		// Legacy data can hold two rows for one attachment.
		$wpdb->insert( $this->assignments()->table(), array( 'folder_id' => $a, 'attachment_id' => $ids[0] ) );
		$wpdb->insert( $this->assignments()->table(), array( 'folder_id' => $b, 'attachment_id' => $ids[0] ) );
		Settings::get_instance()->set( 'include_subfolders_in_query', true );

		$this->assertSame( $ids, $this->ids_for( $a ) );
		$this->assertSame( array(), $this->ids_for( 0 ) );
	}

	public function test_non_attachment_queries_untouched(): void {
		$post = self::factory()->post->create();
		$q    = new \WP_Query(
			array(
				'post_type' => 'post',
				'fields'    => 'ids',
				'fbv'       => 0,
			)
		);
		$this->assertContains( $post, array_map( 'intval', $q->posts ) );
	}

	public function test_ajax_args_passthrough(): void {
		$_REQUEST['query'] = array( 'fbv' => '7' );
		$args              = apply_filters( 'ajax_query_attachments_args', array( 'post_type' => 'attachment' ) );
		$this->assertSame( 7, $args['fbv'] );

		$_REQUEST['query'] = array( 'fbv' => 'abc' );
		$args              = apply_filters( 'ajax_query_attachments_args', array( 'post_type' => 'attachment' ) );
		$this->assertArrayNotHasKey( 'fbv', $args );
	}

	public function test_rest_media_param(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$ids = $this->attachments( 2 );
		$a   = $this->make( 'A' );
		$this->assignments()->assign( $a, array( $ids[1] ) );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/media' );
		$request->set_param( 'fbv', $a );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( array( $ids[1] ), array_column( $data, 'id' ) );

		$request->set_param( 'fbv', 0 );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( array( $ids[0] ), array_column( $data, 'id' ) );
	}

	public function test_prepare_attachment_for_js_keys(): void {
		$ids = $this->attachments( 2 );
		$a   = $this->make( 'A' );
		$this->assignments()->assign( $a, array( $ids[0] ) );

		$js = wp_prepare_attachment_for_js( $ids[0] );
		$this->assertSame( $a, $js['fbv'] );
		$this->assertSame( $a, $js['folder_id'] );

		$js = wp_prepare_attachment_for_js( $ids[1] );
		$this->assertSame( 0, $js['fbv'] );
		$this->assertSame( 0, $js['folder_id'] );

		// Cache is flushed on reassignment.
		$this->assignments()->assign( 0, array( $ids[0] ) );
		$this->assertSame( 0, wp_prepare_attachment_for_js( $ids[0] )['fbv'] );
	}

	public function test_does_not_force_infinite_scrolling(): void {
		$this->assertFalse( has_filter( 'media_library_infinite_scrolling' ) );
	}
}
