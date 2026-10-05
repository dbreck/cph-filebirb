<?php
/**
 * Media → Folder Settings screen.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Admin;

use CPH\FileBirb\Csv;
use CPH\FileBirb\Install;
use CPH\FileBirb\Model\Assignment;
use CPH\FileBirb\Model\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plain PHP settings screen built on the Settings API, plus CSV and cleanup tools.
 */
final class SettingsPage {

	/**
	 * Page slug under upload.php.
	 */
	public const SLUG = 'cphfb-settings';

	/**
	 * Settings API option group.
	 */
	public const GROUP = 'cphfb_settings_group';

	/**
	 * Capability for everything on this screen.
	 */
	public const CAP = 'manage_options';

	/**
	 * Transient key prefix for one-shot notices (suffixed with the user ID).
	 */
	private const NOTICE = 'cphfb_settings_notice_';

	/**
	 * Singleton instance.
	 *
	 * @var SettingsPage|null
	 */
	private static ?SettingsPage $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return SettingsPage
	 */
	public static function get_instance(): SettingsPage {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_cphfb_export_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_cphfb_import_csv', array( $this, 'import_csv' ) );
		add_action( 'admin_post_cphfb_cleanup', array( $this, 'cleanup' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CPHFB_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Page URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'upload.php?page=' . self::SLUG );
	}

	/**
	 * Add the submenu under Media.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_submenu_page(
			'upload.php',
			__( 'Folder Settings', 'cph-filebirb' ),
			__( 'Folder Settings', 'cph-filebirb' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the option, section and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings::DEFAULTS,
			)
		);

		add_settings_section( 'cphfb_general', __( 'Folders', 'cph-filebirb' ), '__return_false', self::SLUG );

		add_settings_field( 'default_sort', __( 'Default folder sort', 'cph-filebirb' ), array( $this, 'field_sort' ), self::SLUG, 'cphfb_general', array( 'label_for' => 'cphfb-default-sort' ) );
		add_settings_field(
			'include_subfolders_in_count',
			__( 'Folder counts', 'cph-filebirb' ),
			array( $this, 'field_checkbox' ),
			self::SLUG,
			'cphfb_general',
			array(
				'key'   => 'include_subfolders_in_count',
				'label' => __( 'Include files in subfolders in each folder\'s count', 'cph-filebirb' ),
			)
		);
		add_settings_field(
			'include_subfolders_in_query',
			__( 'Folder view', 'cph-filebirb' ),
			array( $this, 'field_checkbox' ),
			self::SLUG,
			'cphfb_general',
			array(
				'key'   => 'include_subfolders_in_query',
				'label' => __( 'Show files in subfolders when a folder is selected', 'cph-filebirb' ),
			)
		);
	}

	/**
	 * Sanitize the submitted option through the model's sanitiser.
	 *
	 * Checkboxes are absent from the POST when unchecked, so every boolean key is filled in.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$settings = Settings::get_instance();
		$out      = array();
		foreach ( Settings::DEFAULTS as $key => $default ) {
			$value       = is_bool( $default ) ? ! empty( $input[ $key ] ) : ( $input[ $key ] ?? $default );
			$out[ $key ] = $settings->sanitize( $key, $value );
		}
		Assignment::get_instance()->invalidate_counts();
		return $out;
	}

	/**
	 * Default sort select.
	 *
	 * @return void
	 */
	public function field_sort(): void {
		$labels  = array(
			'ord'       => __( 'Custom order', 'cph-filebirb' ),
			'name_asc'  => __( 'Name (A–Z)', 'cph-filebirb' ),
			'name_desc' => __( 'Name (Z–A)', 'cph-filebirb' ),
		);
		$current = Settings::get_instance()->get( 'default_sort' );
		echo '<select id="cphfb-default-sort" name="' . esc_attr( Settings::OPTION ) . '[default_sort]">';
		foreach ( Settings::SORTS as $sort ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $sort ), selected( $current, $sort, false ), esc_html( $labels[ $sort ] ?? $sort ) );
		}
		echo '</select>';
	}

	/**
	 * Boolean setting checkbox.
	 *
	 * @param array $args { key: string, label: string }.
	 * @return void
	 */
	public function field_checkbox( array $args ): void {
		$key = $args['key'];
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( Settings::OPTION ),
			esc_attr( $key ),
			checked( (bool) Settings::get_instance()->get( $key ), true, false ),
			esc_html( $args['label'] )
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$counts = Assignment::get_instance()->counts();
		$status = array(
			__( 'Folders', 'cph-filebirb' )              => number_format_i18n( count( $counts['folders'] ) ),
			__( 'Assigned attachments', 'cph-filebirb' ) => number_format_i18n( $counts['all'] - $counts['uncategorized'] ),
			__( 'Uncategorized', 'cph-filebirb' )        => number_format_i18n( $counts['uncategorized'] ),
			__( 'Database version', 'cph-filebirb' )     => (string) get_option( Install::VERSION_OPTION, '' ),
			__( 'Plugin version', 'cph-filebirb' )       => CPHFB_VERSION,
		);
		$post   = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Folder Settings', 'cph-filebirb' ); ?></h1>
			<?php $this->render_notice(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Tools', 'cph-filebirb' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Export CSV', 'cph-filebirb' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $post ); ?>">
							<input type="hidden" name="action" value="cphfb_export_csv" />
							<?php wp_nonce_field( 'cphfb_export_csv' ); ?>
							<?php submit_button( __( 'Download CSV', 'cph-filebirb' ), 'secondary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Every folder and its attachment IDs, in a format FileBird can also read.', 'cph-filebirb' ); ?></p>
						</form>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cphfb-import-file"><?php esc_html_e( 'Import CSV', 'cph-filebirb' ); ?></label></th>
					<td>
						<form method="post" action="<?php echo esc_url( $post ); ?>" enctype="multipart/form-data">
							<input type="hidden" name="action" value="cphfb_import_csv" />
							<?php wp_nonce_field( 'cphfb_import_csv' ); ?>
							<input type="file" id="cphfb-import-file" name="cphfb_csv" accept=".csv,text/csv" required />
							<?php submit_button( __( 'Import', 'cph-filebirb' ), 'secondary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Folders with the same name under the same parent are merged.', 'cph-filebirb' ); ?></p>
						</form>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Clean up', 'cph-filebirb' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $post ); ?>">
							<input type="hidden" name="action" value="cphfb_cleanup" />
							<?php wp_nonce_field( 'cphfb_cleanup' ); ?>
							<?php submit_button( __( 'Remove orphaned rows', 'cph-filebirb' ), 'secondary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Deletes folder assignments whose folder or attachment no longer exists.', 'cph-filebirb' ); ?></p>
						</form>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Status', 'cph-filebirb' ); ?></h2>
			<table class="widefat striped" style="max-width:40em">
				<tbody>
				<?php foreach ( $status as $label => $value ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Stream the CSV export as a download.
	 *
	 * @return void
	 */
	public function export_csv(): void {
		$this->guard( 'cphfb_export_csv' );

		$filename = sprintf( 'cph-filebirb-%s.csv', gmdate( 'Y-m-d' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo Csv::get_instance()->export(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, not HTML.
		exit;
	}

	/**
	 * Handle a CSV upload.
	 *
	 * @return void
	 */
	public function import_csv(): void {
		$this->guard( 'cphfb_import_csv' );

		$file = $_FILES['cphfb_csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); tmp_name is checked with is_uploaded_file.
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$this->redirect( 'error', __( 'No file was uploaded.', 'cph-filebirb' ) );
		}

		// Checked before reading; Csv::import() checks again for the REST and CLI paths.
		if ( (int) filesize( (string) $file['tmp_name'] ) > Csv::max_bytes() ) {
			$this->redirect( 'error', __( 'That CSV file is too large.', 'cph-filebirb' ) );
		}

		$result = Csv::get_instance()->import( (string) file_get_contents( (string) $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}

		/* translators: 1: folder count, 2: assignment count. */
		$this->redirect( 'success', sprintf( __( 'Imported %1$d folders and %2$d assignments.', 'cph-filebirb' ), $result['folders'], $result['assignments'] ) );
	}

	/**
	 * Remove orphaned assignment rows.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		$this->guard( 'cphfb_cleanup' );
		$removed = Assignment::get_instance()->cleanup_orphans();
		/* translators: %d: number of rows removed. */
		$this->redirect( 'success', sprintf( _n( 'Removed %d orphaned row.', 'Removed %d orphaned rows.', $removed, 'cph-filebirb' ), $removed ) );
	}

	/**
	 * Add a "Settings" link to the plugin row.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$links = (array) $links;
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'cph-filebirb' ) ) );
		return $links;
	}

	/**
	 * Capability and nonce check for an admin-post action.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cph-filebirb' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Store a one-shot notice and redirect back to the page.
	 *
	 * @param string $type    `success` or `error`.
	 * @param string $message Message text.
	 * @return never
	 */
	private function redirect( string $type, string $message ): never {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Print and clear the pending notice, if any.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( (string) $notice['message'] )
		);
	}
}
