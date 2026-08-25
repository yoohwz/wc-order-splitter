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

	public function split_preflight(WC_Order $source, $operation_id = '', $confirmed_precision = null) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $source);
		return (new WCOS_Split_WooCommerce_Adapter())->preflight($source, $operation_id, $confirmed_precision);
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
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $source, $target);
		return (new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, $confirmed_precision, $confirmation_authority);
	}

	public function merge_preflight(WC_Order $source, WC_Order $target, $operation_id = '', $confirmed_precision = null) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $source, $target);
		return (new WCOS_Merge_WooCommerce_Adapter())->preflight($source, $target, $operation_id, $confirmed_precision);
	}

	public function return_order(WC_Order $child, $operation_id, $confirmed_precision = null) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::RETURN_ORDER);
		$report = WCOS_Return_Preflight::assert_supported($child, false);
		$original = wc_get_order(absint($report['source_order_id']));
		if (!$original instanceof WC_Order) {
			throw new RuntimeException(__('The server-resolved Return original is unavailable.', 'wc-order-splitter'));
		}
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::RETURN_ORDER, $child, $original);
		return (new WCOS_Return_WooCommerce_Adapter())->return_order($child, $operation_id, $confirmed_precision);
	}
}
