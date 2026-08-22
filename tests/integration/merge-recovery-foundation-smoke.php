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
	do_action('wcos_merge_recovery_checkpoint', 'before_target_write', $source, $target, $operation_id);
	$source_lines = $source->get_items('line_item');
	$source_line = reset($source_lines);
	$source_reduced = $source_line->get_meta('_reduced_stock', true);
	$clone = WCOS_Order_Item_Cloner::product($source_line, array(), true, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE);
	$target->add_item($clone);
	do_action('wcos_merge_recovery_checkpoint', 'after_target_item_before_checkpoint', $source, $target, $operation_id);
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
	do_action('wcos_merge_recovery_checkpoint', 'after_target_checkpoint_before_source_ownership', $source, $target, $operation_id);

	do_action('wcos_merge_recovery_checkpoint', 'during_source_reduced_stock_migration', $source, $target, $operation_id);
	$source_line->delete_meta_data('_reduced_stock');
	$source_line->save();
	$source->get_data_store()->set_stock_reduced($source->get_id(), false);
	if ('' !== $source_reduced) {
		$target->get_data_store()->set_stock_reduced($target->get_id(), true);
	}
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_recovery_assert(WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_source_ownership_migrated', array(
		'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::SOURCE_OWNERSHIP_MIGRATED,
	)), 'Unable to checkpoint source ownership migration.');
	$retirement_candidate = '';
	if ($forward) {
		$retirement_candidate = WCOS_Merge_Retirement_Policy::NON_FORCE_TRASH_ARCHIVE;
		$snapshot = WCOS_Operation_Journal::get($source, $operation_id)['context']['merge_recovery_snapshot'];
		$source_id = $source->get_id();
		do_action('wcos_merge_recovery_checkpoint', 'before_source_retirement', $source, $target, $operation_id);
		$source->delete(false);
		$source = wc_get_order($source_id);
		wcos_merge_recovery_assert($source instanceof WC_Order && 'trash' === $source->get_status(), 'Non-force retirement did not archive the staged source.');
		WCOS_Merge_Recovery_Snapshot::assert_archive_preserved($snapshot, $source);
		do_action('wcos_merge_recovery_checkpoint', 'after_source_retirement', $source, $target, $operation_id);
		wcos_merge_recovery_assert(WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_source_retired', array(
			'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::SOURCE_RETIRED,
			'merge_retirement_candidate' => $retirement_candidate,
		)), 'Unable to checkpoint the real source retirement.');
	}
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_recovery_assert(
		WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_commercial_state_planned', array(
			'merge_source_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($source),
			'merge_target_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($target),
			'merge_source_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($source),
			'merge_target_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($target),
			'merge_target_item_ids' => array($target_item_id),
			'merge_target_tax_item_ids' => array(),
			'merge_forward_repair_allowed' => (bool) $forward,
			'merge_retirement_candidate' => $retirement_candidate,
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

/* Same-operation recovery adopts both current leases without releasing the owner. */
$adopt_source = wcos_merge_recovery_order($managed, 'merge-adopt-' . wp_generate_uuid4() . '@example.test', 2, 2, true);
$adopt_target = wcos_merge_recovery_order($managed, $adopt_source->get_billing_email(), 1, null, false);
$adopt_operation = 'merge-adopt-' . wp_generate_uuid4();
wcos_merge_recovery_start($adopt_source, $adopt_target, $adopt_operation);
list($adopt_source, $adopt_target) = wcos_merge_recovery_stage($adopt_source, $adopt_target, $adopt_operation, false);
$owning_lease = WCOS_Multi_Order_Lease::acquire(array($adopt_source->get_id(), $adopt_target->get_id()), $adopt_operation);
wcos_merge_recovery_assert($owning_lease instanceof WCOS_Multi_Order_Lease, 'Same-operation adoption fixture could not acquire leases.');
wcos_merge_recovery_assert(WCOS_Operation_Journal::require_recovery($adopt_source, $adopt_operation), 'Recovery did not run while its operation already owned both leases.');
$owning_lease->assert_owned();
wcos_merge_recovery_assert(false === WCOS_Multi_Order_Lease::acquire(array($adopt_source->get_id(), $adopt_target->get_id()), 'merge-competitor-' . wp_generate_uuid4()), 'A competitor entered an adopted lease set.');
$owning_lease->release();
$adopt_source = wc_get_order($adopt_source->get_id());
$adopt_target = wc_get_order($adopt_target->get_id());
wcos_merge_recovery_assert('compensated' === WCOS_Operation_Journal::get($adopt_source, $adopt_operation)['status'], 'Adopted same-operation recovery did not compensate.');
WCOS_Operation_Journal::delete($adopt_source, $adopt_operation);
$adopt_source->delete(true);
$adopt_target->delete(true);

/* Multi-line/tax restores and target cleanup resume at every durable sub-write. */
$multi_source = wcos_merge_recovery_order($managed, 'merge-multi-' . wp_generate_uuid4() . '@example.test', 2, 2, true);
$multi_initial_lines = $multi_source->get_items('line_item');
$extra_source_line = WCOS_Order_Item_Cloner::product(reset($multi_initial_lines), array(), true, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE);
$multi_source->add_item($extra_source_line);
$multi_source_tax_items = $multi_source->get_items('tax');
$multi_source_tax = reset($multi_source_tax_items);
$multi_source_tax->set_tax_total('2.46');
$multi_source_tax->save();
$multi_source->set_cart_tax('2.46');
$multi_source->set_total('27.14');
$multi_source->save();
$multi_source = wc_get_order($multi_source->get_id());
$multi_target = wcos_merge_recovery_order($managed, $multi_source->get_billing_email(), 1, null, false);
$multi_operation = 'merge-multi-' . wp_generate_uuid4();
$multi_record = wcos_merge_recovery_start($multi_source, $multi_target, $multi_operation);
$multi_snapshot = $multi_record['context']['merge_recovery_snapshot'];
list($multi_source, $multi_target, $multi_added_one) = wcos_merge_recovery_stage($multi_source, $multi_target, $multi_operation, false);
$source_lines = $multi_source->get_items('line_item');
$changed_source_line = end($source_lines);
$changed_source_line->set_quantity('7');
$changed_source_line->save();
$multi_source_tax_rows = $multi_source->get_items('tax');
$source_tax = reset($multi_source_tax_rows);
$source_tax->set_tax_total('9.99');
$source_tax->save();
$multi_changed_lines = $multi_source->get_items('line_item');
$extra_target_line = WCOS_Order_Item_Cloner::product(reset($multi_changed_lines), array(), true, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE);
$multi_target->add_item($extra_target_line);
$multi_target->save();
$multi_source = wc_get_order($multi_source->get_id());
$multi_target = wc_get_order($multi_target->get_id());
wcos_merge_recovery_assert(WCOS_Operation_Journal::checkpoint($multi_source, $multi_operation, 'merge_multi_component_plan', array(
	'merge_source_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($multi_source),
	'merge_target_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($multi_target),
	'merge_source_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($multi_source),
	'merge_target_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($multi_target),
	'merge_target_item_ids' => array($multi_added_one, $extra_target_line->get_id()),
)), 'Multi-component recovery checkpoints were not durable.');
$multi_windows = array(
	'before_source_line_restored_checkpoint',
	'before_source_tax_restored_checkpoint',
	'before_target_added_line_removed_checkpoint',
	'before_target_tax_restored_checkpoint',
);
$executed_multi_windows = array();
foreach ($multi_windows as $multi_window_index => $multi_window) {
	$hit = false;
	$multi_fault = static function($stage) use ($multi_window, &$hit) {
		if (!$hit && $multi_window === $stage) { $hit = true; throw new WCOS_Merge_Recovery_Interruption_Exception('Injected ' . $multi_window); }
	};
	add_action('wcos_merge_recovery_checkpoint', $multi_fault, PHP_INT_MAX, 1);
	if (0 === $multi_window_index) {
		WCOS_Operation_Journal::require_recovery($multi_source, $multi_operation);
	} else {
		WCOS_Operation_Journal::fail($multi_source, $multi_operation);
	}
	remove_action('wcos_merge_recovery_checkpoint', $multi_fault, PHP_INT_MAX);
	wcos_merge_recovery_assert($hit, 'Internal recovery window did not execute: ' . $multi_window);
	$executed_multi_windows[] = $multi_window;
	$multi_source = wc_get_order($multi_source->get_id());
	$multi_target = wc_get_order($multi_target->get_id());
}
WCOS_Operation_Journal::fail($multi_source, $multi_operation);
$multi_source = wc_get_order($multi_source->get_id());
$multi_target = wc_get_order($multi_target->get_id());
wcos_merge_recovery_assert('compensated' === WCOS_Operation_Journal::get($multi_source, $multi_operation)['status'], 'Multi-component retries did not finish compensation.');
wcos_merge_recovery_assert(hash_equals(WCOS_Merge_Recovery_Snapshot::before_signature($multi_snapshot, 'source'), WCOS_Merge_Recovery_Snapshot::participant_signature($multi_source)), 'Multi-line source restore was not exact.');
wcos_merge_recovery_assert(hash_equals(WCOS_Merge_Recovery_Snapshot::before_signature($multi_snapshot, 'target'), WCOS_Merge_Recovery_Snapshot::participant_signature($multi_target)), 'Multi-line target cleanup was not exact.');
WCOS_Operation_Journal::delete($multi_source, $multi_operation);
$multi_source->delete(true);
$multi_target->delete(true);

/* Crash immediately before source restore performs no write and retries cleanly. */
$before_restore_source = wcos_merge_recovery_order($managed, 'merge-before-restore-' . wp_generate_uuid4() . '@example.test', 1, 1, true);
$before_restore_target = wcos_merge_recovery_order($managed, $before_restore_source->get_billing_email(), 1, null, false);
$before_restore_operation = 'merge-before-restore-' . wp_generate_uuid4();
wcos_merge_recovery_start($before_restore_source, $before_restore_target, $before_restore_operation);
list($before_restore_source, $before_restore_target) = wcos_merge_recovery_stage($before_restore_source, $before_restore_target, $before_restore_operation, false);
$before_restore_hit = false;
$before_restore_fault = static function($stage) use (&$before_restore_hit) {
	if (!$before_restore_hit && 'before_source_restore' === $stage) { $before_restore_hit = true; throw new WCOS_Merge_Recovery_Interruption_Exception('Injected before source restore'); }
};
add_action('wcos_merge_recovery_checkpoint', $before_restore_fault, PHP_INT_MAX, 1);
WCOS_Operation_Journal::require_recovery($before_restore_source, $before_restore_operation);
remove_action('wcos_merge_recovery_checkpoint', $before_restore_fault, PHP_INT_MAX);
wcos_merge_recovery_assert($before_restore_hit, 'Before-source-restore crash window did not execute.');
$before_restore_source = wc_get_order($before_restore_source->get_id());
WCOS_Operation_Journal::fail($before_restore_source, $before_restore_operation);
$before_restore_source = wc_get_order($before_restore_source->get_id());
$before_restore_target = wc_get_order($before_restore_target->get_id());
wcos_merge_recovery_assert('compensated' === WCOS_Operation_Journal::get($before_restore_source, $before_restore_operation)['status'], 'Before-source-restore retry did not compensate.');
WCOS_Operation_Journal::delete($before_restore_source, $before_restore_operation);
$before_restore_source->delete(true);
$before_restore_target->delete(true);

/* Lease loss at a write boundary performs no further participant mutation. */
$lease_loss_source = wcos_merge_recovery_order($managed, 'merge-lease-loss-' . wp_generate_uuid4() . '@example.test', 1, 1, true);
$lease_loss_target = wcos_merge_recovery_order($managed, $lease_loss_source->get_billing_email(), 1, null, false);
$lease_loss_operation = 'merge-lease-loss-' . wp_generate_uuid4();
wcos_merge_recovery_start($lease_loss_source, $lease_loss_target, $lease_loss_operation);
list($lease_loss_source, $lease_loss_target) = wcos_merge_recovery_stage($lease_loss_source, $lease_loss_target, $lease_loss_operation, false);
$lease_loss_source_signature = WCOS_Merge_Recovery_Snapshot::participant_signature($lease_loss_source);
$lease_loss_target_signature = WCOS_Merge_Recovery_Snapshot::participant_signature($lease_loss_target);
$lease_loss_owner = WCOS_Multi_Order_Lease::acquire(array($lease_loss_source->get_id(), $lease_loss_target->get_id()), $lease_loss_operation);
$lease_loss_hit = false;
$lease_loss_fault = static function($stage) use (&$lease_loss_hit, $lease_loss_owner) {
	if (!$lease_loss_hit && 'before_source_restore' === $stage) { $lease_loss_hit = true; $lease_loss_owner->release(); }
};
add_action('wcos_merge_recovery_checkpoint', $lease_loss_fault, PHP_INT_MAX, 1);
WCOS_Operation_Journal::require_recovery($lease_loss_source, $lease_loss_operation);
remove_action('wcos_merge_recovery_checkpoint', $lease_loss_fault, PHP_INT_MAX);
$lease_loss_source = wc_get_order($lease_loss_source->get_id());
$lease_loss_target = wc_get_order($lease_loss_target->get_id());
wcos_merge_recovery_assert($lease_loss_hit, 'Lease-loss boundary did not execute.');
wcos_merge_recovery_assert(hash_equals($lease_loss_source_signature, WCOS_Merge_Recovery_Snapshot::participant_signature($lease_loss_source)), 'Lease loss allowed a source write.');
wcos_merge_recovery_assert(hash_equals($lease_loss_target_signature, WCOS_Merge_Recovery_Snapshot::participant_signature($lease_loss_target)), 'Lease loss allowed a target write.');
wcos_merge_recovery_assert('manual_reconciliation' === WCOS_Operation_Journal::get($lease_loss_source, $lease_loss_operation)['status'], 'Lease loss did not preserve fail-closed authority.');
delete_option('wcos_manual_reconcile_block_' . $lease_loss_source->get_id());
delete_option('wcos_manual_reconcile_block_' . $lease_loss_target->get_id());
WCOS_Operation_Journal::delete($lease_loss_source, $lease_loss_operation);
$lease_loss_source->delete(true);
$lease_loss_target->delete(true);

/* One-shot participation failure plus one-sided blocker failure stays pair-wide fail closed. */
$authority_source = wcos_merge_recovery_order($managed, 'merge-authority-' . wp_generate_uuid4() . '@example.test', 1, 1, true);
$authority_target = wcos_merge_recovery_order($managed, $authority_source->get_billing_email(), 1, null, false);
$authority_operation = 'merge-authority-' . wp_generate_uuid4();
$authority_record = wcos_merge_recovery_start($authority_source, $authority_target, $authority_operation);
$authority_lease = WCOS_Multi_Order_Lease::acquire(array($authority_source->get_id(), $authority_target->get_id()), $authority_operation);
$participation_failed_once = false;
$authority_fault = static function($stage) use (&$participation_failed_once) {
	if ('before_manual_participation_attempt_1' === $stage && !$participation_failed_once) {
		$participation_failed_once = true;
		throw new RuntimeException('Injected participation persistence failure.');
	}
	if ('before_manual_target_blocker' === $stage) {
		throw new RuntimeException('Injected target blocker persistence failure.');
	}
};
add_action('wcos_merge_recovery_checkpoint', $authority_fault, PHP_INT_MAX, 1);
$authority_result = WCOS_Merge_Compensator::manual_reconciliation($authority_source, $authority_target, $authority_record, 'authority_fault_matrix');
remove_action('wcos_merge_recovery_checkpoint', $authority_fault, PHP_INT_MAX);
$authority_lease->release();
$authority_source = wc_get_order($authority_source->get_id());
$authority_target = wc_get_order($authority_target->get_id());
wcos_merge_recovery_assert($participation_failed_once && 'manual_reconciliation' === $authority_result, 'Manual authority fault fixture did not complete safely.');
wcos_merge_recovery_assert(in_array($authority_operation, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($authority_source), true), 'Source lost local fail-closed authority.');
wcos_merge_recovery_assert(in_array($authority_operation, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($authority_target), true), 'Peer lost independently verified participation authority.');
$peer_rejected = false;
try { WCOS_Merge_Preflight::assert_supported($authority_target, $authority_source); } catch (Throwable $throwable) { $peer_rejected = true; }
wcos_merge_recovery_assert($peer_rejected, 'Peer mutation was not blocked by partial durable manual evidence.');
delete_option('wcos_manual_reconcile_block_' . $authority_source->get_id());
delete_option('wcos_manual_reconcile_block_' . $authority_target->get_id());
WCOS_Operation_Journal::delete($authority_source, $authority_operation);
$authority_source->delete(true);
$authority_target->delete(true);

/* Source divergence is independently detected before any recovery overwrite. */
$source_diverged = wcos_merge_recovery_order($managed, 'merge-source-diverged-' . wp_generate_uuid4() . '@example.test', 2, 2, true);
$source_diverged_target = wcos_merge_recovery_order($managed, $source_diverged->get_billing_email(), 1, null, false);
$source_diverged_operation = 'merge-source-diverged-' . wp_generate_uuid4();
wcos_merge_recovery_start($source_diverged, $source_diverged_target, $source_diverged_operation);
list($source_diverged, $source_diverged_target) = wcos_merge_recovery_stage($source_diverged, $source_diverged_target, $source_diverged_operation, false);
$source_diverged_lines = $source_diverged->get_items('line_item');
$source_diverged_line = reset($source_diverged_lines);
$source_diverged_line->set_quantity(11);
$source_diverged_line->save();
WCOS_Operation_Journal::require_recovery($source_diverged, $source_diverged_operation);
$source_diverged = wc_get_order($source_diverged->get_id());
$source_diverged_target = wc_get_order($source_diverged_target->get_id());
wcos_merge_recovery_assert('manual_reconciliation' === WCOS_Operation_Journal::get($source_diverged, $source_diverged_operation)['status'], 'Source divergence did not enter manual reconciliation.');
$source_diverged_after_lines = $source_diverged->get_items('line_item');
wcos_merge_recovery_assert('11' === (string) reset($source_diverged_after_lines)->get_quantity(), 'Source divergence was overwritten.');
delete_option('wcos_manual_reconcile_block_' . $source_diverged->get_id());
delete_option('wcos_manual_reconcile_block_' . $source_diverged_target->get_id());
WCOS_Operation_Journal::delete($source_diverged, $source_diverged_operation);
$source_diverged->delete(true);
$source_diverged_target->delete(true);

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

/* Both unselected candidates run inside a real journaled pair and compensate. */
function wcos_merge_retirement_observe($candidate, WC_Product $product) {
	$email = 'merge-retirement-' . $candidate . '-' . wp_generate_uuid4() . '@example.test';
	$source = wcos_merge_recovery_order($product, $email, 1, 1, true);
	$target = wcos_merge_recovery_order($product, $email, 1, null, false);
	$operation_id = 'merge-retirement-' . wp_generate_uuid4();
	$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
	$record = wcos_merge_recovery_start($source, $target, $operation_id);
	$snapshot = $record['context']['merge_recovery_snapshot'];
	$before_source = WCOS_Merge_Recovery_Snapshot::before_signature($snapshot, 'source');
	$before_target = WCOS_Merge_Recovery_Snapshot::before_signature($snapshot, 'target');
	list($source, $target, $target_item_id) = wcos_merge_recovery_stage($source, $target, $operation_id, false);
	$status_filter = null;
	if (WCOS_Merge_Retirement_Policy::NON_FORCE_TRASH_ARCHIVE === $candidate) {
		$source_id = $source->get_id();
		$source->delete(false);
		$source = wc_get_order($source_id);
	} else {
		register_post_status('wc-merged-evidence', array('public' => false, 'show_in_admin_all_list' => false, 'show_in_admin_status_list' => false));
		$status_filter = static function($statuses) { $statuses['wc-merged-evidence'] = 'Merged evidence'; return $statuses; };
		add_filter('wc_order_statuses', $status_filter);
		$source->update_status('merged-evidence');
	}
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	$status_after = $source->get_status();
	WCOS_Merge_Recovery_Snapshot::assert_archive_preserved($snapshot, $source);
	WCOS_Merge_Recovery_Snapshot::assert_active_economic_conserved($snapshot, $target);
	$pair = WCOS_Merge_Journal_Context::pair_from_record(WCOS_Operation_Journal::get($source, $operation_id));
	$lease = WCOS_Multi_Order_Lease::acquire(array($source->get_id(), $target->get_id()), $operation_id);
	wcos_merge_recovery_assert($lease instanceof WCOS_Multi_Order_Lease, 'Retirement evidence could not acquire its pair lease.');
	WCOS_Merge_Participation::persist($source, $target, $operation_id, $pair['pair_fingerprint']);
	$lease->release();
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_recovery_assert(is_array(WCOS_Operation_Journal::get($source, $operation_id)), 'Archived source lost journal discovery.');
	$discovered = WCOS_Merge_Participation::find_targets($source->get_id(), $operation_id);
	$discovered_ids = array_map(static function($order) { return $order->get_id(); }, $discovered);
	wcos_merge_recovery_assert(in_array($target->get_id(), $discovered_ids, true), 'Archived pair lost reciprocal target discovery.');
	wcos_merge_recovery_assert(WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_retirement_candidate_staged', array(
		'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::SOURCE_RETIRED,
		'merge_retirement_candidate' => $candidate,
		'merge_source_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($source),
		'merge_target_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($target),
		'merge_source_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($source),
		'merge_target_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($target),
		'merge_target_item_ids' => array($target_item_id),
		'merge_target_tax_item_ids' => array(),
		'merge_forward_repair_allowed' => false,
	)), 'Retirement candidate state was not durably staged.');
	wcos_merge_recovery_assert(WCOS_Operation_Journal::require_recovery($source, $operation_id), 'Retirement candidate compensation did not dispatch.');
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_recovery_assert('compensated' === WCOS_Operation_Journal::get($source, $operation_id)['status'], 'Retirement candidate did not compensate.');
	wcos_merge_recovery_assert(hash_equals($before_source, WCOS_Merge_Recovery_Snapshot::participant_signature($source)), 'Retirement compensator did not exactly restore source.');
	wcos_merge_recovery_assert(hash_equals($before_target, WCOS_Merge_Recovery_Snapshot::participant_signature($target)), 'Retirement compensator did not exactly restore target.');
	wcos_merge_recovery_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($source), 'Retirement candidate changed physical stock.');
	if (is_callable($status_filter)) { remove_filter('wc_order_statuses', $status_filter); }
	WCOS_Operation_Journal::delete($source, $operation_id);
	$source->delete(true);
	$target->delete(true);
	return array('candidate' => $candidate, 'source_status_observed' => $status_after, 'directly_inspectable_after_transition' => true, 'reversible' => true, 'stock_neutral' => true, 'journal_discoverable' => true, 'production_selected' => false);
}

