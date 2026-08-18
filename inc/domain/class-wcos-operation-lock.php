<?php

defined('ABSPATH') || exit;

/**
 * Storage-agnostic order-operation lease backed by a non-autoloaded option.
 *
 * Every acquisition receives a unique lease token. Stale takeover, refresh,
 * and release use compare-and-swap SQL so an expired worker cannot delete or
 * overwrite a newer worker's lease.
 */
final class WCOS_Operation_Lock {

	const DEFAULT_TTL = 300;

	/**
	 * @return string|false Unique lease token on success, false when locked.
	 */
	public static function acquire($order_id, $operation_id, $ttl = self::DEFAULT_TTL) {
		$order_id = absint($order_id);
		$operation_id = sanitize_key($operation_id);
		$ttl = max(30, absint($ttl));

		if (!$order_id || '' === $operation_id) {
			return false;
		}

		$key = self::key($order_id);
		$value = self::new_value($operation_id, $ttl);

		if (add_option($key, $value, '', false)) {
			return $value['lease_token'];
		}

		$current = get_option($key, null);
		if (!is_array($current) || empty($current['expires_at'])) {
			return self::compare_and_swap($key, $current, $value) ? $value['lease_token'] : false;
		}

		if ((int) $current['expires_at'] > time()) {
			return false;
		}

		return self::compare_and_swap($key, $current, $value) ? $value['lease_token'] : false;
	}

	public static function refresh($order_id, $lease_token, $ttl = self::DEFAULT_TTL) {
		$order_id = absint($order_id);
		$lease_token = sanitize_key($lease_token);
		$ttl = max(30, absint($ttl));
		if (!$order_id || '' === $lease_token) {
			return false;
		}

		$key = self::key($order_id);
		$current = get_option($key, null);
		if (!self::matches_lease($current, $lease_token)) {
			return false;
		}

		$replacement = $current;
		$replacement['expires_at'] = time() + $ttl;
		$replacement['refreshed_at'] = gmdate('c');
		return self::compare_and_swap($key, $current, $replacement);
	}

	public static function is_owned($order_id, $lease_token) {
		$order_id = absint($order_id);
		$lease_token = sanitize_key($lease_token);
		if (!$order_id || '' === $lease_token) {
			return false;
		}

		$current = get_option(self::key($order_id), null);
		return self::matches_lease($current, $lease_token)
			&& isset($current['expires_at'])
			&& (int) $current['expires_at'] > time();
	}

	public static function release($order_id, $lease_token) {
		$order_id = absint($order_id);
		$lease_token = sanitize_key($lease_token);
		if (!$order_id || '' === $lease_token) {
			return false;
		}

		$key = self::key($order_id);
		$current = get_option($key, null);
		if (!self::matches_lease($current, $lease_token)) {
			return false;
		}

		return self::compare_and_delete($key, $current);
	}

	private static function new_value($operation_id, $ttl) {
		return array(
			'operation_id' => $operation_id,
			'lease_token' => sanitize_key(wp_generate_uuid4()),
			'acquired_at' => gmdate('c'),
			'refreshed_at' => null,
			'expires_at' => time() + $ttl,
		);
	}

	private static function matches_lease($value, $lease_token) {
		return is_array($value)
			&& isset($value['lease_token'])
			&& hash_equals((string) $value['lease_token'], (string) $lease_token);
	}

	private static function compare_and_swap($key, $current, array $replacement) {
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
			return false;
		}

		wp_cache_delete($key, 'options');
		return true;
	}

	private static function compare_and_delete($key, $current) {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				maybe_serialize($current)
			)
		);

		if (1 !== $deleted) {
			return false;
		}

		wp_cache_delete($key, 'options');
		return true;
	}

	private static function key($order_id) {
		return 'wcos_mutation_lock_' . absint($order_id);
	}
}
