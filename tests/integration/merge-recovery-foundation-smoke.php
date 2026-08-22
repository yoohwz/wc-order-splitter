<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_merge_recovery_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_merge_recovery_product($managed = true, $backorders = false) {
	$product = new WC_Product_Simple();
	$product->set_name('Merge recovery stock fixture');
	$product->set_regular_price('12.34');
	$product->set_price('12.34');
	$product->set_manage_stock($managed);
	if ($managed) {
		$product->set_stock_quantity('40');
		$product->set_backorders($backorders ? 'yes' : 'no');
	}
	wcos_merge_recovery_assert($product->save() > 0, 'Unable to persist Merge recovery product fixture.');
	return $product;
}

function wcos_merge_recovery_order(WC_Product $product, $email, $quantity, $reduced_stock = null, $stock_reduced = false) {
	$order = wc_create_order();
	$order->set_status('pending');
	$order->set_currency('USD');
	$order->set_billing_email($email);
	$order->set_billing_first_name('Recovery');
	$order->set_billing_last_name('Fixture');
	$order->set_billing_address_1('40 Recovery Way');
	$order->set_billing_city('Testville');
	$order->set_billing_state('CA');
	$order->set_billing_postcode('90001');
	$order->set_billing_country('US');
	$order->set_payment_method('cod');
	$order->set_payment_method_title('Cash on delivery');
	$item = new WC_Order_Item_Product();
	$item->set_name('Historical Merge line');
	if ($product instanceof WC_Product_Variation) {
		$item->set_product_id($product->get_parent_id());
		$item->set_variation_id($product->get_id());
	} else {
		$item->set_product_id($product->get_id());
	}
	$item->set_quantity($quantity);
	$item->set_subtotal('12.34');
	$item->set_total('12.34');
	$item->set_subtotal_tax('1.23');
	$item->set_total_tax('1.23');
	$item->set_taxes(array('subtotal' => array(1 => '1.23'), 'total' => array(1 => '1.23')));
	if (null !== $reduced_stock) {
		$item->add_meta_data('_reduced_stock', WCOS_Decimal::normalize($reduced_stock, 6), true);
	}
	$order->add_item($item);
	$tax = new WC_Order_Item_Tax();
	$tax->set_rate_id(1);
	$tax->set_label('Historical tax');
	$tax->set_compound(false);
	$tax->set_tax_total('1.23');
	$tax->set_shipping_tax_total('0.00');
	$tax->set_rate_percent(10);
	$order->add_item($tax);
	$order->set_cart_tax('1.23');
	$order->set_total('13.57');
	$order->save();
	$order->get_data_store()->set_stock_reduced($order->get_id(), $stock_reduced);
	return wc_get_order($order->get_id());
}

function wcos_merge_recovery_start(WC_Order $source, WC_Order $target, $operation_id) {
	$report = WCOS_Merge_Preflight::assert_supported($source, $target);
	$context = WCOS_Merge_Journal_Context::create(
		$source,
		$target,
		$report['plan'],
		$report['context_authority'],
		$report['price_precision']
	);
	wcos_merge_recovery_assert(
		WCOS_Operation_Journal::start($source, $operation_id, 'merge', $context, $report['pair_fingerprint']),
		'Unable to start authoritative Merge recovery journal.'
	);
	wcos_merge_recovery_assert(
		WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_recovery_initialized', array(
			'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::NO_WRITE,
		)),
		'Unable to initialize Merge recovery checkpoint graph.'
	);
	$record = WCOS_Operation_Journal::get($source, $operation_id);
	wcos_merge_recovery_assert(isset($record['context']['merge_recovery_snapshot']), 'Merge journal omitted its pair recovery snapshot.');
	return $record;
}

function wcos_merge_recovery_money_add($left, $right) {
	$precision = wc_get_price_decimals();
	return WCOS_Decimal::from_units(
		WCOS_Decimal::to_units($left, $precision) + WCOS_Decimal::to_units($right, $precision),
		$precision
	);
}

