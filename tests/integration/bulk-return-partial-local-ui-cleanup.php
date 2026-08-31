<?php

if (!defined('ABSPATH')) { exit(1); }
$key = 'wcos_bulk_return_partial_local_ui_fixture'; $manifest = get_option($key, array());
if (!is_array($manifest) || empty($manifest)) { echo "BULK_PARTIAL_UI_ALREADY_CLEAN\n"; exit(0); }
global $wpdb;
$like = $wpdb->esc_like('_transient_wcos_bulk_return_review_') . '%';
$review_options = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like), ARRAY_A);
$allowed_ids = array_fill_keys(array_map('absint', $manifest['order_ids']), true);
foreach ($review_options as $row) {
	$record = maybe_unserialize($row['option_value']);
	$ids = is_array($record) && isset($record['plan']['canonical_child_ids']) ? array_map('absint', (array) $record['plan']['canonical_child_ids']) : array();
	if (!empty($ids) && empty(array_diff_key(array_fill_keys($ids, true), $allowed_ids))) {
		delete_option($row['option_name']); delete_option(str_replace('_transient_', '_transient_timeout_', $row['option_name']));
	}
}
foreach ($manifest['order_ids'] as $order_id) {
	$order = wc_get_order($order_id);
	if (!$order instanceof WC_Order) { continue; }
	foreach ((array) $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true) as $entry) { if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); } }
	delete_option('wcos_manual_reconcile_block_' . $order->get_id()); $order->delete(true);
}
$product = wc_get_product($manifest['product_id']); if ($product instanceof WC_Product) { $product->delete(true); }
if (!empty($manifest['allowed_status_before_exists'])) { update_option('order_splitter_status_allowed', $manifest['allowed_status_before']); } else { delete_option('order_splitter_status_allowed'); }
delete_option($key);
$orders_absent = true; foreach ($manifest['order_ids'] as $order_id) { if (wc_get_order($order_id)) { $orders_absent = false; } }
if (!$orders_absent || wc_get_product($manifest['product_id']) || false !== get_option($key, false)) { fwrite(STDERR, "BULK_PARTIAL_UI_CLEANUP_INCOMPLETE\n"); exit(2); }
echo "BULK_PARTIAL_UI_CLEANUP_OK orders=absent product=absent manifest=absent settings=restored\n";
