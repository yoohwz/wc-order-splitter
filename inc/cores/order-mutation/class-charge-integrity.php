<?php

defined('ABSPATH') || exit;

final class WC_Order_Splitter_Charge_Integrity {
	public static function normalize_after_split($source_order, $child_order_ids) {
		if (!$source_order instanceof WC_Order) {
			return;
		}

		$orders = array($source_order);
		foreach ((array) $child_order_ids as $order_id) {
			$order = wc_get_order(absint($order_id));
			if ($order instanceof WC_Order) {
				$orders[] = $order;
			}
		}

		self::normalize_coupon_allocations($orders, $source_order->get_id());
	}

	public static function normalize_after_return($order) {
		if (!$order instanceof WC_Order) {
			return;
		}

		$before = WC_Order_Splitter_Mutation_Support::order_totals($order);
		self::merge_returned_coupons($order);
		self::merge_returned_shipping($order);
		self::merge_returned_fees($order);

		// This pass only normalizes the persisted charge-row structure. The mutation
		// engine has already validated the historical monetary aggregates, so do not
		// re-price or re-derive them from current line data here.
		$order->save();

		$after = WC_Order_Splitter_Mutation_Support::order_totals($order);
		self::assert_totals_unchanged($before, $after);
	}

	private static function normalize_coupon_allocations($orders, $source_order_id) {
		$groups = array();
		foreach ($orders as $order) {
			foreach ($order->get_items('coupon') as $item) {
				$key = strtolower((string) $item->get_code());
				if (!isset($groups[$key])) {
					$groups[$key] = array('discount' => 0, 'discount_tax' => 0, 'template' => $item, 'items' => array());
				}
				$groups[$key]['discount'] += (float) $item->get_discount();
				$groups[$key]['discount_tax'] += (float) $item->get_discount_tax();
				if (!isset($groups[$key]['items'][$order->get_id()])) {
					$groups[$key]['items'][$order->get_id()] = array();
				}
				$groups[$key]['items'][$order->get_id()][] = $item;
			}
		}

		if (empty($groups)) {
			return;
		}

		$discount_weights = array();
		$tax_weights = array();
		foreach ($orders as $order) {
			$discount_weights[$order->get_id()] = max(0, (float) $order->get_discount_total());
			$tax_weights[$order->get_id()] = max(0, (float) $order->get_discount_tax());
		}

		foreach ($groups as $group) {
			$discount_parts = self::allocate_or_keep_on_source($group['discount'], $discount_weights, $source_order_id);
			$tax_parts = self::allocate_or_keep_on_source($group['discount_tax'], $tax_weights, $source_order_id);

			foreach ($orders as $order) {
				$order_id = $order->get_id();
				$items = isset($group['items'][$order_id]) ? $group['items'][$order_id] : array();
				$target = !empty($items) ? array_shift($items) : null;
				$discount = isset($discount_parts[$order_id]) ? $discount_parts[$order_id] : 0;
				$discount_tax = isset($tax_parts[$order_id]) ? $tax_parts[$order_id] : 0;

				if (!$target && (abs((float) $discount) > 0.000001 || abs((float) $discount_tax) > 0.000001)) {
					$target = self::clone_coupon_template($group['template']);
					$order->add_item($target);
				}

				if ($target instanceof WC_Order_Item_Coupon) {
					$target->set_discount($discount);
					$target->set_discount_tax($discount_tax);
					$target->save();
				}

				foreach ($items as $duplicate) {
					$order->remove_item($duplicate->get_id());
				}
				$order->save();
			}
		}
	}

	private static function allocate_or_keep_on_source($amount, $weights, $source_order_id) {
		$weights = array_filter((array) $weights, function($weight) {
			return (float) $weight > 0;
		});
		if (!empty($weights)) {
			return WC_Order_Splitter_Mutation_Support::allocate_scalar($amount, $weights);
		}
		return array($source_order_id => WC_Order_Splitter_Mutation_Support::decimal($amount));
	}

	private static function clone_coupon_template($source) {
		$item = new WC_Order_Item_Coupon();
		$item->set_code($source->get_code());
		WC_Order_Splitter_Mutation_Support::copy_item_meta($source, $item, false);
		$source_id = absint($source->get_meta(WC_Order_Splitter_Order_Item_Cloner::META_SOURCE_ITEM_ID, true));
		if ($source_id) {
			$item->update_meta_data(WC_Order_Splitter_Order_Item_Cloner::META_SOURCE_ITEM_ID, $source_id);
		}
		return $item;
	}

