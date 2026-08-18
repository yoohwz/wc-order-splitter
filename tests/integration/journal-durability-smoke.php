<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_journal_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$order = wc_create_order();
$order->set_status('pending');
$order->save();
$order_id = $order->get_id();

$deep_operation = sanitize_key('journal-deep-' . wp_generate_uuid4());
$fingerprint = WCOS_Mutation_Fingerprint::create('duplicate', $order_id, array('case' => 'deep-history'));
wcos_journal_assert(
	WCOS_Operation_Journal::start($order, $deep_operation, 'duplicate', array('case' => 'deep-history'), $fingerprint),
	'Unable to create deep authoritative journal.'
);

for ($index = 0; $index < 60; $index++) {
	wcos_journal_assert(
		WCOS_Operation_Journal::checkpoint(
			$order,
			$deep_operation,
			'checkpoint_' . $index,
			array('checkpoint_index' => $index)
		),
		'Authoritative checkpoint update failed at index ' . $index . '.'
	);
}

$deep_record = WCOS_Operation_Journal::get(wc_get_order($order_id), $deep_operation);
wcos_journal_assert(is_array($deep_record), 'Deep authoritative journal could not be reloaded.');
wcos_journal_assert(61 === count($deep_record['checkpoints']), 'Authoritative journal checkpoints were silently truncated.');
wcos_journal_assert(61 === (int) $deep_record['revision'], 'Journal revision did not advance with every authoritative checkpoint.');
$last_checkpoint = end($deep_record['checkpoints']);
wcos_journal_assert(61 === (int) $last_checkpoint['sequence'], 'Authoritative checkpoint sequence is not monotonic.');

$operation_ids = array($deep_operation);
for ($index = 0; $index < 25; $index++) {
	$operation_id = sanitize_key('journal-summary-' . $index . '-' . wp_generate_uuid4());
	$operation_ids[] = $operation_id;
	$fp = WCOS_Mutation_Fingerprint::create('duplicate', $order_id, array('summary_index' => $index));
	wcos_journal_assert(
		WCOS_Operation_Journal::start($order, $operation_id, 'duplicate', array('summary_index' => $index), $fp),
		'Unable to create summary journal operation ' . $index . '.'
	);
	wcos_journal_assert(
		WCOS_Operation_Journal::fail($order, $operation_id, array('reason' => 'summary-bound-test')),
		'Unable to terminally record summary journal operation ' . $index . '.'
	);
}

$fresh = wc_get_order($order_id);
$summary = (array) $fresh->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
wcos_journal_assert(
	count($summary) <= WCOS_Operation_Journal::MAX_SUMMARY_ENTRIES,
	'Order-meta display summary exceeded its bounded size.'
);
wcos_journal_assert(
	is_array(WCOS_Operation_Journal::get($fresh, $deep_operation)),
	'Old authoritative journal disappeared when display summary was trimmed.'
);

foreach ($operation_ids as $operation_id) {
	WCOS_Operation_Journal::delete($fresh, $operation_id);
}
$fresh->delete(true);

echo "durable-authoritative-journal-and-bounded-summary-ok\n";
