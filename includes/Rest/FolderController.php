<?php
/**
 * REST: folder tree CRUD and ordering.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Rest;

use CPH\FileBirb\Model\Assignment;
use CPH\FileBirb\Model\Folder;

defined( 'ABSPATH' ) || exit;

/**
 * `/folders`, `/folders/{id}`, `/folders/{id}/duplicate`, `/folders/order`.
 */
final class FolderController {

	/**
	 * Singleton instance.
	 *
	 * @var FolderController|null
	 */
	private static ?FolderController $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return FolderController
	 */
	public static function get_instance(): FolderController {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes. `/folders/order` goes first and `{id}` is numeric-only, so it can't be swallowed.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns   = Permissions::NAMESPACE;
		$perm = array( Permissions::class, 'can_upload' );
		$id   = array(
			'type'              => 'integer',
			'minimum'           => 1,
			'required'          => true,
			'sanitize_callback' => 'absint',
		);

		register_rest_route(
			$ns,
			'/folders/order',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'order' ),
				'permission_callback' => $perm,
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'id'     => array(
									'type'     => 'integer',
									'minimum'  => 1,
									'required' => true,
								),
								'parent' => array(
									'type'     => 'integer',
									'minimum'  => 0,
									'required' => true,
								),
								'ord'    => array(
									'type'     => 'integer',
									'minimum'  => 0,
									'required' => true,
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/folders',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $perm,
					'args'                => array(
						'include_counts' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'search'         => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'orderby'        => array(
							'type'    => 'string',
							'enum'    => array( 'ord', 'name' ),
							'default' => 'ord',
						),
						'order'          => array(
							'type'    => 'string',
							'enum'    => array( 'asc', 'desc' ),
							'default' => 'asc',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $perm,
					'args'                => array(
						'name'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'parent' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/folders/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $perm,
					'args'                => array(
						'id'     => $id,
						'name'   => array( 'type' => 'string' ),
						'parent' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'ord'    => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'color'  => array(
							'type'              => 'string',
							'validate_callback' => array( $this, 'validate_color' ),
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $perm,
					'args'                => array(
						'id'   => $id,
						'mode' => array(
							'type'    => 'string',
							'enum'    => array( 'subtree', 'children-up' ),
							'default' => 'subtree',
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/folders/(?P<id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'duplicate' ),
				'permission_callback' => $perm,
				'args'                => array( 'id' => $id ),
			)
		);
	}

	/**
	 * Color is '' (clear) or a hex value.
	 *
	 * @param mixed $value Raw value.
	 * @return true|\WP_Error
	 */
	public function validate_color( $value ): bool|\WP_Error {
		if ( is_string( $value ) && ( '' === $value || sanitize_hex_color( $value ) ) ) {
			return true;
		}
		return new \WP_Error( 'invalid_color', __( 'Color must be a hex value like #ff0000.', 'cph-filebirb' ), array( 'status' => 400 ) );
	}

	/**
	 * GET /folders.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$tree   = Folder::get_instance()->tree(
			array(
				'search'  => (string) $request['search'],
				'orderby' => (string) $request['orderby'],
				'order'   => (string) $request['order'],
			)
		);
		$counts = null;
		if ( $request['include_counts'] ) {
			$counts = Assignment::get_instance()->counts();
			$tree   = $this->fill_counts( $tree, $counts['folders'] );
		}
		return rest_ensure_response(
			array(
				'tree'   => $tree,
				'counts' => $counts,
			)
		);
	}

	/**
	 * POST /folders.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$node = Folder::get_instance()->create( (string) $request['name'], (int) $request['parent'] );
		if ( is_wp_error( $node ) ) {
			return Permissions::error( $node );
		}
		$response = rest_ensure_response( $node );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * PATCH /folders/{id}: rename, then move/ord, then color; returns the fresh node.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$model  = Folder::get_instance();
		$id     = (int) $request['id'];
		$folder = $model->get( $id );
		if ( ! $folder ) {
			return Permissions::error( new \WP_Error( 'folder_not_found', __( 'Folder not found.', 'cph-filebirb' ) ) );
		}

		if ( $request->has_param( 'name' ) ) {
			$result = $model->rename( $id, (string) $request['name'] );
			if ( is_wp_error( $result ) ) {
				return Permissions::error( $result );
			}
		}

		if ( $request->has_param( 'parent' ) || $request->has_param( 'ord' ) ) {
			$parent = $request->has_param( 'parent' ) ? (int) $request['parent'] : $folder->parent;
			$ord    = $request->has_param( 'ord' ) ? (int) $request['ord'] : null;
			$result = $model->move( $id, $parent, $ord );
			if ( is_wp_error( $result ) ) {
				return Permissions::error( $result );
			}
		}

		if ( $request->has_param( 'color' ) ) {
			$result = $model->set_color( $id, (string) $request['color'] );
			if ( is_wp_error( $result ) ) {
				return Permissions::error( $result );
			}
		}

		return rest_ensure_response( $this->fresh_node( $id ) );
	}

	/**
	 * DELETE /folders/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request['id'];
		$result = Folder::get_instance()->delete( $id, (string) $request['mode'] );
		if ( is_wp_error( $result ) ) {
			return Permissions::error( $result );
		}
		$assignments = Assignment::get_instance();
		$assignments->invalidate_counts();
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
				'counts'  => $assignments->counts(),
			)
		);
	}

	/**
	 * POST /folders/{id}/duplicate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$node = Folder::get_instance()->duplicate( (int) $request['id'] );
		if ( is_wp_error( $node ) ) {
			return Permissions::error( $node );
		}
		$response = rest_ensure_response( $node );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * POST /folders/order.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function order( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$model  = Folder::get_instance();
		$result = $model->reorder( (array) $request['items'] );
		if ( is_wp_error( $result ) ) {
			return Permissions::error( $result );
		}
		return rest_ensure_response( array( 'tree' => $model->tree() ) );
	}

	/**
	 * A folder's node from the full tree (so children are included).
	 *
	 * @param int $id Folder ID.
	 * @return array|null
	 */
	private function fresh_node( int $id ): ?array {
		$model = Folder::get_instance();
		$node  = $model->find_node( $model->tree(), $id );
		if ( null === $node ) {
			$row  = $model->get( $id );
			$node = $row ? $model->node( $row ) : null;
		}
		return $node;
	}

	/**
	 * Fill each node's `count` recursively.
	 *
	 * @param array           $nodes  Nested nodes.
	 * @param array<int, int> $counts Folder ID => count.
	 * @return array
	 */
	private function fill_counts( array $nodes, array $counts ): array {
		foreach ( $nodes as &$node ) {
			$node['count']    = (int) ( $counts[ $node['id'] ] ?? 0 );
			$node['children'] = $this->fill_counts( $node['children'], $counts );
		}
		unset( $node );
		return $nodes;
	}
}
