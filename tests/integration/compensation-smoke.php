<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_compensation_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_compensation_build_source($product_id) {
	$order = wc_create_order();
	$order->set_status('pending');
	$order->set_currency('USD');
	$item = new WC_Order_Item_Product();
	$result = $item->set_props(array(
		'name' => 'Compensation item',
		'product_id' => $product_id,
		'quantity' => '2.000000',
		'subtotal' => '20.00',
		'total' => '20.00',
		'subtotal_tax' => '0.00',
		'total_tax' => '0.00',
		'taxes' => array('subtotal' => array(), 'total' => array()),
	));
	wcos_compensation_assert(!is_wp_error($result), 'Unable to build the compensation source line.');
	$order->add_item($item);
	WCOS_Order_Totals_Rebuilder::rebuild($order, 2);
	$order->save();
	return wc_get_order($order->get_id());
}

function wcos_compensation_stage_split(WC_Order $source, $operation_id) {
	$operation_id = sanitize_key($operation_id);
	$fingerprint = WCOS_Mutation_Fingerprint::create(
		'split',
		$source->get_id(),
		array('case' => $operation_id)
	);
	wcos_compensation_assert(
		WCOS_Operation_Journal::start(
			$source,
			$operation_id,
			'split',
			array('source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source)),
			$fingerprint
		),
		'Unable to start compensation journal.'
	);

	$source_item = reset($source->get_items('line_item'));
	$child = wc_create_order();
	$child->set_status('pending');
	$child->set_currency($source->get_currency());
	$child->set_created_via('wc-order-splitter-split');
	$child->set_customer_id($source->get_customer_id());
	$child->set_address($source->get_address('billing'), 'billing');
	$child->set_address($source->get_address('shipping'), 'shipping');
	$child->add_item(
		WCOS_Order_Item_Cloner::product(
			$source_item,
			array(
				'quantity' => '1.000000',
				'subtotal' => '10.00',
				'total' => '10.00',
				'subtotal_tax' => '0.00',
				'total_tax' => '0.00',
				'taxes' => array('subtotal' => array(), 'total' => array()),
			),
			false,
			WCOS_Order_Item_Meta_Policy::CONTEXT_SPLIT
		)
	);
	$child->update_meta_data(WCOS_Split_Order_Service::RELATION_PARENT_META, $source->get_id());
	$child->update_meta_data(WCOS_Split_Order_Service::OPERATION_META, $operation_id);
	$child->update_meta_data(WCOS_Split_Order_Service::CHILD_KEY_META, 'child-one');
	WCOS_Order_Totals_Rebuilder::rebuild($child, 2);
	$child->save();
	$child = wc_get_order($child->get_id());

	$source = wc_get_order($source->get_id());
	$source_item = reset($source->get_items('line_item'));
	$result = $source_item->set_props(array(
		'quantity' => '1.000000',
		'subtotal' => '10.00',
		'total' => '10.00',
		'subtotal_tax' => '0.00',
		'total_tax' => '0.00',
		'taxes' => array('subtotal' => array(), 'total' => array()),
	));
	wcos_compensation_assert(!is_wp_error($result), 'Unable to stage the mutated source line.');
	$source->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, array($child->get_id()));
	$source->update_meta_data('yoos_splitted_order', (string) $child->get_id());
	WCOS_Order_Totals_Rebuilder::rebuild($source, 2);
	$source->save();
	$source = wc_get_order($source->get_id());

	wcos_compensation_assert(
		WCOS_Operation_Journal::checkpoint(
			$source,
			$operation_id,
			'source_persisted',
			array('target_order_ids' => array($child->get_id()))
		),
		'Unable to persist the compensation recovery checkpoint.'
	);
	$record = WCOS_Operation_Journal::get($source, $operation_id);
	wcos_compensation_assert(!empty($record['context']['source_snapshot']), 'PII-free source recovery snapshot is missing.');
	wcos_compensation_assert(!empty($record['context']['source_recovery_signature_after']), 'Post-mutation source recovery signature is missing.');
	wcos_compensation_assert(isset($record['context']['child_signatures'][$child->get_id()]), 'Persisted child recovery signature is missing.');

	return array($source, $child, $record);
}

