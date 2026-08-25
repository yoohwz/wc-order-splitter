<?php

if (!defined('ABSPATH')) { exit(1); }

function wcos_return_service_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_return_service_observed_execute(WC_Order $child, $operation_id, $precision) {
	$hooks = array(
		'wp_trash_post', 'woocommerce_email_before_order_table', 'woocommerce_webhook_delivery',
		'woocommerce_analytics_update_order_stats', 'woocommerce_product_before_set_stock',
		'woocommerce_product_set_stock', 'woocommerce_new_order_note',
	);
	$counts = array_fill_keys($hooks, 0);
	$observer = static function() use (&$counts) { $hook = current_filter(); if (isset($counts[$hook])) { $counts[$hook]++; } };
	foreach ($hooks as $hook) { add_action($hook, $observer, PHP_INT_MAX, 10); }
	try { $result = (new WCOS_Return_WooCommerce_Adapter())->return_order($child, $operation_id, $precision); }
	finally { foreach ($hooks as $hook) { remove_action($hook, $observer, PHP_INT_MAX); } }
	wcos_return_service_assert($counts['wp_trash_post'] <= 1 && $counts['woocommerce_analytics_update_order_stats'] <= 1, 'Return emitted duplicate retirement/analytics transitions.');
	foreach (array('woocommerce_email_before_order_table', 'woocommerce_webhook_delivery', 'woocommerce_product_before_set_stock', 'woocommerce_product_set_stock', 'woocommerce_new_order_note') as $silent_hook) {
		wcos_return_service_assert(0 === $counts[$silent_hook], 'Return emitted an unapproved side effect: ' . $silent_hook);
	}
	return array($result, $counts);
}

$GLOBALS['wcos_return_service_fixtures'] = array();
$wcos_return_private_business = static function($classification, $key) {
	return '_return_private_configuration' === (string) $key ? WCOS_Order_Item_Meta_Policy::CLASS_BUSINESS : $classification;
};
add_filter('wcos_order_item_meta_classification', $wcos_return_private_business, 10, 2);

function wcos_return_service_fixture($label, $whole_line_strategy = '') {
	$whole_line_strategy = true === $whole_line_strategy ? WCOS_Split_Strategy_Gates::CATEGORY : sanitize_key((string) $whole_line_strategy);
	$whole_line = in_array($whole_line_strategy, array(WCOS_Split_Strategy_Gates::CATEGORY, WCOS_Split_Strategy_Gates::STOCK_STATUS), true);
	$product = new WC_Product_Simple();
	$product->set_name('WCOS Return service ' . $label);
	$product->set_regular_price('10.00'); $product->set_price('10.00');
	$product->set_manage_stock(true); $product->set_stock_quantity(40); $product->set_backorders('yes');
	wcos_return_service_assert($product->save() > 0, 'Return service product fixture could not be saved.');

	$original = wc_create_order();
	$original->set_status('pending'); $original->set_currency('USD'); $original->set_prices_include_tax(false);
	$item = new WC_Order_Item_Product();
	$item->set_name('Exact historical Return line'); $item->set_product_id($product->get_id());
	$item->set_quantity(2); $item->set_subtotal('20.00'); $item->set_total('18.00');
	$item->set_subtotal_tax('2.00'); $item->set_total_tax('1.80');
	$item->set_taxes(array('subtotal' => array(901 => '2.00'), 'total' => array(901 => '1.80')));
	$item->add_meta_data('Configured choice', 'preserved', true);
	$item->add_meta_data('_return_private_configuration', 'private-business-preserved', true);
	$item->add_meta_data('_reduced_stock', '2.000000', true);
	$original->add_item($item);
	$unrelated_item_id = 0;
	if ($whole_line) {
		$unrelated = new WC_Order_Item_Product();
		$unrelated->set_name('Unrelated exact-identity line'); $unrelated->set_product_id($product->get_id());
		$unrelated->set_quantity(1); $unrelated->set_subtotal('5.00'); $unrelated->set_total('5.00');
		$unrelated->set_subtotal_tax('0.50'); $unrelated->set_total_tax('0.50');
		$unrelated->set_taxes(array('subtotal' => array(901 => '0.50'), 'total' => array(901 => '0.50')));
		$unrelated->add_meta_data('Configured choice', 'unrelated', true); $unrelated->add_meta_data('_reduced_stock', '1.000000', true);
		$original->add_item($unrelated);
	}
	$tax = new WC_Order_Item_Tax(); $tax->set_rate_id(901); $tax->set_label('Frozen Return rate');
	$tax->set_tax_total($whole_line ? '2.30' : '1.80'); $tax->set_shipping_tax_total('0.00'); $original->add_item($tax);
	WCOS_Order_Totals_Rebuilder::rebuild($original, 2); $original->save();
	if ($whole_line) { $unrelated_item_id = $unrelated->get_id(); }
	$original->get_data_store()->set_stock_reduced($original->get_id(), true);
	$stock_before = WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6);
	$split_operation = 'return-service-split-' . wp_generate_uuid4();
	if ($whole_line) {
		$source = wc_get_order($original->get_id());
		$children = (new WCOS_Split_WooCommerce_Adapter())->split(
			$source,
			array('return-child' => array($item->get_id() => '2.000000')),
			$split_operation,
			2,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			array('strategy_authority' => array(
				'strategy' => $whole_line_strategy,
				'planner_policy_version' => WCOS_Split_Strategy_Gates::CATEGORY === $whole_line_strategy ? WCOS_Category_Split_Planner::POLICY_VERSION : WCOS_Stock_Status_Split_Planner::POLICY_VERSION,
				'classification_fingerprint' => hash('sha256', 'return-service-frozen-' . $whole_line_strategy),
				'source_bucket_key' => $whole_line_strategy . '-return-service-source',
				'review_source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
			))
		);
	} else {
		$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), array(
			'return-child' => array($item->get_id() => '1.000000'),
		), $split_operation, 2);
	}
	wcos_return_service_assert(1 === count($children), 'Return service fixture Split did not create exactly one child.');
	$fixture = array(
		'product_id' => $product->get_id(), 'original_id' => $original->get_id(), 'child_id' => $children[0]->get_id(),
		'source_item_id' => $item->get_id(), 'unrelated_item_id' => $unrelated_item_id,
		'split_operation' => $split_operation, 'stock_before' => $stock_before,
	);
	$GLOBALS['wcos_return_service_fixtures'][] = $fixture;
	return $fixture;
}

