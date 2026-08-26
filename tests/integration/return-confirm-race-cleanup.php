<?php

if (!defined('ABSPATH')) { exit(1); }

$fixture_key = 'wcos_return_confirm_race_fixture';
$fixture = get_option($fixture_key, array());
if (!is_array($fixture) || empty($fixture)) { echo "RETURN_RACE_ALREADY_CLEAN\n"; exit(0); }
if (!empty($fixture['review_id'])) { WCOS_Return_Review_Store::delete($fixture['review_id']); }
foreach (array('child_id', 'original_id') as $key) {
	$order = !empty($fixture[$key]) ? wc_get_order(absint($fixture[$key])) : false;
	if ($order instanceof WC_Order) {
		$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
		foreach (is_array($summary) ? $summary : array() as $entry) {
			if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
		}
		$order->delete(true);
	}
}
$product = !empty($fixture['product_id']) ? wc_get_product(absint($fixture['product_id'])) : false;
if ($product instanceof WC_Product) { $product->delete(true); }
if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
if (!empty($fixture['user_id'])) { wp_delete_user(absint($fixture['user_id'])); }
delete_option($fixture_key);
echo "RETURN_RACE_CLEAN\n";
