<?php
/**
 * WooCommerce source and child order mutation adapter.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Applies an already validated execution specification without recalculation.
 */
final class WCOS_V2_Order_Mutator {

	/**
	 * Create and persist a complete neutral-status child order.
	 *
	 * @param WC_Order $source        Source order.
	 * @param array    $specification Execution specification.
	 * @param string   $operation_id  Operation ID.
	 * @return WC_Order|WP_Error
	 */
	public static function create_child(WC_Order $source, array $specification, $operation_id) {
		if ((int) $specification['source_order_id'] !== $source->get_id()) {
			return self::error('wcos_source_order_mismatch', __('The quantity split source order does not match its specification.', 'wc-order-splitter'));
		}

		if (empty($specification['child']['lines'])) {
			return self::error('wcos_empty_child_order', __('The quantity split specification has no child product lines.', 'wc-order-splitter'));
		}

		$context = isset($specification['child_context']) ? (array) $specification['child_context'] : array();
		$child   = new WC_Order();

		try {
			$child->set_status((string) $specification['initial_child_status']);
			$child->set_customer_id((int) $specification['customer_id']);
			$child->set_currency((string) $specification['currency']);
			$child->set_prices_include_tax((bool) $specification['prices_include_tax']);
			$child->set_address(isset($context['billing_address']) ? (array) $context['billing_address'] : array(), 'billing');
			$child->set_address(isset($context['shipping_address']) ? (array) $context['shipping_address'] : array(), 'shipping');
			$child->set_payment_method(isset($context['payment_method']) ? (string) $context['payment_method'] : '');
			$child->set_payment_method_title(isset($context['payment_method_title']) ? (string) $context['payment_method_title'] : '');
			$child->set_customer_note(isset($context['customer_note']) ? (string) $context['customer_note'] : '');
			$child->set_customer_ip_address(isset($context['customer_ip_address']) ? (string) $context['customer_ip_address'] : '');
			$child->set_customer_user_agent(isset($context['customer_user_agent']) ? (string) $context['customer_user_agent'] : '');
			$child->set_created_via(isset($context['created_via']) ? (string) $context['created_via'] : 'wc-order-splitter-v2');
			$child->set_transaction_id('');
		} catch (Exception $exception) {
			return self::error('wcos_child_context_invalid', $exception->getMessage());
		}

		$child->add_meta_data('_wcos_v2_operation_id', self::identifier($operation_id), true);
		$child->add_meta_data('_wcos_v2_source_order_id', $source->get_id(), true);
		$child->add_meta_data('_wcos_v2_specification_fingerprint', (string) $specification['fingerprint'], true);
		$child->add_meta_data('_wcos_v2_desired_status', (string) $specification['desired_child_status'], true);
		$child->add_meta_data('_wcos_v2_settlement_owner', 'source_order', true);

		foreach ($specification['child']['lines'] as $line_spec) {
			$item = WCOS_V2_Order_Item_Mutator::create_product($line_spec, $operation_id);

			if (is_wp_error($item)) {
				return $item;
			}

			$child->add_item($item);
		}

		foreach ($specification['child']['tax_items'] as $tax_spec) {
			$tax_item = WCOS_V2_Order_Item_Mutator::create_tax($tax_spec);

			if (is_wp_error($tax_item)) {
				return $tax_item;
			}

			$child->add_item($tax_item);
		}

		$result = self::apply_amounts($child, $specification['child']['amounts']);

		if (is_wp_error($result)) {
			return $result;
		}

		try {
			$child_id = $child->save();
		} catch (Exception $exception) {
			return self::error('wcos_child_save_failed', $exception->getMessage());
		}

		if (!$child_id) {
			return self::error('wcos_child_save_failed', __('The child order could not be saved.', 'wc-order-splitter'));
		}

		$stock_result = self::set_stock_reduced($child, (bool) $specification['stock']['child_order_reduced']);

		if (is_wp_error($stock_result)) {
			$child->delete(true);
			return $stock_result;
		}

		$persisted = wc_get_order($child_id);

		if (!$persisted instanceof WC_Order) {
			return self::error('wcos_child_reload_failed', __('The child order could not be reloaded after saving.', 'wc-order-splitter'));
		}

		return $persisted;
	}

	/**
	 * Apply the source portion of a specification.
	 *
	 * The first runtime adapter deliberately rejects fully moved source lines;
	 * support for line deletion is gated on a separate rollback contract.
	 *
	 * @param WC_Order $source        Source order.
	 * @param array    $specification Execution specification.
	 * @return WC_Order|WP_Error
	 */
	public static function update_source(WC_Order $source, array $specification) {
		if ((int) $specification['source_order_id'] !== $source->get_id()) {
			return self::error('wcos_source_order_mismatch', __('The source order does not match its mutation specification.', 'wc-order-splitter'));
		}

		foreach ($specification['source']['lines'] as $item_id => $line_spec) {
			if ('remove' === $line_spec['action']) {
				return self::error(
					'wcos_full_line_move_not_enabled',
					__('Moving an entire order line is not enabled in the first safe quantity split adapter.', 'wc-order-splitter')
				);
			}

			$item = $source->get_item(absint($item_id));

			if (!$item instanceof WC_Order_Item_Product) {
				return self::error('wcos_source_item_missing', __('A source product line was not found during mutation.', 'wc-order-splitter'));
			}

			$result = WCOS_V2_Order_Item_Mutator::update_product($item, $line_spec);

			if (is_wp_error($result)) {
				return $result;
			}
		}

		foreach ($specification['source']['tax_items'] as $item_id => $tax_spec) {
			$item = $source->get_item(absint($item_id));

			if (!$item instanceof WC_Order_Item_Tax) {
				return self::error('wcos_source_tax_missing', __('A source tax item was not found during mutation.', 'wc-order-splitter'));
			}

			$result = WCOS_V2_Order_Item_Mutator::update_tax($item, $tax_spec);

			if (is_wp_error($result)) {
				return $result;
			}
		}

		$result = self::apply_amounts($source, $specification['source']['amounts']);

		if (is_wp_error($result)) {
			return $result;
		}

		try {
			$source->save();
		} catch (Exception $exception) {
			return self::error('wcos_source_save_failed', $exception->getMessage());
		}

		$stock_result = self::set_stock_reduced($source, (bool) $specification['stock']['source_order_reduced']);

		if (is_wp_error($stock_result)) {
			return $stock_result;
		}

		$persisted = wc_get_order($source->get_id());

		return $persisted instanceof WC_Order
			? $persisted
			: self::error('wcos_source_reload_failed', __('The source order could not be reloaded after mutation.', 'wc-order-splitter'));
	}

