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
	wcos_p2_adapter_assert(WCOS_Manual_Split_Quantity_Authority::POLICY_VERSION === $authority['policy_version'], 'Manual quantity Review did not emit the current policy version.');
	wcos_p2_adapter_assert(4000000 === $authority['lines'][$integer_item_id]['maximum_quantity_units'], 'Integer line maximum did not allow whole-line allocation.');
	wcos_p2_adapter_assert(3500000 === $authority['lines'][$fractional_item_id]['maximum_quantity_units'], 'Fractional line maximum did not allow whole-line allocation.');
	wcos_p2_adapter_assert(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER === WCOS_Manual_Split_Quantity_Authority::execution_policy($authority), 'Current Manual authority did not derive whole-line execution policy.');
	$policy_mismatch_operation = 'manual-policy-mismatch-' . wp_generate_uuid4();
	$policy_mismatch_rejected = false;
	try {
		(new WCOS_Split_WooCommerce_Adapter())->split(
			$mixed,
			array('child-1' => array($integer_item_id => '1.000000')),
			$policy_mismatch_operation,
			2,
			WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY,
			array('manual_quantity_authority' => $authority)
		);
	} catch (InvalidArgumentException $exception) {
		$policy_mismatch_rejected = false !== strpos($exception->getMessage(), 'does not match');
	}
	wcos_p2_adapter_assert($policy_mismatch_rejected, 'Current Manual authority was accepted under browser-style partial-only policy mismatch.');
	wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get($mixed, $policy_mismatch_operation), 'Manual policy mismatch created a mutation journal.');

	$accepted = WCOS_Split_Request_Parser::parse_json(
		wp_json_encode(array('child-1' => array($integer_item_id => '1.000000', $fractional_item_id => '0.50'))),
		$mixed,
		$authority
	);
	wcos_p2_adapter_assert('0.500000' === $accepted['child-1'][$fractional_item_id], 'Quarter-step parser did not preserve the canonical decimal.');
	foreach (array('1', '2', '3', '4') as $integer_quantity) {
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

	$whole_fractional = WCOS_Split_Request_Parser::parse_json(
		wp_json_encode(array(
			'child-1' => array($fractional_item_id => '1.750000'),
			'child-2' => array($fractional_item_id => '1.750000'),
		)),
		$mixed,
		$authority
	);
	wcos_p2_adapter_assert('1.750000' === $whole_fractional['child-2'][$fractional_item_id], 'Parser rejected whole-line fractional allocation while another source line remains.');
	$aggregate_rejected = false;
	try {
		WCOS_Split_Request_Parser::parse_json(
			wp_json_encode(array(
				'child-1' => array($integer_item_id => '4.000000', $fractional_item_id => '1.750000'),
				'child-2' => array($fractional_item_id => '1.750000'),
			)),
			$mixed,
			$authority
		);
	} catch (InvalidArgumentException $exception) {
		$aggregate_rejected = true;
	}
	wcos_p2_adapter_assert($aggregate_rejected, 'Parser allowed aggregate child allocation to empty the source order.');

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
		'data-policy-version="2"', 'data-maximum-units="4000000"', 'data-maximum-units="3500000"',
		'step="1"', 'step="0.25"', 'max="4"', 'max="3.5"',
		'inputmode="numeric"', 'inputmode="decimal"',
	) as $needle) {
		wcos_p2_adapter_assert(false !== strpos($html, $needle), 'Mixed-line dialog omitted per-line quantity metadata: ' . $needle);
	}
	wcos_p2_adapter_assert(false === strpos($html, 'step="0.000001"'), 'Dialog reintroduced the global generic fractional step.');

	list($single_source, $single_item_id) = wcos_p2_adapter_order($integer_product, 1, 'pending');
	$quantity_step_order_ids[] = $single_source->get_id();
	$single_preflight = (new WCOS_Mutation_Gateway())->manual_split_preflight($single_source);
	wcos_p2_adapter_assert(empty($single_preflight['supported']), 'A single-total-step source order remained Manual-Split-capable.');
	wcos_p2_adapter_assert('manual_quantity_insufficient_allocatable_steps' === $single_preflight['reason'], 'Single-total-step preflight returned the wrong reason.');
	wcos_p2_adapter_assert(!empty($single_preflight['manual_quantity_authority']['lines'][$single_item_id]['can_partially_split']), 'A one-step line remained inherently disabled instead of using order-level eligibility.');

	list($two_step_source, $two_step_item_id) = wcos_p2_adapter_order($integer_product, 2, 'pending');
	$quantity_step_order_ids[] = $two_step_source->get_id();
	$two_step_preflight = (new WCOS_Mutation_Gateway())->manual_split_preflight($two_step_source);
	wcos_p2_adapter_assert(!empty($two_step_preflight['supported']), 'A single line with two allocatable steps was not Manual-Split-capable.');
	WCOS_Split_Request_Parser::parse_json(
		wp_json_encode(array('child-1' => array($two_step_item_id => '1.000000'))),
		wc_get_order($two_step_source->get_id()),
		$two_step_preflight['manual_quantity_authority']
	);

	$two_single_lines = wc_create_order();
	$two_single_lines->set_status('pending');
	$two_single_lines->add_product($integer_product, 1);
	$two_single_lines->add_product($integer_product, 1);
	$two_single_lines->calculate_totals(false);
	$two_single_lines->save();
	$two_single_lines = wc_get_order($two_single_lines->get_id());
	$quantity_step_order_ids[] = $two_single_lines->get_id();
	$two_single_ids = array_keys($two_single_lines->get_items('line_item'));
	$two_single_preflight = (new WCOS_Mutation_Gateway())->manual_split_preflight($two_single_lines);
	wcos_p2_adapter_assert(!empty($two_single_preflight['supported']), 'Two qty1 lines were not Manual-Split-capable.');
	WCOS_Split_Request_Parser::parse_json(
		wp_json_encode(array('child-1' => array($two_single_ids[0] => '1.000000'))),
		wc_get_order($two_single_lines->get_id()),
		$two_single_preflight['manual_quantity_authority']
	);
	$one_line_two_children_rejected = false;
	try {
		WCOS_Split_Request_Parser::parse_json(
			wp_json_encode(array(
				'child-1' => array($two_single_ids[0] => '1.000000'),
				'child-2' => array($two_single_ids[0] => '1.000000'),
			)),
			wc_get_order($two_single_lines->get_id()),
			$two_single_preflight['manual_quantity_authority']
		);
	} catch (InvalidArgumentException $exception) {
		$one_line_two_children_rejected = true;
	}
	wcos_p2_adapter_assert($one_line_two_children_rejected, 'One qty1 line was allocated to two children under step 1.');

	list($fractional_two_step_source, $fractional_two_step_item_id) = wcos_p2_adapter_order($fractional_product, '0.500000', 'pending');
	$quantity_step_order_ids[] = $fractional_two_step_source->get_id();
	$fractional_two_step_preflight = (new WCOS_Mutation_Gateway())->manual_split_preflight($fractional_two_step_source);
	wcos_p2_adapter_assert(!empty($fractional_two_step_preflight['supported']), 'One fractional line with two 0.25 steps was not Manual-Split-capable.');
	WCOS_Split_Request_Parser::parse_json(
		wp_json_encode(array('child-1' => array($fractional_two_step_item_id => '0.250000'))),
		wc_get_order($fractional_two_step_source->get_id()),
		$fractional_two_step_preflight['manual_quantity_authority']
	);

	$reported = wc_create_order();
	$reported->set_status('pending');
	$reported->add_product($integer_product, 1);
	$reported->add_product($integer_product, 2);
	$reported->add_product($integer_product, 1);
	$reported->calculate_totals(false);
	$reported->save();
	$reported = wc_get_order($reported->get_id());
	$quantity_step_order_ids[] = $reported->get_id();
	$reported_ids = array_keys($reported->get_items('line_item'));
	$reported_preflight = (new WCOS_Mutation_Gateway())->manual_split_preflight($reported);
	wcos_p2_adapter_assert(!empty($reported_preflight['supported']), 'Reported 1 + 2 + 1 order failed Manual preflight.');
	$reported_html = $controller->dialog_html($reported, $reported_preflight);
	wcos_p2_adapter_assert(3 === substr_count($reported_html, 'data-splittable="1"'), 'Reported 1 + 2 + 1 dialog did not enable all three rows.');
	wcos_p2_adapter_assert(false === strpos($reported_html, 'No partial quantity can move while retaining one step.'), 'Reported dialog retained obsolete per-line residual copy.');
	foreach ($reported_preflight['manual_quantity_authority']['lines'] as $line) {
		wcos_p2_adapter_assert(!empty($line['can_partially_split']), 'Reported 1 + 2 + 1 order left a row disabled.');
		wcos_p2_adapter_assert($line['source_quantity_units'] === $line['maximum_quantity_units'], 'Reported order row did not permit whole-line allocation.');
	}
	WCOS_Split_Request_Parser::parse_json(
		wp_json_encode(array('child-1' => array($reported_ids[1] => '2.000000'))),
		wc_get_order($reported->get_id()),
		$reported_preflight['manual_quantity_authority']
	);
	WCOS_Split_Request_Parser::parse_json(
		wp_json_encode(array(
			'child-1' => array($reported_ids[0] => '1.000000'),
			'child-2' => array($reported_ids[1] => '1.000000'),
		)),
		wc_get_order($reported->get_id()),
		$reported_preflight['manual_quantity_authority']
	);
	$reported_nonce = wp_create_nonce('wcos_split_order_' . $reported->get_id());
	$reported_review = $controller->review_request(array(
		'order_id' => $reported->get_id(),
		'nonce' => $reported_nonce,
		'plan' => wp_json_encode(array('child-1' => array($reported_ids[0] => '1.000000'))),
	));
	$quantity_step_operation_ids[] = $reported_review['operation_id'];
	$reported_result = $controller->execute_request(array(
		'order_id' => $reported->get_id(),
		'nonce' => $reported_nonce,
		'operation_id' => $reported_review['operation_id'],
		'confirmation_token' => $reported_review['confirmation_token'],
	));
	wcos_p2_adapter_assert('completed' === $reported_result['status'], 'Reported qty1 whole-line Manual Split did not complete.');
	$reported_source = wc_get_order($reported->get_id());
	wcos_p2_adapter_assert(!$reported_source->get_item($reported_ids[0]) && 2 === count($reported_source->get_items('line_item')), 'Reported qty1 line was not removed while preserving source-order residual.');
	$reported_record = WCOS_Operation_Journal::get($reported_source, $reported_review['operation_id']);
	wcos_p2_adapter_assert(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER === $reported_record['context']['execution_policy'], 'Reported Manual operation did not journal whole-line policy.');
	$reported_children = wcos_p2_adapter_children($reported->get_id(), $reported_review['operation_id']);
	$reported_lineage = WCOS_Return_Lineage_Authority::resolve(reset($reported_children));
	wcos_p2_adapter_assert('manual_quantity' === $reported_lineage['strategy'], 'Whole-line Manual child lost Return strategy lineage.');
	wcos_p2_adapter_assert(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER === $reported_lineage['execution_policy'], 'Whole-line Manual child lost Return execution-policy lineage.');

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
	wcos_p2_adapter_assert(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER === $record['context']['execution_policy'], 'Current Manual Split did not bind whole-line policy in the durable journal.');
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
