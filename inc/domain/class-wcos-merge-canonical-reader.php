<?php

defined('ABSPATH') || exit;

/**
 * Persisted, unfiltered read projection for current/fresh Merge authority.
 *
 * WooCommerce `view` getters and order-item collection filters are presentation
 * surfaces. They must never decide Merge eligibility, identity, money, tax,
 * recovery, or retirement authority.
 */
final class WCOS_Merge_Canonical_Reader {

	const PROJECTION_VERSION = 1;

	public static function items(WC_Abstract_Order $order, $item_type) {
		$item_type = sanitize_key((string) $item_type);
		$allowed = array('line_item', 'shipping', 'fee', 'tax', 'coupon');
		if (!in_array($item_type, $allowed, true) || !absint($order->get_id())) {
			throw new RuntimeException(__('Merge requires a valid persisted order-item collection.', 'wc-order-splitter'));
		}
		$data_store = $order->get_data_store();
		if (!is_object($data_store) || !is_callable(array($data_store, 'read_items'))) {
			throw new RuntimeException(__('Merge cannot read persisted order-item authority.', 'wc-order-splitter'));
		}
		$items = self::without_presentation_filters(static function() use ($data_store, $order, $item_type) {
			return $data_store->read_items($order, $item_type);
		});
		if (!is_array($items)) {
			throw new RuntimeException(__('Merge received malformed persisted order-item authority.', 'wc-order-splitter'));
		}
		$canonical = array();
		foreach ($items as $item_id => $item) {
			$item_id = absint($item_id);
			if (!$item instanceof WC_Order_Item || !$item_id || isset($canonical[$item_id])
				|| $item_id !== absint($item->get_id())
				|| absint($item->get_order_id('edit')) !== absint($order->get_id())
				|| $item_type !== (string) $item->get_type()) {
				throw new RuntimeException(__('Merge encountered ambiguous persisted order-item authority.', 'wc-order-splitter'));
			}
			$canonical[$item_id] = $item;
		}
		ksort($canonical, SORT_NUMERIC);
		return $canonical;
	}

	/** Fresh persisted order/refund object whose hydration cannot consume view filters. */
	public static function order($order_id) {
		$order_id = absint($order_id);
		if (!$order_id) {
			return false;
		}
		return self::without_presentation_filters(static function() use ($order_id) {
			$prototype = wc_get_order($order_id);
			if (!$prototype instanceof WC_Abstract_Order) {
				return false;
			}
			$class = get_class($prototype);
			try {
				$fresh = new $class($order_id);
			} catch (Throwable $throwable) {
				return false;
			}
			return $fresh instanceof WC_Abstract_Order && absint($fresh->get_id()) === $order_id ? $fresh : false;
		});
	}

	/** Exact persisted shop-order participant for a current/fresh Merge decision. */
	public static function shop_order($order_id) {
		$order_id = absint($order_id);
		$order = self::order($order_id);
		return $order instanceof WC_Order
			&& $order_id === absint($order->get_id())
			&& 'shop_order' === (string) $order->get_type()
			? $order
			: false;
	}

	/**
	 * Rehydrate both current/fresh Merge participants from their persisted IDs.
	 * Caller-hydrated objects are presentation inputs and must not cross this
	 * authority boundary.
	 */
	public static function shop_order_pair($source_order_id, $target_order_id) {
		$source_order_id = absint($source_order_id);
		$target_order_id = absint($target_order_id);
		if (!$source_order_id || !$target_order_id) {
			return false;
		}
		$source = self::shop_order($source_order_id);
		$target = self::shop_order($target_order_id);
		if (!$source instanceof WC_Order || !$target instanceof WC_Order
			|| $source_order_id !== absint($source->get_id())
			|| $target_order_id !== absint($target->get_id())) {
			return false;
		}
		return array($source, $target);
	}

