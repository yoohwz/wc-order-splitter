<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$order_id = isset($args[0]) ? absint($args[0]) : 0;
$operation_id = isset($args[1]) ? sanitize_key($args[1]) : '';
$hold_seconds = isset($args[2]) ? max(0, (int) $args[2]) : 0;

if (!$order_id || '' === $operation_id) {
	fwrite(STDERR, "INVALID_WORKER_INPUT\n");
	exit(2);
}

$token = WCOS_Operation_Lock::acquire($order_id, $operation_id, 30);
if (false === $token) {
	echo 'BLOCKED ' . $operation_id . "\n";
	exit(0);
}

echo 'ACQUIRED ' . $operation_id . "\n";
if (function_exists('flush')) {
	flush();
}

if ($hold_seconds > 0) {
	sleep($hold_seconds);
}

if (!WCOS_Operation_Lock::is_owned($order_id, $token)) {
	fwrite(STDERR, 'LOST ' . $operation_id . "\n");
	exit(3);
}
if (!WCOS_Operation_Lock::release($order_id, $token)) {
	fwrite(STDERR, 'RELEASE_FAILED ' . $operation_id . "\n");
	exit(4);
}

echo 'RELEASED ' . $operation_id . "\n";
