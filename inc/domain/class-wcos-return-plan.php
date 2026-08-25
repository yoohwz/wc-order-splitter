<?php

defined('ABSPATH') || exit;

/** Immutable, read-only all-or-nothing Return plan. */
final class WCOS_Return_Plan {

	const SCHEMA_VERSION = 1;
	const POLICY_VERSION = 1;
	const DESTINATION_RESIDUAL_SOURCE_ITEM = 'residual_source_item';
	const DESTINATION_FRESH_SOURCE_ITEM = 'fresh_source_item';

	public static function build(array $lineage_authority) {
		if (empty($lineage_authority['authority_fingerprint'])) {
			throw new InvalidArgumentException(__('A verified Return lineage authority is required.', 'wc-order-splitter'));
		}

		$price_precision = WCOS_Price_Precision_Scope::validate(isset($lineage_authority['price_precision']) ? $lineage_authority['price_precision'] : null);
		$operation_id = isset($lineage_authority['split_operation_id']) ? $lineage_authority['split_operation_id'] : '';
		$child_key = isset($lineage_authority['split_child_key']) ? $lineage_authority['split_child_key'] : '';
		$currency = isset($lineage_authority['currency']) ? $lineage_authority['currency'] : '';
		$execution_policy = isset($lineage_authority['execution_policy']) ? $lineage_authority['execution_policy'] : '';
		$strategy = isset($lineage_authority['strategy']) ? $lineage_authority['strategy'] : '';
		if (!is_string($operation_id) || sanitize_key($operation_id) !== $operation_id
			|| !is_string($child_key) || sanitize_key($child_key) !== $child_key
			|| !is_string($currency) || 1 !== preg_match('/^[A-Z]{3}$/D', $currency)
			|| !is_string($execution_policy) || !in_array($execution_policy, array('partial_lines_only', 'allow_whole_line_transfer'), true)
			|| !is_string($strategy) || !in_array($strategy, array('manual_quantity', 'category', 'stock_status'), true)
			|| ('partial_lines_only' === $execution_policy) !== ('manual_quantity' === $strategy)) {
			throw new InvalidArgumentException(__('Return plan Split identifiers are not canonical.', 'wc-order-splitter'));
		}

		$plan = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'child_order_id' => absint(isset($lineage_authority['child_order_id']) ? $lineage_authority['child_order_id'] : 0),
			'source_order_id' => absint(isset($lineage_authority['source_order_id']) ? $lineage_authority['source_order_id'] : 0),
			'split_operation_id' => $operation_id,
			'split_child_key' => $child_key,
			'lineage_authority_fingerprint' => self::fingerprint_value(isset($lineage_authority['authority_fingerprint']) ? $lineage_authority['authority_fingerprint'] : ''),
			'price_precision' => $price_precision,
			'currency' => $currency,
			'prices_include_tax' => !empty($lineage_authority['prices_include_tax']),
			'execution_policy' => $execution_policy,
			'strategy' => $strategy,
			'source_commercial_authority' => self::fingerprint_value(isset($lineage_authority['source_commercial_authority']) ? $lineage_authority['source_commercial_authority'] : ''),
			'source_relation_authority' => self::fingerprint_value(isset($lineage_authority['source_relation_authority']) ? $lineage_authority['source_relation_authority'] : ''),
			'child_commercial_authority' => self::fingerprint_value(isset($lineage_authority['child_commercial_authority']) ? $lineage_authority['child_commercial_authority'] : ''),
			'lines' => self::canonical_lines(isset($lineage_authority['lines']) && is_array($lineage_authority['lines']) ? $lineage_authority['lines'] : array(), $price_precision),
		);

		if (!$plan['child_order_id'] || !$plan['source_order_id'] || $plan['child_order_id'] === $plan['source_order_id']
			|| '' === $plan['split_operation_id'] || '' === $plan['split_child_key'] || '' === $plan['currency'] || empty($plan['lines'])) {
			throw new InvalidArgumentException(__('Return plan authority is incomplete.', 'wc-order-splitter'));
		}

