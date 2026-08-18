<?php
/**
 * Stock-gated authoritative quantity split service.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Applies stock consistency validation before and throughout execution.
 */
final class WCOS_V2_Safe_Quantity_Split_Service {

	/**
	 * Create a request-bound operation ID.
	 *
	 * @param int         $order_id             Source order ID.
	 * @param array       $requested_quantities Item ID => split quantity.
	 * @param string|null $nonce                 Optional UUID nonce.
	 * @return string|WP_Error
	 */
	public static function create_operation_id($order_id, array $requested_quantities, $nonce = null) {
		return WCOS_V2_Quantity_Split_Service::create_operation_id($order_id, $requested_quantities, $nonce);
	}

	/**
	 * Execute a stock-gated quantity split.
	 *
	 * @param int    $order_id             Source order ID.
	 * @param array  $requested_quantities Item ID => split quantity.
	 * @param string $operation_id         Request-bound operation ID.
	 * @return array|WP_Error
	 */
	public static function execute($order_id, array $requested_quantities, $operation_id) {
		$validated_operation_id = WCOS_V2_Quantity_Split_Service::validate_operation_id(
			$order_id,
			$requested_quantities,
			$operation_id
		);

		if (is_wp_error($validated_operation_id)) {
			return $validated_operation_id;
		}

		$order = wc_get_order(absint($order_id));

		if (!$order instanceof WC_Order) {
			return new WP_Error(
				'wcos_source_order_not_found',
				esc_html__('The source order was not found.', 'wc-order-splitter')
			);
		}

		$stock_context = WCOS_V2_Stock_Safety_Scope::validate($order);

		if (is_wp_error($stock_context)) {
			return $stock_context;
		}

		foreach ($stock_context['lines'] as $item_id => $line) {
			if (!$line['stock_managed'] && null !== $line['reduced_stock']) {
				return new WP_Error(
					'wcos_unmanaged_product_stock_marker',
					sprintf(
						/* translators: %d: WooCommerce order item ID. */
						esc_html__('Unmanaged-stock order item %d has an unexpected reduced-stock marker.', 'wc-order-splitter'),
						(int) $item_id
					)
				);
			}
		}

		$scope = new WCOS_V2_Stock_Safety_Scope($order->get_id());

		try {
			$result = WCOS_V2_Quantity_Split_Service::execute(
				$order->get_id(),
				$requested_quantities,
				$validated_operation_id
			);
		} finally {
			$scope->close();
		}

		if (is_wp_error($result)) {
			return $result;
		}

		$result['stock_safety'] = array(
			'validated'            => true,
			'order_stock_reduced'  => (bool) $stock_context['order_stock_reduced'],
			'validated_line_count' => count($stock_context['lines']),
		);

		return $result;
	}
}
