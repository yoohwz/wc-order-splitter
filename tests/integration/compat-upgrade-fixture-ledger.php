<?php

if (!defined('ABSPATH')) { exit(1); }

const WCOS_COMPAT_007_LEDGER_OPTION = 'wcos_compat_007_cleanup_ledger';
const WCOS_COMPAT_007_LEDGER_BASELINE_SHA = 'e1d8aeb8eff38f4ce69dad1a08993e17521c6359';
const WCOS_COMPAT_007_LEDGER_SCHEMA_VERSION = 2;

function wcos_compat_007_ledger_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_compat_007_ledger_option_state($name) {
	$missing = '__wcos_compat_007_ledger_missing_option__';
	$value = get_option($name, $missing);
	return array(
		'exists' => $missing !== $value,
		'value' => $missing !== $value ? $value : null,
	);
}

function wcos_compat_007_ledger_get($required = true) {
	$ledger = get_option(WCOS_COMPAT_007_LEDGER_OPTION, array());
	$valid = is_array($ledger)
		&& WCOS_COMPAT_007_LEDGER_SCHEMA_VERSION === (int) (isset($ledger['schema_version']) ? $ledger['schema_version'] : 0)
		&& WCOS_COMPAT_007_LEDGER_BASELINE_SHA === (string) (isset($ledger['baseline_sha']) ? $ledger['baseline_sha'] : '');
	if ($required) { wcos_compat_007_ledger_assert($valid, 'The authenticated WOS-COMPAT-007 cleanup ledger is unavailable.'); }
	return $valid ? $ledger : array();
}

function wcos_compat_007_ledger_initialize() {
	wcos_compat_007_ledger_assert(false === get_option(WCOS_COMPAT_007_LEDGER_OPTION, false), 'A prior WOS-COMPAT-007 cleanup ledger still exists.');
	foreach (array('wcos_compat_007_upgrade_fixture', 'wcos_compat_003_genuine_1_4_11_fixture') as $fixture_option) {
		wcos_compat_007_ledger_assert(false === get_option($fixture_option, false), 'A prior compatibility fixture still exists: ' . $fixture_option);
	}
	$option_names = array(
		'order_splitter_status_allowed',
		'order_splitter_exclude_shipping_fee',
		'order_splitter_shop_manager_permission',
		'order_splitter_order_label',
		'order_splitter_disable_split_order_email',
	);
	$options_before = array();
	foreach ($option_names as $option_name) { $options_before[$option_name] = wcos_compat_007_ledger_option_state($option_name); }
	$ledger = array(
		'schema_version' => WCOS_COMPAT_007_LEDGER_SCHEMA_VERSION,
		'baseline_sha' => WCOS_COMPAT_007_LEDGER_BASELINE_SHA,
		'options_before' => $options_before,
		'order_ids' => array(),
		'product_ids' => array(),
		'user_ids' => array(),
		'term_ids' => array(),
		'authorities' => array(),
	);
	wcos_compat_007_ledger_assert(add_option(WCOS_COMPAT_007_LEDGER_OPTION, $ledger, '', false), 'Unable to initialize the WOS-COMPAT-007 cleanup ledger.');
	wcos_compat_007_ledger_get(true);
}

function wcos_compat_007_ledger_authority_prefixes() {
	return array(
		'split' => 'wcos_split_confirm_',
		'strategy' => 'wcos_split_strategy_confirm_',
		'duplicate' => 'wcos_duplicate_confirm_',
		'merge-review' => 'wcos_merge_review_',
		'merge' => 'wcos_merge_confirm_',
		'return-review' => 'wcos_return_review_',
		'return' => 'wcos_return_confirm_',
		'bulk-review' => 'wcos_bulk_return_review_',
	);
}

function wcos_compat_007_ledger_authority_record($type, $authority_id, $order_id = 0) {
	$prefixes = wcos_compat_007_ledger_authority_prefixes();
	$type = sanitize_key((string) $type);
	$authority_id = sanitize_key((string) $authority_id);
	wcos_compat_007_ledger_assert(isset($prefixes[$type]), 'Unsupported WOS-COMPAT-007 authority type.');
	wcos_compat_007_ledger_assert(1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $authority_id), 'Refusing to record an invalid WOS-COMPAT-007 authority ID.');
	$order_id = absint($order_id);
	$is_review = in_array($type, array('merge-review', 'return-review', 'bulk-review'), true);
	if (!$is_review) { wcos_compat_007_ledger_assert($order_id > 0, 'A WOS-COMPAT-007 operation authority requires its source order ID.'); }
	return array(
		'type' => $type,
		'authority_id' => $authority_id,
		'order_id' => $order_id,
		'transient_key' => $prefixes[$type] . hash('sha256', $authority_id),
		'journal_option' => $is_review ? '' : 'wcos_mutation_op_' . hash('sha256', $order_id . '|' . $authority_id),
	);
}

