<?php

if (!defined('ABSPATH')) { exit(1); }

function wcos_return_production_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_return_production_fixture($label, $strategy) {
	$product = new WC_Product_Simple();
	$product->set_name('WCOS Return production ' . $label);
	$product->set_regular_price('10.00'); $product->set_price('10.00');
	$product->set_manage_stock(true); $product->set_stock_quantity(40); $product->set_backorders('yes');
	wcos_return_production_assert($product->save() > 0, 'Production Return product could not be saved.');
	$keep = new WC_Product_Simple();
	$keep->set_name('WCOS Return production keep ' . $label);
	$keep->set_regular_price('3.00'); $keep->set_price('3.00'); $keep->set_manage_stock(false);
	wcos_return_production_assert($keep->save() > 0, 'Production Return keep product could not be saved.');

	$original = wc_create_order();
	$original->set_status('pending'); $original->set_currency('USD'); $original->set_prices_include_tax(false);
	$original->set_billing_email('production-private-' . wp_generate_uuid4() . '@example.test');
	$original->set_billing_address_1('Production Private Street');
	$item_id = $original->add_product($product, 2);
	$original->add_product($keep, 1);
	$original->calculate_totals(false); $original->save();
	$item = $original->get_item($item_id);
	$item->add_meta_data('Production identity', $strategy, true);
	$item->add_meta_data('_reduced_stock', '2.000000', true);
	$item->save();
	$original->get_data_store()->set_stock_reduced($original->get_id(), true);
	$stock_before = WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6);
	$split_operation = 'return-production-split-' . wp_generate_uuid4();
	$source = wc_get_order($original->get_id());
	if ('manual_quantity' === $strategy) {
		$children = (new WCOS_Mutation_Gateway())->split($source, array('production-child' => array($item_id => '1.000000')), $split_operation, 2);
	} else {
		$children = (new WCOS_Split_WooCommerce_Adapter())->split(
			$source,
			array('production-child' => array($item_id => '2.000000')),
			$split_operation,
			2,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			array('strategy_authority' => array(
				'strategy' => $strategy,
				'planner_policy_version' => 'category' === $strategy ? WCOS_Category_Split_Planner::POLICY_VERSION : WCOS_Stock_Status_Split_Planner::POLICY_VERSION,
				'classification_fingerprint' => hash('sha256', 'return-production-' . $strategy . '-' . $label),
				'source_bucket_key' => $strategy . '-production-source',
				'review_source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
			))
		);
	}
	wcos_return_production_assert(1 === count($children), 'Production Return Split did not create one child.');
	return array(
		'product_ids' => array($product->get_id(), $keep->get_id()),
		'product_id' => $product->get_id(),
		'original_id' => $original->get_id(),
		'child_id' => $children[0]->get_id(),
		'strategy' => $strategy,
		'stock_before' => $stock_before,
		'review_ids' => array(),
		'operation_ids' => array(),
	);
}

function wcos_return_production_request(array $fixture) {
	return array(
		'child_order_id' => $fixture['child_id'],
		'nonce' => wp_create_nonce('wcos_return_order_' . $fixture['child_id']),
	);
}

function wcos_return_production_cleanup(array $fixture) {
	delete_option('wcos_manual_reconcile_block_' . absint($fixture['child_id']));
	delete_option('wcos_manual_reconcile_block_' . absint($fixture['original_id']));
	foreach ($fixture['review_ids'] as $review_id) { WCOS_Return_Review_Store::delete($review_id); }
	foreach ($fixture['operation_ids'] as $operation_id) {
		WCOS_Return_Confirmation_Store::delete($operation_id);
		$child = wc_get_order($fixture['child_id']);
		if ($child instanceof WC_Order) { WCOS_Operation_Journal::delete($child, $operation_id); }
	}
	foreach (array($fixture['child_id'], $fixture['original_id']) as $order_id) {
		$order = wc_get_order($order_id);
		if ($order instanceof WC_Order) {
			$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
			foreach (is_array($summary) ? $summary : array() as $entry) {
				if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
			}
			$order->delete(true);
		}
	}
	foreach ($fixture['product_ids'] as $product_id) {
		$product = wc_get_product($product_id);
		if ($product instanceof WC_Product) { $product->delete(true); }
	}
}

