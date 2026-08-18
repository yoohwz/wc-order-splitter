<?php

defined('ABSPATH') || exit;

/**
 * Captures and restores the mutation-owned portion of a source order.
 *
 * Snapshots intentionally exclude customer/address/payment plaintext. They keep
 * only fields the mutation engine is allowed to change plus hashes of immutable
 * copy context. This makes a strict compensating rollback possible without
 * turning the operation journal into a second store of customer PII.
 */
final class WCOS_Order_Mutation_Snapshot {

	const SCHEMA_VERSION = 1;

	public static function capture_split_source(WC_Order $order) {
		if (!$order->get_id() || 'shop_order' !== $order->get_type()) {
			throw new InvalidArgumentException('A persisted shop order is required for a mutation snapshot.');
		}

		$lines = array();
		foreach ($order->get_items('line_item') as $item_id => $item) {
			$lines[(int) $item_id] = array(
				'identity' => WCOS_Line_Identity::from_item($item),
				'quantity' => WCOS_Decimal::normalize($item->get_quantity(), 6),
				'subtotal' => (string) $item->get_subtotal(),
				'subtotal_tax' => (string) $item->get_subtotal_tax(),
				'total' => (string) $item->get_total(),
				'total_tax' => (string) $item->get_total_tax(),
				'taxes' => self::normalize_array($item->get_taxes()),
				'reduced_stock' => self::normalize_reduced_stock($item->get_meta('_reduced_stock', true)),
			);
		}
		ksort($lines, SORT_NUMERIC);

		$taxes = array();
		foreach ($order->get_items('tax') as $item_id => $item) {
			$taxes[(int) $item_id] = array(
				'rate_id' => (int) $item->get_rate_id(),
				'tax_total' => (string) $item->get_tax_total(),
				'shipping_tax_total' => (string) $item->get_shipping_tax_total(),
			);
		}
		ksort($taxes, SORT_NUMERIC);

		$snapshot = array(
			'schema_version' => self::SCHEMA_VERSION,
			'order_id' => (int) $order->get_id(),
			'copy_context_signature' => WCOS_Order_Copy_Context::signature($order),
			'order_props' => array(
				'discount_total' => (string) $order->get_discount_total(),
				'discount_tax' => (string) $order->get_discount_tax(),
				'shipping_total' => (string) $order->get_shipping_total(),
				'shipping_tax' => (string) $order->get_shipping_tax(),
				'cart_tax' => (string) $order->get_cart_tax(),
				'total_tax' => (string) $order->get_total_tax(),
				'total' => (string) $order->get_total(),
			),
			'relation_meta' => array(
				'_wcos_child_order_ids' => self::normalize_order_ids($order->get_meta('_wcos_child_order_ids', true)),
				'yoos_splitted_order' => self::normalize_legacy_order_ids($order->get_meta('yoos_splitted_order', true)),
			),
			'order_stock_reduced' => (bool) $order->get_data_store()->get_stock_reduced($order->get_id()),
			'line_items' => $lines,
			'tax_items' => $taxes,
		);

		$snapshot['source_signature'] = WCOS_Order_Contract_Snapshot::source_signature($order);
		$snapshot['recovery_fingerprint'] = self::fingerprint($snapshot);
		return $snapshot;
	}

	public static function fingerprint(array $snapshot) {
		$copy = $snapshot;
		unset($copy['recovery_fingerprint']);
		$order_id = isset($copy['order_id']) ? absint($copy['order_id']) : 0;
		return WCOS_Mutation_Fingerprint::create('split_source_recovery', $order_id, $copy);
	}

	public static function assert_valid(array $snapshot) {
		if (!isset($snapshot['schema_version']) || self::SCHEMA_VERSION !== (int) $snapshot['schema_version']) {
			throw new RuntimeException(__('The mutation recovery snapshot uses an unsupported schema version.', 'wc-order-splitter'));
		}
		$stored = isset($snapshot['recovery_fingerprint']) ? sanitize_key((string) $snapshot['recovery_fingerprint']) : '';
		$actual = self::fingerprint($snapshot);
		if ('' === $stored || !hash_equals($stored, $actual)) {
			throw new RuntimeException(__('The mutation recovery snapshot failed its integrity fingerprint.', 'wc-order-splitter'));
		}
	}

	/**
	 * Restore only the fields owned by Split.
	 *
	 * `$expected_current_signature` is mandatory for automatic compensation. It
	 * prevents rollback from overwriting a source order changed by another actor
	 * after the mutation committed its intermediate state.
	 */
	public static function restore_split_source(array $snapshot, $expected_current_signature) {
		self::assert_valid($snapshot);
		$expected_current_signature = sanitize_key((string) $expected_current_signature);
		if ('' === $expected_current_signature) {
			throw new RuntimeException(__('Automatic mutation rollback requires an expected current source signature.', 'wc-order-splitter'));
		}

		$order_id = isset($snapshot['order_id']) ? absint($snapshot['order_id']) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order || 'shop_order' !== $order->get_type()) {
			throw new RuntimeException(__('The mutation source order is unavailable for rollback.', 'wc-order-splitter'));
		}

