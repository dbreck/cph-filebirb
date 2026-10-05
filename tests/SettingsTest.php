<?php
/**
 * Settings and UserSettings tests.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Tests;

use CPH\FileBird\Model\Settings;
use CPH\FileBird\Model\UserSettings;

/**
 * Schema, defaults, sanitisation.
 */
class SettingsTest extends TestCase {

	public function test_global_defaults_and_sanitise(): void {
		$s = Settings::get_instance();
		$this->assertSame( Settings::DEFAULTS, $s->all() );
		$this->assertSame( 'ord', $s->get( 'default_sort' ) );
		$this->assertSame( 'fallback', $s->get( 'nope', 'fallback' ) );

		$s->update(
			array(
				'default_sort'                => 'name_desc',
				'include_subfolders_in_query' => '1',
				'user_mode'                   => true,
			)
		);
		$this->assertSame( 'name_desc', $s->get( 'default_sort' ) );
		$this->assertTrue( $s->get( 'include_subfolders_in_query' ) );
		$this->assertArrayNotHasKey( 'user_mode', get_option( Settings::OPTION ) );

		$this->assertTrue( $s->set( 'default_sort', 'bogus' ) );
		$this->assertSame( 'ord', $s->get( 'default_sort' ) );
		$this->assertSame( 'invalid_setting', $s->set( 'user_mode', true )->get_error_code() );
	}

	public function test_user_settings(): void {
		$u    = UserSettings::get_instance();
		$user = self::factory()->user->create();
		$this->assertSame( UserSettings::DEFAULTS, $u->all( $user ) );

		$folder = $this->make( 'Uploads' );
		$u->update(
			array(
				'default_upload_folder' => (string) $folder,
				'collapsed'             => array( '3', 3, -1, 'x', 9 ),
				'sidebar_width'         => -40,
				'selected_folder'       => 0,
				'junk'                  => 1,
			),
			$user
		);
		$this->assertSame(
			array(
				'default_upload_folder' => $folder,
				'collapsed'             => array( 3, 9 ),
				'sidebar_width'         => 0,
				'selected_folder'       => 0,
			),
			$u->all( $user )
		);

		// Other users are untouched.
		$this->assertSame( -1, $u->get( 'default_upload_folder', null, self::factory()->user->create() ) );

		// Missing folder falls back to -1.
		$this->folders()->delete( $folder );
		$this->assertSame( -1, $u->get( 'default_upload_folder', null, $user ) );

		$this->assertTrue( $u->set( 'sidebar_width', 320, $user ) );
		$this->assertSame( 320, $u->get( 'sidebar_width', null, $user ) );
		$this->assertSame( 'invalid_setting', $u->set( 'nope', 1, $user )->get_error_code() );
	}

	public function test_user_default_folder_filter_pair(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		add_filter( 'cphfb_user_default_folder', static fn( $id, $uid ) => $uid === $user ? 5 : $id, 10, 2 );
		add_filter( 'fbv_user_default_folder', static fn( $id ) => $id + 1 );
		$this->assertSame( 6, UserSettings::get_instance()->get( 'default_upload_folder' ) );
	}
}
