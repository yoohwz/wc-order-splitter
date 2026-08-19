<?php

defined('ABSPATH') || exit;

/**
 * Read-only planner for a future Category Split strategy.
 *
 * Category classification is performed only during Review. Execution must use
 * the frozen quantity plan produced from this report and must never re-query
 * catalog categories.
 */
final class WCOS_Category_Split_Planner {
	const STRATEGY = 'category';
	const POLICY_VERSION = 1;
	const UNCATEGORIZED_BUCKET = 'category-uncategorized';

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

		/*
		 * WooCommerce guarantees a default product category and normally assigns
		 * it when a persisted product has no explicit category. Treat the stable
		 * default term ID as the semantic Uncategorized bucket when it is the
		 * product's only leaf category. Never depend on the mutable term name or
		 * slug for this classification.
		 */
		$default_category_id = absint(get_option('default_product_cat', 0));
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

			/* Product categories are owned by the parent product for variations. */
			$product_id = absint($item->get_product_id());
			$terms = wp_get_post_terms($product_id, 'product_cat');
			if (is_wp_error($terms)) {
				return self::reject(
					$report,
					'category_lookup_failed',
					__('Product categories could not be read safely for this order.', 'wc-order-splitter')
				);
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

			$term = empty($leaf_terms) ? null : reset($leaf_terms);
			$is_default_category = $term instanceof WP_Term
				&& $default_category_id > 0
				&& $default_category_id === absint($term->term_id);

			if (!$term instanceof WP_Term || $is_default_category) {
				$bucket_key = self::UNCATEGORIZED_BUCKET;
				$bucket = array(
					'key' => $bucket_key,
					'term_id' => $is_default_category ? absint($term->term_id) : 0,
					'term_slug' => $is_default_category ? sanitize_title((string) $term->slug) : '',
					'label' => $is_default_category ? (string) $term->name : __('Uncategorized', 'wc-order-splitter'),
					'items' => array(),
				);
			} else {
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
			return self::reject(
				$report,
				'single_category_bucket',
				__('Category Split requires at least two deterministic category buckets.', 'wc-order-splitter')
			);
		}

		$report['buckets'] = $buckets;
		$report['classification_fingerprint'] = self::classification_fingerprint($source, $buckets);
		$report['supported'] = true;
		$report['reason'] = 'supported';
		$report['message'] = __('The order has deterministic category buckets that can be reviewed for a future Category Split.', 'wc-order-splitter');
		return $report;
	}

	public static function build_plan(array $review, $source_bucket_key) {
		self::assert_review_authority($review);

		$source_bucket_key = sanitize_key((string) $source_bucket_key);
		$buckets = isset($review['buckets']) && is_array($review['buckets']) ? $review['buckets'] : array();
		if ('' === $source_bucket_key || !isset($buckets[$source_bucket_key])) {
			throw new InvalidArgumentException(__('Choose one reviewed category bucket to remain on the source order.', 'wc-order-splitter'));
		}

		$plan = array();
		foreach ($buckets as $bucket_key => $bucket) {
			if ($bucket_key === $source_bucket_key) {
				continue;
			}
			$items = isset($bucket['items']) && is_array($bucket['items']) ? $bucket['items'] : array();
			if (empty($items)) {
				continue;
			}
			$plan[sanitize_key((string) $bucket_key)] = $items;
		}

		if (empty($plan)) {
			throw new InvalidArgumentException(__('Category Split must create at least one child bucket.', 'wc-order-splitter'));
		}

		ksort($plan, SORT_STRING);
		return WCOS_Split_Plan::canonicalize_request($plan);
	}

	private static function assert_review_authority(array $review) {
		if (empty($review['supported']) || self::STRATEGY !== sanitize_key(isset($review['strategy']) ? (string) $review['strategy'] : '')) {
			throw new InvalidArgumentException(__('A supported Category Split review is required to build a plan.', 'wc-order-splitter'));
		}
		if (!isset($review['policy_version']) || self::POLICY_VERSION !== (int) $review['policy_version']) {
			throw new RuntimeException(__('The Category Split review policy no longer matches the current planner.', 'wc-order-splitter'));
		}
		$policy = isset($review['execution_policy']) ? WCOS_Split_Execution_Policy::normalize($review['execution_policy']) : '';
		if (WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== $policy) {
			throw new RuntimeException(__('The Category Split review does not carry whole-line execution authority.', 'wc-order-splitter'));
		}
		if (empty($review['source_signature']) || empty($review['classification_fingerprint'])) {
			throw new RuntimeException(__('The Category Split review is missing frozen source or classification authority.', 'wc-order-splitter'));
		}
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

	private static function classification_fingerprint(WC_Order $source, array $buckets) {
		$evidence = array();
		foreach ($buckets as $bucket_key => $bucket) {
			/* Stable term IDs and historical item quantities are authority. */
			$evidence[$bucket_key] = array(
				'term_id' => isset($bucket['term_id']) ? absint($bucket['term_id']) : 0,
				'items' => isset($bucket['items']) && is_array($bucket['items']) ? $bucket['items'] : array(),
			);
		}

		return WCOS_Mutation_Fingerprint::create(
			'category_split_review',
			$source->get_id(),
			array(
				'policy_version' => self::POLICY_VERSION,
				'source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
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
