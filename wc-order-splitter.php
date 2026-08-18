<?php
/**
 * Plugin Name: Order Splitter for WooCommerce
 * Description: Split, duplicate, merge, and return WooCommerce orders using integrity-preserving order mutations.
 * Version: 1.5.0
 * Author: YoOhw.com
 * Author URI: https://yoohw.com
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: wc-order-splitter
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
	exit;
}

$wc_order_splitter_plugin_data = get_file_data(__FILE__, array('Version' => 'Version'), false);
define('WC_ORDER_SPLITTER_VERSION', isset($wc_order_splitter_plugin_data['Version']) ? $wc_order_splitter_plugin_data['Version'] : '1.5.0');

add_action('before_woocommerce_init', function() {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

class WooCommerce_Order_Splitter {
	public function __construct() {
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));
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

function wc_order_splitter_missing_woocommerce_notice() {
	if (!current_user_can('activate_plugins')) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__('Order Splitter for WooCommerce requires WooCommerce to be installed and active.', 'wc-order-splitter') . '</p></div>';
}

function wc_order_splitter_bootstrap() {
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'wc_order_splitter_missing_woocommerce_notice');
		return;
	}
	new WooCommerce_Order_Splitter();
}
add_action('plugins_loaded', 'wc_order_splitter_bootstrap', 20);

function wc_order_splitter_activate() {
	$defaults = array(
		'order_splitter_status_allowed' => array('wc-processing'),
		'order_splitter_shipping_policy' => 'keep_on_original',
		'order_splitter_disable_split_order_email' => 'none',
		'order_splitter_shop_manager_permission' => 'no',
		'order_splitter_order_label' => 'yes',
	);

	foreach ($defaults as $key => $value) {
		if (false === get_option($key, false)) {
			add_option($key, $value);
		}
	}
}
register_activation_hook(__FILE__, 'wc_order_splitter_activate');
