=== Order Splitter for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, split order, order management, duplicate order, merge orders
Requires at least: 6.5
Tested up to: 7.0
WC tested up to: 11.0
Requires PHP: 7.4
Stable tag: 1.4.12
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WooCommerce order-management tools from the admin, with a safety-first maintenance release while mutation workflows are being hardened.

== Description ==

Order Splitter for WooCommerce provides administrative tooling for WooCommerce order operations and split-order relationships.

= Important safety notice for 1.4.12 =

Version 1.4.12 is an emergency data-integrity hardening release. Order mutation actions (split, duplicate, merge, return, and bulk return) are temporarily disabled while the mutation engine is rebuilt around explicit stock, monetary, tax, line-identity, idempotency, and rollback guarantees.

This safety mode does not modify existing orders or remove existing split-order relationship metadata. Existing relationship labels remain available in the WooCommerce admin.

The release also removes an automatic external subscription request that was not required for the plugin's order-management functionality.

[Premium version](https://yoohw.com/product/woocommerce-advanced-order-actions/) | [Documentation](https://docs.yoohw.com/category/woocommerce-advanced-order-actions/) | [Support](https://yoohw.com/support/) | [Demo](https://sandbox.yoohw.com/demo/wcaoa_demo.html)

== Current functionality ==

In 1.4.12:

* Existing split-order relationship metadata remains intact.
* Original/split order labels remain available in the admin when enabled.
* Existing settings remain available so configuration is preserved across the safety release.
* Order mutation actions are deliberately unavailable until their integrity guarantees have been completed and validated.

== Why are mutation actions temporarily disabled? ==

Order mutation changes financial and fulfillment records. A correct implementation must preserve quantities, stock-reduction state, line totals, discounts, fees, shipping, taxes, currency, variation identity, and relationships even when an operation is retried or interrupted.

Version 1.4.12 fails closed rather than allowing operations whose complete invariants have not yet been validated.

== High-Performance Order Storage (HPOS) ==

The plugin uses WooCommerce order CRUD APIs and declares HPOS compatibility. The mutation engine is being validated separately across HPOS, legacy storage, and compatibility/synchronization configurations before mutation actions are re-enabled.

== Installation ==

1. Upload the `wc-order-splitter` folder to `/wp-content/plugins/`, or install the plugin from the WordPress plugin directory.
2. Activate the plugin from Plugins in the WordPress admin.
3. Make sure WooCommerce is installed and active.
4. Go to WooCommerce > Settings > Orders to review preserved Order Splitter settings.

== Frequently Asked Questions ==

= Are my existing split orders changed by 1.4.12? =

No. Safety mode does not rewrite existing orders or relationship metadata.

= Why can I no longer see the Split, Duplicate, Merge, or Return actions? =

They are intentionally disabled in 1.4.12 while the mutation engine is being hardened and validated against stock and financial invariants.

= Does this release send site or administrator details to an external subscription endpoint? =

No. The automatic external subscription request has been removed.

= Where are older changelog entries? =

Older release notes are available in `changelog.txt`.

== Changelog ==

= 1.4.12 (Aug 18, 2026) =
* Security/Privacy: Removed the automatic external subscription request and its bundled endpoint integration.
* Safety: Temporarily disabled split, duplicate, merge, return, and bulk-return mutations while the order mutation engine is hardened.
* Safety: Added an admin notice explaining the temporary fail-closed mode without modifying existing order data.
* Compliance: Replaced the WordPress.org URL in `Plugin URI` with the plugin's source repository URL.
* Compatibility: Raised the minimum WordPress version to 6.5 so Core can enforce the WooCommerce plugin dependency.
* Compatibility: Updated the declared WooCommerce tested version to 11.0.

= 1.4.11 (Jun 13, 2026) =
* Fix: Hardened AJAX capability checks for split and bulk return actions.
* Fix: Validated split quantities against each original line item.
* Improve: Declared WooCommerce HPOS compatibility and updated order edit redirects.
* Improve: Scoped admin assets to WooCommerce order and settings screens.
* Improve: Added throttled inline post-action tips after split, duplicate, merge, and return workflows.

== Upgrade Notice ==

= 1.4.12 =
Emergency safety release: removes the automatic external subscription request and temporarily disables order mutations while data-integrity guarantees are rebuilt and validated.
