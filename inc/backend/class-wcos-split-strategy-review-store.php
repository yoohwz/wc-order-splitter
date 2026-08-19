<?php

defined('ABSPATH') || exit;

/**
 * Short-lived server-side store for frozen Category/Stock-status Review evidence.
 *
 * Client code receives a PII-free display report plus an opaque review ID/token.
 * The report itself is never accepted back as authority. Confirmation verifies
 * this server-side record and builds the explicit plan from the frozen evidence.
 */
final class WCOS_Split_Strategy_Review_Store {
	const SCHEMA_VERSION = 1;
	const TTL = 1800;

	public static function create(WC_Order $source, $strategy, $user_id) {
		$user_id = absint($user_id);
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		if (!$source->get_id() || !$user_id) {
			throw new InvalidArgumentException(__('A persisted order and signed-in user are required to create a Split strategy Review.', 'wc-order-splitter'));
		}

		$source = wc_get_order($source->get_id());
		if (!$source instanceof WC_Order) {
			throw new WCOS_Split_Confirmation_Exception('source_missing', __('The source order is no longer available.', 'wc-order-splitter'));
		}

		$adapter = new WCOS_Split_Strategy_WooCommerce_Adapter();
		$review = $adapter->review($source, $strategy);
		if (empty($review['supported'])) {
			throw new WCOS_Split_Confirmation_Exception(
				isset($review['reason']) ? $review['reason'] : 'unsupported',
				isset($review['message']) ? $review['message'] : __('This order is not supported by the selected Split strategy.', 'wc-order-splitter')
			);
		}

		/*
		 * Re-run only the generic Split compatibility preflight, never strategy
		 * classification. Require it to describe the same immutable source order
		 * snapshot the planner reviewed before we issue a frozen Review token.
		 */
		$preflight = (new WCOS_Split_WooCommerce_Adapter())->preflight($source);
		if (empty($preflight['supported'])) {
			throw new WCOS_Split_Confirmation_Exception(
				isset($preflight['reason']) ? $preflight['reason'] : 'unsupported',
				isset($preflight['message']) ? $preflight['message'] : __('This order is not compatible with hardened Split execution.', 'wc-order-splitter')
			);
		}
		$review_signature = isset($review['source_signature']) ? (string) $review['source_signature'] : '';
		$preflight_signature = isset($preflight['source_signature']) ? (string) $preflight['source_signature'] : '';
		if ('' === $review_signature || '' === $preflight_signature || !hash_equals($review_signature, $preflight_signature)) {
			throw new WCOS_Split_Confirmation_Exception('source_changed', __('The order changed while the Split strategy Review was being frozen. Review the order again.', 'wc-order-splitter'));
		}

		$planner_policy_version = isset($review['policy_version']) ? absint($review['policy_version']) : 0;
		if ($planner_policy_version !== WCOS_Split_Strategy_Authority::current_planner_policy_version($strategy)) {
			throw new WCOS_Split_Confirmation_Exception('planner_policy_changed', __('The Split strategy planner policy changed while Review was being prepared.', 'wc-order-splitter'));
		}
		$split_policy_version = isset($preflight['policy']['policy_version'])
			? absint($preflight['policy']['policy_version'])
			: 0;
		if ($split_policy_version !== (int) WCOS_Split_Preflight::POLICY_VERSION) {
			throw new WCOS_Split_Confirmation_Exception('policy_changed', __('The Split safety policy changed while Review was being prepared.', 'wc-order-splitter'));
		}
		$price_precision = WCOS_Price_Precision_Scope::validate(
			isset($preflight['price_precision']) ? $preflight['price_precision'] : null
		);

		$review_id = wp_generate_uuid4();
		$token = wp_generate_password(48, false, false);
		$now = time();
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'review_id' => $review_id,
			'token_hash' => self::token_hash($token),
			'source_order_id' => $source->get_id(),
			'user_id' => $user_id,
			'strategy' => $strategy,
			'planner_policy_version' => $planner_policy_version,
			'source_signature' => $review_signature,
			'classification_fingerprint' => sanitize_key((string) $review['classification_fingerprint']),
			'execution_policy' => WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			'price_precision' => $price_precision,
			'split_policy_version' => $split_policy_version,
			'review' => $review,
			'created_at' => $now,
			'expires_at' => $now + self::TTL,
		);