function wcos_return_service_cleanup(array $fixture, $return_operation = '') {
	$child_ids = isset($fixture['child_ids']) ? (array) $fixture['child_ids'] : array($fixture['child_id']);
	$children = array(); foreach ($child_ids as $child_id) { $candidate = wc_get_order(absint($child_id)); if ($candidate instanceof WC_Order) { $children[] = $candidate; } }
	$child = !empty($children) ? reset($children) : false; $original = wc_get_order($fixture['original_id']);
	foreach ($child_ids as $child_id) { delete_option('wcos_manual_reconcile_block_' . absint($child_id)); }
	delete_option('wcos_manual_reconcile_block_' . $fixture['original_id']);
	foreach (array_merge($children, array($original)) as $order) {
		if (!$order instanceof WC_Order) { continue; }
		$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
		foreach (is_array($summary) ? $summary : array() as $entry) {
			if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
		}
	}
	if ($child instanceof WC_Order && '' !== $return_operation) { WCOS_Operation_Journal::delete($child, $return_operation); }
	foreach ($children as $child_order) { $child_order->delete(true); }
	if ($original instanceof WC_Order) { $original->delete(true); }
	$product_ids = array_merge(array(isset($fixture['product_id']) ? $fixture['product_id'] : 0), isset($fixture['extra_product_ids']) ? (array) $fixture['extra_product_ids'] : array());
	foreach (array_unique(array_filter(array_map('absint', $product_ids))) as $product_id) {
		$product = wc_get_product($product_id); if ($product instanceof WC_Product) { $product->delete(true); }
	}
}

function wcos_return_service_stock_matrix_case($case) {
	$case = sanitize_key((string) $case);
	$extra_product_ids = array();
	if ('parent_managed_fractional_variation' === $case) {
		$parent = new WC_Product_Variable(); $parent->set_name('WCOS Return service parent');
		$parent->set_manage_stock(true); $parent->set_stock_quantity('31.5'); $parent->set_backorders('yes'); $parent->save();
		$product = new WC_Product_Variation(); $product->set_parent_id($parent->get_id());
		$product->set_regular_price('7.25'); $product->set_price('7.25'); $product->set_manage_stock(false); $product->save();
		$extra_product_ids[] = $parent->get_id(); $quantity = '2.000000'; $split_quantity = '1.000000'; $reduced = '1.500000';
	} else {
		$product = new WC_Product_Simple(); $product->set_name('WCOS Return service ' . $case);
		$product->set_regular_price('8.00'); $product->set_price('8.00');
		$product->set_manage_stock('unmanaged' !== $case);
		if ('unmanaged' !== $case) { $product->set_stock_quantity(19); $product->set_backorders('yes'); }
		$product->save(); $quantity = '2.000000'; $split_quantity = '1.000000'; $reduced = 'unmanaged' === $case ? null : '2.000000';
	}
	$owner_id = method_exists($product, 'get_stock_managed_by_id') ? absint($product->get_stock_managed_by_id()) : absint($product->get_id());
	$owner = wc_get_product($owner_id); $stock_before = $owner instanceof WC_Product && null !== $owner->get_stock_quantity() ? WCOS_Decimal::normalize($owner->get_stock_quantity(), 6) : null;
	$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD');
	$item_id = $original->add_product($product, $quantity); $original->calculate_totals(false); $original->save();
	$item = $original->get_item($item_id); $item->add_meta_data('Matrix identity', $case, true);
	if (null !== $reduced) { $item->add_meta_data('_reduced_stock', $reduced, true); }
	$item->save(); $original->get_data_store()->set_stock_reduced($original->get_id(), null !== $reduced);
	$split_operation = 'return-service-matrix-split-' . wp_generate_uuid4();
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), array('matrix-child' => array($item_id => $split_quantity)), $split_operation, 2);
	wcos_return_service_assert(1 === count($children), 'Return stock matrix Split failed: ' . $case);
	$fixture = array(
		'product_id' => $product->get_id(), 'extra_product_ids' => $extra_product_ids, 'original_id' => $original->get_id(),
		'child_id' => $children[0]->get_id(), 'source_item_id' => $item_id, 'unrelated_item_id' => 0,
		'split_operation' => $split_operation, 'stock_before' => $stock_before,
	);
	$GLOBALS['wcos_return_service_fixtures'][] = $fixture;
	$return_operation = 'return-service-matrix-' . wp_generate_uuid4();
	$unavailable = static function($resolved, $order_item) use ($case, $product) {
		return 'catalog_unavailable' === $case && $order_item instanceof WC_Order_Item_Product
			&& absint($order_item->get_product_id()) === absint($product->get_id()) ? false : $resolved;
	};
	if ('catalog_unavailable' === $case) { add_filter('woocommerce_order_item_product', $unavailable, 10, 2); }
	try { $result = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $return_operation, 2); }
	finally { if ('catalog_unavailable' === $case) { remove_filter('woocommerce_order_item_product', $unavailable, 10); } }
	$original = wc_get_order($fixture['original_id']); $child = wc_get_order($fixture['child_id']); $restored = $original->get_item($item_id);
	wcos_return_service_assert('completed' === $result['status'] && 'trash' === $child->get_status() && $restored instanceof WC_Order_Item_Product, 'Return stock matrix did not complete: ' . $case);
	wcos_return_service_assert($quantity === WCOS_Decimal::normalize($restored->get_quantity(), 6) && $case === $restored->get_meta('Matrix identity', true), 'Return stock matrix changed exact line identity/quantity: ' . $case);
	if ('parent_managed_fractional_variation' === $case) { wcos_return_service_assert($restored->get_variation_id() === $product->get_id(), 'Return changed variation identity.'); }
	if (null === $reduced) {
		wcos_return_service_assert('' === $restored->get_meta('_reduced_stock', true) && !(bool) $original->get_data_store()->get_stock_reduced($original->get_id()), 'Unmanaged Return created stock ownership.');
	} else {
		wcos_return_service_assert($reduced === WCOS_Decimal::normalize($restored->get_meta('_reduced_stock', true), 6), 'Return stock matrix did not conserve reduced ownership: ' . $case);
	}
	$owner = wc_get_product($owner_id);
	$current_stock = $owner instanceof WC_Product && null !== $owner->get_stock_quantity() ? WCOS_Decimal::normalize($owner->get_stock_quantity(), 6) : null;
	wcos_return_service_assert($owner instanceof WC_Product && $stock_before === $current_stock, 'Return changed physical stock: ' . $case);
	wcos_return_service_cleanup($fixture, $return_operation);
	return array('case' => $case, 'status' => 'completed');
}

