=== FinLinks Stablecoin Payments for WooCommerce ===
Contributors: pandafish620
Tags: usdc, payments, woocommerce, checkout, stablecoin
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDC payments in WooCommerce through a secure hosted payment page.

== Description ==

Adds a "Pay with USDC" payment method. At checkout the buyer is redirected to a hosted payment page. Funds go straight to the merchant's own wallet. The order is marked paid only after the payment is confirmed on chain and the plugin has checked the result with the API.

* Works with the classic checkout and the Cart/Checkout blocks.
* Compatible with High-Performance Order Storage (HPOS).
* Offered for stores in USD, for order totals from 1.00 to 10,000.00 USD.
* Signed webhooks (HMAC-SHA256, 5-minute replay window, duplicate protection) plus a 10-minute backup status check, and a warning when the server clock is off by more than 2 minutes.
* With Test mode off, "API base URL (advanced)" is ignored and cleared. Live requests go only to the production API. Test mode does nothing until that field is an https URL for a non-production API.

The domains this plugin contacts are listed under External services.

== External services ==

FinLinks is an external service operated by Finlinks Global Solutions LLC (Wyoming, United States). An approved FinLinks merchant account is required. Merchant portal access is currently invitation-only; downloading or activating this plugin does not activate payment services.

Product website: https://www.finlinksglobal.com/integrations/woocommerce
Merchant Services Agreement: https://www.finlinksglobal.com/legal/merchant-services-agreement.html
Website Terms: https://www.finlinksglobal.com/legal/terms.html
Privacy Policy: https://www.finlinksglobal.com/legal/privacy.html
Source code: https://github.com/pandafish620/finlinks-woocommerce

= api.finlinks.io =

The store server contacts the production API when an administrator connects or reconnects the store, tests its connection, registers or verifies its webhook, or sends a test webhook. It also contacts the API when a buyer submits a FinLinks payment, when payment status is checked (including the 10-minute backup check and pending order return checks), and when an open payment session is cancelled.

Authenticated payment and connection-management calls send the API key as a bearer token and the plugin version in the user-agent. Webhook registration also sends the webhook URL, WooCommerce version and plugin version. Connect start and exchange requests instead send the store and return URLs, live/test mode and key-rotation selection where applicable, and temporary connection authorization data (state, authorization code and PKCE challenge/verifier). The API returns credentials to the store server; API keys and webhook signing secrets are not placed in browser URLs.

Creating a payment session sends the store order ID, a short order label, order amount and currency, return URL, cancel URL and webhook callback URL, and a session expiration interval where configured. Return and cancel URLs may contain order access tokens. Status queries and cancellation requests identify the session or order. These data support payment creation, store authorization, reconciliation and order status updates. The API sends signed payment notifications to the store at /wp-json/finlinks/v1/webhook.

The plugin does not send separate buyer name, email, phone or postal address fields. Order labels and URLs should not contain unnecessary personal information. Servers also receive technical request information such as the requesting IP address and user-agent.

= portal.finlinks.io and pay.finlinks.io =

When an administrator clicks Connect, their browser opens the FinLinks portal to sign in and approve the store, carrying a temporary connection state rather than an API key. After a payment session is created, the buyer's browser opens the hosted payment page with the session ID in its /inv/ URL. Live hosted payment URLs are restricted to these two hosts. The plugin does not POST the order directly to these browser destinations. Browser navigation shares ordinary request information, including IP address and browser user-agent; subsequent information entered on those services is governed by the FinLinks Privacy Policy.

These technical hosts and finlinks.io business email are used for the service operated by Finlinks Global Solutions LLC. Their domain differs from the product website at www.finlinksglobal.com.

= Administrator-configured test API =

Test mode requires an explicit HTTPS non-production API URL configured by the administrator. The corresponding connection and payment requests described above go to that configured service instead. Use only a trusted test service and test credentials/data; review that provider's terms and privacy policy. With Test mode off, the override is ignored and cleared, and API requests go only to api.finlinks.io.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. In WooCommerce > Settings > Payments > Pay with USDC, click Connect and approve the store in the FinLinks portal. WooCommerce registers the webhook and verifies the store. Turn Test mode off for live payments.
3. Or, under Advanced, paste an API key and webhook signing secret from the FinLinks portal, save, and click "Register webhook and verify store". With Test mode off the plugin always uses the production API. Leave "API base URL (advanced)" empty; it is ignored and cleared. Test mode stays hidden until that field is an https URL for a different API.