	/**
	 * Restore source product lines, tax items, totals, and stock flag.
	 *
	 * @param WC_Order $source   Source order.
	 * @param array    $snapshot Immutable source snapshot.
	 * @return WC_Order|WP_Error
	 */
	public static function restore_source(WC_Order $source, array $snapshot) {
		if ((int) $snapshot['order_id'] !== $source->get_id()) {
			return self::error('wcos_restore_source_mismatch', __('The rollback snapshot belongs to another order.', 'wc-order-splitter'));
		}

		foreach ($snapshot['lines'] as $item_id => $line_snapshot) {
			$item = $source->get_item(absint($item_id));

			if (!$item instanceof WC_Order_Item_Product) {
				return self::error(
					'wcos_rollback_line_missing',
					__('A source line required for automatic rollback no longer exists.', 'wc-order-splitter')
				);
			}

			$result = WCOS_V2_Order_Item_Mutator::restore_product($item, $line_snapshot);

			if (is_wp_error($result)) {
				return $result;
			}
		}

		foreach ($snapshot['tax_items'] as $item_id => $tax_snapshot) {
			$item = $source->get_item(absint($item_id));

			if (!$item instanceof WC_Order_Item_Tax) {
				return self::error('wcos_rollback_tax_missing', __('A source tax item required for rollback no longer exists.', 'wc-order-splitter'));
			}

			$result = WCOS_V2_Order_Item_Mutator::restore_tax($item, $tax_snapshot);

			if (is_wp_error($result)) {
				return $result;
			}
		}

		$result = self::apply_amounts($source, $snapshot['amounts']);

		if (is_wp_error($result)) {
			return $result;
		}

		try {
			$source->save();
		} catch (Exception $exception) {
			return self::error('wcos_source_restore_failed', $exception->getMessage());
		}

		$stock_result = self::set_stock_reduced($source, (bool) $snapshot['order_stock_reduced']);

		if (is_wp_error($stock_result)) {
			return $stock_result;
		}

		$persisted = wc_get_order($source->get_id());

		return $persisted instanceof WC_Order
			? $persisted
			: self::error('wcos_source_restore_reload_failed', __('The restored source order could not be reloaded.', 'wc-order-splitter'));
	}

	/**
	 * Delete a target order permanently during rollback.
	 *
	 * @param WC_Order $child Child order.
	 * @return true|WP_Error
	 */
	public static function delete_child(WC_Order $child) {
		try {
			$result = $child->delete(true);
		} catch (Exception $exception) {
			return self::error('wcos_child_rollback_delete_failed', $exception->getMessage());
		}

		return false === $result
			? self::error('wcos_child_rollback_delete_failed', __('The child order could not be deleted during rollback.', 'wc-order-splitter'))
			: true;
	}

	/**
	 * Apply aggregate order values without recalculating taxes or prices.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $amounts Explicit amounts.
	 * @return true|WP_Error
	 */
	private static function apply_amounts(WC_Order $order, array $amounts) {
		$required = array('discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'cart_tax', 'total');

		foreach ($required as $field) {
			if (!array_key_exists($field, $amounts)) {
				return self::error('wcos_incomplete_order_amounts', __('The order aggregate amount specification is incomplete.', 'wc-order-splitter'));
			}
		}

		try {
			$order->set_discount_total($amounts['discount_total']);
			$order->set_discount_tax($amounts['discount_tax']);
			$order->set_shipping_total($amounts['shipping_total']);
			$order->set_shipping_tax($amounts['shipping_tax']);
			$order->set_cart_tax($amounts['cart_tax']);
			$order->set_total($amounts['total']);
		} catch (Exception $exception) {
			return self::error('wcos_invalid_order_amounts', $exception->getMessage());
		}

		return true;
	}

	/**
	 * Set the order-level stock-reduced flag through the active data store.
	 *
	 * @param WC_Order $order   Order.
	 * @param bool     $reduced Stock flag.
	 * @return true|WP_Error
	 */
	private static function set_stock_reduced(WC_Order $order, $reduced) {
		$data_store = $order->get_data_store();

		if (!is_object($data_store) || !method_exists($data_store, 'set_stock_reduced')) {
			return self::error('wcos_stock_flag_unsupported', __('The active WooCommerce order data store cannot persist stock state safely.', 'wc-order-splitter'));
		}

		try {
			$data_store->set_stock_reduced($order->get_id(), (bool) $reduced);
		} catch (Exception $exception) {
			return self::error('wcos_stock_flag_save_failed', $exception->getMessage());
		}

		return true;
	}

	/**
	 * Normalize an operation ID.
	 *
	 * @param mixed $value ID.
	 * @return string
	 */
	private static function identifier($value) {
		$value = strtolower(trim((string) $value));

		return preg_replace('/[^a-z0-9._:-]/', '', $value);
	}

	/**
	 * Create a stable mutation error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
