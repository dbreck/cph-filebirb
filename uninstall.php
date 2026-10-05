<?php
/**
 * Uninstall cleanup.
 *
 * Removes only CPH FileBird's own data. FileBird's tables (`{prefix}fbv`,
 * `{prefix}fbv_attachment_folder`) and `fbv_*` options are kept so the site
 * can reinstall or switch back to FileBird with every folder intact. Define
 * `CPHFB_REMOVE_ALL_DATA` as true in wp-config.php to drop those as well.
 *
 * @package CPH\FileBird
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove plugin data from the current site.
 *
 * @param bool $remove_all Also drop the shared FileBird tables and options.
 * @return void
 */
function cphfb_uninstall_site( $remove_all ) {
	global $wpdb;

	delete_option( 'cphfb_settings' );
	delete_option( 'cphfb_db_version' );
	delete_transient( 'cphfb_counts' );

	// Catch-all for any other cphfb_ options and transients (settings notices etc.).
	$like   = $wpdb->esc_like( 'cphfb_' ) . '%';
	$tlike  = $wpdb->esc_like( '_transient_cphfb_' ) . '%';
	$ttlike = $wpdb->esc_like( '_transient_timeout_cphfb_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup on uninstall, no API for prefix deletes.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, $tlike, $ttlike ) );

	if ( ! $remove_all ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix only.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fbv_attachment_folder" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fbv" );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'fbv_' ) . '%' ) );
	// phpcs:enable
}

$cphfb_remove_all = defined( 'CPHFB_REMOVE_ALL_DATA' ) && true === CPHFB_REMOVE_ALL_DATA;

if ( is_multisite() ) {
	$cphfb_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $cphfb_site_ids as $cphfb_site_id ) {
		switch_to_blog( (int) $cphfb_site_id );
		cphfb_uninstall_site( $cphfb_remove_all );
		restore_current_blog();
	}
} else {
	cphfb_uninstall_site( $cphfb_remove_all );
}

// User meta is network-wide. The key is unprefixed by blog, so one delete covers every site.
delete_metadata( 'user', 0, 'cphfb_user_settings', '', true );
