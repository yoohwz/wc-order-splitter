<?php

defined('ABSPATH') || exit;

/**
 * Explicit policy for copying and identifying order-item metadata.
 *
 * Unknown metadata is treated as business metadata by default so common
 * product add-on/vendor integrations keep their immutable configuration.
 * Operational ownership, stock, refund, and mutation metadata is denied.
 */
final class WCOS_Order_Item_Meta_Policy {

	const CONTEXT_DUPLICATE = 'duplicate';
	const CONTEXT_SPLIT = 'split';
	const CONTEXT_IDENTITY = 'identity';

	public static function copy(WC_Order_Item $source, WC_Order_Item $target, $context, array $excluded_keys = array()) {
		$context = sanitize_key($context);
		$excluded_keys = array_map('strval', $excluded_keys);
		$copied = array();
		$excluded = array();

		foreach ($source->get_meta_data() as $meta) {
			$key = (string) $meta->key;
			$should_copy = !in_array($key, $excluded_keys, true) && !self::is_operational($key, $meta->value, $context);
			$should_copy = (bool) apply_filters(
				'wcos_order_item_meta_should_copy',
				$should_copy,
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

		return array(
			'copied' => $copied,
			'excluded' => $excluded,
		);
	}

	public static function business_metadata(WC_Order_Item $item) {
		$metadata = array();
		foreach ($item->get_meta_data() as $meta) {
			$key = (string) $meta->key;
			$is_operational = self::is_operational($key, $meta->value, self::CONTEXT_IDENTITY);
			$is_operational = (bool) apply_filters(
				'wcos_order_item_meta_is_operational',
				$is_operational,
				$key,
				$meta->value,
				$item
			);
			if ($is_operational) {
				continue;
			}
			$metadata[$key][] = self::normalize_identity_value($meta->value);
		}
		ksort($metadata, SORT_STRING);
		return $metadata;
	}

	private static function is_operational($key, $value, $context) {
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

	private static function normalize_identity_value($value) {
		if (is_array($value)) {
			$normalized = array();
			foreach ($value as $key => $item) {
				$normalized[$key] = self::normalize_identity_value($item);
			}
			if (self::is_associative($normalized)) {
				ksort($normalized, SORT_STRING);
			}
			return $normalized;
		}

		if (is_object($value) || is_resource($value)) {
			return maybe_serialize($value);
		}

		return $value;
	}

	private static function is_associative(array $value) {
		$expected = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expected++) {
				return true;
			}
		}
		return false;
	}
}
