<?php

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Storage-aware lookup for mutation relation metadata.
 *
 * HPOS accepts WC_Order_Query meta_query directly. The legacy CPT store does
 * not, so its documented datastore filter is used to inject an equivalent
 * WP_Query meta condition without bypassing WooCommerce order hydration.
 */
final class WCOS_Order_Relation_Repository {

	/**
	 * @param array $conditions Meta conditions containing key/value and optional compare/type.
	 * @param int   $limit      Maximum number of orders, or -1 for all.
	 * @return WC_Order[]
	 */
	public static function find(array $conditions, $limit = -1) {
		$conditions = self::normalize_conditions($conditions);
		if (empty($conditions)) {
			throw new InvalidArgumentException('At least one order relation condition is required.');
		}

		$args = array(
			'limit' => (int) $limit,
			'return' => 'objects',
			'type' => 'shop_order',
			'orderby' => 'ID',
			'order' => 'ASC',
		);

		if (self::uses_hpos()) {
			$args['meta_query'] = array_merge(array('relation' => 'AND'), $conditions);
			return array_values(array_filter(wc_get_orders($args), array(__CLASS__, 'is_order')));
		}

		$lookup_token = 'wcos_' . sanitize_key(wp_generate_uuid4());
		$args['wcos_relation_lookup'] = $lookup_token;
		$filter = static function($wp_query_args, $query_vars) use ($lookup_token, $conditions) {
			if (!isset($query_vars['wcos_relation_lookup']) || $lookup_token !== $query_vars['wcos_relation_lookup']) {
				return $wp_query_args;
			}

			unset($wp_query_args['wcos_relation_lookup']);
			if (!isset($wp_query_args['meta_query']) || !is_array($wp_query_args['meta_query'])) {
				$wp_query_args['meta_query'] = array();
			}
			$wp_query_args['meta_query'][] = array_merge(array('relation' => 'AND'), $conditions);
			return $wp_query_args;
		};

		add_filter('woocommerce_order_data_store_cpt_get_orders_query', $filter, 10, 2);
		try {
			$orders = wc_get_orders($args);
		} finally {
			remove_filter('woocommerce_order_data_store_cpt_get_orders_query', $filter, 10);
		}

		return array_values(array_filter($orders, array(__CLASS__, 'is_order')));
	}

	public static function is_order($order) {
		return $order instanceof WC_Order;
	}

	private static function normalize_conditions(array $conditions) {
		$normalized = array();
		foreach ($conditions as $condition) {
			if (!is_array($condition) || empty($condition['key'])) {
				throw new InvalidArgumentException('Every order relation condition requires a meta key.');
			}

			$key = sanitize_key((string) $condition['key']);
			if ('' === $key || 0 !== strpos($key, '_wcos_')) {
				throw new InvalidArgumentException('Order relation lookups are restricted to _wcos_ metadata.');
			}

			$compare = isset($condition['compare']) ? strtoupper((string) $condition['compare']) : '=';
			if (!in_array($compare, array('=', 'IN'), true)) {
				throw new InvalidArgumentException('Unsupported order relation comparison.');
			}

			$item = array(
				'key' => $key,
				'value' => isset($condition['value']) ? $condition['value'] : '',
				'compare' => $compare,
			);
			if (isset($condition['type'])) {
				$type = strtoupper((string) $condition['type']);
				if (!in_array($type, array('CHAR', 'NUMERIC'), true)) {
					throw new InvalidArgumentException('Unsupported order relation value type.');
				}
				$item['type'] = $type;
			}
			$normalized[] = $item;
		}
		return $normalized;
	}

	private static function uses_hpos() {
		return class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