Requirements: the store must be served over https and use pretty permalinks, so the webhook URL looks like https://your-store.example/wp-json/finlinks/v1/webhook. Plain permalinks (?rest_route=) cannot be registered. Production install steps: docs/INSTALL-PROD.md.

== Frequently Asked Questions ==

= When is an order marked paid? =

Only after a signed notification (or the backup status check) reports the payment as paid and the plugin has confirmed it with the API. A buyer returning to the store is never treated as payment.

= What happens with an underpayment or overpayment? =

An underpaid session that expires puts the order on hold with a note. Settle it from the FinLinks portal. An overpaid session marks the order paid and adds a note with the excess amount.

= What happens if a session receives only part of the amount? =

The order stays pending and gets a note with the amount received; the buyer can still pay the rest on the hosted page until the session expires. If the session ends underpaid, the order is put on hold.

= What happens when an order is cancelled in WooCommerce? =

The plugin cancels the open payment session, so the hosted page can no longer be paid. If the session already received money it cannot be cancelled; the order gets a note asking you to check the FinLinks portal.

= Why did "Pay with USDC" disappear from checkout? =

If the payment API rejects the store's connection (for example the connection was disabled or the API key was revoked), the method is hidden for up to 5 minutes and an admin notice shows the error code. Fix the connection, then click "Test connection".

= Are refunds supported? =

Not from WooCommerce in this version. Refunds are handled in the FinLinks portal.

== Changelog ==

= 1.1.2 =
* Load connection controls and payment confirmation scripts through WordPress script APIs.
* Limit payment warnings to FinLinks settings and legacy-plugin warnings to plugin management.
* Clarify the service operator, account requirements, external data transfers and legal links.
* Add the submitting contributor and product/source links.

= 1.1.1 =
* WordPress.org distribution: updates are managed by WordPress; the private updater is removed.
* Use WordPress URL parsing, escape credential errors and settings descriptions, sanitize callback input, and explicitly verify settings permissions and nonces.
* Prepare database table identifiers and values while preserving atomic duplicate-webhook protection.
* Use automatic WordPress.org translation loading and the supported Tested up to series format.

= 1.1.0 =
* Connect a store from WooCommerce. The API key and webhook signing secret stay under Advanced, and settings saved by 1.0.3 are kept.
* Reconnect replaces the saved key only after FinLinks verifies the new key. Until then the previous key stays in place.

= 1.0.3 =
* Maintenance release for the former private distribution.

= 1.0.2 =
* The plugin directory and text domain are finlinks-stablecoin-payments. The display name is FinLinks Stablecoin Payments for WooCommerce. Saved settings, the payment method id, the webhook route and order meta are unchanged.
* Live mode calls only https://api.finlinks.io. Test mode requires an explicit https API base URL and will not call that host.
* Live hosted payment URLs must use an /inv/{uuid} path.
* Tested with WordPress 7.1.3 and WooCommerce 11.2.1.

= 1.0.1 =
* New installs leave Test mode off and set Unpaid sessions to "Mark the order as failed". Settings that were already saved are not changed.
* If the payment service will not start a session for this order, the buyer is asked to choose another payment method.
* Connection errors in admin are plain FinLinks messages. Details stay in the WooCommerce log.
* The hosted payment page must be the FinLinks page for the active mode (live or test).
* Cart and Checkout blocks follow the same 5-minute pause as the classic checkout.
* The thank-you page says the USDC payment is being confirmed until the order is paid.
* Production install notes say to leave API base URL empty.

= 1.0.0 =
* First production release.
* Return and cancel URLs are rejected when they contain an explicit port (including :443), a backslash, or a control character.
* Buyers are asked to try again when the payment service reports that the commerce session is unavailable.
* Connection-panel errors are passed through the same redaction as the log, so a key or secret in an API message is not shown.
* Live mode always uses the production API. The advanced API base URL is ignored and cleared while Test mode is off, and a non-production or localhost host cannot be used for live payments.
* Live mode will not save an API key that does not start with flk_live_, or an empty webhook signing secret. USDC payments stay hidden until both are saved.

= 0.2.0 =
* Cancelling a WooCommerce order cancels its open payment session.
* Partial payments are noted on the order; a paid session for an order in an unexpected status (e.g. refunded) is recorded without changing the order.
* The method is paused for 5 minutes after a connection or key error, with an admin notice.
* The thank-you page refreshes itself while the payment is being confirmed.
* Translation-ready: translatable order notes, blocks script translations, languages/finlinks-stablecoin-payments.pot.
* Uninstall also removes locks, transients and pause state (multisite aware).
* Stricter settings validation; logs also redact order keys and signatures.

= 0.1.0 =
* First version, built against payment-session contract v1.
