<?php

defined('ABSPATH') || exit;

/**
 * Read-only planner for a future Stock-status Split strategy.
 *
 * Catalog stock status is volatile. It is sampled only during Review and the
 * resulting explicit quantity plan/evidence must be frozen in confirmation.
 * Execute must never re-query product stock status.
 */
final class WCOS_Stock_Status_Split_Planner {
	const STRATEGY = 'stock_status';
	const POLICY_VERSION = 1;

	private static $supported_statuses = array('instock', 'outofstock', 'onbackorder');

	public static function review(WC_Order $source) {
		$base = (new WCOS_Split_WooCommerce_Adapter())->preflight($source);
		$report = array(
			'supported' => false,
			'reason' => '',
			'message' => '',
			'strategy' => self::STRATEGY,
			'policy_version' => self::POLICY_VERSION,
			'order_id' => absint($source->get_id()),
			'source_signature' => isset($base['source_signature']) ? (string) $base['source_signature'] : '',
			'buckets' => array(),
			'classification_fingerprint' => '',
			'execution_policy' => WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
		);

		if (empty($base['supported'])) {
			return self::reject(
				$report,
				'base_' . (isset($base['reason']) ? sanitize_key((string) $base['reason']) : 'unsupported'),
				isset($base['message']) ? (string) $base['message'] : __('The source order is not compatible with the hardened Split engine.', 'wc-order-splitter')
			);
		}

		$buckets = array();
		foreach ($source->get_items('line_item') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				continue;
			}

			$product = $item->get_product();
			if (!$product instanceof WC_Product) {
				return self::reject(
					$report,
					'deleted_product_stock_status_unavailable',
					__('A historical order line no longer has a catalog product, so its current stock-status classification cannot be proven.', 'wc-order-splitter')
				);
			}

			$status = sanitize_key((string) $product->get_stock_status());
			if (!in_array($status, self::$supported_statuses, true)) {
				return self::reject(
					$report,
					'unsupported_stock_status',
					sprintf(
						/* translators: %s: sanitized WooCommerce product stock status. */
						__('The catalog returned an unsupported stock status: %s.', 'wc-order-splitter'),
						$status
					)
				);
			}

			$bucket_key = 'stock-' . $status;
			if (!isset($buckets[$bucket_key])) {
				$buckets[$bucket_key] = array(
					'key' => $bucket_key,
					'stock_status' => $status,
					'label' => self::status_label($status),
					'items' => array(),
					'evidence' => array(),
				);
			}

			$managed_id = method_exists($product, 'get_stock_managed_by_id')
				? absint($product->get_stock_managed_by_id())
				: absint($product->get_id());
			$buckets[$bucket_key]['items'][(int) $item_id] = WCOS_Decimal::normalize($item->get_quantity(), 6);
			$buckets[$bucket_key]['evidence'][(int) $item_id] = array(
				'product_id' => absint($item->get_product_id()),
				'variation_id' => absint($item->get_variation_id()),
				'catalog_object_id' => absint($product->get_id()),
				'stock_owner_id' => $managed_id,
				'stock_status' => $status,
			);
		}

		ksort($buckets, SORT_STRING);
		foreach ($buckets as &$bucket) {
			ksort($bucket['items'], SORT_NUMERIC);
			ksort($bucket['evidence'], SORT_NUMERIC);
		}
		unset($bucket);

		if (count($buckets) < 2) {
			return self::reject(
				$report,
				'single_stock_status_bucket',
				__('Stock-status Split requires at least two reviewed stock-status buckets.', 'wc-order-splitter')
			);
		}

		$report['buckets'] = $buckets;
		$report['classification_fingerprint'] = self::classification_fingerprint($source, $buckets);
		$report['supported'] = true;
		$report['reason'] = 'supported';
		$report['message'] = __('The order has multiple reviewed stock-status buckets that can be frozen into a Split plan.', 'wc-order-splitter');
		return $report;
	}

	public static function build_plan(array $review, $source_bucket_key) {
		self::assert_review_authority($review);

		$source_bucket_key = sanitize_key((string) $source_bucket_key);
		$buckets = isset($review['buckets']) && is_array($review['buckets']) ? $review['buckets'] : array();
		if ('' === $source_bucket_key || !isset($buckets[$source_bucket_key])) {
			throw new InvalidArgumentException(__('Choose one reviewed stock-status bucket to remain on the source order.', 'wc-order-splitter'));
		}

		$plan = array();
		foreach ($buckets as $bucket_key => $bucket) {
			if ($bucket_key === $source_bucket_key) {
				continue;
			}
			$items = isset($bucket['items']) && is_array($bucket['items']) ? $bucket['items'] : array();
			if (!empty($items)) {
				$plan[sanitize_key((string) $bucket_key)] = $items;
			}
		}

		if (empty($plan)) {
			throw new InvalidArgumentException(__('Stock-status Split must create at least one child bucket.', 'wc-order-splitter'));
		}

		ksort($plan, SORT_STRING);
		return WCOS_Split_Plan::canonicalize_request($plan);
	}

	private static function assert_review_authority(array $review) {
		if (empty($review['supported']) || self::STRATEGY !== sanitize_key(isset($review['strategy']) ? (string) $review['strategy'] : '')) {
			throw new InvalidArgumentException(__('A supported Stock-status Split review is required to build a plan.', 'wc-order-splitter'));
		}
		if (!isset($review['policy_version']) || self::POLICY_VERSION !== (int) $review['policy_version']) {
			throw new RuntimeException(__('The Stock-status Split review policy no longer matches the current planner.', 'wc-order-splitter'));
		}
		$policy = isset($review['execution_policy']) ? WCOS_Split_Execution_Policy::normalize($review['execution_policy']) : '';
		if (WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== $policy) {
			throw new RuntimeException(__('The Stock-status Split review does not carry whole-line execution authority.', 'wc-order-splitter'));
		}
		if (empty($review['source_signature']) || empty($review['classification_fingerprint'])) {
			throw new RuntimeException(__('The Stock-status Split review is missing frozen source or classification authority.', 'wc-order-splitter'));
		}
	}

	private static function classification_fingerprint(WC_Order $source, array $buckets) {
		$evidence = array();
		foreach ($buckets as $bucket_key => $bucket) {
			$evidence[$bucket_key] = array(
				'stock_status' => isset($bucket['stock_status']) ? sanitize_key((string) $bucket['stock_status']) : '',
				'items' => isset($bucket['items']) && is_array($bucket['items']) ? $bucket['items'] : array(),
				'evidence' => isset($bucket['evidence']) && is_array($bucket['evidence']) ? $bucket['evidence'] : array(),
			);
		}

		return WCOS_Mutation_Fingerprint::create(
			'stock_status_split_review',
			$source->get_id(),
			array(
				'policy_version' => self::POLICY_VERSION,
				'source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
				'evidence' => $evidence,
			)
		);
	}

	private static function status_label($status) {
		switch ($status) {
			case 'instock':
				return __('In stock', 'wc-order-splitter');
			case 'outofstock':
				return __('Out of stock', 'wc-order-splitter');
			case 'onbackorder':
				return __('On backorder', 'wc-order-splitter');
			default:
				return (string) $status;
		}
	}

	private static function reject(array $report, $reason, $message) {
		$report['supported'] = false;
		$report['reason'] = sanitize_key((string) $reason);
		$report['message'] = (string) $message;
		return $report;
	}
}
