<?php
/**
 * The payment gateway.
 *
 * @package FinLinks\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-finlinks-api-base.php';
require_once __DIR__ . '/class-finlinks-credentials.php';

/**
 * Redirects the buyer to the hosted USDC payment page (contract §5, §11).
 */
class Finlinks_Gateway extends WC_Payment_Gateway {

	const ID = 'finlinks';

	const LIVE_BASE = Finlinks_Api_Base::PROD;

	const META_SESSION_ID  = '_finlinks_session_id';
	const META_ATTEMPT     = '_finlinks_session_attempt';
	const META_LIVEMODE    = '_finlinks_livemode';
	const META_LAST_STATUS = '_finlinks_last_status';

	/** Option set when the API rejects the store's configuration; hides the method for a while. */
	const PAUSE_OPTION = 'finlinks_wc_paused';
	const PAUSE_TTL    = 300;

	/**
	 * Create-session errors that will fail for every buyer until the merchant fixes something.
	 * After one of them the method is hidden for PAUSE_TTL seconds instead of failing each checkout.
	 */
	const PAUSE_CODES = array(
		'COMMERCE_API_KEY_MISSING',
		'COMMERCE_API_KEY_INVALID',
		'COMMERCE_API_KEY_ENVIRONMENT_MISMATCH',
		'COMMERCE_CONNECTION_NOT_ACTIVE',
		'COMMERCE_SESSIONS_DISABLED',
		'COMMERCE_WEBHOOK_NOT_CONFIGURED',
		'COMMERCE_REDIRECT_URL_INVALID',
		'COMMERCE_WEBHOOK_URL_INVALID',
		'IDENTITY_REQUIRED',
		'KYB_REJECTED_FRAUD',
	);

