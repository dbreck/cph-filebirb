<?php
/**
 * Shared REST permission callbacks and error mapping.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers used by every controller.
 */
final class Permissions {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'cph-filebird/v1';

	/**
	 * Model error code => HTTP status.
	 */
	private const STATUSES = array(
		'folder_not_found'   => 404,
		'folder_name_exists' => 409,
		'cannot_delete'      => 403,
		'db_error'           => 500,
	);

	/**
	 * Logged-in user with `upload_files`.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_upload(): bool|\WP_Error {
		return self::check( 'upload_files' );
	}

	/**
	 * Logged-in user with `manage_options`.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_manage(): bool|\WP_Error {
		return self::check( 'manage_options' );
	}

	/**
	 * Give a model error the right HTTP status, keeping its code and message.
	 *
	 * @param \WP_Error $error Model error.
	 * @return \WP_Error
	 */
	public static function error( \WP_Error $error ): \WP_Error {
		$code   = (string) $error->get_error_code();
		$status = self::STATUSES[ $code ] ?? ( str_starts_with( $code, 'invalid_' ) ? 400 : 500 );
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$error->add_data( array_merge( $data, array( 'status' => $status ) ), $code );
		return $error;
	}

	/**
	 * 401 when logged out, 403 without the capability.
	 *
	 * @param string $cap Capability.
	 * @return true|\WP_Error
	 */
	private static function check( string $cap ): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'cph-filebird' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( $cap ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'cph-filebird' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
