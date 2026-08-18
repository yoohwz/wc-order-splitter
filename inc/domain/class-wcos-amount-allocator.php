<?php

if (!defined('ABSPATH') && 'cli' !== PHP_SAPI) {
	exit;
}

/**
 * Deterministically allocates an amount while preserving the exact minor-unit
 * sum. Monetary values and weights are converted to integers before division.
 */
final class WCOS_Amount_Allocator {

	const WEIGHT_PRECISION = 6;

	/**
	 * @param int|float|string $amount    Amount to allocate.
	 * @param array            $weights   Non-negative numeric weights keyed by destination.
	 * @param int              $precision Decimal precision used for the amount.
	 * @return array<string|int, string>
	 */
	public static function allocate($amount, array $weights, $precision = 2) {
		$precision = (int) $precision;
		if (empty($weights)) {
			throw new InvalidArgumentException('At least one allocation weight is required.');
		}

		$amount_units = WCOS_Decimal::to_units($amount, $precision);
		$sign = $amount_units < 0 ? -1 : 1;
		$absolute_units = abs($amount_units);
		$normalized = array();
		$total_weight = 0;

		foreach ($weights as $key => $weight) {
			$weight_units = WCOS_Decimal::to_units($weight, self::WEIGHT_PRECISION);
			if ($weight_units < 0) {
				throw new InvalidArgumentException('Allocation weights cannot be negative.');
			}
			if ($weight_units > PHP_INT_MAX - $total_weight) {
				throw new OverflowException('Allocation weight total exceeds the supported integer range.');
			}
			$normalized[$key] = $weight_units;
			$total_weight += $weight_units;
		}

		if ($total_weight <= 0) {
			if (0 === $absolute_units) {
				return self::format_zero_allocations(array_keys($normalized), $precision);
			}
			throw new InvalidArgumentException('Allocation weights must contain a positive value for a non-zero amount.');
		}

		$base_units = array();
		$fractions = array();
		$allocated_units = 0;

		foreach ($normalized as $key => $weight_units) {
			$product = self::safe_multiply($absolute_units, $weight_units);
			$base = intdiv($product, $total_weight);
			$base_units[$key] = $base;
			$fractions[] = array(
				'key' => $key,
				'remainder' => $product % $total_weight,
				'canonical_key' => self::canonical_key($key),
			);
			$allocated_units += $base;
		}

		usort(
			$fractions,
			static function($left, $right) {
				if ($left['remainder'] === $right['remainder']) {
					return strcmp($left['canonical_key'], $right['canonical_key']);
				}
				return $left['remainder'] > $right['remainder'] ? -1 : 1;
			}
		);

		$remainder = $absolute_units - $allocated_units;
		if ($remainder > count($fractions)) {
			throw new RuntimeException('Allocation remainder exceeded the largest-remainder bound.');
		}

		for ($index = 0; $index < $remainder; $index++) {
			$key = $fractions[$index]['key'];
			$base_units[$key]++;
		}

		/* Stable output ordering makes equivalent associative requests byte-stable. */
		uksort(
			$base_units,
			static function($left, $right) {
				return strcmp(self::canonical_key($left), self::canonical_key($right));
			}
		);

		$result = array();
		foreach ($base_units as $key => $value) {
			$result[$key] = WCOS_Decimal::from_units($value * $sign, $precision);
		}

		return $result;
	}

	private static function format_zero_allocations(array $keys, $precision) {
		usort(
			$keys,
			static function($left, $right) {
				return strcmp(self::canonical_key($left), self::canonical_key($right));
			}
		);
		$result = array();
		foreach ($keys as $key) {
			$result[$key] = WCOS_Decimal::from_units(0, $precision);
		}
		return $result;
	}

	private static function canonical_key($key) {
		return is_int($key)
			? 'i:' . str_pad((string) $key, 20, '0', STR_PAD_LEFT)
			: 's:' . (string) $key;
	}

	private static function safe_multiply($left, $right) {
		if (0 === $left || 0 === $right) {
			return 0;
		}
		if ($left > intdiv(PHP_INT_MAX, $right)) {
			throw new OverflowException('Allocation product exceeds the supported integer range.');
		}
		return $left * $right;
	}
}
