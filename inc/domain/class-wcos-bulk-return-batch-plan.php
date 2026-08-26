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

/** Immutable, PII-free Review plan for at most twenty Return children. */
final class WCOS_Bulk_Return_Batch_Plan {
	const SCHEMA_VERSION = 1;
	const POLICY_VERSION = 1;
	const MAX_CHILDREN = 20;

	public static function build(array $candidate_ids) {
		$normalized = self::normalize_ids($candidate_ids);
		$rows = array();
		foreach ($normalized['canonical_ids'] as $child_id) {
			$child = wc_get_order($child_id);
			$row = array(
				'child_order_id' => $child_id,
				'original_order_id' => 0,
				'split_operation_id' => '',
				'split_child_key' => '',
				'eligible' => false,
				'reason' => 'participant_not_found',
				'message' => __('The selected order is not a persisted WooCommerce order.', 'wc-order-splitter'),
				'batch_child_intent' => array(),
				'summary' => array('child' => array('id' => $child_id, 'number' => (string) $child_id)),
			);
			if ($child instanceof WC_Order && 'shop_order' === $child->get_type()) {
				try {
					$report = (new WCOS_Return_WooCommerce_Adapter())->preflight($child);
					if (empty($report['supported'])) {
						$row['reason'] = 'preflight_' . sanitize_key(isset($report['reason']) ? (string) $report['reason'] : 'unsupported');
						$row['message'] = isset($report['message']) ? (string) $report['message'] : __('This child is not eligible for hardened Return.', 'wc-order-splitter');
					} else {
						$authority = WCOS_Return_Review_Store::authority_from_preflight($child, $report);
						$row = self::eligible_row($child, $authority);
					}
				} catch (WCOS_Bulk_Return_Batch_Exception $exception) {
					$row['reason'] = $exception->get_reason();
					$row['message'] = $exception->getMessage();
				} catch (Throwable $throwable) {
					$row['reason'] = 'preflight_failed';
					$row['message'] = __('The selected child could not be verified safely.', 'wc-order-splitter');
				}
			}
			$rows[] = $row;
		}

		usort($rows, array(__CLASS__, 'compare_rows'));
		self::bind_predecessors($rows);
		self::reject_ambiguous_graphs($rows);
		$all_eligible = !empty($rows);
		foreach ($rows as $row) {
			$all_eligible = $all_eligible && !empty($row['eligible']);
		}

		$plan = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'max_children' => self::MAX_CHILDREN,
			'atomicity' => 'per_child',
			'failure_policy' => 'fail_stop_after_first_non_success',
			'execution_policy' => 'one_child_per_request',
			'deadline_policy' => 'start_next_row_30_minutes',
			'selected_count' => $normalized['selected_count'],
			'canonical_count' => count($normalized['canonical_ids']),
			'duplicate_count' => $normalized['duplicate_count'],
			'canonical_child_ids' => array_values(array_map(static function($row) { return absint($row['child_order_id']); }, $rows)),
			'all_eligible' => $all_eligible,
			'rows' => $rows,
		);
		$plan['batch_fingerprint'] = self::fingerprint($plan);
		return $plan;
	}

	public static function assert_review_current(array $plan) {
		self::assert_valid($plan);
		if (empty($plan['all_eligible'])) {
			throw new WCOS_Bulk_Return_Batch_Exception('batch_ineligible', __('Every selected row must be eligible before Batch Confirm.', 'wc-order-splitter'));
		}
		foreach ($plan['rows'] as $row) {
			$child = wc_get_order(absint($row['child_order_id']));
			if (!$child instanceof WC_Order || 'shop_order' !== $child->get_type()) {
				throw new WCOS_Bulk_Return_Batch_Exception('participant_missing', __('A reviewed Bulk Return participant is unavailable.', 'wc-order-splitter'));
			}
			try {
				WCOS_Return_Review_Store::assert_matches_current($child, $row['batch_child_intent']['return_authority']);
				$original = wc_get_order(absint($row['original_order_id']));
				if (!$original instanceof WC_Order) {
					throw new RuntimeException(__('The reviewed Bulk Return original is unavailable.', 'wc-order-splitter'));
				}
				WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::BULK_RETURN, $child, $original);
			} catch (Throwable $throwable) {
				throw new WCOS_Bulk_Return_Batch_Exception('authority_changed', __('Bulk Return authority or operator permission changed after Review. Review the selection again.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	/** Exact ordinary Return authority for the current row after expected siblings. */
	public static function derive_current_authority(array $plan, $ordinal, array $operation_map) {
		self::assert_valid($plan);
		$ordinal = absint($ordinal);
		if (!isset($plan['rows'][$ordinal]) || empty($plan['rows'][$ordinal]['eligible'])) {
			throw new WCOS_Bulk_Return_Batch_Exception('invalid_cursor', __('The Bulk Return cursor does not identify an eligible row.', 'wc-order-splitter'));
		}
		$row = $plan['rows'][$ordinal];
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

	/** Called by the narrow preallocated Confirmation path after derivation. */
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
		$stored = self::hex(isset($plan['batch_fingerprint']) ? $plan['batch_fingerprint'] : '');
		if (self::SCHEMA_VERSION !== (int) (isset($plan['schema_version']) ? $plan['schema_version'] : 0)
			|| self::POLICY_VERSION !== (int) (isset($plan['policy_version']) ? $plan['policy_version'] : 0)
			|| self::MAX_CHILDREN !== (int) (isset($plan['max_children']) ? $plan['max_children'] : 0)
			|| empty($plan['rows']) || count($plan['rows']) > self::MAX_CHILDREN
			|| !is_array($plan['canonical_child_ids']) || count($plan['canonical_child_ids']) !== count($plan['rows'])
			|| '' === $stored || !hash_equals($stored, self::fingerprint($plan))) {
			throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Bulk Return batch authority failed integrity verification.', 'wc-order-splitter'));
		}
		foreach ($plan['rows'] as $ordinal => $row) {
			if (absint($row['child_order_id']) !== absint($plan['canonical_child_ids'][$ordinal])
				|| !array_key_exists('eligible', $row) || !isset($row['batch_child_intent']) || !is_array($row['batch_child_intent'])) {
				throw new WCOS_Bulk_Return_Batch_Exception('plan_invalid', __('Bulk Return row authority is malformed.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	public static function fingerprint(array $plan) {
		$copy = $plan;
		unset($copy['batch_fingerprint']);
		$anchor = !empty($copy['canonical_child_ids']) ? absint($copy['canonical_child_ids'][0]) : 0;
		return WCOS_Mutation_Fingerprint::create('bulk_return_batch_plan_v1', $anchor, self::canonicalize($copy));
	}

	private static function normalize_ids(array $candidate_ids) {
		if (empty($candidate_ids)) {
			throw new WCOS_Bulk_Return_Batch_Exception('invalid_selection', __('Select between one and twenty canonical Return children.', 'wc-order-splitter'));
		}
		if (count($candidate_ids) > self::MAX_CHILDREN) {
			throw new WCOS_Bulk_Return_Batch_Exception('batch_too_large', __('Bulk Return supports at most twenty selected children.', 'wc-order-splitter'));
		}
		$ids = array();
		foreach ($candidate_ids as $raw) {
			if (is_array($raw) || is_object($raw) || is_bool($raw) || null === $raw) {
				throw new WCOS_Bulk_Return_Batch_Exception('invalid_selection', __('Bulk Return order IDs must be positive decimal scalars.', 'wc-order-splitter'));
			}
			$value = (string) $raw;
			if (1 !== preg_match('/^[1-9][0-9]*$/D', $value) || strlen($value) > strlen((string) PHP_INT_MAX) || (string) (int) $value !== $value) {
				throw new WCOS_Bulk_Return_Batch_Exception('invalid_selection', __('Bulk Return order IDs must be positive decimal scalars.', 'wc-order-splitter'));
			}
			$id = (int) $value;
			if ($id <= 0) {
				throw new WCOS_Bulk_Return_Batch_Exception('invalid_selection', __('Bulk Return order IDs are outside the supported range.', 'wc-order-splitter'));
			}
			$ids[] = $id;
		}
		$canonical = array_values(array_unique($ids));
		if (count($canonical) > self::MAX_CHILDREN) {
			throw new WCOS_Bulk_Return_Batch_Exception('batch_too_large', __('Bulk Return supports at most twenty canonical children.', 'wc-order-splitter'));
		}
		return array('canonical_ids' => $canonical, 'selected_count' => count($ids), 'duplicate_count' => count($ids) - count($canonical));
	}

	private static function eligible_row(WC_Order $child, array $authority) {
		$original = wc_get_order(absint($authority['original_order_id']));
		if (!$original instanceof WC_Order) {
			throw new WCOS_Bulk_Return_Batch_Exception('participant_missing', __('The server-resolved Return original is unavailable.', 'wc-order-splitter'));
		}
		try {
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::BULK_RETURN, $child, $original);
		} catch (Throwable $throwable) {
			throw new WCOS_Bulk_Return_Batch_Exception('authorization_failed', __('You are not allowed to Return this child and its server-resolved original.', 'wc-order-splitter'));
		}
		$plan = $authority['plan'];
		$quantity_units = 0;
		$subtotal_units = 0;
		$total_units = 0;
		$tax_units = 0;
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
			'eligible' => true,
			'reason' => 'supported',
			'message' => __('This child has immutable hardened Return authority.', 'wc-order-splitter'),
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
			if (empty($row['eligible'])) { continue; }
			$key = absint($row['original_order_id']) . '|' . sanitize_key((string) $row['split_operation_id']);
			$predecessors = isset($groups[$key]) ? $groups[$key] : array();
			$row['batch_child_intent']['expected_predecessor_ordinals'] = array_keys($predecessors);
			$row['batch_child_intent']['expected_predecessor_child_ids'] = array_values($predecessors);
			$groups[$key][$ordinal] = absint($row['child_order_id']);
		}
		unset($row);
	}

	private static function reject_ambiguous_graphs(array &$rows) {
		$original_operations = array();
		$child_ids = array();
		$original_ids = array();
		foreach ($rows as $row) {
			if (empty($row['eligible'])) { continue; }
			$original = absint($row['original_order_id']);
			$operation = sanitize_key((string) $row['split_operation_id']);
			$original_operations[$original][$operation] = true;
			$child_ids[absint($row['child_order_id'])] = true;
			$original_ids[$original] = true;
		}
		$ambiguous_originals = array();
		foreach ($original_operations as $original => $operations) {
			if (count($operations) > 1) { $ambiguous_originals[(int) $original] = true; }
		}
		$overlap = array_intersect_key($child_ids, $original_ids);
		if (empty($ambiguous_originals) && empty($overlap)) { return; }
		foreach ($rows as &$row) {
			if (!empty($row['eligible']) && (isset($ambiguous_originals[absint($row['original_order_id'])])
				|| isset($overlap[absint($row['child_order_id'])]) || isset($overlap[absint($row['original_order_id'])]))) {
				$row['eligible'] = false;
				$row['reason'] = 'ambiguous_participant_graph';
				$row['message'] = __('The selected children form an overlapping or ambiguous Return participant graph.', 'wc-order-splitter');
				$row['batch_child_intent'] = array();
			}
		}
		unset($row);
	}

	private static function assert_constrained_derivation(array $intent, array $current, array $plan, array $operation_map) {
		$frozen = $intent['return_authority'];
		foreach (array('child_order_id', 'original_order_id', 'split_operation_id', 'split_child_key', 'price_precision', 'currency', 'prices_include_tax', 'return_service_policy_version', 'preflight_policy_version', 'plan_schema_version', 'plan_policy_version', 'lineage_schema_version', 'lineage_policy_version', 'journal_context_schema_version', 'retirement_policy_schema_version', 'retirement_policy_identifier', 'stock_ownership_policy', 'order_stock_flag_policy') as $field) {
			if (!array_key_exists($field, $current) || (string) $current[$field] !== (string) $frozen[$field]) {
				throw new WCOS_Bulk_Return_Batch_Exception('authority_changed', __('Bulk Return immutable child or policy authority changed.', 'wc-order-splitter'));
			}
		}
		$frozen_plan = $frozen['plan'];
		$current_plan = $current['plan'];
		foreach (array('plan_fingerprint', 'lineage_authority_fingerprint', 'source_commercial_authority', 'source_relation_authority') as $field) {
			unset($frozen_plan[$field], $current_plan[$field]);
		}
		if ($frozen_plan !== $current_plan) {
			throw new WCOS_Bulk_Return_Batch_Exception('child_intent_changed', __('Bulk Return historical line authority changed after Review.', 'wc-order-splitter'));
		}
		$frozen_pair = $frozen['pair_authority'];
		$current_pair = $current['pair_authority'];
		if ((string) $frozen_pair['child_signature_before'] !== (string) $current_pair['child_signature_before']) {
			throw new WCOS_Bulk_Return_Batch_Exception('child_intent_changed', __('Bulk Return child commercial authority changed after Review.', 'wc-order-splitter'));
		}
		$base = $frozen_pair['source_evolution_authority'];
		$now = $current_pair['source_evolution_authority'];
		WCOS_Return_Source_Evolution_Authority::assert_valid($base, $intent['original_order_id'], $intent['split_operation_id']);
		WCOS_Return_Source_Evolution_Authority::assert_valid($now, $intent['original_order_id'], $intent['split_operation_id']);
		$expected_ids = array_values(array_map('absint', $intent['expected_predecessor_child_ids']));
		$expected_returned = array_values(array_unique(array_merge($base['returned_child_ids'], $expected_ids)));
		sort($expected_returned, SORT_NUMERIC);
		$expected_pairs = $base['completed_pair_fingerprints'];
		foreach ($intent['expected_predecessor_ordinals'] as $predecessor_ordinal) {
			$predecessor_ordinal = absint($predecessor_ordinal);
			if (!isset($plan['rows'][$predecessor_ordinal], $operation_map[$predecessor_ordinal])) {
				throw new WCOS_Bulk_Return_Batch_Exception('predecessor_missing', __('Bulk Return predecessor operation authority is incomplete.', 'wc-order-splitter'));
			}
			$predecessor = $plan['rows'][$predecessor_ordinal];
			$operation_id = sanitize_key((string) $operation_map[$predecessor_ordinal]);
			$predecessor_child = wc_get_order(absint($predecessor['child_order_id']));
			$journal = $predecessor_child instanceof WC_Order ? WCOS_Operation_Journal::get($predecessor_child, $operation_id) : null;
			if (!is_array($journal) || 'completed' !== sanitize_key(isset($journal['status']) ? (string) $journal['status'] : '')) {
				throw new WCOS_Bulk_Return_Batch_Exception('predecessor_incomplete', __('Bulk Return predecessor is not durably completed.', 'wc-order-splitter'));
			}
			$result = WCOS_Return_Journal_Context::terminal_result_from_record($journal);
			if (absint($result['child_order_id']) !== absint($predecessor['child_order_id'])
				|| absint($result['original_order_id']) !== absint($intent['original_order_id'])) {
				throw new WCOS_Bulk_Return_Batch_Exception('predecessor_mismatch', __('Bulk Return predecessor journal does not match the frozen sibling chain.', 'wc-order-splitter'));
			}
			$expected_pairs[] = sanitize_key((string) $result['pair_fingerprint']);
		}
		$expected_pairs = array_values(array_unique($expected_pairs));
		sort($expected_pairs, SORT_STRING);
		if ((int) $now['sequence'] !== (int) $base['sequence'] + count($expected_ids)
			|| $now['returned_child_ids'] !== $expected_returned
			|| $now['completed_pair_fingerprints'] !== $expected_pairs) {
			throw new WCOS_Bulk_Return_Batch_Exception('unexpected_source_evolution', __('The original changed outside the exact frozen Bulk Return predecessor chain.', 'wc-order-splitter'));
		}
	}

	private static function compare_rows(array $left, array $right) {
		foreach (array('original_order_id', 'split_operation_id', 'child_order_id') as $field) {
			$a = isset($left[$field]) ? $left[$field] : '';
			$b = isset($right[$field]) ? $right[$field] : '';
			$comparison = is_numeric($a) && is_numeric($b) ? ((int) $a <=> (int) $b) : strcmp((string) $a, (string) $b);
			if (0 !== $comparison) { return $comparison; }
		}
		return 0;
	}

	private static function hex($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}

	private static function add_units($left, $right) {
		$left = (int) $left;
		$right = (int) $right;
		if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
			throw new OverflowException(__('Bulk Return historical aggregates exceed the supported integer range.', 'wc-order-splitter'));
		}
		return $left + $right;
	}

	private static function canonicalize($value) {
		if (!is_array($value)) { return $value; }
		$is_list = array_keys($value) === range(0, count($value) - 1);
		if (!$is_list) { ksort($value, SORT_STRING); }
		foreach ($value as $key => $item) { $value[$key] = self::canonicalize($item); }
		return $value;
	}
}
