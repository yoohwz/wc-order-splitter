<?php

defined('ABSPATH') || exit;

/**
 * Canonical immutable authority for one confirmed server-built Split strategy.
 *
 * This document is PII-free and is safe to persist in the existing Split
 * operation journal. It binds semantic strategy identity to the frozen Review,
 * selected source bucket, explicit plan, price precision, and Split safety
 * policy used by Execute/replay.
 */
final class WCOS_Split_Strategy_Authority {
	const SCHEMA_VERSION = 1;

	public static function create(array $review, $source_bucket_key, array $plan, $price_precision, $split_policy_version) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy(
			isset($review['strategy']) ? $review['strategy'] : ''
		);
		$planner_policy_version = isset($review['policy_version']) ? absint($review['policy_version']) : 0;
		if ($planner_policy_version !== self::current_planner_policy_version($strategy)) {
			throw new InvalidArgumentException(__('The Split strategy Review policy is not current.', 'wc-order-splitter'));
		}

		$source_order_id = isset($review['order_id']) ? absint($review['order_id']) : 0;
		$source_signature = isset($review['source_signature']) ? sanitize_key((string) $review['source_signature']) : '';
		$classification_fingerprint = isset($review['classification_fingerprint'])
			? sanitize_key((string) $review['classification_fingerprint'])
			: '';
		$source_bucket_key = sanitize_key((string) $source_bucket_key);
		$price_precision = WCOS_Price_Precision_Scope::validate($price_precision);
		$split_policy_version = absint($split_policy_version);
		$canonical_plan = WCOS_Split_Plan::canonicalize_request($plan);

		if (!$source_order_id
			|| !self::is_sha256($source_signature)
			|| !self::is_sha256($classification_fingerprint)
			|| '' === $source_bucket_key
			|| !$split_policy_version
			|| empty($canonical_plan)) {
			throw new InvalidArgumentException(__('The Split strategy authority is incomplete.', 'wc-order-splitter'));
		}
		if (!isset($review['execution_policy'])
			|| WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== WCOS_Split_Execution_Policy::normalize($review['execution_policy'])) {
			throw new InvalidArgumentException(__('The Split strategy Review does not carry whole-line execution authority.', 'wc-order-splitter'));
		}

