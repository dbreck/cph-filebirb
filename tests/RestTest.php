<?php
/**
 * REST controller integration tests.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Tests;

use CPH\FileBird\Model\Settings;

/**
 * Every `cph-filebird/v1` route: shapes, permissions, validation.
 */
class RestTest extends TestCase {

	/**
	 * User IDs by role.
	 *
	 * @var array<string,int>
	 */
	private array $users = array();

	/**
	 * Fresh REST server and users.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		foreach ( array( 'administrator', 'editor', 'author', 'subscriber' ) as $role ) {
			$this->users[ $role ] = self::factory()->user->create( array( 'role' => $role ) );
		}
		wp_set_current_user( $this->users['administrator'] );
	}

	/**
	 * Tear down the server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Dispatch a request.
	 */
	private function call( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/cph-filebird/v1' . $route );
		if ( 'GET' === $method || 'DELETE' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return rest_do_request( $request );
	}

	/**
	 * Error code from a response.
	 */
	private function code( \WP_REST_Response $response ): string {
		return (string) ( $response->get_data()['code'] ?? '' );
	}

	public function test_routes_registered(): void {
		$routes = rest_get_server()->get_routes( 'cph-filebird/v1' );
		foreach ( array( '/folders', '/folders/order', '/folders/(?P<id>\d+)', '/folders/(?P<id>\d+)/duplicate', '/assign', '/attachments/(?P<id>\d+)/folder', '/counts', '/settings', '/user-settings', '/export.csv', '/import.csv' ) as $route ) {
			$this->assertArrayHasKey( '/cph-filebird/v1' . $route, $routes, $route );
		}
	}

	public function test_get_folders_tree_and_counts(): void {
		$a   = $this->make( 'A' );
		$b   = $this->make( 'B', $a );
		$ids = $this->attachments( 2 );
		$this->assignments()->assign( $b, $ids );

		$res  = $this->call( 'GET', '/folders' );
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'tree', 'counts' ), array_keys( $data ) );
		$this->assertSame( array( 'id', 'name', 'title', 'parent', 'ord', 'color', 'count', 'children' ), array_keys( $data['tree'][0] ) );
		$this->assertSame( $a, $data['tree'][0]['id'] );
		$this->assertSame( $b, $data['tree'][0]['children'][0]['id'] );
		$this->assertSame( 2, $data['tree'][0]['children'][0]['count'] );
		$this->assertSame( 2, $data['counts']['all'] );
		$this->assertSame( 0, $data['counts']['uncategorized'] );
		$this->assertSame( 2, $data['counts']['folders'][ $b ] );

