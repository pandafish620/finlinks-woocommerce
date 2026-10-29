<?php
/**
 * Bootstrap: loads WooCommerce-dependent classes, REST route, reconciliation schedule.
 *
 * @package FinLinks\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Finlinks_Plugin {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'finlinks_wc_db_version';
	const RECONCILE_HOOK    = 'finlinks_wc_reconcile';
	const RECONCILE_EVERY   = 600; // 10 minutes (§5.4).

	/**
	 * Events table name.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'finlinks_webhook_events';
	}

	/**
	 * Activation: create the processed-events table.
	 */
	public static function activate() {
		self::install_table();
	}

	/**
	 * Deactivation: remove schedules.
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RECONCILE_HOOK, array(), 'finlinks' );
		}
		wp_clear_scheduled_hook( self::RECONCILE_HOOK );
	}

	/**
	 * Create or upgrade the events table.
	 */
	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::events_table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				event_id varchar(64) NOT NULL,
				received_at datetime NOT NULL,
				PRIMARY KEY  (event_id),
				KEY received_at (received_at)
			) {$charset};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * plugins_loaded.
	 */
	public static function init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install_table();
		}

		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-api-base.php';
		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-credentials.php';
		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-connect.php';
		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-logger.php';
		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-api-client.php';
		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-wp-event-store.php';
		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-order-sync.php';
		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-gateway.php';
		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-admin.php';

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_blocks' ) );
		add_action( self::RECONCILE_HOOK, array( 'Finlinks_Order_Sync', 'reconcile_pending' ) );
		add_action( 'woocommerce_order_status_cancelled', array( 'Finlinks_Order_Sync', 'on_order_cancelled' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FINLINKS_WC_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'wp_enqueue_scripts', array( 'Finlinks_Gateway', 'enqueue_confirmation_script' ) );
		Finlinks_Admin::init();
	}

	/**
	 * Add the gateway.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public static function register_gateway( $gateways ) {
		$gateways[] = 'Finlinks_Gateway';
		return $gateways;
	}

	/**
	 * The gateway instance WooCommerce created (falls back to a new one).
	 *
	 * @return Finlinks_Gateway
	 */
	public static function gateway() {
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$all = WC()->payment_gateways()->payment_gateways();
			if ( isset( $all[ Finlinks_Gateway::ID ] ) ) {
				return $all[ Finlinks_Gateway::ID ];
			}
		}
		return new Finlinks_Gateway();
	}

	/**
	 * Webhook route: POST /wp-json/finlinks/v1/webhook (§4.1, §15 decision 7).
	 */
	public static function register_routes() {
		register_rest_route(
			'finlinks/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				// Authenticated by the HMAC signature inside the handler.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( WP_REST_Request $request ) {
		$gateway   = self::gateway();
		$processor = new Finlinks_Webhook_Processor(
			$gateway->get_webhook_secret(),
			new Finlinks_WP_Event_Store(),
			array( 'Finlinks_Order_Sync', 'handle_session_event' ),
			null,
			static function ( $event_ts ) {
				update_option( Finlinks_Admin::STALE_OPTION, array( 'skew' => time() - (int) $event_ts, 'at' => time() ), false );
			},
			Finlinks_Admin::pending_webhook_secret()
		);
		list( $status, $body ) = $processor->handle( $request->get_headers(), $request->get_body() );
		if ( $status >= 400 ) {
			Finlinks_Logger::log( 'Webhook rejected: HTTP ' . $status . ' ' . ( isset( $body['error'] ) ? $body['error'] : '' ), 'warning' );
		}
		return new WP_REST_Response( $body, $status );
	}

	/**
	 * Blocks registration.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registry.
	 */
	public static function register_blocks( $registry ) {
		require_once FINLINKS_WC_DIR . 'includes/class-finlinks-blocks.php';
		$registry->register( new Finlinks_Blocks() );
	}

	/**
	 * WP-Cron interval used when Action Scheduler is unavailable.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['finlinks_10min'] = array(
			'interval' => self::RECONCILE_EVERY,
			'display'  => __( 'Every 10 minutes', 'finlinks-stablecoin-payments' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the backup poll (Action Scheduler preferred, WP-Cron fallback).
	 */
	public static function ensure_schedule() {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( false === as_has_scheduled_action( self::RECONCILE_HOOK, array(), 'finlinks' ) ) {
				as_schedule_recurring_action( time() + 60, self::RECONCILE_EVERY, self::RECONCILE_HOOK, array(), 'finlinks' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + 60, 'finlinks_10min', self::RECONCILE_HOOK );
		}
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Finlinks_Gateway::ID );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'finlinks-stablecoin-payments' ) . '</a>' );
		return $links;
	}
}