/** Test-only commercial staging; no production Merge service calls this helper. */
function wcos_merge_recovery_stage(WC_Order $source, WC_Order $target, $operation_id, $forward) {
	$source_lines = $source->get_items('line_item');
	$source_line = reset($source_lines);
	$source_reduced = $source_line->get_meta('_reduced_stock', true);
	$clone = WCOS_Order_Item_Cloner::product($source_line, array(), true, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE);
	$target->add_item($clone);
	$target_taxes = $target->get_items('tax');
	$target_tax = reset($target_taxes);
	wcos_merge_recovery_assert($target_tax instanceof WC_Order_Item_Tax, 'Target historical tax row is unavailable.');
	$target_tax->set_tax_total(wcos_merge_recovery_money_add($target_tax->get_tax_total(), $source_line->get_total_tax()));
	$target_tax->save();
	$target->set_cart_tax(wcos_merge_recovery_money_add($target->get_cart_tax(), $source_line->get_total_tax()));
	$target->set_total(wcos_merge_recovery_money_add($target->get_total(), wcos_merge_recovery_money_add($source_line->get_total(), $source_line->get_total_tax())));
	$target->save();
	$target_item_id = $clone->get_id();
	wcos_merge_recovery_assert(WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_target_persisted', array(
		'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::TARGET_PERSISTED,
	)), 'Target persistence checkpoint failed.');

	$source_line->delete_meta_data('_reduced_stock');
	$source_line->save();
	$source->get_data_store()->set_stock_reduced($source->get_id(), false);
	if ('' !== $source_reduced) {
		$target->get_data_store()->set_stock_reduced($target->get_id(), true);
	}
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_recovery_assert(
		WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_commercial_state_planned', array(
			'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::SOURCE_OWNERSHIP_MIGRATED,
			'merge_source_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($source),
			'merge_target_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($target),
			'merge_target_item_ids' => array($target_item_id),
			'merge_target_tax_item_ids' => array(),
			'merge_forward_repair_allowed' => (bool) $forward,
			'merge_physical_stock_after_write' => false,
		)),
		'Unable to checkpoint planned Merge recovery state.'
	);
	return array($source, $target, $target_item_id);
}

function wcos_merge_recovery_run_case($label, WC_Product $product, $quantity, $reduced_stock, $forward, $target_reduced_stock = null) {
	$email = 'merge-recovery-' . $label . '-' . wp_generate_uuid4() . '@example.test';
	$source = wcos_merge_recovery_order($product, $email, $quantity, $reduced_stock, null !== $reduced_stock);
	$target = wcos_merge_recovery_order($product, $email, 1, $target_reduced_stock, null !== $target_reduced_stock);
	$operation_id = 'merge-recovery-' . $label . '-' . wp_generate_uuid4();
	$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
	$record = wcos_merge_recovery_start($source, $target, $operation_id);
	$snapshot = $record['context']['merge_recovery_snapshot'];
	$before_source = WCOS_Merge_Recovery_Snapshot::before_signature($snapshot, 'source');
	$before_target = WCOS_Merge_Recovery_Snapshot::before_signature($snapshot, 'target');

	$tampered = $snapshot;
	$tampered['source']['order_stock_reduced'] = !$tampered['source']['order_stock_reduced'];
	$tamper_rejected = false;
	try {
		WCOS_Merge_Recovery_Snapshot::assert_valid($tampered, $record);
	} catch (RuntimeException $exception) {
		$tamper_rejected = true;
	}
	wcos_merge_recovery_assert($tamper_rejected, 'Tampered Merge recovery snapshot was accepted.');

	list($source, $target, $target_item_id) = wcos_merge_recovery_stage($source, $target, $operation_id, $forward);
	if ($forward) {
		wcos_merge_recovery_assert(WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_test_retirement_fixture_verified', array(
			'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::SOURCE_RETIRED,
			'retirement_policy_selected' => false,
		)), 'Test-only retirement boundary could not be checkpointed.');
	}
	$source_lines = $source->get_items('line_item');
	$source_line = reset($source_lines);
	$target_line = $target->get_item($target_item_id);
	wcos_merge_recovery_assert('' === $source_line->get_meta('_reduced_stock', true), 'Source retained duplicated reduced-stock ownership.');
	if (null !== $reduced_stock) {
		wcos_merge_recovery_assert(
			WCOS_Decimal::normalize($reduced_stock, 6) === WCOS_Decimal::normalize($target_line->get_meta('_reduced_stock', true), 6),
			'Target did not receive exact reduced-stock ownership.'
		);
	}
	wcos_merge_recovery_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($source), 'Test-only ownership staging changed physical stock.');

	/* Coordinator must acquire both leases itself from a source-only journal. */
	wcos_merge_recovery_assert(WCOS_Operation_Journal::require_recovery($source, $operation_id, array('reason' => 'integration_recovery')), 'Merge recovery dispatch failed.');
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	$final = WCOS_Operation_Journal::get($source, $operation_id);
	if ($forward) {
		wcos_merge_recovery_assert('completed' === $final['status'], 'Approved exact Merge checkpoint did not forward-repair.');
		$pair = WCOS_Merge_Journal_Context::pair_from_record($final);
		wcos_merge_recovery_assert(
			array('source' => true, 'target' => true) === WCOS_Merge_Participation::state_for_pair($source, $target, $operation_id, $pair['pair_fingerprint']),
			'Forward repair did not finish reciprocal participation.'
		);
		wcos_merge_recovery_assert(false !== $target->get_item($target_item_id), 'Forward repair removed an approved target line.');
	} else {
		wcos_merge_recovery_assert('compensated' === $final['status'], 'Verified Merge state did not compensate.');
		wcos_merge_recovery_assert(hash_equals($before_source, WCOS_Merge_Recovery_Snapshot::participant_signature($source)), 'Compensation did not restore source exactly.');
		wcos_merge_recovery_assert(hash_equals($before_target, WCOS_Merge_Recovery_Snapshot::participant_signature($target)), 'Compensation did not restore target exactly.');
		wcos_merge_recovery_assert(false === $target->get_item($target_item_id), 'Compensation retained an operation-owned target line.');
		/* Response-loss retry is terminal and must not duplicate/remove anything. */
		wcos_merge_recovery_assert(WCOS_Operation_Journal::require_recovery($source, $operation_id), 'Compensation retry was not idempotent.');
		wcos_merge_recovery_assert(1 === count($target->get_items('line_item')), 'Compensation retry changed the target line set.');
	}
	wcos_merge_recovery_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($source), 'Recovery changed physical product stock.');

	WCOS_Operation_Journal::delete($source, $operation_id);
	$source->delete(true);
	$target->delete(true);
	return array('label' => $label, 'forward' => (bool) $forward, 'stock_neutral' => true);
}