	/** Fresh persisted refund collection with unfiltered object hydration. */
	public static function refunds(WC_Order $order) {
		$refund_ids = self::order_ids($order, array(
			'type' => 'shop_order_refund',
			'status' => 'any',
			'parent' => absint($order->get_id()),
			'limit' => -1,
			'orderby' => 'ID',
			'order' => 'ASC',
		));
		$canonical = array();
		foreach ($refund_ids as $refund_id) {
			$fresh = self::order($refund_id);
			if (!$fresh instanceof WC_Order_Refund
				|| absint($fresh->get_parent_id('edit')) !== absint($order->get_id())
				|| 'shop_order_refund' !== (string) $fresh->get_type()
				|| isset($canonical[$refund_id])) {
				throw new RuntimeException(__('Merge encountered ambiguous persisted refund authority.', 'wc-order-splitter'));
			}
			$canonical[$refund_id] = $fresh;
		}
		ksort($canonical, SORT_NUMERIC);
		return $canonical;
	}

	/** Public WooCommerce data-store query with every result-changing hook scoped out. */
	public static function order_ids(WC_Abstract_Order $prototype, array $query_vars) {
		$data_store = $prototype->get_data_store();
		if (!is_object($data_store) || !is_callable(array($data_store, 'query'))) {
			throw new RuntimeException(__('Merge cannot query persisted order authority.', 'wc-order-splitter'));
		}
		$query_vars['return'] = 'ids';
		$query_vars['paginate'] = false;
		$query_vars['no_found_rows'] = true;
		// Canonical persisted Merge authority must bypass result-changing presentation/query filters.
		$query_vars['suppress_filters'] = true; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
		$query_vars['cache_results'] = false;
		$ids = self::without_order_query_filters(static function() use ($data_store, $query_vars) {
			return $data_store->query($query_vars);
		});
		if (!is_array($ids)) {
			throw new RuntimeException(__('Merge received malformed persisted order-query authority.', 'wc-order-splitter'));
		}
		$canonical = array();
		foreach ($ids as $id) {
			$id = absint($id);
			if (!$id || isset($canonical[$id])) {
				throw new RuntimeException(__('Merge encountered ambiguous persisted order-query authority.', 'wc-order-splitter'));
			}
			$canonical[$id] = $id;
		}
		return array_values($canonical);
	}

	/**
	 * WooCommerce order/item hydration can invoke `view` getters from setters.
	 * Isolate those presentation hooks while constructing persisted item objects,
	 * then restore the exact request-local hook registry even when hydration fails.
	 */
	public static function without_presentation_filters(callable $callback) {
		return self::without_scoped_filters(
			$callback,
			array('woocommerce_order_get_', 'woocommerce_order_refund_get_', 'woocommerce_order_item_get_'),
			array()
		);
	}

	/** Query filters can alter or replace the persisted order/refund ID set. */
	public static function without_order_query_filters(callable $callback) {
		return self::without_scoped_filters(
			$callback,
			array(),
			array(
				'woocommerce_order_query_args',
				'woocommerce_order_query',
				'woocommerce_get_wp_query_args',
				'woocommerce_order_data_store_cpt_get_orders_query',
				'woocommerce_orders_table_datastore_get_orders_query',
				'woocommerce_hpos_pre_query',
				'parse_query',
				'pre_get_posts',
				'posts_pre_query',
			)
		);
	}

	private static function without_scoped_filters(callable $callback, array $prefixes, array $exact_hooks) {
		global $wp_filter;
		$matches = static function($hook) use ($prefixes, $exact_hooks) {
			if (in_array((string) $hook, $exact_hooks, true)) {
				return true;
			}
			foreach ($prefixes as $prefix) {
				if (0 === strpos((string) $hook, (string) $prefix)) {
					return true;
				}
			}
			return false;
		};
		$snapshot = array();
		foreach (is_array($wp_filter) ? array_keys($wp_filter) : array() as $hook) {
			if ($matches($hook)) {
				$snapshot[$hook] = $wp_filter[$hook];
				unset($wp_filter[$hook]);
			}
		}
		try {
			return $callback();
		} finally {
			foreach (is_array($wp_filter) ? array_keys($wp_filter) : array() as $hook) {
				if ($matches($hook)) {
					unset($wp_filter[$hook]);
				}
			}
			foreach ($snapshot as $hook => $callbacks) {
				$wp_filter[$hook] = $callbacks;
			}
		}
	}

	public static function item(WC_Abstract_Order $order, $item_id, $item_type) {
		$item_id = absint($item_id);
		$items = self::items($order, $item_type);
		return $item_id && isset($items[$item_id]) ? $items[$item_id] : false;
	}

	public static function status(WC_Order $order) {
		return sanitize_key((string) $order->get_status('edit'));
	}

