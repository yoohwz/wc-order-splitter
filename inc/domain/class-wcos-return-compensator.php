<?php

defined('ABSPATH') || exit;

final class WCOS_Return_Recovery_Interruption_Exception extends RuntimeException {}

/** Recovery-only Return pair compensator; never a commercial Return service. */
final class WCOS_Return_Compensator {
	public static function recover(WC_Order $child, WC_Order $original, array $record, WCOS_Multi_Order_Lease $lease) {
		$operation_id = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		$pair = WCOS_Return_Journal_Context::pair_from_record($record);
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$snapshot = isset($context['return_recovery_snapshot']) && is_array($context['return_recovery_snapshot']) ? $context['return_recovery_snapshot'] : array();
		$plan = isset($context['return_plan']) && is_array($context['return_plan']) ? $context['return_plan'] : array();
		if ('' === $operation_id || !is_array($pair) || empty($snapshot) || empty($plan)) {
			throw new RuntimeException(__('Return recovery authority is incomplete.', 'wc-order-splitter'));
		}
		WCOS_Return_Recovery_Snapshot::assert_valid($snapshot, $record);
		$state = WCOS_Return_Recovery_State_Graph::assert_record($record);
		if (!empty($context['return_physical_stock_after_write'])) {
			return self::manual_reconciliation($child, $original, $record, 'physical_stock_after_write');
		}
		$child_after = isset($context['return_child_state_after']) && is_array($context['return_child_state_after']) ? $context['return_child_state_after'] : array();
		$original_after = isset($context['return_original_state_after']) && is_array($context['return_original_state_after']) ? $context['return_original_state_after'] : array();
		$added_ids = self::ids(isset($context['return_original_added_item_ids']) ? (array) $context['return_original_added_item_ids'] : array());
		if (WCOS_Return_Recovery_State_Graph::PREPARED === $state && empty($child_after) && empty($original_after)) {
			$child_after = $snapshot['child'];
			$original_after = $snapshot['original'];
		}

		try {
			WCOS_Return_Recovery_Snapshot::assert_physical_stock_unchanged($snapshot, $child, $plan);
			$forward_allowed = !empty($context['return_forward_repair_allowed'])
				&& in_array($state, array(
					WCOS_Return_Recovery_State_Graph::CHILD_RETIRED,
					WCOS_Return_Recovery_State_Graph::CHILD_RELATION_PARTIAL,
					WCOS_Return_Recovery_State_Graph::CHILD_RELATION,
					WCOS_Return_Recovery_State_Graph::ACTIVE_SPLIT_CLEANED,
					WCOS_Return_Recovery_State_Graph::RELATIONS_COMPLETE,
					WCOS_Return_Recovery_State_Graph::VERIFIED,
					WCOS_Return_Recovery_State_Graph::COMMITTED,
				), true) && !empty($child_after) && !empty($original_after);
			if ($forward_allowed) {
				try {
					WCOS_Return_Recovery_Snapshot::assert_exact_checkpoint($child_after, $child);
					WCOS_Return_Recovery_Snapshot::assert_exact_checkpoint($original_after, $original, $added_ids);
					return self::forward($child, $original, $record, $lease, $snapshot, $plan, $added_ids);
				} catch (WCOS_Return_Recovery_Interruption_Exception $exception) {
					throw $exception;
				} catch (Throwable $throwable) {
					/* Only exact forward checkpoints may continue; otherwise compensate. */
				}
			}
			if (empty($child_after) || empty($original_after)) {
				throw new RuntimeException(__('Return recovery lacks durable participant checkpoints.', 'wc-order-splitter'));
			}
			WCOS_Return_Recovery_Snapshot::assert_resumable($snapshot['child'], $child_after, $child);
			WCOS_Return_Recovery_Snapshot::assert_resumable($snapshot['original'], $original_after, $original, $added_ids);
			if ('compensating' !== sanitize_key(isset($record['status']) ? (string) $record['status'] : '')) {
				self::lease_guard($lease);
				if (!WCOS_Operation_Journal::mark_compensating($child, $operation_id, array(
					'return_compensation' => true,
					'return_recovery_state' => WCOS_Return_Recovery_State_Graph::COMPENSATING,
				))) {
					throw new RuntimeException(__('Return compensation could not be checkpointed.', 'wc-order-splitter'));
				}
			}

			/* Reverse the forward zero-owner sequence: clear original first, then restore child. */
			list($boundary, $checkpoint) = self::callbacks($lease, $child, $original, $record, $snapshot, $child_after, $original_after, $added_ids);
			$original = WCOS_Return_Recovery_Snapshot::restore_participant($snapshot, 'original', $original_after, $added_ids, $boundary, $checkpoint);
			self::event('after_original_restore', $child, $original, $operation_id);
			self::lease_guard($lease);
			if (!WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_compensation_original_restored', array(
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::ORIGINAL_RESTORED,
			))) {
				throw new RuntimeException(__('Restored Return original could not be checkpointed.', 'wc-order-splitter'));
			}
			$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
			list($boundary, $checkpoint) = self::callbacks($lease, $child, $original, $record, $snapshot, $child_after, $original_after, $added_ids);
			$child = WCOS_Return_Recovery_Snapshot::restore_participant($snapshot, 'child', $child_after, array(), $boundary, $checkpoint);
			self::event('after_child_restore', $child, $original, $operation_id);
			self::lease_guard($lease);
			if (!WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_compensation_child_restored', array(
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::CHILD_RESTORED,
			))) {
				throw new RuntimeException(__('Restored Return child could not be checkpointed.', 'wc-order-splitter'));
			}
			$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
			if (!WCOS_Return_Participation::cleanup($child, $original, $operation_id)) {
				throw new RuntimeException(__('Operation-owned Return participation could not be cleaned up.', 'wc-order-splitter'));
			}
			if (!WCOS_Operation_Journal::mark_compensated($child, $operation_id, array(
				'return_compensated' => true,
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::COMPENSATED,
			))) {
				throw new RuntimeException(__('Return compensation could not be finalized.', 'wc-order-splitter'));
			}
			return 'compensated';
		} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
			if (WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($exception->get_events())) {
				return self::manual_reconciliation($child, $original, $record, 'physical_stock_after_write');
			}
			throw $exception;
		} catch (WCOS_Return_Recovery_Interruption_Exception $exception) {
			throw $exception;
		} catch (Throwable $throwable) {
			return self::manual_reconciliation($child, $original, $record, 'return_recovery_divergence');
		}
	}

	public static function manual_reconciliation(WC_Order $child, $original, array $record, $reason) {
		$operation_id = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		$pair = WCOS_Return_Journal_Context::pair_from_record($record);
		$reason = sanitize_key((string) $reason);
		if ('' === $reason) { $reason = 'return_recovery_ambiguous'; }
		if (is_array($pair) && $original instanceof WC_Order) {
			for ($attempt = 1; $attempt <= 2; $attempt++) {
				try { WCOS_Return_Participation::persist($child, $original, $operation_id, $pair['pair_fingerprint']); break; }
				catch (Throwable $throwable) { /* bounded retry */ }
			}
			$child_blocked = WCOS_Manual_Reconciliation_Blocker::block_participant($child, $child, $operation_id, 'source', $original->get_id(), $pair['pair_fingerprint']);
			$original_blocked = WCOS_Manual_Reconciliation_Blocker::block_participant($original, $child, $operation_id, 'target', $child->get_id(), $pair['pair_fingerprint']);
			if (!$child_blocked || !$original_blocked) {
				throw new RuntimeException(__('Pair-wide Return reconciliation authority could not be persisted.', 'wc-order-splitter'));
			}
		} elseif (!WCOS_Manual_Reconciliation_Blocker::block($child, $operation_id)) {
			throw new RuntimeException(__('Return reconciliation authority could not be persisted for the surviving child.', 'wc-order-splitter'));
		}
		if (!WCOS_Operation_Journal::mark_manual_reconciliation($child, $operation_id, array(
			'reason' => $reason,
			'automatic_compensation_allowed' => false,
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::MANUAL,
		))) {
			throw new RuntimeException(__('Return manual-reconciliation state could not be recorded.', 'wc-order-splitter'));
		}
		return 'manual_reconciliation';
	}

	private static function forward(WC_Order $child, WC_Order $original, array $record, WCOS_Multi_Order_Lease $lease, array $snapshot, array $plan, array $added_ids) {
		$operation_id = sanitize_key((string) $record['operation_id']);
		$pair = WCOS_Return_Journal_Context::pair_from_record($record);
		$state = WCOS_Return_Recovery_State_Graph::assert_record($record);
		if (WCOS_Return_Recovery_State_Graph::CHILD_RETIRED === $state) {
			self::boundary($lease, $child, $original, $record, $snapshot, $added_ids, 'none', 'before_forward_child_relation');
			WCOS_Return_Participation::persist($child, $original, $operation_id, $pair['pair_fingerprint']);
			$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
			$state = WCOS_Return_Recovery_State_Graph::assert_record(WCOS_Operation_Journal::get($child, $operation_id));
		}
		if (WCOS_Return_Recovery_State_Graph::CHILD_RELATION_PARTIAL === $state) {
			self::boundary($lease, $child, $original, $record, $snapshot, $added_ids, 'partial', 'before_forward_original_relation');
			WCOS_Return_Participation::persist($child, $original, $operation_id, $pair['pair_fingerprint']);
			$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
			if (!WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_child_relation_persisted', array(
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::CHILD_RELATION,
			) + self::participant_authority($snapshot, $child, $original, $added_ids))) { throw new RuntimeException(__('Forward Return child relation could not be checkpointed.', 'wc-order-splitter')); }
			$state = WCOS_Return_Recovery_State_Graph::CHILD_RELATION;
		}
		if (WCOS_Return_Recovery_State_Graph::CHILD_RELATION === $state) {
			self::boundary($lease, $child, $original, $record, $snapshot, $added_ids, 'partial', 'before_forward_active_split_cleanup');
			WCOS_Return_Participation::persist($child, $original, $operation_id, $pair['pair_fingerprint']);
			WCOS_Return_Participation::remove_active_split_relation($child, $original, $operation_id, $pair['pair_fingerprint']);
			$child = wc_get_order($child->get_id()); $original = wc_get_order($original->get_id());
			if (!WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_active_split_relation_cleaned', array(
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::ACTIVE_SPLIT_CLEANED,
			) + self::participant_authority($snapshot, $child, $original, $added_ids))) { throw new RuntimeException(__('Forward Return active Split cleanup could not be checkpointed.', 'wc-order-splitter')); }
			$state = WCOS_Return_Recovery_State_Graph::ACTIVE_SPLIT_CLEANED;
		}
		if (WCOS_Return_Recovery_State_Graph::ACTIVE_SPLIT_CLEANED === $state) {
			self::boundary($lease, $child, $original, $record, $snapshot, $added_ids, 'complete', 'before_forward_relations_complete');
			if (!WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_relations_completed', array(
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::RELATIONS_COMPLETE,
			) + self::participant_authority($snapshot, $child, $original, $added_ids))) { throw new RuntimeException(__('Forward Return relations could not be checkpointed.', 'wc-order-splitter')); }
			$state = WCOS_Return_Recovery_State_Graph::RELATIONS_COMPLETE;
		}
		self::boundary($lease, $child, $original, $record, $snapshot, $added_ids, 'complete', 'after_forward_relations');
		WCOS_Return_Recovery_Snapshot::assert_physical_stock_unchanged($snapshot, $child, $plan);
		WCOS_Return_Recovery_Snapshot::assert_success_contract($snapshot, $child, $original, $plan);
		$destinations = isset($record['context']['return_destination_item_ids']) ? (array) $record['context']['return_destination_item_ids'] : array();
		WCOS_Return_Recovery_Snapshot::assert_single_operational_owner($snapshot, $child, $original, $destinations);
		if (WCOS_Return_Recovery_State_Graph::RELATIONS_COMPLETE === $state) {
			if (!WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_pair_verified', array(
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::VERIFIED,
			))) { throw new RuntimeException(__('Forward Return pair verification could not be checkpointed.', 'wc-order-splitter')); }
			$state = WCOS_Return_Recovery_State_Graph::VERIFIED;
			self::event('after_pair_verification', $child, $original, $operation_id);
		}
		$child_signature = WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'child', $child);
		$original_signature = WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'original', $original, $added_ids);
		if (in_array($state, array(WCOS_Return_Recovery_State_Graph::VERIFIED, WCOS_Return_Recovery_State_Graph::COMMITTED), true)) {
			if (!WCOS_Operation_Journal::mark_committed($child, $operation_id, array(
				'return_forward_repaired' => true,
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::COMMITTED,
				'return_child_signature_after' => $child_signature,
				'return_original_signature_after' => $original_signature,
				'return_child_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child),
				'return_original_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original, $added_ids),
			))) { throw new RuntimeException(__('Forward Return state could not be committed.', 'wc-order-splitter')); }
			self::event('after_commit_before_complete', $child, $original, $operation_id);
		}
		$committed = WCOS_Operation_Journal::get(wc_get_order($child->get_id()), $operation_id);
		if (!WCOS_Operation_Journal::complete($child, $operation_id, array(
			'return_verified' => true,
			'return_recovery_state' => WCOS_Return_Recovery_State_Graph::COMPLETED,
			'return_terminal_result' => WCOS_Return_Journal_Context::create_terminal_result($committed, $child, $original),
		))) { throw new RuntimeException(__('Forward Return state could not be completed.', 'wc-order-splitter')); }
		return 'completed';
	}

	private static function boundary(WCOS_Multi_Order_Lease $lease, WC_Order $child, WC_Order $original, array $record, array $snapshot, array $added_ids, $relation, $stage) {
		self::event($stage, $child, $original, $record['operation_id']);
		WCOS_Return_Commit_Guard::assert_boundary(
			$lease, $child, $original, $record,
			WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'child', $child),
			WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'original', $original, $added_ids),
			$added_ids, $relation
		);
	}

	private static function callbacks(WCOS_Multi_Order_Lease $lease, WC_Order $child, WC_Order $original, array $record, array $snapshot, array $child_after, array $original_after, array $added_ids) {
		$guard = static function($stage, $component_id = 0) use ($lease, $child, $original, $record, $snapshot, $child_after, $original_after, $added_ids) {
			WCOS_Return_Compensator::event($stage, $child, $original, $record['operation_id']);
			if (!$lease->refresh()) { throw new RuntimeException(__('A Return participant lease expired during recovery.', 'wc-order-splitter')); }
			WCOS_Stock_Side_Effect_Guard::assert_current_clean();
			$fresh_child = wc_get_order($snapshot['child_order_id']); $fresh_original = wc_get_order($snapshot['original_order_id']);
			WCOS_Return_Recovery_Snapshot::assert_resumable($snapshot['child'], $child_after, $fresh_child);
			WCOS_Return_Recovery_Snapshot::assert_resumable($snapshot['original'], $original_after, $fresh_original, $added_ids);
		};
		$checkpoint = static function($stage, $component_id = 0) use ($guard, $child, $record) {
			$guard('before_' . $stage . '_checkpoint', $component_id);
			if (!WCOS_Operation_Journal::checkpoint($child, $record['operation_id'], 'return_recovery_component_checkpoint', array(
				'return_recovery_component' => array('stage' => sanitize_key($stage), 'component_id' => absint($component_id)),
			))) { throw new RuntimeException(__('A resumable Return component checkpoint could not be persisted.', 'wc-order-splitter')); }
		};
		return array($guard, $checkpoint);
	}

	private static function lease_guard(WCOS_Multi_Order_Lease $lease) {
		if (!$lease->refresh()) { throw new RuntimeException(__('A participant lease expired before a Return recovery write.', 'wc-order-splitter')); }
		WCOS_Stock_Side_Effect_Guard::assert_current_clean();
	}

	private static function participant_authority(array $snapshot, WC_Order $child, WC_Order $original, array $added_ids) {
		return array(
			'return_child_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child),
			'return_original_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original, $added_ids),
			'return_child_signature_after' => WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'child', $child),
			'return_original_signature_after' => WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'original', $original, $added_ids),
		);
	}

	public static function event($stage, WC_Order $child, WC_Order $original, $operation_id) {
		do_action('wcos_return_recovery_checkpoint', sanitize_key((string) $stage), $child, $original, sanitize_key((string) $operation_id));
	}

	private static function ids(array $ids) { $ids = array_values(array_unique(array_filter(array_map('absint', $ids)))); sort($ids, SORT_NUMERIC); return $ids; }
}
