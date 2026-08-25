<?php

defined('ABSPATH') || exit;

final class WCOS_Return_Lineage_Exception extends RuntimeException {
	private $reason;

	public function __construct($reason, $message) {
		$this->reason = sanitize_key((string) $reason);
		parent::__construct((string) $message);
	}

	public function get_reason() {
		return $this->reason;
	}
}

/**
 * Read-only proof that one current order is an exact hardened Split child.
 *
 * Authority is derived from the completed source-keyed Split journal, its
 * integrity-checked pre-Split source snapshot, the immutable Split request
 * fingerprint, and current persisted participant state. Legacy relation meta
 * is corroboration only and never mints executable authority.
 */
final class WCOS_Return_Lineage_Authority {

	const SCHEMA_VERSION = 1;
	const POLICY_VERSION = 1;

	public static function resolve(WC_Order $child) {
		$child_id = absint($child->get_id());
		if (!$child_id || 'shop_order' !== $child->get_type()) {
			self::reject('invalid_child', __('Return lineage requires a persisted WooCommerce shop order child.', 'wc-order-splitter'));
		}

		$raw_source_id = $child->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true);
		if ('' === $raw_source_id || null === $raw_source_id) {
			$legacy = self::legacy_candidate($child);
			if (!empty($legacy['legacy_parent_id'])) {
				self::reject('legacy_lineage_not_authoritative', __('Legacy Split metadata is diagnostic only and cannot authorize Return.', 'wc-order-splitter'));
			}
			self::reject('lineage_missing', __('This order does not carry hardened Split parent authority.', 'wc-order-splitter'));
		}
		$source_id = self::positive_int_scalar($raw_source_id, 'parent_order_id');
		if ($source_id === $child_id) {
			self::reject('same_participant', __('A Return child cannot be its own original order.', 'wc-order-splitter'));
		}

