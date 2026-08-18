<?php
/**
 * Pure-PHP contracts for request-bound quantity split operation IDs.
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/');

class WP_Error {
	private $code;
	private $message;

	public function __construct($code = '', $message = '') {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}
}

function is_wp_error($value) {
	return $value instanceof WP_Error;
}

function __($text, $domain = null) {
	return $text;
}

function absint($value) {
	return abs((int) $value);
}

function sanitize_key($value) {
	return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function wp_strip_all_tags($value) {
	return strip_tags((string) $value);
}

function wp_json_encode($value, $flags = 0) {
	return json_encode($value, $flags);
}

function wp_generate_uuid4() {
	return '123e4567-e89b-42d3-a456-426614174000';
}

final class WCOS_V2_Quantity_Split_Executor {
	public static $calls = array();

	public static function execute($order_id, array $requested_quantities, $operation_id) {
		self::$calls[] = array($order_id, $requested_quantities, $operation_id);

		return array('success' => true, 'operation_id' => $operation_id);
	}
}

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-quantity-split-service.php';

function wcos_v2_service_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$order_id = 12001;
$request_a = array(102 => '2.000000', 101 => 1);
$request_a_reordered = array(101 => '1.0', 102 => 2);
$request_b = array(101 => 2, 102 => 2);
$nonce = '123e4567-e89b-42d3-a456-426614174000';

$fingerprint_a = WCOS_V2_Quantity_Split_Service::request_fingerprint($order_id, $request_a);
$fingerprint_a_reordered = WCOS_V2_Quantity_Split_Service::request_fingerprint($order_id, $request_a_reordered);
wcos_v2_service_assert(!is_wp_error($fingerprint_a), 'A valid request fingerprint was rejected.');
wcos_v2_service_assert($fingerprint_a === $fingerprint_a_reordered, 'Equivalent quantities and item ordering must produce the same request fingerprint.');

$operation_id = WCOS_V2_Quantity_Split_Service::create_operation_id($order_id, $request_a, $nonce);
wcos_v2_service_assert(!is_wp_error($operation_id), 'A valid request-bound operation ID could not be created.');
wcos_v2_service_assert(
	'qsplit.' . $order_id . '.' . $fingerprint_a . '.' . $nonce === $operation_id,
	'The request-bound operation ID is incorrect.'
);

$validated = WCOS_V2_Quantity_Split_Service::validate_operation_id($order_id, $request_a_reordered, $operation_id);
wcos_v2_service_assert($operation_id === $validated, 'An equivalent request did not validate its operation ID.');

$payload_mismatch = WCOS_V2_Quantity_Split_Service::validate_operation_id($order_id, $request_b, $operation_id);
wcos_v2_service_assert(is_wp_error($payload_mismatch) && 'wcos_operation_payload_mismatch' === $payload_mismatch->get_error_code(), 'A changed request payload must be rejected before executor lookup.');

$order_mismatch = WCOS_V2_Quantity_Split_Service::validate_operation_id($order_id + 1, $request_a, $operation_id);
wcos_v2_service_assert(is_wp_error($order_mismatch) && 'wcos_operation_order_mismatch' === $order_mismatch->get_error_code(), 'An operation ID must not move to another source order.');

$executed = WCOS_V2_Quantity_Split_Service::execute($order_id, $request_a_reordered, $operation_id);
wcos_v2_service_assert(!is_wp_error($executed) && true === $executed['success'], 'A valid bound request did not reach the executor.');
wcos_v2_service_assert(1 === count(WCOS_V2_Quantity_Split_Executor::$calls), 'The executor call count is incorrect.');
wcos_v2_service_assert($operation_id === WCOS_V2_Quantity_Split_Executor::$calls[0][2], 'The normalized operation ID was not delegated.');

$rejected_execution = WCOS_V2_Quantity_Split_Service::execute($order_id, $request_b, $operation_id);
wcos_v2_service_assert(is_wp_error($rejected_execution), 'A changed request payload reached the executor.');
wcos_v2_service_assert(1 === count(WCOS_V2_Quantity_Split_Executor::$calls), 'A rejected request must not invoke the executor.');

$invalid_quantity = WCOS_V2_Quantity_Split_Service::request_fingerprint($order_id, array(101 => 0));
wcos_v2_service_assert(is_wp_error($invalid_quantity) && 'wcos_invalid_quantity_request' === $invalid_quantity->get_error_code(), 'A zero quantity must fail request fingerprinting.');

$invalid_nonce = WCOS_V2_Quantity_Split_Service::create_operation_id($order_id, $request_a, 'not-a-uuid');
wcos_v2_service_assert(is_wp_error($invalid_nonce) && 'wcos_invalid_operation_nonce' === $invalid_nonce->get_error_code(), 'An invalid nonce must be rejected.');

echo "WCOS v2 quantity-split service contracts passed.\n";
