=== Order Splitter for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, split order, order management, duplicate order, merge orders
Requires at least: 6.5
Tested up to: 7.1
WC tested up to: 11.0
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Safely split, duplicate, merge, and return supported WooCommerce orders with server-side review, HPOS support, idempotent retries, and preserved historical values.

== Description ==

Order Splitter for WooCommerce provides hardened administrative tools for splitting, duplicating, merging, and returning supported WooCommerce orders.

= Complete order workflow toolkit in 1.5.0 =

Split supports three methods: manual quantities, current product categories, and current product stock status. Category and Stock-status Split first show server-built buckets. You choose the one bucket that stays on the source order; the other reviewed buckets move as complete product lines to new Pending payment child orders. Confirmed classification remains frozen for execution instead of being silently recalculated from later catalog changes.

Duplicate creates one fresh Pending payment order from supported historical source state. Merge moves a supported source order into a selected target by creating fresh target line items and retiring the source through WooCommerce-supported non-force trash/archive behavior.

Return sends an eligible hardened Split child back to its server-resolved original order. Bulk Return offers the same protected flow for up to twenty selected eligible children from the WooCommerce Orders list, advances at most one child per request, and can resume from durable progress after the modal is closed or a response is lost.

= Safety and historical integrity =

Production mutations use server-side Review -> Confirm -> Execute authority, capability and nonce checks, order-scoped confirmation, source-state verification, operation leases, durable journals, recovery, compensation, and idempotent replay. Unsupported or changed order state fails closed before mutation whenever the plugin cannot prove the operation's invariants.

The plugin preserves accepted historical quantities, monetary values, discounts, and per-rate tax context rather than repricing from the current catalog or recalculating current tax. New orders receive fresh WooCommerce order-item objects; persisted items are never re-parented between orders.

Split and Return are designed to be physically stock-neutral while explicitly conserving WooCommerce `_reduced_stock` and order-level stock-reduction ownership. Duplicate does not inherit stock-reduction ownership. Merge keeps physical inventory neutral while transferring operational stock ownership to the active target exactly once.

