<?php
/**
 * Admin\SettingsPage tests.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Tests;

use CPH\FileBird\Admin\SettingsPage;
use CPH\FileBird\Model\Settings;

/**
 * Sanitiser, rendering, action links, permission guard.
 */
class SettingsPageTest extends TestCase {

	public function test_sanitize_fills_unchecked_booleans(): void {
		update_option( Settings::OPTION, array( 'include_subfolders_in_count' => true ) );
		$out = SettingsPage::get_instance()->sanitize(
			array(
				'default_sort' => 'name_asc',
				'bogus'        => 'x',
			)
		);
		$this->assertSame(
			array(
				'default_sort'                => 'name_asc',
				'include_subfolders_in_count' => false,
				'include_subfolders_in_query' => false,
			),
			$out
		);
		$this->assertSame( 'ord', SettingsPage::get_instance()->sanitize( array( 'default_sort' => '<script>' ) )['default_sort'] );
		$this->assertSame( Settings::DEFAULTS, SettingsPage::get_instance()->sanitize( 'garbage' ) );
	}

	public function test_render_requires_capability_and_shows_status(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		SettingsPage::get_instance()->render();
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->make( 'Branding' );
		SettingsPage::get_instance()->register_settings();
		ob_start();
		SettingsPage::get_instance()->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'cphfb_export_csv', $html );
		$this->assertStringContainsString( 'cphfb_import_csv', $html );
		$this->assertStringContainsString( 'cphfb_cleanup', $html );
		$this->assertStringContainsString( 'name="cphfb_settings[default_sort]"', $html );
		$this->assertStringContainsString( CPHFB_VERSION, $html );
		$this->assertMatchesRegularExpression( '/name="_wpnonce"/', $html );
	}

	public function test_action_link(): void {
		$links = apply_filters( 'plugin_action_links_' . plugin_basename( CPHFB_FILE ), array( 'deactivate' => 'x' ) );
		$this->assertStringContainsString( 'upload.php?page=cphfb-settings', $links[0] );
	}

	public function test_admin_post_actions_reject_non_admins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( \WPDieException::class );
		SettingsPage::get_instance()->cleanup();
	}

	public function test_admin_post_actions_reject_bad_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'nope';
		$this->expectException( \WPDieException::class );
		SettingsPage::get_instance()->cleanup();
	}
}
