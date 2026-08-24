<?php

if (!defined('ABSPATH')) {
	exit(1);
}

wcos_p2_adapter_assert(class_exists('WCOS_Split_Strategy_WooCommerce_Adapter'), 'Strategy Split adapter was not loaded by the plugin bootstrap.');
wcos_p2_adapter_assert(method_exists('WCOS_Mutation_Gateway', 'split_strategy'), 'Strategy Split gateway boundary is missing.');
$strategy_runtime_enabled = WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY);
wcos_p2_adapter_assert(
	$strategy_runtime_enabled === WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS),
	'Category and Stock-status strategy gates diverged during adapter acceptance.'
);

$strategy_adapter = new WCOS_Split_Strategy_WooCommerce_Adapter();
$strategy_gateway = new WCOS_Mutation_Gateway();

/* Production gateway must remain hard-off for both future strategies. */
$gateway_product_a = wcos_p2_adapter_product('WCOS strategy gateway A', '5.00');
$gateway_product_b = wcos_p2_adapter_product('WCOS strategy gateway B', '4.00');
$gateway_order = wc_create_order();
$gateway_order->set_status('pending');
$gateway_order->set_currency('USD');
$gateway_item_a = $gateway_order->add_product($gateway_product_a, 2);
$gateway_item_b = $gateway_order->add_product($gateway_product_b, 1);
$gateway_order->calculate_totals(false);
$gateway_order->save();

foreach ($strategy_runtime_enabled ? array() : array(WCOS_Split_Strategy_Gates::CATEGORY, WCOS_Split_Strategy_Gates::STOCK_STATUS) as $blocked_strategy) {
	$blocked_operation = 'p2-strategy-gateway-' . $blocked_strategy . '-' . wp_generate_uuid4();
	$blocked = false;
	try {
		$strategy_gateway->split_strategy(
			wc_get_order($gateway_order->get_id()),
			$blocked_strategy,
			array('child-1' => array($gateway_item_a => '2.000000')),
			$blocked_operation
		);
	} catch (RuntimeException $exception) {
		$blocked = false !== strpos($exception->getMessage(), 'not enabled for production use');
	}
	wcos_p2_adapter_assert($blocked, 'Strategy gateway allowed a hard-off production strategy: ' . $blocked_strategy);
	wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get($gateway_order, $blocked_operation), 'Hard-off strategy gateway created a mutation journal.');
	wcos_p2_adapter_assert(0 === count(wcos_p2_adapter_children($gateway_order->get_id(), $blocked_operation)), 'Hard-off strategy gateway created a child order.');
}
$gateway_order->delete(true);
wp_delete_post($gateway_product_a->get_id(), true);
wp_delete_post($gateway_product_b->get_id(), true);

/* Category Review -> frozen plan -> whole-line adapter execution. */
$category_suffix = strtolower(wp_generate_password(6, false, false));
$category_keep_term = wp_insert_term('WCOS Strategy Keep ' . $category_suffix, 'product_cat');
$category_move_term = wp_insert_term('WCOS Strategy Move ' . $category_suffix, 'product_cat');
wcos_p2_adapter_assert(!is_wp_error($category_keep_term) && !is_wp_error($category_move_term), 'Unable to create strategy adapter category fixtures.');

$manage_stock_before_strategy_adapter = get_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_manage_stock', 'yes');

$category_keep = wcos_p2_adapter_product('WCOS Strategy Category Keep', '12.00', 20);
$category_move = wcos_p2_adapter_product('WCOS Strategy Category Move', '7.00', 20);
wp_set_object_terms($category_keep->get_id(), array(absint($category_keep_term['term_id'])), 'product_cat');
wp_set_object_terms($category_move->get_id(), array(absint($category_move_term['term_id'])), 'product_cat');
$category_order = wc_create_order();
$category_order->set_status('pending');
$category_order->set_currency('USD');
$category_keep_item = $category_order->add_product($category_keep, 2);
$category_move_item = $category_order->add_product($category_move, 2);
$category_order->calculate_totals(false);
$category_order->save();
$category_order_id = $category_order->get_id();
$category_order->update_status('processing');
$category_order = wc_get_order($category_order_id);
$category_stock_keep = wc_get_product($category_keep->get_id())->get_stock_quantity();
$category_stock_move = wc_get_product($category_move->get_id())->get_stock_quantity();
$category_before = WCOS_Order_Contract_Snapshot::aggregate(array($category_order));

