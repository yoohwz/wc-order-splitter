<?php

defined('ABSPATH') || exit;

/**
 * Strategy-specific authority layered on the existing Split confirmation store.
 *
 * The existing store remains the source of truth for operation ID, execute
 * token, user/order ownership, TTL, source signature, price precision, and Split
 * preflight policy. This store adds immutable server-built strategy semantics
 * and requires the same authority to be present in the durable Split journal
 * before transient-less replay is allowed.
 */
final class WCOS_Split_Strategy_Confirmation_Store {
	const SCHEMA_VERSION = 1;

	public static function create(WC_Order $source, $strategy, $review_id, $review_token, $source_bucket_key, $user_id) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		$user_id = absint($user_id);
		$source = $source->get_id() ? wc_get_order($source->get_id()) : false;
		if (!$source instanceof WC_Order || !$user_id) {
			throw new InvalidArgumentException(__('A persisted order and signed-in user are required to confirm a Split strategy.', 'wc-order-splitter'));
		}

		$review_record = WCOS_Split_Strategy_Review_Store::verify(
			$source,
			$strategy,
			$review_id,
			$review_token,
			$user_id
		);
		$review = $review_record['review'];
		$source_bucket_key = sanitize_key((string) $source_bucket_key);
		$plan = (new WCOS_Split_Strategy_WooCommerce_Adapter())->build_plan($review, $source_bucket_key);
		$authority = WCOS_Split_Strategy_Authority::create(
			$review,
			$source_bucket_key,
			$plan,
			$review_record['price_precision'],
			$review_record['split_policy_version']
		);

		$common = WCOS_Split_Confirmation_Store::create(
			$source,
			$plan,
			array(
				'price_precision' => $review_record['price_precision'],
				'source_signature' => $review_record['source_signature'],
				'policy' => array(
					'policy_version' => $review_record['split_policy_version'],
				),
			),
			$user_id
		);

