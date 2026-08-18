<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_duplicate_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_duplicate_targets($source_id, $operation_id) {
	return wc_get_orders(array(
		'limit' => -1,
		'return' => 'objects',
		'meta_query' => array(
			'relation' => 'AND',
			array(
				'key' => '_wcos_operation_id',
				'value' => $operation_id,
			),
			array(
				'key' => '_wcos_duplicate_source_order',
				'value' => $source_id,
				'type' => 'NUMERIC',
			),
		),
	));
}

$product = new WC_Product_Simple();
$product->set_name('WCOS integration product');
$product->set_regular_price('25.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$product_id = $product->save();

$source = wc_create_order();
$source->set_currency('USD');
$source_item_id = $source->add_product($product, 2);
$source_item = $source->get_item($source_item_id);
$source_item->add_meta_data('engraving', 'A-123', true);
$source_item->add_meta_data('_wcos_internal_test', 'must-not-copy', true);
$source_item->save();

$shipping = new WC_Order_Item_Shipping();
$shipping->set_method_title('Integration shipping');
$shipping->set_method_id('flat_rate');
$shipping->set_total('5.00');
$source->add_item($shipping);
$source->calculate_totals(false);
$source->set_transaction_id('source-transaction-must-not-copy');
$source->save();

wc_reduce_stock_levels($source);
$source->get_data_store()->set_stock_reduced($source->get_id(), true);
$source = wc_get_order($source->get_id());
$product = wc_get_product($product_id);

$source_line = current($source->get_items('line_item'));
wcos_duplicate_assert('2' === (string) $source_line->get_meta('_reduced_stock', true), 'Source reduced-stock marker was not created.');
wcos_duplicate_assert(8 === (int) $product->get_stock_quantity(), 'Source stock reduction did not produce the expected baseline.');

$source_shipping_ids = array_keys($source->get_items('shipping'));
$source_total = $source->get_total();
$source_currency = $source->get_currency();
$operation_id = 'integration-duplicate-' . wp_generate_uuid4();

$service = new WCOS_Duplicate_Order_Service();
$duplicate = $service->duplicate($source, $operation_id);
$duplicate = wc_get_order($duplicate->get_id());
$product = wc_get_product($product_id);

wcos_duplicate_assert($duplicate instanceof WC_Order, 'Duplicate order was not created.');
wcos_duplicate_assert('pending' === $duplicate->get_status(), 'Duplicate order must use a safe pending status.');
wcos_duplicate_assert($source_currency === $duplicate->get_currency(), 'Currency was not preserved.');
wcos_duplicate_assert((string) $source_total === (string) $duplicate->get_total(), 'Grand total was not preserved.');
wcos_duplicate_assert('' === $duplicate->get_transaction_id(), 'Payment transaction state must not be copied.');
wcos_duplicate_assert(8 === (int) $product->get_stock_quantity(), 'Duplicating an order changed physical stock.');
wcos_duplicate_assert(false === (bool) $duplicate->get_data_store()->get_stock_reduced($duplicate->get_id()), 'Duplicate order was incorrectly marked stock-reduced.');
wcos_duplicate_assert($operation_id === (string) $duplicate->get_meta('_wcos_operation_id', true), 'Duplicate operation ID was not persisted.');

$duplicate_line = current($duplicate->get_items('line_item'));
wcos_duplicate_assert('' === (string) $duplicate_line->get_meta('_reduced_stock', true), 'Duplicate line inherited _reduced_stock.');
wcos_duplicate_assert('A-123' === (string) $duplicate_line->get_meta('engraving', true), 'Business item metadata was not copied.');
wcos_duplicate_assert('' === (string) $duplicate_line->get_meta('_wcos_internal_test', true), 'Operational item metadata was copied.');

$duplicate_shipping_ids = array_keys($duplicate->get_items('shipping'));
wcos_duplicate_assert(1 === count($source_shipping_ids), 'Source shipping item unexpectedly changed.');
wcos_duplicate_assert(1 === count($duplicate_shipping_ids), 'Duplicate shipping item was not created.');
wcos_duplicate_assert($source_shipping_ids[0] !== $duplicate_shipping_ids[0], 'Shipping item was re-parented instead of cloned.');
wcos_duplicate_assert(1 === count(wc_get_order($source->get_id())->get_items('shipping')), 'Source shipping item disappeared after duplicate.');
wcos_duplicate_assert(1 === count(wcos_duplicate_targets($source->get_id(), $operation_id)), 'The operation produced an unexpected duplicate target count.');

$retry = $service->duplicate(wc_get_order($source->get_id()), $operation_id);
wcos_duplicate_assert($retry->get_id() === $duplicate->get_id(), 'Retry with the same operation ID created a second duplicate.');
wcos_duplicate_assert(1 === count(wcos_duplicate_targets($source->get_id(), $operation_id)), 'Idempotent retry produced a second duplicate target.');
wcos_duplicate_assert(8 === (int) wc_get_product($product_id)->get_stock_quantity(), 'Idempotent retry changed physical stock.');

$journal = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
wcos_duplicate_assert(is_array($journal) && 'completed' === $journal['status'], 'Normal duplicate operation did not reach completed journal state.');

/* Simulate a worker crash immediately after the target becomes durable. */
$recovery_operation_id = 'integration-duplicate-recovery-' . wp_generate_uuid4();
$fail_once = true;
$failure_callback = static function($stage) use (&$fail_once) {
	if ($fail_once && 'after_target_save' === $stage) {
		$fail_once = false;
		throw new RuntimeException('Injected duplicate crash after target persistence.');
	}
};
add_action('wcos_duplicate_mutation_checkpoint', $failure_callback, 10, 4);

$thrown = false;
try {
	$service->duplicate(wc_get_order($source->get_id()), $recovery_operation_id);
} catch (RuntimeException $exception) {
	$thrown = false !== strpos($exception->getMessage(), 'Injected duplicate crash');
}
remove_action('wcos_duplicate_mutation_checkpoint', $failure_callback, 10);
wcos_duplicate_assert($thrown, 'The injected post-persistence duplicate crash did not escape the first attempt.');

$recovery_targets = wcos_duplicate_targets($source->get_id(), $recovery_operation_id);
wcos_duplicate_assert(1 === count($recovery_targets), 'The failed duplicate attempt did not leave exactly one recoverable target.');
$recovery_target_id = reset($recovery_targets)->get_id();
$recovery_journal = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $recovery_operation_id);
wcos_duplicate_assert(is_array($recovery_journal) && 'recovery_required' === $recovery_journal['status'], 'Crash after duplicate persistence was not journaled for recovery.');

$recovered = $service->duplicate(wc_get_order($source->get_id()), $recovery_operation_id);
wcos_duplicate_assert($recovery_target_id === $recovered->get_id(), 'Duplicate recovery created or selected a different target.');
wcos_duplicate_assert(1 === count(wcos_duplicate_targets($source->get_id(), $recovery_operation_id)), 'Duplicate recovery created a second target.');
$recovery_journal = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $recovery_operation_id);
wcos_duplicate_assert(is_array($recovery_journal) && 'completed' === $recovery_journal['status'], 'Recovered duplicate did not reach completed journal state.');
wcos_duplicate_assert(8 === (int) wc_get_product($product_id)->get_stock_quantity(), 'Duplicate recovery changed physical stock.');

WCOS_Operation_Journal::delete($source, $operation_id);
WCOS_Operation_Journal::delete($source, $recovery_operation_id);
$recovered->delete(true);
$duplicate->delete(true);
$source->delete(true);
wp_delete_post($product_id, true);

echo "duplicate-integrity-and-recovery-ok\n";
