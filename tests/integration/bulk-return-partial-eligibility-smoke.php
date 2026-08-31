<?php

if (!defined('ABSPATH')) { exit(1); }

require_once __DIR__ . '/split-status-fixture-authority.php';
WCOS_Test_Split_Status_Fixture_Authority::allow(array('wc-pending'));

function wcos_bulk_partial_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_bulk_partial_fixture($label, $child_count = 1) {
	$product = new WC_Product_Simple();
	$product->set_name('WCOS Bulk partial ' . $label); $product->set_regular_price('9.25'); $product->set_price('9.25'); $product->set_manage_stock(false);
	wcos_bulk_partial_assert($product->save() > 0, 'Bulk partial product fixture could not be saved.');
	$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD'); $original->set_prices_include_tax(false);
	$original->set_billing_email('bulk-partial-' . wp_generate_uuid4() . '@example.test'); $original->set_billing_address_1('Bulk Partial Private Street');
	$item_id = $original->add_product($product, $child_count + 1); $original->calculate_totals(false); $original->save();
	$split_plan = array();
	for ($index = 0; $index < $child_count; $index++) { $split_plan['bulk-partial-' . $label . '-' . $index] = array($item_id => '1.000000'); }
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), $split_plan, 'bulk-partial-split-' . wp_generate_uuid4(), 2);
	wcos_bulk_partial_assert($child_count === count($children), 'Bulk partial fixture created the wrong child count.');
	return array(
		'product_ids' => array($product->get_id()),
		'original_id' => $original->get_id(),
		'child_ids' => array_values(array_map(static function($child) { return $child->get_id(); }, $children)),
		'review_ids' => array(),
	);
}

function wcos_bulk_partial_separate_operations_fixture($label) {
	$product = new WC_Product_Simple(); $product->set_name('WCOS Bulk ambiguous ' . $label); $product->set_regular_price('4.00'); $product->set_price('4.00'); $product->set_manage_stock(false);
	wcos_bulk_partial_assert($product->save() > 0, 'Bulk ambiguous product fixture could not be saved.');
	$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD');
	$item_id = $original->add_product($product, 3); $original->calculate_totals(false); $original->save();
	$first = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), array('ambiguous-a' => array($item_id => '1.000000')), 'bulk-ambiguous-a-' . wp_generate_uuid4(), 2);
	$source = wc_get_order($original->get_id()); $source_items = $source->get_items('line_item'); $source_item = reset($source_items);
	$second = (new WCOS_Mutation_Gateway())->split($source, array('ambiguous-b' => array($source_item->get_id() => '1.000000')), 'bulk-ambiguous-b-' . wp_generate_uuid4(), 2);
	wcos_bulk_partial_assert(1 === count($first) && 1 === count($second), 'Bulk ambiguous fixture did not create two operations.');
	return array('product_ids' => array($product->get_id()), 'original_id' => $original->get_id(), 'child_ids' => array($first[0]->get_id(), $second[0]->get_id()), 'review_ids' => array());
}

function wcos_bulk_partial_return_journal_count($order_id) {
	$order = wc_get_order(absint($order_id)); if (!$order instanceof WC_Order) { return 0; }
	$count = 0;
	foreach ((array) $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true) as $entry) {
		if ('return' === sanitize_key(isset($entry['type']) ? (string) $entry['type'] : '')) { $count++; }
	}
	return $count;
}

function wcos_bulk_partial_reason(array $plan, $child_id) {
	foreach (WCOS_Bulk_Return_Batch_Plan::selection_rows($plan) as $row) {
		if (absint($row['child_order_id']) === absint($child_id)) { return sanitize_key((string) $row['reason']); }
	}
	return '';
}

function wcos_bulk_partial_journal_option_count() {
	global $wpdb;
	return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wcos_mutation_op_%'");
}

function wcos_bulk_partial_downgrade_v1(array $v2_plan) {
	$rows = WCOS_Bulk_Return_Batch_Plan::execution_rows($v2_plan);
	foreach ($rows as &$row) {
		unset($row['ordinal'], $row['selection_ordinal'], $row['classification'], $row['classification_fingerprint']);
		$row['batch_child_intent']['schema_version'] = 1;
	}
	unset($row);
	$plan = array(
		'schema_version' => 1, 'policy_version' => 1, 'max_children' => 20, 'atomicity' => 'per_child',
		'failure_policy' => 'fail_stop_after_first_non_success', 'execution_policy' => 'one_child_per_request',
		'deadline_policy' => 'start_next_row_30_minutes', 'selected_count' => count($rows), 'canonical_count' => count($rows),
		'duplicate_count' => 0, 'canonical_child_ids' => array_values(array_map(static function($row) { return absint($row['child_order_id']); }, $rows)),
		'all_eligible' => true, 'rows' => $rows,
	);
	$plan['batch_fingerprint'] = WCOS_Bulk_Return_Batch_Plan::fingerprint($plan);
	return $plan;
}

