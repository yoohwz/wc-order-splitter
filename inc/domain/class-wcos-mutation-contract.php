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
	}

	private static function normalize_line_quantities(array $lines) {
		$normalized = array();
		foreach ($lines as $identity => $quantity) {
			$factor = 1000000;
			$normalized[(string) $identity] = (int) round(((float) $quantity) * $factor, 0, PHP_ROUND_HALF_UP);
		}
		ksort($normalized, SORT_STRING);
		return $normalized;
	}

	private static function assert_decimal_equal($before, $after, $precision, $label) {
		$factor = 10 ** $precision;
		$before_units = (int) round(((float) $before) * $factor, 0, PHP_ROUND_HALF_UP);
		$after_units = (int) round(((float) $after) * $factor, 0, PHP_ROUND_HALF_UP);

		if ($before_units !== $after_units) {
			throw new RuntimeException(sprintf('%s conservation failed: %s != %s.', $label, (string) $before, (string) $after));
		}
	}
}
