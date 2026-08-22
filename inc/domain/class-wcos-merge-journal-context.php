<?php

defined('ABSPATH') || exit;

/**
 * Self-verifying PII-free authority for the single source Merge journal.
 */
final class WCOS_Merge_Journal_Context {

	const SCHEMA_VERSION = 2;

	public static function create(WC_Order $source, WC_Order $target, array $plan, array $context_authority, $price_precision, array $evidence = array()) {
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		$price_precision = WCOS_Price_Precision_Scope::validate($price_precision);
		if (!$source_id || !$target_id || $source_id === $target_id) {
			throw new InvalidArgumentException(__('A Merge journal context requires two distinct persisted orders.', 'wc-order-splitter'));
		}

		$source_signature = WCOS_Order_Contract_Snapshot::source_signature($source);
		$archive_signature = WCOS_Merge_Recovery_Snapshot::archive_commercial_signature($source);
		$active_signature = WCOS_Merge_Recovery_Snapshot::active_economic_signature(array($source, $target), $price_precision, $source_id);
		$authority = array(
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'source_signature' => $source_signature,
			'target_signature' => WCOS_Order_Contract_Snapshot::source_signature($target),
			'plan_schema_version' => WCOS_Merge_Plan::SCHEMA_VERSION,
			'plan_fingerprint' => WCOS_Merge_Plan::fingerprint($plan),
			'price_precision' => $price_precision,
			'preflight_policy_version' => WCOS_Merge_Preflight::POLICY_VERSION,
			'context_signature_version' => WCOS_Merge_Context_Signature::SCHEMA_VERSION,
			'context_authority' => $context_authority,
			'context_authority_fingerprint' => WCOS_Merge_Context_Signature::authority_fingerprint($context_authority),
			'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
			'retirement_candidates' => WCOS_Merge_Retirement_Policy::identifiers(),
			'retirement_policy_selected' => false,
			'archive_source_signature_before' => isset($evidence['archive_source_signature_before'])
				? sanitize_key((string) $evidence['archive_source_signature_before'])
				: $archive_signature,
			'active_ownership_before_signature' => isset($evidence['active_ownership_before_signature'])
				? sanitize_key((string) $evidence['active_ownership_before_signature'])
				: $active_signature,
			'participation_schema_version' => WCOS_Merge_Participation::SCHEMA_VERSION,
		);
		$authority = self::canonical_authority($authority);
		if (!is_array($authority)) {
			throw new RuntimeException(__('The canonical Merge pair authority could not be constructed.', 'wc-order-splitter'));
		}
		$pair_fingerprint = self::authority_fingerprint($authority);

		return array(
			'merge_pair' => array(
				'schema_version' => self::SCHEMA_VERSION,
				'authority' => $authority,
				'pair_fingerprint' => $pair_fingerprint,
			),
		);
	}

	public static function pair_from_record(array $record) {
		if ('merge' !== sanitize_key(isset($record['type']) ? (string) $record['type'] : '')) {
			return null;
		}
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$pair = isset($context['merge_pair']) && is_array($context['merge_pair']) ? $context['merge_pair'] : array();
		if ((int) (isset($pair['schema_version']) ? $pair['schema_version'] : 0) !== self::SCHEMA_VERSION
			|| !isset($pair['authority']) || !is_array($pair['authority'])) {
			return null;
		}

		try {
			$authority = self::canonical_authority($pair['authority']);
			$context_fingerprint = is_array($authority)
				? WCOS_Merge_Context_Signature::authority_fingerprint($authority['context_authority'])
				: '';
		} catch (Throwable $throwable) {
			return null;
		}
		if (!is_array($authority) || $authority !== $pair['authority']) {
			return null;
		}
		if (!hash_equals($authority['context_authority_fingerprint'], $context_fingerprint)) {
			return null;
		}

		$computed_fingerprint = self::authority_fingerprint($authority);
		$pair_fingerprint = self::normalized_fingerprint(isset($pair['pair_fingerprint']) ? $pair['pair_fingerprint'] : '');
		$journal_fingerprint = self::normalized_fingerprint(isset($record['fingerprint']) ? $record['fingerprint'] : '');
		$record_precision = isset($context['price_precision']) ? (int) $context['price_precision'] : -1;
		if ('' === $pair_fingerprint || '' === $journal_fingerprint
			|| !hash_equals($computed_fingerprint, $pair_fingerprint)
			|| !hash_equals($computed_fingerprint, $journal_fingerprint)
			|| $authority['source_order_id'] !== absint(isset($record['source_order_id']) ? $record['source_order_id'] : 0)
			|| $authority['price_precision'] !== $record_precision) {
			return null;
		}

		$authority['pair_fingerprint'] = $computed_fingerprint;
		return $authority;
	}

