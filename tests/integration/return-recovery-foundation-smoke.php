<?php

if (!defined('ABSPATH')) { exit(1); }

function wcos_return_recovery_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_return_recovery_add($left, $right, $precision) {
	return WCOS_Decimal::from_units(WCOS_Decimal::to_units($left, $precision) + WCOS_Decimal::to_units($right, $precision), $precision);
}

function wcos_return_recovery_add_taxes(array $left, array $right, $precision) {
	$result = array('subtotal' => array(), 'total' => array());
	foreach (array('subtotal', 'total') as $bucket) {
		$rate_ids = array_unique(array_merge(array_keys(isset($left[$bucket]) ? $left[$bucket] : array()), array_keys(isset($right[$bucket]) ? $right[$bucket] : array())));
		foreach ($rate_ids as $rate_id) {
			$result[$bucket][(int) $rate_id] = wcos_return_recovery_add(
				isset($left[$bucket][$rate_id]) ? $left[$bucket][$rate_id] : '0',
				isset($right[$bucket][$rate_id]) ? $right[$bucket][$rate_id] : '0',
				$precision
			);
		}
		ksort($result[$bucket], SORT_NUMERIC);
	}
	return $result;
}

function wcos_return_recovery_event($stage, WC_Order $child, WC_Order $original, $operation_id) {
	do_action('wcos_return_recovery_harness_checkpoint', sanitize_key((string) $stage), $child, $original, sanitize_key((string) $operation_id));
}

function wcos_return_recovery_retire_observed(WC_Order $child) {
	$hooks = array(
		'wp_trash_post', 'woocommerce_email_before_order_table', 'woocommerce_webhook_delivery',
		'woocommerce_analytics_update_order_stats', 'woocommerce_product_before_set_stock',
		'woocommerce_product_set_stock', 'woocommerce_new_order_note',
	);
	$counts = array_fill_keys($hooks, 0);
	$observer = static function() use (&$counts) {
		$hook = current_filter();
		if (isset($counts[$hook])) { $counts[$hook]++; }
	};
	foreach ($hooks as $hook) { add_action($hook, $observer, PHP_INT_MAX, 10); }
	$child_id = $child->get_id();
	try { $child->delete(false); }
	finally { foreach ($hooks as $hook) { remove_action($hook, $observer, PHP_INT_MAX); } }
	wcos_return_recovery_assert($counts['wp_trash_post'] <= 1, 'Return non-force retirement emitted duplicate trash transitions.');
	foreach (array('woocommerce_email_before_order_table', 'woocommerce_webhook_delivery', 'woocommerce_product_before_set_stock', 'woocommerce_product_set_stock', 'woocommerce_new_order_note') as $silent_hook) {
		wcos_return_recovery_assert(0 === $counts[$silent_hook], 'Return retirement emitted an unapproved side effect: ' . $silent_hook);
	}
	wcos_return_recovery_assert($counts['woocommerce_analytics_update_order_stats'] <= 1, 'Return retirement emitted duplicate analytics updates.');
	$GLOBALS['wcos_return_retirement_side_effects'] = $counts;
	return wc_get_order($child_id);
}

