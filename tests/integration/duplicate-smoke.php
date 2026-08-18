<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_integration_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
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
wcos_integration_assert('2' === (string) $source_line->get_meta('_reduced_stock', true), 'Source reduced-stock marker was not created.');
wcos_integration_assert(8 === (int) $product->get_stock_quantity(), 'Source stock reduction did not produce the expected baseline.');

$source_shipping_ids = array_keys($source->get_items('shipping'));
$source_total = $source->get_total();
$source_currency = $source->get_currency();
$operation_id = 'integration-duplicate-' . wp_generate_uuid4();

$service = new WCOS_Duplicate_Order_Service();
$duplicate = $service->duplicate($source, $operation_id);
$duplicate = wc_get_order($duplicate->get_id());
$product = wc_get_product($product_id);

wcos_integration_assert($duplicate instanceof WC_Order, 'Duplicate order was not created.');
wcos_integration_assert('pending' === $duplicate->get_status(), 'Duplicate order must use a safe pending status.');
wcos_integration_assert($source_currency === $duplicate->get_currency(), 'Currency was not preserved.');
wcos_integration_assert((string) $source_total === (string) $duplicate->get_total(), 'Grand total was not preserved.');
wcos_integration_assert('' === $duplicate->get_transaction_id(), 'Payment transaction state must not be copied.');
wcos_integration_assert(8 === (int) $product->get_stock_quantity(), 'Duplicating an order changed physical stock.');
wcos_integration_assert(false === $duplicate->get_data_store()->get_stock_reduced($duplicate->get_id()), 'Duplicate order was incorrectly marked stock-reduced.');
wcos_integration_assert($operation_id === (string) $duplicate->get_meta('_wcos_operation_id', true), 'Duplicate operation ID was not persisted.');

$duplicate_line = current($duplicate->get_items('line_item'));
wcos_integration_assert('' === (string) $duplicate_line->get_meta('_reduced_stock', true), 'Duplicate line inherited _reduced_stock.');

$duplicate_shipping_ids = array_keys($duplicate->get_items('shipping'));
wcos_integration_assert(1 === count($source_shipping_ids), 'Source shipping item unexpectedly changed.');
wcos_integration_assert(1 === count($duplicate_shipping_ids), 'Duplicate shipping item was not created.');
wcos_integration_assert($source_shipping_ids[0] !== $duplicate_shipping_ids[0], 'Shipping item was re-parented instead of cloned.');
wcos_integration_assert(1 === count(wc_get_order($source->get_id())->get_items('shipping')), 'Source shipping item disappeared after duplicate.');

$retry = $service->duplicate(wc_get_order($source->get_id()), $operation_id);
wcos_integration_assert($retry->get_id() === $duplicate->get_id(), 'Retry with the same operation ID created a second duplicate.');
wcos_integration_assert(8 === (int) wc_get_product($product_id)->get_stock_quantity(), 'Idempotent retry changed physical stock.');

$duplicate->delete(true);
$source->delete(true);
wp_delete_post($product_id, true);

echo "duplicate-integrity-ok\n";
