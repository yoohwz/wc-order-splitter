<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_merge_foundation_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_merge_foundation_order($email, $line_count = 1) {
	$order = wc_create_order();
	$order->set_status('pending');
	$order->set_currency('USD');
	$order->set_customer_id(0);
	$order->set_billing_email($email);
	$order->set_billing_first_name('Merge');
	$order->set_billing_last_name('Contract');
	$order->set_billing_address_1('37 Foundation Way');
	$order->set_billing_city('Testville');
	$order->set_billing_state('CA');
	$order->set_billing_postcode('90001');
	$order->set_billing_country('US');
	$order->set_billing_phone('+1 555 0100');
	$order->set_shipping_first_name('Merge');
	$order->set_shipping_last_name('Contract');
	$order->set_shipping_address_1('37 Foundation Way');
	$order->set_shipping_city('Testville');
	$order->set_shipping_state('CA');
	$order->set_shipping_postcode('90001');
	$order->set_shipping_country('US');
	$order->set_payment_method('cod');
	$order->set_payment_method_title('Cash on delivery');

	for ($index = 0; $index < $line_count; $index++) {
		$item = new WC_Order_Item_Product();
		$item->set_name('Persisted configured line');
		$item->set_product_id(999999);
		$item->set_variation_id(888888);
		$item->set_quantity(1);
		$item->set_subtotal('10.00');
		$item->set_total('10.00');
		$item->set_subtotal_tax('0.00');
		$item->set_total_tax('0.00');
		$item->set_taxes(array('subtotal' => array(), 'total' => array()));
		$item->add_meta_data('Configuration', array('finish' => 'matte', 'slot' => 'A'), true);
		$order->add_item($item);
	}
	$order->calculate_totals(false);
	$order->save();
	return wc_get_order($order->get_id());
}

function wcos_merge_foundation_reason(WC_Order $source, WC_Order $target) {
	return WCOS_Merge_Preflight::report($source, $target)['reason'];
}

function wcos_merge_foundation_expect_reason(WC_Order $source, WC_Order $target, $reason, $message) {
	wcos_merge_foundation_assert($reason === wcos_merge_foundation_reason($source, $target), $message);
}

$email = 'merge-' . wp_generate_uuid4() . '@example.test';
$source = wcos_merge_foundation_order($email, 2);
$target = wcos_merge_foundation_order($email, 1);
$source_id = $source->get_id();
$target_id = $target->get_id();

/* Production enablement and routing remain intentionally absent. */
wcos_merge_foundation_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE), 'MERGE gate became enabled.');
wcos_merge_foundation_assert(WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::MANUAL_QUANTITY), 'Manual strategy gate changed.');
wcos_merge_foundation_assert(!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY), 'Category strategy gate changed.');
wcos_merge_foundation_assert(!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS), 'Stock strategy gate changed.');
$gateway = new WCOS_Mutation_Gateway();
try {
	$gateway->merge($source, $target, 'merge-foundation-disabled');
	throw new RuntimeException('Gateway unexpectedly exposed Merge execution.');
} catch (RuntimeException $exception) {
	wcos_merge_foundation_assert(false !== strpos($exception->getMessage(), 'disabled'), 'Gateway no longer fails at the hard-off Merge boundary.');
}

/* Canonical historical-state planning is read-only and never coalesces lines. */
$report = WCOS_Merge_Preflight::assert_supported($source, $target);
wcos_merge_foundation_assert(2 === count($report['plan']['lines']), 'Plan did not preserve one target line per source item.');
wcos_merge_foundation_assert(false === $report['plan']['coalesce_lines'], 'Plan enabled line coalescing.');
$source_line_ids = array_column($report['plan']['lines'], 'source_item_id');
wcos_merge_foundation_assert(2 === count(array_unique($source_line_ids)), 'Configured variation lines collided by source item ID.');
wcos_merge_foundation_assert(
	WCOS_Merge_Plan::fingerprint($report['plan']) === WCOS_Merge_Plan::fingerprint(WCOS_Merge_Plan::build(wc_get_order($source_id), wc_get_order($target_id))),
	'Canonical plan fingerprint was not stable.'
);
wcos_merge_foundation_assert(
	WCOS_Merge_Retirement_Policy::identifiers() === $report['retirement_candidates'],
	'Retirement candidates changed or became selected.'
);

