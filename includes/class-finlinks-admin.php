<?php
/**
 * Connection panel: test connection, register webhook + verify store (§4.2, §4.4), send test webhook.
 *
 * @package FinLinks\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class Finlinks_Admin {

	const ACTION         = 'finlinks_wc_connection';
	const CONNECT_ACTION = 'finlinks_wc_connect';
	const CLOCK_OPTION   = 'finlinks_wc_clock';
	const STALE_OPTION   = 'finlinks_wc_stale_webhook';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( __CLASS__, 'handle_connect_post' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_oauth_callback' ) );
		add_action( 'admin_notices', array( __CLASS__, 'clock_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/** Whether the current screen is the FinLinks payment settings page. */
	public static function is_settings_screen() {
		$screen = get_current_screen();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		$section = isset( $_GET['section'] ) && is_string( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		return $screen && 'woocommerce_page_wc-settings' === $screen->id && 'checkout' === $tab && Finlinks_Gateway::ID === $section;
	}

	/** Load connection controls only on their settings screen. */
	public static function enqueue_scripts() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_settings_screen() ) {
			return;
		}
		wp_enqueue_script( 'finlinks-admin', FINLINKS_WC_URL . 'assets/js/admin.js', array(), FINLINKS_WC_VERSION, true );
	}

	/**
	 * Clock-skew warning text, or '' when the clock looks fine (§16.5).
	 * Sources: the API's Date header (last 24 h) and signed webhooks rejected as stale (last 24 h).
	 *
	 * @return string
	 */
	public static function clock_warning() {
		// Use the most recent measurement; on a tie the Date header (a direct measurement) wins.
		$skew   = null;
		$latest = -1;
		foreach ( array( self::CLOCK_OPTION, self::STALE_OPTION ) as $opt ) {
			$v = get_option( $opt );
			if ( is_array( $v ) && isset( $v['skew'], $v['at'] ) && $v['at'] > time() - DAY_IN_SECONDS && (int) $v['at'] > $latest ) {
				$latest = (int) $v['at'];
				$skew   = (int) $v['skew'];
			}
		}
		if ( ! Finlinks_Timing::skew_is_excessive( $skew ) ) {
			$skew = null;
		}
		if ( null === $skew ) {
			return '';
		}
		return sprintf(
			/* translators: 1: seconds, 2: ahead/behind */
			__( 'USDC payments: this server\'s clock is %1$d seconds %2$s the payment API. Webhooks older than 5 minutes are refused, so order updates may be delayed until the 10-minute backup check runs. Please fix the server time (NTP).', 'finlinks-stablecoin-payments' ),
			abs( $skew ),
			$skew > 0 ? __( 'ahead of', 'finlinks-stablecoin-payments' ) : __( 'behind', 'finlinks-stablecoin-payments' )
		);
	}

	/**
	 * admin_notices: clock warning for shop managers.
	 */
	public static function clock_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_settings_screen() ) {
			return;
		}
		$msg = self::clock_warning();
		if ( '' !== $msg ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $msg ) . '</p></div>';
		}
		$pause = self::pause_warning();
		if ( '' !== $pause ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $pause ) . '</p></div>';
		}
	}

	/**
	 * Pause warning text, or '' when USDC payments are not paused.
	 *
	 * @return string
	 */
	public static function pause_warning() {
		$p = Finlinks_Gateway::pause_state();
		if ( null === $p ) {
			return '';
		}
		return sprintf(
			/* translators: 1: error code, 2: minutes */
			__( 'USDC payments are hidden at checkout for up to %2$d minutes because the payment API rejected this store\'s connection (%1$s). Check the connection in the FinLinks portal and in WooCommerce > Settings > Payments > Pay with USDC, then click "Test connection".', 'finlinks-stablecoin-payments' ),
			(string) $p['code'],
			(int) ceil( Finlinks_Gateway::PAUSE_TTL / 60 )
		);
	}

	/**
	 * Run a connection action. Returns [success, message]. Connect also returns the verify result when verify ran.
	 * Messages never contain secrets. A passed client skips the saved-key checks (rotation verifies the new key).
	 *
	 * @param string                   $what test|connect|ping.
	 * @param Finlinks_Api_Client|null $api  Client. Null uses the saved key.
	 * @return array{0:bool,1:string,2?:array}
	 */
	public static function run( $what, $api = null ) {
		if ( null === $api ) {
			$gateway = Finlinks_Plugin::gateway();
			if ( '' === $gateway->get_api_key() || '' === $gateway->get_webhook_secret() ) {
				return array( false, __( 'Save the API key and webhook signing secret first.', 'finlinks-stablecoin-payments' ) );
			}
			if ( ! $gateway->key_matches_mode() ) {
				return array( false, __( 'The API key does not match the selected mode (test or live).', 'finlinks-stablecoin-payments' ) );
			}
			$api = $gateway->api();
		}

		if ( 'test' === $what ) {
			$res = $api->get_connection();
			if ( ! $res['ok'] ) {
				return array( false, self::error_text( $res ) );
			}
			$c = isset( $res['data']['connection'] ) && is_array( $res['data']['connection'] ) ? $res['data']['connection'] : $res['data'];
			if ( isset( $c['status'] ) && 'ACTIVE' === $c['status'] ) {
				Finlinks_Gateway::unpause();
			}
			return array(
				true,
				sprintf(
					/* translators: 1: status, 2: host, 3: webhook URL */
					__( 'Connected. Status: %1$s. Store host: %2$s. Webhook: %3$s.', 'finlinks-stablecoin-payments' ),
					isset( $c['status'] ) ? $c['status'] : '?',
					isset( $c['storeHost'] ) ? $c['storeHost'] : '?',
					! empty( $c['webhookUrl'] ) ? $c['webhookUrl'] : __( 'not registered', 'finlinks-stablecoin-payments' )
				) . ( '' !== self::clock_warning() ? ' ' . self::clock_warning() : '' ),
			);
		}

		if ( 'connect' === $what ) {
			$url = Finlinks_Gateway::webhook_url();
			if ( ! Finlinks_Gateway::url_ok( $url, false ) ) {
				return array( false, __( 'The webhook URL must be https on this store host without a query string. Enable pretty permalinks (Settings > Permalinks) and serve the site over https.', 'finlinks-stablecoin-payments' ) );
			}
			$res = $api->put_webhook( $url );
			if ( ! $res['ok'] ) {
				return array( false, self::error_text( $res ) );
			}
			$res = $api->verify_connection();
			if ( ! $res['ok'] ) {
				return array( false, self::error_text( $res ), $res );
			}
			$status = isset( $res['data']['status'] ) ? (string) $res['data']['status'] : '?';
			if ( 'ACTIVE' === $status ) {
				Finlinks_Gateway::unpause();
			}
			return array( 'ACTIVE' === $status, sprintf( /* translators: %s: status */ __( 'Webhook registered and store verification finished. Connection status: %s.', 'finlinks-stablecoin-payments' ), $status ), $res );
		}

		if ( 'ping' === $what ) {
			$res = $api->test_webhook();
			if ( ! $res['ok'] ) {
				return array( false, self::error_text( $res ) );
			}
			$d         = $res['data'];
			$delivered = ! empty( $d['delivered'] );
			$http      = isset( $d['httpStatus'] ) && null !== $d['httpStatus'] ? (string) (int) $d['httpStatus'] : __( 'none', 'finlinks-stablecoin-payments' );
			$latency   = isset( $d['latencyMs'] ) ? (int) $d['latencyMs'] : 0;
			if ( $delivered ) {
				/* translators: 1: HTTP status, 2: milliseconds */
				return array( true, sprintf( __( 'Test webhook delivered. This store answered HTTP %1$s in %2$d ms.', 'finlinks-stablecoin-payments' ), $http, $latency ) );
			}
			if ( isset( $d['error'] ) && is_string( $d['error'] ) && '' !== $d['error'] ) {
				Finlinks_Logger::log( 'Test webhook was not delivered: ' . $d['error'], 'error' );
			}
			/* translators: %s: HTTP status */
			return array( false, sprintf( __( 'Test webhook was not delivered (HTTP %s).', 'finlinks-stablecoin-payments' ), $http ) );
		}

		return array( false, __( 'Unknown action.', 'finlinks-stablecoin-payments' ) );
	}

	/**
	 * Human readable API error.
	 *
	 * @param array $res Client result.
	 * @return string
	 */
	private static function error_text( array $res ) {
		$code   = isset( $res['code'] ) ? (string) $res['code'] : '';
		$detail = isset( $res['message'] ) ? (string) $res['message'] : '';
		Finlinks_Logger::log(
			sprintf( 'FinLinks request failed: HTTP %d %s %s', isset( $res['status'] ) ? (int) $res['status'] : 0, $code, $detail ),
			'error'
		);
		$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		return Finlinks_Messages::admin_failure( $code, $data );
	}

	/**
	 * admin-post handler.
	 */
	public static function handle_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'finlinks-stablecoin-payments' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$what = isset( $_POST['finlinks_do'] ) && is_string( $_POST['finlinks_do'] ) ? sanitize_key( wp_unslash( $_POST['finlinks_do'] ) ) : '';
		list( $ok, $message ) = self::run( $what );
		set_transient( 'finlinks_admin_notice_' . get_current_user_id(), array( $ok, $message ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Finlinks_Gateway::ID ) );
		exit;
	}

	/**
	 * Panel under the settings form. Uses separate forms posting to admin-post.php.
	 *
	 * @param Finlinks_Gateway $gateway Gateway.
	 */
	public static function render_connection_panel( $gateway ) {
		$notice = get_transient( 'finlinks_admin_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'finlinks_admin_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', $notice[0] ? 'success' : 'error', esc_html( $notice[1] ) );
		}
		$url = Finlinks_Gateway::webhook_url();
		echo '<h3>' . esc_html__( 'Connection', 'finlinks-stablecoin-payments' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'FinLinks services are operated by Finlinks Global Solutions LLC. An approved merchant account is required.', 'finlinks-stablecoin-payments' ) . '</p>';
		printf(
			'<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> &middot; <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p>',
			esc_url( 'https://www.finlinksglobal.com/legal/merchant-services-agreement.html' ),
			esc_html__( 'Merchant Services Agreement', 'finlinks-stablecoin-payments' ),
			esc_url( 'https://www.finlinksglobal.com/legal/privacy.html' ),
			esc_html__( 'Privacy Policy', 'finlinks-stablecoin-payments' )
		);
		echo '<p>' . esc_html__( 'Webhook URL:', 'finlinks-stablecoin-payments' ) . ' <code>' . esc_html( $url ) . '</code></p>';
		if ( ! Finlinks_Gateway::url_ok( $url, false ) ) {
			echo '<p class="description">' . esc_html__( 'This URL cannot be used yet: the store must use https and pretty permalinks.', 'finlinks-stablecoin-payments' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'After saving your key and secret, click "Register webhook and verify store". The connection goes live only after this store answers the signed verification request.', 'finlinks-stablecoin-payments' ) . '</p>';
		$buttons = array(
			'test'    => __( 'Test connection', 'finlinks-stablecoin-payments' ),
			'connect' => __( 'Register webhook and verify store', 'finlinks-stablecoin-payments' ),
			'ping'    => __( 'Send test webhook', 'finlinks-stablecoin-payments' ),
		);
		// The settings page wraps everything in one <form>, so the buttons post via formaction.
		wp_nonce_field( self::ACTION, '_finlinks_nonce', false );
		echo '<p>';
		foreach ( $buttons as $do => $label ) {
			printf(
				'<button type="button" class="button finlinks-action" data-do="%1$s" data-post-url="%3$s">%2$s</button> ',
				esc_attr( $do ),
				esc_html( $label ),
				esc_url( admin_url( 'admin-post.php' ) )
			);
		}
		echo '</p>';
	}

	/**
	 * Pending webhook secret, or ''. A stale record is ignored until the next rotation overwrites it.
	 *
	 * @return string
	 */
	public static function pending_webhook_secret() {
		$pending = get_option( Finlinks_Connect::PENDING_OPTION, null );
		if ( null === $pending ) {
			return '';
		}
		return Finlinks_Connect::pending_webhook_secret( $pending, time() );
	}

	/**
	 * Connect / Reconnect row on the gateway settings form.
	 *
	 * @param Finlinks_Gateway $gateway Gateway.
	 */
	public static function render_connect_field( $gateway ) {
		echo '<tr valign="top">';
		echo '<th scope="row">' . esc_html__( 'Connect', 'finlinks-stablecoin-payments' ) . '</th>';
		echo '<td class="forminp">';
		if ( ! Finlinks_Connect::connect_supported( home_url( '/' ), site_url( '/' ), admin_url( 'admin.php' ) ) ) {
			echo '<p class="description">' . esc_html__( 'Connect is unavailable for this site address. Paste the API key and webhook signing secret under Advanced.', 'finlinks-stablecoin-payments' ) . '</p>';
			echo '</td></tr>';
			return;
		}
		$has_key = '' !== $gateway->get_api_key();
		if ( $has_key ) {
			echo '<p class="description">' . esc_html__( 'Connect keeps the saved API key. Reconnect replaces it only after FinLinks verifies the new key.', 'finlinks-stablecoin-payments' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Approve this store in the FinLinks portal. WooCommerce registers the webhook and verifies the store.', 'finlinks-stablecoin-payments' ) . '</p>';
		}
		wp_nonce_field( self::CONNECT_ACTION, '_finlinks_connect_nonce', false );
		echo '<p>';
		printf(
			'<button type="button" class="button button-primary finlinks-connect" data-do="connect" data-post-url="%2$s">%1$s</button> ',
			esc_html__( 'Connect', 'finlinks-stablecoin-payments' ),
			esc_url( admin_url( 'admin-post.php' ) )
		);
		if ( $has_key ) {
			printf(
				'<button type="button" class="button finlinks-connect" data-do="reconnect" data-post-url="%2$s">%1$s</button>',
				esc_html__( 'Reconnect', 'finlinks-stablecoin-payments' ),
				esc_url( admin_url( 'admin-post.php' ) )
			);
		}
		echo '</p>';
		echo '</td></tr>';
	}

	/**
	 * Start Connect. Redirects to the portal, or back to settings with a notice.
	 */
	public static function handle_connect_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'finlinks-stablecoin-payments' ), 403 );
		}
		check_admin_referer( self::CONNECT_ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$do = isset( $_POST['finlinks_do'] ) && is_string( $_POST['finlinks_do'] ) ? sanitize_key( wp_unslash( $_POST['finlinks_do'] ) ) : '';
		self::begin_connect( 'reconnect' === $do );
	}

	/**
	 * Finish a portal return on the settings screen: exchange or cancel, then strip the query.
	 */
	public static function maybe_oauth_callback() {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( 'wc-settings' !== self::callback_query( 'page' ) || 'checkout' !== self::callback_query( 'tab' ) || Finlinks_Gateway::ID !== self::callback_query( 'section' ) ) {
			return;
		}
		$marker = self::callback_query( 'finlinks_connect' );
		$code   = self::callback_query( 'code' );
		$state  = self::callback_query( 'state' );
		$action = Finlinks_Connect::callback_action( $marker, $code, $state );
		if ( 'ignore' === $action ) {
			return;
		}
		if ( 'cancel' === $action ) {
			if ( Finlinks_Connect::is_state( $state ) ) {
				Finlinks_Connect::take_verifier( $state );
			}
			self::redirect_notice( self::settings_url(), false, Finlinks_Messages::connect( 'FINLINKS_CONNECT_CANCELLED' ) );
		}
		self::finish_exchange( $code, $state );
	}

	/**
	 * @param string $key Query key.
	 * @return string
	 */
	private static function callback_query( $key ) {
		// OAuth state is the CSRF check. The portal cannot send a WordPress nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * @param bool $reconnect Explicit Reconnect.
	 */
	private static function begin_connect( $reconnect ) {
		$back = self::settings_url();
		if ( ! Finlinks_Connect::connect_supported( home_url( '/' ), site_url( '/' ), admin_url( 'admin.php' ) ) ) {
			self::redirect_notice( $back, false, Finlinks_Messages::connect( 'COMMERCE_CONNECT_RETURN_URL_INVALID' ) );
		}
		$gateway = Finlinks_Plugin::gateway();
		$base    = $gateway->get_api_base();
		if ( '' === $base ) {
			self::redirect_notice( $back, false, __( 'Test mode needs an https API base URL that is not the live API. USDC payments stay hidden until one is saved.', 'finlinks-stablecoin-payments' ) );
		}
		$store  = Finlinks_Connect::store_url( home_url( '/' ) );
		$return = Finlinks_Connect::return_url( home_url( '/' ) );
		if ( '' === $store || '' === $return ) {
			self::redirect_notice( $back, false, Finlinks_Messages::connect( 'COMMERCE_CONNECT_HTTPS_REQUIRED' ) );
		}
		$verifier = Finlinks_Connect::generate_verifier();
		$rotate   = Finlinks_Connect::should_rotate( $gateway->key_matches_mode(), $reconnect );
		$body     = Finlinks_Connect::start_request( $store, $return, $gateway->is_test_mode() ? 'test' : 'live', $rotate, $verifier );
		$res      = self::post_connect( $base, '/api/v1/commerce/woo/connect/start', $body );
		if ( ! $res['ok'] ) {
			self::redirect_notice( $back, false, Finlinks_Messages::connect( $res['code'] ) );
		}
		$state     = isset( $res['data']['state'] ) ? $res['data']['state'] : '';
		$authorize = isset( $res['data']['authorizeUrl'] ) ? $res['data']['authorizeUrl'] : '';
		if ( ! is_string( $state ) || ! is_string( $authorize ) || ! Finlinks_Connect::authorize_url_ok( $authorize, $gateway->is_test_mode(), $state ) ) {
			self::redirect_notice( $back, false, Finlinks_Messages::connect( 'COMMERCE_CONNECT_REQUEST_INVALID' ) );
		}
		Finlinks_Connect::put_verifier( $state, $verifier );
		$host = (string) wp_parse_url( $authorize, PHP_URL_HOST );
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				$hosts[] = strtolower( $host );
				return $hosts;
			}
		);
		wp_safe_redirect( $authorize );
		exit;
	}

	/**
	 * Exchange the code, then redirect with the callback query removed.
	 *
	 * @param string $code  Code from the query string. Not logged.
	 * @param string $state State from the query string.
	 */
	private static function finish_exchange( $code, $state ) {
		$verifier = Finlinks_Connect::is_state( $state ) ? Finlinks_Connect::take_verifier( $state ) : '';
		if ( ! Finlinks_Connect::is_state( $state ) || ! Finlinks_Connect::is_code( $code ) ) {
			self::redirect_notice( self::settings_url(), false, Finlinks_Messages::connect( 'COMMERCE_CONNECT_STATE_INVALID' ) );
		}
		if ( ! Finlinks_Connect::is_verifier( $verifier ) ) {
			self::redirect_notice( self::settings_url(), false, Finlinks_Messages::connect( 'COMMERCE_CONNECT_STATE_EXPIRED' ) );
		}
		$gateway = Finlinks_Plugin::gateway();
		$store   = Finlinks_Connect::store_url( home_url( '/' ) );
		$base    = $gateway->get_api_base();
		if ( '' === $store ) {
			self::redirect_notice( self::settings_url(), false, Finlinks_Messages::connect( 'COMMERCE_CONNECT_HTTPS_REQUIRED' ) );
		}
		if ( '' === $base ) {
			self::redirect_notice( self::settings_url(), false, __( 'Test mode needs an https API base URL that is not the live API. USDC payments stay hidden until one is saved.', 'finlinks-stablecoin-payments' ) );
		}
		$res      = self::post_connect( $base, '/api/v1/commerce/woo/connect/exchange', Finlinks_Connect::exchange_request( $code, $state, $store, $verifier ) );
		$verifier = '';
		if ( ! $res['ok'] ) {
			$err = '' !== $res['code'] ? $res['code'] : 'COMMERCE_CONNECT_REQUEST_INVALID';
			self::redirect_notice( self::settings_url(), false, Finlinks_Messages::connect( $err ) );
		}
		list( $ok, $message ) = self::apply_exchange( $gateway, $res['data'] );
		self::redirect_notice( self::settings_url(), $ok, $message );
	}

	/**
	 * @param Finlinks_Gateway $gateway Gateway.
	 * @param array            $data    Exchange body. May contain secrets; not logged.
	 * @return array{0:bool,1:string}
	 */
	private static function apply_exchange( $gateway, array $data ) {
		$decision = Finlinks_Connect::decide_exchange( $data, $gateway->is_test_mode(), $gateway->key_matches_mode() );
		if ( 'reuse' === $decision['action'] ) {
			list( $ok, $message ) = self::run( 'connect' );
			if ( $ok ) {
				$message = __( 'This store is already connected. The saved API key and webhook signing secret were kept.', 'finlinks-stablecoin-payments' ) . ' ' . $message;
			}
			return array( $ok, $message );
		}
		if ( 'save' !== $decision['action'] ) {
			return array( false, Finlinks_Messages::connect( $decision['code'] ) );
		}
		if ( '' !== $gateway->get_api_key() && '' !== $gateway->get_webhook_secret() ) {
			return self::verify_pending( $gateway, $decision );
		}
		return self::save_then_verify( $gateway, $decision );
	}

	/**
	 * First connection: save, then the existing register + verify path.
	 *
	 * @param Finlinks_Gateway $gateway  Gateway.
	 * @param array            $decision Save decision.
	 * @return array{0:bool,1:string}
	 */
	private static function save_then_verify( $gateway, array $decision ) {
		$settings = get_option( Finlinks_Connect::SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['api_key']        = $decision['api_key'];
		$settings['webhook_secret'] = $decision['webhook_secret'];
		update_option( Finlinks_Connect::SETTINGS_OPTION, $settings );
		if ( method_exists( $gateway, 'init_settings' ) ) {
			$gateway->init_settings();
		}
		return self::run( 'connect' );
	}

	/**
	 * Rotation: verify with the new key, and replace the saved key only after HTTP 200.
	 *
	 * @param Finlinks_Gateway $gateway  Gateway.
	 * @param array            $decision Save decision.
	 * @return array{0:bool,1:string}
	 */
	private static function verify_pending( $gateway, array $decision ) {
		$base = $gateway->get_api_base();
		if ( '' === $base ) {
			return array( false, __( 'Test mode needs an https API base URL that is not the live API. USDC payments stay hidden until one is saved.', 'finlinks-stablecoin-payments' ) );
		}
		$new_key    = $decision['api_key'];
		$new_secret = $decision['webhook_secret'];
		update_option( Finlinks_Connect::PENDING_OPTION, Finlinks_Connect::pending_record( $new_secret, time() ), false );
		Finlinks_Logger::hold( array( $new_secret, $new_key ) );
		try {
			$result = self::run( 'connect', new Finlinks_Api_Client( $base, $new_key ) );
		} finally {
			Finlinks_Logger::hold( array() );
		}
		$ver    = ( isset( $result[2] ) && is_array( $result[2] ) ) ? $result[2] : null;
		if ( is_array( $ver ) && 200 === (int) $ver['status'] && ! empty( $ver['ok'] ) ) {
			$settings = get_option( Finlinks_Connect::SETTINGS_OPTION, array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			$settings['api_key']        = $new_key;
			$settings['webhook_secret'] = $new_secret;
			update_option( Finlinks_Connect::SETTINGS_OPTION, $settings );
			delete_option( Finlinks_Connect::PENDING_OPTION );
			return array( $result[0], $result[1] );
		}
		delete_option( Finlinks_Connect::PENDING_OPTION );
		if ( is_array( $ver ) && ( 0 === (int) $ver['status'] || (int) $ver['status'] >= 500 ) ) {
			return array( false, Finlinks_Messages::connect( 'FINLINKS_ROTATION_UNCERTAIN' ) );
		}
		return array( false, $result[1] );
	}

	/**
	 * Unauthenticated Connect POST. The body is not logged.
	 *
	 * @param string $base API base URL.
	 * @param string $path Path.
	 * @param array  $body JSON body.
	 * @return array{ok:bool,status:int,code:string,data:array}
	 */
	private static function post_connect( $base, $path, array $body ) {
		$base     = untrailingslashit( (string) $base );
		$response = wp_remote_post(
			$base . $path,
			array(
				'method'      => 'POST',
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'FinLinks-Stablecoin-Payments/' . FINLINKS_WC_VERSION,
				),
				'body'        => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			Finlinks_Logger::log( 'POST ' . $path . ' -> network error', 'error' );
			return array(
				'ok'     => false,
				'status' => 0,
				'code'   => 'NETWORK_ERROR',
				'data'   => array(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = Finlinks_Connect::interpret_http( $status, (string) wp_remote_retrieve_body( $response ) );
		$logged = $parsed['ok'] ? 'ok' : Finlinks_Messages::safe_code( $parsed['code'] );
		Finlinks_Logger::log( sprintf( 'POST %s -> %d %s', $path, $status, $logged ), $parsed['ok'] ? 'info' : 'error' );
		return $parsed;
	}

	/**
	 * @param string $url     Destination with no callback secrets.
	 * @param bool   $ok      Success notice.
	 * @param string $message Notice text.
	 */
	private static function redirect_notice( $url, $ok, $message ) {
		set_transient( 'finlinks_admin_notice_' . get_current_user_id(), array( (bool) $ok, $message ), 60 );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Gateway settings URL. The callback redirects here, so code, state, and finlinks_connect are left behind.
	 *
	 * @return string
	 */
	private static function settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Finlinks_Gateway::ID );
	}
}
