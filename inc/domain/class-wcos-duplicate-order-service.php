<?php

defined('ABSPATH') || exit;

/**
 * Duplicates an order without re-parenting persisted items or copying stock/payment state.
 */
final class WCOS_Duplicate_Order_Service {

	public function duplicate(WC_Order $source) {
		$operation_id = 'duplicate-' . wp_generate_uuid4();
		$source_id = $source->get_id();
		$new_order = null;

		if (WCOS_Operation_Journal::has_completed($source, $operation_id)) {
			throw new RuntimeException('Duplicate operation has already completed.');
		}

		if (!WCOS_Operation_Lock::acquire($source_id, $operation_id)) {
			throw new RuntimeException(__('Another order mutation is already in progress for this order.', 'wc-order-splitter'));
		}

		WCOS_Operation_Journal::start($source, $operation_id, 'duplicate');

		try {
			$new_order = wc_create_order();
			if (is_wp_error($new_order)) {
				throw new RuntimeException($new_order->get_error_message());
			}

			$new_order->set_props(array(
				'status' => 'pending',
				'customer_id' => $source->get_customer_id(),
				'currency' => $source->get_currency(),
				'prices_include_tax' => $source->get_prices_include_tax(),
				'discount_total' => $source->get_discount_total(),
				'discount_tax' => $source->get_discount_tax(),
				'shipping_total' => $source->get_shipping_total(),
				'shipping_tax' => $source->get_shipping_tax(),
				'cart_tax' => $source->get_cart_tax(),
				'total' => $source->get_total(),
				'total_tax' => $source->get_total_tax(),
				'payment_method' => $source->get_payment_method(),
				'payment_method_title' => $source->get_payment_method_title(),
				'customer_note' => $source->get_customer_note(),
				'created_via' => 'wc-order-splitter-duplicate',
			));
			$new_order->set_address($source->get_address('billing'), 'billing');
			$new_order->set_address($source->get_address('shipping'), 'shipping');

			foreach ($source->get_items('line_item') as $item) {
				$new_order->add_item(WCOS_Order_Item_Cloner::product($item, array(), false));
			}
			foreach ($source->get_items('shipping') as $item) {
				$new_order->add_item(WCOS_Order_Item_Cloner::shipping($item));
			}
			foreach ($source->get_items('fee') as $item) {
				$new_order->add_item(WCOS_Order_Item_Cloner::fee($item));
			}
			foreach ($source->get_items('tax') as $item) {
				$new_order->add_item(WCOS_Order_Item_Cloner::tax($item));
			}
			foreach ($source->get_items('coupon') as $item) {
				$new_order->add_item(WCOS_Order_Item_Cloner::coupon($item));
			}

			$new_order->update_meta_data('_wcos_duplicate_source_order', $source_id);
			$new_order->save();

			$source->add_order_note(
				sprintf(
					/* translators: %s: duplicated order number. */
					__('Order duplicated safely as order #%s.', 'wc-order-splitter'),
					$new_order->get_order_number()
				)
			);
			$new_order->add_order_note(
				sprintf(
					/* translators: %s: source order number. */
					__('This order is a duplicate of order #%s. Stock-reduction and payment transaction state were not copied.', 'wc-order-splitter'),
					$source->get_order_number()
				)
			);

			WCOS_Operation_Journal::complete($source, $operation_id, array('target_order_id' => $new_order->get_id()));
			return $new_order;
		} catch (Throwable $throwable) {
			if ($new_order instanceof WC_Order && $new_order->get_id()) {
				$new_order->delete(true);
			}
			WCOS_Operation_Journal::fail($source, $operation_id, array('error' => $throwable->getMessage()));
			throw $throwable;
		} finally {
			WCOS_Operation_Lock::release($source_id, $operation_id);
		}
	}
}