/* Keyed compatibility proof contains no durable raw customer context. */
$durable_json = wp_json_encode(array($report['context_authority'], $report['plan']));
foreach (array($email, '37 Foundation Way', '+1 555 0100') as $pii) {
	wcos_merge_foundation_assert(false === strpos($durable_json, $pii), 'Raw PII entered a durable Merge proof.');
}
$tampered_context = $report['context_authority'];
$tampered_context['billing_context_digest'] = str_repeat('0', 64);
try {
	WCOS_Merge_Context_Signature::assert_current($source, $tampered_context);
	throw new RuntimeException('Tampered keyed context was accepted.');
} catch (RuntimeException $exception) {
	wcos_merge_foundation_assert(false !== strpos($exception->getMessage(), 'signature'), 'Unexpected keyed-signature failure.');
}

$registered_source = wcos_merge_foundation_order('registered@example.test', 1);
$registered_target = wcos_merge_foundation_order('registered@example.test', 1);
$registered_source->set_customer_id(707);
$registered_target->set_customer_id(707);
$registered_source->save();
$registered_target->save();
$registered_context = WCOS_Merge_Context_Signature::compatibility($registered_source, $registered_target);
wcos_merge_foundation_assert('registered' === $registered_context['identity_type'], 'Equal registered customer IDs did not form keyed identity authority.');
$registered_target->set_customer_id(708);
$registered_target->save();
wcos_merge_foundation_expect_reason($registered_source, $registered_target, 'incompatible_pair_context', 'Different registered customer IDs passed preflight.');
$registered_source->delete(true);
$registered_target->delete(true);

/* Narrow policy rejection matrix. */
wcos_merge_foundation_expect_reason($source, $source, 'same_order', 'Self Merge passed preflight.');
$target->set_currency('EUR');
$target->save();
wcos_merge_foundation_expect_reason($source, $target, 'incompatible_currency', 'Currency mismatch passed preflight.');
$target->set_currency('USD');
$target->set_status('on-hold');
$target->save();
wcos_merge_foundation_expect_reason($source, $target, 'incompatible_status', 'Status mismatch passed preflight.');
$target->set_status('pending');
$target->set_payment_method('bacs');
$target->save();
wcos_merge_foundation_expect_reason($source, $target, 'incompatible_pair_context', 'Payment mismatch passed preflight.');
$target->set_payment_method('cod');
$target->set_customer_id(77);
$target->save();
wcos_merge_foundation_expect_reason($source, $target, 'incompatible_pair_context', 'Customer mismatch passed preflight.');
$target->set_customer_id(0);
$target->set_billing_phone('+1 555 9999');
$target->save();
wcos_merge_foundation_expect_reason($source, $target, 'incompatible_pair_context', 'Billing-context mismatch passed preflight.');
$failure_json = wp_json_encode(WCOS_Merge_Preflight::report($source, $target));
foreach (array($email, '37 Foundation Way', '+1 555 9999') as $pii) {
	wcos_merge_foundation_assert(false === strpos($failure_json, $pii), 'Raw PII entered preflight failure evidence.');
}
$target->set_billing_phone('+1 555 0100');
$target->set_transaction_id('txn_foundation_reject');
$target->save();
wcos_merge_foundation_expect_reason($source, $target, 'paid_order_unsupported', 'Transaction-bearing order passed preflight.');
$target->set_transaction_id('');
$target->save();

$coupon = new WC_Order_Item_Coupon();
$coupon->set_code('foundation');
$coupon->set_discount('1.00');
$coupon->set_discount_tax('0.00');
$target->add_item($coupon);
$target->save();
wcos_merge_foundation_expect_reason($source, $target, 'coupon_policy_missing', 'Coupon ownership passed preflight.');
$target->remove_item($coupon->get_id());
$fee = new WC_Order_Item_Fee();
$fee->set_name('Foundation fee');
$fee->set_amount('1.00');
$fee->set_total('1.00');
$target->add_item($fee);
$target->save();
wcos_merge_foundation_expect_reason($source, $target, 'fee_policy_missing', 'Fee ownership passed preflight.');
$target->remove_item($fee->get_id());
$target->save();

$shipping = new WC_Order_Item_Shipping();
$shipping->set_method_title('Foundation shipping');
$shipping->set_method_id('flat_rate');
$shipping->set_total('1.00');
$source->add_item($shipping);
$source->save();
wcos_merge_foundation_expect_reason($source, $target, 'source_shipping_policy_missing', 'Source shipping ownership passed preflight.');
$source->remove_item($shipping->get_id());
$source->save();

