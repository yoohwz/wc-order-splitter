<?php
/**
 * Strict per-order mutation lease lock.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || (PHP_SAPI === 'cli') || exit;

/**
 * Prevents concurrent requests from mutating the same order.
 *
 * Idempotency and locking are deliberately separate concepts. Reusing an
 * operation ID never bypasses an active lease; every executing request owns a
 * unique lease ID. An expired lease is cleared conservatively and the caller
 * must retry, so two contenders cannot both acquire during stale-lock cleanup.
 */
final class WCOS_V2_Lease_Lock {

	/**
	 * Acquire a new lease.
	 *
	 * @param int    $order_id     Source order ID.
	 * @param string $operation_id Stable idempotency operation ID.
	 * @param string $lease_id     Unique ID for this executing request.
	 * @param int    $ttl          Lease lifetime in seconds.
	 * @return array|WP_Error Lease payload on success.
	 */
	public static function acquire($order_id, $operation_id, $lease_id, $ttl = 300) {
		$order_id     = absint($order_id);
		$operation_id = self::normalize_identifier($operation_id);
		$lease_id     = self::normalize_identifier($lease_id);
		$ttl          = max(30, min(900, absint($ttl)));

		if (!$order_id || '' === $operation_id || '' === $lease_id) {
			return self::error(
				'wcos_invalid_lease_identity',
				__('The order operation lease identity is invalid.', 'wc-order-splitter')
			);
		}

		$now     = time();
		$key     = self::key($order_id);
		$payload = array(
			'order_id'     => $order_id,
			'operation_id' => $operation_id,
			'lease_id'     => $lease_id,
			'acquired_at'  => $now,
			'expires_at'   => $now + $ttl,
		);

		if (add_option($key, $payload, '', 'no')) {
			return $payload;
		}

		$existing = self::read($order_id);

		if (null === $existing) {
			return self::error(
				'wcos_lease_race',
				__('The order operation lease changed concurrently. Retry the operation.', 'wc-order-splitter')
			);
		}

		if ((int) $existing['expires_at'] >= $now) {
			return self::error(
				'wcos_order_mutation_locked',
				__('Another order operation is already in progress. Refresh the order and try again.', 'wc-order-splitter'),
				array(
					'operation_id' => $existing['operation_id'],
					'expires_at'   => (int) $existing['expires_at'],
				)
			);
		}

		/*
		 * Stale cleanup is intentionally not combined with acquisition. All
		 * contenders return and must retry, preventing a delete/add race from
		 * granting execution to more than one request.
		 */
		$current = self::read($order_id);

		if (null !== $current && hash_equals(self::payload_hash($existing), self::payload_hash($current))) {
			delete_option($key);
		}

		return self::error(
			'wcos_stale_lease_cleared',
			__('An expired order operation lease was cleared. Retry the operation.', 'wc-order-splitter')
		);
	}

	/**
	 * Release only the exact lease owned by the current request.
	 *
	 * @param int    $order_id     Source order ID.
	 * @param string $operation_id Stable operation ID.
	 * @param string $lease_id     Unique request lease ID.
	 * @return bool
	 */
	public static function release($order_id, $operation_id, $lease_id) {
		$order_id     = absint($order_id);
		$operation_id = self::normalize_identifier($operation_id);
		$lease_id     = self::normalize_identifier($lease_id);
		$existing     = self::read($order_id);

		if (null === $existing) {
			return false;
		}

		if (!hash_equals((string) $existing['operation_id'], $operation_id)) {
			return false;
		}

		if (!hash_equals((string) $existing['lease_id'], $lease_id)) {
			return false;
		}

		return delete_option(self::key($order_id));
	}

	/**
	 * Inspect the current normalized lease without changing it.
	 *
	 * @param int $order_id Source order ID.
	 * @return array|null
	 */
	public static function inspect($order_id) {
		return self::read(absint($order_id));
	}

	/**
	 * Read and validate a persisted lease.
	 *
	 * @param int $order_id Source order ID.
	 * @return array|null
	 */
	private static function read($order_id) {
		if (!$order_id) {
			return null;
		}

		$value = get_option(self::key($order_id), null);

		if (!is_array($value)) {
			return null;
		}

		$required = array('order_id', 'operation_id', 'lease_id', 'acquired_at', 'expires_at');

		foreach ($required as $field) {
			if (!array_key_exists($field, $value)) {
				return null;
			}
		}

		return array(
			'order_id'     => absint($value['order_id']),
			'operation_id' => self::normalize_identifier($value['operation_id']),
			'lease_id'     => self::normalize_identifier($value['lease_id']),
			'acquired_at'  => (int) $value['acquired_at'],
			'expires_at'   => (int) $value['expires_at'],
		);
	}

	/**
	 * Build a stable non-autoloaded option key.
	 *
	 * @param int $order_id Source order ID.
	 * @return string
	 */
	private static function key($order_id) {
		return 'wcos_v2_order_lease_' . md5((string) absint($order_id));
	}

	/**
	 * Normalize an externally supplied identifier without truncating UUID-like IDs.
	 *
	 * @param mixed $identifier Identifier.
	 * @return string
	 */
	private static function normalize_identifier($identifier) {
		$identifier = strtolower(trim((string) $identifier));

		return preg_replace('/[^a-z0-9._:-]/', '', $identifier);
	}

	/**
	 * Hash a normalized payload for conservative stale cleanup.
	 *
	 * @param array $payload Lease payload.
	 * @return string
	 */
	private static function payload_hash(array $payload) {
		return hash('sha256', implode('|', array(
			(int) $payload['order_id'],
			(string) $payload['operation_id'],
			(string) $payload['lease_id'],
			(int) $payload['acquired_at'],
			(int) $payload['expires_at'],
		)));
	}

	/**
	 * Create a stable lock error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $data    Optional error data.
	 * @return WP_Error
	 */
	private static function error($code, $message, array $data = array()) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message), $data);
	}
}