function wcos_bulk_partial_cleanup(array $fixtures) {
	foreach ($fixtures as $fixture) {
		foreach (isset($fixture['review_ids']) ? $fixture['review_ids'] : array() as $review_id) { WCOS_Bulk_Return_Review_Store::delete($review_id); }
		$order_ids = array_merge(isset($fixture['child_ids']) ? (array) $fixture['child_ids'] : array(), array(isset($fixture['original_id']) ? $fixture['original_id'] : 0));
		foreach (array_values(array_unique(array_filter(array_map('absint', $order_ids)))) as $order_id) {
			$order = wc_get_order($order_id); if (!$order instanceof WC_Order) { continue; }
			foreach ((array) $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true) as $entry) {
				if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
			}
			delete_option('wcos_manual_reconcile_block_' . $order->get_id()); $order->delete(true);
		}
		foreach (isset($fixture['product_ids']) ? $fixture['product_ids'] : array() as $product_id) {
			$product = wc_get_product(absint($product_id)); if ($product instanceof WC_Product) { $product->delete(true); }
		}
	}
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_bulk_partial_assert(!empty($admins), 'Bulk partial eligibility smoke requires an administrator.');
$operator_id = absint($admins[0]); wp_set_current_user($operator_id); $fixtures = array();

try {
	/* Mixed disclosure, exact Eligible execution set, immutable Skipped rows and terminal Resume. */
	$fixtures[] = wcos_bulk_partial_fixture('mixed-disclosure', 2); $mixed_index = count($fixtures) - 1; $mixed_fixture = $fixtures[$mixed_index];
	$skipped_child = wc_get_order($mixed_fixture['child_ids'][1]); $skipped_child->set_status('cancelled'); $skipped_child->save();
	$missing_id = 999999991;
	$mixed_review = WCOS_Bulk_Return_Review_Store::create(array($mixed_fixture['child_ids'][0], $mixed_fixture['child_ids'][1], $mixed_fixture['original_id'], $missing_id), $operator_id);
	$fixtures[$mixed_index]['review_ids'][] = $mixed_review['review_id']; $mixed_plan = $mixed_review['plan'];
	wcos_bulk_partial_assert(1 === $mixed_plan['eligible_count'] && 3 === $mixed_plan['skipped_count'] && $mixed_plan['has_eligible'] && !$mixed_plan['all_eligible'], 'Mixed Review did not freeze one Eligible and three Skipped rows.');
	wcos_bulk_partial_assert('preflight_lineage_missing' === wcos_bulk_partial_reason($mixed_plan, $mixed_fixture['original_id']), 'Non-Return order did not become an explicit row-local Skip.');
	wcos_bulk_partial_assert('participant_not_found' === wcos_bulk_partial_reason($mixed_plan, $missing_id), 'Missing selected participant did not become an explicit Skip.');
	wcos_bulk_partial_assert('preflight_unsupported_child_status' === wcos_bulk_partial_reason($mixed_plan, $mixed_fixture['child_ids'][1]), 'Unsupported child state did not become an explicit Skip.');
	$skipped_return_before = wcos_bulk_partial_return_journal_count($mixed_fixture['child_ids'][1]);
	$mixed_confirm = WCOS_Bulk_Return_Confirmation_Store::create($mixed_review['review_id'], $mixed_review['review_token'], $operator_id);
	$mixed_record = WCOS_Operation_Journal::get(wc_get_order($mixed_confirm['anchor_child_id']), $mixed_confirm['batch_id']);
	$mixed_verified = WCOS_Bulk_Return_Journal_Context::verify_request($mixed_record, $mixed_confirm['batch_token'], $operator_id);
	wcos_bulk_partial_assert(1 === count($mixed_verified['authority']['operation_map']) && 1 === $mixed_verified['progress']['total'], 'Confirm allocated operation authority outside the Eligible set.');
	$skipped_child = wc_get_order($mixed_fixture['child_ids'][1]); $skipped_child->set_status('pending'); $skipped_child->save();
	$mixed_resume = (new WCOS_Bulk_Return_Orchestrator())->resume($mixed_confirm['batch_id'], $mixed_confirm['anchor_child_id'], $mixed_confirm['batch_token'], $operator_id);
	wcos_bulk_partial_assert(3 === $mixed_resume['counts']['skipped'] && 3 === count($mixed_resume['skipped_results']) && 0 === $mixed_resume['cursor'], 'Resume reclassified or inserted a Skipped row after durable start.');
	$mixed_done = (new WCOS_Bulk_Return_Orchestrator())->advance($mixed_confirm['batch_id'], $mixed_confirm['anchor_child_id'], $mixed_confirm['batch_token'], $operator_id, 0);
	wcos_bulk_partial_assert('completed' === $mixed_done['status'] && 1 === $mixed_done['counts']['completed'] && 3 === $mixed_done['counts']['skipped'], 'Mixed batch did not execute only its frozen Eligible row.');
	wcos_bulk_partial_assert($skipped_return_before === wcos_bulk_partial_return_journal_count($mixed_fixture['child_ids'][1]) && 'trash' !== wc_get_order($mixed_fixture['child_ids'][1])->get_status(), 'Skipped sibling received a Return journal or mutation.');
	$mixed_terminal_resume = (new WCOS_Bulk_Return_Orchestrator())->resume($mixed_confirm['batch_id'], $mixed_confirm['anchor_child_id'], $mixed_confirm['batch_token'], $operator_id);
	wcos_bulk_partial_assert($mixed_done === $mixed_terminal_resume, 'Terminal Resume lost immutable Skipped disclosure.');

	/* Already-returned and missing-source rows remain isolated Skips beside valid work. */
	$fixtures[] = wcos_bulk_partial_fixture('already-returned-valid', 1); $returned_valid_index = count($fixtures) - 1; $returned_valid = $fixtures[$returned_valid_index];
	$returned_review = WCOS_Bulk_Return_Review_Store::create(array($mixed_fixture['child_ids'][0], $returned_valid['child_ids'][0]), $operator_id);
	$fixtures[$returned_valid_index]['review_ids'][] = $returned_review['review_id'];
	$returned_reason = wcos_bulk_partial_reason($returned_review['plan'], $mixed_fixture['child_ids'][0]);
	wcos_bulk_partial_assert(in_array($returned_reason, array('preflight_already_returned', 'preflight_source_relation_mismatch'), true) && 1 === $returned_review['plan']['eligible_count'], 'Already-returned row did not remain an isolated Skip: ' . $returned_reason);
	$returned_confirm = WCOS_Bulk_Return_Confirmation_Store::create($returned_review['review_id'], $returned_review['review_token'], $operator_id);
	$returned_done = (new WCOS_Bulk_Return_Orchestrator())->advance($returned_confirm['batch_id'], $returned_confirm['anchor_child_id'], $returned_confirm['batch_token'], $operator_id, 0);
	wcos_bulk_partial_assert('completed' === $returned_done['status'] && 1 === $returned_done['counts']['skipped'], 'Eligible work was lost beside an already-returned row.');

	$fixtures[] = wcos_bulk_partial_fixture('missing-source', 1); $missing_source = $fixtures[count($fixtures) - 1];
	$missing_source_order = wc_get_order($missing_source['original_id']); $missing_source_order->delete(true);
	$fixtures[] = wcos_bulk_partial_fixture('missing-source-valid', 1); $missing_source_valid_index = count($fixtures) - 1; $missing_source_valid = $fixtures[$missing_source_valid_index];
	$source_review = WCOS_Bulk_Return_Review_Store::create(array($missing_source['child_ids'][0], $missing_source_valid['child_ids'][0]), $operator_id);
	$fixtures[$missing_source_valid_index]['review_ids'][] = $source_review['review_id'];
	wcos_bulk_partial_assert('preflight_source_missing' === wcos_bulk_partial_reason($source_review['plan'], $missing_source['child_ids'][0]) && 1 === $source_review['plan']['eligible_count'], 'Missing source did not remain an isolated Skip.');
	$source_confirm = WCOS_Bulk_Return_Confirmation_Store::create($source_review['review_id'], $source_review['review_token'], $operator_id);
	$source_done = (new WCOS_Bulk_Return_Orchestrator())->advance($source_confirm['batch_id'], $source_confirm['anchor_child_id'], $source_confirm['batch_token'], $operator_id, 0);
	wcos_bulk_partial_assert('completed' === $source_done['status'] && 1 === $source_done['counts']['skipped'], 'Eligible work was lost beside a missing-source row.');

	/* Unauthorized disclosure is generic and contains no protected original/commercial details. */
	$fixtures[] = wcos_bulk_partial_fixture('unauthorized', 1); $unauthorized = $fixtures[count($fixtures) - 1];
	$fixtures[] = wcos_bulk_partial_fixture('unauthorized-valid', 1); $authorized_index = count($fixtures) - 1; $authorized = $fixtures[$authorized_index];
	$denied_order_id = $unauthorized['original_id'];
	$deny_capability = static function($allcaps, $caps, $args) use (&$denied_order_id) {
		$requested = isset($args[0]) ? (string) $args[0] : ''; $object_id = isset($args[2]) ? absint($args[2]) : 0;
		if ($denied_order_id === $object_id && in_array($requested, array('edit_shop_order', 'delete_shop_order'), true)) { foreach ((array) $caps as $capability) { $allcaps[$capability] = false; } }
		return $allcaps;
	};
	add_filter('user_has_cap', $deny_capability, 999, 3);
	$authorization_review = WCOS_Bulk_Return_Review_Store::create(array($unauthorized['child_ids'][0], $authorized['child_ids'][0]), $operator_id);
	$fixtures[$authorized_index]['review_ids'][] = $authorization_review['review_id']; $unauthorized_row = null;
	foreach ($authorization_review['plan']['selection_rows'] as $row) { if (absint($row['child_order_id']) === absint($unauthorized['child_ids'][0])) { $unauthorized_row = $row; } }
	wcos_bulk_partial_assert(is_array($unauthorized_row) && 'preflight_authorization_failed' === $unauthorized_row['reason'], 'Unauthorized pair did not become a generic Skip.');
	wcos_bulk_partial_assert(array('child') === array_keys($unauthorized_row['summary']) && false === strpos((string) $unauthorized_row['message'], (string) $unauthorized['original_id']) && false === strpos(wp_json_encode($unauthorized_row), 'Bulk Partial Private Street'), 'Unauthorized Skip leaked original or commercial detail.');
	$authorization_confirm = WCOS_Bulk_Return_Confirmation_Store::create($authorization_review['review_id'], $authorization_review['review_token'], $operator_id);
	$authorization_done = (new WCOS_Bulk_Return_Orchestrator())->advance($authorization_confirm['batch_id'], $authorization_confirm['anchor_child_id'], $authorization_confirm['batch_token'], $operator_id, 0);
	remove_filter('user_has_cap', $deny_capability, 999);
	wcos_bulk_partial_assert('completed' === $authorization_done['status'] && 1 === $authorization_done['counts']['skipped'], 'Authorized Eligible row did not execute beside an unauthorized Skip.');

	/* Confirm rejects both classification directions and permission drift before durability. */
	$fixtures[] = wcos_bulk_partial_fixture('confirm-drift', 2); $drift_index = count($fixtures) - 1; $drift = $fixtures[$drift_index];
	$drift_skipped = wc_get_order($drift['child_ids'][1]); $drift_skipped->set_status('cancelled'); $drift_skipped->save();
	$eligible_to_skipped = WCOS_Bulk_Return_Review_Store::create($drift['child_ids'], $operator_id); $fixtures[$drift_index]['review_ids'][] = $eligible_to_skipped['review_id'];
	$drift_eligible = wc_get_order($drift['child_ids'][0]); $drift_eligible->set_status('cancelled'); $drift_eligible->save(); $eligible_drift_rejected = false;
	$eligible_drift_journals = wcos_bulk_partial_journal_option_count();
	try { WCOS_Bulk_Return_Confirmation_Store::create($eligible_to_skipped['review_id'], $eligible_to_skipped['review_token'], $operator_id); }
	catch (WCOS_Bulk_Return_Confirmation_Exception $exception) { $eligible_drift_rejected = 'classification_changed' === $exception->get_reason(); }
	wcos_bulk_partial_assert($eligible_drift_rejected && $eligible_drift_journals === wcos_bulk_partial_journal_option_count(), 'Eligible-to-Skipped Confirm drift created a batch.');
	$drift_eligible->set_status('pending'); $drift_eligible->save();
	$skipped_to_eligible = WCOS_Bulk_Return_Review_Store::create($drift['child_ids'], $operator_id); $fixtures[$drift_index]['review_ids'][] = $skipped_to_eligible['review_id'];
	$drift_skipped = wc_get_order($drift['child_ids'][1]); $drift_skipped->set_status('pending'); $drift_skipped->save(); $skipped_drift_rejected = false;
	$skipped_drift_journals = wcos_bulk_partial_journal_option_count();
	try { WCOS_Bulk_Return_Confirmation_Store::create($skipped_to_eligible['review_id'], $skipped_to_eligible['review_token'], $operator_id); }
	catch (WCOS_Bulk_Return_Confirmation_Exception $exception) { $skipped_drift_rejected = 'classification_changed' === $exception->get_reason(); }
	wcos_bulk_partial_assert($skipped_drift_rejected && $skipped_drift_journals === wcos_bulk_partial_journal_option_count(), 'Skipped-to-Eligible Confirm drift created a batch.');
	$permission_review = WCOS_Bulk_Return_Review_Store::create(array($drift['child_ids'][0]), $operator_id); $fixtures[$drift_index]['review_ids'][] = $permission_review['review_id'];
	$denied_order_id = $drift['original_id']; add_filter('user_has_cap', $deny_capability, 999, 3); $permission_drift_rejected = false;
	$permission_drift_journals = wcos_bulk_partial_journal_option_count();
	try { WCOS_Bulk_Return_Confirmation_Store::create($permission_review['review_id'], $permission_review['review_token'], $operator_id); }
	catch (WCOS_Bulk_Return_Confirmation_Exception $exception) { $permission_drift_rejected = 'classification_changed' === $exception->get_reason(); }
	remove_filter('user_has_cap', $deny_capability, 999);
	wcos_bulk_partial_assert($permission_drift_rejected && $permission_drift_journals === wcos_bulk_partial_journal_option_count(), 'Pre-Confirm permission drift created a batch.');

	/* Post-Confirm permission loss is runtime blocked; later Eligible rows stop, Skipped stays Skipped. */
	$fixtures[] = wcos_bulk_partial_fixture('runtime-stop', 4); $runtime_index = count($fixtures) - 1; $runtime = $fixtures[$runtime_index];
	$runtime_skipped = wc_get_order($runtime['child_ids'][1]); $runtime_skipped->set_status('cancelled'); $runtime_skipped->save();
	$runtime_review = WCOS_Bulk_Return_Review_Store::create($runtime['child_ids'], $operator_id); $fixtures[$runtime_index]['review_ids'][] = $runtime_review['review_id'];
	$runtime_confirm = WCOS_Bulk_Return_Confirmation_Store::create($runtime_review['review_id'], $runtime_review['review_token'], $operator_id);
	$runtime_record = WCOS_Operation_Journal::get(wc_get_order($runtime_confirm['anchor_child_id']), $runtime_confirm['batch_id']);
	$runtime_verified = WCOS_Bulk_Return_Journal_Context::verify_request($runtime_record, $runtime_confirm['batch_token'], $operator_id);
	wcos_bulk_partial_assert(3 === count(WCOS_Bulk_Return_Batch_Plan::execution_rows($runtime_verified['authority']['plan'])), 'Runtime fail-stop fixture did not freeze three Eligible rows.');
	$runtime_first = (new WCOS_Bulk_Return_Orchestrator())->advance($runtime_confirm['batch_id'], $runtime_confirm['anchor_child_id'], $runtime_confirm['batch_token'], $operator_id, 0);
	wcos_bulk_partial_assert(1 === $runtime_first['cursor'] && 1 === $runtime_first['counts']['completed'], 'Runtime fail-stop fixture did not complete its first Eligible row.');
	$denied_order_id = $runtime['original_id']; add_filter('user_has_cap', $deny_capability, 999, 3);
	$runtime_stopped = (new WCOS_Bulk_Return_Orchestrator())->advance($runtime_confirm['batch_id'], $runtime_confirm['anchor_child_id'], $runtime_confirm['batch_token'], $operator_id, 1);
	remove_filter('user_has_cap', $deny_capability, 999);
	wcos_bulk_partial_assert('blocked' === $runtime_stopped['status'] && 1 === $runtime_stopped['counts']['completed'] && 1 === $runtime_stopped['counts']['blocked'] && 1 === $runtime_stopped['counts']['not_run_blocked'] && 1 === $runtime_stopped['counts']['skipped'], 'Post-Confirm permission drift did not preserve fail-stop and Skipped distinctions.');
	wcos_bulk_partial_assert('skipped' === $runtime_stopped['skipped_results'][0]['status'] && 'not_run_blocked' === $runtime_stopped['results'][2]['status'], 'Runtime failure converted a pre-existing Skip or advanced a later Eligible row.');
	wcos_bulk_partial_assert(0 === wcos_bulk_partial_return_journal_count($runtime['child_ids'][1]) && 'trash' !== wc_get_order($runtime['child_ids'][1])->get_status(), 'Runtime stop mutated the pre-existing Skipped sibling.');

	/* Cross-operation source ambiguity fails the whole Review instead of guessing row skips. */
	$fixtures[] = wcos_bulk_partial_separate_operations_fixture('graph'); $ambiguous = $fixtures[count($fixtures) - 1]; $ambiguity_rejected = false;
	try { WCOS_Bulk_Return_Batch_Plan::build($ambiguous['child_ids']); }
	catch (WCOS_Bulk_Return_Batch_Exception $exception) { $ambiguity_rejected = 'ambiguous_participant_graph' === $exception->get_reason(); }
	wcos_bulk_partial_assert($ambiguity_rejected, 'Cross-operation source ambiguity became row Skips instead of failing Review.');
	$tampered_plan = WCOS_Bulk_Return_Batch_Plan::build(array($ambiguous['original_id'], $ambiguous['child_ids'][0]));
	$tampered_plan['skipped_rows'][0]['child_order_id'] = $ambiguous['child_ids'][0];
	$tampered_plan['batch_fingerprint'] = WCOS_Bulk_Return_Batch_Plan::fingerprint($tampered_plan);
	$tampered_rejected = false;
	try { WCOS_Bulk_Return_Batch_Plan::assert_valid($tampered_plan); }
	catch (WCOS_Bulk_Return_Batch_Exception $exception) { $tampered_rejected = 'plan_invalid' === $exception->get_reason(); }
	wcos_bulk_partial_assert($tampered_rejected, 'Re-fingerprinted v2 disclosure/execution set tampering was accepted.');

	/* New code reads and executes exact pre-v2 durable authority without migration. */
	$fixtures[] = wcos_bulk_partial_fixture('legacy-v1-replay', 1); $v1_fixture = $fixtures[count($fixtures) - 1];
	$v1_plan = wcos_bulk_partial_downgrade_v1(WCOS_Bulk_Return_Batch_Plan::build($v1_fixture['child_ids'])); WCOS_Bulk_Return_Batch_Plan::assert_valid($v1_plan);
	$v1_batch_id = wp_generate_uuid4(); $v1_operation_id = wp_generate_uuid4(); $v1_token = wp_generate_password(48, false, false);
	$v1_context = WCOS_Bulk_Return_Journal_Context::create($v1_plan, $v1_batch_id, $operator_id, $v1_token, array($v1_operation_id)); $v1_anchor = wc_get_order($v1_fixture['child_ids'][0]);
	wcos_bulk_partial_assert(WCOS_Operation_Journal::start($v1_anchor, $v1_batch_id, WCOS_Bulk_Return_Journal_Context::TYPE, $v1_context, $v1_context['bulk_return_batch']['authority_fingerprint']), 'Legacy v1 coordinator fixture could not start.');
	$v1_done = (new WCOS_Bulk_Return_Orchestrator())->advance($v1_batch_id, $v1_anchor->get_id(), $v1_token, $operator_id, 0);
	wcos_bulk_partial_assert('completed' === $v1_done['status'] && 1 === $v1_done['counts']['completed'] && !isset($v1_done['skipped_results']), 'Legacy v1 durable batch was reinterpreted or failed replay.');
	$stale_v1 = $v1_plan; $stale_v1['all_eligible'] = false; $stale_v1['batch_fingerprint'] = WCOS_Bulk_Return_Batch_Plan::fingerprint($stale_v1); $stale_v1_rejected = false;
	try { WCOS_Bulk_Return_Batch_Plan::assert_review_current($stale_v1); }
	catch (WCOS_Bulk_Return_Batch_Exception $exception) { $stale_v1_rejected = 'batch_ineligible' === $exception->get_reason(); }
	wcos_bulk_partial_assert($stale_v1_rejected, 'Stale v1 mixed Review was reinterpreted as v2 partial authority.');

	echo "bulk-return-partial-eligibility-ok mixed=eligible-only skipped=immutable confirm_drift=both-directions privacy=generic runtime=fail-stop ambiguity=closed legacy_v1=replayed\n";
} finally {
	if (isset($deny_capability)) { remove_filter('user_has_cap', $deny_capability, 999); }
	wcos_bulk_partial_cleanup($fixtures);
}
