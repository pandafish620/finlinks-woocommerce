<?php
/**
 * Plugin Name:       FinLinks Stablecoin Payments for WooCommerce
 * Description:       Accept USDC payments through a FinLinks hosted payment page.
 * Version:           1.1.2
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Plugin URI:        https://www.finlinksglobal.com/integrations/woocommerce
 * Author:            Finlinks Global Solutions LLC
 * Author URI:        https://www.finlinksglobal.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       finlinks-stablecoin-payments
 * Domain Path:       /languages
 * WC requires at least: 8.2
 * WC tested up to:   11.2.1
 *
 * @package FinLinks\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Basename of the previous plugin directory. Still the only production install.
 */
function finlinks_stablecoin_payments_legacy_basename() {
	return 'finlinks-woocommerce/finlinks-woocommerce.php';
}

/**
 * Whether this copy must stay inactive.
 *
 * Active plugins are included in the order stored in active_plugins, and there
 * is no hook between those files. This check runs before this copy declares a
 * class. When the previous plugin is still listed and its file is on disk, this
 * copy returns immediately, whichever of the two files is included first.
 * class_exists() only covers a copy that was included already. Once this copy
 * has declared Finlinks_Plugin, a later include of the old file can still fatal.
 * Deactivate the old plugin before activating this one.
 *
 * @return bool
 */
function finlinks_stablecoin_payments_legacy_active() {
	$legacy = finlinks_stablecoin_payments_legacy_basename();
	$listed = in_array( $legacy, (array) get_option( 'active_plugins', array() ), true );
	if ( is_multisite() ) {
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		if ( isset( $network[ $legacy ] ) ) {
			$listed = true;
		}
	}
	// A stale active_plugins entry whose file is already gone must not block this copy.
	if ( $listed && file_exists( WP_PLUGIN_DIR . '/' . $legacy ) ) {
		return true;
	}
	if ( defined( 'FINLINKS_WC_FILE' ) ) {
		$path = wp_normalize_path( (string) FINLINKS_WC_FILE );
		if ( false !== strpos( $path, '/finlinks-woocommerce/finlinks-woocommerce.php' ) ) {
			return true;
		}
	}
	// The older copy defines this class. This copy must not define it again.
	return class_exists( 'Finlinks_Plugin', false );
}

/**
 * Admin notice when both copies would otherwise boot.
 */
function finlinks_stablecoin_payments_legacy_notice() {
	$screen = get_current_screen();
	if ( ! current_user_can( 'activate_plugins' ) || ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'FinLinks Stablecoin Payments for WooCommerce is not running because FinLinks Pay for WooCommerce is still active. Deactivate the older plugin. Do not delete it: deleting runs its uninstaller and removes the saved API key and webhook secret.', 'finlinks-stablecoin-payments' );
	echo '</p></div>';
}

if ( finlinks_stablecoin_payments_legacy_active() ) {
	add_action( 'admin_notices', 'finlinks_stablecoin_payments_legacy_notice' );
	add_action( 'network_admin_notices', 'finlinks_stablecoin_payments_legacy_notice' );
	return;
}

define( 'FINLINKS_WC_VERSION', '1.1.2' );
define( 'FINLINKS_WC_FILE', __FILE__ );
define( 'FINLINKS_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FINLINKS_WC_URL', plugin_dir_url( __FILE__ ) );

require_once FINLINKS_WC_DIR . 'includes/class-finlinks-amount.php';
require_once FINLINKS_WC_DIR . 'includes/class-finlinks-defaults.php';
require_once FINLINKS_WC_DIR . 'includes/class-finlinks-messages.php';
require_once FINLINKS_WC_DIR . 'includes/class-finlinks-hosted-url.php';
require_once FINLINKS_WC_DIR . 'includes/class-finlinks-signature.php';
require_once FINLINKS_WC_DIR . 'includes/class-finlinks-timing.php';
require_once FINLINKS_WC_DIR . 'includes/class-finlinks-session-decider.php';
require_once FINLINKS_WC_DIR . 'includes/interface-finlinks-event-store.php';
require_once FINLINKS_WC_DIR . 'includes/class-finlinks-webhook-processor.php';
require_once FINLINKS_WC_DIR . 'includes/class-finlinks-plugin.php';

register_activation_hook( __FILE__, array( 'Finlinks_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Finlinks_Plugin', 'deactivate' ) );

// Declare HPOS and Cart/Checkout Blocks compatibility.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Finlinks_Plugin', 'init' ), 11 );