function wcos_return_service_sibling_case($tamper_after_first) {
	$product = new WC_Product_Simple(); $product->set_name('WCOS Return service siblings');
	$product->set_regular_price('6.00'); $product->set_price('6.00'); $product->set_manage_stock(true);
	$product->set_stock_quantity(27); $product->set_backorders('yes'); $product->save();
	$stock_before = WCOS_Decimal::normalize($product->get_stock_quantity(), 6);
	$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD');
	$item_id = $original->add_product($product, 3); $original->calculate_totals(false); $original->save();
	$item = $original->get_item($item_id); $item->add_meta_data('Sibling identity', 'shared-source', true); $item->add_meta_data('_reduced_stock', '3.000000', true); $item->save();
	$original->get_data_store()->set_stock_reduced($original->get_id(), true);
	$split_operation = 'return-service-siblings-split-' . wp_generate_uuid4();
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), array(
		'sibling-a' => array($item_id => '1.000000'), 'sibling-b' => array($item_id => '1.000000'),
	), $split_operation, 2);
	wcos_return_service_assert(2 === count($children), 'Sibling Return fixture did not create two children.');
	$fixture = array(
		'product_id' => $product->get_id(), 'original_id' => $original->get_id(), 'child_id' => $children[0]->get_id(),
		'child_ids' => array($children[0]->get_id(), $children[1]->get_id()), 'source_item_id' => $item_id,
		'unrelated_item_id' => 0, 'split_operation' => $split_operation, 'stock_before' => $stock_before,
	);
	$GLOBALS['wcos_return_service_fixtures'][] = $fixture;
	$operation_a = 'return-service-sibling-a-' . wp_generate_uuid4();
	$operation_b = 'return-service-sibling-b-' . wp_generate_uuid4();
	$result_a = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($children[0]->get_id()), $operation_a, 2);
	if ($tamper_after_first) {
		$fresh_original = wc_get_order($original->get_id()); $line = $fresh_original->get_item($item_id);
		$line->add_meta_data('Unauthenticated source drift', 'reject', true); $line->save();
		$rejected = false;
		try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($children[1]->get_id()), $operation_b, 2); }
		catch (WCOS_Return_Adapter_Exception $exception) { $rejected = 0 === strpos($exception->get_error_code(), 'return_preflight_'); }
		wcos_return_service_assert($rejected && !is_array(WCOS_Operation_Journal::get(wc_get_order($children[1]->get_id()), $operation_b)), 'Unauthenticated sibling source drift did not fail closed before journaling.');
		wcos_return_service_cleanup($fixture);
		return array('case' => 'stale_sibling_authority', 'status' => 'rejected');
	}
	$result_b = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($children[1]->get_id()), $operation_b, 2);
	$fresh_original = wc_get_order($original->get_id()); $restored = $fresh_original->get_item($item_id);
	wcos_return_service_assert('completed' === $result_a['status'] && 'completed' === $result_b['status'] && '3.000000' === WCOS_Decimal::normalize($restored->get_quantity(), 6), 'Sequential sibling Returns did not restore the complete source line.');
	wcos_return_service_assert(empty((array) $fresh_original->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true)), 'Sequential sibling Returns left active Split children.');
	wcos_return_service_assert($stock_before === WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6), 'Sequential sibling Returns changed physical stock.');
	wcos_return_service_cleanup($fixture);
	return array('case' => 'authenticated_sequential_siblings', 'status' => 'completed');
}

function wcos_return_service_legacy_rejection_case() {
	$product = new WC_Product_Simple(); $product->set_name('WCOS Return service legacy diagnostic');
	$product->set_regular_price('4.00'); $product->set_price('4.00'); $product->save();
	$original = wc_create_order(); $original->add_product($product, 1); $original->calculate_totals(false); $original->save();
	$child = wc_create_order(); $child->add_product($product, 1); $child->calculate_totals(false);
	$private_email = 'legacy-return-private@example.test'; $child->set_billing_email($private_email);
	$child->update_meta_data('yoos_original_order', $original->get_id()); $child->save();
	$operation_id = 'return-service-legacy-' . wp_generate_uuid4(); $rejected = false; $report = array();
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($child->get_id()), $operation_id, 2); }
	catch (WCOS_Return_Adapter_Exception $exception) { $rejected = 0 === strpos($exception->get_error_code(), 'return_preflight_'); $report = $exception->get_report(); }
	wcos_return_service_assert($rejected && !is_array(WCOS_Operation_Journal::get($child, $operation_id)), 'Legacy-only Return metadata minted executable authority.');
	wcos_return_service_assert(false === strpos(wp_json_encode($report), $private_email), 'Return adapter rejection report exposed customer PII.');
	$child->delete(true); $original->delete(true); $product->delete(true);
	return array('case' => 'legacy_only_lineage', 'status' => 'rejected');
}

function wcos_return_service_multiline_fixture($label) {
	$product = new WC_Product_Simple(); $product->set_name('WCOS Return service multiline ' . $label);
	$product->set_regular_price('6.00'); $product->set_price('6.00');
	$product->set_manage_stock(true); $product->set_stock_quantity(60); $product->set_backorders('yes');
	wcos_return_service_assert($product->save() > 0, 'Return multiline product fixture could not be saved.');
	$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD');
	$source_item_ids = array();
	foreach (array('alpha', 'beta') as $identity) {
		$item = new WC_Order_Item_Product();
		$item->set_name('Return multiline ' . $identity); $item->set_product_id($product->get_id());
		$item->set_quantity(2); $item->set_subtotal('12.00'); $item->set_total('12.00');
		$item->add_meta_data('Configured choice', $identity, true); $item->add_meta_data('_reduced_stock', '2.000000', true);
		$original->add_item($item); $source_item_ids[] = $item;
	}
	WCOS_Order_Totals_Rebuilder::rebuild($original, 2); $original->save();
	$source_item_ids = array_map(static function($item) { return absint($item->get_id()); }, $source_item_ids);
	$original->get_data_store()->set_stock_reduced($original->get_id(), true);
	$stock_before = WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6);
	$split_operation = 'return-service-multiline-split-' . wp_generate_uuid4();
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), array(
		'multiline-child' => array($source_item_ids[0] => '1.000000', $source_item_ids[1] => '1.000000'),
	), $split_operation, 2);
	wcos_return_service_assert(1 === count($children), 'Return multiline fixture Split did not create one child.');
	$fixture = array(
		'product_id' => $product->get_id(), 'original_id' => $original->get_id(), 'child_id' => $children[0]->get_id(),
		'source_item_id' => $source_item_ids[0], 'source_item_ids' => $source_item_ids, 'unrelated_item_id' => 0,
		'split_operation' => $split_operation, 'stock_before' => $stock_before,
	);
	$GLOBALS['wcos_return_service_fixtures'][] = $fixture;
	return $fixture;
}

