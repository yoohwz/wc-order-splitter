<?php

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Storage-aware lookup for mutation relation metadata.
 *
 * HPOS accepts WC_Order_Query meta_query directly. The legacy CPT store does
 * not. For legacy only, supported _wcos_ relation queries are tokenized before
 * datastore validation and injected through WooCommerce's CPT query filter.
 */
final class WCOS_Order_Relation_Repository {

	private static $bootstrapped = false;
	private static $legacy_contexts = array();

	public static function bootstrap() {
		if (self::$bootstrapped) {
			return;
		}
		self::$bootstrapped = true;

		add_filter('woocommerce_order_query_args', array(__CLASS__, 'tokenize_legacy_query'), 9);
		add_filter('woocommerce_order_data_store_cpt_get_orders_query', array(__CLASS__, 'inject_legacy_query'), 10, 2);
	}

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
			'meta_query' => array_merge(array('relation' => 'AND'), $conditions),
		);

		return array_values(array_filter(wc_get_orders($args), array(__CLASS__, 'is_order')));
	}

	public static function tokenize_legacy_query($query_args) {
		if (self::uses_hpos() || empty($query_args['meta_query']) || !is_array($query_args['meta_query'])) {
			return $query_args;
		}

		$conditions = self::supported_relation_query($query_args['meta_query']);
		if (null === $conditions) {
			return $query_args;
		}

		$token = 'wcos_' . sanitize_key(wp_generate_uuid4());
		self::$legacy_contexts[$token] = $conditions;
		unset($query_args['meta_query']);
		$query_args['wcos_relation_lookup'] = $token;
		return $query_args;
	}

	public static function inject_legacy_query($wp_query_args, $query_vars) {
		$token = isset($query_vars['wcos_relation_lookup']) ? sanitize_key($query_vars['wcos_relation_lookup']) : '';
		if ('' === $token || !isset(self::$legacy_contexts[$token])) {
			return $wp_query_args;
		}

		$conditions = self::$legacy_contexts[$token];
		unset(self::$legacy_contexts[$token], $wp_query_args['wcos_relation_lookup']);

		if (!isset($wp_query_args['meta_query']) || !is_array($wp_query_args['meta_query'])) {
			$wp_query_args['meta_query'] = array();
		}
		$wp_query_args['meta_query'][] = array_merge(array('relation' => 'AND'), $conditions);
		return $wp_query_args;
	}

	public static function is_order($order) {
		return $order instanceof WC_Order;
	}

	private static function supported_relation_query(array $meta_query) {
		$relation = isset($meta_query['relation']) ? strtoupper((string) $meta_query['relation']) : 'AND';
		if ('AND' !== $relation) {
			return null;
		}

		$conditions = array();
		foreach ($meta_query as $key => $condition) {
			if ('relation' === $key) {
				continue;
			}
			if (!is_array($condition) || empty($condition['key'])) {
				return null;
			}
			try {
				$normalized = self::normalize_conditions(array($condition));
			} catch (InvalidArgumentException $exception) {
				return null;
			}
			$conditions[] = reset($normalized);
		}

		return empty($conditions) ? null : $conditions;
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
