<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Orders_Bulk_Return {

	public function __construct() {
		$this->includes();
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '/actions/return-order-bulk-action.php';
	}
}

// Initialize the plugin
new WooCommerce_Order_Splitter_Orders_Bulk_Return();
