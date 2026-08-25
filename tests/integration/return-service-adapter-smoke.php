<?php

if (!defined('ABSPATH')) { exit(1); }

function wcos_return_service_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

$GLOBALS['wcos_return_service_fixtures'] = array();

function wcos_return_service_fixture($label, $whole_line = false) {
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
	$item->add_meta_data('Configured choice', 'preserved', true); $item->add_meta_data('_reduced_stock', '2.000000', true);
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
				'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
				'planner_policy_version' => WCOS_Category_Split_Planner::POLICY_VERSION,
				'classification_fingerprint' => hash('sha256', 'return-service-frozen-category'),
				'source_bucket_key' => 'category-return-service-source',
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
	$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
	delete_option('wcos_manual_reconcile_block_' . $fixture['child_id']);
	delete_option('wcos_manual_reconcile_block_' . $fixture['original_id']);
	foreach (array($child, $original) as $order) {
		if (!$order instanceof WC_Order) { continue; }
		$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
		foreach (is_array($summary) ? $summary : array() as $entry) {
			if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
		}
	}
	if ($child instanceof WC_Order && '' !== $return_operation) { WCOS_Operation_Journal::delete($child, $return_operation); }
	if ($child instanceof WC_Order) { $child->delete(true); }
	if ($original instanceof WC_Order) { $original->delete(true); }
	$product = wc_get_product($fixture['product_id']); if ($product instanceof WC_Product) { $product->delete(true); }
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

	$result = (new WCOS_Return_WooCommerce_Adapter())->return_order($child, $return_operation, 2);
	$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
	wcos_return_service_assert('completed' === $result['status'] && 'trash' === $child->get_status(), 'Return adapter did not complete and non-force archive the child.');
	wcos_return_service_assert($result === (new WCOS_Return_WooCommerce_Adapter())->return_order($child, $return_operation, 2), 'Return terminal replay was not deterministic.');
	$source_item = $original->get_item($fixture['source_item_id']);
	wcos_return_service_assert($source_item instanceof WC_Order_Item_Product && '2.000000' === WCOS_Decimal::normalize($source_item->get_quantity(), 6), 'Return did not restore exact original quantity.');
	wcos_return_service_assert('18.00' === WCOS_Decimal::normalize($source_item->get_total(), 2) && '1.80' === WCOS_Decimal::normalize($source_item->get_total_tax(), 2), 'Return did not restore exact historical money and tax.');
	wcos_return_service_assert('2.000000' === WCOS_Decimal::normalize($source_item->get_meta('_reduced_stock', true), 6), 'Return did not conserve operational stock ownership.');
	wcos_return_service_assert(false === (bool) $child->get_data_store()->get_stock_reduced($child->get_id()) && true === (bool) $original->get_data_store()->get_stock_reduced($original->get_id()), 'Return order-level stock flags do not match line ownership.');
	wcos_return_service_assert($fixture['stock_before'] === WCOS_Decimal::normalize(wc_get_product($fixture['product_id'])->get_stock_quantity(), 6), 'Return changed physical product stock.');
	$relation = WCOS_Return_Participation::state_for_pair($child, $original, $return_operation, $result['pair_fingerprint']);
	wcos_return_service_assert($relation['child'] && $relation['original'] && $relation['active_split_removed'], 'Return participation and active Split cleanup are incomplete.');
	$terminal_json = wp_json_encode($result);
	wcos_return_service_assert(false === strpos($terminal_json, '@') && false === strpos($terminal_json, 'billing') && false === strpos($terminal_json, 'address'), 'Return terminal result exposed customer or payment fields.');
	$evidence[] = array('case' => 'adapter_success_replay_gate', 'status' => 'completed');
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

	/* A whole-line strategy child must restore to a fresh original line with exact provenance. */
	$fixture = wcos_return_service_fixture('fresh-destination', true);
	$return_operation = 'return-service-fresh-' . wp_generate_uuid4();
	$result = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($fixture['child_id']), $return_operation, 2);
	$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
	$record = WCOS_Operation_Journal::get($child, $return_operation);
	$destination_ids = $record['context']['return_destination_item_ids'];
	$destination_id = absint($destination_ids[$fixture['source_item_id']]);
	$destination = $original->get_item($destination_id); $unrelated = $original->get_item($fixture['unrelated_item_id']);
	wcos_return_service_assert('completed' === $result['status'] && $destination_id !== $fixture['source_item_id'] && $destination instanceof WC_Order_Item_Product, 'Return did not use a fresh destination for whole-line Split provenance.');
	wcos_return_service_assert('2.000000' === WCOS_Decimal::normalize($destination->get_quantity(), 6) && '18.00' === WCOS_Decimal::normalize($destination->get_total(), 2), 'Fresh Return destination did not preserve exact historical quantity and money.');
	wcos_return_service_assert($unrelated instanceof WC_Order_Item_Product && '1.000000' === WCOS_Decimal::normalize($unrelated->get_meta('_reduced_stock', true), 6), 'Fresh Return changed unrelated original stock ownership.');
	$evidence[] = array('case' => 'whole_line_fresh_destination', 'status' => 'completed');
	wcos_return_service_cleanup($fixture, $return_operation);

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
	$evidence[] = array('case' => 'adapter_after_write_stock_event', 'status' => 'manual_reconciliation');
	wcos_return_service_cleanup($fixture, $return_operation);
} finally {
	foreach ($GLOBALS['wcos_return_service_fixtures'] as $fixture) { wcos_return_service_cleanup($fixture); }
	wp_delete_user($admin_id);
}

echo 'return-service-adapter-ok ' . wp_json_encode($evidence) . "\n";
