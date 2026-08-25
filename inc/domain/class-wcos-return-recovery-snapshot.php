<?php

defined('ABSPATH') || exit;

/** PII-free, integrity-checked recovery state for one Return child/original pair. */
final class WCOS_Return_Recovery_Snapshot {
	const SCHEMA_VERSION = 1;

	public static function capture(WC_Order $child, WC_Order $original, array $record) {
		$pair = WCOS_Return_Journal_Context::pair_from_record($record);
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$plan = isset($context['return_plan']) && is_array($context['return_plan']) ? $context['return_plan'] : array();
		if (!is_array($pair) || $pair['child_order_id'] !== absint($child->get_id())
			|| $pair['original_order_id'] !== absint($original->get_id())
			|| !hash_equals($pair['plan_fingerprint'], WCOS_Return_Plan::fingerprint($plan))) {
			throw new RuntimeException(__('Return recovery snapshot does not match pair and plan authority.', 'wc-order-splitter'));
		}
		if (!hash_equals($pair['child_signature_before'], WCOS_Return_Source_Evolution_Authority::sealed_signature('child_commercial', WCOS_Order_Contract_Snapshot::source_signature($child)))
			|| !hash_equals($pair['original_signature_before'], WCOS_Return_Source_Evolution_Authority::sealed_signature('commercial', WCOS_Order_Contract_Snapshot::source_signature($original)))
			|| !hash_equals($pair['original_relation_signature_before'], WCOS_Return_Source_Evolution_Authority::sealed_signature('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($original)))) {
			throw new RuntimeException(__('Return participants changed before the recovery snapshot could be persisted.', 'wc-order-splitter'));
		}
		$child_ids = array();
		$original_ids = array();
		$destinations = array();
		foreach ($plan['lines'] as $source_item_id => $line) {
			$child_ids[] = absint($line['child_item_id']);
			$destination_id = absint($line['destination_source_item_id']);
			if ($destination_id) { $original_ids[] = $destination_id; }
			$destinations[absint($source_item_id)] = array(
				'destination' => sanitize_key((string) $line['destination']),
				'before_item_id' => $destination_id,
				'child_item_id' => absint($line['child_item_id']),
			);
		}
		ksort($destinations, SORT_NUMERIC);
		$snapshot = array(
			'schema_version' => self::SCHEMA_VERSION,
			'pair_fingerprint' => $pair['pair_fingerprint'],
			'plan_fingerprint' => $pair['plan_fingerprint'],
			'child_order_id' => $pair['child_order_id'],
			'original_order_id' => $pair['original_order_id'],
			'price_precision' => $pair['price_precision'],
			'retirement_policy' => $pair['retirement_policy_identifier'],
			'stock_ownership_policy' => $pair['stock_ownership_policy'],
			'order_stock_flag_policy' => $pair['order_stock_flag_policy'],
			'destinations' => $destinations,
			'child' => self::participant_state($child, self::ids($child_ids)),
			'original' => self::participant_state($original, self::ids($original_ids)),
			'physical_stock_before' => self::physical_stock_evidence($child, $plan),
		);
		$snapshot['child_archive_signature_before'] = self::child_archive_signature($child);
		$active_contract = WCOS_Order_Contract_Snapshot::aggregate(array($child, $original), $pair['price_precision']);
		unset($active_contract['stock_reduced']);
		$snapshot['active_economic_contract_before'] = $active_contract;
		$snapshot['active_economic_before_signature'] = WCOS_Mutation_Fingerprint::create('return_active_economic_v1', $original->get_id(), $active_contract);
		$snapshot['expected_checkpoint_signatures'] = array(
			'child_before' => self::state_signature($snapshot['child']),
			'original_before' => self::state_signature($snapshot['original']),
		);
		$snapshot['recovery_fingerprint'] = self::fingerprint($snapshot);
		return $snapshot;
	}

	public static function assert_valid(array $snapshot, array $record = array()) {
		$expected = array(
			'active_economic_before_signature', 'active_economic_contract_before', 'child', 'child_archive_signature_before', 'child_order_id', 'destinations', 'expected_checkpoint_signatures', 'order_stock_flag_policy',
			'original', 'original_order_id', 'pair_fingerprint', 'physical_stock_before', 'plan_fingerprint',
			'price_precision', 'recovery_fingerprint', 'retirement_policy', 'schema_version', 'stock_ownership_policy',
		);
		$actual = array_keys($snapshot);
		sort($actual, SORT_STRING); sort($expected, SORT_STRING);
		$stored = self::normalized_fingerprint(isset($snapshot['recovery_fingerprint']) ? $snapshot['recovery_fingerprint'] : '');
		if ($actual !== $expected || self::SCHEMA_VERSION !== (int) $snapshot['schema_version']
			|| !is_array($snapshot['child']) || !is_array($snapshot['original']) || !is_array($snapshot['destinations'])
			|| !is_array($snapshot['expected_checkpoint_signatures']) || !is_array($snapshot['physical_stock_before']) || !is_array($snapshot['active_economic_contract_before'])
			|| '' === $stored || !hash_equals($stored, self::fingerprint($snapshot))) {
			throw new RuntimeException(__('Return recovery snapshot failed schema or integrity verification.', 'wc-order-splitter'));
		}
		if ('' === self::normalized_fingerprint($snapshot['child_archive_signature_before'])
			|| '' === self::normalized_fingerprint($snapshot['active_economic_before_signature'])
			|| !hash_equals($snapshot['active_economic_before_signature'], WCOS_Mutation_Fingerprint::create('return_active_economic_v1', $snapshot['original_order_id'], $snapshot['active_economic_contract_before']))) {
			throw new RuntimeException(__('Return archive or active-economic authority is malformed.', 'wc-order-splitter'));
		}
		self::assert_participant_state($snapshot['child'], absint($snapshot['child_order_id']));
		self::assert_participant_state($snapshot['original'], absint($snapshot['original_order_id']));
		if (!hash_equals($snapshot['expected_checkpoint_signatures']['child_before'], self::state_signature($snapshot['child']))
			|| !hash_equals($snapshot['expected_checkpoint_signatures']['original_before'], self::state_signature($snapshot['original']))) {
			throw new RuntimeException(__('Return before-checkpoint signatures are invalid.', 'wc-order-splitter'));
		}
		if (!empty($record)) {
			$pair = WCOS_Return_Journal_Context::pair_from_record($record);
			if (!is_array($pair) || $pair['child_order_id'] !== absint($snapshot['child_order_id'])
				|| $pair['original_order_id'] !== absint($snapshot['original_order_id'])
				|| !hash_equals($pair['pair_fingerprint'], self::normalized_fingerprint($snapshot['pair_fingerprint']))
				|| !hash_equals($pair['plan_fingerprint'], self::normalized_fingerprint($snapshot['plan_fingerprint']))
				|| $pair['price_precision'] !== (int) $snapshot['price_precision']) {
				throw new RuntimeException(__('Return recovery snapshot no longer matches journal authority.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	public static function fingerprint(array $snapshot) {
		$copy = $snapshot; unset($copy['recovery_fingerprint']);
		return WCOS_Mutation_Fingerprint::create('return_pair_recovery_v1', absint(isset($copy['child_order_id']) ? $copy['child_order_id'] : 0), $copy);
	}

	public static function before_signature(array $snapshot, $role) {
		$role = sanitize_key((string) $role);
		return isset($snapshot['expected_checkpoint_signatures'][$role . '_before'])
			? self::normalized_fingerprint($snapshot['expected_checkpoint_signatures'][$role . '_before']) : '';
	}

	public static function participant_checkpoint(array $snapshot, $role, WC_Order $order, array $added_item_ids = array()) {
		self::assert_valid($snapshot);
		$role = self::role($role);
		$owned = array_keys($snapshot[$role]['line_items']);
		if ('original' === $role) { $owned = array_merge($owned, self::ids($added_item_ids)); }
		return self::participant_state($order, self::ids($owned), $snapshot[$role]['all_line_item_ids']);
	}

	public static function participant_signature(array $snapshot, $role, WC_Order $order, array $added_item_ids = array()) {
		return self::state_signature(self::participant_checkpoint($snapshot, $role, $order, $added_item_ids));
	}

	public static function assert_resumable(array $before, array $after, WC_Order $order, array $added_item_ids = array()) {
		self::assert_participant_state($before, $order->get_id());
		self::assert_participant_state($after, $order->get_id());
		$current = self::participant_state($order, array_keys($after['line_items']), $before['all_line_item_ids']);
		foreach (array('line_items', 'tax_items') as $component) {
			$component_ids = array_unique(array_merge(array_keys($before[$component]), array_keys($after[$component]), array_keys($current[$component])));
			foreach ($component_ids as $component_id) {
				$current_value = array_key_exists($component_id, $current[$component]) ? $current[$component][$component_id] : null;
				$before_value = array_key_exists($component_id, $before[$component]) ? $before[$component][$component_id] : null;
				$after_value = array_key_exists($component_id, $after[$component]) ? $after[$component][$component_id] : null;
				if ($current_value !== $before_value && $current_value !== $after_value) {
					throw new RuntimeException(__('A Return participant item diverged from its approved component checkpoints.', 'wc-order-splitter'));
				}
			}
		}
		foreach (array('order_props', 'order_stock_reduced', 'relation_meta', 'lifecycle') as $component) {
			if ($current[$component] !== $before[$component] && $current[$component] !== $after[$component]) {
				throw new RuntimeException(__('A Return participant diverged from its approved component checkpoints.', 'wc-order-splitter'));
			}
		}
		self::assert_unaffected($before, $order, $added_item_ids);
		return true;
	}

	public static function assert_exact_checkpoint(array $expected, WC_Order $order, array $added_item_ids = array()) {
		$current = self::participant_state($order, array_keys($expected['line_items']), $expected['all_line_item_ids']);
		if (!hash_equals(self::state_signature($expected), self::state_signature($current))) {
			throw new RuntimeException(__('A Return participant does not match its exact checkpoint.', 'wc-order-splitter'));
		}
		self::assert_unaffected($expected, $order, $added_item_ids);
		return true;
	}

	public static function restore_participant(array $snapshot, $role, array $after, array $added_item_ids = array(), $boundary = null, $checkpoint = null) {
		self::assert_valid($snapshot);
		$role = self::role($role);
		$order_id = absint($snapshot[$role]['order_id']);
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) { throw new RuntimeException(__('A Return recovery participant is unavailable.', 'wc-order-splitter')); }
		if ('child' === $role && 'trash' === $order->get_status()) {
			self::invoke($boundary, 'before_child_untrash', 0);
			if (!method_exists($order, 'untrash') || !$order->untrash()) {
				throw new RuntimeException(__('The archived Return child could not be untrashed for compensation.', 'wc-order-splitter'));
			}
			$order = wc_get_order($order_id); self::invoke($checkpoint, 'child_untrashed', 0);
		}
		self::assert_resumable($snapshot[$role], $after, $order, $added_item_ids);
		if ('original' === $role) {
			foreach (self::ids($added_item_ids) as $item_id) {
				if (isset($snapshot[$role]['line_items'][$item_id])) { throw new RuntimeException(__('Return recovery cannot remove a pre-existing original line.', 'wc-order-splitter')); }
				if (false !== $order->get_item($item_id)) {
					self::invoke($boundary, 'before_original_added_line_cleanup', $item_id);
					$order->remove_item($item_id); $order->save(); self::invoke($checkpoint, 'original_added_line_removed', $item_id);
				}
			}
		}
		$order = wc_get_order($order_id);
		foreach ($snapshot[$role]['line_items'] as $item_id => $state) {
			$item = $order->get_item($item_id);
			if (!$item instanceof WC_Order_Item_Product) { throw new RuntimeException(__('A snapshotted Return line disappeared.', 'wc-order-splitter')); }
			$current = self::line_state($item);
			if ($current === $state) { continue; }
			self::invoke($boundary, 'before_' . $role . '_line_restore', $item_id);
			$result = $item->set_props(array(
				'quantity' => $state['quantity'], 'subtotal' => $state['subtotal'], 'subtotal_tax' => $state['subtotal_tax'],
				'total' => $state['total'], 'total_tax' => $state['total_tax'], 'taxes' => $state['taxes'],
			));
			if (is_wp_error($result)) { throw new RuntimeException(__('A Return line could not be restored.', 'wc-order-splitter')); }
			$item->delete_meta_data('_reduced_stock');
			if (null !== $state['reduced_stock']) { $item->add_meta_data('_reduced_stock', $state['reduced_stock'], true); }
			$item->save(); self::invoke($checkpoint, $role . '_line_restored', $item_id);
		}
		$order = wc_get_order($order_id);
		foreach ($snapshot[$role]['tax_items'] as $item_id => $state) {
			$item = $order->get_item($item_id);
			if (!$item instanceof WC_Order_Item_Tax || (int) $item->get_rate_id() !== $state['rate_id']) {
				throw new RuntimeException(__('A Return tax row changed during compensation.', 'wc-order-splitter'));
			}
			if (self::tax_state($item) !== $state) {
				self::invoke($boundary, 'before_' . $role . '_tax_restore', $item_id);
				$item->set_props(array('tax_total' => $state['tax_total'], 'shipping_tax_total' => $state['shipping_tax_total']));
				$item->save(); self::invoke($checkpoint, $role . '_tax_restored', $item_id);
			}
		}
		$order = wc_get_order($order_id);
		$order->set_props($snapshot[$role]['order_props']); $order->save();
		self::restore_relation_state($order, $snapshot[$role]['relation_meta']); $order->save_meta_data();
		$order->get_data_store()->set_stock_reduced($order_id, (bool) $snapshot[$role]['order_stock_reduced']);
		$restored = wc_get_order($order_id);
		if (!hash_equals(self::before_signature($snapshot, $role), self::participant_signature($snapshot, $role, $restored))) {
			throw new RuntimeException(__('A Return participant did not match its verified pre-operation snapshot.', 'wc-order-splitter'));
		}
		return $restored;
	}

	public static function assert_physical_stock_unchanged(array $snapshot, WC_Order $child, array $plan) {
		$current = self::physical_stock_evidence($child, $plan);
		if ($current !== $snapshot['physical_stock_before']) {
			throw new RuntimeException(__('Physical product stock changed during Return recovery.', 'wc-order-splitter'));
		}
		return true;
	}

	public static function assert_single_operational_owner(array $snapshot, WC_Order $child, WC_Order $original, array $destination_item_ids) {
		$added_original_item_ids = array();
		foreach ($snapshot['destinations'] as $source_item_id => $destination) {
			$child_item = $child->get_item($destination['child_item_id']);
			$destination_id = isset($destination_item_ids[$source_item_id]) ? absint($destination_item_ids[$source_item_id]) : absint($destination['before_item_id']);
			if (!absint($destination['before_item_id']) && $destination_id) { $added_original_item_ids[] = $destination_id; }
			$original_item = $original->get_item($destination_id);
			if (!$child_item instanceof WC_Order_Item_Product || !$original_item instanceof WC_Order_Item_Product
				|| null !== self::nullable_reduced($child_item->get_meta('_reduced_stock', true))) {
				throw new RuntimeException(__('Return did not leave exactly one active line-level stock owner.', 'wc-order-splitter'));
			}
			$child_before = isset($snapshot['child']['line_items'][$destination['child_item_id']]['reduced_stock'])
				? $snapshot['child']['line_items'][$destination['child_item_id']]['reduced_stock'] : null;
			$original_before = isset($snapshot['original']['line_items'][$destination['before_item_id']]['reduced_stock'])
				? $snapshot['original']['line_items'][$destination['before_item_id']]['reduced_stock'] : null;
			$expected_units = WCOS_Decimal::to_units(null === $child_before ? '0' : $child_before, 6)
				+ WCOS_Decimal::to_units(null === $original_before ? '0' : $original_before, 6);
			$actual = self::nullable_reduced($original_item->get_meta('_reduced_stock', true));
			if (0 === $expected_units) {
				if (null !== $actual) {
					throw new RuntimeException(__('Return created line-level stock ownership where none existed.', 'wc-order-splitter'));
				}
			} elseif (null === $actual || $expected_units !== WCOS_Decimal::to_units($actual, 6)) {
				throw new RuntimeException(__('Return did not conserve exact line-level stock ownership.', 'wc-order-splitter'));
			}
		}
		self::assert_unaffected($snapshot['original'], $original, self::ids($added_original_item_ids));
		if (self::has_active_operational_stock_ownership($child)
			|| (bool) $child->get_data_store()->get_stock_reduced($child->get_id())) {
			throw new RuntimeException(__('The retired Return child still owns order-level reduced-stock state.', 'wc-order-splitter'));
		}
		$original_owns_stock = self::has_active_operational_stock_ownership($original);
		if ($original_owns_stock !== (bool) $original->get_data_store()->get_stock_reduced($original->get_id())) {
			throw new RuntimeException(__('Return order-level stock ownership does not match its line-level ownership.', 'wc-order-splitter'));
		}
		return true;
	}

	/** Complete active order ownership, including lines unrelated to this Return plan. */
	public static function has_active_operational_stock_ownership(WC_Order $order) {
		foreach ($order->get_items('line_item') as $item) {
			if (!$item instanceof WC_Order_Item_Product) { continue; }
			$reduced = self::nullable_reduced($item->get_meta('_reduced_stock', true));
			if (null !== $reduced && 0 !== WCOS_Decimal::to_units($reduced, 6)) { return true; }
		}
		return false;
	}

	public static function assert_success_contract(array $snapshot, WC_Order $child, WC_Order $original) {
		if (!hash_equals(self::normalized_fingerprint($snapshot['child_archive_signature_before']), self::child_archive_signature($child))) {
			throw new RuntimeException(__('Return retirement did not preserve the complete child commercial archive.', 'wc-order-splitter'));
		}
		$active = WCOS_Order_Contract_Snapshot::aggregate(array($original), $snapshot['price_precision']);
		unset($active['stock_reduced']);
		$after = WCOS_Mutation_Fingerprint::create('return_active_economic_v1', $original->get_id(), $active);
		if (!hash_equals(self::normalized_fingerprint($snapshot['active_economic_before_signature']), $after)) {
			throw new RuntimeException(__('The original order does not conserve active Return economic ownership.', 'wc-order-splitter'));
		}
		return true;
	}

	private static function participant_state(WC_Order $order, array $owned_item_ids, array $baseline_all_ids = array()) {
		$all_ids = self::ids(array_keys($order->get_items('line_item')));
		$lines = array();
		foreach (self::ids($owned_item_ids) as $item_id) {
			$item = $order->get_item($item_id);
			if ($item instanceof WC_Order_Item_Product) { $lines[$item_id] = self::line_state($item); }
		}
		ksort($lines, SORT_NUMERIC);
		$taxes = array();
		foreach ($order->get_items('tax') as $item_id => $item) { $taxes[(int) $item_id] = self::tax_state($item); }
		ksort($taxes, SORT_NUMERIC);
		$unaffected_ids = array_values(array_diff(empty($baseline_all_ids) ? $all_ids : self::ids($baseline_all_ids), array_keys($lines)));
		return array(
			'order_id' => (int) $order->get_id(),
			'commercial_signature' => WCOS_Return_Source_Evolution_Authority::sealed_signature('snapshot_commercial', WCOS_Order_Contract_Snapshot::source_signature($order)),
			'split_relation_signature' => WCOS_Return_Source_Evolution_Authority::sealed_signature('snapshot_relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($order)),
			'all_line_item_ids' => empty($baseline_all_ids) ? $all_ids : self::ids($baseline_all_ids),
			'unaffected_item_signature' => self::unaffected_signature($order, $unaffected_ids),
			'line_items' => $lines,
			'tax_items' => $taxes,
			'order_props' => array(
				'status' => (string) $order->get_status(), 'discount_total' => (string) $order->get_discount_total(),
				'discount_tax' => (string) $order->get_discount_tax(), 'shipping_total' => (string) $order->get_shipping_total(),
				'shipping_tax' => (string) $order->get_shipping_tax(), 'cart_tax' => (string) $order->get_cart_tax(),
				'total_tax' => (string) $order->get_total_tax(), 'total' => (string) $order->get_total(),
			),
			'order_stock_reduced' => (bool) $order->get_data_store()->get_stock_reduced($order->get_id()),
			'relation_meta' => self::relation_state($order),
			'lifecycle' => array('status' => (string) $order->get_status(), 'trashed' => 'trash' === $order->get_status()),
		);
	}

	private static function line_state(WC_Order_Item_Product $item) {
		return array(
			'identity' => WCOS_Line_Identity::from_item($item), 'product_id' => (int) $item->get_product_id(),
			'variation_id' => (int) $item->get_variation_id(), 'tax_class' => (string) $item->get_tax_class(),
			'quantity' => WCOS_Decimal::normalize($item->get_quantity(), 6), 'subtotal' => (string) $item->get_subtotal(),
			'subtotal_tax' => (string) $item->get_subtotal_tax(), 'total' => (string) $item->get_total(),
			'total_tax' => (string) $item->get_total_tax(), 'taxes' => self::canonicalize($item->get_taxes()),
			'reduced_stock' => self::nullable_reduced($item->get_meta('_reduced_stock', true)),
		);
	}

	private static function tax_state(WC_Order_Item_Tax $item) {
		return array('rate_id' => (int) $item->get_rate_id(), 'tax_total' => (string) $item->get_tax_total(), 'shipping_tax_total' => (string) $item->get_shipping_tax_total());
	}

	private static function child_archive_signature(WC_Order $child) {
		$lines = array();
		foreach ($child->get_items('line_item') as $item_id => $item) {
			$line = self::line_state($item);
			unset($line['reduced_stock']);
			$lines[(int) $item_id] = $line;
		}
		ksort($lines, SORT_NUMERIC);
		return WCOS_Mutation_Fingerprint::create('return_child_archive_v1', $child->get_id(), array(
			'currency' => (string) $child->get_currency(),
			'prices_include_tax' => (bool) $child->get_prices_include_tax(),
			'lines' => $lines,
			'totals' => array(
				'discount_total' => (string) $child->get_discount_total(), 'discount_tax' => (string) $child->get_discount_tax(),
				'shipping_total' => (string) $child->get_shipping_total(), 'shipping_tax' => (string) $child->get_shipping_tax(),
				'cart_tax' => (string) $child->get_cart_tax(), 'total_tax' => (string) $child->get_total_tax(), 'total' => (string) $child->get_total(),
			),
		));
	}

	private static function physical_stock_evidence(WC_Order $child, array $plan) {
		$evidence = array();
		foreach ($plan['lines'] as $line) {
			$item = $child->get_item(absint($line['child_item_id']));
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : false;
			if (!$product instanceof WC_Product) { continue; }
			$owner_id = method_exists($product, 'get_stock_managed_by_id') ? absint($product->get_stock_managed_by_id()) : absint($product->get_id());
			$owner = wc_get_product($owner_id);
			$evidence[$owner_id] = array(
				'managed' => (bool) $product->managing_stock(),
				'quantity' => $owner instanceof WC_Product && null !== $owner->get_stock_quantity() ? WCOS_Decimal::normalize($owner->get_stock_quantity(), 6) : null,
				'status' => $owner instanceof WC_Product ? (string) $owner->get_stock_status() : '',
			);
		}
		ksort($evidence, SORT_NUMERIC); return $evidence;
	}

	private static function assert_unaffected(array $before, WC_Order $order, array $allowed_added_ids) {
		$current_ids = self::ids(array_keys($order->get_items('line_item')));
		$allowed = self::ids(array_merge($before['all_line_item_ids'], $allowed_added_ids));
		if (array_diff($before['all_line_item_ids'], $current_ids) || array_diff($current_ids, $allowed)) {
			throw new RuntimeException(__('Return recovery found an unexplained line-item change.', 'wc-order-splitter'));
		}
		$unaffected = array_values(array_diff($before['all_line_item_ids'], array_keys($before['line_items'])));
		if (!hash_equals($before['unaffected_item_signature'], self::unaffected_signature($order, $unaffected))) {
			throw new RuntimeException(__('A non-Return-owned line changed after the approved checkpoint.', 'wc-order-splitter'));
		}
	}

	private static function unaffected_signature(WC_Order $order, array $ids) {
		$state = array();
		foreach (self::ids($ids) as $item_id) {
			$item = $order->get_item($item_id);
			if (!$item instanceof WC_Order_Item_Product) { throw new RuntimeException(__('An unaffected Return line disappeared.', 'wc-order-splitter')); }
			$state[$item_id] = self::line_state($item);
		}
		return WCOS_Mutation_Fingerprint::create('return_unaffected_lines_v1', $order->get_id(), $state);
	}

	private static function relation_state(WC_Order $order) {
		$keys = array(
			WCOS_Split_Order_Service::RELATION_PARENT_META, WCOS_Split_Order_Service::RELATION_CHILDREN_META,
			WCOS_Split_Order_Service::OPERATION_META, WCOS_Split_Order_Service::CHILD_KEY_META,
			self::meta_key('CHILD_ORIGINAL_META'), self::meta_key('CHILD_OPERATION_META'), self::meta_key('CHILD_PAIR_FINGERPRINT_META'),
			self::meta_key('ORIGINAL_CHILD_META'), self::meta_key('ORIGINAL_OPERATION_META'), self::meta_key('ORIGINAL_AUTHORITY_META'),
		);
		$state = array();
		foreach ($keys as $key) {
			$values = array_map(static function($value) {
				return is_array($value) ? WCOS_Return_Recovery_Snapshot::canonical_relation_value($value) : $value;
			}, self::meta_values($order, $key));
			usort($values, static function($left, $right) { return strcmp(wp_json_encode($left), wp_json_encode($right)); });
			$state[$key] = $values;
		}
		ksort($state, SORT_STRING); return $state;
	}

	private static function restore_relation_state(WC_Order $order, array $state) {
		foreach ($state as $key => $values) {
			$order->delete_meta_data($key);
			foreach ($values as $value) { $order->add_meta_data($key, $value, false); }
		}
	}

	private static function meta_key($constant) { return constant('WCOS_Return_Participation::' . $constant); }
	public static function canonical_relation_value(array $value) { return self::canonicalize($value); }

	private static function meta_values(WC_Order $order, $key) {
		$values = array();
		foreach ($order->get_meta_data() as $meta) {
			$data = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : array();
			if (isset($data['key']) && (string) $data['key'] === (string) $key && array_key_exists('value', $data)) { $values[] = $data['value']; }
		}
		return $values;
	}

	private static function assert_participant_state(array $state, $order_id) {
		$expected = array('all_line_item_ids', 'commercial_signature', 'lifecycle', 'line_items', 'order_id', 'order_props', 'order_stock_reduced', 'relation_meta', 'split_relation_signature', 'tax_items', 'unaffected_item_signature');
		$actual = array_keys($state); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
		if ($actual !== $expected || absint($state['order_id']) !== absint($order_id) || !is_array($state['line_items'])
			|| !is_array($state['tax_items']) || !is_array($state['order_props']) || !is_array($state['relation_meta'])
			|| '' === self::normalized_fingerprint($state['commercial_signature']) || '' === self::normalized_fingerprint($state['split_relation_signature'])
			|| '' === self::normalized_fingerprint($state['unaffected_item_signature'])) {
			throw new RuntimeException(__('A Return participant recovery state is invalid.', 'wc-order-splitter'));
		}
	}

	private static function state_signature(array $state) { return WCOS_Mutation_Fingerprint::create('return_participant_state_v1', absint($state['order_id']), $state); }
	private static function role($role) { $role = sanitize_key((string) $role); if (!in_array($role, array('child', 'original'), true)) { throw new InvalidArgumentException(__('Unknown Return participant role.', 'wc-order-splitter')); } return $role; }
	private static function nullable_reduced($value) { return '' === $value || null === $value ? null : WCOS_Decimal::normalize($value, 6); }
	private static function ids(array $ids) { $ids = array_values(array_unique(array_filter(array_map('absint', $ids)))); sort($ids, SORT_NUMERIC); return $ids; }
	private static function invoke($callback, $stage, $component_id) { if (is_callable($callback)) { call_user_func($callback, sanitize_key((string) $stage), absint($component_id)); } }
	private static function canonicalize($value) { if (!is_array($value)) { return $value; } ksort($value, SORT_STRING); foreach ($value as $key => $item) { $value[$key] = self::canonicalize($item); } return $value; }
	private static function normalized_fingerprint($value) { $value = sanitize_key((string) $value); return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : ''; }
}
