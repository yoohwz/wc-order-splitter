<?php

defined('ABSPATH') || exit;

/** Self-verifying, PII-free authority for one child-keyed Return journal. */
final class WCOS_Return_Journal_Context {

	const SCHEMA_VERSION = 1;
	const TERMINAL_RESULT_SCHEMA_VERSION = 1;
	const CONFIRMATION_SCHEMA_VERSION = 1;

	public static function create(WC_Order $child, WC_Order $original, array $plan, array $lineage_authority, array $source_evolution_authority, $operation_id = '', array $confirmation_authority = array()) {
		$child_id = absint($child->get_id());
		$original_id = absint($original->get_id());
		if (!$child_id || !$original_id || $child_id === $original_id
			|| 'shop_order' !== $child->get_type() || 'shop_order' !== $original->get_type()) {
			throw new InvalidArgumentException(__('A Return journal requires two distinct persisted shop orders.', 'wc-order-splitter'));
		}
		$plan_fingerprint = self::fingerprint(isset($plan['plan_fingerprint']) ? $plan['plan_fingerprint'] : '');
		$lineage_fingerprint = self::fingerprint(isset($lineage_authority['authority_fingerprint']) ? $lineage_authority['authority_fingerprint'] : '');
		if ('' === $plan_fingerprint || !hash_equals($plan_fingerprint, WCOS_Return_Plan::fingerprint($plan))
			|| '' === $lineage_fingerprint || !hash_equals($lineage_fingerprint, WCOS_Return_Lineage_Authority::fingerprint($lineage_authority))
			|| $child_id !== absint(isset($plan['child_order_id']) ? $plan['child_order_id'] : 0)
			|| $original_id !== absint(isset($plan['source_order_id']) ? $plan['source_order_id'] : 0)) {
			throw new RuntimeException(__('Return plan and lineage authority do not match the requested pair.', 'wc-order-splitter'));
		}
		WCOS_Return_Source_Evolution_Authority::assert_valid(
			$source_evolution_authority,
			$original_id,
			isset($plan['split_operation_id']) ? $plan['split_operation_id'] : ''
		);

		$authority = array(
			'child_order_id' => $child_id,
			'original_order_id' => $original_id,
			'split_operation_id' => sanitize_key((string) $plan['split_operation_id']),
			'split_child_key' => sanitize_key((string) $plan['split_child_key']),
			'lineage_authority_fingerprint' => $lineage_fingerprint,
			'plan_schema_version' => WCOS_Return_Plan::SCHEMA_VERSION,
			'plan_policy_version' => WCOS_Return_Plan::POLICY_VERSION,
			'plan_fingerprint' => $plan_fingerprint,
			'price_precision' => WCOS_Price_Precision_Scope::validate($plan['price_precision']),
			'currency' => (string) $plan['currency'],
			'prices_include_tax' => (bool) $plan['prices_include_tax'],
			'preflight_policy_version' => WCOS_Return_Preflight::POLICY_VERSION,
			'lineage_schema_version' => WCOS_Return_Lineage_Authority::SCHEMA_VERSION,
			'lineage_policy_version' => WCOS_Return_Lineage_Authority::POLICY_VERSION,
			'participation_schema_version' => WCOS_Return_Participation::SCHEMA_VERSION,
			'retirement_policy_schema_version' => WCOS_Return_Retirement_Policy::SCHEMA_VERSION,
			'retirement_policy_identifier' => WCOS_Return_Retirement_Policy::approved_identifier(),
			'stock_ownership_policy' => WCOS_Return_Retirement_Policy::STOCK_OWNERSHIP_POLICY,
			'order_stock_flag_policy' => WCOS_Return_Retirement_Policy::ORDER_STOCK_FLAG_POLICY,
			'child_signature_before' => WCOS_Return_Source_Evolution_Authority::sealed_signature('child_commercial', WCOS_Order_Contract_Snapshot::source_signature($child)),
			'original_signature_before' => WCOS_Return_Source_Evolution_Authority::sealed_signature('commercial', WCOS_Order_Contract_Snapshot::source_signature($original)),
			'original_relation_signature_before' => WCOS_Return_Source_Evolution_Authority::sealed_signature('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($original)),
			'source_evolution_authority' => $source_evolution_authority,
			'source_evolution_authority_fingerprint' => self::fingerprint($source_evolution_authority['authority_fingerprint']),
		);
		$authority = self::canonical_authority($authority);
		if (!is_array($authority)) {
			throw new RuntimeException(__('The canonical Return pair authority could not be constructed.', 'wc-order-splitter'));
		}
		$context = array(
			'return_pair' => array(
				'schema_version' => self::SCHEMA_VERSION,
				'authority' => $authority,
				'pair_fingerprint' => self::authority_fingerprint($authority),
			),
			'return_plan' => $plan,
		);
		if (!empty($confirmation_authority)) {
			$context['return_confirmation'] = self::create_confirmation_handoff($context, $operation_id, $confirmation_authority);
		}
		return $context;
	}

	/** Bind temporary server Confirmation authority into the existing Return journal context. */
	public static function create_confirmation_handoff(array $context, $operation_id, array $confirmation_authority) {
		$operation_id = sanitize_key((string) $operation_id);
		$pair = self::pair_from_context($context);
		$authority = self::canonical_confirmation_authority($confirmation_authority);
		if ('' === $operation_id || !is_array($pair) || !is_array($authority)
			|| $operation_id !== $authority['operation_id']
			|| !self::confirmation_matches_pair($authority, $pair)) {
			throw new RuntimeException(__('Return Confirmation authority does not match the locked server Return pair.', 'wc-order-splitter'));
		}
		$authority['authority_fingerprint'] = self::confirmation_fingerprint($authority);
		return $authority;
	}

	/** Recover and self-verify the durable Confirmation handoff without any transient. */
	public static function confirmation_handoff_from_record(array $record) {
		$pair = self::pair_from_record($record);
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$stored = isset($context['return_confirmation']) && is_array($context['return_confirmation'])
			? $context['return_confirmation'] : array();
		$authority = self::canonical_confirmation_authority($stored);
		$fingerprint = self::fingerprint(isset($stored['authority_fingerprint']) ? $stored['authority_fingerprint'] : '');
		$operation_id = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		if (!is_array($pair) || !is_array($authority) || '' === $fingerprint
			|| !hash_equals($fingerprint, self::confirmation_fingerprint($authority))
			|| $operation_id !== $authority['operation_id']
			|| !self::confirmation_matches_pair($authority, $pair)) {
			throw new RuntimeException(__('The durable Return Confirmation handoff failed integrity verification.', 'wc-order-splitter'));
		}
		$authority['authority_fingerprint'] = $fingerprint;
		return $authority;
	}

	public static function assert_confirmation_matches_record(array $record, array $confirmation_authority) {
		$durable = self::confirmation_handoff_from_record($record);
		$current = self::canonical_confirmation_authority($confirmation_authority);
		if (!is_array($current) || !hash_equals($durable['authority_fingerprint'], self::confirmation_fingerprint($current))) {
			throw new RuntimeException(__('Temporary Return Confirmation authority conflicts with the durable Return journal.', 'wc-order-splitter'));
		}
		return $durable;
	}

	public static function pair_from_record(array $record) {
		if ('return' !== sanitize_key(isset($record['type']) ? (string) $record['type'] : '')) {
			return null;
		}
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$pair = isset($context['return_pair']) && is_array($context['return_pair']) ? $context['return_pair'] : array();
		if (self::SCHEMA_VERSION !== (int) (isset($pair['schema_version']) ? $pair['schema_version'] : 0)
			|| !isset($pair['authority']) || !is_array($pair['authority'])) {
			return null;
		}
		$authority = self::canonical_authority($pair['authority']);
		if (!is_array($authority) || $authority !== $pair['authority']) {
			return null;
		}
		$computed = self::authority_fingerprint($authority);
		$stored = self::fingerprint(isset($pair['pair_fingerprint']) ? $pair['pair_fingerprint'] : '');
		$journal = self::fingerprint(isset($record['fingerprint']) ? $record['fingerprint'] : '');
		$plan = isset($context['return_plan']) && is_array($context['return_plan']) ? $context['return_plan'] : array();
		try {
			$plan_actual = WCOS_Return_Plan::fingerprint($plan);
			WCOS_Return_Source_Evolution_Authority::assert_valid(
				$authority['source_evolution_authority'],
				$authority['original_order_id'],
				$authority['split_operation_id']
			);
		} catch (Throwable $throwable) {
			return null;
		}
		if ('' === $stored || '' === $journal || !hash_equals($computed, $stored) || !hash_equals($computed, $journal)
			|| !hash_equals($authority['plan_fingerprint'], $plan_actual)
			|| $authority['child_order_id'] !== absint(isset($record['source_order_id']) ? $record['source_order_id'] : 0)
			|| $authority['price_precision'] !== (int) (isset($context['price_precision']) ? $context['price_precision'] : -1)) {
			return null;
		}
		$authority['pair_fingerprint'] = $computed;
		return $authority;
	}

	public static function validates_participant(array $record, $participant_order_id, $role, $peer_order_id = 0, $pair_fingerprint = '', $operation_id = '') {
		$pair = self::pair_from_record($record);
		if (!is_array($pair)) {
			return false;
		}
		$role = sanitize_key((string) $role);
		$participant_order_id = absint($participant_order_id);
		$peer_order_id = absint($peer_order_id);
		$expected_participant = 'source' === $role ? $pair['child_order_id'] : $pair['original_order_id'];
		$expected_peer = 'source' === $role ? $pair['original_order_id'] : $pair['child_order_id'];
		$pair_fingerprint = self::fingerprint($pair_fingerprint);
		$operation_id = sanitize_key((string) $operation_id);
		$record_operation = sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '');
		return in_array($role, array('source', 'target'), true)
			&& $participant_order_id === $expected_participant
			&& (!$peer_order_id || $peer_order_id === $expected_peer)
			&& ('' === $pair_fingerprint || hash_equals($pair['pair_fingerprint'], $pair_fingerprint))
			&& ('' === $operation_id || hash_equals($record_operation, $operation_id));
	}

