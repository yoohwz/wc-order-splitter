<?php

defined('ABSPATH') || exit;

class WC_Order_Splitter_Mutation_Exception extends RuntimeException {
	private $context;

	public function __construct($message, $code = 0, $context = array(), Throwable $previous = null) {
		parent::__construct($message, (int) $code, $previous);
		$this->context = is_array($context) ? $context : array();
	}

	public function get_context() {
		return $this->context;
	}
}

final class WC_Order_Splitter_Mutation_Support {
	const META_OPERATION_ID       = '_wc_order_splitter_operation_id';
	const META_ORIGINAL_ID        = '_wc_order_splitter_original_id';
	const META_OPERATION_IDS      = '_wc_order_splitter_operation_ids';
	const META_RETURNED           = '_wc_order_splitter_returned';
	const META_MERGED_INTO        = '_wc_order_splitter_merged_into';
	const META_CREATED_VIA        = '_wc_order_splitter_created_via';
	const META_ITEMS_SUMMARY      = '_wc_order_splitter_items_summary';

	public static function current_user_can_manage_order($order_id) {
		$order_id = absint($order_id);
		if (!$order_id) {
			return false;
		}

		$user = wp_get_current_user();
		if (!$user || !$user->exists()) {
			return false;
		}

		if (in_array('shop_manager', (array) $user->roles, true) && 'yes' !== get_option('order_splitter_shop_manager_permission', 'no')) {
			return false;
		}

		$allowed = current_user_can('manage_woocommerce') || current_user_can('edit_shop_order', $order_id);

		return (bool) apply_filters('wc_order_splitter_user_can_manage_order', $allowed, $order_id, $user);
	}

	public static function assert_can_manage_order($order) {
		if (!$order instanceof WC_Order) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Order not found.', 'wc-order-splitter'));
		}

