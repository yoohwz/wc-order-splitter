<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Duplicate_Order_Option {

	public function __construct() {
		add_filter('woocommerce_order_actions', array($this, 'add_duplicate_order_action'));
		$this->includes();
	}

	public function add_duplicate_order_action($actions) {
		global $theorder;

		// Retrieve allowed statuses from the settings
		$allowed_statuses = get_option('order_splitter_status_allowed', []);

		if (current_user_can('administrator')) {
			// Check if the status is in the allowed statuses
			if ('trash' !== $theorder->get_status() && in_array('wc-' . $theorder->get_status(), $allowed_statuses, true)) {
				$actions['yoos_duplicate_order'] = __('Duplicate this order', 'wc-order-splitter');
			}
		}

		return $actions;

	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '/actions/duplicate-order.php';
	}
}

new WooCommerce_Order_Splitter_Duplicate_Order_Option();