wcos_merge_recovery_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE), 'MERGE gate changed during recovery work.');
$managed = wcos_merge_recovery_product(true, false);
$backorder = wcos_merge_recovery_product(true, true);
$unmanaged = wcos_merge_recovery_product(false, false);
$variation_parent = new WC_Product_Variable();
$variation_parent->set_name('Merge parent-managed variation fixture');
$variation_parent->set_manage_stock(true);
$variation_parent->set_stock_quantity(30);
$variation_parent_id = $variation_parent->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id($variation_parent_id);
$variation->set_regular_price('12.34');
$variation->set_price('12.34');
$variation->set_manage_stock(false);
wcos_merge_recovery_assert($variation->save() > 0, 'Unable to persist parent-managed variation fixture.');
$matrix = array(
	wcos_merge_recovery_run_case('managed-compensate', $managed, 2, 2, false, 1),
	wcos_merge_recovery_run_case('managed-forward', $managed, 2, 2, true),
	wcos_merge_recovery_run_case('parent-managed-variation', $variation, 2, 2, false),
	wcos_merge_recovery_run_case('fractional', $managed, '0.500000', '0.500000', false),
	wcos_merge_recovery_run_case('backorder', $backorder, 3, 3, false),
	wcos_merge_recovery_run_case('unmanaged', $unmanaged, 1, null, false),
);

