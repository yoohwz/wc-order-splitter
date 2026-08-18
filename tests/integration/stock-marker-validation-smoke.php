<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_stock_marker_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_stock_marker_case($product_id, $marker, $order_reduced, $label) {
	$order = wc_create_order();
	$order->set_status('pending');
	$order->set_currency('USD');

	$item = new WC_Order_Item_Product();
	$result = $item->set_props(array(
		'name' => 'Stock marker validation item',
		'product_id' => $product_id,
		'quantity' => '2.000000',
		'subtotal' => '20.00',
		'total' => '20.00',
		'subtotal_tax' => '0.00',
		'total_tax' => '0.00',
		'taxes' => array('subtotal' => array(), 'total' => array()),
	));
	wcos_stock_marker_assert(!is_wp_error($result), 'Unable to create stock-marker source item.');
	$item->add_meta_data('_reduced_stock', $marker, true);
	$order->add_item($item);
	WCOS_Order_Totals_Rebuilder::rebuild($order, 2);
	$order->save();
	$order->get_data_store()->set_stock_reduced($order->get_id(), (bool) $order_reduced);
	$order = wc_get_order($order->get_id());
	$item = reset($order->get_items('line_item'));

	$operation_id = sanitize_key('stock-marker-' . $label . '-' . wp_generate_uuid4());
	$plan = array('child-one' => array($item->get_id() => '1.000000'));
	$rejected = false;
	try {
		(new WCOS_Split_Order_Service())->split($order, $plan, $operation_id);
	} catch (RuntimeException $exception) {
		$rejected = false !== strpos($exception->getMessage(), 'inconsistent reduced-stock markers');
	}
	wcos_stock_marker_assert($rejected, 'Corrupted reduced-stock case was not rejected: ' . $label);

	$children = WCOS_Order_Relation_Repository::find(
		array(
			array('key' => WCOS_Split_Order_Service::OPERATION_META, 'value' => $operation_id),
			array('key' => WCOS_Split_Order_Service::RELATION_PARENT_META, 'value' => $order->get_id(), 'type' => 'NUMERIC'),
		),
		-1
	);
	wcos_stock_marker_assert(0 === count($children), 'Corrupted stock-marker source created a child: ' . $label);
	wcos_stock_marker_assert(null === WCOS_Operation_Journal::get($order, $operation_id), 'Corrupted stock-marker source started a journal: ' . $label);

	$order->delete(true);
}

$product = new WC_Product_Simple();
$product->set_name('WCOS stock marker validation product');
$product->set_regular_price('10.00');
$product->set_manage_stock(false);
$product_id = $product->save();

wcos_stock_marker_case($product_id, '-1.000000', true, 'negative');
wcos_stock_marker_case($product_id, '3.000000', true, 'exceeds-quantity');
wcos_stock_marker_case($product_id, '1.000000', false, 'order-flag-false');

wp_delete_post($product_id, true);

echo "corrupted-reduced-stock-markers-fail-closed-ok\n";
