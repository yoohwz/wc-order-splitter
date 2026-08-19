<?php

defined('ABSPATH') || exit;

/**
 * Read-only planner for a future Category Split strategy.
 *
 * Category classification is sampled only during Review. Execute must consume
 * the frozen quantity plan/evidence and must never re-query catalog categories.
 */
final class WCOS_Category_Split_Planner {
	const STRATEGY = 'category';
	const POLICY_VERSION = 2;
	const UNCATEGORIZED_BUCKET = 'category-uncategorized';

	public static function review(WC_Order $source) {
		$base = (new WCOS_Split_WooCommerce_Adapter())->preflight($source);
		$source_id = absint($source->get_id());
		$report = array(
			'supported' => false,
			'reason' => '',
			'message' => '',
			'strategy' => self::STRATEGY,
			'policy_version' => self::POLICY_VERSION,
			'order_id' => $source_id,
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

		$source = $source_id ? wc_get_order($source_id) : false;
		if (!$source instanceof WC_Order) {
			return self::reject($report, 'source_unavailable', __('The source order is no longer available for Category Split review.', 'wc-order-splitter'));
		}
		$current_signature = WCOS_Order_Contract_Snapshot::source_signature($source);
		if ('' === $report['source_signature'] || !hash_equals($report['source_signature'], $current_signature)) {
			return self::reject(
				$report,
				'review_source_changed',
				__('The source order changed while Category Split review was being prepared. Review the order again.', 'wc-order-splitter')
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
					'deleted_product_category_unavailable',
					__('A historical order line no longer has a catalog product, so its current category classification cannot be proven.', 'wc-order-splitter')
				);
			}

			$product_id = absint($item->get_product_id());
			$terms = wp_get_post_terms($product_id, 'product_cat');
			if (is_wp_error($terms)) {
				return self::reject($report, 'category_lookup_failed', __('Product categories could not be read safely for this order.', 'wc-order-splitter'));
			}

			$leaf_terms = self::leaf_assigned_terms((array) $terms);
			if (count($leaf_terms) > 1) {
				return self::reject(
					$report,
					'ambiguous_multiple_leaf_categories',
					sprintf(
						/* translators: %d: source order item ID. */
						__('Order item %d belongs to multiple unrelated leaf categories. Choose an explicit category assignment before using Category Split.', 'wc-order-splitter'),
						absint($item_id)
					)
				);
			}

			if (empty($leaf_terms)) {
				$bucket_key = self::UNCATEGORIZED_BUCKET;
				$bucket = array(
					'key' => $bucket_key,
					'term_id' => 0,
					'term_slug' => '',
					'label' => __('Uncategorized', 'wc-order-splitter'),
					'items' => array(),
				);
			} else {
				$term = reset($leaf_terms);
				$bucket_key = 'category-' . absint($term->term_id);
				$bucket = array(
					'key' => $bucket_key,
					'term_id' => absint($term->term_id),
					'term_slug' => sanitize_title((string) $term->slug),
					'label' => (string) $term->name,
					'items' => array(),
				);
			}

			if (!isset($buckets[$bucket_key])) {
				$buckets[$bucket_key] = $bucket;
			}
			$buckets[$bucket_key]['items'][(int) $item_id] = WCOS_Decimal::normalize($item->get_quantity(), 6);
		}

		ksort($buckets, SORT_STRING);
		foreach ($buckets as &$bucket) {
			ksort($bucket['items'], SORT_NUMERIC);
		}
		unset($bucket);

		if (count($buckets) < 2) {
			return self::reject($report, 'single_category_bucket', __('Category Split requires at least two deterministic category buckets.', 'wc-order-splitter'));
		}

		$report['buckets'] = $buckets;
		$report['classification_fingerprint'] = self::classification_fingerprint(
			$source->get_id(),
			$report['source_signature'],
			$buckets
		);
		$report['supported'] = true;
		$report['reason'] = 'supported';
		$report['message'] = __('The order has deterministic category buckets that can be reviewed for Category Split.', 'wc-order-splitter');
		return $report;
	}

