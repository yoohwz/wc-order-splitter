<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_control_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
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

/* Expire the first lease and prove stale release cannot delete its successor. */
$lock_key = 'wcos_mutation_lock_' . $order_id;
$expired = get_option($lock_key);
wcos_control_assert(is_array($expired), 'Mutation lease option is missing.');
$expired['expires_at'] = time() - 1;
update_option($lock_key, $expired, false);

$lease_two = WCOS_Operation_Lock::acquire($order_id, $operation_two, 30);
wcos_control_assert(is_string($lease_two) && '' !== $lease_two && $lease_two !== $lease_one, 'Expired lease was not replaced with a unique successor.');
wcos_control_assert(false === WCOS_Operation_Lock::release($order_id, $lease_one), 'A stale worker released the successor lease.');
wcos_control_assert(WCOS_Operation_Lock::is_owned($order_id, $lease_two), 'The successor lease disappeared after stale release.');
wcos_control_assert(WCOS_Operation_Lock::refresh($order_id, $lease_two, 60), 'The active lease could not be refreshed.');
wcos_control_assert(WCOS_Operation_Lock::release($order_id, $lease_two), 'The active worker could not release its lease.');
wcos_control_assert(false === get_option($lock_key, false), 'Mutation lease option remained after release.');

$journal_operation = 'integration-journal-' . wp_generate_uuid4();
$fingerprint = WCOS_Mutation_Fingerprint::create('split', $order_id, array('plan' => array('child-a' => array(1 => '1.000000'))));
wcos_control_assert(
	WCOS_Operation_Journal::start($order, $journal_operation, 'split', array('test' => true), $fingerprint),
	'Operation journal could not be started.'
);
wcos_control_assert(
	false === WCOS_Operation_Journal::start($order, $journal_operation, 'split', array(), $fingerprint),
	'Duplicate journal start was accepted.'
);

$record = WCOS_Operation_Journal::get(wc_get_order($order_id), $journal_operation);
wcos_control_assert(is_array($record) && 'started' === $record['status'], 'Durable journal was not readable through a fresh order object.');
wcos_control_assert(null === $record['completed_at'], 'A new journal unexpectedly has a terminal timestamp.');
wcos_control_assert($fingerprint === $record['fingerprint'], 'Journal fingerprint was not preserved.');

$fingerprint_rejected = false;
try {
	WCOS_Operation_Journal::assert_fingerprint(
		$record,
		WCOS_Mutation_Fingerprint::create('split', $order_id, array('plan' => array('child-b' => array(1 => '1.000000'))))
	);
} catch (RuntimeException $exception) {
	$fingerprint_rejected = false !== strpos($exception->getMessage(), 'different mutation request');
}
wcos_control_assert($fingerprint_rejected, 'Journal accepted a different mutation fingerprint.');

wcos_control_assert(
	WCOS_Operation_Journal::checkpoint($order, $journal_operation, 'child_persisted', array('target_order_ids' => array(123))),
	'Journal checkpoint failed.'
);
wcos_control_assert(
	WCOS_Operation_Journal::fail($order, $journal_operation, array('error' => 'injected failure')),
	'Journal failure transition failed.'
);
$record = WCOS_Operation_Journal::get(wc_get_order($order_id), $journal_operation);
wcos_control_assert('failed' === $record['status'] && !empty($record['completed_at']), 'Failed journal state did not receive a terminal timestamp.');

wcos_control_assert(
	WCOS_Operation_Journal::resume($order, $journal_operation, array('retry' => true)),
	'Journal resume transition failed.'
);
$record = WCOS_Operation_Journal::get(wc_get_order($order_id), $journal_operation);
wcos_control_assert('started' === $record['status'] && 'resumed' === $record['stage'], 'Journal did not return to an active state.');
wcos_control_assert(null === $record['completed_at'], 'Resumed journal retained a terminal timestamp.');

wcos_control_assert(
	WCOS_Operation_Journal::mark_committed($order, $journal_operation, array('target_order_ids' => array(123))),
	'Journal commit marker failed.'
);
wcos_control_assert(
	WCOS_Operation_Journal::complete($order, $journal_operation, array('verified' => true)),
	'Journal completion failed.'
);

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

echo "operation-lock-and-journal-ok\n";