function wcos_compat_007_ledger_remember_authority($type, $authority_id, $order_id = 0) {
	$record = wcos_compat_007_ledger_authority_record($type, $authority_id, $order_id);
	$ledger = wcos_compat_007_ledger_get(true);
	$authorities = isset($ledger['authorities']) && is_array($ledger['authorities']) ? array_values($ledger['authorities']) : array();
	$exists = false;
	foreach ($authorities as $authority) {
		if (is_array($authority) && isset($authority['transient_key']) && hash_equals((string) $record['transient_key'], (string) $authority['transient_key'])) {
			$exists = true;
			break;
		}
	}
	if (!$exists) {
		$authorities[] = $record;
		$ledger['authorities'] = $authorities;
		update_option(WCOS_COMPAT_007_LEDGER_OPTION, $ledger, false);
	}
	$persisted = wcos_compat_007_ledger_get(true);
	$persisted_keys = array();
	foreach (isset($persisted['authorities']) && is_array($persisted['authorities']) ? $persisted['authorities'] : array() as $authority) {
		if (is_array($authority) && isset($authority['transient_key'])) { $persisted_keys[] = (string) $authority['transient_key']; }
	}
	wcos_compat_007_ledger_assert(in_array($record['transient_key'], $persisted_keys, true), 'The WOS-COMPAT-007 cleanup ledger did not persist an authority.');
}

function wcos_compat_007_ledger_authorities(array $ledger) {
	$records = array();
	foreach (isset($ledger['authorities']) && is_array($ledger['authorities']) ? $ledger['authorities'] : array() as $authority) {
		wcos_compat_007_ledger_assert(is_array($authority), 'The WOS-COMPAT-007 authority ledger is malformed.');
		$record = wcos_compat_007_ledger_authority_record(
			isset($authority['type']) ? $authority['type'] : '',
			isset($authority['authority_id']) ? $authority['authority_id'] : '',
			isset($authority['order_id']) ? $authority['order_id'] : 0
		);
		wcos_compat_007_ledger_assert(isset($authority['transient_key']) && hash_equals($record['transient_key'], (string) $authority['transient_key']), 'The WOS-COMPAT-007 authority key is not authentic.');
		wcos_compat_007_ledger_assert(isset($authority['journal_option']) && hash_equals($record['journal_option'], (string) $authority['journal_option']), 'The WOS-COMPAT-007 journal key is not authentic.');
		$records[] = $record;
	}
	return $records;
}

function wcos_compat_007_ledger_delete_authorities(array $ledger) {
	foreach (wcos_compat_007_ledger_authorities($ledger) as $authority) {
		delete_transient($authority['transient_key']);
		if ('' !== $authority['journal_option']) { delete_option($authority['journal_option']); }
	}
}

function wcos_compat_007_ledger_journal_order_ids($value, $key = '') {
	$related_keys = array(
		'target_order_id',
		'target_order_ids',
		'compensation_target_order_ids',
		'remaining_compensation_target_order_ids',
	);
	if (in_array((string) $key, $related_keys, true)) {
		return array_values(array_filter(array_map('absint', is_array($value) ? $value : array($value))));
	}
	$ids = array();
	if (is_array($value)) {
		foreach ($value as $child_key => $child_value) {
			$ids = array_merge($ids, wcos_compat_007_ledger_journal_order_ids($child_value, is_string($child_key) ? $child_key : ''));
		}
	}
	return array_values(array_unique($ids));
}

function wcos_compat_007_ledger_all_orders() {
	return array_merge(
		wc_get_orders(array('limit' => -1, 'return' => 'objects')),
		wc_get_orders(array('limit' => -1, 'return' => 'objects', 'type' => 'shop_order_refund'))
	);
}