$source_items = $source->get_items('line_item');
$first_source_item = reset($source_items);
$first_source_item->add_meta_data('_unclassified_merge_state', 'unsafe', true);
$first_source_item->save();
wcos_merge_foundation_expect_reason($source, $target, 'incompatible_pair_context', 'Unknown private item metadata passed preflight.');
$first_source_item->delete_meta_data('_unclassified_merge_state');
$first_source_item->save();

$blank_guest = wcos_merge_foundation_order('', 1);
wcos_merge_foundation_expect_reason($source, $blank_guest, 'incompatible_pair_context', 'Blank guest identity passed preflight.');
$blank_guest->delete(true);

$refund_source = wcos_merge_foundation_order($email, 1);
$refund_target = wcos_merge_foundation_order($email, 1);
$refund = wc_create_refund(array(
	'amount' => '1.00',
	'reason' => 'Merge foundation rejection fixture',
	'order_id' => $refund_target->get_id(),
	'refund_payment' => false,
	'restock_items' => false,
));
wcos_merge_foundation_assert($refund instanceof WC_Order_Refund, 'Unable to establish refund policy fixture.');
wcos_merge_foundation_expect_reason($refund_source, wc_get_order($refund_target->get_id()), 'refund_policy_missing', 'Refund ownership passed preflight.');
$refund->delete(true);
$refund_source->delete(true);
$refund_target->delete(true);

/* Deterministic multi-order lease: reverse inputs, partial rollback, crossed attempts. */
$partial_operation = 'merge-partial-' . wp_generate_uuid4();
$competing_token = WCOS_Operation_Lock::acquire($target_id, 'merge-competitor', 60);
wcos_merge_foundation_assert(false !== $competing_token, 'Unable to establish competing lease fixture.');
wcos_merge_foundation_assert(false === WCOS_Multi_Order_Lease::acquire(array($target_id, $source_id), $partial_operation, 60), 'Partial lease acquisition did not fail atomically.');
$rollback_token = WCOS_Operation_Lock::acquire($source_id, 'merge-rollback-proof', 60);
wcos_merge_foundation_assert(false !== $rollback_token, 'Partial lease acquisition leaked its first lease.');
wcos_merge_foundation_assert(WCOS_Operation_Lock::release($source_id, $rollback_token), 'Rollback proof lease did not release.');
wcos_merge_foundation_assert(WCOS_Operation_Lock::release($target_id, $competing_token), 'Competing lease did not release.');

$stale_source = wcos_merge_foundation_order($email, 1);
$stale_target = wcos_merge_foundation_order($email, 1);
$stale_operation = 'merge-stale-' . wp_generate_uuid4();
$stale_lease = WCOS_Multi_Order_Lease::acquire(array($stale_target->get_id(), $stale_source->get_id()), $stale_operation, 60);
wcos_merge_foundation_assert($stale_lease instanceof WCOS_Multi_Order_Lease, 'Unable to establish stale lease fixture.');
$stale_lock_key = 'wcos_mutation_lock_' . $stale_source->get_id();
$stale_value = get_option($stale_lock_key);
$stale_value['expires_at'] = time() - 1;
update_option($stale_lock_key, $stale_value, false);
$successor_token = WCOS_Operation_Lock::acquire($stale_source->get_id(), 'merge-successor', 60);
wcos_merge_foundation_assert(false !== $successor_token, 'Expired participant lease could not be taken over.');
wcos_merge_foundation_assert(false === $stale_lease->release(), 'Stale multi-order release reported full ownership.');
wcos_merge_foundation_assert(WCOS_Operation_Lock::is_owned($stale_source->get_id(), $successor_token, 'merge-successor'), 'Stale release removed a successor lease.');
wcos_merge_foundation_assert(WCOS_Operation_Lock::release($stale_source->get_id(), $successor_token), 'Successor lease did not release.');
$stale_source->delete(true);
$stale_target->delete(true);

$operation_id = 'merge-foundation-' . wp_generate_uuid4();
$lease = WCOS_Multi_Order_Lease::acquire(array($target_id, $source_id, $target_id), $operation_id, 60);
wcos_merge_foundation_assert($lease instanceof WCOS_Multi_Order_Lease, 'Pair lease could not be acquired.');
wcos_merge_foundation_assert(array($source_id, $target_id) === $lease->order_ids(), 'Pair lease did not normalize ascending unique IDs.');
wcos_merge_foundation_assert(false === WCOS_Multi_Order_Lease::acquire(array($source_id, $target_id), 'merge-crossed', 60), 'Crossed acquisition bypassed an owned pair lease.');
wcos_merge_foundation_assert($lease->refresh(60), 'Pair lease refresh failed.');
$lease->assert_owned();

