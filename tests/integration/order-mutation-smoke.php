<?php

if (!defined('ABSPATH') || !class_exists('WC_Order_Splitter_Order_Mutation_Engine')) {
	throw new RuntimeException('Order Splitter mutation engine is not loaded.');
}

function wcos_test_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_test_money($value) {
	return (float) wc_format_decimal($value, wc_get_price_decimals());
}

wp_set_current_user(1);
update_option('order_splitter_status_allowed', array('wc-pending'));
update_option('order_splitter_shipping_policy', WC_Order_Splitter_Order_Mutation_Engine::SHIPPING_KEEP_ON_ORIGINAL);

$product = new WC_Product_Simple();
$product->set_name('Order Splitter CI Product');
$product->set_status('publish');
$product->set_regular_price('10.00');
$product->set_price('10.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(20);
$product->save();

$order_ids = array();

try {
	$source = wc_create_order(array('status' => 'pending'));
	wcos_test_assert($source instanceof WC_Order, 'Could not create source order.');
	$order_ids[] = $source->get_id();
	$source->set_currency('USD');
	$source_item_id = $source->add_product($product, 4, array('subtotal' => 40, 'total' => 40));

	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title('CI Flat Rate');
	$shipping->set_method_id('flat_rate');
	$shipping->set_total(5);
	$shipping->set_taxes(array('total' => array()));
	$source->add_item($shipping);
	$source->calculate_totals(false);
	$source->save();

	$source_item = $source->get_item($source_item_id);
	$source_item->update_meta_data('_reduced_stock', 4);
	$source_item->save_meta_data();
	WC_Order_Splitter_Mutation_Support::set_stock_reduced($source, true);

	$before_total = wcos_test_money($source->get_total());
	$before_stock = wc_get_product($product->get_id())->get_stock_quantity();
	$engine = new WC_Order_Splitter_Order_Mutation_Engine();

	$result = $engine->split(
		$source,
		array('child-1' => array($source_item_id => 2)),
		array(
			'shipping_policy' => WC_Order_Splitter_Order_Mutation_Engine::SHIPPING_KEEP_ON_ORIGINAL,
			'tax_policy' => WC_Order_Splitter_Order_Mutation_Engine::TAX_PRESERVE_HISTORICAL,
			'email_policy' => WC_Order_Splitter_Order_Mutation_Engine::EMAIL_SUPPRESS_ALL_CHILDREN,
			'status_policy' => WC_Order_Splitter_Order_Mutation_Engine::STATUS_PRESERVE,
		),
		'ci-' . wp_generate_uuid4()
	);

	wcos_test_assert(1 === count($result['new_order_ids']), 'Split did not create exactly one child.');
	$child = wc_get_order($result['new_order_ids'][0]);
	wcos_test_assert($child instanceof WC_Order, 'Split child order does not exist.');
	$order_ids[] = $child->get_id();
	$source = wc_get_order($source->get_id());

	$source_qty = array_sum(array_map(function($item) { return (float) $item->get_quantity(); }, $source->get_items('line_item')));
	$child_qty = array_sum(array_map(function($item) { return (float) $item->get_quantity(); }, $child->get_items('line_item')));
	wcos_test_assert(4.0 === $source_qty + $child_qty, 'Split did not conserve product quantity.');
	wcos_test_assert($before_total === wcos_test_money((float) $source->get_total() + (float) $child->get_total()), 'Split did not conserve aggregate total.');
	wcos_test_assert(5.0 === (float) $source->get_shipping_total(), 'Shipping was not kept on the original order.');
	wcos_test_assert(0.0 === (float) $child->get_shipping_total(), 'Child unexpectedly received shipping revenue.');

	$reduced = WC_Order_Splitter_Mutation_Support::sum_reduced_stock_by_identity(array($source, $child));
	wcos_test_assert(4.0 === array_sum($reduced), 'Split did not conserve _reduced_stock.');
	wcos_test_assert($before_stock === wc_get_product($product->get_id())->get_stock_quantity(), 'Split changed physical stock.');

	$returned = $engine->return_split_order($child);
	wcos_test_assert($returned instanceof WC_Order, 'Return did not resolve the original order.');
	$returned = wc_get_order($returned->get_id());
	$returned_qty = array_sum(array_map(function($item) { return (float) $item->get_quantity(); }, $returned->get_items('line_item')));
	wcos_test_assert(4.0 === $returned_qty, 'Return did not restore source quantity.');
	wcos_test_assert($before_total === wcos_test_money($returned->get_total()), 'Return did not restore original total.');
	wcos_test_assert(4.0 === array_sum(WC_Order_Splitter_Mutation_Support::sum_reduced_stock_by_identity(array($returned))), 'Return did not restore _reduced_stock.');
	wcos_test_assert($before_stock === wc_get_product($product->get_id())->get_stock_quantity(), 'Return changed physical stock.');

	$duplicate = $engine->duplicate($returned);
	wcos_test_assert($duplicate instanceof WC_Order, 'Duplicate did not create an order.');
	$order_ids[] = $duplicate->get_id();
	wcos_test_assert('pending' === $duplicate->get_status(), 'Duplicate uses an unsafe draft status.');
	wcos_test_assert('USD' === $duplicate->get_currency(), 'Duplicate did not preserve currency.');
	wcos_test_assert('' === $duplicate->get_transaction_id(), 'Duplicate copied a transaction ID.');
	wcos_test_assert(!WC_Order_Splitter_Mutation_Support::get_stock_reduced($duplicate), 'Duplicate was marked as stock reduced.');
	wcos_test_assert($before_total === wcos_test_money($duplicate->get_total()), 'Duplicate did not preserve totals.');

	$target = wc_create_order(array('status' => 'pending'));
	wcos_test_assert($target instanceof WC_Order, 'Could not create merge target.');
	$order_ids[] = $target->get_id();
	$target->set_currency('USD');
	$target_item_id = $target->add_product($product, 1, array('subtotal' => 10, 'total' => 10));
	$target->calculate_totals(false);
	$target->save();
	$target_item = $target->get_item($target_item_id);
	$target_item->update_meta_data('_reduced_stock', 1);
	$target_item->save_meta_data();
	WC_Order_Splitter_Mutation_Support::set_stock_reduced($target, true);

	$combined_before = wcos_test_money((float) $returned->get_total() + (float) $target->get_total());
	$merged = $engine->merge($returned, $target);
	$merged = wc_get_order($merged->get_id());
	wcos_test_assert($combined_before === wcos_test_money($merged->get_total()), 'Merge did not conserve aggregate total.');
	$merged_qty = array_sum(array_map(function($item) { return (float) $item->get_quantity(); }, $merged->get_items('line_item')));
	wcos_test_assert(5.0 === $merged_qty, 'Merge did not conserve product quantity.');
	wcos_test_assert(5.0 === array_sum(WC_Order_Splitter_Mutation_Support::sum_reduced_stock_by_identity(array($merged))), 'Merge did not consolidate _reduced_stock.');
	wcos_test_assert($before_stock === wc_get_product($product->get_id())->get_stock_quantity(), 'Merge changed physical stock.');

	echo 'Order mutation smoke test passed. HPOS=' . get_option('woocommerce_custom_orders_table_enabled', 'no') . PHP_EOL;
} finally {
	foreach (array_unique($order_ids) as $order_id) {
		$order = wc_get_order($order_id);
		if ($order) {
			$order->delete(true);
		}
	}
	$product = wc_get_product($product->get_id());
	if ($product) {
		$product->delete(true);
	}
}
