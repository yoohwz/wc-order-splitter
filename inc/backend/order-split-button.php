<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Edit_Order_Split_Button {
	public function __construct() {
		add_action('woocommerce_order_item_add_action_buttons', array($this, 'add_split_order_button'), 10, 1);
		add_action('woocommerce_admin_order_totals_after_total', array($this, 'add_split_order_container'), 10, 1);
	}

	public function add_split_order_button($order) {
		if (!$order instanceof WC_Order || !WC_Order_Splitter_Mutation_Support::current_user_can_manage_order($order->get_id())) {
			return;
		}

		if (!WC_Order_Splitter_Mutation_Support::is_status_allowed($order)) {
			return;
		}

		$items = $order->get_items('line_item');
		$can_split = count($items) > 1;
		if (!$can_split && 1 === count($items)) {
			$item = reset($items);
			$can_split = $item && (float) $item->get_quantity() > 1;
		}

		if (!$can_split) {
			return;
		}

		echo '<button type="button" class="button split-order" aria-expanded="false" aria-controls="split-order-container">' . esc_html__('Split order', 'wc-order-splitter') . '</button>';
	}

	public function add_split_order_container($order) {
		if (!$order instanceof WC_Order) {
			return;
		}

		echo '<div id="split-order-container" class="wc-order-splitter-panel" data-order-id="' . esc_attr((string) $order->get_id()) . '" role="region" aria-label="' . esc_attr__('Split order', 'wc-order-splitter') . '" hidden></div>';
	}
}

new WooCommerce_Order_Splitter_Edit_Order_Split_Button();
