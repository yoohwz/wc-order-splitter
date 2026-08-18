<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_split_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_split_children($source_id, $operation_id) {
	$orders = wc_get_orders(array(
		'limit' => -1,
		'return' => 'objects',
		'meta_query' => array(
			'relation' => 'AND',
			array(
				'key' => WCOS_Split_Order_Service::OPERATION_META,
				'value' => $operation_id,
			),
			array(
				'key' => WCOS_Split_Order_Service::RELATION_PARENT_META,
				'value' => $source_id,
				'type' => 'NUMERIC',
			),
		),
	));
	$children = array();
	foreach ($orders as $order) {
		$key = (string) $order->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true);
		$children[$key] = $order;
	}
	ksort($children, SORT_STRING);
	return $children;
}

function wcos_split_ids(array $orders) {
	$ids = array();
	foreach ($orders as $order) {
		$ids[] = $order->get_id();
	}
	sort($ids, SORT_NUMERIC);
	return $ids;
}

$product_a = new WC_Product_Simple();
$product_a->set_name('WCOS split product A');
$product_a->set_regular_price('20.00');
$product_a->set_manage_stock(true);
$product_a->set_stock_quantity(20);
$product_a_id = $product_a->save();

$product_b = new WC_Product_Simple();
$product_b->set_name('WCOS split product B');
$product_b->set_regular_price('15.00');
$product_b->set_manage_stock(true);
$product_b->set_stock_quantity(20);
$product_b_id = $product_b->save();

$source = wc_create_order();
$source->set_currency('USD');
$item_a_id = $source->add_product($product_a, 5);
$item_b_id = $source->add_product($product_b, 4);
$item_a = $source->get_item($item_a_id);
$item_a->add_meta_data('fulfillment_group', 'north', true);
$item_a->save();

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
$source->get_data_store()->set_stock_reduced($source->get_id(), true);
$source = wc_get_order($source->get_id());

$source_id = $source->get_id();
$before_contract = WCOS_Order_Contract_Snapshot::aggregate(array($source));
$before_stock = WCOS_Order_Contract_Snapshot::product_stock($source);
$before_total = (string) $source->get_total();
$before_shipping_ids = array_keys($source->get_items('shipping'));
$before_fee_ids = array_keys($source->get_items('fee'));
$before_item_ids = array_keys($source->get_items('line_item'));

$plan = array(
	'child-a' => array(
		$item_a_id => '1.000000',
		$item_b_id => '1.000000',
	),
	'child-b' => array(
		$item_a_id => '2.000000',
		$item_b_id => '1.000000',
	),
);
$operation_id = 'integration-split-recovery-' . wp_generate_uuid4();
$service = new WCOS_Split_Order_Service();

/* Inject a crash after the first child is durable but before source commit. */
$fail_once = true;
$failure_callback = static function($stage) use (&$fail_once) {
	if ($fail_once && 'after_child_save' === $stage) {
		$fail_once = false;
		throw new RuntimeException('Injected split crash after first child persistence.');
	}
};
add_action('wcos_split_mutation_checkpoint', $failure_callback, 10, 4);

$thrown = false;
try {
	$service->split($source, $plan, $operation_id);
} catch (RuntimeException $exception) {
	$thrown = false !== strpos($exception->getMessage(), 'Injected split crash');
}
remove_action('wcos_split_mutation_checkpoint', $failure_callback, 10);
wcos_split_assert($thrown, 'The injected split crash did not escape the first attempt.');

$source_after_failure = wc_get_order($source_id);
$partial_children = wcos_split_children($source_id, $operation_id);
wcos_split_assert(1 === count($partial_children), 'The interrupted split did not leave exactly one reusable child.');
$first_child_id = reset($partial_children)->get_id();
wcos_split_assert('5' === (string) $source_after_failure->get_item($item_a_id)->get_quantity(), 'Source product A changed before commit.');
wcos_split_assert('4' === (string) $source_after_failure->get_item($item_b_id)->get_quantity(), 'Source product B changed before commit.');
wcos_split_assert($before_total === (string) $source_after_failure->get_total(), 'Source total changed before commit.');
wcos_split_assert(empty($source_after_failure->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true)), 'Source relation graph changed before commit.');
WCOS_Order_Contract_Snapshot::assert_product_stock_equal($before_stock, WCOS_Order_Contract_Snapshot::product_stock($source_after_failure));
$failure_journal = WCOS_Operation_Journal::get($source_after_failure, $operation_id);
wcos_split_assert(is_array($failure_journal) && 'failed' === $failure_journal['status'], 'Pre-commit split failure was not journaled as failed.');

/* Retry the exact request: reuse the durable child and finish the saga. */
$children = $service->split($source_after_failure, $plan, $operation_id);
wcos_split_assert(2 === count($children), 'Expected exactly two completed split children.');
$children_by_key = wcos_split_children($source_id, $operation_id);
wcos_split_assert(array('child-a', 'child-b') === array_keys($children_by_key), 'Persisted child keys do not match the normalized plan.');
wcos_split_assert(in_array($first_child_id, wcos_split_ids($children_by_key), true), 'Retry did not reuse the first durable child.');
wcos_split_assert(2 === count($children_by_key), 'Retry created an unexpected number of child orders.');