	public static function create_terminal_result(array $record, WC_Order $child, WC_Order $original) {
		$pair = self::pair_from_record($record);
		if (!is_array($pair)
			|| $pair['child_order_id'] !== absint($child->get_id())
			|| $pair['original_order_id'] !== absint($original->get_id())) {
			throw new RuntimeException(__('Return terminal result does not match pair authority.', 'wc-order-splitter'));
		}
		$state = WCOS_Return_Recovery_State_Graph::assert_record($record);
		if ('committed' !== sanitize_key(isset($record['status']) ? (string) $record['status'] : '')
			|| WCOS_Return_Recovery_State_Graph::COMMITTED !== $state) {
			throw new RuntimeException(__('Return terminal result requires committed recovery authority.', 'wc-order-splitter'));
		}
		$result = array(
			'schema_version' => self::TERMINAL_RESULT_SCHEMA_VERSION,
			'status' => 'completed',
			'operation_id' => sanitize_key((string) $record['operation_id']),
			'child_order_id' => $pair['child_order_id'],
			'original_order_id' => $pair['original_order_id'],
			'split_operation_id' => $pair['split_operation_id'],
			'split_child_key' => $pair['split_child_key'],
			'pair_fingerprint' => $pair['pair_fingerprint'],
			'plan_fingerprint' => $pair['plan_fingerprint'],
			'retirement_policy' => $pair['retirement_policy_identifier'],
			'stock_ownership_policy' => $pair['stock_ownership_policy'],
			'order_stock_flag_policy' => $pair['order_stock_flag_policy'],
			'source_evolution' => WCOS_Return_Source_Evolution_Authority::create_terminal_evolution($pair, $original),
		);
		$result['result_fingerprint'] = self::terminal_result_fingerprint($result);
		return $result;
	}

