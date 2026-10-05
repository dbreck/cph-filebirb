<?php
/**
 * Global settings (option `cphfb_settings`).
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Schema-validated global settings.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'cphfb_settings';

	/**
	 * Defaults; also the list of valid keys.
	 */
	public const DEFAULTS = array(
		'default_sort'                => 'ord',
		'include_subfolders_in_count' => false,
		'include_subfolders_in_query' => false,
	);

	/**
	 * Allowed `default_sort` values.
	 */
	public const SORTS = array( 'ord', 'name_asc', 'name_desc' );

	/**
	 * Singleton instance.
	 *
	 * @var Settings|null
	 */
	private static ?Settings $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Settings
	 */
	public static function get_instance(): Settings {
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
	 * One setting. Unknown keys, or unset keys when `$default` is given, return `$default`.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->stored();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		if ( null !== $default || ! array_key_exists( $key, self::DEFAULTS ) ) {
			return $default;
		}
		return self::DEFAULTS[ $key ];
	}

	/**
	 * Set one setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 * @return bool|\WP_Error True, or `invalid_setting` for an unknown key.
	 */
	public function set( string $key, $value ): bool|\WP_Error {
		if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
			return new \WP_Error( 'invalid_setting', __( 'Unknown setting.', 'cph-filebird' ) );
		}
		$this->update( array( $key => $value ) );
		return true;
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public function all(): array {
		return array_merge( self::DEFAULTS, $this->stored() );
	}

	/**
	 * Merge and save several settings. Unknown keys are ignored.
	 *
	 * @param array $values Key => value.
	 * @return array All settings after saving.
	 */
	public function update( array $values ): array {
		$all = $this->all();
		foreach ( array_intersect_key( $values, self::DEFAULTS ) as $key => $value ) {
			$all[ $key ] = $this->sanitize( $key, $value );
		}
		update_option( self::OPTION, $all, false );
		Assignment::get_instance()->invalidate_counts();
		return $all;
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
			case 'default_sort':
				return in_array( $value, self::SORTS, true ) ? $value : self::DEFAULTS['default_sort'];
			case 'include_subfolders_in_count':
			case 'include_subfolders_in_query':
				return (bool) rest_sanitize_boolean( $value );
		}
		return $value;
	}

	/**
	 * Stored option, sanitized and limited to known keys.
	 *
	 * @return array
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
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
