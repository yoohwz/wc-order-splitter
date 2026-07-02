<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Split_Order_Set_Email_Filters {

	public function __construct() {
		add_action('init', array($this, 'set_email_filters'));
	}

	public function set_email_filters() {
		$email_setting = get_option('order_splitter_disable_split_order_email', 'none');
		switch ($email_setting) {
			case 'for_customers':
				add_filter('woocommerce_email_recipient_customer_on_hold_order', array($this, 'filter_email_recipient'), 10, 2);
				add_filter('woocommerce_email_recipient_customer_processing_order', array($this, 'filter_email_recipient'), 10, 2);
				break;
			case 'for_administrators':
				add_filter('woocommerce_email_recipient_new_order', array($this, 'filter_email_recipient'), 10, 2);
				break;
			case 'for_everyone':
				add_filter('woocommerce_email_recipient_customer_on_hold_order', array($this, 'filter_email_recipient'), 10, 2);
				add_filter('woocommerce_email_recipient_customer_processing_order', array($this, 'filter_email_recipient'), 10, 2);
				add_filter('woocommerce_email_recipient_new_order', array($this, 'filter_email_recipient'), 10, 2);
				break;
		}
	}

	public function filter_email_recipient($recipient, $order) {
		if ($order instanceof WC_Order) {
			if ($order->get_meta('yoos_original_order')) {
				return '';
			}
		}
		return $recipient;
	}
}

new WooCommerce_Order_Splitter_Split_Order_Set_Email_Filters();