	public static function terminal_result_from_record(array $record) {
		$pair = self::pair_from_record($record);
		if (!is_array($pair) || 'completed' !== sanitize_key(isset($record['status']) ? (string) $record['status'] : '')
			|| WCOS_Return_Recovery_State_Graph::COMPLETED !== WCOS_Return_Recovery_State_Graph::assert_record($record)) {
			throw new RuntimeException(__('Completed Return authority is unavailable.', 'wc-order-splitter'));
		}
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$snapshot = isset($context['return_recovery_snapshot']) && is_array($context['return_recovery_snapshot'])
			? $context['return_recovery_snapshot'] : array();
		if (empty($snapshot)) {
			throw new RuntimeException(__('Completed Return recovery snapshot is unavailable.', 'wc-order-splitter'));
		}
		WCOS_Return_Recovery_Snapshot::assert_valid($snapshot, $record);
		$result = isset($context['return_terminal_result']) && is_array($context['return_terminal_result'])
			? $context['return_terminal_result'] : array();
		$fingerprint = self::fingerprint(isset($result['result_fingerprint']) ? $result['result_fingerprint'] : '');
		if (self::TERMINAL_RESULT_SCHEMA_VERSION !== (int) (isset($result['schema_version']) ? $result['schema_version'] : 0)
			|| 'completed' !== sanitize_key(isset($result['status']) ? (string) $result['status'] : '')
			|| sanitize_key(isset($result['operation_id']) ? (string) $result['operation_id'] : '') !== sanitize_key((string) $record['operation_id'])
			|| absint(isset($result['child_order_id']) ? $result['child_order_id'] : 0) !== $pair['child_order_id']
			|| absint(isset($result['original_order_id']) ? $result['original_order_id'] : 0) !== $pair['original_order_id']
			|| self::fingerprint(isset($result['pair_fingerprint']) ? $result['pair_fingerprint'] : '') !== $pair['pair_fingerprint']
			|| '' === $fingerprint || !hash_equals($fingerprint, self::terminal_result_fingerprint($result))) {
			throw new RuntimeException(__('Completed Return terminal result failed integrity verification.', 'wc-order-splitter'));
		}
		WCOS_Return_Source_Evolution_Authority::assert_terminal_evolution($result['source_evolution'], $pair);
		return $result;
	}

