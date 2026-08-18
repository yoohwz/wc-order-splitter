<?php
/**
 * Stock-safe child order quarantine for incomplete rollback.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Removes stock lifecycle ownership from a child before destructive cleanup.
 */
final class WCOS_V2_Child_Quarantine {

	/**
	 * Quarantine an unchanged split child without changing physical stock.
	 *
	 * @param WC_Order $child         Child order.
	 * @param array    $specification Execution specification.
	 * @param string   $operation_id  Operation ID.
	 * @param string   $reason        Stable quarantine reason.
	 * @return WC_Order|WP_Error
	 */
	public static function apply(WC_Order $child, array $specification, $operation_id, $reason) {
		$verification = WCOS_V2_Specification_Comparator::verify_child($child, $specification, $operation_id);

		if (is_wp_error($verification)) {
			return $verification;
		}

		$operation_id = self::identifier($operation_id);
		$reason       = sanitize_key($reason);

		if ('' === $operation_id || '' === $reason) {
			return self::error('wcos_invalid_quarantine_identity', __('The child quarantine identity is invalid.', 'wc-order-splitter'));
		}

		foreach ($child->get_items('line_item') as $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				return self::error('wcos_quarantine_line_invalid', __('The split child contains an unsupported product line.', 'wc-order-splitter'));
			}

			$item->delete_meta_data('_reduced_stock');
			$item->update_meta_data('_wcos_v2_quarantined_operation', $operation_id);
			$item->save();
		}

		$child->update_meta_data('_wcos_v2_quarantined', 'yes');
		$child->update_meta_data('_wcos_v2_quarantine_reason', $reason);
		$child->update_meta_data('_wcos_v2_quarantined_at', time());

		try {
			$child->save();
		} catch (Exception $exception) {
			return self::error('wcos_child_quarantine_save_failed', $exception->getMessage());
		}

		$data_store = $child->get_data_store();

		if (!is_object($data_store) || !method_exists($data_store, 'set_stock_reduced')) {
			return self::error('wcos_child_quarantine_stock_store', __('The active WooCommerce order store cannot quarantine child stock state.', 'wc-order-splitter'));
		}

		try {
			$data_store->set_stock_reduced($child->get_id(), false);
		} catch (Exception $exception) {
			return self::error('wcos_child_quarantine_stock_failed', $exception->getMessage());
		}

		$persisted = wc_get_order($child->get_id());

		if (!$persisted instanceof WC_Order) {
			return self::error('wcos_child_quarantine_reload_failed', __('The quarantined child order could not be reloaded.', 'wc-order-splitter'));
		}

		$verified = self::verify($persisted, $operation_id);

		return is_wp_error($verified) ? $verified : $persisted;
	}

	/**
	 * Verify a quarantined child cannot participate in stock restoration.
	 *
	 * @param WC_Order $child        Child order.
	 * @param string   $operation_id Operation ID.
	 * @return true|WP_Error
	 */
	public static function verify(WC_Order $child, $operation_id) {
		$operation_id = self::identifier($operation_id);

		if ('yes' !== (string) $child->get_meta('_wcos_v2_quarantined', true)
			|| (string) $child->get_meta('_wcos_v2_operation_id', true) !== $operation_id
		) {
			return self::error('wcos_child_quarantine_meta_mismatch', __('The child quarantine metadata is incomplete.', 'wc-order-splitter'));
		}

		foreach ($child->get_items('line_item') as $item) {
			if ('' !== $item->get_meta('_reduced_stock', true)
				|| (string) $item->get_meta('_wcos_v2_quarantined_operation', true) !== $operation_id
			) {
				return self::error('wcos_child_quarantine_line_stock', __('A quarantined child line still owns stock lifecycle data.', 'wc-order-splitter'));
			}
		}

		$data_store = $child->get_data_store();

		if (!is_object($data_store) || !method_exists($data_store, 'get_stock_reduced')) {
			return self::error('wcos_child_quarantine_stock_store', __('The active WooCommerce order store cannot verify quarantined stock state.', 'wc-order-splitter'));
		}

		if ((bool) $data_store->get_stock_reduced($child->get_id())) {
			return self::error('wcos_child_quarantine_order_stock', __('The quarantined child order still owns stock lifecycle state.', 'wc-order-splitter'));
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
	 * Create a stable quarantine error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
