<?php
/**
 * Plugin bootstrap.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird;

defined( 'ABSPATH' ) || exit;

/**
 * Boots every component, unless FileBird itself is active.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Component classes booted in order. Each is skipped if not present yet.
	 *
	 * @var string[]
	 */
	private const COMPONENTS = array(
		'Compat\\LegacyHooks',
		'Query',
		'Upload',
		'Rest\\FolderController',
		'Rest\\AttachmentController',
		'Rest\\SettingsController',
		'Admin\\ListTable',
		'Admin\\AttachmentFields',
		'Admin\\Assets',
		'Admin\\SettingsPage',
		'Updater',
	);

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		if ( self::filebird_active() ) {
			add_action( 'admin_notices', array( $this, 'conflict_notice' ) );
			return;
		}

		Install::maybe_upgrade();

		foreach ( self::COMPONENTS as $component ) {
			$this->boot( $component );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->boot( 'Cli\\Commands' );
		}

		add_action( 'deleted_user', array( $this, 'on_deleted_user' ), 10, 3 );
	}

	/**
	 * Whether FileBird (free or Pro) is loaded.
	 *
	 * @return bool
	 */
	public static function filebird_active(): bool {
		return class_exists( '\\FileBird\\Plugin' ) || defined( 'NJFB_VERSION' );
	}

	/**
	 * Admin notice shown when FileBird is active.
	 *
	 * @return void
	 */
	public function conflict_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'CPH FileBird is paused because FileBird is active. Deactivate FileBird to use CPH FileBird. Your folders are shared, nothing is lost.', 'cph-filebird' );
		echo '</p></div>';
	}

	/**
	 * Move folder ownership off a deleted user.
	 *
	 * @param int      $user_id  Deleted user ID.
	 * @param int|null $reassign Reassign-to user ID, if any.
	 * @return void
	 */
	public function on_deleted_user( $user_id, $reassign = null ): void {
		Model\Folder::get_instance()->reassign_author( (int) $user_id, (int) $reassign );
	}

	/**
	 * Instantiate a component if its class exists.
	 *
	 * @param string $relative Class name relative to this namespace.
	 * @return void
	 */
	private function boot( string $relative ): void {
		$class = __NAMESPACE__ . '\\' . $relative;
		if ( class_exists( $class ) && method_exists( $class, 'get_instance' ) ) {
			$class::get_instance();
		}
	}
}
