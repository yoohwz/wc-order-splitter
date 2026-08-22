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

	const SCHEMA_VERSION = 4;

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
			'retirement_policy_selected' => (bool) $pair['retirement_policy_selected'],
			'retirement_policy_identifier' => (string) $pair['retirement_policy_identifier'],
			'source' => self::participant_state($source),
			'target' => self::participant_state($target),
			'source_immutable_context' => self::immutable_guard($source),
			'target_immutable_context' => self::immutable_guard($target),
		);
		$snapshot['archive_source_contract_before'] = self::archive_commercial_contract($source);
		$snapshot['archive_source_signature_before'] = self::contract_signature('merge_archive_commercial_v1', $source->get_id(), $snapshot['archive_source_contract_before']);
		$snapshot['active_economic_contract_before'] = self::active_economic_contract(array($source, $target), $pair['price_precision']);
		$snapshot['active_ownership_before_signature'] = self::contract_signature('merge_active_economic_v1', $source->get_id(), $snapshot['active_economic_contract_before']);
		$snapshot['recovery_fingerprint'] = self::fingerprint($snapshot);
		return $snapshot;
	}

	public static function assert_valid(array $snapshot, array $record = array()) {
		$expected_keys = array(
			'active_economic_contract_before', 'active_ownership_before_signature', 'archive_source_contract_before', 'archive_source_signature_before', 'pair_fingerprint',
			'preflight_policy_version', 'price_precision', 'recovery_fingerprint', 'retirement_candidates',
			'retirement_policy_identifier', 'retirement_policy_schema_version', 'retirement_policy_selected', 'schema_version', 'source',
			'source_immutable_context', 'source_order_id', 'target', 'target_immutable_context', 'target_order_id',
		);
		$actual_keys = array_keys($snapshot);
		sort($actual_keys, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		if ($actual_keys !== $expected_keys
			|| self::SCHEMA_VERSION !== (int) $snapshot['schema_version']
			|| !is_array($snapshot['source']) || !is_array($snapshot['target'])
			|| !in_array($snapshot['retirement_policy_selected'], array(true, false), true)
			|| ((bool) $snapshot['retirement_policy_selected'] && WCOS_Merge_Retirement_Policy::approved_identifier() !== sanitize_key((string) $snapshot['retirement_policy_identifier']))
			|| (!(bool) $snapshot['retirement_policy_selected'] && '' !== (string) $snapshot['retirement_policy_identifier'])) {
			throw new RuntimeException(__('The Merge recovery snapshot has an invalid schema.', 'wc-order-splitter'));
		}

		$stored = self::normalized_fingerprint($snapshot['recovery_fingerprint']);
		$actual = self::fingerprint($snapshot);
		if ('' === $stored || !hash_equals($stored, $actual)) {
			throw new RuntimeException(__('The Merge recovery snapshot failed its integrity fingerprint.', 'wc-order-splitter'));
		}
		self::assert_participant_state($snapshot['source'], absint($snapshot['source_order_id']));
		self::assert_participant_state($snapshot['target'], absint($snapshot['target_order_id']));
		self::assert_immutable_guard($snapshot['source_immutable_context'], absint($snapshot['source_order_id']));
		self::assert_immutable_guard($snapshot['target_immutable_context'], absint($snapshot['target_order_id']));
		if (!hash_equals((string) $snapshot['archive_source_signature_before'], self::contract_signature('merge_archive_commercial_v1', $snapshot['source_order_id'], $snapshot['archive_source_contract_before']))
			|| !hash_equals((string) $snapshot['active_ownership_before_signature'], self::contract_signature('merge_active_economic_v1', $snapshot['source_order_id'], $snapshot['active_economic_contract_before']))) {
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
				|| array_values($snapshot['retirement_candidates']) !== $pair['retirement_candidates']
				|| (bool) $snapshot['retirement_policy_selected'] !== (bool) $pair['retirement_policy_selected']
				|| (string) $snapshot['retirement_policy_identifier'] !== (string) $pair['retirement_policy_identifier']
				|| !hash_equals((string) $snapshot['archive_source_signature_before'], $pair['archive_source_signature_before'])
				|| !hash_equals((string) $snapshot['active_ownership_before_signature'], $pair['active_ownership_before_signature'])) {
				throw new RuntimeException(__('The Merge recovery snapshot no longer matches pair authority.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	public static function fingerprint(array $snapshot) {
		$copy = $snapshot;
		unset($copy['recovery_fingerprint']);
		return WCOS_Mutation_Fingerprint::create(
			'merge_pair_recovery_v4',
			isset($copy['source_order_id']) ? absint($copy['source_order_id']) : 0,
			$copy
		);
	}

	public static function participant_signature(WC_Order $order) {
		$state = self::participant_state($order);
		return self::state_signature($state);
	}

	/** Full lifecycle/stock-aware participant state used only for exact recovery. */
	public static function participant_checkpoint(WC_Order $order) {
		return self::participant_state($order);
	}

	/** Commercial archive contract deliberately excludes lifecycle, relations, and stock markers. */
	public static function archive_commercial_contract(WC_Order $order) {
		$state = self::participant_state($order);
		$lines = $state['line_items'];
		foreach ($lines as &$line) {
			unset($line['reduced_stock']);
		}
		unset($line);
		$props = $state['order_props'];
		unset($props['status']);
		return array(
			'order_id' => $state['order_id'],
			'currency' => (string) $order->get_currency(),
			'prices_include_tax' => (bool) $order->get_prices_include_tax(),
			'order_props' => $props,
			'line_items' => $lines,
			'tax_items' => $state['tax_items'],
		);
	}

	public static function archive_commercial_signature(WC_Order $order) {
		return self::contract_signature('merge_archive_commercial_v1', $order->get_id(), self::archive_commercial_contract($order));
	}

	/** Exact quantity/money/per-rate-tax/currency aggregate; operational stock is excluded. */
	public static function active_economic_contract(array $orders, $precision) {
		$contract = WCOS_Order_Contract_Snapshot::aggregate($orders, $precision);
		unset($contract['stock_reduced']);
		return $contract;
	}

	public static function active_economic_signature(array $orders, $precision, $authority_order_id) {
		return self::contract_signature('merge_active_economic_v1', $authority_order_id, self::active_economic_contract($orders, $precision));
	}

	public static function assert_archive_preserved(array $snapshot, WC_Order $source) {
		self::assert_valid($snapshot);
		WCOS_Merge_Retirement_Policy::assert_archive_preserved($snapshot['archive_source_signature_before'], self::archive_commercial_signature($source));
	}

	public static function assert_active_economic_conserved(array $snapshot, WC_Order $target) {
		self::assert_valid($snapshot);
		WCOS_Merge_Retirement_Policy::assert_active_ownership_conserved(
			$snapshot['active_ownership_before_signature'],
			self::active_economic_signature(array($target), $snapshot['price_precision'], $snapshot['source_order_id'])
		);
	}

	/**
	 * Prove keyed customer context and all non-Merge-owned participant state are
	 * unchanged. This guard never restores external state; callers fail closed.
	 */
	public static function assert_immutable_pair(
		array $snapshot,
		array $record,
		WC_Order $source,
		WC_Order $target,
		array $target_item_ids = array(),
		array $target_tax_item_ids = array()
	) {
		self::assert_valid($snapshot, $record);
		$pair = WCOS_Merge_Journal_Context::pair_from_record($record);
		if (!is_array($pair) || !isset($pair['context_authority']) || !is_array($pair['context_authority'])) {
			throw new RuntimeException(__('Immutable Merge context authority is unavailable.', 'wc-order-splitter'));
		}
		WCOS_Merge_Context_Signature::assert_current($source, $pair['context_authority']);
		WCOS_Merge_Context_Signature::assert_current($target, $pair['context_authority']);
		self::assert_immutable_participant($snapshot['source_immutable_context'], $source, array(), array());
		self::assert_immutable_participant($snapshot['target_immutable_context'], $target, $target_item_ids, $target_tax_item_ids);
		return true;
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
		array $after_state,
		array $target_item_ids = array(),
		array $target_tax_item_ids = array(),
		$boundary = null,
		$checkpoint = null,
		$retirement_candidate = ''
	) {
		self::assert_valid($snapshot);
		$role = sanitize_key((string) $role);
		if (!in_array($role, array('source', 'target'), true)) {
			throw new InvalidArgumentException(__('A valid Merge recovery participant role is required.', 'wc-order-splitter'));
		}
		$order_id = absint($snapshot[$role]['order_id']);
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order || 'shop_order' !== $order->get_type()) {
			throw new RuntimeException(__('A Merge recovery participant is unavailable.', 'wc-order-splitter'));
		}
		$retirement_candidate = sanitize_key((string) $retirement_candidate);
		if ('source' === $role && WCOS_Merge_Retirement_Policy::NON_FORCE_TRASH_ARCHIVE === $retirement_candidate && 'trash' === $order->get_status()) {
			self::invoke($boundary, 'before_source_untrash', 0);
			if (!method_exists($order, 'untrash') || !$order->untrash()) {
				throw new RuntimeException(__('The non-force archived Merge source could not be untrashed for recovery.', 'wc-order-splitter'));
			}
			$order = wc_get_order($order_id);
			self::invoke($checkpoint, 'source_untrashed', 0);
		}
		$target_item_ids = array_values(array_unique(array_filter(array_map('absint', $target_item_ids))));
		sort($target_item_ids, SORT_NUMERIC);
		$target_tax_item_ids = array_values(array_unique(array_filter(array_map('absint', $target_tax_item_ids))));
		sort($target_tax_item_ids, SORT_NUMERIC);
		self::assert_resumable_participant($snapshot[$role], $after_state, $order, 'target' === $role ? $target_item_ids : array(), 'target' === $role ? $target_tax_item_ids : array());

		$line_items = $order->get_items('line_item');
		foreach ($snapshot[$role]['line_items'] as $item_id => $state) {
			$item = isset($line_items[$item_id]) ? $line_items[$item_id] : null;
			if (!$item instanceof WC_Order_Item_Product) {
				throw new RuntimeException(__('A snapshotted Merge line disappeared during recovery.', 'wc-order-splitter'));
			}
			$current_line = self::participant_state($order)['line_items'][$item_id];
			if ($current_line === $state) {
				continue;
			}
			self::invoke($boundary, 'before_' . $role . '_line_restore', $item_id);
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
			self::invoke($checkpoint, $role . '_line_restored', $item_id);
		}

		if ('target' === $role) {
			foreach ($target_item_ids as $item_id) {
				if (isset($snapshot[$role]['line_items'][$item_id])) {
					throw new RuntimeException(__('Recovery cannot remove a pre-existing target line.', 'wc-order-splitter'));
				}
				if (false !== $order->get_item($item_id)) {
					self::invoke($boundary, 'before_target_added_line_cleanup', $item_id);
					$order->remove_item($item_id);
					$order->save();
					self::invoke($checkpoint, 'target_added_line_removed', $item_id);
				}
			}
			foreach ($target_tax_item_ids as $item_id) {
				if (isset($snapshot[$role]['tax_items'][$item_id])) {
					throw new RuntimeException(__('Recovery cannot remove a pre-existing target tax row.', 'wc-order-splitter'));
				}
				if (false !== $order->get_item($item_id)) {
					self::invoke($boundary, 'before_target_added_tax_cleanup', $item_id);
					$order->remove_item($item_id);
					$order->save();
					self::invoke($checkpoint, 'target_added_tax_removed', $item_id);
				}
			}
		}

		$tax_items = $order->get_items('tax');
		foreach ($snapshot[$role]['tax_items'] as $item_id => $state) {
			$item = isset($tax_items[$item_id]) ? $tax_items[$item_id] : null;
			if (!$item instanceof WC_Order_Item_Tax || (int) $item->get_rate_id() !== (int) $state['rate_id']) {
				throw new RuntimeException(__('A snapshotted Merge tax row changed during recovery.', 'wc-order-splitter'));
			}
			$current_tax = self::participant_state($order)['tax_items'][$item_id];
			if ($current_tax === $state) {
				continue;
			}
			self::invoke($boundary, 'before_' . $role . '_tax_restore', $item_id);
			$result = $item->set_props(array(
				'tax_total' => $state['tax_total'],
				'shipping_tax_total' => $state['shipping_tax_total'],
			));
			if (is_wp_error($result) || (int) $item_id !== (int) $item->save()) {
				throw new RuntimeException(__('A Merge tax row could not be restored.', 'wc-order-splitter'));
			}
			self::invoke($checkpoint, $role . '_tax_restored', $item_id);
		}

		$current_state = self::participant_state($order);
		if ($current_state['order_props'] !== $snapshot[$role]['order_props']) {
			self::invoke($boundary, 'before_' . $role . '_order_restore', 0);
			$result = $order->set_props($snapshot[$role]['order_props']);
			if (is_wp_error($result)) {
				throw new RuntimeException(__('Merge order totals or lifecycle state could not be restored.', 'wc-order-splitter'));
			}
			$order->save();
			self::invoke($checkpoint, $role . '_order_restored', 0);
		}
		$order = wc_get_order($order_id);
		$current_state = self::participant_state($order);
		if ($current_state['relation_meta'] !== $snapshot[$role]['relation_meta']) {
			self::invoke($boundary, 'before_' . $role . '_relation_restore', 0);
			self::restore_relation_state($order, $snapshot[$role]['relation_meta']);
			$order->save();
			self::invoke($checkpoint, $role . '_relations_restored', 0);
		}
		$order = wc_get_order($order_id);
		if ((bool) $order->get_data_store()->get_stock_reduced($order_id) !== (bool) $snapshot[$role]['order_stock_reduced']) {
			self::invoke($boundary, 'before_' . $role . '_stock_marker_restore', 0);
			$order->get_data_store()->set_stock_reduced($order_id, (bool) $snapshot[$role]['order_stock_reduced']);
			self::invoke($checkpoint, $role . '_stock_marker_restored', 0);
		}

		$restored = wc_get_order($order_id);
		$before = self::before_signature($snapshot, $role);
		if (!$restored instanceof WC_Order || !hash_equals($before, self::participant_signature($restored))) {
			throw new RuntimeException(__('A Merge participant did not match its verified pre-operation snapshot after recovery.', 'wc-order-splitter'));
		}
		return $restored;
	}

	/** Prove every operation-owned component is exactly at its before or after checkpoint. */
	public static function assert_resumable_participant(array $before, array $after, WC_Order $order, array $added_ids = array(), array $added_tax_ids = array()) {
		self::assert_participant_state($before, $order->get_id());
		self::assert_participant_state($after, $order->get_id());
		$current = self::participant_state($order);
		self::assert_item_shape($order, $before, $added_ids, $added_tax_ids, true);
		foreach ($before['line_items'] as $id => $state) {
			if (!isset($current['line_items'][$id]) || ($current['line_items'][$id] !== $state && (!isset($after['line_items'][$id]) || $current['line_items'][$id] !== $after['line_items'][$id]))) {
				throw new RuntimeException(__('A Merge line diverged from its resumable checkpoints.', 'wc-order-splitter'));
			}
		}
		foreach ($added_ids as $id) {
			$id = absint($id);
			if (isset($current['line_items'][$id]) && (!isset($after['line_items'][$id]) || $current['line_items'][$id] !== $after['line_items'][$id])) {
				throw new RuntimeException(__('An operation-owned target line diverged before cleanup.', 'wc-order-splitter'));
			}
		}
		foreach ($before['tax_items'] as $id => $state) {
			if (!isset($current['tax_items'][$id]) || ($current['tax_items'][$id] !== $state && (!isset($after['tax_items'][$id]) || $current['tax_items'][$id] !== $after['tax_items'][$id]))) {
				throw new RuntimeException(__('A Merge tax row diverged from its resumable checkpoints.', 'wc-order-splitter'));
			}
		}
		foreach ($added_tax_ids as $id) {
			$id = absint($id);
			if (isset($current['tax_items'][$id]) && (!isset($after['tax_items'][$id]) || $current['tax_items'][$id] !== $after['tax_items'][$id])) {
				throw new RuntimeException(__('An operation-owned target tax row diverged before cleanup.', 'wc-order-splitter'));
			}
		}
		foreach (array('order_props', 'relation_meta', 'order_stock_reduced') as $component) {
			if ($current[$component] !== $before[$component] && $current[$component] !== $after[$component]) {
				throw new RuntimeException(__('A Merge participant component diverged from its resumable checkpoints.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	/** Forward repair may resume with operation-owned reciprocal metadata partially persisted. */
	public static function assert_forward_checkpoint(array $after, WC_Order $order) {
		self::assert_participant_state($after, $order->get_id());
		$current = self::participant_state($order);
		foreach (array('line_items', 'tax_items', 'order_props', 'order_stock_reduced') as $component) {
			if ($current[$component] !== $after[$component]) {
				throw new RuntimeException(__('A Merge forward participant changed outside reciprocal relation metadata.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	private static function immutable_guard(WC_Order $order) {
		$item_ids = array();
		foreach (array('line_item', 'shipping', 'fee', 'tax', 'coupon') as $type) {
			$item_ids[$type] = array_map('intval', array_keys($order->get_items($type)));
			sort($item_ids[$type], SORT_NUMERIC);
		}
		return array(
			'schema_version' => 1,
			'order_id' => (int) $order->get_id(),
			'item_ids' => $item_ids,
			'immutable_signature' => self::immutable_signature($order, $item_ids),
		);
	}

	private static function assert_immutable_guard($guard, $expected_order_id) {
		$expected_types = array('coupon', 'fee', 'line_item', 'shipping', 'tax');
		if (!is_array($guard)) {
			throw new RuntimeException(__('A Merge immutable-context guard is missing.', 'wc-order-splitter'));
		}
		$keys = array_keys($guard);
		$expected_keys = array('immutable_signature', 'item_ids', 'order_id', 'schema_version');
		sort($keys, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		$types = isset($guard['item_ids']) && is_array($guard['item_ids']) ? array_keys($guard['item_ids']) : array();
		sort($types, SORT_STRING);
		if ($keys !== $expected_keys || 1 !== (int) $guard['schema_version']
			|| absint($guard['order_id']) !== absint($expected_order_id)
			|| $types !== $expected_types || '' === self::normalized_fingerprint($guard['immutable_signature'])) {
			throw new RuntimeException(__('A Merge immutable-context guard has an invalid schema.', 'wc-order-splitter'));
		}
		foreach ($guard['item_ids'] as $ids) {
			$normalized = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
			sort($normalized, SORT_NUMERIC);
			if ($normalized !== array_values($ids)) {
				throw new RuntimeException(__('A Merge immutable-context item authority is non-canonical.', 'wc-order-splitter'));
			}
		}
	}

	private static function assert_immutable_participant(array $guard, WC_Order $order, array $allowed_line_ids, array $allowed_tax_ids) {
		self::assert_immutable_guard($guard, $order->get_id());
		$allowed_additions = array(
			'line_item' => array_values(array_unique(array_filter(array_map('absint', $allowed_line_ids)))),
			'tax' => array_values(array_unique(array_filter(array_map('absint', $allowed_tax_ids)))),
		);
		foreach ($guard['item_ids'] as $type => $baseline) {
			$current = array_map('intval', array_keys($order->get_items($type)));
			$allowed = array_values(array_unique(array_merge($baseline, isset($allowed_additions[$type]) ? $allowed_additions[$type] : array())));
			sort($current, SORT_NUMERIC);
			sort($allowed, SORT_NUMERIC);
			if (array_diff($baseline, $current) || array_diff($current, $allowed)) {
				throw new RuntimeException(__('A non-Merge order item changed outside immutable participant authority.', 'wc-order-splitter'));
			}
		}
		$actual = self::immutable_signature($order, $guard['item_ids']);
		if (!hash_equals((string) $guard['immutable_signature'], $actual)) {
			throw new RuntimeException(__('Immutable non-Merge participant context changed after its approved checkpoint.', 'wc-order-splitter'));
		}
	}

	private static function immutable_signature(WC_Order $order, array $item_ids) {
		$items = array();
		foreach ($item_ids as $type => $ids) {
			foreach ($ids as $item_id) {
				$item = $order->get_item($item_id);
				if (!$item instanceof WC_Order_Item || $item->get_type() !== $type) {
					throw new RuntimeException(__('An immutable Merge participant item disappeared or changed type.', 'wc-order-splitter'));
				}
				$state = array(
					'id' => (int) $item_id,
					'type' => (string) $item->get_type(),
					'name' => (string) $item->get_name(),
					'business_meta' => WCOS_Order_Item_Meta_Policy::business_metadata($item),
				);
				if ($item instanceof WC_Order_Item_Product) {
					$state += array(
						'product_id' => (int) $item->get_product_id(),
						'variation_id' => (int) $item->get_variation_id(),
						'tax_class' => (string) $item->get_tax_class(),
					);
				} elseif ($item instanceof WC_Order_Item_Shipping) {
					$state += array(
						'method_id' => (string) $item->get_method_id(),
						'instance_id' => (int) $item->get_instance_id(),
						'total' => (string) $item->get_total(),
						'total_tax' => (string) $item->get_total_tax(),
						'taxes' => self::canonicalize($item->get_taxes()),
					);
				} elseif ($item instanceof WC_Order_Item_Fee) {
					$state += array(
						'amount' => (string) $item->get_amount(),
						'tax_class' => (string) $item->get_tax_class(),
						'tax_status' => (string) $item->get_tax_status(),
						'total' => (string) $item->get_total(),
						'total_tax' => (string) $item->get_total_tax(),
						'taxes' => self::canonicalize($item->get_taxes()),
					);
				} elseif ($item instanceof WC_Order_Item_Tax) {
					$state += array(
						'rate_id' => (int) $item->get_rate_id(),
						'compound' => (bool) $item->get_compound(),
						'rate_percent' => (string) $item->get_rate_percent(),
					);
				} elseif ($item instanceof WC_Order_Item_Coupon) {
					$state += array(
						'code' => (string) $item->get_code(),
						'discount' => (string) $item->get_discount(),
						'discount_tax' => (string) $item->get_discount_tax(),
					);
				}
				$items[$type][(int) $item_id] = $state;
			}
		}
		$contract = array(
			'order_id' => (int) $order->get_id(),
			'type' => (string) $order->get_type(),
			'currency' => (string) $order->get_currency(),
			'prices_include_tax' => (bool) $order->get_prices_include_tax(),
			'transaction_id' => (string) $order->get_transaction_id(),
			'customer_note' => (string) $order->get_customer_note(),
			'parent_id' => (int) $order->get_parent_id(),
			'created_via' => (string) $order->get_created_via(),
			'items' => $items,
		);
		$secret = (string) wp_salt('auth');
		$document = wp_json_encode(
			array(
				'schema_version' => 1,
				'purpose' => 'merge_immutable_participant_v1',
				'order_id' => (int) $order->get_id(),
				'contract' => self::canonicalize($contract),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ('' === $secret || !is_string($document) || '' === $document) {
			throw new RuntimeException(__('Immutable Merge participant context could not be keyed.', 'wc-order-splitter'));
		}
		return hash_hmac('sha256', $document, $secret);
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
			'participant_recovery_signature' => WCOS_Order_Contract_Snapshot::source_signature($order),
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
		$expected_keys = array('line_items', 'order_id', 'order_props', 'order_stock_reduced', 'participant_recovery_signature', 'relation_meta', 'tax_items');
		$actual = array_keys($state);
		sort($actual, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		if ($actual !== $expected_keys || absint($state['order_id']) !== $expected_order_id
			|| '' === self::normalized_fingerprint($state['participant_recovery_signature'])
			|| !is_array($state['line_items']) || !is_array($state['tax_items'])
			|| !is_array($state['order_props']) || !is_array($state['relation_meta'])) {
			throw new RuntimeException(__('A Merge participant recovery snapshot is invalid.', 'wc-order-splitter'));
		}
	}

	private static function assert_item_shape(WC_Order $order, array $state, array $allowed_added_ids, array $allowed_added_tax_ids, $allow_removed_additions = false) {
		$current = array_map('intval', array_keys($order->get_items('line_item')));
		$expected = array_map('intval', array_keys($state['line_items']));
		$allowed = array_values(array_unique(array_merge($expected, $allowed_added_ids)));
		sort($current, SORT_NUMERIC);
		sort($expected, SORT_NUMERIC);
		sort($allowed, SORT_NUMERIC);
		if (($allow_removed_additions && array_diff($current, $allowed)) || (!$allow_removed_additions && $current !== $allowed) || array_diff($expected, $current)) {
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
		if (($allow_removed_additions && array_diff($current_tax, $allowed_tax)) || (!$allow_removed_additions && $current_tax !== $allowed_tax) || array_diff($expected_tax, $current_tax)) {
			throw new RuntimeException(__('The Merge tax-row set changed during recovery.', 'wc-order-splitter'));
		}
	}

	private static function invoke($callback, $stage, $component_id) {
		if (is_callable($callback)) {
			call_user_func($callback, sanitize_key((string) $stage), absint($component_id));
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
		return WCOS_Mutation_Fingerprint::create('merge_participant_recovery_state_v2', absint($state['order_id']), $state);
	}

	private static function contract_signature($namespace, $order_id, array $contract) {
		return WCOS_Mutation_Fingerprint::create($namespace, absint($order_id), $contract);
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
