<?php
/**
 * Processed webhook ids in a custom table, kept at least 7 days (§7.3).
 *
 * @package FinLinks\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class Finlinks_WP_Event_Store implements Finlinks_Event_Store {

	const RETENTION_DAYS = 8;

	public function claim( $event_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (event_id, received_at) VALUES (%s, %s)', Finlinks_Plugin::events_table(), $event_id, gmdate( 'Y-m-d H:i:s' ) ) );
		return 1 === (int) $rows;
	}

	public function release( $event_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Finlinks_Plugin::events_table(), array( 'event_id' => $event_id ), array( '%s' ) );
	}

	/**
	 * Delete ids older than the retention window.
	 */
	public static function purge() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s', Finlinks_Plugin::events_table(), gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	}
}
