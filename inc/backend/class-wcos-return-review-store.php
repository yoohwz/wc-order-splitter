<?php

defined('ABSPATH') || exit;

final class WCOS_Return_Review_Exception extends RuntimeException {
	private $reason;

	public function __construct($reason, $message) {
		$this->reason = sanitize_key((string) $reason);
		parent::__construct((string) $message);
	}

	public function get_reason() {
		return $this->reason;
	}
}

/** Short-lived, PII-free authority for one server-resolved Return Review. */
final class WCOS_Return_Review_Store {
	const SCHEMA_VERSION = 1;
	const TTL = 900;

	public static function create(WC_Order $child, array $report, $user_id) {
		$child_id = absint($child->get_id());
		$user_id = absint($user_id);
		if (!$child_id || !$user_id || 'shop_order' !== $child->get_type()) {
			throw new WCOS_Return_Review_Exception('invalid_identity', __('Return Review requires one persisted child and a signed-in operator.', 'wc-order-splitter'));
		}
		$authority = self::authority_from_report($report, $child_id);
		self::assert_current($child, $authority);

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
			throw new RuntimeException(__('Unable to store the temporary Return Review.', 'wc-order-splitter'));
		}

		return array(
			'review_id' => $review_id,
			'review_token' => $token,
			'expires_at' => $record['expires_at'],
			'authority' => $authority,
		);
	}

	public static function verify(WC_Order $child, $review_id, $token, $user_id) {
		$review_id = sanitize_key((string) $review_id);
		$user_id = absint($user_id);
		if (!self::is_uuid($review_id) || !$user_id) {
			throw new WCOS_Return_Review_Exception('invalid_identity', __('The Return Review identity is invalid.', 'wc-order-splitter'));
		}
		$record = get_transient(self::key($review_id));
		if (!is_array($record)) {
			throw new WCOS_Return_Review_Exception('expired', __('The Return Review expired or was already consumed. Review the child again.', 'wc-order-splitter'));
		}
		self::assert_record_owner($record, $child, $token, $user_id);
		if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
			self::delete($review_id);
			throw new WCOS_Return_Review_Exception('expired', __('The Return Review expired. Review the child again.', 'wc-order-splitter'));
		}
		$authority = isset($record['authority']) && is_array($record['authority']) ? $record['authority'] : array();
		self::assert_current($child, $authority);
		return $authority;
	}

	public static function assert_matches_current(WC_Order $child, array $authority) {
		self::assert_current($child, $authority);
		return true;
	}

	/**
	 * Build the ordinary Return authority for a server-owned coordination flow.
	 *
	 * This does not persist or consume Review state. Callers must still prove
	 * their own authenticated server authority before using the result.
	 */
	public static function authority_from_preflight(WC_Order $child, array $report) {
		return self::authority_from_report($report, $child->get_id());
	}

	/**
	 * Atomically compare-and-delete the exact DB-backed transient record.
	 *
	 * Two Confirm workers may both finish read-only verification and create an
	 * unexposed candidate. Only one can delete the exact reviewed record; every
	 * loser must delete its candidate before returning it to a client.
	 */
	public static function consume(WC_Order $child, $review_id, $token, $user_id) {
		$review_id = sanitize_key((string) $review_id);
		$user_id = absint($user_id);
		if (!self::is_uuid($review_id) || !$user_id || wp_using_ext_object_cache()) {
			throw new WCOS_Return_Review_Exception('atomic_storage_unavailable', __('The current Review storage cannot prove single-consumption safely.', 'wc-order-splitter'));
		}
		$transient_key = self::key($review_id);
		$record = get_transient($transient_key);
		if (!is_array($record)) {
			return false;
		}
		self::assert_record_owner($record, $child, $token, $user_id);

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

	private static function authority_from_report(array $report, $child_id) {
		if (empty($report['supported'])
			|| absint(isset($report['child_order_id']) ? $report['child_order_id'] : 0) !== $child_id
			|| empty($report['source_order_id'])
			|| empty($report['return_plan']) || !is_array($report['return_plan'])
			|| empty($report['lineage_authority']) || !is_array($report['lineage_authority'])) {
			throw new WCOS_Return_Review_Exception('review_invalid', __('The server Return Review is incomplete or mismatched.', 'wc-order-splitter'));
		}
		$child = wc_get_order($child_id);
		$original = wc_get_order(absint($report['source_order_id']));
		if (!$child instanceof WC_Order || !$original instanceof WC_Order) {
			throw new WCOS_Return_Review_Exception('participant_missing', __('A Return participant is no longer available.', 'wc-order-splitter'));
		}
		$plan = $report['return_plan'];
		$lineage = $report['lineage_authority'];
		$context = WCOS_Return_Journal_Context::create($child, $original, $plan, $lineage, $lineage['source_evolution_authority'], '', array(), true);
		$pair = WCOS_Return_Journal_Context::pair_from_context($context);
		if (!is_array($pair)) {
			throw new WCOS_Return_Review_Exception('review_invalid', __('The canonical Return pair authority could not be frozen.', 'wc-order-splitter'));
		}
		return array(
			'child_order_id' => $child_id,
			'original_order_id' => $original->get_id(),
			'split_operation_id' => $pair['split_operation_id'],
			'split_child_key' => $pair['split_child_key'],
			'plan' => $plan,
			'plan_fingerprint' => $pair['plan_fingerprint'],
			'pair_authority' => $context['return_pair']['authority'],
			'confirmation_provenance' => $context['return_pair']['confirmation_provenance'],
			'pair_fingerprint' => $pair['pair_fingerprint'],
			'lineage_authority_fingerprint' => $pair['lineage_authority_fingerprint'],
			'source_evolution_authority_fingerprint' => $pair['source_evolution_authority_fingerprint'],
			'price_precision' => $pair['price_precision'],
			'currency' => $pair['currency'],
			'prices_include_tax' => $pair['prices_include_tax'],
			'return_service_policy_version' => WCOS_Return_Order_Service::POLICY_VERSION,
			'preflight_policy_version' => $pair['preflight_policy_version'],
			'plan_schema_version' => $pair['plan_schema_version'],
			'plan_policy_version' => $pair['plan_policy_version'],
			'lineage_schema_version' => $pair['lineage_schema_version'],
			'lineage_policy_version' => $pair['lineage_policy_version'],
			'journal_context_schema_version' => WCOS_Return_Journal_Context::SCHEMA_VERSION,
			'retirement_policy_schema_version' => $pair['retirement_policy_schema_version'],
			'retirement_policy_identifier' => $pair['retirement_policy_identifier'],
			'stock_ownership_policy' => $pair['stock_ownership_policy'],
			'order_stock_flag_policy' => $pair['order_stock_flag_policy'],
		);
	}

	private static function assert_current(WC_Order $child, array $authority) {
		self::assert_authority_complete($authority);
		$current_child = wc_get_order($child->get_id());
		if (!$current_child instanceof WC_Order) {
			throw new WCOS_Return_Review_Exception('participant_missing', __('The Return child is no longer available.', 'wc-order-splitter'));
		}
		$report = (new WCOS_Return_WooCommerce_Adapter())->preflight($current_child);
		if (empty($report['supported'])) {
			throw new WCOS_Return_Review_Exception('pair_changed', __('The Return pair is no longer supported. Review the child again.', 'wc-order-splitter'));
		}
		$current = self::authority_from_report($report, $current_child->get_id());
		foreach ($authority as $field => $value) {
			if (!array_key_exists($field, $current) || $value !== $current[$field]) {
				$reason = 'authority_changed';
				if ('pair_authority' === $field && is_array($value) && is_array($current[$field])) {
					if ((string) $value['child_signature_before'] !== (string) $current[$field]['child_signature_before']) { $reason = 'child_changed'; }
					elseif ((string) $value['original_signature_before'] !== (string) $current[$field]['original_signature_before']) { $reason = 'original_changed'; }
					elseif ((string) $value['original_relation_signature_before'] !== (string) $current[$field]['original_relation_signature_before']) { $reason = 'relation_changed'; }
				}
				throw new WCOS_Return_Review_Exception($reason, __('The Return authority changed after Review. Review the child again.', 'wc-order-splitter'));
			}
		}
	}

	private static function assert_authority_complete(array $authority) {
		$required = array(
			'child_order_id', 'original_order_id', 'split_operation_id', 'split_child_key', 'plan', 'plan_fingerprint',
			'pair_authority', 'confirmation_provenance', 'pair_fingerprint', 'lineage_authority_fingerprint', 'source_evolution_authority_fingerprint',
			'price_precision', 'currency', 'prices_include_tax', 'return_service_policy_version', 'preflight_policy_version',
			'plan_schema_version', 'plan_policy_version', 'lineage_schema_version', 'lineage_policy_version',
			'journal_context_schema_version', 'retirement_policy_schema_version', 'retirement_policy_identifier',
			'stock_ownership_policy', 'order_stock_flag_policy',
		);
		foreach ($required as $field) {
			if (!array_key_exists($field, $authority)) {
				throw new WCOS_Return_Review_Exception('review_invalid', __('The stored Return Review authority is incomplete.', 'wc-order-splitter'));
			}
		}
		if (!is_array($authority['plan']) || !is_array($authority['pair_authority']) || !is_array($authority['confirmation_provenance'])
			|| !hash_equals((string) $authority['plan_fingerprint'], WCOS_Return_Plan::fingerprint($authority['plan']))) {
			throw new WCOS_Return_Review_Exception('review_invalid', __('The stored Return Review plan authority is malformed.', 'wc-order-splitter'));
		}
		$context = array('return_pair' => array(
			'schema_version' => WCOS_Return_Journal_Context::SCHEMA_VERSION,
			'authority' => $authority['pair_authority'],
			'confirmation_provenance' => $authority['confirmation_provenance'],
			'pair_fingerprint' => $authority['pair_fingerprint'],
		));
		$pair = WCOS_Return_Journal_Context::pair_from_context($context);
		if (!is_array($pair)
			|| absint($authority['child_order_id']) !== $pair['child_order_id']
			|| absint($authority['original_order_id']) !== $pair['original_order_id']
			|| (int) $authority['return_service_policy_version'] !== WCOS_Return_Order_Service::POLICY_VERSION
			|| (int) $authority['journal_context_schema_version'] !== WCOS_Return_Journal_Context::SCHEMA_VERSION) {
			throw new WCOS_Return_Review_Exception('authority_changed', __('The stored Return Review policy authority is no longer current.', 'wc-order-splitter'));
		}
	}

	private static function assert_record_owner(array $record, WC_Order $child, $token, $user_id) {
		if ((int) (isset($record['schema_version']) ? $record['schema_version'] : 0) !== self::SCHEMA_VERSION
			|| empty($record['token_hash']) || '' === (string) $token
			|| !hash_equals((string) $record['token_hash'], self::token_hash($token))) {
			throw new WCOS_Return_Review_Exception('invalid_token', __('The Return Review token is invalid.', 'wc-order-splitter'));
		}
		$authority = isset($record['authority']) && is_array($record['authority']) ? $record['authority'] : array();
		if (absint(isset($record['user_id']) ? $record['user_id'] : 0) !== absint($user_id)
			|| absint(isset($authority['child_order_id']) ? $authority['child_order_id'] : 0) !== $child->get_id()) {
			throw new WCOS_Return_Review_Exception('owner_mismatch', __('The Return Review does not belong to this operator and child.', 'wc-order-splitter'));
		}
	}

	private static function token_hash($token) {
		return hash_hmac('sha256', (string) $token, wp_salt('auth'));
	}

	private static function key($review_id) {
		return 'wcos_return_review_' . hash('sha256', sanitize_key((string) $review_id));
	}

	private static function is_uuid($value) {
		return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value);
	}
}
