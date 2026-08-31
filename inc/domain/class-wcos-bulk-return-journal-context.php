<?php

defined('ABSPATH') || exit;

/** Self-verifying coordination-only context stored in WCOS_Operation_Journal. */
final class WCOS_Bulk_Return_Journal_Context {
	const SCHEMA_VERSION = 2;
	const POLICY_VERSION = 2;
	const LEGACY_SCHEMA_VERSION = 1;
	const LEGACY_POLICY_VERSION = 1;
	const TYPE = 'bulk_return_batch';
	const DEADLINE_SECONDS = 1800;

	public static function create(array $plan, $batch_id, $user_id, $raw_token, array $operation_map, $now = null) {
		WCOS_Bulk_Return_Batch_Plan::assert_valid($plan);
		$schema_version = WCOS_Bulk_Return_Batch_Plan::is_v2($plan) ? self::SCHEMA_VERSION : self::LEGACY_SCHEMA_VERSION;
		$policy_version = self::SCHEMA_VERSION === $schema_version ? self::POLICY_VERSION : self::LEGACY_POLICY_VERSION;
		$batch_id = sanitize_key((string) $batch_id);
		$user_id = absint($user_id);
		$now = null === $now ? time() : (int) $now;
		$anchor_id = WCOS_Bulk_Return_Batch_Plan::anchor_child_id($plan);
		$mapping = self::canonical_mapping($operation_map, $plan);
		if (!self::is_uuid($batch_id) || !$user_id || '' === (string) $raw_token || !$anchor_id) {
			throw new InvalidArgumentException(__('Bulk Return coordinator identity is incomplete.', 'wc-order-splitter'));
		}
		$authority = array(
			'schema_version' => $schema_version,
			'policy_version' => $policy_version,
			'batch_id' => $batch_id,
			'batch_fingerprint' => sanitize_key((string) $plan['batch_fingerprint']),
			'operator_user_id' => $user_id,
			'anchor_child_id' => $anchor_id,
			'plan' => $plan,
			'operation_map' => $mapping,
			'token_hash' => self::token_hash($raw_token),
			'confirmed_at' => $now,
			'start_next_row_deadline' => $now + self::DEADLINE_SECONDS,
		);
		$authority['authority_fingerprint'] = self::authority_fingerprint($authority);
		return array(
			'bulk_return_batch' => $authority,
			'bulk_return_progress' => self::seal_progress(
				self::initial_progress(WCOS_Bulk_Return_Batch_Plan::execution_count($plan), $schema_version),
				$authority['authority_fingerprint'],
				$schema_version
			),
		);
	}

