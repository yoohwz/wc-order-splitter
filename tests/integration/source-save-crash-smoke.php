<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_source_crash_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$product = new WC_Product_Simple();
$product->set_name('WCOS source-save crash product');
$product->set_regular_price('10.00');
$product->set_manage_stock(false);
$product_id = $product->save();

$source = wc_create_order();
$source->set_status('pending');
$source->set_currency('USD');
$item = new WC_Order_Item_Product();
$result = $item->set_props(array(
	'name' => 'Source-save crash item',
	'product_id' => $product_id,
	'quantity' => '2.000000',
	'subtotal' => '20.00',
	'total' => '20.00',
	'subtotal_tax' => '0.00',
	'total_tax' => '0.00',
	'taxes' => array('subtotal' => array(), 'total' => array()),
));
wcos_source_crash_assert(!is_wp_error($result), 'Unable to construct source-save crash line.');
$source->add_item($item);
WCOS_Order_Totals_Rebuilder::rebuild($source, 2);
$source->save();
$source = wc_get_order($source->get_id());
$source_id = $source->get_id();
$line = reset($source->get_items('line_item'));
$operation_id = sanitize_key('source-save-crash-' . wp_generate_uuid4());
$plan = array(
	'child-one' => array(
		$line->get_id() => '1.000000',
	),
);

$thrown = false;
$after_save = static function($order) use ($source_id, &$thrown) {
	if (!$thrown && $order instanceof WC_Order && $order->get_id() === $source_id) {
		$thrown = true;
		throw new RuntimeException('Injected crash after WooCommerce persisted the source order.');
	}
};
add_action('woocommerce_after_order_object_save', $after_save, PHP_INT_MAX, 1);

try {
	(new WCOS_Split_Order_Service())->split($source, $plan, $operation_id);
} catch (RuntimeException $expected) {
	/* The service may rethrow or may finalize the conserved persisted state. */
}
remove_action('woocommerce_after_order_object_save', $after_save, PHP_INT_MAX);
wcos_source_crash_assert($thrown, 'The source-save crash injector did not execute.');

$source = wc_get_order($source_id);
$record = WCOS_Operation_Journal::get($source, $operation_id);
wcos_source_crash_assert(is_array($record), 'The source-save crash lost its durable operation journal.');
wcos_source_crash_assert(!empty($record['context']['source_signature_after']), 'Planned post-source signature was not persisted before source save.');
wcos_source_crash_assert(!empty($record['context']['source_recovery_signature_after']), 'Planned relation-aware recovery signature was not persisted before source save.');

$planned_checkpoint = false;
foreach ((array) $record['checkpoints'] as $checkpoint) {
	if (isset($checkpoint['stage']) && 'source_commit_planned' === $checkpoint['stage']) {
		$planned_checkpoint = true;
		break;
	}
}
wcos_source_crash_assert($planned_checkpoint, 'The source_commit_planned checkpoint is missing after the injected crash.');

$children = (new WCOS_Split_Order_Service())->split($source, $plan, $operation_id);
wcos_source_crash_assert(1 === count($children), 'Retry after source-save crash did not return exactly one child.');
$child = reset($children);

$persisted_children = WCOS_Order_Relation_Repository::find(
	array(
		array('key' => WCOS_Split_Order_Service::OPERATION_META, 'value' => $operation_id),
		array('key' => WCOS_Split_Order_Service::RELATION_PARENT_META, 'value' => $source_id, 'type' => 'NUMERIC'),
	),
	-1
);
wcos_source_crash_assert(1 === count($persisted_children), 'Retry after source-save crash created duplicate children.');
wcos_source_crash_assert($child->get_id() === reset($persisted_children)->get_id(), 'Retry returned a different child than the durable operation result.');

$source = wc_get_order($source_id);
$record = WCOS_Operation_Journal::get($source, $operation_id);
wcos_source_crash_assert('completed' === $record['status'], 'Retry after source-save crash did not reach completed journal state.');
$before_contract = $record['context']['before_contract'];
$after_contract = WCOS_Order_Contract_Snapshot::aggregate(array($source, wc_get_order($child->get_id())));
WCOS_Mutation_Contract::assert_conserved($before_contract, $after_contract, wc_get_price_decimals());

WCOS_Operation_Journal::delete($source, $operation_id);
wc_get_order($child->get_id())->delete(true);
$source->delete(true);
wp_delete_post($product_id, true);

echo "split-source-save-crash-recovery-ok\n";
