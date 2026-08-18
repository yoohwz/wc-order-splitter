=== Order Splitter for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, split order, order management
Requires at least: 6.5
Tested up to: 7.0
WC tested up to: 11.0
Requires PHP: 7.4
Stable tag: 1.4.12
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Emergency safety release for Order Splitter for WooCommerce. Order mutation actions are temporarily disabled while integrity safeguards are applied.

== Description ==

Order Splitter for WooCommerce provides order-management tools for WooCommerce stores.

Version 1.4.12 is an emergency safety release. Manual split, duplicate, merge, return, and bulk-return mutations are temporarily disabled in this version while stock, tax, shipping, and order-data integrity safeguards are being applied. Existing order relationship labels and plugin settings remain available.

This temporary restriction prevents stores from running mutation paths that could produce inconsistent order totals, shipping amounts, tax data, or stock bookkeeping.

WordPress 6.5 or newer is required so WordPress Core understands the `Requires Plugins` dependency declaration for WooCommerce.

[Premium version](https://yoohw.com/product/woocommerce-advanced-order-actions/) | [Documentation](https://docs.yoohw.com/category/woocommerce-advanced-order-actions/) | [Support](https://yoohw.com/support/) | [Demo](https://sandbox.yoohw.com/demo/wcaoa_demo.html)

== Installation ==

1. Upload the `wc-order-splitter` folder to `/wp-content/plugins/`, or install the plugin from the WordPress plugin directory.
2. Activate the plugin from Plugins in the WordPress admin.
3. Make sure WooCommerce is installed and active.
4. Go to WooCommerce > Settings > Orders to review the plugin settings.

== Frequently Asked Questions ==

= Why are split, duplicate, merge, and return actions unavailable in 1.4.12? =

They are intentionally disabled in this emergency release while integrity protections for order totals, shipping, tax, and stock bookkeeping are being applied.

= Does this release still support HPOS storage? =

The plugin continues to use WooCommerce CRUD APIs and declares High-Performance Order Storage compatibility for the functionality that remains active in this safety release.

= Does this release send site or administrator details to an external subscription endpoint? =

No. The automatic external subscription request and its bundled endpoint integration have been removed.

= Where are older changelog entries? =

Older release notes are available in `changelog.txt`.

== Changelog ==

= 1.4.12 (Aug 18, 2026) =
* Security/Privacy: Removed the automatic external subscription request and its data transmission path.
* Safety: Temporarily disabled split, duplicate, merge, return, and bulk-return mutations while integrity safeguards are applied.
* Safety: Guarded unavailable premium-only settings sections from direct URL access.
* Compliance: Removed the WordPress.org URL from the `Plugin URI` header.
* Compatibility: Raised the minimum WordPress version to 6.5 for Core plugin-dependency support.
* Compatibility: Updated the declared WooCommerce tested version to 11.0.
* Improve: Kept settings and order relationship labels available during the safety release.

= 1.4.11 (Jun 13, 2026) =
* Fix: Hardened AJAX capability checks for split and bulk return actions.
* Fix: Validated split quantities against each original line item.
* Improve: Declared WooCommerce HPOS compatibility and updated order edit redirects.
* Improve: Scoped admin assets to WooCommerce order and settings screens.
* Improve: Added throttled inline post-action tips after split, duplicate, merge, and return workflows.

= 1.4.10 (Apr 24, 2026) =
* Fix: Updated incorrect translation strings.
* Improve: WooCommerce 10.7 compatibility.
* Improve: Cleaned and optimized code.

== Upgrade Notice ==

= 1.4.12 =
Emergency safety release: external subscription telemetry has been removed and order mutation actions are temporarily disabled pending integrity safeguards.
