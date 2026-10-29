<?php
/**
 * WooCommerce Connect (contract §3, §4.1, §4.4, §4.6, §4.7, §5, §8.1).
 *
 * Pure helpers. WordPress I/O stays in Finlinks_Admin.
 * The verifier, challenge, code, API key and webhook secret are never put in a URL or a log line by these helpers.
 *
 * @package FinLinks\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * PKCE, allowlisted return URL, exchange decisions, and rotation cutover.
 */
final class Finlinks_Connect {

	const VERIFIER_TTL = 600;

	const PENDING_TTL = 86400;

	const PENDING_OPTION = 'finlinks_wc_pending_rotation';

	const SETTINGS_OPTION = 'woocommerce_finlinks_settings';

	const PORTAL_HOST = 'portal.finlinks.io';

	const PORTAL_PATH = '/integrations/woocommerce/connect';

	/**
	 * RFC 7636 verifier: 32 CSPRNG bytes, base64url, no padding (43 chars).
	 *
	 * @return string
	 */
	public static function generate_verifier() {
		return self::base64url( random_bytes( 32 ) );
	}

	/**
	 * S256 challenge: base64url(SHA-256(verifier)), no padding. Empty when the verifier is not usable.
	 *
	 * @param string $verifier Verifier.
	 * @return string
	 */
	public static function code_challenge( $verifier ) {
		if ( ! self::is_verifier( $verifier ) ) {
			return '';
		}
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * @param string $verifier Verifier.
	 * @return bool
	 */
	public static function is_verifier( $verifier ) {
		return is_string( $verifier ) && 1 === preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier );
	}

	/**
	 * Service state: 16–512 chars from the contract alphabet.
	 *
	 * @param string $state State.
	 * @return bool
	 */
	public static function is_state( $state ) {
		return is_string( $state ) && 1 === preg_match( '/^[A-Za-z0-9._~-]{16,512}$/', $state );
	}

	/**
	 * One-time code. The contract does not fix the alphabet; this accepts the same token characters as state.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function is_code( $code ) {
		return is_string( $code ) && 1 === preg_match( '/^[A-Za-z0-9._~-]{1,512}$/', $code );
	}

	/**
	 * Transient name for a state. The raw state is not the key (WordPress caps option names).
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function verifier_transient_key( $state ) {
		return 'finlinks_cv_' . hash( 'sha256', (string) $state );
	}

	/**
	 * Store the verifier until the transient expires. Called when Connect starts.
	 *
	 * @param string $state    State.
	 * @param string $verifier Verifier.
	 */
	public static function put_verifier( $state, $verifier ) {
		set_transient( self::verifier_transient_key( $state ), $verifier, self::VERIFIER_TTL );
	}

	/**
	 * Read and delete the verifier. Missing and unusable values return ''.
	 * Exchange calls this before the HTTP request, so success and failure both delete it. Cancel does too.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function take_verifier( $state ) {
		$key   = self::verifier_transient_key( $state );
		$value = get_transient( $key );
		delete_transient( $key );
		return is_string( $value ) && self::is_verifier( $value ) ? $value : '';
	}

	/**
	 * rotate=true when the saved key is not valid for the current mode, or when the merchant chose Reconnect.
	 *
	 * @param bool $key_matches Saved key matches the current mode.
	 * @param bool $reconnect   Explicit Reconnect.
	 * @return bool
	 */
	public static function should_rotate( $key_matches, $reconnect ) {
		if ( ! $key_matches ) {
			return true;
		}
		return (bool) $reconnect;
	}

