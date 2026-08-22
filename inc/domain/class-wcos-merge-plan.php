<?php

defined('ABSPATH') || exit;

/**
 * Canonical server-built whole-line Merge plan for the first safety tranche.
 */
final class WCOS_Merge_Plan {

	const SCHEMA_VERSION = 2;

	public static function build(WC_Order $source, WC_Order $target) {
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		$lines = array();
		foreach ($source->get_items('line_item') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				throw new RuntimeException(__('Merge encountered an unsupported source product-line object.', 'wc-order-splitter'));
			}
			$lines[(int) $item_id] = array(
				'source_item_id' => (int) $item_id,
				'line_identity' => WCOS_Line_Identity::from_item($item),
				'product_id' => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'tax_class' => (string) $item->get_tax_class(),
				'quantity' => WCOS_Decimal::normalize($item->get_quantity(), 6),
				'subtotal' => (string) $item->get_subtotal(),
				'subtotal_tax' => (string) $item->get_subtotal_tax(),
				'total' => (string) $item->get_total(),
				'total_tax' => (string) $item->get_total_tax(),
				'taxes' => $item->get_taxes(),
				'reduced_stock' => self::normalize_reduced_stock($item->get_meta('_reduced_stock', true)),
			);
		}

		return self::canonicalize($source_id, $target_id, $lines);
	}

	public static function canonicalize($source_order_id, $target_order_id, array $lines) {
		$source_order_id = absint($source_order_id);
		$target_order_id = absint($target_order_id);
		if (!$source_order_id || !$target_order_id) {
			throw new InvalidArgumentException(__('Persisted source and target order IDs are required for a Merge plan.', 'wc-order-splitter'));
		}
		if ($source_order_id === $target_order_id) {
			throw new InvalidArgumentException(__('An order cannot be merged into itself.', 'wc-order-splitter'));
		}
		if (empty($lines)) {
			throw new InvalidArgumentException(__('A Merge plan requires at least one source product line.', 'wc-order-splitter'));
		}

		$canonical_lines = array();
		foreach ($lines as $key => $line) {
			if (!is_array($line)) {
				throw new InvalidArgumentException(__('Every Merge plan line must be a canonical array.', 'wc-order-splitter'));
			}
			$item_id = absint(isset($line['source_item_id']) ? $line['source_item_id'] : $key);
			$identity = sanitize_key(isset($line['line_identity']) ? (string) $line['line_identity'] : '');
			if (!$item_id || '' === $identity || isset($canonical_lines[$item_id])) {
				throw new InvalidArgumentException(__('Merge plan source lines require unique persisted item IDs and exact identities.', 'wc-order-splitter'));
			}
			$line['source_item_id'] = $item_id;
			$line['line_identity'] = $identity;
			$canonical_lines[$item_id] = self::canonicalize_value($line);
		}
		ksort($canonical_lines, SORT_NUMERIC);

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'source_order_id' => $source_order_id,
			'target_order_id' => $target_order_id,
			'line_policy' => 'fresh_target_line_per_source_line',
			'coalesce_lines' => false,
			'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'lines' => $canonical_lines,
		);
	}

	public static function fingerprint(array $plan) {
		if (!isset($plan['retirement_policy'])
			|| WCOS_Merge_Retirement_Policy::approved_identifier() !== sanitize_key((string) $plan['retirement_policy'])) {
			throw new InvalidArgumentException(__('The Merge plan does not bind the approved retirement policy.', 'wc-order-splitter'));
		}
		$plan = self::canonicalize(
			isset($plan['source_order_id']) ? $plan['source_order_id'] : 0,
			isset($plan['target_order_id']) ? $plan['target_order_id'] : 0,
			isset($plan['lines']) && is_array($plan['lines']) ? $plan['lines'] : array()
		);
		return WCOS_Mutation_Fingerprint::create('merge_plan', $plan['source_order_id'], $plan);
	}

	private static function normalize_reduced_stock($value) {
		if ('' === $value || null === $value) {
			return null;
		}
		if (!is_numeric($value)) {
			throw new RuntimeException(__('A Merge source line contains a non-numeric reduced-stock marker.', 'wc-order-splitter'));
		}
		return WCOS_Decimal::normalize($value, 6);
	}

	private static function canonicalize_value($value) {
		if (!is_array($value)) {
			return $value;
		}
		if (self::is_list($value)) {
			$result = array();
			foreach ($value as $item) {
				$result[] = self::canonicalize_value($item);
			}
			return $result;
		}
		ksort($value, SORT_STRING);
		foreach ($value as $key => $item) {
			$value[$key] = self::canonicalize_value($item);
		}
		return $value;
	}

	private static function is_list(array $value) {
		$expected = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expected++) {
				return false;
			}
		}
		return true;
	}
}
