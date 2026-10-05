<?php
/**
 * Uninstall tests.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Tests;

/**
 * uninstall.php removes our data and keeps FileBird's.
 */
class UninstallTest extends TestCase {

	/**
	 * Own options, transients and user meta go; folder tables and fbv_* options stay.
	 */
	public function test_uninstall_keeps_folder_data(): void {
		global $wpdb;

		$folder = $this->make( 'Keep me' );
		[ $att ] = $this->attachments( 1 );
		$this->assignments()->assign( $folder, array( $att ) );

		update_option( 'cphfb_settings', array( 'x' => 1 ) );
		update_option( 'cphfb_db_version', '1' );
		set_transient( 'cphfb_counts', array( 1 => 1 ) );
		set_transient( 'cphfb_settings_notice_1', 'hi' );
		update_option( 'fbv_folder_colors', array( $folder => '#ff0000' ) );
		update_option( 'fbv_settings', array( 'theme' => 'default' ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, 'cphfb_user_settings', array( 'a' => 1 ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'cph-filebirb/cph-filebirb.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';
		wp_cache_flush();

		$this->assertFalse( get_option( 'cphfb_settings' ) );
		$this->assertFalse( get_option( 'cphfb_db_version' ) );
		$this->assertFalse( get_transient( 'cphfb_counts' ) );
		$this->assertFalse( get_transient( 'cphfb_settings_notice_1' ) );
		$this->assertSame( '', get_user_meta( $user, 'cphfb_user_settings', true ) );

		$this->assertSame( array( $folder => '#ff0000' ), get_option( 'fbv_folder_colors' ) );
		$this->assertSame( array( 'theme' => 'default' ), get_option( 'fbv_settings' ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fbv" ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fbv_attachment_folder" ) );

		// Restore what the rest of the suite expects.
		update_option( 'cphfb_db_version', \CPH\FileBirb\Install::DB_VERSION );
	}
}
