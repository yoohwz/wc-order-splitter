<?php
/**
 * Atomic per-order mutation lock.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Prevents concurrent mutations against the same WooCommerce order.
 */
final class WCOS_V2_Operation_Lock {

	/**
	 * Acquire an atomic lock using add_option().
	 *
	 * @param int    $order_id     Source order ID.
	 * @param string $operation_id Operation UUID.
	 * @param int    $ttl          Lock lifetime in seconds.
	 * @return true|WP_Error
	 */
	public static function acquire($order_id, $operation_id, $ttl = 120) {
		$order_id     = absint($order_id);
		$operation_id = sanitize_key($operation_id);
		$ttl          = max(30, absint($ttl));

		if (!$order_id || '' === $operation_id) {
			return new WP_Error(
				'wcos_invalid_lock_identity',
				esc_html__('The order mutation lock identity is invalid.', 'wc-order-splitter')
			);
		}

		$key     = self::key($order_id);
		$now     = time();
		$payload = array(
			'operation_id' => $operation_id,
			'acquired_at'  => $now,
			'expires_at'   => $now + $ttl,
		);

		if (add_option($key, $payload, '', 'no')) {
			return true;
		}

		$existing = get_option($key, array());

		if (is_array($existing) && isset($existing['operation_id']) && hash_equals((string) $existing['operation_id'], $operation_id)) {
			return true;
		}

		if (is_array($existing) && isset($existing['expires_at']) && (int) $existing['expires_at'] < $now) {
			delete_option($key);

			if (add_option($key, $payload, '', 'no')) {
				return true;
			}
		}

		return new WP_Error(
			'wcos_order_mutation_locked',
			esc_html__('Another order operation is already in progress. Refresh the order and try again.', 'wc-order-splitter')
		);
	}

	/**
	 * Release a lock only when it belongs to the supplied operation.
	 *
	 * @param int    $order_id     Source order ID.
	 * @param string $operation_id Operation UUID.
	 * @return bool
	 */
	public static function release($order_id, $operation_id) {
		$key       = self::key(absint($order_id));
		$existing  = get_option($key, array());
		$operation = sanitize_key($operation_id);

		if (!is_array($existing) || !isset($existing['operation_id'])) {
			return false;
		}

		if (!hash_equals((string) $existing['operation_id'], $operation)) {
			return false;
		}

		return delete_option($key);
	}

	/**
	 * Build the non-autoloaded option key.
	 *
	 * @param int $order_id Source order ID.
	 * @return string
	 */
	private static function key($order_id) {
		return 'wcos_v2_order_lock_' . md5((string) $order_id);
	}
}
