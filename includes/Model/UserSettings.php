<?php
/**
 * Per-user settings (user meta `cphfb_user_settings`).
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Model;

use CPH\FileBirb\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Schema-validated per-user settings. `$user_id` defaults to the current user.
 */
final class UserSettings {

	/**
	 * User meta key.
	 */
	public const META_KEY = 'cphfb_user_settings';

	/**
	 * Defaults; also the list of valid keys.
	 */
	public const DEFAULTS = array(
		'default_upload_folder' => -1,
		'collapsed'             => array(),
		'sidebar_width'         => 0,
		'selected_folder'       => -1,
	);

	/**
	 * Singleton instance.
	 *
	 * @var UserSettings|null
	 */
	private static ?UserSettings $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return UserSettings
	 */
	public static function get_instance(): UserSettings {
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
	 * One setting. `default_upload_folder` falls back to -1 if its folder is gone and runs
	 * through the `user_default_folder` filter pair.
	 *
	 * @param string   $key     Setting key.
	 * @param mixed    $default Fallback for unknown or unset keys (schema default when null).
	 * @param int|null $user_id User ID.
	 * @return mixed
	 */
	public function get( string $key, $default = null, ?int $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		$stored  = $this->stored( $user_id );

		if ( array_key_exists( $key, $stored ) ) {
			$value = $stored[ $key ];
		} elseif ( null !== $default || ! array_key_exists( $key, self::DEFAULTS ) ) {
			$value = $default;
		} else {
			$value = self::DEFAULTS[ $key ];
		}

		if ( 'default_upload_folder' === $key ) {
			$value = (int) $value;
			if ( $value > 0 && ! Folder::get_instance()->exists( $value ) ) {
				$value = -1;
			}
			$value = (int) Hooks::filter( 'user_default_folder', $value, $user_id );
		}

		return $value;
	}

	/**
	 * Set one setting.
	 *
	 * @param string   $key     Setting key.
	 * @param mixed    $value   Value.
	 * @param int|null $user_id User ID.
	 * @return bool|\WP_Error
	 */
	public function set( string $key, $value, ?int $user_id = null ): bool|\WP_Error {
		if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
			return new \WP_Error( 'invalid_setting', __( 'Unknown setting.', 'cph-filebirb' ) );
		}
		$this->update( array( $key => $value ), $user_id );
		return true;
	}

	/**
	 * All settings for a user, merged over defaults.
	 *
	 * @param int|null $user_id User ID.
	 * @return array
	 */
	public function all( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$all     = array();
		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			$all[ $key ] = $this->get( $key, null, $user_id );
		}
		return $all;
	}

	/**
	 * Merge and save several settings. Unknown keys are ignored.
	 *
	 * @param array    $values  Key => value.
	 * @param int|null $user_id User ID.
	 * @return array All settings after saving.
	 */
	public function update( array $values, ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$stored  = array_merge( self::DEFAULTS, $this->stored( $user_id ) );
		foreach ( array_intersect_key( $values, self::DEFAULTS ) as $key => $value ) {
			$stored[ $key ] = $this->sanitize( $key, $value );
		}
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_KEY, $stored );
		}
		return $this->all( $user_id );
	}

	/**
	 * Sanitize one value per the schema.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	public function sanitize( string $key, $value ) {
		switch ( $key ) {
			case 'default_upload_folder':
			case 'selected_folder':
				return max( -1, (int) $value );
			case 'collapsed':
				return array_values( array_unique( array_filter( array_map( 'intval', (array) $value ), static fn( int $id ) => $id > 0 ) ) );
			case 'sidebar_width':
				return max( 0, (int) $value );
		}
		return $value;
	}

	/**
	 * Stored meta, sanitized and limited to known keys.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function stored( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( array_intersect_key( $stored, self::DEFAULTS ) as $key => $value ) {
			$out[ $key ] = $this->sanitize( $key, $value );
		}
		return $out;
	}
}