		$raw_operation_id = $child->get_meta(WCOS_Split_Order_Service::OPERATION_META, true);
		$operation_id = self::canonical_key_scalar($raw_operation_id, 'split_operation_id');
		$raw_child_key = $child->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true);
		$child_key = self::canonical_key_scalar($raw_child_key, 'split_child_key');

		$source = wc_get_order($source_id);
		if (!$source instanceof WC_Order || 'shop_order' !== $source->get_type()) {
			self::reject('source_missing', __('The hardened Split original order is unavailable.', 'wc-order-splitter'));
		}

		self::assert_structured_relation($source, $child_id);
		$legacy = self::legacy_candidate($child, $source);
		if (!empty($legacy['conflict'])) {
			self::reject('legacy_lineage_conflict', __('Legacy relation metadata conflicts with hardened Split authority.', 'wc-order-splitter'));
		}

		$record = WCOS_Operation_Journal::get($source, $operation_id);
		self::assert_journal_identity($record, $source_id, $operation_id);
		$context = $record['context'];
		$plan = self::canonical_split_plan($context);
		if (!isset($plan[$child_key])) {
			self::reject('child_key_not_in_plan', __('The Split child key is absent from the durable Split plan.', 'wc-order-splitter'));
		}

		$execution_policy = self::execution_policy($context);
		$fully_moved = self::canonical_id_list(isset($context['fully_moved_item_ids']) ? $context['fully_moved_item_ids'] : array(), 'fully_moved_item_ids');
		$price_precision = WCOS_Price_Precision_Scope::validate(isset($context['price_precision']) ? $context['price_precision'] : null);
		$snapshot = self::source_snapshot($context, $source_id);
		$derived_fully_moved = self::derive_fully_moved_ids($snapshot, $plan);
		if ($derived_fully_moved !== $fully_moved) {
			self::reject('fully_moved_authority_mismatch', __('The durable Split whole-line provenance does not match its source snapshot and plan.', 'wc-order-splitter'));
		}
		if (WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY === $execution_policy && !empty($fully_moved)) {
			self::reject('execution_policy_mismatch', __('A partial-line Split journal contains whole-line provenance.', 'wc-order-splitter'));
		}

		$strategy = self::assert_strategy_authority($context, $execution_policy, $snapshot);
		self::assert_split_fingerprint($record, $plan, $execution_policy, $context);
		self::assert_target_set($source, $context, $plan, $source_id, $operation_id, $child_id, $child_key);

		$expected_source_signature = self::fingerprint_value(isset($context['source_signature_after']) ? $context['source_signature_after'] : '', 'source_signature_after');
		$expected_source_recovery_signature = self::fingerprint_value(isset($context['source_recovery_signature_after']) ? $context['source_recovery_signature_after'] : '', 'source_recovery_signature_after');
		if (!hash_equals($expected_source_signature, WCOS_Order_Contract_Snapshot::source_signature($source))
			|| !hash_equals($expected_source_recovery_signature, WCOS_Order_Mutation_Snapshot::split_owned_signature($source))) {
			self::reject('source_drift', __('The original order changed after the completed Split operation.', 'wc-order-splitter'));
		}

		try {
			WCOS_Order_Copy_Context::assert_matches($snapshot['copy_context_signature'], $child);
			WCOS_Order_Totals_Rebuilder::assert_consistent($source, $price_precision);
			WCOS_Order_Totals_Rebuilder::assert_consistent($child, $price_precision);
		} catch (Throwable $throwable) {
			self::reject('commercial_context_drift', __('The Return participant commercial context no longer matches durable Split evidence.', 'wc-order-splitter'));
		}

		$lines = self::prove_child_lines($source, $child, $snapshot, $plan, $child_key, $fully_moved, $price_precision);
		$child_signature = WCOS_Order_Contract_Snapshot::source_signature($child);
		$authority = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'child_order_id' => $child_id,
			'source_order_id' => $source_id,
			'split_operation_id' => $operation_id,
			'split_child_key' => $child_key,
			'split_operation_fingerprint' => self::fingerprint_value($record['fingerprint'], 'split_operation_fingerprint'),
			'split_plan_fingerprint' => WCOS_Mutation_Fingerprint::create('split_plan', $source_id, $plan),
			'source_snapshot_authority' => self::sealed_fingerprint('source_snapshot', $snapshot['recovery_fingerprint']),
			'execution_policy' => $execution_policy,
			'strategy' => $strategy,
			'price_precision' => $price_precision,
			'currency' => (string) $source->get_currency(),
			'prices_include_tax' => (bool) $source->get_prices_include_tax(),
			'source_commercial_authority' => self::sealed_fingerprint('source_commercial', $expected_source_signature),
			'source_relation_authority' => self::sealed_fingerprint('source_relation', $expected_source_recovery_signature),
			'child_commercial_authority' => self::sealed_fingerprint('child_commercial', $child_signature),
			'lines' => $lines,
			'legacy_diagnosis' => $legacy,
		);
		$authority['authority_fingerprint'] = self::fingerprint($authority);
		return $authority;
	}

	public static function fingerprint(array $authority) {
		$copy = $authority;
		unset($copy['authority_fingerprint']);
		return WCOS_Mutation_Fingerprint::create(
			'return_lineage_authority',
			absint(isset($copy['child_order_id']) ? $copy['child_order_id'] : 0),
			self::canonicalize($copy)
		);
	}

	public static function legacy_candidate(WC_Order $child, ?WC_Order $source = null) {
		$legacy_parent_raw = $child->get_meta('yoos_original_order', true);
		$legacy_parent_id = 0;
		$malformed = false;
		if ('' !== $legacy_parent_raw && null !== $legacy_parent_raw) {
			try {
				$legacy_parent_id = self::positive_int_scalar($legacy_parent_raw, 'legacy_parent_id');
			} catch (WCOS_Return_Lineage_Exception $exception) {
				$malformed = true;
			}
		}

		$reciprocal = null;
		if ($source instanceof WC_Order) {
			try {
				$legacy_children = self::legacy_id_list($source->get_meta('yoos_splitted_order', true));
				$reciprocal = in_array($child->get_id(), $legacy_children, true);
			} catch (WCOS_Return_Lineage_Exception $exception) {
				$malformed = true;
				$reciprocal = false;
			}
		}

		$structured_parent = 0;
		try {
			$raw = $child->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true);
			if ('' !== $raw && null !== $raw) {
				$structured_parent = self::positive_int_scalar($raw, 'parent_order_id');
			}
		} catch (WCOS_Return_Lineage_Exception $exception) {
			$malformed = true;
		}

		$conflict = $malformed
			|| ($legacy_parent_id && $structured_parent && $legacy_parent_id !== $structured_parent)
			|| (null !== $reciprocal && $legacy_parent_id && !$reciprocal);

		return array(
			'executable' => false,
			'reason' => 'legacy_lineage_not_authoritative',
			'legacy_parent_id' => $legacy_parent_id,
			'source_exists' => $source instanceof WC_Order,
			'reciprocal_legacy_relation' => $reciprocal,
			'conflict' => $conflict,
			'migration_required' => $legacy_parent_id > 0,
		);
	}

	private static function assert_journal_identity($record, $source_id, $operation_id) {
		if (!is_array($record)) {
			self::reject('journal_missing', __('The authoritative Split journal is missing.', 'wc-order-splitter'));
		}
		if (absint(isset($record['source_order_id']) ? $record['source_order_id'] : 0) !== $source_id
			|| sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '') !== $operation_id) {
			self::reject('journal_identity_mismatch', __('The Split journal does not belong to this source and operation.', 'wc-order-splitter'));
		}
		if ('split' !== sanitize_key(isset($record['type']) ? (string) $record['type'] : '')) {
			self::reject('journal_wrong_type', __('The durable operation is not a Split journal.', 'wc-order-splitter'));
		}
		if ('completed' !== sanitize_key(isset($record['status']) ? (string) $record['status'] : '')) {
			self::reject('journal_not_completed', __('Return requires a completed Split journal.', 'wc-order-splitter'));
		}
		if (empty($record['context']) || !is_array($record['context'])) {
			self::reject('journal_context_missing', __('The completed Split journal is missing durable context.', 'wc-order-splitter'));
		}
	}

	private static function canonical_split_plan(array $context) {
		if (empty($context['plan']) || !is_array($context['plan'])) {
			self::reject('split_plan_missing', __('The Split journal is missing its normalized plan.', 'wc-order-splitter'));
		}
		try {
			$plan = WCOS_Split_Plan::canonicalize_request($context['plan']);
		} catch (Throwable $throwable) {
			self::reject('split_plan_malformed', __('The durable Split plan is malformed.', 'wc-order-splitter'));
		}
		if ($plan !== $context['plan']) {
			self::reject('split_plan_not_canonical', __('The durable Split plan is not canonical.', 'wc-order-splitter'));
		}
		$child_keys = isset($context['child_keys']) ? array_values($context['child_keys']) : array();
		if (WCOS_Split_Plan::child_keys($plan) !== $child_keys) {
			self::reject('child_key_set_mismatch', __('The Split journal child-key set does not match its plan.', 'wc-order-splitter'));
		}
		return $plan;
	}

	private static function source_snapshot(array $context, $source_id) {
		if (empty($context['source_snapshot']) || !is_array($context['source_snapshot'])) {
			self::reject('source_snapshot_missing', __('The Split journal is missing its pre-Split source snapshot.', 'wc-order-splitter'));
		}
		$snapshot = $context['source_snapshot'];
		try {
			WCOS_Order_Mutation_Snapshot::assert_valid($snapshot);
		} catch (Throwable $throwable) {
			self::reject('source_snapshot_invalid', __('The pre-Split source snapshot failed integrity verification.', 'wc-order-splitter'));
		}
		if (absint(isset($snapshot['order_id']) ? $snapshot['order_id'] : 0) !== $source_id
			|| empty($snapshot['line_items']) || !is_array($snapshot['line_items'])) {
			self::reject('source_snapshot_mismatch', __('The pre-Split snapshot does not match this source order.', 'wc-order-splitter'));
		}
		$stored = self::fingerprint_value(isset($context['source_snapshot_fingerprint']) ? $context['source_snapshot_fingerprint'] : '', 'source_snapshot_fingerprint');
		if (!hash_equals($stored, (string) $snapshot['recovery_fingerprint'])) {
			self::reject('source_snapshot_fingerprint_mismatch', __('The Split snapshot pointer does not match its integrity fingerprint.', 'wc-order-splitter'));
		}
		if (!empty($context['source_signature']) && !hash_equals((string) $context['source_signature'], (string) $snapshot['source_signature'])) {
			self::reject('source_snapshot_signature_mismatch', __('The Split source signature does not match its recovery snapshot.', 'wc-order-splitter'));
		}
		return $snapshot;
	}

	private static function execution_policy(array $context) {
		try {
			return WCOS_Split_Execution_Policy::normalize(isset($context['execution_policy']) ? $context['execution_policy'] : '');
		} catch (Throwable $throwable) {
			self::reject('execution_policy_invalid', __('The Split journal execution policy is invalid.', 'wc-order-splitter'));
		}
	}

	private static function assert_strategy_authority(array $context, $execution_policy, array $snapshot) {
		$has_strategy = isset($context['strategy_authority']) && is_array($context['strategy_authority']);
		if (WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY === $execution_policy) {
			if ($has_strategy) {
				self::reject('strategy_policy_mismatch', __('A manual partial Split unexpectedly carries strategy authority.', 'wc-order-splitter'));
			}
			return 'manual_quantity';
		}
		if (!$has_strategy) {
			self::reject('strategy_authority_missing', __('Whole-line Return provenance requires durable Split strategy authority.', 'wc-order-splitter'));
		}
		$authority = $context['strategy_authority'];
		$strategy = sanitize_key(isset($authority['strategy']) ? (string) $authority['strategy'] : '');
		if (!in_array($strategy, array(WCOS_Split_Strategy_Gates::CATEGORY, WCOS_Split_Strategy_Gates::STOCK_STATUS), true)) {
			self::reject('strategy_authority_invalid', __('The Split strategy identity is unsupported.', 'wc-order-splitter'));
		}
		$expected_policy = WCOS_Split_Strategy_Gates::CATEGORY === $strategy
			? WCOS_Category_Split_Planner::POLICY_VERSION
			: WCOS_Stock_Status_Split_Planner::POLICY_VERSION;
		if (absint(isset($authority['planner_policy_version']) ? $authority['planner_policy_version'] : 0) !== $expected_policy
			|| empty($authority['classification_fingerprint']) || empty($authority['source_bucket_key'])
			|| empty($authority['review_source_signature'])
			|| !hash_equals((string) $authority['review_source_signature'], (string) $snapshot['source_signature'])) {
			self::reject('strategy_authority_invalid', __('The durable Split strategy authority is incomplete or inconsistent.', 'wc-order-splitter'));
		}
		return $strategy;
	}

	private static function assert_split_fingerprint(array $record, array $plan, $execution_policy, array $context) {
		$fingerprint_context = array(
			'policy_version' => WCOS_Split_Order_Service::POLICY_VERSION,
			'plan' => $plan,
			'shipping_policy' => 'keep_on_source',
			'fee_policy' => 'keep_on_source',
			'child_status' => 'pending',
			'execution_policy' => $execution_policy,
		);
		if (isset($context['strategy_authority'])) {
			$fingerprint_context['strategy_authority'] = $context['strategy_authority'];
		}
		$expected = WCOS_Mutation_Fingerprint::create('split', absint($record['source_order_id']), $fingerprint_context);
		$stored = self::fingerprint_value(isset($record['fingerprint']) ? $record['fingerprint'] : '', 'split_operation_fingerprint');
		if (!hash_equals($stored, $expected)) {
			self::reject('split_fingerprint_mismatch', __('The Split journal request fingerprint does not match its durable plan and policy.', 'wc-order-splitter'));
		}
	}

	private static function assert_target_set(WC_Order $source, array $context, array $plan, $source_id, $operation_id, $child_id, $child_key) {
		$target_ids = self::canonical_id_list(isset($context['target_order_ids']) ? $context['target_order_ids'] : array(), 'target_order_ids');
		if (count($target_ids) !== count($plan) || 1 !== count(array_keys($target_ids, $child_id, true))) {
			self::reject('journal_target_set_mismatch', __('The completed Split target set does not contain this child exactly once.', 'wc-order-splitter'));
		}
		$relation_ids = self::canonical_id_list($source->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true), 'source_child_relations');
		if ($relation_ids !== $target_ids) {
			self::reject('source_relation_target_mismatch', __('The original order child relation set does not match the completed Split target set.', 'wc-order-splitter'));
		}
		$seen_keys = array();
		foreach ($target_ids as $target_id) {
			$target = wc_get_order($target_id);
			if (!$target instanceof WC_Order || 'shop_order' !== $target->get_type()) {
				self::reject('journal_target_missing', __('A completed Split target is unavailable.', 'wc-order-splitter'));
			}
			$key = self::canonical_key_scalar($target->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true), 'target_child_key');
			if (isset($seen_keys[$key]) || !isset($plan[$key])
				|| self::positive_int_scalar($target->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true), 'target_parent') !== $source_id
				|| self::canonical_key_scalar($target->get_meta(WCOS_Split_Order_Service::OPERATION_META, true), 'target_operation') !== $operation_id) {
				self::reject('journal_target_provenance_mismatch', __('The completed Split target set has conflicting child provenance.', 'wc-order-splitter'));
			}
			$seen_keys[$key] = true;
			if ($target_id === $child_id && $key !== $child_key) {
				self::reject('child_key_mismatch', __('The current child key does not match its target-set authority.', 'wc-order-splitter'));
			}
		}
		$keys = array_keys($seen_keys);
		sort($keys, SORT_STRING);
		if ($keys !== array_keys($plan)) {
			self::reject('journal_target_key_set_mismatch', __('The target child-key set does not match the durable Split plan.', 'wc-order-splitter'));
		}
	}

	private static function prove_child_lines(WC_Order $source, WC_Order $child, array $snapshot, array $plan, $child_key, array $fully_moved, $precision) {
		$expected = self::expected_allocations($snapshot, $plan, $child_key, $precision);
		$actual = array();
		foreach ($child->get_items('line_item') as $child_item_id => $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				self::reject('child_line_type_invalid', __('A Return child contains an unsupported product-line type.', 'wc-order-splitter'));
			}
			try {
				$source_item_id = self::positive_int_scalar($item->get_meta('_wcos_source_item_id', true), 'source_item_id');
			} catch (WCOS_Return_Lineage_Exception $exception) {
				self::reject('child_line_provenance_invalid', __('A child line has missing or malformed Split source-item provenance.', 'wc-order-splitter'));
			}
			if (isset($actual[$source_item_id]) || !isset($expected[$source_item_id])) {
				self::reject('child_line_provenance_mismatch', __('Child line provenance is duplicated or absent from the durable Split plan.', 'wc-order-splitter'));
			}
			self::assert_line_matches($item, $expected[$source_item_id], $precision, 'child');
			$line = $expected[$source_item_id];
			$line['source_item_id'] = $source_item_id;
			$line['child_item_id'] = absint($child_item_id);
			$line['product_id'] = absint($item->get_product_id());
			$line['variation_id'] = absint($item->get_variation_id());
			$line['tax_class'] = (string) $item->get_tax_class();
			$line['destination'] = in_array($source_item_id, $fully_moved, true)
				? WCOS_Return_Plan::DESTINATION_FRESH_SOURCE_ITEM
				: WCOS_Return_Plan::DESTINATION_RESIDUAL_SOURCE_ITEM;
			$line['destination_source_item_id'] = in_array($source_item_id, $fully_moved, true) ? 0 : $source_item_id;
			$actual[$source_item_id] = $line;
		}
		ksort($actual, SORT_NUMERIC);
		if (array_keys($actual) !== array_keys($expected)) {
			self::reject('child_line_set_mismatch', __('The child line set does not exactly match its durable Split allocation.', 'wc-order-splitter'));
		}

		foreach ($actual as $source_item_id => $line) {
			$source_item = $source->get_item($source_item_id);
			if (in_array($source_item_id, $fully_moved, true)) {
				if ($source_item instanceof WC_Order_Item_Product) {
					self::reject('whole_line_source_drift', __('A fully moved Split source line unexpectedly exists on the original order.', 'wc-order-splitter'));
				}
				continue;
			}
			if (!$source_item instanceof WC_Order_Item_Product || empty($line['expected_source'])) {
				self::reject('residual_source_line_missing', __('A partial Split residual source line is missing.', 'wc-order-splitter'));
			}
			self::assert_line_matches($source_item, $line['expected_source'], $precision, 'source');
			unset($actual[$source_item_id]['expected_source']);
		}
		foreach ($actual as $source_item_id => $line) {
			unset($actual[$source_item_id]['expected_source']);
			$actual[$source_item_id]['line_identity_authority'] = self::sealed_fingerprint('line_identity', $line['line_identity']);
			unset($actual[$source_item_id]['line_identity']);
		}
		return $actual;
	}

	private static function expected_allocations(array $snapshot, array $plan, $child_key, $precision) {
		$expected = array();
		foreach ($plan[$child_key] as $source_item_id => $child_quantity) {
			$source_item_id = absint($source_item_id);
			if (!isset($snapshot['line_items'][$source_item_id]) || !is_array($snapshot['line_items'][$source_item_id])) {
				self::reject('source_item_snapshot_missing', __('The Split plan references a source item absent from its pre-Split snapshot.', 'wc-order-splitter'));
			}
			$before = $snapshot['line_items'][$source_item_id];
			$before_units = WCOS_Decimal::to_units($before['quantity'], 6);
			$allocated_units = 0;
			$weights = array();
			foreach ($plan as $key => $items) {
				if (!isset($items[$source_item_id])) {
					continue;
				}
				$units = WCOS_Decimal::to_units($items[$source_item_id], 6);
				$allocated_units += $units;
				$weights[$key] = WCOS_Decimal::from_units($units, 6);
			}
			$residual_units = $before_units - $allocated_units;
			if ($residual_units < 0) {
				self::reject('split_allocation_invalid', __('The durable Split plan over-allocates a source line.', 'wc-order-splitter'));
			}
			if ($residual_units > 0) {
				$weights = array_merge(array('source' => WCOS_Decimal::from_units($residual_units, 6)), $weights);
			}

			$parts = array();
			foreach (array('subtotal', 'total', 'subtotal_tax', 'total_tax') as $field) {
				$allocated = WCOS_Amount_Allocator::allocate((string) $before[$field], $weights, $precision);
				$parts[$field] = $allocated[$child_key];
				if ($residual_units > 0) {
					$parts['source_' . $field] = $allocated['source'];
				}
			}
			$taxes = self::allocate_taxes(isset($before['taxes']) ? (array) $before['taxes'] : array(), $weights, $child_key, $precision);
			$source_taxes = $residual_units > 0
				? self::allocate_taxes(isset($before['taxes']) ? (array) $before['taxes'] : array(), $weights, 'source', $precision)
				: array('subtotal' => array(), 'total' => array());
			$reduced = null;
			$source_reduced = null;
			if (isset($before['reduced_stock']) && null !== $before['reduced_stock']) {
				$reduced_parts = WCOS_Amount_Allocator::allocate((string) $before['reduced_stock'], $weights, 6);
				$reduced = self::nullable_decimal($reduced_parts[$child_key], 6);
				$source_reduced = $residual_units > 0 ? self::nullable_decimal($reduced_parts['source'], 6) : null;
			}

			$expected[$source_item_id] = array(
				'line_identity' => self::fingerprint_value(isset($before['identity']) ? $before['identity'] : '', 'line_identity'),
				'quantity' => WCOS_Decimal::normalize($child_quantity, 6),
				'subtotal' => $parts['subtotal'],
				'total' => $parts['total'],
				'subtotal_tax' => $parts['subtotal_tax'],
				'total_tax' => $parts['total_tax'],
				'taxes' => $taxes,
				'reduced_stock' => $reduced,
				'expected_source' => $residual_units > 0 ? array(
					'line_identity' => self::fingerprint_value(isset($before['identity']) ? $before['identity'] : '', 'line_identity'),
					'quantity' => WCOS_Decimal::from_units($residual_units, 6),
					'subtotal' => $parts['source_subtotal'],
					'total' => $parts['source_total'],
					'subtotal_tax' => $parts['source_subtotal_tax'],
					'total_tax' => $parts['source_total_tax'],
					'taxes' => $source_taxes,
					'reduced_stock' => $source_reduced,
				) : null,
			);
		}
		ksort($expected, SORT_NUMERIC);
		return $expected;
	}

	private static function allocate_taxes(array $taxes, array $weights, $destination, $precision) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			foreach (isset($taxes[$bucket]) ? (array) $taxes[$bucket] : array() as $rate_id => $amount) {
				$parts = WCOS_Amount_Allocator::allocate((string) $amount, $weights, $precision);
				$result[$bucket][(int) $rate_id] = $parts[$destination];
			}
			ksort($result[$bucket], SORT_NUMERIC);
		}
		return $result;
	}

	private static function assert_line_matches(WC_Order_Item_Product $item, array $expected, $precision, $role) {
		try {
			$identity = WCOS_Line_Identity::from_item($item);
			$unknown = WCOS_Order_Item_Meta_Policy::unknown_private_keys($item, WCOS_Order_Item_Meta_Policy::CONTEXT_RETURN);
			$inconsistent = WCOS_Order_Item_Meta_Policy::inconsistent_private_keys($item, WCOS_Order_Item_Meta_Policy::CONTEXT_RETURN);
		} catch (Throwable $throwable) {
			self::reject($role . '_line_identity_invalid', __('A Return participant line has non-canonical business metadata.', 'wc-order-splitter'));
		}
		if (!empty($unknown) || !empty($inconsistent)) {
			self::reject($role . '_line_private_meta_unsupported', __('A Return participant line contains unclassified private metadata.', 'wc-order-splitter'));
		}
		$actual = array(
			'line_identity' => $identity,
			'quantity' => WCOS_Decimal::normalize($item->get_quantity(), 6),
			'subtotal' => WCOS_Decimal::normalize($item->get_subtotal(), $precision),
			'total' => WCOS_Decimal::normalize($item->get_total(), $precision),
			'subtotal_tax' => WCOS_Decimal::normalize($item->get_subtotal_tax(), $precision),
			'total_tax' => WCOS_Decimal::normalize($item->get_total_tax(), $precision),
			'taxes' => self::normalize_taxes($item->get_taxes(), $precision),
			'reduced_stock' => self::nullable_decimal($item->get_meta('_reduced_stock', true), 6),
		);
		$compare = $expected;
		unset($compare['expected_source']);
		if ($actual !== $compare) {
			self::reject($role . '_line_drift', __('A Return participant line no longer matches its durable Split allocation.', 'wc-order-splitter'));
		}
	}

	private static function normalize_taxes(array $taxes, $precision) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			foreach (isset($taxes[$bucket]) ? (array) $taxes[$bucket] : array() as $rate_id => $amount) {
				$result[$bucket][(int) $rate_id] = WCOS_Decimal::normalize($amount, $precision);
			}
			ksort($result[$bucket], SORT_NUMERIC);
		}
		return $result;
	}

	private static function derive_fully_moved_ids(array $snapshot, array $plan) {
		$allocated = array();
		foreach ($plan as $items) {
			foreach ($items as $source_item_id => $quantity) {
				$source_item_id = absint($source_item_id);
				$units = WCOS_Decimal::to_units($quantity, 6);
				$allocated[$source_item_id] = isset($allocated[$source_item_id]) ? $allocated[$source_item_id] + $units : $units;
			}
		}
		$fully = array();
		foreach ($allocated as $source_item_id => $units) {
			if (!isset($snapshot['line_items'][$source_item_id])) {
				self::reject('source_item_snapshot_missing', __('A Split allocation references an unknown pre-Split source item.', 'wc-order-splitter'));
			}
			$before = WCOS_Decimal::to_units($snapshot['line_items'][$source_item_id]['quantity'], 6);
			if ($units > $before) {
				self::reject('split_allocation_invalid', __('The Split allocation exceeds its source quantity.', 'wc-order-splitter'));
			}
			if ($units === $before) {
				$fully[] = $source_item_id;
			}
		}
		sort($fully, SORT_NUMERIC);
		return $fully;
	}

	private static function assert_structured_relation(WC_Order $source, $child_id) {
		$ids = self::canonical_id_list($source->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true), 'source_child_relations');
		if (1 !== count(array_keys($ids, $child_id, true))) {
			self::reject('source_relation_mismatch', __('The original order does not contain this child exactly once in its hardened relation graph.', 'wc-order-splitter'));
		}
	}

	private static function canonical_id_list($value, $field) {
		if (!is_array($value)) {
			self::reject($field . '_malformed', __('A hardened Return authority ID list is malformed.', 'wc-order-splitter'));
		}
		$ids = array();
		foreach ($value as $item) {
			$id = self::positive_int_scalar($item, $field);
			if (isset($ids[$id])) {
				self::reject($field . '_duplicate', __('A hardened Return authority ID list contains duplicates.', 'wc-order-splitter'));
			}
			$ids[$id] = true;
		}
		$result = array_keys($ids);
		sort($result, SORT_NUMERIC);
		return $result;
	}

	private static function legacy_id_list($value) {
		if ('' === $value || null === $value) {
			return array();
		}
		if (!is_string($value) || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $value)) {
			self::reject('legacy_relation_malformed', __('Legacy Split relation metadata is malformed.', 'wc-order-splitter'));
		}
		$ids = array_map('intval', explode(',', $value));
		if (count($ids) !== count(array_unique($ids))) {
			self::reject('legacy_relation_duplicate', __('Legacy Split relation metadata contains duplicate IDs.', 'wc-order-splitter'));
		}
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private static function positive_int_scalar($value, $field) {
		if (is_int($value) && $value > 0) {
			return $value;
		}
		if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
			return (int) $value;
		}
		self::reject($field . '_malformed', __('A hardened Return authority order/item ID is malformed.', 'wc-order-splitter'));
	}

	private static function canonical_key_scalar($value, $field) {
		if (!is_string($value) || '' === $value || sanitize_key($value) !== $value) {
			self::reject($field . '_malformed', __('A hardened Return authority key is malformed.', 'wc-order-splitter'));
		}
		return $value;
	}

	private static function fingerprint_value($value, $field) {
		$value = is_string($value) ? $value : '';
		if (1 !== preg_match('/^[0-9a-f]{64}$/D', $value)) {
			self::reject($field . '_malformed', __('A hardened Return authority fingerprint is malformed.', 'wc-order-splitter'));
		}
		return $value;
	}

	private static function sealed_fingerprint($domain, $value) {
		$value = self::fingerprint_value($value, $domain);
		return hash_hmac('sha256', 'wcos_return_' . sanitize_key($domain) . '|' . $value, wp_salt('auth'));
	}

	private static function nullable_decimal($value, $precision) {
		if ('' === $value || null === $value) {
			return null;
		}
		$normalized = WCOS_Decimal::normalize($value, $precision);
		return 0 === WCOS_Decimal::to_units($normalized, $precision) ? null : $normalized;
	}

	private static function canonicalize($value) {
		if (!is_array($value)) {
			return $value;
		}
		$is_list = true;
		$expected = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expected++) {
				$is_list = false;
				break;
			}
		}
		if (!$is_list) {
			ksort($value, SORT_STRING);
		}
		foreach ($value as $key => $item) {
			$value[$key] = self::canonicalize($item);
		}
		return $value;
	}

	private static function reject($reason, $message) {
		throw new WCOS_Return_Lineage_Exception($reason, $message);
	}
}
