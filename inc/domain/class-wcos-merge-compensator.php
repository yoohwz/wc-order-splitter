<?php

defined('ABSPATH') || exit;

/** Test/failure-injection signal that models process loss without ambiguity. */
final class WCOS_Merge_Recovery_Interruption_Exception extends RuntimeException {}

/**
 * Verified forward repair or compensation for a source-journal Merge pair.
 *
 * This class is recovery-only. It does not perform a commercial Merge and has
 * no production transport, adapter, controller, or gateway entry point.
 */
final class WCOS_Merge_Compensator {

	public static function recover(WC_Order $source, WC_Order $target, array $record, WCOS_Multi_Order_Lease $lease) {
		$operation_id = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$snapshot = isset($context['merge_recovery_snapshot']) && is_array($context['merge_recovery_snapshot'])
			? $context['merge_recovery_snapshot']
			: array();
		$pair = WCOS_Merge_Journal_Context::pair_from_record($record);
		if ('' === $operation_id || !is_array($pair) || empty($snapshot)) {
			throw new RuntimeException(__('Merge recovery authority is incomplete.', 'wc-order-splitter'));
		}
		WCOS_Merge_Recovery_Snapshot::assert_valid($snapshot, $record);
		$recovery_state = WCOS_Merge_Recovery_State_Graph::assert_record($record);
		if (!empty($context['merge_physical_stock_after_write'])) {
			return self::manual_reconciliation($source, $target, $record, 'physical_stock_after_write');
		}

		$source_before = WCOS_Merge_Recovery_Snapshot::before_signature($snapshot, 'source');
		$target_before = WCOS_Merge_Recovery_Snapshot::before_signature($snapshot, 'target');
		$source_after = self::fingerprint(isset($context['merge_source_signature_after']) ? $context['merge_source_signature_after'] : '');
		$target_after = self::fingerprint(isset($context['merge_target_signature_after']) ? $context['merge_target_signature_after'] : '');
		$target_item_ids = self::ids(isset($context['merge_target_item_ids']) ? (array) $context['merge_target_item_ids'] : array());
		$target_tax_item_ids = self::ids(isset($context['merge_target_tax_item_ids']) ? (array) $context['merge_target_tax_item_ids'] : array());

		try {
			$current_source = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
			$current_target = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
			if (!empty($context['merge_forward_repair_allowed'])
				&& in_array($recovery_state, array(
					WCOS_Merge_Recovery_State_Graph::SOURCE_RETIRED,
					WCOS_Merge_Recovery_State_Graph::SOURCE_RELATION,
					WCOS_Merge_Recovery_State_Graph::RELATIONS_COMPLETE,
					WCOS_Merge_Recovery_State_Graph::VERIFIED,
					WCOS_Merge_Recovery_State_Graph::COMMITTED,
				), true)
				&& '' !== $source_after && '' !== $target_after
				&& hash_equals($source_after, $current_source)
				&& hash_equals($target_after, $current_target)) {
				return self::forward($source, $target, $record, $lease, $source_after, $target_after);
			}

			$source_known = hash_equals($source_before, $current_source)
				|| ('' !== $source_after && hash_equals($source_after, $current_source));
			$target_known = hash_equals($target_before, $current_target)
				|| ('' !== $target_after && hash_equals($target_after, $current_target));
			if (!$source_known || !$target_known) {
				throw new RuntimeException(__('A Merge participant diverged from every approved recovery checkpoint.', 'wc-order-splitter'));
			}

			if ('compensating' !== sanitize_key(isset($record['status']) ? (string) $record['status'] : '')) {
				if (!WCOS_Operation_Journal::mark_compensating($source, $operation_id, array(
					'merge_compensation' => true,
					'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::COMPENSATING,
				))) {
					throw new RuntimeException(__('Merge compensation could not be checkpointed.', 'wc-order-splitter'));
				}
			}

			/* Restore stock/commercial ownership to source before target cleanup. */
			if (!hash_equals($source_before, $current_source)) {
				self::boundary($lease, $source, $target, $record, $current_source, $current_target, 'any', 'before_source_restore');
				$source = WCOS_Merge_Recovery_Snapshot::restore_participant($snapshot, 'source', $current_source);
				self::event('after_source_restore', $source, $target, $operation_id);
			}
			if (!WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_compensation_source_restored', array(
				'merge_source_compensated_signature' => $source_before,
				'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::SOURCE_RESTORED,
			))) {
				throw new RuntimeException(__('Restored Merge source ownership could not be checkpointed.', 'wc-order-splitter'));
			}

			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			$current_source = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
			$current_target = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
			if (!hash_equals($target_before, $current_target)) {
				self::boundary($lease, $source, $target, $record, $source_before, $current_target, 'any', 'before_target_cleanup');
				$target = WCOS_Merge_Recovery_Snapshot::restore_participant(
					$snapshot,
					'target',
					$current_target,
					$target_item_ids,
					$target_tax_item_ids
				);
				self::event('after_target_cleanup', $source, $target, $operation_id);
			}
			if (!WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_compensation_target_restored', array(
				'merge_target_compensated_signature' => $target_before,
				'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::TARGET_RESTORED,
			))) {
				throw new RuntimeException(__('Restored Merge target could not be checkpointed.', 'wc-order-splitter'));
			}

			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			self::boundary($lease, $source, $target, $record, $source_before, $target_before, 'any', 'before_relation_cleanup');
			if (!WCOS_Merge_Participation::cleanup($source, $target, $operation_id)) {
				throw new RuntimeException(__('Operation-owned Merge participation could not be cleaned up.', 'wc-order-splitter'));
			}
			if (!WCOS_Operation_Journal::mark_compensated($source, $operation_id, array(
				'merge_compensated' => true,
				'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::COMPENSATED,
			))) {
				throw new RuntimeException(__('Merge compensation could not be finalized.', 'wc-order-splitter'));
			}
			return 'compensated';
		} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
			if (WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($exception->get_events())) {
				return self::manual_reconciliation($source, $target, $record, 'physical_stock_after_write');
			}
			throw $exception;
		} catch (WCOS_Merge_Recovery_Interruption_Exception $exception) {
			throw $exception;
		} catch (Throwable $throwable) {
			return self::manual_reconciliation($source, $target, $record, 'merge_recovery_divergence');
		}
	}

	public static function manual_reconciliation(WC_Order $source, $target, array $record, $reason) {
		$operation_id = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		$pair = WCOS_Merge_Journal_Context::pair_from_record($record);
		$reason = sanitize_key((string) $reason);
		if ('' === $reason) {
			$reason = 'merge_recovery_ambiguous';
		}
		if (is_array($pair) && $target instanceof WC_Order) {
			try {
				WCOS_Merge_Participation::persist($source, $target, $operation_id, $pair['pair_fingerprint']);
			} catch (Throwable $throwable) {
				/* Pair blockers below remain the primary fail-closed authority. */
			}
			$blocked = WCOS_Manual_Reconciliation_Blocker::block_pair($source, $target, $operation_id, $pair['pair_fingerprint']);
			if (!$blocked
				&& !WCOS_Manual_Reconciliation_Blocker::has_active($source)
				&& !WCOS_Manual_Reconciliation_Blocker::has_active($target)) {
				throw new RuntimeException(__('Pair-wide Merge reconciliation authority could not be persisted.', 'wc-order-splitter'));
			}
		} else {
			if (!WCOS_Manual_Reconciliation_Blocker::block($source, $operation_id)
				&& !WCOS_Manual_Reconciliation_Blocker::has_active($source)) {
				throw new RuntimeException(__('Merge reconciliation authority could not be persisted for the surviving source.', 'wc-order-splitter'));
			}
		}
		if (!WCOS_Operation_Journal::mark_manual_reconciliation($source, $operation_id, array(
			'reason' => $reason,
			'automatic_compensation_allowed' => false,
		))) {
			throw new RuntimeException(__('Merge manual-reconciliation state could not be recorded.', 'wc-order-splitter'));
		}
		return 'manual_reconciliation';
	}

	private static function forward(WC_Order $source, WC_Order $target, array $record, WCOS_Multi_Order_Lease $lease, $source_after, $target_after) {
		$operation_id = sanitize_key((string) $record['operation_id']);
		$pair = WCOS_Merge_Journal_Context::pair_from_record($record);
		self::boundary($lease, $source, $target, $record, $source_after, $target_after, 'any', 'before_forward_relations');
		WCOS_Merge_Participation::persist($source, $target, $operation_id, $pair['pair_fingerprint']);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		if (!WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_relations_completed', array(
			'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::RELATIONS_COMPLETE,
		))) {
			throw new RuntimeException(__('Forward-repaired Merge relations could not be checkpointed.', 'wc-order-splitter'));
		}
		self::boundary(
			$lease,
			$source,
			$target,
			$record,
			WCOS_Merge_Recovery_Snapshot::participant_signature($source),
			WCOS_Merge_Recovery_Snapshot::participant_signature($target),
			'complete',
			'after_forward_relations'
		);
		if (!WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_commercial_verified', array(
			'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::VERIFIED,
		))
			|| !WCOS_Operation_Journal::mark_committed($source, $operation_id, array(
				'merge_forward_repaired' => true,
				'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::COMMITTED,
			))
			|| !WCOS_Operation_Journal::complete($source, $operation_id, array(
				'merge_verified' => true,
				'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::COMPLETED,
			))) {
			throw new RuntimeException(__('Forward-repaired Merge state could not be finalized.', 'wc-order-splitter'));
		}
		return 'completed';
	}

	private static function boundary(WCOS_Multi_Order_Lease $lease, WC_Order $source, WC_Order $target, array $record, $source_signature, $target_signature, $relation, $stage) {
		self::event($stage, $source, $target, sanitize_key((string) $record['operation_id']));
		WCOS_Merge_Commit_Guard::assert_boundary($lease, $source, $target, $record, $source_signature, $target_signature, $relation);
	}

	private static function event($stage, WC_Order $source, WC_Order $target, $operation_id) {
		do_action('wcos_merge_recovery_checkpoint', sanitize_key((string) $stage), $source, $target, $operation_id);
	}

	private static function ids(array $ids) {
		$ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private static function fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