/** Test harness only: applies the immutable plan without registering a production Return route. */
function wcos_return_recovery_execute(WC_Order $child, $operation_id, $stop_after = '') {
	$report = WCOS_Return_Preflight::assert_supported($child, true);
	$plan = $report['return_plan'];
	$lineage = $report['lineage_authority'];
	$original = wc_get_order($plan['source_order_id']);
	$context = WCOS_Return_Journal_Context::create($child, $original, $plan, $lineage, $lineage['source_evolution_authority']);
	$pair_fingerprint = $context['return_pair']['pair_fingerprint'];
	$lease = WCOS_Multi_Order_Lease::acquire(array($child->get_id(), $original->get_id()), $operation_id);
	wcos_return_recovery_assert($lease instanceof WCOS_Multi_Order_Lease, 'Return harness could not acquire pair leases.');
	$stock_guard = WCOS_Stock_Side_Effect_Guard::begin($operation_id);
	try {
		wcos_return_recovery_event('before_journal_persistence', $child, $original, $operation_id);
		wcos_return_recovery_assert(WCOS_Operation_Journal::start($child, $operation_id, 'return', $context, $pair_fingerprint), 'Return journal could not start.');
		$record = WCOS_Operation_Journal::get($child, $operation_id);
		$snapshot = $record['context']['return_recovery_snapshot'];
		wcos_return_recovery_event('after_durable_preparation', $child, $original, $operation_id);
		$templates = WCOS_Tax_Item_Synchronizer::templates($original) + WCOS_Tax_Item_Synchronizer::templates($child);
		$added_ids = array();
		$destination_ids = array();

		wcos_return_recovery_event('before_original_write', $child, $original, $operation_id);
		foreach ($plan['lines'] as $source_item_id => $line) {
			$child_item = $child->get_item($line['child_item_id']);
			wcos_return_recovery_assert($child_item instanceof WC_Order_Item_Product, 'Return child plan line disappeared.');
			if (WCOS_Return_Plan::DESTINATION_RESIDUAL_SOURCE_ITEM === $line['destination']) {
				$destination = $original->get_item($line['destination_source_item_id']);
				wcos_return_recovery_assert($destination instanceof WC_Order_Item_Product, 'Return residual destination disappeared.');
				$props = array(
					'quantity' => wcos_return_recovery_add($destination->get_quantity(), $line['quantity'], 6),
					'subtotal' => wcos_return_recovery_add($destination->get_subtotal(), $line['subtotal'], $plan['price_precision']),
					'total' => wcos_return_recovery_add($destination->get_total(), $line['total'], $plan['price_precision']),
					'subtotal_tax' => wcos_return_recovery_add($destination->get_subtotal_tax(), $line['subtotal_tax'], $plan['price_precision']),
					'total_tax' => wcos_return_recovery_add($destination->get_total_tax(), $line['total_tax'], $plan['price_precision']),
					'taxes' => wcos_return_recovery_add_taxes($destination->get_taxes(), $line['taxes'], $plan['price_precision']),
				);
				wcos_return_recovery_assert(!is_wp_error($destination->set_props($props)), 'Return residual line staging failed.');
				$destination->save();
				$destination_ids[$source_item_id] = $destination->get_id();
			} else {
				$destination = WCOS_Order_Item_Cloner::product($child_item, array(), false, WCOS_Order_Item_Meta_Policy::CONTEXT_RETURN);
				$destination->delete_meta_data('_reduced_stock');
				$original->add_item($destination);
				$original->save();
				$added_ids[] = $destination->get_id();
				$destination_ids[$source_item_id] = $destination->get_id();
			}
			wcos_return_recovery_event('after_original_line_before_checkpoint', $child, wc_get_order($original->get_id()), $operation_id);
		}
		$original = wc_get_order($original->get_id());
		WCOS_Tax_Item_Synchronizer::synchronize($original, $templates, $plan['price_precision'], true, WCOS_Order_Item_Meta_Policy::CONTEXT_RETURN);
		foreach ($original->get_items('tax') as $tax_item) { $tax_item->save(); }
		WCOS_Order_Totals_Rebuilder::rebuild($original, $plan['price_precision']);
		$original->save();
		$original_persisted_child_state = WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', wc_get_order($child->get_id()));
		$original_persisted_state = WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original, $added_ids);
		wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_original_commercial_persisted', array(
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::ORIGINAL_PERSISTED,
			'return_original_added_item_ids' => $added_ids,
			'return_destination_item_ids' => $destination_ids,
			'return_child_state_after' => $original_persisted_child_state,
			'return_original_state_after' => $original_persisted_state,
		)), 'Original commercial checkpoint failed.');
		wcos_return_recovery_event('after_original_persisted', $child, $original, $operation_id);

		/* Observed safe policy: neutralize child first, then activate original ownership. */
		$child = wc_get_order($child->get_id());
		wcos_return_recovery_event('before_child_ownership_neutralization', $child, $original, $operation_id);
		foreach ($plan['lines'] as $line) {
			$item = $child->get_item($line['child_item_id']);
			$item->delete_meta_data('_reduced_stock');
			$item->save();
			wcos_return_recovery_event('during_child_ownership_neutralization', wc_get_order($child->get_id()), $original, $operation_id);
		}
		$child->get_data_store()->set_stock_reduced($child->get_id(), false);
		$child_neutralized = wc_get_order($child->get_id());
		wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_child_stock_neutralized', array(
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::CHILD_OWNERSHIP_NEUTRALIZED,
			'return_child_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child_neutralized),
			'return_original_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', wc_get_order($original->get_id()), $added_ids),
		)), 'Child stock-neutralization checkpoint failed.');
		wcos_return_recovery_event('after_child_ownership_neutralized', $child_neutralized, $original, $operation_id);

		$original = wc_get_order($original->get_id());
		wcos_return_recovery_event('before_original_ownership_activation', $child_neutralized, $original, $operation_id);
		foreach ($plan['lines'] as $source_item_id => $line) {
			$destination = $original->get_item($destination_ids[$source_item_id]);
			$before = $destination->get_meta('_reduced_stock', true);
			$before = '' === $before || null === $before ? '0.000000' : WCOS_Decimal::normalize($before, 6);
			$child_reduced = null === $line['reduced_stock'] ? '0.000000' : $line['reduced_stock'];
			$combined = wcos_return_recovery_add($before, $child_reduced, 6);
			$destination->delete_meta_data('_reduced_stock');
			if (0 !== WCOS_Decimal::to_units($combined, 6)) { $destination->add_meta_data('_reduced_stock', $combined, true); }
			$destination->save();
			wcos_return_recovery_event('after_original_ownership_line_before_flag', $child_neutralized, wc_get_order($original->get_id()), $operation_id);
		}
		$original->get_data_store()->set_stock_reduced(
			$original->get_id(),
			WCOS_Return_Recovery_Snapshot::has_active_operational_stock_ownership(wc_get_order($original->get_id()))
		);
		$original_activated = wc_get_order($original->get_id());
		wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_original_stock_activated', array(
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::ORIGINAL_OWNERSHIP_ACTIVATED,
			'return_child_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child_neutralized),
			'return_original_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original_activated, $added_ids),
		)), 'Original stock-ownership checkpoint failed.');
		wcos_return_recovery_event('after_original_ownership_activated', $child_neutralized, $original_activated, $operation_id);

		$child = wc_get_order($child->get_id());
		wcos_return_recovery_event('before_child_retirement', $child, $original_activated, $operation_id);
		$child = wcos_return_recovery_retire_observed($child);
		wcos_return_recovery_event('after_child_retirement_before_checkpoint', $child, $original_activated, $operation_id);
		wcos_return_recovery_assert($child instanceof WC_Order && 'trash' === $child->get_status(), 'Non-force Return retirement did not archive the child.');
		$original = wc_get_order($original->get_id());
		$retired_child_state = WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child);
		$retired_original_state = WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original, $added_ids);
		wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_child_retired', array(
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::CHILD_RETIRED,
			'return_forward_repair_allowed' => true,
			'return_original_added_item_ids' => $added_ids,
			'return_destination_item_ids' => $destination_ids,
			'return_retirement_side_effects' => isset($GLOBALS['wcos_return_retirement_side_effects']) ? $GLOBALS['wcos_return_retirement_side_effects'] : array(),
			'return_child_state_after' => $retired_child_state,
			'return_original_state_after' => $retired_original_state,
		)), 'Child retirement checkpoint failed.');
		if ('child_retired' === sanitize_key((string) $stop_after)) {
			throw new RuntimeException('return_harness_stop_after_child_retired');
		}
		wcos_return_recovery_event('after_child_retired_checkpoint', $child, $original, $operation_id);

		WCOS_Return_Participation::persist($child, $original, $operation_id, $pair_fingerprint);
		$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
		wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_child_relation_persisted', array(
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::CHILD_RELATION,
			'return_forward_repair_allowed' => true,
			'return_child_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child),
			'return_original_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original, $added_ids),
		)), 'Return child relation checkpoint failed.');
		WCOS_Return_Participation::remove_active_split_relation($child, $original, $operation_id, $pair_fingerprint);
		$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
		wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_active_split_relation_cleaned', array(
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::ACTIVE_SPLIT_CLEANED,
			'return_forward_repair_allowed' => true,
			'return_child_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child),
			'return_original_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original, $added_ids),
		)), 'Return active Split cleanup checkpoint failed.');
		wcos_return_recovery_event('after_active_split_cleanup', $child, $original, $operation_id);
		WCOS_Return_Recovery_Snapshot::assert_physical_stock_unchanged($snapshot, $child, $plan);
		WCOS_Return_Recovery_Snapshot::assert_success_contract($snapshot, $child, $original);
		WCOS_Return_Recovery_Snapshot::assert_single_operational_owner($snapshot, $child, $original, $destination_ids);
		$child_after = WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child);
		$original_after = WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original, $added_ids);
		$after_context = array(
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::RELATIONS_COMPLETE,
			'return_forward_repair_allowed' => true,
			'return_original_added_item_ids' => $added_ids,
			'return_destination_item_ids' => $destination_ids,
			'return_child_state_after' => $child_after,
			'return_original_state_after' => $original_after,
			'return_child_signature_after' => WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'child', $child),
			'return_original_signature_after' => WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'original', $original, $added_ids),
		);
		wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_relations_completed', $after_context), 'Return relations checkpoint failed.');
		wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_pair_verified', array(
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::VERIFIED,
		)), 'Return verification checkpoint failed.');
		wcos_return_recovery_event('after_pair_verification', $child, $original, $operation_id);
		$after_context['return_recovery_state'] = WCOS_Return_Recovery_State_Graph::COMMITTED;
		wcos_return_recovery_assert(WCOS_Operation_Journal::mark_committed($child, $operation_id, $after_context), 'Return commit checkpoint failed.');
		wcos_return_recovery_event('after_commit_before_complete', $child, $original, $operation_id);
		$committed = WCOS_Operation_Journal::get(wc_get_order($child->get_id()), $operation_id);
		wcos_return_recovery_assert(WCOS_Operation_Journal::complete($child, $operation_id, array(
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::COMPLETED,
			'return_terminal_result' => WCOS_Return_Journal_Context::create_terminal_result($committed, $child, $original),
		)), 'Return completion checkpoint failed.');
		$completed = WCOS_Operation_Journal::get(wc_get_order($child->get_id()), $operation_id);
		$terminal_once = WCOS_Return_Journal_Context::terminal_result_from_record($completed);
		wcos_return_recovery_assert($terminal_once === WCOS_Return_Journal_Context::terminal_result_from_record($completed), 'Return terminal response replay was not deterministic.');
		return array('child' => $child, 'original' => $original, 'record' => $completed, 'snapshot' => $snapshot);
	} finally {
		WCOS_Stock_Side_Effect_Guard::end($stock_guard);
		$lease->release();
	}
}

