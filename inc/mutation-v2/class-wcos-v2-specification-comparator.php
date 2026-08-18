<?php
/**
 * Persisted source and child execution specification comparators.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Determines whether an interrupted operation is safe to resume or roll back.
 */
final class WCOS_V2_Specification_Comparator {

	/**
	 * Verify that a source order matches the planned mutated source state.
	 *
	 * @param WC_Order $source        Source order.
	 * @param array    $snapshot      Original source snapshot.
	 * @param array    $specification Execution specification.
	 * @return true|WP_Error
	 */
	public static function verify_source(WC_Order $source, array $snapshot, array $specification) {
		if ($source->get_id() !== (int) $specification['source_order_id']) {
			return self::error('wcos_source_spec_order_mismatch', __('The source order does not belong to this split specification.', 'wc-order-splitter'));
		}

		if ((string) $source->get_status() !== (string) $snapshot['status']
			|| (string) $source->get_currency() !== (string) $snapshot['currency']
			|| (int) $source->get_customer_id() !== (int) $snapshot['customer_id']
			|| (string) $source->get_transaction_id() !== (string) $snapshot['transaction_id']
			|| count($source->get_refunds()) > 0
		) {
			return self::error('wcos_source_spec_context_mismatch', __('The source order context changed after the split operation started.', 'wc-order-splitter'));
		}

		$precision = (int) $specification['precision'];
		$result    = self::verify_amounts($source, $specification['source']['amounts'], $precision, 'source');

		if (is_wp_error($result)) {
			return $result;
		}

		foreach ($specification['source']['lines'] as $item_id => $line_spec) {
			if ('remove' === $line_spec['action']) {
				return self::error('wcos_source_spec_full_line_unsupported', __('The active source comparator does not support removed source lines.', 'wc-order-splitter'));
			}

			$item = $source->get_item(absint($item_id));

			if (!$item instanceof WC_Order_Item_Product) {
				return self::error('wcos_source_spec_line_missing', __('A planned source product line is missing.', 'wc-order-splitter'));
			}

			$result = WCOS_V2_Order_Item_Mutator::verify_product($item, $line_spec, $precision);

			if (is_wp_error($result)) {
				return $result;
			}
		}

		$result = self::verify_tax_items($source, $specification['source']['tax_items'], $precision, false);

		if (is_wp_error($result)) {
			return $result;
		}

		$current_snapshot = WCOS_V2_Order_Snapshot::capture($source);

		foreach (array('shipping_items', 'fee_items', 'coupon_items') as $field) {
			if (self::canonical_json(self::normalize_generic_items((array) $current_snapshot[$field]))
				!== self::canonical_json(self::normalize_generic_items((array) $snapshot[$field]))
			) {
				return self::error('wcos_source_spec_charge_mismatch', __('Source shipping, fee, or coupon ownership changed unexpectedly.', 'wc-order-splitter'));
			}
		}

		$stock_flag = self::stock_flag($source);

		if (is_wp_error($stock_flag)) {
			return $stock_flag;
		}

		if ((bool) $stock_flag !== (bool) $specification['stock']['source_order_reduced']) {
			return self::error('wcos_source_spec_stock_flag_mismatch', __('The source order stock state does not match the split specification.', 'wc-order-splitter'));
		}

		return true;
	}

