<?php

if (!defined('ABSPATH')) { exit(1); }

require_once __DIR__ . '/split-status-fixture-authority.php';
WCOS_Test_Split_Status_Fixture_Authority::allow(array('wc-pending'));

function wcos_bulk_enabled_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

function wcos_bulk_enabled_fixture($label, $strategy, $child_count = 1) {
	$product = new WC_Product_Simple();
	$product->set_name('WCOS Bulk enabled ' . $label); $product->set_regular_price('8.50'); $product->set_price('8.50'); $product->set_manage_stock(false);
	wcos_bulk_enabled_assert($product->save() > 0, 'Enabled Bulk Return product fixture could not be saved.');
	$keep = new WC_Product_Simple();
	$keep->set_name('WCOS Bulk enabled keep ' . $label); $keep->set_regular_price('2.00'); $keep->set_price('2.00'); $keep->set_manage_stock(false);
	wcos_bulk_enabled_assert($keep->save() > 0, 'Enabled Bulk Return keep product fixture could not be saved.');

	$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD'); $original->set_prices_include_tax(false);
	$item_id = $original->add_product($product, 'manual_quantity' === $strategy ? $child_count + 1 : 2);
	$original->add_product($keep, 1); $original->calculate_totals(false); $original->save();
	$operation_id = 'bulk-enabled-split-' . wp_generate_uuid4();
	if ('manual_quantity' === $strategy) {
		$split_plan = array();
		for ($index = 0; $index < $child_count; $index++) { $split_plan['bulk-enabled-' . $label . '-' . $index] = array($item_id => '1.000000'); }
		$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), $split_plan, $operation_id, 2);
	} else {
		$children = (new WCOS_Split_WooCommerce_Adapter())->split(
			wc_get_order($original->get_id()),
			array('bulk-enabled-' . $label => array($item_id => '2.000000')),
			$operation_id,
			2,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			array('strategy_authority' => array(
				'strategy' => $strategy,
				'planner_policy_version' => 'category' === $strategy ? WCOS_Category_Split_Planner::POLICY_VERSION : WCOS_Stock_Status_Split_Planner::POLICY_VERSION,
				'classification_fingerprint' => hash('sha256', 'bulk-enabled-' . $strategy . '-' . $label),
				'source_bucket_key' => $strategy . '-source',
				'review_source_signature' => WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($original->get_id())),
			))
		);
	}
	wcos_bulk_enabled_assert($child_count === count($children), 'Enabled Bulk Return fixture created the wrong child count.');
	return array(
		'product_ids' => array($product->get_id(), $keep->get_id()),
		'original_id' => $original->get_id(),
		'child_ids' => array_values(array_map(static function($child) { return $child->get_id(); }, $children)),
		'strategy' => $strategy,
		'review_ids' => array(),
	);
}

