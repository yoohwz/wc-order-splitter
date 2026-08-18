<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_lease_binding_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$order = wc_create_order();
$order->set_status('pending');
$order->save();
$order_id = $order->get_id();

$operation_a = sanitize_key('lease-a-' . wp_generate_uuid4());
$operation_b = sanitize_key('lease-b-' . wp_generate_uuid4());
$token = WCOS_Operation_Lock::acquire($order_id, $operation_a, 60);
wcos_lease_binding_assert(false !== $token, 'Unable to acquire operation A lease.');
wcos_lease_binding_assert(WCOS_Operation_Lock::is_owned($order_id, $token, $operation_a), 'Operation A token is not owned by operation A.');
wcos_lease_binding_assert(!WCOS_Operation_Lock::is_owned($order_id, $token, $operation_b), 'Operation A token was accepted for operation B.');
wcos_lease_binding_assert(WCOS_Operation_Lock::is_current_owned_for($order_id, $operation_a), 'Request-local operation A ownership is missing.');
wcos_lease_binding_assert(!WCOS_Operation_Lock::is_current_owned_for($order_id, $operation_b), 'Request-local operation A lease was exposed as operation B.');
wcos_lease_binding_assert(!WCOS_Operation_Lock::refresh_current_for($order_id, $operation_b, 60), 'Operation B refreshed operation A lease.');
wcos_lease_binding_assert(WCOS_Operation_Lock::refresh_current_for($order_id, $operation_a, 60), 'Operation A could not refresh its own lease.');
wcos_lease_binding_assert(WCOS_Operation_Lock::is_current_owned_for($order_id, $operation_a), 'Operation A ownership was lost after valid refresh.');

$wrong_assertion_rejected = false;
try {
	WCOS_Operation_Lock::assert_current_owned_for($order_id, $operation_b);
} catch (RuntimeException $exception) {
	$wrong_assertion_rejected = true;
}
wcos_lease_binding_assert($wrong_assertion_rejected, 'Operation B ownership assertion was not rejected.');
wcos_lease_binding_assert(WCOS_Operation_Lock::release($order_id, $token), 'Operation A lease could not be released by its exact token.');

$order->delete(true);

echo "operation-specific-mutation-lease-binding-ok\n";
