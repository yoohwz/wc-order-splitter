<?php

defined('ABSPATH') || exit;

/**
 * Fail-closed dual-participant persistence boundary for Merge recovery.
 */
final class WCOS_Merge_Commit_Guard {

	public static function assert_boundary(
		WCOS_Multi_Order_Lease $lease,
		WC_Order $source,
		WC_Order $target,
		array $record,
		$expected_source_signature,
		$expected_target_signature,
		$expected_relation_state = 'any'
	) {
		if (!$lease->refresh()) {
			throw new RuntimeException(__('A Merge participant lease was lost before a recovery persistence boundary.', 'wc-order-splitter'));
		}
		$lease->assert_owned();
		WCOS_Stock_Side_Effect_Guard::assert_current_clean();

		$operation_id = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		$fresh_record = WCOS_Operation_Journal::get($source, $operation_id);
		if (!is_array($fresh_record)) {
			throw new RuntimeException(__('The authoritative Merge journal disappeared before a recovery persistence boundary.', 'wc-order-splitter'));
		}
		WCOS_Operation_Journal::assert_fingerprint($fresh_record, isset($record['fingerprint']) ? $record['fingerprint'] : '');
		$pair = WCOS_Merge_Journal_Context::pair_from_record($fresh_record);
		$snapshot = isset($fresh_record['context']['merge_recovery_snapshot'])
			&& is_array($fresh_record['context']['merge_recovery_snapshot'])
			? $fresh_record['context']['merge_recovery_snapshot']
			: array();
		if (!is_array($pair) || empty($snapshot)
			|| $pair['source_order_id'] !== (int) $source->get_id()
			|| $pair['target_order_id'] !== (int) $target->get_id()) {
			throw new RuntimeException(__('Merge pair authority is invalid at a recovery persistence boundary.', 'wc-order-splitter'));
		}
		WCOS_Merge_Recovery_Snapshot::assert_valid($snapshot, $fresh_record);
		$fresh_source = self::participant_order($pair['source_order_id'], $snapshot['schema_version']);
		$fresh_target = self::participant_order($pair['target_order_id'], $snapshot['schema_version']);
		if (!$fresh_source instanceof WC_Order || !$fresh_target instanceof WC_Order
			|| 'shop_order' !== $fresh_source->get_type() || 'shop_order' !== $fresh_target->get_type()
			|| $pair['source_order_id'] !== (int) $fresh_source->get_id()
			|| $pair['target_order_id'] !== (int) $fresh_target->get_id()) {
			throw new RuntimeException(__('A Merge participant disappeared before a recovery persistence boundary.', 'wc-order-splitter'));
		}
		$context = isset($fresh_record['context']) && is_array($fresh_record['context']) ? $fresh_record['context'] : array();
		WCOS_Merge_Recovery_Snapshot::assert_immutable_pair(
			$snapshot,
			$fresh_record,
			$fresh_source,
			$fresh_target,
			isset($context['merge_target_item_ids']) ? (array) $context['merge_target_item_ids'] : array(),
			isset($context['merge_target_tax_item_ids']) ? (array) $context['merge_target_tax_item_ids'] : array()
		);

		self::assert_signature($fresh_source, $expected_source_signature, 'source', $snapshot['schema_version']);
		self::assert_signature($fresh_target, $expected_target_signature, 'target', $snapshot['schema_version']);
		self::assert_relation_state(
			WCOS_Merge_Participation::state_for_pair(
				$fresh_source,
				$fresh_target,
				$operation_id,
				$pair['pair_fingerprint']
			),
			$expected_relation_state
		);
		return array($fresh_source, $fresh_target, $fresh_record);
	}

	private static function participant_order($order_id, $snapshot_schema) {
		return WCOS_Merge_Recovery_Snapshot::SCHEMA_VERSION === (int) $snapshot_schema
			? WCOS_Merge_Canonical_Reader::order($order_id)
			: wc_get_order($order_id);
	}

	private static function assert_signature(WC_Order $order, $expected, $role, $schema_version) {
		$expected = self::fingerprint($expected);
		if ('' === $expected || !hash_equals($expected, WCOS_Merge_Recovery_Snapshot::participant_signature($order, $schema_version))) {
			throw new RuntimeException(
				'source' === $role
					? __('The Merge source changed after its approved checkpoint.', 'wc-order-splitter')
					: __('The Merge target changed after its approved checkpoint.', 'wc-order-splitter')
			);
		}
	}

	private static function assert_relation_state(array $state, $expected) {
		$expected = sanitize_key((string) $expected);
		$actual = $state['source'] && $state['target']
			? 'complete'
			: (($state['source'] || $state['target']) ? 'partial' : 'none');
		if ('any' !== $expected && $actual !== $expected) {
			throw new RuntimeException(__('Operation-owned Merge relation state does not match its recovery checkpoint.', 'wc-order-splitter'));
		}
	}

	private static function fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
