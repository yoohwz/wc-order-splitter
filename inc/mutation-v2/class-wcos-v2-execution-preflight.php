<?php
/**
 * Execution-complete quantity split preflight.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Binds copyable customer and payment context to the strict source fingerprint.
 */
final class WCOS_V2_Execution_Preflight {

	/**
	 * Validate an order and capture all context that a child may inherit.
	 *
	 * @param WC_Order $order                Source order.
	 * @param array    $requested_quantities Item ID => split quantity.
	 * @return array|WP_Error
	 */
	public static function validate(WC_Order $order, array $requested_quantities) {
		$result = WCOS_V2_Strict_Preflight::validate($order, $requested_quantities);

		if (is_wp_error($result)) {
			return $result;
		}

		$context = array(
			'billing_address'      => self::normalize_map($order->get_address('billing')),
			'shipping_address'     => self::normalize_map($order->get_address('shipping')),
			'payment_method'       => (string) $order->get_payment_method(),
			'payment_method_title' => (string) $order->get_payment_method_title(),
			'customer_note'        => (string) $order->get_customer_note(),
			'customer_ip_address'  => (string) $order->get_customer_ip_address(),
			'customer_user_agent'  => (string) $order->get_customer_user_agent(),
		);
		$payload = array(
			'strict_fingerprint' => $result['fingerprint'],
			'copy_context'       => $context,
		);
		$json = wp_json_encode(self::canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		if (!is_string($json)) {
			return new WP_Error(
				'wcos_execution_fingerprint_failed',
				esc_html__('The complete child-order copy context could not be fingerprinted.', 'wc-order-splitter')
			);
		}

		$result['base_strict_fingerprint'] = $result['fingerprint'];
		$result['execution_context']       = $context;
		$result['fingerprint']             = hash('sha256', $json);
		$result['fingerprint_scope']       = 'execution_complete_order_state';

		return $result;
	}

	/**
	 * Normalize an address map deterministically.
	 *
	 * @param mixed $value Address data.
	 * @return array
	 */
	private static function normalize_map($value) {
		$value = is_array($value) ? $value : array();
		$result = array();

		foreach ($value as $key => $field_value) {
			$result[(string) $key] = is_scalar($field_value) || null === $field_value ? (string) $field_value : '';
		}

		ksort($result, SORT_STRING);

		return $result;
	}

	/**
	 * Recursively canonicalize associative arrays.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalize($value) {
		if (!is_array($value)) {
			return $value;
		}

		$result = array();

		foreach ($value as $key => $nested) {
			$result[$key] = self::canonicalize($nested);
		}

		if (array() !== $result && array_keys($result) !== range(0, count($result) - 1)) {
			ksort($result, SORT_STRING);
		}

		return $result;
	}
}
