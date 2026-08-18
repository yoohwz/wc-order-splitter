<?php

defined('ABSPATH') || exit;

/**
 * Central capability policy for order mutation workflows.
 */
final class WCOS_Order_Mutation_Authorizer {

	public static function assert_can_edit(WC_Order $order) {
		if (!current_user_can('edit_shop_order', $order->get_id())) {
			throw new RuntimeException(__('You are not allowed to edit this order.', 'wc-order-splitter'));
		}
	}

	public static function assert_can_delete(WC_Order $order) {
		if (!current_user_can('delete_shop_order', $order->get_id())) {
			throw new RuntimeException(__('You are not allowed to remove this order from active orders.', 'wc-order-splitter'));
		}
	}

	public static function assert_split(WC_Order $source) {
		self::assert_can_edit($source);
	}

	public static function assert_duplicate(WC_Order $source) {
		self::assert_can_edit($source);
	}

	public static function assert_return(WC_Order $child, WC_Order $parent) {
		self::assert_can_edit($child);
		self::assert_can_edit($parent);
		self::assert_can_delete($child);
	}

	public static function assert_merge(WC_Order $source, WC_Order $target) {
		self::assert_can_edit($source);
		self::assert_can_edit($target);
		self::assert_can_delete($source);
	}
}
