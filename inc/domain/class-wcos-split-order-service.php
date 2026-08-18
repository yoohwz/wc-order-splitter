<?php

defined('ABSPATH') || exit;

/**
 * Shared split mutation engine.
 *
 * Initial safety policy:
 * - shipping and fee lines remain on the original order;
 * - coupon/refunded orders fail closed until an explicit allocation policy exists;
 * - historical line amounts/taxes are allocated without recalculating current rates;
 * - reduced-stock markers move with quantities without changing physical stock.
 */
final class WCOS_Split_Order_Service {

	const RELATION_PARENT_META = '_wcos_parent_order_id';
	const RELATION_CHILDREN_META = '_wcos_child_order_ids';

	public function split(WC_Order $source, array $plan, $operation_id) {
		$source_id = $source->get_id();
		$operation_id = sanitize_key($operation_id);
		if ('' === $operation_id) {
			throw new InvalidArgumentException(__('A split operation ID is required.', 'wc-order-splitter'));
		}

		$existing = WCOS_Operation_Journal::get($source, $operation_id);
		if (is_array($existing)) {
			if (isset($existing['status']) && 'completed' === $existing['status']) {
				return $this->load_orders(isset($existing['context']['target_order_ids']) ? $existing['context']['target_order_ids'] : array());
			}
			throw new RuntimeException(__('This split operation ID has already been used and did not complete successfully.', 'wc-order-splitter'));
		}

		$this->assert_supported_source($source);
		$normalized_plan = $this->validate_and_normalize_plan($source, $plan);

		if (!WCOS_Operation_Lock::acquire($source_id, $operation_id)) {
			throw new RuntimeException(__('Another order mutation is already in progress for this order.', 'wc-order-splitter'));
		}

		$children = array();
		$source_persisted = false;
		WCOS_Operation_Journal::start($source, $operation_id, 'split', array('plan' => $normalized_plan));

		try {
			$before = $this->snapshot(array($source));
			$source_stock_reduced = (bool) $source->get_data_store()->get_stock_reduced($source_id);
			$precision = wc_get_price_decimals();
			$tax_templates = $this->capture_tax_templates($source);

			foreach (array_keys($normalized_plan) as $child_key) {
				$child = wc_create_order();
				if (is_wp_error($child)) {
					throw new RuntimeException($child->get_error_message());
				}
				$this->copy_order_context($source, $child);
				$child->update_meta_data(self::RELATION_PARENT_META, $source_id);
				$child->update_meta_data('yoos_original_order', $source_id);
				$children[$child_key] = $child;
			}

			foreach ($source->get_items('line_item') as $item_id => $source_item) {
				$this->allocate_source_line($source, $source_item, $item_id, $normalized_plan, $children, $precision);
			}

			$this->rebuild_tax_items($source, $tax_templates);
			$this->recompute_order_totals($source, $precision);
			foreach ($children as $child) {
				$this->rebuild_tax_items($child, $tax_templates);
				$this->recompute_order_totals($child, $precision);
			}

			WCOS_Mutation_Contract::assert_conserved(
				$before,
				$this->snapshot(array_merge(array($source), array_values($children))),
				$precision
			);

			/* Persist children first. Until source is saved, failure can be compensated safely. */
			foreach ($children as $child) {
				$child->save();
				if ($source_stock_reduced) {
					$child->get_data_store()->set_stock_reduced($child->get_id(), true);
				}
				$this->apply_source_status_without_stock_side_effect($source, $child);
			}

			$child_ids = array_values(array_map(static function($child) {
				return $child->get_id();
			}, $children));

			$current_children = (array) $source->get_meta(self::RELATION_CHILDREN_META, true);
			$source->update_meta_data(
				self::RELATION_CHILDREN_META,
				array_values(array_unique(array_merge(array_map('absint', $current_children), $child_ids)))
			);

			$legacy_children = array_filter(array_map('absint', explode(',', (string) $source->get_meta('yoos_splitted_order', true))));
			$source->update_meta_data('yoos_splitted_order', implode(',', array_values(array_unique(array_merge($legacy_children, $child_ids)))));
			$source->save();
			$source_persisted = true;

			if (!WCOS_Operation_Journal::complete($source, $operation_id, array('target_order_ids' => $child_ids))) {
				throw new RuntimeException(__('The split completed but its operation journal could not be finalized.', 'wc-order-splitter'));
			}

			return array_values($children);
		} catch (Throwable $throwable) {
			if (!$source_persisted) {
				foreach ($children as $child) {
					if ($child instanceof WC_Order && $child->get_id()) {
						$child->delete(true);
					}
				}
			}
			WCOS_Operation_Journal::fail($source, $operation_id, array(
				'error' => $throwable->getMessage(),
				'source_persisted' => $source_persisted,
			));
			throw $throwable;
		} finally {
			WCOS_Operation_Lock::release($source_id, $operation_id);
		}
	}

