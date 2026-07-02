<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Edit_Order_Return_Option {

	public function __construct() {
		add_filter('woocommerce_order_actions', array($this, 'add_return_order_action'));
		$this->includes();
	}

	public function add_return_order_action($actions) {
		global $theorder;

		// Retrieve allowed statuses from the settings
		$allowed_statuses = get_option('order_splitter_status_allowed', []);

		if (current_user_can('administrator')) {
			// Check if it's a split order, has not been trashed, and the status is in the allowed statuses
			if ($theorder->get_meta('yoos_original_order') && 'trash' !== $theorder->get_status() && in_array('wc-' . $theorder->get_status(), $allowed_statuses, true)) {
				$actions['yoos_return_order'] = __('Return to the original order', 'wc-order-splitter');
			}
		}

		return $actions;
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '/actions/return-order.php';
	}
}

new WooCommerce_Order_Splitter_Edit_Order_Return_Option();
