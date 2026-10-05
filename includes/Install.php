<?php
/**
 * Table creation and first-run seeding.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb;

defined( 'ABSPATH' ) || exit;

/**
 * Creates FileBird's two tables (exact DDL) when missing.
 */
final class Install {

	/**
	 * Schema version stored in `cphfb_db_version`.
	 */
	public const DB_VERSION = '1';

	/**
	 * Option holding the schema version.
	 */
	public const VERSION_OPTION = 'cphfb_db_version';

	/**
	 * Activation hook callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::seed_settings();
	}

	/**
	 * Run install steps if the stored schema version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Create `{prefix}fbv` and `{prefix}fbv_attachment_folder` if they don't exist.
	 *
	 * The DDL is FileBird's, verbatim, so the tables stay byte-compatible.
	 * dbDelta never runs against a table that already exists.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$table_fbv = $wpdb->prefix . 'fbv';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_fbv ) ) ) !== $table_fbv ) {
			$sql = 'CREATE TABLE ' . $table_fbv . ' (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(250) NOT NULL,
            `parent` int(11) NOT NULL DEFAULT 0,
            `type` int(2) NOT NULL DEFAULT 0,
            `ord` int(11) NULL DEFAULT 0,
            `created_by` int(11) NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY `id` (id)) ' . $charset_collate . ';';
			dbDelta( $sql );
		}

		$table = $wpdb->prefix . 'fbv_attachment_folder';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			$sql = 'CREATE TABLE ' . $table . ' (
            `folder_id` int(11) unsigned NOT NULL,
            `attachment_id` bigint(20) unsigned NOT NULL,
            PRIMARY KEY( `folder_id`, `attachment_id`)
            )' . $charset_collate . ';';
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Seed `cphfb_settings` from FileBird's `fbv_settings` once.
	 *
	 * FileBird's shape: [ user_mode, svg_support, searching_api, show_breadcrumb,
	 * folder_counter_type ('counter_file_in_folder'|'counter_file_in_folder_and_sub'),
	 * theme, enable_cache_optimization ]. Only the counter type maps across.
	 *
	 * @return void
	 */
	public static function seed_settings(): void {
		if ( false !== get_option( Model\Settings::OPTION, false ) ) {
			return;
		}

		$values   = array();
		$filebird = get_option( 'fbv_settings', false );
		if ( is_array( $filebird ) && isset( $filebird['folder_counter_type'] ) ) {
			$values['include_subfolders_in_count'] = 'counter_file_in_folder_and_sub' === $filebird['folder_counter_type'];
		}

		Model\Settings::get_instance()->update( $values );
	}
}
