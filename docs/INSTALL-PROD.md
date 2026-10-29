# Install FinLinks Stablecoin Payments for WooCommerce 1.1.2

This guide covers **1.1.2 (WordPress.org distribution)**. Production use requires an approved FinLinks merchant account and an operational production API at `https://api.finlinks.io`. Portal access is currently invitation-only and may require Cloudflare Access verification before the FinLinks login. Contact FinLinks for account approval/access before connecting a store.

The service is operated by Finlinks Global Solutions LLC. Product information, the Merchant Services Agreement and Privacy Policy are available at:

- https://www.finlinksglobal.com/integrations/woocommerce
- https://www.finlinksglobal.com/legal/merchant-services-agreement.html
- https://www.finlinksglobal.com/legal/privacy.html

## Requirements

- WordPress 6.4+, WooCommerce 8.2+ active, and PHP 7.4+. HPOS and classic/Blocks checkout are supported. Consult `readme.txt` and the plugin header for declared compatibility; those declarations do not replace testing on your store.
- A public HTTPS store without an explicit port, including `:443`. Return/cancel URLs containing a backslash or control character are rejected.
- Pretty permalinks (for example, Post name). The webhook URL must be `https://your-store.example/wp-json/finlinks/v1/webhook`, without a query string. Plain permalinks using `?rest_route=` cannot be registered.
- Store currency USD and order totals from 1.00 to 10,000.00 USD.
- Outbound HTTPS to the FinLinks API and inbound HTTPS to the store webhook. The webhook authenticates signed requests itself; do not place a WordPress login or interactive access challenge in front of it.

Connect requires WordPress Address and Site Address to match in host and path, with the expected `/wp-admin/admin.php` address. If WordPress is installed in a different directory or Connect is unavailable, use the manual configuration path below. Checkout URL and webhook requirements still apply.

## Install or upgrade

Back up the database and plugin files before upgrading. Preserve existing credentials securely, and test on a staging copy before updating a production store.

### New installation

1. Open **Plugins > Add New Plugin > Upload Plugin** and choose `finlinks-stablecoin-payments-1.1.2.zip`.
2. Install and activate **FinLinks Stablecoin Payments for WooCommerce**. WooCommerce must already be active.
3. Open **WooCommerce > Settings > Payments > Pay with USDC**, then follow one configuration path below.

The installed main file must be `wp-content/plugins/finlinks-stablecoin-payments/finlinks-stablecoin-payments.php`. Avoid nesting another copy of the plugin directory inside it.

### Upgrade from 1.1.0 or 1.1.1 in the same directory

1. Upload `finlinks-stablecoin-payments-1.1.2.zip` through **Plugins > Add New Plugin > Upload Plugin**.
2. When WordPress detects the existing plugin, confirm **Replace current with uploaded**. Do not delete/uninstall the existing plugin first.
3. Confirm the installed version is 1.1.2 and activate it if necessary. Replacement preserves saved settings; check the connection, mode and payment method before accepting orders.

For a manual file deployment, replace the contents of `wp-content/plugins/finlinks-stablecoin-payments/` with this release using your normal controlled deployment process, without running the uninstaller.

### Migrate from the older FinLinks Pay for WooCommerce directory

The legacy directory is `wp-content/plugins/finlinks-woocommerce/`.

1. Deactivate **FinLinks Pay for WooCommerce**. Do not delete it: its uninstaller can remove shared credentials and webhook records.
2. Upload and activate `finlinks-stablecoin-payments-1.1.2.zip`. The new copy refuses to run alongside an active legacy copy; any conflict notice appears on the Plugins screen.
3. Check the existing settings under **Pay with USDC**. Leave saved secret fields blank to retain their values; do not select **Remove the saved value**. The payment method ID remains `finlinks`, and existing orders are retained.
4. Keep the old copy inactive until migration is verified. If later removing it through WordPress, first remove its `uninstall.php` through your file-management/deployment tool so deletion cannot erase the shared settings. Keep a backup before doing so.

The new plugin's uninstaller skips cleanup while the legacy main file remains on disk. Once it is gone, uninstalling the new plugin removes its settings, event table, transients and schedules; orders and their notes/meta are retained.

## Recommended: Connect automatic authorization

1. Open **WooCommerce > Settings > Payments > Pay with USDC**. For production, leave **Test mode** unchecked and **API base URL (advanced)** empty.
2. Select **Connect** and sign in at `portal.finlinks.io`. Complete any access verification and approve the correct production store.
3. Return to WooCommerce. Credentials are exchanged server-to-server; you do not need to copy a Key or Secret. The connection flow registers and verifies the webhook. Confirm an **ACTIVE** status; if verification fails, fix the reported issue and use **Register webhook and verify store** to retry.
4. Enable **Enable USDC payments**, review **Unpaid sessions**, and save. New installs default to **Mark the order as failed**; existing settings are preserved.
5. Complete the verification steps below before accepting payments.

Do not also perform manual credential replacement after a successful Connect. Use **Reconnect** only when intentionally reauthorizing; saved credentials are replaced only after the new credentials have been verified successfully.

## Alternative: Advanced manual Key and Secret

Use this path when Connect is unavailable or FinLinks provides credentials for manual setup.

