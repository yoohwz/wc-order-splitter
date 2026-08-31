<?php

defined('ABSPATH') || exit;

final class WCOS_Split_Strategy_Confirmation_Exception extends RuntimeException {
	private $reason;

	public function __construct($reason, $message) {
		$this->reason = sanitize_key((string) $reason);
		parent::__construct((string) $message);
	}

	public function get_reason() {
		return $this->reason;
	}
}

/**
 * Short-lived confirmation authority for future Category/Stock-status Split.
 *
 * Before the first mutation, authority lives only in a transient. Once the
 * Split journal starts, the journal becomes the single durable replay source of
 * truth. No second persistent strategy-operation store is introduced.
 */
final class WCOS_Split_Strategy_Confirmation_Store {
	const SCHEMA_VERSION = 2;
	const TTL = 1800;

	private static $verified_source_signatures = array();

	public static function create(WC_Order $source, $strategy, array $review, $source_bucket_key, $user_id) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		$user_id = absint($user_id);
		$source_id = absint($source->get_id());
		if (!$source_id || !$user_id) {
			throw new InvalidArgumentException(__('A persisted order and signed-in user are required to confirm a Split strategy.', 'wc-order-splitter'));
		}
		if (absint(isset($review['order_id']) ? $review['order_id'] : 0) !== $source_id
			|| $strategy !== sanitize_key(isset($review['strategy']) ? (string) $review['strategy'] : '')) {
			throw new WCOS_Split_Strategy_Confirmation_Exception(
				'review_mismatch',
				__('The Split strategy review does not belong to this order and strategy.', 'wc-order-splitter')
			);
		}