		$data = $this->call( 'GET', '/folders', array( 'include_counts' => 0 ) )->get_data();
		$this->assertNull( $data['counts'] );
		$this->assertSame( 0, $data['tree'][0]['children'][0]['count'] );
	}

	public function test_get_folders_search_and_order(): void {
		$this->make( 'Alpha' );
		$this->make( 'Beta' );
		$data = $this->call( 'GET', '/folders', array( 'search' => 'bet' ) )->get_data();
		$this->assertSame( array( 'Beta' ), array_column( $data['tree'], 'name' ) );

		$data = $this->call( 'GET', '/folders', array( 'orderby' => 'name', 'order' => 'desc' ) )->get_data();
		$this->assertSame( array( 'Beta', 'Alpha' ), array_column( $data['tree'], 'name' ) );

		$this->assertSame( 400, $this->call( 'GET', '/folders', array( 'orderby' => 'bogus' ) )->get_status() );
	}

	public function test_create_folder(): void {
		$res = $this->call( 'POST', '/folders', array( 'name' => 'Hero' ) );
		$this->assertSame( 201, $res->get_status() );
		$node = $res->get_data();
		$this->assertIsInt( $node['id'] );
		$this->assertSame( 'Hero', $node['name'] );
		$this->assertSame( 0, $node['parent'] );

		$child = $this->call( 'POST', '/folders', array( 'name' => 'Sub', 'parent' => $node['id'] ) )->get_data();
		$this->assertSame( $node['id'], $child['parent'] );
	}

	public function test_create_validation(): void {
		$this->assertSame( 400, $this->call( 'POST', '/folders', array() )->get_status() );
		$res = $this->call( 'POST', '/folders', array( 'name' => '   ' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_name', $this->code( $res ) );
		$res = $this->call( 'POST', '/folders', array( 'name' => 'X', 'parent' => 99999 ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_parent', $this->code( $res ) );
	}

	public function test_patch_rename_move_color(): void {
		$a = $this->make( 'A' );
		$b = $this->make( 'B' );

		$res  = $this->call( 'PATCH', "/folders/{$b}", array( 'name' => 'B2', 'parent' => $a, 'color' => '#ff0000' ) );
		$node = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'B2', $node['name'] );
		$this->assertSame( $a, $node['parent'] );
		$this->assertSame( '#ff0000', $node['color'] );

		$node = $this->call( 'PATCH', "/folders/{$b}", array( 'color' => '' ) )->get_data();
		$this->assertSame( '', $node['color'] );

		$node = $this->call( 'PATCH', "/folders/{$b}", array( 'ord' => 5 ) )->get_data();
		$this->assertSame( 5, $node['ord'] );
		$this->assertSame( $a, $node['parent'] );
	}

	public function test_patch_move_auto_renames(): void {
		$a = $this->make( 'A' );
		$this->make( 'Dup', $a );
		$dup = $this->make( 'Dup' );

		$node = $this->call( 'PATCH', "/folders/{$dup}", array( 'parent' => $a ) )->get_data();
		$this->assertSame( $a, $node['parent'] );
		$this->assertNotSame( 'Dup', $node['name'] );
		$this->assertStringStartsWith( 'Dup', $node['name'] );
	}

	public function test_patch_errors(): void {
		$a = $this->make( 'A' );
		$b = $this->make( 'B' );
		$c = $this->make( 'C', $a );

		$res = $this->call( 'PATCH', "/folders/{$b}", array( 'name' => 'A' ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'folder_name_exists', $this->code( $res ) );

		$res = $this->call( 'PATCH', "/folders/{$a}", array( 'parent' => $c ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_parent', $this->code( $res ) );

		$this->assertSame( 400, $this->call( 'PATCH', "/folders/{$a}", array( 'color' => 'red' ) )->get_status() );

		$res = $this->call( 'PATCH', '/folders/99999', array( 'name' => 'X' ) );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'folder_not_found', $this->code( $res ) );
	}

	public function test_delete_subtree(): void {
		$a   = $this->make( 'A' );
		$b   = $this->make( 'B', $a );
		$ids = $this->attachments( 1 );
		$this->assignments()->assign( $b, $ids );

		$res  = $this->call( 'DELETE', "/folders/{$a}" );
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( $a, $data['id'] );
		$this->assertSame( 1, $data['counts']['uncategorized'] );
		$this->assertFalse( $this->folders()->exists( $b ) );
	}

	public function test_delete_children_up(): void {
		$a = $this->make( 'A' );
		$b = $this->make( 'B', $a );

		$res = $this->call( 'DELETE', "/folders/{$a}", array( 'mode' => 'children-up' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 0, $this->folders()->get( $b )->parent );

		$this->assertSame( 400, $this->call( 'DELETE', "/folders/{$b}", array( 'mode' => 'nope' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/folders/99999' )->get_status() );
	}

	public function test_delete_blocked_by_filter(): void {
		$a = $this->make( 'A' );
		add_filter( 'cphfb_can_delete_folder', '__return_false' );
		$res = $this->call( 'DELETE', "/folders/{$a}" );
		remove_filter( 'cphfb_can_delete_folder', '__return_false' );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'cannot_delete', $this->code( $res ) );
	}

	public function test_duplicate(): void {
		$a = $this->make( 'A' );
		$this->make( 'B', $a );

		$res  = $this->call( 'POST', "/folders/{$a}/duplicate" );
		$node = $res->get_data();
		$this->assertSame( 201, $res->get_status() );
		$this->assertNotSame( $a, $node['id'] );
		$this->assertSame( 'B', $node['children'][0]['name'] );
		$this->assertSame( 404, $this->call( 'POST', '/folders/99999/duplicate' )->get_status() );
	}

	public function test_order(): void {
		$a = $this->make( 'A' );
		$b = $this->make( 'B' );

		$res = $this->call(
			'POST',
			'/folders/order',
			array(
				'items' => array(
					array( 'id' => $b, 'parent' => 0, 'ord' => 0 ),
					array( 'id' => $a, 'parent' => $b, 'ord' => 0 ),
				),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$tree = $res->get_data()['tree'];
		$this->assertCount( 1, $tree );
		$this->assertSame( $b, $tree[0]['id'] );
		$this->assertSame( $a, $tree[0]['children'][0]['id'] );

		$res = $this->call( 'POST', '/folders/order', array( 'items' => array( array( 'id' => $a, 'parent' => $a, 'ord' => 0 ) ) ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/folders/order', array() )->get_status() );
	}

	public function test_assign_and_unassign(): void {
		$a   = $this->make( 'A' );
		$ids = $this->attachments( 2 );

		$res  = $this->call( 'POST', '/assign', array( 'folder' => $a, 'ids' => $ids ) );
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $a, $data['folder'] );
		$this->assertSame( $ids, $data['assigned'] );
		$this->assertSame( 2, $data['counts']['folders'][ $a ] );

		$data = $this->call( 'POST', '/assign', array( 'folder' => 0, 'ids' => array( $ids[0] ) ) )->get_data();
		$this->assertSame( 1, $data['counts']['uncategorized'] );

		$this->assertSame( 404, $this->call( 'POST', '/assign', array( 'folder' => 99999, 'ids' => $ids ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/assign', array( 'folder' => $a, 'ids' => array() ) )->get_status() );
	}

	public function test_assign_filters_by_edit_post(): void {
		$a     = $this->make( 'A' );
		$other = $this->attachments( 1 )[0];
		wp_update_post( array( 'ID' => $other, 'post_author' => $this->users['editor'] ) );
		wp_set_current_user( $this->users['author'] );
		$own = self::factory()->attachment->create_object(
			array(
				'file'           => 'own.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $this->users['author'],
			)
		);

		$data = $this->call( 'POST', '/assign', array( 'folder' => $a, 'ids' => array( $own, $other ) ) )->get_data();
		$this->assertSame( array( $own ), $data['assigned'] );

		$res = $this->call( 'POST', '/assign', array( 'folder' => $a, 'ids' => array( $other ) ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'nothing_to_move', $this->code( $res ) );
	}

	public function test_attachment_folder(): void {
		$a   = $this->make( 'A' );
		$ids = $this->attachments( 2 );
		$this->assignments()->assign( $a, array( $ids[0] ) );

		$data = $this->call( 'GET', "/attachments/{$ids[0]}/folder" )->get_data();
		$this->assertSame( $ids[0], $data['attachment_id'] );
		$this->assertSame( $a, $data['folder_id'] );
		$this->assertSame( 'A', $data['folder']['name'] );

		$data = $this->call( 'GET', "/attachments/{$ids[1]}/folder" )->get_data();
		$this->assertSame( 0, $data['folder_id'] );
		$this->assertNull( $data['folder'] );

		$post = self::factory()->post->create();
		$this->assertSame( 404, $this->call( 'GET', "/attachments/{$post}/folder" )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/attachments/99999/folder' )->get_status() );
	}

	public function test_counts(): void {
		$this->attachments( 3 );
		$data = $this->call( 'GET', '/counts' )->get_data();
		$this->assertSame( array( 'all', 'uncategorized', 'folders' ), array_keys( $data ) );
		$this->assertSame( 3, $data['all'] );
	}

	public function test_settings(): void {
		$this->assertSame( Settings::DEFAULTS, $this->call( 'GET', '/settings' )->get_data() );
		$data = $this->call( 'POST', '/settings', array( 'default_sort' => 'name_asc' ) )->get_data();
		$this->assertSame( 'name_asc', $data['default_sort'] );
		$this->assertFalse( $data['include_subfolders_in_count'] );
		$this->assertSame( 400, $this->call( 'POST', '/settings', array( 'default_sort' => 'x' ) )->get_status() );
	}

	public function test_user_settings(): void {
		$a    = $this->make( 'A' );
		$data = $this->call( 'POST', '/user-settings', array( 'default_upload_folder' => $a, 'collapsed' => array( $a ) ) )->get_data();
		$this->assertSame( $a, $data['default_upload_folder'] );
		$this->assertSame( array( $a ), $data['collapsed'] );
		$this->assertSame( -1, $data['selected_folder'] );
		$this->assertSame( $data, $this->call( 'GET', '/user-settings' )->get_data() );

		wp_set_current_user( $this->users['author'] );
		$this->assertSame( -1, $this->call( 'GET', '/user-settings' )->get_data()['default_upload_folder'] );
	}

	public function test_export_csv(): void {
		$a   = $this->make( 'A' );
		$ids = $this->attachments( 1 );
		$this->assignments()->assign( $a, $ids );

		$res     = $this->call( 'GET', '/export.csv' );
		$headers = $res->get_headers();
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'attachment; filename="cph-filebird-folders-' . gmdate( 'Y-m-d' ) . '.csv"', $headers['Content-Disposition'] );
		$this->assertIsString( $res->get_data() );
		$this->assertStringStartsWith( 'id,name,parent', $res->get_data() );
		$this->assertStringContainsString( (string) $ids[0], $res->get_data() );
	}

	public function test_export_served_raw(): void {
		$request = new \WP_REST_Request( 'GET', '/cph-filebird/v1/export.csv' );
		$result  = rest_do_request( $request );
		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $result, $request, rest_get_server() );
		$body   = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertStringStartsWith( 'id,name,parent', $body );
	}

	public function test_import_round_trip(): void {
		$a   = $this->make( 'A' );
		$this->make( 'B', $a );
		$ids = $this->attachments( 2 );
		$this->assignments()->assign( $a, $ids );
		$csv = $this->call( 'GET', '/export.csv' )->get_data();

		$this->folders()->delete_all();
		$res = $this->call( 'POST', '/import.csv', array( 'csv' => $csv ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'folders' => 2, 'assignments' => 2 ), $res->get_data() );

		$tree = $this->call( 'GET', '/folders' )->get_data()['tree'];
		$this->assertSame( 'A', $tree[0]['name'] );
		$this->assertSame( 2, $tree[0]['count'] );
		$this->assertSame( 'B', $tree[0]['children'][0]['name'] );

		$res = $this->call( 'POST', '/import.csv', array( 'csv' => "foo,bar\n1,2" ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_csv', $this->code( $res ) );
		$this->assertSame( 400, $this->call( 'POST', '/import.csv', array() )->get_status() );
	}

	/**
	 * Permission matrix: route => [ method, minimum role level ].
	 */
	public function test_permission_matrix(): void {
		$a = $this->make( 'A' );
		$checks = array(
			array( 'GET', '/folders', array(), 'upload' ),
			array( 'POST', '/folders', array( 'name' => 'P' ), 'upload' ),
			array( 'PATCH', "/folders/{$a}", array( 'color' => '#000000' ), 'upload' ),
			array( 'POST', '/folders/order', array( 'items' => array() ), 'upload' ),
			array( 'GET', '/counts', array(), 'upload' ),
			array( 'GET', '/settings', array(), 'upload' ),
			array( 'POST', '/settings', array( 'default_sort' => 'ord' ), 'manage' ),
			array( 'GET', '/user-settings', array(), 'upload' ),
			array( 'POST', '/user-settings', array( 'sidebar_width' => 300 ), 'upload' ),
			array( 'GET', '/export.csv', array(), 'upload' ),
			array( 'POST', '/import.csv', array( 'csv' => "id,name,parent\n" ), 'manage' ),
		);
		$roles  = array(
			0                            => array( 401, 401 ),
			$this->users['subscriber']   => array( 403, 403 ),
			$this->users['author']       => array( 200, 403 ),
			$this->users['editor']       => array( 200, 403 ),
			$this->users['administrator'] => array( 200, 200 ),
		);

		foreach ( $roles as $user => [ $upload, $manage ] ) {
			wp_set_current_user( $user );
			foreach ( $checks as [ $method, $route, $params, $level ] ) {
				$expected = 'manage' === $level ? $manage : $upload;
				$status   = $this->call( $method, $route, $params )->get_status();
				if ( 200 === $expected ) {
					$this->assertContains( $status, array( 200, 201 ), "{$method} {$route} as user {$user}" );
				} else {
					$this->assertSame( $expected, $status, "{$method} {$route} as user {$user}" );
				}
			}
		}

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'DELETE', "/folders/{$a}" )->get_status() );
		$this->assertSame( 401, $this->call( 'POST', '/assign', array( 'folder' => 0, 'ids' => array( 1 ) ) )->get_status() );
		wp_set_current_user( $this->users['subscriber'] );
		$this->assertSame( 403, $this->call( 'POST', "/folders/{$a}/duplicate" )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/attachments/1/folder' )->get_status() );
		wp_set_current_user( $this->users['author'] );
		$this->assertSame( 200, $this->call( 'DELETE', "/folders/{$a}" )->get_status() );
	}
}
