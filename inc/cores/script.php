<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Order_Splitter_Script {
	private $version;

	public function __construct() {
		$this->version = WC_ORDER_SPLITTER_VERSION;

		add_action('admin_init', array($this, 'record_version'));

		$this->includes();
	}

	public function record_version() {
		if (get_option('wc_order_splitter_version') !== $this->version) {
			update_option('wc_order_splitter_version', $this->version);
		}
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '../backend/settings.php';
		include_once plugin_dir_path(__FILE__) . '../backend/orders.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-woo-settings-tabs-reorder.php';
		include_once plugin_dir_path(__FILE__) . 'safety.php';

		if (defined('WC_ORDER_SPLITTER_MUTATIONS_ENABLED') && true === WC_ORDER_SPLITTER_MUTATIONS_ENABLED) {
			include_once plugin_dir_path(__FILE__) . '../backend/orders-bulk-return.php';
			include_once plugin_dir_path(__FILE__) . '../backend/order-split-button.php';
			include_once plugin_dir_path(__FILE__) . '../backend/order-return-option.php';
			include_once plugin_dir_path(__FILE__) . '../backend/order-duplicate-option.php';
			include_once plugin_dir_path(__FILE__) . '../backend/order-merge-option.php';
		}
	}
}

new WC_Order_Splitter_Script();
