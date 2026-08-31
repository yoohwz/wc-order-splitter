<?php

if (!defined('ABSPATH')) { exit(1); }

require_once __DIR__ . '/split-status-fixture-authority.php';
WCOS_Test_Split_Status_Fixture_Authority::allow(array('wc-pending'));

function wcos_bulk_limit_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_bulk_limit_assert(!empty($admins), 'Bulk Return near-limit smoke requires an administrator.');
$operator_id = absint($admins[0]); wp_set_current_user($operator_id);
$order_ids = array(); $product_id = 0;

try {
	$product = new WC_Product_Simple(); $product->set_name('WCOS Bulk Return limit 20'); $product->set_regular_price('2.00'); $product->set_price('2.00'); $product->set_manage_stock(false);
	wcos_bulk_limit_assert($product->save() > 0, 'Near-limit product could not be saved.'); $product_id = $product->get_id();
	$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD'); $item_id = $original->add_product($product, 21); $original->calculate_totals(false); $original->save();
	$order_ids[] = $original->get_id();
	$split_plan = array();
	for ($index = 1; $index <= 20; $index++) { $split_plan['bulk-limit-' . $index] = array($item_id => '1.000000'); }
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), $split_plan, 'bulk-limit-split-' . wp_generate_uuid4(), 2);
	wcos_bulk_limit_assert(20 === count($children), 'Near-limit Split did not create twenty children.');
	$child_ids = array(); foreach ($children as $child) { $child_ids[] = $child->get_id(); $order_ids[] = $child->get_id(); }

	$memory_before = memory_get_usage(true); $started = microtime(true);
	$review = WCOS_Bulk_Return_Review_Store::create($child_ids, $operator_id);
	$confirmed = WCOS_Bulk_Return_Confirmation_Store::create($review['review_id'], $review['review_token'], $operator_id);
	$elapsed = microtime(true) - $started; $memory_delta = memory_get_usage(true) - $memory_before;
	wcos_bulk_limit_assert(20 === $review['plan']['canonical_count'] && $review['plan']['all_eligible'], 'Near-limit Review did not retain twenty eligible rows.');
	$coordinator = WCOS_Bulk_Return_Confirmation_Store::verify($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id);
	wcos_bulk_limit_assert(20 === count($coordinator['verified']['authority']['operation_map']), 'Near-limit Confirm did not persist twenty UUID mappings.');
	wcos_bulk_limit_assert($elapsed < 30 && $memory_delta < 64 * 1024 * 1024, 'Near-limit Review/Confirm exceeded bounded time or memory.');
	$one = (new WCOS_Bulk_Return_Orchestrator())->advance($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id, 0);
	wcos_bulk_limit_assert(1 === $one['cursor'] && 1 === count($one['results']) && $one['has_more'], 'Near-limit Execute request advanced more than one child.');

	echo 'bulk-return-near-limit-ok rows=20 cursor=1 elapsed_ms=' . (int) round($elapsed * 1000) . ' memory_bytes=' . (int) $memory_delta . "\n";
} finally {
	foreach ($order_ids as $order_id) {
		$order = wc_get_order($order_id); if (!$order instanceof WC_Order) { continue; }
		$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
		foreach (is_array($summary) ? $summary : array() as $entry) { if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); } }
		delete_option('wcos_manual_reconcile_block_' . $order->get_id()); $order->delete(true);
	}
	$product = $product_id ? wc_get_product($product_id) : false; if ($product instanceof WC_Product) { $product->delete(true); }
}