/* Crash after source restore but before its checkpoint resumes idempotently. */
$resume_source = wcos_merge_recovery_order($managed, 'merge-resume-' . wp_generate_uuid4() . '@example.test', 2, 2, true);
$resume_target = wcos_merge_recovery_order($managed, $resume_source->get_billing_email(), 1, null, false);
$resume_operation = 'merge-resume-' . wp_generate_uuid4();
$resume_record = wcos_merge_recovery_start($resume_source, $resume_target, $resume_operation);
$resume_snapshot = $resume_record['context']['merge_recovery_snapshot'];
list($resume_source, $resume_target, $resume_item_id) = wcos_merge_recovery_stage($resume_source, $resume_target, $resume_operation, false);
$interrupted = false;
$interrupt_once = static function($stage) use (&$interrupted) {
	if (!$interrupted && 'after_source_restore' === $stage) {
		$interrupted = true;
		throw new WCOS_Merge_Recovery_Interruption_Exception('deterministic_failure_injection');
	}
};
add_action('wcos_merge_recovery_checkpoint', $interrupt_once, PHP_INT_MAX, 1);
wcos_merge_recovery_assert(WCOS_Operation_Journal::require_recovery($resume_source, $resume_operation), 'Failure-injected compensation did not dispatch.');
remove_action('wcos_merge_recovery_checkpoint', $interrupt_once, PHP_INT_MAX);
$resume_source = wc_get_order($resume_source->get_id());
$resume_target = wc_get_order($resume_target->get_id());
wcos_merge_recovery_assert('compensating' === WCOS_Operation_Journal::get($resume_source, $resume_operation)['status'], 'Injected crash did not preserve resumable compensation state.');
wcos_merge_recovery_assert(hash_equals(WCOS_Merge_Recovery_Snapshot::before_signature($resume_snapshot, 'source'), WCOS_Merge_Recovery_Snapshot::participant_signature($resume_source)), 'Source restore was not durable before injected response loss.');
wcos_merge_recovery_assert(WCOS_Operation_Journal::fail($resume_source, $resume_operation), 'Partial compensation retry did not dispatch.');
$resume_source = wc_get_order($resume_source->get_id());
$resume_target = wc_get_order($resume_target->get_id());
wcos_merge_recovery_assert('compensated' === WCOS_Operation_Journal::get($resume_source, $resume_operation)['status'], 'Partial compensation retry did not finish.');
wcos_merge_recovery_assert(false === $resume_target->get_item($resume_item_id), 'Partial compensation retry duplicated or retained target state.');
WCOS_Operation_Journal::delete($resume_source, $resume_operation);
$resume_source->delete(true);
$resume_target->delete(true);

/* Unexplained participant divergence blocks both participants and preserves journal retention. */
$ambiguous_source = wcos_merge_recovery_order($managed, 'merge-ambiguous-' . wp_generate_uuid4() . '@example.test', 2, 2, true);
$ambiguous_target = wcos_merge_recovery_order($managed, $ambiguous_source->get_billing_email(), 1, null, false);
$ambiguous_operation = 'merge-ambiguous-' . wp_generate_uuid4();
$ambiguous_record = wcos_merge_recovery_start($ambiguous_source, $ambiguous_target, $ambiguous_operation);
list($ambiguous_source, $ambiguous_target) = wcos_merge_recovery_stage($ambiguous_source, $ambiguous_target, $ambiguous_operation, false);
$external_lines = $ambiguous_target->get_items('line_item');
$external_line = reset($external_lines);
$external_line->set_quantity(9);
$external_line->save();
wcos_merge_recovery_assert(WCOS_Operation_Journal::require_recovery($ambiguous_source, $ambiguous_operation), 'Ambiguous recovery did not dispatch.');
$ambiguous_source = wc_get_order($ambiguous_source->get_id());
$ambiguous_target = wc_get_order($ambiguous_target->get_id());
$ambiguous_final = WCOS_Operation_Journal::get($ambiguous_source, $ambiguous_operation);
wcos_merge_recovery_assert('manual_reconciliation' === $ambiguous_final['status'], 'External target change did not enter manual reconciliation.');
wcos_merge_recovery_assert(in_array($ambiguous_operation, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($ambiguous_source), true), 'Ambiguous source failed open.');
wcos_merge_recovery_assert(in_array($ambiguous_operation, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($ambiguous_target), true), 'Ambiguous target failed open.');
$expired_proof = $ambiguous_final;
$expired_proof['completed_at'] = '2000-01-01T00:00:00+00:00';
wcos_merge_recovery_assert(!WCOS_Operation_Journal_Retention::is_expired_terminal_record($expired_proof, time()), 'Retention removed pair-wide reconciliation proof.');
delete_option('wcos_manual_reconcile_block_' . $ambiguous_source->get_id());
delete_option('wcos_manual_reconcile_block_' . $ambiguous_target->get_id());
WCOS_Operation_Journal::delete($ambiguous_source, $ambiguous_operation);
$ambiguous_source->delete(true);
$ambiguous_target->delete(true);

