<?php
/**
 * End-to-end WP-CLI smoke test for the gated quantity split service.
 *
 * Usage:
 * wp eval-file tests/integration/quantity-split-executor-smoke.php legacy
 * wp eval-file tests/integration/quantity-split-executor-smoke.php hpos
 */

defined('ABSPATH') || exit;

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/service-loader.php';

/**
 * Fail the integration command when a contract is not satisfied.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 * @return void
 */
function wcos_quantity_integration_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

/**
 * Compare two currency values in minor units.
 *
 * @param mixed  $expected Expected amount.
 * @param mixed  $actual   Actual amount.
 * @param string $message  Failure message.
 * @return void
 */
function wcos_quantity_integration_amount($expected, $actual, $message) {
	wcos_quantity_integration_assert(
		WCOS_V2_Amount_Allocator::to_minor_units($expected, 2) === WCOS_V2_Amount_Allocator::to_minor_units($actual, 2),
		$message . sprintf(' Expected %s, received %s.', $expected, $actual)
	);
}

/**
 * Return all standard order IDs in the disposable test environment.
 *
 * @return int[]
 */
function wcos_quantity_integration_order_ids() {
	$ids = wc_get_orders(
		array(
			'limit'  => -1,
			'return' => 'ids',
			'type'   => 'shop_order',
			'status' => array_keys(wc_get_order_statuses()),
		)
	);
	$ids = array_map('intval', $ids);
	sort($ids, SORT_NUMERIC);

	return $ids;
}

