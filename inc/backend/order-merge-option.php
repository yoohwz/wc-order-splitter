<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Edit_Order_Merge_Option {

	public function __construct() {
		add_filter('woocommerce_order_actions', array($this, 'add_merge_order_action'));
		$this->includes();
	}

	public function add_merge_order_action($actions) {
		global $theorder;

		if (current_user_can('administrator') || (current_user_can('shop_manager') && get_option('order_splitter_shop_manager_permission', 'no') === 'yes'))  {
			$actions['yoos_merge_order'] = __('Merge this order to...', 'wc-order-splitter');
		}

		return $actions;
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '/actions/merge-order.php';
	}
}

new WooCommerce_Order_Splitter_Edit_Order_Merge_Option();