/** Exercises the real recovery coordinator before any commercial Return write. */
function wcos_return_recovery_compensation_case($user_id) {
	$product = new WC_Product_Simple();
	$product->set_name('WCOS Return recovery compensation');
	$product->set_regular_price('12.00');
	$product->set_manage_stock(true); $product->set_stock_quantity(40); $product->save();
	$source = wc_create_order(); $source->set_status('pending'); $source->set_currency('USD');
	$source_item_id = $source->add_product($product, 2); $source->calculate_totals(false); $source->save();
	$source_item = $source->get_item($source_item_id); $source_item->add_meta_data('_reduced_stock', '2.000000', true); $source_item->save();
	$source->get_data_store()->set_stock_reduced($source->get_id(), true);
	$split_operation = 'return-recovery-compensation-split-' . wp_generate_uuid4();
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($source->get_id()), array(
		'child-recovery' => array($source_item_id => '1.000000'),
	), $split_operation, 2);
	wcos_return_recovery_assert(1 === count($children), 'Return compensation fixture did not create one child.');
	$child = wc_get_order($children[0]->get_id()); $original = wc_get_order($source->get_id());
	$child_before = WCOS_Order_Contract_Snapshot::source_signature($child);
	$original_before = WCOS_Order_Contract_Snapshot::source_signature($original);
	$active_before = $original->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true);
	$report = WCOS_Return_Preflight::assert_supported($child, true);
	$context = WCOS_Return_Journal_Context::create($child, $original, $report['return_plan'], $report['lineage_authority'], $report['lineage_authority']['source_evolution_authority']);
	$operation_id = 'return-recovery-compensation-' . wp_generate_uuid4();
	$pair_fingerprint = $context['return_pair']['pair_fingerprint'];
	wcos_return_recovery_assert(WCOS_Operation_Journal::start($child, $operation_id, 'return', $context, $pair_fingerprint), 'Return compensation journal could not start.');
	$record = WCOS_Operation_Journal::get($child, $operation_id);
	$snapshot = $record['context']['return_recovery_snapshot'];
	$child_checkpoint = WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child);
	$original_checkpoint = WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original);
	wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_original_staging', array(
		'return_recovery_state' => WCOS_Return_Recovery_State_Graph::ORIGINAL_STAGING,
		'return_original_added_item_ids' => array(),
		'return_child_state_after' => $child_checkpoint,
		'return_original_state_after' => $original_checkpoint,
	)), 'Return compensation durable participant checkpoints failed.');
	wcos_return_recovery_assert(WCOS_Operation_Journal::require_recovery($child, $operation_id, array(
		'return_recovery_state' => WCOS_Return_Recovery_State_Graph::COMPENSATING,
	)), 'Return compensation recovery dispatch failed.');
	$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
	$terminal = WCOS_Operation_Journal::get($child, $operation_id);
	wcos_return_recovery_assert('compensated' === $terminal['status'] && WCOS_Return_Recovery_State_Graph::COMPENSATED === WCOS_Return_Recovery_State_Graph::assert_record($terminal), 'Return coordinator did not reach compensated state.');
	wcos_return_recovery_assert($child_before === WCOS_Order_Contract_Snapshot::source_signature($child), 'Return compensation changed child commercial state.');
	wcos_return_recovery_assert($original_before === WCOS_Order_Contract_Snapshot::source_signature($original), 'Return compensation changed original commercial state.');
	wcos_return_recovery_assert($active_before === $original->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true), 'Return compensation changed active Split authority.');
	wcos_return_recovery_assert(empty(WCOS_Manual_Reconciliation_Blocker::active_operation_ids($child)) && empty(WCOS_Manual_Reconciliation_Blocker::active_operation_ids($original)), 'Safe Return compensation created a manual blocker.');
	WCOS_Operation_Journal::delete($child, $operation_id);
	$forward_operation = 'return-recovery-forward-' . wp_generate_uuid4();
	$stopped = false;
	try {
		wcos_return_recovery_execute($child, $forward_operation, 'child_retired');
	} catch (RuntimeException $exception) {
		$stopped = 'return_harness_stop_after_child_retired' === $exception->getMessage();
		if (!$stopped) { throw $exception; }
	}
	wcos_return_recovery_assert($stopped, 'Return forward-recovery fixture did not stop after child retirement.');
	$child = wc_get_order($child->get_id());
	wcos_return_recovery_assert(WCOS_Operation_Journal::require_recovery($child, $forward_operation), 'Return forward-recovery dispatch failed.');
	$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
	$forward_terminal = WCOS_Operation_Journal::get($child, $forward_operation);
	wcos_return_recovery_assert('completed' === $forward_terminal['status'] && WCOS_Return_Recovery_State_Graph::COMPLETED === WCOS_Return_Recovery_State_Graph::assert_record($forward_terminal), 'Return coordinator did not complete the child-retired forward window.');
	wcos_return_recovery_assert('trash' === $child->get_status() && !in_array($child->get_id(), (array) $original->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true), true), 'Return forward recovery did not preserve retirement and active relation cleanup.');
	WCOS_Operation_Journal::delete($child, $forward_operation); WCOS_Operation_Journal::delete($original, $split_operation);
	$child->delete(true); $original->delete(true); wp_delete_post($product->get_id(), true);
}

function wcos_return_recovery_fixture($label) {
	$product = new WC_Product_Simple();
	$product->set_name('WCOS Return window ' . $label); $product->set_regular_price('9.00');
	$product->set_manage_stock(true); $product->set_stock_quantity(25); $product->set_backorders('yes'); $product->save();
	$source = wc_create_order(); $source->set_status('pending'); $source->set_currency('USD');
	$item_id = $source->add_product($product, 2); $source->calculate_totals(false); $source->save();
	$item = $source->get_item($item_id); $item->add_meta_data('_reduced_stock', '2.000000', true); $item->save();
	$source->get_data_store()->set_stock_reduced($source->get_id(), true);
	$split_operation = 'return-window-split-' . wp_generate_uuid4();
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($source->get_id()), array(
		'child-window' => array($item_id => '1.000000'),
	), $split_operation, 2);
	wcos_return_recovery_assert(1 === count($children), 'Return crash-window fixture did not create one child.');
	return array(
		'product_id' => $product->get_id(), 'stock_before' => WCOS_Decimal::normalize($product->get_stock_quantity(), 6),
		'original_id' => $source->get_id(), 'child_id' => $children[0]->get_id(), 'split_operation' => $split_operation,
	);
}

