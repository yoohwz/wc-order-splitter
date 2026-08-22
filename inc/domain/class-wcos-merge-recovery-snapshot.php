<?php

defined('ABSPATH') || exit;

/**
 * PII-minimized, self-verifying recovery snapshot for an existing Merge pair.
 *
 * The snapshot contains only state the Merge recovery foundation owns: exact
 * historical amounts/taxes, operational stock markers, lifecycle state, and
 * Merge participation metadata. Customer, address, payment, item-name, and
 * arbitrary third-party order metadata are deliberately excluded.
 */
final class WCOS_Merge_Recovery_Snapshot {

	const SCHEMA_VERSION = 1;

	public static function capture(WC_Order $source, WC_Order $target, array $record) {
		$pair = WCOS_Merge_Journal_Context::pair_from_record($record);
		if (!is_array($pair)
			|| absint($source->get_id()) !== $pair['source_order_id']
			|| absint($target->get_id()) !== $pair['target_order_id']) {
			throw new RuntimeException(__('Merge recovery snapshot participants do not match pair authority.', 'wc-order-splitter'));
		}
		if (!hash_equals($pair['source_signature'], WCOS_Order_Contract_Snapshot::source_signature($source))
			|| !hash_equals($pair['target_signature'], WCOS_Order_Contract_Snapshot::source_signature($target))) {
			throw new RuntimeException(__('Merge participants changed before recovery state could be captured.', 'wc-order-splitter'));
		}

		$snapshot = array(
			'schema_version' => self::SCHEMA_VERSION,
			'pair_fingerprint' => $pair['pair_fingerprint'],
			'source_order_id' => $pair['source_order_id'],
			'target_order_id' => $pair['target_order_id'],
			'price_precision' => $pair['price_precision'],
			'preflight_policy_version' => $pair['preflight_policy_version'],
			'retirement_policy_schema_version' => $pair['retirement_policy_schema_version'],
			'retirement_candidates' => $pair['retirement_candidates'],
			'retirement_policy_selected' => false,
			'source' => self::participant_state($source),
			'target' => self::participant_state($target),
		);
		$snapshot['archive_source_signature_before'] = $snapshot['source']['commercial_signature'];
		$snapshot['active_ownership_before_signature'] = self::active_ownership_signature($snapshot['source'], $snapshot['target']);
		$snapshot['recovery_fingerprint'] = self::fingerprint($snapshot);
		return $snapshot;
	}

