<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_merge_service_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_merge_service_product($name, $managed = true, $backorders = false, $stock = '40') {
	$product = new WC_Product_Simple();
	$product->set_name($name);
	$product->set_regular_price('10.00');
	$product->set_price('10.00');
	$product->set_manage_stock($managed);
	if ($managed) {
		$product->set_stock_quantity($stock);
		$product->set_backorders($backorders ? 'yes' : 'no');
	}
	wcos_merge_service_assert($product->save() > 0, 'Unable to save a Merge service product fixture.');
	return $product;
}

function wcos_merge_service_parent_managed_variation() {
	$parent = new WC_Product_Variable();
	$parent->set_name('Merge parent-managed variable');
	$parent->set_manage_stock(true);
	$parent->set_stock_quantity('31.5');
	wcos_merge_service_assert($parent->save() > 0, 'Unable to save the parent-managed Merge fixture.');

	$variation = new WC_Product_Variation();
	$variation->set_parent_id($parent->get_id());
	$variation->set_regular_price('10.00');
	$variation->set_price('10.00');
	$variation->set_manage_stock(false);
	wcos_merge_service_assert($variation->save() > 0, 'Unable to save the Merge variation fixture.');
	return array($parent, $variation);
}

function wcos_merge_service_order(WC_Product $product, $email, array $reduced_markers, $shipping = false, $target_amount = false) {
	$order = wc_create_order();
	$order->set_status('pending');
	$order->set_currency('USD');
	$order->set_prices_include_tax(false);
	$order->set_customer_id(0);
	$order->set_billing_first_name('Merge');
	$order->set_billing_last_name('Service');
	$order->set_billing_email($email);
	$order->set_billing_phone('+1 555 0142');
	$order->set_billing_address_1('42 Merge Service Way');
	$order->set_billing_city('Testville');
	$order->set_billing_state('CA');
	$order->set_billing_postcode('90001');
	$order->set_billing_country('US');
	$order->set_shipping_first_name('Merge');
	$order->set_shipping_last_name('Service');
	$order->set_shipping_address_1('42 Merge Service Way');
	$order->set_shipping_city('Testville');
	$order->set_shipping_state('CA');
	$order->set_shipping_postcode('90001');
	$order->set_shipping_country('US');
	$order->set_payment_method('cod');
	$order->set_payment_method_title('Cash on delivery');

	foreach ($reduced_markers as $index => $marker) {
		$item = new WC_Order_Item_Product();
		$item->set_name($target_amount ? 'Existing target line' : 'Identical historical source line');
		if ($product instanceof WC_Product_Variation) {
			$item->set_product_id($product->get_parent_id());
			$item->set_variation_id($product->get_id());
		} else {
			$item->set_product_id($product->get_id());
		}
		$item->set_quantity($target_amount ? '1' : ('fractional' === $marker ? '1.5' : '1'));
		$item->set_subtotal($target_amount ? '5.00' : '10.00');
		$item->set_total($target_amount ? '5.00' : '10.00');
		$item->set_subtotal_tax($target_amount ? '0.50' : '1.00');
		$item->set_total_tax($target_amount ? '0.50' : '1.00');
		$item->set_taxes(array(
			'subtotal' => array(701 => $target_amount ? '0.50' : '1.00'),
			'total' => array(701 => $target_amount ? '0.50' : '1.00'),
		));
		$item->add_meta_data('Merge fixture', 'preserved-business-value', true);
		if (null !== $marker) {
			$item->add_meta_data('_reduced_stock', 'fractional' === $marker ? '1.5' : WCOS_Decimal::normalize($marker, 6), true);
		}
		$order->add_item($item);
	}

	if ($shipping) {
		$shipping_item = new WC_Order_Item_Shipping();
		$shipping_item->set_method_title('Supported target shipping');
		$shipping_item->set_method_id('flat_rate');
		$shipping_item->set_instance_id(9);
		$shipping_item->set_total('3.00');
		$shipping_item->set_total_tax('0.30');
		$shipping_item->set_taxes(array('total' => array(701 => '0.30')));
		$shipping_item->add_meta_data('Delivery window', 'morning', true);
		$order->add_item($shipping_item);
	}

	$cart_tax = count($reduced_markers) * ($target_amount ? 0.5 : 1.0);
	$tax = new WC_Order_Item_Tax();
	$tax->set_rate_id(701);
	$tax->set_label('Frozen historical rate');
	$tax->set_compound(false);
	$tax->set_rate_percent(10);
	$tax->set_tax_total(wc_format_decimal($cart_tax, 2));
	$tax->set_shipping_tax_total($shipping ? '0.30' : '0.00');
	$order->add_item($tax);
	WCOS_Order_Totals_Rebuilder::rebuild($order, 2);
	$order->save();
	$order->get_data_store()->set_stock_reduced($order->get_id(), !empty(array_filter($reduced_markers, static function($value) {
		return null !== $value;
	})));
	return wc_get_order($order->get_id());
}

