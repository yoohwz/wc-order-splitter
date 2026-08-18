<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_split_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_split_reduced_stock_total(array $orders) {
	$total = 0.0;
	foreach ($orders as $order) {
		foreach ($order->get_items('line_item') as $item) {
			$value = $item->get_meta('_reduced_stock', true);
			if (is_numeric($value)) {
				$total += (float) $value;
			}
		}
	}
	return $total;
}

$product_a = new WC_Product_Simple();
$product_a->set_name('WCOS split product A');
$product_a->set_regular_price('20.00');
$product_a->set_manage_stock(true);
$product_a->set_stock_quantity(10);
$product_a_id = $product_a->save();

$product_b = new WC_Product_Simple();
$product_b->set_name('WCOS split product B');
$product_b->set_regular_price('15.00');
$product_b->set_manage_stock(true);
$product_b->set_stock_quantity(10);
$product_b_id = $product_b->save();

$source = wc_create_order();
$source->set_currency('USD');
$item_a_id = $source->add_product($product_a, 3);
$item_b_id = $source->add_product($product_b, 2);

$shipping = new WC_Order_Item_Shipping();
$shipping->set_method_title('Integration shipping');
$shipping->set_method_id('flat_rate');
$shipping->set_total('5.00');
$source->add_item($shipping);

$fee = new WC_Order_Item_Fee();
$fee->set_name('Handling');
$fee->set_amount('2.00');
$fee->set_total('2.00');
$source->add_item($fee);

$source->calculate_totals(false);
$source->save();
wc_reduce_stock_levels($source);
$source = wc_get_order($source->get_id());

$before_total = (float) $source->get_total();
$before_stock_a = (int) wc_get_product($product_a_id)->get_stock_quantity();
$before_stock_b = (int) wc_get_product($product_b_id)->get_stock_quantity();
$before_reduced = wcos_split_reduced_stock_total(array($source));

$operation_id = 'integration-split-' . wp_generate_uuid4();
$service = new WCOS_Split_Order_Service();
$children = $service->split($source, array(
	'child-a' => array(
		$item_a_id => 1,
		$item_b_id => 1,
	),
), $operation_id);

wcos_split_assert(1 === count($children), 'Expected exactly one child order.');
$child = wc_get_order($children[0]->get_id());
$source = wc_get_order($source->get_id());

wcos_split_assert($child instanceof WC_Order, 'Child order was not persisted.');
wcos_split_assert(2 === (int) $source->get_item($item_a_id)->get_quantity(), 'Source product A quantity was not reduced correctly.');
wcos_split_assert(1 === (int) $source->get_item($item_b_id)->get_quantity(), 'Source product B quantity was not reduced correctly.');

$child_quantities = array();
foreach ($child->get_items('line_item') as $item) {
	$child_quantities[$item->get_product_id()] = (int) $item->get_quantity();
}
wcos_split_assert(1 === $child_quantities[$product_a_id], 'Child product A quantity is incorrect.');
wcos_split_assert(1 === $child_quantities[$product_b_id], 'Child product B quantity is incorrect.');

wcos_split_assert(1 === count($source->get_items('shipping')), 'Shipping must remain on the source order.');
wcos_split_assert(0 === count($child->get_items('shipping')), 'Shipping must not be duplicated to the child order.');
wcos_split_assert(1 === count($source->get_items('fee')), 'Fee must remain on the source order.');
wcos_split_assert(0 === count($child->get_items('fee')), 'Fee must not be duplicated to the child order.');

$after_total = (float) $source->get_total() + (float) $child->get_total();
wcos_split_assert(abs($before_total - $after_total) < 0.000001, 'Aggregate grand total was not conserved.');
wcos_split_assert($before_stock_a === (int) wc_get_product($product_a_id)->get_stock_quantity(), 'Split changed physical stock for product A.');
wcos_split_assert($before_stock_b === (int) wc_get_product($product_b_id)->get_stock_quantity(), 'Split changed physical stock for product B.');
wcos_split_assert(abs($before_reduced - wcos_split_reduced_stock_total(array($source, $child))) < 0.000001, 'Reduced-stock markers were not conserved.');

wcos_split_assert((int) $child->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true) === $source->get_id(), 'Child structured parent relation is missing.');
$child_ids = array_map('absint', (array) $source->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true));
wcos_split_assert(in_array($child->get_id(), $child_ids, true), 'Source structured child relation is missing.');

$retry = $service->split($source, array(
	'child-a' => array(
		$item_a_id => 1,
	),
), $operation_id);
wcos_split_assert(1 === count($retry) && $retry[0]->get_id() === $child->get_id(), 'Completed split operation was not idempotent.');

$child->delete(true);
$source->delete(true);
wp_delete_post($product_a_id, true);
wp_delete_post($product_b_id, true);

echo "split-conservation-ok\n";
