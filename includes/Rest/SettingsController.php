<?php
/**
 * REST: global and per-user settings, CSV export / import.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Rest;

use CPH\FileBird\Csv;
use CPH\FileBird\Model\Settings;
use CPH\FileBird\Model\UserSettings;

defined( 'ABSPATH' ) || exit;

/**
 * `/settings`, `/user-settings`, `/export.csv`, `/import.csv`.
 */
final class SettingsController {

	/**
	 * Singleton instance.
	 *
	 * @var SettingsController|null
	 */
	private static ?SettingsController $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return SettingsController
	 */
	public static function get_instance(): SettingsController {
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
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_csv' ), 10, 4 );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns     = Permissions::NAMESPACE;
		$upload = array( Permissions::class, 'can_upload' );
		$manage = array( Permissions::class, 'can_manage' );

		register_rest_route(
			$ns,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $upload,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'set_settings' ),
					'permission_callback' => $manage,
					'args'                => array(
						'default_sort'                => array(
							'type' => 'string',
							'enum' => Settings::SORTS,
						),
						'include_subfolders_in_count' => array( 'type' => 'boolean' ),
						'include_subfolders_in_query' => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/user-settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_user_settings' ),
					'permission_callback' => $upload,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'set_user_settings' ),
					'permission_callback' => $upload,
					'args'                => array(
						'default_upload_folder' => array(
							'type'    => 'integer',
							'minimum' => -1,
						),
						'selected_folder'       => array(
							'type'    => 'integer',
							'minimum' => -1,
						),
						'collapsed'             => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'sidebar_width'         => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/export.csv',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $upload,
			)
		);

		register_rest_route(
			$ns,
			'/import.csv',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import' ),
				'permission_callback' => $manage,
				'args'                => array(
					'csv' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * GET /settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( Settings::get_instance()->all() );
	}

	/**
	 * POST /settings (partial).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function set_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$values = $this->known( $request, array_keys( Settings::DEFAULTS ) );
		return rest_ensure_response( Settings::get_instance()->update( $values ) );
	}

	/**
	 * GET /user-settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_user_settings(): \WP_REST_Response {
		return rest_ensure_response( UserSettings::get_instance()->all() );
	}

	/**
	 * POST /user-settings (partial).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function set_user_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$values = $this->known( $request, array_keys( UserSettings::DEFAULTS ) );
		return rest_ensure_response( UserSettings::get_instance()->update( $values ) );
	}

	/**
	 * GET /export.csv. The body is sent raw by serve_csv().
	 *
	 * @return \WP_REST_Response
	 */
	public function export(): \WP_REST_Response {
		$response = new \WP_REST_Response( Csv::get_instance()->export() );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="cph-filebird-folders-' . gmdate( 'Y-m-d' ) . '.csv"' );
		return $response;
	}

	/**
	 * POST /import.csv: uploaded `file`, or a `csv` string.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$files = $request->get_file_params();
		$csv   = null;
		if ( ! empty( $files['file']['tmp_name'] ) && empty( $files['file']['error'] ) && is_uploaded_file( $files['file']['tmp_name'] ) ) {
			$csv = (string) file_get_contents( $files['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} elseif ( is_string( $request['csv'] ) && '' !== $request['csv'] ) {
			$csv = $request['csv'];
		}
		if ( null === $csv ) {
			return new \WP_Error( 'invalid_csv', __( 'Choose a CSV file to import.', 'cph-filebird' ), array( 'status' => 400 ) );
		}

		$result = Csv::get_instance()->import( $csv );
		if ( is_wp_error( $result ) ) {
			return Permissions::error( $result );
		}
		return rest_ensure_response(
			array(
				'folders'     => (int) $result['folders'],
				'assignments' => (int) $result['assignments'],
			)
		);
	}

	/**
	 * Send `/export.csv` as a raw body instead of JSON.
	 *
	 * @param bool              $served  Whether the request was already served.
	 * @param \WP_HTTP_Response $result  Response.
	 * @param \WP_REST_Request  $request Request.
	 * @param \WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public function serve_csv( $served, $result, $request, $server ): bool {
		if ( $served || ! $request instanceof \WP_REST_Request || '/' . Permissions::NAMESPACE . '/export.csv' !== $request->get_route() ) {
			return (bool) $served;
		}
		if ( ! $result instanceof \WP_HTTP_Response || $result->is_error() || ! is_string( $result->get_data() ) ) {
			return (bool) $served;
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return true;
	}

	/**
	 * Params from the request that are known setting keys.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string[]         $keys    Allowed keys.
	 * @return array
	 */
	private function known( \WP_REST_Request $request, array $keys ): array {
		$values = array();
		foreach ( $keys as $key ) {
			if ( $request->has_param( $key ) ) {
				$values[ $key ] = $request[ $key ];
			}
		}
		return $values;
	}
}