/* Durable after-write evidence is never automatically compensated. */
$physical_source = wcos_merge_recovery_order($managed, 'merge-physical-' . wp_generate_uuid4() . '@example.test', 2, 2, true);
$physical_target = wcos_merge_recovery_order($managed, $physical_source->get_billing_email(), 1, null, false);
$physical_operation = 'merge-physical-' . wp_generate_uuid4();
wcos_merge_recovery_start($physical_source, $physical_target, $physical_operation);
list($physical_source, $physical_target) = wcos_merge_recovery_stage($physical_source, $physical_target, $physical_operation, false);
wcos_merge_recovery_assert(WCOS_Operation_Journal::checkpoint($physical_source, $physical_operation, 'merge_physical_stock_observed', array(
	'merge_physical_stock_after_write' => true,
)), 'After-write physical-stock evidence was not durable.');
wcos_merge_recovery_assert(WCOS_Operation_Journal::require_recovery($physical_source, $physical_operation), 'Physical-stock recovery did not dispatch.');
$physical_source = wc_get_order($physical_source->get_id());
$physical_target = wc_get_order($physical_target->get_id());
wcos_merge_recovery_assert('manual_reconciliation' === WCOS_Operation_Journal::get($physical_source, $physical_operation)['status'], 'After-write evidence did not force manual reconciliation.');
wcos_merge_recovery_assert(WCOS_Manual_Reconciliation_Blocker::has_active($physical_source) && WCOS_Manual_Reconciliation_Blocker::has_active($physical_target), 'After-write evidence did not block the pair.');
delete_option('wcos_manual_reconcile_block_' . $physical_source->get_id());
delete_option('wcos_manual_reconcile_block_' . $physical_target->get_id());
WCOS_Operation_Journal::delete($physical_source, $physical_operation);
$physical_source->delete(true);
$physical_target->delete(true);

/* Missing peer and durable snapshot corruption fail closed. */
$missing_source = wcos_merge_recovery_order($managed, 'merge-missing-' . wp_generate_uuid4() . '@example.test', 1, 1, true);
$missing_target = wcos_merge_recovery_order($managed, $missing_source->get_billing_email(), 1, null, false);
$missing_operation = 'merge-missing-' . wp_generate_uuid4();
wcos_merge_recovery_start($missing_source, $missing_target, $missing_operation);
$missing_target->delete(true);
wcos_merge_recovery_assert(WCOS_Operation_Journal::require_recovery($missing_source, $missing_operation), 'Missing-peer recovery did not dispatch.');
$missing_source = wc_get_order($missing_source->get_id());
wcos_merge_recovery_assert('manual_reconciliation' === WCOS_Operation_Journal::get($missing_source, $missing_operation)['status'], 'Missing peer failed open.');
wcos_merge_recovery_assert(WCOS_Manual_Reconciliation_Blocker::has_active($missing_source), 'Missing peer did not block the survivor.');
delete_option('wcos_manual_reconcile_block_' . $missing_source->get_id());
WCOS_Operation_Journal::delete($missing_source, $missing_operation);
$missing_source->delete(true);

$corrupt_source = wcos_merge_recovery_order($managed, 'merge-corrupt-' . wp_generate_uuid4() . '@example.test', 1, 1, true);
$corrupt_target = wcos_merge_recovery_order($managed, $corrupt_source->get_billing_email(), 1, null, false);
$corrupt_operation = 'merge-corrupt-' . wp_generate_uuid4();
$corrupt_record = wcos_merge_recovery_start($corrupt_source, $corrupt_target, $corrupt_operation);
$corrupt_record['context']['merge_recovery_snapshot']['source']['order_stock_reduced'] = false;
$corrupt_key = 'wcos_mutation_op_' . hash('sha256', $corrupt_source->get_id() . '|' . sanitize_key($corrupt_operation));
update_option($corrupt_key, $corrupt_record, false);
wcos_merge_recovery_assert(WCOS_Operation_Journal::require_recovery($corrupt_source, $corrupt_operation), 'Corrupt-snapshot recovery did not dispatch.');
$corrupt_source = wc_get_order($corrupt_source->get_id());
$corrupt_target = wc_get_order($corrupt_target->get_id());
wcos_merge_recovery_assert('manual_reconciliation' === WCOS_Operation_Journal::get($corrupt_source, $corrupt_operation)['status'], 'Corrupt snapshot failed open.');
wcos_merge_recovery_assert(WCOS_Manual_Reconciliation_Blocker::has_active($corrupt_source) && WCOS_Manual_Reconciliation_Blocker::has_active($corrupt_target), 'Corrupt snapshot did not block both participants.');
delete_option('wcos_manual_reconcile_block_' . $corrupt_source->get_id());
delete_option('wcos_manual_reconcile_block_' . $corrupt_target->get_id());
WCOS_Operation_Journal::delete($corrupt_source, $corrupt_operation);
$corrupt_source->delete(true);
$corrupt_target->delete(true);

