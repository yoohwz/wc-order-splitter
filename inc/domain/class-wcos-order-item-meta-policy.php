<?php

defined('ABSPATH') || exit;

/**
 * Explicit policy for copying and identifying order-item metadata.
 *
 * Public metadata is treated as immutable business configuration by default.
 * Private metadata (keys beginning with an underscore) is operational by
 * default and requires an explicit adapter/filter to become business metadata.
 * Protected stock, refund, ownership, and mutation keys are always operational
 * and cannot be re-enabled by filters.
 */
final class WCOS_Order_Item_Meta_Policy {

	const CONTEXT_DUPLICATE = 'duplicate';
	const CONTEXT_SPLIT = 'split';
	const CONTEXT_IDENTITY = 'identity';

	const CLASS_BUSINESS = 'business';
	const CLASS_OPERATIONAL = 'operational';

	public static function copy(WC_Order_Item $source, WC_Order_Item $target, $context, array $excluded_keys = array()) {
		$context = sanitize_key($context);
		$excluded_keys = array_map('strval', $excluded_keys);
		$copied = array();
		$excluded = array();

		foreach ($source->get_meta_data() as $meta) {
			$key = (string) $meta->key;
			if (in_array($key, $excluded_keys, true)
				|| self::CLASS_BUSINESS !== self::classify($key, $meta->value, $context, $source)) {
				$excluded[] = $key;
				continue;
			}

			$should_copy = (bool) apply_filters(
				'wcos_order_item_meta_should_copy',
				true,
				$key,
				$meta->value,
				$context,
				$source,
				$target
			);

			if (!$should_copy) {
				$excluded[] = $key;
				continue;
			}

			$target->add_meta_data($key, $meta->value);
			$copied[] = $key;
		}

		return array('copied' => $copied, 'excluded' => $excluded);
	}

	public static function business_metadata(WC_Order_Item $item) {
		$metadata = array();
		foreach ($item->get_meta_data() as $meta) {
			$key = (string) $meta->key;
			if (self::CLASS_BUSINESS !== self::classify($key, $meta->value, self::CONTEXT_IDENTITY, $item)) {
				continue;
			}
			$metadata[$key][] = self::normalize_identity_value($meta->value, $key);
		}
		ksort($metadata, SORT_STRING);
		return $metadata;
	}

	/**
	 * Private line metadata is ambiguous until an integration declares whether
	 * it is immutable business configuration or known operational state.
	 *
	 * Business adapters should use `wcos_order_item_meta_classification` and
	 * return `business`. Operational adapters should leave classification as
	 * operational and return true from `wcos_order_item_private_meta_is_known_operational`.
	 * Protected core/mutation keys never require adapter classification.
	 */
	public static function unknown_private_keys(WC_Order_Item $item, $context = self::CONTEXT_SPLIT) {
		$context = sanitize_key((string) $context);
		$unknown = array();
		foreach ($item->get_meta_data() as $meta) {
			$key = (string) $meta->key;
			if (0 !== strpos($key, '_') || self::is_protected($key)) {
				continue;
			}
			if (self::CLASS_BUSINESS === self::classify($key, $meta->value, $context, $item)) {
				continue;
			}
			$known_operational = (bool) apply_filters(
				'wcos_order_item_private_meta_is_known_operational',
				false,
				$key,
				$meta->value,
				$context,
				$item
			);
			if (!$known_operational) {
				$unknown[] = $key;
			}
		}
		$unknown = array_values(array_unique($unknown));
		sort($unknown, SORT_STRING);
		return $unknown;
	}

	public static function classify($key, $value, $context, WC_Order_Item $item = null) {
		$key = (string) $key;
		$context = sanitize_key($context);

		if (self::is_protected($key)) {
			return self::CLASS_OPERATIONAL;
		}

		$classification = 0 === strpos($key, '_') ? self::CLASS_OPERATIONAL : self::CLASS_BUSINESS;
		$classification = sanitize_key(
			(string) apply_filters(
				'wcos_order_item_meta_classification',
				$classification,
				$key,
				$value,
				$context,
				$item
			)
		);

		if (!in_array($classification, array(self::CLASS_BUSINESS, self::CLASS_OPERATIONAL), true)) {
			$classification = self::CLASS_OPERATIONAL;
		}

		$is_operational = self::CLASS_OPERATIONAL === $classification;
		$is_operational = (bool) apply_filters(
			'wcos_order_item_meta_is_operational',
			$is_operational,
			$key,
			$value,
			$item
		);

		return $is_operational ? self::CLASS_OPERATIONAL : self::CLASS_BUSINESS;
	}

	public static function is_protected($key) {
		$key = (string) $key;
		$exact = array(
			'_reduced_stock',
			'_restock_refunded_items',
			'_refunded_item_id',
			'_refunded_by',
			'_wcos_operation_id',
			'_wcos_source_item_id',
			'_yoos_original_item_id',
		);
		if (in_array($key, $exact, true)) {
			return true;
		}

		foreach (array('_wcos_', '_yoos_mutation_') as $prefix) {
			if (0 === strpos($key, $prefix)) {
				return true;
			}
		}

		return false;
	}

	private static function normalize_identity_value($value, $meta_key = '') {
		if (is_array($value)) {
			$normalized = array();
			foreach ($value as $key => $item) {
				$normalized[$key] = self::normalize_identity_value($item, $meta_key);
			}
			if (self::is_associative($normalized)) {
				ksort($normalized, SORT_STRING);
			}
			return $normalized;
		}

		if (is_object($value) || is_resource($value)) {
			throw new RuntimeException(
				sprintf(
					__('Business metadata "%s" contains a non-canonical object or resource value. Classify it as operational or normalize it before order mutation.', 'wc-order-splitter'),
					(string) $meta_key
				)
			);
		}

		if (is_float($value) && !is_finite($value)) {
			throw new RuntimeException(
				sprintf(
					__('Business metadata "%s" contains a non-finite numeric value and cannot form a stable line identity.', 'wc-order-splitter'),
					(string) $meta_key
				)
			);
		}

		return $value;
	}

	private static function is_associative(array $value) {
		$expected = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expected++) {
				return true;
			}
		return false;
	}
}