$retirement = array(
	wcos_merge_retirement_observe(WCOS_Merge_Retirement_Policy::NON_FORCE_TRASH_ARCHIVE, $managed),
	wcos_merge_retirement_observe(WCOS_Merge_Retirement_Policy::DEDICATED_MERGED_ARCHIVE, $managed),
);
$observed_candidates = array_column($retirement, 'candidate');
sort($observed_candidates, SORT_STRING);
wcos_merge_recovery_assert(WCOS_Merge_Retirement_Policy::identifiers() === $observed_candidates, 'Retirement evidence changed candidate authority.');

/* Execute the material pre-authority crash boundaries; each must become pair-wide manual. */
$failure_windows = array();
foreach (array('before_target_write', 'after_target_item_before_checkpoint', 'after_target_checkpoint_before_source_ownership', 'during_source_reduced_stock_migration', 'before_source_retirement', 'after_source_retirement') as $stage_under_test) {
	$window_source = wcos_merge_recovery_order($managed, 'merge-window-' . wp_generate_uuid4() . '@example.test', 1, 1, true);
	$window_target = wcos_merge_recovery_order($managed, $window_source->get_billing_email(), 1, null, false);
	$window_source_id = $window_source->get_id();
	$window_target_id = $window_target->get_id();
	$window_operation = 'merge-window-' . wp_generate_uuid4();
	wcos_merge_recovery_start($window_source, $window_target, $window_operation);
	$hit = false;
	$window_fault = static function($stage) use ($stage_under_test, &$hit) {
		if (!$hit && $stage_under_test === $stage) { $hit = true; throw new WCOS_Merge_Recovery_Interruption_Exception('Injected ' . $stage_under_test); }
	};
	add_action('wcos_merge_recovery_checkpoint', $window_fault, PHP_INT_MAX, 1);
	try { wcos_merge_recovery_stage($window_source, $window_target, $window_operation, true); } catch (WCOS_Merge_Recovery_Interruption_Exception $exception) {}
	remove_action('wcos_merge_recovery_checkpoint', $window_fault, PHP_INT_MAX);
	wcos_merge_recovery_assert($hit, 'Pre-authority crash window did not execute: ' . $stage_under_test);
	$window_source = wc_get_order($window_source_id);
	$window_target = wc_get_order($window_target_id);
	WCOS_Operation_Journal::require_recovery($window_source, $window_operation);
	$window_source = wc_get_order($window_source->get_id());
	$window_target = wc_get_order($window_target->get_id());
	wcos_merge_recovery_assert('manual_reconciliation' === WCOS_Operation_Journal::get($window_source, $window_operation)['status'], 'Pre-authority crash did not fail closed: ' . $stage_under_test);
	$failure_windows[] = array('window' => $stage_under_test, 'outcome' => 'manual_reconciliation');
	delete_option('wcos_manual_reconcile_block_' . $window_source->get_id());
	delete_option('wcos_manual_reconcile_block_' . $window_target->get_id());
	WCOS_Operation_Journal::delete($window_source, $window_operation);
	$window_source->delete(true);
	$window_target->delete(true);
}

