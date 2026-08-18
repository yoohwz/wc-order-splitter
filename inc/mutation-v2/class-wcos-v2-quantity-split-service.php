<?php
/**
 * Request-bound public service boundary for the gated quantity split executor.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Prevents one committed operation ID from being reused with another payload.
 */
final class WCOS_V2_Quantity_Split_Service {

	/**
	 * Create a request-bound operation ID.
	 *
	 * @param int         $order_id             Source order ID.
	 * @param array       $requested_quantities Source item ID => quantity.
	 * @param string|null $nonce                 Optional UUID nonce for tests.
	 * @return string|WP_Error
	 */
	public static function create_operation_id($order_id, array $requested_quantities, $nonce = null) {
		$fingerprint = self::request_fingerprint($order_id, $requested_quantities);

		if (is_wp_error($fingerprint)) {
			return $fingerprint;
		}

		$nonce = null === $nonce ? wp_generate_uuid4() : strtolower(trim((string) $nonce));

		if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $nonce)) {
			return self::error('wcos_invalid_operation_nonce', __('The quantity split operation nonce is invalid.', 'wc-order-splitter'));
		}

		return sprintf('qsplit.%d.%s.%s', absint($order_id), $fingerprint, $nonce);
	}

	/**
	 * Execute only when the operation ID is bound to the exact request payload.
	 *
	 * @param int    $order_id             Source order ID.
	 * @param array  $requested_quantities Source item ID => quantity.
	 * @param string $operation_id         Request-bound operation ID.
	 * @return array|WP_Error
	 */
	public static function execute($order_id, array $requested_quantities, $operation_id) {
		$validation = self::validate_operation_id($order_id, $requested_quantities, $operation_id);

		if (is_wp_error($validation)) {
			return $validation;
		}

		return WCOS_V2_Quantity_Split_Executor::execute($order_id, $requested_quantities, $validation);
	}

	/**
	 * Validate and normalize a request-bound operation ID.
	 *
	 * @param int    $order_id             Source order ID.
	 * @param array  $requested_quantities Source item ID => quantity.
	 * @param string $operation_id         Operation ID.
	 * @return string|WP_Error
	 */
	public static function validate_operation_id($order_id, array $requested_quantities, $operation_id) {
		$order_id     = absint($order_id);
		$operation_id = strtolower(trim((string) $operation_id));
		$pattern      = '/^qsplit\.([1-9][0-9]*)\.([a-f0-9]{64})\.([a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})$/';

		if (!$order_id || !preg_match($pattern, $operation_id, $matches)) {
			return self::error('wcos_invalid_bound_operation_id', __('The quantity split operation ID has an invalid format.', 'wc-order-splitter'));
		}

		if ((int) $matches[1] !== $order_id) {
			return self::error('wcos_operation_order_mismatch', __('The quantity split operation ID belongs to another source order.', 'wc-order-splitter'));
		}

		$fingerprint = self::request_fingerprint($order_id, $requested_quantities);

		if (is_wp_error($fingerprint)) {
			return $fingerprint;
		}

		if (!hash_equals($matches[2], $fingerprint)) {
			return self::error(
				'wcos_operation_payload_mismatch',
				__('The quantity split operation ID was created for a different item quantity request.', 'wc-order-splitter')
			);
		}

		return $operation_id;
	}

	/**
	 * Compute a stable request-only fingerprint.
	 *
	 * @param int   $order_id             Source order ID.
	 * @param array $requested_quantities Source item ID => quantity.
	 * @return string|WP_Error
	 */
	public static function request_fingerprint($order_id, array $requested_quantities) {
		$order_id = absint($order_id);

		if (!$order_id || empty($requested_quantities)) {
			return self::error('wcos_invalid_quantity_request', __('The quantity split request is empty or invalid.', 'wc-order-splitter'));
		}

		$normalized = array();

		foreach ($requested_quantities as $item_id => $quantity) {
			$item_id = absint($item_id);

			if (!$item_id || !is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity <= 0) {
				return self::error('wcos_invalid_quantity_request', __('Every quantity split item and quantity must be valid.', 'wc-order-splitter'));
			}

			$normalized[$item_id] = self::normalize_quantity($quantity);
		}

		ksort($normalized, SORT_NUMERIC);
		$json = wp_json_encode(
			array(
				'order_id'   => $order_id,
				'quantities' => $normalized,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);

		if (!is_string($json)) {
			return self::error('wcos_quantity_request_encode_failed', __('The quantity split request could not be fingerprinted.', 'wc-order-splitter'));
		}

		return hash('sha256', $json);
	}

	/**
	 * Normalize a positive quantity without float presentation differences.
	 *
	 * @param mixed $quantity Quantity.
	 * @return string
	 */
	private static function normalize_quantity($quantity) {
		$normalized = number_format((float) $quantity, 12, '.', '');
		$normalized = rtrim(rtrim($normalized, '0'), '.');

		return '' === $normalized ? '0' : $normalized;
	}

	/**
	 * Create a stable request-boundary error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