function wcos_compat_007_ledger_related_order_ids(array $order_ids, array $ledger = array()) {
	foreach (wcos_compat_007_ledger_authorities($ledger) as $authority) {
		if ('' === $authority['journal_option']) { continue; }
		$journal = get_option($authority['journal_option'], array());
		if (is_array($journal)) { $order_ids = array_merge($order_ids, wcos_compat_007_ledger_journal_order_ids($journal)); }
	}
	$resolved = array();
	foreach (array_values(array_unique(array_filter(array_map('absint', $order_ids)))) as $order_id) { $resolved[$order_id] = $order_id; }
	$orders = wcos_compat_007_ledger_all_orders();
	$changed = true;
	while ($changed) {
		$changed = false;
		foreach ($orders as $order) {
			if (!$order instanceof WC_Order) { continue; }
			$order_id = absint($order->get_id());
			if (isset($resolved[$order_id])) {
				$related = array_map('absint', explode(',', (string) $order->get_meta('yoos_splitted_order', true)));
				$related = array_merge($related, array_map('absint', (array) $order->get_meta('_wcos_child_order_ids', true)));
				foreach ($order->get_refunds() as $refund) { $related[] = absint($refund->get_id()); }
				$related = array_merge($related, wcos_compat_007_ledger_journal_order_ids($order->get_meta('_wcos_operation_journal', true)));
				foreach (array_filter($related) as $related_id) {
					if (!isset($resolved[$related_id])) { $resolved[$related_id] = $related_id; $changed = true; }
				}
				continue;
			}
			$parent_ids = array(
				absint($order->get_meta('yoos_original_order', true)),
				absint($order->get_meta('_wcos_parent_order_id', true)),
				absint($order->get_meta('_wcos_duplicate_source_order', true)),
			);
			if (!empty(array_intersect(array_filter($parent_ids), array_keys($resolved)))) {
				$resolved[$order_id] = $order_id;
				$changed = true;
			}
		}
	}
	return array_values($resolved);
}

function wcos_compat_007_ledger_assert_authorities_absent(array $ledger) {
	$operation_ids = array();
	foreach (wcos_compat_007_ledger_authorities($ledger) as $authority) {
		wcos_compat_007_ledger_assert(false === get_transient($authority['transient_key']), 'A recorded WOS-COMPAT-007 transient authority survived cleanup.');
		wcos_compat_007_ledger_assert(false === get_option('_transient_' . $authority['transient_key'], false), 'A recorded WOS-COMPAT-007 transient option survived cleanup.');
		wcos_compat_007_ledger_assert(false === get_option('_transient_timeout_' . $authority['transient_key'], false), 'A recorded WOS-COMPAT-007 transient timeout survived cleanup.');
		wcos_compat_007_ledger_assert('' === $authority['journal_option'] || false === get_option($authority['journal_option'], false), 'A recorded WOS-COMPAT-007 journal option survived cleanup.');
		if (!in_array($authority['type'], array('merge-review', 'return-review', 'bulk-review'), true)) { $operation_ids[] = $authority['authority_id']; }
	}
	$operation_ids = array_values(array_unique($operation_ids));
	foreach (wcos_compat_007_ledger_all_orders() as $order) {
		if (!$order instanceof WC_Order) { continue; }
		foreach ((array) $order->get_meta('_wcos_operation_journal', true) as $entry) {
			$operation_id = is_array($entry) && isset($entry['operation_id']) ? sanitize_key((string) $entry['operation_id']) : '';
			wcos_compat_007_ledger_assert('' === $operation_id || !in_array($operation_id, $operation_ids, true), 'A recorded WOS-COMPAT-007 journal survived cleanup.');
		}
	}
}

function wcos_compat_007_ledger_remember($type, $object_id) {
	$fields = array('order' => 'order_ids', 'product' => 'product_ids', 'user' => 'user_ids', 'term' => 'term_ids');
	wcos_compat_007_ledger_assert(isset($fields[$type]), 'Unsupported WOS-COMPAT-007 cleanup-ledger object type.');
	$object_id = absint($object_id);
	wcos_compat_007_ledger_assert($object_id > 0, 'Refusing to record an invalid WOS-COMPAT-007 fixture object ID.');
	$ledger = wcos_compat_007_ledger_get(true);
	$field = $fields[$type];
	$ids = isset($ledger[$field]) ? array_values(array_unique(array_filter(array_map('absint', (array) $ledger[$field])))) : array();
	if (!in_array($object_id, $ids, true)) {
		$ids[] = $object_id;
		$ledger[$field] = $ids;
		update_option(WCOS_COMPAT_007_LEDGER_OPTION, $ledger, false);
	}
	$persisted = wcos_compat_007_ledger_get(true);
	wcos_compat_007_ledger_assert(in_array($object_id, array_map('absint', (array) $persisted[$field]), true), 'The WOS-COMPAT-007 cleanup ledger did not persist an object ID.');
}