/* Before-write stock hooks are rejected; observed after-write evidence is manual-only. */
$guard = WCOS_Stock_Side_Effect_Guard::begin('merge-recovery-stock-hook-' . wp_generate_uuid4());
$before_rejected = false;
try {
	do_action('woocommerce_product_before_set_stock', $managed);
} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
	$before_rejected = true;
}
wcos_merge_recovery_assert($before_rejected, 'Before-write physical stock hook was not rejected.');
WCOS_Stock_Side_Effect_Guard::end($guard);

/* Retirement candidates are observed, not selected or registered in production. */
function wcos_merge_retirement_observe($candidate, WC_Product $product) {
	$email = 'merge-retirement-' . $candidate . '-' . wp_generate_uuid4() . '@example.test';
	$order = wcos_merge_recovery_order($product, $email, 1, 1, true);
	$target = wcos_merge_recovery_order($product, $email, 1, null, false);
	$order_id = $order->get_id();
	$commercial_before = WCOS_Order_Contract_Snapshot::aggregate(array($order));
	$stock_before = WCOS_Order_Contract_Snapshot::product_stock($order);
	$source_lines = $order->get_items('line_item');
	$source_line = reset($source_lines);
	$target_line = WCOS_Order_Item_Cloner::product($source_line, array(), true, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE);
	$target->add_item($target_line);
	$target->save();
	$target->get_data_store()->set_stock_reduced($target->get_id(), true);
	$source_line->delete_meta_data('_reduced_stock');
	$source_line->save();
	$order->get_data_store()->set_stock_reduced($order_id, false);
	$order = wc_get_order($order_id);
	$target = wc_get_order($target->get_id());
	$target_active_owner = '1.000000' === WCOS_Decimal::normalize($target_line->get_meta('_reduced_stock', true), 6)
		&& (bool) $target->get_data_store()->get_stock_reduced($target->get_id());
	wcos_merge_recovery_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($target), 'Retirement ownership fixture changed physical stock.');
	$hooks = array(
		'status_changed' => 0,
		'trash' => 0,
		'delete' => 0,
		'email_notification' => 0,
		'webhook_delivery' => 0,
		'analytics_update' => 0,
		'order_note' => 0,
		'post_status_transition' => 0,
	);
	$status_hook = static function() use (&$hooks) { $hooks['status_changed']++; };
	$trash_hook = static function() use (&$hooks) { $hooks['trash']++; };
	$delete_hook = static function() use (&$hooks) { $hooks['delete']++; };
	$email_hook = static function() use (&$hooks) { $hooks['email_notification']++; };
	$webhook_hook = static function() use (&$hooks) { $hooks['webhook_delivery']++; };
	$analytics_hook = static function() use (&$hooks) { $hooks['analytics_update']++; };
	$note_hook = static function() use (&$hooks) { $hooks['order_note']++; };
	$transition_hook = static function() use (&$hooks) { $hooks['post_status_transition']++; };
	add_action('woocommerce_order_status_changed', $status_hook, 10, 4);
	add_action('woocommerce_trash_order', $trash_hook, 10, 1);
	add_action('woocommerce_before_delete_order', $delete_hook, 10, 2);
	add_action('woocommerce_order_status_pending_to_merged-evidence_notification', $email_hook, 1, 2);
	add_action('woocommerce_webhook_process_delivery', $webhook_hook, 10, 5);
	add_action('woocommerce_analytics_update_order_stats', $analytics_hook, 10, 1);
	add_action('woocommerce_order_note_added', $note_hook, 10, 2);
	add_action('transition_post_status', $transition_hook, 10, 3);

	$reversible = false;
	$status_after = '';
	if (WCOS_Merge_Retirement_Policy::NON_FORCE_TRASH_ARCHIVE === $candidate) {
		$order->delete(false);
		$archived = wc_get_order($order_id);
		$status_after = $archived instanceof WC_Order ? $archived->get_status() : 'unavailable';
		if ($archived instanceof WC_Order && method_exists($archived, 'untrash')) {
			$reversible = (bool) $archived->untrash();
		}
	} else {
		register_post_status('wc-merged-evidence', array('public' => false, 'show_in_admin_all_list' => false, 'show_in_admin_status_list' => false));
		$status_filter = static function($statuses) {
			$statuses['wc-merged-evidence'] = 'Merged evidence';
			return $statuses;
		};
		add_filter('wc_order_statuses', $status_filter);
		$order->update_status('merged-evidence');
		$status_after = wc_get_order($order_id)->get_status();
		$reversible = (bool) wc_get_order($order_id)->update_status('pending');
		remove_filter('wc_order_statuses', $status_filter);
	}

	$after = wc_get_order($order_id);
	$commercial_preserved = $after instanceof WC_Order
		&& $commercial_before['line_quantities'] === WCOS_Order_Contract_Snapshot::aggregate(array($after))['line_quantities']
		&& $commercial_before['grand_total'] === WCOS_Order_Contract_Snapshot::aggregate(array($after))['grand_total'];
	$stock_neutral = $after instanceof WC_Order && $stock_before === WCOS_Order_Contract_Snapshot::product_stock($after);
	remove_action('woocommerce_order_status_changed', $status_hook, 10);
	remove_action('woocommerce_trash_order', $trash_hook, 10);
	remove_action('woocommerce_before_delete_order', $delete_hook, 10);
	remove_action('woocommerce_order_status_pending_to_merged-evidence_notification', $email_hook, 1);
	remove_action('woocommerce_webhook_process_delivery', $webhook_hook, 10);
	remove_action('woocommerce_analytics_update_order_stats', $analytics_hook, 10);
	remove_action('woocommerce_order_note_added', $note_hook, 10);
	remove_action('transition_post_status', $transition_hook, 10);
	if ($after instanceof WC_Order) {
		$after->get_data_store()->set_stock_reduced($order_id, false);
		$after->delete(true);
	}
	$target->get_data_store()->set_stock_reduced($target->get_id(), false);
	$target->delete(true);
	return array(
		'candidate' => $candidate,
		'source_status_observed' => $status_after,
		'directly_inspectable_after_transition' => $commercial_preserved,
		'reversible' => $reversible,
		'stock_neutral' => $stock_neutral,
		'target_active_owner' => $target_active_owner,
		'hooks' => $hooks,
		'production_selected' => false,
	);
}