/**
 * Read the order-level stock flag.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function wcos_quantity_integration_stock_flag(WC_Order $order) {
	$data_store = $order->get_data_store();
	wcos_quantity_integration_assert(method_exists($data_store, 'get_stock_reduced'), 'The active order store cannot read stock state.');

	return (bool) $data_store->get_stock_reduced($order->get_id());
}

$storage_mode = isset($args[0]) ? sanitize_key($args[0]) : 'legacy';
$product_id   = 0;
$source_id    = 0;
$child_id     = 0;
$initial_stock = 20.0;

try {
	wcos_quantity_integration_assert(class_exists('WooCommerce'), 'WooCommerce is not active.');
	wcos_quantity_integration_assert(class_exists('WCOS_V2_Quantity_Split_Service'), 'The quantity split service was not loaded.');

	if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
		$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		wcos_quantity_integration_assert(
			'hpos' === $storage_mode ? $hpos_enabled : !$hpos_enabled,
			sprintf('The active order storage does not match requested mode %s.', $storage_mode)
		);
	}

	update_option('order_splitter_status_allowed', array('wc-processing'));

	$product = new WC_Product_Simple();
	$product->set_name('WCOS quantity split integration product');
	$product->set_status('publish');
	$product->set_regular_price('5.00');
	$product->set_manage_stock(true);
	$product->set_stock_quantity($initial_stock);
	$product_id = $product->save();
	wcos_quantity_integration_assert($product_id > 0, 'Unable to create the integration product.');

	$source = wc_create_order(array('status' => 'pending', 'created_via' => 'wcos-integration-test'));
	wcos_quantity_integration_assert($source instanceof WC_Order, 'Unable to create the source order.');
	$source_id = $source->get_id();
	$source->set_currency('USD');
	$source->set_prices_include_tax(false);
	$source->set_customer_id(0);
	$source->set_address(
		array(
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'email'      => 'ada@example.test',
			'country'    => 'US',
		),
		'billing'
	);
	$source->set_address(
		array(
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'address_1'  => '1 Integration Street',
			'country'    => 'US',
		),
		'shipping'
	);
	$source->set_payment_method('bacs');
	$source->set_payment_method_title('Direct bank transfer');
	$source->set_customer_note('Integration context must be copied exactly.');
	$source->set_customer_ip_address('192.0.2.20');
	$source->set_customer_user_agent('WCOS-Integration/1.0');
	$source->set_transaction_id('txn-source-integration');

	$first = new WC_Order_Item_Product();
	$first->set_props(
		array(
			'name'          => 'Configured first line',
			'product_id'    => $product_id,
			'variation_id'  => 0,
			'quantity'      => 3,
			'tax_class'     => '',
			'subtotal'      => '10.00',
			'total'         => '9.00',
			'subtotal_tax'  => '1.00',
			'total_tax'     => '0.90',
			'taxes'         => array(
				'subtotal' => array(1 => '1.00'),
				'total'    => array(1 => '0.90'),
			),
		)
	);
	$first->add_meta_data('_wcos_integration_role', 'first', true);
	$first->add_meta_data('_addon_configuration', array('engraving' => 'A'), true);
	$source->add_item($first);

	$second = new WC_Order_Item_Product();
	$second->set_props(
		array(
			'name'          => 'Configured second line',
			'product_id'    => $product_id,
			'variation_id'  => 0,
			'quantity'      => 1,
			'tax_class'     => '',
			'subtotal'      => '5.00',
			'total'         => '5.00',
			'subtotal_tax'  => '0.50',
			'total_tax'     => '0.50',
			'taxes'         => array(
				'subtotal' => array(1 => '0.50'),
				'total'    => array(1 => '0.50'),
			),
		)
	);
	$second->add_meta_data('_wcos_integration_role', 'second', true);
	$second->add_meta_data('_addon_configuration', array('engraving' => 'B'), true);
	$source->add_item($second);

	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title('Integration shipping');
	$shipping->set_method_id('flat_rate');
	$shipping->set_total('5.00');
	$shipping->set_taxes(array('total' => array(1 => '0.50')));
	$shipping->add_meta_data('Items', 'Configured first line × 3, Configured second line × 1', true);
	$source->add_item($shipping);

	$tax = new WC_Order_Item_Tax();
	$tax->set_rate_id(1);
	$tax->set_label('Integration tax');
	$tax->set_compound(false);
	$tax->set_tax_total('1.40');
	$tax->set_shipping_tax_total('0.50');
	$source->add_item($tax);

	$source->set_discount_total('1.00');
	$source->set_discount_tax('0.10');
	$source->set_shipping_total('5.00');
	$source->set_shipping_tax('0.50');
	$source->set_cart_tax('1.40');
	$source->set_total('20.90');
	$source->save();

	wc_reduce_stock_levels($source_id);
	$source = wc_get_order($source_id);
	wcos_quantity_integration_assert($source instanceof WC_Order, 'Unable to reload the stock-reduced source order.');
	wcos_quantity_integration_assert(wcos_quantity_integration_stock_flag($source), 'The source order stock flag was not reduced.');
	wcos_quantity_integration_amount('16.00', wc_get_product($product_id)->get_stock_quantity(), 'Initial physical stock reduction is incorrect.');

	$source->set_status('processing');
	$source->save();
	$source = wc_get_order($source_id);

	$first_item_id = 0;
	foreach ($source->get_items('line_item') as $item_id => $item) {
		if ('first' === $item->get_meta('_wcos_integration_role', true)) {
			$first_item_id = (int) $item_id;
		}
	}
	wcos_quantity_integration_assert($first_item_id > 0, 'Unable to identify the first source line.');

	$before_ids   = wcos_quantity_integration_order_ids();
	$before_stock = (float) wc_get_product($product_id)->get_stock_quantity();
	$request      = array($first_item_id => '1');
	$operation_id = WCOS_V2_Quantity_Split_Service::create_operation_id($source_id, $request);
	wcos_quantity_integration_assert(!is_wp_error($operation_id), 'Unable to create a request-bound operation ID.');

	$result = WCOS_V2_Quantity_Split_Service::execute($source_id, $request, $operation_id);
	wcos_quantity_integration_assert(!is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'The quantity split failed.');
	wcos_quantity_integration_assert(true === $result['success'] && false === $result['idempotent'], 'The first quantity split result is incorrect.');
	wcos_quantity_integration_assert(1 === count($result['target_order_ids']), 'The split did not create exactly one child.');
	$child_id = (int) reset($result['target_order_ids']);

	$source = wc_get_order($source_id);
	$child  = wc_get_order($child_id);
	wcos_quantity_integration_assert($source instanceof WC_Order && $child instanceof WC_Order, 'The split orders could not be reloaded.');
	wcos_quantity_integration_assert('processing' === $source->get_status(), 'The source order status changed unexpectedly.');
	wcos_quantity_integration_assert('pending' === $child->get_status(), 'The child order was not left in neutral pending status.');
	wcos_quantity_integration_assert('' === $child->get_transaction_id(), 'The child copied the source transaction ID.');
	wcos_quantity_integration_assert('txn-source-integration' === $source->get_transaction_id(), 'The source lost transaction ownership.');
	wcos_quantity_integration_assert(empty($child->get_items('shipping')), 'The child incorrectly received shipping items.');
	wcos_quantity_integration_assert(empty($child->get_items('fee')), 'The child incorrectly received fee items.');
	wcos_quantity_integration_assert(empty($child->get_items('coupon')), 'The child incorrectly received coupon items.');
	wcos_quantity_integration_assert(1 === count($child->get_items('line_item')), 'The child has an unexpected line count.');
	wcos_quantity_integration_assert(2 === count($source->get_items('line_item')), 'The source line count changed unexpectedly.');
	wcos_quantity_integration_amount('17.60', $source->get_total(), 'The persisted source total is incorrect.');
	wcos_quantity_integration_amount('3.30', $child->get_total(), 'The persisted child total is incorrect.');
	wcos_quantity_integration_amount('20.90', (float) $source->get_total() + (float) $child->get_total(), 'Source and child total do not conserve the original.');
	wcos_quantity_integration_assert(wcos_quantity_integration_stock_flag($source), 'The source stock flag changed unexpectedly.');
	wcos_quantity_integration_assert(wcos_quantity_integration_stock_flag($child), 'The child did not inherit the allocated stock state.');
	wcos_quantity_integration_amount((string) $before_stock, wc_get_product($product_id)->get_stock_quantity(), 'The split changed physical product stock.');

	$after_ids = wcos_quantity_integration_order_ids();
	wcos_quantity_integration_assert(count($after_ids) === count($before_ids) + 1, 'The split created an unexpected number of orders.');

	$relation = WCOS_V2_Relation_Repository::find($source, $operation_id);
	wcos_quantity_integration_assert(is_array($relation) && 'committed' === $relation['status'] && $child_id === (int) $relation['child_order_id'], 'The committed reciprocal relation is invalid.');
	$record = WCOS_V2_Operation_Ledger::find($source, $operation_id);
	wcos_quantity_integration_assert(is_array($record) && 'committed' === $record['status'] && array($child_id) === $record['target_ids'], 'The committed operation record is invalid.');
	wcos_quantity_integration_assert(null === WCOS_V2_Recovery_Context::find($source, $operation_id), 'Successful execution left a recovery context behind.');

	$retry = WCOS_V2_Quantity_Split_Service::execute($source_id, $request, $operation_id);
	wcos_quantity_integration_assert(!is_wp_error($retry) && true === $retry['idempotent'], 'An identical retry did not return the committed result.');
	wcos_quantity_integration_assert(array($child_id) === $retry['target_order_ids'], 'The idempotent retry returned another target.');
	wcos_quantity_integration_assert($after_ids === wcos_quantity_integration_order_ids(), 'The idempotent retry created another order.');
	wcos_quantity_integration_amount((string) $before_stock, wc_get_product($product_id)->get_stock_quantity(), 'The idempotent retry changed physical stock.');

	$changed_payload = WCOS_V2_Quantity_Split_Service::execute($source_id, array($first_item_id => '2'), $operation_id);
	wcos_quantity_integration_assert(is_wp_error($changed_payload) && 'wcos_operation_payload_mismatch' === $changed_payload->get_error_code(), 'The committed token accepted a different quantity request.');
	wcos_quantity_integration_assert($after_ids === wcos_quantity_integration_order_ids(), 'The rejected payload created another order.');

	/* Prove each allocated stock marker is restored exactly once. */
	$child->set_status('cancelled');
	$child->save();
	wcos_quantity_integration_amount('17.00', wc_get_product($product_id)->get_stock_quantity(), 'Cancelling the child did not restore exactly its allocated stock.');

	$source->set_status('cancelled');
	$source->save();
	wcos_quantity_integration_amount((string) $initial_stock, wc_get_product($product_id)->get_stock_quantity(), 'Cancelling source and child did not restore original stock exactly once.');

	if (defined('WP_CLI') && WP_CLI) {
		WP_CLI::success(sprintf('WCOS quantity split executor passed using %s storage.', $storage_mode));
	}
} finally {
	foreach (array_filter(array($child_id, $source_id)) as $cleanup_order_id) {
		$cleanup_order = wc_get_order($cleanup_order_id);
		if (!$cleanup_order instanceof WC_Order) {
			continue;
		}

		if (wcos_quantity_integration_stock_flag($cleanup_order)) {
			wc_increase_stock_levels($cleanup_order_id);
		}

		$cleanup_order->delete(true);
	}

	if ($product_id) {
		$cleanup_product = wc_get_product($product_id);
		if ($cleanup_product) {
			$cleanup_product->delete(true);
		}
	}
}