	public static function assert_record(array $record) {
		if (self::TYPE !== sanitize_key(isset($record['type']) ? (string) $record['type'] : '')) {
			throw new RuntimeException(__('The operation journal is not a Bulk Return coordinator.', 'wc-order-splitter'));
		}
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$authority = isset($context['bulk_return_batch']) && is_array($context['bulk_return_batch']) ? $context['bulk_return_batch'] : array();
		$progress = isset($context['bulk_return_progress']) && is_array($context['bulk_return_progress']) ? $context['bulk_return_progress'] : array();
		$stored = self::hex(isset($authority['authority_fingerprint']) ? $authority['authority_fingerprint'] : '');
		$copy = $authority;
		unset($copy['authority_fingerprint']);
		$authority_keys = array_keys($authority);
		$expected_authority_keys = array('anchor_child_id', 'authority_fingerprint', 'batch_fingerprint', 'batch_id', 'confirmed_at', 'operation_map', 'operator_user_id', 'plan', 'policy_version', 'schema_version', 'start_next_row_deadline', 'token_hash');
		sort($authority_keys, SORT_STRING);
		sort($expected_authority_keys, SORT_STRING);
		$schema_version = (int) (isset($authority['schema_version']) ? $authority['schema_version'] : 0);
		$policy_version = (int) (isset($authority['policy_version']) ? $authority['policy_version'] : 0);
		$supported_version = (self::SCHEMA_VERSION === $schema_version && self::POLICY_VERSION === $policy_version)
			|| (self::LEGACY_SCHEMA_VERSION === $schema_version && self::LEGACY_POLICY_VERSION === $policy_version);
		if (!$supported_version
			|| !self::is_uuid(isset($authority['batch_id']) ? $authority['batch_id'] : '')
			|| sanitize_key((string) $record['operation_id']) !== sanitize_key((string) $authority['batch_id'])
			|| absint($record['source_order_id']) !== absint(isset($authority['anchor_child_id']) ? $authority['anchor_child_id'] : 0)
			|| $authority_keys !== $expected_authority_keys
			|| !$authority['operator_user_id'] || '' === self::hex($authority['token_hash'])
			|| '' === self::hex($authority['batch_fingerprint'])
			|| (int) $authority['confirmed_at'] <= 0
			|| (int) $authority['start_next_row_deadline'] !== (int) $authority['confirmed_at'] + self::DEADLINE_SECONDS
			|| '' === $stored || !hash_equals($stored, self::authority_fingerprint($copy))
			|| '' === self::hex(isset($record['fingerprint']) ? $record['fingerprint'] : '')
			|| !hash_equals((string) $record['fingerprint'], $stored)
			|| empty($authority['plan']) || !is_array($authority['plan'])) {
			throw new RuntimeException(__('Bulk Return coordinator authority failed integrity verification.', 'wc-order-splitter'));
		}
		WCOS_Bulk_Return_Batch_Plan::assert_valid($authority['plan']);
		$plan_schema = (int) $authority['plan']['schema_version'];
		if ($plan_schema !== $schema_version
			|| absint($authority['anchor_child_id']) !== WCOS_Bulk_Return_Batch_Plan::anchor_child_id($authority['plan'])) {
			throw new RuntimeException(__('Bulk Return coordinator and plan schema authority do not match.', 'wc-order-splitter'));
		}
		if (!hash_equals((string) $authority['batch_fingerprint'], (string) $authority['plan']['batch_fingerprint'])) {
			throw new RuntimeException(__('Bulk Return coordinator plan fingerprint changed.', 'wc-order-splitter'));
		}
		$mapping = self::canonical_mapping($authority['operation_map'], $authority['plan']);
		if ($mapping !== $authority['operation_map']) {
			throw new RuntimeException(__('Bulk Return child operation mapping is not canonical.', 'wc-order-splitter'));
		}
		self::assert_progress($progress, $authority, $stored);
		$journal_status = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
		if (('in_progress' === $progress['status'] && 'started' !== $journal_status)
			|| ('in_progress' !== $progress['status'] && !in_array($journal_status, array('committed', 'completed'), true))) {
			throw new RuntimeException(__('Bulk Return coordinator journal status does not match durable progress.', 'wc-order-splitter'));
		}
		return array('authority' => $authority, 'progress' => $progress);
	}

	public static function verify_request(array $record, $raw_token, $user_id) {
		$verified = self::assert_record($record);
		if (absint($user_id) !== absint($verified['authority']['operator_user_id'])
			|| '' === (string) $raw_token
			|| !hash_equals((string) $verified['authority']['token_hash'], self::token_hash($raw_token))) {
			throw new RuntimeException(__('Bulk Return coordinator token or operator is invalid.', 'wc-order-splitter'));
		}
		return $verified;
	}

	public static function progress(array $current, $cursor, $status, array $results, $reason, $batch_authority_fingerprint) {
		$total = (int) $current['total'];
		$schema_version = (int) (isset($current['schema_version']) ? $current['schema_version'] : 0);
		$progress = array(
			'schema_version' => $schema_version,
			'cursor' => max(0, min($total, (int) $cursor)),
			'total' => $total,
			'status' => sanitize_key((string) $status),
			'results' => array_values($results),
			'terminal_reason' => sanitize_key((string) $reason),
		);
		return self::seal_progress($progress, $batch_authority_fingerprint, $schema_version);
	}

