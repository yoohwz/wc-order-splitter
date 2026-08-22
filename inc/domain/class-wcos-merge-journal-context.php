<?php

defined('ABSPATH') || exit;

/**
 * PII-free pair vocabulary for the single authoritative source Merge journal.
 */
final class WCOS_Merge_Journal_Context {

	const SCHEMA_VERSION = 1;
	const POLICY_VERSION = 1;

	public static function create(WC_Order $source, WC_Order $target, array $plan, array $context_authority, array $evidence = array()) {
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		if (!$source_id || !$target_id || $source_id === $target_id) {
			throw new InvalidArgumentException(__('A Merge journal context requires two distinct persisted orders.', 'wc-order-splitter'));
		}

		$source_signature = WCOS_Order_Contract_Snapshot::source_signature($source);
		$target_signature = WCOS_Order_Contract_Snapshot::source_signature($target);
		$plan_fingerprint = WCOS_Merge_Plan::fingerprint($plan);
		$pair_fingerprint = WCOS_Mutation_Fingerprint::create(
			'merge_pair',
			$source_id,
			array(
				'target_order_id' => $target_id,
				'source_signature' => $source_signature,
				'target_signature' => $target_signature,
				'plan_fingerprint' => $plan_fingerprint,
				'policy_version' => self::POLICY_VERSION,
				'context_signature_version' => isset($context_authority['schema_version']) ? (int) $context_authority['schema_version'] : 0,
			)
		);

		return array(
			'merge_pair' => array(
				'schema_version' => self::SCHEMA_VERSION,
				'policy_version' => self::POLICY_VERSION,
				'source_order_id' => $source_id,
				'target_order_id' => $target_id,
				'source_signature' => $source_signature,
				'target_signature' => $target_signature,
				'pair_fingerprint' => $pair_fingerprint,
				'plan_fingerprint' => $plan_fingerprint,
				'context_signature_version' => isset($context_authority['schema_version']) ? (int) $context_authority['schema_version'] : 0,
				'context_authority' => $context_authority,
				'retirement_candidates' => WCOS_Merge_Retirement_Policy::identifiers(),
				'retirement_policy_selected' => false,
				'archive_source_signature_before' => isset($evidence['archive_source_signature_before'])
					? sanitize_key((string) $evidence['archive_source_signature_before'])
					: $source_signature,
				'active_ownership_before_signature' => isset($evidence['active_ownership_before_signature'])
					? sanitize_key((string) $evidence['active_ownership_before_signature'])
					: '',
				'participation_schema_version' => class_exists('WCOS_Merge_Participation') ? WCOS_Merge_Participation::SCHEMA_VERSION : 1,
			),
		);
	}

	public static function pair_from_record(array $record) {
		if ('merge' !== sanitize_key(isset($record['type']) ? (string) $record['type'] : '')) {
			return null;
		}
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$pair = isset($context['merge_pair']) && is_array($context['merge_pair']) ? $context['merge_pair'] : array();
		if ((int) (isset($pair['schema_version']) ? $pair['schema_version'] : 0) !== self::SCHEMA_VERSION) {
			return null;
		}
		$source_id = absint(isset($pair['source_order_id']) ? $pair['source_order_id'] : 0);
		$target_id = absint(isset($pair['target_order_id']) ? $pair['target_order_id'] : 0);
		$fingerprint = sanitize_key(isset($pair['pair_fingerprint']) ? (string) $pair['pair_fingerprint'] : '');
		$journal_fingerprint = sanitize_key(isset($record['fingerprint']) ? (string) $record['fingerprint'] : '');
		$source_signature = sanitize_key(isset($pair['source_signature']) ? (string) $pair['source_signature'] : '');
		$target_signature = sanitize_key(isset($pair['target_signature']) ? (string) $pair['target_signature'] : '');
		$plan_fingerprint = sanitize_key(isset($pair['plan_fingerprint']) ? (string) $pair['plan_fingerprint'] : '');
		if (!$source_id || !$target_id || $source_id === $target_id || '' === $fingerprint
			|| '' === $source_signature || '' === $target_signature || '' === $plan_fingerprint
			|| (int) (isset($pair['policy_version']) ? $pair['policy_version'] : 0) !== self::POLICY_VERSION
			|| (int) (isset($pair['context_signature_version']) ? $pair['context_signature_version'] : 0) < 1
			|| $source_id !== absint(isset($record['source_order_id']) ? $record['source_order_id'] : 0)
			|| '' === $journal_fingerprint || !hash_equals($fingerprint, $journal_fingerprint)) {
			return null;
		}
		return $pair;
	}

	public static function validates_participant(array $record, $participant_order_id, $role, $peer_order_id = 0, $pair_fingerprint = '', $operation_id = '') {
		$pair = self::pair_from_record($record);
		if (!is_array($pair)) {
			return false;
		}
		$participant_order_id = absint($participant_order_id);
		$peer_order_id = absint($peer_order_id);
		$role = sanitize_key((string) $role);
		$expected_participant = 'source' === $role ? absint($pair['source_order_id']) : absint($pair['target_order_id']);
		$expected_peer = 'source' === $role ? absint($pair['target_order_id']) : absint($pair['source_order_id']);
		if (!in_array($role, array('source', 'target'), true)
			|| $participant_order_id !== $expected_participant
			|| ($peer_order_id && $peer_order_id !== $expected_peer)) {
			return false;
		}
		$pair_fingerprint = sanitize_key((string) $pair_fingerprint);
		$operation_id = sanitize_key((string) $operation_id);
		$record_operation_id = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		return ('' === $pair_fingerprint || hash_equals((string) $pair['pair_fingerprint'], $pair_fingerprint))
			&& ('' === $operation_id || hash_equals($operation_id, $record_operation_id));
	}

	public static function is_unsafe_record(array $record) {
		if (!is_array(self::pair_from_record($record))) {
			return false;
		}
		$status = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
		return !in_array($status, array('completed', 'compensated', 'manual_reconciled'), true);
	}
}
