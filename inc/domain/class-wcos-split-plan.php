<?php

defined('ABSPATH') || exit;

/**
 * Validates and normalizes a quantity split plan.
 */
final class WCOS_Split_Plan {

	public static function normalize(WC_Order $source, array $plan) {
		if (empty($plan)) {
			throw new InvalidArgumentException(__('At least one split child is required.', 'wc-order-splitter'));
		}

		$source_quantities = array();
		foreach ($source->get_items('line_item') as $item_id => $item) {
			$source_quantities[(int) $item_id] = WCOS_Decimal::to_units($item->get_quantity(), 6);
		}

		$normalized = array();
		$totals_by_item = array();
		foreach ($plan as $raw_child_key => $items) {
			$child_key = sanitize_key((string) $raw_child_key);
			if ('' === $child_key) {
				throw new InvalidArgumentException(__('Every split child requires a stable key.', 'wc-order-splitter'));
			}
			if (isset($normalized[$child_key])) {
				throw new InvalidArgumentException(__('Two split child keys normalize to the same value.', 'wc-order-splitter'));
			}
			if (!is_array($items) || empty($items)) {
				throw new InvalidArgumentException(__('Every split child must contain at least one line quantity.', 'wc-order-splitter'));
			}

			$normalized[$child_key] = array();
			foreach ($items as $raw_item_id => $quantity) {
				$item_id = absint($raw_item_id);
				if (!$item_id || !isset($source_quantities[$item_id])) {
					throw new InvalidArgumentException(__('The split plan references an item outside the source order.', 'wc-order-splitter'));
				}

				$quantity_units = WCOS_Decimal::to_units($quantity, 6);
				if ($quantity_units <= 0) {
					throw new InvalidArgumentException(__('Split quantities must be greater than zero.', 'wc-order-splitter'));
				}

				$normalized[$child_key][$item_id] = WCOS_Decimal::from_units($quantity_units, 6);
				$totals_by_item[$item_id] = isset($totals_by_item[$item_id])
					? self::safe_add($totals_by_item[$item_id], $quantity_units)
					: $quantity_units;
			}
			ksort($normalized[$child_key], SORT_NUMERIC);
		}

		foreach ($totals_by_item as $item_id => $split_units) {
			if ($split_units >= $source_quantities[$item_id]) {
				throw new InvalidArgumentException(__('The hardened split engine requires every source line to retain a positive quantity.', 'wc-order-splitter'));
			}
		}

		ksort($normalized, SORT_STRING);
		return $normalized;
	}

	public static function child_keys(array $normalized_plan) {
		return array_keys($normalized_plan);
	}

	private static function safe_add($left, $right) {
		if ($left > PHP_INT_MAX - $right) {
			throw new OverflowException('Split quantity exceeds the supported integer range.');
		}
		return $left + $right;
	}
}
