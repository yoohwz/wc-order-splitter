<?php

defined('ABSPATH') || exit;

final class WC_Order_Splitter_Mutation_Settings {
	public function __construct() {
		add_filter('order_splitter_settings', array($this, 'replace_legacy_shipping_setting'), 20);
	}

	public function replace_legacy_shipping_setting($settings) {
		if (!is_array($settings)) {
			return $settings;
		}

		$new_settings = array();
		foreach ($settings as $key => $setting) {
			if ('exclude_shipping' === $key) {
				$new_settings['shipping_policy'] = array(
					'name' => esc_html__('Shipping allocation', 'wc-order-splitter'),
					'type' => 'select',
					'desc' => esc_html__('Choose how existing shipping charges are allocated when an order is split.', 'wc-order-splitter'),
					'desc_tip' => esc_html__('Keep on original is the safest default. Proportional divides the historical shipping amount across orders by split quantity. Reference copy adds zero-value shipping references to child orders without duplicating revenue.', 'wc-order-splitter'),
					'id' => 'order_splitter_shipping_policy',
					'default' => WC_Order_Splitter_Order_Mutation_Engine::SHIPPING_KEEP_ON_ORIGINAL,
					'options' => array(
						WC_Order_Splitter_Order_Mutation_Engine::SHIPPING_KEEP_ON_ORIGINAL => esc_html__('Keep charges on original order', 'wc-order-splitter'),
						WC_Order_Splitter_Order_Mutation_Engine::SHIPPING_PROPORTIONAL => esc_html__('Allocate proportionally', 'wc-order-splitter'),
						WC_Order_Splitter_Order_Mutation_Engine::SHIPPING_ZERO_VALUE_REFERENCE => esc_html__('Zero-value reference copies', 'wc-order-splitter'),
					),
				);
				continue;
			}
			$new_settings[$key] = $setting;
		}

		return $new_settings;
	}
}

new WC_Order_Splitter_Mutation_Settings();
