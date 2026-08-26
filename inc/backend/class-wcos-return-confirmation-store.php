<?php

defined('ABSPATH') || exit;

final class WCOS_Return_Confirmation_Exception extends RuntimeException {
	private $reason;

	public function __construct($reason, $message) {
		$this->reason = sanitize_key((string) $reason);
		parent::__construct((string) $message);
	}

	public function get_reason() {
		return $this->reason;
	}
}

/** Temporary Return authority until the exact child-keyed journal exists. */
final class WCOS_Return_Confirmation_Store {
	const SCHEMA_VERSION = 1;
	const TTL = 1800;

	public static function create(WC_Order $child, array $review_authority, $user_id) {
		$child_id = absint($child->get_id());
		$user_id = absint($user_id);
		if (!$child_id || !$user_id || 'shop_order' !== $child->get_type()) {
			throw new WCOS_Return_Confirmation_Exception('invalid_identity', __('Return Confirmation requires one persisted child and a signed-in operator.', 'wc-order-splitter'));
		}
		if (absint(isset($review_authority['child_order_id']) ? $review_authority['child_order_id'] : 0) !== $child_id) {
			throw new WCOS_Return_Confirmation_Exception('review_mismatch', __('The Return Review does not match this child.', 'wc-order-splitter'));
		}
		try {
			WCOS_Return_Review_Store::assert_matches_current($child, $review_authority);
		} catch (WCOS_Return_Review_Exception $exception) {
			throw new WCOS_Return_Confirmation_Exception('authority_changed', $exception->getMessage());
		}

		$operation_id = wp_generate_uuid4();
		$token = wp_generate_password(48, false, false);
		$now = time();
		$record = array_merge($review_authority, array(
			'schema_version' => self::SCHEMA_VERSION,
			'operation_id' => $operation_id,
			'token_hash' => self::token_hash($token),
			'user_id' => $user_id,
			'created_at' => $now,
			'expires_at' => $now + self::TTL,
		));
		self::assert_complete($record);
		if (!set_transient(self::key($operation_id), $record, self::TTL)) {
			throw new RuntimeException(__('Unable to store the temporary Return Confirmation.', 'wc-order-splitter'));
		}

		return array(
			'operation_id' => $operation_id,
			'confirmation_token' => $token,
			'expires_at' => $record['expires_at'],
			'record' => $record,
		);
	}

