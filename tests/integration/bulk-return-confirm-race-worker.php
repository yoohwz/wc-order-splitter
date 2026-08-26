<?php

if (!defined('ABSPATH')) { exit(1); }
$label = isset($args[0]) ? sanitize_key((string) $args[0]) : '';
$fixture = get_option('wcos_bulk_return_confirm_race_fixture', array());
if ('' === $label || empty($fixture['review_id'])) { fwrite(STDERR, "BULK_CONFIRM_WORKER_INVALID\n"); exit(2); }
wp_set_current_user(absint($fixture['user_id']));
try {
	$result = WCOS_Bulk_Return_Confirmation_Store::create($fixture['review_id'], $fixture['review_token'], absint($fixture['user_id']));
	$anchor = wc_get_order(absint($result['anchor_child_id']));
	$record = $anchor instanceof WC_Order ? WCOS_Operation_Journal::get($anchor, $result['batch_id']) : null;
	$verified = is_array($record) ? WCOS_Bulk_Return_Journal_Context::verify_request($record, $result['batch_token'], absint($fixture['user_id'])) : null;
	if (!is_array($verified) || 1 !== count($verified['authority']['operation_map'])
		|| false !== strpos(wp_json_encode($record), $result['batch_token'])) {
		throw new RuntimeException('The winning Bulk Confirm authority failed post-persistence verification.');
	}
	echo 'CONFIRMED ' . $label . ' ' . $result['batch_id'] . ' ' . $result['anchor_child_id'] . "\n";
} catch (WCOS_Bulk_Return_Confirmation_Exception $exception) {
	echo 'REJECTED ' . $label . ' ' . $exception->get_reason() . "\n";
} catch (Throwable $throwable) {
	fwrite(STDERR, 'BULK_CONFIRM_WORKER_FAILED ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n"); exit(3);
}
