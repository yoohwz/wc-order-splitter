<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Duplicate_Order_Option {
	public function __construct() {
		add_filter('woocommerce_order_actions', array($this, 'add_duplicate_order_action'));
	}

	public function add_duplicate_order_action($actions) {
		global $theorder;

		if (!$theorder instanceof WC_Order) {
			return $actions;
		}
		if (!WC_Order_Splitter_Mutation_Support::current_user_can_manage_order($theorder->get_id())) {
			return $actions;
		}
		if ('trash' === $theorder->get_status() || !WC_Order_Splitter_Mutation_Support::is_status_allowed($theorder)) {
			return $actions;
		}
		if ($theorder->get_meta(WC_Order_Splitter_Mutation_Support::META_MERGED_INTO, true)) {
			return $actions;
		}

		$actions['yoos_duplicate_order'] = __('Duplicate this order', 'wc-order-splitter');
		return $actions;
	}
}

new WooCommerce_Order_Splitter_Duplicate_Order_Option();
