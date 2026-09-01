<?php

defined('ABSPATH') || exit;

/**
 * Canonical ordinary-commercial Merge disposition and conservation authority.
 *
 * This policy deliberately moves only persisted product-line outcomes. Source
 * charges and order-level context remain archived on the source; the target's
 * existing charges and context remain authoritative.
 */
final class WCOS_Merge_Commercial_Policy {

	const SCHEMA_VERSION = 2;
	const PREVIOUS_SCHEMA_VERSION = 1;
	const POLICY_VERSION = 2;
	const PREVIOUS_POLICY_VERSION = 1;

	public static function authority(WC_Order $source, WC_Order $target, $precision, array $financial_authority = array()) {
		$precision = WCOS_Price_Precision_Scope::validate($precision);
		$financial_authority = empty($financial_authority)
			? WCOS_Merge_Financial_Authority::freeze_pair($source, $target, $precision)
			: WCOS_Merge_Financial_Authority::canonicalize_pair($financial_authority);
		$financial_target = WCOS_Merge_Financial_Authority::target_has_history($financial_authority);
		return self::canonicalize_authority(array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'commercial_scope' => $financial_target ? 'settlement_neutral_financial_target' : 'ordinary_unpaid_non_refund',
			'target_status_disposition' => 'keep_target',
			'source_retirement_disposition' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'source_shipping_disposition' => 'retain_on_retired_source',
			'source_fee_disposition' => 'retain_on_retired_source',
			'source_coupon_disposition' => 'retain_on_retired_source',
			'target_charge_shipping_disposition' => 'preserve_target',
			'order_context_disposition' => 'keep_target_context',
			'line_disposition' => $financial_target ? 'fresh_target_line_only' : 'canonical_identity_coalesce_or_fresh',
			'historical_money_tax' => 'preserve_exact',
			'catalog_tax_promotion_recalculation' => 'never',
			'physical_stock_disposition' => 'neutral',
			'financial_target' => $financial_target,
			'financial_policy_fingerprint' => $financial_authority['pair_financial_policy_fingerprint'],
			'target_financial_history_disposition' => $financial_target ? 'preserve_exact' : 'absent',
			'payment_refund_api_disposition' => 'never',
			'source_status' => sanitize_key((string) $source->get_status()),
			'target_status' => sanitize_key((string) $target->get_status()),
			'currency' => (string) $target->get_currency(),
			'prices_include_tax' => (bool) $target->get_prices_include_tax(),
			'price_precision' => $precision,
		));
	}

	public static function canonicalize_authority(array $authority) {
		$expected = array(
			'catalog_tax_promotion_recalculation', 'commercial_scope', 'currency', 'historical_money_tax',
			'financial_policy_fingerprint', 'financial_target', 'line_disposition', 'order_context_disposition',
			'payment_refund_api_disposition', 'physical_stock_disposition', 'policy_version',
			'price_precision', 'prices_include_tax', 'schema_version', 'source_coupon_disposition',
			'source_fee_disposition', 'source_retirement_disposition', 'source_shipping_disposition',
			'source_status', 'target_charge_shipping_disposition', 'target_financial_history_disposition',
			'target_status', 'target_status_disposition',
		);
		$actual = array_keys($authority);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		if ($actual !== $expected
			|| self::SCHEMA_VERSION !== (int) $authority['schema_version']
			|| self::POLICY_VERSION !== (int) $authority['policy_version']
			|| !in_array($authority['financial_target'], array(true, false), true)
			|| 'keep_target' !== (string) $authority['target_status_disposition']
			|| WCOS_Merge_Retirement_Policy::approved_identifier() !== sanitize_key((string) $authority['source_retirement_disposition'])
			|| 'retain_on_retired_source' !== (string) $authority['source_shipping_disposition']
			|| 'retain_on_retired_source' !== (string) $authority['source_fee_disposition']
			|| 'retain_on_retired_source' !== (string) $authority['source_coupon_disposition']
			|| 'preserve_target' !== (string) $authority['target_charge_shipping_disposition']
			|| 'keep_target_context' !== (string) $authority['order_context_disposition']
			|| 'preserve_exact' !== (string) $authority['historical_money_tax']
			|| 'never' !== (string) $authority['catalog_tax_promotion_recalculation']
			|| 'never' !== (string) $authority['payment_refund_api_disposition']
			|| 'neutral' !== (string) $authority['physical_stock_disposition']
			|| !in_array($authority['prices_include_tax'], array(true, false), true)) {
			throw new InvalidArgumentException(__('The Merge commercial policy authority is malformed.', 'wc-order-splitter'));
		}
		$financial_target = (bool) $authority['financial_target'];
		$financial_fingerprint = self::normalized_fingerprint($authority['financial_policy_fingerprint']);
		if ('' === $financial_fingerprint
			|| ($financial_target && ('settlement_neutral_financial_target' !== (string) $authority['commercial_scope']
				|| 'fresh_target_line_only' !== (string) $authority['line_disposition']
				|| 'preserve_exact' !== (string) $authority['target_financial_history_disposition']))
			|| (!$financial_target && ('ordinary_unpaid_non_refund' !== (string) $authority['commercial_scope']
				|| 'canonical_identity_coalesce_or_fresh' !== (string) $authority['line_disposition']
				|| 'absent' !== (string) $authority['target_financial_history_disposition']))) {
			throw new InvalidArgumentException(__('The Merge financial-commercial disposition is inconsistent.', 'wc-order-splitter'));
		}
		$source_status = sanitize_key((string) $authority['source_status']);
		$target_status = sanitize_key((string) $authority['target_status']);
		$currency = strtoupper(trim((string) $authority['currency']));
		if ('' === $source_status || '' === $target_status || '' === $currency) {
			throw new InvalidArgumentException(__('The Merge commercial status or currency authority is incomplete.', 'wc-order-splitter'));
		}
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'commercial_scope' => $financial_target ? 'settlement_neutral_financial_target' : 'ordinary_unpaid_non_refund',
			'target_status_disposition' => 'keep_target',
			'source_retirement_disposition' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'source_shipping_disposition' => 'retain_on_retired_source',
			'source_fee_disposition' => 'retain_on_retired_source',
			'source_coupon_disposition' => 'retain_on_retired_source',
			'target_charge_shipping_disposition' => 'preserve_target',
			'order_context_disposition' => 'keep_target_context',
			'line_disposition' => $financial_target ? 'fresh_target_line_only' : 'canonical_identity_coalesce_or_fresh',
			'historical_money_tax' => 'preserve_exact',
			'catalog_tax_promotion_recalculation' => 'never',
			'physical_stock_disposition' => 'neutral',
			'financial_target' => $financial_target,
			'financial_policy_fingerprint' => $financial_fingerprint,
			'target_financial_history_disposition' => $financial_target ? 'preserve_exact' : 'absent',
			'payment_refund_api_disposition' => 'never',
			'source_status' => $source_status,
			'target_status' => $target_status,
			'currency' => $currency,
			'prices_include_tax' => (bool) $authority['prices_include_tax'],
			'price_precision' => WCOS_Price_Precision_Scope::validate($authority['price_precision']),
		);
	}

	/** Exact WOS-COMPAT-005 policy parser retained only for durable schema-v3 plans. */
	public static function canonicalize_previous_authority(array $authority) {
		$expected = array(
			'catalog_tax_promotion_recalculation', 'commercial_scope', 'currency', 'historical_money_tax',
			'line_disposition', 'order_context_disposition', 'physical_stock_disposition', 'policy_version',
			'price_precision', 'prices_include_tax', 'schema_version', 'source_coupon_disposition',
			'source_fee_disposition', 'source_retirement_disposition', 'source_shipping_disposition',
			'source_status', 'target_charge_shipping_disposition', 'target_status', 'target_status_disposition',
		);
		$actual = array_keys($authority);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		if ($actual !== $expected
			|| self::PREVIOUS_SCHEMA_VERSION !== (int) $authority['schema_version']
			|| self::PREVIOUS_POLICY_VERSION !== (int) $authority['policy_version']
			|| 'ordinary_unpaid_non_refund' !== (string) $authority['commercial_scope']
			|| 'keep_target' !== (string) $authority['target_status_disposition']
			|| WCOS_Merge_Retirement_Policy::approved_identifier() !== sanitize_key((string) $authority['source_retirement_disposition'])
			|| 'retain_on_retired_source' !== (string) $authority['source_shipping_disposition']
			|| 'retain_on_retired_source' !== (string) $authority['source_fee_disposition']
			|| 'retain_on_retired_source' !== (string) $authority['source_coupon_disposition']
			|| 'preserve_target' !== (string) $authority['target_charge_shipping_disposition']
			|| 'keep_target_context' !== (string) $authority['order_context_disposition']
			|| 'canonical_identity_coalesce_or_fresh' !== (string) $authority['line_disposition']
			|| 'preserve_exact' !== (string) $authority['historical_money_tax']
			|| 'never' !== (string) $authority['catalog_tax_promotion_recalculation']
			|| 'neutral' !== (string) $authority['physical_stock_disposition']
			|| !in_array($authority['prices_include_tax'], array(true, false), true)) {
			throw new InvalidArgumentException(__('The WOS-COMPAT-005 Merge commercial authority is malformed.', 'wc-order-splitter'));
		}
		$source_status = sanitize_key((string) $authority['source_status']);
		$target_status = sanitize_key((string) $authority['target_status']);
		$currency = strtoupper(trim((string) $authority['currency']));
		if ('' === $source_status || '' === $target_status || '' === $currency) {
			throw new InvalidArgumentException(__('The WOS-COMPAT-005 Merge status or currency authority is incomplete.', 'wc-order-splitter'));
		}
		$authority['source_status'] = $source_status;
		$authority['target_status'] = $target_status;
		$authority['currency'] = $currency;
		$authority['price_precision'] = WCOS_Price_Precision_Scope::validate($authority['price_precision']);
		$authority['prices_include_tax'] = (bool) $authority['prices_include_tax'];
		return $authority;
	}

	/** Canonical identity for deciding whether historical values may be added. */
	public static function line_identity(WC_Order_Item_Product $item) {
		$taxes = $item->get_taxes();
		$structure = array();
		foreach (array('subtotal', 'total') as $bucket) {
			$rate_ids = array_map('strval', array_keys(isset($taxes[$bucket]) && is_array($taxes[$bucket]) ? $taxes[$bucket] : array()));
			sort($rate_ids, SORT_STRING);
			$structure[$bucket] = $rate_ids;
		}
		return WCOS_Mutation_Fingerprint::create(
			'merge_commercial_line_identity_v1',
			absint($item->get_product_id()),
			array(
				'line_identity' => WCOS_Line_Identity::from_item($item),
				'name' => (string) $item->get_name(),
				'tax_rate_structure' => $structure,
				'quantity_precision' => 6,
			)
		);
	}

	public static function product_tax_rate_ids(WC_Order $order) {
		$ids = array();
		foreach ($order->get_items('line_item') as $item) {
			foreach (array('subtotal', 'total') as $bucket) {
				$taxes = $item->get_taxes();
				foreach (array_keys(isset($taxes[$bucket]) && is_array($taxes[$bucket]) ? $taxes[$bucket] : array()) as $rate_id) {
					$ids[] = (int) $rate_id;
				}
			}
		}
		$ids = array_values(array_unique($ids));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	/** Expected active target economy after only source product history moves. */
	public static function expected_target_contract(WC_Order $source, WC_Order $target, $precision) {
		$precision = WCOS_Price_Precision_Scope::validate($precision);
		$result = WCOS_Order_Contract_Snapshot::aggregate(array($target), $precision);
		$source_lines = self::line_contract($source, $precision);
		$financial_target = WCOS_Merge_Financial_Authority::has_history($target, $precision);

		foreach (array('line_subtotal', 'line_total', 'line_subtotal_tax', 'line_total_tax') as $field) {
			$result[$field] = self::add_decimal($result[$field], $source_lines[$field], $precision);
		}
		$result['discount_total'] = self::add_decimal(
			$result['discount_total'],
			self::subtract_decimal($source_lines['line_subtotal'], $source_lines['line_total'], $precision),
			$precision
		);
		$result['discount_tax'] = self::add_decimal(
			$result['discount_tax'],
			self::subtract_decimal($source_lines['line_subtotal_tax'], $source_lines['line_total_tax'], $precision),
			$precision
		);
		$result['tax_total'] = self::add_decimal($result['tax_total'], $source_lines['line_total_tax'], $precision);
		$result['grand_total'] = self::add_decimal(
			$result['grand_total'],
			self::add_decimal($source_lines['line_total'], $source_lines['line_total_tax'], $precision),
			$precision
		);
		$result['stock_reduced'] = self::add_decimal($result['stock_reduced'], $source_lines['stock_reduced'], 6);

		foreach ($source_lines['line_quantities'] as $identity => $quantity) {
			$current = isset($result['line_quantities'][$identity]) ? $result['line_quantities'][$identity] : '0.000000';
			$result['line_quantities'][$identity] = self::add_decimal($current, $quantity, 6);
		}
		ksort($result['line_quantities'], SORT_STRING);

		foreach ($source_lines['line_tax_by_rate'] as $rate_id => $taxes) {
			if (!isset($result['line_tax_by_rate'][$rate_id])) {
				$zero = WCOS_Decimal::from_units(0, $precision);
				$result['line_tax_by_rate'][$rate_id] = array('subtotal' => $zero, 'total' => $zero);
			}
			foreach (array('subtotal', 'total') as $bucket) {
				$result['line_tax_by_rate'][$rate_id][$bucket] = self::add_decimal(
					$result['line_tax_by_rate'][$rate_id][$bucket],
					$taxes[$bucket],
					$precision
				);
			}
			if (!$financial_target && !isset($result['tax_by_rate'][$rate_id])) {
				$zero = WCOS_Decimal::from_units(0, $precision);
				$result['tax_by_rate'][$rate_id] = array('cart' => $zero, 'shipping' => $zero);
			}
			if (isset($result['tax_by_rate'][$rate_id])) {
				$result['tax_by_rate'][$rate_id]['cart'] = self::add_decimal(
					$result['tax_by_rate'][$rate_id]['cart'],
					$taxes['total'],
					$precision
				);
			}
		}
		ksort($result['line_tax_by_rate'], SORT_STRING);
		ksort($result['tax_by_rate'], SORT_STRING);
		return $result;
	}

	public static function expected_target_signature(WC_Order $source, WC_Order $target, $precision, $preflight_policy_version = null) {
		return WCOS_Mutation_Fingerprint::create(
			self::active_economic_namespace($preflight_policy_version),
			$source->get_id(),
			self::expected_target_contract($source, $target, $precision)
		);
	}

	public static function target_signature(WC_Order $target, $precision, $source_order_id, $preflight_policy_version = null) {
		return WCOS_Mutation_Fingerprint::create(
			self::active_economic_namespace($preflight_policy_version),
			absint($source_order_id),
			WCOS_Order_Contract_Snapshot::aggregate(array($target), $precision)
		);
	}

	private static function active_economic_namespace($preflight_policy_version) {
		$preflight_policy_version = null === $preflight_policy_version
			? WCOS_Merge_Preflight::POLICY_VERSION
			: (int) $preflight_policy_version;
		return WCOS_Merge_Preflight::PREVIOUS_POLICY_VERSION === $preflight_policy_version
			? 'merge_ordinary_active_economic_v1'
			: 'merge_financial_boundary_active_economic_v1';
	}

	public static function add_decimal($left, $right, $precision) {
		$left = WCOS_Decimal::to_units($left, $precision);
		$right = WCOS_Decimal::to_units($right, $precision);
		if ($right > 0 && $left > PHP_INT_MAX - $right) {
			throw new OverflowException('Merge commercial value exceeds the supported integer range.');
		}
		if ($right < 0 && $left < -PHP_INT_MAX - $right) {
			throw new OverflowException('Merge commercial value exceeds the supported integer range.');
		}
		return WCOS_Decimal::from_units($left + $right, $precision);
	}

	private static function subtract_decimal($left, $right, $precision) {
		return self::add_decimal($left, WCOS_Decimal::from_units(-WCOS_Decimal::to_units($right, $precision), $precision), $precision);
	}

	private static function line_contract(WC_Order $order, $precision) {
		$result = array(
			'line_subtotal' => '0', 'line_total' => '0', 'line_subtotal_tax' => '0', 'line_total_tax' => '0',
			'stock_reduced' => '0', 'line_quantities' => array(), 'line_tax_by_rate' => array(),
		);
		foreach ($order->get_items('line_item') as $item) {
			$result['line_subtotal'] = self::add_decimal($result['line_subtotal'], $item->get_subtotal(), $precision);
			$result['line_total'] = self::add_decimal($result['line_total'], $item->get_total(), $precision);
			$result['line_subtotal_tax'] = self::add_decimal($result['line_subtotal_tax'], $item->get_subtotal_tax(), $precision);
			$result['line_total_tax'] = self::add_decimal($result['line_total_tax'], $item->get_total_tax(), $precision);
			$identity = WCOS_Line_Identity::from_item($item);
			$current_quantity = isset($result['line_quantities'][$identity]) ? $result['line_quantities'][$identity] : '0.000000';
			$result['line_quantities'][$identity] = self::add_decimal($current_quantity, $item->get_quantity(), 6);
			$reduced = $item->get_meta('_reduced_stock', true);
			if ('' !== $reduced && null !== $reduced) {
				$result['stock_reduced'] = self::add_decimal($result['stock_reduced'], $reduced, 6);
			}
			$taxes = $item->get_taxes();
			foreach (array('subtotal', 'total') as $bucket) {
				foreach (isset($taxes[$bucket]) && is_array($taxes[$bucket]) ? $taxes[$bucket] : array() as $rate_id => $amount) {
					$key = (string) (int) $rate_id;
					if (!isset($result['line_tax_by_rate'][$key])) {
						$result['line_tax_by_rate'][$key] = array('subtotal' => '0', 'total' => '0');
					}
					$result['line_tax_by_rate'][$key][$bucket] = self::add_decimal($result['line_tax_by_rate'][$key][$bucket], $amount, $precision);
				}
			}
		}
		ksort($result['line_quantities'], SORT_STRING);
		ksort($result['line_tax_by_rate'], SORT_STRING);
		return $result;
	}

	private static function normalized_fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
