<?php

defined('ABSPATH') || exit;

final class WCOS_Split_Strategy_Review_Exception extends RuntimeException {
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
 * Short-lived server-side authority for one Category/Stock-status Review.
 *
 * Clients receive only an opaque review ID/token plus the read-only review
 * payload for display. Confirm must reload the authoritative review from this
 * store; client-supplied review evidence is never confirmation authority.
 */
final class WCOS_Split_Strategy_Review_Store {
	const SCHEMA_VERSION = 1;
	const TTL = 900;

	public static function create(WC_Order $source, $strategy, array $review, $user_id) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		$user_id = absint($user_id);
		$source_id = absint($source->get_id());
		if (!$source_id || !$user_id) {
			throw new InvalidArgumentException(__('A persisted order and signed-in user are required to store a Split strategy review.', 'wc-order-splitter'));
		}
		if (empty($review['supported'])
			|| absint(isset($review['order_id']) ? $review['order_id'] : 0) !== $source_id
			|| sanitize_key(isset($review['strategy']) ? (string) $review['strategy'] : '') !== $strategy
			|| empty($review['source_signature'])
			|| empty($review['classification_fingerprint'])
			|| empty($review['buckets'])
			|| !is_array($review['buckets'])) {
			throw new WCOS_Split_Strategy_Review_Exception('review_invalid', __('The Split strategy review is incomplete or does not match this order.', 'wc-order-splitter'));
		}

		$current = wc_get_order($source_id);
		if (!$current instanceof WC_Order) {
			throw new WCOS_Split_Strategy_Review_Exception('source_missing', __('The source order is no longer available.', 'wc-order-splitter'));
		}
		$actual_signature = WCOS_Order_Contract_Snapshot::source_signature($current);
		if (!hash_equals((string) $review['source_signature'], $actual_signature)) {
			throw new WCOS_Split_Strategy_Review_Exception('source_changed', __('The source order changed before its Split strategy review could be stored.', 'wc-order-splitter'));
		}

		$review_id = wp_generate_uuid4();
		$token = wp_generate_password(48, false, false);
		$now = time();
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'review_id' => $review_id,
			'token_hash' => self::token_hash($token),
			'source_order_id' => $source_id,
			'user_id' => $user_id,
			'strategy' => $strategy,
			'review' => $review,
			'created_at' => $now,
			'expires_at' => $now + self::TTL,
		);
		if (!set_transient(self::key($review_id), $record, self::TTL)) {
			throw new RuntimeException(__('Unable to store the temporary Split strategy review.', 'wc-order-splitter'));
		}

		return array(
			'review_id' => $review_id,
			'review_token' => $token,
			'expires_at' => $record['expires_at'],
			'review' => $review,
		);
	}

	public static function verify(WC_Order $source, $strategy, $review_id, $token, $user_id) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		$review_id = sanitize_key((string) $review_id);
		$user_id = absint($user_id);
		if (!self::is_uuid($review_id) || !$user_id) {
			throw new WCOS_Split_Strategy_Review_Exception('invalid_identity', __('The Split strategy review identity is invalid.', 'wc-order-splitter'));
		}

		$record = get_transient(self::key($review_id));
		if (!is_array($record)) {
			throw new WCOS_Split_Strategy_Review_Exception('expired', __('The Split strategy review expired. Review the order again.', 'wc-order-splitter'));
		}
		if ('' === (string) $token || empty($record['token_hash']) || !hash_equals((string) $record['token_hash'], self::token_hash($token))) {
			throw new WCOS_Split_Strategy_Review_Exception('invalid_token', __('The Split strategy review token is invalid.', 'wc-order-splitter'));
		}
		if (absint(isset($record['source_order_id']) ? $record['source_order_id'] : 0) !== $source->get_id()
			|| absint(isset($record['user_id']) ? $record['user_id'] : 0) !== $user_id
			|| sanitize_key(isset($record['strategy']) ? (string) $record['strategy'] : '') !== $strategy) {
			throw new WCOS_Split_Strategy_Review_Exception('owner_mismatch', __('The Split strategy review does not belong to this user, order, and strategy.', 'wc-order-splitter'));
		}
		if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
			self::delete($review_id);
			throw new WCOS_Split_Strategy_Review_Exception('expired', __('The Split strategy review expired. Review the order again.', 'wc-order-splitter'));
		}

		$review = isset($record['review']) && is_array($record['review']) ? $record['review'] : array();
		if (empty($review['source_signature'])) {
			throw new WCOS_Split_Strategy_Review_Exception('review_invalid', __('The stored Split strategy review is incomplete.', 'wc-order-splitter'));
		}
		$current = wc_get_order($source->get_id());
		if (!$current instanceof WC_Order) {
			throw new WCOS_Split_Strategy_Review_Exception('source_missing', __('The source order is no longer available.', 'wc-order-splitter'));
		}
		$current_signature = WCOS_Order_Contract_Snapshot::source_signature($current);
		if (!hash_equals((string) $review['source_signature'], $current_signature)) {
			throw new WCOS_Split_Strategy_Review_Exception('source_changed', __('The source order changed after Review. Review the strategy again.', 'wc-order-splitter'));
		}

		return $review;
	}

	public static function consume($review_id) {
		return self::delete($review_id);
	}

	public static function delete($review_id) {
		$review_id = sanitize_key((string) $review_id);
		return self::is_uuid($review_id) ? delete_transient(self::key($review_id)) : false;
	}

	private static function token_hash($token) {
		return hash_hmac('sha256', (string) $token, wp_salt('auth'));
	}

	private static function key($review_id) {
		return 'wcos_split_strategy_review_' . hash('sha256', sanitize_key((string) $review_id));
	}

	private static function is_uuid($value) {
		return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value);
	}
}
