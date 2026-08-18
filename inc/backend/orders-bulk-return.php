<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Orders_Bulk_Return {
	public function __construct() {
		add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_action'));
		add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_action'), 10, 3);
		add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'add_bulk_action'));
		add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_bulk_action'), 10, 3);
		add_action('admin_notices', array($this, 'render_notice'));
	}

	public function add_bulk_action($actions) {
		if (current_user_can('manage_woocommerce')) {
			$actions['return_to_original_order'] = __('Return to original order', 'wc-order-splitter');
		}
		return $actions;
	}

	public function handle_bulk_action($redirect_to, $action, $order_ids) {
		if ('return_to_original_order' !== $action) {
			return $redirect_to;
		}

		$queued = 0;
		$user_id = get_current_user_id();
		$engine = null;

		foreach (array_values(array_unique(array_map('absint', (array) $order_ids))) as $order_id) {
			$order = wc_get_order($order_id);
			if (!$order || !WC_Order_Splitter_Mutation_Support::current_user_can_manage_order($order_id)) {
				continue;
			}

			$original_id = absint($order->get_meta(WC_Order_Splitter_Mutation_Support::META_ORIGINAL_ID, true));
			if (!$original_id) {
				$original_id = absint($order->get_meta('yoos_original_order', true));
			}
			if (!$original_id || !WC_Order_Splitter_Mutation_Support::current_user_can_manage_order($original_id)) {
				continue;
			}

			if (function_exists('as_enqueue_async_action')) {
				$action_id = as_enqueue_async_action(
					'wc_order_splitter_return_order',
					array($order_id, $user_id),
					'wc-order-splitter',
					true
				);
				if ($action_id) {
					$queued++;
				}
				continue;
			}

			try {
				if (!$engine) {
					$engine = new WC_Order_Splitter_Order_Mutation_Engine();
				}
				$original = $engine->return_split_order($order);
				if ($original instanceof WC_Order) {
					WC_Order_Splitter_Charge_Integrity::normalize_after_return($original);
				}
				$queued++;
			} catch (Throwable $error) {
				$order->add_order_note(sprintf(__('Bulk return failed: %s', 'wc-order-splitter'), $error->getMessage()), false);
			}
		}

		return add_query_arg('wc_order_splitter_return_queued', $queued, $redirect_to);
	}

	public function render_notice() {
		if (!isset($_GET['wc_order_splitter_return_queued'])) {
			return;
		}
		$count = absint(wp_unslash($_GET['wc_order_splitter_return_queued']));
		$message = sprintf(
			/* translators: %d: number of return operations. */
			_n('%d return operation was queued.', '%d return operations were queued.', $count, 'wc-order-splitter'),
			$count
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
	}
}

new WooCommerce_Order_Splitter_Orders_Bulk_Return();
