<?php

defined('ABSPATH') || exit;

/**
 * Produces exact, PII-free snapshots used by mutation contracts and recovery.
 */
final class WCOS_Order_Contract_Snapshot {

	public static function aggregate(array $orders, $precision = null) {
		$precision = null === $precision ? wc_get_price_decimals() : (int) $precision;
		$money = array(
			'line_subtotal' => 0,
			'line_total' => 0,
			'line_subtotal_tax' => 0,
			'line_total_tax' => 0,
			'discount_total' => 0,
			'discount_tax' => 0,
			'fees_total' => 0,
			'shipping_total' => 0,
			'tax_total' => 0,
			'grand_total' => 0,
		);
		$stock_reduced = 0;
		$line_quantities = array();
		$currencies = array();
		$tax_by_rate = array();
		$line_tax_by_rate = array();

		foreach ($orders as $order) {
			if (!$order instanceof WC_Order) {
				throw new InvalidArgumentException('Order contract snapshots require WC_Order objects.');
			}

			$currencies[] = $order->get_currency();
			$money['discount_total'] = self::safe_add($money['discount_total'], WCOS_Decimal::to_units($order->get_discount_total(), $precision));
			$money['discount_tax'] = self::safe_add($money['discount_tax'], WCOS_Decimal::to_units($order->get_discount_tax(), $precision));
			$money['tax_total'] = self::safe_add($money['tax_total'], WCOS_Decimal::to_units($order->get_total_tax(), $precision));
			$money['grand_total'] = self::safe_add($money['grand_total'], WCOS_Decimal::to_units($order->get_total(), $precision));

			foreach ($order->get_items('line_item') as $item) {
				$identity = self::line_identity($item);
				$quantity = WCOS_Decimal::to_units($item->get_quantity(), 6);
				$line_quantities[$identity] = self::safe_add(isset($line_quantities[$identity]) ? $line_quantities[$identity] : 0, $quantity);
				$money['line_subtotal'] = self::safe_add($money['line_subtotal'], WCOS_Decimal::to_units($item->get_subtotal(), $precision));
				$money['line_total'] = self::safe_add($money['line_total'], WCOS_Decimal::to_units($item->get_total(), $precision));
				$money['line_subtotal_tax'] = self::safe_add($money['line_subtotal_tax'], WCOS_Decimal::to_units($item->get_subtotal_tax(), $precision));
				$money['line_total_tax'] = self::safe_add($money['line_total_tax'], WCOS_Decimal::to_units($item->get_total_tax(), $precision));

				$taxes = $item->get_taxes();
				foreach (array('subtotal', 'total') as $tax_kind) {
					foreach (isset($taxes[$tax_kind]) && is_array($taxes[$tax_kind]) ? $taxes[$tax_kind] : array() as $rate_id => $tax_amount) {
						$rate_key = (string) $rate_id;
						if (!isset($line_tax_by_rate[$rate_key])) {
							$line_tax_by_rate[$rate_key] = array('subtotal' => 0, 'total' => 0);
						}
						$line_tax_by_rate[$rate_key][$tax_kind] = self::safe_add(
							$line_tax_by_rate[$rate_key][$tax_kind],
							WCOS_Decimal::to_units($tax_amount, $precision)
						);
					}
				}

				$reduced = $item->get_meta('_reduced_stock', true);
				if ('' !== $reduced && is_numeric($reduced)) {
					$stock_reduced = self::safe_add($stock_reduced, WCOS_Decimal::to_units($reduced, 6));
				}
			}

			foreach ($order->get_items('fee') as $item) {
				$money['fees_total'] = self::safe_add($money['fees_total'], WCOS_Decimal::to_units($item->get_total(), $precision));
			}
			foreach ($order->get_items('shipping') as $item) {
				$money['shipping_total'] = self::safe_add($money['shipping_total'], WCOS_Decimal::to_units($item->get_total(), $precision));
			}
			foreach ($order->get_items('tax') as $item) {
				$rate_id = (string) absint($item->get_rate_id());
				if (!isset($tax_by_rate[$rate_id])) {
					$tax_by_rate[$rate_id] = array('cart' => 0, 'shipping' => 0);
				}
				$tax_by_rate[$rate_id]['cart'] = self::safe_add(
					$tax_by_rate[$rate_id]['cart'],
					WCOS_Decimal::to_units($item->get_tax_total(), $precision)
				);
				$tax_by_rate[$rate_id]['shipping'] = self::safe_add(
					$tax_by_rate[$rate_id]['shipping'],
					WCOS_Decimal::to_units($item->get_shipping_tax_total(), $precision)
				);
			}
		}

		$result = array();
		foreach ($money as $key => $units) {
			$result[$key] = WCOS_Decimal::from_units($units, $precision);
		}
		foreach ($line_quantities as $identity => $units) {
			$line_quantities[$identity] = WCOS_Decimal::from_units($units, 6);
		}
		ksort($line_quantities, SORT_STRING);

		$formatted_tax_by_rate = array();
		ksort($tax_by_rate, SORT_STRING);
		foreach ($tax_by_rate as $rate_id => $totals) {
			$formatted_tax_by_rate[$rate_id] = array(
				'cart' => WCOS_Decimal::from_units($totals['cart'], $precision),
				'shipping' => WCOS_Decimal::from_units($totals['shipping'], $precision),
			);
		}

		$formatted_line_tax_by_rate = array();
		ksort($line_tax_by_rate, SORT_STRING);
		foreach ($line_tax_by_rate as $rate_id => $totals) {
			$formatted_line_tax_by_rate[$rate_id] = array(
				'subtotal' => WCOS_Decimal::from_units($totals['subtotal'], $precision),
				'total' => WCOS_Decimal::from_units($totals['total'], $precision),
			);
		}

		$result['stock_reduced'] = WCOS_Decimal::from_units($stock_reduced, 6);
		$result['line_quantities'] = $line_quantities;
		$result['line_tax_by_rate'] = $formatted_line_tax_by_rate;
		$result['tax_by_rate'] = $formatted_tax_by_rate;
		$result['currencies'] = array_values(array_unique(array_map('strval', $currencies)));
		return $result;
	}

