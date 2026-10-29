# FinLinks Stablecoin Payments for WooCommerce

Public source for the FinLinks WooCommerce plugin operated by Finlinks Global Solutions LLC. Product website: https://www.finlinksglobal.com/integrations/woocommerce

## Release status

Version 1.1.2 has been submitted for WordPress.org review. The GitHub release is a prerelease archive, not a claim of WordPress.org approval. The website's production download currently remains at 1.1.0.

Use the attached `finlinks-stablecoin-payments-1.1.2.zip` release asset for installation, not GitHub's automatic Source code archives. That install ZIP is identical to the submitted package: 74,417 bytes; SHA-256 `42bfd957dba2f87933c0a5f3c6f803c20ebf582f6ab2a793067062900543e67d`.

## Install and service requirements

See [the installation guide](docs/INSTALL-PROD.md) and [plugin readme](readme.txt). Requires WordPress 6.4+, WooCommerce 8.2+ and PHP 7.4+. Production payments require an approved FinLinks merchant account. Portal access is invitation-only.

The plugin is a client integration with the external FinLinks service. This repository contains the distributed plugin source, not the service backend, private credentials or customer data. See the readme for data transfers, terms and privacy links.

## Build

With Python 3, run `python scripts/build-release-zip.py`. The portable builder generates the install ZIP, SHA-256 sidecar and file-hash manifest under `dist/`. It canonicalizes text line endings, entry order and timestamps, and excludes development files. Its default `SOURCE_DATE_EPOCH` is 1704067200. Reproducing the exact archive requires the same source and compression toolchain.

## Regression checks

Run `php tests/integration/assets.php` and `node tests/integration/assets.cjs` for focused asset-scope and JavaScript interaction checks. The PHP check uses WordPress stubs. These checks do not substitute for full WordPress/WooCommerce testing or the official Plugin Check, which were not performed in this session.

## License

GPL-2.0-or-later; see [LICENSE](LICENSE). FinLinks trademarks and service access are separate from the software license. Do not commit credentials or customer data.