	public static function is_unsafe_record(array $record) {
		return !is_array(self::pair_from_record($record))
			|| !in_array(sanitize_key(isset($record['status']) ? (string) $record['status'] : ''), array('completed', 'compensated', 'manual_reconciled'), true);
	}

	private static function canonical_authority(array $authority) {
		$expected = array(
			'child_order_id', 'child_signature_before', 'currency', 'lineage_authority_fingerprint',
			'lineage_policy_version', 'lineage_schema_version', 'order_stock_flag_policy', 'original_order_id',
			'original_relation_signature_before', 'original_signature_before', 'participation_schema_version',
			'plan_fingerprint', 'plan_policy_version', 'plan_schema_version', 'preflight_policy_version',
			'price_precision', 'prices_include_tax', 'retirement_policy_identifier',
			'retirement_policy_schema_version', 'source_evolution_authority',
			'source_evolution_authority_fingerprint', 'split_child_key', 'split_operation_id', 'stock_ownership_policy',
		);
		$actual = array_keys($authority);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		$child_id = absint(isset($authority['child_order_id']) ? $authority['child_order_id'] : 0);
		$original_id = absint(isset($authority['original_order_id']) ? $authority['original_order_id'] : 0);
		$precision = isset($authority['price_precision']) ? (int) $authority['price_precision'] : -1;
		$fingerprints = array(
			self::fingerprint(isset($authority['child_signature_before']) ? $authority['child_signature_before'] : ''),
			self::fingerprint(isset($authority['original_signature_before']) ? $authority['original_signature_before'] : ''),
			self::fingerprint(isset($authority['original_relation_signature_before']) ? $authority['original_relation_signature_before'] : ''),
			self::fingerprint(isset($authority['lineage_authority_fingerprint']) ? $authority['lineage_authority_fingerprint'] : ''),
			self::fingerprint(isset($authority['plan_fingerprint']) ? $authority['plan_fingerprint'] : ''),
			self::fingerprint(isset($authority['source_evolution_authority_fingerprint']) ? $authority['source_evolution_authority_fingerprint'] : ''),
		);
		if ($actual !== $expected || !$child_id || !$original_id || $child_id === $original_id
			|| in_array('', $fingerprints, true) || !is_array($authority['source_evolution_authority'])
			|| $precision !== WCOS_Price_Precision_Scope::validate($precision)
			|| 1 !== preg_match('/^[A-Z]{3}$/D', (string) $authority['currency'])
			|| !in_array($authority['prices_include_tax'], array(true, false), true)
			|| WCOS_Return_Plan::SCHEMA_VERSION !== (int) $authority['plan_schema_version']
			|| WCOS_Return_Plan::POLICY_VERSION !== (int) $authority['plan_policy_version']
			|| WCOS_Return_Preflight::POLICY_VERSION !== (int) $authority['preflight_policy_version']
			|| WCOS_Return_Lineage_Authority::SCHEMA_VERSION !== (int) $authority['lineage_schema_version']
			|| WCOS_Return_Lineage_Authority::POLICY_VERSION !== (int) $authority['lineage_policy_version']
			|| WCOS_Return_Participation::SCHEMA_VERSION !== (int) $authority['participation_schema_version']
			|| WCOS_Return_Retirement_Policy::SCHEMA_VERSION !== (int) $authority['retirement_policy_schema_version']
			|| WCOS_Return_Retirement_Policy::approved_identifier() !== sanitize_key((string) $authority['retirement_policy_identifier'])
			|| WCOS_Return_Retirement_Policy::STOCK_OWNERSHIP_POLICY !== sanitize_key((string) $authority['stock_ownership_policy'])
			|| WCOS_Return_Retirement_Policy::ORDER_STOCK_FLAG_POLICY !== sanitize_key((string) $authority['order_stock_flag_policy'])) {
			return null;
		}
		$canonical = $authority;
		$canonical['child_order_id'] = $child_id;
		$canonical['original_order_id'] = $original_id;
		$canonical['split_operation_id'] = sanitize_key((string) $authority['split_operation_id']);
		$canonical['split_child_key'] = sanitize_key((string) $authority['split_child_key']);
		$canonical['price_precision'] = $precision;
		foreach (array('child_signature_before', 'original_signature_before', 'original_relation_signature_before', 'lineage_authority_fingerprint', 'plan_fingerprint', 'source_evolution_authority_fingerprint') as $field) {
			$canonical[$field] = self::fingerprint($authority[$field]);
		}
		return '' === $canonical['split_operation_id'] || '' === $canonical['split_child_key'] ? null : $canonical;
	}