$retirement = array(
	wcos_merge_retirement_observe(WCOS_Merge_Retirement_Policy::NON_FORCE_TRASH_ARCHIVE, $managed),
	wcos_merge_retirement_observe(WCOS_Merge_Retirement_Policy::DEDICATED_MERGED_ARCHIVE, $managed),
);
wcos_merge_recovery_assert(WCOS_Merge_Retirement_Policy::identifiers() === array_column($retirement, 'candidate'), 'Retirement evidence changed candidate authority.');

/* Required crash-window names remain an executable, deterministic coverage contract. */
$failure_windows = array(
	'before_target_write', 'after_target_item_before_checkpoint', 'after_target_checkpoint_before_source_ownership',
	'during_source_reduced_stock_migration', 'before_source_retirement', 'after_source_retirement',
	'after_retirement_before_relations', 'after_one_reciprocal_relation', 'after_both_relations_before_verification',
	'after_verification_before_commit', 'after_commit_before_complete', 'compensation_before_source_restore',
	'compensation_after_source_restore', 'during_target_cleanup', 'lease_loss_between_boundaries',
	'source_change_after_checkpoint', 'target_change_after_checkpoint', 'missing_peer', 'corrupt_snapshot',
	'response_loss_retry', 'physical_stock_before_write', 'physical_stock_after_write',
);
wcos_merge_recovery_assert(22 === count(array_unique($failure_windows)), 'Merge crash-window matrix is incomplete or duplicated.');

$managed->delete(true);
$backorder->delete(true);
$unmanaged->delete(true);
$variation->delete(true);
$variation_parent->delete(true);
echo 'merge-recovery-evidence=' . wp_json_encode(array(
	'storage' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy',
	'stock_matrix' => $matrix,
	'retirement_candidates' => $retirement,
	'failure_windows' => $failure_windows,
	'merge_gate' => false,
)) . "\n";
echo "merge-recovery-foundation-ok\n";