/* One source journal is authority; scalar participation closes the blocker crash window. */
$report = WCOS_Merge_Preflight::assert_supported(wc_get_order($source_id), wc_get_order($target_id));
$context = WCOS_Merge_Journal_Context::create($source, $target, $report['plan'], $report['context_authority']);
wcos_merge_foundation_assert(WCOS_Operation_Journal::start($source, $operation_id, 'merge', $context, $report['pair_fingerprint']), 'Source Merge journal did not start.');
$target->add_meta_data(WCOS_Merge_Participation::TARGET_SOURCE_META, 424242, false);
$target->add_meta_data(WCOS_Merge_Participation::TARGET_OPERATION_META, 'unrelated-operation', false);
$target->save_meta_data();
wcos_merge_foundation_assert(WCOS_Merge_Participation::persist($source, $target, $operation_id, $report['pair_fingerprint']), 'Pair participation did not persist.');
wcos_merge_foundation_assert(WCOS_Merge_Participation::persist($source, $target, $operation_id, $report['pair_fingerprint']), 'Pair participation was not idempotent.');
$fresh_target = wc_get_order($target_id);
wcos_merge_foundation_assert(1 === count(array_filter(array_map('strval', $fresh_target->get_meta(WCOS_Merge_Participation::TARGET_AUTHORITY_META, false)), static function($value) use ($operation_id) {
	return false !== strpos($value, '|' . $operation_id . '|');
})), 'Idempotent persistence duplicated the atomic target authority pointer.');
wcos_merge_foundation_assert(in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($source), true), 'Source crash window did not fail closed.');
wcos_merge_foundation_assert(in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($fresh_target), true), 'Target crash window did not dereference the source journal.');
$discovered_target_ids = array_map(static function($order) { return $order->get_id(); }, WCOS_Merge_Participation::find_targets($source_id, $operation_id));
wcos_merge_foundation_assert(in_array($target_id, $discovered_target_ids, true), 'Relation discovery did not find the target in the active storage mode.');

wcos_merge_foundation_assert(WCOS_Manual_Reconciliation_Blocker::block_participant($source, $source, $operation_id, 'source', $target_id, $report['pair_fingerprint']), 'Source participant blocker did not persist.');
wcos_merge_foundation_assert(in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($fresh_target), true), 'Missing target blocker after source persistence did not fail closed through participation.');
wcos_merge_foundation_assert(WCOS_Manual_Reconciliation_Blocker::block_participant($target, $source, $operation_id, 'target', $source_id, $report['pair_fingerprint']), 'Target participant blocker did not persist.');
$journal = WCOS_Operation_Journal::get($source, $operation_id);
$durable_json = wp_json_encode(array($journal, get_option('wcos_manual_reconcile_block_' . $source_id), get_option('wcos_manual_reconcile_block_' . $target_id)));
foreach (array($email, '37 Foundation Way', '+1 555 0100') as $pii) {
	wcos_merge_foundation_assert(false === strpos($durable_json, $pii), 'Raw PII entered journal or blocker evidence.');
}

/* Target fallback alone remains authoritative and resolves only from the newer source journal. */
$target_blocker_key = 'wcos_manual_reconcile_block_' . $target_id;
$target_primary = get_option($target_blocker_key);
$target_incident = $target_primary['operations'][$operation_id];
$fresh_target->update_meta_data(WCOS_Manual_Reconciliation_Blocker::FALLBACK_META_PREFIX . $operation_id, $target_incident);
$fresh_target->save_meta_data();
delete_option($target_blocker_key);
wcos_merge_foundation_assert(in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($fresh_target), true), 'Target-only fallback blocker did not fail closed.');
$source_blocker_key = 'wcos_manual_reconcile_block_' . $source_id;
$source_primary = get_option($source_blocker_key);
$source_incident = $source_primary['operations'][$operation_id];
$fresh_source = wc_get_order($source_id);
$fresh_source->update_meta_data(WCOS_Manual_Reconciliation_Blocker::FALLBACK_META_PREFIX . $operation_id, $source_incident);
$fresh_source->save_meta_data();
delete_option($source_blocker_key);
wcos_merge_foundation_assert(in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($fresh_source), true), 'Source-only fallback blocker did not fail closed.');
wcos_merge_foundation_assert(WCOS_Operation_Journal::mark_manual_reconciliation($source, $operation_id, array('reason' => 'foundation_test')), 'Manual-reconciliation transition failed.');
wcos_merge_foundation_assert(WCOS_Operation_Journal::mark_manual_reconciled($source, $operation_id, array('resolution' => 'verified')), 'Manual-reconciled transition failed.');
wcos_merge_foundation_assert(!in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids(wc_get_order($source_id)), true), 'Source blocker survived authoritative reconciliation.');
wcos_merge_foundation_assert(!in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids(wc_get_order($target_id)), true), 'Target fallback survived authoritative reconciliation.');

