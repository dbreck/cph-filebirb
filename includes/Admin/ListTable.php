<?php
/**
 * Folder filter, column and bulk move for upload.php list mode.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Admin;

use CPH\FileBird\Model\Assignment;
use CPH\FileBird\Model\Folder;
use CPH\FileBird\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Media list table integration.
 */
final class ListTable {

	/**
	 * Column key.
	 */
	public const COLUMN = 'cphfb_folder';

	/**
	 * Bulk action key.
	 */
	public const BULK_ACTION = 'cphfb_move';

	/**
	 * Request var naming the bulk move target.
	 */
	public const BULK_TARGET = 'cphfb_bulk_folder';

	/**
	 * Singleton instance.
	 *
	 * @var ListTable|null
	 */
	private static ?ListTable $instance = null;

	/**
	 * Folder ID => name, loaded once per request.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $names = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return ListTable
	 */
	public static function get_instance(): ListTable {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ), 10, 2 );
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		foreach ( array( 'folder_created', 'folder_renamed', 'folder_deleted', 'delete_all' ) as $hook ) {
			add_action( 'cphfb_' . $hook, array( $this, 'reset' ) );
		}
	}

	/**
	 * Folder filter dropdown and the bulk move target.
	 *
	 * @param string $post_type Post type.
	 * @param string $which     Tablenav position.
	 * @return void
	 */
	public function render_filter( $post_type = '', $which = 'top' ): void {
		if ( 'attachment' !== $post_type || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
		$current = isset( $_GET[ Query::VAR ] ) ? Query::parse( wp_unslash( $_GET[ Query::VAR ] ) ) : null;
		$current = $current ?? Folder::ALL;
		$counts  = Assignment::get_instance()->counts();

		echo '<label for="filter-by-fbv" class="screen-reader-text">' . esc_html__( 'Filter by folder', 'cph-filebird' ) . '</label>';
		echo '<select name="' . esc_attr( Query::VAR ) . '" id="filter-by-fbv" class="attachment-filters">';
		$this->option( Folder::ALL, __( 'All Folders', 'cph-filebird' ), $counts['all'], $current );
		$this->option( Folder::UNCATEGORIZED, __( 'Uncategorized', 'cph-filebird' ), $counts['uncategorized'], $current );
		foreach ( Folder::get_instance()->flat() as $node ) {
			$this->option( $node['id'], $this->indent( $node ), $counts['folders'][ $node['id'] ] ?? 0, $current );
		}
		echo '</select>';

		echo '<label for="cphfb-bulk-folder" class="screen-reader-text">' . esc_html__( 'Bulk move target folder', 'cph-filebird' ) . '</label>';
		echo '<select name="' . esc_attr( self::BULK_TARGET ) . '" id="cphfb-bulk-folder" class="cphfb-bulk-folder">';
		echo '<option value="">' . esc_html__( 'Move to folder…', 'cph-filebird' ) . '</option>';
		echo '<option value="0">' . esc_html__( 'Uncategorized', 'cph-filebird' ) . '</option>';
		foreach ( Folder::get_instance()->flat() as $node ) {
			echo '<option value="' . esc_attr( (string) $node['id'] ) . '">' . esc_html( $this->indent( $node ) ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Add the Folder column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns[ self::COLUMN ] = __( 'Folder', 'cph-filebird' );
		return $columns;
	}

	/**
	 * Render the Folder column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Attachment ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$folder = Query::get_instance()->folder_of( (int) $post_id );
		$names  = $this->names();
		if ( $folder <= 0 || ! isset( $names[ $folder ] ) ) {
			echo esc_html__( 'Uncategorized', 'cph-filebird' );
			return;
		}
		$url = add_query_arg(
			array(
				'mode'      => 'list',
				Query::VAR => $folder,
			),
			admin_url( 'upload.php' )
		);
		echo '<a href="' . esc_url( $url ) . '">' . esc_html( $names[ $folder ] ) . '</a>';
	}

	/**
	 * Register "Move to folder…".
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_action( $actions ) {
		if ( current_user_can( 'upload_files' ) ) {
			$actions[ self::BULK_ACTION ] = __( 'Move to folder…', 'cph-filebird' );
		}
		return $actions;
	}

	/**
	 * Run the bulk move. Core has already checked the `bulk-media` nonce.
	 *
	 * @param string $location Redirect URL.
	 * @param string $action   Bulk action.
	 * @param int[]  $post_ids Attachment IDs.
	 * @return string
	 */
	public function handle_bulk_action( $location, $action, $post_ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $location;
		}
		check_admin_referer( 'bulk-media' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$raw    = isset( $_REQUEST[ self::BULK_TARGET ] ) ? Query::parse( wp_unslash( $_REQUEST[ self::BULK_TARGET ] ) ) : null;
		$folder = $raw ?? -1;
		if ( ! current_user_can( 'upload_files' ) || $folder < 0 ) {
			return add_query_arg( 'cphfb_moved', 'error', $location );
		}

		$ids    = array_values( array_filter( array_map( 'intval', (array) $post_ids ), static fn( int $id ) => $id > 0 && current_user_can( 'edit_post', $id ) ) );
		$result = Assignment::get_instance()->assign( $folder, $ids );
		if ( is_wp_error( $result ) ) {
			return add_query_arg( 'cphfb_moved', 'error', $location );
		}
		return add_query_arg( 'cphfb_moved', count( $ids ), $location );
	}

	/**
	 * Result notice after a bulk move.
	 *
	 * @return void
	 */
	public function bulk_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['cphfb_moved'] ) || 'upload.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		$raw = sanitize_text_field( wp_unslash( $_GET['cphfb_moved'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'error' === $raw ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Pick a valid folder to move the selected files to.', 'cph-filebird' ) . '</p></div>';
			return;
		}
		$n = absint( $raw );
		/* translators: %d: number of files moved. */
		$msg = sprintf( _n( '%d file moved.', '%d files moved.', $n, 'cph-filebird' ), $n );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Print one filter option.
	 *
	 * @param int    $value   Value.
	 * @param string $label   Label.
	 * @param int    $count   Count.
	 * @param int    $current Selected value.
	 * @return void
	 */
	private function option( int $value, string $label, int $count, int $current ): void {
		printf(
			'<option value="%1$s"%2$s>%3$s (%4$s)</option>',
			esc_attr( (string) $value ),
			selected( $value, $current, false ),
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Depth-indented node name.
	 *
	 * @param array $node Flat node.
	 * @return string
	 */
	private function indent( array $node ): string {
		return str_repeat( "\u{00A0}\u{00A0}\u{00A0}", (int) $node['depth'] ) . $node['name'];
	}

	/**
	 * Folder ID => name map.
	 *
	 * @return array<int,string>
	 */
	private function names(): array {
		if ( null === $this->names ) {
			$this->names = array();
			foreach ( Folder::get_instance()->all() as $row ) {
				$this->names[ $row->id ] = $row->name;
			}
		}
		return $this->names;
	}

	/**
	 * Forget the cached names (tests, or after folder writes in the same request).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->names = null;
	}
}
