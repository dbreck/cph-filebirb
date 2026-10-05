<?php
/**
 * Plugin Name:       CPH FileBird
 * Description:       Media library folders by Clear pH. Data-compatible replacement for FileBird.
 * Version:           0.1.0
 * Author:            Clear pH
 * Author URI:        https://clearph.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cph-filebird
 * Requires at least: 6.5
 * Requires PHP:      8.1
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'CPHFB_VERSION', '0.1.0' );
define( 'CPHFB_FILE', __FILE__ );
define( 'CPHFB_PATH', plugin_dir_path( __FILE__ ) );
define( 'CPHFB_URL', plugin_dir_url( __FILE__ ) );

if ( ! is_readable( CPHFB_PATH . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'CPH FileBird is missing its autoloader. Run "composer install" in the plugin folder or reinstall the plugin.', 'cph-filebird' );
			echo '</p></div>';
		}
	);
	return;
}

require_once CPHFB_PATH . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \CPH\FileBird\Install::class, 'activate' ) );

add_action( 'plugins_loaded', array( \CPH\FileBird\Plugin::class, 'get_instance' ) );
