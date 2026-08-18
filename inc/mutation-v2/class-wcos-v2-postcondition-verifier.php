<?php
/**
 * Persisted order mutation postcondition verifier.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Verifies source and child orders against an execution specification after all
 * writes have been persisted and reloaded.
 */
final class WCOS_V2_Postcondition_Verifier {

	/**
	 * Verify the first safe one-child quantity split mutation.
	 *
	 * @param WC_Order $source        Reloaded source order.
	 * @param WC_Order $child         Reloaded child order.
	 * @param array    $snapshot      Immutable pre-operation source snapshot.
	 * @param array    $specification Execution specification.
	 * @param string   $operation_id  Operation ID.
	 * @return true|WP_Error
	 */
	public static function verify(WC_Order $source, WC_Order $child, array $snapshot, array $specification, $operation_id) {
		if ($source->get_id() !== (int) $specification['source_order_id'] || $snapshot['order_id'] !== $source->get_id()) {
			return self::error('wcos_verify_source_mismatch', __('The persisted source order does not match the mutation contract.', 'wc-order-splitter'));
		}

		if ($source->get_id() === $child->get_id()) {
			return self::error('wcos_verify_self_relation', __('The source and child order cannot be the same order.', 'wc-order-splitter'));
		}

		$precision = (int) $specification['precision'];

		$result = self::verify_order_amounts($source, $specification['source']['amounts'], $precision, 'source');
		if (is_wp_error($result)) {
			return $result;
		}

		$result = self::verify_order_amounts($child, $specification['child']['amounts'], $precision, 'child');
		if (is_wp_error($result)) {
			return $result;
		}

		foreach ($specification['source']['lines'] as $item_id => $line_spec) {
			if ('remove' === $line_spec['action']) {
				return self::error('wcos_verify_full_line_unsupported', __('The active verifier does not permit a fully removed source line.', 'wc-order-splitter'));
			}

			$item = $source->get_item(absint($item_id));

			if (!$item instanceof WC_Order_Item_Product) {
				return self::error('wcos_verify_source_line_missing', __('A persisted source product line is missing.', 'wc-order-splitter'));
			}

			$result = WCOS_V2_Order_Item_Mutator::verify_product($item, $line_spec, $precision);
			if (is_wp_error($result)) {
				return $result;
			}
		}

		$child_items_by_source = array();

		foreach ($child->get_items('line_item') as $child_item) {
			$source_item_id = absint($child_item->get_meta('_wcos_v2_source_item_id', true));

			if (!$source_item_id || isset($child_items_by_source[$source_item_id])) {
				return self::error('wcos_verify_child_line_mapping', __('A child product line has an invalid source mapping.', 'wc-order-splitter'));
			}

			$child_items_by_source[$source_item_id] = $child_item;
		}

		if (count($child_items_by_source) !== count($specification['child']['lines'])) {
			return self::error('wcos_verify_child_line_count', __('The child order contains an unexpected number of product lines.', 'wc-order-splitter'));
		}

		foreach ($specification['child']['lines'] as $source_item_id => $line_spec) {
			if (!isset($child_items_by_source[$source_item_id])) {
				return self::error('wcos_verify_child_line_missing', __('A required child product line is missing.', 'wc-order-splitter'));
			}

			$result = WCOS_V2_Order_Item_Mutator::verify_product($child_items_by_source[$source_item_id], $line_spec, $precision);
			if (is_wp_error($result)) {
				return $result;
			}
		}

		$result = self::verify_tax_items($source, $specification['source']['tax_items'], $precision, false);
		if (is_wp_error($result)) {
			return $result;
		}

		$result = self::verify_tax_items($child, $specification['child']['tax_items'], $precision, true);
		if (is_wp_error($result)) {
			return $result;
		}

		if (!empty($child->get_items('shipping')) || !empty($child->get_items('fee')) || !empty($child->get_items('coupon'))) {
			return self::error('wcos_verify_child_charge_ownership', __('Shipping, fees, or coupons were incorrectly assigned to the child order.', 'wc-order-splitter'));
		}

		$after_source_snapshot = WCOS_V2_Order_Snapshot::capture($source);

		foreach (array('shipping_items', 'fee_items', 'coupon_items') as $field) {
			if (self::canonical_json($snapshot[$field]) !== self::canonical_json($after_source_snapshot[$field])) {
				return self::error('wcos_verify_source_charge_changed', __('A source shipping, fee, or coupon item changed unexpectedly.', 'wc-order-splitter'));
			}
		}

		if ((string) $source->get_transaction_id() !== (string) $snapshot['transaction_id'] || '' !== (string) $child->get_transaction_id()) {
			return self::error('wcos_verify_settlement_ownership', __('Payment transaction ownership was not preserved on the source order.', 'wc-order-splitter'));
		}

		$result = self::verify_child_context($child, $specification, $operation_id);
		if (is_wp_error($result)) {
			return $result;
		}

		$source_stock = self::stock_reduced($source);
		$child_stock  = self::stock_reduced($child);

		if (is_wp_error($source_stock)) {
			return $source_stock;
		}
		if (is_wp_error($child_stock)) {
			return $child_stock;
		}

		if ((bool) $source_stock !== (bool) $specification['stock']['source_order_reduced']
			|| (bool) $child_stock !== (bool) $specification['stock']['child_order_reduced']
		) {
			return self::error('wcos_verify_order_stock_flag', __('An order-level stock marker does not match the split specification.', 'wc-order-splitter'));
		}

		$result = self::verify_aggregate_conservation($source, $child, $snapshot, $precision);
		if (is_wp_error($result)) {
			return $result;
		}

		$relation = WCOS_V2_Relation_Repository::find($source, $operation_id);

		if (is_wp_error($relation) || !is_array($relation) || 'staged' !== $relation['status'] || (int) $relation['child_order_id'] !== $child->get_id()) {
			return self::error('wcos_verify_staged_relation', __('The staged reciprocal order relationship is missing or invalid.', 'wc-order-splitter'));
		}

		return true;
	}

