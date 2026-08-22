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
		$source_after_state = isset($context['merge_source_state_after']) && is_array($context['merge_source_state_after']) ? $context['merge_source_state_after'] : array();
		$target_after_state = isset($context['merge_target_state_after']) && is_array($context['merge_target_state_after']) ? $context['merge_target_state_after'] : array();
		$target_item_ids = self::ids(isset($context['merge_target_item_ids']) ? (array) $context['merge_target_item_ids'] : array());
		$target_tax_item_ids = self::ids(isset($context['merge_target_tax_item_ids']) ? (array) $context['merge_target_tax_item_ids'] : array());
		$retirement_candidate = sanitize_key(isset($context['merge_retirement_candidate']) ? (string) $context['merge_retirement_candidate'] : '');
		if ('' !== $retirement_candidate) {
			WCOS_Merge_Retirement_Policy::assert_candidate($retirement_candidate);
		}

		try {
			$current_source = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
			$current_target = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
			$forward_state = !empty($context['merge_forward_repair_allowed'])
				&& in_array($recovery_state, array(
					WCOS_Merge_Recovery_State_Graph::SOURCE_RETIRED,
					WCOS_Merge_Recovery_State_Graph::SOURCE_RELATION,
					WCOS_Merge_Recovery_State_Graph::RELATIONS_COMPLETE,
					WCOS_Merge_Recovery_State_Graph::VERIFIED,
					WCOS_Merge_Recovery_State_Graph::COMMITTED,
				), true)
				&& '' !== $source_after && '' !== $target_after;
			if ($forward_state) {
				$forward_valid = false;
				try {
					WCOS_Merge_Recovery_Snapshot::assert_forward_checkpoint($source_after_state, $source);
					WCOS_Merge_Recovery_Snapshot::assert_forward_checkpoint($target_after_state, $target);
					$forward_valid = true;
				} catch (Throwable $throwable) {
					/* Fall through to compensation/manual validation. */
				}
				if ($forward_valid) {
					return self::forward($source, $target, $record, $lease, $current_source, $current_target);
				}
			}

			if (empty($source_after_state) || empty($target_after_state)) {
				throw new RuntimeException(__('Merge recovery is missing durable component checkpoints.', 'wc-order-splitter'));
			}
			try {
				WCOS_Merge_Recovery_Snapshot::assert_resumable_participant($snapshot['source'], $source_after_state, $source, array(), array());
				WCOS_Merge_Recovery_Snapshot::assert_resumable_participant($snapshot['target'], $target_after_state, $target, $target_item_ids, $target_tax_item_ids);
			} catch (Throwable $throwable) {
				throw new RuntimeException(__('A Merge participant diverged from every approved recovery checkpoint.', 'wc-order-splitter'));
			}

			if ('compensating' !== sanitize_key(isset($record['status']) ? (string) $record['status'] : '')) {
				self::lease_guard($lease);
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
				list($write_boundary, $sub_checkpoint) = self::resumable_callbacks($lease, $source, $target, $record, $snapshot, $source_after_state, $target_after_state, $target_item_ids, $target_tax_item_ids);
				$source = WCOS_Merge_Recovery_Snapshot::restore_participant($snapshot, 'source', $source_after_state, array(), array(), $write_boundary, $sub_checkpoint, $retirement_candidate);
				self::event('after_source_restore', $source, $target, $operation_id);
			}
			self::lease_guard($lease);
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
				list($write_boundary, $sub_checkpoint) = self::resumable_callbacks($lease, $source, $target, $record, $snapshot, $source_after_state, $target_after_state, $target_item_ids, $target_tax_item_ids);
				$target = WCOS_Merge_Recovery_Snapshot::restore_participant(
					$snapshot,
					'target',
					$target_after_state,
					$target_item_ids,
					$target_tax_item_ids,
					$write_boundary,
					$sub_checkpoint
				);
				self::event('after_target_cleanup', $source, $target, $operation_id);
			}
			self::lease_guard($lease);
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
			self::lease_guard($lease);
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
			for ($attempt = 1; $attempt <= 2; $attempt++) {
				try {
					self::event('before_manual_participation_attempt_' . $attempt, $source, $target, $operation_id);
					WCOS_Merge_Participation::persist($source, $target, $operation_id, $pair['pair_fingerprint']);
					break;
				} catch (Throwable $throwable) {
					/* A bounded retry closes response-loss and one-shot persistence windows. */
				}
			}
			foreach (array('source' => $source, 'target' => $target) as $role => $participant) {
				try {
					self::event('before_manual_' . $role . '_blocker', $source, $target, $operation_id);
					WCOS_Manual_Reconciliation_Blocker::block_participant(
						$participant,
						$source,
						$operation_id,
						$role,
						'source' === $role ? $target->get_id() : $source->get_id(),
						$pair['pair_fingerprint']
					);
				} catch (Throwable $throwable) {
					/* Verified participation may remain this participant's local authority. */
				}
			}
			$source_active = in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($source), true);
			$target_active = in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($target), true);
			if (!$source_active || !$target_active) {
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
		$recovery_state = WCOS_Merge_Recovery_State_Graph::assert_record($record);
		self::boundary($lease, $source, $target, $record, $source_after, $target_after, 'any', 'before_forward_relations');
		WCOS_Merge_Participation::persist($source, $target, $operation_id, $pair['pair_fingerprint']);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		self::lease_guard($lease);
		if (!in_array($recovery_state, array(WCOS_Merge_Recovery_State_Graph::VERIFIED, WCOS_Merge_Recovery_State_Graph::COMMITTED), true)
			&& !WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_relations_completed', array(
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
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$snapshot = isset($context['merge_recovery_snapshot']) && is_array($context['merge_recovery_snapshot']) ? $context['merge_recovery_snapshot'] : array();
		WCOS_Merge_Recovery_Snapshot::assert_archive_preserved($snapshot, $source);
		WCOS_Merge_Recovery_Snapshot::assert_active_economic_conserved($snapshot, $target);
		if (!in_array($recovery_state, array(WCOS_Merge_Recovery_State_Graph::VERIFIED, WCOS_Merge_Recovery_State_Graph::COMMITTED), true)
			&& !WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_commercial_verified', array(
			'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::VERIFIED,
		))) {
			throw new RuntimeException(__('Forward-repaired Merge state could not be verified.', 'wc-order-splitter'));
		}
		if (WCOS_Merge_Recovery_State_Graph::COMMITTED !== $recovery_state) {
			self::event('after_verification_before_commit', $source, $target, $operation_id);
			self::lease_guard($lease);
		}
		$journal_status = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
		$commit_checkpoint_required = WCOS_Merge_Recovery_State_Graph::COMMITTED !== $recovery_state || 'committed' !== $journal_status;
		if ($commit_checkpoint_required && !WCOS_Operation_Journal::mark_committed($source, $operation_id, array(
				'merge_forward_repaired' => true,
				'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::COMMITTED,
			))) {
			throw new RuntimeException(__('Forward-repaired Merge state could not be committed.', 'wc-order-splitter'));
		}
		self::event('after_commit_before_complete', $source, $target, $operation_id);
		self::lease_guard($lease);
		if (!WCOS_Operation_Journal::complete($source, $operation_id, array(
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

	private static function lease_guard(WCOS_Multi_Order_Lease $lease) {
		if (!$lease->refresh()) {
			throw new RuntimeException(__('A participant lease expired before a durable Merge recovery write.', 'wc-order-splitter'));
		}
		WCOS_Stock_Side_Effect_Guard::assert_current_clean();
	}

	private static function resumable_callbacks(WCOS_Multi_Order_Lease $lease, WC_Order $source, WC_Order $target, array $record, array $snapshot, array $source_after, array $target_after, array $target_item_ids, array $target_tax_item_ids) {
		$operation_id = sanitize_key((string) $record['operation_id']);
		$guard = static function($stage, $component_id = 0) use ($lease, $source, $target, $operation_id, $snapshot, $source_after, $target_after, $target_item_ids, $target_tax_item_ids) {
			WCOS_Merge_Compensator::event($stage, $source, $target, $operation_id);
			if (!$lease->refresh()) {
				throw new RuntimeException(__('A participant lease expired during resumable Merge recovery.', 'wc-order-splitter'));
			}
			WCOS_Stock_Side_Effect_Guard::assert_current_clean();
			$fresh_source = wc_get_order($snapshot['source_order_id']);
			$fresh_target = wc_get_order($snapshot['target_order_id']);
			if (!$fresh_source instanceof WC_Order || !$fresh_target instanceof WC_Order) {
				throw new RuntimeException(__('A Merge recovery participant disappeared during a durable write.', 'wc-order-splitter'));
			}
			WCOS_Merge_Recovery_Snapshot::assert_resumable_participant($snapshot['source'], $source_after, $fresh_source, array(), array());
			WCOS_Merge_Recovery_Snapshot::assert_resumable_participant($snapshot['target'], $target_after, $fresh_target, $target_item_ids, $target_tax_item_ids);
		};
		$checkpoint = static function($stage, $component_id = 0) use ($guard, $source, $operation_id) {
			$guard('before_' . $stage . '_checkpoint', $component_id);
			if (!WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_recovery_component_checkpoint', array(
				'merge_recovery_component' => array('stage' => sanitize_key($stage), 'component_id' => absint($component_id)),
			))) {
				throw new RuntimeException(__('A resumable Merge component checkpoint could not be persisted.', 'wc-order-splitter'));
			}
		};
		return array($guard, $checkpoint);
	}

	public static function event($stage, WC_Order $source, WC_Order $target, $operation_id) {
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