	public static function pair_from_context(array $context) {
		$pair = isset($context['return_pair']) && is_array($context['return_pair']) ? $context['return_pair'] : array();
		$authority = isset($pair['authority']) && is_array($pair['authority']) ? self::canonical_authority($pair['authority']) : null;
		$fingerprint = self::fingerprint(isset($pair['pair_fingerprint']) ? $pair['pair_fingerprint'] : '');
		if (self::SCHEMA_VERSION !== (int) (isset($pair['schema_version']) ? $pair['schema_version'] : 0)
			|| !is_array($authority) || '' === $fingerprint
			|| !hash_equals($fingerprint, self::authority_fingerprint($authority))) {
			return null;
		}
		$authority['pair_fingerprint'] = $fingerprint;
		return $authority;
	}

	private static function canonical_confirmation_authority(array $authority) {
		$copy = $authority;
		unset($copy['authority_fingerprint'], $copy['token_hash'], $copy['confirmation_token'], $copy['created_at'], $copy['expires_at'], $copy['replay_authority']);
		$expected = array(
			'child_order_id', 'confirmation_schema_version', 'currency', 'journal_context_schema_version',
			'lineage_authority_fingerprint', 'lineage_policy_version', 'lineage_schema_version', 'operation_id',
			'operator_user_id', 'order_stock_flag_policy', 'original_order_id', 'pair_fingerprint',
			'plan_fingerprint', 'plan_policy_version', 'plan_schema_version', 'preflight_policy_version',
			'price_precision', 'prices_include_tax', 'retirement_policy_identifier',
			'retirement_policy_schema_version', 'return_service_policy_version',
			'source_evolution_authority_fingerprint', 'split_child_key', 'split_operation_id', 'stock_ownership_policy',
		);
		$actual = array_keys($copy);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		if ($actual !== $expected) {
			return null;
		}
		$canonical = array(
			'confirmation_schema_version' => (int) $copy['confirmation_schema_version'],
			'operation_id' => sanitize_key((string) $copy['operation_id']),
			'operator_user_id' => absint($copy['operator_user_id']),
			'child_order_id' => absint($copy['child_order_id']),
			'original_order_id' => absint($copy['original_order_id']),
			'split_operation_id' => sanitize_key((string) $copy['split_operation_id']),
			'split_child_key' => sanitize_key((string) $copy['split_child_key']),
			'pair_fingerprint' => self::fingerprint($copy['pair_fingerprint']),
			'plan_fingerprint' => self::fingerprint($copy['plan_fingerprint']),
			'lineage_authority_fingerprint' => self::fingerprint($copy['lineage_authority_fingerprint']),
			'source_evolution_authority_fingerprint' => self::fingerprint($copy['source_evolution_authority_fingerprint']),
			'price_precision' => (int) $copy['price_precision'],
			'currency' => (string) $copy['currency'],
			'prices_include_tax' => $copy['prices_include_tax'],
			'return_service_policy_version' => (int) $copy['return_service_policy_version'],
			'preflight_policy_version' => (int) $copy['preflight_policy_version'],
			'plan_schema_version' => (int) $copy['plan_schema_version'],
			'plan_policy_version' => (int) $copy['plan_policy_version'],
			'lineage_schema_version' => (int) $copy['lineage_schema_version'],
			'lineage_policy_version' => (int) $copy['lineage_policy_version'],
			'journal_context_schema_version' => (int) $copy['journal_context_schema_version'],
			'retirement_policy_schema_version' => (int) $copy['retirement_policy_schema_version'],
			'retirement_policy_identifier' => sanitize_key((string) $copy['retirement_policy_identifier']),
			'stock_ownership_policy' => sanitize_key((string) $copy['stock_ownership_policy']),
			'order_stock_flag_policy' => sanitize_key((string) $copy['order_stock_flag_policy']),
		);
		if (self::CONFIRMATION_SCHEMA_VERSION !== $canonical['confirmation_schema_version']
			|| '' === $canonical['operation_id'] || !$canonical['operator_user_id']
			|| !$canonical['child_order_id'] || !$canonical['original_order_id']
			|| $canonical['child_order_id'] === $canonical['original_order_id']
			|| '' === $canonical['split_operation_id'] || '' === $canonical['split_child_key']
			|| in_array('', array($canonical['pair_fingerprint'], $canonical['plan_fingerprint'], $canonical['lineage_authority_fingerprint'], $canonical['source_evolution_authority_fingerprint']), true)
			|| WCOS_Price_Precision_Scope::validate($canonical['price_precision']) !== $canonical['price_precision']
			|| 1 !== preg_match('/^[A-Z]{3}$/D', $canonical['currency'])
			|| !in_array($canonical['prices_include_tax'], array(true, false), true)
			|| WCOS_Return_Order_Service::POLICY_VERSION !== $canonical['return_service_policy_version']
			|| WCOS_Return_Preflight::POLICY_VERSION !== $canonical['preflight_policy_version']
			|| WCOS_Return_Plan::SCHEMA_VERSION !== $canonical['plan_schema_version']
			|| WCOS_Return_Plan::POLICY_VERSION !== $canonical['plan_policy_version']
			|| WCOS_Return_Lineage_Authority::SCHEMA_VERSION !== $canonical['lineage_schema_version']
			|| WCOS_Return_Lineage_Authority::POLICY_VERSION !== $canonical['lineage_policy_version']
			|| self::SCHEMA_VERSION !== $canonical['journal_context_schema_version']
			|| WCOS_Return_Retirement_Policy::SCHEMA_VERSION !== $canonical['retirement_policy_schema_version']
			|| WCOS_Return_Retirement_Policy::approved_identifier() !== $canonical['retirement_policy_identifier']
			|| WCOS_Return_Retirement_Policy::STOCK_OWNERSHIP_POLICY !== $canonical['stock_ownership_policy']
			|| WCOS_Return_Retirement_Policy::ORDER_STOCK_FLAG_POLICY !== $canonical['order_stock_flag_policy']) {
			return null;
		}
		return $canonical;
	}

