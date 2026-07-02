<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Return_Order {

	public function __construct() {
		add_action('woocommerce_order_action_yoos_return_order', array($this, 'handle_return_order_action'));
	}

	private function get_orders_list_url() {
		if (
			class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) &&
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url('admin.php?page=wc-orders');
		}

		return admin_url('edit.php?post_type=shop_order');
	}

	public function handle_return_order_action($new_order) {
		$original_order_id = $new_order->get_meta('yoos_original_order');
		if (!$original_order_id) {
			$new_order->add_order_note(__('No original order found for this split order.', 'wc-order-splitter'), false);  // Private note
			return;
		}

		$original_order = wc_get_order($original_order_id);
		if (!$original_order) {
			$new_order->add_order_note(__('Original order does not exist.', 'wc-order-splitter'), false);  // Private note
			return;
		}

		// Process each item in the new (split) order
		foreach ($new_order->get_items() as $item_id => $item) {
			$product_id = $item->get_product_id();
			$split_quantity = $item->get_quantity();
			$split_subtotal = $item->get_subtotal();
			$split_total = $item->get_total();

			// Find or create the item in the original order
			$found = false;
			foreach ($original_order->get_items() as $orig_item_id => $orig_item) {
				if ($orig_item->get_product_id() === $product_id) {
					$original_quantity = $orig_item->get_quantity();
					$original_subtotal = $orig_item->get_subtotal();
					$original_total = $orig_item->get_total();

					// Update quantities and recalculate costs
					$orig_item->set_quantity($original_quantity + $split_quantity);
					$orig_item->set_subtotal($original_subtotal + $split_subtotal);
					$orig_item->set_total($original_total + $split_total);
					$orig_item->save();
					$found = true;
					break;
				}
			}

			if (!$found) {
				// If the item does not exist in the original order, create it
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

		// Update totals for the original order
		$original_order->calculate_totals();
		$original_order->save();

		// Optionally clear items from the new order if needed
		foreach ($new_order->get_items() as $item) {
			$new_order->remove_item($item->get_id());
		}
		$new_order->calculate_totals();
		$new_order->update_status('trash', __('Order moved to trash by user action.', 'wc-order-splitter'));
		$new_order->save();

		// Update the original order meta to remove the returned order ID
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

		// Add a note to the original order indicating the return
		$original_order->add_order_note(sprintf(__('Items returned from order #%s.', 'wc-order-splitter'), $new_order->get_id()), false);
		$new_order->add_order_note(sprintf(__('This order was returned and all items were restored to the original order #%s.', 'wc-order-splitter'), $original_order_id), false);

		wp_safe_redirect(add_query_arg('wcos_action_tip', 'return', $this->get_orders_list_url()));
		exit;
	}
}

// Initialize the plugin
new WooCommerce_Order_Splitter_Return_Order();
