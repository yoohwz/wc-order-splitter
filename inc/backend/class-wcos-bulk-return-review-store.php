<?php

defined('ABSPATH') || exit;

final class WCOS_Bulk_Return_Review_Exception extends RuntimeException {
	private $reason;

	public function __construct($reason, $message) {
		$this->reason = sanitize_key((string) $reason);
		parent::__construct((string) $message);
	}

	public function get_reason() { return $this->reason; }
}

/** Short-lived, operator-bound and single-consumed Bulk Return Review. */
final class WCOS_Bulk_Return_Review_Store {
	const SCHEMA_VERSION = 1;
	const TTL = 900;

	public static function create(array $candidate_ids, $user_id) {
		$user_id = absint($user_id);
		if (!$user_id) {
			throw new WCOS_Bulk_Return_Review_Exception('invalid_identity', __('Bulk Return Review requires a signed-in operator.', 'wc-order-splitter'));
		}
		try {
			$plan = WCOS_Bulk_Return_Batch_Plan::build($candidate_ids);
		} catch (WCOS_Bulk_Return_Batch_Exception $exception) {
			throw new WCOS_Bulk_Return_Review_Exception($exception->get_reason(), $exception->getMessage());
		}
		$review_id = wp_generate_uuid4();
		$token = wp_generate_password(48, false, false);
		$now = time();
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'review_id' => $review_id,
			'token_hash' => self::token_hash($token),
			'user_id' => $user_id,
			'plan' => $plan,
			'created_at' => $now,
			'expires_at' => $now + self::TTL,
		);
		if (!set_transient(self::key($review_id), $record, self::TTL)) {
			throw new RuntimeException(__('Unable to store the temporary Bulk Return Review.', 'wc-order-splitter'));
		}
		return array('review_id' => $review_id, 'review_token' => $token, 'expires_at' => $record['expires_at'], 'plan' => $plan);
	}

	public static function verify($review_id, $token, $user_id) {
		$review_id = sanitize_key((string) $review_id);
		$user_id = absint($user_id);
		if (!self::is_uuid($review_id) || !$user_id) {
			throw new WCOS_Bulk_Return_Review_Exception('invalid_identity', __('The Bulk Return Review identity is invalid.', 'wc-order-splitter'));
		}
		$record = get_transient(self::key($review_id));
		if (!is_array($record)) {
			throw new WCOS_Bulk_Return_Review_Exception('expired', __('The Bulk Return Review expired or was consumed.', 'wc-order-splitter'));
		}
		self::assert_owner($record, $token, $user_id);
		if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
			self::delete($review_id);
			throw new WCOS_Bulk_Return_Review_Exception('expired', __('The Bulk Return Review expired.', 'wc-order-splitter'));
		}
		try {
			WCOS_Bulk_Return_Batch_Plan::assert_review_current($record['plan']);
		} catch (WCOS_Bulk_Return_Batch_Exception $exception) {
			throw new WCOS_Bulk_Return_Review_Exception($exception->get_reason(), $exception->getMessage());
		}
		return $record['plan'];
	}

	/** Atomic compare-and-delete using the accepted DB-backed transient pattern. */
	public static function consume($review_id, $token, $user_id) {
		$review_id = sanitize_key((string) $review_id);
		$user_id = absint($user_id);
		if (!self::is_uuid($review_id) || !$user_id || wp_using_ext_object_cache()) {
			throw new WCOS_Bulk_Return_Review_Exception('atomic_storage_unavailable', __('The current Bulk Review storage cannot prove single-consumption safely.', 'wc-order-splitter'));
		}
		$transient_key = self::key($review_id);
		$record = get_transient($transient_key);
		if (!is_array($record)) { return false; }
		self::assert_owner($record, $token, $user_id);

		global $wpdb;
		$option_name = '_transient_' . $transient_key;
		$deleted = $wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			$option_name,
			maybe_serialize($record)
		));
		if (1 !== (int) $deleted) {
			wp_cache_delete($option_name, 'options');
			return false;
		}
		$timeout_name = '_transient_timeout_' . $transient_key;
		$wpdb->delete($wpdb->options, array('option_name' => $timeout_name), array('%s'));
		wp_cache_delete($option_name, 'options');
		wp_cache_delete($timeout_name, 'options');
		wp_cache_delete($transient_key, 'transient');
		return true;
	}

	public static function delete($review_id) {
		$review_id = sanitize_key((string) $review_id);
		return self::is_uuid($review_id) ? delete_transient(self::key($review_id)) : false;
	}

	private static function assert_owner(array $record, $token, $user_id) {
		if (self::SCHEMA_VERSION !== (int) (isset($record['schema_version']) ? $record['schema_version'] : 0)
			|| absint(isset($record['user_id']) ? $record['user_id'] : 0) !== absint($user_id)
			|| '' === (string) $token || empty($record['token_hash'])
			|| !hash_equals((string) $record['token_hash'], self::token_hash($token))
			|| empty($record['plan']) || !is_array($record['plan'])) {
			throw new WCOS_Bulk_Return_Review_Exception('invalid_token', __('The Bulk Return Review token or owner is invalid.', 'wc-order-splitter'));
		}
		try {
			WCOS_Bulk_Return_Batch_Plan::assert_valid($record['plan']);
		} catch (WCOS_Bulk_Return_Batch_Exception $exception) {
			throw new WCOS_Bulk_Return_Review_Exception('review_invalid', __('The stored Bulk Return Review authority is malformed.', 'wc-order-splitter'));
		}
	}

	private static function token_hash($token) { return hash_hmac('sha256', (string) $token, wp_salt('auth')); }
	private static function key($review_id) { return 'wcos_bulk_return_review_' . hash('sha256', sanitize_key((string) $review_id)); }
	private static function is_uuid($value) { return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value); }
}
