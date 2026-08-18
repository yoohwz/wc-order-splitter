<?php

defined('ABSPATH') || exit;

/**
 * Strict compensating rollback for a split that cannot be finalized safely.
 *
 * Compensation is journaled and resumable. The source is always restored before
 * operation-owned child deletion. Recovery recognizes both the post-mutation
 * source signature and an already-restored pre-mutation signature so a crash
 * between persistence and checkpointing is safe to resume.
 */
final class WCOS_Split_Compensator {

	public static function compensate(WC_Order $source, array $children, array $record) {
		$operation_id = isset($record['operation_id']) ? sanitize_key($record['operation_id']) : '';
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$snapshot = isset($context['source_snapshot']) && is_array($context['source_snapshot']) ? $context['source_snapshot'] : array();
		$expected_after = isset($context['source_recovery_signature_after'])
			? sanitize_key((string) $context['source_recovery_signature_after'])
			: '';
		$expected_child_signatures = isset($context['child_signatures']) && is_array($context['child_signatures'])
			? $context['child_signatures']
			: array();

		if ('' === $operation_id || empty($snapshot)) {
			throw new RuntimeException(__('The split operation does not contain a complete recovery snapshot.', 'wc-order-splitter'));
		}
		WCOS_Order_Mutation_Snapshot::assert_valid($snapshot);
		$expected_before = sanitize_key((string) $snapshot['source_recovery_signature']);

		$expected_ids = isset($context['target_order_ids']) ? array_map('absint', (array) $context['target_order_ids']) : array();
		$expected_ids = array_values(array_unique(array_filter($expected_ids)));
		sort($expected_ids, SORT_NUMERIC);
		$current_ids = self::child_ids($children);
		sort($current_ids, SORT_NUMERIC);

		$status = isset($record['status']) ? sanitize_key($record['status']) : '';
		$stage = isset($record['stage']) ? sanitize_key($record['stage']) : '';
		$current_signature = WCOS_Order_Mutation_Snapshot::split_owned_signature($source);

		if ('compensating' !== $status) {
			if ('' === $expected_after || !hash_equals($expected_after, $current_signature)) {
				throw new RuntimeException(__('The split source no longer matches its persisted mutation checkpoint; automatic compensation is unsafe.', 'wc-order-splitter'));
			}
			if ($expected_ids !== $current_ids) {
				throw new RuntimeException(__('The split child set changed before compensation; automatic rollback is unsafe.', 'wc-order-splitter'));
			}
			self::assert_children_safe($source, $children, $operation_id, $expected_child_signatures);
			if (!WCOS_Operation_Journal::mark_compensating(
				$source,
				$operation_id,
				array(
					'compensation_started_at' => gmdate('c'),
					'compensation_target_order_ids' => $expected_ids,
				)
			)) {
				throw new RuntimeException(__('The split compensation state could not be recorded.', 'wc-order-splitter'));
			}
			$stage = 'compensating';
		}

		if (!in_array($stage, array('compensation_source_restored', 'compensation_child_removed'), true)) {
			$source = wc_get_order($source->get_id());
			$current_signature = WCOS_Order_Mutation_Snapshot::split_owned_signature($source);

			if (hash_equals($expected_before, $current_signature)) {
				/* Crash window: source was restored but its checkpoint was not. */
				WCOS_Order_Copy_Context::assert_matches((string) $snapshot['copy_context_signature'], $source);
			} elseif ('' !== $expected_after && hash_equals($expected_after, $current_signature)) {
				do_action('wcos_split_compensation_checkpoint', 'before_source_restore', $source, null, $operation_id);
				$source = WCOS_Order_Mutation_Snapshot::restore_split_source($snapshot, $expected_after);
				do_action('wcos_split_compensation_checkpoint', 'after_source_restore', $source, null, $operation_id);
			} else {
				throw new RuntimeException(__('The split source changed while compensation was pending; automatic rollback is unsafe.', 'wc-order-splitter'));
			}

			if (!WCOS_Operation_Journal::checkpoint(
				$source,
				$operation_id,
				'compensation_source_restored',
				array(
					'source_signature_compensated' => WCOS_Order_Contract_Snapshot::source_signature($source),
					'source_recovery_signature_compensated' => WCOS_Order_Mutation_Snapshot::split_owned_signature($source),
				)
			)) {
				throw new RuntimeException(__('The restored split source could not be checkpointed.', 'wc-order-splitter'));
			}
		}

		$source = wc_get_order($source->get_id());
		self::assert_source_restored($source, $snapshot);

		foreach ($expected_ids as $child_id) {
			$child = wc_get_order($child_id);
			if (!$child) {
				continue;
			}
			self::assert_child_safe($source, $child, $operation_id, $expected_child_signatures);
			do_action('wcos_split_compensation_checkpoint', 'before_child_delete', $source, $child, $operation_id);

			/* Deletion itself must not be interpreted as a stock restoration event. */
			$child->get_data_store()->set_stock_reduced($child->get_id(), false);
			$child->delete(true);
			if (wc_get_order($child_id)) {
				throw new RuntimeException(__('A compensated split child could not be removed.', 'wc-order-splitter'));
			}
			do_action('wcos_split_compensation_checkpoint', 'after_child_delete', $source, null, $operation_id);

			$remaining = array();
			foreach ($expected_ids as $candidate_id) {
				if (wc_get_order($candidate_id)) {
					$remaining[] = $candidate_id;
				}
			}
			if (!WCOS_Operation_Journal::checkpoint(
				$source,
				$operation_id,
				'compensation_child_removed',
				array('remaining_compensation_target_order_ids' => $remaining)
			)) {
				throw new RuntimeException(__('A split child was removed but its compensation checkpoint could not be recorded.', 'wc-order-splitter'));
			}
		}

		$source = wc_get_order($source->get_id());
		self::assert_source_restored($source, $snapshot);
		if (!WCOS_Operation_Journal::mark_compensated(
			$source,
			$operation_id,
			array(
				'compensated_at' => gmdate('c'),
				'remaining_compensation_target_order_ids' => array(),
			)
		)) {
			throw new RuntimeException(__('The split compensation could not be finalized in the operation journal.', 'wc-order-splitter'));
		}

		return $source;
	}

