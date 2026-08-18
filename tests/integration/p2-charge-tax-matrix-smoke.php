<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_p2_charge_units($value, $precision = 2) {
	return WCOS_Decimal::to_units($value, (int) $precision);
}

function wcos_p2_charge_tax_rows(WC_Order $order) {
	$rows = array();
	foreach ($order->get_items('tax') as $item) {
		$rows[(int) $item->get_rate_id()] = array(
			'cart' => wcos_p2_charge_units($item->get_tax_total()),
			'shipping' => wcos_p2_charge_units($item->get_shipping_tax_total()),
		);
	}
	ksort($rows, SORT_NUMERIC);
	return $rows;
}

function wcos_p2_charge_order_ids(array $orders) {
	return array_values(array_map(static function($order) {
		return $order instanceof WC_Order ? $order->get_id() : 0;
	}, $orders));
}

function wcos_p2_charge_build_order($prices_include_tax, $paid = false) {
	$product = wcos_p2_adapter_product('WCOS P2 charge matrix ' . ($prices_include_tax ? 'incl' : 'excl'), '10.00');
	$order = wc_create_order();
	$order->set_status($paid ? 'processing' : 'pending');
	$order->set_currency('USD');
	$order->set_prices_include_tax((bool) $prices_include_tax);
	if ($paid) {
		$order->set_payment_method('bacs');
		$order->set_payment_method_title('Bank transfer');
		$order->set_transaction_id('wcos-p2-source-transaction');
		$order->set_date_paid(time());
	}

	$line = new WC_Order_Item_Product();
	$result = $line->set_props(array(
		'name' => 'Historical taxable line',
		'product_id' => $product->get_id(),
		'variation_id' => 0,
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
	wcos_p2_adapter_assert(!is_wp_error($result), 'Unable to create the P2 historical line item.');
	$order->add_item($line);

	$fee = new WC_Order_Item_Fee();
	$fee->set_name('Historical service fee');
	$fee->set_amount('5.00');
	$fee->set_total('5.00');
	$fee->set_tax_status('taxable');
	$fee->set_tax_class('');
	$fee->set_taxes(array('total' => array(202 => '0.50')));
	$order->add_item($fee);

	$shipping_a = new WC_Order_Item_Shipping();
	$shipping_a->set_method_title('Historical package A');
	$shipping_a->set_method_id('flat_rate');
	$shipping_a->set_instance_id(1);
	$shipping_a->set_total('4.00');
	$shipping_a->set_taxes(array('total' => array(303 => '0.40')));
	$order->add_item($shipping_a);

	$shipping_b = new WC_Order_Item_Shipping();
	$shipping_b->set_method_title('Historical package B');
	$shipping_b->set_method_id('local_pickup');
	$shipping_b->set_instance_id(2);
	$shipping_b->set_total('6.00');
	$shipping_b->set_taxes(array('total' => array(304 => '0.60')));
	$order->add_item($shipping_b);

	foreach (array(
		101 => array('label' => 'Historical line rate', 'cart' => '2.00', 'shipping' => '0.00'),
		202 => array('label' => 'Historical fee rate', 'cart' => '0.50', 'shipping' => '0.00'),
		303 => array('label' => 'Historical package A rate', 'cart' => '0.00', 'shipping' => '0.40'),
		304 => array('label' => 'Historical package B rate', 'cart' => '0.00', 'shipping' => '0.60'),
	) as $rate_id => $state) {
		$tax = new WC_Order_Item_Tax();
		$tax_result = $tax->set_props(array(
			'rate_id' => $rate_id,
			'label' => $state['label'],
			'compound' => false,
			'tax_total' => $state['cart'],
			'shipping_tax_total' => $state['shipping'],
			'rate_percent' => 10,
		));
		wcos_p2_adapter_assert(!is_wp_error($tax_result), 'Unable to create a historical tax row.');
		$order->add_item($tax);
	}

	$order_result = $order->set_props(array(
		'discount_total' => '0.00',
		'discount_tax' => '0.00',
		'shipping_total' => '10.00',
		'shipping_tax' => '1.00',
		'cart_tax' => '2.50',
		'total_tax' => '3.50',
		'total' => '38.50',
	));
	wcos_p2_adapter_assert(!is_wp_error($order_result), 'Unable to set historical order charge totals.');
	$order->save();
	return array(wc_get_order($order->get_id()), $product, $line->get_id());
}

$adapter = new WCOS_Split_WooCommerce_Adapter();

foreach (array(false, true) as $prices_include_tax) {
	foreach (array(false, true) as $paid) {
		list($source, $product, $line_id) = wcos_p2_charge_build_order($prices_include_tax, $paid);
		WCOS_Order_Totals_Rebuilder::assert_consistent($source, 2);
		$report = $adapter->preflight($source);
		wcos_p2_adapter_assert(!empty($report['supported']), 'P2 preflight rejected a supported historical charge fixture.');
		wcos_p2_adapter_assert((bool) $prices_include_tax === (bool) $report['prices_include_tax'], 'P2 preflight lost the prices-include-tax context.');
		wcos_p2_adapter_assert((bool) $paid === (bool) $report['is_paid'], 'P2 preflight reported the wrong paid-state fact.');
		wcos_p2_adapter_assert(2 === (int) $report['shipping_count'] && 1 === (int) $report['fee_count'], 'P2 preflight lost the charge-row counts.');

		$source_shipping_ids = array_keys($source->get_items('shipping'));
		$source_fee_ids = array_keys($source->get_items('fee'));
		$before = WCOS_Order_Contract_Snapshot::aggregate(array($source), 2);
		$operation_id = 'p2-charge-tax-' . ($prices_include_tax ? 'incl-' : 'excl-') . ($paid ? 'paid-' : 'unpaid-') . wp_generate_uuid4();
		$children = $adapter->split($source, array('child-one' => array($line_id => '1.000000')), $operation_id);
		wcos_p2_adapter_assert(1 === count($children), 'Historical charge Split did not create exactly one child.');
		$source = wc_get_order($source->get_id());
		$child = wc_get_order($children[0]->get_id());

		wcos_p2_adapter_assert($source_shipping_ids === array_keys($source->get_items('shipping')), 'Shipping row ownership changed on the source.');
		wcos_p2_adapter_assert($source_fee_ids === array_keys($source->get_items('fee')), 'Fee row ownership changed on the source.');
		wcos_p2_adapter_assert(0 === count($child->get_items('shipping')), 'A child received a duplicated shipping package.');
		wcos_p2_adapter_assert(0 === count($child->get_items('fee')), 'A child received a duplicated fee row.');
		wcos_p2_adapter_assert(1000 === wcos_p2_charge_units($source->get_shipping_total()), 'Source shipping total changed under keep-on-source policy.');
		$source_fees = $source->get_items('fee');
		$source_fee = reset($source_fees);
		wcos_p2_adapter_assert($source_fee instanceof WC_Order_Item_Fee && 500 === wcos_p2_charge_units($source_fee->get_total()), 'Source fee total changed under keep-on-source policy.');
		wcos_p2_adapter_assert((bool) $prices_include_tax === (bool) $child->get_prices_include_tax(), 'Child lost prices-include-tax context.');
		wcos_p2_adapter_assert('USD' === $child->get_currency(), 'Child lost the source currency.');

		$expected_source_tax = array(
			101 => array('cart' => 100, 'shipping' => 0),
			202 => array('cart' => 50, 'shipping' => 0),
			303 => array('cart' => 0, 'shipping' => 40),
			304 => array('cart' => 0, 'shipping' => 60),
		);
		$expected_child_tax = array(101 => array('cart' => 100, 'shipping' => 0));
		wcos_p2_adapter_assert($expected_source_tax === wcos_p2_charge_tax_rows($source), 'Source historical tax rows drifted across line/fee/shipping rates.');
		wcos_p2_adapter_assert($expected_child_tax === wcos_p2_charge_tax_rows($child), 'Child tax rows include non-child charges or lost the line rate.');
		wcos_p2_adapter_assert(2750 === wcos_p2_charge_units($source->get_total()), 'Source grand total is wrong after keep-on-source charge allocation.');
		wcos_p2_adapter_assert(1100 === wcos_p2_charge_units($child->get_total()), 'Child grand total is wrong after historical line allocation.');
		WCOS_Mutation_Contract::assert_conserved($before, WCOS_Order_Contract_Snapshot::aggregate(array($source, $child), 2), 2);

		if ($paid) {
			wcos_p2_adapter_assert('wcos-p2-source-transaction' === $source->get_transaction_id(), 'Paid source lost its transaction ID.');
			wcos_p2_adapter_assert('' === $child->get_transaction_id(), 'Child duplicated the source transaction ID.');
			wcos_p2_adapter_assert('bacs' === $child->get_payment_method(), 'Child lost non-transaction payment context.');
			wcos_p2_adapter_assert('pending' === $child->get_status(), 'Paid source produced a paid-status child.');
			wcos_p2_adapter_assert(null === $child->get_date_paid(), 'Child duplicated the source paid timestamp.');
		}

		wcos_p2_adapter_cleanup($source->get_id(), $operation_id);
		wp_delete_post($product->get_id(), true);
	}
}

/* Negative fees are discount-like and remain explicitly unsupported. */
$negative_product = wcos_p2_adapter_product('WCOS P2 negative fee reject', '10.00');
list($negative_source, $negative_line_id) = wcos_p2_adapter_order($negative_product, 2);
$negative_fee = new WC_Order_Item_Fee();
$negative_fee->set_name('Legacy negative fee');
$negative_fee->set_amount('-2.00');
$negative_fee->set_total('-2.00');
$negative_fee->set_tax_status('none');
$negative_source->add_item($negative_fee);
$negative_source->set_total('18.00');
$negative_source->save();
$negative_source = wc_get_order($negative_source->get_id());
$negative_report = $adapter->preflight($negative_source);
wcos_p2_adapter_assert(empty($negative_report['supported']) && 'negative_fee_policy_missing' === $negative_report['reason'], 'Negative fee did not fail closed with a stable policy reason.');
wcos_p2_adapter_assert(1 === (int) $negative_report['negative_fee_count'], 'Negative fee was not reported by preflight.');
$negative_operation = 'p2-negative-fee-' . wp_generate_uuid4();
$negative_before = WCOS_Order_Contract_Snapshot::source_signature($negative_source);
$negative_rejected = false;
try {
	$adapter->split($negative_source, array('child-one' => array($negative_line_id => '1.000000')), $negative_operation);
} catch (WCOS_Split_Preflight_Exception $exception) {
	$negative_rejected = 'negative_fee_policy_missing' === $exception->get_reason();
}
wcos_p2_adapter_assert($negative_rejected, 'Negative-fee Split was not rejected before mutation.');
wcos_p2_adapter_assert($negative_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($negative_source->get_id())), 'Negative-fee rejection changed the source order.');
wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($negative_source->get_id()), $negative_operation), 'Negative-fee rejection created a journal.');
wcos_p2_adapter_cleanup($negative_source->get_id());
wp_delete_post($negative_product->get_id(), true);

