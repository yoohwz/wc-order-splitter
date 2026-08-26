<?php

if (!defined('ABSPATH')) { exit(1); }

function wcos_bulk_return_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_bulk_return_reduced_total(WC_Order $order) {
	$units = 0;
	foreach ($order->get_items('line_item') as $item) {
		$value = $item->get_meta('_reduced_stock', true);
		if ('' !== (string) $value) { $units += WCOS_Decimal::to_units($value, 6); }
	}
	return WCOS_Decimal::from_units($units, 6);
}

function wcos_bulk_return_cleanup(array $ids) {
	foreach ($ids['orders'] as $order_id) {
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) { continue; }
		$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
		foreach (is_array($summary) ? $summary : array() as $entry) {
			if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
		}
		delete_option('wcos_manual_reconcile_block_' . $order->get_id());
		$order->delete(true);
	}
	foreach ($ids['products'] as $product_id) {
		$product = wc_get_product($product_id);
		if ($product instanceof WC_Product) { $product->delete(true); }
	}
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_bulk_return_assert(!empty($admins), 'Bulk Return coordinator smoke requires an administrator.');
$operator_id = absint($admins[0]);
wp_set_current_user($operator_id);
$fixtures = array('orders' => array(), 'products' => array());

wcos_bulk_return_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN), 'Production Bulk Return gate drifted on.');
wcos_bulk_return_assert(null === WCOS_Bulk_Return_Admin_Controller::bootstrap(), 'Hard-off Bulk Return controller bootstrapped.');
foreach (array(WCOS_Bulk_Return_Admin_Controller::REVIEW_ACTION, WCOS_Bulk_Return_Admin_Controller::CONFIRM_ACTION, WCOS_Bulk_Return_Admin_Controller::EXECUTE_ACTION, WCOS_Bulk_Return_Admin_Controller::RESUME_ACTION) as $action) {
	wcos_bulk_return_assert(false === has_action('wp_ajax_' . $action), 'Hard-off Bulk Return AJAX hook is registered: ' . $action);
}
wcos_bulk_return_assert(!file_exists(dirname(__DIR__, 2) . '/inc/backend/actions/return-order-bulk-action.php'), 'Legacy Bulk Return action still exists.');
wcos_bulk_return_assert(!file_exists(dirname(__DIR__, 2) . '/inc/backend/orders-bulk-return.php'), 'Legacy Bulk Return bootstrap still exists.');
wcos_bulk_return_assert(!file_exists(dirname(__DIR__, 2) . '/js/bulk-return-action.js'), 'Legacy Bulk Return client still exists.');

