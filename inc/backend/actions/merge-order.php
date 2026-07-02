<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Merge_Order {

	public function __construct() {
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('wp_ajax_yoos_merge_order_action', array($this, 'process_merge_order_ajax'));
	}
	
	public function enqueue_scripts() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (!$this->is_order_edit_screen($screen)) {
			return;
		}
		
		wp_enqueue_script(
			'wc-order-splitter-merge-order',
			plugin_dir_url(__FILE__) . '../../../js/merge-action.js',
			array('jquery'),
			'1.0.1',
			true
		);
		
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'localize_order_splitter_script'));
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
	
	public function localize_order_splitter_script($order) {
		if ($order instanceof WC_Order) {
			wp_localize_script('wc-order-splitter-merge-order', 'wc_order_splitter_params', array(
				'order_id'           => $order->get_id(),
				'ajax_url'           => admin_url('admin-ajax.php'),
				'nonce'              => wp_create_nonce('yoos_merge_order_nonce'),
				'texts'              => array(
					'promptMessage'   => __('Enter the order ID to merge this order into:', 'wc-order-splitter'),
					'cancelMessage'   => __('Order merge action canceled.', 'wc-order-splitter'),
					'invalidMessage'  => __('Please enter a valid numeric order ID.', 'wc-order-splitter'),
					'successMessage'  => __('Order merged successfully to the order #', 'wc-order-splitter'),
					'errorMessage'    => __('Failed to merge orders.', 'wc-order-splitter'),
					'ajaxFailMessage' => __('Failed to process merge order action.', 'wc-order-splitter')
				)
			));
		}
	}
	
	// Process the merge order action via AJAX
	public function process_merge_order_ajax() {
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'yoos_merge_order_nonce')) {
			wp_send_json_error(array('message' => 'Invalid request. Nonce verification failed.'));
			exit;
		}
	
		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Permission denied.'));
			exit;
		}
	
		// Sanitize input values
		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		if (!$order_id) {
			wp_send_json_error(array('message' => 'Invalid current order ID.'));
			exit;
		}
	
		$merge_to_order_id = isset($_POST['merge_order_id']) ? absint($_POST['merge_order_id']) : 0;
	
		// Validate target order
		$target_order = wc_get_order($merge_to_order_id);
		if (!$target_order) {
			wp_send_json_error(array('message' => __('Invalid target order ID.', 'wc-order-splitter')));
			exit;
		}
	
		$current_order = wc_get_order($order_id);
		if (!$current_order) {
			wp_send_json_error(array('message' => 'Invalid current order ID.'));
			exit;
		}
	
		// Get all target order items as a list of product IDs and their quantities
		$target_order_items = $target_order->get_items();
		$target_items_map = [];
	
		foreach ($target_order_items as $item_id => $target_item) {
			$product_id = $target_item->get_product_id();
			if ($product_id) {
				$target_items_map[$product_id] = $target_item;
			}
		}
	
		// Copy or update product items from current order to target order
		foreach ($current_order->get_items() as $item_id => $item) {
			$product_id = $item->get_product_id();
			if (isset($target_items_map[$product_id])) {
				// If the product exists in the target order, update its quantity
				$existing_target_item = $target_items_map[$product_id];
				$new_quantity = $existing_target_item->get_quantity() + $item->get_quantity();
				$existing_target_item->set_quantity($new_quantity);
				$existing_target_item->save();
			} else {
				// Create a new product item in the target order if it doesn't exist
				$new_item = new WC_Order_Item_Product();
				$new_item->set_product_id($item->get_product_id());
				$new_item->set_quantity($item->get_quantity());
				$new_item->set_total($item->get_total());
				$new_item->set_subtotal($item->get_subtotal());
				$new_item->set_name($item->get_name());
		
				// Copy item meta data
				foreach ($item->get_meta_data() as $meta) {
					$new_item->add_meta_data($meta->key, $meta->value, true);
				}
		
				// Add the new product item to the target order
				$target_order->add_item($new_item);
				$new_item->save(); // Save the item explicitly
			}
		}
	
		// Recalculate and save the target order's totals
		$target_order->calculate_totals();
		$target_order->add_order_note(sprintf(__('Merged items from order #%d', 'wc-order-splitter'), $order_id));
		$target_order->update_meta_data('yoos_merged_order', $order_id);
		$target_order->save();
	
		// Create a summary of all items in the target order for the shipping meta
		$item_summary = [];
		foreach ($target_order->get_items() as $item) {
			$item_summary[] = $item->get_name() . ' × ' . $item->get_quantity();
		}
		$item_summary_text = implode(', ', $item_summary);

		// Update the "Items" meta in the first shipping item with the consolidated item list
		$shipping_items = $target_order->get_items('shipping');
		if (!empty($shipping_items)) {
			$target_shipping_item = reset($shipping_items); // Get the first shipping item

			// Retrieve the existing "Items" meta value, if any, and append the new summary
			$existing_items_meta = $target_shipping_item->get_meta('Items', true);
			if ($existing_items_meta) {
				$updated_items_meta = $item_summary_text; // Replace the entire "Items" meta with the new summary
			} else {
				$updated_items_meta = $item_summary_text;
			}

			// Update the "Items" meta with the consolidated item list
			$target_shipping_item->update_meta_data('Items', $updated_items_meta);
			$target_shipping_item->save();
		}
	
		// Update the status of the current order to trash
		$current_order->update_status('trash');
	
		// Get the edit URL of the merged order for redirection
		$redirect_url = add_query_arg('wcos_action_tip', 'merge', $target_order->get_edit_order_url());
	
		// Send the success response with the redirect URL
		wp_send_json_success(array(
			'message' => __('Order merged successfully!', 'wc-order-splitter'),
			'redirect_url' => $redirect_url
		));
	}
	
}

new WooCommerce_Order_Splitter_Merge_Order();
