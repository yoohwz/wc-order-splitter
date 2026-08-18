<?php

defined('ABSPATH') || exit;

/**
 * Durable idempotency and recovery journal.
 *
 * The authoritative record is stored in a non-autoloaded option keyed by the
 * source order and operation ID. A bounded order-meta summary is maintained for
 * admin/audit visibility, but it is never used to decide idempotency or
 * recovery. Authoritative checkpoints are never silently discarded.
 */
final class WCOS_Operation_Journal {

	const SCHEMA_VERSION = 2;
	const SUMMARY_META_KEY = '_wcos_operation_journal';
	const MAX_SUMMARY_ENTRIES = 20;

	public static function start(WC_Order $order, $operation_id, $type, array $context = array(), $fingerprint = '') {
		$operation_id = sanitize_key($operation_id);
		$type = sanitize_key($type);
		$fingerprint = sanitize_key($fingerprint);
		if (!$order->get_id() || '' === $operation_id || '' === $type || '' === $fingerprint) {
			return false;
		}

		$now = gmdate('c');
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'revision' => 1,
			'source_order_id' => $order->get_id(),
			'operation_id' => $operation_id,
			'type' => $type,
			'fingerprint' => $fingerprint,
			'status' => 'started',
			'stage' => 'started',
			'started_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'context' => $context,
			'checkpoints' => array(
				array(
					'sequence' => 1,
					'stage' => 'started',
					'at' => $now,
					'context' => array(),
				),
			),
		);

		if (!add_option(self::key($order->get_id(), $operation_id), $record, '', false)) {
			return false;
		}

