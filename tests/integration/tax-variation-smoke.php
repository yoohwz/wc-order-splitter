<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$tax_variation_previous_allowed = get_option('order_splitter_status_allowed', array('wc-processing'));
update_option('order_splitter_status_allowed', array('wc-pending'));

function wcos_tax_variation_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_tax_variation_units($value, $precision = 2) {
	return WCOS_Decimal::to_units($value, (int) $precision);
}

function wcos_tax_variation_line($order, $variation_id) {
	foreach ($order->get_items('line_item') as $item) {
		if ((int) $item->get_variation_id() === (int) $variation_id) {
			return $item;
		}
	}
	return false;
}

function wcos_tax_variation_tax_totals($order) {
	$totals = array();
	foreach ($order->get_items('tax') as $item) {
		$totals[(int) $item->get_rate_id()] = array(
			'cart' => wcos_tax_variation_units($item->get_tax_total()),
			'shipping' => wcos_tax_variation_units($item->get_shipping_tax_total()),
		);
	}
	ksort($totals, SORT_NUMERIC);
	return $totals;
}

$parent = new WC_Product_Variable();
$parent->set_name('WCOS tax and variation parent');
$parent_id = $parent->save();

$variation_red = new WC_Product_Variation();
$variation_red->set_parent_id($parent_id);
$variation_red->set_regular_price('10.00');
$variation_red->set_attributes(array('configuration' => 'red'));
$variation_red_id = $variation_red->save();

$variation_blue = new WC_Product_Variation();
$variation_blue->set_parent_id($parent_id);
$variation_blue->set_regular_price('15.00');
$variation_blue->set_attributes(array('configuration' => 'blue'));
$variation_blue_id = $variation_blue->save();

$source = wc_create_order();
$source->set_status('pending');
$source->set_currency('USD');
$source->set_prices_include_tax(false);

$red_item = new WC_Order_Item_Product();
$red_result = $red_item->set_props(array(
	'name' => 'Configured variation — red',
	'product_id' => $parent_id,
	'variation_id' => $variation_red_id,
	'quantity' => '2.000000',
	'tax_class' => '',
	'subtotal' => '20.00',
	'total' => '20.00',
	'taxes' => array(
		'subtotal' => array(101 => '2.00'),
		'total' => array(101 => '2.00'),
	),
	'subtotal_tax' => '2.00',
	'total_tax' => '2.00',
));
wcos_tax_variation_assert(!is_wp_error($red_result), 'Unable to build the red variation line.');
$red_item->add_meta_data('configuration', 'red', true);
$source->add_item($red_item);

$blue_item = new WC_Order_Item_Product();
$blue_result = $blue_item->set_props(array(
	'name' => 'Configured variation — blue',
	'product_id' => $parent_id,
	'variation_id' => $variation_blue_id,
	'quantity' => '2.000000',
	'tax_class' => '',
	'subtotal' => '30.00',
	'total' => '30.00',
	'taxes' => array(
		'subtotal' => array(202 => '3.00'),
		'total' => array(202 => '3.00'),
	),
	'subtotal_tax' => '3.00',
	'total_tax' => '3.00',
));
wcos_tax_variation_assert(!is_wp_error($blue_result), 'Unable to build the blue variation line.');
$blue_item->add_meta_data('configuration', 'blue', true);
$source->add_item($blue_item);

$red_tax = new WC_Order_Item_Tax();
$red_tax_result = $red_tax->set_props(array(
	'rate_id' => 101,
	'label' => 'Historical red rate',
	'compound' => false,
	'tax_total' => '2.00',
	'shipping_tax_total' => '0.00',
	'rate_percent' => 10,
));
wcos_tax_variation_assert(!is_wp_error($red_tax_result), 'Unable to build the red tax row.');
$source->add_item($red_tax);

$blue_tax = new WC_Order_Item_Tax();
$blue_tax_result = $blue_tax->set_props(array(
	'rate_id' => 202,
	'label' => 'Historical blue rate',
	'compound' => false,
	'tax_total' => '3.00',
	'shipping_tax_total' => '0.00',
	'rate_percent' => 10,
));
wcos_tax_variation_assert(!is_wp_error($blue_tax_result), 'Unable to build the blue tax row.');
$source->add_item($blue_tax);

$order_result = $source->set_props(array(
	'discount_total' => '0.00',
	'discount_tax' => '0.00',
	'shipping_total' => '0.00',
	'shipping_tax' => '0.00',
	'cart_tax' => '5.00',
	'total_tax' => '5.00',
	'total' => '55.00',
));
wcos_tax_variation_assert(!is_wp_error($order_result), 'Unable to set the historical order totals.');
$source->save();
$source = wc_get_order($source->get_id());

WCOS_Order_Totals_Rebuilder::assert_consistent($source, 2);
$before_contract = WCOS_Order_Contract_Snapshot::aggregate(array($source), 2);
$source_tax_ids = array_keys($source->get_items('tax'));
$source_line_ids = array_keys($source->get_items('line_item'));
$operation_id = 'integration-tax-variation-' . wp_generate_uuid4();
$plan = array(
	'configured-child' => array(
		$red_item->get_id() => '1.000000',
		$blue_item->get_id() => '1.000000',
	),
);

