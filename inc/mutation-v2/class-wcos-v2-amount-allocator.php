<?php
/**
 * Deterministic amount allocation utilities for order mutations.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || (PHP_SAPI === 'cli') || exit;

/**
 * Allocates decimal amounts by weight while preserving the exact minor-unit sum.
 */
final class WCOS_V2_Amount_Allocator {

	/**
	 * Allocate an amount across weighted buckets.
	 *
	 * The largest-remainder method is used so the returned allocations always add
	 * up to the original amount at the requested precision. Input array keys are
	 * preserved and provide the deterministic tie-break order.
	 *
	 * @param int|float|string $amount    Decimal amount.
	 * @param array            $weights   Non-negative numeric weights.
	 * @param int              $precision Decimal precision.
	 * @return array
	 * @throws InvalidArgumentException When the allocation cannot be performed.
	 */
	public static function allocate($amount, array $weights, $precision = 2) {
		$precision = (int) $precision;

		if ($precision < 0 || $precision > 6) {
			throw new InvalidArgumentException('Precision must be between 0 and 6.');
		}

		if (empty($weights)) {
			throw new InvalidArgumentException('At least one allocation weight is required.');
		}

		$normalized_weights = array();
		$total_weight       = 0.0;

		foreach ($weights as $key => $weight) {
			if (!is_numeric($weight) || (float) $weight < 0) {
				throw new InvalidArgumentException('Allocation weights must be non-negative numbers.');
			}

			$normalized_weights[$key] = (float) $weight;
			$total_weight             += (float) $weight;
		}

		if ($total_weight <= 0) {
			throw new InvalidArgumentException('The total allocation weight must be greater than zero.');
		}

		$minor_units = self::to_minor_units($amount, $precision);
		$sign        = $minor_units < 0 ? -1 : 1;
		$remaining   = abs($minor_units);
		$allocated   = 0;
		$buckets     = array();
		$position    = 0;

		foreach ($normalized_weights as $key => $weight) {
			$raw_share  = $remaining * ($weight / $total_weight);
			$base_share = (int) floor($raw_share);

			$buckets[$key] = array(
				'minor'     => $base_share,
				'remainder' => $raw_share - $base_share,
				'position'  => $position,
			);

			$allocated += $base_share;
			++$position;
		}

		$units_left = $remaining - $allocated;
		$order      = array_keys($buckets);

		usort(
			$order,
			static function ($left, $right) use ($buckets) {
				if ($buckets[$left]['remainder'] === $buckets[$right]['remainder']) {
					return $buckets[$left]['position'] <=> $buckets[$right]['position'];
				}

				return ($buckets[$left]['remainder'] > $buckets[$right]['remainder']) ? -1 : 1;
			}
		);

		for ($index = 0; $index < $units_left; ++$index) {
			$key = $order[$index % count($order)];
			++$buckets[$key]['minor'];
		}

		$result = array();

		foreach ($buckets as $key => $bucket) {
			$result[$key] = self::from_minor_units($bucket['minor'] * $sign, $precision);
		}

		return $result;
	}

	/**
	 * Convert a decimal amount into integer minor units.
	 *
	 * @param int|float|string $amount    Decimal amount.
	 * @param int              $precision Decimal precision.
	 * @return int
	 */
	public static function to_minor_units($amount, $precision = 2) {
		if (!is_numeric($amount)) {
			throw new InvalidArgumentException('Amount must be numeric.');
		}

		$factor = 10 ** (int) $precision;

		return (int) round((float) $amount * $factor, 0, PHP_ROUND_HALF_UP);
	}

	/**
	 * Convert integer minor units into a normalized decimal string.
	 *
	 * @param int $minor_units Integer minor units.
	 * @param int $precision   Decimal precision.
	 * @return string
	 */
	public static function from_minor_units($minor_units, $precision = 2) {
		$precision = (int) $precision;
		$factor    = 10 ** $precision;

		return number_format(((int) $minor_units) / $factor, $precision, '.', '');
	}
}
