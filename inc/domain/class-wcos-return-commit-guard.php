<?php

defined('ABSPATH') || exit;

/** Fail-closed dual-participant persistence boundary for Return recovery. */
final class WCOS_Return_Commit_Guard {
	public static function assert_boundary(
		WCOS_Multi_Order_Lease $lease,
		WC_Order $child,
		WC_Order $original,
		array $record,
		$expected_child_signature,
		$expected_original_signature,
		array $added_original_item_ids = array(),
		$expected_relation_state = 'any'
	) {
		if (!$lease->refresh()) {
			throw new RuntimeException(__('A Return participant lease was lost before a persistence boundary.', 'wc-order-splitter'));
		}
		$lease->assert_owned();
		WCOS_Stock_Side_Effect_Guard::assert_current_clean();
		$operation_id = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		$fresh_child = wc_get_order($child->get_id());
		$fresh_original = wc_get_order($original->get_id());
		if (!$fresh_child instanceof WC_Order || !$fresh_original instanceof WC_Order) {
			throw new RuntimeException(__('A Return participant disappeared before a persistence boundary.', 'wc-order-splitter'));
		}
		$fresh_record = WCOS_Operation_Journal::get($fresh_child, $operation_id);
		if (!is_array($fresh_record)) {
			throw new RuntimeException(__('The authoritative Return journal disappeared before a persistence boundary.', 'wc-order-splitter'));
		}
		WCOS_Operation_Journal::assert_fingerprint($fresh_record, isset($record['fingerprint']) ? $record['fingerprint'] : '');
		$pair = WCOS_Return_Journal_Context::pair_from_record($fresh_record);
		$context = isset($fresh_record['context']) && is_array($fresh_record['context']) ? $fresh_record['context'] : array();
		$snapshot = isset($context['return_recovery_snapshot']) && is_array($context['return_recovery_snapshot']) ? $context['return_recovery_snapshot'] : array();
		$plan = isset($context['return_plan']) && is_array($context['return_plan']) ? $context['return_plan'] : array();
		if (!is_array($pair) || empty($snapshot) || empty($plan)
			|| $pair['child_order_id'] !== (int) $fresh_child->get_id()
			|| $pair['original_order_id'] !== (int) $fresh_original->get_id()
			|| !hash_equals($pair['plan_fingerprint'], WCOS_Return_Plan::fingerprint($plan))) {
			throw new RuntimeException(__('Return pair, plan, or policy authority is invalid at a persistence boundary.', 'wc-order-splitter'));
		}
		WCOS_Return_Recovery_Snapshot::assert_valid($snapshot, $fresh_record);
		WCOS_Return_Recovery_Snapshot::assert_physical_stock_unchanged($snapshot, $fresh_child, $plan);
		self::assert_signature($snapshot, 'child', $fresh_child, $expected_child_signature, array());
		self::assert_signature($snapshot, 'original', $fresh_original, $expected_original_signature, $added_original_item_ids);
		self::assert_relation_state(
			WCOS_Return_Participation::state_for_pair($fresh_child, $fresh_original, $operation_id, $pair['pair_fingerprint']),
			$expected_relation_state
		);
		return array($fresh_child, $fresh_original, $fresh_record);
	}

	private static function assert_signature(array $snapshot, $role, WC_Order $order, $expected, array $added_ids) {
		$expected = self::fingerprint($expected);
		$actual = WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, $role, $order, $added_ids);
		if ('' === $expected || !hash_equals($expected, $actual)) {
			throw new RuntimeException(
				'child' === $role
					? __('The Return child changed after its approved checkpoint.', 'wc-order-splitter')
					: __('The Return original changed after its approved checkpoint.', 'wc-order-splitter')
			);
		}
	}

	private static function assert_relation_state(array $state, $expected) {
		$expected = sanitize_key((string) $expected);
		$actual = $state['child'] && $state['original'] && $state['active_split_removed']
			? 'complete'
			: (($state['child'] || $state['original'] || $state['active_split_removed']) ? 'partial' : 'none');
		if ('any' !== $expected && $actual !== $expected) {
			throw new RuntimeException(__('Operation-owned Return relation state does not match its recovery checkpoint.', 'wc-order-splitter'));
		}
	}

	private static function fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