function wcos_return_production_reduced_total(WC_Order $order) {
	$total = 0;
	foreach ($order->get_items('line_item') as $item) {
		$value = $item->get_meta('_reduced_stock', true);
		if ('' !== (string) $value) { $total += WCOS_Decimal::to_units($value, 6); }
	}
	return WCOS_Decimal::from_units($total, 6);
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_return_production_assert(!empty($admins), 'Enabled Return production requires an administrator.');
wp_set_current_user(absint($admins[0]));
wcos_return_production_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER), 'Production Return gate is not enabled.');
wcos_return_production_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN), 'Production Bulk Return gate is not enabled alongside Return.');
foreach (array(WCOS_Feature_Gates::SPLIT, WCOS_Feature_Gates::DUPLICATE, WCOS_Feature_Gates::MERGE) as $workflow) {
	wcos_return_production_assert(WCOS_Feature_Gates::enabled($workflow), 'Accepted production workflow gate drifted off: ' . $workflow);
}

$controller = WCOS_Return_Admin_Controller::bootstrap();
wcos_return_production_assert($controller instanceof WCOS_Return_Admin_Controller, 'Enabled Return controller did not bootstrap.');
$hook_contracts = array(
	'wp_ajax_' . WCOS_Return_Admin_Controller::REVIEW_ACTION => 'ajax_review',
	'wp_ajax_' . WCOS_Return_Admin_Controller::CONFIRM_ACTION => 'ajax_confirm',
	'wp_ajax_' . WCOS_Return_Admin_Controller::EXECUTE_ACTION => 'ajax_execute',
	'woocommerce_order_item_add_action_buttons' => 'render_launcher',
	'admin_footer' => 'render_dialog',
	'admin_enqueue_scripts' => 'enqueue_assets',
);
foreach ($hook_contracts as $hook => $method) {
	wcos_return_production_assert(false !== has_action($hook, array($controller, $method)), 'Enabled Return hook is missing: ' . $hook);
}
foreach (array('wp_ajax_return_order', 'wp_ajax_return_order_bulk', 'woocommerce_order_action_yoos_return_order') as $legacy_hook) {
	wcos_return_production_assert(false === has_action($legacy_hook), 'Legacy or Bulk Return hook became reachable: ' . $legacy_hook);
}