	private static function merge_returned_coupons($order) {
		foreach ($order->get_items('coupon') as $item_id => $item) {
			$source_item_id = absint($item->get_meta(WC_Order_Splitter_Order_Item_Cloner::META_SOURCE_ITEM_ID, true));
			if (!$source_item_id || $source_item_id === (int) $item_id) {
				continue;
			}
			$target = $order->get_item($source_item_id);
			if (!$target instanceof WC_Order_Item_Coupon || strtolower((string) $target->get_code()) !== strtolower((string) $item->get_code())) {
				continue;
			}

			$target->set_discount(WC_Order_Splitter_Mutation_Support::decimal((float) $target->get_discount() + (float) $item->get_discount()));
			$target->set_discount_tax(WC_Order_Splitter_Mutation_Support::decimal((float) $target->get_discount_tax() + (float) $item->get_discount_tax()));
			$target->save();
			$order->remove_item($item_id);
		}
	}

	private static function merge_returned_shipping($order) {
		foreach ($order->get_items('shipping') as $item_id => $item) {
			$source_item_id = absint($item->get_meta(WC_Order_Splitter_Order_Item_Cloner::META_SOURCE_ITEM_ID, true));
			if (!$source_item_id || $source_item_id === (int) $item_id) {
				continue;
			}
			$target = $order->get_item($source_item_id);
			if (!$target instanceof WC_Order_Item_Shipping) {
				continue;
			}
			if ((string) $target->get_method_id() !== (string) $item->get_method_id() || (string) $target->get_instance_id() !== (string) $item->get_instance_id()) {
				continue;
			}

			$target->set_total(WC_Order_Splitter_Mutation_Support::decimal((float) $target->get_total() + (float) $item->get_total()));
			$target->set_taxes(self::add_shipping_taxes($target->get_taxes(), $item->get_taxes()));
			$target->update_meta_data(WC_Order_Splitter_Mutation_Support::META_ITEMS_SUMMARY, self::items_summary($order));
			$target->save();
			$order->remove_item($item_id);
		}
	}

	private static function merge_returned_fees($order) {
		foreach ($order->get_items('fee') as $item_id => $item) {
			$source_item_id = absint($item->get_meta(WC_Order_Splitter_Order_Item_Cloner::META_SOURCE_ITEM_ID, true));
			if (!$source_item_id || $source_item_id === (int) $item_id) {
				continue;
			}
			$target = $order->get_item($source_item_id);
			if (!$target instanceof WC_Order_Item_Fee || (string) $target->get_name() !== (string) $item->get_name()) {
				continue;
			}

			$target->set_total(WC_Order_Splitter_Mutation_Support::decimal((float) $target->get_total() + (float) $item->get_total()));
			$target->set_total_tax(WC_Order_Splitter_Mutation_Support::decimal((float) $target->get_total_tax() + (float) $item->get_total_tax()));
			$target->set_taxes(WC_Order_Splitter_Mutation_Support::add_tax_arrays($target->get_taxes(), $item->get_taxes()));
			$target->save();
			$order->remove_item($item_id);
		}
	}

	private static function add_shipping_taxes($left, $right) {
		$result = array('total' => array());
		$rate_ids = array_unique(array_merge(
			array_keys(isset($left['total']) && is_array($left['total']) ? $left['total'] : array()),
			array_keys(isset($right['total']) && is_array($right['total']) ? $right['total'] : array())
		));
		foreach ($rate_ids as $rate_id) {
			$result['total'][$rate_id] = WC_Order_Splitter_Mutation_Support::decimal(
				(isset($left['total'][$rate_id]) ? $left['total'][$rate_id] : 0) +
				(isset($right['total'][$rate_id]) ? $right['total'][$rate_id] : 0)
			);
		}
		return $result;
	}

	private static function assert_totals_unchanged($before, $after) {
		$tolerance = pow(10, -WC_Order_Splitter_Mutation_Support::decimals()) / 2;
		foreach ($before as $field => $expected) {
			$actual = isset($after[$field]) ? $after[$field] : 0;
			if (abs((float) $expected - (float) $actual) > $tolerance) {
				throw new WC_Order_Splitter_Mutation_Exception(
					sprintf(__('Returning split charges changed the %s aggregate.', 'wc-order-splitter'), $field),
					0,
					array('field' => $field, 'before' => $expected, 'after' => $actual)
				);
			}
		}
	}

	private static function items_summary($order) {
		$parts = array();
		foreach ($order->get_items('line_item') as $item) {
			$parts[] = sprintf('%s × %s', $item->get_name(), wc_format_decimal($item->get_quantity(), 6));
		}
		return implode(', ', $parts);
	}
}
