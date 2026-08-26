<?php

if (!defined('ABSPATH')) { exit(1); }

$label = isset($args[0]) ? sanitize_key((string) $args[0]) : '';
$fixture = get_option('wcos_return_confirm_race_fixture', array());
if ('' === $label || empty($fixture['child_id']) || empty($fixture['review_id']) || empty($fixture['review_token'])) {
	fwrite(STDERR, "RETURN_RACE_WORKER_INVALID_INPUT\n"); exit(2);
}
wp_set_current_user(absint($fixture['user_id']));
$controller = new WCOS_Return_Admin_Controller();
try {
	$result = $controller->confirm_request(array(
		'child_order_id' => absint($fixture['child_id']),
		'nonce' => wp_create_nonce('wcos_return_order_' . absint($fixture['child_id'])),
		'review_id' => sanitize_key((string) $fixture['review_id']),
		'review_token' => (string) $fixture['review_token'],
	));
	echo 'CONFIRMED ' . $label . ' ' . sanitize_key((string) $result['operation_id']) . ' ' . (string) $result['confirmation_token'] . "\n";
	exit(0);
} catch (WCOS_Return_Transport_Exception $exception) {
	echo 'REJECTED ' . $label . ' ' . $exception->get_error_code() . "\n";
	exit(0);
} catch (Throwable $throwable) {
	fwrite(STDERR, 'RETURN_RACE_WORKER_FAILED ' . $label . ' ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n");
	exit(3);
}
