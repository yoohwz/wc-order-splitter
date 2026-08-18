=== Order Splitter for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, split order, order management
Requires at least: 6.3
Tested up to: 7.0
WC tested up to: 11.0
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Split, duplicate, merge, and return WooCommerce orders with explicit safeguards for stock, tax, shipping, currency, and order totals.

== Description ==

Order Splitter for WooCommerce provides administrative order-mutation tools built on WooCommerce CRUD APIs for both legacy order storage and High-Performance Order Storage (HPOS).

Version 1.5.0 rebuilds the mutation layer around explicit validation and conservation checks instead of blindly copying order data.

= Split orders =

* Split product quantities into one or more child orders.
* Split by product category or current stock status.
* Review a preflight preview before any mutation is applied.
* Allocate product line values, historical tax data, currency, and `_reduced_stock` bookkeeping across related orders.
* Keep existing shipping charges on the original order by default.
* Optionally allocate shipping proportionally or create zero-value shipping references on child orders.
* Protect normal admin retries with an idempotency key and per-order mutation lock.

= Duplicate orders =

* Creates new WooCommerce order-item objects instead of reusing persisted source items.
* Preserves currency, customer context, line values, historical tax data, shipping, fees, and coupons.
* Does not copy the source transaction ID.
* Creates the duplicate as a normal pending order instead of `checkout-draft`.
* Does not mark duplicate stock as already reduced.

= Merge compatible orders =

Merge requires a compatibility preflight and explicit confirmation. By default, source and target must have matching order type, currency, customer, payment method, status, billing/shipping addresses, and stock-reduction state. Refunded or paid orders are rejected by the free safety workflow.

Product lines are matched using product ID, variation ID, tax class, name, and business metadata rather than parent product ID alone.

= Return split orders =

A split child can be returned only through a verified split relationship. The return workflow restores exact product identities and allocated stock markers and records returned state so repeated return requests do not repeat the mutation.

= Order relationships =

Order edit screens and both legacy and HPOS order lists show semantic links between original, split, duplicate, and merged orders.

= Privacy =

Order Splitter for WooCommerce does not automatically transmit site, administrator, customer, or order data to a YoOhw server. Version 1.4.12 removed the previous automatic subscription request path.

[Premium version](https://yoohw.com/product/woocommerce-advanced-order-actions/) | [Documentation](https://docs.yoohw.com/category/woocommerce-advanced-order-actions/) | [Support](https://yoohw.com/support/) | [Demo](https://sandbox.yoohw.com/demo/wcaoa_demo.html)

== Installation ==

1. Upload the `wc-order-splitter` folder to `/wp-content/plugins/`, or install the plugin from the WordPress plugin directory.
2. Activate WooCommerce.
3. Activate Order Splitter for WooCommerce.
4. Go to WooCommerce > Settings > Orders to configure allowed statuses, shipping allocation, labels, and shop-manager access.

== Frequently Asked Questions ==

= Which shipping allocation should I use? =

`Keep charges on original order` is the safest default because it does not create additional shipping revenue. `Allocate proportionally` divides the existing historical shipping amount across the resulting orders. `Zero-value reference copies` leaves all shipping revenue on the original while adding zero-value shipping references to children.

= Does splitting change physical stock? =

The mutation engine checks that normal split and return operations do not alter physical product stock. Existing `_reduced_stock` bookkeeping is allocated between related orders so later stock restoration uses the appropriate quantities.

= Can every pair of orders be merged? =

No. Merge intentionally rejects incompatible or high-risk combinations. Paid or refunded orders are rejected by default, as are orders with different currencies, customers, statuses, payment methods, addresses, order types, or stock-reduction states.

= Does this plugin support HPOS? =

The plugin uses WooCommerce order CRUD/data-store APIs and declares HPOS compatibility. The repository CI runs the same mutation smoke scenario with HPOS disabled and enabled before a release is accepted.

== Changelog ==

= 1.5.0 (Aug 18, 2026) =
* New: Rebuilt Split, Duplicate, Merge, and Return on a shared order-mutation engine.
* New: Added operation snapshots, UUIDs, per-order locks, structured journals, and compensation paths for supported failures.
* New: Added exact product-line identity using variation, tax class, and business metadata.
* New: Added preflight preview and explicit confirmation for Split and Merge.
* New: Added explicit shipping-allocation policies with `keep_on_original` as the safe default.
* New: Added residual-aware monetary, tax, and `_reduced_stock` allocation.
* New: Added semantic relationship links for legacy and HPOS order screens.
* New: Bulk Return now uses Action Scheduler when available.
* New: Added unit tests, WooCommerce HPOS on/off smoke tests, Plugin Check CI, and deterministic Git release archives.
* Fix: Duplicate now creates new item objects, preserves currency, avoids transaction IDs, and no longer uses `checkout-draft`.
* Fix: Merge blocks self-merge and validates compatibility before mutation.
* Fix: Category split preserves uncategorized/deleted-product lines and groups by stable category IDs.
* Fix: Return and Merge use variation-aware line identity and transfer stock bookkeeping instead of matching only parent product IDs.
* Privacy: Removed automatic external subscription/telemetry transmission.
* Compliance: Removed the invalid WordPress.org `Plugin URI` header value and added a WooCommerce runtime dependency guard.

= 1.4.12 (Aug 18, 2026) =
* Security/Privacy: Removed the automatic external subscription request and its data transmission path.
* Safety: Temporarily disabled split, duplicate, merge, and return mutations while integrity safeguards were applied.
* Compliance: Removed the WordPress.org URL from the `Plugin URI` header.

= 1.4.11 (Jun 13, 2026) =
* Fix: Hardened AJAX capability checks for split and bulk return actions.
* Fix: Validated split quantities against each original line item.
* Improve: Declared WooCommerce HPOS compatibility and updated order edit redirects.
* Improve: Scoped admin assets to WooCommerce order and settings screens.

== Upgrade Notice ==

= 1.5.0 =
Major order-integrity release. Split, Duplicate, Merge, and Return now use a shared mutation engine with preflight validation, stock/tax/total checks, locks, journals, and an HPOS integration-test matrix.
