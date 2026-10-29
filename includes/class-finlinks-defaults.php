<?php
/**
 * Defaults for settings that have never been saved.
 *
 * WooCommerce returns a stored value when the merchant has saved the gateway
 * settings. These constants apply only when that key is absent or empty.
 *
 * @package FinLinks\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * New-install defaults.
 */
final class Finlinks_Defaults {

	/** Test mode checkbox. "no" means live mode. */
	const TEST_MODE = 'no';

	/** Unpaid sessions select. Matches Finlinks_Session_Decider::UNPAID_FAIL. */
	const UNPAID_ACTION = 'fail';
}
