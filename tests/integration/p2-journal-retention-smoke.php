<?php

if (!defined('ABSPATH')) {
	exit(1);
}

/* Scheduling stays dormant while all production mutation gates are hard-off. */
wp_clear_scheduled_hook(WCOS_Operation_Journal_Retention::CRON_HOOK);
delete_option(WCOS_Operation_Journal_Retention::SCAN_STATE_OPTION);
WCOS_Operation_Journal_Retention::maybe_schedule();
wcos_p2_adapter_assert(false === wp_next_scheduled(WCOS_Operation_Journal_Retention::CRON_HOOK), 'Journal cleanup was scheduled while every production workflow gate is hard-off.');

$now = time();
wcos_p2_adapter_assert(
	WCOS_Operation_Journal_Retention::is_expired_terminal_record(array('status' => 'completed', 'completed_at' => gmdate('c', $now - (100 * DAY_IN_SECONDS))), $now),
	'Expired completed mutation journal was not eligible for retention cleanup.'
);
wcos_p2_adapter_assert(
	!WCOS_Operation_Journal_Retention::is_expired_terminal_record(array('status' => 'completed', 'completed_at' => gmdate('c', $now - DAY_IN_SECONDS)), $now),
	'Recent completed mutation journal was eligible for premature cleanup.'
);
wcos_p2_adapter_assert(
	!WCOS_Operation_Journal_Retention::is_expired_terminal_record(array('status' => 'recovery_required', 'completed_at' => gmdate('c', $now - (200 * DAY_IN_SECONDS))), $now),
	'Recovery-required mutation journal was eligible for destructive cleanup.'
);

/*
 * High-water-cycle regression: more than one full batch of active records must
 * not hide an expired record, and a record scanned while recent must be revisited
 * in a later cycle even if newer journal options keep arriving continuously.
 */
$fixture_prefix = 'wcos_mutation_op_retention_' . str_replace('-', '', wp_generate_uuid4()) . '_';
$fixture_keys = array();
$recent_key = $fixture_prefix . 'recent_then_expired';
add_option(
	$recent_key,
	array(
		'status' => 'completed',
		'completed_at' => gmdate('c', $now - DAY_IN_SECONDS),
	),
	'',
	false
);
$fixture_keys[] = $recent_key;

for ($index = 0; $index < 60; $index++) {
	$key = $fixture_prefix . sprintf('active_%03d', $index);
	add_option($key, array('status' => 'started', 'completed_at' => null), '', false);
	$fixture_keys[] = $key;
}

$expired_key = $fixture_prefix . 'expired_terminal';
add_option(
	$expired_key,
	array(
		'status' => 'completed',
		'completed_at' => gmdate('c', $now - (120 * DAY_IN_SECONDS)),
	),
	'',
	false
);
$fixture_keys[] = $expired_key;

/* First cycle spans multiple bounded pages and must eventually delete expired_key. */
for ($pass = 0; $pass < 10 && false !== get_option($expired_key, false); $pass++) {
	WCOS_Operation_Journal_Retention::cleanup();
}
wcos_p2_adapter_assert(false === get_option($expired_key, false), 'Retention high-water cycle never reached an expired journal behind active records.');
wcos_p2_adapter_assert(false !== get_option($recent_key, false), 'Retention cleanup deleted a recent terminal journal.');
wcos_p2_adapter_assert(false !== get_option($fixture_keys[1], false), 'Retention cleanup deleted an active mutation journal.');
wcos_p2_adapter_assert(false === get_option(WCOS_Operation_Journal_Retention::SCAN_STATE_OPTION, false), 'Retention scan state did not reset after reaching the first high-water mark.');

/* Age the previously scanned recent journal after cycle completion. */
update_option(
	$recent_key,
	array(
		'status' => 'completed',
		'completed_at' => gmdate('c', $now - (120 * DAY_IN_SECONDS)),
	),
	false
);

/* Add newer rows before the next cleanup cycle to simulate continuous traffic. */
for ($index = 0; $index < 10; $index++) {
	$key = $fixture_prefix . sprintf('new_%03d', $index);
	add_option($key, array('status' => 'started', 'completed_at' => null), '', false);
	$fixture_keys[] = $key;
}

for ($pass = 0; $pass < 10 && false !== get_option($recent_key, false); $pass++) {
	WCOS_Operation_Journal_Retention::cleanup();
}
wcos_p2_adapter_assert(false === get_option($recent_key, false), 'A journal scanned while recent was never reconsidered after it aged, despite a new high-water cycle.');

foreach ($fixture_keys as $fixture_key) {
	delete_option($fixture_key);
}
delete_option(WCOS_Operation_Journal_Retention::SCAN_STATE_OPTION);

echo "p2-journal-retention-high-water-cycle-ok\n";
