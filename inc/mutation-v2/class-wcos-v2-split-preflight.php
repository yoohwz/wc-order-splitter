<?php
/**
 * Read-only WooCommerce preflight for the replacement quantity split workflow.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Validates an order and produces a side-effect-free split plan.
 */
final class WCOS_V2_Split_Preflight {

	/**
	 * Validate a requested quantity split.
	 *
	 * @param WC_Order $order                Source order.
	 * @param array    $requested_quantities Item ID => split quantity.
	 * @return array|WP_Error Snapshot, plan and immutable operation fingerprint.
	 */
	public static function validate(WC_Order $order, array $requested_quantities) {
		$snapshot = WCOS_V2_Order_Snapshot::capture($order);

		if ('shop_order' !== $snapshot['order_type']) {
			return self::error('wcos_unsupported_order_type', __('Only standard WooCommerce orders can be split.', 'wc-order-splitter'));
		}

		$safe_statuses = (array) apply_filters(
			'wcos_v2_safe_quantity_split_statuses',
			array('pending', 'on-hold', 'processing', 'completed'),
			$order
		);

		if (!in_array($snapshot['status'], $safe_statuses, true)) {
			return self::error('wcos_unsupported_order_status', __('This order status is not supported by the safe quantity split workflow.', 'wc-order-splitter'));
		}

		$configured_statuses = (array) get_option('order_splitter_status_allowed', array());

		if (!in_array('wc-' . $snapshot['status'], $configured_statuses, true)) {
			return self::error('wcos_status_not_enabled', __('This order status is not enabled in the Order Splitter settings.', 'wc-order-splitter'));
		}

		if ($snapshot['has_refunds']) {
			return self::error('wcos_refunded_order_unsupported', __('Refunded or partially refunded orders cannot be split by this workflow.', 'wc-order-splitter'));
		}

		if (empty($snapshot['lines'])) {
			return self::error('wcos_order_has_no_lines', __('The order has no product lines to split.', 'wc-order-splitter'));
		}

		$normalized_request = array();

		foreach ($requested_quantities as $item_id => $quantity) {
			$item_id = absint($item_id);

			if (!$item_id || !isset($snapshot['lines'][$item_id])) {
				return self::error('wcos_unknown_order_item', __('The split request contains an item that does not belong to this order.', 'wc-order-splitter'));
			}

			if (!is_numeric($quantity) || (float) $quantity <= 0) {
				return self::error('wcos_invalid_split_quantity', __('Every requested split quantity must be greater than zero.', 'wc-order-splitter'));
			}

			$normalized_request[$item_id] = (string) $quantity;
		}

		if (empty($normalized_request)) {
			return self::error('wcos_empty_split_request', __('Select at least one product quantity to split.', 'wc-order-splitter'));
		}

		$precision = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
		$plan_lines = array();

		foreach ($snapshot['lines'] as $item_id => $line) {
			$tax_error = self::validate_line_tax_snapshot($line, $precision);

			if (is_wp_error($tax_error)) {
				return $tax_error;
			}

			$plan_line                   = $line;
			$plan_line['split_quantity'] = isset($normalized_request[$item_id]) ? $normalized_request[$item_id] : '0';
			$plan_lines[]                = $plan_line;
		}

		try {
			$plan = WCOS_V2_Split_Plan::build($plan_lines, $precision);
		} catch (InvalidArgumentException $exception) {
			return self::error('wcos_invalid_split_plan', $exception->getMessage());
		} catch (LogicException $exception) {
			return self::error('wcos_split_invariant_failed', $exception->getMessage());
		}

		$fingerprint_payload = array(
			'order_id'            => $snapshot['order_id'],
			'status'              => $snapshot['status'],
			'currency'            => $snapshot['currency'],
			'total'               => $snapshot['amounts']['total'],
			'order_stock_reduced' => $snapshot['order_stock_reduced'],
			'plan_fingerprint'    => $plan['fingerprint'],
		);
		$fingerprint_json = wp_json_encode($fingerprint_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		if (!is_string($fingerprint_json)) {
			return self::error('wcos_fingerprint_failed', __('The order snapshot could not be fingerprinted safely.', 'wc-order-splitter'));
		}

		return array(
			'snapshot'    => $snapshot,
			'plan'        => $plan,
			'fingerprint' => hash('sha256', $fingerprint_json),
		);
	}

	/**
	 * Confirm line tax totals match their historical per-rate arrays.
	 *
	 * @param array $line      Line snapshot.
	 * @param int   $precision Currency precision.
	 * @return true|WP_Error
	 */
	private static function validate_line_tax_snapshot(array $line, $precision) {
		$checks = array(
			'subtotal' => 'subtotal_tax',
			'total'    => 'total_tax',
		);

		foreach ($checks as $tax_context => $aggregate_field) {
			$rate_sum = 0;

			foreach ($line['taxes'][$tax_context] as $tax_amount) {
				$rate_sum += WCOS_V2_Amount_Allocator::to_minor_units($tax_amount, $precision);
			}

			$aggregate = WCOS_V2_Amount_Allocator::to_minor_units($line[$aggregate_field], $precision);

			if ($rate_sum !== $aggregate) {
				return self::error(
					'wcos_inconsistent_historical_tax',
					sprintf(
						/* translators: %d: WooCommerce order item ID. */
						__('Order item %d has inconsistent historical tax data and cannot be split automatically.', 'wc-order-splitter'),
						(int) $line['item_id']
					)
				);
			}
		}

		return true;
	}

	/**
	 * Create a stable preflight error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