	public static function validates_participant(array $record, $participant_order_id, $role, $peer_order_id = 0, $pair_fingerprint = '', $operation_id = '') {
		$pair = self::pair_from_record($record);
		if (!is_array($pair)) {
			return false;
		}
		$participant_order_id = absint($participant_order_id);
		$peer_order_id = absint($peer_order_id);
		$role = sanitize_key((string) $role);
		$expected_participant = 'source' === $role ? $pair['source_order_id'] : $pair['target_order_id'];
		$expected_peer = 'source' === $role ? $pair['target_order_id'] : $pair['source_order_id'];
		if (!in_array($role, array('source', 'target'), true)
			|| $participant_order_id !== $expected_participant
			|| ($peer_order_id && $peer_order_id !== $expected_peer)) {
			return false;
		}
		$pair_fingerprint = self::normalized_fingerprint($pair_fingerprint);
		$operation_id = sanitize_key((string) $operation_id);
		$record_operation_id = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		return ('' === $pair_fingerprint || hash_equals($pair['pair_fingerprint'], $pair_fingerprint))
			&& ('' === $operation_id || hash_equals($operation_id, $record_operation_id));
	}

	public static function is_unsafe_record(array $record) {
		if (!is_array(self::pair_from_record($record))) {
			return true;
		}
		$status = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
		return !in_array($status, array('completed', 'compensated', 'manual_reconciled'), true);
	}

	private static function authority_fingerprint(array $authority) {
		return WCOS_Mutation_Fingerprint::create('merge_pair_authority_v2', $authority['source_order_id'], $authority);
	}

