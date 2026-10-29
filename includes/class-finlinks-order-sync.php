<?php
/**
 * Applies confirmed session state to WooCommerce orders. Used by the webhook, the backup poll
 * and the thank-you page. Always confirms with GET /api/v1/commerce/sessions/:id (§7.3 step 4).
 *
 * @package FinLinks\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class Finlinks_Order_Sync {

	const POLL_MAX_AGE        = 172800; // 48 h (§5.4).
	const POLL_BATCH          = 50;
	const WEBHOOK_GET_TIMEOUT = 7;

	/** Set while this plugin itself moves an order, so the cancel hook does not call the API back. */
	private static $applying = false;

	/**
	 * Webhook handler for payment_session.* events. The payload is only a trigger: the
	 * order is resolved from data.storeOrderId and state comes from an authenticated GET.
	 *
	 * @param array $payload Verified webhook payload.
	 * @return string Finlinks_Webhook_Processor::HANDLED|IGNORED|RETRY
	 */
	public static function handle_session_event( array $payload ) {
		$data     = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$sid      = isset( $data['id'] ) ? (string) $data['id'] : '';
		$order_id = isset( $data['storeOrderId'] ) ? (string) $data['storeOrderId'] : '';
		if ( '' === $sid || ! ctype_digit( $order_id ) ) {
			return Finlinks_Webhook_Processor::IGNORED;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || Finlinks_Gateway::ID !== $order->get_payment_method() ) {
			Finlinks_Logger::log( 'Webhook for unknown order ' . $order_id . ' ignored.', 'warning' );
			return Finlinks_Webhook_Processor::IGNORED;
		}
		// The service waits at most 10 s for our answer (§7.3 step 5), so the confirming GET gets a shorter budget.
		return self::sync_session( $order, $sid, self::WEBHOOK_GET_TIMEOUT );
	}

	/**
	 * Fetch a session and apply it to the order.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $sid     Session id.
	 * @param int|null $timeout HTTP timeout in seconds (null = client default).
	 * @return string HANDLED|IGNORED|RETRY
	 */
	public static function sync_session( $order, $sid, $timeout = null ) {
		$gateway = Finlinks_Plugin::gateway();
		if ( ! $gateway->is_configured() ) {
			return Finlinks_Webhook_Processor::RETRY;
		}
		$res = $gateway->api()->get_session( $sid, $timeout );
		if ( ! $res['ok'] ) {
			if ( $res['network_error'] || $res['status'] >= 500 || 429 === $res['status'] || 408 === $res['status'] ) {
				return Finlinks_Webhook_Processor::RETRY;
			}
			Finlinks_Logger::log( sprintf( 'Session lookup for order %d failed: HTTP %d %s', $order->get_id(), $res['status'], $res['code'] ), 'warning' );
			return Finlinks_Webhook_Processor::IGNORED;
		}
		return self::apply( $order, $res['data'] );
	}

	/**
	 * Apply a confirmed session object to an order, under a short per-order lock.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $session Session object.
	 * @return string HANDLED|IGNORED|RETRY
	 */
	public static function apply( $order, array $session ) {
		$lock = 'finlinks_lock_' . $order->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) {
			$since = (int) get_option( $lock );
			if ( $since > time() - 30 ) {
				return Finlinks_Webhook_Processor::RETRY;
			}
			update_option( $lock, time(), false );
		}
		try {
			$order = wc_get_order( $order->get_id() ); // Fresh copy.
			if ( ! $order ) {
				return Finlinks_Webhook_Processor::IGNORED;
			}
			$gateway  = Finlinks_Plugin::gateway();
			$decision = Finlinks_Session_Decider::decide(
				$session,
				array(
					'order_id'           => (string) $order->get_id(),
					'order_total'        => Finlinks_Amount::format_usd( $order->get_total() ),
					'order_currency'     => $order->get_currency(),
					'order_is_paid'      => $order->is_paid(),
					'order_status'       => $order->get_status(),
					'current_session_id' => (string) $order->get_meta( Finlinks_Gateway::META_SESSION_ID ),
					'livemode'           => ! $gateway->is_test_mode(),
					'last_status'        => (string) $order->get_meta( Finlinks_Gateway::META_LAST_STATUS ),
					'unpaid_action'      => $gateway->get_option( 'unpaid_action', Finlinks_Defaults::UNPAID_ACTION ),
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing WooCommerce compatibility hook, not a custom FinLinks hook.
					'payable_statuses'   => (array) apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', Finlinks_Session_Decider::PAYABLE_ORDER_STATUSES, $order ),
				)
			);
			Finlinks_Logger::log( sprintf( 'Order %d session %s: %s (%s)', $order->get_id(), isset( $session['id'] ) ? $session['id'] : '?', $decision['action'], $decision['reason'] ) );

			$note           = isset( $decision['note'] ) ? $decision['note'] : '';
			self::$applying = true;
			switch ( $decision['action'] ) {
				case Finlinks_Session_Decider::ACTION_IGNORE:
					return Finlinks_Webhook_Processor::IGNORED;
				case Finlinks_Session_Decider::ACTION_COMPLETE:
					$order->add_order_note( $note );
					$order->update_meta_data( Finlinks_Gateway::META_LAST_STATUS, 'paid' );
					$order->save();
					$order->payment_complete( isset( $decision['tx_hash'] ) ? sanitize_text_field( $decision['tx_hash'] ) : '' );
					break;
				case Finlinks_Session_Decider::ACTION_HOLD:
					$order->update_meta_data( Finlinks_Gateway::META_LAST_STATUS, $decision['reason'] );
					$order->update_status( 'on-hold', $note );
					break;
				case Finlinks_Session_Decider::ACTION_CANCEL:
					$order->update_meta_data( Finlinks_Gateway::META_LAST_STATUS, $decision['reason'] );
					$order->update_status( 'cancelled', $note );
					break;
				case Finlinks_Session_Decider::ACTION_FAIL:
					$order->update_meta_data( Finlinks_Gateway::META_LAST_STATUS, $decision['reason'] );
					$order->update_status( 'failed', $note );
					break;
				case Finlinks_Session_Decider::ACTION_NOTE:
					$order->update_meta_data( Finlinks_Gateway::META_LAST_STATUS, $decision['reason'] );
					$order->add_order_note( $note );
					$order->save();
					break;
			}
			return Finlinks_Webhook_Processor::HANDLED;
		} finally {
			self::$applying = false;
			delete_option( $lock );
		}
	}

	/**
	 * woocommerce_order_status_cancelled: when the buyer (cancel URL), the merchant or WooCommerce's
	 * unpaid-order cleanup cancels a pending USDC order, cancel its open session too (§5.5), so the
	 * hosted page can no longer be paid for a cancelled order. A session that already received
	 * money cannot be cancelled; that is recorded on the order for the merchant.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_order_cancelled( $order_id, $order = null ) {
		if ( self::$applying ) {
			return; // We are applying an expired/cancelled session ourselves.
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || Finlinks_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}
		$sid  = (string) $order->get_meta( Finlinks_Gateway::META_SESSION_ID );
		$last = (string) $order->get_meta( Finlinks_Gateway::META_LAST_STATUS );
		if ( '' === $sid || in_array( $last, array( 'paid', 'expired', 'cancelled', 'failed', 'session_cancel_refused' ), true ) ) {
			return;
		}
		$gateway = Finlinks_Plugin::gateway();
		if ( ! $gateway->is_configured() ) {
			return;
		}
		// Mark first, so the payment_session.cancelled webhook that follows is treated as "unchanged".
		$order->update_meta_data( Finlinks_Gateway::META_LAST_STATUS, 'cancelled' );
		$order->save_meta_data();
		$res = $gateway->api()->cancel_session( $sid );
		if ( $res['ok'] ) {
			/* translators: %s: session id */
			$order->add_order_note( sprintf( __( 'USDC payment session %s cancelled because the order was cancelled.', 'finlinks-stablecoin-payments' ), $sid ) );
			return;
		}
		if ( 409 === $res['status'] && 'COMMERCE_SESSION_NOT_CANCELLABLE' === $res['code'] ) {
			// Money may already have arrived (partial) or the session already ended. Look it up.
			$order->update_meta_data( Finlinks_Gateway::META_LAST_STATUS, 'session_cancel_refused' );
			$order->save_meta_data();
			/* translators: %s: session id */
			$order->add_order_note( sprintf( __( 'The order was cancelled, but USDC payment session %s could not be cancelled because it already received a payment or has ended. Check it in the FinLinks portal before restocking or refunding.', 'finlinks-stablecoin-payments' ), $sid ) );
			return;
		}
		// Network or server error: undo the marker so a later poll or webhook still applies.
		$order->update_meta_data( Finlinks_Gateway::META_LAST_STATUS, $last );
		$order->save_meta_data();
		Finlinks_Logger::log( sprintf( 'Cancel session for cancelled order %d failed: HTTP %d %s', $order->get_id(), $res['status'], $res['code'] ), 'warning' );
		/* translators: %s: session id */
		$order->add_order_note( sprintf( __( 'The order was cancelled, but USDC payment session %s could not be cancelled right now. It stays payable until it expires.', 'finlinks-stablecoin-payments' ), $sid ) );
	}

	/**
	 * Backup poll: pending USDC orders younger than 48 h (§5.4, §7.4).
	 */
	public static function reconcile_pending() {
		Finlinks_WP_Event_Store::purge();
		$gateway = Finlinks_Plugin::gateway();
		if ( ! $gateway->is_configured() ) {
			return;
		}
		$orders = wc_get_orders(
			array(
				'status'         => array( 'pending' ),
				'payment_method' => Finlinks_Gateway::ID,
				'date_created'   => '>' . ( time() - self::POLL_MAX_AGE ),
				'limit'          => self::POLL_BATCH,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( $orders as $order ) {
			$sid = (string) $order->get_meta( Finlinks_Gateway::META_SESSION_ID );
			if ( '' !== $sid ) {
				self::sync_session( $order, $sid );
			}
		}
	}
}