		if (!self::current_user_can_manage_order($order->get_id())) {
			throw new WC_Order_Splitter_Mutation_Exception(__('You do not have permission to modify this order.', 'wc-order-splitter'));
		}
	}

	public static function is_status_allowed($order) {
		$allowed_statuses = (array) get_option('order_splitter_status_allowed', array('wc-processing'));
		return in_array('wc-' . $order->get_status(), $allowed_statuses, true);
	}

	public static function get_stock_reduced($order) {
		if (!$order instanceof WC_Order) {
			return false;
		}

		$data_store = WC_Data_Store::load('order');
		if ($data_store && is_callable(array($data_store, 'get_stock_reduced'))) {
			return (bool) $data_store->get_stock_reduced($order->get_id());
		}

		return (bool) $order->get_meta('_order_stock_reduced', true);
	}

	public static function set_stock_reduced($order, $reduced) {
		if (!$order instanceof WC_Order) {
			return;
		}

		$data_store = WC_Data_Store::load('order');
		if ($data_store && is_callable(array($data_store, 'set_stock_reduced'))) {
			$data_store->set_stock_reduced($order->get_id(), (bool) $reduced);
			return;
		}

		$order->update_meta_data('_order_stock_reduced', $reduced ? 'yes' : 'no');
		$order->save_meta_data();
	}

	public static function get_protected_item_meta_keys() {
		$keys = array(
			'_reduced_stock',
			'_refunded_item_id',
			self::META_OPERATION_ID,
			self::META_ORIGINAL_ID,
			self::META_RETURNED,
			self::META_MERGED_INTO,
		);

		return array_values(array_unique((array) apply_filters('wc_order_splitter_protected_item_meta_keys', $keys)));
	}

	public static function copy_item_meta($source, $target, $include_protected = false) {
		$protected = self::get_protected_item_meta_keys();
		foreach ($source->get_meta_data() as $meta) {
			$key = (string) $meta->key;
			if (!$include_protected && in_array($key, $protected, true)) {
				continue;
			}
			$target->add_meta_data($key, $meta->value, false);
		}
	}

	public static function line_identity($item) {
		if (!$item instanceof WC_Order_Item_Product) {
			return '';
		}

		$protected = self::get_protected_item_meta_keys();
		$meta = array();
		foreach ($item->get_meta_data() as $entry) {
			$key = (string) $entry->key;
			if (in_array($key, $protected, true)) {
				continue;
			}
			if (!isset($meta[$key])) {
				$meta[$key] = array();
			}
			$meta[$key][] = maybe_serialize($entry->value);
		}
		ksort($meta);
		foreach ($meta as $key => $values) {
			sort($values, SORT_STRING);
			$meta[$key] = $values;
		}

		$identity = array(
			'product_id'   => (int) $item->get_product_id(),
			'variation_id' => (int) $item->get_variation_id(),
			'tax_class'    => (string) $item->get_tax_class(),
			'name'         => (string) $item->get_name(),
			'meta'         => $meta,
		);

		return hash('sha256', wp_json_encode($identity));
	}

	public static function decimals() {
		return function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
	}

	public static function decimal($value, $precision = null) {
		if (null === $precision) {
			$precision = self::decimals();
		}
		return (float) wc_format_decimal($value, (int) $precision);
	}

	public static function add_tax_arrays($left, $right) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			$rate_ids = array_unique(array_merge(
				array_keys(isset($left[$bucket]) && is_array($left[$bucket]) ? $left[$bucket] : array()),
				array_keys(isset($right[$bucket]) && is_array($right[$bucket]) ? $right[$bucket] : array())
			));
			foreach ($rate_ids as $rate_id) {
				$result[$bucket][$rate_id] = self::decimal(
					(isset($left[$bucket][$rate_id]) ? $left[$bucket][$rate_id] : 0) +
					(isset($right[$bucket][$rate_id]) ? $right[$bucket][$rate_id] : 0)
				);
			}
		}
		return $result;
	}

	public static function zero_tax_array_like($taxes) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			foreach ((array) (isset($taxes[$bucket]) ? $taxes[$bucket] : array()) as $rate_id => $amount) {
				$result[$bucket][$rate_id] = 0;
			}
		}
		return $result;
	}

	public static function allocate_scalar($amount, $weights, $precision = null) {
		if (null === $precision) {
			$precision = self::decimals();
		}

		$amount = self::decimal($amount, $precision);
		$weights = array_filter($weights, function($weight) {
			return (float) $weight > 0;
		});

		if (empty($weights)) {
			return array();
		}

		$total_weight = array_sum($weights);
		$remaining_amount = $amount;
		$remaining_weight = $total_weight;
		$allocations = array();
		$keys = array_keys($weights);
		$last_key = end($keys);

		foreach ($weights as $key => $weight) {
			if ($key === $last_key) {
				$part = $remaining_amount;
			} else {
				$part = self::decimal($remaining_amount * ((float) $weight / (float) $remaining_weight), $precision);
			}
			$allocations[$key] = $part;
			$remaining_amount = self::decimal($remaining_amount - $part, $precision);
			$remaining_weight -= (float) $weight;
		}

		return $allocations;
	}

	public static function allocate_tax_array($taxes, $weights) {
		$result = array();
		foreach ($weights as $destination => $weight) {
			$result[$destination] = array('subtotal' => array(), 'total' => array());
		}

		foreach (array('subtotal', 'total') as $bucket) {
			foreach ((array) (isset($taxes[$bucket]) ? $taxes[$bucket] : array()) as $rate_id => $amount) {
				$parts = self::allocate_scalar($amount, $weights);
				foreach ($parts as $destination => $part) {
					$result[$destination][$bucket][$rate_id] = $part;
				}
			}
		}

		return $result;
	}

	public static function capture_order_snapshot($order) {
		$items = array();
		foreach (array('line_item', 'shipping', 'fee', 'coupon', 'tax') as $type) {
			foreach ($order->get_items($type) as $item_id => $item) {
				$entry = array(
					'id'   => (int) $item_id,
					'type' => $type,
					'name' => (string) $item->get_name(),
					'meta' => array(),
				);
				foreach ($item->get_meta_data() as $meta) {
					$entry['meta'][] = array('key' => (string) $meta->key, 'value' => $meta->value);
				}

				if ($item instanceof WC_Order_Item_Product) {
					$entry['props'] = array(
						'product_id'   => $item->get_product_id(),
						'variation_id' => $item->get_variation_id(),
						'quantity'     => $item->get_quantity(),
						'tax_class'    => $item->get_tax_class(),
						'subtotal'     => $item->get_subtotal(),
						'subtotal_tax' => $item->get_subtotal_tax(),
						'total'        => $item->get_total(),
						'total_tax'    => $item->get_total_tax(),
						'taxes'        => $item->get_taxes(),
					);
				} elseif ($item instanceof WC_Order_Item_Shipping) {
					$entry['props'] = array(
						'method_title' => $item->get_method_title(),
						'method_id'    => $item->get_method_id(),
						'instance_id'  => $item->get_instance_id(),
						'total'        => $item->get_total(),
						'taxes'        => $item->get_taxes(),
					);
				} elseif ($item instanceof WC_Order_Item_Fee) {
					$entry['props'] = array(
						'tax_class'  => $item->get_tax_class(),
						'tax_status' => $item->get_tax_status(),
						'total'      => $item->get_total(),
						'total_tax'  => $item->get_total_tax(),
						'taxes'      => $item->get_taxes(),
					);
				} elseif ($item instanceof WC_Order_Item_Coupon) {
					$entry['props'] = array(
						'code'         => $item->get_code(),
						'discount'     => $item->get_discount(),
						'discount_tax' => $item->get_discount_tax(),
					);
				} elseif ($item instanceof WC_Order_Item_Tax) {
					$entry['props'] = array(
						'rate_id'             => $item->get_rate_id(),
						'label'               => $item->get_label(),
						'compound'            => $item->get_compound(),
						'tax_total'           => $item->get_tax_total(),
						'shipping_tax_total'  => $item->get_shipping_tax_total(),
					);
				}
				$items[] = $entry;
			}
		}

		$snapshot = array(
			'order_id'      => $order->get_id(),
			'currency'      => $order->get_currency(),
			'status'        => $order->get_status(),
			'stock_reduced' => self::get_stock_reduced($order),
			'totals'        => self::order_totals($order),
			'items'         => $items,
		);
		$snapshot['hash'] = hash('sha256', wp_json_encode($snapshot));
		return $snapshot;
	}

	public static function order_totals($order) {
		return array(
			'subtotal'       => self::decimal($order->get_subtotal()),
			'discount_total' => self::decimal($order->get_discount_total()),
			'discount_tax'   => self::decimal($order->get_discount_tax()),
			'shipping_total' => self::decimal($order->get_shipping_total()),
			'shipping_tax'   => self::decimal($order->get_shipping_tax()),
			'cart_tax'       => self::decimal($order->get_cart_tax()),
			'total_tax'      => self::decimal($order->get_total_tax()),
			'total'          => self::decimal($order->get_total()),
		);
	}

	public static function add_amount_maps($left, $right) {
		$result = $left;
		foreach ($right as $key => $value) {
			$result[$key] = self::decimal((isset($result[$key]) ? $result[$key] : 0) + $value);
		}
		return $result;
	}

	public static function assert_totals_conserved($before, $orders) {
		$after = array_fill_keys(array_keys($before), 0);
		foreach ($orders as $order) {
			$after = self::add_amount_maps($after, self::order_totals($order));
		}

		foreach ($before as $key => $expected) {
			$actual = isset($after[$key]) ? $after[$key] : 0;
			if (abs((float) $expected - (float) $actual) > pow(10, -self::decimals())) {
				throw new WC_Order_Splitter_Mutation_Exception(
					sprintf(__('Order total invariant failed for %1$s: expected %2$s, got %3$s.', 'wc-order-splitter'), $key, $expected, $actual),
					0,
					array('field' => $key, 'expected' => $expected, 'actual' => $actual)
				);
			}
		}
	}

	public static function sum_line_quantities_by_identity($orders) {
		$result = array();
		foreach ($orders as $order) {
			foreach ($order->get_items('line_item') as $item) {
				$key = self::line_identity($item);
				if (!isset($result[$key])) {
					$result[$key] = 0;
				}
				$result[$key] += (float) $item->get_quantity();
			}
		}
		ksort($result);
		return $result;
	}

	public static function sum_reduced_stock_by_identity($orders) {
		$result = array();
		foreach ($orders as $order) {
			foreach ($order->get_items('line_item') as $item) {
				$key = self::line_identity($item);
				$reduced = $item->get_meta('_reduced_stock', true);
				if ('' === $reduced || null === $reduced) {
					continue;
				}
				if (!isset($result[$key])) {
					$result[$key] = 0;
				}
				$result[$key] += (float) $reduced;
			}
		}
		ksort($result);
		return $result;
	}

	public static function assert_map_conserved($before, $after, $label, $precision = 6) {
		$keys = array_unique(array_merge(array_keys($before), array_keys($after)));
		foreach ($keys as $key) {
			$expected = isset($before[$key]) ? (float) $before[$key] : 0;
			$actual = isset($after[$key]) ? (float) $after[$key] : 0;
			if (abs($expected - $actual) > pow(10, -$precision)) {
				throw new WC_Order_Splitter_Mutation_Exception(
					sprintf(__('%1$s invariant failed.', 'wc-order-splitter'), $label),
					0,
					array('identity' => $key, 'expected' => $expected, 'actual' => $actual)
				);
			}
		}
	}

	public static function order_number($order) {
		return $order instanceof WC_Order ? $order->get_order_number() : '';
	}
}