function wcos_return_service_pair_contract(array $fixture) {
	$orders = array();
	foreach (array('child_id', 'original_id') as $key) {
		$order = isset($fixture[$key]) ? wc_get_order(absint($fixture[$key])) : false;
		if ($order instanceof WC_Order) { $orders[] = $order; }
	}
	return WCOS_Order_Contract_Snapshot::aggregate($orders, 2);
}

function wcos_return_service_journal_key($child_id, $operation_id) {
	return 'wcos_mutation_op_' . hash('sha256', absint($child_id) . '|' . sanitize_key($operation_id));
}

function wcos_return_service_freeze_checkpoint(array $fixture, $operation_id, $stage, $expected_state, $occurrence = 1) {
	$hits = 0; $thrown = false;
	$fault = static function($actual) use ($stage, $occurrence, &$hits) {
		if ($stage === $actual && ++$hits >= $occurrence) {
			throw new WCOS_Return_Recovery_Interruption_Exception('Injected production handoff at ' . $stage);
		}
	};
	$block_dispatch = static function() { throw new WCOS_Return_Recovery_Interruption_Exception('Injected service-to-coordinator handoff boundary.'); };
	add_action('wcos_return_mutation_checkpoint', $fault, 10, 1);
	add_action('wcos_return_recovery_checkpoint', $fault, 10, 1);
	add_action('wcos_mutation_recovery_required', $block_dispatch, 1, 2);
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $operation_id, 2); }
	catch (Throwable $throwable) { $thrown = true; }
	finally {
		remove_action('wcos_return_mutation_checkpoint', $fault, 10);
		remove_action('wcos_return_recovery_checkpoint', $fault, 10);
		remove_action('wcos_mutation_recovery_required', $block_dispatch, 1);
	}
	$record = WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $operation_id);
	wcos_return_service_assert($thrown && $hits >= $occurrence && is_array($record), 'Production Return handoff did not persist a journal at ' . $stage . '.');
	$actual_state = WCOS_Return_Recovery_State_Graph::assert_record($record);
	wcos_return_service_assert('recovery_required' === $record['status'] && $expected_state === $actual_state, 'Production Return handoff persisted the wrong durable state at ' . $stage . ': ' . $record['status'] . '/' . $actual_state . '.');
	$context = $record['context'];
	foreach (array('return_original_added_item_ids', 'return_destination_item_ids', 'return_child_state_after', 'return_original_state_after', 'return_child_signature_after', 'return_original_signature_after', 'return_forward_repair_allowed') as $field) {
		wcos_return_service_assert(array_key_exists($field, $context), 'Production Return handoff omitted checkpoint field ' . $field . ' at ' . $stage . '.');
	}
	if (in_array($expected_state, array(
		WCOS_Return_Recovery_State_Graph::CHILD_RETIRED, WCOS_Return_Recovery_State_Graph::CHILD_RELATION_PARTIAL,
		WCOS_Return_Recovery_State_Graph::ACTIVE_SPLIT_CLEANED, WCOS_Return_Recovery_State_Graph::VERIFIED,
		WCOS_Return_Recovery_State_Graph::COMMITTED,
	), true)) {
		wcos_return_service_assert(!empty($context['return_forward_repair_allowed']) && !empty($context['return_destination_item_ids']), 'Forward Return handoff lacks repair/destination authority at ' . $stage . '.');
	}
	return $record;
}

function wcos_return_service_replay_outcome(array $fixture, $operation_id, $expected, $label) {
	$before = wcos_return_service_pair_contract($fixture);
	$stock_before = WCOS_Decimal::normalize(wc_get_product($fixture['product_id'])->get_stock_quantity(), 6);
	$result = null; $error_code = ''; $recovery_errors = array(); $recovery_stages = array();
	$observe_error = static function($throwable) use (&$recovery_errors) { $recovery_errors[] = get_class($throwable) . ': ' . $throwable->getMessage(); };
	$observe_stage = static function($stage) use (&$recovery_stages) { $recovery_stages[] = $stage; };
	add_action('wcos_mutation_compensation_error', $observe_error, PHP_INT_MAX, 1);
	add_action('wcos_return_recovery_checkpoint', $observe_stage, PHP_INT_MAX - 1, 1);
	try { $result = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $operation_id, 2); }
	catch (WCOS_Return_Adapter_Exception $exception) {
		$error_code = $exception->get_error_code();
		$payload = wp_json_encode(array($exception->getMessage(), $exception->get_report()));
		wcos_return_service_assert(false === strpos($payload, '@') && false === strpos($payload, 'billing') && false === strpos($payload, 'address'), 'Return replay error exposed PII: ' . $label);
	} finally {
		remove_action('wcos_mutation_compensation_error', $observe_error, PHP_INT_MAX);
		remove_action('wcos_return_recovery_checkpoint', $observe_stage, PHP_INT_MAX - 1);
	}
	if ('completed' === $expected) {
		wcos_return_service_assert(is_array($result) && 'completed' === $result['status'], 'Return service replay did not complete: ' . $label . '/' . $error_code . '/' . implode(' | ', $recovery_errors) . '/stages=' . implode(',', $recovery_stages));
	} else {
		wcos_return_service_assert('return_' . $expected === $error_code, 'Return service replay returned the wrong terminal classification: ' . $label . '/' . $error_code . '/' . implode(' | ', $recovery_errors) . '/stages=' . implode(',', $recovery_stages));
	}
	if ('compensated' !== $expected) {
		wcos_return_service_assert($before === wcos_return_service_pair_contract($fixture), 'Return service replay repeated or drifted commercial state: ' . $label);
	}
	wcos_return_service_assert($stock_before === WCOS_Decimal::normalize(wc_get_product($fixture['product_id'])->get_stock_quantity(), 6), 'Return service replay changed physical stock: ' . $label);
	$stable_before = wcos_return_service_pair_contract($fixture); $stable_result = null; $stable_code = '';
	try { $stable_result = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $operation_id, 2); }
	catch (WCOS_Return_Adapter_Exception $exception) { $stable_code = $exception->get_error_code(); }
	if ('completed' === $expected) { wcos_return_service_assert($result === $stable_result, 'Completed Return handoff replay was not deterministic: ' . $label); }
	else { wcos_return_service_assert($error_code === $stable_code, 'Terminal Return handoff replay classification changed: ' . $label); }
	wcos_return_service_assert($stable_before === wcos_return_service_pair_contract($fixture), 'Terminal Return handoff replay performed repeated commercial writes: ' . $label);
	return array('case' => $label, 'status' => $expected);
}

