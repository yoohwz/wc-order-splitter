<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Edit_Order {
	const COLUMN = 'wc_order_splitter_relations';

	public function __construct() {
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_order_relations'));
		add_filter('manage_edit-shop_order_columns', array($this, 'add_relation_column'));
		add_action('manage_shop_order_posts_custom_column', array($this, 'render_relation_column_legacy'), 25, 1);
		add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_relation_column'));
		add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_relation_column'), 25, 2);
	}

	public function add_relation_column($columns) {
		if ('yes' !== get_option('order_splitter_order_label', 'yes')) {
			return $columns;
		}

		$new_columns = array();
		$inserted = false;
		foreach ($columns as $key => $label) {
			$new_columns[$key] = $label;
			if ('order_status' === $key) {
				$new_columns[self::COLUMN] = __('Order relations', 'wc-order-splitter');
				$inserted = true;
			}
		}
		if (!$inserted) {
			$new_columns[self::COLUMN] = __('Order relations', 'wc-order-splitter');
		}
		return $new_columns;
	}

	public function render_relation_column_legacy($column_name) {
		global $the_order;
		if ($the_order instanceof WC_Order) {
			$this->render_relation_column($column_name, $the_order);
		}
	}

	public function render_relation_column($column_name, $order) {
		if (self::COLUMN !== $column_name || !$order instanceof WC_Order) {
			return;
		}
		$relations = $this->get_relations($order);
		if (empty($relations)) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('No Order Splitter relations', 'wc-order-splitter') . '</span>';
			return;
		}
		echo '<div class="wc-order-splitter-relations">';
		foreach ($relations as $relation) {
			echo '<div><span class="screen-reader-text">' . esc_html($relation['label']) . ': </span>';
			echo '<span aria-hidden="true">' . esc_html($relation['short']) . ' </span>';
			echo $this->order_link($relation['order']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated with escaped URL/text.
			echo '</div>';
		}
		echo '</div>';
	}

	public function render_order_relations($order) {
		if ('yes' !== get_option('order_splitter_order_label', 'yes') || !$order instanceof WC_Order) {
			return;
		}
		$relations = $this->get_relations($order);
		if (empty($relations)) {
			return;
		}

		echo '<div class="wc-order-splitter-order-relations" aria-label="' . esc_attr__('Order Splitter relations', 'wc-order-splitter') . '">';
		echo '<strong>' . esc_html__('Order relations:', 'wc-order-splitter') . '</strong> ';
		$parts = array();
		foreach ($relations as $relation) {
			$parts[] = esc_html($relation['label']) . ' ' . $this->order_link($relation['order']);
		}
		echo wp_kses_post(implode(' · ', $parts));
		echo '</div>';
	}

	private function get_relations($order) {
		$relations = array();
		$original_id = absint($order->get_meta(WC_Order_Splitter_Mutation_Support::META_ORIGINAL_ID, true));
		if (!$original_id) {
			$original_id = absint($order->get_meta('yoos_original_order', true));
		}
		if ($original_id) {
			$original = wc_get_order($original_id);
			if ($original) {
				$relations[] = array('label' => __('Original', 'wc-order-splitter'), 'short' => __('O:', 'wc-order-splitter'), 'order' => $original);
			}
		}

		$children = (array) $order->get_meta('_wc_order_splitter_children', true);
		if (empty($children)) {
			$legacy = (string) $order->get_meta('yoos_splitted_order', true);
			$children = $legacy ? explode(',', $legacy) : array();
		}
		foreach (array_values(array_unique(array_filter(array_map('absint', $children)))) as $child_id) {
			$child = wc_get_order($child_id);
			if ($child && 'yes' !== $child->get_meta(WC_Order_Splitter_Mutation_Support::META_RETURNED, true)) {
				$relations[] = array('label' => __('Split', 'wc-order-splitter'), 'short' => __('S:', 'wc-order-splitter'), 'order' => $child);
			}
		}

		$merged_into_id = absint($order->get_meta(WC_Order_Splitter_Mutation_Support::META_MERGED_INTO, true));
		if ($merged_into_id) {
			$merged_into = wc_get_order($merged_into_id);
			if ($merged_into) {
				$relations[] = array('label' => __('Merged into', 'wc-order-splitter'), 'short' => __('M:', 'wc-order-splitter'), 'order' => $merged_into);
			}
		}

		$duplicate_of_id = absint($order->get_meta('_wc_order_splitter_duplicate_of', true));
		if ($duplicate_of_id) {
			$duplicate_of = wc_get_order($duplicate_of_id);
			if ($duplicate_of) {
				$relations[] = array('label' => __('Duplicate of', 'wc-order-splitter'), 'short' => __('D:', 'wc-order-splitter'), 'order' => $duplicate_of);
			}
		}

		return $relations;
	}

	private function order_link($order) {
		return sprintf(
			'<a href="%1$s">#%2$s</a>',
			esc_url($order->get_edit_order_url()),
			esc_html($order->get_order_number())
		);
	}
}

new WooCommerce_Order_Splitter_Edit_Order();
