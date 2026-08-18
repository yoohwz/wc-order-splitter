<?php
/**
 * Strict preflight and complete operation fingerprint.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-wcos-v2-amount-allocator.php';
require_once __DIR__ . '/class-wcos-v2-metadata-policy.php';
require_once __DIR__ . '/class-wcos-v2-line-identity.php';
require_once __DIR__ . '/class-wcos-v2-split-plan.php';
require_once __DIR__ . '/class-wcos-v2-order-snapshot.php';
require_once __DIR__ . '/class-wcos-v2-split-preflight.php';

/**
 * Adds source-integrity checks and a complete idempotency fingerprint.
 */
final class WCOS_V2_Strict_Preflight {

	/**
	 * Validate and fingerprint an immutable split request.
	 *
	 * @param WC_Order $order                Source order.
	 * @param array    $requested_quantities Item ID => split quantity.
	 * @return array|WP_Error
	 */
	public static function validate(WC_Order $order, array $requested_quantities) {
		$result = WCOS_V2_Split_Preflight::validate($order, $requested_quantities);

		if (is_wp_error($result)) {
			return $result;
		}

		$snapshot = $result['snapshot'];

		if ('' === trim((string) $snapshot['currency'])) {
			return self::error('wcos_missing_order_currency', __('The source order does not have a currency.', 'wc-order-splitter'));
		}

		if (null === $snapshot['order_stock_reduced']) {
			return self::error('wcos_unknown_stock_state', __('The source order stock state could not be read safely.', 'wc-order-splitter'));
		}

		foreach ($snapshot['lines'] as $line) {
			if ('' === (string) $line['identity']) {
				return self::error('wcos_missing_line_identity', __('An order line could not be identified safely.', 'wc-order-splitter'));
			}

			if (null !== $line['reduced_stock'] && (float) $line['reduced_stock'] > (float) $line['quantity'] + 0.0000001) {
				return self::error(
					'wcos_invalid_reduced_stock',
					sprintf(
						/* translators: %d: WooCommerce order item ID. */
						__('Order item %d has a stock marker greater than its quantity.', 'wc-order-splitter'),
						(int) $line['item_id']
					)
				);
			}
		}

		$payload = array(
			'order' => array(
				'order_id'            => (int) $snapshot['order_id'],
				'order_type'          => (string) $snapshot['order_type'],
				'status'              => (string) $snapshot['status'],
				'currency'            => (string) $snapshot['currency'],
				'prices_include_tax'  => (bool) $snapshot['prices_include_tax'],
				'customer_id'         => (int) $snapshot['customer_id'],
				'transaction_id'      => (string) $snapshot['transaction_id'],
				'order_stock_reduced' => (bool) $snapshot['order_stock_reduced'],
				'amounts'             => $snapshot['amounts'],
			),
			'lines' => array(),
			'non_product_items' => array(
				'shipping' => $snapshot['shipping_items'],
				'fees'     => $snapshot['fee_items'],
				'coupons'  => $snapshot['coupon_items'],
				'taxes'    => $snapshot['tax_items'],
			),
			'plan_fingerprint' => $result['plan']['fingerprint'],
		);

		foreach ($snapshot['lines'] as $item_id => $line) {
			$payload['lines'][(int) $item_id] = array(
				'identity'      => (string) $line['identity'],
				'quantity'      => (string) $line['quantity'],
				'subtotal'      => (string) $line['subtotal'],
				'total'         => (string) $line['total'],
				'subtotal_tax'  => (string) $line['subtotal_tax'],
				'total_tax'     => (string) $line['total_tax'],
				'taxes'         => $line['taxes'],
				'reduced_stock' => $line['reduced_stock'],
			);
		}

		$canonical = self::canonicalize($payload);
		$json      = wp_json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		if (!is_string($json)) {
			return self::error('wcos_strict_fingerprint_failed', __('The complete order state could not be fingerprinted safely.', 'wc-order-splitter'));
		}

		$result['base_fingerprint'] = $result['fingerprint'];
		$result['fingerprint']      = hash('sha256', $json);
		$result['fingerprint_scope'] = 'complete_commercial_order_state';

		return $result;
	}

	/**
	 * Recursively sort associative structures for stable JSON.
	 *
	 * @param mixed $value Value to canonicalize.
	 * @return mixed
	 */
	private static function canonicalize($value) {
		if (is_object($value)) {
			$value = get_object_vars($value);
		}

		if (!is_array($value)) {
			return $value;
		}

		$normalized = array();

		foreach ($value as $key => $nested) {
			$normalized[$key] = self::canonicalize($nested);
		}

		if (array() !== $normalized && array_keys($normalized) !== range(0, count($normalized) - 1)) {
			ksort($normalized, SORT_STRING);
		}

		return $normalized;
	}

	/**
	 * Create a stable strict-preflight error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
