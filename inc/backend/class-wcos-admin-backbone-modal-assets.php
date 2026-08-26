<?php

defined('ABSPATH') || exit;

/**
 * Shared admin dependency for all Order Splitter modal workflows.
 *
 * WooCommerce registers wc-backbone-modal as a supported admin script handle.
 * This bridge is loaded on order-edit screens and on the gate-enabled Bulk
 * Return Orders list, then becomes an explicit workflow-client dependency.
 */
final class WCOS_Admin_Backbone_Modal_Assets {
	public static function bootstrap() {
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 5);
		add_action('admin_enqueue_scripts', array(__CLASS__, 'bind_workflow_dependencies'), 100);
	}

	public static function enqueue() {
		if (!self::is_order_edit_screen()) {
			return;
		}

		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		wp_enqueue_script('wc-backbone-modal');
		wp_enqueue_style(
			'wcos-admin-backbone-modal',
			plugins_url('css/p2-backbone-modal.css', $plugin_file),
			array(),
			WC_ORDER_SPLITTER_VERSION
		);
		wp_enqueue_script(
			'wcos-admin-backbone-modal',
			plugins_url('js/p2-backbone-modal.js', $plugin_file),
			array('jquery', 'wc-backbone-modal'),
			WC_ORDER_SPLITTER_VERSION,
			true
		);
	}

	public static function bind_workflow_dependencies() {
		if (!self::is_order_edit_screen()) {
			return;
		}

		$scripts = wp_scripts();
		if (!$scripts || !isset($scripts->registered['wcos-admin-backbone-modal'])) {
			return;
		}

		foreach (array('wcos-split-admin', 'wcos-duplicate-admin', 'wcos-split-strategy-admin', 'wcos-merge-admin', 'wcos-return-admin', 'wcos-bulk-return-admin') as $handle) {
			if (!isset($scripts->registered[$handle])) {
				continue;
			}
			$deps = (array) $scripts->registered[$handle]->deps;
			if (!in_array('wcos-admin-backbone-modal', $deps, true)) {
				$deps[] = 'wcos-admin-backbone-modal';
				$scripts->registered[$handle]->deps = array_values(array_unique($deps));
			}
		}
	}

	private static function is_order_edit_screen() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) {
			return false;
		}
		$hpos_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
		$hpos_order_id = isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0;
		$is_edit = 'shop_order' === $screen->id || ($hpos_screen === $screen->id && $hpos_order_id > 0);
		$is_bulk_list = class_exists('WCOS_Feature_Gates')
			&& WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN)
			&& ('edit-shop_order' === $screen->id || ($hpos_screen === $screen->id && 0 === $hpos_order_id));
		return $is_edit || $is_bulk_list;
	}
}
