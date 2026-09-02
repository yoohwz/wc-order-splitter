<?php

defined('ABSPATH') || exit;

/**
 * Mandatory boundary for all future HTTP/admin mutation controllers.
 *
 * Controllers must never instantiate mutation services directly. This gateway
 * applies the production feature/strategy gates first, then centralized
 * authorization, before delegating to a hardened WooCommerce adapter/service.
 */
final class WCOS_Mutation_Gateway {

	public function split(WC_Order $source, array $plan, $operation_id, $confirmed_precision = null) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::SPLIT);
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $source);
		return (new WCOS_Split_WooCommerce_Adapter())->split($source, $plan, $operation_id, $confirmed_precision);
	}

	public function split_manual_confirmed(WC_Order $source, array $plan, $operation_id, $confirmed_precision, array $confirmation) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::SPLIT);
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $source);
		$operation_id = sanitize_key((string) $operation_id);
		$replay_authority = isset($confirmation['replay_authority']) ? sanitize_key((string) $confirmation['replay_authority']) : '';
		$confirmation_operation_id = isset($confirmation['operation_id']) ? sanitize_key((string) $confirmation['operation_id']) : '';
		$confirmation_source_id = isset($confirmation['source_order_id']) ? absint($confirmation['source_order_id']) : 0;
		$confirmation_plan = isset($confirmation['plan']) && is_array($confirmation['plan'])
			? WCOS_Split_Plan::canonicalize_request($confirmation['plan'])
			: array();
		$canonical_plan = WCOS_Split_Plan::canonicalize_request($plan);
		$precision = WCOS_Price_Precision_Scope::validate($confirmed_precision);
		$confirmation_precision = WCOS_Price_Precision_Scope::validate(
			isset($confirmation['price_precision']) ? $confirmation['price_precision'] : null
		);
		if (!in_array($replay_authority, array('confirmation', 'journal'), true)
			|| '' === $operation_id
			|| $operation_id !== $confirmation_operation_id
			|| $source->get_id() !== $confirmation_source_id
			|| $canonical_plan !== $confirmation_plan
			|| $precision !== $confirmation_precision) {
			throw new RuntimeException(__('The verified Manual Split confirmation does not match this mutation request.', 'wc-order-splitter'));
		}
		$operation_context = array();
		if (isset($confirmation['manual_quantity_authority']) && is_array($confirmation['manual_quantity_authority'])) {
			$operation_context['manual_quantity_authority'] = WCOS_Manual_Split_Quantity_Authority::assert_valid($confirmation['manual_quantity_authority']);
		} else {
			throw new RuntimeException(__('A verified server Manual Split quantity authority is required.', 'wc-order-splitter'));
		}
		$operation_context['commercial_policy'] = WCOS_Split_Commercial_Policy::assert_valid(
			isset($confirmation['commercial_policy']) && is_array($confirmation['commercial_policy'])
				? $confirmation['commercial_policy']
				: array()
		);
		$execution_policy = WCOS_Manual_Split_Quantity_Authority::execution_policy($operation_context['manual_quantity_authority']);
		return (new WCOS_Split_WooCommerce_Adapter())->split(
			$source,
			$canonical_plan,
			$operation_id,
			$precision,
			$execution_policy,
			$operation_context
		);
	}

	public function split_preflight(WC_Order $source, $operation_id = '', $confirmed_precision = null) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $source);
		return (new WCOS_Split_WooCommerce_Adapter())->preflight($source, $operation_id, $confirmed_precision);
	}

	public function manual_split_preflight(WC_Order $source, $operation_id = '', $confirmed_precision = null) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $source);
		return (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($source, $operation_id, $confirmed_precision);
	}

	public function split_strategy(WC_Order $source, $strategy, array $plan, $operation_id, $confirmed_precision = null, array $confirmation = array()) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::SPLIT);
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		WCOS_Split_Strategy_Gates::assert_enabled($strategy);
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $source);
		return (new WCOS_Split_Strategy_WooCommerce_Adapter())->split_confirmed(
			$source,
			$strategy,
			$plan,
			$operation_id,
			$confirmed_precision,
			$confirmation
		);
	}

	public function duplicate(WC_Order $source, $operation_id, $confirmed_precision = null) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::DUPLICATE);
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::DUPLICATE, $source);
		return (new WCOS_Duplicate_WooCommerce_Adapter())->duplicate($source, $operation_id, $confirmed_precision);
	}

	public function duplicate_preflight(WC_Order $source, $operation_id = '', $confirmed_precision = null) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::DUPLICATE, $source);
		return (new WCOS_Duplicate_WooCommerce_Adapter())->preflight($source, $operation_id, $confirmed_precision);
	}

	public function merge(WC_Order $source, WC_Order $target, $operation_id, $confirmed_precision = null, array $confirmation_authority = array()) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::MERGE);
		$participants = WCOS_Merge_Canonical_Reader::shop_order_pair($source->get_id(), $target->get_id());
		if (!is_array($participants)) {
			throw new InvalidArgumentException(__('Merge requires two persisted WooCommerce shop orders.', 'wc-order-splitter'));
		}
		list($source, $target) = $participants;
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $source, $target);
		return (new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, $confirmed_precision, $confirmation_authority);
	}

	public function merge_preflight(WC_Order $source, WC_Order $target, $operation_id = '', $confirmed_precision = null) {
		$participants = WCOS_Merge_Canonical_Reader::shop_order_pair($source->get_id(), $target->get_id());
		if (!is_array($participants)) {
			throw new InvalidArgumentException(__('Merge requires two persisted WooCommerce shop orders.', 'wc-order-splitter'));
		}
		list($source, $target) = $participants;
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $source, $target);
		return (new WCOS_Merge_WooCommerce_Adapter())->preflight($source, $target, $operation_id, $confirmed_precision);
	}

	public function return_order(WC_Order $child, $operation_id, $confirmed_precision = null, array $confirmation_authority = array()) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::RETURN_ORDER);
		if (empty($confirmation_authority)) {
			throw new RuntimeException(__('A verified server Return Confirmation is required.', 'wc-order-splitter'));
		}
		$operation_id = sanitize_key((string) $operation_id);
		$journal = WCOS_Operation_Journal::get($child, $operation_id);
		if (is_array($journal)) {
			$durable = WCOS_Return_Journal_Context::assert_confirmation_matches_record($journal, $confirmation_authority);
			$original = wc_get_order(absint($durable['original_order_id']));
		} else {
			$report = WCOS_Return_Preflight::assert_supported($child, false);
			$original = wc_get_order(absint($report['source_order_id']));
		}
		if (!$original instanceof WC_Order) {
			throw new RuntimeException(__('The server-resolved Return original is unavailable.', 'wc-order-splitter'));
		}
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::RETURN_ORDER, $child, $original);
		return (new WCOS_Return_WooCommerce_Adapter())->return_order($child, $operation_id, $confirmed_precision, $confirmation_authority);
	}

	/** Gate-aware production entry for one coordination-only Bulk Return step. */
	public function bulk_return_advance($batch_id, $anchor_child_id, $batch_token, $user_id, $expected_cursor) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::BULK_RETURN);
		return (new WCOS_Bulk_Return_Orchestrator())->advance(
			$batch_id,
			$anchor_child_id,
			$batch_token,
			$user_id,
			$expected_cursor
		);
	}
}