	private static function confirmation_matches_pair(array $confirmation, array $pair) {
		$fields = array(
			'child_order_id', 'original_order_id', 'split_operation_id', 'split_child_key', 'pair_fingerprint',
			'plan_fingerprint', 'lineage_authority_fingerprint', 'source_evolution_authority_fingerprint',
			'price_precision', 'currency', 'prices_include_tax', 'preflight_policy_version', 'plan_schema_version',
			'plan_policy_version', 'lineage_schema_version', 'lineage_policy_version',
			'retirement_policy_schema_version', 'retirement_policy_identifier', 'stock_ownership_policy', 'order_stock_flag_policy',
		);
		foreach ($fields as $field) {
			if (!array_key_exists($field, $confirmation) || !array_key_exists($field, $pair)
				|| (string) $confirmation[$field] !== (string) $pair[$field]) {
				return false;
			}
		}
		return self::SCHEMA_VERSION === (int) $confirmation['journal_context_schema_version']
			&& WCOS_Return_Order_Service::POLICY_VERSION === (int) $confirmation['return_service_policy_version'];
	}

	private static function confirmation_fingerprint(array $authority) {
		$canonical = self::canonical_confirmation_authority($authority);
		if (!is_array($canonical)) {
			return '';
		}
		return WCOS_Mutation_Fingerprint::create('return_confirmation_handoff_v1', $canonical['child_order_id'], $canonical);
	}

	private static function authority_fingerprint(array $authority) {
		return WCOS_Mutation_Fingerprint::create('return_pair_authority_v1', $authority['child_order_id'], $authority);
	}

	private static function terminal_result_fingerprint(array $result) {
		unset($result['result_fingerprint']);
		return WCOS_Mutation_Fingerprint::create('return_terminal_result_v1', absint(isset($result['child_order_id']) ? $result['child_order_id'] : 0), $result);
	}

	private static function fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
