<?php
/**
 * WP-CLI smoke test for the read-only v2 split preflight.
 *
 * Usage:
 * wp eval-file tests/integration/preflight-smoke.php legacy
 * wp eval-file tests/integration/preflight-smoke.php hpos
 */

defined('ABSPATH') || exit;

/**
 * Fail the integration command when a contract is not satisfied.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 * @return void
 */
function wcos_integration_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$storage_mode = isset($args[0]) ? sanitize_key($args[0]) : 'legacy';
$product_id   = 0;
$order_id     = 0;

try {
	wcos_integration_assert(class_exists('WooCommerce'), 'WooCommerce is not active.');
	wcos_integration_assert(class_exists('WCOS_V2_Split_Preflight'), 'The v2 split preflight was not loaded.');

	if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
		$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ('hpos' === $storage_mode) {
			wcos_integration_assert($hpos_enabled, 'HPOS was requested but is not active.');
		} else {
			wcos_integration_assert(!$hpos_enabled, 'Legacy storage was requested but HPOS is active.');
		}
	}

	update_option('order_splitter_status_allowed', array('wc-pending'));

	$product = new WC_Product_Simple();
	$product->set_name('WCOS integration product');
	$product->set_status('publish');
	$product->set_regular_price('5.00');
	$product->set_manage_stock(true);
	$product->set_stock_quantity(20);
	$product_id = $product->save();

	wcos_integration_assert($product_id > 0, 'Unable to create the integration product.');

	$order = wc_create_order(
		array(
			'status'     => 'pending',
			'created_via' => 'wcos-integration-test',
		)
	);

	wcos_integration_assert($order instanceof WC_Order, 'Unable to create the integration order.');
	$order_id = $order->get_id();
	$order->set_currency('USD');
	$order->set_prices_include_tax(false);

	$first_item = new WC_Order_Item_Product();
	$first_item->set_props(
		array(
			'name'          => 'Configured line',
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
	$first_item->add_meta_data('_addon_configuration', array('engraving' => 'A'), true);
	$first_item->add_meta_data('_reduced_stock', '3', true);
	$order->add_item($first_item);

	$second_item = new WC_Order_Item_Product();
	$second_item->set_props(
		array(
			'name'          => 'Remaining line',
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
	$order->add_item($second_item);
	$order->calculate_totals(false);
	$order->save();

	$data_store = $order->get_data_store();
	if (is_object($data_store) && method_exists($data_store, 'set_stock_reduced')) {
		$data_store->set_stock_reduced($order_id, true);
	}

	$order = wc_get_order($order_id);
	wcos_integration_assert($order instanceof WC_Order, 'Unable to reload the integration order.');

	$line_ids = array_keys($order->get_items('line_item'));
	wcos_integration_assert(2 === count($line_ids), 'The integration order does not contain two product lines.');

	$before_snapshot = WCOS_V2_Order_Snapshot::capture($order);
	$before_orders   = wc_get_orders(
		array(
			'limit'  => -1,
			'return' => 'ids',
			'type'   => 'shop_order',
		)
	);
	$before_stock = (float) wc_get_product($product_id)->get_stock_quantity();

	$result = WCOS_V2_Split_Preflight::validate(
		$order,
		array(
			$line_ids[0] => '1',
		)
	);

	wcos_integration_assert(!is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'Preflight failed.');
	wcos_integration_assert('1' === $result['plan']['split_quantity'], 'The planned split quantity is incorrect.');
	wcos_integration_assert('3' === $result['plan']['original_quantity'], 'The planned remaining quantity is incorrect.');
	wcos_integration_assert('1' === $result['plan']['lines'][$line_ids[0]]['child']['reduced_stock'], 'The child stock marker allocation is incorrect.');

	$retry = WCOS_V2_Split_Preflight::validate(
		wc_get_order($order_id),
		array(
			$line_ids[0] => '1',
		)
	);

	wcos_integration_assert(!is_wp_error($retry), 'An identical preflight retry failed.');
	wcos_integration_assert($result['fingerprint'] === $retry['fingerprint'], 'An identical preflight retry changed fingerprint.');

	$after_order    = wc_get_order($order_id);
	$after_snapshot = WCOS_V2_Order_Snapshot::capture($after_order);
	$after_orders   = wc_get_orders(
		array(
			'limit'  => -1,
			'return' => 'ids',
			'type'   => 'shop_order',
		)
	);
	$after_stock = (float) wc_get_product($product_id)->get_stock_quantity();

	wcos_integration_assert(
		wp_json_encode($before_snapshot) === wp_json_encode($after_snapshot),
		'The read-only preflight changed the source order.'
	);
	wcos_integration_assert($before_orders === $after_orders, 'The read-only preflight created or deleted an order.');
	wcos_integration_assert($before_stock === $after_stock, 'The read-only preflight changed physical stock.');

	if (defined('WP_CLI') && WP_CLI) {
		WP_CLI::success(sprintf('WCOS preflight smoke test passed using %s storage.', $storage_mode));
	}
} finally {
	if ($order_id) {
		$cleanup_order = wc_get_order($order_id);
		if ($cleanup_order) {
			$cleanup_order->delete(true);
		}
	}

	if ($product_id) {
		$cleanup_product = wc_get_product($product_id);
		if ($cleanup_product) {
			$cleanup_product->delete(true);
		}
	}
}
