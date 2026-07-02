<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Order_Splitter_Script {
	private $version;

	public function __construct() {
		$this->version = WC_ORDER_SPLITTER_VERSION;

		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('admin_init', [$this, 'check_version']);

		$this->includes();
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

		// Prevent shop manager from running the scripts if the permission is set to 'no'
		if (current_user_can('shop_manager') && get_option('order_splitter_shop_manager_permission', 'no') === 'no') {
			return;
		}

		$translation_array = array(
			'errorOccurredFetchingOrder' => esc_html__('Error occurred while fetching order items.', 'wc-order-splitter'),
			'unableToFetch' => esc_html__('Unable to fetch order items.', 'wc-order-splitter'),
			'errorOccurred' => esc_html__('Error occurred while fetching order items.', 'wc-order-splitter'),
			'orderSplitSuccess' => esc_html__('Order split successfully. New order ID:', 'wc-order-splitter'),
			'failedToSplitOrder' => esc_html__('Failed to split order.', 'wc-order-splitter'),
			'product' => esc_html__('Product', 'wc-order-splitter'),
			'order' => esc_html__('Order', 'wc-order-splitter'),
			'newOrder' => esc_html__('New order #', 'wc-order-splitter'),
			'quantity' => esc_html__('Qty', 'wc-order-splitter'),
			'splitQuantity' => esc_html__('Split Qty', 'wc-order-splitter'),
			'splitIt' => esc_html__('Split it', 'wc-order-splitter'),
			'default' => esc_html__('Default', 'wc-order-splitter'),
			'unit' => esc_html__('Unit [Premium]', 'wc-order-splitter'),
			'group' => esc_html__('Group [Premium]', 'wc-order-splitter'),
			'inGroup' => esc_html__('Item (In group) [Premium]', 'wc-order-splitter'),
			'nonGroup' => esc_html__('Item (Non-group) [Premium]', 'wc-order-splitter'),
			'category' => esc_html__('Category', 'wc-order-splitter'),
			'stockStatus' => esc_html__('Stock status', 'wc-order-splitter'),
			'tag' => esc_html__('Tag [Premium]', 'wc-order-splitter'),
			'attribute' => esc_html__('Attribute [Premium]', 'wc-order-splitter'),
			'bundle' => esc_html__('Bundle [Premium]', 'wc-order-splitter'),
			'vendor' => esc_html__('Vendor [Premium]', 'wc-order-splitter'),
			'cancel' => esc_html__('Cancel', 'wc-order-splitter'),
			'splitting' => esc_html__('Splitting...', 'wc-order-splitter'),
			'returnToOriginalOrder' => esc_html__('Return to original order', 'wc-order-splitter'),
			'pleaseSelectAtLeastOneOrder' => esc_html__('Please select at least one order.', 'wc-order-splitter'),
			'premiumModeHintTitle' => esc_html__('Need more split modes?', 'wc-order-splitter'),
			'premiumModeHint' => esc_html__('Premium adds automated rules, product groups, tags, attributes, bundles, vendors, and email controls for stores that split orders often.', 'wc-order-splitter'),
			'splitSuccessTip' => esc_html__('Split completed. If this is a recurring workflow, Premium can automate the same rules and customer emails.', 'wc-order-splitter'),
			'learnMore' => esc_html__('Learn more', 'wc-order-splitter'),
			'dismiss' => esc_html__('Dismiss', 'wc-order-splitter'),
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('split_order_nonce'),
			'bulkReturnOrderNonce' => wp_create_nonce('yoos_handle_bulk_action'),
			'premiumUrl' => esc_url('https://yoohw.com/product/woocommerce-advanced-order-actions/'),
		);

		$post_action_tip_array = array(
			'comparePremium' => esc_html__('Compare Premium', 'wc-order-splitter'),
			'premiumUrl' => esc_url('https://yoohw.com/product/woocommerce-advanced-order-actions/'),
			'actionTips' => array(
				'split' => esc_html__('Order split successfully. Premium can automate this workflow with rule-based splitting and email controls.', 'wc-order-splitter'),
				'duplicate' => esc_html__('Order duplicated successfully. Premium adds status rules, bulk workflows, and automation for repeated order operations.', 'wc-order-splitter'),
				'merge' => esc_html__('Order merged successfully. Premium adds status rules, bulk workflows, and automation for repeated order operations.', 'wc-order-splitter'),
				'return' => esc_html__('Split order returned successfully. Premium adds automated rules and email controls for repeated split-order workflows.', 'wc-order-splitter'),
			),
		);

		if ($is_order_edit || $is_order_list) {
			wp_enqueue_script('wcos-post-action-tip-js', plugin_dir_url(__FILE__) . '../../js/post-action-tip.js', array('jquery'), '1.0.0', true);
			wp_localize_script('wcos-post-action-tip-js', 'wcosPostActionTip', $post_action_tip_array);
		}

		if ($is_order_edit) {
			wp_enqueue_script('split-order-js', plugin_dir_url(__FILE__) . '../../js/split-table.js', array('jquery'), '1.7', true);
			wp_localize_script('split-order-js', 'splitOrderTranslations', $translation_array);
		}

		if ($is_order_list && current_user_can('manage_woocommerce')) {
			wp_enqueue_script('bulk-return-order-js', plugin_dir_url(__FILE__) . '../../js/bulk-return-action.js', array('jquery'), '1.3', true);
			wp_localize_script('bulk-return-order-js', 'splitOrderTranslations', $translation_array);
		}

		if ($is_order_edit || $is_orders_settings) {
			wp_enqueue_style('split-order-css', plugin_dir_url(__FILE__) . '../../css/style.css', array(), '2.1');
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

	public function check_version() {
		if ( get_option('wc_order_splitter_version') != $this->version ) {
			WC_Order_Splitter_Push_Subscription::push_subscription();
		}

		update_option('wc_order_splitter_version', $this->version);
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '../backend/settings.php';
		include_once plugin_dir_path(__FILE__) . '../backend/orders.php';
		include_once plugin_dir_path(__FILE__) . '../backend/orders-bulk-return.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-split-button.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-return-option.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-duplicate-option.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-merge-option.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-woo-settings-tabs-reorder.php';
		include_once plugin_dir_path(__FILE__) . 'api/push-subscription.php';
	}
}

new WC_Order_Splitter_Script();