function wcos_return_recovery_cleanup_fixture(array $fixture, $return_operation) {
	$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
	delete_option('wcos_manual_reconcile_block_' . $fixture['child_id']);
	delete_option('wcos_manual_reconcile_block_' . $fixture['original_id']);
	if ($child instanceof WC_Order) { WCOS_Operation_Journal::delete($child, $return_operation); }
	if ($original instanceof WC_Order) { WCOS_Operation_Journal::delete($original, $fixture['split_operation']); }
	if ($child instanceof WC_Order) { $child->delete(true); }
	if ($original instanceof WC_Order) { $original->delete(true); }
	wp_delete_post($fixture['product_id'], true);
}

function wcos_return_recovery_crash_matrix() {
	$expected = array(
		'before_journal_persistence' => 'reject_before_write',
		'after_durable_preparation' => 'compensated',
		'before_original_write' => 'compensated',
		'after_original_line_before_checkpoint' => 'manual_reconciliation',
		'after_original_persisted' => 'compensated',
		'before_child_ownership_neutralization' => 'compensated',
		'during_child_ownership_neutralization' => 'manual_reconciliation',
		'after_child_ownership_neutralized' => 'compensated',
		'before_original_ownership_activation' => 'compensated',
		'after_original_ownership_line_before_flag' => 'manual_reconciliation',
		'after_original_ownership_activated' => 'compensated',
		'before_child_retirement' => 'compensated',
		'after_child_retirement_before_checkpoint' => 'manual_reconciliation',
		'after_child_retired_checkpoint' => 'completed',
		'after_one_reciprocal_relation' => 'completed',
		'after_active_split_cleanup' => 'completed',
		'after_pair_verification' => 'completed',
		'after_commit_before_complete' => 'completed',
	);
	$evidence = array();
	foreach ($expected as $stage_under_test => $expected_outcome) {
		$fixture = wcos_return_recovery_fixture($stage_under_test);
		$operation_id = 'return-window-' . wp_generate_uuid4();
		$hit = false;
		$fault = static function($stage) use ($stage_under_test, &$hit) {
			if (!$hit && $stage_under_test === $stage) {
				$hit = true;
				throw new WCOS_Return_Recovery_Interruption_Exception('Injected ' . $stage_under_test);
			}
		};
		add_action('wcos_return_recovery_harness_checkpoint', $fault, PHP_INT_MAX, 1);
		add_action('wcos_return_recovery_checkpoint', $fault, PHP_INT_MAX, 1);
		try {
			wcos_return_recovery_execute(wc_get_order($fixture['child_id']), $operation_id);
		} catch (WCOS_Return_Recovery_Interruption_Exception $exception) {
			/* Exact injected crash boundary. */
		} finally {
			remove_action('wcos_return_recovery_harness_checkpoint', $fault, PHP_INT_MAX);
			remove_action('wcos_return_recovery_checkpoint', $fault, PHP_INT_MAX);
		}
		wcos_return_recovery_assert($hit, 'Return crash window did not execute: ' . $stage_under_test);
		$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
		if ('reject_before_write' === $expected_outcome) {
			wcos_return_recovery_assert(!is_array(WCOS_Operation_Journal::get($child, $operation_id)), 'Before-journal crash unexpectedly persisted authority.');
			$actual = 'reject_before_write';
		} else {
			wcos_return_recovery_assert(WCOS_Operation_Journal::require_recovery($child, $operation_id), 'Return crash-window recovery did not dispatch: ' . $stage_under_test);
			$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
			$record = WCOS_Operation_Journal::get($child, $operation_id);
			$actual = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
			if ('manual_reconciliation' === $actual) {
				wcos_return_recovery_assert(WCOS_Manual_Reconciliation_Blocker::has_active($child) && WCOS_Manual_Reconciliation_Blocker::has_active($original), 'Return manual outcome did not block both participants: ' . $stage_under_test);
			}
		}
		wcos_return_recovery_assert($expected_outcome === $actual, 'Unexpected Return crash outcome for ' . $stage_under_test . ': ' . $actual);
		$product = wc_get_product($fixture['product_id']);
		wcos_return_recovery_assert($product instanceof WC_Product && $fixture['stock_before'] === WCOS_Decimal::normalize($product->get_stock_quantity(), 6), 'Return crash recovery changed physical stock: ' . $stage_under_test);
		$evidence[] = array('window' => $stage_under_test, 'outcome' => $actual);
		wcos_return_recovery_cleanup_fixture($fixture, $operation_id);
	}
	return $evidence;
}

function wcos_return_recovery_interrupted_fixture($stage) {
	$fixture = wcos_return_recovery_fixture($stage);
	$operation_id = 'return-adversarial-' . wp_generate_uuid4();
	$hit = false;
	$fault = static function($actual) use ($stage, &$hit) {
		if (!$hit && $stage === $actual) { $hit = true; throw new WCOS_Return_Recovery_Interruption_Exception('Injected ' . $stage); }
	};
	add_action('wcos_return_recovery_harness_checkpoint', $fault, PHP_INT_MAX, 1);
	add_action('wcos_return_recovery_checkpoint', $fault, PHP_INT_MAX, 1);
	try { wcos_return_recovery_execute(wc_get_order($fixture['child_id']), $operation_id); }
	catch (WCOS_Return_Recovery_Interruption_Exception $exception) {}
	finally {
		remove_action('wcos_return_recovery_harness_checkpoint', $fault, PHP_INT_MAX);
		remove_action('wcos_return_recovery_checkpoint', $fault, PHP_INT_MAX);
	}
	wcos_return_recovery_assert($hit, 'Adversarial Return fixture did not reach ' . $stage);
	$fixture['return_operation'] = $operation_id;
	return $fixture;
}