		$source_bucket_key = sanitize_key((string) $source_bucket_key);
		$adapter = new WCOS_Split_Strategy_WooCommerce_Adapter();
		try {
			$plan = $adapter->build_plan($review, $source_bucket_key);
		} catch (Throwable $throwable) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('review_invalid', $throwable->getMessage());
		}

		$preflight = (new WCOS_Split_WooCommerce_Adapter())->preflight($source);
		if (empty($preflight['supported'])) {
			throw new WCOS_Split_Strategy_Confirmation_Exception(
				'preflight_unsupported',
				isset($preflight['message']) ? (string) $preflight['message'] : __('The source order is no longer supported by the Split safety policy.', 'wc-order-splitter')
			);
		}

		$reviewed_signature = isset($review['source_signature']) ? (string) $review['source_signature'] : '';
		$preflight_signature = isset($preflight['source_signature']) ? (string) $preflight['source_signature'] : '';
		if ('' === $reviewed_signature || '' === $preflight_signature || !hash_equals($reviewed_signature, $preflight_signature)) {
			throw new WCOS_Split_Strategy_Confirmation_Exception(
				'source_changed',
				__('The source order changed after the Split strategy review. Review the strategy again before confirming it.', 'wc-order-splitter')
			);
		}

		$scoped_source = wc_get_order($source_id);
		if (!$scoped_source instanceof WC_Order) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('source_missing', __('The source order is no longer available.', 'wc-order-splitter'));
		}
		$current_signature = WCOS_Order_Contract_Snapshot::source_signature($scoped_source);
		if (!hash_equals($reviewed_signature, $current_signature)) {
			throw new WCOS_Split_Strategy_Confirmation_Exception(
				'source_changed',
				__('The source order changed while the Split strategy confirmation was being created.', 'wc-order-splitter')
			);
		}

		$planner_policy_version = absint(isset($review['policy_version']) ? $review['policy_version'] : 0);
		if (!$planner_policy_version || $planner_policy_version !== self::current_planner_policy_version($strategy)) {
			throw new WCOS_Split_Strategy_Confirmation_Exception(
				'planner_policy_changed',
				__('The Split strategy planner policy changed after Review. Review the strategy again.', 'wc-order-splitter')
			);
		}
		if (!isset($review['execution_policy'])
			|| WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== WCOS_Split_Execution_Policy::normalize($review['execution_policy'])) {
			throw new WCOS_Split_Strategy_Confirmation_Exception(
				'execution_policy_mismatch',
				__('The Split strategy review does not carry whole-line execution authority.', 'wc-order-splitter')
			);
		}

		$classification_fingerprint = isset($review['classification_fingerprint']) ? sanitize_key((string) $review['classification_fingerprint']) : '';
		if ('' === $classification_fingerprint || '' === $source_bucket_key) {
			throw new WCOS_Split_Strategy_Confirmation_Exception(
				'review_incomplete',
				__('The Split strategy review is missing frozen classification or source-bucket authority.', 'wc-order-splitter')
			);
		}

		$operation_id = wp_generate_uuid4();
		$token = wp_generate_password(48, false, false);
		$precision = WCOS_Price_Precision_Scope::validate(isset($preflight['price_precision']) ? $preflight['price_precision'] : wc_get_price_decimals());
		$split_policy_version = isset($preflight['policy']['policy_version']) ? absint($preflight['policy']['policy_version']) : 0;
		if (!$split_policy_version) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('review_incomplete', __('The Split safety policy version is missing from strategy confirmation.', 'wc-order-splitter'));
		}
		try {
			$commercial_policy = WCOS_Split_Commercial_Policy::assert_current(
				$scoped_source,
				isset($review['commercial_policy']) && is_array($review['commercial_policy']) ? $review['commercial_policy'] : array()
			);
			WCOS_Split_Commercial_Policy::assert_plan($plan, $commercial_policy);
		} catch (Throwable $throwable) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('commercial_policy_changed', $throwable->getMessage());
		}

		$now = time();
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'operation_id' => $operation_id,
			'token_hash' => self::token_hash($token),
			'source_order_id' => $source_id,
			'user_id' => $user_id,
			'strategy' => $strategy,
			'planner_policy_version' => $planner_policy_version,
			'source_signature' => $current_signature,
			'classification_fingerprint' => $classification_fingerprint,
			'source_bucket_key' => $source_bucket_key,
			'plan' => WCOS_Split_Plan::canonicalize_request($plan),
			'execution_policy' => WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			'price_precision' => $precision,
				'split_policy_version' => $split_policy_version,
				'commercial_policy' => $commercial_policy,
			'created_at' => $now,
			'expires_at' => $now + self::TTL,
		);

		if (!set_transient(self::key($operation_id), $record, self::TTL)) {
			throw new RuntimeException(__('Unable to create the temporary Split strategy confirmation record.', 'wc-order-splitter'));
		}

		return array(
			'operation_id' => $operation_id,
			'confirmation_token' => $token,
			'expires_at' => $record['expires_at'],
			'record' => $record,
		);
	}

	public static function verify(WC_Order $source, $operation_id, $token, $user_id) {
		$operation_id = sanitize_key((string) $operation_id);
		$user_id = absint($user_id);
		unset(self::$verified_source_signatures[$operation_id]);
		if (!self::is_uuid($operation_id) || !$user_id) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('invalid_identity', __('The Split strategy confirmation identity is invalid.', 'wc-order-splitter'));
		}

		$record = get_transient(self::key($operation_id));
		if (!is_array($record)) {
			return self::durable_replay($source, $operation_id);
		}
		if ('' === (string) $token || empty($record['token_hash']) || !hash_equals((string) $record['token_hash'], self::token_hash($token))) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('invalid_token', __('The Split strategy confirmation token is invalid.', 'wc-order-splitter'));
		}
		if (absint(isset($record['source_order_id']) ? $record['source_order_id'] : 0) !== $source->get_id()
			|| absint(isset($record['user_id']) ? $record['user_id'] : 0) !== $user_id) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('owner_mismatch', __('The Split strategy confirmation does not belong to this user and order.', 'wc-order-splitter'));
		}
		if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
			self::delete($operation_id);
			return self::durable_replay($source, $operation_id);
		}

		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy(isset($record['strategy']) ? $record['strategy'] : '');
		$journal = WCOS_Operation_Journal::get($source, $operation_id);
		if (is_array($journal)) {
			$durable = self::durable_replay($source, $operation_id);
			self::assert_confirmation_matches_durable($record, $durable);
			return $durable;
		}

		if (absint(isset($record['planner_policy_version']) ? $record['planner_policy_version'] : 0) !== self::current_planner_policy_version($strategy)) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('planner_policy_changed', __('The Split strategy planner policy changed after confirmation. Review the strategy again.', 'wc-order-splitter'));
		}
		if (absint(isset($record['split_policy_version']) ? $record['split_policy_version'] : 0) !== (int) WCOS_Split_Preflight::POLICY_VERSION) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('split_policy_changed', __('The Split safety policy changed after strategy confirmation. Review the strategy again.', 'wc-order-splitter'));
		}
		if (!isset($record['schema_version']) || self::SCHEMA_VERSION !== (int) $record['schema_version']) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('commercial_policy_changed', __('The Split strategy confirmation predates commercial policy authority. Review the strategy again.', 'wc-order-splitter'));
		}
		try {
			$commercial_policy = WCOS_Split_Commercial_Policy::assert_valid(
				isset($record['commercial_policy']) && is_array($record['commercial_policy']) ? $record['commercial_policy'] : array()
			);
		} catch (Throwable $throwable) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('commercial_policy_changed', $throwable->getMessage());
		}

		$precision = WCOS_Price_Precision_Scope::validate(isset($record['price_precision']) ? $record['price_precision'] : null);
		$precision_token = WCOS_Price_Precision_Scope::begin($precision);
		try {
			$scoped_source = wc_get_order($source->get_id());
			if (!$scoped_source instanceof WC_Order) {
				throw new WCOS_Split_Strategy_Confirmation_Exception('source_missing', __('The source order is no longer available.', 'wc-order-splitter'));
			}
			$expected = isset($record['source_signature']) ? (string) $record['source_signature'] : '';
			$actual = WCOS_Order_Contract_Snapshot::source_signature($scoped_source);
				if ('' === $expected || !hash_equals($expected, $actual)) {
				throw new WCOS_Split_Strategy_Confirmation_Exception('source_changed', __('The source order changed after strategy confirmation. Review the strategy again.', 'wc-order-splitter'));
				}
				try {
					WCOS_Split_Commercial_Policy::assert_current($scoped_source, $commercial_policy);
					WCOS_Split_Commercial_Policy::assert_plan($record['plan'], $commercial_policy);
				} catch (Throwable $throwable) {
					throw new WCOS_Split_Strategy_Confirmation_Exception('commercial_policy_changed', $throwable->getMessage());
				}
			self::$verified_source_signatures[$operation_id] = $expected;
		} finally {
			WCOS_Price_Precision_Scope::end($precision_token);
		}

		$record['plan'] = WCOS_Split_Plan::canonicalize_request(isset($record['plan']) && is_array($record['plan']) ? $record['plan'] : array());
		$record['price_precision'] = $precision;
		$record['commercial_policy'] = $commercial_policy;
		$record['replay_authority'] = 'confirmation';
		return $record;
	}

	public static function operation_authority(array $record) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy(isset($record['strategy']) ? $record['strategy'] : '');
		$authority = array(
			'strategy' => $strategy,
			'planner_policy_version' => absint(isset($record['planner_policy_version']) ? $record['planner_policy_version'] : 0),
			'review_source_signature' => isset($record['source_signature']) ? sanitize_key((string) $record['source_signature']) : '',
			'classification_fingerprint' => isset($record['classification_fingerprint']) ? sanitize_key((string) $record['classification_fingerprint']) : '',
			'source_bucket_key' => sanitize_key(isset($record['source_bucket_key']) ? (string) $record['source_bucket_key'] : ''),
		);
		if (!$authority['planner_policy_version'] || '' === $authority['review_source_signature']
			|| '' === $authority['classification_fingerprint'] || '' === $authority['source_bucket_key']) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('authority_incomplete', __('The Split strategy confirmation is missing durable authority fields.', 'wc-order-splitter'));
		}
		return $authority;
	}

	public static function verified_source_signature($operation_id) {
		$operation_id = sanitize_key((string) $operation_id);
		return isset(self::$verified_source_signatures[$operation_id]) ? (string) self::$verified_source_signatures[$operation_id] : '';
	}

	public static function delete($operation_id) {
		$operation_id = sanitize_key((string) $operation_id);
		unset(self::$verified_source_signatures[$operation_id]);
		return self::is_uuid($operation_id) ? delete_transient(self::key($operation_id)) : false;
	}

	private static function durable_replay(WC_Order $source, $operation_id) {
		$journal = WCOS_Operation_Journal::get($source, $operation_id);
		if (!is_array($journal)) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('expired', __('The Split strategy confirmation expired. Review the strategy again.', 'wc-order-splitter'));
		}
		if (!isset($journal['type']) || 'split' !== sanitize_key((string) $journal['type'])
			|| absint(isset($journal['source_order_id']) ? $journal['source_order_id'] : 0) !== $source->get_id()) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('journal_mismatch', __('The durable Split strategy operation does not match this source order.', 'wc-order-splitter'));
		}

		$status = sanitize_key(isset($journal['status']) ? (string) $journal['status'] : '');
		if ('manual_reconciliation' === $status) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('manual_reconciliation', __('This Split strategy operation requires manual reconciliation.', 'wc-order-splitter'));
		}
		if (in_array($status, array('manual_reconciled', 'compensated'), true)) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('operation_closed', __('This Split strategy operation is closed and cannot be replayed.', 'wc-order-splitter'));
		}

		$context = isset($journal['context']) && is_array($journal['context']) ? $journal['context'] : array();
		if (empty($context['strategy_authority']) || !is_array($context['strategy_authority'])
			|| empty($context['plan']) || !is_array($context['plan'])
			|| !array_key_exists('price_precision', $context)
			|| !array_key_exists('policy_version', $context)) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('journal_incomplete', __('The durable Split strategy operation is missing replay authority.', 'wc-order-splitter'));
		}
		try {
			$commercial_policy = WCOS_Split_Commercial_Policy::from_journal($journal);
		} catch (Throwable $throwable) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('commercial_policy_changed', $throwable->getMessage());
		}
		if (WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== WCOS_Split_Execution_Policy::normalize(isset($context['execution_policy']) ? $context['execution_policy'] : '')) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('execution_policy_mismatch', __('The durable Split strategy operation lost whole-line execution authority.', 'wc-order-splitter'));
		}

		$authority = self::normalize_durable_authority($context['strategy_authority']);
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'operation_id' => $operation_id,
			'source_order_id' => $source->get_id(),
			'strategy' => $authority['strategy'],
			'planner_policy_version' => $authority['planner_policy_version'],
			'source_signature' => $authority['review_source_signature'],
			'classification_fingerprint' => $authority['classification_fingerprint'],
			'source_bucket_key' => $authority['source_bucket_key'],
			'plan' => WCOS_Split_Plan::canonicalize_request($context['plan']),
			'execution_policy' => WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			'price_precision' => WCOS_Price_Precision_Scope::validate($context['price_precision']),
				'split_policy_version' => (int) $context['policy_version'],
				'commercial_policy' => $commercial_policy,
			'replay_authority' => 'journal',
		);
	}

	private static function normalize_durable_authority(array $authority) {
		$record = array(
			'strategy' => WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy(isset($authority['strategy']) ? $authority['strategy'] : ''),
			'planner_policy_version' => absint(isset($authority['planner_policy_version']) ? $authority['planner_policy_version'] : 0),
			'review_source_signature' => isset($authority['review_source_signature']) ? sanitize_key((string) $authority['review_source_signature']) : '',
			'classification_fingerprint' => isset($authority['classification_fingerprint']) ? sanitize_key((string) $authority['classification_fingerprint']) : '',
			'source_bucket_key' => sanitize_key(isset($authority['source_bucket_key']) ? (string) $authority['source_bucket_key'] : ''),
		);
		if (!$record['planner_policy_version'] || '' === $record['review_source_signature']
			|| '' === $record['classification_fingerprint'] || '' === $record['source_bucket_key']) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('journal_incomplete', __('The durable Split strategy authority is incomplete.', 'wc-order-splitter'));
		}
		return $record;
	}

	private static function assert_confirmation_matches_durable(array $record, array $durable) {
		$fields = array('strategy', 'planner_policy_version', 'source_signature', 'classification_fingerprint', 'source_bucket_key', 'execution_policy', 'price_precision', 'split_policy_version');
		foreach ($fields as $field) {
			if (!array_key_exists($field, $record) || !array_key_exists($field, $durable)
				|| (string) $record[$field] !== (string) $durable[$field]) {
				throw new WCOS_Split_Strategy_Confirmation_Exception('journal_mismatch', __('The temporary Split strategy confirmation no longer matches durable journal authority.', 'wc-order-splitter'));
			}
		}
		if (WCOS_Split_Plan::canonicalize_request($record['plan']) !== WCOS_Split_Plan::canonicalize_request($durable['plan'])) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('journal_mismatch', __('The temporary Split strategy plan no longer matches durable journal authority.', 'wc-order-splitter'));
		}
		if (!isset($record['commercial_policy']) || !is_array($record['commercial_policy'])
			|| !isset($durable['commercial_policy']) || !is_array($durable['commercial_policy'])) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('journal_mismatch', __('The Split strategy confirmation is missing commercial policy authority.', 'wc-order-splitter'));
		}
		$temporary_policy = WCOS_Split_Commercial_Policy::assert_valid($record['commercial_policy']);
		$durable_policy = WCOS_Split_Commercial_Policy::assert_valid($durable['commercial_policy']);
		if (!hash_equals((string) $temporary_policy['policy_fingerprint'], (string) $durable_policy['policy_fingerprint'])) {
			throw new WCOS_Split_Strategy_Confirmation_Exception('journal_mismatch', __('The temporary Split strategy commercial policy no longer matches durable journal authority.', 'wc-order-splitter'));
		}
	}

	private static function current_planner_policy_version($strategy) {
		switch (WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy)) {
			case WCOS_Split_Strategy_Gates::CATEGORY:
				return (int) WCOS_Category_Split_Planner::POLICY_VERSION;
			case WCOS_Split_Strategy_Gates::STOCK_STATUS:
				return (int) WCOS_Stock_Status_Split_Planner::POLICY_VERSION;
		}
		return 0;
	}

	private static function token_hash($token) {
		return hash_hmac('sha256', (string) $token, wp_salt('auth'));
	}

	private static function key($operation_id) {
		return 'wcos_split_strategy_confirm_' . hash('sha256', sanitize_key((string) $operation_id));
	}

	private static function is_uuid($value) {
		return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value);
	}
}
