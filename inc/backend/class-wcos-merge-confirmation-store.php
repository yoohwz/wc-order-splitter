<?php

defined('ABSPATH') || exit;

final class WCOS_Merge_Confirmation_Exception extends RuntimeException {
	private $reason;

	public function __construct($reason, $message) {
		$this->reason = sanitize_key((string) $reason);
		parent::__construct((string) $message);
	}

	public function get_reason() {
		return $this->reason;
	}
}

/** Temporary Merge authority until the source-keyed operation journal exists. */
final class WCOS_Merge_Confirmation_Store {
	const SCHEMA_VERSION = 1;
	const TTL = 1800;

	public static function create(WC_Order $source, WC_Order $target, array $review_authority, $user_id) {
		$user_id = absint($user_id);
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		if (!$user_id || !$source_id || !$target_id || $source_id === $target_id) {
			throw new WCOS_Merge_Confirmation_Exception('invalid_identity', __('Merge Confirmation requires a signed-in operator and two distinct persisted orders.', 'wc-order-splitter'));
		}
		if (absint(isset($review_authority['source_order_id']) ? $review_authority['source_order_id'] : 0) !== $source_id
			|| absint(isset($review_authority['target_order_id']) ? $review_authority['target_order_id'] : 0) !== $target_id) {
			throw new WCOS_Merge_Confirmation_Exception('review_mismatch', __('The Merge Review does not match this participant pair.', 'wc-order-splitter'));
		}

		$operation_id = wp_generate_uuid4();
		$token = wp_generate_password(48, false, false);
		$now = time();
		$record = array_merge(
			$review_authority,
			array(
				'schema_version' => self::SCHEMA_VERSION,
				'operation_id' => $operation_id,
				'token_hash' => self::token_hash($token),
				'user_id' => $user_id,
				'created_at' => $now,
				'expires_at' => $now + self::TTL,
			)
		);
		self::assert_complete($record);
		if (!set_transient(self::key($operation_id), $record, self::TTL)) {
			throw new RuntimeException(__('Unable to store the temporary Merge Confirmation.', 'wc-order-splitter'));
		}

		return array(
			'operation_id' => $operation_id,
			'confirmation_token' => $token,
			'expires_at' => $record['expires_at'],
			'record' => $record,
		);
	}

	public static function verify(WC_Order $source, WC_Order $target, $operation_id, $token, $user_id) {
		$operation_id = sanitize_key((string) $operation_id);
		$user_id = absint($user_id);
		if (!self::is_uuid($operation_id) || !$user_id) {
			throw new WCOS_Merge_Confirmation_Exception('invalid_identity', __('The Merge Confirmation identity is invalid.', 'wc-order-splitter'));
		}

		$journal = WCOS_Operation_Journal::get($source, $operation_id);
		$record = get_transient(self::key($operation_id));
		if (is_array($journal)) {
			$durable = self::durable_replay($source, $target, $operation_id, $user_id, $journal);
			if (is_array($record)) {
				self::assert_confirmation_matches_durable($record, $durable);
			}
			return $durable;
		}
		if (!is_array($record)) {
			throw new WCOS_Merge_Confirmation_Exception('expired', __('The Merge Confirmation expired before a journal existed. Review the pair again.', 'wc-order-splitter'));
		}
		if ('' === (string) $token || empty($record['token_hash']) || !hash_equals((string) $record['token_hash'], self::token_hash($token))) {
			throw new WCOS_Merge_Confirmation_Exception('invalid_token', __('The Merge Confirmation token is invalid.', 'wc-order-splitter'));
		}
		if (absint(isset($record['user_id']) ? $record['user_id'] : 0) !== $user_id
			|| absint(isset($record['source_order_id']) ? $record['source_order_id'] : 0) !== $source->get_id()
			|| absint(isset($record['target_order_id']) ? $record['target_order_id'] : 0) !== $target->get_id()) {
			throw new WCOS_Merge_Confirmation_Exception('owner_mismatch', __('The Merge Confirmation does not belong to this operator and order pair.', 'wc-order-splitter'));
		}
		if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
			self::delete($operation_id);
			throw new WCOS_Merge_Confirmation_Exception('expired', __('The Merge Confirmation expired before a journal existed. Review the pair again.', 'wc-order-splitter'));
		}

