<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Split_Order {

	public function __construct() {
		add_action('wp_ajax_get_order_items', array($this, 'get_order_items_callback'));
		add_action('wp_ajax_split_order', array($this, 'order_splitter_callback'));
	}

	protected function current_user_can_manage_order($order_id) {
		if (current_user_can('shop_manager') && get_option('order_splitter_shop_manager_permission', 'no') === 'no') {
			return false;
		}

		return current_user_can('manage_woocommerce') || current_user_can('edit_shop_order', $order_id);
	}

	protected function is_order_status_allowed($order) {
		$allowed_statuses = get_option('order_splitter_status_allowed', array());

		return in_array('wc-' . $order->get_status(), (array) $allowed_statuses, true);
	}

	protected function get_authorized_order($order_id) {
		$order = wc_get_order($order_id);

		if (!$order) {
			wp_send_json_error(esc_html__('Original order not found', 'wc-order-splitter'));
			wp_die();
		}

		if (!$this->current_user_can_manage_order($order_id)) {
			wp_send_json_error(esc_html__('Insufficient permissions', 'wc-order-splitter'));
			wp_die();
		}

		if (!$this->is_order_status_allowed($order)) {
			wp_send_json_error(esc_html__('This order status is not allowed for splitting.', 'wc-order-splitter'));
			wp_die();
		}

		return $order;
	}

	public function get_order_items_callback() {
		if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'split_order_nonce')) {
			wp_send_json_error('Nonce verification failed');
			wp_die();
		}

		$order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$order = $this->get_authorized_order($order_id);
		$items_data = array();
		
		if ($order) {
			foreach ($order->get_items() as $item_id => $item) {
				$product = $item->get_product();
				$categories = [];
	
				if ($product) {
					// Get categories for the product
					$categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
	
					// If the product is a variation, get categories for the parent product
					if ($product->is_type('variation')) {
						$parent_id = $product->get_parent_id();
						$parent_categories = wp_get_post_terms($parent_id, 'product_cat', ['fields' => 'names']);
						$categories = array_merge($categories, $parent_categories);
					}
	
					// Remove duplicate categories
					$categories = array_unique($categories);
				}
	
				$stock_status = $product ? $product->get_stock_status() : '';
	
				// Map stock status to human-readable format
				$stock_status_map = array(
					'instock'      => __('In stock', 'wc-order-splitter'),
					'outofstock'   => __('Out of stock', 'wc-order-splitter'),
					'onbackorder'  => __('On backorder', 'wc-order-splitter'),
					'onpreorder'   => __('On preorder', 'wc-order-splitter'),
				);
	
				$stock_status_display = isset($stock_status_map[$stock_status]) ? $stock_status_map[$stock_status] : $stock_status;
	
				$items_data[] = array(
					'id' => $item_id,
					'sku' => $product ? $product->get_sku() : '',
					'name' => $item->get_name(),
					'quantity' => $item->get_quantity(),
					'category' => implode(', ', $categories),
					'stock_status' => $stock_status_display,
				);
			}
			wp_send_json_success($items_data);
		} else {
			wp_send_json_error(esc_html__('Order not found', 'wc-order-splitter'));
		}
		wp_die();
	}	   

	private function adjust_original_order_quantities($original_order_id, $split_data) {
		$original_order = wc_get_order($original_order_id);
		if (!$original_order) {
			return esc_html__('Original order not found', 'wc-order-splitter');
		}

		foreach ($original_order->get_items() as $item_id => $item) {
			if (isset($split_data[$item_id]) && isset($split_data[$item_id]['quantity']) && $split_data[$item_id]['quantity'] > 0) {
				$original_quantity = $item->get_quantity();
				$split_quantity = intval($split_data[$item_id]['quantity']);
				$new_quantity = $original_quantity - $split_quantity;

				if ($new_quantity > 0) {
					// Update the item quantity and line totals
					$item->set_quantity($new_quantity);
					$item->set_subtotal($item->get_subtotal() / $original_quantity * $new_quantity);
					$item->set_total($item->get_total() / $original_quantity * $new_quantity);
					$item->save();
				} else {
					// Remove the item from the order if the new quantity is 0
					$original_order->remove_item($item_id);
				}
			}
		}

		if ( 'yes' !== get_option( 'order_splitter_exclude_shipping_fee', 'no' ) ) {
			$parts = array();
			foreach ( $original_order->get_items('line_item') as $li ) {
				$qty = (int) $li->get_quantity();
				if ( $qty <= 0 ) {
					continue;
				}
				$name    = $li->get_name(); // includes variation attrs nicely
				$parts[] = sprintf('%s × %d', $name, $qty);
			}
			$items_summary = implode(', ', $parts);

			// Meta key (label) to use. Keep it simple; change if you need another label.
			$items_meta_key = esc_html__('Items', 'wc-order-splitter');

			foreach ( $original_order->get_shipping_methods() as $shipping_item ) {
				// Remove any existing "Items" meta (case-insensitive)
				foreach ( $shipping_item->get_meta_data() as $meta ) {
					if ( 0 === strcasecmp( (string) $meta->key, $items_meta_key ) ) {
						$shipping_item->delete_meta_data( $meta->key );
					}
				}
				// Add the fresh summary if any items remain
				if ( $items_summary !== '' ) {
					$shipping_item->add_meta_data( $items_meta_key, $items_summary, true );
				}
				$shipping_item->save();
			}
		}

		$original_order->delete_meta_data('_pre_order_marked');
		$original_order->calculate_totals();
		$original_order->save();
	}

	public function order_splitter_callback() {
		if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'split_order_nonce')) {
			wp_send_json_error('Nonce verification failed');
			wp_die();
		}

		$original_order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$original_order = $this->get_authorized_order($original_order_id);

		$split_data_raw = isset($_POST['split_data']) && is_array($_POST['split_data']) ? wc_clean(wp_unslash($_POST['split_data'])) : array();

		$split_data = array();
		$item_quantities = array();
		$total_original_quantity = 0;
		$total_split_quantity = 0;

		foreach ($original_order->get_items() as $item_id => $item) {
			if ($item->get_type() === 'line_item') {
				$item_quantities[$item_id] = (int) $item->get_quantity();
				$total_original_quantity += (int) $item->get_quantity();
			}
		}

		foreach ($split_data_raw as $item_id => $data) {
			$item_id = intval($item_id);
			if (isset($data['quantity']) && isset($data['order'])) {
				$quantity = intval($data['quantity']);
				$order_number = sanitize_text_field($data['order']);
				if ($item_id > 0 && $quantity > 0) {
					if (!isset($item_quantities[$item_id])) {
						wp_send_json_error(esc_html__('Invalid order item selected for splitting.', 'wc-order-splitter'));
						wp_die();
					}

					if ($quantity > $item_quantities[$item_id]) {
						wp_send_json_error(esc_html__('Split quantity cannot exceed the original item quantity.', 'wc-order-splitter'));
						wp_die();
					}

					$split_data[$order_number][$item_id] = array('quantity' => $quantity);
					$total_split_quantity += $quantity;
				}
			}
		}

		if (empty($split_data)) {
			wp_send_json_error(esc_html__('No quantities set for splitting.', 'wc-order-splitter'));
			wp_die();
		}

		if ($total_split_quantity >= $total_original_quantity) {
			wp_send_json_error(esc_html__('You cannot split all of the items in the order.', 'wc-order-splitter'));
			wp_die();
		}

		$new_order_ids = array();

		foreach ($split_data as $order_number => $items) {
			// Create a new order
			$new_order = wc_create_order();
			if (is_wp_error($new_order)) {
				wp_send_json_error('Failed to create a new order: ' . $new_order->get_error_message());
				wp_die();
			}

			$new_order_status = $original_order->get_status();
			$new_order->set_status($new_order_status);
			$new_order->set_address($original_order->get_address('billing'), 'billing');
			$new_order->set_address($original_order->get_address('shipping'), 'shipping');
			$new_order->set_payment_method($original_order->get_payment_method());
			$new_order->set_payment_method_title($original_order->get_payment_method_title());
			
			// Copy customer user
			$new_order->set_customer_id($original_order->get_customer_id());

			// Copy customer note
			if ($original_order->get_customer_note()) {
				$new_order->set_customer_note($original_order->get_customer_note());
			}

			foreach ($original_order->get_items() as $item_id => $item) {
				if (isset($items[$item_id]) && $items[$item_id]['quantity'] > 0) {
					$new_quantity = intval($items[$item_id]['quantity']);

					// Create a new item with the same properties as the original
					$new_item = new WC_Order_Item_Product();
					$new_item->set_props(array(
						'product_id'   => $item->get_product_id(),
						'variation_id' => $item->get_variation_id(),
						'quantity'     => $new_quantity,
						'subtotal'     => $item->get_subtotal() / $item->get_quantity() * $new_quantity,
						'total'        => $item->get_total() / $item->get_quantity() * $new_quantity,
						'name'         => $item->get_name()
					));

					// Copy metadata and tax data
					foreach ($item->get_meta_data() as $meta) {
						$new_item->add_meta_data($meta->key, $meta->value);
					}

					$new_order->add_item($new_item);
				}
			}

			// Conditionally copy shipping line items
			if ( 'yes' !== get_option( 'order_splitter_exclude_shipping_fee', 'no' ) ) {
				// Build "Items" from the CURRENT split order's items
				$parts = array();
				foreach ( $new_order->get_items( 'line_item' ) as $li ) {
					$qty  = (int) $li->get_quantity();
					if ( $qty <= 0 ) {
						continue;
					}
					$name = $li->get_name(); // variation attrs are included in a readable way
					$parts[] = sprintf( '%s × %d', $name, $qty );
				}
				$items_summary = implode( ', ', $parts );

				// Create shipping item(s) with just core props + "Items" meta
				foreach ( $original_order->get_shipping_methods() as $shipping_item ) {
					$new_shipping_item = new WC_Order_Item_Shipping();
					$new_shipping_item->set_props( array(
						'method_title' => $shipping_item->get_method_title(),
						'method_id'    => $shipping_item->get_method_id(),
						'total'        => $shipping_item->get_total(),
						'taxes'        => $shipping_item->get_taxes(),
					) );

					if ( $items_summary !== '' ) {
						$new_shipping_item->add_meta_data( esc_html__('Items', 'wc-order-splitter'), $items_summary, true );
					}

					$new_order->add_item( $new_shipping_item );
				}
			}

			add_filter('woocommerce_email_recipient_new_order', '__return_empty_string');
			add_filter('woocommerce_email_recipient_customer_processing_order', '__return_empty_string');
			add_filter('woocommerce_email_recipient_customer_on_hold_order', '__return_empty_string');
			add_filter('woocommerce_email_recipient_customer_completed_order', '__return_empty_string');

			$new_order->calculate_totals();
			$new_order->save();

			$new_order_ids[] = $new_order->get_id();

			// Set metadata to track original and split orders
			$original_splitted_orders = $original_order->get_meta('yoos_splitted_order', true);
			if (!empty($original_splitted_orders)) {
				$original_splitted_orders .= ',' . $new_order->get_id();
			} else {
				$original_splitted_orders = $new_order->get_id();
			}
			$original_order->update_meta_data('yoos_splitted_order', $original_splitted_orders);
			$original_order->save();

			$new_order->update_meta_data('yoos_original_order', $original_order_id);
			$new_order->save();

			// Add note to the new order
			$new_order->add_order_note(
				sprintf(
					__('This order has been split from the original order #%s.', 'wc-order-splitter'),
					$original_order_id
				),
				false
			);
		}

		// Add note to original order
		if (!empty($new_order_ids)) {
			$original_order->add_order_note(
				sprintf(
					__('This order has been split to order #%s.', 'wc-order-splitter'),
					implode(', ', $new_order_ids)
				),
				false
			);
			$original_order->save();
		}

		// Flatten the split data for adjustment
		$flattened_split_data = [];
		foreach ($split_data as $order_number => $items) {
			foreach ($items as $item_id => $item_data) {
				$flattened_split_data[$item_id] = $item_data;
			}
		}

		$this->adjust_original_order_quantities($original_order_id, $flattened_split_data);

		wp_send_json_success(array('new_order_ids' => $new_order_ids));
		wp_die();
	}
}

// Initialize the plugin
new WooCommerce_Order_Splitter_Split_Order();
