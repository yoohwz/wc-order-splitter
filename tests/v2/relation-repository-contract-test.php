<?php
/**
 * Pure-PHP contracts for reciprocal split-order relations.
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

$GLOBALS['wcos_v2_relation_options'] = array();

function add_option($key, $value, $deprecated = '', $autoload = 'yes') {
	if (array_key_exists($key, $GLOBALS['wcos_v2_relation_options'])) {
		return false;
	}

	$GLOBALS['wcos_v2_relation_options'][$key] = $value;

	return true;
}

function get_option($key, $default = false) {
	return array_key_exists($key, $GLOBALS['wcos_v2_relation_options'])
		? $GLOBALS['wcos_v2_relation_options'][$key]
		: $default;
}

function delete_option($key) {
	if (!array_key_exists($key, $GLOBALS['wcos_v2_relation_options'])) {
		return false;
	}

	unset($GLOBALS['wcos_v2_relation_options'][$key]);

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
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-relation-repository.php';

function wcos_v2_relation_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$source       = new WC_Order(10001);
$child        = new WC_Order(10002);
$operation_id = 'op:split:10001:a';
$lease_id     = 'lease:10001:a';

wcos_v2_relation_assert(is_array(WCOS_V2_Lease_Lock::acquire($source->get_id(), $operation_id, $lease_id, 120)), 'The relation test could not acquire its source lease.');

$staged = WCOS_V2_Relation_Repository::stage($source, $child, $operation_id, $lease_id, 'quantity_split');
wcos_v2_relation_assert(!is_wp_error($staged), 'A valid relation could not be staged.');
wcos_v2_relation_assert('staged' === $staged['status'], 'A new relation must start staged.');
wcos_v2_relation_assert($source->get_id() === $staged['source_order_id'], 'The staged source ID is incorrect.');
wcos_v2_relation_assert($child->get_id() === $staged['child_order_id'], 'The staged child ID is incorrect.');
wcos_v2_relation_assert('' === $source->get_meta('yoos_splitted_order', true), 'Legacy relation metadata must not be published while staged.');
wcos_v2_relation_assert('' === $child->get_meta('yoos_original_order', true), 'Legacy parent metadata must not be published while staged.');

$staged_retry = WCOS_V2_Relation_Repository::stage($source, $child, $operation_id, $lease_id, 'quantity_split');
wcos_v2_relation_assert(!is_wp_error($staged_retry) && $staged === $staged_retry, 'An identical stage request must be idempotent.');

$foreign_child = new WC_Order(10003);
$conflict = WCOS_V2_Relation_Repository::stage($source, $foreign_child, $operation_id, $lease_id, 'quantity_split');
wcos_v2_relation_assert(is_wp_error($conflict) && 'wcos_relation_conflict' === $conflict->get_error_code(), 'An operation ID must not be rebound to another child.');

$wrong_lease = WCOS_V2_Relation_Repository::commit($source, $child, $operation_id, 'lease:wrong');
wcos_v2_relation_assert(is_wp_error($wrong_lease) && 'wcos_relation_lease_mismatch' === $wrong_lease->get_error_code(), 'A foreign lease must not commit a relation.');

$committed = WCOS_V2_Relation_Repository::commit($source, $child, $operation_id, $lease_id);
wcos_v2_relation_assert(!is_wp_error($committed), 'The exact lease owner could not commit the relation.');
wcos_v2_relation_assert('committed' === $committed['status'], 'The relation did not enter committed state.');
wcos_v2_relation_assert('10002' === $source->get_meta('yoos_splitted_order', true), 'Legacy child compatibility metadata is incorrect.');
wcos_v2_relation_assert(10001 === $child->get_meta('yoos_original_order', true), 'Legacy parent compatibility metadata is incorrect.');

$commit_retry = WCOS_V2_Relation_Repository::commit($source, $child, $operation_id, $lease_id);
wcos_v2_relation_assert(!is_wp_error($commit_retry) && $committed === $commit_retry, 'An identical relation commit must be idempotent.');
wcos_v2_relation_assert('10002' === $source->get_meta('yoos_splitted_order', true), 'An idempotent relation commit duplicated the legacy child ID.');

$found = WCOS_V2_Relation_Repository::find($source, $operation_id);
wcos_v2_relation_assert(!is_wp_error($found) && 'committed' === $found['status'], 'Read-only structured relation lookup failed.');

$wrong_unlink = WCOS_V2_Relation_Repository::unlink($source, $child, $operation_id, 'lease:wrong');
wcos_v2_relation_assert(is_wp_error($wrong_unlink) && 'wcos_relation_lease_mismatch' === $wrong_unlink->get_error_code(), 'A foreign lease must not unlink a relation.');

$unlinked = WCOS_V2_Relation_Repository::unlink($source, $child, $operation_id, $lease_id);
wcos_v2_relation_assert(true === $unlinked, 'The exact lease owner could not unlink the relation.');
wcos_v2_relation_assert(null === WCOS_V2_Relation_Repository::find($source, $operation_id), 'The structured relation still exists after unlink.');
wcos_v2_relation_assert('' === $source->get_meta('yoos_splitted_order', true), 'Legacy child metadata still exists after unlink.');
wcos_v2_relation_assert('' === $child->get_meta('yoos_original_order', true), 'Legacy parent metadata still exists after unlink.');
wcos_v2_relation_assert(true === WCOS_V2_Lease_Lock::release($source->get_id(), $operation_id, $lease_id), 'The relation test could not release its lease.');

$self_relation = new WC_Order(11001);
$self_operation = 'op:self';
$self_lease = 'lease:self';
wcos_v2_relation_assert(is_array(WCOS_V2_Lease_Lock::acquire($self_relation->get_id(), $self_operation, $self_lease, 120)), 'The self-relation test could not acquire a lease.');
$self_error = WCOS_V2_Relation_Repository::stage($self_relation, $self_relation, $self_operation, $self_lease, 'quantity_split');
wcos_v2_relation_assert(is_wp_error($self_error) && 'wcos_invalid_order_relation' === $self_error->get_error_code(), 'An order must never be related to itself.');
wcos_v2_relation_assert(true === WCOS_V2_Lease_Lock::release($self_relation->get_id(), $self_operation, $self_lease), 'The self-relation test could not release its lease.');

echo "WCOS v2 relation-repository contract tests passed.\n";