	public static function verify(WC_Order $child, $operation_id, $token, $user_id) {
		$operation_id = sanitize_key((string) $operation_id);
		$user_id = absint($user_id);
		if (!self::is_uuid($operation_id) || !$user_id) {
			throw new WCOS_Return_Confirmation_Exception('invalid_identity', __('The Return Confirmation identity is invalid.', 'wc-order-splitter'));
		}
		$journal = WCOS_Operation_Journal::get($child, $operation_id);
		$record = get_transient(self::key($operation_id));
		if (is_array($journal)) {
			$durable = self::durable_replay($child, $operation_id, $user_id, $journal);
			if (is_array($record)) {
				self::assert_confirmation_matches_durable($record, $durable);
			}
			return $durable;
		}
		if (!is_array($record)) {
			throw new WCOS_Return_Confirmation_Exception('expired', __('The Return Confirmation expired before a journal existed. Review the child again.', 'wc-order-splitter'));
		}
		if ('' === (string) $token || empty($record['token_hash'])
			|| !hash_equals((string) $record['token_hash'], self::token_hash($token))) {
			throw new WCOS_Return_Confirmation_Exception('invalid_token', __('The Return Confirmation token is invalid.', 'wc-order-splitter'));
		}
		if (absint(isset($record['user_id']) ? $record['user_id'] : 0) !== $user_id
			|| absint(isset($record['child_order_id']) ? $record['child_order_id'] : 0) !== $child->get_id()) {
			throw new WCOS_Return_Confirmation_Exception('owner_mismatch', __('The Return Confirmation does not belong to this operator and child.', 'wc-order-splitter'));
		}
		if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
			self::delete($operation_id);
			throw new WCOS_Return_Confirmation_Exception('expired', __('The Return Confirmation expired before a journal existed. Review the child again.', 'wc-order-splitter'));
		}
		self::assert_complete($record);
		try {
			WCOS_Return_Review_Store::assert_matches_current($child, self::review_authority($record));
		} catch (WCOS_Return_Review_Exception $exception) {
			throw new WCOS_Return_Confirmation_Exception('authority_changed', __('The frozen Return authority changed after Confirmation. Review the child again.', 'wc-order-splitter'));
		}
		$record['replay_authority'] = 'confirmation';
		return $record;
	}

	/** PII-free authority passed from the controller/gateway into the existing Return journal. */
	public static function operation_authority(array $record) {
		self::assert_complete($record);
		return array(
			'confirmation_schema_version' => self::SCHEMA_VERSION,
			'operation_id' => sanitize_key((string) $record['operation_id']),
			'operator_user_id' => absint($record['user_id']),
			'child_order_id' => absint($record['child_order_id']),
			'original_order_id' => absint($record['original_order_id']),
			'split_operation_id' => sanitize_key((string) $record['split_operation_id']),
			'split_child_key' => sanitize_key((string) $record['split_child_key']),
			'pair_fingerprint' => sanitize_key((string) $record['pair_fingerprint']),
			'plan_fingerprint' => sanitize_key((string) $record['plan_fingerprint']),
			'lineage_authority_fingerprint' => sanitize_key((string) $record['lineage_authority_fingerprint']),
			'source_evolution_authority_fingerprint' => sanitize_key((string) $record['source_evolution_authority_fingerprint']),
			'price_precision' => WCOS_Price_Precision_Scope::validate($record['price_precision']),
			'currency' => (string) $record['currency'],
			'prices_include_tax' => (bool) $record['prices_include_tax'],
			'return_service_policy_version' => absint($record['return_service_policy_version']),
			'preflight_policy_version' => absint($record['preflight_policy_version']),
			'plan_schema_version' => absint($record['plan_schema_version']),
			'plan_policy_version' => absint($record['plan_policy_version']),
			'lineage_schema_version' => absint($record['lineage_schema_version']),
			'lineage_policy_version' => absint($record['lineage_policy_version']),
			'journal_context_schema_version' => absint($record['journal_context_schema_version']),
			'retirement_policy_schema_version' => absint($record['retirement_policy_schema_version']),
			'retirement_policy_identifier' => sanitize_key((string) $record['retirement_policy_identifier']),
			'stock_ownership_policy' => sanitize_key((string) $record['stock_ownership_policy']),
			'order_stock_flag_policy' => sanitize_key((string) $record['order_stock_flag_policy']),
		);
	}

	public static function delete($operation_id) {
		$operation_id = sanitize_key((string) $operation_id);
		return self::is_uuid($operation_id) ? delete_transient(self::key($operation_id)) : false;
	}

	private static function durable_replay(WC_Order $child, $operation_id, $user_id, array $journal) {
		try {
			$confirmed = WCOS_Return_Journal_Context::confirmation_handoff_from_record($journal);
		} catch (Throwable $throwable) {
			throw new WCOS_Return_Confirmation_Exception('journal_mismatch', __('The durable Return Confirmation authority is invalid.', 'wc-order-splitter'));
		}
		if (absint($confirmed['child_order_id']) !== $child->get_id()
			|| sanitize_key((string) $confirmed['operation_id']) !== $operation_id
			|| absint($confirmed['operator_user_id']) !== $user_id
			|| (int) $confirmed['confirmation_schema_version'] !== self::SCHEMA_VERSION) {
			throw new WCOS_Return_Confirmation_Exception('journal_mismatch', __('The durable Return journal does not match this operation, operator, and child.', 'wc-order-splitter'));
		}
		$original = wc_get_order(absint($confirmed['original_order_id']));
		if (!$original instanceof WC_Order) {
			throw new WCOS_Return_Confirmation_Exception('participant_missing', __('The durable Return original is unavailable.', 'wc-order-splitter'));
		}
		try {
			WCOS_Order_Mutation_Authorizer::assert_return($child, $original);
		} catch (Throwable $throwable) {
			throw new WCOS_Return_Confirmation_Exception('owner_mismatch', __('The operator is no longer authorized for this durable Return pair.', 'wc-order-splitter'));
		}
		$context = isset($journal['context']) && is_array($journal['context']) ? $journal['context'] : array();
		$record = array_merge($confirmed, array(
			'schema_version' => self::SCHEMA_VERSION,
			'user_id' => $user_id,
			'plan' => isset($context['return_plan']) && is_array($context['return_plan']) ? $context['return_plan'] : array(),
			'pair_authority' => isset($context['return_pair']['authority']) && is_array($context['return_pair']['authority']) ? $context['return_pair']['authority'] : array(),
			'replay_authority' => 'journal',
		));
		self::assert_complete($record);
		return $record;
	}

	private static function assert_complete(array $record) {
		$required = array(
			'operation_id', 'user_id', 'child_order_id', 'original_order_id', 'plan', 'pair_authority',
			'plan_fingerprint', 'pair_fingerprint', 'lineage_authority_fingerprint', 'source_evolution_authority_fingerprint',
			'price_precision', 'currency', 'prices_include_tax', 'return_service_policy_version', 'preflight_policy_version',
			'plan_schema_version', 'plan_policy_version', 'lineage_schema_version', 'lineage_policy_version',
			'journal_context_schema_version', 'retirement_policy_schema_version', 'retirement_policy_identifier',
			'split_operation_id', 'split_child_key', 'stock_ownership_policy', 'order_stock_flag_policy',
		);
		foreach ($required as $field) {
			if (!array_key_exists($field, $record)) {
				throw new WCOS_Return_Confirmation_Exception('confirmation_invalid', __('The stored Return Confirmation authority is incomplete.', 'wc-order-splitter'));
			}
		}
		if ((int) (isset($record['schema_version']) ? $record['schema_version'] : 0) !== self::SCHEMA_VERSION
			|| !self::is_uuid($record['operation_id']) || !absint($record['user_id'])
			|| !is_array($record['plan']) || !is_array($record['pair_authority'])
			|| !hash_equals((string) $record['plan_fingerprint'], WCOS_Return_Plan::fingerprint($record['plan']))) {
			throw new WCOS_Return_Confirmation_Exception('confirmation_invalid', __('The stored Return Confirmation plan is malformed.', 'wc-order-splitter'));
		}
		$context = array('return_pair' => array(
			'schema_version' => WCOS_Return_Journal_Context::SCHEMA_VERSION,
			'authority' => $record['pair_authority'],
			'pair_fingerprint' => $record['pair_fingerprint'],
		));
		try {
			WCOS_Return_Journal_Context::create_confirmation_handoff($context, $record['operation_id'], self::operation_authority_unchecked($record));
		} catch (Throwable $throwable) {
			throw new WCOS_Return_Confirmation_Exception('authority_changed', __('The stored Return Confirmation no longer matches current policy authority.', 'wc-order-splitter'));
		}
	}

	private static function operation_authority_unchecked(array $record) {
		return array(
			'confirmation_schema_version' => self::SCHEMA_VERSION,
			'operation_id' => sanitize_key((string) $record['operation_id']),
			'operator_user_id' => absint($record['user_id']),
			'child_order_id' => absint($record['child_order_id']),
			'original_order_id' => absint($record['original_order_id']),
			'split_operation_id' => sanitize_key((string) $record['split_operation_id']),
			'split_child_key' => sanitize_key((string) $record['split_child_key']),
			'pair_fingerprint' => sanitize_key((string) $record['pair_fingerprint']),
			'plan_fingerprint' => sanitize_key((string) $record['plan_fingerprint']),
			'lineage_authority_fingerprint' => sanitize_key((string) $record['lineage_authority_fingerprint']),
			'source_evolution_authority_fingerprint' => sanitize_key((string) $record['source_evolution_authority_fingerprint']),
			'price_precision' => (int) $record['price_precision'],
			'currency' => (string) $record['currency'],
			'prices_include_tax' => (bool) $record['prices_include_tax'],
			'return_service_policy_version' => (int) $record['return_service_policy_version'],
			'preflight_policy_version' => (int) $record['preflight_policy_version'],
			'plan_schema_version' => (int) $record['plan_schema_version'],
			'plan_policy_version' => (int) $record['plan_policy_version'],
			'lineage_schema_version' => (int) $record['lineage_schema_version'],
			'lineage_policy_version' => (int) $record['lineage_policy_version'],
			'journal_context_schema_version' => (int) $record['journal_context_schema_version'],
			'retirement_policy_schema_version' => (int) $record['retirement_policy_schema_version'],
			'retirement_policy_identifier' => sanitize_key((string) $record['retirement_policy_identifier']),
			'stock_ownership_policy' => sanitize_key((string) $record['stock_ownership_policy']),
			'order_stock_flag_policy' => sanitize_key((string) $record['order_stock_flag_policy']),
		);
	}

	private static function review_authority(array $record) {
		$review = $record;
		foreach (array('schema_version', 'operation_id', 'token_hash', 'user_id', 'created_at', 'expires_at', 'replay_authority', 'confirmation_schema_version', 'operator_user_id', 'authority_fingerprint') as $field) {
			unset($review[$field]);
		}
		return $review;
	}

	private static function assert_confirmation_matches_durable(array $record, array $durable) {
		$temporary = self::operation_authority($record);
		$journal = self::operation_authority($durable);
		if ($temporary !== $journal) {
			throw new WCOS_Return_Confirmation_Exception('journal_mismatch', __('The temporary Return Confirmation conflicts with durable journal authority.', 'wc-order-splitter'));
		}
	}

	private static function token_hash($token) {
		return hash_hmac('sha256', (string) $token, wp_salt('auth'));
	}

	private static function key($operation_id) {
		return 'wcos_return_confirm_' . hash('sha256', sanitize_key((string) $operation_id));
	}

	private static function is_uuid($value) {
		return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value);
	}
}