function wcos_compat_007_ledger_restore_options(array $ledger) {
	foreach (isset($ledger['options_before']) && is_array($ledger['options_before']) ? $ledger['options_before'] : array() as $name => $state) {
		if (!is_array($state)) { continue; }
		if (!empty($state['exists'])) { update_option($name, array_key_exists('value', $state) ? $state['value'] : null); }
		else { delete_option($name); }
		wcos_compat_007_ledger_assert($state === wcos_compat_007_ledger_option_state($name), 'A pre-fixture option was not restored exactly: ' . $name);
	}
}

function wcos_compat_007_ledger_assert_clean() {
	foreach (array(WCOS_COMPAT_007_LEDGER_OPTION, 'wcos_compat_007_upgrade_fixture', 'wcos_compat_003_genuine_1_4_11_fixture') as $option_name) {
		wcos_compat_007_ledger_assert(false === get_option($option_name, false), 'Task-owned fixture option survived cleanup: ' . $option_name);
	}

	foreach (wcos_compat_007_ledger_all_orders() as $order) {
		if (!$order instanceof WC_Order) { continue; }
		$task_owned = '' !== (string) $order->get_meta('_wcos_compat_007_fixture', true)
			|| 0 === strpos((string) $order->get_billing_email(), 'wos-compat-007-')
			|| ($order instanceof WC_Order_Refund && 0 === strpos((string) $order->get_reason(), 'WOS COMPAT 007 '));
		foreach ($order->get_items('line_item') as $item) {
			$name = (string) $item->get_name();
			$task_owned = $task_owned || 0 === strpos($name, 'WOS COMPAT 007 ') || 0 === strpos($name, 'Exact 1.4.11 ');
		}
		wcos_compat_007_ledger_assert(!$task_owned, 'A task-owned order survived WOS-COMPAT-007 cleanup: ' . $order->get_id());
	}

	foreach (wc_get_products(array('limit' => -1, 'return' => 'objects')) as $product) {
		if (!$product instanceof WC_Product) { continue; }
		$name = (string) $product->get_name();
		wcos_compat_007_ledger_assert(0 !== strpos($name, 'WOS COMPAT 007 ') && 0 !== strpos($name, 'Exact 1.4.11 '), 'A task-owned product survived WOS-COMPAT-007 cleanup: ' . $product->get_id());
	}

	foreach (get_users(array('fields' => array('ID', 'user_login'))) as $user) {
		wcos_compat_007_ledger_assert(0 !== strpos((string) $user->user_login, 'wcos_compat_007_'), 'A task-owned user survived WOS-COMPAT-007 cleanup: ' . $user->ID);
	}
	$terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
	if (!is_wp_error($terms)) {
		foreach ($terms as $term) {
			wcos_compat_007_ledger_assert(0 !== strpos((string) $term->name, 'WOS COMPAT 007 baseline category '), 'A task-owned term survived WOS-COMPAT-007 cleanup: ' . $term->term_id);
		}
	}

	global $wpdb;
	$patterns = array('%wcos_compat_007%', '%wos-compat-007%');
	foreach ($patterns as $pattern) {
		$count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
			$wpdb->esc_like('_transient_') . '%',
			$pattern
		));
		wcos_compat_007_ledger_assert(0 === $count, 'Task-owned transient authority survived WOS-COMPAT-007 cleanup.');
	}
}

if (!defined('WCOS_COMPAT_007_LEDGER_LIBRARY_ONLY')) {
	$arguments = isset($args) && is_array($args) ? array_values($args) : array();
	$action = isset($arguments[0]) ? (string) $arguments[0] : '';
	if ('init' === $action) {
		wcos_compat_007_ledger_initialize();
		echo "compat-upgrade-cleanup-ledger-initialized\n";
	} elseif ('assert-clean' === $action) {
		wcos_compat_007_ledger_assert_clean();
		echo "compat-upgrade-cleanup-assert-ok\n";
	} else {
		throw new RuntimeException('Unknown WOS-COMPAT-007 cleanup-ledger action.');
	}
}