	public static function build_plan(array $review, $source_bucket_key) {
		if (empty($review['supported']) || self::STRATEGY !== sanitize_key(isset($review['strategy']) ? (string) $review['strategy'] : '')) {
			throw new InvalidArgumentException(__('A supported Category Split review is required to build a plan.', 'wc-order-splitter'));
		}
		if (!isset($review['policy_version']) || self::POLICY_VERSION !== (int) $review['policy_version']) {
			throw new RuntimeException(__('The Category Split review policy no longer matches the current planner.', 'wc-order-splitter'));
		}
		if (!isset($review['execution_policy']) || WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== $review['execution_policy']) {
			throw new RuntimeException(__('The Category Split review does not carry the required whole-line execution policy.', 'wc-order-splitter'));
		}
		if (empty($review['order_id']) || empty($review['classification_fingerprint']) || empty($review['source_signature'])) {
			throw new RuntimeException(__('The Category Split review is missing frozen classification authority.', 'wc-order-splitter'));
		}

		$buckets = isset($review['buckets']) && is_array($review['buckets']) ? $review['buckets'] : array();
		$expected_fingerprint = self::classification_fingerprint(
			absint($review['order_id']),
			(string) $review['source_signature'],
			$buckets
		);
		if (!hash_equals((string) $review['classification_fingerprint'], $expected_fingerprint)) {
			throw new RuntimeException(__('The frozen Category Split review evidence failed its integrity fingerprint.', 'wc-order-splitter'));
		}

		$source_bucket_key = sanitize_key((string) $source_bucket_key);
		if ('' === $source_bucket_key || !isset($buckets[$source_bucket_key])) {
			throw new InvalidArgumentException(__('Choose one reviewed category bucket to remain on the source order.', 'wc-order-splitter'));
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
			throw new InvalidArgumentException(__('Category Split must create at least one child bucket.', 'wc-order-splitter'));
		}

		ksort($plan, SORT_STRING);
		return WCOS_Split_Plan::canonicalize_request($plan);
	}

	private static function leaf_assigned_terms(array $terms) {
		$by_id = array();
		foreach ($terms as $term) {
			if ($term instanceof WP_Term && absint($term->term_id)) {
				$by_id[absint($term->term_id)] = $term;
			}
		}
		$leaf = $by_id;
		foreach ($by_id as $candidate_id => $candidate) {
			foreach ($by_id as $other_id => $other) {
				if ($candidate_id === $other_id) {
					continue;
				}
				$ancestors = array_map('absint', get_ancestors($other_id, 'product_cat', 'taxonomy'));
				if (in_array($candidate_id, $ancestors, true)) {
					unset($leaf[$candidate_id]);
					break;
				}
			}
		}
		ksort($leaf, SORT_NUMERIC);
		return array_values($leaf);
	}

	private static function classification_fingerprint($source_order_id, $source_signature, array $buckets) {
		$evidence = array();
		foreach ($buckets as $bucket_key => $bucket) {
			/*
			 * Stable term ID + frozen source-item allocations are authority. Term
			 * slug/name remain display data and deliberately do not affect identity.
			 */
			$evidence[$bucket_key] = array(
				'term_id' => isset($bucket['term_id']) ? absint($bucket['term_id']) : 0,
				'items' => isset($bucket['items']) && is_array($bucket['items']) ? $bucket['items'] : array(),
			);
		}
		return WCOS_Mutation_Fingerprint::create(
			'category_split_review',
			absint($source_order_id),
			array(
				'policy_version' => self::POLICY_VERSION,
				'source_signature' => (string) $source_signature,
				'evidence' => $evidence,
			)
		);
	}

	private static function reject(array $report, $reason, $message) {
		$report['supported'] = false;
		$report['reason'] = sanitize_key((string) $reason);
		$report['message'] = (string) $message;
		return $report;
	}
}