		self::write_summary($order, $record);
		return true;
	}

	public static function get(WC_Order $order, $operation_id) {
		$operation_id = sanitize_key($operation_id);
		if (!$order->get_id() || '' === $operation_id) {
			return null;
		}

		$record = get_option(self::key($order->get_id(), $operation_id), null);
		return is_array($record) ? $record : null;
	}

	public static function assert_fingerprint(array $record, $fingerprint) {
		$stored = isset($record['fingerprint']) ? (string) $record['fingerprint'] : '';
		$fingerprint = sanitize_key($fingerprint);
		if ('' === $stored || '' === $fingerprint || !hash_equals($stored, $fingerprint)) {
			throw new RuntimeException(__('This operation ID was already used for a different mutation request.', 'wc-order-splitter'));
		}
	}

	public static function checkpoint(WC_Order $order, $operation_id, $stage, array $context = array()) {
		$stage = sanitize_key($stage);
		if ('' === $stage) {
			return false;
		}

		return self::mutate(
			$order,
			$operation_id,
			static function(array $record) use ($stage, $context) {
				$status = isset($record['status']) ? sanitize_key($record['status']) : '';
				if (!in_array($status, array('started', 'recovery_required', 'committed', 'compensating'), true)) {
					return false;
				}
				return self::append_checkpoint($record, $stage, $context);
			}
		);
	}

	public static function mark_committed(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status(
			$order,
			$operation_id,
			'committed',
			'source_committed',
			$context,
			false,
			array('started', 'recovery_required', 'committed')
		);
	}

	public static function complete(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status(
			$order,
			$operation_id,
			'completed',
			'completed',
			$context,
			true,
			array('committed', 'completed')
		);
	}

	public static function fail(WC_Order $order, $operation_id, array $context = array()) {
		$current = self::get($order, $operation_id);
		if (!is_array($current)) {
			return false;
		}
		$status = isset($current['status']) ? sanitize_key($current['status']) : '';
		if (in_array($status, array('failed', 'recovery_required', 'committed', 'completed', 'compensating', 'compensated'), true)) {
			return true;
		}
		return self::set_status(
			$order,
			$operation_id,
			'failed',
			'failed',
			$context,
			true,
			array('started')
		);
	}

	public static function require_recovery(WC_Order $order, $operation_id, array $context = array()) {
		$current = self::get($order, $operation_id);
		if (!is_array($current)) {
			return false;
		}
		$status = isset($current['status']) ? sanitize_key($current['status']) : '';
		if (in_array($status, array('completed', 'compensated'), true)) {
			return true;
		}
		return self::set_status(
			$order,
			$operation_id,
			'recovery_required',
			'recovery_required',
			$context,
			false,
			array('started', 'failed', 'recovery_required', 'committed')
		);
	}

	public static function resume(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status(
			$order,
			$operation_id,
			'started',
			'resumed',
			$context,
			false,
			array('started', 'failed', 'recovery_required')
		);
	}

	public static function mark_compensating(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status(
			$order,
			$operation_id,
			'compensating',
			'compensating',
			$context,
			false,
			array('started', 'failed', 'recovery_required', 'compensating')
		);
	}

	public static function mark_compensated(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status(
			$order,
			$operation_id,
			'compensated',
			'compensated',
			$context,
			true,
			array('compensating', 'compensated')
		);
	}

	public static function delete(WC_Order $order, $operation_id) {
		$operation_id = sanitize_key($operation_id);
		if (!$order->get_id() || '' === $operation_id) {
			return false;
		}
		return delete_option(self::key($order->get_id(), $operation_id));
	}

	private static function set_status(WC_Order $order, $operation_id, $status, $stage, array $context, $terminal, array $allowed_from) {
		$status = sanitize_key($status);
		$stage = sanitize_key($stage);
		return self::mutate(
			$order,
			$operation_id,
			static function(array $record) use ($status, $stage, $context, $terminal, $allowed_from) {
				$current_status = isset($record['status']) ? sanitize_key($record['status']) : '';
				if (!in_array($current_status, $allowed_from, true)) {
					return false;
				}
				$record['status'] = $status;
				$record['completed_at'] = $terminal ? gmdate('c') : null;
				return self::append_checkpoint($record, $stage, $context);
			}
		);
	}

	private static function append_checkpoint(array $record, $stage, array $context) {
		$now = gmdate('c');
		$record['stage'] = sanitize_key($stage);
		$record['updated_at'] = $now;
		$record['context'] = array_merge(
			isset($record['context']) && is_array($record['context']) ? $record['context'] : array(),
			$context
		);
		$checkpoints = isset($record['checkpoints']) && is_array($record['checkpoints']) ? $record['checkpoints'] : array();
		$checkpoints[] = array(
			'sequence' => count($checkpoints) + 1,
			'stage' => sanitize_key($stage),
			'at' => $now,
			'context' => $context,
		);
		$record['checkpoints'] = $checkpoints;
		return $record;
	}

	private static function mutate(WC_Order $order, $operation_id, callable $mutator) {
		$operation_id = sanitize_key($operation_id);
		if (!$order->get_id() || '' === $operation_id) {
			return false;
		}

		$key = self::key($order->get_id(), $operation_id);
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$current = get_option($key, null);
			if (!is_array($current)) {
				return false;
			}

			$replacement = $mutator($current);
			if (!is_array($replacement)) {
				return false;
			}
			if (!self::immutable_fields_match($current, $replacement)) {
				return false;
			}

			$replacement['schema_version'] = self::SCHEMA_VERSION;
			$replacement['revision'] = isset($current['revision']) ? ((int) $current['revision'] + 1) : 2;
			if (self::compare_and_swap($key, $current, $replacement)) {
				self::write_summary($order, $replacement);
				return true;
			}
		}

		return false;
	}

	private static function immutable_fields_match(array $current, array $replacement) {
		foreach (array('source_order_id', 'operation_id', 'type', 'fingerprint') as $field) {
			if (!array_key_exists($field, $current)
				|| !array_key_exists($field, $replacement)
				|| (string) $current[$field] !== (string) $replacement[$field]) {
				return false;
			}
		}
		return true;
	}

	private static function compare_and_swap($key, array $current, array $replacement) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize($replacement),
				$key,
				maybe_serialize($current)
			)
		);

		wp_cache_delete($key, 'options');
		return 1 === $updated;
	}

	private static function write_summary(WC_Order $order, array $record) {
		$entries = $order->get_meta(self::SUMMARY_META_KEY, true);
		$entries = is_array($entries) ? $entries : array();
		$summary = array(
			'operation_id' => isset($record['operation_id']) ? $record['operation_id'] : '',
			'type' => isset($record['type']) ? $record['type'] : '',
			'fingerprint' => isset($record['fingerprint']) ? $record['fingerprint'] : '',
			'status' => isset($record['status']) ? $record['status'] : '',
			'stage' => isset($record['stage']) ? $record['stage'] : '',
			'revision' => isset($record['revision']) ? (int) $record['revision'] : 0,
			'started_at' => isset($record['started_at']) ? $record['started_at'] : null,
			'updated_at' => isset($record['updated_at']) ? $record['updated_at'] : null,
			'completed_at' => isset($record['completed_at']) ? $record['completed_at'] : null,
		);

		$replaced = false;
		foreach ($entries as $index => $entry) {
			if (isset($entry['operation_id']) && $entry['operation_id'] === $summary['operation_id']) {
				$entries[$index] = $summary;
				$replaced = true;
				break;
			}
		}
		if (!$replaced) {
			$entries[] = $summary;
		}

		$order->update_meta_data(self::SUMMARY_META_KEY, array_slice($entries, -self::MAX_SUMMARY_ENTRIES));
		$order->save_meta_data();
	}

	private static function key($order_id, $operation_id) {
		return 'wcos_mutation_op_' . hash('sha256', absint($order_id) . '|' . sanitize_key($operation_id));
	}
}
