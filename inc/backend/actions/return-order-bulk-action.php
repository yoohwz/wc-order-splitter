<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Return_Order_Bulk_Action {

	public function __construct() {
		add_action('wp_ajax_yoos_handle_bulk_action', array($this, 'handle_ajax_bulk_action'));
		add_action('admin_notices', array($this, 'bulk_action_admin_notice'));
	}

	public function handle_ajax_bulk_action() {
		check_ajax_referer('yoos_handle_bulk_action', 'security');

		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(__('Insufficient permissions.', 'wc-order-splitter'));
			return;
		}

		$order_ids = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('absint', wp_unslash($_POST['order_ids'])) : array();

		if (empty($order_ids)) {
			wp_send_json_error(__('No orders selected.', 'wc-order-splitter'));
			return;
		}

		$processed_count = 0;

		foreach ($order_ids as $order_id) {
			$new_order = wc_get_order($order_id);
			if (!$new_order) {
				continue;
			}

			$original_order_id = $new_order->get_meta('yoos_original_order');
			if (!$original_order_id) {
				continue;
			}

			$original_order = wc_get_order($original_order_id);
			if (!$original_order) {
				continue;
			}

			if (!current_user_can('edit_shop_order', $new_order->get_id()) || !current_user_can('edit_shop_order', $original_order->get_id())) {
				continue;
			}

			foreach ($new_order->get_items() as $item_id => $item) {
				$product_id = $item->get_product_id();
				$split_quantity = $item->get_quantity();
				$split_subtotal = $item->get_subtotal();
				$split_total = $item->get_total();

				$found = false;
				foreach ($original_order->get_items() as $orig_item_id => $orig_item) {
					if ($orig_item->get_product_id() === $product_id) {
						$original_quantity = $orig_item->get_quantity();
						$original_subtotal = $orig_item->get_subtotal();
						$original_total = $orig_item->get_total();

						$orig_item->set_quantity($original_quantity + $split_quantity);
						$orig_item->set_subtotal($original_subtotal + $split_subtotal);
						$orig_item->set_total($original_total + $split_total);
						$orig_item->save();

						$found = true;
						break;
					}
				}

				if (!$found) {
					$new_item = new WC_Order_Item_Product();
					$new_item->set_props(array(
						'product_id'   => $product_id,
						'variation_id' => $item->get_variation_id(),
						'quantity'     => $split_quantity,
						'subtotal'     => $split_subtotal,
						'total'        => $split_total,
						'name'         => $item->get_name()
					));
					$original_order->add_item($new_item);
				}
			}

			$original_order->calculate_totals();
			$original_order->save();

			foreach ($new_order->get_items() as $item) {
				$new_order->remove_item($item->get_id());
			}
			$new_order->calculate_totals();
			$new_order->update_status('trash', __('Order moved to trash by user action.', 'wc-order-splitter'));
			$new_order->save();

			$original_splitted_orders = $original_order->get_meta('yoos_splitted_order', true);
			if (!empty($original_splitted_orders)) {
				$original_splitted_orders = array_map('intval', explode(',', $original_splitted_orders));
				$returned_order_id = $new_order->get_id();
				if (($key = array_search($returned_order_id, $original_splitted_orders)) !== false) {
					unset($original_splitted_orders[$key]);
					$original_order->update_meta_data('yoos_splitted_order', implode(',', $original_splitted_orders));
				}
			}
			$original_order->save();

			$original_order->add_order_note(sprintf(__('Items returned from order #%s.', 'wc-order-splitter'), $new_order->get_id()), false);
			$new_order->add_order_note(sprintf(__('This order was returned and all items were restored to the original order #%s.', 'wc-order-splitter'), $original_order_id), false);

			$processed_count++;
		}

		wp_send_json_success(sprintf(__('Successfully returned %d orders to their original orders.', 'wc-order-splitter'), $processed_count));
	}

	public function bulk_action_admin_notice() {
		if (!empty($_REQUEST['bulk_returned_orders'])) {
			$count = intval($_REQUEST['bulk_returned_orders']);
			printf('<div id="message" class="updated fade"><p>' .
				_n('%s order has been returned to its original order.',
					'%s orders have been returned to their original orders.', $count, 'wc-order-splitter') . '</p></div>', $count);
		}
	}
}

// Initialize the plugin
new WooCommerce_Order_Splitter_Return_Order_Bulk_Action();