function wcos_return_service_handoff_matrix() {
	$evidence = array();

	$fixture = wcos_return_service_multiline_fixture('partial-child-ownership');
	$operation_id = 'return-service-partial-child-' . wp_generate_uuid4();
	$child_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id']));
	$original_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id']));
	wcos_return_service_freeze_checkpoint($fixture, $operation_id, 'before_child_ownership_write', WCOS_Return_Recovery_State_Graph::CHILD_OWNERSHIP_NEUTRALIZING, 2);
	$markers = array_map(static function($item) { return (string) $item->get_meta('_reduced_stock', true); }, array_values(wc_get_order($fixture['child_id'])->get_items('line_item')));
	wcos_return_service_assert(1 === count(array_filter($markers, static function($value) { return '' === $value; })), 'Multiline Return did not freeze after exactly one durable child ownership neutralization.');
	$evidence[] = wcos_return_service_replay_outcome($fixture, $operation_id, 'compensated', 'partial_multiline_child_ownership');
	wcos_return_service_assert($child_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id'])) && $original_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id'])), 'Multiline Return compensation did not restore both exact participants.');
	wcos_return_service_cleanup($fixture, $operation_id);

	$forward = array(
		'before_forward_child_relation' => array(WCOS_Return_Recovery_State_Graph::CHILD_RETIRED, 'child_retired_forward_replay'),
		'after_one_reciprocal_relation' => array(WCOS_Return_Recovery_State_Graph::CHILD_RELATION_PARTIAL, 'partial_reciprocal_participation_replay'),
		'before_forward_relations_complete' => array(WCOS_Return_Recovery_State_Graph::ACTIVE_SPLIT_CLEANED, 'active_split_cleanup_replay'),
		'after_pair_verification' => array(WCOS_Return_Recovery_State_Graph::VERIFIED, 'verified_before_complete_replay'),
		'after_commit_before_complete' => array(WCOS_Return_Recovery_State_Graph::COMMITTED, 'committed_before_complete_replay'),
	);
	foreach ($forward as $stage => $authority) {
		$fixture = wcos_return_service_fixture('handoff-' . $stage);
		$operation_id = 'return-service-handoff-' . wp_generate_uuid4();
		wcos_return_service_freeze_checkpoint($fixture, $operation_id, $stage, $authority[0]);
		$evidence[] = wcos_return_service_replay_outcome($fixture, $operation_id, 'completed', $authority[1]);
		$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
		$record = WCOS_Operation_Journal::get($child, $operation_id); $pair = WCOS_Return_Journal_Context::pair_from_record($record);
		$relation = WCOS_Return_Participation::state_for_pair($child, $original, $operation_id, $pair['pair_fingerprint']);
		wcos_return_service_assert($relation['child'] && $relation['original'] && $relation['active_split_removed'], 'Forward Return replay did not finish exact relations: ' . $authority[1]);
		wcos_return_service_cleanup($fixture, $operation_id);
	}

	$fixture = wcos_return_service_fixture('post-journal-lease-loss');
	$operation_id = 'return-service-lease-loss-' . wp_generate_uuid4(); $lease_lost = false;
	$child_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id']));
	$original_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id']));
	$loss = static function($stage, $child, $original, $actual_operation) use (&$lease_lost) {
		if (!$lease_lost && 'after_durable_preparation' === $stage) {
			$token = WCOS_Operation_Lock::current_token_for($child->get_id(), $actual_operation);
			$lease_lost = false !== $token && WCOS_Operation_Lock::release($child->get_id(), $token);
		}
	};
	add_action('wcos_return_mutation_checkpoint', $loss, 10, 4);
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $operation_id, 2); }
	catch (WCOS_Return_Adapter_Exception $exception) { /* Expected retryable incomplete same-operation lease set. */ }
	finally { remove_action('wcos_return_mutation_checkpoint', $loss, 10); }
	$record = WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $operation_id);
	wcos_return_service_assert($lease_lost && 'recovery_required' === $record['status'] && WCOS_Return_Recovery_State_Graph::ORIGINAL_STAGING === WCOS_Return_Recovery_State_Graph::assert_record($record), 'Post-journal Return lease loss did not remain retryable.');
	$evidence[] = wcos_return_service_replay_outcome($fixture, $operation_id, 'compensated', 'post_journal_lease_loss');
	wcos_return_service_assert($child_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id'])) && $original_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id'])), 'Post-journal lease-loss compensation did not restore exact participants.');
	wcos_return_service_cleanup($fixture, $operation_id);

	foreach (array('child', 'original') as $role) {
		$fixture = wcos_return_service_fixture('checkpoint-drift-' . $role); $operation_id = 'return-service-drift-' . wp_generate_uuid4();
		wcos_return_service_freeze_checkpoint($fixture, $operation_id, 'after_durable_preparation', WCOS_Return_Recovery_State_Graph::ORIGINAL_STAGING);
		$drifted = wc_get_order($fixture[$role . '_id']); $drifted->set_status('on-hold'); $drifted->save();
		$evidence[] = wcos_return_service_replay_outcome($fixture, $operation_id, 'manual_reconciliation', $role . '_drift_after_service_checkpoint');
		wcos_return_service_assert(WCOS_Manual_Reconciliation_Blocker::has_active(wc_get_order($fixture['child_id'])) && WCOS_Manual_Reconciliation_Blocker::has_active(wc_get_order($fixture['original_id'])), 'Return service drift did not block both participants: ' . $role);
		wcos_return_service_cleanup($fixture, $operation_id);
	}

	$fixture = wcos_return_service_fixture('missing-peer'); $operation_id = 'return-service-missing-peer-' . wp_generate_uuid4();
	wcos_return_service_freeze_checkpoint($fixture, $operation_id, 'after_durable_preparation', WCOS_Return_Recovery_State_Graph::ORIGINAL_STAGING);
	wc_get_order($fixture['original_id'])->delete(true);
	$evidence[] = wcos_return_service_replay_outcome($fixture, $operation_id, 'manual_reconciliation', 'missing_peer_after_service_checkpoint');
	wcos_return_service_assert(WCOS_Manual_Reconciliation_Blocker::has_active(wc_get_order($fixture['child_id'])), 'Missing Return peer did not block the surviving child.');
	wcos_return_service_cleanup($fixture, $operation_id);

	foreach (array('snapshot', 'checkpoint', 'pair') as $corruption) {
		$fixture = wcos_return_service_fixture('corrupt-' . $corruption); $operation_id = 'return-service-corrupt-' . wp_generate_uuid4();
		wcos_return_service_freeze_checkpoint($fixture, $operation_id, 'after_durable_preparation', WCOS_Return_Recovery_State_Graph::ORIGINAL_STAGING);
		$key = wcos_return_service_journal_key($fixture['child_id'], $operation_id); $record = get_option($key);
		if ('snapshot' === $corruption) { $record['context']['return_recovery_snapshot']['recovery_fingerprint'] = str_repeat('a', 64); }
		elseif ('checkpoint' === $corruption) {
			for ($index = count($record['checkpoints']) - 1; $index >= 0; $index--) {
				if (isset($record['checkpoints'][$index]['context']['return_recovery_state'])) {
					$record['checkpoints'][$index]['context']['return_recovery_checkpoint_fingerprint'] = str_repeat('b', 64); break;
				}
			}
		}
		else { $record['context']['return_pair']['pair_fingerprint'] = str_repeat('c', 64); }
		update_option($key, $record, false);
		$evidence[] = wcos_return_service_replay_outcome($fixture, $operation_id, 'manual_reconciliation', 'corrupt_' . $corruption . '_authority_replay');
		wcos_return_service_assert(WCOS_Manual_Reconciliation_Blocker::has_active(wc_get_order($fixture['child_id'])), 'Corrupt Return authority did not block the surviving child: ' . $corruption);
		wcos_return_service_cleanup($fixture, $operation_id);
	}
	return $evidence;
}