	public static function currency(WC_Order $order) {
		return strtoupper(trim((string) $order->get_currency('edit')));
	}

	public static function prices_include_tax(WC_Order $order) {
		return (bool) $order->get_prices_include_tax('edit');
	}

	public static function line_identity(WC_Order_Item_Product $item) {
		return WCOS_Line_Identity::from_values(
			$item->get_product_id('edit'),
			$item->get_variation_id('edit'),
			$item->get_tax_class('edit'),
			WCOS_Order_Item_Meta_Policy::business_metadata($item)
		);
	}

	public static function line_state(WC_Order_Item_Product $item) {
		$reduced = $item->get_meta('_reduced_stock', true, 'edit');
		return array(
			'commercial_identity' => self::line_identity($item),
			'product_id' => absint($item->get_product_id('edit')),
			'variation_id' => absint($item->get_variation_id('edit')),
			'tax_class' => (string) $item->get_tax_class('edit'),
			'quantity' => WCOS_Decimal::normalize($item->get_quantity('edit'), 6),
			'subtotal' => (string) $item->get_subtotal('edit'),
			'subtotal_tax' => (string) $item->get_subtotal_tax('edit'),
			'total' => (string) $item->get_total('edit'),
			'total_tax' => (string) $item->get_total_tax('edit'),
			'taxes' => self::canonicalize((array) $item->get_taxes('edit')),
			'reduced_stock' => '' === $reduced || null === $reduced ? null : WCOS_Decimal::normalize($reduced, 6),
		);
	}

	public static function source_signature(WC_Order $order) {
		$state = array(
			'order_id' => absint($order->get_id()),
			'type' => (string) $order->get_type(),
			'status' => self::status($order),
			'currency' => self::currency($order),
			'prices_include_tax' => self::prices_include_tax($order),
			'discount_total' => (string) $order->get_discount_total('edit'),
			'discount_tax' => (string) $order->get_discount_tax('edit'),
			'shipping_total' => (string) $order->get_shipping_total('edit'),
			'shipping_tax' => (string) $order->get_shipping_tax('edit'),
			'cart_tax' => (string) $order->get_cart_tax('edit'),
			'total_tax' => (string) $order->get_total_tax('edit'),
			'total' => (string) $order->get_total('edit'),
			'transaction_id' => (string) $order->get_transaction_id('edit'),
			'copy_context_signature' => self::context_signature($order),
			'stock_reduced' => (bool) $order->get_data_store()->get_stock_reduced($order->get_id()),
			'items' => array(),
		);
		foreach (array('line_item', 'shipping', 'fee', 'tax', 'coupon') as $item_type) {
			foreach (self::items($order, $item_type) as $item_id => $item) {
				$state['items'][$item_type][(int) $item_id] = self::item_state($item);
			}
		}
		return WCOS_Mutation_Fingerprint::create('source_state', $order->get_id(), $state);
	}

