<?php

defined('ABSPATH') || exit;

/**
 * Shared admin dependency for all Order Splitter modal workflows.
 *
 * WooCommerce registers wc-backbone-modal as a supported admin script handle.
 * This bridge is loaded only on order-edit screens and is enqueued before the
 * Split, strategy Split, and Duplicate clients.
 */
final class WCOS_Admin_Backbone_Modal_Assets {
	public static function bootstrap() {
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 5);
	}

	public static function enqueue() {
		if (!self::is_order_edit_screen()) {
			return;
		}

		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		wp_enqueue_script('wc-backbone-modal');
		wp_enqueue_script(
			'wcos-admin-backbone-modal',
			plugins_url('js/p2-backbone-modal.js', $plugin_file),
			array('jquery', 'wc-backbone-modal'),
			WC_ORDER_SPLITTER_VERSION,
			true
		);
	}

	private static function is_order_edit_screen() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) {
			return false;
		}
		$hpos_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
		$hpos_order_id = isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0;
		return 'shop_order' === $screen->id || ($hpos_screen === $screen->id && $hpos_order_id > 0);
	}
}
