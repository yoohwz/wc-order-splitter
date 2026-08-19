<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$label = isset($args[0]) ? sanitize_key((string) $args[0]) : '';
$fixture = get_option('wcos_strategy_confirm_race_fixture', array());
if ('' === $label || !is_array($fixture) || empty($fixture['order_id']) || empty($fixture['review_id']) || empty($fixture['review_token'])) {
	fwrite(STDERR, "RACE_WORKER_INVALID_INPUT\n");
	exit(2);
}

$reflection = new ReflectionClass('WCOS_Split_Strategy_Gates');
$states_property = $reflection->getProperty('states');
$states_property->setAccessible(true);
$states = $states_property->getValue();
$states[WCOS_Split_Strategy_Gates::CATEGORY] = true;
$states_property->setValue(null, $states);

wp_set_current_user(absint($fixture['user_id']));
$order_id = absint($fixture['order_id']);
$nonce = wp_create_nonce('wcos_split_strategy_order_' . $order_id);
$controller = new WCOS_Split_Strategy_Admin_Controller();

try {
	$result = $controller->confirm_request(array(
		'order_id' => $order_id,
		'nonce' => $nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
		'review_id' => sanitize_key((string) $fixture['review_id']),
		'review_token' => (string) $fixture['review_token'],
		'source_bucket_key' => sanitize_key((string) $fixture['source_bucket_key']),
	));
	echo 'CONFIRMED ' . $label . ' ' . sanitize_key((string) $result['operation_id']) . ' ' . (string) $result['confirmation_token'] . "\n";
	exit(0);
} catch (WCOS_Split_Transport_Exception $exception) {
	echo 'REJECTED ' . $label . ' ' . $exception->get_error_code() . "\n";
	exit(0);
} catch (Throwable $throwable) {
	fwrite(STDERR, 'RACE_WORKER_FAILED ' . $label . ' ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n");
	exit(3);
}