	/**
	 * Verify aggregate order amounts.
	 *
	 * @param WC_Order $order     Order.
	 * @param array    $expected  Expected amounts.
	 * @param int      $precision Currency precision.
	 * @param string   $scope     Error scope.
	 * @return true|WP_Error
	 */
	private static function verify_order_amounts(WC_Order $order, array $expected, $precision, $scope) {
		$actual = array(
			'subtotal'       => $order->get_subtotal(),
			'discount_total' => $order->get_discount_total(),
			'discount_tax'   => $order->get_discount_tax(),
			'shipping_total' => $order->get_shipping_total(),
			'shipping_tax'   => $order->get_shipping_tax(),
			'cart_tax'       => $order->get_cart_tax(),
			'total_tax'      => $order->get_total_tax(),
			'total'          => $order->get_total(),
		);

		foreach ($actual as $field => $value) {
			if (WCOS_V2_Amount_Allocator::to_minor_units($value, $precision) !== WCOS_V2_Amount_Allocator::to_minor_units($expected[$field], $precision)) {
				return self::error(
					'wcos_verify_' . sanitize_key($scope) . '_amount',
					sprintf(
						/* translators: 1: source or child scope, 2: amount field. */
						__('The persisted %1$s order has an unexpected %2$s amount.', 'wc-order-splitter'),
						$scope,
						$field
					)
				);
			}
		}

		return true;
	}

