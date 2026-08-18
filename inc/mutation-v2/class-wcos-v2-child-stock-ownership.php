<?php
/**
 * Compensating restoration of split-child stock lifecycle ownership.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Restores child stock markers when a quarantine attempt cannot complete.
 */
final class WCOS_V2_Child_Stock_Ownership {

	/**
	 * Restore stock markers and the order-level stock flag from the specification.
	 *
	 * @param WC_Order $child         Child order.
	 * @param array    $specification Execution specification.
	 * @param string   $operation_id  Operation ID.
	 * @return WC_Order|WP_Error
	 */
	public static function restore(WC_Order $child, array $specification, $operation_id) {
		$operation_id = self::identifier($operation_id);

		if ('' === $operation_id
			|| (string) $child->get_meta('_wcos_v2_operation_id', true) !== $operation_id
			|| (int) $child->get_meta('_wcos_v2_source_order_id', true) !== (int) $specification['source_order_id']
		) {
			return self::error('wcos_child_stock_restore_identity', __('The child stock ownership identity does not match the split specification.', 'wc-order-splitter'));
		}

		$items_by_source = array();

		foreach ($child->get_items('line_item') as $item) {
			$source_item_id = absint($item->get_meta('_wcos_v2_source_item_id', true));

			if (!$source_item_id || isset($items_by_source[$source_item_id])) {
				return self::error('wcos_child_stock_restore_mapping', __('The child product-line mapping is invalid.', 'wc-order-splitter'));
			}

			$items_by_source[$source_item_id] = $item;
		}

		if (count($items_by_source) !== count($specification['child']['lines'])) {
			return self::error('wcos_child_stock_restore_count', __('The child product-line count does not match the split specification.', 'wc-order-splitter'));
		}

		foreach ($specification['child']['lines'] as $source_item_id => $line_spec) {
			if (!isset($items_by_source[$source_item_id])) {
				return self::error('wcos_child_stock_restore_line_missing', __('A child product line required for stock restoration is missing.', 'wc-order-splitter'));
			}

			$item = $items_by_source[$source_item_id];
			$item->delete_meta_data('_reduced_stock');
			$item->delete_meta_data('_wcos_v2_quarantined_operation');

			if (null !== $line_spec['reduced_stock'] && '' !== $line_spec['reduced_stock']) {
				$item->add_meta_data('_reduced_stock', $line_spec['reduced_stock'], true);
			}

			$item->save();
		}

		$child->delete_meta_data('_wcos_v2_quarantined');
		$child->delete_meta_data('_wcos_v2_quarantine_reason');
		$child->delete_meta_data('_wcos_v2_quarantined_at');

		try {
			$child->save();
		} catch (Exception $exception) {
			return self::error('wcos_child_stock_restore_save_failed', $exception->getMessage());
		}

		$data_store = $child->get_data_store();

		if (!is_object($data_store) || !method_exists($data_store, 'set_stock_reduced')) {
			return self::error('wcos_child_stock_restore_store', __('The active WooCommerce order store cannot restore child stock state.', 'wc-order-splitter'));
		}

		try {
			$data_store->set_stock_reduced($child->get_id(), (bool) $specification['stock']['child_order_reduced']);
		} catch (Exception $exception) {
			return self::error('wcos_child_stock_restore_flag_failed', $exception->getMessage());
		}

		$persisted = wc_get_order($child->get_id());

		if (!$persisted instanceof WC_Order) {
			return self::error('wcos_child_stock_restore_reload_failed', __('The child order could not be reloaded after stock restoration.', 'wc-order-splitter'));
		}

		$verification = WCOS_V2_Specification_Comparator::verify_child($persisted, $specification, $operation_id);

		return is_wp_error($verification) ? $verification : $persisted;
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
	 * Create a stable compensation error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
