<?php

if (!defined('ABSPATH')) { exit(1); }
$label = isset($args[0]) ? sanitize_key((string) $args[0]) : '';
$index = isset($args[1]) && in_array((string) $args[1], array('0', '1'), true) ? (int) $args[1] : -1;
$fixture = get_option('wcos_bulk_return_execute_race_fixture', array());
if ('' === $label || !isset($fixture['batches'][$index])) { fwrite(STDERR, "BULK_EXECUTE_WORKER_INVALID\n"); exit(2); }
wp_set_current_user(absint($fixture['user_id'])); $batch = $fixture['batches'][$index];
try {
	$result = (new WCOS_Mutation_Gateway())->bulk_return_advance($batch['batch_id'], $batch['anchor_child_id'], $batch['batch_token'], absint($fixture['user_id']), 0);
	echo 'RESULT ' . $label . ' ' . sanitize_key((string) $result['status']) . ' ' . (int) $result['cursor'] . "\n";
} catch (WCOS_Bulk_Return_Orchestrator_Exception $exception) {
	echo 'RETRY ' . $label . ' ' . $exception->get_reason() . ' ' . ($exception->is_retryable() ? 'yes' : 'no') . "\n";
} catch (Throwable $throwable) {
	fwrite(STDERR, 'BULK_EXECUTE_WORKER_FAILED ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n"); exit(3);
}
