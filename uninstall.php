<?php
/**
 * Uninstall: remove settings, transients, locks, schedules and the processed-events table.
 * Orders, their notes and their _finlinks_* meta are kept (they are the merchant's records).
 *
 * @package FinLinks\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Shared settings belong to whichever copy is still on disk. Deleting this
// plugin while the previous directory remains must not wipe the API key.
if ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/finlinks-woocommerce/finlinks-woocommerce.php' ) ) {
	return;
}

/**
 * Clean one site.
 */
function finlinks_wc_uninstall_site() {
	global $wpdb;
	foreach ( array( 'woocommerce_finlinks_settings', 'finlinks_wc_db_version', 'finlinks_wc_clock', 'finlinks_wc_stale_webhook', 'finlinks_wc_paused', 'finlinks_wc_pending_rotation' ) as $option ) {
		delete_option( $option );
	}
	// Per-order locks, thank-you throttles and admin notices.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'finlinks_lock_' ) . '%',
			$wpdb->esc_like( '_transient_finlinks_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_finlinks_' ) . '%',
			$wpdb->esc_like( '_site_transient_finlinks_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_finlinks_' ) . '%'
		)
	);
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'finlinks_webhook_events' ) );
	// phpcs:enable
	wp_clear_scheduled_hook( 'finlinks_wc_reconcile' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'finlinks_wc_reconcile', array(), 'finlinks' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $finlinks_site_id ) {
		switch_to_blog( (int) $finlinks_site_id );
		finlinks_wc_uninstall_site();
		restore_current_blog();
	}
} else {
	finlinks_wc_uninstall_site();
}
