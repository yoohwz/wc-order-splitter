<?php

if (!defined('ABSPATH')) { exit(1); }
function wcos_bulk_partial_ui_verify_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function wcos_bulk_partial_ui_batch_records($order_id) {
	$order = wc_get_order(absint($order_id)); $records = array();
	if (!$order instanceof WC_Order) { return $records; }
	foreach ((array) $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true) as $entry) {
		if ('bulk_return_batch' !== sanitize_key(isset($entry['type']) ? (string) $entry['type'] : '') || empty($entry['operation_id'])) { continue; }
		$record = WCOS_Operation_Journal::get($order, $entry['operation_id']); if (is_array($record)) { $records[] = $record; }
	}
	return $records;
}
$manifest = get_option('wcos_bulk_return_partial_local_ui_fixture', array());
wcos_bulk_partial_ui_verify_assert(is_array($manifest) && 2 === count($manifest['mixed']['child_ids']) && 4 === count($manifest['runtime']['child_ids']), 'Bulk partial UI manifest is unavailable.');

$mixed_eligible = wc_get_order($manifest['mixed']['child_ids'][0]); $mixed_skipped = wc_get_order($manifest['mixed']['child_ids'][1]);
wcos_bulk_partial_ui_verify_assert($mixed_eligible instanceof WC_Order && 'trash' === $mixed_eligible->get_status(), 'Mixed Eligible child did not complete.');
wcos_bulk_partial_ui_verify_assert($mixed_skipped instanceof WC_Order && 'trash' !== $mixed_skipped->get_status(), 'Mixed Skipped child was mutated.');
$mixed_batches = wcos_bulk_partial_ui_batch_records($mixed_eligible->get_id());
wcos_bulk_partial_ui_verify_assert(1 === count($mixed_batches), 'Mixed UI flow did not create exactly one coordinator.');
$mixed_verified = WCOS_Bulk_Return_Journal_Context::assert_record($mixed_batches[0]); $mixed_summary = WCOS_Bulk_Return_Journal_Context::public_summary($mixed_verified);
wcos_bulk_partial_ui_verify_assert('completed' === $mixed_summary['status'] && 1 === $mixed_summary['counts']['completed'] && 1 === $mixed_summary['counts']['skipped'], 'Mixed terminal summary lost Eligible/Skipped outcomes.');
wcos_bulk_partial_ui_verify_assert(empty(wcos_bulk_partial_ui_batch_records($manifest['mixed']['original_id'])) && empty(wcos_bulk_partial_ui_batch_records($mixed_skipped->get_id())), 'All-Skipped UI Review or Skipped row created durable authority.');

$confirm_child = wc_get_order($manifest['confirm_drift']['child_ids'][0]);
wcos_bulk_partial_ui_verify_assert($confirm_child instanceof WC_Order && 'cancelled' === $confirm_child->get_status() && empty(wcos_bulk_partial_ui_batch_records($confirm_child->get_id())), 'Eligible-to-ineligible Confirm drift created durable authority.');

$runtime_ids = $manifest['runtime']['child_ids'];
$runtime_completed = wc_get_order($runtime_ids[0]); $runtime_skipped = wc_get_order($runtime_ids[1]); $runtime_blocked = wc_get_order($runtime_ids[2]); $runtime_not_run = wc_get_order($runtime_ids[3]);
wcos_bulk_partial_ui_verify_assert($runtime_completed instanceof WC_Order && 'trash' === $runtime_completed->get_status(), 'Runtime first Eligible child did not complete.');
foreach (array($runtime_skipped, $runtime_blocked, $runtime_not_run) as $order) { wcos_bulk_partial_ui_verify_assert($order instanceof WC_Order && 'trash' !== $order->get_status(), 'Runtime stop mutated a Skipped/blocked/not-run child.'); }
$runtime_batches = wcos_bulk_partial_ui_batch_records($runtime_completed->get_id());
wcos_bulk_partial_ui_verify_assert(1 === count($runtime_batches), 'Runtime UI flow did not retain exactly one coordinator.');
$runtime_verified = WCOS_Bulk_Return_Journal_Context::assert_record($runtime_batches[0]); $runtime_summary = WCOS_Bulk_Return_Journal_Context::public_summary($runtime_verified);
wcos_bulk_partial_ui_verify_assert('blocked' === $runtime_summary['status'] && 1 === $runtime_summary['counts']['completed'] && 1 === $runtime_summary['counts']['blocked'] && 1 === $runtime_summary['counts']['not_run_blocked'] && 1 === $runtime_summary['counts']['skipped'], 'Runtime UI terminal summary did not preserve fail-stop distinctions.');

$reduced_units = 0;
foreach ($manifest['order_ids'] as $order_id) {
	$order = wc_get_order($order_id); wcos_bulk_partial_ui_verify_assert($order instanceof WC_Order, 'Bulk partial UI order is unavailable.');
	foreach ($order->get_items('line_item') as $item) { $reduced_units += WCOS_Decimal::to_units($item->get_meta('_reduced_stock', true) ?: '0', 6); }
}
$product = wc_get_product($manifest['product_id']);
wcos_bulk_partial_ui_verify_assert($product instanceof WC_Product && $manifest['product_stock_before'] === WCOS_Decimal::normalize($product->get_stock_quantity(), 6), 'Bulk partial UI flows changed physical stock.');
wcos_bulk_partial_ui_verify_assert($manifest['reduced_stock_before'] === WCOS_Decimal::from_units($reduced_units, 6), 'Bulk partial UI flows did not conserve reduced-stock ownership.');
echo 'BULK_PARTIAL_UI_VERIFY_OK mixed=eligible-only all_skipped=non-durable confirm_drift=fresh-review runtime=fail-stop skipped=preserved stock=neutral reduced=conserved' . "\n";