	/** Thank-you page: reload while waiting for confirmation, at most this many times. */
	const THANKYOU_REFRESH_SECONDS = 10;
	const THANKYOU_REFRESH_MAX     = 12;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Pay with USDC (FinLinks)', 'finlinks-stablecoin-payments' );
		$this->method_description = __( 'Buyers are redirected to a secure hosted page to pay with USDC. Orders are marked paid only after the payment is confirmed on chain. An approved FinLinks merchant account is required; portal access is invitation-only.', 'finlinks-stablecoin-payments' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Enable/Disable', 'finlinks-stablecoin-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable USDC payments', 'finlinks-stablecoin-payments' ),
				'default' => 'no',
			),
			'title'         => array(
				'title'       => __( 'Title', 'finlinks-stablecoin-payments' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown at checkout.', 'finlinks-stablecoin-payments' ),
				'default'     => __( 'Pay with USDC', 'finlinks-stablecoin-payments' ),
				'desc_tip'    => true,
			),
			'description'   => array(
				'title'       => __( 'Description', 'finlinks-stablecoin-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Shown to buyers at checkout.', 'finlinks-stablecoin-payments' ),
				'default'     => __( 'You will be redirected to a secure page to pay with USDC.', 'finlinks-stablecoin-payments' ),
				'desc_tip'    => true,
			),
			'testmode'      => array(
				'title'       => __( 'Test mode', 'finlinks-stablecoin-payments' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the test environment (test network, flk_test_ keys)', 'finlinks-stablecoin-payments' ),
				'default'     => Finlinks_Defaults::TEST_MODE,
				'description' => __( 'Turn off to accept real payments with a flk_live_ key.', 'finlinks-stablecoin-payments' ),
			),
			'unpaid_action' => array(
				'title'       => __( 'Unpaid sessions', 'finlinks-stablecoin-payments' ),
				'type'        => 'select',
				'description' => __( 'What to do with a pending order when its payment session expires or is cancelled.', 'finlinks-stablecoin-payments' ),
				'default'     => Finlinks_Defaults::UNPAID_ACTION,
				'options'     => array(
					Finlinks_Session_Decider::UNPAID_NONE   => __( 'Add an order note only (stock hold settings still apply)', 'finlinks-stablecoin-payments' ),
					Finlinks_Session_Decider::UNPAID_CANCEL => __( 'Cancel the order', 'finlinks-stablecoin-payments' ),
					Finlinks_Session_Decider::UNPAID_FAIL   => __( 'Mark the order as failed', 'finlinks-stablecoin-payments' ),
				),
			),
			'finlinks_connect' => array(
				'title' => __( 'Connect', 'finlinks-stablecoin-payments' ),
				'type'  => 'finlinks_connect',
			),
			'advanced'      => array(
				'title'       => __( 'Advanced', 'finlinks-stablecoin-payments' ),
				'type'        => 'title',
				'description' => __( 'Paste an API key and webhook signing secret from the FinLinks portal.', 'finlinks-stablecoin-payments' ),
			),
			'api_key'       => array(
				'title'       => __( 'API key', 'finlinks-stablecoin-payments' ),
				'type'        => 'finlinks_secret',
				'desc_tip'    => false,
				'description' => __( 'From the FinLinks portal, Settings > Integrations. Starts with flk_test_ or flk_live_.', 'finlinks-stablecoin-payments' ),
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook signing secret', 'finlinks-stablecoin-payments' ),
				'type'        => 'finlinks_secret',
				'desc_tip'    => false,
				'description' => __( 'Shown once when the connection is created in the FinLinks portal.', 'finlinks-stablecoin-payments' ),
			),
			'api_base_url'  => array(
				'title'       => __( 'API base URL (advanced)', 'finlinks-stablecoin-payments' ),
				'type'        => 'text',
				'description' => __( 'Leave empty for live payments. Test mode requires an https API base URL and will not call the production API. Ignored and cleared when Test mode is off.', 'finlinks-stablecoin-payments' ),
				'default'     => '',
			),
			'debug'         => array(
				'title'       => __( 'Debug log', 'finlinks-stablecoin-payments' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log API calls to WooCommerce > Status > Logs (keys and secrets are never logged)', 'finlinks-stablecoin-payments' ),
				'default'     => 'no',
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Configuration                                                      */
	/* ------------------------------------------------------------------ */

	public function is_test_mode() {
		// The second argument applies only when the saved value is missing or empty.
		return 'yes' === $this->get_option( 'testmode', Finlinks_Defaults::TEST_MODE );
	}

	public function get_api_key() {
		return trim( (string) $this->get_option( 'api_key', '' ) );
	}

	public function get_webhook_secret() {
		return trim( (string) $this->get_option( 'webhook_secret', '' ) );
	}

	public function get_api_base() {
		// Live mode must not read the advanced override. Test mode may, for a mock server.
		if ( ! $this->is_test_mode() ) {
			return Finlinks_Api_Base::resolve( false, '' );
		}
		return Finlinks_Api_Base::resolve( true, (string) $this->get_option( 'api_base_url', '' ) );
	}

	/**
	 * Key prefix expected for the current mode.
	 *
	 * @return string
	 */
	public function expected_key_prefix() {
		return $this->is_test_mode() ? 'flk_test_' : 'flk_live_';
	}

	/**
	 * Key present and matching the mode.
	 *
	 * @return bool
	 */
	public function key_matches_mode() {
		if ( $this->is_test_mode() ) {
			return 0 === strpos( $this->get_api_key(), 'flk_test_' );
		}
		return Finlinks_Credentials::is_live_key( $this->get_api_key() );
	}

	public function is_configured() {
		if ( '' === $this->get_api_base() ) {
			return false;
		}
		return Finlinks_Credentials::gateway_ready( $this->is_test_mode(), $this->get_api_key(), $this->get_webhook_secret() );
	}

	public function api() {
		return new Finlinks_Api_Client( $this->get_api_base(), $this->get_api_key() );
	}

	/**
	 * Lowercase host of the store (connection storeHost).
	 *
	 * @return string
	 */
	public static function store_host() {
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * The REST webhook URL registered with the service.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'finlinks/v1/webhook' );
	}

	/**
	 * Check a URL against the contract rules (§5.2, §8.3): https, store host, no userinfo/port/fragment.
	 *
	 * @param string $url         URL.
	 * @param bool   $allow_query Whether a query string is allowed.
	 * @return bool
	 */
	public static function url_ok( $url, $allow_query ) {
		// §5.2 / W3a: https, store host, ≤2048; no userinfo, port (incl. explicit :443), fragment, backslash or control chars.
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || false !== strpos( $url, '\\' ) || preg_match( "/[\x00-\x1F\x7F]/", $url ) ) {
			return false;
		}
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || 'https' !== strtolower( $p['scheme'] ) || empty( $p['host'] ) ) {
			return false;
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) || isset( $p['port'] ) ) {
			return false;
		}
		if ( ! $allow_query && isset( $p['query'] ) ) {
			return false;
		}
		return strtolower( $p['host'] ) === self::store_host();
	}