function wcos_return_recovery_adversarial_matrix() {
	$evidence = array();

	/* Compensation is resumable after interruption between participant restores. */
	$fixture = wcos_return_recovery_interrupted_fixture('after_original_persisted');
	$interrupted = false;
	$fault = static function($stage) use (&$interrupted) {
		if (!$interrupted && 'after_original_restore' === $stage) { $interrupted = true; throw new WCOS_Return_Recovery_Interruption_Exception('Injected compensation interruption'); }
	};
	add_action('wcos_return_recovery_checkpoint', $fault, PHP_INT_MAX, 1);
	WCOS_Operation_Journal::require_recovery(wc_get_order($fixture['child_id']), $fixture['return_operation']);
	remove_action('wcos_return_recovery_checkpoint', $fault, PHP_INT_MAX);
	$child = wc_get_order($fixture['child_id']);
	wcos_return_recovery_assert($interrupted && 'compensating' === WCOS_Operation_Journal::get($child, $fixture['return_operation'])['status'], 'Return compensation interruption was not resumable.');
	WCOS_Operation_Journal::fail($child, $fixture['return_operation']);
	wcos_return_recovery_assert('compensated' === WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $fixture['return_operation'])['status'], 'Return compensation retry did not finish.');
	$evidence[] = array('case' => 'compensation_interruption_retry', 'outcome' => 'compensated');
	wcos_return_recovery_cleanup_fixture($fixture, $fixture['return_operation']);

	/* Foreign pair leases keep recovery retryable and prevent writes. */
	$fixture = wcos_return_recovery_interrupted_fixture('after_original_persisted');
	$foreign = WCOS_Multi_Order_Lease::acquire(array($fixture['child_id'], $fixture['original_id']), 'foreign-return-owner-' . wp_generate_uuid4());
	wcos_return_recovery_assert($foreign instanceof WCOS_Multi_Order_Lease, 'Foreign Return contention lease could not be acquired.');
	WCOS_Operation_Journal::require_recovery(wc_get_order($fixture['child_id']), $fixture['return_operation']);
	$record = WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $fixture['return_operation']);
	wcos_return_recovery_assert('recovery_required' === $record['status'], 'Foreign lease contention did not preserve retryable recovery authority.');
	$foreign->release();
	WCOS_Operation_Journal::fail(wc_get_order($fixture['child_id']), $fixture['return_operation']);
	wcos_return_recovery_assert('compensated' === WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $fixture['return_operation'])['status'], 'Return recovery did not resume after foreign lease release.');
	$evidence[] = array('case' => 'pair_lease_contention', 'outcome' => 'recovery_required_then_compensated');
	wcos_return_recovery_cleanup_fixture($fixture, $fixture['return_operation']);

	foreach (array('child_drift', 'original_drift') as $drift) {
		$fixture = wcos_return_recovery_interrupted_fixture('after_original_persisted');
		$order = wc_get_order('child_drift' === $drift ? $fixture['child_id'] : $fixture['original_id']);
		$order->set_status('on-hold'); $order->save();
		WCOS_Operation_Journal::require_recovery(wc_get_order($fixture['child_id']), $fixture['return_operation']);
		$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']);
		wcos_return_recovery_assert('manual_reconciliation' === WCOS_Operation_Journal::get($child, $fixture['return_operation'])['status'], 'Return participant drift did not become manual reconciliation: ' . $drift);
		wcos_return_recovery_assert(WCOS_Manual_Reconciliation_Blocker::has_active($child) && WCOS_Manual_Reconciliation_Blocker::has_active($original), 'Return drift did not block both participants: ' . $drift);
		$evidence[] = array('case' => $drift, 'outcome' => 'manual_reconciliation');
		wcos_return_recovery_cleanup_fixture($fixture, $fixture['return_operation']);
	}

	$fixture = wcos_return_recovery_interrupted_fixture('after_durable_preparation');
	$original = wc_get_order($fixture['original_id']); $original->delete(true);
	WCOS_Operation_Journal::require_recovery(wc_get_order($fixture['child_id']), $fixture['return_operation']);
	$child = wc_get_order($fixture['child_id']);
	wcos_return_recovery_assert('manual_reconciliation' === WCOS_Operation_Journal::get($child, $fixture['return_operation'])['status'] && WCOS_Manual_Reconciliation_Blocker::has_active($child), 'Missing Return original did not block the surviving child.');
	$evidence[] = array('case' => 'missing_original', 'outcome' => 'manual_reconciliation');
	wcos_return_recovery_cleanup_fixture($fixture, $fixture['return_operation']);

	foreach (array('corrupt_snapshot', 'after_write_physical_stock') as $case) {
		$fixture = wcos_return_recovery_interrupted_fixture('after_durable_preparation');
		$child = wc_get_order($fixture['child_id']);
		if ('corrupt_snapshot' === $case) {
			$key = 'wcos_mutation_op_' . hash('sha256', $fixture['child_id'] . '|' . $fixture['return_operation']);
			$record = get_option($key); $record['context']['return_recovery_snapshot']['recovery_fingerprint'] = str_repeat('f', 64); update_option($key, $record, false);
		} else {
			WCOS_Operation_Journal::checkpoint($child, $fixture['return_operation'], 'return_physical_stock_observed', array(
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::PREPARED,
				'return_physical_stock_after_write' => true,
			));
		}
		WCOS_Operation_Journal::require_recovery($child, $fixture['return_operation']);
		$child = wc_get_order($fixture['child_id']);
		wcos_return_recovery_assert('manual_reconciliation' === WCOS_Operation_Journal::get($child, $fixture['return_operation'])['status'], 'Unsafe Return evidence did not become manual reconciliation: ' . $case);
		$evidence[] = array('case' => $case, 'outcome' => 'manual_reconciliation');
		wcos_return_recovery_cleanup_fixture($fixture, $fixture['return_operation']);
	}

	$product = new WC_Product_Simple(); $product->set_name('WCOS Return before-stock-hook'); $product->set_regular_price('3.00'); $product->set_manage_stock(true); $product->set_stock_quantity(7); $product->save();
	$guard = WCOS_Stock_Side_Effect_Guard::begin('return-before-stock-' . wp_generate_uuid4()); $rejected = false;
	try { do_action('woocommerce_product_before_set_stock', $product); }
	catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) { $rejected = true; }
	WCOS_Stock_Side_Effect_Guard::end($guard);
	wcos_return_recovery_assert($rejected && '7.000000' === WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6), 'Before-write Return stock event was not rejected without mutation.');
	$product->delete(true);
	$evidence[] = array('case' => 'before_write_physical_stock', 'outcome' => 'reject_before_write');

	return $evidence;
}

function wcos_return_recovery_stock_case($label, WC_Product $product, $reduced_stock) {
	$owner_id = method_exists($product, 'get_stock_managed_by_id') ? absint($product->get_stock_managed_by_id()) : absint($product->get_id());
	$owner = wc_get_product($owner_id);
	$stock_before = $owner instanceof WC_Product && null !== $owner->get_stock_quantity() ? WCOS_Decimal::normalize($owner->get_stock_quantity(), 6) : null;
	$source = wc_create_order(); $source->set_status('pending'); $source->set_currency('USD');
	$item_id = $source->add_product($product, 2); $source->calculate_totals(false); $source->save();
	if (null !== $reduced_stock) {
		$item = $source->get_item($item_id); $item->add_meta_data('_reduced_stock', $reduced_stock, true); $item->save();
		$source->get_data_store()->set_stock_reduced($source->get_id(), true);
	}
	$split_operation = 'return-stock-split-' . wp_generate_uuid4();
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($source->get_id()), array(
		'child-stock' => array($item_id => '1.000000'),
	), $split_operation, 2);
	wcos_return_recovery_assert(1 === count($children), 'Return stock matrix Split failed: ' . $label);
	$result = wcos_return_recovery_execute(wc_get_order($children[0]->get_id()), 'return-stock-' . wp_generate_uuid4());
	$owner = wc_get_product($owner_id);
	$stock_after = $owner instanceof WC_Product && null !== $owner->get_stock_quantity() ? WCOS_Decimal::normalize($owner->get_stock_quantity(), 6) : null;
	wcos_return_recovery_assert($stock_before === $stock_after, 'Return stock matrix changed physical stock: ' . $label);
	WCOS_Operation_Journal::delete(wc_get_order($result['child']->get_id()), $result['record']['operation_id']);
	WCOS_Operation_Journal::delete(wc_get_order($source->get_id()), $split_operation);
	$fresh_child = wc_get_order($result['child']->get_id()); if ($fresh_child) { $fresh_child->delete(true); }
	$fresh_source = wc_get_order($source->get_id()); if ($fresh_source) { $fresh_source->delete(true); }
	return array('case' => $label, 'physical_stock_unchanged' => true, 'operational_owner_conserved' => true);
}

