<?php
/**
 * Plugin Name:       CPH FileBirb
 * Description:       Media library folders by Clear pH. Data-compatible replacement for FileBird.
 * Version:           0.1.1
 * Author:            Clear pH
 * Author URI:        https://clearph.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cph-filebirb
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Update URI:        https://github.com/dbreck/cph-filebirb
 *
 * @package CPH\FileBirb
 */

// This file must stay parseable on PHP 7.x so the version guard below can
// show a notice instead of a fatal. Keep 8.x-only syntax inside includes/.

defined( 'ABSPATH' ) || exit;

define( 'CPHFB_VERSION', '0.1.1' );
define( 'CPHFB_FILE', __FILE__ );
define( 'CPHFB_PATH', plugin_dir_path( __FILE__ ) );
define( 'CPHFB_URL', plugin_dir_url( __FILE__ ) );
define( 'CPHFB_MIN_PHP', '8.1' );
define( 'CPHFB_MIN_WP', '6.5' );

/**
 * Show an admin error notice and stop loading.
 *
 * @param string $message Plain-text message.
 * @return void
 */
function cphfb_boot_notice( $message ) {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	);
}

if ( version_compare( PHP_VERSION, CPHFB_MIN_PHP, '<' ) || version_compare( get_bloginfo( 'version' ), CPHFB_MIN_WP, '<' ) ) {
	cphfb_boot_notice(
		sprintf(
			/* translators: 1: required PHP version, 2: required WordPress version, 3: current PHP version, 4: current WordPress version. */
			__( 'CPH FileBirb needs PHP %1$s+ and WordPress %2$s+ (this site runs PHP %3$s, WordPress %4$s). It is not running.', 'cph-filebirb' ),
			CPHFB_MIN_PHP,
			CPHFB_MIN_WP,
			PHP_VERSION,
			get_bloginfo( 'version' )
		)
	);
	return;
}

if ( ! is_readable( CPHFB_PATH . 'vendor/autoload.php' ) ) {
	cphfb_boot_notice( __( 'CPH FileBirb is missing its autoloader. Run "composer install" in the plugin folder or reinstall the plugin.', 'cph-filebirb' ) );
	return;
}

require_once CPHFB_PATH . 'vendor/autoload.php';

/**
 * Activation callback. Writes nothing while FileBird is loaded, so activating
 * alongside FileBird leaves its data and options untouched. On a network
 * activation each site installs lazily on its first load (Install::maybe_upgrade).
 *
 * @return void
 */
function cphfb_activate() {
	if ( \CPH\FileBirb\Plugin::filebird_active() ) {
		return;
	}
	\CPH\FileBirb\Install::activate();
}

register_activation_hook( __FILE__, 'cphfb_activate' );

add_action( 'plugins_loaded', array( \CPH\FileBirb\Plugin::class, 'get_instance' ) );
