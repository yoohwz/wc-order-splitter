<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Order_Splitter_Script {
	private $version;

	public function __construct() {
		$this->version = WC_ORDER_SPLITTER_VERSION;

		add_action('admin_init', [$this, 'guard_unavailable_settings_sections'], 1);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('admin_init', [$this, 'check_version']);
		add_action('admin_notices', [$this, 'render_safety_notice']);

		$this->includes();
	}

	public function guard_unavailable_settings_sections() {
		if (!is_admin() || !isset($_GET['page'], $_GET['tab'], $_GET['section'])) {
			return;
		}

		$page = sanitize_key(wp_unslash($_GET['page']));
		$tab = sanitize_key(wp_unslash($_GET['tab']));
		$section = sanitize_key(wp_unslash($_GET['section']));
		if ('wc-settings' !== $page || 'orders' !== $tab) {
			return;
		}

		$premium_sections = array('cancellation', 'returns', 'reorder', 'appearance');
		if (!in_array($section, $premium_sections, true)) {
			return;
		}

		$settings_available = class_exists('WC_Order_Cancellation_Return_Premium_Settings');
		$appearance_available = class_exists('WC_Order_Cancellation_Return_Premium_Settings_Appearance');
		if ($settings_available && ('appearance' !== $section || $appearance_available)) {
			return;
		}

		$_GET['section'] = 'order_splitter';
	}

	public function enqueue_scripts() {
		if (!is_admin()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$is_order_list = $this->is_order_list_screen($screen);
		$is_orders_settings = $this->is_orders_settings_screen($screen);

		if ($is_order_list) {
			wp_enqueue_script('wc-order-splitter-orders-js', plugin_dir_url(__FILE__) . '../../js/orders.js', array('jquery'), WC_ORDER_SPLITTER_VERSION, true);
		}

		if ($is_orders_settings) {
			wp_enqueue_style('split-order-css', plugin_dir_url(__FILE__) . '../../css/style.css', array(), WC_ORDER_SPLITTER_VERSION);
		}
	}

	private function get_hpos_order_screen_id() {
		return function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
	}

	private function is_order_edit_screen($screen) {
		if (!$screen) {
			return false;
		}

		if ('shop_order' === $screen->id) {
			return true;
		}

		return $this->get_hpos_order_screen_id() === $screen->id && isset($_GET['id']) && absint(wp_unslash($_GET['id'])) > 0;
	}

	private function is_order_list_screen($screen) {
		if (!$screen) {
			return false;
		}

		if ('edit-shop_order' === $screen->id) {
			return true;
		}

		return $this->get_hpos_order_screen_id() === $screen->id && empty($_GET['id']);
	}

	private function is_orders_settings_screen($screen) {
		if (!$screen || 'woocommerce_page_wc-settings' !== $screen->id) {
			return false;
		}

		$current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

		return 'orders' === $current_tab;
	}

	public function render_safety_notice() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (!$this->is_order_edit_screen($screen) && !$this->is_order_list_screen($screen) && !$this->is_orders_settings_screen($screen)) {
			return;
		}

		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__('Order Splitter mutation actions are temporarily disabled in this safety release while order, stock, tax, and shipping integrity safeguards are being applied.', 'wc-order-splitter');
		echo '</p></div>';
	}

	public function check_version() {
		if (get_option('wc_order_splitter_version') !== $this->version) {
			update_option('wc_order_splitter_version', $this->version);
		}
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '../backend/settings.php';
		include_once plugin_dir_path(__FILE__) . '../backend/orders.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-woo-settings-tabs-reorder.php';
	}
}

new WC_Order_Splitter_Script();