1. Obtain the production store's API key and webhook signing secret through the approved FinLinks onboarding/portal process. The live key must start with `flk_live_`.
2. Under **Advanced**, paste the key and secret into their corresponding fields. Leave **Test mode** unchecked and **API base URL (advanced)** empty. Enable USDC payments, review **Unpaid sessions**, and save.
3. Under **Connection**, select **Register webhook and verify store**. Confirm the connection becomes **ACTIVE**. Complete the checks below.

Saved secrets are not redisplayed. Leave a secret field blank to retain its stored value. Invalid credential fields are rejected individually and their previous values retained; other settings may still save. A failed save does not necessarily disable an already valid connection. A new/unconfigured store cannot offer payments without valid credentials. Removing the required live webhook secret is rejected.

## Production and test settings

Production requires Test mode off and a valid `flk_live_` key plus webhook secret. Only `https://api.finlinks.io` is used; the advanced API override is ignored and cleared on save.

The **Test mode checkbox remains visible**. Test payments require Test mode on, an `flk_test_` key and an explicit HTTPS non-production API URL. Without a usable test API/credentials, the checkout payment method is unavailable. There is no built-in test API host. Use test mode and trusted test data only in a separate test store.

**Unpaid sessions** applies only to a pending order whose current session expires or is cancelled without payment. The default **Mark the order as failed** records an unsuccessful payment. **Cancel the order** and **Add an order note only** are supported alternatives; choose deliberately for your order/stock workflow. Existing paid orders are not downgraded. Underpaid expired sessions are placed on hold for review.

## Verify the connection and checkout

1. Confirm the displayed webhook URL meets the HTTPS/permalink requirements.
2. Select **Test connection**, then **Register webhook and verify store** if needed. Confirm **ACTIVE**.
3. Select **Send test webhook** and confirm delivery with HTTP 200.
4. Test classic and Blocks checkout as used by your store. For an authorized live smoke test, use a controlled 1.00 USD order and understand that it sends a real payment; use a separate test store for test-network payments.
5. Confirm that the buyer opens the hosted payment page and the order becomes paid only after API-confirmed payment. Returning to the store alone is not payment proof.

A pending confirmation page refreshes every 10 seconds, up to 12 refreshes. Stopping the refresh does not indicate payment failure. Signed webhooks and the scheduled 10-minute backup check can still synchronize the order, subject to scheduler execution and the reconciliation window (recent pending orders, up to 48 hours old, 50 per batch).

Webhook signatures use HMAC-SHA256 with a five-minute timestamp window. Invalid signatures return HTTP 401; stale signed requests or retryable confirmation failures return HTTP 503. Keep the server clock synchronized.

WooCommerce refunds are not implemented by this plugin. Arrange any refund with the merchant and the applicable FinLinks/support or payment-provider workflow; do not assume a Portal refund button is available.

## Troubleshooting and logs

- **Cannot open Portal:** obtain invitation/approval and satisfy its access policy. This is separate from installing the plugin.
- **Pay with USDC missing:** check enablement, account/credentials, mode, API URL, USD currency, order amount and any connection pause. Operational notices are shown on the FinLinks settings page.
- **Connection pause:** certain API configuration/authentication errors pause the method for up to five minutes. Correct the issue and test the connection. A transient network error alone does not trigger that pause.
- **Order still pending:** check authoritative payment status, webhook delivery, firewall rules, server time and the WordPress/Action Scheduler jobs. Do not treat a timeout as proof that no payment exists or pay again without checking.
- **Logs:** open **WooCommerce > Status > Logs**, source `finlinks`. Debug defaults off; `error` messages are still logged, while routine info/warning messages require Debug. Enable Debug temporarily for diagnosis. Logs redact supported credential, signature and order-key patterns.
- **Log write errors:** ask your host to verify the active WooCommerce log handler and PHP user's storage permissions. Do not assume `FS_METHOD=direct` resolves filesystem ownership or permission problems.

Ordinary API calls and Connect requests use a 15-second per-request timeout. Connection verification/test-webhook calls use 30 seconds; webhook-triggered payment confirmation uses 7 seconds. An operation may contain more than one request.

## Build and distribute (maintainers)

From the release source checkout, with Python 3 available:

```text
python scripts/build-release-zip.py
```

The builder validates the plugin header, version constant, Stable tag and contributor, and writes:

- `dist/finlinks-stablecoin-payments-1.1.2.zip`
- `dist/finlinks-stablecoin-payments-1.1.2.zip.sha256`
- `dist/release-1.1.2.json` (size, archive hash and per-file hashes)

The ZIP has one `finlinks-stablecoin-payments/` directory. It includes runtime files, translations, license, readme and this guide. Development files and the legacy private updater are excluded. File ordering, text line endings and timestamps are normalized; `SOURCE_DATE_EPOCH` defaults to 2024-01-01 UTC. Identical inputs and toolchain produce an identical ZIP.

Use the generated SHA-256 for this exact archive. Earlier 1.1.2 candidate checksums no longer apply after documentation changes. Installing the ZIP does not imply WordPress.org approval. Once approved and published, this distribution uses WordPress.org updates and includes no private update checker. Keep the same release artifact for the review upload and any corresponding GitHub/Cloudflare downloads.
