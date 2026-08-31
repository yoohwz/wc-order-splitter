<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_control_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_duplicate_targets($source_id, $operation_id) {
	return WCOS_Order_Relation_Repository::find(
		array(
			array('key' => '_wcos_operation_id', 'value' => sanitize_key($operation_id)),
			array('key' => '_wcos_duplicate_source_order', 'value' => absint($source_id), 'type' => 'NUMERIC'),
		),
		-1
	);
}

$order = wc_create_order();
$order->set_status('pending');
$order->save();
$order_id = $order->get_id();

$operation_one = 'integration-lock-one-' . wp_generate_uuid4();
$operation_two = 'integration-lock-two-' . wp_generate_uuid4();
$lease_one = WCOS_Operation_Lock::acquire($order_id, $operation_one, 30);
wcos_control_assert(is_string($lease_one) && '' !== $lease_one, 'First mutation lease was not acquired.');
wcos_control_assert(false === WCOS_Operation_Lock::acquire($order_id, $operation_two, 30), 'A concurrent mutation acquired the same order lease.');
wcos_control_assert(WCOS_Operation_Lock::is_owned($order_id, $lease_one), 'The first worker did not own its lease.');
wcos_control_assert(WCOS_Operation_Lock::refresh($order_id, $lease_one, 30, $operation_one), 'The first rapid lease refresh failed.');
wcos_control_assert(WCOS_Operation_Lock::refresh($order_id, $lease_one, 30, $operation_one), 'The second rapid lease refresh was mistaken for a lost lease.');
$rapid = get_option('wcos_mutation_lock_' . $order_id);
wcos_control_assert(is_array($rapid) && isset($rapid['revision']) && (int) $rapid['revision'] >= 3, 'Lease refresh revision did not advance monotonically.');
$lock_key = 'wcos_mutation_lock_' . $order_id;
$expired = get_option($lock_key);
wcos_control_assert(is_array($expired), 'Mutation lease option is missing.');
$expired['expires_at'] = time() - 1;
update_option($lock_key, $expired, false);
wcos_control_assert(false === WCOS_Operation_Lock::refresh($order_id, $lease_one, 30, $operation_one), 'An expired worker refreshed its stale lease.');
$lease_two = WCOS_Operation_Lock::acquire($order_id, $operation_two, 30);
wcos_control_assert(is_string($lease_two) && '' !== $lease_two && $lease_two !== $lease_one, 'Expired lease was not replaced with a unique successor.');
wcos_control_assert(false === WCOS_Operation_Lock::release($order_id, $lease_one), 'A stale worker released the successor lease.');
wcos_control_assert(WCOS_Operation_Lock::is_owned($order_id, $lease_two), 'The successor lease disappeared after stale release.');
wcos_control_assert(WCOS_Operation_Lock::refresh($order_id, $lease_two, 60, $operation_two), 'The active lease could not be refreshed.');
wcos_control_assert(WCOS_Operation_Lock::release($order_id, $lease_two), 'The active worker could not release its lease.');
wcos_control_assert(false === get_option($lock_key, false), 'Mutation lease option remained after release.');

$journal_operation = 'integration-journal-' . wp_generate_uuid4();
$fingerprint = WCOS_Mutation_Fingerprint::create('split', $order_id, array('plan' => array('child-a' => array(1 => '1.000000'))));
wcos_control_assert(WCOS_Operation_Journal::start($order, $journal_operation, 'split', array('test' => true), $fingerprint), 'Operation journal could not be started.');
wcos_control_assert(false === WCOS_Operation_Journal::start($order, $journal_operation, 'split', array(), $fingerprint), 'Duplicate journal start was accepted.');
$record = WCOS_Operation_Journal::get(wc_get_order($order_id), $journal_operation);
wcos_control_assert(is_array($record) && 'started' === $record['status'], 'Durable journal was not readable through a fresh order object.');
wcos_control_assert(null === $record['completed_at'], 'A new journal unexpectedly has a terminal timestamp.');
wcos_control_assert($fingerprint === $record['fingerprint'], 'Journal fingerprint was not preserved.');
$fingerprint_rejected = false;
try {
	WCOS_Operation_Journal::assert_fingerprint($record, WCOS_Mutation_Fingerprint::create('split', $order_id, array('plan' => array('child-b' => array(1 => '1.000000')))));
} catch (RuntimeException $exception) {
	$fingerprint_rejected = false !== strpos($exception->getMessage(), 'different mutation request');
}
wcos_control_assert($fingerprint_rejected, 'Journal accepted a different mutation fingerprint.');
wcos_control_assert(WCOS_Operation_Journal::checkpoint($order, $journal_operation, 'child_persisted', array('target_order_ids' => array(123))), 'Journal checkpoint failed.');
wcos_control_assert(WCOS_Operation_Journal::fail($order, $journal_operation, array('error' => 'injected failure')), 'Journal failure transition failed.');
$record = WCOS_Operation_Journal::get(wc_get_order($order_id), $journal_operation);
wcos_control_assert('failed' === $record['status'] && !empty($record['completed_at']), 'Failed journal state did not receive a terminal timestamp.');
wcos_control_assert(WCOS_Operation_Journal::resume($order, $journal_operation, array('retry' => true)), 'Journal resume transition failed.');
$record = WCOS_Operation_Journal::get(wc_get_order($order_id), $journal_operation);
wcos_control_assert('started' === $record['status'] && 'resumed' === $record['stage'], 'Journal did not return to an active state.');
wcos_control_assert(null === $record['completed_at'], 'Resumed journal retained a terminal timestamp.');
wcos_control_assert(WCOS_Operation_Journal::mark_committed($order, $journal_operation, array('target_order_ids' => array(123))), 'Journal commit marker failed.');
wcos_control_assert(WCOS_Operation_Journal::complete($order, $journal_operation, array('verified' => true)), 'Journal completion failed.');
$record = WCOS_Operation_Journal::get(wc_get_order($order_id), $journal_operation);
wcos_control_assert('completed' === $record['status'] && 'completed' === $record['stage'], 'Journal did not persist terminal state.');
wcos_control_assert(!empty($record['completed_at']), 'Completed journal is missing its terminal timestamp.');
wcos_control_assert(true === $record['context']['verified'], 'Journal context was not merged across transitions.');
wcos_control_assert(count($record['checkpoints']) >= 6, 'Journal checkpoints were not retained.');
$fresh_order = wc_get_order($order_id);
$summary = (array) $fresh_order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
$summary_entry = null;
foreach ($summary as $entry) {
	if (isset($entry['operation_id']) && $journal_operation === $entry['operation_id']) {
		$summary_entry = $entry;
		break;
	}
}
wcos_control_assert(is_array($summary_entry) && 'completed' === $summary_entry['status'], 'Bounded order-meta audit summary was not updated.');
wcos_control_assert(!empty($summary_entry['completed_at']), 'Audit summary is missing the terminal timestamp.');
wcos_control_assert(WCOS_Operation_Journal::delete($order, $journal_operation), 'Durable journal cleanup failed.');
$order->delete(true);

