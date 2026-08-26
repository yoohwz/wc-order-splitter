<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_quantity_step_expect_authority_reason($reason, callable $callback, $message) {
	try {
		$callback();
	} catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
		wcos_p2_adapter_assert($reason === $exception->get_reason(), $message . ' Wrong reason: ' . $exception->get_reason());
		return;
	}
	throw new RuntimeException($message);
}

$quantity_step_previous_user = get_current_user_id();
$quantity_step_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$quantity_step_user_id = 0;
$quantity_step_product_ids = array();
$quantity_step_order_ids = array();
$quantity_step_operation_ids = array();
$quantity_step_map = array();
$quantity_step_filter = static function($step, $product, $context) use (&$quantity_step_map) {
	$product_id = $product instanceof WC_Product ? $product->get_id() : 0;
	return 'edit' === $context && isset($quantity_step_map[$product_id]) ? $quantity_step_map[$product_id] : $step;
};

remove_filter('woocommerce_stock_amount', 'intval');
add_filter('woocommerce_stock_amount', 'floatval');
add_filter('woocommerce_quantity_input_step_admin', $quantity_step_filter, 10, 3);

try {
	$quantity_step_user_id = wp_insert_user(array(
		'user_login' => 'wcos_quantity_step_' . wp_generate_password(8, false),
		'user_pass' => wp_generate_password(24, true),
		'user_email' => 'wcos-quantity-step-' . wp_generate_uuid4() . '@example.test',
		'role' => 'administrator',
	));
	wcos_p2_adapter_assert(!is_wp_error($quantity_step_user_id), 'Unable to create quantity-step test user.');
	wp_set_current_user($quantity_step_user_id);
	update_option('order_splitter_status_allowed', array('wc-pending'));

	$integer_product = wcos_p2_adapter_product('WCOS quantity step integer', '10.00', 20);
	$fractional_product = wcos_p2_adapter_product('WCOS quantity step quarter', '8.00', 20);
	$quantity_step_product_ids[] = $integer_product->get_id();
	$quantity_step_product_ids[] = $fractional_product->get_id();
	$quantity_step_map[$fractional_product->get_id()] = '0.25';

	$mixed = wc_create_order();
	$mixed->set_status('pending');
	$mixed->add_product($integer_product, 4);
	$mixed->add_product($fractional_product, 3.5);
	$mixed->calculate_totals(false);
	$mixed->save();
	$mixed = wc_get_order($mixed->get_id());
	$quantity_step_order_ids[] = $mixed->get_id();
	$mixed_items = array_values($mixed->get_items('line_item'));
	$integer_item_id = $mixed_items[0]->get_product_id() === $integer_product->get_id() ? $mixed_items[0]->get_id() : $mixed_items[1]->get_id();
	$fractional_item_id = $mixed_items[0]->get_product_id() === $fractional_product->get_id() ? $mixed_items[0]->get_id() : $mixed_items[1]->get_id();

	$authority = WCOS_Manual_Split_Quantity_Authority::create($mixed);
	wcos_p2_adapter_assert(1000000 === $authority['lines'][$integer_item_id]['step_units'], 'Integer line did not retain step 1.');
	wcos_p2_adapter_assert(250000 === $authority['lines'][$fractional_item_id]['step_units'], 'Admin edit step 0.25 was not bound.');
	wcos_p2_adapter_assert(3000000 === $authority['lines'][$integer_item_id]['maximum_quantity_units'], 'Integer line maximum did not retain one source step.');
	wcos_p2_adapter_assert(3250000 === $authority['lines'][$fractional_item_id]['maximum_quantity_units'], 'Fractional line maximum did not retain one source step.');

	$accepted = WCOS_Split_Request_Parser::parse_json(
		wp_json_encode(array('child-1' => array($integer_item_id => '1.000000', $fractional_item_id => '0.50'))),
		$mixed,
		$authority
	);
	wcos_p2_adapter_assert('0.500000' === $accepted['child-1'][$fractional_item_id], 'Quarter-step parser did not preserve the canonical decimal.');
	foreach (array('1', '2', '3') as $integer_quantity) {
		$integer_plan = WCOS_Split_Request_Parser::parse_json(
			wp_json_encode(array('child-1' => array($integer_item_id => $integer_quantity))),
			$mixed,
			$authority
		);
		wcos_p2_adapter_assert(
			WCOS_Decimal::normalize($integer_quantity, 6) === $integer_plan['child-1'][$integer_item_id],
			'Default integer line rejected an exact step allocation: ' . $integer_quantity
		);
	}
	foreach (array(
		array($integer_item_id, '0.5'),
		array($integer_item_id, '0.000001'),
		array($fractional_item_id, '0.1'),
		array($fractional_item_id, '0.000001'),
		array($fractional_item_id, '0.30'),
	) as $invalid) {
		$rejected = false;
		try {
			WCOS_Split_Request_Parser::parse_json(
				wp_json_encode(array('child-1' => array($invalid[0] => $invalid[1]))),
				$mixed,
				$authority
			);
		} catch (InvalidArgumentException $exception) {
			$rejected = true;
		}
		wcos_p2_adapter_assert($rejected, 'Parser accepted a quantity outside per-line step authority: ' . $invalid[1]);
	}

	$aggregate_rejected = false;
	try {
		WCOS_Split_Request_Parser::parse_json(
			wp_json_encode(array(
				'child-1' => array($fractional_item_id => '1.750000'),
				'child-2' => array($fractional_item_id => '1.750000'),
			)),
			$mixed,
			$authority
		);
	} catch (InvalidArgumentException $exception) {
		$aggregate_rejected = true;
	}
	wcos_p2_adapter_assert($aggregate_rejected, 'Parser allowed aggregate child allocation to consume the fractional source line.');

	$quantity_step_map[$fractional_product->get_id()] = '0.1';
	$tenth_authority = WCOS_Manual_Split_Quantity_Authority::create($mixed);
	$tenth_plan = WCOS_Split_Request_Parser::parse_json(
		wp_json_encode(array(
			'child-1' => array($fractional_item_id => '0.1'),
			'child-2' => array($fractional_item_id => '0.2'),
		)),
		$mixed,
		$tenth_authority
	);
	wcos_p2_adapter_assert(
		300000 === WCOS_Decimal::to_units($tenth_plan['child-1'][$fractional_item_id], 6)
			+ WCOS_Decimal::to_units($tenth_plan['child-2'][$fractional_item_id], 6),
		'Tenth-step multi-child allocation lost exact decimal-unit arithmetic.'
	);
	$tenth_mismatch_rejected = false;
	try {
		WCOS_Split_Request_Parser::parse_json(
			wp_json_encode(array('child-1' => array($fractional_item_id => '0.25'))),
			$mixed,
			$tenth_authority
		);
	} catch (InvalidArgumentException $exception) {
		$tenth_mismatch_rejected = true;
	}
	wcos_p2_adapter_assert($tenth_mismatch_rejected, 'Tenth-step authority accepted a non-multiple quantity.');
	$quantity_step_map[$fractional_product->get_id()] = '0.25';

	$controller = new WCOS_Split_Admin_Controller();
	$preflight = (new WCOS_Mutation_Gateway())->manual_split_preflight($mixed);
	wcos_p2_adapter_assert(!empty($preflight['supported']), 'Mixed-line Manual Split preflight failed.');
	$html = $controller->dialog_html($mixed, $preflight);
	foreach (array(
		'data-step-units="1000000"', 'data-step-units="250000"',
		'data-maximum-units="3000000"', 'data-maximum-units="3250000"',
		'step="1"', 'step="0.25"', 'max="3"', 'max="3.25"',
		'inputmode="numeric"', 'inputmode="decimal"',
	) as $needle) {
		wcos_p2_adapter_assert(false !== strpos($html, $needle), 'Mixed-line dialog omitted per-line quantity metadata: ' . $needle);
	}
	wcos_p2_adapter_assert(false === strpos($html, 'step="0.000001"'), 'Dialog reintroduced the global generic fractional step.');

	list($single_source, $single_item_id) = wcos_p2_adapter_order($integer_product, 1, 'pending');
	$quantity_step_order_ids[] = $single_source->get_id();
	$single_preflight = (new WCOS_Mutation_Gateway())->manual_split_preflight($single_source);
	$single_html = $controller->dialog_html($single_source, $single_preflight);
	wcos_p2_adapter_assert(false !== strpos($single_html, 'data-splittable="0"'), 'A one-step source line was not marked non-splittable.');
	wcos_p2_adapter_assert(false !== strpos($single_html, 'disabled='), 'A one-step source line retained editable allocation inputs.');

	$drift_preflight = (new WCOS_Mutation_Gateway())->manual_split_preflight($mixed);
	$drift_confirmation = WCOS_Split_Confirmation_Store::create(
		$mixed,
		array('child-1' => array($fractional_item_id => '0.500000')),
		$drift_preflight,
		$quantity_step_user_id
	);
	$quantity_step_operation_ids[] = $drift_confirmation['operation_id'];
	$quantity_step_map[$fractional_product->get_id()] = '0.5';
	$drift_rejected = false;
	try {
		WCOS_Split_Confirmation_Store::verify(
			$mixed,
			$drift_confirmation['operation_id'],
			$drift_confirmation['confirmation_token'],
			$quantity_step_user_id
		);
	} catch (WCOS_Split_Confirmation_Exception $exception) {
		$drift_rejected = 'quantity_authority_changed' === $exception->get_reason();
	}
	wcos_p2_adapter_assert($drift_rejected, 'Step drift after Review did not invalidate confirmation authority.');
	wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get($mixed, $drift_confirmation['operation_id']), 'Step-drift rejection created a mutation journal.');
	$quantity_step_map[$fractional_product->get_id()] = '0.25';

	foreach (array('0', '-0.25', 'not-a-number', '0.0000001', '999999999999999999999999') as $invalid_step) {
		$quantity_step_map[$fractional_product->get_id()] = $invalid_step;
		wcos_quantity_step_expect_authority_reason(
			'0.0000001' === $invalid_step ? 'quantity_step_precision_invalid' : 'quantity_step_invalid',
			static function() use ($mixed) { WCOS_Manual_Split_Quantity_Authority::create($mixed); },
			'Invalid WooCommerce admin quantity step was accepted: ' . $invalid_step
		);
	}
	$quantity_step_map[$fractional_product->get_id()] = '0.3';
	wcos_quantity_step_expect_authority_reason(
		'source_quantity_step_mismatch',
		static function() use ($mixed) { WCOS_Manual_Split_Quantity_Authority::create($mixed); },
		'A source quantity outside the current product quantum was accepted.'
	);
	$quantity_step_map[$fractional_product->get_id()] = '0.25';

	$deleted_integer_product = wcos_p2_adapter_product('WCOS deleted integer step', '5.00');
	$deleted_fractional_product = wcos_p2_adapter_product('WCOS deleted fractional step', '5.00');
	$quantity_step_product_ids[] = $deleted_integer_product->get_id();
	$quantity_step_product_ids[] = $deleted_fractional_product->get_id();
	list($deleted_integer_source) = wcos_p2_adapter_order($deleted_integer_product, 2, 'pending');
	list($deleted_fractional_source) = wcos_p2_adapter_order($deleted_fractional_product, 1.5, 'pending');
	$quantity_step_order_ids[] = $deleted_integer_source->get_id();
	$quantity_step_order_ids[] = $deleted_fractional_source->get_id();
	wp_delete_post($deleted_integer_product->get_id(), true);
	wp_delete_post($deleted_fractional_product->get_id(), true);
	$deleted_integer_authority = WCOS_Manual_Split_Quantity_Authority::create(wc_get_order($deleted_integer_source->get_id()));
	$deleted_integer_lines = array_values($deleted_integer_authority['lines']);
	wcos_p2_adapter_assert(1000000 === $deleted_integer_lines[0]['step_units'], 'Deleted integer product did not fail closed to step 1.');
	wcos_quantity_step_expect_authority_reason(
		'deleted_product_fractional_step_unprovable',
		static function() use ($deleted_fractional_source) { WCOS_Manual_Split_Quantity_Authority::create(wc_get_order($deleted_fractional_source->get_id())); },
		'Deleted fractional product retained unprovable Manual Split authority.'
	);

	list($execute_source, $execute_item_id) = wcos_p2_adapter_order($fractional_product, 3.5, 'pending');
	$execute_source_id = $execute_source->get_id();
	$quantity_step_order_ids[] = $execute_source_id;
	$execute_nonce = wp_create_nonce('wcos_split_order_' . $execute_source_id);
	$stock_before = wc_get_product($fractional_product->get_id())->get_stock_quantity();
	$review = $controller->review_request(array(
		'order_id' => $execute_source_id,
		'nonce' => $execute_nonce,
		'plan' => wp_json_encode(array('child-1' => array($execute_item_id => '0.500000'))),
	));
	$quantity_step_operation_ids[] = $review['operation_id'];
	$result = $controller->execute_request(array(
		'order_id' => $execute_source_id,
		'nonce' => $execute_nonce,
		'operation_id' => $review['operation_id'],
		'confirmation_token' => $review['confirmation_token'],
	));
	wcos_p2_adapter_assert('completed' === $result['status'] && 1 === count($result['children']), 'Fractional-step Manual Split did not complete exactly once.');
	$record = WCOS_Operation_Journal::get(wc_get_order($execute_source_id), $review['operation_id']);
	wcos_p2_adapter_assert(is_array($record) && 'completed' === $record['status'], 'Fractional-step Split journal did not complete.');
	wcos_p2_adapter_assert(250000 === $record['context']['manual_quantity_authority']['lines'][$execute_item_id]['step_units'], 'Split journal lost frozen quarter-step authority.');
	$children = wcos_p2_adapter_children($execute_source_id, $review['operation_id']);
	wcos_p2_adapter_assert(1 === count($children), 'Fractional-step Split produced the wrong child set.');
	$lineage = WCOS_Return_Lineage_Authority::resolve(reset($children));
	wcos_p2_adapter_assert('manual_quantity' === $lineage['strategy'], 'Return lineage rejected the new Manual quantity authority context.');
	wcos_p2_adapter_assert($stock_before == wc_get_product($fractional_product->get_id())->get_stock_quantity(), 'Fractional-step Split changed physical product stock.');

	$quantity_step_map[$fractional_product->get_id()] = '0.5';
	$retry = $controller->execute_request(array(
		'order_id' => $execute_source_id,
		'nonce' => $execute_nonce,
		'operation_id' => $review['operation_id'],
		'confirmation_token' => $review['confirmation_token'],
	));
	wcos_p2_adapter_assert('completed' === $retry['status'] && 1 === count($retry['children']), 'Durable journal replay reinterpreted current quantity-step drift.');
	wcos_p2_adapter_assert(1 === count(wcos_p2_adapter_children($execute_source_id, $review['operation_id'])), 'Durable replay duplicated the child set.');
} finally {
	remove_filter('woocommerce_quantity_input_step_admin', $quantity_step_filter, 10);
	remove_filter('woocommerce_stock_amount', 'floatval');
	add_filter('woocommerce_stock_amount', 'intval');
	foreach ($quantity_step_operation_ids as $operation_id) {
		WCOS_Split_Confirmation_Store::delete($operation_id);
	}
	foreach (array_reverse(array_unique(array_map('absint', $quantity_step_order_ids))) as $order_id) {
		$order = wc_get_order($order_id);
		if ($order instanceof WC_Order) {
			foreach (WCOS_Order_Relation_Repository::find(array(array('key' => WCOS_Split_Order_Service::RELATION_PARENT_META, 'value' => $order_id, 'type' => 'NUMERIC')), -1) as $child) {
				if ($child instanceof WC_Order) {
					$child->delete(true);
				}
			}
			$order->delete(true);
		}
	}
	foreach (array_unique(array_map('absint', $quantity_step_product_ids)) as $product_id) {
		if ($product_id) {
			wp_delete_post($product_id, true);
		}
	}
	update_option('order_splitter_status_allowed', $quantity_step_allowed_statuses);
	wp_set_current_user($quantity_step_previous_user);
	if ($quantity_step_user_id && !is_wp_error($quantity_step_user_id)) {
		if (!function_exists('wp_delete_user')) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user($quantity_step_user_id);
	}
}

echo "p2-manual-quantity-step-authority-ok\n";
