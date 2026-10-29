<?php
/**
 * Cart/Checkout Blocks integration.
 *
 * @package FinLinks\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Finlinks_Blocks extends AbstractPaymentMethodType {

	/** @var string */
	protected $name = 'finlinks';

	/** @var Finlinks_Gateway|null */
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_finlinks_settings', array() );
		$this->gateway  = Finlinks_Plugin::gateway();
	}

	public function is_active() {
		if ( ! $this->gateway || 'yes' !== $this->gateway->enabled || ! $this->gateway->is_configured() ) {
			return false;
		}
		// Classic checkout hides the method for PAUSE_TTL seconds. Blocks must do the same.
		return null === Finlinks_Gateway::pause_state();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'finlinks-blocks',
			FINLINKS_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			FINLINKS_WC_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'finlinks-blocks', 'finlinks-stablecoin-payments', FINLINKS_WC_DIR . 'languages' );
		}
		return array( 'finlinks-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway ? $this->gateway->get_title() : 'Pay with USDC',
			'description' => $this->gateway ? $this->gateway->get_description() : '',
			'supports'    => $this->gateway ? array_values( array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) ) : array( 'products' ),
			'currencies'  => Finlinks_Amount::SUPPORTED_CURRENCIES,
			'minMinor'    => Finlinks_Amount::MIN_MINOR,
			'maxMinor'    => Finlinks_Amount::MAX_MINOR,
			'paused'      => null !== Finlinks_Gateway::pause_state(),
		);
	}
}