/*
 * Preserve the complete hard-off P2 regression suite after production gates are
 * enabled. This Reflection scope is test-only: it temporarily restores the
 * all-false gate map, executes the previously accepted hard-off contracts
 * unchanged, then restores the real release map.
 */
$feature_gate_reflection = new ReflectionClass('WCOS_Feature_Gates');
$feature_gate_states = $feature_gate_reflection->getProperty('states');
$feature_gate_states->setAccessible(true);
$release_gate_states = $feature_gate_states->getValue();
$hard_off_gate_states = $release_gate_states;
foreach ($hard_off_gate_states as $workflow => $enabled) {
	$hard_off_gate_states[$workflow] = false;
}
$feature_gate_states->setValue(null, $hard_off_gate_states);
$regression_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$regression_exclude_shipping = get_option('order_splitter_exclude_shipping_fee', 'no');
update_option('order_splitter_status_allowed', array('wc-pending', 'wc-processing'));
update_option('order_splitter_exclude_shipping_fee', 'yes');

try {
	require __DIR__ . '/p2-quantity-split-adapter-smoke.php';
	require __DIR__ . '/p2-manual-reconciliation-smoke.php';
	require __DIR__ . '/p2-manual-reconciliation-crash-window-smoke.php';
	require __DIR__ . '/p2-price-precision-smoke.php';
	require __DIR__ . '/p2-stock-matrix-smoke.php';
	require __DIR__ . '/p2-stock-cancellation-lifecycle-smoke.php';
	require __DIR__ . '/p2-charge-tax-matrix-smoke.php';
	require __DIR__ . '/p2-production-side-effect-smoke.php';
	require __DIR__ . '/p2-metadata-compatibility-smoke.php';
	require __DIR__ . '/p2-journal-retention-smoke.php';
	require __DIR__ . '/p2-admin-transport-smoke.php';
	require __DIR__ . '/p2-admin-client-state-smoke.php';
	require __DIR__ . '/p2-review-confirmation-toctou-smoke.php';
	require __DIR__ . '/p2-policy-replay-smoke.php';
	require __DIR__ . '/p2-durable-replay-smoke.php';
	require __DIR__ . '/p2-duplicate-readiness-smoke.php';
} finally {
	update_option('order_splitter_status_allowed', $regression_allowed_statuses);
	update_option('order_splitter_exclude_shipping_fee', $regression_exclude_shipping);
	$feature_gate_states->setValue(null, $release_gate_states);
}

wcos_control_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT), 'Release Split gate was not restored after hard-off regression scope.');
wcos_control_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE), 'Release Duplicate gate was not restored after hard-off regression scope.');
wcos_control_assert(WC_Order_Splitter_Safety_Guard::mutations_enabled(), 'Safety guard did not restore the production-enabled state.');

require __DIR__ . '/p2-production-split-enabled-smoke.php';
require __DIR__ . '/p2-manual-quantity-step-authority-smoke.php';
require __DIR__ . '/p2-production-duplicate-enabled-smoke.php';
require __DIR__ . '/p2-whole-line-plan-smoke.php';
require __DIR__ . '/p2-duplicate-side-effects-smoke.php';
require __DIR__ . '/p2-duplicate-precision-replay-smoke.php';
require __DIR__ . '/p2-duplicate-compatibility-smoke.php';
require __DIR__ . '/p2-duplicate-confirmation-race-smoke.php';
require __DIR__ . '/p2-confirmation-mutation-boundary-smoke.php';

echo "operation-lock-and-journal-ok\n";
