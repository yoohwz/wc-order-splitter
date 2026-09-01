<?php

defined('ABSPATH') || exit;

final class WCOS_Merge_Review_Exception extends RuntimeException {
	private $reason;

	public function __construct($reason, $message) {
		$this->reason = sanitize_key((string) $reason);
		parent::__construct((string) $message);
	}

	public function get_reason() {
		return $this->reason;
	}
}

/** Short-lived, server-owned authority for one Merge Review. */
final class WCOS_Merge_Review_Store {
	const SCHEMA_VERSION = 3;
	const TTL = 900;

	public static function create(WC_Order $source, WC_Order $target, array $report, $user_id) {
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		$user_id = absint($user_id);
		if (!$source_id || !$target_id || $source_id === $target_id || !$user_id) {
			throw new WCOS_Merge_Review_Exception('invalid_identity', __('Merge Review requires two distinct persisted orders and a signed-in operator.', 'wc-order-splitter'));
		}
		$participants = WCOS_Merge_Canonical_Reader::shop_order_pair($source_id, $target_id);
		if (!is_array($participants)) {
			throw new WCOS_Merge_Review_Exception('participant_missing', __('A Merge participant is no longer available.', 'wc-order-splitter'));
		}
		list($source, $target) = $participants;

		$authority = self::authority_from_report($report, $source_id, $target_id);
		self::assert_current($source, $target, $authority);
		$review_id = wp_generate_uuid4();
		$token = wp_generate_password(48, false, false);
		$now = time();
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'review_id' => $review_id,
			'token_hash' => self::token_hash($token),
			'user_id' => $user_id,
			'authority' => $authority,
			'created_at' => $now,
			'expires_at' => $now + self::TTL,
		);
		if (!set_transient(self::key($review_id), $record, self::TTL)) {
			throw new RuntimeException(__('Unable to store the temporary Merge Review.', 'wc-order-splitter'));
		}