function wcos_return_service_interruption_case($stage, $expected_status) {
	$fixture = wcos_return_service_fixture('interruption-' . $stage);
	$operation_id = 'return-service-window-' . wp_generate_uuid4(); $hit = false;
	$fault = static function($actual) use ($stage, &$hit) {
		if (!$hit && $stage === $actual) { $hit = true; throw new WCOS_Return_Recovery_Interruption_Exception('Injected production service window ' . $stage); }
	};
	add_action('wcos_return_mutation_checkpoint', $fault, 10, 1);
	add_action('wcos_return_recovery_checkpoint', $fault, 10, 1);
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $operation_id, 2); }
	catch (Throwable $throwable) { /* Expected injected interruption after durable authority. */ }
	finally {
		remove_action('wcos_return_mutation_checkpoint', $fault, 10);
		remove_action('wcos_return_recovery_checkpoint', $fault, 10);
	}
	$child = wc_get_order($fixture['child_id']); $record = WCOS_Operation_Journal::get($child, $operation_id);
	$actual_status = is_array($record) && isset($record['status']) ? $record['status'] : 'missing';
	wcos_return_service_assert($hit && $expected_status === $actual_status, 'Unexpected production Return service recovery outcome for ' . $stage . ': ' . $actual_status . '.');
	wcos_return_service_assert($fixture['stock_before'] === WCOS_Decimal::normalize(wc_get_product($fixture['product_id'])->get_stock_quantity(), 6), 'Production Return interruption changed physical stock: ' . $stage);
	wcos_return_service_cleanup($fixture, $operation_id);
	return array('case' => 'service_window_' . $stage, 'status' => $expected_status);
}

$admin_id = wp_insert_user(array(
	'user_login' => 'wcos-return-service-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(24),
	'user_email' => 'return-service-' . wp_generate_uuid4() . '@example.test', 'role' => 'administrator',
));
wcos_return_service_assert(!is_wp_error($admin_id), 'Return service fixture administrator could not be created.');
wp_set_current_user($admin_id);