	private static function assert_source_restored(WC_Order $source, array $snapshot) {
		if (!$source
			|| !hash_equals((string) $snapshot['source_signature'], WCOS_Order_Contract_Snapshot::source_signature($source))
			|| !hash_equals((string) $snapshot['source_recovery_signature'], WCOS_Order_Mutation_Snapshot::split_owned_signature($source))) {
			throw new RuntimeException(__('The compensated source does not match its pre-split snapshot.', 'wc-order-splitter'));
		}
	}

	private static function assert_children_safe(WC_Order $source, array $children, $operation_id, array $expected_signatures) {
		foreach ($children as $child) {
			if (!$child instanceof WC_Order) {
				throw new RuntimeException(__('The split compensation received an invalid child order.', 'wc-order-splitter'));
			}
			self::assert_child_safe($source, $child, $operation_id, $expected_signatures);
		}
	}

	private static function assert_child_safe(WC_Order $source, WC_Order $child, $operation_id, array $expected_signatures) {
		if ('shop_order' !== $child->get_type()
			|| 'wc-order-splitter-split' !== $child->get_created_via()
			|| 'pending' !== $child->get_status()
			|| (int) $child->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true) !== $source->get_id()
			|| (string) $child->get_meta(WCOS_Split_Order_Service::OPERATION_META, true) !== $operation_id
			|| '' === sanitize_key((string) $child->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true))) {
			throw new RuntimeException(__('A split child changed ownership or workflow state; automatic compensation is unsafe.', 'wc-order-splitter'));
		}

		$child_id = $child->get_id();
		if (!isset($expected_signatures[$child_id])) {
			throw new RuntimeException(__('A split child is missing its persisted recovery signature.', 'wc-order-splitter'));
		}
		$expected = sanitize_key((string) $expected_signatures[$child_id]);
		$actual = class_exists('WCOS_Split_Recovery_Signature')
			? WCOS_Split_Recovery_Signature::child($child)
			: WCOS_Order_Contract_Snapshot::source_signature($child);
		if ('' === $expected || !hash_equals($expected, $actual)) {
			throw new RuntimeException(__('A split child changed after its persistence checkpoint; automatic compensation is unsafe.', 'wc-order-splitter'));
		}
	}

	private static function child_ids(array $children) {
		$ids = array();
		foreach ($children as $child) {
			if ($child instanceof WC_Order && $child->get_id()) {
				$ids[] = absint($child->get_id());
			}
		}
		$ids = array_values(array_unique($ids));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}
}