		return array(
			'review_id' => $review_id,
			'review_token' => $token,
			'expires_at' => $record['expires_at'],
			'authority' => $authority,
		);
	}

	public static function verify(WC_Order $source, WC_Order $target, $review_id, $token, $user_id) {
		$review_id = sanitize_key((string) $review_id);
		$user_id = absint($user_id);
		if (!self::is_uuid($review_id) || !$user_id) {
			throw new WCOS_Merge_Review_Exception('invalid_identity', __('The Merge Review identity is invalid.', 'wc-order-splitter'));
		}
		$participants = WCOS_Merge_Canonical_Reader::shop_order_pair($source->get_id(), $target->get_id());
		if (!is_array($participants)) {
			throw new WCOS_Merge_Review_Exception('participant_missing', __('A Merge participant is no longer available.', 'wc-order-splitter'));
		}
		list($source, $target) = $participants;
		$record = get_transient(self::key($review_id));
		if (!is_array($record)) {
			throw new WCOS_Merge_Review_Exception('expired', __('The Merge Review expired. Review the pair again.', 'wc-order-splitter'));
		}
		if ((int) (isset($record['schema_version']) ? $record['schema_version'] : 0) !== self::SCHEMA_VERSION
			|| empty($record['token_hash']) || '' === (string) $token
			|| !hash_equals((string) $record['token_hash'], self::token_hash($token))) {
			throw new WCOS_Merge_Review_Exception('invalid_token', __('The Merge Review token is invalid.', 'wc-order-splitter'));
		}
		$authority = isset($record['authority']) && is_array($record['authority']) ? $record['authority'] : array();
		self::assert_authority_complete($authority);
		if (absint(isset($record['user_id']) ? $record['user_id'] : 0) !== $user_id
			|| absint(isset($authority['source_order_id']) ? $authority['source_order_id'] : 0) !== $source->get_id()
			|| absint(isset($authority['target_order_id']) ? $authority['target_order_id'] : 0) !== $target->get_id()) {
			throw new WCOS_Merge_Review_Exception('owner_mismatch', __('The Merge Review does not belong to this operator and order pair.', 'wc-order-splitter'));
		}
		if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
			self::delete($review_id);
			throw new WCOS_Merge_Review_Exception('expired', __('The Merge Review expired. Review the pair again.', 'wc-order-splitter'));
		}
		self::assert_current($source, $target, $authority);
		return $authority;
	}

	public static function consume($review_id) {
		return self::delete($review_id);
	}

	public static function delete($review_id) {
		$review_id = sanitize_key((string) $review_id);
		return self::is_uuid($review_id) ? delete_transient(self::key($review_id)) : false;
	}

	private static function authority_from_report(array $report, $source_id, $target_id) {
		if (empty($report['supported'])
			|| absint(isset($report['source_order_id']) ? $report['source_order_id'] : 0) !== $source_id
			|| absint(isset($report['target_order_id']) ? $report['target_order_id'] : 0) !== $target_id
			|| empty($report['plan']) || !is_array($report['plan'])
			|| empty($report['context_authority']) || !is_array($report['context_authority'])) {
			throw new WCOS_Merge_Review_Exception('review_invalid', __('The server Merge Review is incomplete or mismatched.', 'wc-order-splitter'));
		}
		$precision = WCOS_Price_Precision_Scope::validate(isset($report['price_precision']) ? $report['price_precision'] : null);
		$plan = WCOS_Merge_Plan::canonicalize_current($report['plan']);
		$participants = WCOS_Merge_Canonical_Reader::shop_order_pair($source_id, $target_id);
		if (!is_array($participants)) {
			throw new WCOS_Merge_Review_Exception('participant_missing', __('A Merge participant is no longer available.', 'wc-order-splitter'));
		}
		$context = WCOS_Merge_Journal_Context::create_executable(
			$participants[0],
			$participants[1],
			$plan,
			$report['context_authority'],
			$precision
		);
		$pair = $context['merge_pair']['authority'];
		return array(
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'source_signature' => $pair['source_signature'],
			'target_signature' => $pair['target_signature'],
			'plan' => $plan,
			'plan_fingerprint' => $pair['plan_fingerprint'],
			'pair_fingerprint' => $context['merge_pair']['pair_fingerprint'],
			'context_authority' => $pair['context_authority'],
			'context_authority_fingerprint' => $pair['context_authority_fingerprint'],
			'financial_authority_fingerprint' => $pair['financial_authority_fingerprint'],
			'price_precision' => $precision,
			'merge_service_policy_version' => (int) WCOS_Merge_Order_Service::POLICY_VERSION,
			'preflight_policy_version' => (int) $pair['preflight_policy_version'],
			'plan_schema_version' => (int) $pair['plan_schema_version'],
			'context_signature_version' => (int) $pair['context_signature_version'],
			'retirement_policy_schema_version' => (int) $pair['retirement_policy_schema_version'],
			'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
		);
	}

	private static function assert_current(WC_Order $source, WC_Order $target, array $authority) {
		self::assert_authority_complete($authority);
		$precision = WCOS_Price_Precision_Scope::validate(isset($authority['price_precision']) ? $authority['price_precision'] : null);
		$participants = WCOS_Merge_Canonical_Reader::shop_order_pair($source->get_id(), $target->get_id());
		if (!is_array($participants)) {
			throw new WCOS_Merge_Review_Exception('participant_missing', __('A Merge participant is no longer available.', 'wc-order-splitter'));
		}
		list($source, $target) = $participants;
		$report = WCOS_Merge_Preflight::report($source, $target, $precision);
		if (empty($report['supported'])) {
			throw new WCOS_Merge_Review_Exception('pair_changed', __('The Merge pair is no longer supported. Review it again.', 'wc-order-splitter'));
		}
		$current = self::authority_from_report($report, $source->get_id(), $target->get_id());
		foreach (array('source_signature', 'target_signature', 'plan_fingerprint', 'pair_fingerprint', 'context_authority_fingerprint', 'financial_authority_fingerprint', 'price_precision', 'merge_service_policy_version', 'preflight_policy_version', 'plan_schema_version', 'context_signature_version', 'retirement_policy_schema_version', 'retirement_policy') as $field) {
			if (!array_key_exists($field, $authority) || (string) $authority[$field] !== (string) $current[$field]) {
				$reason = 'source_signature' === $field ? 'source_changed' : ('target_signature' === $field ? 'target_changed' : 'authority_changed');
				throw new WCOS_Merge_Review_Exception($reason, __('The Merge pair authority changed after Review. Review the pair again.', 'wc-order-splitter'));
			}
		}
		if ($authority['plan'] !== $current['plan'] || $authority['context_authority'] !== $current['context_authority']) {
			throw new WCOS_Merge_Review_Exception('authority_changed', __('The frozen Merge plan or context changed after Review.', 'wc-order-splitter'));
		}
	}

	private static function assert_authority_complete(array $authority) {
		$required = array(
			'source_order_id', 'target_order_id', 'source_signature', 'target_signature', 'plan', 'plan_fingerprint',
			'pair_fingerprint', 'context_authority', 'context_authority_fingerprint', 'financial_authority_fingerprint', 'price_precision',
			'merge_service_policy_version', 'preflight_policy_version', 'plan_schema_version', 'context_signature_version',
			'retirement_policy_schema_version', 'retirement_policy',
		);
		foreach ($required as $field) {
			if (!array_key_exists($field, $authority)) {
				throw new WCOS_Merge_Review_Exception('review_invalid', __('The stored Merge Review authority is incomplete.', 'wc-order-splitter'));
			}
		}
		if (!absint($authority['source_order_id']) || !absint($authority['target_order_id'])
			|| absint($authority['source_order_id']) === absint($authority['target_order_id'])
			|| !is_array($authority['plan']) || !is_array($authority['context_authority'])
			|| !self::is_fingerprint($authority['source_signature']) || !self::is_fingerprint($authority['target_signature'])
			|| !self::is_fingerprint($authority['plan_fingerprint']) || !self::is_fingerprint($authority['pair_fingerprint'])
			|| !self::is_fingerprint($authority['context_authority_fingerprint'])
			|| !self::is_fingerprint($authority['financial_authority_fingerprint'])) {
			throw new WCOS_Merge_Review_Exception('review_invalid', __('The stored Merge Review authority is malformed.', 'wc-order-splitter'));
		}
		try {
			$precision = WCOS_Price_Precision_Scope::validate($authority['price_precision']);
			$plan_fingerprint = WCOS_Merge_Plan::fingerprint($authority['plan']);
		} catch (Throwable $throwable) {
			throw new WCOS_Merge_Review_Exception('review_invalid', __('The stored Merge Review plan or precision is malformed.', 'wc-order-splitter'));
		}
		if ($precision !== (int) $authority['price_precision']
			|| !hash_equals((string) $authority['plan_fingerprint'], $plan_fingerprint)
			|| (int) $authority['merge_service_policy_version'] !== (int) WCOS_Merge_Order_Service::POLICY_VERSION
			|| (int) $authority['preflight_policy_version'] !== (int) WCOS_Merge_Preflight::POLICY_VERSION
			|| (int) $authority['plan_schema_version'] !== (int) WCOS_Merge_Plan::SCHEMA_VERSION
			|| (int) $authority['context_signature_version'] !== (int) WCOS_Merge_Context_Signature::SCHEMA_VERSION
			|| (int) $authority['retirement_policy_schema_version'] !== (int) WCOS_Merge_Retirement_Policy::SCHEMA_VERSION
			|| WCOS_Merge_Retirement_Policy::approved_identifier() !== sanitize_key((string) $authority['retirement_policy'])) {
			throw new WCOS_Merge_Review_Exception('authority_changed', __('The stored Merge Review policy or plan authority is no longer current.', 'wc-order-splitter'));
		}
	}

	private static function token_hash($token) {
		return hash_hmac('sha256', (string) $token, wp_salt('auth'));
	}

	private static function key($review_id) {
		return 'wcos_merge_review_' . hash('sha256', sanitize_key((string) $review_id));
	}

	private static function is_uuid($value) {
		return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value);
	}

	private static function is_fingerprint($value) {
		return 1 === preg_match('/^[0-9a-f]{64}$/D', (string) $value);
	}
}