try {
	$product = new WC_Product_Simple();
	$product->set_name('WCOS Bulk Return disposable');
	$product->set_regular_price('12.50'); $product->set_price('12.50');
	$product->set_manage_stock(true); $product->set_stock_quantity(50); $product->set_backorders('yes');
	wcos_bulk_return_assert($product->save() > 0, 'Bulk Return product fixture could not be saved.');
	$fixtures['products'][] = $product->get_id();

	$original = wc_create_order();
	$original->set_status('pending'); $original->set_currency('USD'); $original->set_prices_include_tax(false);
	$original->set_billing_email('bulk-private-' . wp_generate_uuid4() . '@example.test');
	$original->set_billing_address_1('Bulk Private Street');
	$item_id = $original->add_product($product, 4);
	$original->calculate_totals(false); $original->save();
	$item = $original->get_item($item_id);
	$item->add_meta_data('_reduced_stock', '4.000000', true); $item->save();
	$original->get_data_store()->set_stock_reduced($original->get_id(), true);
	$fixtures['orders'][] = $original->get_id();
	$stock_before = WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6);

	$split_operation = 'bulk-return-split-' . wp_generate_uuid4();
	$children = (new WCOS_Mutation_Gateway())->split(
		wc_get_order($original->get_id()),
		array(
			'bulk-return-a' => array($item_id => '1.000000'),
			'bulk-return-b' => array($item_id => '1.000000'),
		),
		$split_operation,
		2
	);
	wcos_bulk_return_assert(2 === count($children), 'Bulk Return fixture did not create two siblings.');
	foreach ($children as $child) { $fixtures['orders'][] = $child->get_id(); }
	$child_ids = array($children[1]->get_id(), $children[0]->get_id(), $children[0]->get_id());

	$malformed = false;
	try { WCOS_Bulk_Return_Batch_Plan::build(array(array($children[0]->get_id()))); }
	catch (WCOS_Bulk_Return_Batch_Exception $exception) { $malformed = 'invalid_selection' === $exception->get_reason(); }
	wcos_bulk_return_assert($malformed, 'Nested Bulk Return IDs were accepted.');
	$oversize = false;
	try { WCOS_Bulk_Return_Batch_Plan::build(range(1000001, 1000021)); }
	catch (WCOS_Bulk_Return_Batch_Exception $exception) { $oversize = 'batch_too_large' === $exception->get_reason(); }
	wcos_bulk_return_assert($oversize, 'Oversize Bulk Return selection was accepted.');
	$duplicate_flood = false;
	try { WCOS_Bulk_Return_Batch_Plan::build(array_fill(0, 21, $children[0]->get_id())); }
	catch (WCOS_Bulk_Return_Batch_Exception $exception) { $duplicate_flood = 'batch_too_large' === $exception->get_reason(); }
	wcos_bulk_return_assert($duplicate_flood, 'Bulk Return did not cap the raw selection before graph work.');

	$mixed = WCOS_Bulk_Return_Batch_Plan::build(array($children[0]->get_id(), $original->get_id()));
	wcos_bulk_return_assert(!$mixed['all_eligible'], 'Mixed eligible/ineligible Bulk Return Review did not block Confirm.');
	wp_set_current_user(0);
	$unauthorized = WCOS_Bulk_Return_Batch_Plan::build(array($children[0]->get_id()));
	wcos_bulk_return_assert(!$unauthorized['all_eligible'], 'Bulk Return Review exposed eligible authority to an unauthorized operator.');
	wcos_bulk_return_assert('preflight_authorization_failed' === $unauthorized['rows'][0]['reason'], 'Bulk Return unauthorized Review returned unexpected reason: ' . $unauthorized['rows'][0]['reason']);
	wp_set_current_user($operator_id);

	$stored = WCOS_Bulk_Return_Review_Store::create($child_ids, $operator_id);
	$plan = $stored['plan'];
	wcos_bulk_return_assert(3 === $plan['selected_count'] && 2 === $plan['canonical_count'] && 1 === $plan['duplicate_count'], 'Bulk Return duplicate disclosure is incorrect.');
	wcos_bulk_return_assert($plan['all_eligible'], 'Two same-original siblings did not pass Bulk Return Review.');
	wcos_bulk_return_assert(array(0) === $plan['rows'][1]['batch_child_intent']['expected_predecessor_ordinals'], 'Same-original sibling predecessor order was not frozen.');
	wcos_bulk_return_assert(false === strpos(wp_json_encode($stored), '@example.test') && false === strpos(wp_json_encode($stored), 'Bulk Private Street'), 'Bulk Return Review exposed customer PII.');

	$confirmed = WCOS_Bulk_Return_Confirmation_Store::create($stored['review_id'], $stored['review_token'], $operator_id);
	$anchor = wc_get_order($confirmed['anchor_child_id']);
	$coordinator = WCOS_Operation_Journal::get($anchor, $confirmed['batch_id']);
	$verified = WCOS_Bulk_Return_Journal_Context::verify_request($coordinator, $confirmed['batch_token'], $operator_id);
	$initial_coordinator_progress = $coordinator['context']['bulk_return_progress'];
	wcos_bulk_return_assert(2 === count($verified['authority']['operation_map']), 'Bulk Return did not persist every child UUID mapping before Confirm success.');
	$uuids = array_column($verified['authority']['operation_map'], 'operation_id');
	wcos_bulk_return_assert(2 === count(array_unique($uuids)), 'Bulk Return child operation UUIDs are not unique.');
	foreach ($uuids as $uuid) { wcos_bulk_return_assert(1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid), 'Bulk Return child operation is not UUIDv4.'); }
	wcos_bulk_return_assert(false === strpos(wp_json_encode($coordinator), $confirmed['batch_token']), 'Raw Bulk Return token entered durable journal authority.');

	$gateway_hard_off = false;
	try { (new WCOS_Mutation_Gateway())->bulk_return_advance($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id, 0); }
	catch (Throwable $throwable) { $gateway_hard_off = true; }
	wcos_bulk_return_assert($gateway_hard_off, 'Production Bulk Return gateway did not fail closed.');
	wcos_bulk_return_assert(null === WCOS_Operation_Journal::get(wc_get_order($plan['rows'][0]['child_order_id']), $uuids[0]), 'Hard-off gateway created a child Return journal.');

	$orchestrator = new WCOS_Bulk_Return_Orchestrator();
	$first = $orchestrator->advance($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id, 0);
	wcos_bulk_return_assert(1 === $first['cursor'] && $first['has_more'], 'First Bulk Return request did not advance exactly one child.');
	$first_child_signature = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($plan['rows'][0]['child_order_id']));
	$coordinator_key = 'wcos_mutation_op_' . hash('sha256', absint($confirmed['anchor_child_id']) . '|' . sanitize_key((string) $confirmed['batch_id']));
	$lost_checkpoint = get_option($coordinator_key, array());
	$lost_checkpoint['context']['bulk_return_progress'] = $initial_coordinator_progress;
	update_option($coordinator_key, $lost_checkpoint, false); wp_cache_delete($coordinator_key, 'options');
	$reconstructed = $orchestrator->advance($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id, 0);
	wcos_bulk_return_assert(1 === $reconstructed['cursor'] && 1 === $reconstructed['counts']['completed'], 'Lost coordinator checkpoint was not reconstructed from the child journal.');
	wcos_bulk_return_assert($first_child_signature === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($plan['rows'][0]['child_order_id'])), 'Coordinator reconstruction repeated child commercial writes.');
	$authentic_checkpoint = get_option($coordinator_key, array());
	$tampered_checkpoint = $authentic_checkpoint;
	$tampered_checkpoint['context']['bulk_return_progress']['cursor'] = 2;
	update_option($coordinator_key, $tampered_checkpoint, false); wp_cache_delete($coordinator_key, 'options');
	$corruption_rejected = false;
	try { $orchestrator->advance($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id, 1); }
	catch (WCOS_Bulk_Return_Orchestrator_Exception $exception) { $corruption_rejected = 'coordinator_invalid' === $exception->get_reason(); }
	wcos_bulk_return_assert($corruption_rejected, 'Tampered coordinator progress did not fail closed.');
	wcos_bulk_return_assert(null === WCOS_Operation_Journal::get(wc_get_order($plan['rows'][1]['child_order_id']), $uuids[1]), 'Coordinator corruption started a new child row.');
	update_option($coordinator_key, $authentic_checkpoint, false); wp_cache_delete($coordinator_key, 'options');
	$lost_response = $orchestrator->advance($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id, 0);
	wcos_bulk_return_assert($first === $lost_response, 'Bulk Return response-loss replay did not reconstruct exact durable progress.');
	$second = $orchestrator->advance($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id, 1);
	wcos_bulk_return_assert('completed' === $second['status'] && 2 === $second['cursor'] && !$second['has_more'], 'Same-original Bulk Return batch did not complete in canonical order.');
	wcos_bulk_return_assert(2 === $second['counts']['completed'], 'Bulk Return terminal aggregate is incorrect.');
	foreach ($uuids as $uuid) { wcos_bulk_return_assert(false === strpos(wp_json_encode($second), $uuid), 'Bulk Return response exposed a child operation UUID.'); }

	foreach ($plan['rows'] as $ordinal => $row) {
		$child = wc_get_order($row['child_order_id']);
		wcos_bulk_return_assert($child instanceof WC_Order && 'trash' === $child->get_status(), 'Bulk Return did not preserve retired child history.');
		wcos_bulk_return_assert('0.000000' === wcos_bulk_return_reduced_total($child), 'Bulk Return child retained reduced-stock ownership.');
		$journal = WCOS_Operation_Journal::get($child, $uuids[$ordinal]);
		wcos_bulk_return_assert(is_array($journal) && 'completed' === $journal['status'] && 'return' === $journal['type'], 'Bulk coordinator replaced ordinary child Return journal authority.');
	}
	$original_after = wc_get_order($original->get_id());
	wcos_bulk_return_assert('4.000000' === wcos_bulk_return_reduced_total($original_after), 'Bulk Return did not conserve exact reduced-stock ownership.');
	wcos_bulk_return_assert($stock_before === WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6), 'Bulk Return changed physical stock.');
	$coordinator_after = WCOS_Operation_Journal::get(wc_get_order($confirmed['anchor_child_id']), $confirmed['batch_id']);
	wcos_bulk_return_assert(is_array($coordinator_after) && 'completed' === $coordinator_after['status'] && 'bulk_return_batch' === $coordinator_after['type'], 'Coordinator became unavailable after anchor child retirement.');
	wcos_bulk_return_assert(3 === count(array_filter(array($coordinator_after, WCOS_Operation_Journal::get(wc_get_order($plan['rows'][0]['child_order_id']), $uuids[0]), WCOS_Operation_Journal::get(wc_get_order($plan['rows'][1]['child_order_id']), $uuids[1])))), 'Bulk Return did not keep one coordinator plus ordinary child journals.');
	$committed_window = $coordinator_after;
	array_pop($committed_window['checkpoints']);
	$committed_window['status'] = 'committed';
	$committed_window['stage'] = 'source_committed';
	$committed_window['completed_at'] = null;
	update_option($coordinator_key, $committed_window, false); wp_cache_delete($coordinator_key, 'options');
	$healed = $orchestrator->resume($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id);
	$healed_record = WCOS_Operation_Journal::get(wc_get_order($confirmed['anchor_child_id']), $confirmed['batch_id']);
	wcos_bulk_return_assert('completed' === $healed['status'] && is_array($healed_record) && 'completed' === $healed_record['status'], 'Committed coordinator crash window was not completed idempotently.');
	$retention_probe = $healed_record;
	$retention_probe['completed_at'] = gmdate('c', time() - (100 * DAY_IN_SECONDS));
	wcos_bulk_return_assert(WCOS_Operation_Journal_Retention::is_expired_terminal_record($retention_probe), 'Completed Bulk Return coordinator is not recognized by generic terminal retention.');
	$retention_probe['status'] = 'committed';
	wcos_bulk_return_assert(!WCOS_Operation_Journal_Retention::is_expired_terminal_record($retention_probe), 'Committed Bulk Return coordinator became retention-eligible.');

	echo "bulk-return-hard-off-coordinator-ok siblings=2 duplicates=1 auth=closed child_journals=2 coordinator=1 checkpoint=reconstructed committed_window=healed corruption=blocked retention=terminal-only gateway=hard-off stock=neutral\n";
} finally {
	wcos_bulk_return_cleanup($fixtures);
}