	public static function source_signature(WC_Order $order) {
		$state = array(
			'order_id' => $order->get_id(),
			'type' => $order->get_type(),
			'status' => $order->get_status(),
			'currency' => $order->get_currency(),
			'prices_include_tax' => $order->get_prices_include_tax(),
			'discount_total' => $order->get_discount_total(),
			'discount_tax' => $order->get_discount_tax(),
			'shipping_total' => $order->get_shipping_total(),
			'shipping_tax' => $order->get_shipping_tax(),
			'cart_tax' => $order->get_cart_tax(),
			'total_tax' => $order->get_total_tax(),
			'total' => $order->get_total(),
			'transaction_id' => $order->get_transaction_id(),
			'copy_context_signature' => WCOS_Order_Copy_Context::signature($order),
			'stock_reduced' => (bool) $order->get_data_store()->get_stock_reduced($order->get_id()),
			'items' => array(),
		);

		foreach (array('line_item', 'shipping', 'fee', 'tax', 'coupon') as $item_type) {
			foreach ($order->get_items($item_type) as $item_id => $item) {
				$state['items'][$item_type][(int) $item_id] = self::item_state($item);
			}
		}

		return WCOS_Mutation_Fingerprint::create('source_state', $order->get_id(), $state);
	}

	public static function product_stock(WC_Order $order) {
		$stock = array();
		foreach ($order->get_items('line_item') as $item) {
			$product = $item->get_product();
			if (!$product || !$product->managing_stock()) {
				continue;
			}

			$managed_id = method_exists($product, 'get_stock_managed_by_id') ? absint($product->get_stock_managed_by_id()) : absint($product->get_id());
			$managed_product = $managed_id ? wc_get_product($managed_id) : false;
			if (!$managed_product) {
				continue;
			}

			$quantity = $managed_product->get_stock_quantity();
			$stock[$managed_id] = null === $quantity ? null : WCOS_Decimal::normalize($quantity, 6);
		}
		ksort($stock, SORT_NUMERIC);
		return $stock;
	}

	public static function assert_product_stock_equal(array $before, array $after) {
		if ($before !== $after) {
			throw new RuntimeException(__('Physical product stock changed during an order-only mutation.', 'wc-order-splitter'));
		}
	}

	private static function item_state(WC_Order_Item $item) {
		$state = array(
			'id' => $item->get_id(),
			'type' => $item->get_type(),
			'name' => $item->get_name(),
			'meta' => WCOS_Order_Item_Meta_Policy::business_metadata($item),
		);

		if ($item instanceof WC_Order_Item_Product) {
			$state += array(
				'product_id' => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity' => WCOS_Decimal::normalize($item->get_quantity(), 6),
				'tax_class' => $item->get_tax_class(),
				'subtotal' => $item->get_subtotal(),
				'subtotal_tax' => $item->get_subtotal_tax(),
				'total' => $item->get_total(),
				'total_tax' => $item->get_total_tax(),
				'taxes' => $item->get_taxes(),
				'reduced_stock' => $item->get_meta('_reduced_stock', true),
			);
		} elseif ($item instanceof WC_Order_Item_Shipping) {
			$state += array(
				'method_id' => $item->get_method_id(),
				'instance_id' => $item->get_instance_id(),
				'total' => $item->get_total(),
				'total_tax' => $item->get_total_tax(),
				'taxes' => $item->get_taxes(),
			);
		} elseif ($item instanceof WC_Order_Item_Fee) {
			$state += array(
				'amount' => $item->get_amount(),
				'total' => $item->get_total(),
				'total_tax' => $item->get_total_tax(),
				'taxes' => $item->get_taxes(),
			);
		} elseif ($item instanceof WC_Order_Item_Tax) {
			$state += array(
				'rate_id' => $item->get_rate_id(),
				'tax_total' => $item->get_tax_total(),
				'shipping_tax_total' => $item->get_shipping_tax_total(),
			);
		} elseif ($item instanceof WC_Order_Item_Coupon) {
			$state += array(
				'code' => $item->get_code(),
				'discount' => $item->get_discount(),
				'discount_tax' => $item->get_discount_tax(),
			);
		}

		return $state;
	}

	private static function line_identity(WC_Order_Item_Product $item) {
		return WCOS_Line_Identity::from_values(
			$item->get_product_id(),
			$item->get_variation_id(),
			$item->get_tax_class(),
			WCOS_Order_Item_Meta_Policy::business_metadata($item)
		);
	}

	private static function safe_add($left, $right) {
		if ($right > 0 && $left > PHP_INT_MAX - $right) {
			throw new OverflowException('Order contract total exceeds the supported integer range.');
		}
		if ($right < 0 && $left < -PHP_INT_MAX - $right) {
			throw new OverflowException('Order contract total exceeds the supported integer range.');
		}
		return $left + $right;
	}
}
