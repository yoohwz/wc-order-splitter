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

Split WooCommerce orders by quantity, category, or stock status. Duplicate, merge, return split orders, and manage fulfillment with HPOS support.

== Description ==

Order Splitter for WooCommerce helps store teams organize fulfillment without rebuilding orders by hand. Separate items into linked orders, duplicate an order, combine product lines, or return eligible split orders to their original.

Free provides complete supported on-demand order operations in the WooCommerce admin. These workflows need no Premium license.

= What you can do =

* Split an order by selected quantities, product category, or stock status.
* Duplicate one order into a new Pending payment order.
* Merge a supported source order into a selected target order.
* Return an eligible split order to its original, or review up to 20 selected orders with Bulk Return.
* Follow original and child order links from the WooCommerce order screens.

= Split methods =

**Manual Quantity:** Move selected quantities or whole lines. Quantities must match each line's WooCommerce quantity step; fractional quantities require compatible quantity and stock handling.

**Category:** Group lines by current product categories. Choose one group to keep on the source; the others become child orders.

**Stock Status:** Group lines by current product stock status and keep one group on the source. Both grouped methods move whole lines using the classification you confirmed.

New Split children preserve the source status approved during review. Eligible current and older split children can be split again. Fees and coupon records stay on the source; supported discounted line values are preserved. Only unaffected lines may move around refunded lines. Refund records stay on the source; unclear refund relationships block the action.

Shipping follows the Split setting: source-only, or retained on the source and copied to each child using the original shipping rows. It is not automatically recalculated.

= More order actions =

**Duplicate:** Create a Pending payment order with supported historical items, charges, addresses, and payment-method details. Payment transactions, paid state, and stock-reduction records are not copied. Refunded or inconsistent sources may be rejected.

**Merge:** Move supported product lines into a target while keeping its status, customer details, and charges. Lines combine only on exact commercial identity; otherwise new lines are created. Source shipping, fees, and coupon records stay with the archived source. Payment/refund history is never transferred. See the FAQ for paid/refund targets.

**Return:** Move an eligible split child's items back to its verified original order, including supported Free 1.4.11 relationships. The retired child retains its history. This is an admin undo-split action, not a customer return or refund portal.

**Bulk Return:** Review up to 20 selected orders as Eligible or Skipped, with exclusions explained. Only Eligible rows execute. A runtime failure stops later Eligible rows; saved progress supports resume without repeating completed returns.

= Review, safety, and compatibility =

Review changes, confirm, then execute. Permissions and eligibility are checked on the server. Changed orders or unsupported data stop the action rather than trigger guesswork.

Supported operations preserve historical amounts and taxes, not today's prices or rates. Split, Merge, and Return preserve physical inventory and assign stock-reduction responsibility to the appropriate orders. Duplicate does not inherit stock reduction. Recovery and retry checks prevent duplicate work; some failures require manual reconciliation.

Administrator and Shop Manager are supported by default, subject to WooCommerce permissions on every participating order. The old Shop Manager permission setting is retired.

Supports legacy order storage, High-Performance Order Storage (HPOS), and HPOS compatibility/sync mode. Back up and test product, tax, shipping, and inventory integrations on staging before upgrading.

= Upgrade to Advanced Order Actions =

For automation and more operational controls, [WooCommerce Advanced Order Actions](https://yoohw.com/product/woocommerce-advanced-order-actions/) is a separate standalone Premium plugin offering:

* Additional routing by product group, tag, attribute, and conditions; vendor and bundle routing require compatible integrations.
* Split/merge automation rules, previews, queues, and retries for eligible jobs.
* Bulk Duplicate, Action Logs, and guarded rollback for supported operations.
* Deeper shipping and customer/admin email controls.

Premium replaces and deactivates Free when activated; it is not a Free add-on or license unlock. Free does not manage Premium licensing, entitlement, checkout, downloads, or installation. Contextual product suggestions do not restrict Free actions.

== Installation ==

1. Install and activate WooCommerce.
2. Install Order Splitter for WooCommerce from Plugins > Add New, or upload the `wc-order-splitter` folder to `/wp-content/plugins/`, then activate it.
3. Review allowed Split statuses, shipping, and order labels in WooCommerce > Settings > Orders.
4. Open an eligible order, choose an action, review the result, and confirm before executing.
5. For Bulk Return, select orders in WooCommerce > Orders and choose Return to original order from Bulk actions.

== Frequently Asked Questions ==

= Can I separate in-stock and backordered items? =

Stock Status groups whole lines by reviewed catalog stock status. It does not calculate how many units of a line can ship now. Review the groups before confirming.

= Can I split orders with coupons, fees, or partial refunds? =

Yes, where review confirms eligibility. Charges and coupon records stay on the source. Unaffected lines may move around refunded lines; ambiguous refund references or unsupported product metadata prevent execution.

= Can I merge paid or refunded orders? =

Ordinary unpaid, non-refunded pairs are supported when review succeeds. A non-terminal target with payment/refund history may accept a source without financial history only when every source line has both a total and a tax total of exactly zero. Lines are added separately; target payment/refund records, existing items, payable total, and tax stay unchanged. Financial sources and non-zero settled-value transfers are rejected.

= Can I return split orders created before upgrading? =

Eligible Free 1.4.11 relationships can return after review. The plugin verifies the original; you cannot choose another destination. Ambiguous relationships, payment/refund evidence, or unsupported order data may prevent Return.

= What if an action is interrupted? =

Reopen the action and follow recovery instructions. A valid retry resumes or returns the existing result. Bulk Return keeps Eligible/Skipped rows and completed progress. Review failures before continuing; do not create new operations to bypass them.

= Does Free automate orders or require Premium? =

No. Staff start Free actions without Premium. Advanced Order Actions is optional for automation and extra controls; it replaces Free.

== Changelog ==

= 1.5.0 =
* New: Reviewed quantity, category, and stock-status Split; Duplicate, Merge, Return, and resumable Bulk Return.
* Compatibility: Preserved supported Split statuses, shipping settings, fees/coupons, unaffected refund lines, repeat splitting, and eligible 1.4.11 Return relationships.
* Integrity: Expanded supported Merge cases while protecting historical prices, taxes, stock, payments, and refunds.
* Safety: Added confirmation, interrupted-operation recovery, and duplicate-execution protection; removed the obsolete automatic external subscription request.
* UI: Improved order-action dialogs, relationship links, Shop Manager access, and optional Advanced Order Actions discovery.
* Compatibility: Validated legacy/HPOS/sync storage, WooCommerce 11.0.1, and PHP 7.4, 8.1, and 8.3; WordPress tested up to 7.1.

Full public release history is available in `changelog.txt`.

== Upgrade Notice ==

= 1.5.0 =
Back up and test on staging before upgrading from 1.4.11. Adds reviewed order operations and recovery while preserving supported historical values and split relationships. Duplicate creates Pending payment orders; unsupported or ambiguous cases stop for review.
