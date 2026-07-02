<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Duplicate_Order {

	public function __construct() {
		add_action('woocommerce_order_action_yoos_duplicate_order', array($this, 'process_duplicate_order_action'));
	}

	public function process_duplicate_order_action($order) {
		$original_order_id = $order->get_id();
		
		// Create a new order
		$new_order = wc_create_order();

		// Copy order items
		foreach ($order->get_items() as $item_id => $item) {
			$new_order->add_product($item->get_product(), $item->get_quantity(), array(
				'name'         => $item->get_name(),
				'tax_class'    => $item->get_tax_class(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'subtotal'     => $item->get_subtotal(),
				'subtotal_tax' => $item->get_subtotal_tax(),
				'total'        => $item->get_total(),
				'total_tax'    => $item->get_total_tax(),
				'taxes'        => $item->get_taxes(),
			));
		}

		// Copy billing and shipping addresses
		$new_order->set_address($order->get_address('billing'), 'billing');
		$new_order->set_address($order->get_address('shipping'), 'shipping');

		// Copy shipping methods
		foreach ($order->get_shipping_methods() as $shipping_item_id => $shipping_item) {
			$new_order->add_item($shipping_item);
		}

		// Copy fees
		foreach ($order->get_fees() as $fee_item_id => $fee_item) {
			$new_order->add_item($fee_item);
		}

		// Copy taxes
		foreach ($order->get_taxes() as $tax_item_id => $tax_item) {
			$new_order->add_item($tax_item);
		}

		// Copy coupons
		foreach ($order->get_coupon_codes() as $coupon_code) {
			$new_order->apply_coupon($coupon_code);
		}

		// Set order status to draft
		$new_order->set_status('checkout-draft');

		// Copy other order data
		$new_order->set_props(array(
			'customer_id' => $order->get_customer_id(),
			'discount_total' => $order->get_discount_total(),
			'discount_tax' => $order->get_discount_tax(),
			'shipping_total' => $order->get_shipping_total(),
			'shipping_tax' => $order->get_shipping_tax(),
			'cart_tax' => $order->get_cart_tax(),
			'total' => $order->get_total(),
			'total_tax' => $order->get_total_tax(),
			'payment_method' => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'customer_ip_address' => $order->get_customer_ip_address(),
			'customer_user_agent' => $order->get_customer_user_agent(),
		));

		// Save the new order
		$new_order->save();

		// Add a note to the original order
		$order->add_order_note(
			sprintf(
				__('Order duplicated: New order #%s', 'wc-order-splitter'),
				$new_order->get_id()
			)
		);

		// Add a note to the new order
		$new_order->add_order_note(
			sprintf(
				__('This order is a duplicate of the original order #%s', 'wc-order-splitter'),
				$original_order_id
			)
			);

			// Redirect to the new order edit page
			wp_safe_redirect(add_query_arg('wcos_action_tip', 'duplicate', $new_order->get_edit_order_url()));
			exit;
		}
	}

new WooCommerce_Order_Splitter_Duplicate_Order();
