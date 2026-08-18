<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Edit_Order_Return_Option {
	public function __construct() {
		add_filter('woocommerce_order_actions', array($this, 'add_return_order_action'));
	}

	public function add_return_order_action($actions) {
		global $theorder;

		if (!$theorder instanceof WC_Order || !WC_Order_Splitter_Mutation_Support::current_user_can_manage_order($theorder->get_id())) {
			return $actions;
		}
		if ('trash' === $theorder->get_status() || 'yes' === $theorder->get_meta(WC_Order_Splitter_Mutation_Support::META_RETURNED, true)) {
			return $actions;
		}

		$original_id = absint($theorder->get_meta(WC_Order_Splitter_Mutation_Support::META_ORIGINAL_ID, true));
		if (!$original_id) {
			$original_id = absint($theorder->get_meta('yoos_original_order', true));
		}
		if (!$original_id || !WC_Order_Splitter_Mutation_Support::current_user_can_manage_order($original_id)) {
			return $actions;
		}

		$actions['yoos_return_order'] = __('Return to the original order', 'wc-order-splitter');
		return $actions;
	}
}

new WooCommerce_Order_Splitter_Edit_Order_Return_Option();
