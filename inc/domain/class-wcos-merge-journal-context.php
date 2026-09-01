<?php

defined('ABSPATH') || exit;

/**
 * Self-verifying PII-free authority for the single source Merge journal.
 */
final class WCOS_Merge_Journal_Context {

	const SCHEMA_VERSION = 6;
	const PREVIOUS_SCHEMA_VERSION = 4;
	const LEGACY_SCHEMA_VERSION = 3;
	const TERMINAL_RESULT_SCHEMA_VERSION = 1;
	const CONFIRMATION_HANDOFF_SCHEMA_VERSION = 1;

	public static function create(WC_Order $source, WC_Order $target, array $plan, array $context_authority, $price_precision, array $evidence = array(), $selected_retirement_policy = '') {
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		$price_precision = WCOS_Price_Precision_Scope::validate($price_precision);
		if (!$source_id || !$target_id || $source_id === $target_id) {
			throw new InvalidArgumentException(__('A Merge journal context requires two distinct persisted orders.', 'wc-order-splitter'));
		}

		$selected_retirement_policy = sanitize_key((string) $selected_retirement_policy);
		if ('' !== $selected_retirement_policy) {
			WCOS_Merge_Retirement_Policy::assert_approved($selected_retirement_policy);
		}
		$source_signature = WCOS_Merge_Canonical_Reader::source_signature($source);
		$archive_signature = WCOS_Merge_Recovery_Snapshot::archive_commercial_signature($source);
		$financial_authority = isset($plan['financial_authority']) && is_array($plan['financial_authority'])
			? WCOS_Merge_Financial_Authority::canonicalize_pair($plan['financial_authority'])
			: array();
		if (empty($financial_authority)) {
			throw new InvalidArgumentException(__('Current Merge journal authority requires a frozen financial policy.', 'wc-order-splitter'));
		}
		WCOS_Merge_Financial_Authority::assert_current($source, $target, $financial_authority);
		$active_signature = WCOS_Merge_Commercial_Policy::expected_target_signature(
			$source,
			$target,
			$price_precision,
			WCOS_Merge_Preflight::POLICY_VERSION
		);
		$authority = array(
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'source_signature' => $source_signature,
			'target_signature' => WCOS_Merge_Canonical_Reader::source_signature($target),
			'plan_schema_version' => WCOS_Merge_Plan::SCHEMA_VERSION,
			'plan_fingerprint' => WCOS_Merge_Plan::fingerprint($plan),
			'price_precision' => $price_precision,
			'preflight_policy_version' => WCOS_Merge_Preflight::POLICY_VERSION,
			'context_signature_version' => WCOS_Merge_Context_Signature::SCHEMA_VERSION,
			'context_authority' => $context_authority,
			'context_authority_fingerprint' => WCOS_Merge_Context_Signature::authority_fingerprint($context_authority),
			'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
			'retirement_candidates' => WCOS_Merge_Retirement_Policy::identifiers(),
			'retirement_policy_selected' => '' !== $selected_retirement_policy,
			'retirement_policy_identifier' => $selected_retirement_policy,
			'archive_source_signature_before' => isset($evidence['archive_source_signature_before'])
				? sanitize_key((string) $evidence['archive_source_signature_before'])
				: $archive_signature,
			'active_ownership_before_signature' => isset($evidence['active_ownership_before_signature'])
				? sanitize_key((string) $evidence['active_ownership_before_signature'])
				: $active_signature,
			'participation_schema_version' => WCOS_Merge_Participation::SCHEMA_VERSION,
			'financial_authority' => $financial_authority,
			'financial_authority_fingerprint' => $financial_authority['pair_financial_policy_fingerprint'],
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

	public static function create_executable(WC_Order $source, WC_Order $target, array $plan, array $context_authority, $price_precision, array $evidence = array()) {
		return self::create(
			$source,
			$target,
			$plan,
			$context_authority,
			$price_precision,
			$evidence,
			WCOS_Merge_Retirement_Policy::approved_identifier()
		);
	}

	public static function pair_from_record(array $record) {
		if ('merge' !== sanitize_key(isset($record['type']) ? (string) $record['type'] : '')) {
			return null;
		}
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$pair = isset($context['merge_pair']) && is_array($context['merge_pair']) ? $context['merge_pair'] : array();
		$pair_schema = (int) (isset($pair['schema_version']) ? $pair['schema_version'] : 0);
		if (!in_array($pair_schema, array(self::LEGACY_SCHEMA_VERSION, self::PREVIOUS_SCHEMA_VERSION, self::SCHEMA_VERSION), true)
			|| !isset($pair['authority']) || !is_array($pair['authority'])) {
			return null;
		}

		try {
			$authority = self::canonical_authority($pair['authority'], $pair_schema);
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

		$computed_fingerprint = self::authority_fingerprint($authority, $pair_schema);
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

	public static function assert_executable_policy(array $record) {
		$pair = self::pair_from_record($record);
		if (!is_array($pair)
			|| true !== $pair['retirement_policy_selected']
			|| WCOS_Merge_Retirement_Policy::approved_identifier() !== $pair['retirement_policy_identifier']) {
			throw new RuntimeException(__('The Merge journal does not contain executable approved retirement-policy authority.', 'wc-order-splitter'));
		}
		WCOS_Merge_Retirement_Policy::assert_approved($pair['retirement_policy_identifier']);
		return $pair;
	}

	public static function create_confirmation_handoff(array $authority, array $pair) {
		$pair_authority = isset($pair['authority']) && is_array($pair['authority']) ? $pair['authority'] : array();
		$canonical = self::canonical_confirmation_authority($authority);
		if (!is_array($canonical)
			|| !isset($pair['pair_fingerprint'])
			|| $canonical['source_order_id'] !== absint(isset($pair_authority['source_order_id']) ? $pair_authority['source_order_id'] : 0)
			|| $canonical['target_order_id'] !== absint(isset($pair_authority['target_order_id']) ? $pair_authority['target_order_id'] : 0)
			|| !hash_equals($canonical['pair_fingerprint'], self::normalized_fingerprint($pair['pair_fingerprint']))
			|| !hash_equals($canonical['plan_fingerprint'], self::normalized_fingerprint(isset($pair_authority['plan_fingerprint']) ? $pair_authority['plan_fingerprint'] : ''))
			|| !hash_equals($canonical['context_authority_fingerprint'], self::normalized_fingerprint(isset($pair_authority['context_authority_fingerprint']) ? $pair_authority['context_authority_fingerprint'] : ''))) {
			throw new RuntimeException(__('The Merge Confirmation handoff does not match locked pair authority.', 'wc-order-splitter'));
		}
		return array(
			'schema_version' => self::CONFIRMATION_HANDOFF_SCHEMA_VERSION,
			'authority' => $canonical,
			'authority_fingerprint' => self::confirmation_authority_fingerprint($canonical),
		);
	}

	public static function confirmation_handoff_from_record(array $record) {
		$pair = self::assert_executable_policy($record);
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$handoff = isset($context['merge_confirmation_authority']) && is_array($context['merge_confirmation_authority'])
			? $context['merge_confirmation_authority'] : array();
		$expected_keys = array('authority', 'authority_fingerprint', 'schema_version');
		$actual_keys = array_keys($handoff);
		sort($actual_keys, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		$authority = isset($handoff['authority']) && is_array($handoff['authority'])
			? self::canonical_confirmation_authority($handoff['authority']) : null;
		$fingerprint = self::normalized_fingerprint(isset($handoff['authority_fingerprint']) ? $handoff['authority_fingerprint'] : '');
		if ($actual_keys !== $expected_keys
			|| (int) (isset($handoff['schema_version']) ? $handoff['schema_version'] : 0) !== self::CONFIRMATION_HANDOFF_SCHEMA_VERSION
			|| !is_array($authority) || $authority !== $handoff['authority'] || '' === $fingerprint
			|| !hash_equals($fingerprint, self::confirmation_authority_fingerprint($authority))
			|| sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '') !== $authority['operation_id']
			|| (int) $pair['source_order_id'] !== $authority['source_order_id']
			|| (int) $pair['target_order_id'] !== $authority['target_order_id']
			|| !hash_equals((string) $pair['pair_fingerprint'], $authority['pair_fingerprint'])
			|| !hash_equals((string) $pair['plan_fingerprint'], $authority['plan_fingerprint'])
			|| !hash_equals((string) $pair['context_authority_fingerprint'], $authority['context_authority_fingerprint'])
			|| (int) $pair['price_precision'] !== $authority['price_precision']
			|| (int) $pair['preflight_policy_version'] !== $authority['preflight_policy_version']
			|| (int) $pair['plan_schema_version'] !== $authority['plan_schema_version']
			|| (int) $pair['context_signature_version'] !== $authority['context_signature_version']
			|| (int) $pair['retirement_policy_schema_version'] !== $authority['retirement_policy_schema_version']
			|| (string) $pair['retirement_policy_identifier'] !== $authority['retirement_policy']
			|| !self::confirmation_versions_match_pair($authority, $pair)) {
			throw new RuntimeException(__('The durable Merge Confirmation handoff failed authority verification.', 'wc-order-splitter'));
		}
		return $authority;
	}

	public static function is_unsafe_record(array $record) {
		if (!is_array(self::pair_from_record($record))) {
			return true;
		}
		$status = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
		return !in_array($status, array('completed', 'compensated', 'manual_reconciled'), true);
	}

	public static function service_policy_for_pair(array $pair) {
		$versions = array(
			'preflight_policy_version' => (int) (isset($pair['preflight_policy_version']) ? $pair['preflight_policy_version'] : 0),
			'plan_schema_version' => (int) (isset($pair['plan_schema_version']) ? $pair['plan_schema_version'] : 0),
			'context_signature_version' => (int) (isset($pair['context_signature_version']) ? $pair['context_signature_version'] : 0),
		);
		if (self::legacy_pair_versions($versions)) {
			return WCOS_Merge_Order_Service::LEGACY_POLICY_VERSION;
		}
		if (self::previous_pair_versions($versions)) {
			return WCOS_Merge_Order_Service::PREVIOUS_POLICY_VERSION;
		}
		if (self::current_pair_versions($versions)) {
			return WCOS_Merge_Order_Service::POLICY_VERSION;
		}
		throw new RuntimeException(__('The Merge pair contains an unsupported version tuple.', 'wc-order-splitter'));
	}

	public static function confirmation_versions_match_pair(array $confirmation, array $pair) {
		return (int) (isset($confirmation['merge_service_policy_version']) ? $confirmation['merge_service_policy_version'] : 0) === self::service_policy_for_pair($pair)
			&& (int) (isset($confirmation['preflight_policy_version']) ? $confirmation['preflight_policy_version'] : 0) === (int) $pair['preflight_policy_version']
			&& (int) (isset($confirmation['plan_schema_version']) ? $confirmation['plan_schema_version'] : 0) === (int) $pair['plan_schema_version']
			&& (int) (isset($confirmation['context_signature_version']) ? $confirmation['context_signature_version'] : 0) === (int) $pair['context_signature_version'];
	}

	public static function create_terminal_result(array $record) {
		$pair = self::assert_executable_policy($record);
		if ('committed' !== sanitize_key(isset($record['status']) ? (string) $record['status'] : '')
			|| WCOS_Merge_Recovery_State_Graph::COMMITTED !== WCOS_Merge_Recovery_State_Graph::assert_record($record)) {
			throw new RuntimeException(__('The Merge terminal result requires durable committed authority.', 'wc-order-splitter'));
		}
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$result = array(
			'schema_version' => self::TERMINAL_RESULT_SCHEMA_VERSION,
			'status' => 'completed',
			'operation_id' => sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : ''),
			'source_order_id' => (int) $pair['source_order_id'],
			'target_order_id' => (int) $pair['target_order_id'],
			'retirement_policy' => (string) $pair['retirement_policy_identifier'],
			'target_item_ids' => self::canonical_ids(isset($context['merge_target_item_ids']) ? (array) $context['merge_target_item_ids'] : array()),
			'target_tax_item_ids' => self::canonical_ids(isset($context['merge_target_tax_item_ids']) ? (array) $context['merge_target_tax_item_ids'] : array()),
		);
		if ('' === $result['operation_id']) {
			throw new RuntimeException(__('The Merge terminal result is missing its operation authority.', 'wc-order-splitter'));
		}
		$result['result_fingerprint'] = self::terminal_result_fingerprint($result);
		return $result;
	}

	public static function terminal_result_from_record(array $record) {
		$pair = self::assert_executable_policy($record);
		if ('completed' !== sanitize_key(isset($record['status']) ? (string) $record['status'] : '')
			|| WCOS_Merge_Recovery_State_Graph::COMPLETED !== WCOS_Merge_Recovery_State_Graph::assert_record($record)) {
			throw new RuntimeException(__('The Merge terminal result requires durable completed authority.', 'wc-order-splitter'));
		}
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$stored = isset($context['merge_terminal_result']) && is_array($context['merge_terminal_result']) ? $context['merge_terminal_result'] : array();
		$expected_keys = array(
			'operation_id', 'result_fingerprint', 'retirement_policy', 'schema_version', 'source_order_id',
			'status', 'target_item_ids', 'target_order_id', 'target_tax_item_ids',
		);
		$actual_keys = array_keys($stored);
		sort($actual_keys, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		if ($actual_keys !== $expected_keys || !is_array($stored['target_item_ids']) || !is_array($stored['target_tax_item_ids'])) {
			throw new RuntimeException(__('Completed Merge authority is missing its bounded terminal result.', 'wc-order-splitter'));
		}
		$result = array(
			'schema_version' => (int) $stored['schema_version'],
			'status' => sanitize_key((string) $stored['status']),
			'operation_id' => sanitize_key((string) $stored['operation_id']),
			'source_order_id' => absint($stored['source_order_id']),
			'target_order_id' => absint($stored['target_order_id']),
			'retirement_policy' => sanitize_key((string) $stored['retirement_policy']),
			'target_item_ids' => self::canonical_ids($stored['target_item_ids']),
			'target_tax_item_ids' => self::canonical_ids($stored['target_tax_item_ids']),
		);
		$fingerprint = self::normalized_fingerprint($stored['result_fingerprint']);
		$expected_item_ids = self::canonical_ids(isset($context['merge_target_item_ids']) ? (array) $context['merge_target_item_ids'] : array());
		$expected_tax_ids = self::canonical_ids(isset($context['merge_target_tax_item_ids']) ? (array) $context['merge_target_tax_item_ids'] : array());
		if (self::TERMINAL_RESULT_SCHEMA_VERSION !== $result['schema_version']
			|| 'completed' !== $result['status']
			|| sanitize_key((string) $record['operation_id']) !== $result['operation_id']
			|| (int) $pair['source_order_id'] !== $result['source_order_id']
			|| (int) $pair['target_order_id'] !== $result['target_order_id']
			|| (string) $pair['retirement_policy_identifier'] !== $result['retirement_policy']
			|| $expected_item_ids !== $result['target_item_ids']
			|| $expected_tax_ids !== $result['target_tax_item_ids']
			|| '' === $fingerprint
			|| !hash_equals($fingerprint, self::terminal_result_fingerprint($result))) {
			throw new RuntimeException(__('Completed Merge terminal result failed authority verification.', 'wc-order-splitter'));
		}
		return $result;
	}

	private static function terminal_result_fingerprint(array $result) {
		unset($result['result_fingerprint']);
		return WCOS_Mutation_Fingerprint::create('merge_terminal_result_v1', absint(isset($result['source_order_id']) ? $result['source_order_id'] : 0), $result);
	}

	private static function confirmation_authority_fingerprint(array $authority) {
		return WCOS_Mutation_Fingerprint::create('merge_confirmation_handoff_v1', $authority['source_order_id'], $authority);
	}

	private static function canonical_confirmation_authority(array $authority) {
		$expected_keys = array(
			'confirmation_schema_version', 'context_authority_fingerprint', 'context_signature_version',
			'merge_service_policy_version', 'operation_id', 'operator_user_id', 'pair_fingerprint',
			'plan_fingerprint', 'plan_schema_version', 'preflight_policy_version', 'price_precision',
			'retirement_policy', 'retirement_policy_schema_version', 'source_order_id', 'target_order_id',
		);
		$actual_keys = array_keys($authority);
		sort($actual_keys, SORT_STRING);
		sort($expected_keys, SORT_STRING);
		$operation_id = sanitize_key(isset($authority['operation_id']) ? (string) $authority['operation_id'] : '');
		$source_id = absint(isset($authority['source_order_id']) ? $authority['source_order_id'] : 0);
		$target_id = absint(isset($authority['target_order_id']) ? $authority['target_order_id'] : 0);
		$pair_fingerprint = self::normalized_fingerprint(isset($authority['pair_fingerprint']) ? $authority['pair_fingerprint'] : '');
		$plan_fingerprint = self::normalized_fingerprint(isset($authority['plan_fingerprint']) ? $authority['plan_fingerprint'] : '');
		$context_fingerprint = self::normalized_fingerprint(isset($authority['context_authority_fingerprint']) ? $authority['context_authority_fingerprint'] : '');
		$precision = isset($authority['price_precision']) ? (int) $authority['price_precision'] : -1;
		$versions = array(
			'merge_service_policy_version' => (int) (isset($authority['merge_service_policy_version']) ? $authority['merge_service_policy_version'] : 0),
			'preflight_policy_version' => (int) (isset($authority['preflight_policy_version']) ? $authority['preflight_policy_version'] : 0),
			'plan_schema_version' => (int) (isset($authority['plan_schema_version']) ? $authority['plan_schema_version'] : 0),
			'context_signature_version' => (int) (isset($authority['context_signature_version']) ? $authority['context_signature_version'] : 0),
		);
		if ($actual_keys !== $expected_keys || '' === $operation_id || !$source_id || !$target_id || $source_id === $target_id
			|| !absint(isset($authority['operator_user_id']) ? $authority['operator_user_id'] : 0)
			|| '' === $pair_fingerprint || '' === $plan_fingerprint || '' === $context_fingerprint
			|| $precision !== WCOS_Price_Precision_Scope::validate($precision)
			|| !absint(isset($authority['confirmation_schema_version']) ? $authority['confirmation_schema_version'] : 0)
			|| !self::supported_version_tuple($versions)
			|| (int) $authority['retirement_policy_schema_version'] !== WCOS_Merge_Retirement_Policy::SCHEMA_VERSION
			|| WCOS_Merge_Retirement_Policy::approved_identifier() !== sanitize_key((string) $authority['retirement_policy'])) {
			return null;
		}
		return array(
			'operation_id' => $operation_id,
			'operator_user_id' => absint($authority['operator_user_id']),
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'confirmation_schema_version' => absint($authority['confirmation_schema_version']),
			'merge_service_policy_version' => $versions['merge_service_policy_version'],
			'preflight_policy_version' => $versions['preflight_policy_version'],
			'plan_schema_version' => $versions['plan_schema_version'],
			'plan_fingerprint' => $plan_fingerprint,
			'context_signature_version' => $versions['context_signature_version'],
			'context_authority_fingerprint' => $context_fingerprint,
			'pair_fingerprint' => $pair_fingerprint,
			'price_precision' => $precision,
			'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
			'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
		);
	}

	private static function canonical_ids(array $ids) {
		$ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private static function authority_fingerprint(array $authority, $schema_version = self::SCHEMA_VERSION) {
		$schema_version = (int) $schema_version;
		$namespace = self::LEGACY_SCHEMA_VERSION === $schema_version
			? 'merge_pair_authority_v3'
			: (self::PREVIOUS_SCHEMA_VERSION === $schema_version ? 'merge_pair_authority_v4' : 'merge_pair_authority_v6');
		return WCOS_Mutation_Fingerprint::create($namespace, $authority['source_order_id'], $authority);
	}

	private static function canonical_authority(array $authority, $pair_schema = self::SCHEMA_VERSION) {
		$expected_keys = array(
			'active_ownership_before_signature', 'archive_source_signature_before', 'context_authority',
			'context_authority_fingerprint', 'context_signature_version', 'participation_schema_version',
			'plan_fingerprint', 'plan_schema_version', 'preflight_policy_version', 'price_precision',
			'retirement_candidates', 'retirement_policy_identifier', 'retirement_policy_schema_version', 'retirement_policy_selected',
			'source_order_id', 'source_signature', 'target_order_id', 'target_signature',
		);
		$current_pair = self::SCHEMA_VERSION === (int) $pair_schema;
		if ($current_pair) {
			$expected_keys[] = 'financial_authority';
			$expected_keys[] = 'financial_authority_fingerprint';
		}
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
		$versions = array(
			'preflight_policy_version' => (int) $authority['preflight_policy_version'],
			'plan_schema_version' => (int) $authority['plan_schema_version'],
			'context_signature_version' => (int) $authority['context_signature_version'],
		);
		$expected_legacy = self::LEGACY_SCHEMA_VERSION === (int) $pair_schema;
		$expected_previous = self::PREVIOUS_SCHEMA_VERSION === (int) $pair_schema;
		$policy_selected = true === $authority['retirement_policy_selected'];
		$policy_identifier = sanitize_key((string) $authority['retirement_policy_identifier']);
		if (!$source_id || !$target_id || $source_id === $target_id || !is_array($context_authority)
			|| '' === $source_signature || '' === $target_signature || '' === $plan_fingerprint
			|| '' === $context_fingerprint || '' === $archive_signature
			|| ('' !== (string) $authority['active_ownership_before_signature'] && '' === $active_signature)
			|| $price_precision !== WCOS_Price_Precision_Scope::validate($price_precision)
			|| ($expected_legacy && !self::legacy_pair_versions($versions))
			|| ($expected_previous && !self::previous_pair_versions($versions))
			|| ($current_pair && !self::current_pair_versions($versions))
			|| (!$expected_legacy && !$expected_previous && !$current_pair)
			|| (int) $authority['retirement_policy_schema_version'] !== WCOS_Merge_Retirement_Policy::SCHEMA_VERSION
			|| (int) $authority['participation_schema_version'] !== WCOS_Merge_Participation::SCHEMA_VERSION
			|| (!in_array($authority['retirement_policy_selected'], array(true, false), true))
			|| ($policy_selected && WCOS_Merge_Retirement_Policy::approved_identifier() !== $policy_identifier)
			|| (!$policy_selected && '' !== $policy_identifier)
			|| WCOS_Merge_Retirement_Policy::identifiers() !== array_values($authority['retirement_candidates'])) {
			return null;
		}

		$result = array(
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'source_signature' => $source_signature,
			'target_signature' => $target_signature,
			'plan_schema_version' => $versions['plan_schema_version'],
			'plan_fingerprint' => $plan_fingerprint,
			'price_precision' => $price_precision,
			'preflight_policy_version' => $versions['preflight_policy_version'],
			'context_signature_version' => $versions['context_signature_version'],
			'context_authority' => $context_authority,
			'context_authority_fingerprint' => $context_fingerprint,
			'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
			'retirement_candidates' => WCOS_Merge_Retirement_Policy::identifiers(),
			'retirement_policy_selected' => $policy_selected,
			'retirement_policy_identifier' => $policy_identifier,
			'archive_source_signature_before' => $archive_signature,
			'active_ownership_before_signature' => $active_signature,
			'participation_schema_version' => WCOS_Merge_Participation::SCHEMA_VERSION,
		);
		if ($current_pair) {
			try {
				$financial_authority = WCOS_Merge_Financial_Authority::canonicalize_pair($authority['financial_authority']);
			} catch (Throwable $throwable) {
				return null;
			}
			$financial_fingerprint = self::normalized_fingerprint($authority['financial_authority_fingerprint']);
			if ('' === $financial_fingerprint
				|| !hash_equals($financial_fingerprint, (string) $financial_authority['pair_financial_policy_fingerprint'])
				|| $source_id !== (int) $financial_authority['source']['order_id']
				|| $target_id !== (int) $financial_authority['target']['order_id']
				|| $price_precision !== (int) $financial_authority['price_precision']) {
				return null;
			}
			$result['financial_authority'] = $financial_authority;
			$result['financial_authority_fingerprint'] = $financial_fingerprint;
		}
		return $result;
	}

	private static function canonical_context_authority($authority) {
		if (!is_array($authority)) {
			return null;
		}
		$schema = (int) (isset($authority['schema_version']) ? $authority['schema_version'] : 0);
		if (in_array($schema, array(WCOS_Merge_Context_Signature::PREVIOUS_SCHEMA_VERSION, WCOS_Merge_Context_Signature::SCHEMA_VERSION), true)) {
			$expected_keys = array(
				'algorithm', 'disposition', 'schema_version',
				'source_billing_context_digest', 'source_identity_digest', 'source_identity_type',
				'source_payment_context_digest', 'source_shipping_context_digest',
				'target_billing_context_digest', 'target_identity_digest', 'target_identity_type',
				'target_payment_context_digest', 'target_shipping_context_digest',
			);
			$actual_keys = array_keys($authority);
			sort($actual_keys, SORT_STRING);
			sort($expected_keys, SORT_STRING);
			if ($actual_keys !== $expected_keys
				|| WCOS_Merge_Context_Signature::ALGORITHM !== (string) $authority['algorithm']
				|| 'keep_target_context' !== sanitize_key((string) $authority['disposition'])) {
				return null;
			}
			$result = array(
				'schema_version' => $schema,
				'algorithm' => WCOS_Merge_Context_Signature::ALGORITHM,
				'disposition' => 'keep_target_context',
			);
			foreach (array('source', 'target') as $role) {
				$type = sanitize_key((string) $authority[$role . '_identity_type']);
				if (!in_array($type, array('registered', 'guest'), true)) {
					return null;
				}
				$result[$role . '_identity_type'] = $type;
				foreach (array('identity_digest', 'billing_context_digest', 'shipping_context_digest', 'payment_context_digest') as $field) {
					$value = self::normalized_fingerprint($authority[$role . '_' . $field]);
					if ('' === $value) {
						return null;
					}
					$result[$role . '_' . $field] = $value;
				}
			}
			return $result;
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
			|| (int) $authority['schema_version'] !== WCOS_Merge_Context_Signature::LEGACY_SCHEMA_VERSION
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
			'schema_version' => WCOS_Merge_Context_Signature::LEGACY_SCHEMA_VERSION,
			'algorithm' => WCOS_Merge_Context_Signature::ALGORITHM,
			'identity_type' => $identity_type,
			'customer_id' => $customer_id,
			'identity_digest' => self::normalized_fingerprint($authority['identity_digest']),
			'billing_context_digest' => self::normalized_fingerprint($authority['billing_context_digest']),
			'shipping_context_digest' => self::normalized_fingerprint($authority['shipping_context_digest']),
			'payment_context_digest' => self::normalized_fingerprint($authority['payment_context_digest']),
		);
	}

	private static function supported_version_tuple(array $versions) {
		$pair = array(
			'preflight_policy_version' => isset($versions['preflight_policy_version']) ? $versions['preflight_policy_version'] : 0,
			'plan_schema_version' => isset($versions['plan_schema_version']) ? $versions['plan_schema_version'] : 0,
			'context_signature_version' => isset($versions['context_signature_version']) ? $versions['context_signature_version'] : 0,
		);
		$service = (int) (isset($versions['merge_service_policy_version']) ? $versions['merge_service_policy_version'] : 0);
		return ($service === WCOS_Merge_Order_Service::LEGACY_POLICY_VERSION && self::legacy_pair_versions($pair))
			|| ($service === WCOS_Merge_Order_Service::PREVIOUS_POLICY_VERSION && self::previous_pair_versions($pair))
			|| ($service === WCOS_Merge_Order_Service::POLICY_VERSION && self::current_pair_versions($pair));
	}

	private static function legacy_pair_versions(array $versions) {
		return WCOS_Merge_Preflight::LEGACY_POLICY_VERSION === (int) (isset($versions['preflight_policy_version']) ? $versions['preflight_policy_version'] : 0)
			&& WCOS_Merge_Plan::LEGACY_SCHEMA_VERSION === (int) (isset($versions['plan_schema_version']) ? $versions['plan_schema_version'] : 0)
			&& WCOS_Merge_Context_Signature::LEGACY_SCHEMA_VERSION === (int) (isset($versions['context_signature_version']) ? $versions['context_signature_version'] : 0);
	}

	private static function previous_pair_versions(array $versions) {
		return WCOS_Merge_Preflight::PREVIOUS_POLICY_VERSION === (int) (isset($versions['preflight_policy_version']) ? $versions['preflight_policy_version'] : 0)
			&& WCOS_Merge_Plan::PREVIOUS_SCHEMA_VERSION === (int) (isset($versions['plan_schema_version']) ? $versions['plan_schema_version'] : 0)
			&& WCOS_Merge_Context_Signature::PREVIOUS_SCHEMA_VERSION === (int) (isset($versions['context_signature_version']) ? $versions['context_signature_version'] : 0);
	}

	private static function current_pair_versions(array $versions) {
		return WCOS_Merge_Preflight::POLICY_VERSION === (int) (isset($versions['preflight_policy_version']) ? $versions['preflight_policy_version'] : 0)
			&& WCOS_Merge_Plan::SCHEMA_VERSION === (int) (isset($versions['plan_schema_version']) ? $versions['plan_schema_version'] : 0)
			&& WCOS_Merge_Context_Signature::SCHEMA_VERSION === (int) (isset($versions['context_signature_version']) ? $versions['context_signature_version'] : 0);
	}

	private static function normalized_fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