	private function assert_supported_source(WC_Order $source) {
		$supported_statuses = array('pending', 'on-hold', 'processing', 'completed');
		if (!in_array($source->get_status(), $supported_statuses, true)) {
			throw new RuntimeException(__('This order status is not yet supported by the hardened split engine.', 'wc-order-splitter'));
		}
		if (!empty($source->get_items('coupon'))) {
			throw new RuntimeException(__('Orders containing coupon lines are not yet supported by the hardened split engine.', 'wc-order-splitter'));
		}
		if ($source->get_total_refunded() != 0 || !empty($source->get_refunds())) {
			throw new RuntimeException(__('Refunded orders are not supported by the hardened split engine.', 'wc-order-splitter'));
		}
	}

	private function validate_and_normalize_plan(WC_Order $source, array $plan) {
		$source_quantities = array();
		$total_source = 0.0;
		foreach ($source->get_items('line_item') as $item_id => $item) {
			$source_quantities[(int) $item_id] = (float) $item->get_quantity();
			$total_source += (float) $item->get_quantity();
		}

		$normalized = array();
		$totals_by_item = array();
		$total_split = 0.0;
		foreach ($plan as $child_key => $items) {
			$child_key = sanitize_key((string) $child_key);
			if ('' === $child_key || !is_array($items)) {
				continue;
			}
			foreach ($items as $item_id => $quantity) {
				$item_id = absint($item_id);
				$quantity = (float) $quantity;
				if (!$item_id || $quantity <= 0) {
					continue;
				}
				if (!isset($source_quantities[$item_id])) {
					throw new InvalidArgumentException(__('The split plan contains an item that does not belong to the source order.', 'wc-order-splitter'));
				}
				$normalized[$child_key][$item_id] = $quantity;
				$totals_by_item[$item_id] = isset($totals_by_item[$item_id]) ? $totals_by_item[$item_id] + $quantity : $quantity;
				$total_split += $quantity;
			}
		}

		if (empty($normalized)) {
			throw new InvalidArgumentException(__('The split plan is empty.', 'wc-order-splitter'));
		}
		foreach ($totals_by_item as $item_id => $quantity) {
			if ($quantity > $source_quantities[$item_id]) {
				throw new InvalidArgumentException(__('A split quantity exceeds its source line quantity.', 'wc-order-splitter'));
			}
		}
		if ($total_split >= $total_source) {
			throw new InvalidArgumentException(__('The original order must retain at least one item quantity.', 'wc-order-splitter'));
		}
		return $normalized;
	}

	private function copy_order_context(WC_Order $source, WC_Order $child) {
		$child->set_props(array(
			'status' => 'pending',
			'customer_id' => $source->get_customer_id(),
			'currency' => $source->get_currency(),
			'prices_include_tax' => $source->get_prices_include_tax(),
			'payment_method' => $source->get_payment_method(),
			'payment_method_title' => $source->get_payment_method_title(),
			'customer_note' => $source->get_customer_note(),
			'created_via' => 'wc-order-splitter',
		));
		$child->set_address($source->get_address('billing'), 'billing');
		$child->set_address($source->get_address('shipping'), 'shipping');
	}

	private function allocate_source_line(WC_Order $source, WC_Order_Item_Product $source_item, $item_id, array $plan, array $children, $precision) {
		$source_quantity = (float) $source_item->get_quantity();
		$weights = array('original' => $source_quantity);
		foreach ($plan as $child_key => $items) {
			$quantity = isset($items[$item_id]) ? (float) $items[$item_id] : 0.0;
			$weights[$child_key] = $quantity;
			$weights['original'] -= $quantity;
		}

		$allocations = array();
		foreach (array('subtotal', 'subtotal_tax', 'total', 'total_tax') as $field) {
			$getter = 'get_' . $field;
			$allocations[$field] = WCOS_Amount_Allocator::allocate($source_item->{$getter}(), $weights, $precision);
		}

		$taxes = (array) $source_item->get_taxes();
		$tax_allocations = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			foreach ((array) (isset($taxes[$bucket]) ? $taxes[$bucket] : array()) as $rate_id => $amount) {
				$tax_allocations[$bucket][$rate_id] = WCOS_Amount_Allocator::allocate($amount, $weights, $precision);
			}
		}

		$reduced_stock = $source_item->get_meta('_reduced_stock', true);
		$stock_allocations = null;
		if ('' !== $reduced_stock && is_numeric($reduced_stock)) {
			$stock_allocations = WCOS_Amount_Allocator::allocate($reduced_stock, $weights, 6);
		}

