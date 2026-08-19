<?php

defined('ABSPATH') || exit;

/**
 * Mandatory boundary for all future HTTP/admin mutation controllers.
 *
 * Controllers must never instantiate mutation services directly. This gateway
 * applies the production feature/strategy gates first, then centralized
 * authorization and immutable confirmation authority, before delegating to a
 * hardened WooCommerce adapter/service.
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

	public function split_strategy(WC_Order $source, $strategy, $operation_id, $confirmation_token) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::SPLIT);
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		WCOS_Split_Strategy_Gates::assert_enabled($strategy);
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $source);

		$verified = WCOS_Split_Strategy_Confirmation_Store::verify(
			$source,
			$strategy,
			$operation_id,
			$confirmation_token,
			get_current_user_id()
		);
		return (new WCOS_Split_Strategy_WooCommerce_Adapter())->split(
			$source,
			$strategy,
			$verified['plan'],
			$verified['operation_id'],
			$verified['price_precision'],
			$verified['strategy_authority']
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

	public function merge(WC_Order $source, WC_Order $target, $operation_id) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::MERGE);
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $source, $target);
		throw new RuntimeException(__('The hardened merge service has not been implemented.', 'wc-order-splitter'));
	}

	public function return_order(WC_Order $child, WC_Order $parent, $operation_id) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::RETURN_ORDER);
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::RETURN_ORDER, $child, $parent);
		throw new RuntimeException(__('The hardened return service has not been implemented.', 'wc-order-splitter'));
	}
}
