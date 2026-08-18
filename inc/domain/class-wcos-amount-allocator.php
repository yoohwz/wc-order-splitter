<?php

if (!defined('ABSPATH') && 'cli' !== PHP_SAPI) {
	exit;
}

/**
 * Deterministically allocates an amount while preserving the exact minor-unit sum.
 */
final class WCOS_Amount_Allocator {

	/**
	 * @param int|float|string $amount    Amount to allocate.
	 * @param array            $weights   Non-negative numeric weights keyed by destination.
	 * @param int              $precision Decimal precision used for the amount.
	 * @return array<string|int, string>
	 */
	public static function allocate($amount, array $weights, $precision = 2) {
		$precision = (int) $precision;
		if ($precision < 0 || $precision > 8) {
			throw new InvalidArgumentException('Precision must be between 0 and 8.');
		}

		if (empty($weights)) {
			throw new InvalidArgumentException('At least one allocation weight is required.');
		}

		$factor = 10 ** $precision;
		$units = (int) round(((float) $amount) * $factor, 0, PHP_ROUND_HALF_UP);
		$sign = $units < 0 ? -1 : 1;
		$absolute_units = abs($units);

		$total_weight = 0.0;
		$normalized = array();
		foreach ($weights as $key => $weight) {
			$weight = (float) $weight;
			if ($weight < 0) {
				throw new InvalidArgumentException('Allocation weights cannot be negative.');
			}
			$normalized[$key] = $weight;
			$total_weight += $weight;
		}

		if ($total_weight <= 0.0) {
			if (0 === $absolute_units) {
				return self::format_zero_allocations(array_keys($normalized), $precision);
			}
			throw new InvalidArgumentException('Allocation weights must contain a positive value for a non-zero amount.');
		}

		$base_units = array();
		$fractions = array();
		$allocated_units = 0;
		$position = 0;

		foreach ($normalized as $key => $weight) {
			$raw = ($absolute_units * $weight) / $total_weight;
			$base = (int) floor($raw);
			$base_units[$key] = $base;
			$fractions[] = array(
				'key' => $key,
				'fraction' => $raw - $base,
				'position' => $position++,
			);
			$allocated_units += $base;
		}

		usort(
			$fractions,
			static function($left, $right) {
				if ($left['fraction'] === $right['fraction']) {
					return $left['position'] <=> $right['position'];
				}
				return ($left['fraction'] > $right['fraction']) ? -1 : 1;
			}
		);

		$remainder = $absolute_units - $allocated_units;
		for ($index = 0; $index < $remainder; $index++) {
			$key = $fractions[$index % count($fractions)]['key'];
			$base_units[$key]++;
		}

		$result = array();
		foreach ($base_units as $key => $value) {
			$result[$key] = self::format_units($value * $sign, $precision);
		}

		return $result;
	}

	private static function format_zero_allocations(array $keys, $precision) {
		$result = array();
		foreach ($keys as $key) {
			$result[$key] = self::format_units(0, $precision);
		}
		return $result;
	}

	private static function format_units($units, $precision) {
		$factor = 10 ** $precision;
		return number_format($units / $factor, $precision, '.', '');
	}
}
