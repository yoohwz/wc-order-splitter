<?php

defined('ABSPATH') || exit;

/**
 * Internal WooCommerce adapter for server-built Split strategies.
 *
 * This class is intentionally not a production gate. It validates strategy
 * semantics and delegates execution to the single hardened Split adapter/service
 * using the explicit whole-line policy. Production reachability is owned by
 * WCOS_Mutation_Gateway + WCOS_Split_Strategy_Gates.
 *
 * Execute consumes only a frozen explicit quantity plan. It never re-runs a
 * Category or Stock-status planner and therefore never reclassifies live catalog
 * state during mutation.
 */
final class WCOS_Split_Strategy_WooCommerce_Adapter {

	public function review(WC_Order $source, $strategy) {
		$strategy = self::normalize_strategy($strategy);
		switch ($strategy) {
			case WCOS_Split_Strategy_Gates::CATEGORY:
				return WCOS_Category_Split_Planner::review($source);
			case WCOS_Split_Strategy_Gates::STOCK_STATUS:
				return WCOS_Stock_Status_Split_Planner::review($source);
		}

		throw new InvalidArgumentException(__('Unsupported server-built Split strategy.', 'wc-order-splitter'));
	}

	public function build_plan(array $review, $source_bucket_key) {
		$strategy = self::normalize_strategy(isset($review['strategy']) ? $review['strategy'] : '');
		switch ($strategy) {
			case WCOS_Split_Strategy_Gates::CATEGORY:
				return WCOS_Category_Split_Planner::build_plan($review, $source_bucket_key);
			case WCOS_Split_Strategy_Gates::STOCK_STATUS:
				return WCOS_Stock_Status_Split_Planner::build_plan($review, $source_bucket_key);
		}

		throw new InvalidArgumentException(__('Unsupported server-built Split strategy.', 'wc-order-splitter'));
	}

	public function split(WC_Order $source, $strategy, array $plan, $operation_id, $confirmed_precision = null) {
		self::normalize_strategy($strategy);
		$source_id = $source->get_id();
		$source = $source_id ? wc_get_order($source_id) : false;
		if (!$source instanceof WC_Order) {
			throw new RuntimeException(__('The source order is no longer available.', 'wc-order-splitter'));
		}

		$normalized_plan = $this->assert_whole_line_bucket_plan($source, $plan);
		return (new WCOS_Split_WooCommerce_Adapter())->split(
			$source,
			$normalized_plan,
			$operation_id,
			$confirmed_precision,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
		);
	}

	public static function normalize_strategy($strategy) {
		$strategy = sanitize_key((string) $strategy);
		if (!in_array(
			$strategy,
			array(
				WCOS_Split_Strategy_Gates::CATEGORY,
				WCOS_Split_Strategy_Gates::STOCK_STATUS,
			),
			true
		)) {
			throw new InvalidArgumentException(__('Unsupported server-built Split strategy.', 'wc-order-splitter'));
		}
		return $strategy;
	}

	private function assert_whole_line_bucket_plan(WC_Order $source, array $plan) {
		$normalized = WCOS_Split_Plan::normalize(
			$source,
			$plan,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
		);

		$assigned_item_ids = array();
		foreach ($normalized as $items) {
			foreach ($items as $item_id => $quantity) {
				$item_id = absint($item_id);
				if (isset($assigned_item_ids[$item_id])) {
					throw new InvalidArgumentException(__('A server-built Split strategy may assign each source line to only one child bucket.', 'wc-order-splitter'));
				}
				$assigned_item_ids[$item_id] = true;
			}
		}

		$assigned_item_ids = array_keys($assigned_item_ids);
		$assigned_item_ids = array_values(array_map('absint', $assigned_item_ids));
		sort($assigned_item_ids, SORT_NUMERIC);
		$fully_moved_item_ids = WCOS_Split_Plan::fully_moved_item_ids(
			$source,
			$normalized,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
		);

		if ($assigned_item_ids !== $fully_moved_item_ids) {
			throw new InvalidArgumentException(__('Server-built Split strategies may move only complete source product lines.', 'wc-order-splitter'));
		}

		return $normalized;
	}
}
