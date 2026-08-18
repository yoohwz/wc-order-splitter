<?php

defined('ABSPATH') || exit;

/**
 * Rebuilds order aggregate props from preserved historical item amounts without
 * recalculating catalog prices or current tax rates.
 */
final class WCOS_Order_Totals_Rebuilder {

	public static function assert_consistent(WC_Order $order, $precision = null) {
		$precision = null === $precision ? wc_get_price_decimals() : (int) $precision;
		$calculated = self::calculate($order, $precision);
		$stored = array(
			'discount_total' => WCOS_Decimal::to_units($order->get_discount_total(), $precision),
			'discount_tax' => WCOS_Decimal::to_units($order->get_discount_tax(), $precision),
			'shipping_total' => WCOS_Decimal::to_units($order->get_shipping_total(), $precision),
			'shipping_tax' => WCOS_Decimal::to_units($order->get_shipping_tax(), $precision),
			'cart_tax' => WCOS_Decimal::to_units($order->get_cart_tax(), $precision),
			'total_tax' => WCOS_Decimal::to_units($order->get_total_tax(), $precision),
			'total' => WCOS_Decimal::to_units($order->get_total(), $precision),
		);

		foreach ($stored as $key => $value) {
			if ($value !== $calculated[$key]) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: inconsistent order total field. */
						__('The source order has an unsupported historical rounding difference in %s.', 'wc-order-splitter'),
						$key
					)
				);
			}
		}

		self::assert_item_tax_arrays($order, $precision);
		self::assert_tax_items($order, $calculated, $precision);
	}

	public static function rebuild(WC_Order $order, $precision = null) {
		$precision = null === $precision ? wc_get_price_decimals() : (int) $precision;
		$calculated = self::calculate($order, $precision);
		$result = $order->set_props(array(
			'discount_total' => WCOS_Decimal::from_units($calculated['discount_total'], $precision),
			'discount_tax' => WCOS_Decimal::from_units($calculated['discount_tax'], $precision),
			'shipping_total' => WCOS_Decimal::from_units($calculated['shipping_total'], $precision),
			'shipping_tax' => WCOS_Decimal::from_units($calculated['shipping_tax'], $precision),
			'cart_tax' => WCOS_Decimal::from_units($calculated['cart_tax'], $precision),
			'total_tax' => WCOS_Decimal::from_units($calculated['total_tax'], $precision),
			'total' => WCOS_Decimal::from_units($calculated['total'], $precision),
		));
		if (is_wp_error($result)) {
			throw new RuntimeException($result->get_error_message());
		}
		return $calculated;
	}

	public static function calculate(WC_Order $order, $precision = null) {
		$precision = null === $precision ? wc_get_price_decimals() : (int) $precision;
		$line_subtotal = 0;
		$line_total = 0;
		$line_subtotal_tax = 0;
		$line_total_tax = 0;
		$fee_total = 0;
		$fee_tax = 0;
		$shipping_total = 0;
		$shipping_tax = 0;

		foreach ($order->get_items('line_item') as $item) {
			$line_subtotal = self::safe_add($line_subtotal, WCOS_Decimal::to_units($item->get_subtotal(), $precision));
			$line_total = self::safe_add($line_total, WCOS_Decimal::to_units($item->get_total(), $precision));
			$line_subtotal_tax = self::safe_add($line_subtotal_tax, WCOS_Decimal::to_units($item->get_subtotal_tax(), $precision));
			$line_total_tax = self::safe_add($line_total_tax, WCOS_Decimal::to_units($item->get_total_tax(), $precision));
		}
		foreach ($order->get_items('fee') as $item) {
			$fee_total = self::safe_add($fee_total, WCOS_Decimal::to_units($item->get_total(), $precision));
			$fee_tax = self::safe_add($fee_tax, WCOS_Decimal::to_units($item->get_total_tax(), $precision));
		}
		foreach ($order->get_items('shipping') as $item) {
			$shipping_total = self::safe_add($shipping_total, WCOS_Decimal::to_units($item->get_total(), $precision));
			$shipping_tax = self::safe_add($shipping_tax, WCOS_Decimal::to_units($item->get_total_tax(), $precision));
		}

		$discount_total = $line_subtotal - $line_total;
		$discount_tax = $line_subtotal_tax - $line_total_tax;
		$cart_tax = self::safe_add($line_total_tax, $fee_tax);
		$total_tax = self::safe_add($cart_tax, $shipping_tax);
		$total = self::safe_add(self::safe_add(self::safe_add($line_total, $fee_total), $shipping_total), $total_tax);

		return array(
			'line_subtotal' => $line_subtotal,
			'line_total' => $line_total,
			'line_subtotal_tax' => $line_subtotal_tax,
			'line_total_tax' => $line_total_tax,
			'fee_total' => $fee_total,
			'fee_tax' => $fee_tax,
			'shipping_total' => $shipping_total,
			'shipping_tax' => $shipping_tax,
			'discount_total' => $discount_total,
			'discount_tax' => $discount_tax,
			'cart_tax' => $cart_tax,
			'total_tax' => $total_tax,
			'total' => $total,
		);
	}

	private static function assert_item_tax_arrays(WC_Order $order, $precision) {
		foreach ($order->get_items('line_item') as $item) {
			$taxes = $item->get_taxes();
			$subtotal = self::sum_tax_values(isset($taxes['subtotal']) ? (array) $taxes['subtotal'] : array(), $precision);
			$total = self::sum_tax_values(isset($taxes['total']) ? (array) $taxes['total'] : array(), $precision);
			if ($subtotal !== WCOS_Decimal::to_units($item->get_subtotal_tax(), $precision)
				|| $total !== WCOS_Decimal::to_units($item->get_total_tax(), $precision)) {
				throw new RuntimeException(__('A product line contains unsupported tax-array rounding.', 'wc-order-splitter'));
			}
		}

		foreach (array('fee', 'shipping') as $item_type) {
			foreach ($order->get_items($item_type) as $item) {
				$taxes = $item->get_taxes();
				$total = self::sum_tax_values(isset($taxes['total']) ? (array) $taxes['total'] : (array) $taxes, $precision);
				if ($total !== WCOS_Decimal::to_units($item->get_total_tax(), $precision)) {
					throw new RuntimeException(__('An order charge contains unsupported tax-array rounding.', 'wc-order-splitter'));
				}
			}
		}
	}

	private static function assert_tax_items(WC_Order $order, array $calculated, $precision) {
		$cart_tax = 0;
		$shipping_tax = 0;
		foreach ($order->get_items('tax') as $tax_item) {
			$cart_tax = self::safe_add($cart_tax, WCOS_Decimal::to_units($tax_item->get_tax_total(), $precision));
			$shipping_tax = self::safe_add($shipping_tax, WCOS_Decimal::to_units($tax_item->get_shipping_tax_total(), $precision));
		}
		if ($cart_tax !== $calculated['cart_tax'] || $shipping_tax !== $calculated['shipping_tax']) {
			throw new RuntimeException(__('Order tax rows do not match the historical item taxes.', 'wc-order-splitter'));
		}
	}

	private static function sum_tax_values(array $values, $precision) {
		$total = 0;
		foreach ($values as $value) {
			$total = self::safe_add($total, WCOS_Decimal::to_units($value, $precision));
		}
		return $total;
	}

	private static function safe_add($left, $right) {
		if ($right > 0 && $left > PHP_INT_MAX - $right) {
			throw new OverflowException('Order total exceeds the supported integer range.');
		}
		if ($right < 0 && $left < -PHP_INT_MAX - $right) {
			throw new OverflowException('Order total exceeds the supported integer range.');
		}
		return $left + $right;
	}
}
