<?php

if (!defined('ABSPATH') && 'cli' !== PHP_SAPI) {
	exit;
}

/**
 * Produces a stable business identity for an order line.
 */
final class WCOS_Line_Identity {

	public static function from_values($product_id, $variation_id, $tax_class, array $business_meta = array()) {
		$payload = array(
			'product_id' => (int) $product_id,
			'variation_id' => (int) $variation_id,
			'tax_class' => (string) $tax_class,
			'business_meta' => self::canonicalize($business_meta),
		);

		return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	private static function canonicalize($value) {
		if (!is_array($value)) {
			return $value;
		}

		if (self::is_list($value)) {
			$result = array();
			foreach ($value as $item) {
				$result[] = self::canonicalize($item);
			}
			return $result;
		}

		ksort($value, SORT_STRING);
		foreach ($value as $key => $item) {
			$value[$key] = self::canonicalize($item);
		}
		return $value;
	}

	private static function is_list(array $value) {
		$expected = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expected++) {
				return false;
			}
		}
		return true;
	}
}
