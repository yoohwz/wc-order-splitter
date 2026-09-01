<?php

defined('ABSPATH') || exit;

/** Canonical server-built Merge plan with exact v2/v3 durable replay support. */
final class WCOS_Merge_Plan {

	const SCHEMA_VERSION = 5;
	const PREVIOUS_SCHEMA_VERSION = 3;
	const LEGACY_SCHEMA_VERSION = 2;

	public static function build(WC_Order $source, WC_Order $target, $precision = null) {
		$precision = null === $precision
			? WCOS_Price_Precision_Scope::store_precision()
			: WCOS_Price_Precision_Scope::validate($precision);
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		if (!$source_id || !$target_id || $source_id === $target_id) {
			throw new InvalidArgumentException(__('A Merge plan requires two distinct persisted orders.', 'wc-order-splitter'));
		}
		$financial_authority = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, $precision);
		$financial_target = WCOS_Merge_Financial_Authority::target_has_history($financial_authority);

		$source_stock_flag = (bool) $source->get_data_store()->get_stock_reduced($source_id);
		$target_stock_flag = (bool) $target->get_data_store()->get_stock_reduced($target_id);
		$target_identities = array();
		$target_states = array();
		foreach (WCOS_Merge_Canonical_Reader::items($target, 'line_item') as $target_item_id => $target_item) {
			if (!$target_item instanceof WC_Order_Item_Product) {
				throw new RuntimeException(__('Merge encountered an unsupported target product-line object.', 'wc-order-splitter'));
			}
			$target_item_id = (int) $target_item_id;
			$target_identities[$target_item_id] = WCOS_Merge_Commercial_Policy::line_identity($target_item);
			$target_states[$target_item_id] = self::line_state($target_item);
			self::assert_reduced_stock($target_states[$target_item_id]['reduced_stock'], $target_states[$target_item_id]['quantity']);
			if (!$target_stock_flag && self::has_reduced_stock($target_states[$target_item_id]['reduced_stock'])) {
				throw new RuntimeException(__('Merge found target line-level reduced-stock ownership without its order-level stock flag.', 'wc-order-splitter'));
			}
		}
		$lines = array();
		$coalesces = false;
		foreach (WCOS_Merge_Canonical_Reader::items($source, 'line_item') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				throw new RuntimeException(__('Merge encountered an unsupported source product-line object.', 'wc-order-splitter'));
			}
			$source_state = self::line_state($item);
			if ($financial_target) {
				self::assert_financial_target_line_neutral($source_state, $precision);
			}
			self::assert_reduced_stock($source_state['reduced_stock'], $source_state['quantity']);
			if (!$source_stock_flag && self::has_reduced_stock($source_state['reduced_stock'])) {
				throw new RuntimeException(__('Merge found source line-level reduced-stock ownership without its order-level stock flag.', 'wc-order-splitter'));
			}
			$candidates = array();
			foreach ($target_identities as $target_item_id => $target_identity) {
				if (hash_equals($source_state['commercial_identity'], $target_identity)) {
					$candidates[] = (int) $target_item_id;
				}
			}

			$action = !$financial_target && 1 === count($candidates) ? 'coalesce' : 'fresh_target_line';
			$target_item_id = 'coalesce' === $action ? (int) reset($candidates) : 0;
			$target_before = array();
			$target_after = $source_state;
			if ('coalesce' === $action) {
				$coalesces = true;
				$target_before = $target_states[$target_item_id];
				$target_after = self::add_line_states($target_before, $source_state, $precision);
				$target_states[$target_item_id] = $target_after;
			}

