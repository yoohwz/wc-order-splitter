<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_failure_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_failure_source($product_id) {
	$order = wc_create_order();
	$order->set_status('pending');
	$order->set_currency('USD');
	$item = new WC_Order_Item_Product();
	$result = $item->set_props(array(
		'name' => 'Failure matrix item',
		'product_id' => $product_id,
		'quantity' => '2.000000',
		'subtotal' => '20.00',
		'total' => '20.00',
		'subtotal_tax' => '0.00',
		'total_tax' => '0.00',
		'taxes' => array('subtotal' => array(), 'total' => array()),
	));
	wcos_failure_assert(!is_wp_error($result), 'Unable to create failure-matrix source item.');
	$order->add_item($item);
	WCOS_Order_Totals_Rebuilder::rebuild($order, 2);
	$order->save();
	return wc_get_order($order->get_id());
}

function wcos_failure_children(WC_Order $source, $operation_id) {
	return WCOS_Order_Relation_Repository::find(
		array(
			array('key' => WCOS_Split_Order_Service::OPERATION_META, 'value' => $operation_id),
			array('key' => WCOS_Split_Order_Service::RELATION_PARENT_META, 'value' => $source->get_id(), 'type' => 'NUMERIC'),
		),
		-1
	);
}

function wcos_failure_duplicate_targets(WC_Order $source, $operation_id) {
	return WCOS_Order_Relation_Repository::find(
		array(
			array('key' => '_wcos_operation_id', 'value' => $operation_id),
			array('key' => '_wcos_duplicate_source_order', 'value' => $source->get_id(), 'type' => 'NUMERIC'),
		),
		-1
	);
}

$product = new WC_Product_Simple();
$product->set_name('WCOS failure matrix product');
$product->set_regular_price('10.00');
$product->set_manage_stock(false);
$product_id = $product->save();

$split_stages = array(
	'before_child_save',
	'after_child_save',
	'before_source_save',
	'after_source_save',
	'after_persisted_verify',
);
foreach ($split_stages as $stage_under_test) {
	$source = wcos_failure_source($product_id);
	$line = reset($source->get_items('line_item'));
	$operation_id = sanitize_key('split-' . $stage_under_test . '-' . wp_generate_uuid4());
	$plan = array(
		'child-one' => array(
			$line->get_id() => '1.000000',
		),
	);
	$injected = false;
	$injector = static function($stage) use ($stage_under_test, &$injected) {
		if ($stage === $stage_under_test && !$injected) {
			$injected = true;
			throw new RuntimeException('Injected split failure at ' . $stage_under_test);
		}
	};
	add_action('wcos_split_mutation_checkpoint', $injector, 1, 1);
	try {
		(new WCOS_Split_Order_Service())->split($source, $plan, $operation_id);
	} catch (RuntimeException $expected) {
		/* Expected for pre-commit boundaries; post-commit failures may auto-finalize. */
	}
	remove_action('wcos_split_mutation_checkpoint', $injector, 1);
	wcos_failure_assert($injected, 'Split failure injector did not run at ' . $stage_under_test . '.');

	$source = wc_get_order($source->get_id());
	$children = (new WCOS_Split_Order_Service())->split($source, $plan, $operation_id);
	wcos_failure_assert(1 === count($children), 'Split retry did not return exactly one child at ' . $stage_under_test . '.');
	$child_id = $children[0]->get_id();
	$persisted = wcos_failure_children($source, $operation_id);
	wcos_failure_assert(1 === count($persisted) && $child_id === reset($persisted)->get_id(), 'Split retry created duplicate children at ' . $stage_under_test . '.');
	$record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
	wcos_failure_assert('completed' === $record['status'], 'Split retry did not complete its journal at ' . $stage_under_test . '.');

	$retry = (new WCOS_Split_Order_Service())->split(wc_get_order($source->get_id()), $plan, $operation_id);
	wcos_failure_assert(1 === count($retry) && $child_id === $retry[0]->get_id(), 'Completed Split retry was not idempotent at ' . $stage_under_test . '.');
	WCOS_Operation_Journal::delete(wc_get_order($source->get_id()), $operation_id);
	$retry[0]->delete(true);
	wc_get_order($source->get_id())->delete(true);
}

$duplicate_stages = array('before_target_save', 'after_target_save', 'after_target_verify');
foreach ($duplicate_stages as $stage_under_test) {
	$source = wcos_failure_source($product_id);
	$operation_id = sanitize_key('duplicate-' . $stage_under_test . '-' . wp_generate_uuid4());
	$injected = false;
	$injector = static function($stage) use ($stage_under_test, &$injected) {
		if ($stage === $stage_under_test && !$injected) {
			$injected = true;
			throw new RuntimeException('Injected duplicate failure at ' . $stage_under_test);
		}
	};
	add_action('wcos_duplicate_mutation_checkpoint', $injector, 1, 1);
	try {
		(new WCOS_Duplicate_Order_Service())->duplicate($source, $operation_id);
	} catch (RuntimeException $expected) {
		/* Retry below must recover the same target or create exactly one target. */
	}
	remove_action('wcos_duplicate_mutation_checkpoint', $injector, 1);
	wcos_failure_assert($injected, 'Duplicate failure injector did not run at ' . $stage_under_test . '.');

	$target = (new WCOS_Duplicate_Order_Service())->duplicate(wc_get_order($source->get_id()), $operation_id);
	$targets = wcos_failure_duplicate_targets($source, $operation_id);
	wcos_failure_assert(1 === count($targets) && $target->get_id() === reset($targets)->get_id(), 'Duplicate retry created multiple targets at ' . $stage_under_test . '.');
	$record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
	wcos_failure_assert('completed' === $record['status'], 'Duplicate retry did not complete its journal at ' . $stage_under_test . '.');
	$retry = (new WCOS_Duplicate_Order_Service())->duplicate(wc_get_order($source->get_id()), $operation_id);
	wcos_failure_assert($target->get_id() === $retry->get_id(), 'Completed Duplicate retry was not idempotent at ' . $stage_under_test . '.');

	WCOS_Operation_Journal::delete(wc_get_order($source->get_id()), $operation_id);
	$retry->delete(true);
	wc_get_order($source->get_id())->delete(true);
}

wp_delete_post($product_id, true);

echo "mutation-failure-matrix-idempotency-ok\n";
