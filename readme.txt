=== Order Splitter for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, split order, order management, duplicate order, merge orders
Requires at least: 6.5
Tested up to: 7.0
WC tested up to: 11.0
Requires PHP: 7.4
Stable tag: 1.4.13
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Safely split WooCommerce orders by quantity with server-side review, HPOS support, idempotent retries, and preserved historical order values.

== Description ==

Order Splitter for WooCommerce provides administrative tooling for WooCommerce order operations and split-order relationships.

= Manual quantity Split in 1.4.13 =

Version 1.4.13 re-enables the first hardened mutation workflow: manual quantity Split from the WooCommerce order edit screen.

The workflow uses a server-reviewed confirmation flow and preserves historical order amounts and tax context instead of recalculating against current catalog values. Split operations are journaled and idempotent so an interrupted or retried request reuses the same child orders rather than creating duplicates.

The Split request is designed as an order-only mutation and must not change physical product stock. Stock-reduction markers are redistributed with the source and child orders so later WooCommerce cancellation/restock lifecycle behavior remains consistent.

Other mutation workflows — Duplicate, Merge, Return, and Bulk Return — remain unavailable while they complete the same production-readiness process.

[Premium version](https://yoohw.com/product/woocommerce-advanced-order-actions/) | [Documentation](https://docs.yoohw.com/category/woocommerce-advanced-order-actions/) | [Support](https://yoohw.com/support/) | [Demo](https://sandbox.yoohw.com/demo/wcaoa_demo.html)

== Current functionality ==

In 1.4.13:

* Manual quantity Split is available on supported WooCommerce orders.
* A server-side review step validates the source order and requested quantity allocation before execution.
* Child orders are created as Pending payment and do not inherit the source payment transaction ID.
* Historical line totals and taxes are preserved at the order's captured price precision.
* The Split operation does not intentionally write physical product stock.
* Operation IDs, durable journals, leases, confirmation tokens, and idempotent retry handling protect against duplicate execution.
* Legacy split-order relationship labels remain available.
* WooCommerce legacy order storage, HPOS-only storage, and HPOS compatibility/synchronization mode are supported by the production acceptance matrix.
* Duplicate, Merge, Return, and Bulk Return remain disabled in the free plugin.

== Split safety policy ==

The first production-enabled manual quantity Split intentionally fails closed for unsupported cases instead of guessing how third-party business rules should be redistributed.

The workflow currently rejects before mutation when an order contains unsupported conditions such as:

* coupons;
* refunds or partial refunds;
* negative fees;
* nested Split relationships;
* unclassified or inconsistently classified private line-item metadata;
* stock integrations that bypass WooCommerce stock APIs/hooks without an explicit compatibility adapter.

Shipping and positive fees remain on the source order. Historical line taxes are allocated without recalculating current tax rates. Fractional quantities are accepted only when the active WooCommerce quantity integration actually preserves fractional stock amounts.

== High-Performance Order Storage (HPOS) ==

The plugin uses WooCommerce CRUD APIs and declares HPOS compatibility. Manual quantity Split is validated in CI against legacy order storage, HPOS-only storage, and HPOS compatibility/synchronization mode.

== Installation ==

1. Upload the `wc-order-splitter` folder to `/wp-content/plugins/`, or install the plugin from the WordPress plugin directory.
2. Activate the plugin from Plugins in the WordPress admin.
3. Make sure WooCommerce is installed and active.
4. Go to WooCommerce > Settings > Orders to review Order Splitter settings.
5. Open a supported WooCommerce order and use the Split order action to review and confirm a quantity allocation.

== Frequently Asked Questions ==

= Which mutation workflow is enabled in 1.4.13? =

Manual quantity Split is enabled. Duplicate, Merge, Return, and Bulk Return remain disabled until their hardened replacements complete production acceptance.

= Does Split change physical product stock? =

The Split request is an order-only mutation and is designed not to write physical product stock. It redistributes WooCommerce stock-reduction markers between the source and child orders so later cancellation/restock lifecycle behavior remains consistent.

= Why can a Split request be rejected before execution? =

The first hardened workflow intentionally fails closed when it cannot prove conservation and compatibility, including orders with coupons, refunds, negative fees, nested Split state, or unclassified private line-item metadata.

= What happens if I retry an interrupted Split? =

The operation uses a server-generated operation ID, durable journal, source-order lease, and idempotent child discovery. A valid retry resumes or returns the original child set instead of creating duplicate child orders.

= Are existing split-order relationships preserved? =

Yes. Existing relationship metadata and admin labels remain available.

= Does this release send site or administrator details to the removed external subscription endpoint? =

No. The automatic external subscription request was removed in 1.4.12 and remains absent.

= Where are older changelog entries? =

Older release notes are available in `changelog.txt`.

== Changelog ==

= 1.4.13 (Aug 19, 2026) =
* New: Re-enabled hardened manual quantity Split for supported WooCommerce orders.
* Safety: Added server-side review/confirmation, strict plan parsing, nonce/capability checks, operation leases, durable journals, idempotent retry, and fail-closed recovery contracts.
* Stock: Split remains an order-only mutation and preserves physical stock while redistributing WooCommerce stock-reduction markers for later cancellation/restock lifecycle handling.
* Integrity: Preserved historical monetary values, per-rate tax state, exact line identity, configured line metadata policy, price precision, and source/child relationships.
* Compatibility: Validated manual quantity Split across legacy order storage, HPOS-only, and HPOS compatibility/synchronization modes.
* Safety: Duplicate, Merge, Return, and Bulk Return remain disabled until their hardened production workflows are completed.

= 1.4.12 (Aug 18, 2026) =
* Security/Privacy: Removed the automatic external subscription request and its bundled endpoint integration.
* Safety: Temporarily disabled split, duplicate, merge, return, and bulk-return mutations while the order mutation engine was hardened.
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

= 1.4.13 =
Manual quantity Split is re-enabled through the new hardened production path. Other mutation workflows remain fail-closed.