	/**
	 * Verify that a child order is still the untouched target defined by a spec.
	 *
	 * @param WC_Order $child         Child order.
	 * @param array    $specification Execution specification.
	 * @param string   $operation_id  Operation ID.
	 * @return true|WP_Error
	 */
	public static function verify_child(WC_Order $child, array $specification, $operation_id) {
		$context   = (array) $specification['child_context'];
		$precision = (int) $specification['precision'];

		if ((string) $child->get_status() !== (string) $specification['initial_child_status']
			|| (string) $child->get_currency() !== (string) $specification['currency']
			|| (bool) $child->get_prices_include_tax() !== (bool) $specification['prices_include_tax']
			|| (int) $child->get_customer_id() !== (int) $specification['customer_id']
			|| '' !== (string) $child->get_transaction_id()
			|| count($child->get_refunds()) > 0
		) {
			return self::error('wcos_child_spec_context_mismatch', __('The split child order changed after it was created.', 'wc-order-splitter'));
		}

		$copy_checks = array(
			'payment_method'       => $child->get_payment_method(),
			'payment_method_title' => $child->get_payment_method_title(),
			'customer_note'        => $child->get_customer_note(),
			'customer_ip_address'  => $child->get_customer_ip_address(),
			'customer_user_agent'  => $child->get_customer_user_agent(),
			'created_via'          => $child->get_created_via(),
		);

		foreach ($copy_checks as $field => $actual) {
			if (!array_key_exists($field, $context) || (string) $actual !== (string) $context[$field]) {
				return self::error('wcos_child_spec_copy_context_mismatch', __('The split child copy context changed after creation.', 'wc-order-splitter'));
			}
		}

		if (self::normalize_map($child->get_address('billing')) !== self::normalize_map($context['billing_address'])
			|| self::normalize_map($child->get_address('shipping')) !== self::normalize_map($context['shipping_address'])
		) {
			return self::error('wcos_child_spec_address_mismatch', __('The split child addresses changed after creation.', 'wc-order-splitter'));
		}

		if ((string) $child->get_meta('_wcos_v2_operation_id', true) !== self::identifier($operation_id)
			|| (int) $child->get_meta('_wcos_v2_source_order_id', true) !== (int) $specification['source_order_id']
			|| (string) $child->get_meta('_wcos_v2_specification_fingerprint', true) !== (string) $specification['fingerprint']
			|| 'source_order' !== (string) $child->get_meta('_wcos_v2_settlement_owner', true)
		) {
			return self::error('wcos_child_spec_operation_mismatch', __('The split child operation metadata changed after creation.', 'wc-order-splitter'));
		}

		$result = self::verify_amounts($child, $specification['child']['amounts'], $precision, 'child');

		if (is_wp_error($result)) {
			return $result;
		}

		$children_by_source = array();

		foreach ($child->get_items('line_item') as $item) {
			$source_item_id = absint($item->get_meta('_wcos_v2_source_item_id', true));

			if (!$source_item_id || isset($children_by_source[$source_item_id])) {
				return self::error('wcos_child_spec_line_mapping_mismatch', __('The split child product-line mapping is invalid.', 'wc-order-splitter'));
			}

			$children_by_source[$source_item_id] = $item;
		}

		if (count($children_by_source) !== count($specification['child']['lines'])) {
			return self::error('wcos_child_spec_line_count_mismatch', __('The split child contains an unexpected number of product lines.', 'wc-order-splitter'));
		}

		foreach ($specification['child']['lines'] as $source_item_id => $line_spec) {
			if (!isset($children_by_source[$source_item_id])) {
				return self::error('wcos_child_spec_line_missing', __('A planned split child product line is missing.', 'wc-order-splitter'));
			}

			$result = WCOS_V2_Order_Item_Mutator::verify_product($children_by_source[$source_item_id], $line_spec, $precision);

			if (is_wp_error($result)) {
				return $result;
			}
		}

		$result = self::verify_tax_items($child, $specification['child']['tax_items'], $precision, true);

		if (is_wp_error($result)) {
			return $result;
		}

		if (!empty($child->get_items('shipping')) || !empty($child->get_items('fee')) || !empty($child->get_items('coupon'))) {
			return self::error('wcos_child_spec_charge_mismatch', __('The split child acquired shipping, fees, or coupons after creation.', 'wc-order-splitter'));
		}

		$stock_flag = self::stock_flag($child);

		if (is_wp_error($stock_flag)) {
			return $stock_flag;
		}

		if ((bool) $stock_flag !== (bool) $specification['stock']['child_order_reduced']) {
			return self::error('wcos_child_spec_stock_flag_mismatch', __('The split child stock state changed after creation.', 'wc-order-splitter'));
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
	private static function verify_amounts(WC_Order $order, array $expected, $precision, $scope) {
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
			if (!array_key_exists($field, $expected)
				|| WCOS_V2_Amount_Allocator::to_minor_units($value, $precision)
				!== WCOS_V2_Amount_Allocator::to_minor_units($expected[$field], $precision)
			) {
				return self::error(
					'wcos_' . sanitize_key($scope) . '_spec_amount_mismatch',
					__('A persisted order amount does not match the execution specification.', 'wc-order-splitter'),
					array('scope' => $scope, 'field' => $field)
				);
			}
		}

		return true;
	}

	/**
	 * Verify tax items by item ID for source or rate ID for child.
	 *
	 * @param WC_Order $order     Order.
	 * @param array    $expected  Tax specifications.
	 * @param int      $precision Currency precision.
	 * @param bool     $by_rate   Whether expected keys are rate IDs.
	 * @return true|WP_Error
	 */
	private static function verify_tax_items(WC_Order $order, array $expected, $precision, $by_rate) {
		$actual_items = $order->get_items('tax');

		if (count($actual_items) !== count($expected)) {
			return self::error('wcos_spec_tax_count_mismatch', __('A persisted order has an unexpected number of tax items.', 'wc-order-splitter'));
		}

		$actual_by_rate = array();

		foreach ($actual_items as $item_id => $item) {
			$rate_id = (string) $item->get_rate_id();

			if (isset($actual_by_rate[$rate_id])) {
				return self::error('wcos_spec_duplicate_tax_rate', __('A persisted order has duplicate tax items for one rate.', 'wc-order-splitter'));
			}

			$actual_by_rate[$rate_id] = array('item_id' => (int) $item_id, 'item' => $item);
		}

		foreach ($expected as $key => $tax_spec) {
			$rate_id = (string) $tax_spec['rate_id'];

			if (!isset($actual_by_rate[$rate_id])) {
				return self::error('wcos_spec_tax_rate_missing', __('A required persisted tax rate is missing.', 'wc-order-splitter'));
			}

			$actual = $actual_by_rate[$rate_id];

			if (!$by_rate && (int) $key !== $actual['item_id']) {
				return self::error('wcos_spec_source_tax_id_mismatch', __('A source tax item ID changed during the operation.', 'wc-order-splitter'));
			}

			if (WCOS_V2_Amount_Allocator::to_minor_units($actual['item']->get_tax_total(), $precision)
				!== WCOS_V2_Amount_Allocator::to_minor_units($tax_spec['tax_total'], $precision)
				|| WCOS_V2_Amount_Allocator::to_minor_units($actual['item']->get_shipping_tax_total(), $precision)
				!== WCOS_V2_Amount_Allocator::to_minor_units($tax_spec['shipping_tax_total'], $precision)
			) {
				return self::error('wcos_spec_tax_amount_mismatch', __('A persisted tax item does not match the execution specification.', 'wc-order-splitter'));
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
	private static function stock_flag(WC_Order $order) {
		$data_store = $order->get_data_store();

		if (!is_object($data_store) || !method_exists($data_store, 'get_stock_reduced')) {
			return self::error('wcos_spec_stock_store_unreadable', __('The active WooCommerce order store cannot read stock state.', 'wc-order-splitter'));
		}

		return (bool) $data_store->get_stock_reduced($order->get_id());
	}

	/**
	 * Normalize generic order items for source ownership comparison.
	 *
	 * @param array $items Item snapshots.
	 * @return array
	 */
	private static function normalize_generic_items(array $items) {
		$result = array();

		foreach ($items as $item_id => $item) {
			$data = isset($item['data']) && is_array($item['data']) ? $item['data'] : array();
			unset($data['id'], $data['order_id']);

			$result[(int) $item_id] = array(
				'type'     => isset($item['type']) ? (string) $item['type'] : '',
				'data'     => self::canonicalize($data),
				'metadata' => WCOS_V2_Metadata_Policy::normalize_records(
					isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : array(),
					false
				),
			);
		}

		ksort($result, SORT_NUMERIC);

		return $result;
	}

	/**
	 * Normalize an address map.
	 *
	 * @param mixed $value Address data.
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
	 * Canonically encode a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function canonical_json($value) {
		$json = wp_json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

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
	 * Create a stable comparator error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $data    Mismatch data.
	 * @return WP_Error
	 */
	private static function error($code, $message, array $data = array()) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message), $data);
	}
}