function wcos_return_recovery_mixed_stock_ownership_case($user_id) {
	$retained = new WC_Product_Simple();
	$retained->set_name('WCOS Return retained stock owner'); $retained->set_regular_price('7.00');
	$retained->set_manage_stock(true); $retained->set_stock_quantity(20); $retained->save();
	$returned = new WC_Product_Simple();
	$returned->set_name('WCOS Return zero-marker line'); $returned->set_regular_price('5.00');
	$returned->set_manage_stock(false); $returned->set_stock_status('outofstock'); $returned->save();
	$source = wc_create_order(); $source->set_status('pending'); $source->set_currency('USD');
	$retained_item_id = $source->add_product($retained, 2);
	$returned_item_id = $source->add_product($returned, 1);
	$source->calculate_totals(false); $source->save();
	$retained_item = $source->get_item($retained_item_id);
	$retained_item->add_meta_data('_reduced_stock', '2.000000', true); $retained_item->save();
	$source->get_data_store()->set_stock_reduced($source->get_id(), true);

	$adapter = new WCOS_Split_Strategy_WooCommerce_Adapter();
	$review = $adapter->review(wc_get_order($source->get_id()), WCOS_Split_Strategy_Gates::STOCK_STATUS);
	wcos_return_recovery_assert(!empty($review['supported']), 'Mixed-ownership Stock-status review failed.');
	$confirmation = WCOS_Split_Strategy_Confirmation_Store::create(
		wc_get_order($source->get_id()), WCOS_Split_Strategy_Gates::STOCK_STATUS, $review, 'stock-instock', $user_id
	);
	$verified = WCOS_Split_Strategy_Confirmation_Store::verify(
		wc_get_order($source->get_id()), $confirmation['operation_id'], $confirmation['confirmation_token'], $user_id
	);
	$children = $adapter->split_confirmed(
		wc_get_order($source->get_id()), WCOS_Split_Strategy_Gates::STOCK_STATUS,
		$verified['plan'], $verified['operation_id'], $verified['price_precision'], $verified
	);
	$split_operation = $verified['operation_id'];
	wcos_return_recovery_assert(1 === count($children), 'Mixed-ownership Return Split did not create one child.');
	$child = wc_get_order($children[0]->get_id()); $original = wc_get_order($source->get_id());
	$retained_before = WCOS_Decimal::normalize($original->get_item($retained_item_id)->get_meta('_reduced_stock', true), 6);
	wcos_return_recovery_assert('2.000000' === $retained_before, 'Mixed-ownership Split changed the unrelated original marker.');
	wcos_return_recovery_assert((bool) $original->get_data_store()->get_stock_reduced($original->get_id()), 'Mixed-ownership original lost its active stock flag before Return.');

	/* Exercise safe compensation before any Return write, then replay a fresh Return to completion. */
	$report = WCOS_Return_Preflight::assert_supported($child, true);
	$context = WCOS_Return_Journal_Context::create(
		$child, $original, $report['return_plan'], $report['lineage_authority'],
		$report['lineage_authority']['source_evolution_authority']
	);
	$compensation_operation = 'return-mixed-compensation-' . wp_generate_uuid4();
	wcos_return_recovery_assert(WCOS_Operation_Journal::start(
		$child, $compensation_operation, 'return', $context, $context['return_pair']['pair_fingerprint']
	), 'Mixed-ownership Return compensation journal could not start.');
	$record = WCOS_Operation_Journal::get($child, $compensation_operation);
	$snapshot = $record['context']['return_recovery_snapshot'];
	wcos_return_recovery_assert(WCOS_Operation_Journal::checkpoint($child, $compensation_operation, 'return_original_staging', array(
		'return_recovery_state' => WCOS_Return_Recovery_State_Graph::ORIGINAL_STAGING,
		'return_original_added_item_ids' => array(),
		'return_child_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child),
		'return_original_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original),
	)), 'Mixed-ownership Return compensation checkpoint failed.');
	wcos_return_recovery_assert(WCOS_Operation_Journal::require_recovery($child, $compensation_operation), 'Mixed-ownership Return compensation did not dispatch.');
	$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
	$compensated = WCOS_Operation_Journal::get($child, $compensation_operation);
	wcos_return_recovery_assert('compensated' === $compensated['status'], 'Mixed-ownership Return did not compensate.');
	wcos_return_recovery_assert(
		$retained_before === WCOS_Decimal::normalize($original->get_item($retained_item_id)->get_meta('_reduced_stock', true), 6)
		&& (bool) $original->get_data_store()->get_stock_reduced($original->get_id()),
		'Mixed-ownership compensation changed unrelated original stock ownership.'
	);
	WCOS_Operation_Journal::delete($child, $compensation_operation);

	$result = wcos_return_recovery_execute($child, 'return-mixed-' . wp_generate_uuid4());
	$original = wc_get_order($original->get_id()); $child = wc_get_order($child->get_id());
	wcos_return_recovery_assert(
		$retained_before === WCOS_Decimal::normalize($original->get_item($retained_item_id)->get_meta('_reduced_stock', true), 6),
		'Mixed-ownership Return changed the unrelated original marker.'
	);
	wcos_return_recovery_assert((bool) $original->get_data_store()->get_stock_reduced($original->get_id()), 'Mixed-ownership Return cleared the original flag while an unrelated marker remained.');
	foreach ($result['record']['context']['return_destination_item_ids'] as $destination_item_id) {
		$destination = $original->get_item($destination_item_id);
		wcos_return_recovery_assert($destination instanceof WC_Order_Item_Product && '' === $destination->get_meta('_reduced_stock', true), 'Zero-marker Return destination acquired stock ownership.');
	}
	$terminal_once = WCOS_Return_Journal_Context::terminal_result_from_record($result['record']);
	wcos_return_recovery_assert($terminal_once === WCOS_Return_Journal_Context::terminal_result_from_record($result['record']), 'Mixed-ownership Return terminal replay changed.');

	WCOS_Operation_Journal::delete($child, $result['record']['operation_id']);
	WCOS_Operation_Journal::delete($original, $split_operation);
	WCOS_Split_Strategy_Confirmation_Store::delete($split_operation);
	$child->delete(true); $original->delete(true);
	wp_delete_post($retained->get_id(), true); wp_delete_post($returned->get_id(), true);
	return array(
		'case' => 'mixed_unrelated_original_owner_returned_zero_marker',
		'unrelated_marker_preserved' => true, 'original_flag_preserved' => true,
		'compensation_restored' => true, 'terminal_replay_stable' => true,
	);
}