		self::assert_complete($record);
		$precision = WCOS_Price_Precision_Scope::validate($record['price_precision']);
		$report = WCOS_Merge_Preflight::report(wc_get_order($source->get_id()), wc_get_order($target->get_id()), $precision);
		if (empty($report['supported'])) {
			throw new WCOS_Merge_Confirmation_Exception('pair_changed', __('The Merge pair changed after Confirmation. Review it again.', 'wc-order-splitter'));
		}
		$current_context = WCOS_Merge_Journal_Context::create_executable(
			wc_get_order($source->get_id()),
			wc_get_order($target->get_id()),
			$report['plan'],
			$report['context_authority'],
			$precision
		);
		$current = $current_context['merge_pair']['authority'];
		if (!hash_equals((string) $record['pair_fingerprint'], (string) $current_context['merge_pair']['pair_fingerprint'])
			|| !hash_equals((string) $record['source_signature'], (string) $current['source_signature'])
			|| !hash_equals((string) $record['target_signature'], (string) $current['target_signature'])
			|| $record['plan'] !== $report['plan']) {
			throw new WCOS_Merge_Confirmation_Exception('authority_changed', __('The frozen Merge authority changed after Confirmation. Review the pair again.', 'wc-order-splitter'));
		}

		$record['replay_authority'] = 'confirmation';
		return $record;
	}

	public static function operation_authority(array $record) {
		self::assert_complete($record);
		return array(
			'confirmation_schema_version' => self::SCHEMA_VERSION,
			'operation_id' => sanitize_key((string) $record['operation_id']),
			'operator_user_id' => absint($record['user_id']),
			'source_order_id' => absint($record['source_order_id']),
			'target_order_id' => absint($record['target_order_id']),
			'source_signature' => sanitize_key((string) $record['source_signature']),
			'target_signature' => sanitize_key((string) $record['target_signature']),
			'plan' => $record['plan'],
			'plan_fingerprint' => sanitize_key((string) $record['plan_fingerprint']),
			'pair_fingerprint' => sanitize_key((string) $record['pair_fingerprint']),
			'context_authority_fingerprint' => sanitize_key((string) $record['context_authority_fingerprint']),
			'price_precision' => WCOS_Price_Precision_Scope::validate($record['price_precision']),
			'preflight_policy_version' => absint($record['preflight_policy_version']),
			'plan_schema_version' => absint($record['plan_schema_version']),
			'context_signature_version' => absint($record['context_signature_version']),
			'retirement_policy_schema_version' => absint($record['retirement_policy_schema_version']),
			'retirement_policy' => sanitize_key((string) $record['retirement_policy']),
		);
	}

	public static function delete($operation_id) {
		$operation_id = sanitize_key((string) $operation_id);
		return self::is_uuid($operation_id) ? delete_transient(self::key($operation_id)) : false;
	}

	private static function durable_replay(WC_Order $source, WC_Order $target, $operation_id, $user_id, array $journal) {
		try {
			$pair = WCOS_Merge_Journal_Context::assert_executable_policy($journal);
		} catch (Throwable $throwable) {
			throw new WCOS_Merge_Confirmation_Exception('journal_mismatch', __('The durable Merge journal authority is invalid.', 'wc-order-splitter'));
		}
		$context = isset($journal['context']) && is_array($journal['context']) ? $journal['context'] : array();
		$confirmed = isset($context['merge_confirmation_authority']) && is_array($context['merge_confirmation_authority'])
			? $context['merge_confirmation_authority'] : array();
		if (absint($pair['source_order_id']) !== $source->get_id()
			|| absint($pair['target_order_id']) !== $target->get_id()
			|| sanitize_key(isset($journal['operation_id']) ? (string) $journal['operation_id'] : '') !== $operation_id
			|| absint(isset($confirmed['operator_user_id']) ? $confirmed['operator_user_id'] : 0) !== $user_id
			|| sanitize_key(isset($confirmed['confirmed_pair_fingerprint']) ? (string) $confirmed['confirmed_pair_fingerprint'] : '') !== $pair['pair_fingerprint']) {
			throw new WCOS_Merge_Confirmation_Exception('journal_mismatch', __('The durable Merge journal does not match this operation, operator, and participant pair.', 'wc-order-splitter'));
		}
		$status = sanitize_key(isset($journal['status']) ? (string) $journal['status'] : '');
		if ('manual_reconciliation' === $status) {
			throw new WCOS_Merge_Confirmation_Exception('manual_reconciliation', __('This Merge operation requires manual reconciliation.', 'wc-order-splitter'));
		}
		if (in_array($status, array('compensated', 'manual_reconciled'), true)) {
			throw new WCOS_Merge_Confirmation_Exception('operation_closed', __('This Merge operation is closed and cannot be replayed.', 'wc-order-splitter'));
		}
		if ('completed' === $status) {
			$result = WCOS_Merge_Journal_Context::terminal_result_from_record($journal);
		} elseif (in_array($status, array('recovery_required', 'recovering', 'started', 'committed', 'failed', 'compensating'), true)) {
			if (!WCOS_Operation_Journal::require_recovery($source, $operation_id, array('reason' => 'confirmation_replay'))) {
				throw new WCOS_Merge_Confirmation_Exception('recovery_required', __('The durable Merge operation requires recovery.', 'wc-order-splitter'));
			}
			$result = array();
		} else {
			throw new WCOS_Merge_Confirmation_Exception('journal_mismatch', __('The durable Merge journal has an unsupported state.', 'wc-order-splitter'));
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'operation_id' => $operation_id,
			'user_id' => $user_id,
			'source_order_id' => (int) $pair['source_order_id'],
			'target_order_id' => (int) $pair['target_order_id'],
			'source_signature' => (string) $pair['source_signature'],
			'target_signature' => (string) $pair['target_signature'],
			'plan' => isset($context['merge_plan']) && is_array($context['merge_plan']) ? $context['merge_plan'] : array(),
			'plan_fingerprint' => (string) $pair['plan_fingerprint'],
			'pair_fingerprint' => (string) $pair['pair_fingerprint'],
			'context_authority_fingerprint' => (string) $pair['context_authority_fingerprint'],
			'price_precision' => (int) $pair['price_precision'],
			'preflight_policy_version' => (int) $pair['preflight_policy_version'],
			'plan_schema_version' => (int) $pair['plan_schema_version'],
			'context_signature_version' => (int) $pair['context_signature_version'],
			'retirement_policy_schema_version' => (int) $pair['retirement_policy_schema_version'],
			'retirement_policy' => (string) $pair['retirement_policy_identifier'],
			'replay_authority' => 'journal',
			'journal_status' => $status,
			'terminal_result' => $result,
		);
	}

	private static function assert_confirmation_matches_durable(array $record, array $durable) {
		foreach (array('operation_id', 'user_id', 'source_order_id', 'target_order_id', 'source_signature', 'target_signature', 'plan_fingerprint', 'pair_fingerprint', 'context_authority_fingerprint', 'price_precision', 'preflight_policy_version', 'plan_schema_version', 'context_signature_version', 'retirement_policy_schema_version', 'retirement_policy') as $field) {
			if (!isset($record[$field], $durable[$field]) || (string) $record[$field] !== (string) $durable[$field]) {
				throw new WCOS_Merge_Confirmation_Exception('journal_mismatch', __('Temporary Merge Confirmation no longer matches durable journal authority.', 'wc-order-splitter'));
			}
		}
		if (!empty($durable['plan']) && $record['plan'] !== $durable['plan']) {
			throw new WCOS_Merge_Confirmation_Exception('journal_mismatch', __('The temporary Merge plan no longer matches durable journal authority.', 'wc-order-splitter'));
		}
	}

	private static function assert_complete(array $record) {
		$required = array('operation_id', 'user_id', 'source_order_id', 'target_order_id', 'source_signature', 'target_signature', 'plan', 'plan_fingerprint', 'pair_fingerprint', 'context_authority_fingerprint', 'price_precision', 'preflight_policy_version', 'plan_schema_version', 'context_signature_version', 'retirement_policy_schema_version', 'retirement_policy');
		foreach ($required as $field) {
			if (!array_key_exists($field, $record) || (is_string($record[$field]) && '' === $record[$field])) {
				throw new WCOS_Merge_Confirmation_Exception('authority_incomplete', __('The Merge Confirmation is missing frozen server authority.', 'wc-order-splitter'));
			}
		}
		if (!is_array($record['plan'])
			|| WCOS_Merge_Plan::fingerprint($record['plan']) !== (string) $record['plan_fingerprint']
			|| WCOS_Merge_Retirement_Policy::approved_identifier() !== sanitize_key((string) $record['retirement_policy'])
			|| (int) $record['preflight_policy_version'] !== (int) WCOS_Merge_Preflight::POLICY_VERSION
			|| (int) $record['plan_schema_version'] !== (int) WCOS_Merge_Plan::SCHEMA_VERSION
			|| (int) $record['context_signature_version'] !== (int) WCOS_Merge_Context_Signature::SCHEMA_VERSION
			|| (int) $record['retirement_policy_schema_version'] !== (int) WCOS_Merge_Retirement_Policy::SCHEMA_VERSION) {
			throw new WCOS_Merge_Confirmation_Exception('authority_changed', __('The Merge Confirmation policy, plan, or schema authority changed.', 'wc-order-splitter'));
		}
	}

	private static function token_hash($token) {
		return hash_hmac('sha256', (string) $token, wp_salt('auth'));
	}

	private static function key($operation_id) {
		return 'wcos_merge_confirm_' . hash('sha256', sanitize_key((string) $operation_id));
	}

	private static function is_uuid($value) {
		return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value);
	}
}
