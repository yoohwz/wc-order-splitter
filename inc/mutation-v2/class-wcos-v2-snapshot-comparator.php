<?php
/**
 * Semantic source-order snapshot comparator for rollback verification.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Compares business state without depending on metadata serialization order.
 */
final class WCOS_V2_Snapshot_Comparator {

	/**
	 * Verify that a persisted order matches an immutable source snapshot.
	 *
	 * @param WC_Order $order    Persisted source order.
	 * @param array    $snapshot Immutable source snapshot.
	 * @return true|WP_Error
	 */
	public static function verify(WC_Order $order, array $snapshot) {
		$current   = WCOS_V2_Order_Snapshot::capture($order);
		$precision = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;

		$scalar_fields = array(
			'order_id',
			'order_type',
			'status',
			'currency',
			'prices_include_tax',
			'customer_id',
			'transaction_id',
			'has_refunds',
			'order_stock_reduced',
		);

		foreach ($scalar_fields as $field) {
			if (!array_key_exists($field, $snapshot) || $current[$field] !== $snapshot[$field]) {
				return self::error(
					'wcos_snapshot_scalar_mismatch',
					sprintf(
						/* translators: %s: source order field name. */
						__('The restored source order does not match its %s snapshot field.', 'wc-order-splitter'),
						$field
					),
					array('field' => $field)
				);
			}
		}

		foreach ((array) $snapshot['amounts'] as $field => $expected) {
			if (!array_key_exists($field, $current['amounts'])
				|| WCOS_V2_Amount_Allocator::to_minor_units($current['amounts'][$field], $precision)
				!== WCOS_V2_Amount_Allocator::to_minor_units($expected, $precision)
			) {
				return self::error(
					'wcos_snapshot_amount_mismatch',
					__('The restored source order totals do not match the immutable snapshot.', 'wc-order-splitter'),
					array('field' => $field)
				);
			}
		}

		$result = self::verify_lines((array) $current['lines'], (array) $snapshot['lines'], $precision);

		if (is_wp_error($result)) {
			return $result;
		}

		foreach (array('shipping_items', 'fee_items', 'coupon_items', 'tax_items') as $field) {
			if (self::canonical_json(self::normalize_generic_items((array) $current[$field]))
				!== self::canonical_json(self::normalize_generic_items((array) $snapshot[$field]))
			) {
				return self::error(
					'wcos_snapshot_order_item_mismatch',
					__('The restored source order charges or tax items do not match the immutable snapshot.', 'wc-order-splitter'),
					array('field' => $field)
				);
			}
		}

		return true;
	}

	/**
	 * Verify exact source line identity, values, taxes, and stock markers.
	 *
	 * @param array $current   Current lines.
	 * @param array $expected  Snapshot lines.
	 * @param int   $precision Currency precision.
	 * @return true|WP_Error
	 */
	private static function verify_lines(array $current, array $expected, $precision) {
		ksort($current, SORT_NUMERIC);
		ksort($expected, SORT_NUMERIC);

		if (array_keys($current) !== array_keys($expected)) {
			return self::error('wcos_snapshot_line_set_mismatch', __('The restored source order has a different product line set.', 'wc-order-splitter'));
		}

		foreach ($expected as $item_id => $expected_line) {
			$current_line = $current[$item_id];
			$identity_fields = array('name', 'product_id', 'variation_id', 'tax_class', 'identity');

			foreach ($identity_fields as $field) {
				if (!array_key_exists($field, $current_line) || (string) $current_line[$field] !== (string) $expected_line[$field]) {
					return self::error(
						'wcos_snapshot_line_identity_mismatch',
						__('A restored source product line does not match its commercial identity.', 'wc-order-splitter'),
						array('item_id' => (int) $item_id, 'field' => $field)
					);
				}
			}

			if (abs((float) $current_line['quantity'] - (float) $expected_line['quantity']) > 0.0000001) {
				return self::error(
					'wcos_snapshot_line_quantity_mismatch',
					__('A restored source product line has an unexpected quantity.', 'wc-order-splitter'),
					array('item_id' => (int) $item_id)
				);
			}

			foreach (array('subtotal', 'total', 'subtotal_tax', 'total_tax') as $field) {
				if (WCOS_V2_Amount_Allocator::to_minor_units($current_line[$field], $precision)
					!== WCOS_V2_Amount_Allocator::to_minor_units($expected_line[$field], $precision)
				) {
					return self::error(
						'wcos_snapshot_line_amount_mismatch',
						__('A restored source product line has an unexpected amount.', 'wc-order-splitter'),
						array('item_id' => (int) $item_id, 'field' => $field)
					);
				}
			}

			if (self::normalize_taxes((array) $current_line['taxes'], $precision)
				!== self::normalize_taxes((array) $expected_line['taxes'], $precision)
			) {
				return self::error(
					'wcos_snapshot_line_tax_mismatch',
					__('A restored source product line has unexpected historical tax allocations.', 'wc-order-splitter'),
					array('item_id' => (int) $item_id)
				);
			}

			$current_stock  = $current_line['reduced_stock'];
			$expected_stock = $expected_line['reduced_stock'];

			if (null === $current_stock || null === $expected_stock) {
				if ($current_stock !== $expected_stock) {
					return self::error(
						'wcos_snapshot_line_stock_mismatch',
						__('A restored source product line has an unexpected stock marker.', 'wc-order-splitter'),
						array('item_id' => (int) $item_id)
					);
				}
			} elseif (abs((float) $current_stock - (float) $expected_stock) > 0.0000001) {
				return self::error(
					'wcos_snapshot_line_stock_mismatch',
					__('A restored source product line has an unexpected stock marker.', 'wc-order-splitter'),
					array('item_id' => (int) $item_id)
				);
			}

			$current_meta  = WCOS_V2_Metadata_Policy::normalize_records((array) $current_line['metadata'], true);
			$expected_meta = WCOS_V2_Metadata_Policy::normalize_records((array) $expected_line['metadata'], true);

			if (self::canonical_json($current_meta) !== self::canonical_json($expected_meta)) {
				return self::error(
					'wcos_snapshot_line_metadata_mismatch',
					__('A restored source product line has unexpected commercial metadata.', 'wc-order-splitter'),
					array('item_id' => (int) $item_id)
				);
			}
		}

		return true;
	}

	/**
	 * Normalize generic order-item collections and metadata ordering.
	 *
	 * @param array $items Generic item snapshots.
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
	 * Normalize per-rate historical taxes to minor units.
	 *
	 * @param array $taxes     Taxes.
	 * @param int   $precision Currency precision.
	 * @return array
	 */
	private static function normalize_taxes(array $taxes, $precision) {
		$result = array('subtotal' => array(), 'total' => array());

		foreach (array('subtotal', 'total') as $context) {
			$values = isset($taxes[$context]) && is_array($taxes[$context]) ? $taxes[$context] : array();

			foreach ($values as $rate_id => $amount) {
				$result[$context][(string) $rate_id] = WCOS_V2_Amount_Allocator::to_minor_units($amount, $precision);
			}

			ksort($result[$context], SORT_NATURAL);
		}

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
	 * Create a stable snapshot mismatch error.
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
