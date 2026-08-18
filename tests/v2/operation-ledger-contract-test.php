<?php
/**
 * Pure-PHP contracts for the lease-guarded operation ledger.
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

$GLOBALS['wcos_v2_ledger_options'] = array();

function add_option($key, $value, $deprecated = '', $autoload = 'yes') {
	if (array_key_exists($key, $GLOBALS['wcos_v2_ledger_options'])) {
		return false;
	}

	$GLOBALS['wcos_v2_ledger_options'][$key] = $value;

	return true;
}

function get_option($key, $default = false) {
	return array_key_exists($key, $GLOBALS['wcos_v2_ledger_options'])
		? $GLOBALS['wcos_v2_ledger_options'][$key]
		: $default;
}

function delete_option($key) {
	if (!array_key_exists($key, $GLOBALS['wcos_v2_ledger_options'])) {
		return false;
	}

	unset($GLOBALS['wcos_v2_ledger_options'][$key]);

	return true;
}

class WC_Order {
	private $id;
	private $meta = array();
	public $save_meta_count = 0;

	public function __construct($id) {
		$this->id = (int) $id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta($key, $single = true) {
		return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
	}

	public function update_meta_data($key, $value) {
		$this->meta[$key] = $value;
	}

	public function save_meta_data() {
		++$this->save_meta_count;
	}
}

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-lease-lock.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-operation-record.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-operation-ledger.php';

function wcos_v2_ledger_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$order         = new WC_Order(7001);
$operation_id  = 'op:split:7001:a';
$lease_id      = 'lease:request:a';
$fingerprint   = hash('sha256', 'complete-order-state-a');
$lease         = WCOS_V2_Lease_Lock::acquire($order->get_id(), $operation_id, $lease_id, 120);

wcos_v2_ledger_assert(is_array($lease), 'The test request could not acquire its lease.');

$record = WCOS_V2_Operation_Ledger::begin($order, $operation_id, $fingerprint, 'quantity_split', $lease_id);
wcos_v2_ledger_assert(!is_wp_error($record), 'A valid operation could not begin.');
wcos_v2_ledger_assert('preparing' === $record['status'], 'The ledger did not persist preparing state.');
wcos_v2_ledger_assert(1 === $order->save_meta_count, 'Beginning an operation must persist once.');

$found = WCOS_V2_Operation_Ledger::find($order, $operation_id);
wcos_v2_ledger_assert(!is_wp_error($found) && 'preparing' === $found['status'], 'Read-only lookup did not return the preparing record.');
wcos_v2_ledger_assert(1 === $order->save_meta_count, 'Read-only lookup must not write order metadata.');

$resumed = WCOS_V2_Operation_Ledger::begin($order, $operation_id, $fingerprint, 'quantity_split', $lease_id);
wcos_v2_ledger_assert(!is_wp_error($resumed) && $record === $resumed, 'An identical begin request must resume idempotently.');
wcos_v2_ledger_assert(1 === $order->save_meta_count, 'Idempotent resume must not create another write.');

$conflict = WCOS_V2_Operation_Ledger::begin($order, $operation_id, hash('sha256', 'changed-state'), 'quantity_split', $lease_id);
wcos_v2_ledger_assert(is_wp_error($conflict) && 'wcos_idempotency_conflict' === $conflict->get_error_code(), 'A changed fingerprint must fail with an idempotency conflict.');

$wrong_lease = WCOS_V2_Operation_Ledger::commit($order, $operation_id, 'lease:wrong', array(8001));
wcos_v2_ledger_assert(is_wp_error($wrong_lease) && 'wcos_operation_lease_mismatch' === $wrong_lease->get_error_code(), 'A foreign lease must not commit the operation.');

$committed = WCOS_V2_Operation_Ledger::commit($order, $operation_id, $lease_id, array(8002, 8001, 8002));
wcos_v2_ledger_assert(!is_wp_error($committed), 'The exact lease owner could not commit.');
wcos_v2_ledger_assert('committed' === $committed['status'], 'The operation did not enter committed state.');
wcos_v2_ledger_assert(array(8001, 8002) === $committed['target_ids'], 'Committed target IDs were not normalized.');

$committed_retry = WCOS_V2_Operation_Ledger::commit($order, $operation_id, $lease_id, array(8002, 8001));
wcos_v2_ledger_assert(!is_wp_error($committed_retry) && $committed === $committed_retry, 'An identical commit retry must be idempotent.');

$late_failure = WCOS_V2_Operation_Ledger::fail($order, $operation_id, $lease_id, 'late_failure');
wcos_v2_ledger_assert(is_wp_error($late_failure) && 'wcos_terminal_operation_conflict' === $late_failure->get_error_code(), 'A committed record must not transition to failed.');

wcos_v2_ledger_assert(true === WCOS_V2_Lease_Lock::release($order->get_id(), $operation_id, $lease_id), 'The exact operation lease could not be released.');

$committed_without_lease = WCOS_V2_Operation_Ledger::find($order, $operation_id);
wcos_v2_ledger_assert(!is_wp_error($committed_without_lease) && 'committed' === $committed_without_lease['status'], 'Committed lookup must remain available without an execution lease.');

$missing_lease = WCOS_V2_Operation_Ledger::begin($order, 'op:split:7001:b', hash('sha256', 'state-b'), 'quantity_split', 'lease:missing');
wcos_v2_ledger_assert(is_wp_error($missing_lease) && 'wcos_operation_lease_missing' === $missing_lease->get_error_code(), 'A ledger write without a lease must fail closed.');

$failed_operation = 'op:split:7001:c';
$failed_lease     = 'lease:request:c';
wcos_v2_ledger_assert(is_array(WCOS_V2_Lease_Lock::acquire($order->get_id(), $failed_operation, $failed_lease, 120)), 'The failed-operation test could not acquire a lease.');
$failed_record = WCOS_V2_Operation_Ledger::begin($order, $failed_operation, hash('sha256', 'state-c'), 'quantity_split', $failed_lease);
wcos_v2_ledger_assert(!is_wp_error($failed_record), 'The failed-operation test could not begin.');
$failed_record = WCOS_V2_Operation_Ledger::fail($order, $failed_operation, $failed_lease, 'target_create_failed');
wcos_v2_ledger_assert(!is_wp_error($failed_record) && 'failed' === $failed_record['status'], 'The operation could not enter failed state.');
$failed_retry = WCOS_V2_Operation_Ledger::fail($order, $failed_operation, $failed_lease, 'target_create_failed');
wcos_v2_ledger_assert(!is_wp_error($failed_retry) && $failed_record === $failed_retry, 'An identical failure retry must be idempotent.');
$failed_commit = WCOS_V2_Operation_Ledger::commit($order, $failed_operation, $failed_lease, array(9001));
wcos_v2_ledger_assert(is_wp_error($failed_commit) && 'wcos_terminal_operation_conflict' === $failed_commit->get_error_code(), 'A failed record must not transition to committed.');
wcos_v2_ledger_assert(true === WCOS_V2_Lease_Lock::release($order->get_id(), $failed_operation, $failed_lease), 'The failed-operation lease could not be released.');

echo "WCOS v2 operation-ledger contract tests passed.\n";
