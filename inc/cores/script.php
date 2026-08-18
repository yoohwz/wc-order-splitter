<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Order_Splitter_Script {
	private $version;

	public function __construct() {
		$this->version = WC_ORDER_SPLITTER_VERSION;
		$this->includes();
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('admin_init', array($this, 'check_version'));
	}

	public function enqueue_scripts() {
		if (!is_admin()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$is_order_edit = $this->is_order_edit_screen($screen);
		$is_order_list = $this->is_order_list_screen($screen);
		$is_orders_settings = $this->is_orders_settings_screen($screen);

		if (!$is_order_edit && !$is_order_list && !$is_orders_settings) {
			return;
		}

		if ($is_order_edit || $is_orders_settings) {
			wp_enqueue_style('split-order-css', plugin_dir_url(__FILE__) . '../../css/style.css', array(), $this->version);
		}

		$post_action_tip_array = array(
			'comparePremium' => esc_html__('Compare Premium', 'wc-order-splitter'),
			'premiumUrl' => esc_url('https://yoohw.com/product/woocommerce-advanced-order-actions/'),
			'actionTips' => array(
				'split' => esc_html__('Order split completed.', 'wc-order-splitter'),
				'duplicate' => esc_html__('Order duplicated successfully.', 'wc-order-splitter'),
				'merge' => esc_html__('Order merged successfully.', 'wc-order-splitter'),
				'return' => esc_html__('Split order returned successfully.', 'wc-order-splitter'),
			),
		);

		if ($is_order_edit || $is_order_list) {
			wp_enqueue_script('wcos-post-action-tip-js', plugin_dir_url(__FILE__) . '../../js/post-action-tip.js', array('jquery'), $this->version, true);
			wp_localize_script('wcos-post-action-tip-js', 'wcosPostActionTip', $post_action_tip_array);
		}

		if (!$is_order_edit) {
			return;
		}

		wp_enqueue_script('split-order-js', plugin_dir_url(__FILE__) . '../../js/split-table.js', array('jquery'), $this->version, true);
		wp_localize_script('split-order-js', 'splitOrderTranslations', array(
			'errorOccurredFetchingOrder' => esc_html__('Error occurred while fetching order items.', 'wc-order-splitter'),
			'unableToFetch' => esc_html__('Unable to fetch order items.', 'wc-order-splitter'),
			'errorOccurred' => esc_html__('The order operation could not be completed.', 'wc-order-splitter'),
			'orderSplitSuccess' => esc_html__('Order split successfully. New order IDs:', 'wc-order-splitter'),
			'failedToSplitOrder' => esc_html__('Failed to split order.', 'wc-order-splitter'),
			'product' => esc_html__('Product', 'wc-order-splitter'),
			'order' => esc_html__('Destination', 'wc-order-splitter'),
			'newOrder' => esc_html__('New order ', 'wc-order-splitter'),
			'quantity' => esc_html__('Qty', 'wc-order-splitter'),
			'splitQuantity' => esc_html__('Split Qty', 'wc-order-splitter'),
			'default' => esc_html__('Quantity', 'wc-order-splitter'),
			'category' => esc_html__('Category', 'wc-order-splitter'),
			'stockStatus' => esc_html__('Stock status', 'wc-order-splitter'),
			'cancel' => esc_html__('Cancel', 'wc-order-splitter'),
			'splitting' => esc_html__('Splitting…', 'wc-order-splitter'),
			'loadingItems' => esc_html__('Loading order items…', 'wc-order-splitter'),
			'splitMethod' => esc_html__('Split method', 'wc-order-splitter'),
			'previewSplit' => esc_html__('Preview split', 'wc-order-splitter'),
			'previewing' => esc_html__('Building preview…', 'wc-order-splitter'),
			'previewRequired' => esc_html__('Review a preview before applying any order changes.', 'wc-order-splitter'),
			'previewTitle' => esc_html__('Split preview', 'wc-order-splitter'),
			'previewPolicy' => esc_html__('Shipping policy', 'wc-order-splitter'),
			'previewAmount' => esc_html__('Product amount', 'wc-order-splitter'),
			'confirmWarning' => esc_html__('Confirm only after reviewing the allocation. The operation will preserve historical taxes, stock bookkeeping, currency, and aggregate order totals.', 'wc-order-splitter'),
			'confirmSplit' => esc_html__('Confirm split', 'wc-order-splitter'),
			'changeAllocation' => esc_html__('Change allocation', 'wc-order-splitter'),
			'previewReady' => esc_html__('Preview ready. Review the allocation before confirming.', 'wc-order-splitter'),
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('split_order_nonce'),
		));
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

	public function check_version() {
		if (false === get_option('order_splitter_shipping_policy', false)) {
			add_option('order_splitter_shipping_policy', WC_Order_Splitter_Order_Mutation_Engine::SHIPPING_KEEP_ON_ORIGINAL);
		}
		if (get_option('wc_order_splitter_version') !== $this->version) {
			update_option('wc_order_splitter_version', $this->version);
		}
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . 'order-mutation/class-mutation-support.php';
		include_once plugin_dir_path(__FILE__) . 'order-mutation/class-operation-journal.php';
		include_once plugin_dir_path(__FILE__) . 'order-mutation/class-order-item-cloner.php';
		include_once plugin_dir_path(__FILE__) . 'order-mutation/class-charge-integrity.php';
		include_once plugin_dir_path(__FILE__) . 'order-mutation/class-order-mutation-engine.php';
		include_once plugin_dir_path(__FILE__) . '../backend/settings.php';
		include_once plugin_dir_path(__FILE__) . 'order-mutation/class-mutation-settings.php';
		include_once plugin_dir_path(__FILE__) . '../backend/actions/order-mutation-controller.php';
		include_once plugin_dir_path(__FILE__) . '../backend/orders.php';
		include_once plugin_dir_path(__FILE__) . '../backend/orders-bulk-return.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-split-button.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-return-option.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-duplicate-option.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-merge-option.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-woo-settings-tabs-reorder.php';
	}
}

new WC_Order_Splitter_Script();
