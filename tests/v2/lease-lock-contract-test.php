<?php
/**
 * Pure-PHP contract tests for the v2 mutation lease lock.
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/');

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct($code = '', $message = '', $data = array()) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
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

$GLOBALS['wcos_v2_lock_options'] = array();

function add_option($key, $value, $deprecated = '', $autoload = 'yes') {
	if (array_key_exists($key, $GLOBALS['wcos_v2_lock_options'])) {
		return false;
	}

	$GLOBALS['wcos_v2_lock_options'][$key] = $value;

	return true;
}

function get_option($key, $default = false) {
	return array_key_exists($key, $GLOBALS['wcos_v2_lock_options'])
		? $GLOBALS['wcos_v2_lock_options'][$key]
		: $default;
}

function delete_option($key) {
	if (!array_key_exists($key, $GLOBALS['wcos_v2_lock_options'])) {
		return false;
	}

	unset($GLOBALS['wcos_v2_lock_options'][$key]);

	return true;
}

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-lease-lock.php';

/**
 * Assert a condition.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure context.
 * @return void
 */
function wcos_v2_lease_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$order_id     = 4101;
$operation_id = 'op:4101:split:abc';
$lease_one    = 'lease:first-request';
$lease_two    = 'lease:second-request';

$first = WCOS_V2_Lease_Lock::acquire($order_id, $operation_id, $lease_one, 120);
wcos_v2_lease_assert(is_array($first), 'The first request must acquire the lease.');
wcos_v2_lease_assert($lease_one === $first['lease_id'], 'The acquired lease ID is incorrect.');

$same_operation_retry = WCOS_V2_Lease_Lock::acquire($order_id, $operation_id, $lease_two, 120);
wcos_v2_lease_assert(is_wp_error($same_operation_retry), 'A concurrent request with the same operation ID must be blocked.');
wcos_v2_lease_assert('wcos_order_mutation_locked' === $same_operation_retry->get_error_code(), 'The same-operation lock error code is incorrect.');

$different_operation = WCOS_V2_Lease_Lock::acquire($order_id, 'op:different', 'lease:different', 120);
wcos_v2_lease_assert(is_wp_error($different_operation), 'A different operation against the same order must be blocked.');
wcos_v2_lease_assert('wcos_order_mutation_locked' === $different_operation->get_error_code(), 'The different-operation lock error code is incorrect.');

wcos_v2_lease_assert(false === WCOS_V2_Lease_Lock::release($order_id, $operation_id, 'lease:wrong'), 'A wrong lease ID must not release the lock.');
wcos_v2_lease_assert(false === WCOS_V2_Lease_Lock::release($order_id, 'op:wrong', $lease_one), 'A wrong operation ID must not release the lock.');
wcos_v2_lease_assert(null !== WCOS_V2_Lease_Lock::inspect($order_id), 'The protected lease disappeared after a rejected release.');

wcos_v2_lease_assert(true === WCOS_V2_Lease_Lock::release($order_id, $operation_id, $lease_one), 'The exact lease owner must be able to release.');
wcos_v2_lease_assert(null === WCOS_V2_Lease_Lock::inspect($order_id), 'The exact release did not remove the lease.');

$reacquired = WCOS_V2_Lease_Lock::acquire($order_id, 'op:next', 'lease:next', 120);
wcos_v2_lease_assert(is_array($reacquired), 'A new request must acquire after a valid release.');
wcos_v2_lease_assert(true === WCOS_V2_Lease_Lock::release($order_id, 'op:next', 'lease:next'), 'The second valid release failed.');

$stale_key = 'wcos_v2_order_lease_' . md5((string) $order_id);
$GLOBALS['wcos_v2_lock_options'][$stale_key] = array(
	'order_id'     => $order_id,
	'operation_id' => 'op:stale',
	'lease_id'     => 'lease:stale',
	'acquired_at'  => time() - 600,
	'expires_at'   => time() - 300,
);

$stale_attempt = WCOS_V2_Lease_Lock::acquire($order_id, 'op:replacement', 'lease:replacement', 120);
wcos_v2_lease_assert(is_wp_error($stale_attempt), 'Stale cleanup must not acquire in the same request.');
wcos_v2_lease_assert('wcos_stale_lease_cleared' === $stale_attempt->get_error_code(), 'The stale cleanup error code is incorrect.');
wcos_v2_lease_assert(null === WCOS_V2_Lease_Lock::inspect($order_id), 'The expired lease was not cleared.');

$after_stale_retry = WCOS_V2_Lease_Lock::acquire($order_id, 'op:replacement', 'lease:replacement', 120);
wcos_v2_lease_assert(is_array($after_stale_retry), 'A retry after stale cleanup must acquire.');
wcos_v2_lease_assert(true === WCOS_V2_Lease_Lock::release($order_id, 'op:replacement', 'lease:replacement'), 'The replacement lease could not be released.');

$invalid = WCOS_V2_Lease_Lock::acquire(0, '', '', 120);
wcos_v2_lease_assert(is_wp_error($invalid) && 'wcos_invalid_lease_identity' === $invalid->get_error_code(), 'Invalid lease identity must fail closed.');

echo "WCOS v2 lease-lock contract tests passed.\n";