final class WC_Order_Splitter_Mutation_Lock {
	const TTL = 300;

	private $locks = array();

	public function acquire_orders($order_ids) {
		$order_ids = array_values(array_unique(array_map('absint', (array) $order_ids)));
		sort($order_ids, SORT_NUMERIC);
		foreach ($order_ids as $order_id) {
			$this->acquire($order_id);
		}
	}

	public function acquire($order_id) {
		$order_id = absint($order_id);
		$key = 'wc_order_splitter_lock_' . $order_id;
		$token = wp_generate_uuid4();
		$payload = array('token' => $token, 'created_at' => time(), 'user_id' => get_current_user_id());

		if (!add_option($key, $payload, '', 'no')) {
			$current = get_option($key, array());
			if (is_array($current) && isset($current['created_at']) && (time() - (int) $current['created_at']) > self::TTL) {
				delete_option($key);
				if (!add_option($key, $payload, '', 'no')) {
					throw new WC_Order_Splitter_Mutation_Exception(__('This order is already being modified by another operation.', 'wc-order-splitter'));
				}
			} else {
				throw new WC_Order_Splitter_Mutation_Exception(__('This order is already being modified by another operation.', 'wc-order-splitter'));
			}
		}

		$this->locks[$key] = $token;
	}

	public function release_all() {
		foreach ($this->locks as $key => $token) {
			$current = get_option($key, array());
			if (is_array($current) && isset($current['token']) && hash_equals((string) $current['token'], (string) $token)) {
				delete_option($key);
			}
		}
		$this->locks = array();
	}

	public function __destruct() {
		$this->release_all();
	}
}
