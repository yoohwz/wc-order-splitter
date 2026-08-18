<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Edit_Order_Merge_Option {
	public function __construct() {
		add_filter('woocommerce_order_actions', array($this, 'add_merge_order_action'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_merge_panel'));
	}

	public function add_merge_order_action($actions) {
		global $theorder;

		if (!$theorder instanceof WC_Order || !WC_Order_Splitter_Mutation_Support::current_user_can_manage_order($theorder->get_id())) {
			return $actions;
		}
		if ('trash' === $theorder->get_status() || $theorder->get_meta(WC_Order_Splitter_Mutation_Support::META_MERGED_INTO, true)) {
			return $actions;
		}

		$actions['yoos_merge_order'] = __('Merge this order to…', 'wc-order-splitter');
		return $actions;
	}

	public function enqueue_scripts() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$this->is_order_edit_screen($screen)) {
			return;
		}

		wp_enqueue_script(
			'wc-order-splitter-merge-order',
			plugin_dir_url(__FILE__) . '../../js/merge-action.js',
			array('jquery'),
			WC_ORDER_SPLITTER_VERSION,
			true
		);
	}

	public function render_merge_panel($order) {
		if (!$order instanceof WC_Order || !WC_Order_Splitter_Mutation_Support::current_user_can_manage_order($order->get_id())) {
			return;
		}

		wp_localize_script('wc-order-splitter-merge-order', 'wcOrderSplitterMerge', array(
			'orderId' => $order->get_id(),
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('yoos_merge_order_nonce'),
			'texts' => array(
				'title' => __('Merge order preview', 'wc-order-splitter'),
				'targetLabel' => __('Target order ID', 'wc-order-splitter'),
				'preview' => __('Preview merge', 'wc-order-splitter'),
				'confirm' => __('Confirm merge', 'wc-order-splitter'),
				'cancel' => __('Cancel', 'wc-order-splitter'),
				'loading' => __('Checking compatibility…', 'wc-order-splitter'),
				'source' => __('Source', 'wc-order-splitter'),
				'target' => __('Target', 'wc-order-splitter'),
				'combinedTotal' => __('Combined total', 'wc-order-splitter'),
				'invalid' => __('Enter a valid target order ID.', 'wc-order-splitter'),
				'failed' => __('The merge could not be completed.', 'wc-order-splitter'),
			),
		));

		echo '<div id="wc-order-splitter-merge-panel" class="wc-order-splitter-panel" role="region" aria-label="' . esc_attr__('Merge order preview', 'wc-order-splitter') . '" hidden>';
		echo '<h3>' . esc_html__('Merge order preview', 'wc-order-splitter') . '</h3>';
		echo '<label for="wc-order-splitter-merge-target">' . esc_html__('Target order ID', 'wc-order-splitter') . '</label> ';
		echo '<input id="wc-order-splitter-merge-target" type="number" min="1" inputmode="numeric" /> ';
		echo '<button type="button" class="button wc-order-splitter-merge-preview">' . esc_html__('Preview merge', 'wc-order-splitter') . '</button> ';
		echo '<button type="button" class="button wc-order-splitter-merge-cancel">' . esc_html__('Cancel', 'wc-order-splitter') . '</button>';
		echo '<div class="wc-order-splitter-merge-result" aria-live="polite"></div>';
		echo '</div>';
	}

	private function is_order_edit_screen($screen) {
		if (!$screen) {
			return false;
		}
		if ('shop_order' === $screen->id) {
			return true;
		}
		$hpos_id = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
		return $hpos_id === $screen->id && isset($_GET['id']) && absint(wp_unslash($_GET['id'])) > 0;
	}
}

new WooCommerce_Order_Splitter_Edit_Order_Merge_Option();
