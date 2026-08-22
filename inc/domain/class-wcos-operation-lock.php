<?php

defined('ABSPATH') || exit;

/**
 * Storage-agnostic order-operation lease backed by a non-autoloaded option.
 *
 * Every acquisition receives a unique lease token. Stale takeover, refresh,
 * and release use compare-and-swap SQL so an expired worker cannot delete or
 * overwrite a newer worker's lease. Request-local ownership is tracked together
 * with the operation ID so one mutation cannot reuse another operation's lease.
 */
final class WCOS_Operation_Lock {

	const DEFAULT_TTL = 300;

	private static $local_leases = array();

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
			self::remember($order_id, $value['lease_token'], $operation_id);
			return $value['lease_token'];
		}

		$current = get_option($key, null);
		if (!is_array($current) || empty($current['expires_at'])) {
			if (self::compare_and_swap($key, $current, $value)) {
				self::remember($order_id, $value['lease_token'], $operation_id);
				return $value['lease_token'];
			}
			return false;
		}

		if ((int) $current['expires_at'] > time()) {
			return false;
		}

		if (!self::compare_and_swap($key, $current, $value)) {
			return false;
		}
		self::remember($order_id, $value['lease_token'], $operation_id);
		return $value['lease_token'];
	}

	public static function refresh($order_id, $lease_token, $ttl = self::DEFAULT_TTL, $operation_id = '') {
		$order_id = absint($order_id);
		$lease_token = sanitize_key($lease_token);
		$operation_id = sanitize_key($operation_id);
		$ttl = max(30, absint($ttl));
		if (!$order_id || '' === $lease_token) {
			return false;
		}

		$key = self::key($order_id);
		$current = get_option($key, null);
		$now = time();
		if (!self::matches_lease($current, $lease_token)
			|| ('' !== $operation_id && !self::matches_operation($current, $operation_id))
			|| !isset($current['expires_at'])
			|| (int) $current['expires_at'] <= $now) {
			self::forget_if_token($order_id, $lease_token);
			return false;
		}

		$replacement = $current;
		$replacement['revision'] = isset($current['revision']) ? ((int) $current['revision'] + 1) : 2;
		$replacement['expires_at'] = $now + $ttl;
		$replacement['refreshed_at'] = gmdate('c');
		if (!self::compare_and_swap($key, $current, $replacement)) {
			return false;
		}
		self::remember($order_id, $lease_token, isset($replacement['operation_id']) ? $replacement['operation_id'] : '');
		return true;
	}

	public static function refresh_current($order_id, $ttl = self::DEFAULT_TTL) {
		$order_id = absint($order_id);
		if (!$order_id || !isset(self::$local_leases[$order_id]['lease_token'])) {
			return false;
		}
		return self::refresh($order_id, self::$local_leases[$order_id]['lease_token'], $ttl);
	}

	public static function refresh_current_for($order_id, $operation_id, $ttl = self::DEFAULT_TTL) {
		$order_id = absint($order_id);
		$operation_id = sanitize_key($operation_id);
		if (!$order_id || '' === $operation_id || !self::local_matches_operation($order_id, $operation_id)) {
			return false;
		}
		return self::refresh(
			$order_id,
			self::$local_leases[$order_id]['lease_token'],
			$ttl,
			$operation_id
		);
	}

	public static function is_owned($order_id, $lease_token, $operation_id = '') {
		$order_id = absint($order_id);
		$lease_token = sanitize_key($lease_token);
		$operation_id = sanitize_key($operation_id);
		if (!$order_id || '' === $lease_token) {
			return false;
		}

		$current = get_option(self::key($order_id), null);
		return self::matches_lease($current, $lease_token)
			&& ('' === $operation_id || self::matches_operation($current, $operation_id))
			&& isset($current['expires_at'])
			&& (int) $current['expires_at'] > time();
	}

	public static function is_current_owned($order_id) {
		$order_id = absint($order_id);
		return $order_id
			&& isset(self::$local_leases[$order_id]['lease_token'])
			&& self::is_owned($order_id, self::$local_leases[$order_id]['lease_token']);
	}

	public static function is_current_owned_for($order_id, $operation_id) {
		$order_id = absint($order_id);
		$operation_id = sanitize_key($operation_id);
		return $order_id
			&& '' !== $operation_id
			&& self::local_matches_operation($order_id, $operation_id)
			&& self::is_owned(
				$order_id,
				self::$local_leases[$order_id]['lease_token'],
				$operation_id
			);
	}

	/** Return the in-process token only to the operation that currently owns it. */
	public static function current_token_for($order_id, $operation_id) {
		$order_id = absint($order_id);
		$operation_id = sanitize_key((string) $operation_id);
		if (!self::is_current_owned_for($order_id, $operation_id)) {
			return false;
		}
		return isset(self::$local_leases[$order_id]['lease_token'])
			? (string) self::$local_leases[$order_id]['lease_token']
			: false;
	}

	public static function assert_current_owned($order_id) {
		if (!self::is_current_owned($order_id)) {
			throw new RuntimeException(__('The order mutation lease is no longer owned by this worker.', 'wc-order-splitter'));
		}
	}

	public static function assert_current_owned_for($order_id, $operation_id) {
		if (!self::is_current_owned_for($order_id, $operation_id)) {
			throw new RuntimeException(__('The order mutation lease is not owned by this operation.', 'wc-order-splitter'));
		}
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
			self::forget_if_token($order_id, $lease_token);
			return false;
		}

		$released = self::compare_and_delete($key, $current);
		if ($released) {
			self::forget_if_token($order_id, $lease_token);
		}
		return $released;
	}

	private static function new_value($operation_id, $ttl) {
		return array(
			'operation_id' => $operation_id,
			'lease_token' => sanitize_key(wp_generate_uuid4()),
			'revision' => 1,
			'acquired_at' => gmdate('c'),
			'refreshed_at' => null,
			'expires_at' => time() + $ttl,
		);
	}

	private static function remember($order_id, $lease_token, $operation_id) {
		self::$local_leases[absint($order_id)] = array(
			'lease_token' => sanitize_key($lease_token),
			'operation_id' => sanitize_key($operation_id),
		);
	}

	private static function forget_if_token($order_id, $lease_token) {
		$order_id = absint($order_id);
		if (isset(self::$local_leases[$order_id]['lease_token'])
			&& hash_equals((string) self::$local_leases[$order_id]['lease_token'], (string) $lease_token)) {
			unset(self::$local_leases[$order_id]);
		}
	}

	private static function local_matches_operation($order_id, $operation_id) {
		return isset(self::$local_leases[$order_id]['operation_id'])
			&& hash_equals((string) self::$local_leases[$order_id]['operation_id'], (string) $operation_id);
	}

	private static function matches_lease($value, $lease_token) {
		return is_array($value)
			&& isset($value['lease_token'])
			&& hash_equals((string) $value['lease_token'], (string) $lease_token);
	}

	private static function matches_operation($value, $operation_id) {
		return is_array($value)
			&& isset($value['operation_id'])
			&& hash_equals((string) $value['operation_id'], (string) $operation_id);
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

		wp_cache_delete($key, 'options');
		return 1 === $updated;
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

		wp_cache_delete($key, 'options');
		return 1 === $deleted;
	}

	private static function key($order_id) {
		return 'wcos_mutation_lock_' . absint($order_id);
	}
}