	/* ------------------------------------------------------------------ */
	/* Availability                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Only offered for USD stores, totals 1–10,000 USD, and a configured connection.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled || ! $this->is_configured() ) {
			return false;
		}
		if ( null !== self::pause_state() ) {
			return false;
		}
		if ( ! Finlinks_Amount::is_supported_currency( get_woocommerce_currency() ) ) {
			return false;
		}
		if ( ( is_admin() && ! wp_doing_ajax() ) && ! WC()->cart ) {
			return true;
		}
		$total = $this->get_order_total();
		if ( $total > 0 && ! Finlinks_Amount::is_eligible( get_woocommerce_currency(), $total ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Current pause, or null. array{code:string, at:int}.
	 *
	 * @return array|null
	 */
	public static function pause_state() {
		$p = get_option( self::PAUSE_OPTION );
		if ( is_array( $p ) && isset( $p['code'], $p['at'] ) && (int) $p['at'] > time() - self::PAUSE_TTL ) {
			return $p;
		}
		return null;
	}

	/**
	 * Pause the method after a configuration error from the API.
	 *
	 * @param string $code Error code.
	 */
	public static function pause( $code ) {
		$code = preg_match( '/^[A-Z0-9_]{1,64}$/', (string) $code ) ? (string) $code : 'UNKNOWN';
		update_option( self::PAUSE_OPTION, array( 'code' => $code, 'at' => time() ), false );
	}

	/**
	 * Lift the pause (successful connection test, or settings saved).
	 */
	public static function unpause() {
		delete_option( self::PAUSE_OPTION );
	}

	/**
	 * Whether a create-session error should pause the method.
	 *
	 * @param array $res Client result.
	 * @return bool
	 */
	public static function is_pause_error( array $res ) {
		if ( in_array( $res['code'], self::PAUSE_CODES, true ) ) {
			return true;
		}
		// §3: routes return 404 while COMMERCE_SESSIONS_ENABLED is off.
		return 404 === (int) $res['status'];
	}