			$lines[(int) $item_id] = array_merge(
				$source_state,
				array(
					'source_item_id' => (int) $item_id,
					'action' => $action,
					'target_item_id' => $target_item_id,
					'target_before' => $target_before,
					'target_after' => $target_after,
				)
			);
		}
		if (empty($lines)) {
			throw new InvalidArgumentException(__('A Merge plan requires at least one source product line.', 'wc-order-splitter'));
		}

		$plan = array(
			'schema_version' => self::SCHEMA_VERSION,
			'commercial_policy' => WCOS_Merge_Commercial_Policy::authority($source, $target, $precision, $financial_authority),
			'financial_authority' => $financial_authority,
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'line_policy' => $financial_target ? 'fresh_target_line_only' : 'canonical_identity_coalesce_or_fresh',
			'coalesce_lines' => $coalesces,
			'tax_template_rate_ids' => $financial_target ? array() : WCOS_Merge_Commercial_Policy::product_tax_rate_ids($source),
			'tax_template_policy' => $financial_target ? 'preserve_target_rows_only' : 'import_source_product_rates',
			'source_order_stock_reduced' => $source_stock_flag,
			'target_order_stock_reduced' => $target_stock_flag,
			'target_order_stock_reduced_after' => $source_stock_flag || $target_stock_flag,
			'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'lines' => $lines,
		);
		return self::canonicalize_current($plan);
	}

	/** Legacy schema-v2 canonicalizer retained only for exact durable fixtures. */
	public static function canonicalize($source_order_id, $target_order_id, array $lines) {
		$source_order_id = absint($source_order_id);
		$target_order_id = absint($target_order_id);
		self::assert_pair($source_order_id, $target_order_id, $lines);
		return array(
			'schema_version' => self::LEGACY_SCHEMA_VERSION,
			'source_order_id' => $source_order_id,
			'target_order_id' => $target_order_id,
			'line_policy' => 'fresh_target_line_per_source_line',
			'coalesce_lines' => false,
			'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'lines' => self::canonical_lines($lines, false),
		);
	}

	public static function canonicalize_current(array $plan) {
		return self::canonicalize_commercial($plan, true);
	}

	/** Exact WOS-COMPAT-005 schema-v3 parser retained for durable replay/recovery. */
	public static function canonicalize_previous(array $plan) {
		return self::canonicalize_commercial($plan, false);
	}

	private static function canonicalize_commercial(array $plan, $current) {
		$expected = array(
			'coalesce_lines', 'commercial_policy', 'line_policy', 'lines', 'retirement_policy', 'schema_version',
			'source_order_id', 'source_order_stock_reduced', 'target_order_id', 'target_order_stock_reduced',
			'target_order_stock_reduced_after', 'tax_template_rate_ids',
		);
		if ($current) {
			$expected[] = 'financial_authority';
			$expected[] = 'tax_template_policy';
		}
		$actual = array_keys($plan);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		$source_id = absint(isset($plan['source_order_id']) ? $plan['source_order_id'] : 0);
		$target_id = absint(isset($plan['target_order_id']) ? $plan['target_order_id'] : 0);
		$lines = isset($plan['lines']) && is_array($plan['lines']) ? $plan['lines'] : array();
		self::assert_pair($source_id, $target_id, $lines);
		$schema_version = $current ? self::SCHEMA_VERSION : self::PREVIOUS_SCHEMA_VERSION;
		if ($actual !== $expected || $schema_version !== (int) $plan['schema_version']
			|| !in_array($plan['coalesce_lines'], array(true, false), true)
			|| !in_array($plan['source_order_stock_reduced'], array(true, false), true)
			|| !in_array($plan['target_order_stock_reduced'], array(true, false), true)
			|| !in_array($plan['target_order_stock_reduced_after'], array(true, false), true)
			|| (bool) ($plan['source_order_stock_reduced'] || $plan['target_order_stock_reduced']) !== (bool) $plan['target_order_stock_reduced_after']
			|| WCOS_Merge_Retirement_Policy::approved_identifier() !== sanitize_key((string) $plan['retirement_policy'])
			|| !is_array($plan['commercial_policy']) || !is_array($plan['tax_template_rate_ids'])) {
			throw new InvalidArgumentException(__('The ordinary-commercial Merge plan schema is invalid.', 'wc-order-splitter'));
		}
		$policy = $current
			? WCOS_Merge_Commercial_Policy::canonicalize_authority($plan['commercial_policy'])
			: WCOS_Merge_Commercial_Policy::canonicalize_previous_authority($plan['commercial_policy']);
		$financial_authority = null;
		$financial_target = false;
		$line_policy = 'canonical_identity_coalesce_or_fresh';
		$tax_template_policy = 'import_source_product_rates';
		if ($current) {
			$financial_authority = WCOS_Merge_Financial_Authority::canonicalize_pair($plan['financial_authority']);
			$financial_target = WCOS_Merge_Financial_Authority::target_has_history($financial_authority);
			$line_policy = $financial_target ? 'fresh_target_line_only' : 'canonical_identity_coalesce_or_fresh';
			$tax_template_policy = $financial_target ? 'preserve_target_rows_only' : 'import_source_product_rates';
			if ((bool) $policy['financial_target'] !== $financial_target
				|| !hash_equals((string) $policy['financial_policy_fingerprint'], (string) $financial_authority['pair_financial_policy_fingerprint'])
				|| $tax_template_policy !== sanitize_key((string) $plan['tax_template_policy'])) {
				throw new InvalidArgumentException(__('The Merge financial plan does not match its commercial authority.', 'wc-order-splitter'));
			}
		}
		if ($line_policy !== sanitize_key((string) $plan['line_policy'])) {
			throw new InvalidArgumentException(__('The Merge line disposition does not match its frozen financial policy.', 'wc-order-splitter'));
		}

		$canonical_lines = self::canonical_lines($lines, true);
		$has_coalesce = false;
		$target_progress = array();
		$precision = WCOS_Price_Precision_Scope::validate(isset($policy['price_precision']) ? $policy['price_precision'] : null);
		foreach ($canonical_lines as $line) {
			$has_coalesce = $has_coalesce || 'coalesce' === $line['action'];
			if ($financial_target) {
				self::assert_financial_target_line_neutral($line, $precision);
			}
			if ($financial_target && 'fresh_target_line' !== $line['action']) {
				throw new InvalidArgumentException(__('A financially historical target requires every Merge line to be fresh.', 'wc-order-splitter'));
			}
			if (!$plan['source_order_stock_reduced'] && self::has_reduced_stock($line['reduced_stock'])) {
				throw new InvalidArgumentException(__('The Merge source reduced-stock ownership authority is inconsistent.', 'wc-order-splitter'));
			}
			$source_state = self::source_state_from_line($line);
			$target_after = self::canonical_line_state($line['target_after']);
			if ('coalesce' === $line['action']) {
				$target_item_id = absint($line['target_item_id']);
				$target_before = self::canonical_line_state($line['target_before']);
				if (isset($target_progress[$target_item_id])) {
					if ($target_progress[$target_item_id] !== $target_before) {
						throw new InvalidArgumentException(__('Sequential Merge coalescing authority is discontinuous.', 'wc-order-splitter'));
					}
				} elseif (!$plan['target_order_stock_reduced'] && self::has_reduced_stock($target_before['reduced_stock'])) {
					throw new InvalidArgumentException(__('The Merge target reduced-stock ownership authority is inconsistent.', 'wc-order-splitter'));
				}
				$expected_after = self::add_line_states($target_before, $source_state, $precision);
				$target_progress[$target_item_id] = $target_after;
			} else {
				$expected_after = $source_state;
			}
			if ($expected_after !== $target_after) {
				throw new InvalidArgumentException(__('The Merge target-after line authority is not the exact frozen historical result.', 'wc-order-splitter'));
			}
			if (!$plan['target_order_stock_reduced_after'] && self::has_reduced_stock($line['target_after']['reduced_stock'])) {
				throw new InvalidArgumentException(__('The Merge resulting reduced-stock ownership authority is inconsistent.', 'wc-order-splitter'));
			}
		}
		if ($has_coalesce !== (bool) $plan['coalesce_lines']) {
			throw new InvalidArgumentException(__('The Merge coalescing summary does not match its line actions.', 'wc-order-splitter'));
		}
		$rate_ids = array_values(array_unique(array_map('intval', $plan['tax_template_rate_ids'])));
		sort($rate_ids, SORT_NUMERIC);
		if ($rate_ids !== array_values($plan['tax_template_rate_ids'])) {
			throw new InvalidArgumentException(__('The Merge tax-template authority is not canonical.', 'wc-order-splitter'));
		}
		if ($financial_target && !empty($rate_ids)) {
			throw new InvalidArgumentException(__('A financial-target Merge cannot materialize source tax rows.', 'wc-order-splitter'));
		}
		$canonical = array(
			'schema_version' => $schema_version,
			'commercial_policy' => $policy,
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'line_policy' => $line_policy,
			'coalesce_lines' => (bool) $plan['coalesce_lines'],
			'tax_template_rate_ids' => $rate_ids,
			'source_order_stock_reduced' => (bool) $plan['source_order_stock_reduced'],
			'target_order_stock_reduced' => (bool) $plan['target_order_stock_reduced'],
			'target_order_stock_reduced_after' => (bool) $plan['target_order_stock_reduced_after'],
			'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'lines' => $canonical_lines,
		);
		if ($current) {
			$canonical['financial_authority'] = $financial_authority;
			$canonical['tax_template_policy'] = $tax_template_policy;
		}
		return $canonical;
	}

	public static function fingerprint(array $plan) {
		$schema = (int) (isset($plan['schema_version']) ? $plan['schema_version'] : 0);
		if (self::LEGACY_SCHEMA_VERSION === $schema) {
			$canonical = self::canonicalize(
				isset($plan['source_order_id']) ? $plan['source_order_id'] : 0,
				isset($plan['target_order_id']) ? $plan['target_order_id'] : 0,
				isset($plan['lines']) && is_array($plan['lines']) ? $plan['lines'] : array()
			);
			return WCOS_Mutation_Fingerprint::create('merge_plan', $canonical['source_order_id'], $canonical);
		}
		if (self::PREVIOUS_SCHEMA_VERSION === $schema) {
			$canonical = self::canonicalize_previous($plan);
			return WCOS_Mutation_Fingerprint::create('merge_plan_v3', $canonical['source_order_id'], $canonical);
		}
		if (self::SCHEMA_VERSION === $schema) {
			$canonical = self::canonicalize_current($plan);
			return WCOS_Mutation_Fingerprint::create('merge_plan_v5', $canonical['source_order_id'], $canonical);
		}
		throw new InvalidArgumentException(__('The Merge plan schema is unsupported.', 'wc-order-splitter'));
	}

	public static function is_current(array $plan) {
		return self::SCHEMA_VERSION === (int) (isset($plan['schema_version']) ? $plan['schema_version'] : 0);
	}

	private static function line_state(WC_Order_Item_Product $item) {
		$state = WCOS_Merge_Canonical_Reader::line_state($item);
		$state['line_identity'] = $state['commercial_identity'];
		$state['commercial_identity'] = WCOS_Merge_Commercial_Policy::line_identity($item);
		return self::canonicalize_value($state);
	}

	private static function add_line_states(array $target, array $source, $precision) {
		if (!hash_equals($target['commercial_identity'], $source['commercial_identity'])) {
			throw new RuntimeException(__('Merge cannot coalesce commercially distinct product lines.', 'wc-order-splitter'));
		}
		$after = $target;
		$after['quantity'] = WCOS_Merge_Commercial_Policy::add_decimal($target['quantity'], $source['quantity'], 6);
		foreach (array('subtotal', 'subtotal_tax', 'total', 'total_tax') as $field) {
			$after[$field] = WCOS_Merge_Commercial_Policy::add_decimal($target[$field], $source[$field], $precision);
		}
		$after['taxes'] = self::add_taxes($target['taxes'], $source['taxes'], $precision);
		if (null === $target['reduced_stock'] && null === $source['reduced_stock']) {
			$after['reduced_stock'] = null;
		} else {
			$after['reduced_stock'] = WCOS_Merge_Commercial_Policy::add_decimal(
				null === $target['reduced_stock'] ? '0' : $target['reduced_stock'],
				null === $source['reduced_stock'] ? '0' : $source['reduced_stock'],
				6
			);
		}
		self::assert_reduced_stock($after['reduced_stock'], $after['quantity']);
		return $after;
	}

	private static function add_taxes(array $target, array $source, $precision) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			$rate_ids = array_values(array_unique(array_merge(
				array_keys(isset($target[$bucket]) ? (array) $target[$bucket] : array()),
				array_keys(isset($source[$bucket]) ? (array) $source[$bucket] : array())
			)));
			sort($rate_ids, SORT_NUMERIC);
			foreach ($rate_ids as $rate_id) {
				$result[$bucket][(int) $rate_id] = WCOS_Merge_Commercial_Policy::add_decimal(
					isset($target[$bucket][$rate_id]) ? $target[$bucket][$rate_id] : '0',
					isset($source[$bucket][$rate_id]) ? $source[$bucket][$rate_id] : '0',
					$precision
				);
			}
		}
		return $result;
	}

	private static function canonical_lines(array $lines, $current) {
		$canonical = array();
		foreach ($lines as $key => $line) {
			if (!is_array($line)) {
				throw new InvalidArgumentException(__('Every Merge plan line must be a canonical array.', 'wc-order-splitter'));
			}
			$item_id = absint(isset($line['source_item_id']) ? $line['source_item_id'] : $key);
			$identity = sanitize_key(isset($line['line_identity']) ? (string) $line['line_identity'] : '');
			if (!$item_id || 64 !== strlen($identity) || !ctype_xdigit($identity) || isset($canonical[$item_id])) {
				throw new InvalidArgumentException(__('Merge plan source lines require unique persisted item IDs and exact identities.', 'wc-order-splitter'));
			}
			$line['source_item_id'] = $item_id;
			$line['line_identity'] = strtolower($identity);
			if ($current) {
				self::assert_current_line($line);
			}
			$canonical[$item_id] = self::canonicalize_value($line);
		}
		ksort($canonical, SORT_NUMERIC);
		return $canonical;
	}

	private static function assert_current_line(array $line) {
		$required = array(
			'action', 'commercial_identity', 'line_identity', 'product_id', 'quantity', 'reduced_stock',
			'source_item_id', 'subtotal', 'subtotal_tax', 'target_after', 'target_before', 'target_item_id',
			'tax_class', 'taxes', 'total', 'total_tax', 'variation_id',
		);
		$actual = array_keys($line);
		sort($actual, SORT_STRING);
		sort($required, SORT_STRING);
		$action = sanitize_key(isset($line['action']) ? (string) $line['action'] : '');
		$commercial = sanitize_key(isset($line['commercial_identity']) ? (string) $line['commercial_identity'] : '');
		if ($actual !== $required || !in_array($action, array('coalesce', 'fresh_target_line'), true)
			|| 64 !== strlen($commercial) || !ctype_xdigit($commercial)
			|| !is_array($line['taxes']) || !is_array($line['target_before']) || !is_array($line['target_after'])
			|| ('coalesce' === $action && (!absint($line['target_item_id']) || empty($line['target_before'])))
			|| ('fresh_target_line' === $action && (0 !== (int) $line['target_item_id'] || !empty($line['target_before'])))) {
			throw new InvalidArgumentException(__('A Merge line action is malformed or ambiguous.', 'wc-order-splitter'));
		}
		self::assert_reduced_stock($line['reduced_stock'], $line['quantity']);
		self::assert_reduced_stock(isset($line['target_after']['reduced_stock']) ? $line['target_after']['reduced_stock'] : null, isset($line['target_after']['quantity']) ? $line['target_after']['quantity'] : 0);
	}

	private static function source_state_from_line(array $line) {
		$state = array();
		foreach (self::line_state_keys() as $key) {
			if (!array_key_exists($key, $line)) {
				throw new InvalidArgumentException(__('The Merge source line authority is incomplete.', 'wc-order-splitter'));
			}
			$state[$key] = $line[$key];
		}
		return self::canonical_line_state($state);
	}

	private static function canonical_line_state(array $state) {
		$expected = self::line_state_keys();
		$actual = array_keys($state);
		sort($expected, SORT_STRING);
		sort($actual, SORT_STRING);
		if ($actual !== $expected) {
			throw new InvalidArgumentException(__('The Merge line-state authority is malformed.', 'wc-order-splitter'));
		}
		$state = self::canonicalize_value($state);
		$identity = sanitize_key((string) $state['line_identity']);
		$commercial = sanitize_key((string) $state['commercial_identity']);
		if (64 !== strlen($identity) || !ctype_xdigit($identity)
			|| 64 !== strlen($commercial) || !ctype_xdigit($commercial)
			|| !is_array($state['taxes'])) {
			throw new InvalidArgumentException(__('The Merge line-state identity authority is malformed.', 'wc-order-splitter'));
		}
		self::assert_reduced_stock($state['reduced_stock'], $state['quantity']);
		return $state;
	}

	private static function line_state_keys() {
		return array(
			'commercial_identity', 'line_identity', 'product_id', 'quantity', 'reduced_stock',
			'subtotal', 'subtotal_tax', 'tax_class', 'taxes', 'total', 'total_tax', 'variation_id',
		);
	}

	private static function assert_pair($source_id, $target_id, array $lines) {
		if (!$source_id || !$target_id) {
			throw new InvalidArgumentException(__('Persisted source and target order IDs are required for a Merge plan.', 'wc-order-splitter'));
		}
		if ($source_id === $target_id) {
			throw new InvalidArgumentException(__('An order cannot be merged into itself.', 'wc-order-splitter'));
		}
		if (empty($lines)) {
			throw new InvalidArgumentException(__('A Merge plan requires at least one source product line.', 'wc-order-splitter'));
		}
	}

	private static function assert_reduced_stock($value, $quantity) {
		if (null === $value) {
			return;
		}
		if (!is_numeric($value) || WCOS_Decimal::to_units($value, 6) < 0
			|| WCOS_Decimal::to_units($value, 6) > WCOS_Decimal::to_units($quantity, 6)) {
			throw new RuntimeException(__('A Merge line contains an invalid reduced-stock ownership marker.', 'wc-order-splitter'));
		}
	}

	private static function assert_financial_target_line_neutral(array $line, $precision) {
		if (0 !== WCOS_Decimal::to_units(isset($line['total']) ? $line['total'] : null, $precision)
			|| !WCOS_Merge_Commercial_Policy::financial_target_line_tax_is_neutral(
				isset($line['total_tax']) ? $line['total_tax'] : null,
				isset($line['taxes']) && is_array($line['taxes']) ? $line['taxes'] : array(),
				$precision
			)) {
			throw new InvalidArgumentException(__('A financially historical target requires exact settlement-neutral source line authority.', 'wc-order-splitter'));
		}
	}

	private static function normalize_reduced_stock($value) {
		if ('' === $value || null === $value) {
			return null;
		}
		if (!is_numeric($value)) {
			throw new RuntimeException(__('A Merge line contains a non-numeric reduced-stock marker.', 'wc-order-splitter'));
		}
		return WCOS_Decimal::normalize($value, 6);
	}

	private static function has_reduced_stock($value) {
		return null !== $value && WCOS_Decimal::to_units($value, 6) > 0;
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
