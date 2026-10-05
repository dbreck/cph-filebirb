<?php
/**
 * REST: assignment, per-attachment folder lookup, counts.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Rest;

use CPH\FileBirb\Model\Assignment;
use CPH\FileBirb\Model\Folder;

defined( 'ABSPATH' ) || exit;

/**
 * `/assign`, `/attachments/{id}/folder`, `/counts`.
 */
final class AttachmentController {

	/**
	 * Singleton instance.
	 *
	 * @var AttachmentController|null
	 */
	private static ?AttachmentController $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return AttachmentController
	 */
	public static function get_instance(): AttachmentController {
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
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns   = Permissions::NAMESPACE;
		$perm = array( Permissions::class, 'can_upload' );

		register_rest_route(
			$ns,
			'/assign',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'assign' ),
				'permission_callback' => $perm,
				'args'                => array(
					'folder' => array(
						'type'     => 'integer',
						'minimum'  => 0,
						'required' => true,
					),
					'ids'    => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'required' => true,
						'minItems' => 1,
						'maxItems' => 5000,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/attachments/(?P<id>\d+)/folder',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'folder_of' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/counts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'counts' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * POST /assign. IDs the user can't `edit_post` are dropped.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function assign( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$folder = (int) $request['folder'];
		$ids    = array();
		foreach ( (array) $request['ids'] as $id ) {
			$id = (int) $id;
			if ( $id > 0 && 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) {
				$ids[ $id ] = $id;
			}
		}
		$ids = array_values( $ids );
		if ( empty( $ids ) ) {
			return new \WP_Error( 'nothing_to_move', __( 'None of those files can be moved.', 'cph-filebirb' ), array( 'status' => 400 ) );
		}

		$assignments = Assignment::get_instance();
		$result      = $assignments->assign( $folder, $ids );
		if ( is_wp_error( $result ) ) {
			return Permissions::error( $result );
		}

		return rest_ensure_response(
			array(
				'folder'   => $folder,
				'assigned' => $ids,
				'counts'   => $assignments->counts(),
			)
		);
	}

	/**
	 * GET /attachments/{id}/folder.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function folder_of( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'attachment_not_found', __( 'Attachment not found.', 'cph-filebirb' ), array( 'status' => 404 ) );
		}
		$folder_id = Assignment::get_instance()->get_folder_id( $id );
		$row       = $folder_id > 0 ? Folder::get_instance()->get( $folder_id ) : null;
		return rest_ensure_response(
			array(
				'attachment_id' => $id,
				'folder_id'     => $row ? $folder_id : 0,
				'folder'        => $row ? Folder::get_instance()->node( $row ) : null,
			)
		);
	}

	/**
	 * GET /counts.
	 *
	 * @return \WP_REST_Response
	 */
	public function counts(): \WP_REST_Response {
		return rest_ensure_response( Assignment::get_instance()->counts() );
	}
}