	/* ------------------------------------------------------------------ */
	/* Payment                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Create (or reuse) the payment session and redirect to the hosted page.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->fail( __( 'Order not found.', 'finlinks-stablecoin-payments' ) );
		}
		if ( $order->is_paid() ) {
			// Never open a second payable session for an order that is already paid.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
		if ( null !== self::pause_state() || ! $this->is_configured() ) {
			return $this->fail( __( 'USDC payments are temporarily unavailable. Please choose another payment method.', 'finlinks-stablecoin-payments' ) );
		}
		$currency = $order->get_currency();
		$amount   = Finlinks_Amount::format_usd( $order->get_total() );
		if ( ! Finlinks_Amount::is_eligible( $currency, $amount ) ) {
			return $this->fail( __( 'USDC payment is not available for this order amount or currency.', 'finlinks-stablecoin-payments' ) );
		}

		$return_url = $this->get_return_url( $order );
		$cancel_url = $order->get_cancel_order_url_raw();
		$callback   = self::webhook_url();
		if ( ! self::url_ok( $return_url, true ) || ! self::url_ok( $cancel_url, true ) || ! self::url_ok( $callback, false ) ) {
			Finlinks_Logger::log( 'Store URLs do not meet the requirements (https, same host, pretty permalinks for the webhook).', 'error' );
			return $this->fail( __( 'USDC payments are temporarily unavailable. Please choose another payment method.', 'finlinks-stablecoin-payments' ) );
		}

		$label = sprintf( 'Order #%s', $order->get_order_number() );
		if ( strlen( $label ) > 64 || ! preg_match( '/^[\x20-\x7E]+$/', $label ) ) {
			$label = 'Order ' . $order->get_id();
		}

		$body = array(
			'storeOrderId'     => (string) $order->get_id(),
			'orderLabel'       => $label,
			'amount'           => $amount,
			'currency'         => strtoupper( $currency ),
			'returnUrl'        => $return_url,
			'cancelUrl'        => $cancel_url,
			'callbackUrl'      => $callback,
		);
		// §16.7: expire before WooCommerce auto-cancels the unpaid order; omit when stock holding is off.
		$expires = Finlinks_Timing::expires_in( get_option( 'woocommerce_manage_stock', 'yes' ), get_option( 'woocommerce_hold_stock_minutes', '' ) );
		if ( null !== $expires ) {
			$body['expiresInSeconds'] = $expires;
		}

		$api = $this->api();
		$res = $api->create_session( $body );

		if ( ! $res['ok'] && 409 === $res['status'] && 'COMMERCE_SESSION_AMOUNT_CHANGED' === $res['code'] ) {
			// §5.3: cancel the old open session, then create again.
			$old = $this->find_open_session_id( $order );
			// Detach first, so the cancelled event for the old session is treated as stale
			// and can never cancel this order while the new session is being created.
			$order->update_meta_data( self::META_SESSION_ID, '' );
			$order->save();
			if ( '' !== $old ) {
				$cancel = $api->cancel_session( $old );
				if ( $cancel['ok'] ) {
					/* translators: %s: session id */
					$order->add_order_note( sprintf( __( 'USDC payment session %s cancelled because the order total changed.', 'finlinks-stablecoin-payments' ), $old ) );
				}
			}
			$res = $api->create_session( $body );
		}

		if ( ! $res['ok'] && 409 === $res['status'] && 'COMMERCE_ORDER_ALREADY_PAID' === $res['code'] ) {
			$sid = isset( $res['data']['sessionId'] ) ? (string) $res['data']['sessionId'] : $order->get_meta( self::META_SESSION_ID );
			if ( '' !== $sid ) {
				Finlinks_Order_Sync::sync_session( $order, $sid );
				$order = wc_get_order( $order_id );
			}
			if ( $order && $order->is_paid() ) {
				return array(
					'result'   => 'success',
					'redirect' => $return_url,
				);
			}
			return $this->fail( __( 'This order has already been paid. Please contact the store.', 'finlinks-stablecoin-payments' ) );
		}

		if ( ! $res['ok'] ) {
			Finlinks_Logger::log( sprintf( 'Create session for order %d failed: HTTP %d %s %s', $order->get_id(), $res['status'], $res['code'], $res['message'] ), 'error' );
			if ( self::is_pause_error( $res ) ) {
				self::pause( $res['code'] );
				Finlinks_Logger::log( sprintf( 'USDC payments paused for %d s after %s. Fix the connection, then use "Test connection" to resume.', self::PAUSE_TTL, $res['code'] ), 'error' );
			}
			return $this->fail( self::buyer_message( $res['code'] ) );
		}

		$s         = $res['data'];
		$hosted    = isset( $s['hostedUrl'] ) ? (string) $s['hostedUrl'] : '';
		$rejection = Finlinks_Hosted_Url::rejection( $hosted, $this->is_test_mode() );
		if ( empty( $s['id'] ) || null !== $rejection
			|| ! isset( $s['storeOrderId'] ) || (string) $s['storeOrderId'] !== (string) $order->get_id() ) {
			if ( null !== $rejection ) {
				Finlinks_Logger::log( sprintf( 'Hosted payment URL rejected (%s). Host: %s', $rejection['reason'], $rejection['host'] ), 'error' );
			} else {
				Finlinks_Logger::log( 'Create session returned an unexpected response.', 'error' );
			}
			return $this->fail( self::buyer_message( '' ) );
		}

		$order->update_meta_data( self::META_SESSION_ID, sanitize_text_field( (string) $s['id'] ) );
		$order->update_meta_data( self::META_ATTEMPT, isset( $s['attempt'] ) ? absint( $s['attempt'] ) : 1 );
		$order->update_meta_data( self::META_LIVEMODE, ! empty( $s['livemode'] ) ? 'yes' : 'no' );
		$order->delete_meta_data( self::META_LAST_STATUS );
		if ( empty( $s['reused'] ) ) {
			/* translators: 1: session id, 2: attempt number */
			$order->add_order_note( sprintf( __( 'USDC payment session %1$s created (attempt %2$d). Waiting for payment.', 'finlinks-stablecoin-payments' ), $s['id'], isset( $s['attempt'] ) ? (int) $s['attempt'] : 1 ) );
		}
		if ( ! $order->has_status( 'pending' ) ) {
			$order->update_status( 'pending' );
		}
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( (string) $s['hostedUrl'] ),
		);
	}

	/**
	 * The open session for an order: stored id, else the newest open one from the list endpoint.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function find_open_session_id( $order ) {
		$list = $this->api()->list_sessions( (string) $order->get_id() );
		if ( $list['ok'] && isset( $list['data']['sessions'] ) && is_array( $list['data']['sessions'] ) ) {
			foreach ( $list['data']['sessions'] as $s ) {
				if ( isset( $s['status'], $s['id'] ) && 'open' === $s['status'] ) {
					return (string) $s['id'];
				}
			}
			return '';
		}
		return (string) $order->get_meta( self::META_SESSION_ID );
	}

	/**
	 * Buyer-facing message for an API error code (never shows raw service text).
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function buyer_message( $code ) {
		return Finlinks_Messages::buyer( $code );
	}

	/**
	 * Add an error notice and return failure.
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private function fail( $message ) {
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Thank-you page. Returning here is not proof of payment (§5.6).
	 *
	 * @param int $order_id Order id.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$sid = (string) $order->get_meta( self::META_SESSION_ID );
		if ( '' !== $sid && $order->has_status( 'pending' ) && false === get_transient( 'finlinks_ty_' . $order->get_id() ) ) {
			set_transient( 'finlinks_ty_' . $order->get_id(), 1, 15 );
			Finlinks_Order_Sync::sync_session( $order, $sid );
			$order = wc_get_order( $order_id );
		}
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		if ( $order->has_status( 'pending' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$wait    = isset( $_GET['finlinks_wait'] ) ? absint( wp_unslash( $_GET['finlinks_wait'] ) ) : 0;
			$refresh = $wait >= self::THANKYOU_REFRESH_MAX ? 'stopped' : 'scheduled';
			echo '<p class="finlinks-awaiting" data-finlinks-refresh="' . esc_attr( $refresh ) . '" data-finlinks-next="' . esc_attr( (string) ( $wait + 1 ) ) . '" data-finlinks-delay="' . esc_attr( (string) ( self::THANKYOU_REFRESH_SECONDS * 1000 ) ) . '">' . esc_html__( 'We\'re confirming your USDC payment. Your order will be updated automatically once the payment is confirmed.', 'finlinks-stablecoin-payments' ) . '</p>';
		} elseif ( $order->has_status( 'on-hold' ) ) {
			echo '<p class="finlinks-on-hold">' . esc_html__( 'Your payment needs a manual review by the store. The store will contact you.', 'finlinks-stablecoin-payments' ) . '</p>';
		}
	}

	/** Load the confirmation script in time for the footer on FinLinks order returns. */
	public static function enqueue_confirmation_script() {
		if ( ! is_order_received_page() ) {
			return;
		}
		$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );
		if ( ! $order || self::ID !== $order->get_payment_method() || ! $order->has_status( 'pending' ) ) {
			return;
		}
		wp_enqueue_script( 'finlinks-confirmation', FINLINKS_WC_URL . 'assets/js/confirmation.js', array(), FINLINKS_WC_VERSION, true );
	}

	/**
	 * Validate the unpaid-session action against the allowed values.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return string
	 */
	public function validate_unpaid_action_field( $key, $value ) {
		$value   = is_string( $value ) ? sanitize_key( wp_unslash( $value ) ) : '';
		$allowed = array( Finlinks_Session_Decider::UNPAID_NONE, Finlinks_Session_Decider::UNPAID_CANCEL, Finlinks_Session_Decider::UNPAID_FAIL );
		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}
		// A bad post must not replace a value the merchant already saved.
		$saved = (string) $this->get_option( $key, '' );
		if ( in_array( $saved, $allowed, true ) ) {
			return $saved;
		}
		return Finlinks_Defaults::UNPAID_ACTION;
	}

	/**
	 * Title must not be empty (buyers would see a blank method).
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return string
	 */
	public function validate_title_field( $key, $value ) {
		$value = trim( wp_strip_all_tags( is_string( $value ) ? wp_unslash( $value ) : '' ) );
		return '' === $value ? __( 'Pay with USDC', 'finlinks-stablecoin-payments' ) : substr( $value, 0, 100 );
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Secret field: never echoes the stored value back into the page.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_finlinks_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$saved     = '' !== trim( (string) $this->get_option( $key, '' ) );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<input class="input-text regular-input" type="password" autocomplete="new-password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="" placeholder="<?php echo esc_attr( $saved ? __( 'Saved. Leave empty to keep it.', 'finlinks-stablecoin-payments' ) : '' ); ?>" />
					<?php if ( $saved ) : ?>
						<label><input type="checkbox" name="<?php echo esc_attr( $field_key ); ?>_clear" value="1" /> <?php esc_html_e( 'Remove the saved value', 'finlinks-stablecoin-payments' ); ?></label>
					<?php endif; ?>
					<?php echo wp_kses_post( $this->get_description_html( $data ) ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Validate a secret field: empty keeps the saved value.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 * @throws Exception On a malformed value.
	 */
	public function validate_finlinks_secret_field( $key, $value ) {
		$field_key = $this->get_field_key( $key );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			throw new Exception( esc_html__( 'You are not allowed to change payment credentials.', 'finlinks-stablecoin-payments' ) );
		}
		check_admin_referer( 'woocommerce-settings' );
		$clear = isset( $_POST[ $field_key . '_clear' ] ) && is_string( $_POST[ $field_key . '_clear' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field_key . '_clear' ] ) ) : '';
		if ( '1' === $clear ) {
			$value = '';
		} else {
			$value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
			if ( '' === $value ) {
				$value = (string) $this->get_option( $key, '' );
			}
		}
		$code = Finlinks_Credentials::save_error( $this->is_test_mode(), $key, $value );
		if ( '' !== $code ) {
			// Throwing leaves the previously stored value in place (WooCommerce skips this field).
			throw new Exception( esc_html( self::credential_error_message( $code ) ) );
		}
		return $value;
	}

	/**
	 * Admin error for a credential that must not be saved.
	 *
	 * @param string $code Error code from Finlinks_Credentials::save_error().
	 * @return string
	 */
	private static function credential_error_message( $code ) {
		switch ( $code ) {
			case 'live_key':
				return __( 'Test mode is off, so the API key must start with flk_live_. This value was not saved, and USDC payments stay hidden until a live key is saved.', 'finlinks-stablecoin-payments' );
			case 'live_secret':
				return __( 'Test mode is off, so a webhook signing secret is required. USDC payments stay hidden until one is saved.', 'finlinks-stablecoin-payments' );
			case 'secret_format':
				return __( 'The webhook signing secret format is not valid.', 'finlinks-stablecoin-payments' );
			case 'key_format':
			default:
				return __( 'The API key format is not valid. It starts with flk_test_ or flk_live_.', 'finlinks-stablecoin-payments' );
		}
	}

	/**
	 * Validate the base URL override (https only).
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return string
	 * @throws Exception On a bad URL.
	 */
	public function validate_api_base_url_field( $key, $value ) {
		// Live mode never keeps an override, so a saved local URL cannot be used later.
		if ( ! $this->is_test_mode() ) {
			return '';
		}
		$value = trim( (string) wp_unslash( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$p = wp_parse_url( $value );
		if ( ! is_array( $p ) || empty( $p['host'] ) || 'https' !== strtolower( (string) $p['scheme'] ) || isset( $p['query'] ) || isset( $p['user'] ) ) {
			throw new Exception( esc_html__( 'The API base URL must be an https URL without a query string.', 'finlinks-stablecoin-payments' ) );
		}
		if ( Finlinks_Api_Base::is_production_host( $value ) ) {
			throw new Exception( esc_html__( 'Test mode cannot use the live API host. Enter a different https API base URL. USDC payments stay hidden until one is saved.', 'finlinks-stablecoin-payments' ) );
		}
		return untrailingslashit( esc_url_raw( $value ) );
	}

	/**
	 * The Connect row is not a saved setting.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_finlinks_connect_field( $key, $value ) {
		unset( $key, $value );
		return '';
	}

	/**
	 * Connect button. Not a stored setting.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_finlinks_connect_html( $key, $data ) {
		unset( $key, $data );
		ob_start();
		Finlinks_Admin::render_connect_field( $this );
		return ob_get_clean();
	}

	/**
	 * Save settings and warn about a mode/key mismatch.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		// New settings may fix whatever paused the method; the next checkout re-checks with the API.
		self::unpause();
		if ( class_exists( 'WC_Admin_Settings' ) ) {
			if ( $this->is_test_mode() && '' === $this->get_api_base() ) {
				WC_Admin_Settings::add_error( __( 'Test mode needs an https API base URL that is not the live API. USDC payments stay hidden until one is saved.', 'finlinks-stablecoin-payments' ) );
			}
			if ( '' !== $this->get_api_key() && ! $this->key_matches_mode() ) {
				WC_Admin_Settings::add_error(
					$this->is_test_mode()
						? __( 'Test mode is on, so the API key must start with flk_test_. USDC payments stay hidden until this is fixed.', 'finlinks-stablecoin-payments' )
						: __( 'Test mode is off, so the API key must start with flk_live_. USDC payments stay hidden until this is fixed.', 'finlinks-stablecoin-payments' )
				);
			}
		}
		return $saved;
	}

	/**
	 * Settings page with the connection panel.
	 */
	public function admin_options() {
		parent::admin_options();
		Finlinks_Admin::render_connection_panel( $this );
	}
}