		if (!set_transient(self::key($review_id), $record, self::TTL)) {
			throw new RuntimeException(__('Unable to create the temporary Split strategy Review record.', 'wc-order-splitter'));
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
		$token = (string) $token;
		$user_id = absint($user_id);
		if (!self::is_uuid($review_id) || !$source->get_id() || !$user_id) {
			throw new WCOS_Split_Confirmation_Exception('invalid_review_identity', __('The Split strategy Review identity is invalid.', 'wc-order-splitter'));
		}

		$record = get_transient(self::key($review_id));
		if (!is_array($record)) {
			throw new WCOS_Split_Confirmation_Exception('review_expired', __('The Split strategy Review expired. Review the order again before confirming a strategy.', 'wc-order-splitter'));
		}
		if ('' === $token || !isset($record['token_hash']) || !hash_equals((string) $record['token_hash'], self::token_hash($token))) {
			throw new WCOS_Split_Confirmation_Exception('invalid_review_token', __('The Split strategy Review token is invalid.', 'wc-order-splitter'));
		}
		if (absint($record['source_order_id']) !== $source->get_id() || absint($record['user_id']) !== $user_id) {
			throw new WCOS_Split_Confirmation_Exception('review_owner_mismatch', __('The Split strategy Review does not belong to this user and order.', 'wc-order-splitter'));
		}
		if (!isset($record['strategy']) || $strategy !== sanitize_key((string) $record['strategy'])) {
			throw new WCOS_Split_Confirmation_Exception('strategy_mismatch', __('The Split strategy Review belongs to a different strategy.', 'wc-order-splitter'));
		}
		if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
			self::delete($review_id);
			throw new WCOS_Split_Confirmation_Exception('review_expired', __('The Split strategy Review expired. Review the order again before confirming a strategy.', 'wc-order-splitter'));
		}
		if (!isset($record['planner_policy_version'])
			|| (int) $record['planner_policy_version'] !== WCOS_Split_Strategy_Authority::current_planner_policy_version($strategy)) {
			throw new WCOS_Split_Confirmation_Exception('planner_policy_changed', __('The Split strategy planner policy changed after Review. Review the order again.', 'wc-order-splitter'));
		}
		if (!isset($record['split_policy_version'])
			|| (int) $record['split_policy_version'] !== (int) WCOS_Split_Preflight::POLICY_VERSION) {
			throw new WCOS_Split_Confirmation_Exception('policy_changed', __('The Split safety policy changed after Review. Review the order again.', 'wc-order-splitter'));
		}
		if (!isset($record['execution_policy'])
			|| WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== WCOS_Split_Execution_Policy::normalize($record['execution_policy'])) {
			throw new WCOS_Split_Confirmation_Exception('execution_policy_changed', __('The Split strategy Review no longer carries whole-line execution authority.', 'wc-order-splitter'));
		}

		$precision = WCOS_Price_Precision_Scope::validate(isset($record['price_precision']) ? $record['price_precision'] : null);
		$precision_token = WCOS_Price_Precision_Scope::begin($precision);
		try {
			$fresh = wc_get_order($source->get_id());
			if (!$fresh instanceof WC_Order) {
				throw new WCOS_Split_Confirmation_Exception('source_missing', __('The source order is no longer available.', 'wc-order-splitter'));
			}
			$expected = isset($record['source_signature']) ? (string) $record['source_signature'] : '';
			$actual = WCOS_Order_Contract_Snapshot::source_signature($fresh);
			if ('' === $expected || !hash_equals($expected, $actual)) {
				throw new WCOS_Split_Confirmation_Exception('source_changed', __('The source order changed after the strategy Review. Review it again before confirming.', 'wc-order-splitter'));
			}
		} finally {
			WCOS_Price_Precision_Scope::end($precision_token);
		}

		$review = isset($record['review']) && is_array($record['review']) ? $record['review'] : array();
		if (empty($review['supported'])
			|| $strategy !== sanitize_key(isset($review['strategy']) ? (string) $review['strategy'] : '')
			|| absint(isset($review['order_id']) ? $review['order_id'] : 0) !== $source->get_id()
			|| (int) $review['policy_version'] !== (int) $record['planner_policy_version']
			|| !hash_equals((string) $record['source_signature'], (string) $review['source_signature'])
			|| !hash_equals((string) $record['classification_fingerprint'], (string) $review['classification_fingerprint'])) {
			throw new WCOS_Split_Confirmation_Exception('review_integrity_failed', __('The frozen Split strategy Review failed its server-side integrity checks.', 'wc-order-splitter'));
		}

		$record['review_id'] = $review_id;
		$record['review'] = $review;
		$record['price_precision'] = $precision;
		return $record;
	}

	public static function delete($review_id) {
		$review_id = sanitize_key((string) $review_id);
		if (!self::is_uuid($review_id)) {
			return false;
		}
		return delete_transient(self::key($review_id));
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