$category_review = $strategy_adapter->review($category_order, WCOS_Split_Strategy_Gates::CATEGORY);
$category_keep_bucket = 'category-' . absint($category_keep_term['term_id']);
$category_move_bucket = 'category-' . absint($category_move_term['term_id']);
$category_plan = $strategy_adapter->build_plan($category_review, $category_keep_bucket);
wcos_p2_adapter_assert(isset($category_plan[$category_move_bucket][$category_move_item]), 'Category strategy adapter did not build the expected frozen whole-line plan.');

/* Live catalog classification may change after Review; Execute must not reclassify. */
wp_set_object_terms($category_move->get_id(), array(absint($category_keep_term['term_id'])), 'product_cat');
$category_operation = 'p2-strategy-category-' . wp_generate_uuid4();
$category_children = $strategy_adapter->split(
	wc_get_order($category_order_id),
	WCOS_Split_Strategy_Gates::CATEGORY,
	$category_plan,
	$category_operation
);
wcos_p2_adapter_assert(1 === count($category_children), 'Category strategy adapter did not create exactly one frozen-plan child.');
$category_source = wc_get_order($category_order_id);
$category_child = wc_get_order($category_children[0]->get_id());
wcos_p2_adapter_assert($category_source->get_item($category_keep_item) instanceof WC_Order_Item_Product, 'Category strategy adapter removed the selected source bucket.');
wcos_p2_adapter_assert(!$category_source->get_item($category_move_item), 'Category strategy adapter did not remove the frozen moved line from source.');
$category_child_items = array_values($category_child->get_items('line_item'));
wcos_p2_adapter_assert(1 === count($category_child_items) && 2 === (int) $category_child_items[0]->get_quantity(), 'Category strategy child does not contain the complete frozen source line.');
WCOS_Mutation_Contract::assert_conserved(
	$category_before,
	WCOS_Order_Contract_Snapshot::aggregate(array($category_source, $category_child)),
	wc_get_price_decimals()
);
wcos_p2_adapter_assert($category_stock_keep == wc_get_product($category_keep->get_id())->get_stock_quantity(), 'Category strategy adapter changed physical stock for the retained line.');
wcos_p2_adapter_assert($category_stock_move == wc_get_product($category_move->get_id())->get_stock_quantity(), 'Category strategy adapter changed physical stock for the moved line.');
$category_record = WCOS_Operation_Journal::get($category_source, $category_operation);
wcos_p2_adapter_assert(
	is_array($category_record)
	&& 'completed' === $category_record['status']
	&& WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER === $category_record['context']['execution_policy'],
	'Category strategy adapter did not durably use whole-line execution policy.'
);
wcos_p2_adapter_assert(array($category_move_item) === array_values($category_record['context']['fully_moved_item_ids']), 'Category strategy journal recorded the wrong destructive source-item set.');
$category_retry = $strategy_adapter->split(
	wc_get_order($category_order_id),
	WCOS_Split_Strategy_Gates::CATEGORY,
	$category_plan,
	$category_operation
);
wcos_p2_adapter_assert(1 === count($category_retry) && $category_child->get_id() === $category_retry[0]->get_id(), 'Category strategy retry created a different child order.');
wcos_p2_adapter_cleanup($category_order_id, $category_operation);
wp_delete_post($category_keep->get_id(), true);
wp_delete_post($category_move->get_id(), true);
wp_delete_term(absint($category_keep_term['term_id']), 'product_cat');
wp_delete_term(absint($category_move_term['term_id']), 'product_cat');

/* Stock-status Review remains frozen when live status changes before Execute. */
$stock_keep = wcos_p2_adapter_product('WCOS Strategy Stock Keep', '11.00');
$stock_move = wcos_p2_adapter_product('WCOS Strategy Stock Move', '6.00');
$stock_keep->set_stock_status('instock');
$stock_keep->save();
$stock_move->set_stock_status('outofstock');
$stock_move->save();
$stock_order = wc_create_order();
$stock_order->set_status('pending');
$stock_order->set_currency('USD');
$stock_keep_item = $stock_order->add_product($stock_keep, 1);
$stock_move_item = $stock_order->add_product($stock_move, 2);
$stock_order->calculate_totals(false);
$stock_order->save();
$stock_order_id = $stock_order->get_id();
$stock_before = WCOS_Order_Contract_Snapshot::aggregate(array(wc_get_order($stock_order_id)));
$stock_review = $strategy_adapter->review(wc_get_order($stock_order_id), WCOS_Split_Strategy_Gates::STOCK_STATUS);
$stock_plan = $strategy_adapter->build_plan($stock_review, 'stock-instock');
wcos_p2_adapter_assert(isset($stock_plan['stock-outofstock'][$stock_move_item]), 'Stock-status strategy adapter did not freeze the reviewed out-of-stock line.');