		$authority = array(
			'schema_version' => self::SCHEMA_VERSION,
			'source_order_id' => $source_order_id,
			'strategy' => $strategy,
			'planner_policy_version' => $planner_policy_version,
			'source_signature' => $source_signature,
			'classification_fingerprint' => $classification_fingerprint,
			'source_bucket_key' => $source_bucket_key,
			'plan' => $canonical_plan,
			'execution_policy' => WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			'price_precision' => $price_precision,
			'split_policy_version' => $split_policy_version,
		);
		$authority['authority_fingerprint'] = self::fingerprint($authority);
		return $authority;
	}

	public static function normalize(array $authority) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy(
			isset($authority['strategy']) ? $authority['strategy'] : ''
		);
		$normalized = array(
			'schema_version' => isset($authority['schema_version']) ? absint($authority['schema_version']) : 0,
			'source_order_id' => isset($authority['source_order_id']) ? absint($authority['source_order_id']) : 0,
			'strategy' => $strategy,
			'planner_policy_version' => isset($authority['planner_policy_version']) ? absint($authority['planner_policy_version']) : 0,
			'source_signature' => isset($authority['source_signature']) ? sanitize_key((string) $authority['source_signature']) : '',
			'classification_fingerprint' => isset($authority['classification_fingerprint']) ? sanitize_key((string) $authority['classification_fingerprint']) : '',
			'source_bucket_key' => isset($authority['source_bucket_key']) ? sanitize_key((string) $authority['source_bucket_key']) : '',
			'plan' => WCOS_Split_Plan::canonicalize_request(isset($authority['plan']) && is_array($authority['plan']) ? $authority['plan'] : array()),
			'execution_policy' => WCOS_Split_Execution_Policy::normalize(isset($authority['execution_policy']) ? $authority['execution_policy'] : ''),
			'price_precision' => WCOS_Price_Precision_Scope::validate(isset($authority['price_precision']) ? $authority['price_precision'] : null),
			'split_policy_version' => isset($authority['split_policy_version']) ? absint($authority['split_policy_version']) : 0,
		);

		if (self::SCHEMA_VERSION !== $normalized['schema_version']
			|| !$normalized['source_order_id']
			|| $normalized['planner_policy_version'] !== self::current_planner_policy_version($strategy)
			|| !self::is_sha256($normalized['source_signature'])
			|| !self::is_sha256($normalized['classification_fingerprint'])
			|| '' === $normalized['source_bucket_key']
			|| empty($normalized['plan'])
			|| WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== $normalized['execution_policy']
			|| !$normalized['split_policy_version']) {
			throw new InvalidArgumentException(__('The Split strategy authority is invalid or incomplete.', 'wc-order-splitter'));
		}

		$expected_fingerprint = self::fingerprint($normalized);
		$actual_fingerprint = isset($authority['authority_fingerprint'])
			? sanitize_key((string) $authority['authority_fingerprint'])
			: '';
		if (!self::is_sha256($actual_fingerprint) || !hash_equals($expected_fingerprint, $actual_fingerprint)) {
			throw new RuntimeException(__('The Split strategy authority failed its integrity fingerprint.', 'wc-order-splitter'));
		}
		$normalized['authority_fingerprint'] = $expected_fingerprint;
		return $normalized;
	}

	public static function assert_matches_execution(array $authority, WC_Order $source, array $plan, $execution_policy) {
		$authority = self::normalize($authority);
		$canonical_plan = WCOS_Split_Plan::canonicalize_request($plan);
		$execution_policy = WCOS_Split_Execution_Policy::normalize($execution_policy);
		$current_precision = WCOS_Price_Precision_Scope::current_or_store_precision();

		if ((int) $authority['source_order_id'] !== (int) $source->get_id()) {
			throw new RuntimeException(__('The confirmed Split strategy authority belongs to a different source order.', 'wc-order-splitter'));
		}
		if ($authority['plan'] !== $canonical_plan) {
			throw new RuntimeException(__('The confirmed Split strategy plan does not match the mutation request.', 'wc-order-splitter'));
		}
		if ($authority['execution_policy'] !== $execution_policy) {
			throw new RuntimeException(__('The confirmed Split strategy execution policy does not match the mutation request.', 'wc-order-splitter'));
		}
		if ((int) $authority['price_precision'] !== (int) $current_precision) {
			throw new RuntimeException(__('The confirmed Split strategy price precision does not match the active operation precision.', 'wc-order-splitter'));
		}
		if ((int) $authority['split_policy_version'] !== (int) WCOS_Split_Preflight::POLICY_VERSION) {
			throw new RuntimeException(__('The confirmed Split strategy safety policy is no longer current.', 'wc-order-splitter'));
		}
		return $authority;
	}

	public static function current_planner_policy_version($strategy) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		switch ($strategy) {
			case WCOS_Split_Strategy_Gates::CATEGORY:
				return (int) WCOS_Category_Split_Planner::POLICY_VERSION;
			case WCOS_Split_Strategy_Gates::STOCK_STATUS:
				return (int) WCOS_Stock_Status_Split_Planner::POLICY_VERSION;
		}
		return 0;
	}

	private static function fingerprint(array $authority) {
		$payload = $authority;
		unset($payload['authority_fingerprint']);
		return WCOS_Mutation_Fingerprint::create(
			'split_strategy_authority',
			isset($authority['source_order_id']) ? absint($authority['source_order_id']) : 0,
			$payload
		);
	}

	private static function is_sha256($value) {
		return 1 === preg_match('/^[0-9a-f]{64}$/D', (string) $value);
	}
}