	/**
	 * Verify persisted tax items.
	 *
	 * @param WC_Order $order     Order.
	 * @param array    $expected  Expected tax specs, keyed by item ID or rate ID.
	 * @param int      $precision Currency precision.
	 * @param bool     $by_rate   Child specs are keyed by rate ID.
	 * @return true|WP_Error
	 */
	private static function verify_tax_items(WC_Order $order, array $expected, $precision, $by_rate) {
		$actual_items = $order->get_items('tax');

		if (count($actual_items) !== count($expected)) {
			return self::error('wcos_verify_tax_item_count', __('An order contains an unexpected number of tax items.', 'wc-order-splitter'));
		}

		$actual_by_rate = array();
		foreach ($actual_items as $item_id => $item) {
			$rate_id = (string) $item->get_rate_id();

			if (isset($actual_by_rate[$rate_id])) {
				return self::error('wcos_verify_duplicate_tax_rate', __('An order contains duplicate tax items for one rate.', 'wc-order-splitter'));
			}

			$actual_by_rate[$rate_id] = array('item_id' => (int) $item_id, 'item' => $item);
		}

		foreach ($expected as $key => $tax_spec) {
			$rate_id = (string) $tax_spec['rate_id'];

			if (!isset($actual_by_rate[$rate_id])) {
				return self::error('wcos_verify_tax_rate_missing', __('A required persisted tax rate is missing.', 'wc-order-splitter'));
			}

			$actual = $actual_by_rate[$rate_id];

			if (!$by_rate && (int) $key !== $actual['item_id']) {
				return self::error('wcos_verify_source_tax_identity', __('A source tax item ID changed unexpectedly.', 'wc-order-splitter'));
			}

			$item = $actual['item'];
			if (WCOS_V2_Amount_Allocator::to_minor_units($item->get_tax_total(), $precision) !== WCOS_V2_Amount_Allocator::to_minor_units($tax_spec['tax_total'], $precision)
				|| WCOS_V2_Amount_Allocator::to_minor_units($item->get_shipping_tax_total(), $precision) !== WCOS_V2_Amount_Allocator::to_minor_units($tax_spec['shipping_tax_total'], $precision)
			) {
				return self::error('wcos_verify_tax_amount', __('A persisted tax item has unexpected amounts.', 'wc-order-splitter'));
			}
		}

		return true;
	}

	/**
	 * Verify copied child context and operation markers.
	 *
	 * @param WC_Order $child         Child order.
	 * @param array    $specification Specification.
	 * @param string   $operation_id  Operation ID.
	 * @return true|WP_Error
	 */
	private static function verify_child_context(WC_Order $child, array $specification, $operation_id) {
		$context = $specification['child_context'];

		if ((int) $child->get_customer_id() !== (int) $specification['customer_id']
			|| (string) $child->get_currency() !== (string) $specification['currency']
			|| (bool) $child->get_prices_include_tax() !== (bool) $specification['prices_include_tax']
			|| (string) $child->get_status() !== (string) $specification['initial_child_status']
		) {
			return self::error('wcos_verify_child_core_context', __('The child order core context is incorrect.', 'wc-order-splitter'));
		}

		$checks = array(
			'payment_method'       => $child->get_payment_method(),
			'payment_method_title' => $child->get_payment_method_title(),
			'customer_note'        => $child->get_customer_note(),
			'customer_ip_address'  => $child->get_customer_ip_address(),
			'customer_user_agent'  => $child->get_customer_user_agent(),
			'created_via'          => $child->get_created_via(),
		);

		foreach ($checks as $field => $actual) {
			if ((string) $actual !== (string) $context[$field]) {
				return self::error('wcos_verify_child_copy_context', __('The child order copy context is incorrect.', 'wc-order-splitter'));
			}
		}

		if (self::normalize_map($child->get_address('billing')) !== self::normalize_map($context['billing_address'])
			|| self::normalize_map($child->get_address('shipping')) !== self::normalize_map($context['shipping_address'])
		) {
			return self::error('wcos_verify_child_address', __('The child order address context is incorrect.', 'wc-order-splitter'));
		}

		if ((string) $child->get_meta('_wcos_v2_operation_id', true) !== self::identifier($operation_id)
			|| (int) $child->get_meta('_wcos_v2_source_order_id', true) !== (int) $specification['source_order_id']
			|| (string) $child->get_meta('_wcos_v2_specification_fingerprint', true) !== (string) $specification['fingerprint']
			|| 'source_order' !== (string) $child->get_meta('_wcos_v2_settlement_owner', true)
		) {
			return self::error('wcos_verify_child_operation_meta', __('The child order operation metadata is incorrect.', 'wc-order-splitter'));
		}

		return true;
	}

