<?php

if (!defined('ABSPATH')) { exit(1); }
$key = 'wcos_bulk_return_execute_race_fixture'; $fixture = get_option($key, array());
if (!is_array($fixture) || empty($fixture)) { echo "BULK_EXECUTE_ALREADY_CLEAN\n"; exit(0); }
foreach (array('child_id', 'original_id') as $field) {
	$order = !empty($fixture[$field]) ? wc_get_order(absint($fixture[$field])) : false;
	if ($order instanceof WC_Order) {
		$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
		foreach (is_array($summary) ? $summary : array() as $entry) { if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); } }
		delete_option('wcos_manual_reconcile_block_' . $order->get_id()); $order->delete(true);
	}
}
$product = !empty($fixture['product_id']) ? wc_get_product(absint($fixture['product_id'])) : false;
if ($product instanceof WC_Product) { $product->delete(true); }
delete_option($key); echo "BULK_EXECUTE_CLEAN\n";
