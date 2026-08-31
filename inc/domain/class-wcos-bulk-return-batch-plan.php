<?php

defined('ABSPATH') || exit;

final class WCOS_Bulk_Return_Batch_Exception extends RuntimeException {
	private $reason;

	public function __construct($reason, $message) {
		$this->reason = sanitize_key((string) $reason);
		parent::__construct((string) $message);
	}

	public function get_reason() { return $this->reason; }
}

/** Immutable, PII-free Review plan for at most twenty selected orders. */
final class WCOS_Bulk_Return_Batch_Plan {
	const SCHEMA_VERSION = 2;
	const POLICY_VERSION = 2;
	const LEGACY_SCHEMA_VERSION = 1;
	const LEGACY_POLICY_VERSION = 1;
	const MAX_CHILDREN = 20;

	public static function build(array $candidate_ids) {
		$normalized = self::normalize_ids($candidate_ids);
		$classified = array();
		foreach ($normalized['canonical_ids'] as $child_id) {
			$classified[] = self::classify_candidate($child_id);
		}

		usort($classified, array(__CLASS__, 'compare_rows'));
		$selection_rows = array();
		$execution_rows = array();
		$skipped_rows = array();
		foreach ($classified as $selection_ordinal => $row) {
			$selection_rows[] = self::disclosure_row($row, $selection_ordinal);
			if (!empty($row['eligible'])) {
				$row['ordinal'] = count($execution_rows);
				$row['selection_ordinal'] = (int) $selection_ordinal;
				$row['classification'] = 'eligible';
				$execution_rows[] = $row;
			} else {
				$skipped_rows[] = array(
					'selection_ordinal' => (int) $selection_ordinal,
					'child_order_id' => absint($row['child_order_id']),
					'reason' => sanitize_key((string) $row['reason']),
				);
			}
		}

		self::reject_ambiguous_graphs($execution_rows);
		self::bind_predecessors($execution_rows);
		$eligible_count = count($execution_rows);
		$skipped_count = count($skipped_rows);
		$plan = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'max_children' => self::MAX_CHILDREN,
			'atomicity' => 'per_child',
			'failure_policy' => 'fail_stop_after_first_non_success',
			'execution_policy' => 'one_eligible_child_per_request',
			'deadline_policy' => 'start_next_execution_row_30_minutes',
			'classification_policy' => 'server_review_eligible_or_skipped_v2',
			'selected_count' => $normalized['selected_count'],
			'canonical_count' => count($selection_rows),
			'duplicate_count' => $normalized['duplicate_count'],
			'eligible_count' => $eligible_count,
			'skipped_count' => $skipped_count,
			'has_eligible' => $eligible_count > 0,
			'all_eligible' => $eligible_count > 0 && 0 === $skipped_count,
			'canonical_child_ids' => array_values(array_map(static function($row) { return absint($row['child_order_id']); }, $selection_rows)),
			'eligible_child_ids' => array_values(array_map(static function($row) { return absint($row['child_order_id']); }, $execution_rows)),
			'skipped_child_ids' => array_values(array_map(static function($row) { return absint($row['child_order_id']); }, $skipped_rows)),
			'skipped_rows' => $skipped_rows,
			'selection_rows' => $selection_rows,
			'execution_rows' => $execution_rows,
		);
		$plan['batch_fingerprint'] = self::fingerprint($plan);
		return $plan;
	}

	public static function assert_review_current(array $plan) {
		self::assert_valid($plan);
		if (!self::is_v2($plan)) {
			return self::assert_legacy_review_current($plan);
		}
		if (empty($plan['has_eligible'])) {
			throw new WCOS_Bulk_Return_Batch_Exception('nothing_eligible', __('No reviewed row is eligible for Bulk Return. Review remains available, but no durable batch can be created.', 'wc-order-splitter'));
		}

		$execution_by_selection = array();
		foreach ($plan['execution_rows'] as $row) {
			$execution_by_selection[(int) $row['selection_ordinal']] = $row;
		}
		foreach ($plan['selection_rows'] as $selection_ordinal => $selection_row) {
			if (!empty($selection_row['eligible'])) {
				if (!isset($execution_by_selection[$selection_ordinal])) {
					throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('The reviewed execution set is incomplete.', 'wc-order-splitter'));
				}
				$row = $execution_by_selection[$selection_ordinal];
				$child = wc_get_order(absint($row['child_order_id']));
				if (!$child instanceof WC_Order || 'shop_order' !== $child->get_type()) {
					throw new WCOS_Bulk_Return_Batch_Exception('classification_changed', __('A reviewed Eligible row changed before Confirm. Review the selection again.', 'wc-order-splitter'));
				}
				try {
					WCOS_Return_Review_Store::assert_matches_current($child, $row['batch_child_intent']['return_authority']);
					$original = wc_get_order(absint($row['original_order_id']));
					if (!$original instanceof WC_Order) { throw new RuntimeException(__('The reviewed Bulk Return original is unavailable.', 'wc-order-splitter')); }
					WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::BULK_RETURN, $child, $original);
				} catch (Throwable $throwable) {
					throw new WCOS_Bulk_Return_Batch_Exception('classification_changed', __('A reviewed Eligible row or operator authority changed before Confirm. Review the selection again.', 'wc-order-splitter'));
				}
				continue;
			}

			$current = self::classify_candidate(absint($selection_row['child_order_id']));
			if (!empty($current['eligible'])
				|| sanitize_key((string) $current['reason']) !== sanitize_key((string) $selection_row['reason'])
				|| !hash_equals((string) $selection_row['classification_fingerprint'], (string) $current['classification_fingerprint'])) {
				throw new WCOS_Bulk_Return_Batch_Exception('classification_changed', __('A reviewed Skipped row changed before Confirm. Review the selection again.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	/** Exact ordinary Return authority for the current execution row after expected siblings. */
	public static function derive_current_authority(array $plan, $ordinal, array $operation_map) {
		self::assert_valid($plan);
		$ordinal = absint($ordinal);
		$rows = self::execution_rows($plan);
		if (!isset($rows[$ordinal]) || empty($rows[$ordinal]['eligible'])) {
			throw new WCOS_Bulk_Return_Batch_Exception('invalid_cursor', __('The Bulk Return cursor does not identify an Eligible execution row.', 'wc-order-splitter'));
		}
		$row = $rows[$ordinal];
		$intent = $row['batch_child_intent'];
		$child = wc_get_order(absint($row['child_order_id']));
		if (!$child instanceof WC_Order) {
			throw new WCOS_Bulk_Return_Batch_Exception('participant_missing', __('The current Bulk Return child is unavailable.', 'wc-order-splitter'));
		}
		$report = (new WCOS_Return_WooCommerce_Adapter())->preflight($child);
		if (empty($report['supported'])) {
			throw new WCOS_Bulk_Return_Batch_Exception('authority_changed', __('The current Bulk Return child no longer passes hardened Return preflight.', 'wc-order-splitter'));
		}
		$current = WCOS_Return_Review_Store::authority_from_preflight($child, $report);
		self::assert_constrained_derivation($intent, $current, $plan, $operation_map);
		return $current;
	}

	public static function assert_ordinary_authority_current(WC_Order $child, array $authority) {
		$report = (new WCOS_Return_WooCommerce_Adapter())->preflight($child);
		if (empty($report['supported'])) {
			throw new WCOS_Bulk_Return_Batch_Exception('authority_changed', __('The current child is no longer eligible for Return.', 'wc-order-splitter'));
		}
		$current = WCOS_Return_Review_Store::authority_from_preflight($child, $report);
		if ($current !== $authority) {
			throw new WCOS_Bulk_Return_Batch_Exception('authority_changed', __('The current Return authority differs from the constrained batch derivation.', 'wc-order-splitter'));
		}
		return true;
	}

	public static function assert_valid(array $plan) {
		$schema = (int) (isset($plan['schema_version']) ? $plan['schema_version'] : 0);
		if (self::LEGACY_SCHEMA_VERSION === $schema) { return self::assert_valid_v1($plan); }
		if (self::SCHEMA_VERSION !== $schema) {
			throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Bulk Return batch authority uses an unsupported schema.', 'wc-order-splitter'));
		}
		return self::assert_valid_v2($plan);
	}

	public static function fingerprint(array $plan) {
		$copy = $plan;
		unset($copy['batch_fingerprint']);
		$schema = (int) (isset($copy['schema_version']) ? $copy['schema_version'] : 0);
		$canonical = isset($copy['canonical_child_ids']) && is_array($copy['canonical_child_ids']) ? $copy['canonical_child_ids'] : array();
		$anchor = !empty($canonical) ? absint($canonical[0]) : 0;
		$namespace = self::LEGACY_SCHEMA_VERSION === $schema ? 'bulk_return_batch_plan_v1' : 'bulk_return_batch_plan_v2';
		return WCOS_Mutation_Fingerprint::create($namespace, $anchor, self::canonicalize($copy));
	}

	public static function is_v2(array $plan) {
		return self::SCHEMA_VERSION === (int) (isset($plan['schema_version']) ? $plan['schema_version'] : 0);
	}

	public static function selection_rows(array $plan) {
		self::assert_valid($plan);
		return self::is_v2($plan) ? $plan['selection_rows'] : $plan['rows'];
	}

	public static function execution_rows(array $plan) {
		self::assert_valid($plan);
		return self::is_v2($plan) ? $plan['execution_rows'] : $plan['rows'];
	}

	public static function execution_count(array $plan) { return count(self::execution_rows($plan)); }

	public static function anchor_child_id(array $plan) {
		$rows = self::execution_rows($plan);
		return empty($rows) ? 0 : absint($rows[0]['child_order_id']);
	}

	private static function classify_candidate($child_id) {
		$child_id = absint($child_id);
		$child = $child_id ? wc_get_order($child_id) : false;
		$row = self::skipped_candidate($child_id, 'participant_not_found', __('The selected order is not a persisted WooCommerce order.', 'wc-order-splitter'));
		$report = array('supported' => false, 'reason' => 'participant_not_found', 'source_order_id' => 0);
		if (!$child instanceof WC_Order || 'shop_order' !== $child->get_type()) {
			$row['classification_fingerprint'] = self::classification_fingerprint($child_id, $row['reason'], $child, $report);
			return $row;
		}

		try {
			$report = (new WCOS_Return_WooCommerce_Adapter())->preflight($child);
		} catch (Throwable $throwable) {
			throw new WCOS_Bulk_Return_Batch_Exception('review_integrity_failed', __('Bulk Return could not construct a trustworthy row classification.', 'wc-order-splitter'));
		}
		if (empty($report['supported'])) {
			$reason = 'preflight_' . sanitize_key(isset($report['reason']) ? (string) $report['reason'] : 'unsupported');
			$message = isset($report['message']) ? (string) $report['message'] : __('This row is not eligible for hardened Return.', 'wc-order-splitter');
			if ('preflight_authorization_failed' === $reason) { $message = __('This selected order is not available for Bulk Return.', 'wc-order-splitter'); }
			$row = self::skipped_candidate($child_id, $reason, $message);
			$row['classification_fingerprint'] = self::classification_fingerprint($child_id, $reason, $child, $report);
			return $row;
		}

		try {
			$authority = WCOS_Return_Review_Store::authority_from_preflight($child, $report);
			$row = self::eligible_row($child, $authority);
		} catch (WCOS_Bulk_Return_Batch_Exception $exception) {
			$reason = $exception->get_reason();
			$message = 'authorization_failed' === $reason ? __('This selected order is not available for Bulk Return.', 'wc-order-splitter') : $exception->getMessage();
			$row = self::skipped_candidate($child_id, $reason, $message);
		} catch (Throwable $throwable) {
			throw new WCOS_Bulk_Return_Batch_Exception('review_integrity_failed', __('Bulk Return could not freeze exact ordinary Return authority for one row.', 'wc-order-splitter'));
		}
		$row['classification_fingerprint'] = self::classification_fingerprint($child_id, $row['reason'], $child, $report);
		return $row;
	}

	private static function skipped_candidate($child_id, $reason, $message) {
		return array(
			'child_order_id' => absint($child_id),
			'original_order_id' => 0,
			'split_operation_id' => '',
			'split_child_key' => '',
			'classification' => 'skipped',
			'eligible' => false,
			'reason' => sanitize_key((string) $reason),
			'message' => (string) $message,
			'classification_fingerprint' => '',
			'batch_child_intent' => array(),
			'summary' => array('child' => array('id' => absint($child_id), 'number' => (string) absint($child_id))),
		);
	}

	private static function disclosure_row(array $row, $selection_ordinal) {
		return array(
			'selection_ordinal' => (int) $selection_ordinal,
			'child_order_id' => absint($row['child_order_id']),
			'classification' => empty($row['eligible']) ? 'skipped' : 'eligible',
			'eligible' => !empty($row['eligible']),
			'reason' => sanitize_key((string) $row['reason']),
			'message' => (string) $row['message'],
			'classification_fingerprint' => self::hex($row['classification_fingerprint']),
			'summary' => isset($row['summary']) && is_array($row['summary']) ? $row['summary'] : array(),
		);
	}

	private static function classification_fingerprint($child_id, $reason, $child, array $report) {
		$state = array(
			'schema_version' => self::SCHEMA_VERSION,
			'child_order_id' => absint($child_id),
			'child_exists' => $child instanceof WC_Order,
			'child_type' => $child instanceof WC_Order ? sanitize_key((string) $child->get_type()) : '',
			'reason' => sanitize_key((string) $reason),
			'report_reason' => sanitize_key(isset($report['reason']) ? (string) $report['reason'] : ''),
			'source_order_id' => absint(isset($report['source_order_id']) ? $report['source_order_id'] : 0),
			'child_signature' => '',
			'child_mutation_meta_signature' => '',
			'source_signature' => '',
			'source_mutation_meta_signature' => '',
		);
		if ($child instanceof WC_Order) {
			$state['child_signature'] = WCOS_Order_Contract_Snapshot::source_signature($child);
			$state['child_mutation_meta_signature'] = self::mutation_meta_signature($child);
		}
		$source = !empty($state['source_order_id']) ? wc_get_order($state['source_order_id']) : false;
		if ($source instanceof WC_Order) {
			$state['source_signature'] = WCOS_Order_Contract_Snapshot::source_signature($source);
			$state['source_mutation_meta_signature'] = self::mutation_meta_signature($source);
		}
		return WCOS_Mutation_Fingerprint::create('bulk_return_review_classification_v2', absint($child_id), self::canonicalize($state));
	}

	private static function mutation_meta_signature(WC_Order $order) {
		$values = array();
		foreach ($order->get_meta_data() as $meta) {
			$data = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : array();
			$key = isset($data['key']) ? (string) $data['key'] : '';
			if (0 !== strpos($key, '_wcos_') && !in_array($key, array('yoos_original_order', 'yoos_splitted_order'), true)) { continue; }
			$values[] = array('key' => $key, 'value' => array_key_exists('value', $data) ? $data['value'] : null);
		}
		return WCOS_Mutation_Fingerprint::create('bulk_return_mutation_meta_v2', $order->get_id(), self::canonicalize($values));
	}

	private static function normalize_ids(array $candidate_ids) {
		if (empty($candidate_ids)) { throw new WCOS_Bulk_Return_Batch_Exception('invalid_selection', __('Select between one and twenty canonical Return children.', 'wc-order-splitter')); }
		if (count($candidate_ids) > self::MAX_CHILDREN) { throw new WCOS_Bulk_Return_Batch_Exception('batch_too_large', __('Bulk Return supports at most twenty selected children.', 'wc-order-splitter')); }
		$ids = array();
		foreach ($candidate_ids as $raw) {
			if (is_array($raw) || is_object($raw) || is_bool($raw) || null === $raw) { throw new WCOS_Bulk_Return_Batch_Exception('invalid_selection', __('Bulk Return order IDs must be positive decimal scalars.', 'wc-order-splitter')); }
			$value = (string) $raw;
			if (1 !== preg_match('/^[1-9][0-9]*$/D', $value) || strlen($value) > strlen((string) PHP_INT_MAX) || (string) (int) $value !== $value) { throw new WCOS_Bulk_Return_Batch_Exception('invalid_selection', __('Bulk Return order IDs must be positive decimal scalars.', 'wc-order-splitter')); }
			$id = (int) $value;
			if ($id <= 0) { throw new WCOS_Bulk_Return_Batch_Exception('invalid_selection', __('Bulk Return order IDs are outside the supported range.', 'wc-order-splitter')); }
			$ids[] = $id;
		}
		$canonical = array_values(array_unique($ids));
		if (count($canonical) > self::MAX_CHILDREN) { throw new WCOS_Bulk_Return_Batch_Exception('batch_too_large', __('Bulk Return supports at most twenty canonical children.', 'wc-order-splitter')); }
		return array('canonical_ids' => $canonical, 'selected_count' => count($ids), 'duplicate_count' => count($ids) - count($canonical));
	}

	private static function eligible_row(WC_Order $child, array $authority) {
		$original = wc_get_order(absint($authority['original_order_id']));
		if (!$original instanceof WC_Order) { throw new WCOS_Bulk_Return_Batch_Exception('participant_missing', __('The server-resolved Return original is unavailable.', 'wc-order-splitter')); }
		try { WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::BULK_RETURN, $child, $original); }
		catch (Throwable $throwable) { throw new WCOS_Bulk_Return_Batch_Exception('authorization_failed', __('This selected order is not available for Bulk Return.', 'wc-order-splitter')); }
		$plan = $authority['plan'];
		$quantity_units = 0; $subtotal_units = 0; $total_units = 0; $tax_units = 0;
		$precision = (int) $authority['price_precision'];
		foreach ($plan['lines'] as $line) {
			$quantity_units = self::add_units($quantity_units, WCOS_Decimal::to_units($line['quantity'], 6));
			$subtotal_units = self::add_units($subtotal_units, WCOS_Decimal::to_units($line['subtotal'], $precision));
			$total_units = self::add_units($total_units, WCOS_Decimal::to_units($line['total'], $precision));
			$tax_units = self::add_units($tax_units, WCOS_Decimal::to_units($line['total_tax'], $precision));
		}
		$intent = array(
			'schema_version' => self::SCHEMA_VERSION,
			'child_order_id' => $child->get_id(),
			'original_order_id' => $original->get_id(),
			'split_operation_id' => sanitize_key((string) $authority['split_operation_id']),
			'split_child_key' => sanitize_key((string) $authority['split_child_key']),
			'return_authority' => $authority,
			'expected_predecessor_ordinals' => array(),
			'expected_predecessor_child_ids' => array(),
		);
		return array(
			'child_order_id' => $child->get_id(),
			'original_order_id' => $original->get_id(),
			'split_operation_id' => $intent['split_operation_id'],
			'split_child_key' => $intent['split_child_key'],
			'classification' => 'eligible',
			'eligible' => true,
			'reason' => 'supported',
			'message' => __('This child has immutable hardened Return authority.', 'wc-order-splitter'),
			'classification_fingerprint' => '',
			'batch_child_intent' => $intent,
			'summary' => array(
				'child' => array('id' => $child->get_id(), 'number' => (string) $child->get_order_number()),
				'original' => array('id' => $original->get_id(), 'number' => (string) $original->get_order_number()),
				'strategy' => sanitize_key((string) $plan['strategy']),
				'line_count' => count($plan['lines']),
				'quantity' => WCOS_Decimal::from_units($quantity_units, 6),
				'historical_subtotal' => WCOS_Decimal::from_units($subtotal_units, $precision),
				'historical_total' => WCOS_Decimal::from_units($total_units, $precision),
				'historical_tax' => WCOS_Decimal::from_units($tax_units, $precision),
				'currency' => (string) $authority['currency'],
			),
		);
	}

	private static function bind_predecessors(array &$rows) {
		$groups = array();
		foreach ($rows as $ordinal => &$row) {
			$key = absint($row['original_order_id']) . '|' . sanitize_key((string) $row['split_operation_id']);
			$predecessors = isset($groups[$key]) ? $groups[$key] : array();
			$row['batch_child_intent']['expected_predecessor_ordinals'] = array_keys($predecessors);
			$row['batch_child_intent']['expected_predecessor_child_ids'] = array_values($predecessors);
			$groups[$key][$ordinal] = absint($row['child_order_id']);
		}
		unset($row);
	}

	private static function reject_ambiguous_graphs(array $rows) {
		$original_operations = array(); $child_ids = array(); $original_ids = array();
		foreach ($rows as $row) {
			$original = absint($row['original_order_id']); $operation = sanitize_key((string) $row['split_operation_id']);
			$original_operations[$original][$operation] = true; $child_ids[absint($row['child_order_id'])] = true; $original_ids[$original] = true;
		}
		foreach ($original_operations as $operations) {
			if (count($operations) > 1) { throw new WCOS_Bulk_Return_Batch_Exception('ambiguous_participant_graph', __('The selected rows form an ambiguous cross-operation Return graph.', 'wc-order-splitter')); }
		}
		if (!empty(array_intersect_key($child_ids, $original_ids))) { throw new WCOS_Bulk_Return_Batch_Exception('ambiguous_participant_graph', __('The selected rows form an overlapping Return participant graph.', 'wc-order-splitter')); }
	}

	private static function assert_constrained_derivation(array $intent, array $current, array $plan, array $operation_map) {
		$frozen = $intent['return_authority'];
		foreach (array('child_order_id', 'original_order_id', 'split_operation_id', 'split_child_key', 'price_precision', 'currency', 'prices_include_tax', 'return_service_policy_version', 'preflight_policy_version', 'plan_schema_version', 'plan_policy_version', 'lineage_schema_version', 'lineage_policy_version', 'journal_context_schema_version', 'retirement_policy_schema_version', 'retirement_policy_identifier', 'stock_ownership_policy', 'order_stock_flag_policy') as $field) {
			if (!array_key_exists($field, $current) || (string) $current[$field] !== (string) $frozen[$field]) { throw new WCOS_Bulk_Return_Batch_Exception('authority_changed', __('Bulk Return immutable child or policy authority changed.', 'wc-order-splitter')); }
		}
		$frozen_plan = $frozen['plan']; $current_plan = $current['plan'];
		foreach (array('plan_fingerprint', 'lineage_authority_fingerprint', 'source_commercial_authority', 'source_relation_authority') as $field) { unset($frozen_plan[$field], $current_plan[$field]); }
		if ($frozen_plan !== $current_plan) { throw new WCOS_Bulk_Return_Batch_Exception('child_intent_changed', __('Bulk Return historical line authority changed after Review.', 'wc-order-splitter')); }
		$frozen_pair = $frozen['pair_authority']; $current_pair = $current['pair_authority'];
		if ((string) $frozen_pair['child_signature_before'] !== (string) $current_pair['child_signature_before']) { throw new WCOS_Bulk_Return_Batch_Exception('child_intent_changed', __('Bulk Return child commercial authority changed after Review.', 'wc-order-splitter')); }
		$base = $frozen_pair['source_evolution_authority']; $now = $current_pair['source_evolution_authority'];
		WCOS_Return_Source_Evolution_Authority::assert_valid($base, $intent['original_order_id'], $intent['split_operation_id']);
		WCOS_Return_Source_Evolution_Authority::assert_valid($now, $intent['original_order_id'], $intent['split_operation_id']);
		$expected_ids = array_values(array_map('absint', $intent['expected_predecessor_child_ids']));
		$expected_returned = array_values(array_unique(array_merge($base['returned_child_ids'], $expected_ids))); sort($expected_returned, SORT_NUMERIC);
		$expected_pairs = $base['completed_pair_fingerprints']; $execution_rows = self::execution_rows($plan);
		foreach ($intent['expected_predecessor_ordinals'] as $predecessor_ordinal) {
			$predecessor_ordinal = absint($predecessor_ordinal);
			if (!isset($execution_rows[$predecessor_ordinal], $operation_map[$predecessor_ordinal])) { throw new WCOS_Bulk_Return_Batch_Exception('predecessor_missing', __('Bulk Return predecessor operation authority is incomplete.', 'wc-order-splitter')); }
			$predecessor = $execution_rows[$predecessor_ordinal]; $operation_id = sanitize_key((string) $operation_map[$predecessor_ordinal]);
			$predecessor_child = wc_get_order(absint($predecessor['child_order_id']));
			$journal = $predecessor_child instanceof WC_Order ? WCOS_Operation_Journal::get($predecessor_child, $operation_id) : null;
			if (!is_array($journal) || 'completed' !== sanitize_key(isset($journal['status']) ? (string) $journal['status'] : '')) { throw new WCOS_Bulk_Return_Batch_Exception('predecessor_incomplete', __('Bulk Return predecessor is not durably completed.', 'wc-order-splitter')); }
			$result = WCOS_Return_Journal_Context::terminal_result_from_record($journal);
			if (absint($result['child_order_id']) !== absint($predecessor['child_order_id']) || absint($result['original_order_id']) !== absint($intent['original_order_id'])) { throw new WCOS_Bulk_Return_Batch_Exception('predecessor_mismatch', __('Bulk Return predecessor journal does not match the frozen sibling chain.', 'wc-order-splitter')); }
			$expected_pairs[] = sanitize_key((string) $result['pair_fingerprint']);
		}
		$expected_pairs = array_values(array_unique($expected_pairs)); sort($expected_pairs, SORT_STRING);
		if ((int) $now['sequence'] !== (int) $base['sequence'] + count($expected_ids) || $now['returned_child_ids'] !== $expected_returned || $now['completed_pair_fingerprints'] !== $expected_pairs) { throw new WCOS_Bulk_Return_Batch_Exception('unexpected_source_evolution', __('The original changed outside the exact frozen Bulk Return predecessor chain.', 'wc-order-splitter')); }
	}

	private static function assert_legacy_review_current(array $plan) {
		if (empty($plan['all_eligible'])) { throw new WCOS_Bulk_Return_Batch_Exception('batch_ineligible', __('A pre-v2 Review cannot create partial-eligibility execution authority.', 'wc-order-splitter')); }
		foreach ($plan['rows'] as $row) {
			$child = wc_get_order(absint($row['child_order_id']));
			if (!$child instanceof WC_Order || 'shop_order' !== $child->get_type()) { throw new WCOS_Bulk_Return_Batch_Exception('participant_missing', __('A reviewed Bulk Return participant is unavailable.', 'wc-order-splitter')); }
			try {
				WCOS_Return_Review_Store::assert_matches_current($child, $row['batch_child_intent']['return_authority']);
				$original = wc_get_order(absint($row['original_order_id']));
				if (!$original instanceof WC_Order) { throw new RuntimeException(__('The reviewed Bulk Return original is unavailable.', 'wc-order-splitter')); }
				WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::BULK_RETURN, $child, $original);
			} catch (Throwable $throwable) { throw new WCOS_Bulk_Return_Batch_Exception('authority_changed', __('Bulk Return authority or operator permission changed after Review. Review the selection again.', 'wc-order-splitter')); }
		}
		return true;
	}

	private static function assert_valid_v1(array $plan) {
		$stored = self::hex(isset($plan['batch_fingerprint']) ? $plan['batch_fingerprint'] : '');
		if (self::LEGACY_POLICY_VERSION !== (int) (isset($plan['policy_version']) ? $plan['policy_version'] : 0)
			|| self::MAX_CHILDREN !== (int) (isset($plan['max_children']) ? $plan['max_children'] : 0)
			|| empty($plan['rows']) || count($plan['rows']) > self::MAX_CHILDREN
			|| !isset($plan['canonical_child_ids']) || !is_array($plan['canonical_child_ids']) || count($plan['canonical_child_ids']) !== count($plan['rows'])
			|| '' === $stored || !hash_equals($stored, self::fingerprint($plan))) { throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Legacy Bulk Return batch authority failed integrity verification.', 'wc-order-splitter')); }
		foreach ($plan['rows'] as $ordinal => $row) {
			if (absint($row['child_order_id']) !== absint($plan['canonical_child_ids'][$ordinal]) || !array_key_exists('eligible', $row) || !isset($row['batch_child_intent']) || !is_array($row['batch_child_intent'])) { throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Legacy Bulk Return row authority is malformed.', 'wc-order-splitter')); }
		}
		return true;
	}

	private static function assert_valid_v2(array $plan) {
		$stored = self::hex(isset($plan['batch_fingerprint']) ? $plan['batch_fingerprint'] : '');
		$selection = isset($plan['selection_rows']) && is_array($plan['selection_rows']) ? array_values($plan['selection_rows']) : array();
		$execution = isset($plan['execution_rows']) && is_array($plan['execution_rows']) ? array_values($plan['execution_rows']) : array();
		$skipped = isset($plan['skipped_rows']) && is_array($plan['skipped_rows']) ? array_values($plan['skipped_rows']) : array();
		$plan_keys = array_keys($plan);
		$expected_plan_keys = array('all_eligible', 'atomicity', 'batch_fingerprint', 'canonical_child_ids', 'canonical_count', 'classification_policy', 'deadline_policy', 'duplicate_count', 'eligible_child_ids', 'eligible_count', 'execution_policy', 'execution_rows', 'failure_policy', 'has_eligible', 'max_children', 'policy_version', 'schema_version', 'selected_count', 'selection_rows', 'skipped_child_ids', 'skipped_count', 'skipped_rows');
		sort($plan_keys, SORT_STRING); sort($expected_plan_keys, SORT_STRING);
		if ($plan_keys !== $expected_plan_keys
			|| self::POLICY_VERSION !== (int) (isset($plan['policy_version']) ? $plan['policy_version'] : 0)
			|| self::MAX_CHILDREN !== (int) (isset($plan['max_children']) ? $plan['max_children'] : 0)
			|| empty($selection) || count($selection) > self::MAX_CHILDREN
			|| !isset($plan['canonical_child_ids'], $plan['eligible_child_ids'], $plan['skipped_child_ids'])
			|| !is_array($plan['canonical_child_ids']) || !is_array($plan['eligible_child_ids']) || !is_array($plan['skipped_child_ids'])
			|| !isset($plan['canonical_count'], $plan['eligible_count'], $plan['skipped_count'])
			|| (int) $plan['canonical_count'] !== count($selection) || (int) $plan['eligible_count'] !== count($execution) || (int) $plan['skipped_count'] !== count($skipped)
			|| (int) (isset($plan['selected_count']) ? $plan['selected_count'] : 0) !== count($selection) + (int) (isset($plan['duplicate_count']) ? $plan['duplicate_count'] : -1)
			|| (int) $plan['selected_count'] < count($selection) || (int) $plan['selected_count'] > self::MAX_CHILDREN || (int) $plan['duplicate_count'] < 0
			|| count($selection) !== count($execution) + count($skipped)
			|| !array_key_exists('has_eligible', $plan) || !is_bool($plan['has_eligible']) || $plan['has_eligible'] !== !empty($execution)
			|| !array_key_exists('all_eligible', $plan) || !is_bool($plan['all_eligible']) || $plan['all_eligible'] !== (!empty($execution) && empty($skipped))
			|| 'per_child' !== (string) (isset($plan['atomicity']) ? $plan['atomicity'] : '')
			|| 'fail_stop_after_first_non_success' !== (string) (isset($plan['failure_policy']) ? $plan['failure_policy'] : '')
			|| 'one_eligible_child_per_request' !== (string) (isset($plan['execution_policy']) ? $plan['execution_policy'] : '')
			|| 'start_next_execution_row_30_minutes' !== (string) (isset($plan['deadline_policy']) ? $plan['deadline_policy'] : '')
			|| 'server_review_eligible_or_skipped_v2' !== (string) (isset($plan['classification_policy']) ? $plan['classification_policy'] : '')
			|| '' === $stored || !hash_equals($stored, self::fingerprint($plan))) { throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Bulk Return v2 disclosure/execution authority failed integrity verification.', 'wc-order-splitter')); }

		$canonical_ids = array(); $eligible_selection = array(); $derived_skipped = array();
		foreach ($selection as $ordinal => $row) {
			$classification = is_array($row) && isset($row['classification']) ? sanitize_key((string) $row['classification']) : '';
			$row_keys = is_array($row) ? array_keys($row) : array();
			$expected_row_keys = array('child_order_id', 'classification', 'classification_fingerprint', 'eligible', 'message', 'reason', 'selection_ordinal', 'summary');
			sort($row_keys, SORT_STRING); sort($expected_row_keys, SORT_STRING);
			$summary = is_array($row) && isset($row['summary']) && is_array($row['summary']) ? $row['summary'] : array();
			$summary_keys = array_keys($summary);
			$expected_summary_keys = 'eligible' === $classification
				? array('child', 'currency', 'historical_subtotal', 'historical_tax', 'historical_total', 'line_count', 'original', 'quantity', 'strategy')
				: array('child');
			sort($summary_keys, SORT_STRING); sort($expected_summary_keys, SORT_STRING);
			$child_summary_keys = isset($summary['child']) && is_array($summary['child']) ? array_keys($summary['child']) : array();
			$expected_participant_keys = array('id', 'number');
			sort($child_summary_keys, SORT_STRING); sort($expected_participant_keys, SORT_STRING);
			$original_summary_keys = isset($summary['original']) && is_array($summary['original']) ? array_keys($summary['original']) : array();
			sort($original_summary_keys, SORT_STRING);
			if (!is_array($row) || $row_keys !== $expected_row_keys || !isset($row['selection_ordinal'], $row['eligible'], $row['child_order_id'], $row['reason'], $row['message'], $row['summary'])
				|| (int) $row['selection_ordinal'] !== (int) $ordinal || !in_array($classification, array('eligible', 'skipped'), true)
				|| !is_bool($row['eligible']) || $row['eligible'] !== ('eligible' === $classification) || !absint($row['child_order_id'])
				|| '' === sanitize_key((string) $row['reason']) || '' === (string) $row['message']
				|| '' === self::hex(isset($row['classification_fingerprint']) ? $row['classification_fingerprint'] : '') || !is_array($row['summary'])
				|| $summary_keys !== $expected_summary_keys || $child_summary_keys !== $expected_participant_keys
				|| !isset($summary['child']['id']) || absint($summary['child']['id']) !== absint($row['child_order_id'])
				|| ('eligible' === $classification && ($original_summary_keys !== $expected_participant_keys || !absint($summary['original']['id'])))) { throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Bulk Return v2 selection disclosure is malformed.', 'wc-order-splitter')); }
			$canonical_ids[] = absint($row['child_order_id']);
			if ('eligible' === $classification) { $eligible_selection[$ordinal] = absint($row['child_order_id']); }
			else { $derived_skipped[] = array('selection_ordinal' => (int) $ordinal, 'child_order_id' => absint($row['child_order_id']), 'reason' => sanitize_key((string) $row['reason'])); }
		}

		$eligible_ids = array(); $predecessor_groups = array();
		foreach ($execution as $ordinal => $row) {
			$selection_ordinal = is_array($row) && isset($row['selection_ordinal']) ? (int) $row['selection_ordinal'] : -1;
			$intent = is_array($row) && isset($row['batch_child_intent']) && is_array($row['batch_child_intent']) ? $row['batch_child_intent'] : array();
			$predecessors = isset($intent['expected_predecessor_ordinals']) && is_array($intent['expected_predecessor_ordinals']) ? array_values($intent['expected_predecessor_ordinals']) : null;
			$predecessor_ids = isset($intent['expected_predecessor_child_ids']) && is_array($intent['expected_predecessor_child_ids']) ? array_values(array_map('absint', $intent['expected_predecessor_child_ids'])) : null;
			$row_keys = is_array($row) ? array_keys($row) : array();
			$expected_row_keys = array('batch_child_intent', 'child_order_id', 'classification', 'classification_fingerprint', 'eligible', 'message', 'ordinal', 'original_order_id', 'reason', 'selection_ordinal', 'split_child_key', 'split_operation_id', 'summary');
			sort($row_keys, SORT_STRING); sort($expected_row_keys, SORT_STRING);
			$group_key = is_array($row) ? absint(isset($row['original_order_id']) ? $row['original_order_id'] : 0) . '|' . sanitize_key(isset($row['split_operation_id']) ? (string) $row['split_operation_id'] : '') : '';
			$expected_predecessors = isset($predecessor_groups[$group_key]) ? array_keys($predecessor_groups[$group_key]) : array();
			$expected_predecessor_ids = isset($predecessor_groups[$group_key]) ? array_values($predecessor_groups[$group_key]) : array();
			if (!is_array($row) || $row_keys !== $expected_row_keys || !isset($row['ordinal'], $row['classification'], $row['eligible'], $row['child_order_id'], $row['original_order_id'])
				|| (int) $row['ordinal'] !== (int) $ordinal || 'eligible' !== sanitize_key((string) $row['classification']) || !is_bool($row['eligible']) || true !== $row['eligible']
				|| !isset($eligible_selection[$selection_ordinal]) || absint($row['child_order_id']) !== $eligible_selection[$selection_ordinal]
				|| !isset($selection[$selection_ordinal]) || self::hex($row['classification_fingerprint']) !== self::hex($selection[$selection_ordinal]['classification_fingerprint'])
				|| $row['summary'] !== $selection[$selection_ordinal]['summary'] || (string) $row['reason'] !== (string) $selection[$selection_ordinal]['reason'] || (string) $row['message'] !== (string) $selection[$selection_ordinal]['message']
				|| !absint($row['original_order_id']) || '' === sanitize_key((string) $row['split_operation_id']) || '' === sanitize_key((string) $row['split_child_key'])
				|| empty($intent) || self::SCHEMA_VERSION !== (int) (isset($intent['schema_version']) ? $intent['schema_version'] : 0)
				|| absint(isset($intent['child_order_id']) ? $intent['child_order_id'] : 0) !== absint($row['child_order_id'])
				|| absint(isset($intent['original_order_id']) ? $intent['original_order_id'] : 0) !== absint($row['original_order_id'])
				|| sanitize_key((string) (isset($intent['split_operation_id']) ? $intent['split_operation_id'] : '')) !== sanitize_key((string) $row['split_operation_id'])
				|| sanitize_key((string) (isset($intent['split_child_key']) ? $intent['split_child_key'] : '')) !== sanitize_key((string) $row['split_child_key'])
				|| empty($intent['return_authority']) || !is_array($intent['return_authority']) || !is_array($predecessors) || !is_array($predecessor_ids)
				|| $predecessors !== $expected_predecessors || $predecessor_ids !== $expected_predecessor_ids) { throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Bulk Return v2 execution authority is malformed.', 'wc-order-splitter')); }
			foreach ($predecessors as $predecessor) {
				if (!is_int($predecessor) || $predecessor < 0 || $predecessor >= $ordinal) { throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Bulk Return v2 predecessor authority is malformed.', 'wc-order-splitter')); }
			}
			$eligible_ids[] = absint($row['child_order_id']);
			$predecessor_groups[$group_key][$ordinal] = absint($row['child_order_id']);
		}
		if ($canonical_ids !== array_values(array_map('absint', $plan['canonical_child_ids']))
			|| count($canonical_ids) !== count(array_unique($canonical_ids))
			|| $eligible_ids !== array_values(array_map('absint', $plan['eligible_child_ids']))
			|| $derived_skipped !== $skipped
			|| array_values(array_map('absint', array_column($skipped, 'child_order_id'))) !== array_values(array_map('absint', $plan['skipped_child_ids']))) { throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Bulk Return v2 disclosed sets do not match their canonical indexes.', 'wc-order-splitter')); }
		return true;
	}

	private static function compare_rows(array $left, array $right) {
		foreach (array('original_order_id', 'split_operation_id', 'child_order_id') as $field) {
			$a = isset($left[$field]) ? $left[$field] : ''; $b = isset($right[$field]) ? $right[$field] : '';
			$comparison = is_numeric($a) && is_numeric($b) ? ((int) $a <=> (int) $b) : strcmp((string) $a, (string) $b);
			if (0 !== $comparison) { return $comparison; }
		}
		return 0;
	}

	private static function hex($value) { $value = sanitize_key((string) $value); return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : ''; }

	private static function add_units($left, $right) {
		$left = (int) $left; $right = (int) $right;
		if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) { throw new OverflowException(__('Bulk Return historical aggregates exceed the supported integer range.', 'wc-order-splitter')); }
		return $left + $right;
	}

	private static function canonicalize($value) {
		if (!is_array($value)) { return $value; }
		$keys = array_keys($value); $is_list = empty($value) || $keys === range(0, count($value) - 1);
		if (!$is_list) { ksort($value, SORT_STRING); }
		foreach ($value as $key => $item) { $value[$key] = self::canonicalize($item); }
		return $value;
	}
}