/* Multi-child rounding: every cent and tax cent is conserved deterministically. */
$round_product = wcos_p2_adapter_product('WCOS P2 rounding line', '3.33');
$round_source = wc_create_order();
$round_source->set_status('pending');
$round_source->set_currency('USD');
$round_line = new WC_Order_Item_Product();
$round_result = $round_line->set_props(array(
	'name' => 'Rounding line',
	'product_id' => $round_product->get_id(),
	'quantity' => '3.000000',
	'subtotal' => '10.00',
	'total' => '10.00',
	'taxes' => array('subtotal' => array(401 => '1.00'), 'total' => array(401 => '1.00')),
	'subtotal_tax' => '1.00',
	'total_tax' => '1.00',
));
wcos_p2_adapter_assert(!is_wp_error($round_result), 'Unable to build multi-child rounding line.');
$round_source->add_item($round_line);
$round_tax = new WC_Order_Item_Tax();
$round_tax_result = $round_tax->set_props(array('rate_id' => 401, 'label' => 'Rounding tax', 'tax_total' => '1.00', 'shipping_tax_total' => '0.00', 'compound' => false, 'rate_percent' => 10));
wcos_p2_adapter_assert(!is_wp_error($round_tax_result), 'Unable to build multi-child rounding tax row.');
$round_source->add_item($round_tax);
$round_order_result = $round_source->set_props(array('cart_tax' => '1.00', 'total_tax' => '1.00', 'total' => '11.00'));
wcos_p2_adapter_assert(!is_wp_error($round_order_result), 'Unable to set multi-child rounding totals.');
$round_source->save();
$round_source = wc_get_order($round_source->get_id());
$round_line_ids = array_keys($round_source->get_items('line_item'));
$round_line_id = reset($round_line_ids);
$round_before = WCOS_Order_Contract_Snapshot::aggregate(array($round_source), 2);
$round_operation = 'p2-rounding-' . wp_generate_uuid4();
$round_plan = array(
	'child-a' => array($round_line_id => '1.000000'),
	'child-b' => array($round_line_id => '1.000000'),
);
$round_children = $adapter->split($round_source, $round_plan, $round_operation);
wcos_p2_adapter_assert(2 === count($round_children), 'Multi-child rounding Split did not create two children.');
$round_source = wc_get_order($round_source->get_id());
$round_child_ids = wcos_p2_charge_order_ids(array_values($round_children));
$round_orders = array($round_source);
foreach ($round_child_ids as $round_child_id) {
	$round_orders[] = wc_get_order($round_child_id);
}
$subtotal_parts = array();
$tax_parts = array();
foreach ($round_orders as $round_order) {
	$round_items = $round_order->get_items('line_item');
	$round_item = reset($round_items);
	wcos_p2_adapter_assert($round_item instanceof WC_Order_Item_Product, 'Multi-child rounding destination lost its line item.');
	wcos_p2_adapter_assert(1000000 === wcos_p2_charge_units($round_item->get_quantity(), 6), 'Multi-child rounding changed a destination quantity.');
	$subtotal_parts[] = wcos_p2_charge_units($round_item->get_subtotal());
	$tax_parts[] = wcos_p2_charge_units($round_item->get_total_tax());
}
sort($subtotal_parts, SORT_NUMERIC);
sort($tax_parts, SORT_NUMERIC);
wcos_p2_adapter_assert(array(333, 333, 334) === $subtotal_parts, 'Ten-dollar line was not allocated by deterministic one-cent remainder.');
wcos_p2_adapter_assert(array(33, 33, 34) === $tax_parts, 'One-dollar tax was not allocated by deterministic one-cent remainder.');
WCOS_Mutation_Contract::assert_conserved($round_before, WCOS_Order_Contract_Snapshot::aggregate($round_orders, 2), 2);
$retry_children = $adapter->split(wc_get_order($round_source->get_id()), $round_plan, $round_operation);
wcos_p2_adapter_assert($round_child_ids === wcos_p2_charge_order_ids(array_values($retry_children)), 'Multi-child rounding retry did not reuse the same child set.');
wcos_p2_adapter_cleanup($round_source->get_id(), $round_operation);
wp_delete_post($round_product->get_id(), true);

echo "p2-charge-tax-payment-rounding-matrix-ok\n";