		foreach ($children as $child_key => $child) {
			$quantity = isset($plan[$child_key][$item_id]) ? (float) $plan[$child_key][$item_id] : 0.0;
			if ($quantity <= 0) {
				continue;
			}
			$child_taxes = array('subtotal' => array(), 'total' => array());
			foreach (array('subtotal', 'total') as $bucket) {
				foreach ($tax_allocations[$bucket] as $rate_id => $by_destination) {
					$child_taxes[$bucket][$rate_id] = $by_destination[$child_key];
				}
			}
			$new_item = WCOS_Order_Item_Cloner::product($source_item, array(
				'quantity' => $quantity,
				'subtotal' => $allocations['subtotal'][$child_key],
				'subtotal_tax' => $allocations['subtotal_tax'][$child_key],
				'total' => $allocations['total'][$child_key],
				'total_tax' => $allocations['total_tax'][$child_key],
				'taxes' => $child_taxes,
			), false);
			if (is_array($stock_allocations) && (float) $stock_allocations[$child_key] > 0) {
				$new_item->add_meta_data('_reduced_stock', $stock_allocations[$child_key], true);
			}
			$child->add_item($new_item);
		}

		$remaining = (float) $weights['original'];
		if ($remaining <= 0) {
			$source->remove_item($item_id);
			return;
		}
		$source_taxes = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			foreach ($tax_allocations[$bucket] as $rate_id => $by_destination) {
				$source_taxes[$bucket][$rate_id] = $by_destination['original'];
			}
		}
		$source_item->set_props(array(
			'quantity' => $remaining,
			'subtotal' => $allocations['subtotal']['original'],
			'subtotal_tax' => $allocations['subtotal_tax']['original'],
			'total' => $allocations['total']['original'],
			'total_tax' => $allocations['total_tax']['original'],
			'taxes' => $source_taxes,
		));
		$source_item->delete_meta_data('_reduced_stock');
		if (is_array($stock_allocations) && (float) $stock_allocations['original'] > 0) {
			$source_item->add_meta_data('_reduced_stock', $stock_allocations['original'], true);
		}
	}

	private function capture_tax_templates(WC_Order $order) {
		$templates = array();
		foreach ($order->get_items('tax') as $tax_item) {
			$templates[(int) $tax_item->get_rate_id()] = array(
				'rate_id' => $tax_item->get_rate_id(),
				'label' => $tax_item->get_label(),
				'compound' => $tax_item->get_compound(),
				'rate_percent' => $tax_item->get_rate_percent(),
			);
		}
		return $templates;
	}

	private function rebuild_tax_items(WC_Order $order, array $templates) {
		foreach (array_keys($order->get_items('tax')) as $tax_item_id) {
			$order->remove_item($tax_item_id);
		}

		$rates = array();
		foreach (array('line_item', 'fee') as $item_type) {
			foreach ($order->get_items($item_type) as $item) {
				$taxes = (array) $item->get_taxes();
				foreach ((array) (isset($taxes['total']) ? $taxes['total'] : array()) as $rate_id => $amount) {
					$rate_id = (int) $rate_id;
					$rates[$rate_id]['tax_total'] = (isset($rates[$rate_id]['tax_total']) ? $rates[$rate_id]['tax_total'] : 0.0) + (float) $amount;
				}
			}
		}
		foreach ($order->get_items('shipping') as $item) {
			$taxes = (array) $item->get_taxes();
			foreach ((array) (isset($taxes['total']) ? $taxes['total'] : array()) as $rate_id => $amount) {
				$rate_id = (int) $rate_id;
				$rates[$rate_id]['shipping_tax_total'] = (isset($rates[$rate_id]['shipping_tax_total']) ? $rates[$rate_id]['shipping_tax_total'] : 0.0) + (float) $amount;
			}
		}

		foreach ($rates as $rate_id => $amounts) {
			$tax_item = new WC_Order_Item_Tax();
			if (isset($templates[$rate_id])) {
				$tax_item->set_props($templates[$rate_id]);
			} else {
				$tax_item->set_rate_id($rate_id);
			}
			$tax_item->set_tax_total(isset($amounts['tax_total']) ? $amounts['tax_total'] : 0);
			$tax_item->set_shipping_tax_total(isset($amounts['shipping_tax_total']) ? $amounts['shipping_tax_total'] : 0);
			$order->add_item($tax_item);
		}
	}

	private function recompute_order_totals(WC_Order $order, $precision) {
		$line_subtotal = 0.0;
		$line_total = 0.0;
		$line_subtotal_tax = 0.0;
		$line_total_tax = 0.0;
		$cart_tax = 0.0;
		$shipping_total = 0.0;
		$shipping_tax = 0.0;
		$fee_total = 0.0;

		foreach ($order->get_items('line_item') as $item) {
			$line_subtotal += (float) $item->get_subtotal();
			$line_total += (float) $item->get_total();
			$line_subtotal_tax += (float) $item->get_subtotal_tax();
			$line_total_tax += (float) $item->get_total_tax();
			$cart_tax += (float) $item->get_total_tax();
		}
		foreach ($order->get_items('fee') as $item) {
			$fee_total += (float) $item->get_total();
			$cart_tax += (float) $item->get_total_tax();
		}
		foreach ($order->get_items('shipping') as $item) {
			$shipping_total += (float) $item->get_total();
			$shipping_tax += (float) $item->get_total_tax();
		}

		$discount_total = max(0, $line_subtotal - $line_total);
		$discount_tax = max(0, $line_subtotal_tax - $line_total_tax);
		$total_tax = $cart_tax + $shipping_tax;
		$grand_total = $line_total + $fee_total + $shipping_total + $total_tax;
		$order->set_props(array(
			'discount_total' => wc_format_decimal($discount_total, $precision),
			'discount_tax' => wc_format_decimal($discount_tax, $precision),
			'shipping_total' => wc_format_decimal($shipping_total, $precision),
			'shipping_tax' => wc_format_decimal($shipping_tax, $precision),
			'cart_tax' => wc_format_decimal($cart_tax, $precision),
			'total_tax' => wc_format_decimal($total_tax, $precision),
			'total' => wc_format_decimal($grand_total, $precision),
		));
	}

	private function snapshot(array $orders) {
		$snapshot = array(
			'line_subtotal' => 0.0,
			'line_total' => 0.0,
			'discount_total' => 0.0,
			'discount_tax' => 0.0,
			'fees_total' => 0.0,
			'shipping_total' => 0.0,
			'tax_total' => 0.0,
			'grand_total' => 0.0,
			'stock_reduced' => 0.0,
			'line_quantities' => array(),
		);
		foreach ($orders as $order) {
			foreach ($order->get_items('line_item') as $item) {
				$identity = $this->line_identity($item);
				$snapshot['line_quantities'][$identity] = (isset($snapshot['line_quantities'][$identity]) ? $snapshot['line_quantities'][$identity] : 0) + (float) $item->get_quantity();
				$snapshot['line_subtotal'] += (float) $item->get_subtotal();
				$snapshot['line_total'] += (float) $item->get_total();
				$reduced = $item->get_meta('_reduced_stock', true);
				if (is_numeric($reduced)) {
					$snapshot['stock_reduced'] += (float) $reduced;
				}
			}
			foreach ($order->get_items('fee') as $item) {
				$snapshot['fees_total'] += (float) $item->get_total();
			}
			$snapshot['discount_total'] += (float) $order->get_discount_total();
			$snapshot['discount_tax'] += (float) $order->get_discount_tax();
			$snapshot['shipping_total'] += (float) $order->get_shipping_total();
			$snapshot['tax_total'] += (float) $order->get_total_tax();
			$snapshot['grand_total'] += (float) $order->get_total();
		}
		ksort($snapshot['line_quantities'], SORT_STRING);
		return $snapshot;
	}

	private function line_identity(WC_Order_Item_Product $item) {
		$meta = array();
		foreach ($item->get_meta_data() as $entry) {
			$key = (string) $entry->key;
			if ('_reduced_stock' === $key) {
				continue;
			}
			$meta[$key][] = $entry->value;
		}
		return WCOS_Line_Identity::from_values($item->get_product_id(), $item->get_variation_id(), $item->get_tax_class(), $meta);
	}

	private function apply_source_status_without_stock_side_effect(WC_Order $source, WC_Order $child) {
		$target_status = $source->get_status();
		if ('pending' === $target_status) {
			return;
		}
		$child_id = $child->get_id();
		$suppress_stock = static function($can_reduce, $order) use ($child_id) {
			return $order instanceof WC_Order && $order->get_id() === $child_id ? false : $can_reduce;
		};
		add_filter('woocommerce_can_reduce_order_stock', $suppress_stock, 999, 2);
		try {
			$child->set_status($target_status);
			$child->save();
		} finally {
			remove_filter('woocommerce_can_reduce_order_stock', $suppress_stock, 999);
		}
	}

	private function load_orders(array $ids) {
		$orders = array();
		foreach (array_filter(array_map('absint', $ids)) as $id) {
			$order = wc_get_order($id);
			if ($order) {
				$orders[] = $order;
			}
		}
		return $orders;
	}
}