	private static function canonical_authority(array $authority) {
		$expected_keys = array(
			'active_ownership_before_signature', 'archive_source_signature_before', 'context_authority',
			'context_authority_fingerprint', 'context_signature_version', 'participation_schema_version',
			'plan_fingerprint', 'plan_schema_version', 'preflight_policy_version', 'price_precision',
			'retirement_candidates', 'retirement_policy_schema_version', 'retirement_policy_selected',
			'source_order_id', 'source_signature', 'target_order_id', 'target_signature',
		);
		$actual_keys = array_keys($authority);
		sort($actual_keys, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		if ($actual_keys !== $expected_keys || !is_array($authority['retirement_candidates'])) {
			return null;
		}

		$context_authority = self::canonical_context_authority($authority['context_authority']);
		$source_id = absint($authority['source_order_id']);
		$target_id = absint($authority['target_order_id']);
		$source_signature = self::normalized_fingerprint($authority['source_signature']);
		$target_signature = self::normalized_fingerprint($authority['target_signature']);
		$plan_fingerprint = self::normalized_fingerprint($authority['plan_fingerprint']);
		$context_fingerprint = self::normalized_fingerprint($authority['context_authority_fingerprint']);
		$archive_signature = self::normalized_fingerprint($authority['archive_source_signature_before']);
		$active_signature = '' === (string) $authority['active_ownership_before_signature']
			? ''
			: self::normalized_fingerprint($authority['active_ownership_before_signature']);
		$price_precision = (int) $authority['price_precision'];
		if (!$source_id || !$target_id || $source_id === $target_id || !is_array($context_authority)
			|| '' === $source_signature || '' === $target_signature || '' === $plan_fingerprint
			|| '' === $context_fingerprint || '' === $archive_signature
			|| ('' !== (string) $authority['active_ownership_before_signature'] && '' === $active_signature)
			|| $price_precision !== WCOS_Price_Precision_Scope::validate($price_precision)
			|| (int) $authority['plan_schema_version'] !== WCOS_Merge_Plan::SCHEMA_VERSION
			|| (int) $authority['preflight_policy_version'] !== WCOS_Merge_Preflight::POLICY_VERSION
			|| (int) $authority['context_signature_version'] !== WCOS_Merge_Context_Signature::SCHEMA_VERSION
			|| (int) $authority['retirement_policy_schema_version'] !== WCOS_Merge_Retirement_Policy::SCHEMA_VERSION
			|| (int) $authority['participation_schema_version'] !== WCOS_Merge_Participation::SCHEMA_VERSION
			|| false !== (bool) $authority['retirement_policy_selected']
			|| WCOS_Merge_Retirement_Policy::identifiers() !== array_values($authority['retirement_candidates'])) {
			return null;
		}

		return array(
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'source_signature' => $source_signature,
			'target_signature' => $target_signature,
			'plan_schema_version' => WCOS_Merge_Plan::SCHEMA_VERSION,
			'plan_fingerprint' => $plan_fingerprint,
			'price_precision' => $price_precision,
			'preflight_policy_version' => WCOS_Merge_Preflight::POLICY_VERSION,
			'context_signature_version' => WCOS_Merge_Context_Signature::SCHEMA_VERSION,
			'context_authority' => $context_authority,
			'context_authority_fingerprint' => $context_fingerprint,
			'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
			'retirement_candidates' => WCOS_Merge_Retirement_Policy::identifiers(),
			'retirement_policy_selected' => false,
			'archive_source_signature_before' => $archive_signature,
			'active_ownership_before_signature' => $active_signature,
			'participation_schema_version' => WCOS_Merge_Participation::SCHEMA_VERSION,
		);
	}

	private static function canonical_context_authority($authority) {
		if (!is_array($authority)) {
			return null;
		}
		$expected_keys = array(
			'algorithm', 'billing_context_digest', 'customer_id', 'identity_digest', 'identity_type',
			'payment_context_digest', 'schema_version', 'shipping_context_digest',
		);
		$actual_keys = array_keys($authority);
		sort($actual_keys, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		$identity_type = isset($authority['identity_type']) ? sanitize_key((string) $authority['identity_type']) : '';
		$customer_id = absint(isset($authority['customer_id']) ? $authority['customer_id'] : 0);
		if ($actual_keys !== $expected_keys
			|| (int) $authority['schema_version'] !== WCOS_Merge_Context_Signature::SCHEMA_VERSION
			|| (string) $authority['algorithm'] !== WCOS_Merge_Context_Signature::ALGORITHM
			|| !in_array($identity_type, array('registered', 'guest'), true)
			|| ('registered' === $identity_type && !$customer_id)
			|| ('guest' === $identity_type && $customer_id)
			|| '' === self::normalized_fingerprint($authority['identity_digest'])
			|| '' === self::normalized_fingerprint($authority['billing_context_digest'])
			|| '' === self::normalized_fingerprint($authority['shipping_context_digest'])
			|| '' === self::normalized_fingerprint($authority['payment_context_digest'])) {
			return null;
		}
		return array(
			'schema_version' => WCOS_Merge_Context_Signature::SCHEMA_VERSION,
			'algorithm' => WCOS_Merge_Context_Signature::ALGORITHM,
			'identity_type' => $identity_type,
			'customer_id' => $customer_id,
			'identity_digest' => self::normalized_fingerprint($authority['identity_digest']),
			'billing_context_digest' => self::normalized_fingerprint($authority['billing_context_digest']),
			'shipping_context_digest' => self::normalized_fingerprint($authority['shipping_context_digest']),
			'payment_context_digest' => self::normalized_fingerprint($authority['payment_context_digest']),
		);
	}

	private static function normalized_fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