function wcos_return_recovery_strategy_case($strategy, $user_id) {
	$keep = new WC_Product_Simple(); $keep->set_name('WCOS Return recovery keep ' . $strategy); $keep->set_regular_price('8.00'); $keep->set_manage_stock(true); $keep->set_stock_quantity(30); $keep->save();
	$move = new WC_Product_Simple(); $move->set_name('WCOS Return recovery move ' . $strategy); $move->set_regular_price('6.00'); $move->set_manage_stock(true); $move->set_stock_quantity(20); $move->save();
	$terms = array();
	if (WCOS_Split_Strategy_Gates::CATEGORY === $strategy) {
		$suffix = strtolower(wp_generate_password(6, false, false));
		$keep_term = wp_insert_term('WCOS Return Recovery Keep ' . $suffix, 'product_cat');
		$move_term = wp_insert_term('WCOS Return Recovery Move ' . $suffix, 'product_cat');
		wcos_return_recovery_assert(!is_wp_error($keep_term) && !is_wp_error($move_term), 'Category Return terms could not be created.');
		$terms = array(absint($keep_term['term_id']), absint($move_term['term_id']));
		wp_set_object_terms($keep->get_id(), array($terms[0]), 'product_cat'); wp_set_object_terms($move->get_id(), array($terms[1]), 'product_cat');
		$source_bucket = 'category-' . $terms[0];
	} else {
		$keep->set_stock_status('instock'); $keep->save();
		$move->set_stock_quantity(0); $move->set_stock_status('outofstock'); $move->save();
		$source_bucket = 'stock-instock';
	}
	$keep_stock = null === $keep->get_stock_quantity() ? null : WCOS_Decimal::normalize($keep->get_stock_quantity(), 6);
	$move_stock = null === $move->get_stock_quantity() ? null : WCOS_Decimal::normalize($move->get_stock_quantity(), 6);
	$source = wc_create_order(); $source->set_status('pending'); $source->set_currency('USD');
	$keep_id = $source->add_product($keep, 2); $move_id = $source->add_product($move, 2);
	$source->calculate_totals(false); $source->save();
	foreach (array($keep_id, $move_id) as $line_id) { $line = $source->get_item($line_id); $line->add_meta_data('_reduced_stock', '2.000000', true); $line->save(); }
	$source->get_data_store()->set_stock_reduced($source->get_id(), true);
	$adapter = new WCOS_Split_Strategy_WooCommerce_Adapter();
	$review = $adapter->review(wc_get_order($source->get_id()), $strategy);
	wcos_return_recovery_assert(!empty($review['supported']), 'Whole-line Return strategy review failed.');
	$confirmation = WCOS_Split_Strategy_Confirmation_Store::create(wc_get_order($source->get_id()), $strategy, $review, $source_bucket, $user_id);
	$verified = WCOS_Split_Strategy_Confirmation_Store::verify(wc_get_order($source->get_id()), $confirmation['operation_id'], $confirmation['confirmation_token'], $user_id);
	$children = $adapter->split_confirmed(wc_get_order($source->get_id()), $strategy, $verified['plan'], $verified['operation_id'], $verified['price_precision'], $verified);
	wcos_return_recovery_assert(1 === count($children), 'Whole-line strategy did not create one Return child.');
	$child = wc_get_order($children[0]->get_id());
	$result = wcos_return_recovery_execute($child, 'return-' . $strategy . '-' . wp_generate_uuid4());
	$terminal = WCOS_Return_Journal_Context::terminal_result_from_record($result['record']);
	$destination_ids = $result['record']['context']['return_destination_item_ids'];
	wcos_return_recovery_assert(!empty($destination_ids[$move_id]) && $destination_ids[$move_id] !== $move_id, 'Whole-line Return did not bind a fresh original destination.');
	$keep = wc_get_product($keep->get_id()); $move = wc_get_product($move->get_id());
	wcos_return_recovery_assert($keep_stock === (null === $keep->get_stock_quantity() ? null : WCOS_Decimal::normalize($keep->get_stock_quantity(), 6)), 'Whole-line Return changed keep-product stock.');
	wcos_return_recovery_assert($move_stock === (null === $move->get_stock_quantity() ? null : WCOS_Decimal::normalize($move->get_stock_quantity(), 6)), 'Whole-line Return changed moved-product stock.');
	wcos_return_recovery_assert($strategy === $result['record']['context']['return_plan']['strategy'] && 'completed' === $terminal['status'], 'Whole-line Return terminal authority lost strategy identity.');
	WCOS_Operation_Journal::delete(wc_get_order($result['child']->get_id()), $result['record']['operation_id']);
	WCOS_Operation_Journal::delete(wc_get_order($source->get_id()), $verified['operation_id']);
	WCOS_Split_Strategy_Confirmation_Store::delete($verified['operation_id']);
	$fresh_child = wc_get_order($result['child']->get_id()); if ($fresh_child) { $fresh_child->delete(true); }
	$fresh_source = wc_get_order($source->get_id()); if ($fresh_source) { $fresh_source->delete(true); }
	wp_delete_post($keep->get_id(), true); wp_delete_post($move->get_id(), true);
	foreach ($terms as $term_id) { wp_delete_term($term_id, 'product_cat'); }
}

wcos_return_recovery_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER), 'Production Return gate is not enabled.');
wcos_return_recovery_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN), 'Bulk Return production gate changed.');
wcos_return_recovery_assert(class_exists('WCOS_Return_Compensator'), 'Return compensator is not loaded.');

