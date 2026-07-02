<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Edit_Order_Split_Button {

	public function __construct() {
		add_action('woocommerce_order_item_add_action_buttons', array($this, 'add_split_order_button'), 10, 1);
		add_action('woocommerce_admin_order_totals_after_total', array($this, 'add_split_order_container'), 10, 1);
		$this->includes();
	}

	public function add_split_order_button($order) {
		$should_display_button = false;

		// Retrieve allowed statuses from the settings
		$allowed_statuses = get_option('order_splitter_status_allowed', []);

		if (current_user_can('shop_manager') && get_option('order_splitter_shop_manager_permission', 'no') === 'no') {
			return;
		}

		// Check if the current order status is allowed
		if (!in_array('wc-' . $order->get_status(), $allowed_statuses, true)) {
			return; // Do not display the button if the status is not allowed
		}

		// Count items to determine if the button should be displayed
		$items = $order->get_items();
		if (count($items) > 1) {
			$should_display_button = true;
		} else if (count($items) == 1) {
			$item = reset($items); // Get the first (and only) item
			if ($item->get_quantity() > 1) {
				$should_display_button = true;
			}
		}

		// Display the button if applicable
		if ($should_display_button) {
			echo '<button type="button" class="button split-order">' . esc_html__('Split order', 'wc-order-splitter') . '</button>';
		}
	}

	public function add_split_order_container($order) {
		echo '<div id="split-order-container" style="display:none;"></div>';
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '/actions/split-order-by-default.php';
		include_once plugin_dir_path(__FILE__) . '/actions/split-order-by-category.php';
		include_once plugin_dir_path(__FILE__) . '/actions/split-order-by-stock-status.php';
		include_once plugin_dir_path(__FILE__) . '/actions/split-order-set-email-filters.php';
	}
}

new WooCommerce_Order_Splitter_Edit_Order_Split_Button();