function wcos_merge_service_pair(WC_Product $product, $label, array $source_markers = array('1'), $shipping = false) {
	$email = 'merge-service-' . $label . '-' . wp_generate_uuid4() . '@example.test';
	return array(
		wcos_merge_service_order($product, $email, $source_markers, false, false),
		wcos_merge_service_order($product, $email, array(null), $shipping, true),
	);
}

function wcos_merge_service_cleanup(WC_Order $source, WC_Order $target, $operation_id) {
	$fresh_source = wc_get_order($source->get_id());
	if ($fresh_source instanceof WC_Order) {
		WCOS_Operation_Journal::delete($fresh_source, $operation_id);
		$fresh_source->delete(true);
	}
	$fresh_target = wc_get_order($target->get_id());
	if ($fresh_target instanceof WC_Order) {
		$fresh_target->delete(true);
	}
}

function wcos_merge_service_status(WC_Order $source, $operation_id) {
	$record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
	return is_array($record) && isset($record['status']) ? sanitize_key((string) $record['status']) : '';
}

function wcos_merge_service_assert_manual_pair(WC_Order $source, WC_Order $target, $operation_id) {
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_service_assert('manual_reconciliation' === wcos_merge_service_status($source, $operation_id), 'Immutable drift did not enter manual reconciliation.');
	wcos_merge_service_assert(in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($source), true), 'Source lacks pair-wide manual authority.');
	wcos_merge_service_assert(in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($target), true), 'Target lacks pair-wide manual authority.');
}

$manage_stock_before = get_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_manage_stock', 'yes');
$products = array();

