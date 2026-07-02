<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Split_Order_By_Stock_Status extends WooCommerce_Order_Splitter_Split_Order {

	public function __construct() {
		add_action('wp_ajax_split_order_by_stock_status', array($this, 'order_splitter_by_stock_status_callback'));
	}

	public function order_splitter_by_stock_status_callback() {
		if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'split_order_nonce')) {
			wp_send_json_error('Nonce verification failed');
			wp_die();
		}

		$original_order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$original_order = $this->get_authorized_order($original_order_id);

		$stock_status_orders = array();

		// Group items by stock status
		foreach ($original_order->get_items() as $item_id => $item) {
			$product = $item->get_product();
			$stock_status = $product->get_stock_status();

			if (!isset($stock_status_orders[$stock_status])) {
				$stock_status_orders[$stock_status] = array();
			}
			$stock_status_orders[$stock_status][$item_id] = $item;
		}

		// Check if there is only one stock status in the order
		if (count($stock_status_orders) <= 1) {
			wp_send_json_error(esc_html__('There is no different stock status to split.', 'wc-order-splitter'));
			wp_die();
		}

		$new_order_ids = array();

		// Create new orders for each stock status, except the first stock status which will remain in the original order
		$first_stock_status = true;
		foreach ($stock_status_orders as $stock_status => $items) {
			if ($first_stock_status) {
				$first_stock_status = false;
				continue;
			}

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
			$new_order->set_customer_id($original_order->get_customer_id());

			if ($original_order->get_customer_note()) {
				$new_order->set_customer_note($original_order->get_customer_note());
			}

			foreach ($items as $item_id => $item) {
				$new_item = new WC_Order_Item_Product();
				$new_item->set_props(array(
					'product_id'   => $item->get_product_id(),
					'variation_id' => $item->get_variation_id(),
					'quantity'     => $item->get_quantity(),
					'subtotal'     => $item->get_subtotal(),
					'total'        => $item->get_total(),
					'name'         => $item->get_name()
				));

				foreach ($item->get_meta_data() as $meta) {
					$new_item->add_meta_data($meta->key, $meta->value);
				}

				$new_order->add_item($new_item);
			}

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

			$new_order->add_order_note(
				sprintf(
					__('This order has been split from the original order #%s.', 'wc-order-splitter'),
					$original_order_id
				),
				false
			);
		}

		// Remove items of other stock statuses from the original order
		$first_stock_status_items = reset($stock_status_orders);
		foreach ($original_order->get_items() as $item_id => $item) {
			if (!isset($first_stock_status_items[$item_id])) {
				$original_order->remove_item($item_id);
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

		wp_send_json_success(array('new_order_ids' => $new_order_ids));
		wp_die();
	}
}

// Initialize the plugin
new WooCommerce_Order_Splitter_Split_Order_By_Stock_Status();