	public static function assert_valid(array $snapshot, array $record = array()) {
		$expected_keys = array(
			'active_ownership_before_signature', 'archive_source_signature_before', 'pair_fingerprint',
			'preflight_policy_version', 'price_precision', 'recovery_fingerprint', 'retirement_candidates',
			'retirement_policy_schema_version', 'retirement_policy_selected', 'schema_version', 'source',
			'source_order_id', 'target', 'target_order_id',
		);
		$actual_keys = array_keys($snapshot);
		sort($actual_keys, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		if ($actual_keys !== $expected_keys
			|| self::SCHEMA_VERSION !== (int) $snapshot['schema_version']
			|| !is_array($snapshot['source']) || !is_array($snapshot['target'])
			|| false !== (bool) $snapshot['retirement_policy_selected']) {
			throw new RuntimeException(__('The Merge recovery snapshot has an invalid schema.', 'wc-order-splitter'));
		}

		$stored = self::normalized_fingerprint($snapshot['recovery_fingerprint']);
		$actual = self::fingerprint($snapshot);
		if ('' === $stored || !hash_equals($stored, $actual)) {
			throw new RuntimeException(__('The Merge recovery snapshot failed its integrity fingerprint.', 'wc-order-splitter'));
		}
		self::assert_participant_state($snapshot['source'], absint($snapshot['source_order_id']));
		self::assert_participant_state($snapshot['target'], absint($snapshot['target_order_id']));
		if (!hash_equals((string) $snapshot['archive_source_signature_before'], (string) $snapshot['source']['commercial_signature'])
			|| !hash_equals(
				(string) $snapshot['active_ownership_before_signature'],
				self::active_ownership_signature($snapshot['source'], $snapshot['target'])
			)) {
			throw new RuntimeException(__('The Merge archive or active-ownership snapshot authority is invalid.', 'wc-order-splitter'));
		}

		if (!empty($record)) {
			$pair = WCOS_Merge_Journal_Context::pair_from_record($record);
			if (!is_array($pair)
				|| absint($snapshot['source_order_id']) !== $pair['source_order_id']
				|| absint($snapshot['target_order_id']) !== $pair['target_order_id']
				|| !hash_equals((string) $snapshot['pair_fingerprint'], $pair['pair_fingerprint'])
				|| (int) $snapshot['price_precision'] !== $pair['price_precision']
				|| (int) $snapshot['preflight_policy_version'] !== $pair['preflight_policy_version']
				|| (int) $snapshot['retirement_policy_schema_version'] !== $pair['retirement_policy_schema_version']
				|| array_values($snapshot['retirement_candidates']) !== $pair['retirement_candidates']) {
				throw new RuntimeException(__('The Merge recovery snapshot no longer matches pair authority.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	public static function fingerprint(array $snapshot) {
		$copy = $snapshot;
		unset($copy['recovery_fingerprint']);
		return WCOS_Mutation_Fingerprint::create(
			'merge_pair_recovery_v1',
			isset($copy['source_order_id']) ? absint($copy['source_order_id']) : 0,
			$copy
		);
	}

	public static function participant_signature(WC_Order $order) {
		$state = self::participant_state($order);
		return self::state_signature($state);
	}

	public static function before_signature(array $snapshot, $role) {
		$role = sanitize_key((string) $role);
		if (!in_array($role, array('source', 'target'), true) || !isset($snapshot[$role])) {
			return '';
		}
		return self::state_signature($snapshot[$role]);
	}

	/**
	 * Restore one participant only after an exact planned-checkpoint signature.
	 * Target additions are removed only from the journal-owned ID allowlist.
	 */
	public static function restore_participant(
		array $snapshot,
		$role,
		$expected_current_signature,
		array $target_item_ids = array(),
		array $target_tax_item_ids = array()
	) {
		self::assert_valid($snapshot);
		$role = sanitize_key((string) $role);
		if (!in_array($role, array('source', 'target'), true)) {
			throw new InvalidArgumentException(__('A valid Merge recovery participant role is required.', 'wc-order-splitter'));
		}
		$expected_current_signature = self::normalized_fingerprint($expected_current_signature);
		$order_id = absint($snapshot[$role]['order_id']);
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order || 'shop_order' !== $order->get_type()) {
			throw new RuntimeException(__('A Merge recovery participant is unavailable.', 'wc-order-splitter'));
		}
		$current = self::participant_signature($order);
		if ('' === $expected_current_signature || !hash_equals($expected_current_signature, $current)) {
			throw new RuntimeException(__('A Merge participant changed after its approved checkpoint.', 'wc-order-splitter'));
		}

		$target_item_ids = array_values(array_unique(array_filter(array_map('absint', $target_item_ids))));
		sort($target_item_ids, SORT_NUMERIC);
		$target_tax_item_ids = array_values(array_unique(array_filter(array_map('absint', $target_tax_item_ids))));
		sort($target_tax_item_ids, SORT_NUMERIC);
		self::assert_item_shape(
			$order,
			$snapshot[$role],
			'target' === $role ? $target_item_ids : array(),
			'target' === $role ? $target_tax_item_ids : array()
		);

		$line_items = $order->get_items('line_item');
		foreach ($snapshot[$role]['line_items'] as $item_id => $state) {
			$item = isset($line_items[$item_id]) ? $line_items[$item_id] : null;
			if (!$item instanceof WC_Order_Item_Product) {
				throw new RuntimeException(__('A snapshotted Merge line disappeared during recovery.', 'wc-order-splitter'));
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
				throw new RuntimeException(__('A Merge line could not be restored.', 'wc-order-splitter'));
			}
			$item->delete_meta_data('_reduced_stock');
			if (null !== $state['reduced_stock']) {
				$item->add_meta_data('_reduced_stock', $state['reduced_stock'], true);
			}
			if ((int) $item_id !== (int) $item->save()) {
				throw new RuntimeException(__('A restored Merge line did not persist.', 'wc-order-splitter'));
			}
		}

		if ('target' === $role) {
			foreach ($target_item_ids as $item_id) {
				if (isset($snapshot[$role]['line_items'][$item_id])) {
					throw new RuntimeException(__('Recovery cannot remove a pre-existing target line.', 'wc-order-splitter'));
				}
				$order->remove_item($item_id);
			}
			foreach ($target_tax_item_ids as $item_id) {
				if (isset($snapshot[$role]['tax_items'][$item_id])) {
					throw new RuntimeException(__('Recovery cannot remove a pre-existing target tax row.', 'wc-order-splitter'));
				}
				$order->remove_item($item_id);
			}
		}

		$tax_items = $order->get_items('tax');
		foreach ($snapshot[$role]['tax_items'] as $item_id => $state) {
			$item = isset($tax_items[$item_id]) ? $tax_items[$item_id] : null;
			if (!$item instanceof WC_Order_Item_Tax || (int) $item->get_rate_id() !== (int) $state['rate_id']) {
				throw new RuntimeException(__('A snapshotted Merge tax row changed during recovery.', 'wc-order-splitter'));
			}
			$result = $item->set_props(array(
				'tax_total' => $state['tax_total'],
				'shipping_tax_total' => $state['shipping_tax_total'],
			));
			if (is_wp_error($result) || (int) $item_id !== (int) $item->save()) {
				throw new RuntimeException(__('A Merge tax row could not be restored.', 'wc-order-splitter'));
			}
		}

		$result = $order->set_props($snapshot[$role]['order_props']);
		if (is_wp_error($result)) {
			throw new RuntimeException(__('Merge order totals or lifecycle state could not be restored.', 'wc-order-splitter'));
		}
		self::restore_relation_state($order, $snapshot[$role]['relation_meta']);
		$order->save();
		$order->get_data_store()->set_stock_reduced($order_id, (bool) $snapshot[$role]['order_stock_reduced']);

		$restored = wc_get_order($order_id);
		$before = self::before_signature($snapshot, $role);
		if (!$restored instanceof WC_Order || !hash_equals($before, self::participant_signature($restored))) {
			throw new RuntimeException(__('A Merge participant did not match its verified pre-operation snapshot after recovery.', 'wc-order-splitter'));
		}
		return $restored;
	}

	private static function participant_state(WC_Order $order) {
		if (!$order->get_id() || 'shop_order' !== $order->get_type()) {
			throw new InvalidArgumentException(__('Merge recovery requires persisted shop orders.', 'wc-order-splitter'));
		}
		$lines = array();
		foreach ($order->get_items('line_item') as $item_id => $item) {
			$reduced = $item->get_meta('_reduced_stock', true);
			$lines[(int) $item_id] = array(
				'identity' => WCOS_Line_Identity::from_item($item),
				'product_id' => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'tax_class' => (string) $item->get_tax_class(),
				'quantity' => WCOS_Decimal::normalize($item->get_quantity(), 6),
				'subtotal' => (string) $item->get_subtotal(),
				'subtotal_tax' => (string) $item->get_subtotal_tax(),
				'total' => (string) $item->get_total(),
				'total_tax' => (string) $item->get_total_tax(),
				'taxes' => self::canonicalize($item->get_taxes()),
				'reduced_stock' => '' === $reduced || null === $reduced ? null : WCOS_Decimal::normalize($reduced, 6),
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

		return array(
			'order_id' => (int) $order->get_id(),
			'commercial_signature' => WCOS_Order_Contract_Snapshot::source_signature($order),
			'order_props' => array(
				'status' => (string) $order->get_status(),
				'discount_total' => (string) $order->get_discount_total(),
				'discount_tax' => (string) $order->get_discount_tax(),
				'shipping_total' => (string) $order->get_shipping_total(),
				'shipping_tax' => (string) $order->get_shipping_tax(),
				'cart_tax' => (string) $order->get_cart_tax(),
				'total_tax' => (string) $order->get_total_tax(),
				'total' => (string) $order->get_total(),
			),
			'order_stock_reduced' => (bool) $order->get_data_store()->get_stock_reduced($order->get_id()),
			'relation_meta' => self::relation_state($order),
			'line_items' => $lines,
			'tax_items' => $taxes,
		);
	}

	private static function assert_participant_state(array $state, $expected_order_id) {
		$expected_keys = array('commercial_signature', 'line_items', 'order_id', 'order_props', 'order_stock_reduced', 'relation_meta', 'tax_items');
		$actual = array_keys($state);
		sort($actual, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		if ($actual !== $expected_keys || absint($state['order_id']) !== $expected_order_id
			|| '' === self::normalized_fingerprint($state['commercial_signature'])
			|| !is_array($state['line_items']) || !is_array($state['tax_items'])
			|| !is_array($state['order_props']) || !is_array($state['relation_meta'])) {
			throw new RuntimeException(__('A Merge participant recovery snapshot is invalid.', 'wc-order-splitter'));
		}
	}

	private static function assert_item_shape(WC_Order $order, array $state, array $allowed_added_ids, array $allowed_added_tax_ids) {
		$current = array_map('intval', array_keys($order->get_items('line_item')));
		$expected = array_map('intval', array_keys($state['line_items']));
		$allowed = array_values(array_unique(array_merge($expected, $allowed_added_ids)));
		sort($current, SORT_NUMERIC);
		sort($expected, SORT_NUMERIC);
		sort($allowed, SORT_NUMERIC);
		if ($current !== $allowed) {
			throw new RuntimeException(__('The Merge line set contains an unexplained external change.', 'wc-order-splitter'));
		}
		foreach ($state['line_items'] as $item_id => $line) {
			$item = $order->get_item((int) $item_id);
			if (!$item instanceof WC_Order_Item_Product || !hash_equals((string) $line['identity'], WCOS_Line_Identity::from_item($item))) {
				throw new RuntimeException(__('A pre-existing Merge line identity changed during recovery.', 'wc-order-splitter'));
			}
		}
		$current_tax = array_map('intval', array_keys($order->get_items('tax')));
		$expected_tax = array_map('intval', array_keys($state['tax_items']));
		sort($current_tax, SORT_NUMERIC);
		sort($expected_tax, SORT_NUMERIC);
		$allowed_tax = array_values(array_unique(array_merge($expected_tax, $allowed_added_tax_ids)));
		sort($allowed_tax, SORT_NUMERIC);
		if ($current_tax !== $allowed_tax) {
			throw new RuntimeException(__('The Merge tax-row set changed during recovery.', 'wc-order-splitter'));
		}
	}

	private static function relation_state(WC_Order $order) {
		$state = array();
		foreach (array(
			WCOS_Merge_Participation::SOURCE_TARGET_META,
			WCOS_Merge_Participation::SOURCE_OPERATION_META,
			WCOS_Merge_Participation::SOURCE_PAIR_FINGERPRINT_META,
			WCOS_Merge_Participation::TARGET_SOURCE_META,
			WCOS_Merge_Participation::TARGET_OPERATION_META,
			WCOS_Merge_Participation::TARGET_AUTHORITY_META,
		) as $key) {
			$values = array_map('strval', self::meta_values($order, $key));
			sort($values, SORT_STRING);
			$state[$key] = $values;
		}
		ksort($state, SORT_STRING);
		return $state;
	}

	private static function restore_relation_state(WC_Order $order, array $state) {
		foreach ($state as $key => $values) {
			$order->delete_meta_data((string) $key);
			foreach ((array) $values as $value) {
				$order->add_meta_data((string) $key, (string) $value, false);
			}
		}
	}

	private static function meta_values(WC_Order $order, $key) {
		$values = array();
		foreach ($order->get_meta_data() as $meta) {
			$data = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : array();
			if (isset($data['key']) && (string) $data['key'] === (string) $key && array_key_exists('value', $data)) {
				$values[] = $data['value'];
			}
		}
		return $values;
	}

	private static function state_signature(array $state) {
		return WCOS_Mutation_Fingerprint::create('merge_participant_recovery_state_v1', absint($state['order_id']), $state);
	}

	private static function active_ownership_signature(array $source, array $target) {
		return WCOS_Mutation_Fingerprint::create(
			'merge_active_ownership_before_v1',
			absint($source['order_id']),
			array(
				'source_commercial_signature' => $source['commercial_signature'],
				'target_commercial_signature' => $target['commercial_signature'],
				'source_stock_reduced' => (bool) $source['order_stock_reduced'],
				'target_stock_reduced' => (bool) $target['order_stock_reduced'],
			)
		);
	}

	private static function canonicalize(array $value) {
		ksort($value, SORT_STRING);
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = self::canonicalize($item);
			}
		}
		return $value;
	}

	private static function normalized_fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