	public static function public_summary(array $verified) {
		$progress = $verified['progress'];
		$is_v2 = WCOS_Bulk_Return_Batch_Plan::is_v2($verified['authority']['plan']);
		$counts = array('completed' => 0, 'blocked' => 0, 'manual_reconciliation' => 0, 'not_run_blocked' => 0);
		if ($is_v2) { $counts['skipped'] = (int) $verified['authority']['plan']['skipped_count']; }
		$public_results = array();
		foreach ($progress['results'] as $result) {
			$status = sanitize_key(isset($result['status']) ? (string) $result['status'] : '');
			if (isset($counts[$status])) { $counts[$status]++; }
			$public_results[] = array(
				'ordinal' => (int) $result['ordinal'],
				'child_order_id' => absint($result['child_order_id']),
				'original_order_id' => absint($result['original_order_id']),
				'status' => $status,
				'reason' => sanitize_key((string) $result['reason']),
				'message' => self::row_reason_message($result['reason']),
			);
		}
		$summary = array(
			'batch_id' => sanitize_key((string) $verified['authority']['batch_id']),
			'anchor_child_id' => absint($verified['authority']['anchor_child_id']),
			'cursor' => (int) $progress['cursor'],
			'total' => (int) $progress['total'],
			'status' => sanitize_key((string) $progress['status']),
			'terminal_reason' => sanitize_key((string) $progress['terminal_reason']),
			'counts' => $counts,
			'results' => $public_results,
			'has_more' => 'in_progress' === $progress['status'] && $progress['cursor'] < $progress['total'],
			'start_next_row_deadline' => (int) $verified['authority']['start_next_row_deadline'],
		);
		if ($is_v2) {
			$plan = $verified['authority']['plan'];
			$skipped_results = array();
			foreach ($plan['selection_rows'] as $row) {
				if (!empty($row['eligible'])) { continue; }
				$skipped_results[] = array(
					'selection_ordinal' => (int) $row['selection_ordinal'],
					'child_order_id' => absint($row['child_order_id']),
					'status' => 'skipped',
					'reason' => sanitize_key((string) $row['reason']),
					'message' => (string) $row['message'],
				);
			}
			$summary += array(
				'selected_count' => (int) $plan['selected_count'],
				'canonical_count' => (int) $plan['canonical_count'],
				'duplicate_count' => (int) $plan['duplicate_count'],
				'eligible_count' => (int) $plan['eligible_count'],
				'skipped_count' => (int) $plan['skipped_count'],
				'skipped_results' => $skipped_results,
			);
		}
		return $summary;
	}

	private static function initial_progress($total, $schema_version) {
		return array('schema_version' => (int) $schema_version, 'cursor' => 0, 'total' => absint($total), 'status' => 'in_progress', 'results' => array(), 'terminal_reason' => '');
	}

