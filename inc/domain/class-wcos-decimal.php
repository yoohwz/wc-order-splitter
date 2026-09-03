<?php

defined('ABSPATH') || exit;

/**
 * Converts decimal values to and from integer minor units without binary-float
 * arithmetic in the conservation path.
 */
final class WCOS_Decimal {

	const MAX_PRECISION = 8;

	/**
	 * @param int|float|string $value     Decimal value.
	 * @param int              $precision Number of decimal places.
	 * @return int
	 */
	public static function to_units($value, $precision) {
		$precision = self::validate_precision($precision);
		$value = self::stringify($value);

		if (!preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/D', $value, $matches)) {
			throw new InvalidArgumentException('Invalid decimal value.');
		}

		$negative = '-' === $matches[1];
		$whole = ltrim($matches[2], '0');
		$whole = '' === $whole ? '0' : $whole;
		$fraction = isset($matches[3]) ? $matches[3] : '';
		$kept_fraction = substr($fraction, 0, $precision);
		$kept_fraction = str_pad($kept_fraction, $precision, '0');
		$rounding_digit = strlen($fraction) > $precision ? (int) $fraction[$precision] : 0;

		$digits = ltrim($whole . $kept_fraction, '0');
		$digits = '' === $digits ? '0' : $digits;
		$maximum = (string) PHP_INT_MAX;

		if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
			throw new OverflowException('Decimal value exceeds the supported integer range.');
		}

		$units = (int) $digits;
		if ($rounding_digit >= 5) {
			if (PHP_INT_MAX === $units) {
				throw new OverflowException('Rounded decimal value exceeds the supported integer range.');
			}
			$units++;
		}

		if ($negative && 0 !== $units) {
			$units *= -1;
		}

		return $units;
	}

	/**
	 * @param int $units     Integer minor units.
	 * @param int $precision Number of decimal places.
	 * @return string
	 */
	public static function from_units($units, $precision) {
		$precision = self::validate_precision($precision);
		if (!is_int($units)) {
			throw new InvalidArgumentException('Decimal units must be an integer.');
		}
		if (PHP_INT_MIN === $units) {
			throw new OverflowException('The minimum platform integer cannot be formatted safely.');
		}

		$negative = $units < 0;
		$absolute = abs($units);
		$factor = self::factor($precision);
		$whole = intdiv($absolute, $factor);

		if (0 === $precision) {
			return ($negative ? '-' : '') . (string) $whole;
		}

		$fraction = str_pad((string) ($absolute % $factor), $precision, '0', STR_PAD_LEFT);
		return ($negative ? '-' : '') . $whole . '.' . $fraction;
	}

	/**
	 * @param int|float|string $value
	 * @param int              $precision
	 * @return string
	 */
	public static function normalize($value, $precision) {
		return self::from_units(self::to_units($value, $precision), $precision);
	}

	/**
	 * @param int $precision
	 * @return int
	 */
	public static function factor($precision) {
		$precision = self::validate_precision($precision);
		$factor = 1;
		for ($index = 0; $index < $precision; $index++) {
			$factor *= 10;
		}
		return $factor;
	}

	private static function validate_precision($precision) {
		$precision = (int) $precision;
		if ($precision < 0 || $precision > self::MAX_PRECISION) {
			throw new InvalidArgumentException('Precision must be between 0 and 8.');
		}
		return $precision;
	}

	private static function stringify($value) {
		if (is_int($value)) {
			return (string) $value;
		}

		if (is_float($value)) {
			if (is_nan($value) || is_infinite($value)) {
				throw new InvalidArgumentException('Decimal value must be finite.');
			}
			$value = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
			return '-0' === $value ? '0' : $value;
		}

		if (!is_string($value) && !is_numeric($value)) {
			throw new InvalidArgumentException('Decimal value must be numeric.');
		}

		$value = trim((string) $value);
		if ('' === $value) {
			throw new InvalidArgumentException('Decimal value cannot be empty.');
		}
		return $value;
	}
}