		$plan['plan_fingerprint'] = self::fingerprint($plan);
		return $plan;
	}

	public static function fingerprint(array $plan) {
		$copy = $plan;
		unset($copy['plan_fingerprint']);
		$child_id = absint(isset($copy['child_order_id']) ? $copy['child_order_id'] : 0);
		return WCOS_Mutation_Fingerprint::create('return_plan', $child_id, self::canonicalize($copy));
	}

	private static function canonical_lines(array $lines, $price_precision) {
		$canonical = array();
		foreach ($lines as $raw_source_item_id => $line) {
			if (!is_array($line)) {
				throw new InvalidArgumentException(__('Every Return plan line must be structured.', 'wc-order-splitter'));
			}
			$source_item_id = absint($raw_source_item_id);
			if (!$source_item_id || isset($canonical[$source_item_id])) {
				throw new InvalidArgumentException(__('Return plan source item IDs must be unique and positive.', 'wc-order-splitter'));
			}
			$destination = sanitize_key(isset($line['destination']) ? (string) $line['destination'] : '');
			if (!in_array($destination, array(self::DESTINATION_RESIDUAL_SOURCE_ITEM, self::DESTINATION_FRESH_SOURCE_ITEM), true)) {
				throw new InvalidArgumentException(__('Return plan contains an unsupported destination policy.', 'wc-order-splitter'));
			}
			$destination_item_id = absint(isset($line['destination_source_item_id']) ? $line['destination_source_item_id'] : 0);
			if ((self::DESTINATION_RESIDUAL_SOURCE_ITEM === $destination && $destination_item_id !== $source_item_id)
				|| (self::DESTINATION_FRESH_SOURCE_ITEM === $destination && 0 !== $destination_item_id)) {
				throw new InvalidArgumentException(__('Return plan destination provenance is inconsistent.', 'wc-order-splitter'));
			}

			$child_item_id = absint(isset($line['child_item_id']) ? $line['child_item_id'] : 0);
			$product_id = absint(isset($line['product_id']) ? $line['product_id'] : 0);
			if (!$child_item_id || !$product_id) {
				throw new InvalidArgumentException(__('Return plan child item identity is missing.', 'wc-order-splitter'));
			}
			$canonical[$source_item_id] = array(
				'source_item_id' => $source_item_id,
				'child_item_id' => $child_item_id,
				'product_id' => $product_id,
				'variation_id' => absint(isset($line['variation_id']) ? $line['variation_id'] : 0),
				'tax_class' => isset($line['tax_class']) && is_string($line['tax_class']) ? $line['tax_class'] : '',
				'line_identity_authority' => self::fingerprint_value(isset($line['line_identity_authority']) ? $line['line_identity_authority'] : ''),
				'destination' => $destination,
				'destination_source_item_id' => $destination_item_id,
				'quantity' => WCOS_Decimal::normalize(isset($line['quantity']) ? $line['quantity'] : '', 6),
				'subtotal' => WCOS_Decimal::normalize(isset($line['subtotal']) ? $line['subtotal'] : '', $price_precision),
				'total' => WCOS_Decimal::normalize(isset($line['total']) ? $line['total'] : '', $price_precision),
				'subtotal_tax' => WCOS_Decimal::normalize(isset($line['subtotal_tax']) ? $line['subtotal_tax'] : '', $price_precision),
				'total_tax' => WCOS_Decimal::normalize(isset($line['total_tax']) ? $line['total_tax'] : '', $price_precision),
				'taxes' => self::canonical_taxes(isset($line['taxes']) && is_array($line['taxes']) ? $line['taxes'] : array(), $price_precision),
				'reduced_stock' => null === (isset($line['reduced_stock']) ? $line['reduced_stock'] : null)
					? null
					: WCOS_Decimal::normalize($line['reduced_stock'], 6),
			);
		}
		ksort($canonical, SORT_NUMERIC);
		return $canonical;
	}

	private static function canonical_taxes(array $taxes, $price_precision) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $kind) {
			foreach (isset($taxes[$kind]) && is_array($taxes[$kind]) ? $taxes[$kind] : array() as $rate_id => $amount) {
				$rate_id = absint($rate_id);
				if (!$rate_id || isset($result[$kind][$rate_id])) {
					throw new InvalidArgumentException(__('Return plan contains malformed per-rate tax authority.', 'wc-order-splitter'));
				}
				$result[$kind][$rate_id] = WCOS_Decimal::normalize($amount, $price_precision);
			}
			ksort($result[$kind], SORT_NUMERIC);
		}
		return $result;
	}

	private static function fingerprint_value($value) {
		$value = sanitize_key((string) $value);
		if (1 !== preg_match('/^[0-9a-f]{64}$/D', $value)) {
			throw new InvalidArgumentException(__('Return authority contains a malformed fingerprint.', 'wc-order-splitter'));
		}
		return $value;
	}

	private static function canonicalize($value) {
		if (!is_array($value)) {
			return $value;
		}
		$is_list = true;
		$expected = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expected++) {
				$is_list = false;
				break;
			}
		}
		if (!$is_list) {
			ksort($value, SORT_STRING);
		}
		foreach ($value as $key => $item) {
			$value[$key] = self::canonicalize($item);
		}
		return $value;
	}
}
