<?php
/**
 * Self-hosted updates from GitHub releases.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird;

defined( 'ABSPATH' ) || exit;

/**
 * Wires Plugin Update Checker to the plugin's GitHub releases.
 *
 * Each release must carry an asset named `cph-filebird.zip`. No licence keys,
 * no other remote calls. Define `CPHFB_DISABLE_UPDATES` as true to switch off,
 * and `CPHFB_GITHUB_TOKEN` to read a private repository.
 */
final class Updater {

	/**
	 * Default repository URL.
	 */
	public const REPO_URL = 'https://github.com/dbreck/cph-filebird/';

	/**
	 * Plugin slug used by the update checker.
	 */
	public const SLUG = 'cph-filebird';

	/**
	 * Name of the release asset that holds the installable zip.
	 */
	public const ASSET_NAME = 'cph-filebird.zip';

	/**
	 * Fully qualified factory class from Plugin Update Checker v5.
	 */
	private const FACTORY = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';

	/**
	 * Singleton instance.
	 *
	 * @var Updater|null
	 */
	private static ?Updater $instance = null;

	/**
	 * The update checker, when one was built.
	 *
	 * @var object|null
	 */
	private ?object $checker = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Updater
	 */
	public static function get_instance(): Updater {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		if ( self::disabled() || ! class_exists( self::FACTORY ) ) {
			return;
		}

		$this->checker = $this->build( self::repo_url(), self::token() );
	}

	/**
	 * Whether updates are switched off by constant.
	 *
	 * @return bool
	 */
	public static function disabled(): bool {
		return defined( 'CPHFB_DISABLE_UPDATES' ) && CPHFB_DISABLE_UPDATES;
	}

	/**
	 * Repository URL, filterable via `cphfb_update_repo_url`.
	 *
	 * @return string
	 */
	public static function repo_url(): string {
		/**
		 * Filters the GitHub repository the plugin updates from.
		 *
		 * @param string $url Repository URL.
		 */
		$url = apply_filters( 'cphfb_update_repo_url', self::REPO_URL );
		return is_string( $url ) && '' !== $url ? $url : self::REPO_URL;
	}

	/**
	 * GitHub token from `CPHFB_GITHUB_TOKEN`, or an empty string.
	 *
	 * @return string
	 */
	public static function token(): string {
		if ( ! defined( 'CPHFB_GITHUB_TOKEN' ) ) {
			return '';
		}
		$token = constant( 'CPHFB_GITHUB_TOKEN' );
		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * The built update checker, or null when updates are off or unavailable.
	 *
	 * @return object|null
	 */
	public function checker(): ?object {
		return $this->checker;
	}

	/**
	 * Build and configure the update checker.
	 *
	 * @param string $url   Repository URL.
	 * @param string $token GitHub token, empty for public repositories.
	 * @return object|null
	 */
	private function build( string $url, string $token ): ?object {
		$factory = self::FACTORY;

		try {
			$checker = $factory::buildUpdateChecker( $url, CPHFB_FILE, self::SLUG );
		} catch ( \Throwable $e ) {
			return null;
		}

		// Require the built asset: the tag's source zipball has no vendor/ or
		// assets/build/, so installing it would break the plugin.
		$api = $checker->getVcsApi();
		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/^' . preg_quote( self::ASSET_NAME, '/' ) . '$/', 2 );
		}
		if ( '' !== $token ) {
			$checker->setAuthentication( $token );
		}

		return $checker;
	}
}