/* A newer incident cannot be cleared by the older reconciliation, and either participant preserves retention proof. */
wcos_merge_foundation_assert(WCOS_Manual_Reconciliation_Blocker::block_pair($source, $target, $operation_id, $report['pair_fingerprint']), 'Unable to establish newer pair incidents.');
wcos_merge_foundation_assert(!WCOS_Manual_Reconciliation_Blocker::resolve_if_reconciled($source, $operation_id), 'Older reconciliation cleared a newer source incident.');
wcos_merge_foundation_assert(!WCOS_Manual_Reconciliation_Blocker::resolve_if_reconciled($target, $operation_id), 'Older reconciliation cleared a newer target incident.');
$old_record = WCOS_Operation_Journal::get($source, $operation_id);
$old_record['completed_at'] = '2000-01-01T00:00:00+00:00';
wcos_merge_foundation_assert(!WCOS_Operation_Journal_Retention::is_expired_terminal_record($old_record, time()), 'Retention discarded proof while pair blockers remained.');
delete_option('wcos_manual_reconcile_block_' . $target_id);
wcos_merge_foundation_assert(!WCOS_Operation_Journal_Retention::is_expired_terminal_record($old_record, time()), 'Retention discarded proof after only one participant blocker cleared.');
delete_option('wcos_manual_reconcile_block_' . $source_id);
wcos_merge_foundation_assert(WCOS_Manual_Reconciliation_Blocker::block_participant($target, $source, $operation_id, 'target', $source_id, $report['pair_fingerprint']), 'Unable to restore target-only retention fixture.');
wcos_merge_foundation_assert(!WCOS_Operation_Journal_Retention::is_expired_terminal_record($old_record, time()), 'Retention discarded proof while only the target blocker remained.');
delete_option('wcos_manual_reconcile_block_' . $target_id);

wcos_merge_foundation_assert(WCOS_Merge_Participation::cleanup($source, $target, $operation_id), 'Participation cleanup failed.');
$fresh_target = wc_get_order($target_id);
wcos_merge_foundation_assert(in_array('424242', array_map('strval', $fresh_target->get_meta(WCOS_Merge_Participation::TARGET_SOURCE_META, false)), true), 'Cleanup removed an unrelated source relation.');
wcos_merge_foundation_assert(in_array('unrelated-operation', array_map('strval', $fresh_target->get_meta(WCOS_Merge_Participation::TARGET_OPERATION_META, false)), true), 'Cleanup removed an unrelated operation relation.');
wcos_merge_foundation_assert($lease->release(), 'Pair lease did not release safely.');

/* Cleanup test records after terminal journal proof is no longer referenced. */
WCOS_Operation_Journal::delete($source, $operation_id);
$source->delete(true);
$target->delete(true);

/* A surviving participant remains blocked when its peer disappears. */
$orphan_source = wcos_merge_foundation_order($email, 1);
$orphan_target = wcos_merge_foundation_order($email, 1);
$orphan_report = WCOS_Merge_Preflight::assert_supported($orphan_source, $orphan_target);
$orphan_operation = 'merge-orphan-' . wp_generate_uuid4();
$orphan_context = WCOS_Merge_Journal_Context::create($orphan_source, $orphan_target, $orphan_report['plan'], $orphan_report['context_authority']);
wcos_merge_foundation_assert(WCOS_Operation_Journal::start($orphan_source, $orphan_operation, 'merge', $orphan_context, $orphan_report['pair_fingerprint']), 'Orphan journal fixture did not start.');
wcos_merge_foundation_assert(WCOS_Manual_Reconciliation_Blocker::block_participant($orphan_source, $orphan_source, $orphan_operation, 'source', $orphan_target->get_id(), $orphan_report['pair_fingerprint']), 'Orphan source blocker did not persist.');
$orphan_target->delete(true);
wcos_merge_foundation_assert(in_array($orphan_operation, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($orphan_source), true), 'Deleted peer made the surviving participant appear safe.');
delete_option('wcos_manual_reconcile_block_' . $orphan_source->get_id());
WCOS_Operation_Journal::delete($orphan_source, $orphan_operation);
$orphan_source->delete(true);

echo "merge-domain-foundation-ok\n";
