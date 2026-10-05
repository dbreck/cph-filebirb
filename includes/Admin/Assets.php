<?php
/**
 * Admin script and style loading for the folder sidebar.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Admin;

use CPH\FileBird\Model\Assignment;
use CPH\FileBird\Model\Folder;
use CPH\FileBird\Model\Settings;
use CPH\FileBird\Model\UserSettings;
use CPH\FileBird\Query;
use CPH\FileBird\Rest\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the sidebar bundle on the Media Library and prints its mount point.
 */
final class Assets {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'cphfb-admin';

	/**
	 * Singleton instance.
	 *
	 * @var Assets|null
	 */
	private static ?Assets $instance = null;

	/**
	 * Whether the bundle has been enqueued this request.
	 *
	 * @var bool
	 */
	private bool $enqueued = false;

	/**
	 * Get the singleton instance.
	 *
	 * @return Assets
	 */
	public static function get_instance(): Assets {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'load-upload.php', array( $this, 'on_load_upload' ) );
		add_action( 'wp_enqueue_media', array( $this, 'on_enqueue_media' ) );
		add_action( 'load-media-new.php', array( $this, 'on_load_media_new' ) );
	}

	/**
	 * Media Library screen: wire the page hooks.
	 *
	 * @return void
	 */
	public function on_load_upload(): void {
		if ( ! current_user_can( 'upload_files' ) || ! $this->built() ) {
			return;
		}
		$this->maybe_redirect_list();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_head', array( $this, 'print_width_style' ) );
		add_action( 'in_admin_header', array( $this, 'print_root' ) );
	}

	/**
	 * Any `wp_enqueue_media()` call in wp-admin (post and block editors, page
	 * builders, theme options, widgets, Customizer...). Only prototype hooks run
	 * on load; the tree mounts when a media frame builds a library browser.
	 *
	 * @return void
	 */
	public function on_enqueue_media(): void {
		if ( ! is_admin() || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$this->enqueue( true );
	}

	/**
	 * Media > Add New: the "Upload to" picker on the plupload form.
	 *
	 * @return void
	 */
	public function on_load_media_new(): void {
		if ( ! current_user_can( 'upload_files' ) || ! $this->built() ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the bundle and its data. Safe to call more than once.
	 *
	 * @param bool $media Whether `wp.media` is on the page (load after media-views).
	 * @return void
	 */
	public function enqueue( $media = false ): void {
		if ( $this->enqueued || ! current_user_can( 'upload_files' ) || ! $this->built() ) {
			return;
		}
		$this->enqueued = true;

		$asset = include CPHFB_PATH . 'assets/build/index.asset.php';
		$deps  = (array) ( $asset['dependencies'] ?? array() );
		$mode  = $this->screen_mode();
		if ( 'grid' === $mode ) {
			// Load after media-grid so we can hook the Manage frame before it is built.
			$deps[] = 'media-grid';
		}
		if ( 'grid' === $mode || true === $media ) {
			$deps[] = 'media-views';
		}
		$version = (string) ( $asset['version'] ?? CPHFB_VERSION );

		wp_enqueue_script( self::HANDLE, CPHFB_URL . 'assets/build/index.js', array_unique( $deps ), $version, true );
		wp_set_script_translations( self::HANDLE, 'cph-filebird', CPHFB_PATH . 'languages' );
		wp_add_inline_script( self::HANDLE, 'window.cphfbData = ' . wp_json_encode( $this->data( $mode ) ) . ';', 'before' );

		if ( is_readable( CPHFB_PATH . 'assets/build/index.css' ) ) {
			wp_enqueue_style( self::HANDLE, CPHFB_URL . 'assets/build/index.css', array(), $version );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Add the sidebar layout class before first paint.
	 *
	 * @param string $classes Space-separated classes.
	 * @return string
	 */
	public function body_class( $classes ): string {
		return $classes . ' cphfb-has-sidebar';
	}

	/**
	 * Saved sidebar width as a CSS variable, so the layout does not shift on load.
	 *
	 * @return void
	 */
	public function print_width_style(): void {
		$width = (int) UserSettings::get_instance()->get( 'sidebar_width' );
		if ( $width > 0 ) {
			$width = max( 220, min( 520, $width ) );
			printf( "<style id=\"cphfb-width\">body.cphfb-has-sidebar{--cphfb-width:%dpx}</style>\n", (int) $width );
		}
	}

	/**
	 * The mount point, inside `#wpbody`, with a skeleton until the script runs.
	 * The inline script restores the collapsed-rail state before paint.
	 *
	 * @return void
	 */
	public function print_root(): void {
		echo '<div id="cphfb-root" aria-busy="true"><div class="cphfb-skeleton" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div></div>';
		$key = wp_json_encode( 'cphfb:' . $this->site_key() . ':' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded string.
		echo "<script>try{var k={$key};if(JSON.parse(localStorage.getItem(k+'rail')||'false')){document.body.classList.add('cphfb-is-rail')}var w=JSON.parse(localStorage.getItem(k+'width')||'0');if(w&&!document.getElementById('cphfb-width')){document.body.style.setProperty('--cphfb-width',Math.max(220,Math.min(520,w))+'px')}}catch(e){}</script>\n";
	}

	/**
	 * List mode opened without a folder: go to the user's last folder.
	 * Only for a bare `upload.php` (optionally `?mode=list`) GET, so filters,
	 * searches, paging and bulk actions are never redirected.
	 *
	 * @return void
	 */
	private function maybe_redirect_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( 'list' !== $this->screen_mode() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		$keys = array_diff( array_keys( $_GET ), array( 'mode' ) );
		// phpcs:enable
		if ( $keys ) {
			return;
		}
		$selected = (int) UserSettings::get_instance()->get( 'selected_folder' );
		if ( Folder::ALL === $selected || ( $selected > 0 && ! Folder::get_instance()->exists( $selected ) ) ) {
			return;
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'mode'     => 'list',
					Query::VAR => $selected,
				),
				admin_url( 'upload.php' )
			)
		);
		exit;
	}

	/**
	 * Data handed to the script as `window.cphfbData`.
	 *
	 * @param string $mode Screen mode.
	 * @return array
	 */
	private function data( string $mode ): array {
		$counts = Assignment::get_instance()->counts();
		$tree   = $this->fill_counts( Folder::get_instance()->tree(), $counts['folders'] ?? array() );
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return array(
			'restRoot'          => esc_url_raw( rest_url() ),
			'namespace'         => Permissions::NAMESPACE,
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'screen'            => array(
				'id'   => $screen ? $screen->id : '',
				'mode' => $mode,
			),
			'userSettings'      => UserSettings::get_instance()->all(),
			'canManage'         => current_user_can( 'upload_files' ),
			'canManageSettings' => current_user_can( 'manage_options' ),
			'settings'          => array(
				'includeSubfolders' => (bool) Settings::get_instance()->get( 'include_subfolders_in_query' ),
			),
			'uploadUrl'         => admin_url( 'upload.php' ),
			'siteKey'           => $this->site_key(),
			'tree'              => $tree,
			'counts'            => $counts,
		);
	}

	/**
	 * `grid` or `list` on upload.php, empty elsewhere. Mirrors core's logic.
	 *
	 * @return string
	 */
	private function screen_mode(): string {
		global $pagenow;
		if ( 'upload.php' !== $pagenow ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mode  = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		$modes = array( 'grid', 'list' );
		if ( ! in_array( $mode, $modes, true ) ) {
			$mode = (string) get_user_option( 'media_library_mode', get_current_user_id() );
		}
		return in_array( $mode, $modes, true ) ? $mode : 'grid';
	}

	/**
	 * Short per-site key for localStorage namespacing.
	 *
	 * @return string
	 */
	private function site_key(): string {
		return substr( md5( home_url() ), 0, 10 );
	}

	/**
	 * Whether the build exists.
	 *
	 * @return bool
	 */
	private function built(): bool {
		return is_readable( CPHFB_PATH . 'assets/build/index.asset.php' );
	}

	/**
	 * Fill each node's `count` recursively.
	 *
	 * @param array           $nodes  Nested nodes.
	 * @param array<int, int> $counts Folder ID => count.
	 * @return array
	 */
	private function fill_counts( array $nodes, array $counts ): array {
		foreach ( $nodes as &$node ) {
			$node['count']    = (int) ( $counts[ $node['id'] ] ?? 0 );
			$node['children'] = $this->fill_counts( $node['children'], $counts );
		}
		unset( $node );
		return $nodes;
	}
}
