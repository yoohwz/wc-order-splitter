<?php

defined('ABSPATH') || exit;

/**
 * Mandatory boundary for all future HTTP/admin mutation controllers.
 *
 * Controllers must never instantiate mutation services directly. This gateway
 * applies the production feature gate first, then centralized authorization,
 * before delegating to a hardened WooCommerce adapter/service.
 */
final class WCOS_Mutation_Gateway {

	public function split(WC_Order $source, array $plan, $operation_id) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::SPLIT);
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $source);
		return (new WCOS_Split_WooCommerce_Adapter())->split($source, $plan, $operation_id);
	}

	public function split_preflight(WC_Order $source) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $source);
		return (new WCOS_Split_WooCommerce_Adapter())->preflight($source);
	}

	public function duplicate(WC_Order $source, $operation_id) {
		WCOS_Feature_Gates::assert_enabled(WCOS_Feature_Gates::DUPLICATE);
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::DUPLICATE, $source);
		return (new WCOS_Duplicate_Order_Service())->duplicate($source, $operation_id);
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