function wcos_compensation_assert_restored(WC_Order $source, $child_id, array $record) {
	$source = wc_get_order($source->get_id());
	$line = reset($source->get_items('line_item'));
	wcos_compensation_assert('2' === (string) $line->get_quantity() || '2.000000' === (string) $line->get_quantity(), 'Compensation did not restore source quantity.');
	wcos_compensation_assert(2000 === WCOS_Decimal::to_units($source->get_total(), 2), 'Compensation did not restore source total.');
	wcos_compensation_assert(!wc_get_order($child_id), 'Compensation did not remove the operation-owned child.');
	wcos_compensation_assert(empty($source->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true)), 'Structured source relation was not restored.');
	wcos_compensation_assert('' === (string) $source->get_meta('yoos_splitted_order', true), 'Legacy source relation was not restored.');
	$snapshot = $record['context']['source_snapshot'];
	wcos_compensation_assert(
		hash_equals((string) $snapshot['source_recovery_signature'], WCOS_Order_Mutation_Snapshot::split_owned_signature($source)),
		'Restored source does not match its pre-operation recovery signature.'
	);
}

$product = new WC_Product_Simple();
$product->set_name('WCOS compensation product');
$product->set_regular_price('10.00');
$product->set_manage_stock(false);
$product_id = $product->save();

/* Normal automatic compensation. */
$source = wcos_compensation_build_source($product_id);
$operation_id = 'compensate-' . wp_generate_uuid4();
list($source, $child, $record) = wcos_compensation_stage_split($source, $operation_id);
$child_id = $child->get_id();
WCOS_Operation_Journal::require_recovery($source, $operation_id, array('reason' => 'integration_compensation'));
$final_record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
wcos_compensation_assert('compensated' === $final_record['status'], 'Automatic compensation did not reach its terminal state.');
wcos_compensation_assert_restored($source, $child_id, $record);
WCOS_Operation_Journal::delete(wc_get_order($source->get_id()), $operation_id);
wc_get_order($source->get_id())->delete(true);

/* Crash after source restore but before its checkpoint, then resume. */
$source = wcos_compensation_build_source($product_id);
$operation_id = 'compensate-resume-' . wp_generate_uuid4();
list($source, $child, $record) = wcos_compensation_stage_split($source, $operation_id);
$child_id = $child->get_id();
$thrown_once = false;
$injector = static function($stage) use (&$thrown_once) {
	if ('after_source_restore' === $stage && !$thrown_once) {
		$thrown_once = true;
		throw new RuntimeException('Injected crash after source restore.');
	}
};
add_action('wcos_split_compensation_checkpoint', $injector, 10, 1);
WCOS_Operation_Journal::require_recovery($source, $operation_id, array('reason' => 'integration_compensation_resume'));
remove_action('wcos_split_compensation_checkpoint', $injector, 10);

$intermediate_source = wc_get_order($source->get_id());
$intermediate_record = WCOS_Operation_Journal::get($intermediate_source, $operation_id);
wcos_compensation_assert('compensating' === $intermediate_record['status'], 'Injected compensation crash did not preserve resumable state.');
wcos_compensation_assert(wc_get_order($child_id) instanceof WC_Order, 'Child was deleted before source restore was durably checkpointed.');
wcos_compensation_assert(
	hash_equals((string) $record['context']['source_snapshot']['source_recovery_signature'], WCOS_Order_Mutation_Snapshot::split_owned_signature($intermediate_source)),
	'Source was not restored before the injected crash.'
);

/* A later service error/retry re-dispatches recovery for compensating state. */
WCOS_Operation_Journal::fail($intermediate_source, $operation_id, array('reason' => 'resume_compensation'));
$final_source = wc_get_order($source->get_id());
$final_record = WCOS_Operation_Journal::get($final_source, $operation_id);
wcos_compensation_assert('compensated' === $final_record['status'], 'Compensation did not resume to completion.');
wcos_compensation_assert_restored($final_source, $child_id, $record);
WCOS_Operation_Journal::delete($final_source, $operation_id);
$final_source->delete(true);

wp_delete_post($product_id, true);

echo "split-compensation-and-resume-ok\n";