$fixtures = array();
try {
	foreach (array('manual_quantity', 'category', 'stock_status') as $strategy) {
		$fixture = wcos_return_production_fixture('controller-' . $strategy, $strategy);
		$fixtures[] = $fixture; $index = count($fixtures) - 1;
		$request = wcos_return_production_request($fixture);
		$child = wc_get_order($fixture['child_id']);
		$child_line_count = count($child->get_items('line_item'));

		ob_start(); $controller->render_launcher($child); $launcher = (string) ob_get_clean();
		wcos_return_production_assert(false !== strpos($launcher, 'Return to original order'), 'Eligible Return launcher did not render: ' . $strategy);
		wcos_return_production_assert(false === strpos($launcher, '@example.test') && false === strpos($launcher, 'Private Street'), 'Return launcher exposed PII.');
		ob_start(); $controller->render_dialog(); $dialog = (string) ob_get_clean();
		wcos_return_production_assert(false !== strpos($dialog, 'data-child-order-id="' . $fixture['child_id'] . '"'), 'Eligible Return modal did not freeze the current child: ' . $strategy);
		wcos_return_production_assert(false === strpos($dialog, '@example.test') && false === strpos($dialog, 'Private Street'), 'Return modal exposed PII.');

		$unexpected = $request; $unexpected['original_order_id'] = $fixture['original_id'];
		$client_original_rejected = false;
		try { $controller->review_request($unexpected); }
		catch (WCOS_Return_Transport_Exception $exception) { $client_original_rejected = 'unexpected_field' === $exception->get_error_code(); }
		wcos_return_production_assert($client_original_rejected, 'Client-selected original was accepted: ' . $strategy);

		$review = $controller->review_request($request);
		$fixtures[$index]['review_ids'][] = $review['review_id'];
		wcos_return_production_assert($strategy === $review['summary']['strategy'], 'Return Review lost strategy: ' . $strategy);
		wcos_return_production_assert($fixture['original_id'] === $review['summary']['original']['id'], 'Return Review changed server-resolved original.');
		wcos_return_production_assert($fixture['child_id'] === $review['summary']['child']['id'], 'Return Review changed child identity.');
		wcos_return_production_assert(false === strpos(wp_json_encode($review['summary']), '@example.test'), 'Return Review summary exposed PII.');

		$confirm = $controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
		$fixtures[$index]['operation_ids'][] = $confirm['operation_id'];
		if ('category' === $strategy) {
			$second_confirm_rejected = false;
			try { $controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token']))); }
			catch (WCOS_Return_Transport_Exception $exception) { $second_confirm_rejected = 0 === strpos($exception->get_error_code(), 'review_'); }
			wcos_return_production_assert($second_confirm_rejected, 'One Return Review created two Confirm results.');
		}
		$execute_request = array_merge($request, array('operation_id' => $confirm['operation_id'], 'confirmation_token' => $confirm['confirmation_token']));
		if ('manual_quantity' === $strategy) {
			$invalid_token_rejected = false;
			try { $controller->execute_request(array_merge($execute_request, array('confirmation_token' => 'invalid'))); }
			catch (WCOS_Return_Transport_Exception $exception) { $invalid_token_rejected = 'confirmation_invalid_token' === $exception->get_error_code(); }
			wcos_return_production_assert($invalid_token_rejected, 'Invalid Return Confirmation reached Execute.');
			wcos_return_production_assert(null === WCOS_Operation_Journal::get($child, $confirm['operation_id']), 'Invalid Confirmation created a journal.');
		}

		$result = $controller->execute_request($execute_request);
		$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
		wcos_return_production_assert('completed' === $result['status'] && 'trash' === $child->get_status(), 'Enabled Return did not complete/retire child: ' . $strategy);
		wcos_return_production_assert($fixture['original_id'] === $result['original']['id'] && !empty($result['original']['edit_url']), 'Return result lacks bounded original navigation.');
		wcos_return_production_assert($child_line_count === count($child->get_items('line_item')), 'Return retirement deleted child history.');
		wcos_return_production_assert('2.000000' === wcos_return_production_reduced_total($original), 'Return did not restore exact reduced-stock ownership.');
		wcos_return_production_assert('0.000000' === wcos_return_production_reduced_total($child), 'Retired child retained reduced-stock ownership.');
		wcos_return_production_assert((bool) $original->get_data_store()->get_stock_reduced($original->get_id()), 'Original did not regain order stock flag.');
		wcos_return_production_assert(!(bool) $child->get_data_store()->get_stock_reduced($child->get_id()), 'Retired child kept order stock flag.');
		wcos_return_production_assert($fixture['stock_before'] === WCOS_Decimal::normalize(wc_get_product($fixture['product_id'])->get_stock_quantity(), 6), 'Controller Return changed physical stock.');

		$child_signature = WCOS_Order_Contract_Snapshot::source_signature($child);
		$original_signature = WCOS_Order_Contract_Snapshot::source_signature($original);
		$replay = $controller->execute_request($execute_request);
		wcos_return_production_assert($result === $replay, 'Response-loss retry did not replay the same terminal result.');
		wcos_return_production_assert($child_signature === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id'])), 'Replay repeated child commercial writes.');
		wcos_return_production_assert($original_signature === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id'])), 'Replay repeated original commercial writes.');
	}

	$drift = wcos_return_production_fixture('drift', 'manual_quantity');
	$fixtures[] = $drift; $drift_index = count($fixtures) - 1;
	$drift_request = wcos_return_production_request($drift);
	$drift_review = $controller->review_request($drift_request); $fixtures[$drift_index]['review_ids'][] = $drift_review['review_id'];
	$drift_child = wc_get_order($drift['child_id']); $drift_line = current($drift_child->get_items('line_item'));
	$drift_line->add_meta_data('Production drift', 'reject', true); $drift_line->save();
	$drift_rejected = false;
	try { $controller->confirm_request(array_merge($drift_request, array('review_id' => $drift_review['review_id'], 'review_token' => $drift_review['review_token']))); }
	catch (WCOS_Return_Transport_Exception $exception) { $drift_rejected = 0 === strpos($exception->get_error_code(), 'review_'); }
	wcos_return_production_assert($drift_rejected, 'Return Confirm accepted participant drift.');

	$blocked = wcos_return_production_fixture('blocked', 'manual_quantity');
	$fixtures[] = $blocked;
	$blocked_child = wc_get_order($blocked['child_id']);
	wcos_return_production_assert(WCOS_Manual_Reconciliation_Blocker::block($blocked_child, 'return-production-blocked'), 'Manual-reconciliation blocker fixture failed.');
	ob_start(); $controller->render_launcher($blocked_child); $blocked_launcher = (string) ob_get_clean();
	wcos_return_production_assert('' === $blocked_launcher, 'Blocked Return child exposed a launcher.');
	$blocked_rejected = false;
	try { $controller->review_request(wcos_return_production_request($blocked)); }
	catch (WCOS_Return_Transport_Exception $exception) { $blocked_rejected = 0 === strpos($exception->get_error_code(), 'preflight_'); }
	wcos_return_production_assert($blocked_rejected, 'Blocked Return child minted Review authority.');

	echo "return-enabled-production-ok strategies=3 hooks=6 client_original_rejected=3 replay=3 drift=1 blocked=1 bulk_return=off\n";
} finally {
	foreach (array_reverse($fixtures) as $fixture) {
		try { wcos_return_production_cleanup($fixture); } catch (Throwable $throwable) {}
	}
}
