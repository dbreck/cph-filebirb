<?php
/**
 * Updater tests.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Tests;

use CPH\FileBird\Updater;

/**
 * GitHub release update checker wiring.
 */
class UpdaterTest extends \WP_UnitTestCase {

	/**
	 * The library ships as a runtime dependency and the checker is built.
	 */
	public function test_checker_is_built_from_release_assets(): void {
		$this->assertTrue( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) );

		$checker = Updater::get_instance()->checker();
		$this->assertIsObject( $checker );
		$this->assertSame( Updater::SLUG, $checker->slug );

		$api = $checker->getVcsApi();
		$this->assertInstanceOf( '\\YahnisElsts\\PluginUpdateChecker\\v5p7\\Vcs\\GitHubApi', $api );
		$this->assertSame( 'https://github.com/dbreck/cph-filebird', untrailingslashit( $api->getRepositoryUrl() ) );
	}

	/**
	 * The repository URL is filterable, and bad filter output falls back.
	 */
	public function test_repo_url_filter(): void {
		$this->assertSame( Updater::REPO_URL, Updater::repo_url() );

		$custom = static fn() => 'https://github.com/example/fork/';
		add_filter( 'cphfb_update_repo_url', $custom );
		$this->assertSame( 'https://github.com/example/fork/', Updater::repo_url() );
		remove_filter( 'cphfb_update_repo_url', $custom );

		add_filter( 'cphfb_update_repo_url', '__return_empty_string' );
		$this->assertSame( Updater::REPO_URL, Updater::repo_url() );
		remove_filter( 'cphfb_update_repo_url', '__return_empty_string' );
	}

	/**
	 * No token constant means no authentication, and updates stay on by default.
	 */
	public function test_defaults_without_constants(): void {
		$this->assertFalse( defined( 'CPHFB_GITHUB_TOKEN' ) );
		$this->assertSame( '', Updater::token() );
		$this->assertFalse( Updater::disabled() );
	}

	/**
	 * Nothing else phones home: the only update checker registered for our file is ours.
	 */
	public function test_main_file_declares_update_uri(): void {
		$data = get_file_data( CPHFB_FILE, array( 'uri' => 'Update URI', 'version' => 'Version' ) );
		$this->assertSame( 'https://github.com/dbreck/cph-filebird', $data['uri'] );
		$this->assertSame( CPHFB_VERSION, $data['version'] );
	}
}
