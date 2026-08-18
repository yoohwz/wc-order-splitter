<?php

defined('ABSPATH') || exit;

/**
 * Synchronizes tax rows from preserved item tax arrays without consulting
 * current tax tables.
 */
final class WCOS_Tax_Item_Synchronizer {

	public static function templates(WC_Order $source) {
		$templates = array();
		foreach ($source->get_items('tax') as $item) {
			$rate_id = (int) $item->get_rate_id();
			if (isset($templates[$rate_id])) {
				throw new RuntimeException(__('Duplicate tax rows for one rate are not supported by the hardened split engine.', 'wc-order-splitter'));
			}
			$templates[$rate_id] = $item;
		}
		return $templates;
	}

	public static function synchronize(WC_Order $order, array $templates, $precision = null, $preserve_existing_ids = false) {
		$precision = null === $precision ? wc_get_price_decimals() : (int) $precision;
		$totals = self::collect($order, $precision);
		$existing = array();
		foreach ($order->get_items('tax') as $item) {
			$rate_id = (int) $item->get_rate_id();
			if (isset($existing[$rate_id])) {
				throw new RuntimeException(__('Duplicate tax rows were found while synchronizing an order.', 'wc-order-splitter'));
			}
			$existing[$rate_id] = $item;
		}

		$rate_ids = array_values(array_unique(array_merge(array_keys($templates), array_keys($existing), array_keys($totals))));
		sort($rate_ids, SORT_NUMERIC);
		foreach ($rate_ids as $rate_id) {
			$cart_units = isset($totals[$rate_id]['cart']) ? $totals[$rate_id]['cart'] : 0;
			$shipping_units = isset($totals[$rate_id]['shipping']) ? $totals[$rate_id]['shipping'] : 0;

			if (isset($existing[$rate_id])) {
				$item = $existing[$rate_id];
			} elseif (0 !== $cart_units || 0 !== $shipping_units) {
				if (!isset($templates[$rate_id])) {
					throw new RuntimeException(__('A historical tax-rate template is missing from the source order.', 'wc-order-splitter'));
				}
				$item = WCOS_Order_Item_Cloner::tax($templates[$rate_id], WCOS_Order_Item_Meta_Policy::CONTEXT_SPLIT);
				$order->add_item($item);
			} else {
				continue;
			}

			$result = $item->set_props(array(
				'tax_total' => WCOS_Decimal::from_units($cart_units, $precision),
				'shipping_tax_total' => WCOS_Decimal::from_units($shipping_units, $precision),
			));
			if (is_wp_error($result)) {
				throw new RuntimeException($result->get_error_message());
			}
		}

		if (!$preserve_existing_ids) {
			foreach ($existing as $rate_id => $item) {
				if (!isset($totals[$rate_id]) || (0 === $totals[$rate_id]['cart'] && 0 === $totals[$rate_id]['shipping'])) {
					$order->remove_item($item->get_id());
				}
			}
		}
	}

	private static function collect(WC_Order $order, $precision) {
		$totals = array();
		foreach ($order->get_items('line_item') as $item) {
			self::collect_tax_array($totals, $item->get_taxes(), 'cart', $precision);
		}
		foreach ($order->get_items('fee') as $item) {
			self::collect_tax_array($totals, $item->get_taxes(), 'cart', $precision);
		}
		foreach ($order->get_items('shipping') as $item) {
			self::collect_tax_array($totals, $item->get_taxes(), 'shipping', $precision);
		}
		return $totals;
	}

	private static function collect_tax_array(array &$totals, array $taxes, $bucket, $precision) {
		$values = isset($taxes['total']) ? (array) $taxes['total'] : (array) $taxes;
		foreach ($values as $rate_id => $value) {
			$rate_id = (int) $rate_id;
			if (!isset($totals[$rate_id])) {
				$totals[$rate_id] = array('cart' => 0, 'shipping' => 0);
			}
			$units = WCOS_Decimal::to_units($value, $precision);
			if ($units > 0 && $totals[$rate_id][$bucket] > PHP_INT_MAX - $units) {
				throw new OverflowException('Tax total exceeds the supported integer range.');
			}
			if ($units < 0 && $totals[$rate_id][$bucket] < -PHP_INT_MAX - $units) {
				throw new OverflowException('Tax total exceeds the supported integer range.');
			}
			$totals[$rate_id][$bucket] += $units;
		}
	}
}