	/**
	 * POST /connect/start body. No pluginVersion (no telemetry).
	 *
	 * @param string $store_url  Store URL.
	 * @param string $return_url Allowlisted return URL.
	 * @param string $mode       live or test.
	 * @param bool   $rotate     Rotation flag.
	 * @param string $verifier   PKCE verifier.
	 * @return array
	 */
	public static function start_request( $store_url, $return_url, $mode, $rotate, $verifier ) {
		return array(
			'storeUrl'            => (string) $store_url,
			'returnUrl'           => (string) $return_url,
			'mode'                => 'test' === $mode ? 'test' : 'live',
			'rotate'              => (bool) $rotate,
			'codeChallenge'       => self::code_challenge( $verifier ),
			'codeChallengeMethod' => 'S256',
		);
	}

	/**
	 * POST /connect/exchange body.
	 *
	 * @param string $code     One-time code.
	 * @param string $state    State.
	 * @param string $store_url Store URL.
	 * @param string $verifier PKCE verifier.
	 * @return array
	 */
	public static function exchange_request( $code, $state, $store_url, $verifier ) {
		return array(
			'code'         => (string) $code,
			'state'        => (string) $state,
			'storeUrl'     => (string) $store_url,
			'codeVerifier' => (string) $verifier,
		);
	}

