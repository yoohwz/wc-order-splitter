<?php

if (!defined('ABSPATH') && 'cli' !== PHP_SAPI) {
	exit;
}

/**
 * Validates aggregate invariants before a mutation is considered complete.
 */
final class WCOS_Mutation_Contract {

	public static function assert_conserved(array $before, array $after, $precision = 2) {
		$precision = (int) $precision;
		$money_keys = array(
			'line_subtotal',
			'line_total',
			'discount_total',
			'discount_tax',
			'fees_total',
			'shipping_total',
			'tax_total',
			'grand_total',
		);

		foreach ($money_keys as $key) {
			self::assert_decimal_equal(
				isset($before[$key]) ? $before[$key] : 0,
				isset($after[$key]) ? $after[$key] : 0,
				$precision,
				$key
			);
		}

		self::assert_decimal_equal(
			isset($before['stock_reduced']) ? $before['stock_reduced'] : 0,
			isset($after['stock_reduced']) ? $after['stock_reduced'] : 0,
			6,
			'stock_reduced'
		);

		if (isset($before['line_quantities']) || isset($after['line_quantities'])) {
			$before_lines = self::normalize_line_quantities(
				isset($before['line_quantities']) ? (array) $before['line_quantities'] : array()
			);
			$after_lines = self::normalize_line_quantities(
				isset($after['line_quantities']) ? (array) $after['line_quantities'] : array()
			);

			if ($before_lines !== $after_lines) {
				throw new RuntimeException('Line quantity conservation failed.');
			}
		}

		if (isset($before['tax_by_rate']) || isset($after['tax_by_rate'])) {
			$before_tax = self::normalize_tax_by_rate(
				isset($before['tax_by_rate']) ? (array) $before['tax_by_rate'] : array(),
				$precision
			);
			$after_tax = self::normalize_tax_by_rate(
				isset($after['tax_by_rate']) ? (array) $after['tax_by_rate'] : array(),
				$precision
			);
			if ($before_tax !== $after_tax) {
				throw new RuntimeException('Per-rate historical tax conservation failed.');
			}
		}

		if (isset($before['currencies']) || isset($after['currencies'])) {
			$before_currencies = self::normalize_strings(isset($before['currencies']) ? (array) $before['currencies'] : array());
			$after_currencies = self::normalize_strings(isset($after['currencies']) ? (array) $after['currencies'] : array());
			if ($before_currencies !== $after_currencies) {
				throw new RuntimeException('Currency conservation failed.');
			}
		}
	}

	private static function normalize_line_quantities(array $lines) {
		$normalized = array();
		foreach ($lines as $identity => $quantity) {
			$normalized[(string) $identity] = WCOS_Decimal::to_units($quantity, 6);
		}
		ksort($normalized, SORT_STRING);
		return $normalized;
	}

	private static function normalize_tax_by_rate(array $taxes, $precision) {
		$normalized = array();
		foreach ($taxes as $rate_id => $totals) {
			$totals = is_array($totals) ? $totals : array();
			$key = (string) $rate_id;
			$normalized[$key] = array(
				'cart' => WCOS_Decimal::to_units(isset($totals['cart']) ? $totals['cart'] : 0, $precision),
				'shipping' => WCOS_Decimal::to_units(isset($totals['shipping']) ? $totals['shipping'] : 0, $precision),
			);
		}
		ksort($normalized, SORT_STRING);
		return $normalized;
	}

	private static function normalize_strings(array $values) {
		$values = array_values(array_unique(array_map('strval', $values)));
		sort($values, SORT_STRING);
		return $values;
	}

	private static function assert_decimal_equal($before, $after, $precision, $label) {
		$before_units = WCOS_Decimal::to_units($before, $precision);
		$after_units = WCOS_Decimal::to_units($after, $precision);

		if ($before_units !== $after_units) {
			throw new RuntimeException(sprintf('%s conservation failed: %s != %s.', $label, (string) $before, (string) $after));
		}
	}
}