$user_id = wp_insert_user(array(
	'user_login' => 'wcos_return_recovery_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-return-recovery-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
wcos_return_recovery_assert(!is_wp_error($user_id), 'Return recovery user could not be created.');
wp_set_current_user($user_id);
wcos_return_recovery_compensation_case($user_id);
$failure_windows = wcos_return_recovery_crash_matrix();
$adversarial = wcos_return_recovery_adversarial_matrix();
$unmanaged = new WC_Product_Simple(); $unmanaged->set_name('WCOS Return unmanaged'); $unmanaged->set_regular_price('5.00'); $unmanaged->set_manage_stock(false); $unmanaged->save();
$variation_parent = new WC_Product_Variable(); $variation_parent->set_name('WCOS Return parent-managed'); $variation_parent->set_manage_stock(true); $variation_parent->set_stock_quantity(30); $variation_parent_id = $variation_parent->save();
$variation = new WC_Product_Variation(); $variation->set_parent_id($variation_parent_id); $variation->set_regular_price('12.00'); $variation->set_price('12.00'); $variation->set_manage_stock(false); $variation->save();
$stock_matrix = array(
	wcos_return_recovery_stock_case('parent_managed_variation', $variation, '2.000000'),
	wcos_return_recovery_stock_case('unmanaged', $unmanaged, null),
	wcos_return_recovery_mixed_stock_ownership_case($user_id),
);
$variation->delete(true); $variation_parent->delete(true); $unmanaged->delete(true);
$product = new WC_Product_Simple();
$product->set_name('WCOS Return recovery managed fractional');
$product->set_regular_price('10.00');
$product->set_manage_stock(true); $product->set_stock_quantity('100'); $product->set_backorders('yes'); $product->save();
$physical_before = WCOS_Decimal::normalize($product->get_stock_quantity(), 6);
$source = wc_create_order(); $source->set_status('pending'); $source->set_currency('USD');
$item = new WC_Order_Item_Product();
$item->set_props(array(
	'name' => $product->get_name(), 'product_id' => $product->get_id(), 'quantity' => '6.000000',
	'subtotal' => '60.00', 'total' => '54.00', 'subtotal_tax' => '6.00', 'total_tax' => '5.40',
	'taxes' => array('subtotal' => array(1 => '6.00'), 'total' => array(1 => '5.40')),
));
$item->add_meta_data('return_group', 'sequential', true); $item->add_meta_data('_reduced_stock', '4.800000', true); $source->add_item($item);
$tax = new WC_Order_Item_Tax(); $tax->set_rate_id(1); $tax->set_label('Return historical rate'); $tax->set_tax_total('5.40'); $tax->set_shipping_tax_total('0.00'); $source->add_item($tax);
WCOS_Order_Totals_Rebuilder::rebuild($source, 2); $source->save(); $source->get_data_store()->set_stock_reduced($source->get_id(), true);
$split_operation = 'return-recovery-split-' . wp_generate_uuid4();
$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($source->get_id()), array(
	'child-a' => array($item->get_id() => '1.000000'),
	'child-b' => array($item->get_id() => '1.000000'),
	'child-c' => array($item->get_id() => '1.000000'),
), $split_operation, 2);
wcos_return_recovery_assert(3 === count($children), 'Sequential Return fixture did not create three hardened children.');
$by_key = array(); foreach ($children as $candidate) { $candidate = wc_get_order($candidate->get_id()); $by_key[$candidate->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true)] = $candidate; }

$result_a = wcos_return_recovery_execute($by_key['child-a'], 'return-a-' . wp_generate_uuid4());
$after_a = WCOS_Return_Preflight::report(wc_get_order($by_key['child-b']->get_id()), true);
wcos_return_recovery_assert(!empty($after_a['supported']) && 1 === $after_a['lineage_authority']['source_evolution_authority']['sequence'], 'Sibling B did not derive authenticated source evolution after A.');
$stale_c = WCOS_Return_Preflight::assert_supported(wc_get_order($by_key['child-c']->get_id()), true);
$stale_context = WCOS_Return_Journal_Context::create(
	wc_get_order($by_key['child-c']->get_id()), wc_get_order($source->get_id()), $stale_c['return_plan'],
	$stale_c['lineage_authority'], $stale_c['lineage_authority']['source_evolution_authority']
);

/* Tampered completed A journal cannot authenticate sibling B. */
$journal_key_a = 'wcos_mutation_op_' . hash('sha256', $result_a['child']->get_id() . '|' . $result_a['record']['operation_id']);
$journal_a = get_option($journal_key_a); $tampered = $journal_a;
$tampered['context']['return_terminal_result']['source_evolution']['evolution_fingerprint'] = str_repeat('f', 64);
update_option($journal_key_a, $tampered, false);
$tampered_b = WCOS_Return_Preflight::report(wc_get_order($by_key['child-b']->get_id()), true);
wcos_return_recovery_assert(empty($tampered_b['supported']) && 'source_drift' === $tampered_b['reason'], 'Tampered sibling evolution did not fail closed.');
update_option($journal_key_a, $journal_a, false);

$result_b = wcos_return_recovery_execute(wc_get_order($by_key['child-b']->get_id()), 'return-b-' . wp_generate_uuid4());
$stale_operation = 'return-stale-c-' . wp_generate_uuid4();
wcos_return_recovery_assert(false === WCOS_Operation_Journal::start(
	wc_get_order($by_key['child-c']->get_id()), $stale_operation, 'return', $stale_context, $stale_context['return_pair']['pair_fingerprint']
), 'Stale sibling source authority unexpectedly started a Return journal.');
$after_b = WCOS_Return_Preflight::report(wc_get_order($by_key['child-c']->get_id()), true);
wcos_return_recovery_assert(!empty($after_b['supported']) && 2 === $after_b['lineage_authority']['source_evolution_authority']['sequence'], 'Sibling C did not derive the two-step authenticated evolution chain.');

$product = wc_get_product($product->get_id());
wcos_return_recovery_assert($physical_before === WCOS_Decimal::normalize($product->get_stock_quantity(), 6), 'Physical stock changed during sequential Return harness.');
$active = wc_get_order($source->get_id())->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true);
wcos_return_recovery_assert(array($by_key['child-c']->get_id()) === array_values($active), 'Sequential Returns changed sibling active relation authority.');

/* Cleanup exact disposable fixture. */
foreach (array($result_a['record'], $result_b['record']) as $record) {
	$child_id = $record['context']['return_pair']['authority']['child_order_id'];
	WCOS_Operation_Journal::delete(wc_get_order($child_id), $record['operation_id']);
}
WCOS_Operation_Journal::delete(wc_get_order($source->get_id()), $split_operation);
foreach ($by_key as $child) { $fresh = wc_get_order($child->get_id()); if ($fresh) { $fresh->delete(true); } }
$fresh_source = wc_get_order($source->get_id()); if ($fresh_source) { $fresh_source->delete(true); }
wp_delete_post($product->get_id(), true);

/* Fresh-destination stock ownership through both enabled server-built strategies. */
wcos_return_recovery_strategy_case(WCOS_Split_Strategy_Gates::CATEGORY, $user_id);
wcos_return_recovery_strategy_case(WCOS_Split_Strategy_Gates::STOCK_STATUS, $user_id);
wp_delete_user($user_id);

echo "return-recovery-policy=non_force_trash_archive child_false_original_true_when_owned\n";
echo "return-recovery-compensation=coordinator_original_then_child\n";
echo "return-recovery-forward=coordinator_child_retired_to_completed\n";
echo "return-recovery-source-evolution=sequential_siblings_authenticated\n";
echo 'return-recovery-failure-windows=' . wp_json_encode($failure_windows) . "\n";
echo 'return-recovery-adversarial=' . wp_json_encode($adversarial) . "\n";
echo 'return-recovery-stock-matrix=' . wp_json_encode($stock_matrix) . "\n";
echo 'return-recovery-retirement-observation=' . wp_json_encode(array(
	'storage' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy',
	'status' => 'trash',
	'policy' => WCOS_Return_Retirement_Policy::approved_identifier(),
	'side_effect_counts' => isset($GLOBALS['wcos_return_retirement_side_effects']) ? $GLOBALS['wcos_return_retirement_side_effects'] : array(),
)) . "\n";
echo 'return-recovery-fixture-manifest=' . hash('sha256', wp_json_encode(array($failure_windows, $adversarial, $stock_matrix))) . "\n";
echo "return-recovery-foundation-ok\n";