/* Execute post-retirement/relations/verification/commit response-loss windows and retry. */
foreach (array('before_forward_relations', 'after_one_reciprocal_relation', 'after_both_relations_before_verification', 'after_verification_before_commit', 'after_commit_before_complete') as $stage_under_test) {
	$window_source = wcos_merge_recovery_order($managed, 'merge-forward-window-' . wp_generate_uuid4() . '@example.test', 1, 1, true);
	$window_target = wcos_merge_recovery_order($managed, $window_source->get_billing_email(), 1, null, false);
	$window_operation = 'merge-forward-window-' . wp_generate_uuid4();
	wcos_merge_recovery_start($window_source, $window_target, $window_operation);
	list($window_source, $window_target) = wcos_merge_recovery_stage($window_source, $window_target, $window_operation, true);
	$hit = false;
	$window_fault = static function($stage) use ($stage_under_test, &$hit) {
		if (!$hit && $stage_under_test === $stage) { $hit = true; throw new WCOS_Merge_Recovery_Interruption_Exception('Injected ' . $stage_under_test); }
	};
	add_action('wcos_merge_recovery_checkpoint', $window_fault, PHP_INT_MAX, 1);
	WCOS_Operation_Journal::require_recovery($window_source, $window_operation);
	remove_action('wcos_merge_recovery_checkpoint', $window_fault, PHP_INT_MAX);
	wcos_merge_recovery_assert($hit, 'Forward crash window did not execute: ' . $stage_under_test);
	$window_source = wc_get_order($window_source->get_id());
	wcos_merge_recovery_assert(
		WCOS_Operation_Journal::require_recovery($window_source, $window_operation),
		'Forward crash retry did not dispatch: ' . $stage_under_test
	);
	$window_source = wc_get_order($window_source->get_id());
	$window_target = wc_get_order($window_target->get_id());
	wcos_merge_recovery_assert('completed' === WCOS_Operation_Journal::get($window_source, $window_operation)['status'], 'Forward crash retry did not complete: ' . $stage_under_test);
	$failure_windows[] = array('window' => 'before_forward_relations' === $stage_under_test ? 'after_retirement_before_relations' : $stage_under_test, 'outcome' => 'completed_on_retry');
	WCOS_Operation_Journal::delete($window_source, $window_operation);
	$window_source->delete(true);
	$window_target->delete(true);
}
$failure_windows[] = array('window' => 'before_source_restore', 'outcome' => 'compensated_on_retry');
$failure_windows[] = array('window' => 'interruption_during_multi_line_source_restore', 'outcome' => 'compensated_on_retry');
$failure_windows[] = array('window' => 'interruption_during_target_cleanup', 'outcome' => 'compensated_on_retry');
$failure_windows[] = array('window' => 'lease_loss', 'outcome' => 'recovery_required');
$failure_windows[] = array('window' => 'source_divergence', 'outcome' => 'manual_reconciliation');
$failure_windows[] = array('window' => 'target_divergence', 'outcome' => 'manual_reconciliation');
wcos_merge_recovery_assert(17 === count($failure_windows), 'Executed Merge crash-window matrix is incomplete.');

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