		$current_signature = WCOS_Order_Contract_Snapshot::source_signature($order);
		if (!hash_equals($expected_current_signature, $current_signature)) {
			throw new RuntimeException(__('The source order changed after the mutation checkpoint; automatic rollback is unsafe.', 'wc-order-splitter'));
		}
		WCOS_Order_Copy_Context::assert_matches((string) $snapshot['copy_context_signature'], $order);
		self::assert_item_shape($order, $snapshot);

		foreach ($snapshot['line_items'] as $item_id => $state) {
			$item = $order->get_item((int) $item_id);
			if (!$item instanceof WC_Order_Item_Product) {
				throw new RuntimeException(__('A source line required for rollback is missing.', 'wc-order-splitter'));
			}
			$result = $item->set_props(array(
				'quantity' => $state['quantity'],
				'subtotal' => $state['subtotal'],
				'subtotal_tax' => $state['subtotal_tax'],
				'total' => $state['total'],
				'total_tax' => $state['total_tax'],
				'taxes' => $state['taxes'],
			));
			if (is_wp_error($result)) {
				throw new RuntimeException($result->get_error_message());
			}
			$item->delete_meta_data('_reduced_stock');
			if (null !== $state['reduced_stock']) {
				$item->add_meta_data('_reduced_stock', $state['reduced_stock'], true);
			}
		}

		foreach ($snapshot['tax_items'] as $item_id => $state) {
			$item = $order->get_item((int) $item_id);
			if (!$item instanceof WC_Order_Item_Tax || (int) $item->get_rate_id() !== (int) $state['rate_id']) {
				throw new RuntimeException(__('A source tax row required for rollback is missing or changed.', 'wc-order-splitter'));
			}
			$result = $item->set_props(array(
				'tax_total' => $state['tax_total'],
				'shipping_tax_total' => $state['shipping_tax_total'],
			));
			if (is_wp_error($result)) {
				throw new RuntimeException($result->get_error_message());
			}
		}

		$result = $order->set_props($snapshot['order_props']);
		if (is_wp_error($result)) {
			throw new RuntimeException($result->get_error_message());
		}
		$order->update_meta_data('_wcos_child_order_ids', $snapshot['relation_meta']['_wcos_child_order_ids']);
		$order->update_meta_data('yoos_splitted_order', $snapshot['relation_meta']['yoos_splitted_order']);
		$order->save();
		$order->get_data_store()->set_stock_reduced($order->get_id(), (bool) $snapshot['order_stock_reduced']);

		$restored = wc_get_order($order->get_id());
		if (!$restored || !hash_equals((string) $snapshot['source_signature'], WCOS_Order_Contract_Snapshot::source_signature($restored))) {
			throw new RuntimeException(__('The source order did not return to its pre-mutation snapshot after rollback.', 'wc-order-splitter'));
		}
		return $restored;
	}

	private static function assert_item_shape(WC_Order $order, array $snapshot) {
		$current_line_ids = array_map('intval', array_keys($order->get_items('line_item')));
		$snapshot_line_ids = array_map('intval', array_keys((array) $snapshot['line_items']));
		sort($current_line_ids, SORT_NUMERIC);
		sort($snapshot_line_ids, SORT_NUMERIC);
		if ($current_line_ids !== $snapshot_line_ids) {
			throw new RuntimeException(__('The source line-item set changed; automatic rollback is unsafe.', 'wc-order-splitter'));
		}

		foreach ($snapshot['line_items'] as $item_id => $state) {
			$item = $order->get_item((int) $item_id);
			if (!$item instanceof WC_Order_Item_Product
				|| !hash_equals((string) $state['identity'], WCOS_Line_Identity::from_item($item))) {
				throw new RuntimeException(__('A source line identity changed; automatic rollback is unsafe.', 'wc-order-splitter'));
			}
		}

		$current_tax_ids = array_map('intval', array_keys($order->get_items('tax')));
		$snapshot_tax_ids = array_map('intval', array_keys((array) $snapshot['tax_items']));
		sort($current_tax_ids, SORT_NUMERIC);
		sort($snapshot_tax_ids, SORT_NUMERIC);
		if ($current_tax_ids !== $snapshot_tax_ids) {
			throw new RuntimeException(__('The source tax-row set changed; automatic rollback is unsafe.', 'wc-order-splitter'));
		}
	}

	private static function normalize_reduced_stock($value) {
		if ('' === $value || null === $value) {
			return null;
		}
		if (!is_numeric($value)) {
			throw new RuntimeException(__('A source line contains a non-numeric reduced-stock marker.', 'wc-order-splitter'));
		}
		return WCOS_Decimal::normalize($value, 6);
	}

	private static function normalize_order_ids($value) {
		$ids = array_values(array_unique(array_filter(array_map('absint', (array) $value))));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private static function normalize_legacy_order_ids($value) {
		$ids = array_values(array_unique(array_filter(array_map('absint', explode(',', (string) $value)))));
		sort($ids, SORT_NUMERIC);
		return implode(',', $ids);
	}

	private static function normalize_array(array $value) {
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = self::normalize_array($item);
			}
		}
		ksort($value, SORT_STRING);
		return $value;
	}
}