	private static function assert_progress(array $progress, array $authority, $batch_authority_fingerprint) {
		$total = WCOS_Bulk_Return_Batch_Plan::execution_count($authority['plan']);
		$schema_version = (int) $authority['schema_version'];
		$execution_rows = WCOS_Bulk_Return_Batch_Plan::execution_rows($authority['plan']);
		$status = sanitize_key(isset($progress['status']) ? (string) $progress['status'] : '');
		$cursor = isset($progress['cursor']) ? (int) $progress['cursor'] : -1;
		$results = isset($progress['results']) && is_array($progress['results']) ? array_values($progress['results']) : null;
		$stored_fingerprint = self::hex(isset($progress['progress_fingerprint']) ? $progress['progress_fingerprint'] : '');
		$keys = array_keys($progress);
		$expected_keys = array('cursor', 'progress_fingerprint', 'results', 'schema_version', 'status', 'terminal_reason', 'total');
		sort($keys, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		if ($keys !== $expected_keys || '' === $stored_fingerprint
			|| !hash_equals($stored_fingerprint, self::progress_fingerprint($progress, $batch_authority_fingerprint))
			|| $schema_version !== (int) (isset($progress['schema_version']) ? $progress['schema_version'] : 0)
			|| (int) (isset($progress['total']) ? $progress['total'] : -1) !== (int) $total
			|| $cursor < 0 || $cursor > (int) $total || !is_array($results)
			|| !in_array($status, array('in_progress', 'completed', 'blocked'), true)
			|| count($results) > (int) $total
			|| ('in_progress' === $status && ($cursor >= (int) $total || count($results) !== $cursor || '' !== (string) $progress['terminal_reason']))
			|| ('in_progress' !== $status && ($cursor !== (int) $total || count($results) !== (int) $total))
			|| ('completed' === $status && '' !== (string) $progress['terminal_reason'])
			|| ('blocked' === $status && '' === sanitize_key((string) $progress['terminal_reason']))) {
			throw new RuntimeException(__('Bulk Return coordinator progress is malformed.', 'wc-order-splitter'));
		}
		foreach ($results as $expected_ordinal => $result) {
			$result_keys = is_array($result) ? array_keys($result) : array();
			$expected_result_keys = array('child_order_id', 'operation_id', 'ordinal', 'original_order_id', 'reason', 'status');
			sort($result_keys, SORT_STRING);
			sort($expected_result_keys, SORT_STRING);
			$expected_row = isset($execution_rows[$expected_ordinal]) ? $execution_rows[$expected_ordinal] : array();
			$expected_mapping = isset($authority['operation_map'][$expected_ordinal]) ? $authority['operation_map'][$expected_ordinal] : array();
			$result_status = is_array($result) && isset($result['status']) ? sanitize_key((string) $result['status']) : '';
			$result_reason = is_array($result) && isset($result['reason']) ? sanitize_key((string) $result['reason']) : '';
			if (!is_array($result) || $result_keys !== $expected_result_keys
				|| !self::is_uuid($result['operation_id'])
				|| absint($result['child_order_id']) !== absint(isset($expected_row['child_order_id']) ? $expected_row['child_order_id'] : 0)
				|| absint($result['original_order_id']) !== absint(isset($expected_row['original_order_id']) ? $expected_row['original_order_id'] : 0)
				|| sanitize_key((string) $result['operation_id']) !== sanitize_key(isset($expected_mapping['operation_id']) ? (string) $expected_mapping['operation_id'] : '')
				|| (int) $result['ordinal'] !== (int) $expected_ordinal
				|| !in_array($result_status, array('completed', 'blocked', 'manual_reconciliation', 'not_run_blocked'), true)
				|| ('completed' === $result_status && 'completed' !== $result_reason)
				|| ('not_run_blocked' === $result_status && 'prior_row_non_success' !== $result_reason)
				|| (in_array($result_status, array('blocked', 'manual_reconciliation'), true) && '' === $result_reason)) {
				throw new RuntimeException(__('Bulk Return row result is malformed.', 'wc-order-splitter'));
			}
		}
		$non_success = array_values(array_filter($results, static function($result) {
			return 'completed' !== sanitize_key((string) $result['status']);
		}));
		if (('in_progress' === $status || 'completed' === $status) && !empty($non_success)) {
			throw new RuntimeException(__('Bulk Return success progress contains a non-success row.', 'wc-order-splitter'));
		}
		if ('blocked' === $status) {
			$first_non_success = null;
			foreach ($results as $result) {
				$result_status = sanitize_key((string) $result['status']);
				if (null === $first_non_success && 'completed' !== $result_status) { $first_non_success = $result; continue; }
				if (null !== $first_non_success && 'not_run_blocked' !== $result_status) {
					throw new RuntimeException(__('Bulk Return fail-stop result order is malformed.', 'wc-order-splitter'));
				}
			}
			if (!is_array($first_non_success)
				|| !in_array(sanitize_key((string) $first_non_success['status']), array('blocked', 'manual_reconciliation'), true)
				|| sanitize_key((string) $progress['terminal_reason']) !== sanitize_key((string) $first_non_success['reason'])) {
				throw new RuntimeException(__('Bulk Return terminal reason does not match the first non-success row.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	private static function seal_progress(array $progress, $batch_authority_fingerprint, $schema_version) {
		$progress['progress_fingerprint'] = self::progress_fingerprint($progress, $batch_authority_fingerprint, $schema_version);
		return $progress;
	}

	private static function progress_fingerprint(array $progress, $batch_authority_fingerprint, $schema_version = null) {
		unset($progress['progress_fingerprint']);
		$schema_version = null === $schema_version ? (int) (isset($progress['schema_version']) ? $progress['schema_version'] : 0) : (int) $schema_version;
		$batch_authority_fingerprint = self::hex($batch_authority_fingerprint);
		if ('' === $batch_authority_fingerprint) {
			throw new InvalidArgumentException(__('Bulk Return progress requires exact coordinator authority.', 'wc-order-splitter'));
		}
		$namespace = self::LEGACY_SCHEMA_VERSION === $schema_version ? 'bulk_return_progress_v1' : 'bulk_return_progress_v2';
		return WCOS_Mutation_Fingerprint::create($namespace, absint(isset($progress['total']) ? $progress['total'] : 0), array(
			'batch_authority_fingerprint' => $batch_authority_fingerprint,
			'progress' => self::canonicalize($progress),
		));
	}

	private static function canonical_mapping(array $operation_map, array $plan) {
		$mapping = array();
		foreach (WCOS_Bulk_Return_Batch_Plan::execution_rows($plan) as $ordinal => $row) {
			$candidate = isset($operation_map[$ordinal]) ? $operation_map[$ordinal] : '';
			$operation_id = is_array($candidate) && isset($candidate['operation_id']) ? $candidate['operation_id'] : $candidate;
			$operation_id = sanitize_key((string) $operation_id);
			if (!self::is_uuid($operation_id)) {
				throw new InvalidArgumentException(__('Every Bulk Return row requires one persisted UUIDv4 operation.', 'wc-order-splitter'));
			}
			$mapping[$ordinal] = array('ordinal' => (int) $ordinal, 'child_order_id' => absint($row['child_order_id']), 'operation_id' => $operation_id);
		}
		return $mapping;
	}

	private static function authority_fingerprint(array $authority) {
		unset($authority['authority_fingerprint']);
		$schema_version = (int) (isset($authority['schema_version']) ? $authority['schema_version'] : 0);
		$namespace = self::LEGACY_SCHEMA_VERSION === $schema_version ? 'bulk_return_coordinator_v1' : 'bulk_return_coordinator_v2';
		return WCOS_Mutation_Fingerprint::create($namespace, absint(isset($authority['anchor_child_id']) ? $authority['anchor_child_id'] : 0), self::canonicalize($authority));
	}

	private static function token_hash($token) { return hash_hmac('sha256', (string) $token, wp_salt('auth')); }
	private static function row_reason_message($reason) {
		switch (sanitize_key((string) $reason)) {
			case 'completed': return __('Completed.', 'wc-order-splitter');
			case 'prior_row_non_success': return __('Not run because an earlier row did not complete.', 'wc-order-splitter');
			case 'participant_missing': return __('A required order is unavailable.', 'wc-order-splitter');
			case 'start_next_row_deadline_expired': return __('The deadline to start the next row expired.', 'wc-order-splitter');
			case 'manual_reconciliation':
			case 'manual_reconciled': return __('The child requires manual reconciliation.', 'wc-order-splitter');
			case 'compensated': return __('The child Return was compensated and the batch stopped.', 'wc-order-splitter');
			case 'operation_closed': return __('The child Return is closed and cannot continue.', 'wc-order-splitter');
			default: return __('The row no longer matches its confirmed authority and was not started.', 'wc-order-splitter');
		}
	}
	private static function is_uuid($value) { return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value); }
	private static function hex($value) { $value = sanitize_key((string) $value); return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : ''; }
	private static function canonicalize($value) { if (!is_array($value)) { return $value; } $keys = array_keys($value); $is_list = empty($value) || $keys === range(0, count($value) - 1); if (!$is_list) { ksort($value, SORT_STRING); } foreach ($value as $key => $item) { $value[$key] = self::canonicalize($item); } return $value; }
}
