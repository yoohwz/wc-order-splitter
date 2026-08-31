<?php

if (!defined('ABSPATH')) { exit(1); }

require_once __DIR__ . '/split-status-fixture-authority.php';
WCOS_Test_Split_Status_Fixture_Authority::allow(array('wc-processing'));
$key = 'wcos_bulk_return_local_ui_fixture';
if (get_option($key, false)) { fwrite(STDERR, "BULK_UI_FIXTURE_EXISTS\n"); exit(2); }
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
if (empty($admins)) { fwrite(STDERR, "BULK_UI_ADMIN_MISSING\n"); exit(3); }
$user_id = absint($admins[0]); wp_set_current_user($user_id);
$product = new WC_Product_Simple(); $product->set_name('WOS-BULK-003 UI Fixture'); $product->set_regular_price('12.00'); $product->set_price('12.00'); $product->set_manage_stock(true); $product->set_stock_quantity(60); $product->save();
$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD'); $item_id = $original->add_product($product, 3); $original->calculate_totals(false); $original->save(); $original->update_status('processing');
$children = (new WCOS_Mutation_Gateway())->split(
	wc_get_order($original->get_id()),
	array('bulk-ui-a' => array($item_id => '1.000000'), 'bulk-ui-b' => array($item_id => '1.000000')),
	'bulk-ui-split-' . wp_generate_uuid4(),
	2
);
if (2 !== count($children)) { fwrite(STDERR, "BULK_UI_SPLIT_FAILED\n"); exit(4); }
$child_ids = array_values(array_map(static function($child) { return $child->get_id(); }, $children));
$reduced_total = static function(array $order_ids) {
	$total = 0;
	foreach ($order_ids as $order_id) {
		$order = wc_get_order($order_id); if (!$order instanceof WC_Order) { continue; }
		foreach ($order->get_items('line_item') as $item) { $total += WCOS_Decimal::to_units($item->get_meta('_reduced_stock', true) ?: '0', 6); }
	}
	return WCOS_Decimal::from_units($total, 6);
};
$manifest = array(
	'user_id' => $user_id,
	'product_id' => $product->get_id(),
	'original_id' => $original->get_id(),
	'child_ids' => $child_ids,
	'order_ids' => array_merge(array($original->get_id()), $child_ids),
	'product_stock_before' => WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6),
	'reduced_stock_before' => $reduced_total(array_merge(array($original->get_id()), $child_ids)),
	'created_at' => time(),
);
update_option($key, $manifest, false);
echo 'BULK_UI_FIXTURE_READY original=' . $manifest['original_id'] . ' child_a=' . $child_ids[0] . ' child_b=' . $child_ids[1] . ' stock=' . $manifest['product_stock_before'] . ' reduced=' . $manifest['reduced_stock_before'] . "\n";
