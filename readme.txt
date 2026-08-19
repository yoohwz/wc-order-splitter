=== Order Splitter for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, split order, order management, duplicate order, merge orders
Requires at least: 6.5
Tested up to: 7.0
WC tested up to: 11.0
Requires PHP: 7.4
Stable tag: 1.4.14
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Safely split and duplicate WooCommerce orders with server-side review, HPOS support, idempotent retries, and preserved historical order values.

== Description ==

Order Splitter for WooCommerce provides administrative tooling for WooCommerce order operations and split-order relationships.

= Hardened Duplicate in 1.4.14 =

Version 1.4.14 enables the hardened single-order Duplicate workflow from the WooCommerce order edit screen.

Duplicate uses the same fail-closed production architecture as the hardened Split workflow: server-side review, order-scoped authorization, confirmation tokens, durable operation journals, operation leases, source-state verification, idempotent retries, and HPOS-safe WooCommerce CRUD persistence.

The duplicated order is always created as Pending payment. Historical line, shipping, fee, tax, and coupon rows are copied through fresh order-item objects, while the source transaction ID, paid state, order-level stock-reduction state, and line `_reduced_stock` markers are deliberately not copied. The Duplicate request is designed not to write physical product stock.

Merge, Return, and Bulk Return remain unavailable while their hardened replacements complete the same production-readiness process.

= Manual quantity Split in 1.4.13 =

Version 1.4.13 re-enabled hardened manual quantity Split from the WooCommerce order edit screen.

The workflow uses a server-reviewed confirmation flow and preserves historical order amounts and tax context instead of recalculating against current catalog values. Split operations are journaled and idempotent so an interrupted or retried request reuses the same child orders rather than creating duplicates.

The Split request is designed as an order-only mutation and must not change physical product stock. Stock-reduction markers are redistributed with the source and child orders so later WooCommerce cancellation/restock lifecycle behavior remains consistent.

