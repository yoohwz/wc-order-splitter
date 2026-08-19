<?php

defined('ABSPATH') || exit;

/**
 * Validates and normalizes a quantity split plan.
 */
final class WCOS_Split_Plan {

	public static function canonicalize_request(array $plan) {
		if (empty($plan)) {
			throw new InvalidArgumentException(__('At least one split child is required.', 'wc-order-splitter'));
		}

		$canonical = array();
		foreach ($plan as $raw_child_key => $items) {
			$child_key = sanitize_key((string) $raw_child_key);
			if ('' === $child_key) {
				throw new InvalidArgumentException(__('Every split child requires a stable key.', 'wc-order-splitter'));
			}
			if (isset($canonical[$child_key])) {
				throw new InvalidArgumentException(__('Two split child keys normalize to the same value.', 'wc-order-splitter'));
			}
			if (!is_array($items) || empty($items)) {
				throw new InvalidArgumentException(__('Every split child must contain at least one line quantity.', 'wc-order-splitter'));
			}

			$canonical[$child_key] = array();
			foreach ($items as $raw_item_id => $quantity) {
				$item_id = absint($raw_item_id);
				$quantity_units = WCOS_Decimal::to_units($quantity, 6);
				if (!$item_id || $quantity_units <= 0) {
					throw new InvalidArgumentException(__('Split item IDs and quantities must be positive.', 'wc-order-splitter'));
				}
				if (isset($canonical[$child_key][$item_id])) {
					throw new InvalidArgumentException(__('Two split item keys normalize to the same source item ID.', 'wc-order-splitter'));
				}
				$canonical[$child_key][$item_id] = WCOS_Decimal::from_units($quantity_units, 6);
			}
			ksort($canonical[$child_key], SORT_NUMERIC);
		}

		ksort($canonical, SORT_STRING);
		return $canonical;
	}

	public static function normalize(WC_Order $source, array $plan, $execution_policy = WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY) {
		$execution_policy = WCOS_Split_Execution_Policy::normalize($execution_policy);
		$whole_line_allowed = WCOS_Split_Execution_Policy::allows_whole_line_transfer($execution_policy);
		$normalized = self::canonicalize_request($plan);
		$source_quantities = array();
		foreach ($source->get_items('line_item') as $item_id => $item) {
			$quantity_units = WCOS_Decimal::to_units($item->get_quantity(), 6);
			if ($quantity_units <= 0) {
				throw new InvalidArgumentException(__('Every source line must have a positive quantity before Split.', 'wc-order-splitter'));
			}
			$source_quantities[(int) $item_id] = $quantity_units;
		}

		$totals_by_item = array();
		foreach ($normalized as $child_key => $items) {
			foreach ($items as $item_id => $quantity) {
				if (!isset($source_quantities[$item_id])) {
					throw new InvalidArgumentException(__('The split plan references an item outside the source order.', 'wc-order-splitter'));
				}
				$quantity_units = WCOS_Decimal::to_units($quantity, 6);
				$totals_by_item[$item_id] = isset($totals_by_item[$item_id])
					? self::safe_add($totals_by_item[$item_id], $quantity_units)
					: $quantity_units;
			}
		}

		$fully_moved_items = array();
		foreach ($totals_by_item as $item_id => $split_units) {
			if ($split_units > $source_quantities[$item_id]) {
				throw new InvalidArgumentException(__('The split plan allocates more than the source line quantity.', 'wc-order-splitter'));
			}
			if ($split_units === $source_quantities[$item_id]) {
				if (!$whole_line_allowed) {
					throw new InvalidArgumentException(__('The manual quantity Split policy requires every affected source line to retain a positive quantity.', 'wc-order-splitter'));
				}
				$fully_moved_items[$item_id] = true;
			}
		}

		if ($whole_line_allowed && !empty($fully_moved_items)) {
			$residual_line_count = count($source_quantities) - count($fully_moved_items);
			if ($residual_line_count <= 0) {
				throw new InvalidArgumentException(__('Whole-line Split must leave at least one product line on the source order.', 'wc-order-splitter'));
			}
		}

		return $normalized;
	}

	public static function fully_moved_item_ids(WC_Order $source, array $normalized_plan, $execution_policy = WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY) {
		if (!WCOS_Split_Execution_Policy::allows_whole_line_transfer($execution_policy)) {
			return array();
		}

		$source_quantities = array();
		foreach ($source->get_items('line_item') as $item_id => $item) {
			$source_quantities[(int) $item_id] = WCOS_Decimal::to_units($item->get_quantity(), 6);
		}
		$allocated = array();
		foreach ($normalized_plan as $items) {
			foreach ($items as $item_id => $quantity) {
				$quantity_units = WCOS_Decimal::to_units($quantity, 6);
				$allocated[$item_id] = isset($allocated[$item_id])
					? self::safe_add($allocated[$item_id], $quantity_units)
					: $quantity_units;
			}
		}

		$fully_moved = array();
		foreach ($allocated as $item_id => $quantity_units) {
			if (isset($source_quantities[$item_id]) && $quantity_units === $source_quantities[$item_id]) {
				$fully_moved[] = (int) $item_id;
			}
		}
		sort($fully_moved, SORT_NUMERIC);
		return $fully_moved;
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
