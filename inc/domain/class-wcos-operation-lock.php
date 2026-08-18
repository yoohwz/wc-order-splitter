<?php

defined('ABSPATH') || exit;

/**
 * Storage-agnostic order-operation lock backed by WordPress options.
 *
 * add_option() provides a unique option-name insert, which is used as the
 * compare-and-set primitive. The lock is independent of HPOS order storage.
 */
final class WCOS_Operation_Lock {

	const DEFAULT_TTL = 300;

	public static function acquire($order_id, $operation_id, $ttl = self::DEFAULT_TTL) {
		$order_id = absint($order_id);
		$operation_id = sanitize_key($operation_id);
		$ttl = max(30, absint($ttl));

		if (!$order_id || '' === $operation_id) {
			return false;
		}

		$key = self::key($order_id);
		$now = time();
		$value = array(
			'operation_id' => $operation_id,
			'expires_at' => $now + $ttl,
		);

		if (add_option($key, $value, '', false)) {
			return true;
		}

		$current = get_option($key, array());
		if (!is_array($current) || empty($current['expires_at']) || (int) $current['expires_at'] >= $now) {
			return false;
		}

		delete_option($key);
		return add_option($key, $value, '', false);
	}

	public static function release($order_id, $operation_id) {
		$order_id = absint($order_id);
		$operation_id = sanitize_key($operation_id);
		$key = self::key($order_id);
		$current = get_option($key, array());

		if (!is_array($current) || !isset($current['operation_id']) || $current['operation_id'] !== $operation_id) {
			return false;
		}

		return delete_option($key);
	}

	private static function key($order_id) {
		return 'wcos_mutation_lock_' . absint($order_id);
	}
}