	/**
	 * Bare JSON success, or an error code. The server message is dropped.
	 *
	 * @param int    $status HTTP status.
	 * @param string $raw    Response body.
	 * @return array{ok:bool,status:int,code:string,data:array}
	 */
	public static function interpret_http( $status, $raw ) {
		$status = (int) $status;
		if ( 404 === $status ) {
			return array(
				'ok'     => false,
				'status' => 404,
				'code'   => 'CONNECT_DISABLED',
				'data'   => array(),
			);
		}
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'     => false,
				'status' => $status,
				'code'   => 'COMMERCE_CONNECT_REQUEST_INVALID',
				'data'   => array(),
			);
		}
		$failed = $status < 200 || $status >= 300 || ( array_key_exists( 'success', $decoded ) && false === $decoded['success'] );
		if ( $failed ) {
			$code = isset( $decoded['code'] ) && is_string( $decoded['code'] ) ? $decoded['code'] : '';
			return array(
				'ok'     => false,
				'status' => $status,
				'code'   => $code,
				'data'   => array(),
			);
		}
		return array(
			'ok'     => true,
			'status' => $status,
			'code'   => '',
			'data'   => $decoded,
		);
	}

	/**
	 * What the exchange body means for the saved credentials.
	 *
	 * action is save, reuse, needs_reauth, or reject. Secrets are returned only for save.
	 *
	 * @param array $data          Decoded exchange body.
	 * @param bool  $test_mode     Test mode.
	 * @param bool  $has_local_key Saved key matches the current mode.
	 * @return array{action:string,code:string,api_key:string,webhook_secret:string}
	 */
	public static function decide_exchange( array $data, $test_mode, $has_local_key ) {
		$empty = array(
			'action'         => 'reject',
			'code'           => 'COMMERCE_CONNECT_REQUEST_INVALID',
			'api_key'        => '',
			'webhook_secret' => '',
		);
		if ( ! $test_mode && self::live_api_base_rejected( $data ) ) {
			$empty['code'] = 'FINLINKS_API_BASE_MISMATCH';
			return $empty;
		}
		if ( ! empty( $data['reused'] ) && self::secrets_absent( $data ) ) {
			if ( $has_local_key ) {
				$empty['action'] = 'reuse';
				$empty['code']   = '';
				return $empty;
			}
			$empty['action'] = 'needs_reauth';
			$empty['code']   = 'COMMERCE_CONNECTION_NEEDS_REAUTH';
			return $empty;
		}
		$api_key = isset( $data['apiKey'] ) && is_string( $data['apiKey'] ) ? $data['apiKey'] : '';
		$secret  = isset( $data['webhookSecret'] ) && is_string( $data['webhookSecret'] ) ? $data['webhookSecret'] : '';
		if ( ! $test_mode ) {
			if ( ! Finlinks_Credentials::is_live_key( $api_key ) || ! Finlinks_Credentials::is_secret( $secret ) ) {
				$empty['code'] = 'FINLINKS_CREDENTIALS_REJECTED';
				return $empty;
			}
		} elseif ( ! Finlinks_Credentials::is_key_shape( $api_key ) || 0 !== strpos( $api_key, 'flk_test_' ) || ! Finlinks_Credentials::is_secret( $secret ) ) {
			$empty['code'] = 0 === strpos( $api_key, 'flk_live_' ) ? 'COMMERCE_CONNECT_MODE_MISMATCH' : 'FINLINKS_CREDENTIALS_REJECTED';
			return $empty;
		}
		return array(
			'action'         => 'save',
			'code'           => '',
			'api_key'        => $api_key,
			'webhook_secret' => $secret,
		);
	}

	/**
	 * Pending rotation record. The new API key stays in memory for this request.
	 *
	 * @param string $secret Webhook secret.
	 * @param int    $now    Unix time.
	 * @return array{webhook_secret:string,at:int}
	 */
	public static function pending_record( $secret, $now ) {
		return array(
			'webhook_secret' => (string) $secret,
			'at'             => (int) $now,
		);
	}

	/**
	 * Webhook secret from a pending record, or '' when missing, malformed, or older than 24 hours.
	 *
	 * @param mixed $option Option value.
	 * @param int   $now    Unix time.
	 * @return string
	 */
	public static function pending_webhook_secret( $option, $now ) {
		if ( ! is_array( $option ) || ! isset( $option['webhook_secret'], $option['at'] ) || ! is_string( $option['webhook_secret'] ) ) {
			return '';
		}
		if ( (int) $option['at'] <= 0 || (int) $now > (int) $option['at'] + self::PENDING_TTL ) {
			return '';
		}
		if ( ! Finlinks_Credentials::is_secret( $option['webhook_secret'] ) ) {
			return '';
		}
		return $option['webhook_secret'];
	}

	/**
	 * ignore, cancel, or exchange.
	 *
	 * @param string $marker finlinks_connect query value.
	 * @param string $code   code query value.
	 * @param string $state  state query value.
	 * @return string
	 */
	public static function callback_action( $marker, $code, $state ) {
		$marker    = is_string( $marker ) ? $marker : '';
		$has_code  = is_string( $code ) && '' !== $code;
		$has_state = is_string( $state ) && '' !== $state;
		if ( 'cancelled' === $marker ) {
			return 'cancel';
		}
		if ( $has_code || $has_state ) {
			return 'exchange';
		}
		return 'ignore';
	}

	/**
	 * https store URL without a trailing slash. Empty when Connect cannot use this home URL.
	 *
	 * @param string $home_url home_url( '/' ).
	 * @return string
	 */
	public static function store_url( $home_url ) {
		$p = self::parts( $home_url );
		if ( null === $p || 'https' !== $p['scheme'] ) {
			return '';
		}
		return $p['scheme'] . '://' . $p['host'] . $p['path'];
	}

	/**
	 * Primary allowlist return URL (§5), including finlinks_connect=1.
	 *
	 * @param string $home_url home_url( '/' ).
	 * @return string
	 */
	public static function return_url( $home_url ) {
		$store = self::store_url( $home_url );
		if ( '' === $store ) {
			return '';
		}
		return $store . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=finlinks&finlinks_connect=1';
	}

	/**
	 * Connect is offered only when the primary return URL is the real settings page.
	 * home_url !== site_url, a core-in-its-own-directory install, and http stores return false.
	 * A subdirectory store whose admin page is {base}/wp-admin/admin.php is allowed.
	 *
	 * @param string $home_url      home_url( '/' ).
	 * @param string $site_url      site_url( '/' ).
	 * @param string $admin_php_url admin_url( 'admin.php' ).
	 * @return bool
	 */
	public static function connect_supported( $home_url, $site_url, $admin_php_url ) {
		$home  = self::parts( $home_url );
		$site  = self::parts( $site_url );
		$admin = self::parts( $admin_php_url );
		if ( null === $home || null === $site || null === $admin ) {
			return false;
		}
		if ( 'https' !== $home['scheme'] || 'https' !== $site['scheme'] || 'https' !== $admin['scheme'] ) {
			return false;
		}
		if ( $home['host'] !== $site['host'] || $home['host'] !== $admin['host'] || $home['path'] !== $site['path'] ) {
			return false;
		}
		return $admin['path'] === $home['path'] . '/wp-admin/admin.php';
	}

	/**
	 * authorizeUrl may be used for this mode. Live mode is portal.finlinks.io only.
	 * Test mode accepts another https host on the same path, and not the live portal.
	 *
	 * @param string $url       authorizeUrl.
	 * @param bool   $test_mode Test mode.
	 * @param string $state     State from the start response.
	 * @return bool
	 */
	public static function authorize_url_ok( $url, $test_mode, $state ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) || 'https' !== strtolower( (string) $p['scheme'] ) ) {
			return false;
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || isset( $p['fragment'] ) ) {
			return false;
		}
		$path = isset( $p['path'] ) ? rtrim( (string) $p['path'], '/' ) : '';
		if ( self::PORTAL_PATH !== $path ) {
			return false;
		}
		$host = strtolower( (string) $p['host'] );
		if ( $test_mode ) {
			if ( in_array( $host, array( self::PORTAL_HOST, 'pay.finlinks.io', Finlinks_Api_Base::PROD_HOST ), true ) ) {
				return false;
			}
		} elseif ( self::PORTAL_HOST !== $host ) {
			return false;
		}
		$query = array();
		if ( isset( $p['query'] ) ) {
			parse_str( (string) $p['query'], $query );
		}
		if ( array( 'state' ) !== array_keys( $query ) || ! isset( $query['state'] ) || ! is_string( $query['state'] ) ) {
			return false;
		}
		if ( ! self::is_state( $state ) || ! hash_equals( $state, $query['state'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Live mode rejects a present apiBase that is not https://api.finlinks.io.
	 * A credential response with no apiBase is also rejected. Reuse may omit it.
	 *
	 * @param array $data Exchange body.
	 * @return bool
	 */
	private static function live_api_base_rejected( array $data ) {
		$issuing = empty( $data['reused'] ) || ! self::secrets_absent( $data );
		$base    = isset( $data['apiBase'] ) && is_string( $data['apiBase'] ) ? $data['apiBase'] : '';
		if ( '' === $base ) {
			return $issuing;
		}
		return ! Finlinks_Api_Base::is_exact_prod( $base );
	}

	/**
	 * @param array $data Exchange body.
	 * @return bool
	 */
	private static function secrets_absent( array $data ) {
		$key    = array_key_exists( 'apiKey', $data ) ? $data['apiKey'] : null;
		$secret = array_key_exists( 'webhookSecret', $data ) ? $data['webhookSecret'] : null;
		return ( null === $key || '' === $key ) && ( null === $secret || '' === $secret );
	}

	/**
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Scheme, lowercase host, and path without a trailing slash. Null when the URL has userinfo, a port, a query, or a fragment.
	 *
	 * @param string $url URL.
	 * @return array{scheme:string,host:string,path:string}|null
	 */
	private static function parts( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return null;
		}
		$p = wp_parse_url( trim( $url ) );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return null;
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || isset( $p['fragment'] ) ) {
			return null;
		}
		if ( isset( $p['query'] ) && '' !== $p['query'] ) {
			return null;
		}
		$path = isset( $p['path'] ) ? (string) $p['path'] : '';
		if ( '/' === $path ) {
			$path = '';
		}
		$path = rtrim( $path, '/' );
		return array(
			'scheme' => strtolower( (string) $p['scheme'] ),
			'host'   => strtolower( (string) $p['host'] ),
			'path'   => $path,
		);
	}
}