function wcos_bulk_enabled_cleanup(array $fixtures) {
	foreach ($fixtures as $fixture) {
		foreach (isset($fixture['review_ids']) ? $fixture['review_ids'] : array() as $review_id) { WCOS_Bulk_Return_Review_Store::delete($review_id); }
		foreach (array_merge($fixture['child_ids'], array($fixture['original_id'])) as $order_id) {
			delete_option('wcos_manual_reconcile_block_' . absint($order_id));
			$order = wc_get_order($order_id); if (!$order instanceof WC_Order) { continue; }
			$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
			foreach (is_array($summary) ? $summary : array() as $entry) {
				if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
			}
			$order->delete(true);
		}
		foreach ($fixture['product_ids'] as $product_id) {
			$product = wc_get_product($product_id); if ($product instanceof WC_Product) { $product->delete(true); }
		}
	}
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_bulk_enabled_assert(!empty($admins), 'Enabled Bulk Return controller smoke requires an administrator.');
$operator_id = absint($admins[0]); wp_set_current_user($operator_id);
$controller = WCOS_Bulk_Return_Admin_Controller::bootstrap();
wcos_bulk_enabled_assert($controller instanceof WCOS_Bulk_Return_Admin_Controller, 'Enabled Bulk Return controller did not bootstrap.');
foreach (array(WCOS_Bulk_Return_Admin_Controller::REVIEW_ACTION, WCOS_Bulk_Return_Admin_Controller::CONFIRM_ACTION, WCOS_Bulk_Return_Admin_Controller::EXECUTE_ACTION, WCOS_Bulk_Return_Admin_Controller::RESUME_ACTION) as $action) {
	wcos_bulk_enabled_assert(false !== has_action('wp_ajax_' . $action), 'Enabled Bulk Return AJAX hook is missing: ' . $action);
}
$actions = $controller->register_bulk_action(array());
wcos_bulk_enabled_assert(isset($actions[WCOS_Bulk_Return_Admin_Controller::BULK_ACTION]), 'Enabled Bulk Return Orders-list action is missing.');

$nonce = wp_create_nonce(WCOS_Bulk_Return_Admin_Controller::NONCE_ACTION);
$fixtures = array();
try {
	$fixtures[] = wcos_bulk_enabled_fixture('manual-siblings', 'manual_quantity', 2);
	$manual_index = count($fixtures) - 1;
	$manual = $fixtures[$manual_index];
	$unexpected_rejected = false;
	try { $controller->review_request(array('nonce' => $nonce, 'child_order_ids' => $manual['child_ids'], 'original_order_id' => $manual['original_id'])); }
	catch (WCOS_Bulk_Return_Transport_Exception $exception) { $unexpected_rejected = 'unexpected_field' === $exception->get_error_code(); }
	wcos_bulk_enabled_assert($unexpected_rejected, 'Enabled Bulk Return accepted a client-authored original.');

	$selected = array($manual['child_ids'][1], $manual['child_ids'][0], $manual['child_ids'][0]);
	$review = $controller->review_request(array('nonce' => $nonce, 'child_order_ids' => $selected));
	$fixtures[$manual_index]['review_ids'][] = $review['review_id'];
	wcos_bulk_enabled_assert(3 === $review['summary']['selected_count'] && 2 === $review['summary']['canonical_count'] && 1 === $review['summary']['duplicate_count'], 'Enabled Bulk Return Review lost duplicate disclosure.');
	wcos_bulk_enabled_assert($review['summary']['all_eligible'] && 1 === count($review['summary']['groups']), 'Enabled Bulk Return Review lost same-original grouping.');
	$confirm = $controller->confirm_request(array('nonce' => $nonce, 'review_id' => $review['review_id'], 'review_token' => $review['review_token']));
	$coordinator = WCOS_Operation_Journal::get(wc_get_order($confirm['anchor_child_id']), $confirm['batch_id']);
	$verified = WCOS_Bulk_Return_Journal_Context::verify_request($coordinator, $confirm['batch_token'], $operator_id);
	$operation_ids = array_column($verified['authority']['operation_map'], 'operation_id');
	wcos_bulk_enabled_assert(2 === count(array_unique($operation_ids)) && false === strpos(wp_json_encode($coordinator), $confirm['batch_token']), 'Enabled Bulk Return Confirm did not persist exact hash-only UUID authority.');
	$execute = array('nonce' => $nonce, 'batch_id' => $confirm['batch_id'], 'batch_token' => $confirm['batch_token'], 'anchor_child_id' => $confirm['anchor_child_id'], 'cursor' => 0);
	$first = $controller->execute_request($execute);
	$replay = $controller->execute_request($execute);
	wcos_bulk_enabled_assert($first === $replay && 1 === $first['cursor'], 'Enabled Bulk Return response-loss retry did not reconstruct exact one-row progress.');
	$execute['cursor'] = 1;
	$second = $controller->execute_request($execute);
	wcos_bulk_enabled_assert('completed' === $second['status'] && 2 === $second['counts']['completed'], 'Enabled same-original controller batch did not complete.');
	foreach ($operation_ids as $operation_id) { wcos_bulk_enabled_assert(false === strpos(wp_json_encode($second), $operation_id), 'Enabled Bulk Return response exposed a child operation UUID.'); }

	$fixtures[] = wcos_bulk_enabled_fixture('mixed-category', 'category'); $mixed_index = count($fixtures) - 1;
	$mixed_fixture = $fixtures[$mixed_index];
	$mixed = $controller->review_request(array('nonce' => $nonce, 'child_order_ids' => array($mixed_fixture['child_ids'][0], $mixed_fixture['original_id'])));
	$fixtures[$mixed_index]['review_ids'][] = $mixed['review_id'];
	wcos_bulk_enabled_assert(empty($mixed['summary']['all_eligible']) && !empty($mixed['summary']['has_eligible']) && 1 === $mixed['summary']['eligible_count'] && 1 === $mixed['summary']['skipped_count'], 'Enabled Bulk Return mixed Review did not disclose exact Eligible and Skipped counts.');
	$mixed_confirm = $controller->confirm_request(array('nonce' => $nonce, 'review_id' => $mixed['review_id'], 'review_token' => $mixed['review_token']));
	$mixed_record = WCOS_Operation_Journal::get(wc_get_order($mixed_confirm['anchor_child_id']), $mixed_confirm['batch_id']);
	$mixed_verified = WCOS_Bulk_Return_Journal_Context::verify_request($mixed_record, $mixed_confirm['batch_token'], $operator_id);
	wcos_bulk_enabled_assert(1 === count($mixed_verified['authority']['operation_map']) && 1 === $mixed_confirm['summary']['counts']['skipped'] && 1 === count($mixed_confirm['summary']['skipped_results']), 'Mixed Confirm granted authority beyond the exact Eligible execution set.');
	wcos_bulk_enabled_assert(null === WCOS_Operation_Journal::get(wc_get_order($mixed_fixture['original_id']), $mixed_confirm['batch_id']), 'Mixed Confirm created coordinator authority on a Skipped row.');
	$mixed_done = $controller->execute_request(array('nonce' => $nonce, 'batch_id' => $mixed_confirm['batch_id'], 'batch_token' => $mixed_confirm['batch_token'], 'anchor_child_id' => $mixed_confirm['anchor_child_id'], 'cursor' => 0));
	wcos_bulk_enabled_assert('completed' === $mixed_done['status'] && 1 === $mixed_done['counts']['completed'] && 1 === $mixed_done['counts']['skipped'], 'Mixed Bulk Return did not execute only the Eligible row.');

	$fixtures[] = wcos_bulk_enabled_fixture('category', 'category'); $category_index = count($fixtures) - 1;
	$fixtures[] = wcos_bulk_enabled_fixture('stock', 'stock_status'); $stock_index = count($fixtures) - 1;
	$category = $fixtures[$category_index]; $stock = $fixtures[$stock_index];
	$all_skipped = $controller->review_request(array('nonce' => $nonce, 'child_order_ids' => array($category['original_id'], $stock['original_id'])));
	$fixtures[$category_index]['review_ids'][] = $all_skipped['review_id'];
	wcos_bulk_enabled_assert(empty($all_skipped['summary']['has_eligible']) && 0 === $all_skipped['summary']['eligible_count'] && 2 === $all_skipped['summary']['skipped_count'], 'All-Skipped Review did not remain visible with zero Eligible rows.');
	global $wpdb;
	$all_skipped_journals_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wcos_mutation_op_%'");
	$all_skipped_confirm_rejected = false;
	try { $controller->confirm_request(array('nonce' => $nonce, 'review_id' => $all_skipped['review_id'], 'review_token' => $all_skipped['review_token'])); }
	catch (WCOS_Bulk_Return_Transport_Exception $exception) { $all_skipped_confirm_rejected = 'confirmation_nothing_eligible' === $exception->get_error_code(); }
	$all_skipped_journals_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wcos_mutation_op_%'");
	wcos_bulk_enabled_assert($all_skipped_confirm_rejected && $all_skipped_journals_before === $all_skipped_journals_after, 'All-Skipped Review created durable operation authority.');

	$different = $controller->review_request(array('nonce' => $nonce, 'child_order_ids' => array($category['child_ids'][0], $stock['child_ids'][0])));
	$fixtures[$category_index]['review_ids'][] = $different['review_id'];
	$strategies = array_values(array_unique(array_column($different['summary']['rows'], 'strategy'))); sort($strategies, SORT_STRING);
	wcos_bulk_enabled_assert(array('category', 'stock_status') === $strategies && 2 === count($different['summary']['groups']), 'Enabled Bulk Return Review lost different-original strategy/group authority.');
	$different_confirm = $controller->confirm_request(array('nonce' => $nonce, 'review_id' => $different['review_id'], 'review_token' => $different['review_token']));
	$different_execute = array('nonce' => $nonce, 'batch_id' => $different_confirm['batch_id'], 'batch_token' => $different_confirm['batch_token'], 'anchor_child_id' => $different_confirm['anchor_child_id'], 'cursor' => 0);
	$different_first = $controller->execute_request($different_execute); $different_execute['cursor'] = $different_first['cursor'];
	$different_done = $controller->execute_request($different_execute);
	wcos_bulk_enabled_assert('completed' === $different_done['status'] && 2 === $different_done['counts']['completed'], 'Enabled different-original strategy batch did not complete.');

	foreach ($fixtures as $fixture) {
		foreach ($fixture['child_ids'] as $child_id) { $child = wc_get_order($child_id); wcos_bulk_enabled_assert($child instanceof WC_Order && 'trash' === $child->get_status(), 'Enabled Bulk Return did not retire a completed child.'); }
		$original = wc_get_order($fixture['original_id']); wcos_bulk_enabled_assert($original instanceof WC_Order && 'trash' !== $original->get_status(), 'Enabled Bulk Return retired an original order.');
	}
	echo "bulk-return-enabled-controller-ok same_original=2 different_original=2 strategies=3 duplicates=1 mixed=eligible-only all_skipped=non-durable replay=exact gateway=1\n";
} finally {
	wcos_bulk_enabled_cleanup($fixtures);
}
