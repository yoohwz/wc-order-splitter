<?php

defined('ABSPATH') || exit;

/**
 * Durable idempotency and recovery journal.
 *
 * The authoritative record is stored in a non-autoloaded option keyed by the
 * source order and operation ID. A bounded order-meta summary is maintained for
 * admin/audit visibility, but it is never used to decide idempotency.
 */
final class WCOS_Operation_Journal {

	const SUMMARY_META_KEY = '_wcos_operation_journal';
	const MAX_SUMMARY_ENTRIES = 20;
	const MAX_CHECKPOINTS = 50;

	public static function start(WC_Order $order, $operation_id, $type, array $context = array(), $fingerprint = '') {
		$operation_id = sanitize_key($operation_id);
		$type = sanitize_key($type);
		$fingerprint = sanitize_key($fingerprint);
		if (!$order->get_id() || '' === $operation_id || '' === $type || '' === $fingerprint) {
			return false;
		}

		$now = gmdate('c');
		$record = array(
			'schema_version' => 1,
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
				$now = gmdate('c');
				$record['stage'] = $stage;
				$record['updated_at'] = $now;
				$record['context'] = array_merge(
					isset($record['context']) && is_array($record['context']) ? $record['context'] : array(),
					$context
				);
				$checkpoints = isset($record['checkpoints']) && is_array($record['checkpoints']) ? $record['checkpoints'] : array();
				$checkpoints[] = array(
					'stage' => $stage,
					'at' => $now,
					'context' => $context,
				);
				$record['checkpoints'] = array_slice($checkpoints, -self::MAX_CHECKPOINTS);
				return $record;
			}
		);
	}

	public static function mark_committed(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status($order, $operation_id, 'committed', 'source_committed', $context, false);
	}

	public static function complete(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status($order, $operation_id, 'completed', 'completed', $context, true);
	}

	public static function fail(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status($order, $operation_id, 'failed', 'failed', $context, true);
	}

	public static function require_recovery(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status($order, $operation_id, 'recovery_required', 'recovery_required', $context, false);
	}

	public static function resume(WC_Order $order, $operation_id, array $context = array()) {
		return self::set_status($order, $operation_id, 'started', 'resumed', $context, false);
	}

	public static function delete(WC_Order $order, $operation_id) {
		$operation_id = sanitize_key($operation_id);
		if (!$order->get_id() || '' === $operation_id) {
			return false;
		}
		return delete_option(self::key($order->get_id(), $operation_id));
	}

	private static function set_status(WC_Order $order, $operation_id, $status, $stage, array $context, $terminal) {
		$status = sanitize_key($status);
		$stage = sanitize_key($stage);
		return self::mutate(
			$order,
			$operation_id,
			static function(array $record) use ($status, $stage, $context, $terminal) {
				$now = gmdate('c');
				$record['status'] = $status;
				$record['stage'] = $stage;
				$record['updated_at'] = $now;
				if ($terminal) {
					$record['completed_at'] = $now;
				}
				$record['context'] = array_merge(
					isset($record['context']) && is_array($record['context']) ? $record['context'] : array(),
					$context
				);
				$checkpoints = isset($record['checkpoints']) && is_array($record['checkpoints']) ? $record['checkpoints'] : array();
				$checkpoints[] = array(
					'stage' => $stage,
					'at' => $now,
					'context' => $context,
				);
				$record['checkpoints'] = array_slice($checkpoints, -self::MAX_CHECKPOINTS);
				return $record;
			}
		);
	}

	private static function mutate(WC_Order $order, $operation_id, callable $mutator) {
		$operation_id = sanitize_key($operation_id);
		if (!$order->get_id() || '' === $operation_id) {
			return false;
		}

		$key = self::key($order->get_id(), $operation_id);
		for ($attempt = 0; $attempt < 3; $attempt++) {
			$current = get_option($key, null);
			if (!is_array($current)) {
				return false;
			}

			$replacement = $mutator($current);
			if (!is_array($replacement)) {
				return false;
			}

			if (self::compare_and_swap($key, $current, $replacement)) {
				self::write_summary($order, $replacement);
				return true;
			}
		}

		return false;
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

		if (1 !== $updated) {
			wp_cache_delete($key, 'options');
			return false;
		}

		wp_cache_delete($key, 'options');
		return true;
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
