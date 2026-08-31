<?php

defined('ABSPATH') || exit;

final class WCOS_Bulk_Return_Orchestrator_Exception extends RuntimeException {
	private $reason;
	private $retryable;

	public function __construct($reason, $message, $retryable = false) {
		$this->reason = sanitize_key((string) $reason);
		$this->retryable = (bool) $retryable;
		parent::__construct((string) $message);
	}

	public function get_reason() { return $this->reason; }
	public function is_retryable() { return $this->retryable; }
}

/** Advances at most one ordinary child Return per authenticated request. */
final class WCOS_Bulk_Return_Orchestrator {
	public function advance($batch_id, $anchor_child_id, $raw_token, $user_id, $expected_cursor) {
		try {
			$confirmation = WCOS_Bulk_Return_Confirmation_Store::verify($batch_id, $anchor_child_id, $raw_token, $user_id);
		} catch (WCOS_Bulk_Return_Confirmation_Exception $exception) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception($exception->get_reason(), $exception->getMessage(), false);
		}
		$verified = $confirmation['verified'];
		$progress = $verified['progress'];
		if ('in_progress' !== $progress['status']) {
			return $this->terminal_summary($confirmation);
		}
		$expected_cursor = (int) $expected_cursor;
		if ($expected_cursor >= 0 && $expected_cursor < (int) $progress['cursor']) {
			/* Exact response-loss replay: report durable progress without starting N+1. */
			return WCOS_Bulk_Return_Journal_Context::public_summary($verified);
		}
		if ($expected_cursor < 0 || $expected_cursor !== (int) $progress['cursor']) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('stale_cursor', __('Bulk Return progress changed. Resume from the durable current row.', 'wc-order-splitter'), true);
		}

		$ordinal = (int) $progress['cursor'];
		$plan = $verified['authority']['plan'];
		$execution_rows = WCOS_Bulk_Return_Batch_Plan::execution_rows($plan);
		if (!isset($execution_rows[$ordinal], $verified['authority']['operation_map'][$ordinal])) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_corrupt', __('Bulk Return coordinator does not contain the current row.', 'wc-order-splitter'), false);
		}
		$row = $execution_rows[$ordinal];
		$mapping = $verified['authority']['operation_map'][$ordinal];
		$operation_id = sanitize_key((string) $mapping['operation_id']);
		$child = wc_get_order(absint($row['child_order_id']));
		if (!$child instanceof WC_Order) {
			return $this->stop_batch($confirmation, $ordinal, $operation_id, 'participant_missing');
		}
		$child_journal = WCOS_Operation_Journal::get($child, $operation_id);
		if (is_array($child_journal)) {
			return $this->resume_child($confirmation, $child, $ordinal, $operation_id, $user_id);
		}
		if (time() > (int) $verified['authority']['start_next_row_deadline']) {
			return $this->stop_batch($confirmation, $ordinal, $operation_id, 'start_next_row_deadline_expired');
		}

		$flat_map = array();
		foreach ($verified['authority']['operation_map'] as $map_ordinal => $entry) {
			$flat_map[$map_ordinal] = $entry['operation_id'];
		}
		try {
			$authority = WCOS_Bulk_Return_Batch_Plan::derive_current_authority($plan, $ordinal, $flat_map);
			$handoff = WCOS_Return_Confirmation_Store::create_for_batch($child, $authority, $user_id, $operation_id);
		} catch (WCOS_Bulk_Return_Batch_Exception $exception) {
			return $this->stop_batch($confirmation, $ordinal, $operation_id, $exception->get_reason());
		} catch (WCOS_Return_Confirmation_Exception $exception) {
			return $this->stop_batch($confirmation, $ordinal, $operation_id, $exception->get_reason());
		} catch (Throwable $throwable) {
			return $this->stop_batch($confirmation, $ordinal, $operation_id, 'authority_derivation_failed');
		}

		try {
			$result = (new WCOS_Mutation_Gateway())->return_order(
				$child,
				$operation_id,
				$handoff['price_precision'],
				WCOS_Return_Confirmation_Store::operation_authority($handoff)
			);
			if ('completed' !== sanitize_key(isset($result['status']) ? (string) $result['status'] : 'completed')) {
				throw new RuntimeException(__('The child Return did not produce a completed terminal result.', 'wc-order-splitter'));
			}
			return $this->complete_row($confirmation, $ordinal, $operation_id, $result);
		} catch (Throwable $throwable) {
			$fresh_child = wc_get_order($child->get_id());
			$journal = $fresh_child instanceof WC_Order ? WCOS_Operation_Journal::get($fresh_child, $operation_id) : null;
			if (is_array($journal)) {
				return $this->resume_child($confirmation, $fresh_child, $ordinal, $operation_id, $user_id);
			}
			throw new WCOS_Bulk_Return_Orchestrator_Exception('current_row_retryable', __('The current Bulk Return row did not start and may be retried with the same authority.', 'wc-order-splitter'), true);
		}
	}

	public function resume($batch_id, $anchor_child_id, $raw_token, $user_id) {
		try {
			$confirmation = WCOS_Bulk_Return_Confirmation_Store::verify($batch_id, $anchor_child_id, $raw_token, $user_id);
			return 'in_progress' === $confirmation['verified']['progress']['status']
				? WCOS_Bulk_Return_Journal_Context::public_summary($confirmation['verified'])
				: $this->terminal_summary($confirmation);
		} catch (WCOS_Bulk_Return_Confirmation_Exception $exception) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception($exception->get_reason(), $exception->getMessage(), false);
		}
	}

	private function resume_child(array $confirmation, WC_Order $child, $ordinal, $operation_id, $user_id) {
		try {
			WCOS_Return_Confirmation_Store::replay_for_batch($child, $operation_id, $user_id);
		} catch (WCOS_Return_Confirmation_Exception $exception) {
			/* Inspect the authoritative journal below; its terminal state wins. */
		}
		$fresh_child = wc_get_order($child->get_id());
		$journal = $fresh_child instanceof WC_Order ? WCOS_Operation_Journal::get($fresh_child, $operation_id) : null;
		if (!is_array($journal)) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('child_journal_missing', __('The current Return journal disappeared during replay.', 'wc-order-splitter'), false);
		}
		$status = sanitize_key(isset($journal['status']) ? (string) $journal['status'] : '');
		if ('completed' === $status) {
			try {
				$result = WCOS_Return_Journal_Context::terminal_result_from_record($journal);
			} catch (Throwable $throwable) {
				return $this->stop_batch($confirmation, $ordinal, $operation_id, 'completed_child_authority_invalid');
			}
			return $this->complete_row($confirmation, $ordinal, $operation_id, $result);
		}
		if (in_array($status, array('compensated', 'manual_reconciliation', 'manual_reconciled'), true)) {
			return $this->stop_batch($confirmation, $ordinal, $operation_id, $status);
		}
		if (in_array($status, array('started', 'failed', 'recovery_required', 'recovering', 'committed', 'compensating'), true)) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('current_row_recovery_pending', __('The current child Return remains under ordinary Return recovery. Retry this same row.', 'wc-order-splitter'), true);
		}
		return $this->stop_batch($confirmation, $ordinal, $operation_id, 'operation_closed');
	}

	private function complete_row(array $confirmation, $ordinal, $operation_id, array $result) {
		$fresh = $this->fresh_confirmation($confirmation);
		$verified = $fresh['verified'];
		$current_cursor = (int) $verified['progress']['cursor'];
		if ($current_cursor > (int) $ordinal) {
			return 'in_progress' === $verified['progress']['status']
				? WCOS_Bulk_Return_Journal_Context::public_summary($verified)
				: $this->terminal_summary($fresh);
		}
		if ($current_cursor !== (int) $ordinal) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_cursor_invalid', __('Bulk Return coordinator cursor cannot be reconstructed safely.', 'wc-order-splitter'), false);
		}
		$execution_rows = WCOS_Bulk_Return_Batch_Plan::execution_rows($verified['authority']['plan']);
		$row = $execution_rows[$ordinal];
		$results = $verified['progress']['results'];
		$results[] = array(
			'ordinal' => (int) $ordinal,
			'child_order_id' => absint($row['child_order_id']),
			'original_order_id' => absint($row['original_order_id']),
			'operation_id' => sanitize_key((string) $operation_id),
			'status' => 'completed',
			'reason' => 'completed',
		);
		$next = (int) $ordinal + 1;
		$status = $next >= (int) $verified['progress']['total'] ? 'completed' : 'in_progress';
		$progress = WCOS_Bulk_Return_Journal_Context::progress($verified['progress'], $next, $status, $results, '', $verified['authority']['authority_fingerprint']);
		return $this->persist_progress($fresh, $progress, 'completed' === $status);
	}

	private function stop_batch(array $confirmation, $ordinal, $operation_id, $reason) {
		$fresh = $this->fresh_confirmation($confirmation);
		$verified = $fresh['verified'];
		if ('in_progress' !== $verified['progress']['status']) {
			return $this->terminal_summary($fresh);
		}
		$cursor = (int) $verified['progress']['cursor'];
		if ($cursor > (int) $ordinal) {
			return WCOS_Bulk_Return_Journal_Context::public_summary($verified);
		}
		$results = $verified['progress']['results'];
		$total = (int) $verified['progress']['total'];
		$current_status = in_array(sanitize_key((string) $reason), array('manual_reconciliation', 'manual_reconciled'), true)
			? 'manual_reconciliation'
			: 'blocked';
		$execution_rows = WCOS_Bulk_Return_Batch_Plan::execution_rows($verified['authority']['plan']);
		for ($index = $cursor; $index < $total; $index++) {
			$row = $execution_rows[$index];
			$mapping = $verified['authority']['operation_map'][$index];
			$results[] = array(
				'ordinal' => $index,
				'child_order_id' => absint($row['child_order_id']),
				'original_order_id' => absint($row['original_order_id']),
				'operation_id' => sanitize_key((string) $mapping['operation_id']),
				'status' => $index === $cursor ? $current_status : 'not_run_blocked',
				'reason' => $index === $cursor ? sanitize_key((string) $reason) : 'prior_row_non_success',
			);
		}
		$progress = WCOS_Bulk_Return_Journal_Context::progress($verified['progress'], $total, 'blocked', $results, $reason, $verified['authority']['authority_fingerprint']);
		return $this->persist_progress($fresh, $progress, true);
	}

	private function persist_progress(array $confirmation, array $progress, $terminal) {
		$anchor = wc_get_order($confirmation['anchor']->get_id());
		if (!$anchor instanceof WC_Order) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_anchor_missing', __('Bulk Return coordinator anchor is unavailable.', 'wc-order-splitter'), false);
		}
		$batch_id = $confirmation['verified']['authority']['batch_id'];
		if ($terminal) {
			if (!WCOS_Operation_Journal::mark_committed($anchor, $batch_id, array('bulk_return_progress' => $progress))
				|| !WCOS_Operation_Journal::complete($anchor, $batch_id, array('bulk_return_progress' => $progress))) {
				throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_checkpoint_failed', __('Bulk Return terminal progress could not be persisted.', 'wc-order-splitter'), true);
			}
		} elseif (!WCOS_Operation_Journal::checkpoint($anchor, $batch_id, 'bulk_return_row_completed', array('bulk_return_progress' => $progress))) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_checkpoint_failed', __('Bulk Return progress could not be persisted.', 'wc-order-splitter'), true);
		}
		$fresh_anchor = wc_get_order($anchor->get_id());
		if (!$fresh_anchor instanceof WC_Order) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_anchor_missing', __('Bulk Return coordinator anchor is unavailable after progress persistence.', 'wc-order-splitter'), false);
		}
		$record = WCOS_Operation_Journal::get($fresh_anchor, $batch_id);
		if (!is_array($record)) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_missing', __('The durable Bulk Return coordinator is unavailable after progress persistence.', 'wc-order-splitter'), false);
		}
		$verified = WCOS_Bulk_Return_Journal_Context::assert_record($record);
		return WCOS_Bulk_Return_Journal_Context::public_summary($verified);
	}

	private function fresh_confirmation(array $confirmation) {
		$authority = $confirmation['verified']['authority'];
		$anchor = wc_get_order(absint($authority['anchor_child_id']));
		$record = $anchor instanceof WC_Order ? WCOS_Operation_Journal::get($anchor, $authority['batch_id']) : null;
		if (!$anchor instanceof WC_Order || !is_array($record)) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_missing', __('The durable Bulk Return coordinator is unavailable.', 'wc-order-splitter'), false);
		}
		return array('anchor' => $anchor, 'record' => $record, 'verified' => WCOS_Bulk_Return_Journal_Context::assert_record($record));
	}

	/** Finish the coordinator-only committed→completed crash window without rerunning a child. */
	private function terminal_summary(array $confirmation) {
		$status = sanitize_key(isset($confirmation['record']['status']) ? (string) $confirmation['record']['status'] : '');
		if ('completed' === $status) {
			return WCOS_Bulk_Return_Journal_Context::public_summary($confirmation['verified']);
		}
		if ('committed' !== $status
			|| !WCOS_Operation_Journal::complete(
				$confirmation['anchor'],
				$confirmation['verified']['authority']['batch_id'],
				array('bulk_return_progress' => $confirmation['verified']['progress'])
			)) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_completion_pending', __('Bulk Return is terminal but its coordinator completion checkpoint must be retried.', 'wc-order-splitter'), true);
		}
		$fresh = $this->fresh_confirmation($confirmation);
		if ('completed' !== sanitize_key((string) $fresh['record']['status'])) {
			throw new WCOS_Bulk_Return_Orchestrator_Exception('coordinator_completion_pending', __('Bulk Return coordinator completion is not yet durable.', 'wc-order-splitter'), true);
		}
		return WCOS_Bulk_Return_Journal_Context::public_summary($fresh['verified']);
	}
}