		$operation_id = sanitize_key((string) $common['operation_id']);
		$common_record = isset($common['record']) && is_array($common['record']) ? $common['record'] : array();
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'operation_id' => $operation_id,
			'source_order_id' => $source->get_id(),
			'user_id' => $user_id,
			'strategy_authority' => $authority,
			'created_at' => isset($common_record['created_at']) ? (int) $common_record['created_at'] : time(),
			'expires_at' => isset($common_record['expires_at']) ? (int) $common_record['expires_at'] : (time() + WCOS_Split_Confirmation_Store::TTL),
		);

		if (!set_transient(self::key($operation_id), $record, WCOS_Split_Confirmation_Store::TTL)) {
			WCOS_Split_Confirmation_Store::delete($operation_id);
			throw new RuntimeException(__('Unable to create the temporary Split strategy confirmation authority.', 'wc-order-splitter'));
		}

		WCOS_Split_Strategy_Review_Store::delete($review_id);
		return array(
			'operation_id' => $operation_id,
			'confirmation_token' => $common['confirmation_token'],
			'expires_at' => $record['expires_at'],
			'strategy' => $authority['strategy'],
			'source_bucket_key' => $authority['source_bucket_key'],
			'plan' => $authority['plan'],
		);
	}

	public static function verify(WC_Order $source, $strategy, $operation_id, $token, $user_id) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		$operation_id = sanitize_key((string) $operation_id);
		$user_id = absint($user_id);
		$common = WCOS_Split_Confirmation_Store::verify($source, $operation_id, $token, $user_id);
		$journal = WCOS_Operation_Journal::get($source, $operation_id);
		$record = get_transient(self::key($operation_id));

		if (!is_array($record)) {
			if (!is_array($journal)) {
				throw new WCOS_Split_Confirmation_Exception('strategy_confirmation_missing', __('The Split strategy confirmation authority is unavailable. Review and confirm the strategy again.', 'wc-order-splitter'));
			}
			return self::durable_replay($common, $journal, $strategy, $source);
		}

		if (!isset($record['schema_version']) || self::SCHEMA_VERSION !== (int) $record['schema_version']) {
			throw new WCOS_Split_Confirmation_Exception('strategy_confirmation_schema', __('The Split strategy confirmation schema is not supported.', 'wc-order-splitter'));
		}
		if (absint($record['source_order_id']) !== $source->get_id() || absint($record['user_id']) !== $user_id) {
			throw new WCOS_Split_Confirmation_Exception('strategy_owner_mismatch', __('The Split strategy confirmation does not belong to this user and order.', 'wc-order-splitter'));
		}
		if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
			delete_transient(self::key($operation_id));
			if (!is_array($journal)) {
				throw new WCOS_Split_Confirmation_Exception('expired', __('The Split strategy confirmation expired. Review the strategy again before executing it.', 'wc-order-splitter'));
			}
			return self::durable_replay($common, $journal, $strategy, $source);
		}
		if (!isset($record['strategy_authority']) || !is_array($record['strategy_authority'])) {
			throw new WCOS_Split_Confirmation_Exception('strategy_authority_missing', __('The Split strategy confirmation is missing its immutable authority.', 'wc-order-splitter'));
		}

		$authority = WCOS_Split_Strategy_Authority::normalize($record['strategy_authority']);
		self::assert_common_matches_authority($common, $authority, $strategy, $source);

		if (is_array($journal)) {
			$context = isset($journal['context']) && is_array($journal['context']) ? $journal['context'] : array();
			if (!isset($context['strategy_authority']) || !is_array($context['strategy_authority'])) {
				throw new WCOS_Split_Confirmation_Exception('journal_strategy_authority_missing', __('The durable Split operation is missing strategy confirmation authority.', 'wc-order-splitter'));
			}
			$journal_authority = WCOS_Split_Strategy_Authority::normalize($context['strategy_authority']);
			if ($journal_authority !== $authority) {
				throw new WCOS_Split_Confirmation_Exception('journal_strategy_authority_mismatch', __('The durable Split operation does not match the confirmed strategy authority.', 'wc-order-splitter'));
			}
		}

		$common['strategy'] = $strategy;
		$common['source_bucket_key'] = $authority['source_bucket_key'];
		$common['strategy_authority'] = $authority;
		return $common;
	}

	public static function delete($operation_id) {
		$operation_id = sanitize_key((string) $operation_id);
		$strategy_deleted = delete_transient(self::key($operation_id));
		$common_deleted = WCOS_Split_Confirmation_Store::delete($operation_id);
		return $strategy_deleted || $common_deleted;
	}

	private static function durable_replay(array $common, array $journal, $strategy, WC_Order $source) {
		$context = isset($journal['context']) && is_array($journal['context']) ? $journal['context'] : array();
		if (!isset($context['strategy_authority']) || !is_array($context['strategy_authority'])) {
			throw new WCOS_Split_Confirmation_Exception('journal_strategy_authority_missing', __('The durable Split operation is missing strategy confirmation authority and cannot be replayed as a server-built strategy.', 'wc-order-splitter'));
		}
		$authority = WCOS_Split_Strategy_Authority::normalize($context['strategy_authority']);
		self::assert_common_matches_authority($common, $authority, $strategy, $source);

		$common['strategy'] = $strategy;
		$common['source_bucket_key'] = $authority['source_bucket_key'];
		$common['strategy_authority'] = $authority;
		$common['replay_authority'] = 'journal';
		return $common;
	}

	private static function assert_common_matches_authority(array $common, array $authority, $strategy, WC_Order $source) {
		if ($strategy !== $authority['strategy']) {
			throw new WCOS_Split_Confirmation_Exception('strategy_mismatch', __('The Split confirmation belongs to a different strategy.', 'wc-order-splitter'));
		}
		if ((int) $authority['source_order_id'] !== (int) $source->get_id()
			|| absint(isset($common['source_order_id']) ? $common['source_order_id'] : 0) !== $source->get_id()) {
			throw new WCOS_Split_Confirmation_Exception('source_mismatch', __('The Split strategy confirmation belongs to a different source order.', 'wc-order-splitter'));
		}
		$common_plan = WCOS_Split_Plan::canonicalize_request(isset($common['plan']) && is_array($common['plan']) ? $common['plan'] : array());
		if ($common_plan !== $authority['plan']) {
			throw new WCOS_Split_Confirmation_Exception('plan_mismatch', __('The Split confirmation plan does not match the strategy authority.', 'wc-order-splitter'));
		}
		$common_precision = WCOS_Price_Precision_Scope::validate(isset($common['price_precision']) ? $common['price_precision'] : null);
		if ((int) $common_precision !== (int) $authority['price_precision']) {
			throw new WCOS_Split_Confirmation_Exception('precision_mismatch', __('The Split confirmation precision does not match the strategy authority.', 'wc-order-splitter'));
		}
		if ((int) (isset($common['policy_version']) ? $common['policy_version'] : 0) !== (int) $authority['split_policy_version']) {
			throw new WCOS_Split_Confirmation_Exception('policy_changed', __('The Split confirmation safety policy does not match the strategy authority.', 'wc-order-splitter'));
		}
	}

	private static function key($operation_id) {
		return 'wcos_split_strategy_confirm_' . hash('sha256', sanitize_key((string) $operation_id));
	}
}
