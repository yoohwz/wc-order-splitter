<?php
/**
 * Plugin Name: Order Splitter for WooCommerce
 * Plugin URI: https://github.com/yoohwz/wc-order-splitter
 * Description: Safely split WooCommerce orders by quantity with review, idempotency, HPOS support, and preserved historical order values.
 * Version: 1.4.13
 * Author: YoOhw.com
 * Author URI: https://yoohw.com
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: wc-order-splitter
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('before_woocommerce_init', function() {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

class WooCommerce_Order_Splitter {

	public function __construct() {
		$wcos_plugin_data = get_file_data(__FILE__, array('Version' => 'Version'), false);
		$wcos_plugin_version = isset($wcos_plugin_data['Version']) ? $wcos_plugin_data['Version'] : '';

		if (!defined('WC_ORDER_SPLITTER_VERSION')) {
			define('WC_ORDER_SPLITTER_VERSION', $wcos_plugin_version);
		}

		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));

		$this->includes();
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . 'inc/cores/script.php';
	}

	public function add_action_links($links) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url(admin_url('admin.php?page=wc-settings&tab=orders')),
			esc_html__('Settings', 'wc-order-splitter')
		);
		array_unshift($links, $settings_link);
		return $links;
	}
}

new WooCommerce_Order_Splitter();

register_activation_hook(__FILE__, array('WooCommerce_Order_Splitter_Settings', 'set_default_settings'));