$service = new WCOS_Split_Order_Service();
$children = $service->split($source, $plan, $operation_id);
wcos_tax_variation_assert(1 === count($children), 'The tax/variation split did not create exactly one child.');

$source = wc_get_order($source->get_id());
$child = wc_get_order(reset($children)->get_id());
wcos_tax_variation_assert($child instanceof WC_Order, 'The tax/variation child could not be reloaded.');
wcos_tax_variation_assert($source_line_ids === array_keys($source->get_items('line_item')), 'Source variation line ownership changed.');
wcos_tax_variation_assert($source_tax_ids === array_keys($source->get_items('tax')), 'Source historical tax-row ownership changed.');

$source_red = wcos_tax_variation_line($source, $variation_red_id);
$source_blue = wcos_tax_variation_line($source, $variation_blue_id);
$child_red = wcos_tax_variation_line($child, $variation_red_id);
$child_blue = wcos_tax_variation_line($child, $variation_blue_id);
wcos_tax_variation_assert($source_red && $source_blue && $child_red && $child_blue, 'Variations sharing one parent product were collapsed or lost.');

foreach (array($source_red, $source_blue, $child_red, $child_blue) as $item) {
	wcos_tax_variation_assert(1000000 === wcos_tax_variation_units($item->get_quantity(), 6), 'A variation quantity was not allocated exactly.');
}
wcos_tax_variation_assert('red' === (string) $source_red->get_meta('configuration', true), 'Source red configuration metadata changed.');
wcos_tax_variation_assert('blue' === (string) $source_blue->get_meta('configuration', true), 'Source blue configuration metadata changed.');
wcos_tax_variation_assert('red' === (string) $child_red->get_meta('configuration', true), 'Child red configuration metadata was lost.');
wcos_tax_variation_assert('blue' === (string) $child_blue->get_meta('configuration', true), 'Child blue configuration metadata was lost.');

wcos_tax_variation_assert(1000 === wcos_tax_variation_units($source_red->get_subtotal()), 'Source red historical subtotal was not allocated exactly.');
wcos_tax_variation_assert(1000 === wcos_tax_variation_units($child_red->get_subtotal()), 'Child red historical subtotal was not allocated exactly.');
wcos_tax_variation_assert(1500 === wcos_tax_variation_units($source_blue->get_subtotal()), 'Source blue historical subtotal was not allocated exactly.');
wcos_tax_variation_assert(1500 === wcos_tax_variation_units($child_blue->get_subtotal()), 'Child blue historical subtotal was not allocated exactly.');
wcos_tax_variation_assert(100 === wcos_tax_variation_units($source_red->get_total_tax()), 'Source red historical tax was not allocated exactly.');
wcos_tax_variation_assert(100 === wcos_tax_variation_units($child_red->get_total_tax()), 'Child red historical tax was not allocated exactly.');
wcos_tax_variation_assert(150 === wcos_tax_variation_units($source_blue->get_total_tax()), 'Source blue historical tax was not allocated exactly.');
wcos_tax_variation_assert(150 === wcos_tax_variation_units($child_blue->get_total_tax()), 'Child blue historical tax was not allocated exactly.');

$expected_tax_totals = array(
	101 => array('cart' => 100, 'shipping' => 0),
	202 => array('cart' => 150, 'shipping' => 0),
);
wcos_tax_variation_assert($expected_tax_totals === wcos_tax_variation_tax_totals($source), 'Source historical tax rows were not synchronized by rate.');
wcos_tax_variation_assert($expected_tax_totals === wcos_tax_variation_tax_totals($child), 'Child historical tax rows were not cloned and synchronized by rate.');
wcos_tax_variation_assert(2750 === wcos_tax_variation_units($source->get_total()), 'Source historical grand total was not allocated exactly.');
wcos_tax_variation_assert(2750 === wcos_tax_variation_units($child->get_total()), 'Child historical grand total was not allocated exactly.');

$after_contract = WCOS_Order_Contract_Snapshot::aggregate(array($source, $child), 2);
WCOS_Mutation_Contract::assert_conserved($before_contract, $after_contract, 2);

$source_identity_red = WCOS_Line_Identity::from_item($source_red);
$source_identity_blue = WCOS_Line_Identity::from_item($source_blue);
wcos_tax_variation_assert($source_identity_red !== $source_identity_blue, 'Variation-aware line identity collapsed configured lines sharing a parent.');
wcos_tax_variation_assert($source_identity_red === WCOS_Line_Identity::from_item($child_red), 'Red line identity changed across the split.');
wcos_tax_variation_assert($source_identity_blue === WCOS_Line_Identity::from_item($child_blue), 'Blue line identity changed across the split.');

WCOS_Operation_Journal::delete($source, $operation_id);
$child->delete(true);
$source->delete(true);
wp_delete_post($variation_red_id, true);
wp_delete_post($variation_blue_id, true);
wp_delete_post($parent_id, true);
update_option('order_splitter_status_allowed', $tax_variation_previous_allowed);

echo "tax-variation-history-and-identity-ok\n";