$source = wc_get_order($source_id);
wcos_split_assert('2' === (string) $source->get_item($item_a_id)->get_quantity(), 'Source product A quantity was not reduced correctly.');
wcos_split_assert('2' === (string) $source->get_item($item_b_id)->get_quantity(), 'Source product B quantity was not reduced correctly.');
wcos_split_assert($before_item_ids === array_keys($source->get_items('line_item')), 'Source product item IDs changed during split.');
wcos_split_assert($before_shipping_ids === array_keys($source->get_items('shipping')), 'Source shipping item ownership changed during split.');
wcos_split_assert($before_fee_ids === array_keys($source->get_items('fee')), 'Source fee item ownership changed during split.');

$expected_quantities = array(
	'child-a' => array($product_a_id => 1, $product_b_id => 1),
	'child-b' => array($product_a_id => 2, $product_b_id => 1),
);
foreach ($children_by_key as $child_key => $child) {
	wcos_split_assert('pending' === $child->get_status(), 'Split child must remain pending.');
	wcos_split_assert(0 === count($child->get_items('shipping')), 'Shipping was duplicated to a child order.');
	wcos_split_assert(0 === count($child->get_items('fee')), 'Fee was duplicated to a child order.');
	wcos_split_assert(0 === count($child->get_items('coupon')), 'Coupon was duplicated to a child order.');
	wcos_split_assert((int) $child->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true) === $source_id, 'Child structured parent relation is missing.');
	wcos_split_assert($operation_id === (string) $child->get_meta(WCOS_Split_Order_Service::OPERATION_META, true), 'Child operation relation is missing.');
	wcos_split_assert(true === (bool) $child->get_data_store()->get_stock_reduced($child->get_id()), 'Child order-level stock flag was not synchronized.');

	$quantities = array();
	foreach ($child->get_items('line_item') as $item) {
		$quantities[$item->get_product_id()] = (int) $item->get_quantity();
		wcos_split_assert('' !== (string) $item->get_meta('_wcos_source_item_id', true), 'Child line source relation is missing.');
	}
	ksort($quantities, SORT_NUMERIC);
	ksort($expected_quantities[$child_key], SORT_NUMERIC);
	wcos_split_assert($expected_quantities[$child_key] === $quantities, 'Child line quantities are incorrect.');
}

$child_a_product_a = null;
foreach ($children_by_key['child-a']->get_items('line_item') as $item) {
	if ($product_a_id === $item->get_product_id()) {
		$child_a_product_a = $item;
		break;
	}
}
wcos_split_assert($child_a_product_a instanceof WC_Order_Item_Product, 'Child product A line is missing.');
wcos_split_assert('north' === (string) $child_a_product_a->get_meta('fulfillment_group', true), 'Business item metadata was not preserved in split child.');

$after_contract = WCOS_Order_Contract_Snapshot::aggregate(array_merge(array($source), array_values($children_by_key)));
WCOS_Mutation_Contract::assert_conserved($before_contract, $after_contract, wc_get_price_decimals());
WCOS_Order_Contract_Snapshot::assert_product_stock_equal($before_stock, WCOS_Order_Contract_Snapshot::product_stock($source));

$child_ids = wcos_split_ids($children_by_key);
$relation_ids = array_map('absint', (array) $source->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true));
sort($relation_ids, SORT_NUMERIC);
wcos_split_assert($child_ids === $relation_ids, 'Source structured child relations are incomplete.');

$journal = WCOS_Operation_Journal::get($source, $operation_id);
wcos_split_assert(is_array($journal) && 'completed' === $journal['status'], 'Recovered split did not reach completed journal state.');

/* Completed retries are idempotent only for the same immutable request. */
$retry = $service->split(wc_get_order($source_id), $plan, $operation_id);
wcos_split_assert($child_ids === wcos_split_ids($retry), 'Completed split retry returned a different child set.');
wcos_split_assert(2 === count(wcos_split_children($source_id, $operation_id)), 'Completed split retry created more children.');

$changed_plan_rejected = false;
$changed_plan = $plan;
$changed_plan['child-b'][$item_a_id] = '1.000000';
try {
	$service->split(wc_get_order($source_id), $changed_plan, $operation_id);
} catch (RuntimeException $exception) {
	$changed_plan_rejected = false !== strpos($exception->getMessage(), 'different mutation request');
}
wcos_split_assert($changed_plan_rejected, 'The operation ID accepted a changed split request.');
wcos_split_assert(2 === count(wcos_split_children($source_id, $operation_id)), 'Changed-plan retry created additional child orders.');

WCOS_Operation_Journal::delete($source, $operation_id);
foreach ($children_by_key as $child) {
	$child->delete(true);
}
$source->delete(true);
wp_delete_post($product_a_id, true);
wp_delete_post($product_b_id, true);

echo "split-conservation-idempotency-and-recovery-ok\n";