$evidence = array();
try {
	/* The adapter is directly callable while the production gateway gate remains hard-off. */
	$fixture = wcos_return_service_fixture('success');
	$return_operation = 'return-service-' . wp_generate_uuid4();
	$child = wc_get_order($fixture['child_id']);
	$unsupported_operation = 'return-unsupported-' . wp_generate_uuid4();
	$unsupported = false;
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['original_id']), $unsupported_operation, 2); }
	catch (WCOS_Return_Adapter_Exception $exception) { $unsupported = 0 === strpos($exception->get_error_code(), 'return_preflight_'); }
	wcos_return_service_assert($unsupported && !is_array(WCOS_Operation_Journal::get(wc_get_order($fixture['original_id']), $unsupported_operation)), 'Return adapter did not reject unsupported lineage before journal persistence.');
	$gateway_rejected = false;
	$gate_operation = 'return-gate-' . wp_generate_uuid4();
	try { (new WCOS_Mutation_Gateway())->return_order($child, $gate_operation, 2); }
	catch (RuntimeException $exception) { $gateway_rejected = true; }
	wcos_return_service_assert($gateway_rejected, 'Return production gateway did not fail before preflight while its gate is hard-off.');
	wcos_return_service_assert(!is_array(WCOS_Operation_Journal::get($child, $gate_operation)), 'Return gate rejection created a journal.');

	list($result, $retirement_counts) = wcos_return_service_observed_execute($child, $return_operation, 2);
	$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
	wcos_return_service_assert('completed' === $result['status'] && 'trash' === $child->get_status(), 'Return adapter did not complete and non-force archive the child.');
	wcos_return_service_assert($result === (new WCOS_Return_WooCommerce_Adapter())->return_order($child, $return_operation, 2), 'Return terminal replay was not deterministic.');
	$precision_rejected = false;
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order($child, $return_operation, 3); }
	catch (WCOS_Return_Adapter_Exception $exception) { $precision_rejected = 'price_precision_mismatch' === $exception->get_error_code(); }
	wcos_return_service_assert($precision_rejected, 'Completed Return replay accepted conflicting precision authority.');
	$source_item = $original->get_item($fixture['source_item_id']);
	wcos_return_service_assert($source_item instanceof WC_Order_Item_Product && '2.000000' === WCOS_Decimal::normalize($source_item->get_quantity(), 6), 'Return did not restore exact original quantity.');
	wcos_return_service_assert('18.00' === WCOS_Decimal::normalize($source_item->get_total(), 2) && '1.80' === WCOS_Decimal::normalize($source_item->get_total_tax(), 2), 'Return did not restore exact historical money and tax.');
	$restored_taxes = $source_item->get_taxes();
	wcos_return_service_assert('2.00' === WCOS_Decimal::normalize($restored_taxes['subtotal'][901], 2) && '1.80' === WCOS_Decimal::normalize($restored_taxes['total'][901], 2), 'Return did not conserve exact per-rate historical tax authority.');
	wcos_return_service_assert('2.000000' === WCOS_Decimal::normalize($source_item->get_meta('_reduced_stock', true), 6), 'Return did not conserve operational stock ownership.');
	wcos_return_service_assert(false === (bool) $child->get_data_store()->get_stock_reduced($child->get_id()) && true === (bool) $original->get_data_store()->get_stock_reduced($original->get_id()), 'Return order-level stock flags do not match line ownership.');
	wcos_return_service_assert($fixture['stock_before'] === WCOS_Decimal::normalize(wc_get_product($fixture['product_id'])->get_stock_quantity(), 6), 'Return changed physical product stock.');
	$relation = WCOS_Return_Participation::state_for_pair($child, $original, $return_operation, $result['pair_fingerprint']);
	wcos_return_service_assert($relation['child'] && $relation['original'] && $relation['active_split_removed'], 'Return participation and active Split cleanup are incomplete.');
	$terminal_json = wp_json_encode($result);
	wcos_return_service_assert(false === strpos($terminal_json, '@') && false === strpos($terminal_json, 'billing') && false === strpos($terminal_json, 'address'), 'Return terminal result exposed customer or payment fields.');
	$evidence[] = array('case' => 'adapter_success_replay_gate', 'status' => 'completed', 'retirement_side_effects' => $retirement_counts);
	wcos_return_service_cleanup($fixture, $return_operation);

	/* A durable service interruption must compensate both participants exactly. */
	$fixture = wcos_return_service_fixture('compensation');
	$return_operation = 'return-service-compensate-' . wp_generate_uuid4();
	$child_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id']));
	$original_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id']));
	$interrupted = false;
	$fault = static function($stage) use (&$interrupted) {
		if (!$interrupted && 'after_original_persisted' === $stage) { $interrupted = true; throw new WCOS_Return_Recovery_Interruption_Exception('Injected Return service interruption.'); }
	};
	add_action('wcos_return_mutation_checkpoint', $fault, 10, 1);
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $return_operation, 2); }
	catch (WCOS_Return_Adapter_Exception $exception) { /* Expected after synchronous compensation. */ }
	finally { remove_action('wcos_return_mutation_checkpoint', $fault, 10); }
	$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
	$record = WCOS_Operation_Journal::get($child, $return_operation);
	wcos_return_service_assert($interrupted && 'compensated' === $record['status'], 'Return service interruption did not compensate automatically.');
	wcos_return_service_assert($child_before === WCOS_Order_Contract_Snapshot::source_signature($child) && $original_before === WCOS_Order_Contract_Snapshot::source_signature($original), 'Return compensation did not restore both exact commercial states.');
	wcos_return_service_assert($fixture['stock_before'] === WCOS_Decimal::normalize(wc_get_product($fixture['product_id'])->get_stock_quantity(), 6), 'Return compensation changed physical stock.');
	$evidence[] = array('case' => 'service_interruption', 'status' => 'compensated');
	wcos_return_service_cleanup($fixture, $return_operation);

	$fixture = wcos_return_service_fixture('before-write-stock-event');
	$return_operation = 'return-service-before-write-stock-' . wp_generate_uuid4();
	$before_stock = static function($stage) use ($fixture) {
		if ('after_durable_preparation' === $stage) { do_action('woocommerce_product_before_set_stock', wc_get_product($fixture['product_id'])); }
	};
	add_action('wcos_return_mutation_checkpoint', $before_stock, 10, 1);
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $return_operation, 2); }
	catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) { /* Expected blocked-before-write signal. */ }
	finally { remove_action('wcos_return_mutation_checkpoint', $before_stock, 10); }
	$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']); $record = WCOS_Operation_Journal::get($child, $return_operation);
	$child_blocked = WCOS_Manual_Reconciliation_Blocker::has_active($child); $original_blocked = WCOS_Manual_Reconciliation_Blocker::has_active($original);
	wcos_return_service_assert('compensated' === $record['status'] && !$child_blocked && !$original_blocked, 'Before-write stock signal did not remain automatically compensatable: ' . $record['status'] . '/' . (int) $child_blocked . '/' . (int) $original_blocked);
	$evidence[] = array('case' => 'adapter_before_write_stock_event', 'status' => 'compensated');
	wcos_return_service_cleanup($fixture, $return_operation);

	foreach (array(
		'after_durable_preparation' => 'compensated',
		'before_original_ownership_write' => 'compensated',
		'before_child_retirement' => 'compensated',
		'after_non_force_child_retirement' => 'manual_reconciliation',
	) as $window => $outcome) {
		$evidence[] = wcos_return_service_interruption_case($window, $outcome);
	}
	$evidence = array_merge($evidence, wcos_return_service_handoff_matrix());

	/* Before-journal interruption and foreign pair contention perform no writes and remain retryable. */
	$fixture = wcos_return_service_fixture('before-journal-contention');
	$before_operation = 'return-service-before-journal-' . wp_generate_uuid4();
	$child_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id']));
	$original_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id']));
	$before_fault = static function($stage) { if ('before_journal_start' === $stage) { throw new WCOS_Return_Recovery_Interruption_Exception('Injected before journal.'); } };
	add_action('wcos_return_mutation_checkpoint', $before_fault, 10, 1);
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $before_operation, 2); }
	catch (WCOS_Return_Adapter_Exception $exception) { /* Expected before durable authority. */ }
	finally { remove_action('wcos_return_mutation_checkpoint', $before_fault, 10); }
	wcos_return_service_assert(!is_array(WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $before_operation))
		&& $child_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id']))
		&& $original_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id'])), 'Before-journal Return interruption changed participants or persisted authority.');
	$contention_operation = 'return-service-contention-' . wp_generate_uuid4();
	$foreign = WCOS_Multi_Order_Lease::acquire(array($fixture['child_id'], $fixture['original_id']), 'foreign-return-service-' . wp_generate_uuid4());
	wcos_return_service_assert($foreign instanceof WCOS_Multi_Order_Lease, 'Return service foreign contention fixture could not acquire pair leases.');
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $contention_operation, 2); }
	catch (WCOS_Return_Adapter_Exception $exception) { /* Expected no-journal contention rejection. */ }
	wcos_return_service_assert(!is_array(WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $contention_operation)), 'Return lease contention created a journal.');
	$foreign->release();
	$result = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $contention_operation, 2);
	wcos_return_service_assert('completed' === $result['status'], 'Return did not retry successfully after foreign lease release.');
	$evidence[] = array('case' => 'before_journal_and_pair_contention', 'status' => 'rejected_then_completed');
	wcos_return_service_cleanup($fixture, $contention_operation);

	/* A whole-line strategy child must restore to a fresh original line with exact provenance. */
	$fixture = wcos_return_service_fixture('fresh-destination', WCOS_Split_Strategy_Gates::CATEGORY);
	$return_operation = 'return-service-fresh-' . wp_generate_uuid4();
	$result = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $return_operation, 2);
	$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
	$record = WCOS_Operation_Journal::get($child, $return_operation);
	$destination_ids = $record['context']['return_destination_item_ids'];
	$destination_id = absint($destination_ids[$fixture['source_item_id']]);
	$destination = $original->get_item($destination_id); $unrelated = $original->get_item($fixture['unrelated_item_id']);
	wcos_return_service_assert('completed' === $result['status'] && $destination_id !== $fixture['source_item_id'] && $destination instanceof WC_Order_Item_Product, 'Return did not use a fresh destination for whole-line Split provenance.');
	wcos_return_service_assert('2.000000' === WCOS_Decimal::normalize($destination->get_quantity(), 6) && '18.00' === WCOS_Decimal::normalize($destination->get_total(), 2), 'Fresh Return destination did not preserve exact historical quantity and money.');
	$fresh_taxes = $destination->get_taxes();
	wcos_return_service_assert('2.00' === WCOS_Decimal::normalize($fresh_taxes['subtotal'][901], 2) && '1.80' === WCOS_Decimal::normalize($fresh_taxes['total'][901], 2), 'Fresh Return destination did not preserve exact per-rate tax values.');
	wcos_return_service_assert('preserved' === $destination->get_meta('Configured choice', true) && 'private-business-preserved' === $destination->get_meta('_return_private_configuration', true), 'Fresh Return destination did not preserve accepted public/private business identity metadata.');
	wcos_return_service_assert($unrelated instanceof WC_Order_Item_Product && '1.000000' === WCOS_Decimal::normalize($unrelated->get_meta('_reduced_stock', true), 6), 'Fresh Return changed unrelated original stock ownership.');
	$evidence[] = array('case' => 'whole_line_fresh_destination', 'status' => 'completed');
	wcos_return_service_cleanup($fixture, $return_operation);

	$fixture = wcos_return_service_fixture('stock-status-fresh', WCOS_Split_Strategy_Gates::STOCK_STATUS);
	$return_operation = 'return-service-stock-status-' . wp_generate_uuid4();
	$result = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $return_operation, 2);
	$child = wc_get_order($fixture['child_id']); $record = WCOS_Operation_Journal::get($child, $return_operation);
	$destination_id = absint($record['context']['return_destination_item_ids'][$fixture['source_item_id']]);
	wcos_return_service_assert('completed' === $result['status'] && $destination_id !== $fixture['source_item_id'] && wc_get_order($fixture['original_id'])->get_item($destination_id) instanceof WC_Order_Item_Product, 'Stock-status whole-line Return did not restore a fresh exact destination.');
	$evidence[] = array('case' => 'stock_status_fresh_destination', 'status' => 'completed');
	wcos_return_service_cleanup($fixture, $return_operation);

	foreach (array('parent_managed_fractional_variation', 'unmanaged', 'catalog_unavailable') as $stock_case) {
		$evidence[] = wcos_return_service_stock_matrix_case($stock_case);
	}
	$evidence[] = wcos_return_service_sibling_case(false);
	$evidence[] = wcos_return_service_sibling_case(true);
	$evidence[] = wcos_return_service_legacy_rejection_case();

	/* Adapter observation of an after-write stock hook makes the pair manual, never auto-compensated. */
	$fixture = wcos_return_service_fixture('stock-event');
	$return_operation = 'return-service-stock-event-' . wp_generate_uuid4();
	$stock_event = static function($stage) use ($fixture) {
		if ('after_durable_preparation' === $stage) { do_action('woocommerce_product_set_stock', wc_get_product($fixture['product_id'])); }
	};
	add_action('wcos_return_mutation_checkpoint', $stock_event, 10, 1);
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $return_operation, 2); }
	catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) { /* Expected after-write signal after pair-wide manual authority. */ }
	finally { remove_action('wcos_return_mutation_checkpoint', $stock_event, 10); }
	$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
	$record = WCOS_Operation_Journal::get($child, $return_operation);
	wcos_return_service_assert('manual_reconciliation' === $record['status'] && WCOS_Manual_Reconciliation_Blocker::has_active($child) && WCOS_Manual_Reconciliation_Blocker::has_active($original), 'Return adapter did not convert an after-write stock event into pair-wide manual reconciliation.');
	$blocked = false;
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order($child, 'return-service-blocked-' . wp_generate_uuid4(), 2); }
	catch (WCOS_Return_Adapter_Exception $exception) { $blocked = 0 === strpos($exception->get_error_code(), 'return_preflight_'); }
	wcos_return_service_assert($blocked, 'Pair-wide manual reconciliation did not block a future Return mutation.');
	$evidence[] = array('case' => 'adapter_after_write_stock_event', 'status' => 'manual_reconciliation');
	wcos_return_service_cleanup($fixture, $return_operation);
} finally {
	foreach ($GLOBALS['wcos_return_service_fixtures'] as $fixture) { wcos_return_service_cleanup($fixture); }
	remove_filter('wcos_order_item_meta_classification', $wcos_return_private_business, 10);
	wp_delete_user($admin_id);
}

echo 'return-service-adapter-ok ' . wp_json_encode($evidence) . "\n";
