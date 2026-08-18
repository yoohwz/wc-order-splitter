<?php
/**
 * Pure-PHP contracts for durable mutation recovery contexts.
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

$GLOBALS['wcos_v2_recovery_options'] = array();

function add_option($key, $value, $deprecated = '', $autoload = 'yes') {
	if (array_key_exists($key, $GLOBALS['wcos_v2_recovery_options'])) {
		return false;
	}

	$GLOBALS['wcos_v2_recovery_options'][$key] = $value;

	return true;
}

function get_option($key, $default = false) {
	return array_key_exists($key, $GLOBALS['wcos_v2_recovery_options'])
		? $GLOBALS['wcos_v2_recovery_options'][$key]
		: $default;
}

function delete_option($key) {
	if (!array_key_exists($key, $GLOBALS['wcos_v2_recovery_options'])) {
		return false;
	}

	unset($GLOBALS['wcos_v2_recovery_options'][$key]);

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

	public function delete_meta_data($key) {
		unset($this->meta[$key]);
	}

	public function save_meta_data() {
		++$this->save_meta_count;
	}
}

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-lease-lock.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-recovery-context.php';

function wcos_v2_recovery_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$order         = new WC_Order(8101);
$operation_id  = 'op:split:8101:a';
$lease_id      = 'lease:8101:a';
$specification = array(
	'fingerprint'     => hash('sha256', 'specification-a'),
	'operation_type'  => 'quantity_split_one_child',
	'source_order_id' => 8101,
);
$snapshot = array(
	'order_id' => 8101,
	'status'   => 'processing',
	'lines'    => array(101 => array('quantity' => '3')),
);

wcos_v2_recovery_assert(is_array(WCOS_V2_Lease_Lock::acquire($order->get_id(), $operation_id, $lease_id, 120)), 'The recovery test could not acquire its lease.');

$prepared = WCOS_V2_Recovery_Context::prepare($order, $operation_id, $lease_id, $snapshot, $specification);
wcos_v2_recovery_assert(!is_wp_error($prepared), 'A valid recovery context could not be prepared.');
wcos_v2_recovery_assert('prepared' === $prepared['phase'], 'A new recovery context must start prepared.');
wcos_v2_recovery_assert(array() === $prepared['target_ids'], 'A prepared context cannot have target orders.');
wcos_v2_recovery_assert(1 === $order->save_meta_count, 'Preparing a context must persist exactly once.');

$resumed = WCOS_V2_Recovery_Context::prepare($order, $operation_id, $lease_id, $snapshot, $specification);
wcos_v2_recovery_assert(!is_wp_error($resumed) && $prepared === $resumed, 'An identical prepare request must resume idempotently.');
wcos_v2_recovery_assert(1 === $order->save_meta_count, 'Idempotent recovery resume must not write again.');

$conflicting_spec = $specification;
$conflicting_spec['fingerprint'] = hash('sha256', 'different-specification');
$conflict = WCOS_V2_Recovery_Context::prepare($order, $operation_id, $lease_id, $snapshot, $conflicting_spec);
wcos_v2_recovery_assert(is_wp_error($conflict) && 'wcos_recovery_context_conflict' === $conflict->get_error_code(), 'A different specification must conflict with the existing operation context.');

$wrong_lease = WCOS_V2_Recovery_Context::add_target($order, $operation_id, 'lease:wrong', 9101);
wcos_v2_recovery_assert(is_wp_error($wrong_lease) && 'wcos_recovery_lease_mismatch' === $wrong_lease->get_error_code(), 'A foreign lease must not mutate recovery data.');

$target_created = WCOS_V2_Recovery_Context::add_target($order, $operation_id, $lease_id, 9101);
wcos_v2_recovery_assert(!is_wp_error($target_created), 'The exact lease owner could not record a target.');
wcos_v2_recovery_assert('target_created' === $target_created['phase'], 'Recording a target must advance the context phase.');
wcos_v2_recovery_assert(array(9101) === $target_created['target_ids'], 'The recovery target was not recorded.');

$duplicate_target = WCOS_V2_Recovery_Context::add_target($order, $operation_id, $lease_id, 9101);
wcos_v2_recovery_assert(!is_wp_error($duplicate_target) && array(9101) === $duplicate_target['target_ids'], 'Recording the same target twice must remain idempotent.');

$invalid_advance = WCOS_V2_Recovery_Context::advance($order, $operation_id, $lease_id, 'verified');
wcos_v2_recovery_assert(is_wp_error($invalid_advance) && 'wcos_recovery_phase_conflict' === $invalid_advance->get_error_code(), 'The context must not skip source_mutated phase.');

$source_mutated = WCOS_V2_Recovery_Context::advance($order, $operation_id, $lease_id, 'source_mutated');
wcos_v2_recovery_assert(!is_wp_error($source_mutated) && 'source_mutated' === $source_mutated['phase'], 'The context did not enter source_mutated phase.');

$verified = WCOS_V2_Recovery_Context::advance($order, $operation_id, $lease_id, 'verified');
wcos_v2_recovery_assert(!is_wp_error($verified) && 'verified' === $verified['phase'], 'The context did not enter verified phase.');

$terminal_advance = WCOS_V2_Recovery_Context::advance($order, $operation_id, $lease_id, 'source_mutated');
wcos_v2_recovery_assert(is_wp_error($terminal_advance) && 'wcos_recovery_phase_conflict' === $terminal_advance->get_error_code(), 'A verified context must be terminal.');

$found = WCOS_V2_Recovery_Context::find($order, $operation_id);
wcos_v2_recovery_assert(!is_wp_error($found) && 'verified' === $found['phase'], 'Read-only recovery lookup failed.');
wcos_v2_recovery_assert($snapshot === $found['source_snapshot'], 'The durable source snapshot changed.');
wcos_v2_recovery_assert($specification === $found['write_specification'], 'The durable write specification changed.');

$wrong_remove = WCOS_V2_Recovery_Context::remove($order, $operation_id, 'lease:wrong');
wcos_v2_recovery_assert(is_wp_error($wrong_remove) && 'wcos_recovery_lease_mismatch' === $wrong_remove->get_error_code(), 'A foreign lease must not remove recovery data.');

$removed = WCOS_V2_Recovery_Context::remove($order, $operation_id, $lease_id);
wcos_v2_recovery_assert(true === $removed, 'The exact lease owner could not remove recovery data.');
wcos_v2_recovery_assert(null === WCOS_V2_Recovery_Context::find($order, $operation_id), 'The recovery context still exists after removal.');
wcos_v2_recovery_assert(true === WCOS_V2_Lease_Lock::release($order->get_id(), $operation_id, $lease_id), 'The recovery test could not release its lease.');

$no_lease = WCOS_V2_Recovery_Context::prepare($order, 'op:no-lease', 'lease:none', $snapshot, $specification);
wcos_v2_recovery_assert(is_wp_error($no_lease) && 'wcos_recovery_lease_missing' === $no_lease->get_error_code(), 'Preparing recovery data without a lease must fail closed.');

echo "WCOS v2 recovery-context contract tests passed.\n";