	/**
	 * Verify aggregate source + child conservation against the source snapshot.
	 *
	 * @param WC_Order $source    Source order.
	 * @param WC_Order $child     Child order.
	 * @param array    $snapshot  Source snapshot.
	 * @param int      $precision Currency precision.
	 * @return true|WP_Error
	 */
	private static function verify_aggregate_conservation(WC_Order $source, WC_Order $child, array $snapshot, $precision) {
		$source_amounts = array(
			'subtotal'       => $source->get_subtotal(),
			'discount_total' => $source->get_discount_total(),
			'discount_tax'   => $source->get_discount_tax(),
			'shipping_total' => $source->get_shipping_total(),
			'shipping_tax'   => $source->get_shipping_tax(),
			'cart_tax'       => $source->get_cart_tax(),
			'total_tax'      => $source->get_total_tax(),
			'total'          => $source->get_total(),
		);
		$child_amounts = array(
			'subtotal'       => $child->get_subtotal(),
			'discount_total' => $child->get_discount_total(),
			'discount_tax'   => $child->get_discount_tax(),
			'shipping_total' => $child->get_shipping_total(),
			'shipping_tax'   => $child->get_shipping_tax(),
			'cart_tax'       => $child->get_cart_tax(),
			'total_tax'      => $child->get_total_tax(),
			'total'          => $child->get_total(),
		);

		foreach ($source_amounts as $field => $source_value) {
			$before = WCOS_V2_Amount_Allocator::to_minor_units($snapshot['amounts'][$field], $precision);
			$after  = WCOS_V2_Amount_Allocator::to_minor_units($source_value, $precision)
				+ WCOS_V2_Amount_Allocator::to_minor_units($child_amounts[$field], $precision);

			if ($before !== $after) {
				return self::error('wcos_verify_aggregate_conservation', __('Source and child order amounts do not conserve the original order.', 'wc-order-splitter'));
			}
		}

		return true;
	}

	/**
	 * Read the order-level stock flag.
	 *
	 * @param WC_Order $order Order.
	 * @return bool|WP_Error
	 */
	private static function stock_reduced(WC_Order $order) {
		$data_store = $order->get_data_store();

		if (!is_object($data_store) || !method_exists($data_store, 'get_stock_reduced')) {
			return self::error('wcos_verify_stock_store', __('The active WooCommerce order data store cannot verify stock state.', 'wc-order-splitter'));
		}

		return (bool) $data_store->get_stock_reduced($order->get_id());
	}

	/**
	 * Normalize an address map.
	 *
	 * @param mixed $value Map.
	 * @return array
	 */
	private static function normalize_map($value) {
		$value  = is_array($value) ? $value : array();
		$result = array();

		foreach ($value as $key => $field_value) {
			$result[(string) $key] = is_scalar($field_value) || null === $field_value ? (string) $field_value : '';
		}

		ksort($result, SORT_STRING);

		return $result;
	}

	/**
	 * Canonically encode a snapshot section.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function canonical_json($value) {
		$value = self::canonicalize($value);
		$json  = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		return is_string($json) ? $json : '';
	}

	/**
	 * Recursively sort associative arrays.
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

	/**
	 * Normalize an operation ID.
	 *
	 * @param mixed $value ID.
	 * @return string
	 */
	private static function identifier($value) {
		$value = strtolower(trim((string) $value));

		return preg_replace('/[^a-z0-9._:-]/', '', $value);
	}

	/**
	 * Create a stable verification error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
