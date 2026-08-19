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

	/**
	 * Internal foundation execution used by canonical adapter tests.
	 *
	 * No production controller may call this method. Future production strategy
	 * transport must use split_confirmed() through WCOS_Mutation_Gateway so the
	 * semantic strategy authority is durably bound to the Split journal.
	 */
	public function split(WC_Order $source, $strategy, array $plan, $operation_id, $confirmed_precision = null) {
		self::normalize_strategy($strategy);
		$operation_id = sanitize_key((string) $operation_id);
		if ('' === $operation_id) {
			throw new InvalidArgumentException(__('A split operation ID is required.', 'wc-order-splitter'));
		}

		$source_id = $source->get_id();
		$source = $source_id ? wc_get_order($source_id) : false;
		if (!$source instanceof WC_Order) {
			throw new RuntimeException(__('The source order is no longer available.', 'wc-order-splitter'));
		}

		$normalized_plan = $this->assert_whole_line_bucket_plan($source, $plan, $operation_id);
		return (new WCOS_Split_WooCommerce_Adapter())->split(
			$source,
			$normalized_plan,
			$operation_id,
			$confirmed_precision,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
		);
	}

	/**
	 * Confirmed execution boundary for future production strategy transport.
	 */
	public function split_confirmed(WC_Order $source, $strategy, array $plan, $operation_id, $confirmed_precision, array $confirmation) {
		$strategy = self::normalize_strategy($strategy);
		$operation_id = sanitize_key((string) $operation_id);
		if ('' === $operation_id) {
			throw new InvalidArgumentException(__('A split operation ID is required.', 'wc-order-splitter'));
		}

		$source_id = absint($source->get_id());
		$source = $source_id ? wc_get_order($source_id) : false;
		if (!$source instanceof WC_Order) {
			throw new RuntimeException(__('The source order is no longer available.', 'wc-order-splitter'));
		}

		$canonical_plan = WCOS_Split_Plan::canonicalize_request($plan);
		$this->assert_confirmation_matches_request(
			$source,
			$strategy,
			$canonical_plan,
			$operation_id,
			$confirmed_precision,
			$confirmation
		);
		$normalized_plan = $this->assert_whole_line_bucket_plan($source, $canonical_plan, $operation_id);
		$authority = WCOS_Split_Strategy_Confirmation_Store::operation_authority($confirmation);

		$record = WCOS_Operation_Journal::get($source, $operation_id);
		if (is_array($record)) {
			$this->assert_recorded_strategy_authority($record, $authority);
		}

		return (new WCOS_Split_WooCommerce_Adapter())->split(
			$source,
			$normalized_plan,
			$operation_id,
			$confirmed_precision,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			array('strategy_authority' => $authority)
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

	private function assert_confirmation_matches_request(WC_Order $source, $strategy, array $canonical_plan, $operation_id, $confirmed_precision, array $confirmation) {
		if (sanitize_key(isset($confirmation['operation_id']) ? (string) $confirmation['operation_id'] : '') !== $operation_id) {
			throw new RuntimeException(__('The Split strategy confirmation does not match this operation ID.', 'wc-order-splitter'));
		}
		if (absint(isset($confirmation['source_order_id']) ? $confirmation['source_order_id'] : 0) !== $source->get_id()) {
			throw new RuntimeException(__('The Split strategy confirmation does not match this source order.', 'wc-order-splitter'));
		}
		if (self::normalize_strategy(isset($confirmation['strategy']) ? $confirmation['strategy'] : '') !== $strategy) {
			throw new RuntimeException(__('The Split strategy confirmation does not match the requested strategy.', 'wc-order-splitter'));
		}
		if (WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== WCOS_Split_Execution_Policy::normalize(isset($confirmation['execution_policy']) ? $confirmation['execution_policy'] : '')) {
			throw new RuntimeException(__('The Split strategy confirmation does not carry whole-line execution authority.', 'wc-order-splitter'));
		}
		$confirmed_plan = WCOS_Split_Plan::canonicalize_request(isset($confirmation['plan']) && is_array($confirmation['plan']) ? $confirmation['plan'] : array());
		if ($confirmed_plan !== $canonical_plan) {
			throw new RuntimeException(__('The Split strategy confirmation plan does not match the requested plan.', 'wc-order-splitter'));
		}
		$precision = WCOS_Price_Precision_Scope::validate($confirmed_precision);
		$authority_precision = WCOS_Price_Precision_Scope::validate(isset($confirmation['price_precision']) ? $confirmation['price_precision'] : null);
		if ($precision !== $authority_precision) {
			throw new RuntimeException(__('The Split strategy confirmation precision does not match the requested operation precision.', 'wc-order-splitter'));
		}
		if (absint(isset($confirmation['split_policy_version']) ? $confirmation['split_policy_version'] : 0) !== (int) WCOS_Split_Preflight::POLICY_VERSION) {
			throw new RuntimeException(__('The Split strategy confirmation safety policy no longer matches the current Split policy.', 'wc-order-splitter'));
		}
	}

	private function assert_whole_line_bucket_plan(WC_Order $source, array $plan, $operation_id) {
		$canonical = WCOS_Split_Plan::canonicalize_request($plan);
		$record = WCOS_Operation_Journal::get($source, $operation_id);
		if (is_array($record)) {
			return $this->assert_recorded_bucket_plan($canonical, $record);
		}

		$normalized = WCOS_Split_Plan::normalize(
			$source,
			$canonical,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
		);
		$assigned_item_ids = $this->assigned_item_ids($normalized);
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

	private function assert_recorded_bucket_plan(array $canonical_plan, array $record) {
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$record_policy = isset($context['execution_policy'])
			? WCOS_Split_Execution_Policy::normalize($context['execution_policy'])
			: WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY;
		if (WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== $record_policy) {
			throw new RuntimeException(__('The durable Split operation does not carry server-built whole-line execution authority.', 'wc-order-splitter'));
		}
		if (!isset($context['plan']) || !is_array($context['plan'])) {
			throw new RuntimeException(__('The durable Split operation is missing its frozen strategy plan.', 'wc-order-splitter'));
		}

		$recorded_plan = WCOS_Split_Plan::canonicalize_request($context['plan']);
		if ($recorded_plan !== $canonical_plan) {
			throw new RuntimeException(__('The requested strategy plan does not match the durable Split operation.', 'wc-order-splitter'));
		}

		$assigned_item_ids = $this->assigned_item_ids($recorded_plan);
		$fully_moved_item_ids = isset($context['fully_moved_item_ids'])
			? array_values(array_unique(array_filter(array_map('absint', (array) $context['fully_moved_item_ids']))))
			: array();
		sort($fully_moved_item_ids, SORT_NUMERIC);
		if ($assigned_item_ids !== $fully_moved_item_ids) {
			throw new RuntimeException(__('The durable Split operation does not prove whole-line bucket semantics for every assigned source line.', 'wc-order-splitter'));
		}

		return $recorded_plan;
	}

	private function assert_recorded_strategy_authority(array $record, array $authority) {
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		if (empty($context['strategy_authority']) || !is_array($context['strategy_authority'])) {
			throw new RuntimeException(__('The durable Split operation is missing semantic strategy authority.', 'wc-order-splitter'));
		}
		if ($context['strategy_authority'] !== $authority) {
			throw new RuntimeException(__('The requested Split strategy authority does not match the durable operation journal.', 'wc-order-splitter'));
		}
	}

	private function assigned_item_ids(array $normalized_plan) {
		$assigned = array();
		foreach ($normalized_plan as $items) {
			foreach ($items as $item_id => $quantity) {
				$item_id = absint($item_id);
				if (isset($assigned[$item_id])) {
					throw new InvalidArgumentException(__('A server-built Split strategy may assign each source line to only one child bucket.', 'wc-order-splitter'));
				}
				$assigned[$item_id] = true;
			}
		}

		$assigned = array_values(array_map('absint', array_keys($assigned)));
		sort($assigned, SORT_NUMERIC);
		return $assigned;
	}
}