[Premium version](https://yoohw.com/product/woocommerce-advanced-order-actions/) | [Documentation](https://docs.yoohw.com/category/woocommerce-advanced-order-actions/) | [Support](https://yoohw.com/support/) | [Demo](https://sandbox.yoohw.com/demo/wcaoa_demo.html)

== Current functionality ==

In 1.4.14:

* Manual quantity Split is available on supported WooCommerce orders.
* Hardened single-order Duplicate is available on supported WooCommerce orders.
* Server-side review validates the source order before either production mutation executes.
* Split child orders and Duplicate targets are created as Pending payment and do not inherit the source payment transaction ID.
* Duplicate preserves supported historical line, shipping, fee, tax, coupon, and business-metadata state through fresh order-item objects.
* Historical line totals and taxes are preserved at the operation's captured price precision.
* Split and Duplicate do not intentionally write physical product stock.
* Operation IDs, durable journals, leases, confirmation tokens, source-state verification, and idempotent retry handling protect against duplicate execution.
* Legacy split-order relationship labels remain available.
* WooCommerce legacy order storage, HPOS-only storage, and HPOS compatibility/synchronization mode are supported by the production acceptance matrix.
* Merge, Return, and Bulk Return remain disabled in the free plugin.

== Split safety policy ==

The hardened manual quantity Split intentionally fails closed for unsupported cases instead of guessing how third-party business rules should be redistributed.

The workflow currently rejects before mutation when an order contains unsupported conditions such as:

* coupons;
* refunds or partial refunds;
* negative fees;
* nested Split relationships;
* unclassified or inconsistently classified private line-item metadata;
* stock integrations that bypass WooCommerce stock APIs/hooks without an explicit compatibility adapter.

Shipping and positive fees remain on the source order. Historical line taxes are allocated without recalculating current tax rates. Fractional quantities are accepted only when the active WooCommerce quantity integration actually preserves fractional stock amounts.

== Duplicate safety policy ==

The hardened Duplicate workflow creates one fresh Pending payment order from the reviewed historical source state.

Duplicate fails closed for unsupported conditions such as refunds, unresolved manual-reconciliation state, unclassified or inconsistently classified private line-item metadata, unsupported fractional state, or internally inconsistent historical totals/taxes.

The source transaction ID, paid state, order-level stock-reduced state, and line `_reduced_stock` markers are not copied. Arbitrary custom order-level metadata is not copied by the first hardened Duplicate workflow. Deleted catalog products remain supported when their persisted historical order-line state can be proven.

== High-Performance Order Storage (HPOS) ==

The plugin uses WooCommerce CRUD APIs and declares HPOS compatibility. Manual quantity Split and hardened Duplicate are validated in CI against legacy order storage, HPOS-only storage, and HPOS compatibility/synchronization mode.

== Installation ==

1. Upload the `wc-order-splitter` folder to `/wp-content/plugins/`, or install the plugin from the WordPress plugin directory.
2. Activate the plugin from Plugins in the WordPress admin.
3. Make sure WooCommerce is installed and active.
4. Go to WooCommerce > Settings > Orders to review Order Splitter settings.
5. Open a supported WooCommerce order and use Split or Duplicate to review and confirm the requested operation.

== Frequently Asked Questions ==

= Which mutation workflows are enabled in 1.4.14? =

Manual quantity Split and hardened single-order Duplicate are enabled. Merge, Return, and Bulk Return remain disabled until their hardened replacements complete production acceptance.

= Does Split change physical product stock? =

The Split request is an order-only mutation and is designed not to write physical product stock. It redistributes WooCommerce stock-reduction markers between the source and child orders so later cancellation/restock lifecycle behavior remains consistent.

= What does Duplicate copy? =

Duplicate copies supported historical line, shipping, fee, tax, coupon, payment-method context, addresses, and business item metadata into fresh WooCommerce order-item objects. It does not copy the source transaction ID, paid state, order-level stock-reduction state, line `_reduced_stock`, or arbitrary custom order-level metadata.

= Why can a Split or Duplicate request be rejected before execution? =

The hardened workflows intentionally fail closed when they cannot prove conservation, source-state integrity, or compatibility. Split and Duplicate have different operation policies, but both reject ambiguous or unsupported state before mutation.

= What happens if I retry an interrupted operation? =

The workflows use server-generated operation IDs, durable journals, source-order leases, confirmation authority, and idempotent target discovery. A valid retry resumes or returns the original child/target set instead of creating duplicate orders.

= Are existing split-order relationships preserved? =

Yes. Existing relationship metadata and admin labels remain available.

= Does this release send site or administrator details to the removed external subscription endpoint? =

No. The automatic external subscription request was removed in 1.4.12 and remains absent.

= Where are older changelog entries? =

Older release notes are available in `changelog.txt`.

== Changelog ==

= 1.4.14 (Aug 19, 2026) =
* New: Enabled the hardened single-order Duplicate workflow for supported WooCommerce orders.
* Safety: Added server-reviewed Duplicate confirmation, nonce/capability enforcement, source-state verification, operation leases, durable journals, and idempotent retry under the approved production gate.
* Integrity: Duplicate creates fresh order-item objects and preserves supported historical line, shipping, fee, tax, coupon, and configured business metadata without recalculating current catalog values.
* Payment/Stock: Duplicate targets remain Pending payment and do not inherit the source transaction ID, paid state, order-level stock-reduction state, or line `_reduced_stock`; Duplicate does not intentionally write physical product stock.
* Compatibility: Validated production Duplicate across legacy order storage, HPOS-only, and HPOS compatibility/synchronization modes, including 0/2/3-decimal precision, deleted products, configured lines, retries, source races, and side-effect contracts.
* Safety: Merge, Return, and Bulk Return remain disabled until their hardened production workflows are completed.

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

= 1.4.14 =
Hardened Duplicate is now production-enabled alongside manual quantity Split. Merge, Return, and Bulk Return remain fail-closed.