try {
	$managed = wcos_merge_service_product('Merge managed fixture');
	$products[] = $managed;

	/* Gateway stays hard-off while direct adapter/service acceptance is executable. */
	list($gate_source, $gate_target) = wcos_merge_service_pair($managed, 'gate');
	$gate_operation = 'merge-gate-' . wp_generate_uuid4();
	$gate_rejected = false;
	try {
		(new WCOS_Mutation_Gateway())->merge($gate_source, $gate_target, $gate_operation, 2);
	} catch (RuntimeException $exception) {
		$gate_rejected = true;
	}
	wcos_merge_service_assert($gate_rejected, 'MERGE=false did not stop the gateway before delegation.');
	wcos_merge_service_assert(null === WCOS_Operation_Journal::get($gate_source, $gate_operation), 'Hard-off gateway created journal state.');
	$gate_source->delete(true);
	$gate_target->delete(true);

	/* Success: identical source lines stay distinct, historical tax/shipping survive, retry is stable. */
	list($source, $target) = wcos_merge_service_pair($managed, 'success', array('1.5', '2'), true);
	$operation_id = 'merge-service-success-' . wp_generate_uuid4();
	$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
	$source_line_ids = array_map('intval', array_keys($source->get_items('line_item')));
	$shipping_before = WCOS_Merge_Recovery_Snapshot::participant_checkpoint($target)['order_props']['shipping_total'];
	$result = (new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	$retry = (new WCOS_Merge_WooCommerce_Adapter())->merge(wc_get_order($source->get_id()), wc_get_order($target->get_id()), $operation_id, 2);
	wcos_merge_service_assert($result === $retry, 'Completed Merge retry did not return the stable result.');
	wcos_merge_service_assert('completed' === $result['status'], 'Merge service did not complete.');
	wcos_merge_service_assert('non_force_trash_archive' === $result['retirement_policy'], 'Result lost the binding retirement policy.');
	wcos_merge_service_assert(2 === count($result['target_item_ids']), 'Merge did not create one fresh target line per source line.');
	wcos_merge_service_assert(2 === count(array_unique($result['target_item_ids'])), 'Merge coalesced or reused a target line.');
	wcos_merge_service_assert(empty(array_intersect($source_line_ids, $result['target_item_ids'])), 'Merge re-parented a persisted source item.');
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_service_assert('trash' === $source->get_status(), 'Approved non-force retirement did not trash the source.');
	wcos_merge_service_assert($shipping_before === WCOS_Merge_Recovery_Snapshot::participant_checkpoint($target)['order_props']['shipping_total'], 'Supported target shipping total changed.');
	wcos_merge_service_assert(3 === count($target->get_items('line_item')), 'Target line count is not additive.');
	wcos_merge_service_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($target), 'Successful Merge changed physical stock.');
	foreach ($source->get_items('line_item') as $item) {
		wcos_merge_service_assert('' === (string) $item->get_meta('_reduced_stock', true), 'Retired source retained stock ownership.');
	}
	$target_reduced = array();
	foreach ($result['target_item_ids'] as $item_id) {
		$target_reduced[] = WCOS_Decimal::normalize($target->get_item($item_id)->get_meta('_reduced_stock', true), 6);
	}
	sort($target_reduced, SORT_STRING);
	wcos_merge_service_assert(array('1.500000', '2.000000') === $target_reduced, 'Target did not receive exact line stock ownership.');
	wcos_merge_service_cleanup($source, $target, $operation_id);

	/* Real partial ownership crash: first source write and checkpoint are durable before the second boundary. */
	list($source, $target) = wcos_merge_service_pair($managed, 'partial-ownership', array('1', '2'));
	$operation_id = 'merge-service-partial-' . wp_generate_uuid4();
	$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
	$ownership_boundaries = 0;
	$partial_observed = false;
	$partial_crash = static function($stage, $event_source) use (&$ownership_boundaries, &$partial_observed) {
		if ('before_source_line_ownership_write' !== $stage) {
			return;
		}
		$ownership_boundaries++;
		if (2 !== $ownership_boundaries) {
			return;
		}
		$markers = array_map(static function($item) {
			return (string) $item->get_meta('_reduced_stock', true);
		}, array_values(wc_get_order($event_source->get_id())->get_items('line_item')));
		$partial_observed = 1 === count(array_filter($markers, static function($marker) { return '' === $marker; }))
			&& 1 === count(array_filter($markers, static function($marker) { return '' !== $marker; }));
		throw new WCOS_Merge_Recovery_Interruption_Exception('Injected crash after one durable ownership checkpoint.');
	};
	add_action('wcos_merge_mutation_checkpoint', $partial_crash, 10, 4);
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	} catch (Throwable $throwable) {
		/* The durable journal outcome is asserted below. */
	}
	remove_action('wcos_merge_mutation_checkpoint', $partial_crash, 10);
	wcos_merge_service_assert($partial_observed, 'Crash fixture did not observe a genuinely partial durable source ownership migration.');
	wcos_merge_service_assert('compensated' === wcos_merge_service_status($source, $operation_id), 'Partial ownership crash did not compensate safely.');
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_service_assert(2 === count($target->get_items('line_item')), 'Compensation did not remove operation-owned target lines.');
	foreach ($source->get_items('line_item') as $item) {
		wcos_merge_service_assert('' !== (string) $item->get_meta('_reduced_stock', true), 'Compensation did not restore source stock ownership.');
	}
	wcos_merge_service_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($source), 'Partial ownership recovery changed physical stock.');
	$retry_failed_closed = false;
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	} catch (WCOS_Merge_Adapter_Exception $exception) {
		$retry_failed_closed = 'merge_compensated' === $exception->get_error_code();
	}
	wcos_merge_service_assert($retry_failed_closed, 'Compensated operation retry did not fail closed with a stable code.');
	wcos_merge_service_cleanup($source, $target, $operation_id);

	/* A response-loss crash between reciprocal relation writes resumes forward on retry. */
	list($source, $target) = wcos_merge_service_pair($managed, 'relation-retry');
	$operation_id = 'merge-service-relation-' . wp_generate_uuid4();
	$relation_once = true;
	$relation_crash = static function($stage) use (&$relation_once) {
		if ($relation_once && 'after_one_reciprocal_relation' === $stage) {
			$relation_once = false;
			throw new WCOS_Merge_Recovery_Interruption_Exception('Injected reciprocal relation crash.');
		}
	};
	add_action('wcos_merge_recovery_checkpoint', $relation_crash, 10, 4);
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	} catch (Throwable $throwable) {
		/* Retry after removing the injected process-loss boundary. */
	}
	remove_action('wcos_merge_recovery_checkpoint', $relation_crash, 10);
	$result = (new WCOS_Merge_WooCommerce_Adapter())->merge(wc_get_order($source->get_id()), wc_get_order($target->get_id()), $operation_id, 2);
	wcos_merge_service_assert('completed' === $result['status'], 'Forward relation recovery did not complete on retry.');
	wcos_merge_service_cleanup($source, $target, $operation_id);

	/* Independent immutable-context drifts fail closed before automatic recovery writes and remain untouched. */
	$drift_cases = array(
		'source_billing' => static function(WC_Order $source, WC_Order $target) {
			$source->set_billing_address_1('Externally changed billing address');
			$source->save();
		},
		'target_payment' => static function(WC_Order $source, WC_Order $target) {
			$target->set_payment_method_title('Externally changed payment title');
			$target->save();
		},
		'target_shipping_item' => static function(WC_Order $source, WC_Order $target) {
			$shipping_items = array_values($target->get_items('shipping'));
			$shipping = $shipping_items[0];
			$shipping->set_method_title('Externally changed shipping method');
			$shipping->save();
			$line_items = array_values($target->get_items('line_item'));
			$line = $line_items[0];
			$line->set_name('Externally changed target item');
			$line->update_meta_data('Merge fixture', 'external-item-state');
			$line->save();
		},
	);
	foreach ($drift_cases as $label => $mutate) {
		list($source, $target) = wcos_merge_service_pair($managed, 'drift-' . $label, array('1'), 'target_shipping_item' === $label);
		$operation_id = 'merge-service-drift-' . $label . '-' . wp_generate_uuid4();
		$source_lines_before = array_values($source->get_items('line_item'));
		$source_quantity = (string) $source_lines_before[0]->get_quantity();
		$target_total_at_drift = '';
		$drift_once = true;
		$drift = static function($stage, $event_source, $event_target) use (&$drift_once, &$target_total_at_drift, $mutate) {
			if ($drift_once && 'after_target_money_tax_persistence' === $stage) {
				$drift_once = false;
				$fresh_target = wc_get_order($event_target->get_id());
				$target_total_at_drift = (string) $fresh_target->get_total();
				$mutate(wc_get_order($event_source->get_id()), $fresh_target);
				throw new WCOS_Merge_Recovery_Interruption_Exception('Injected immutable participant drift.');
			}
		};
		add_action('wcos_merge_mutation_checkpoint', $drift, 10, 4);
		try {
			(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
		} catch (Throwable $throwable) {
			/* Manual state is asserted after the recovery coordinator returns. */
		}
		remove_action('wcos_merge_mutation_checkpoint', $drift, 10);
		wcos_merge_service_assert_manual_pair($source, $target, $operation_id);
		$fresh_source = wc_get_order($source->get_id());
		$fresh_target = wc_get_order($target->get_id());
		$fresh_source_lines = array_values($fresh_source->get_items('line_item'));
		wcos_merge_service_assert($source_quantity === (string) $fresh_source_lines[0]->get_quantity(), 'Immutable drift fixture changed source quantity.');
		if ('source_billing' === $label) {
			wcos_merge_service_assert('Externally changed billing address' === $fresh_source->get_billing_address_1(), 'Recovery overwrote external billing state.');
		} elseif ('target_payment' === $label) {
			wcos_merge_service_assert('Externally changed payment title' === $fresh_target->get_payment_method_title(), 'Recovery overwrote external payment state.');
		} else {
			$fresh_shipping_items = array_values($fresh_target->get_items('shipping'));
			$fresh_target_lines = array_values($fresh_target->get_items('line_item'));
			wcos_merge_service_assert('Externally changed shipping method' === $fresh_shipping_items[0]->get_method_title(), 'Recovery overwrote external target shipping state.');
			wcos_merge_service_assert('Externally changed target item' === $fresh_target_lines[0]->get_name(), 'Recovery overwrote external target item state.');
		}
		wcos_merge_service_assert($target_total_at_drift === (string) $fresh_target->get_total(), 'Immutable-context recovery overwrote target totals.');
		wcos_merge_service_cleanup($fresh_source, $fresh_target, $operation_id);
	}

	/* Service-level stock matrix: unmanaged, backorder, fractional, parent-managed variation, and deleted product. */
	$unmanaged = wcos_merge_service_product('Merge unmanaged fixture', false);
	$backorder = wcos_merge_service_product('Merge backorder fixture', true, true, '-2.5');
	list($parent, $variation) = wcos_merge_service_parent_managed_variation();
	$deleted = wcos_merge_service_product('Merge deleted-product fixture');
	$products = array_merge($products, array($unmanaged, $backorder, $variation, $parent));
	$stock_cases = array(
		'unmanaged' => array($unmanaged, array(null), false),
		'backorder_fractional' => array($backorder, array('fractional'), false),
		'parent_managed_variation' => array($variation, array('1'), false),
		'deleted_product' => array($deleted, array('1'), true),
	);
	foreach ($stock_cases as $label => $case) {
		list($case_product, $markers, $delete_product) = $case;
		list($source, $target) = wcos_merge_service_pair($case_product, 'stock-' . $label, $markers);
		$operation_id = 'merge-service-stock-' . $label . '-' . wp_generate_uuid4();
		if ($delete_product) {
			$case_product->delete(true);
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
		}
		$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
		$result = (new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
		wcos_merge_service_assert('completed' === $result['status'], 'Stock matrix Merge did not complete: ' . $label);
		$fresh_target = wc_get_order($target->get_id());
		wcos_merge_service_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($fresh_target), 'Stock matrix changed physical stock: ' . $label);
		wcos_merge_service_cleanup(wc_get_order($source->get_id()), $fresh_target, $operation_id);
	}
} finally {
	update_option('woocommerce_manage_stock', $manage_stock_before);
	foreach ($products as $product) {
		if ($product instanceof WC_Product && $product->get_id() && wc_get_product($product->get_id())) {
			$product->delete(true);
		}
	}
}

echo "merge-service-adapter-ok\n";