	public static function aggregate(array $orders, $precision) {
		$precision = WCOS_Price_Precision_Scope::validate($precision);
		$money = array(
			'line_subtotal' => 0, 'line_total' => 0, 'line_subtotal_tax' => 0, 'line_total_tax' => 0,
			'discount_total' => 0, 'discount_tax' => 0, 'fees_total' => 0, 'shipping_total' => 0,
			'tax_total' => 0, 'grand_total' => 0,
		);
		$stock_reduced = 0;
		$line_quantities = array();
		$currencies = array();
		$tax_by_rate = array();
		$line_tax_by_rate = array();

		foreach ($orders as $order) {
			if (!$order instanceof WC_Order) {
				throw new InvalidArgumentException(__('Merge contract projections require WC_Order objects.', 'wc-order-splitter'));
			}
			$currencies[] = self::currency($order);
			foreach (array(
				'discount_total' => $order->get_discount_total('edit'),
				'discount_tax' => $order->get_discount_tax('edit'),
				'tax_total' => $order->get_total_tax('edit'),
				'grand_total' => $order->get_total('edit'),
			) as $field => $value) {
				$money[$field] = self::safe_add($money[$field], WCOS_Decimal::to_units($value, $precision));
			}
			foreach (self::items($order, 'line_item') as $item) {
				if (!$item instanceof WC_Order_Item_Product) {
					throw new RuntimeException(__('Merge encountered an unsupported persisted product line.', 'wc-order-splitter'));
				}
				$identity = self::line_identity($item);
				$line_quantities[$identity] = self::safe_add(isset($line_quantities[$identity]) ? $line_quantities[$identity] : 0, WCOS_Decimal::to_units($item->get_quantity('edit'), 6));
				foreach (array(
					'line_subtotal' => $item->get_subtotal('edit'),
					'line_total' => $item->get_total('edit'),
					'line_subtotal_tax' => $item->get_subtotal_tax('edit'),
					'line_total_tax' => $item->get_total_tax('edit'),
				) as $field => $value) {
					$money[$field] = self::safe_add($money[$field], WCOS_Decimal::to_units($value, $precision));
				}
				$taxes = (array) $item->get_taxes('edit');
				foreach (array('subtotal', 'total') as $bucket) {
					foreach (isset($taxes[$bucket]) && is_array($taxes[$bucket]) ? $taxes[$bucket] : array() as $rate_id => $amount) {
						$key = (string) (int) $rate_id;
						if (!isset($line_tax_by_rate[$key])) {
							$line_tax_by_rate[$key] = array('subtotal' => 0, 'total' => 0);
						}
						$line_tax_by_rate[$key][$bucket] = self::safe_add($line_tax_by_rate[$key][$bucket], WCOS_Decimal::to_units($amount, $precision));
					}
				}
				$reduced = $item->get_meta('_reduced_stock', true, 'edit');
				if ('' !== $reduced && null !== $reduced && is_numeric($reduced)) {
					$stock_reduced = self::safe_add($stock_reduced, WCOS_Decimal::to_units($reduced, 6));
				}
			}
			foreach (self::items($order, 'fee') as $item) {
				$money['fees_total'] = self::safe_add($money['fees_total'], WCOS_Decimal::to_units($item->get_total('edit'), $precision));
			}
			foreach (self::items($order, 'shipping') as $item) {
				$money['shipping_total'] = self::safe_add($money['shipping_total'], WCOS_Decimal::to_units($item->get_total('edit'), $precision));
			}
			foreach (self::items($order, 'tax') as $item) {
				$key = (string) absint($item->get_rate_id('edit'));
				if (!isset($tax_by_rate[$key])) {
					$tax_by_rate[$key] = array('cart' => 0, 'shipping' => 0);
				}
				$tax_by_rate[$key]['cart'] = self::safe_add($tax_by_rate[$key]['cart'], WCOS_Decimal::to_units($item->get_tax_total('edit'), $precision));
				$tax_by_rate[$key]['shipping'] = self::safe_add($tax_by_rate[$key]['shipping'], WCOS_Decimal::to_units($item->get_shipping_tax_total('edit'), $precision));
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
		$result['stock_reduced'] = WCOS_Decimal::from_units($stock_reduced, 6);
		$result['line_quantities'] = $line_quantities;
		$result['line_tax_by_rate'] = self::format_rate_units($line_tax_by_rate, $precision, array('subtotal', 'total'));
		$result['tax_by_rate'] = self::format_rate_units($tax_by_rate, $precision, array('cart', 'shipping'));
		$result['currencies'] = array_values(array_unique(array_map('strval', $currencies)));
		return $result;
	}

	public static function product_stock(WC_Order $order) {
		$stock = array();
		foreach (self::items($order, 'line_item') as $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				throw new RuntimeException(__('Merge encountered an unsupported persisted product line.', 'wc-order-splitter'));
			}
			$product_id = absint($item->get_variation_id('edit')) ?: absint($item->get_product_id('edit'));
			$product = $product_id ? wc_get_product($product_id) : false;
			if (!$product instanceof WC_Product) {
				continue;
			}
			$managed = $product;
			if ($product instanceof WC_Product_Variation && !$product->get_manage_stock('edit')) {
				$parent_id = absint($product->get_parent_id('edit'));
				$managed = $parent_id ? wc_get_product($parent_id) : false;
			}
			$managed_id = $managed instanceof WC_Product ? absint($managed->get_id()) : 0;
			if (!$managed instanceof WC_Product || !$managed->get_manage_stock('edit')) {
				continue;
			}
			$quantity = $managed->get_stock_quantity('edit');
			$stock[$managed_id] = null === $quantity ? null : WCOS_Decimal::normalize($quantity, 6);
		}
		ksort($stock, SORT_NUMERIC);
		return $stock;
	}

	public static function context_payload(WC_Order $order) {
		return array(
			'customer_id' => absint($order->get_customer_id('edit')),
			'currency' => self::currency($order),
			'prices_include_tax' => self::prices_include_tax($order),
			'payment_method' => (string) $order->get_payment_method('edit'),
			'payment_method_title' => (string) $order->get_payment_method_title('edit'),
			'customer_note' => (string) $order->get_customer_note('edit'),
			'billing' => self::address($order, 'billing'),
			'shipping' => self::address($order, 'shipping'),
		);
	}

	public static function address(WC_Order $order, $type) {
		$type = sanitize_key((string) $type);
		if (!in_array($type, array('billing', 'shipping'), true)) {
			throw new InvalidArgumentException(__('Merge requires a canonical billing or shipping address type.', 'wc-order-splitter'));
		}
		$fields = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country');
		if ('billing' === $type) {
			$fields[] = 'email';
			$fields[] = 'phone';
		} else {
			foreach (array('email', 'phone') as $optional) {
				if (is_callable(array($order, 'get_shipping_' . $optional))) {
					$fields[] = $optional;
				}
			}
		}
		$address = array();
		foreach ($fields as $field) {
			$getter = 'get_' . $type . '_' . $field;
			if (!is_callable(array($order, $getter))) {
				throw new RuntimeException(__('Merge cannot read canonical participant address authority.', 'wc-order-splitter'));
			}
			$address[$field] = (string) $order->{$getter}('edit');
		}
		return $address;
	}

	public static function context_signature(WC_Order $order) {
		return WCOS_Mutation_Fingerprint::create('order_copy_context', 0, self::context_payload($order));
	}

	private static function item_state(WC_Order_Item $item) {
		$state = array(
			'id' => absint($item->get_id()),
			'type' => (string) $item->get_type(),
			'name' => (string) $item->get_name('edit'),
			'meta' => WCOS_Order_Item_Meta_Policy::business_metadata($item),
		);
		if ($item instanceof WC_Order_Item_Product) {
			$line = self::line_state($item);
			unset($line['commercial_identity']);
			return $state + $line;
		}
		if ($item instanceof WC_Order_Item_Shipping) {
			return $state + array(
				'method_id' => (string) $item->get_method_id('edit'),
				'instance_id' => (string) $item->get_instance_id('edit'),
				'total' => (string) $item->get_total('edit'),
				'total_tax' => (string) $item->get_total_tax('edit'),
				'taxes' => (array) $item->get_taxes('edit'),
			);
		}
		if ($item instanceof WC_Order_Item_Fee) {
			return $state + array(
				'amount' => (string) $item->get_amount('edit'),
				'total' => (string) $item->get_total('edit'),
				'total_tax' => (string) $item->get_total_tax('edit'),
				'taxes' => (array) $item->get_taxes('edit'),
			);
		}
		if ($item instanceof WC_Order_Item_Tax) {
			return $state + array(
				'rate_id' => absint($item->get_rate_id('edit')),
				'tax_total' => (string) $item->get_tax_total('edit'),
				'shipping_tax_total' => (string) $item->get_shipping_tax_total('edit'),
			);
		}
		if ($item instanceof WC_Order_Item_Coupon) {
			return $state + array(
				'code' => (string) $item->get_code('edit'),
				'discount' => (string) $item->get_discount('edit'),
				'discount_tax' => (string) $item->get_discount_tax('edit'),
			);
		}
		throw new RuntimeException(__('Merge encountered an unsupported persisted order item.', 'wc-order-splitter'));
	}

	private static function format_rate_units(array $rates, $precision, array $buckets) {
		ksort($rates, SORT_STRING);
		$result = array();
		foreach ($rates as $rate_id => $values) {
			foreach ($buckets as $bucket) {
				$result[(string) $rate_id][$bucket] = WCOS_Decimal::from_units((int) $values[$bucket], $precision);
			}
		}
		return $result;
	}

	private static function safe_add($left, $right) {
		$left = (int) $left;
		$right = (int) $right;
		if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < -PHP_INT_MAX - $right)) {
			throw new OverflowException('Merge canonical value exceeds the supported integer range.');
		}
		return $left + $right;
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
