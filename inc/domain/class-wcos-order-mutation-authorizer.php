<?php

defined('ABSPATH') || exit;

/**
 * Central capability and policy boundary for order mutation workflows.
 */
final class WCOS_Order_Mutation_Authorizer {

	public static function assert_workflow($workflow, WC_Order $source, WC_Order $target = null) {
		$workflow = sanitize_key((string) $workflow);
		self::assert_shop_order($source);
		self::assert_operator();

		switch ($workflow) {
			case WCOS_Feature_Gates::SPLIT:
				self::assert_split($source);
				return;
			case WCOS_Feature_Gates::DUPLICATE:
				self::assert_duplicate($source);
				return;
			case WCOS_Feature_Gates::MERGE:
				if (!$target instanceof WC_Order) {
					throw new InvalidArgumentException(__('A merge target order is required.', 'wc-order-splitter'));
				}
				self::assert_shop_order($target);
				self::assert_merge($source, $target);
				return;
			case WCOS_Feature_Gates::RETURN_ORDER:
			case WCOS_Feature_Gates::BULK_RETURN:
				if (!$target instanceof WC_Order) {
					throw new InvalidArgumentException(__('The parent order is required for a return operation.', 'wc-order-splitter'));
				}
				self::assert_shop_order($target);
				self::assert_return($source, $target);
				return;
			default:
				throw new InvalidArgumentException(__('Unknown order mutation workflow.', 'wc-order-splitter'));
		}
	}

	public static function assert_can_edit(WC_Order $order) {
		self::assert_shop_order($order);
		if (!current_user_can('edit_shop_order', $order->get_id())) {
			throw new RuntimeException(__('You are not allowed to edit this order.', 'wc-order-splitter'));
		}
	}

	public static function assert_can_delete(WC_Order $order) {
		self::assert_shop_order($order);
		if (!current_user_can('delete_shop_order', $order->get_id())) {
			throw new RuntimeException(__('You are not allowed to remove this order from active orders.', 'wc-order-splitter'));
		}
	}

	public static function assert_split(WC_Order $source) {
		self::assert_operator();
		self::assert_can_edit($source);
	}

	public static function assert_duplicate(WC_Order $source) {
		self::assert_operator();
		self::assert_can_edit($source);
	}

	public static function assert_return(WC_Order $child, WC_Order $parent) {
		self::assert_operator();
		self::assert_can_edit($child);
		self::assert_can_edit($parent);
		self::assert_can_delete($child);
	}

	public static function assert_merge(WC_Order $source, WC_Order $target) {
		self::assert_merge_source($source);
		if ($source->get_id() === $target->get_id()) {
			throw new RuntimeException(__('An order cannot be merged into itself.', 'wc-order-splitter'));
		}
		self::assert_can_edit($target);
	}

	/** Authorize the current edited order before a Merge target is selected. */
	public static function assert_merge_source(WC_Order $source) {
		self::assert_operator();
		self::assert_can_edit($source);
		self::assert_can_delete($source);
	}

	/**
	 * Restrict plugin mutation workflows to the two supported operator roles.
	 *
	 * Per-order WooCommerce capability checks remain mandatory and are applied
	 * separately by the workflow-specific methods above.
	 */
	public static function assert_operator() {
		$user = wp_get_current_user();
		if (!$user || !$user->exists()) {
			throw new RuntimeException(__('You must be signed in to mutate orders.', 'wc-order-splitter'));
		}

		if (empty(array_intersect(array('administrator', 'shop_manager'), (array) $user->roles))) {
			throw new RuntimeException(__('You are not allowed to use order mutation workflows.', 'wc-order-splitter'));
		}
	}

	private static function assert_shop_order(WC_Order $order) {
		if (!$order->get_id() || 'shop_order' !== $order->get_type()) {
			throw new InvalidArgumentException(__('Only persisted WooCommerce shop orders can be mutated.', 'wc-order-splitter'));
		}
	}

}