[Premium version](https://yoohw.com/product/woocommerce-advanced-order-actions/) | [Documentation](https://docs.yoohw.com/category/woocommerce-advanced-order-actions/) | [Support](https://yoohw.com/support/) | [Demo](https://sandbox.yoohw.com/demo/wcaoa_demo.html)

== Current functionality ==

In 1.5.0:

* Manual Quantity, Category, and Stock-status Split are available on supported WooCommerce orders.
* Hardened single-order Duplicate is available on supported WooCommerce orders.
* Hardened single-source Merge is available for supported source/target pairs.
* Single Return is available for eligible children created by hardened Split.
* Bulk Return is available from the WooCommerce Orders list for up to twenty eligible children per reviewed batch.
* Server-side Review, Confirm, and Execute authority revalidates participating orders before production mutation.
* Split child orders and Duplicate targets are created as Pending payment and do not inherit the source payment transaction ID.
* Historical line amounts, discounts, and per-rate taxes are preserved at the operation's captured price precision.
* Operation IDs, durable journals, leases, confirmation tokens, source-state verification, recovery, and idempotent replay protect against duplicate execution.
* Existing split-order relationship labels remain available.
* WooCommerce legacy order storage, HPOS-only storage, and HPOS compatibility/synchronization mode are supported by the production acceptance matrix.

== Split safety policy ==

All Split methods fail closed instead of guessing how unsupported third-party business rules should be redistributed.

Manual Quantity Split lets an authorized administrator review and allocate supported line quantities to child orders. Shipping and positive fees remain on the source. The workflow rejects ambiguous state such as coupons, refunds or partial refunds, negative fees, nested Split relationships, unclassified private line-item metadata, and unsupported stock integrations.

Category and Stock-status Split move whole product lines according to server-built buckets. One reviewed bucket stays on the source and every other bucket becomes a child order. Category classification rejects catalog state it cannot prove, including deleted products or unrelated leaf-category ambiguity. Stock-status classification uses the reviewed server state. Confirmed plans use frozen server authority for execution.

Historical line taxes are allocated without recalculating current rates. Fractional quantities are accepted only when the active WooCommerce quantity integration actually preserves fractional stock amounts.

== Duplicate safety policy ==

Duplicate creates one fresh Pending payment order from the reviewed historical source state.

Supported historical line, shipping, fee, tax, coupon, payment-method context, addresses, and business item metadata are copied through fresh WooCommerce order-item objects. The source transaction ID, paid state, order-level stock-reduced state, line `_reduced_stock`, and arbitrary custom order-level metadata are not copied.

Duplicate fails closed for refunds, unresolved manual-reconciliation state, unclassified private line-item metadata, unsupported fractional state, or internally inconsistent historical totals and taxes. Deleted catalog products remain supported only when their persisted historical order-line state can be proven.

== Merge safety policy ==

Merge moves one supported source order into a selected target direction. It creates fresh target product-line items, does not re-parent or coalesce source lines, preserves historical money and per-rate tax state, and does not reprice or recalculate tax from the current catalog.

The source is retired with non-force trash/archive semantics while the target remains active. Existing target-owned shipping remains on the target. Physical stock stays neutral and `_reduced_stock` operational ownership transfers exactly once under the durable source-keyed journal and recovery contract.

Unsupported pairs fail closed before mutation. Paid or transaction-bearing orders, refunds, coupons, fees, source-owned shipping, incompatible status, currency or customer context, and sources without product lines are not supported.

== Return safety policy ==

Return is available only for a child whose original can be authenticated from hardened Split lineage. The browser cannot choose or replace the original order. Review shows the server-resolved original and bounded historical summary before confirmation.

Successful Return preserves the child's commercial history while retiring it through non-force trash/archive behavior and restoring the original as the active operational owner. Historical money and per-rate tax authority are preserved, physical stock remains unchanged, and stock-reduction ownership is explicitly transferred. Invalid lineage, participant drift, stale confirmation, conflicting operations, and unresolved manual reconciliation fail closed.

Bulk Return applies the same single-order Return authority to a reviewed selection. Duplicate selections are canonicalized, an ineligible participant blocks confirmation of the batch, each request advances at most one child, and the batch stops after the first non-success instead of silently continuing. Durable progress allows safe resume without reminting child operations or repeating completed commercial writes.

== High-Performance Order Storage (HPOS) ==

The plugin uses WooCommerce CRUD APIs and declares HPOS compatibility. Split, Duplicate, Merge, Return, and Bulk Return are validated in CI against legacy order storage, HPOS-only storage, and HPOS compatibility/synchronization mode.

== Installation ==

1. Upload the `wc-order-splitter` folder to `/wp-content/plugins/`, or install the plugin from the WordPress plugin directory.
2. Activate the plugin from Plugins in the WordPress admin.
3. Make sure WooCommerce is installed and active.
4. Go to WooCommerce > Settings > Orders to review Order Splitter settings.
5. Open a supported WooCommerce order and choose Split, Duplicate, Merge, or Return to review and confirm the operation.
6. To return multiple eligible Split children, select them in the WooCommerce Orders list and choose Return to original order from Bulk actions.

== Frequently Asked Questions ==

= Which mutation workflows are enabled in 1.5.0? =

Manual Quantity, Category, and Stock-status Split; single-order Duplicate; single-source Merge; single Return; and Bulk Return are enabled for supported orders.

= Does Split change physical product stock? =

The Split request is an order-only mutation and is designed not to write physical product stock. It redistributes WooCommerce stock-reduction markers between the source and child orders so later cancellation and restock behavior remains consistent.

= How do Category and Stock-status Split work? =

The server reviews the order and presents deterministic category or stock-status buckets. You choose one bucket to keep on the source; all other buckets move as whole product lines to child orders. Confirmation freezes the server-reviewed classification for execution.

= What does Duplicate copy? =

Duplicate copies supported historical line, shipping, fee, tax, coupon, payment-method context, addresses, and business item metadata into fresh WooCommerce order-item objects. It does not copy the source transaction ID, paid state, order-level stock-reduction state, line `_reduced_stock`, or arbitrary custom order-level metadata.

= How does Merge handle the source and target orders? =

The edited order is the source and the selected order is the target. The target remains active with its existing target-owned shipping preserved. Fresh target product-line items retain supported historical source values, while the source is retired through WooCommerce non-force trash/archive behavior. Unsupported source-owned shipping and participant state are rejected rather than transferred.

= Which orders can be returned? =

Return requires an eligible child created by a hardened Split operation. The plugin resolves the original order from authenticated server-side lineage; administrators cannot select a different destination. Review must succeed before confirmation and execution.

= How does Bulk Return behave if a request is interrupted? =

Bulk Return stores durable batch progress and advances at most one child per request. Reopening or retrying resumes the same confirmed batch. Completed rows are not repeated, and the batch stops after the first non-success so remaining rows are clearly marked as not run.

= Why can a request be rejected before execution? =

The workflows intentionally fail closed when they cannot prove conservation, participant-state integrity, lineage, or compatibility. Each operation has its own supported envelope and rejects ambiguous or changed state before mutation.

= What happens if I retry an interrupted operation? =

The workflows use server-generated operation IDs, durable journals, operation leases, confirmation authority, and idempotent target discovery. A valid retry resumes or returns the original result instead of creating duplicate orders or repeating a completed mutation.

= Are existing split-order relationships preserved? =

Yes. Existing relationship metadata and admin labels remain available.

= Does this release send site or administrator details to the removed external subscription endpoint? =

No. The automatic external subscription request was removed and remains absent.

= Where are older changelog entries? =

Older public release notes are available in `changelog.txt`.

== Changelog ==

= 1.5.0 (Aug 26, 2026) =
* New: Added hardened Manual Quantity, Category, and Stock-status Split; single-order Duplicate; single-source Merge; single Return; and resumable Bulk Return for supported WooCommerce orders.
* Security & Safety: Removed the obsolete automatic external subscription request and introduced code-owned feature gates, server-side Review -> Confirm -> Execute authority, capability and nonce checks, source-state verification, and fail-closed rejection of unsupported or changed state.
* Integrity: Preserved historical quantities, monetary values, discounts, configured line identity, and per-rate taxes without current-catalog repricing or tax recalculation; all transferred or copied lines use fresh order-item objects.
* Stock: Kept order mutations physically stock-neutral where required, conserved `_reduced_stock` ownership through Split, Merge, and Return, and prevented Duplicate from inheriting stock-reduction ownership.
* Recovery: Added operation-scoped leases, durable journals, immutable fingerprints, idempotent replay, recovery, compensation, and manual-reconciliation blockers for interrupted or conflicting operations.
* Compatibility: Added WooCommerce 11.0.1 validation across legacy, HPOS-only, and HPOS compatibility/synchronization storage, with PHP 7.4/8.1/8.3 and WordPress 7.1 compatibility evidence.
* UI/UX: Added shared accessible WooCommerce Backbone modals, server-built Split bucket review, Merge target search, single Return, resumable Bulk Return, focus management, and native order-relation links.
* Developer: Consolidated production mutations behind one gateway and domain engine, removed legacy mutation execution paths, and hardened deterministic distribution validation and package contents.

= 1.4.11 (Jun 13, 2026) =
* Fix: Hardened AJAX capability checks for split and bulk return actions.
* Fix: Validated split quantities against each original line item.
* Improve: Declared WooCommerce HPOS compatibility and updated order edit redirects.
* Improve: Scoped admin assets to WooCommerce order and settings screens.
* Improve: Added throttled inline post-action tips after split, duplicate, merge, and return workflows.

== Upgrade Notice ==

= 1.5.0 =
Adds hardened Split, Duplicate, Merge, Return, and Bulk Return workflows with server-side review, historical value preservation, durable recovery, and legacy/HPOS storage validation.