$stock_move = wc_get_product($stock_move->get_id());
$stock_move->set_stock_status('instock');
$stock_move->save();
$stock_operation = 'p2-strategy-stock-' . wp_generate_uuid4();
$stock_children = $strategy_adapter->split(
	wc_get_order($stock_order_id),
	WCOS_Split_Strategy_Gates::STOCK_STATUS,
	$stock_plan,
	$stock_operation
);
wcos_p2_adapter_assert(1 === count($stock_children), 'Stock-status strategy adapter did not execute the frozen reviewed plan.');
$stock_source = wc_get_order($stock_order_id);
$stock_child = wc_get_order($stock_children[0]->get_id());
wcos_p2_adapter_assert($stock_source->get_item($stock_keep_item) instanceof WC_Order_Item_Product, 'Stock-status strategy adapter removed the selected source bucket.');
wcos_p2_adapter_assert(!$stock_source->get_item($stock_move_item), 'Stock-status strategy adapter reclassified live status instead of executing the frozen plan.');
WCOS_Mutation_Contract::assert_conserved(
	$stock_before,
	WCOS_Order_Contract_Snapshot::aggregate(array($stock_source, $stock_child)),
	wc_get_price_decimals()
);
wcos_p2_adapter_cleanup($stock_order_id, $stock_operation);
wp_delete_post($stock_keep->get_id(), true);
wp_delete_post($stock_move->get_id(), true);

/* Strategy adapter rejects partial lines even though the whole-line service policy could represent them. */
$partial_a = wcos_p2_adapter_product('WCOS Strategy Partial A', '4.00');
$partial_b = wcos_p2_adapter_product('WCOS Strategy Partial B', '3.00');
$partial_order = wc_create_order();
$partial_order->set_status('pending');
$partial_order->set_currency('USD');
$partial_a_item = $partial_order->add_product($partial_a, 2);
$partial_b_item = $partial_order->add_product($partial_b, 1);
$partial_order->calculate_totals(false);
$partial_order->save();
$partial_operation = 'p2-strategy-partial-' . wp_generate_uuid4();
$partial_rejected = false;
try {
	$strategy_adapter->split(
		$partial_order,
		WCOS_Split_Strategy_Gates::CATEGORY,
		array('child-1' => array($partial_a_item => '1.000000')),
		$partial_operation
	);
} catch (InvalidArgumentException $exception) {
	$partial_rejected = false !== strpos($exception->getMessage(), 'only complete source product lines');
}
wcos_p2_adapter_assert($partial_rejected, 'Strategy adapter accepted a partial source-line allocation.');
wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get($partial_order, $partial_operation), 'Rejected partial strategy plan started a mutation journal.');

/* A single source line may not be spread across multiple strategy children. */
$multi_operation = 'p2-strategy-multi-child-' . wp_generate_uuid4();
$multi_rejected = false;
try {
	$strategy_adapter->split(
		$partial_order,
		WCOS_Split_Strategy_Gates::CATEGORY,
		array(
			'child-1' => array($partial_a_item => '1.000000'),
			'child-2' => array($partial_a_item => '1.000000'),
		),
		$multi_operation
	);
} catch (InvalidArgumentException $exception) {
	$multi_rejected = false !== strpos($exception->getMessage(), 'only one child bucket');
}
wcos_p2_adapter_assert($multi_rejected, 'Strategy adapter allowed one source line to span multiple child buckets.');
wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get($partial_order, $multi_operation), 'Rejected multi-child strategy plan started a mutation journal.');

$manual_strategy_rejected = false;
try {
	WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy(WCOS_Split_Strategy_Gates::MANUAL_QUANTITY);
} catch (InvalidArgumentException $exception) {
	$manual_strategy_rejected = true;
}
wcos_p2_adapter_assert($manual_strategy_rejected, 'Strategy adapter accepted manual_quantity as a server-built whole-line strategy.');

$partial_order->delete(true);
wp_delete_post($partial_a->get_id(), true);
wp_delete_post($partial_b->get_id(), true);
update_option('woocommerce_manage_stock', $manage_stock_before_strategy_adapter);

echo "p2-strategy-adapter-foundation-ok\n";
