<?php

defined('ABSPATH') || exit;

final class WCOS_Manual_Split_Quantity_Authority_Exception extends RuntimeException {
	private $reason;
	private $report;

	public function __construct($reason, $message, array $report = array()) {
		$this->reason = sanitize_key((string) $reason);
		$this->report = $report;
		parent::__construct((string) $message);
	}

	public function get_reason() {
		return $this->reason;
	}

	public function get_report() {
		return $this->report;
	}
}

/**
 * Frozen per-line quantity-step authority for Manual Quantity Split.
 *
 * This primitive is intentionally separate from the general Split preflight:
 * server-built whole-line strategies must not depend on editable-quantity
 * semantics. All quantity comparisons use six-decimal integer units.
 */
final class WCOS_Manual_Split_Quantity_Authority {
	const SCHEMA_VERSION = 1;
	const LEGACY_POLICY_VERSION = 1;
	const POLICY_VERSION = 2;
	const PRECISION = 6;

	public static function create(WC_Order $source) {
		return self::create_for_policy($source, self::POLICY_VERSION);
	}

	private static function create_for_policy(WC_Order $source, $policy_version) {
		$policy_version = (int) $policy_version;
		if (!in_array($policy_version, array(self::LEGACY_POLICY_VERSION, self::POLICY_VERSION), true)) {
			self::reject('authority_policy_unsupported', __('Manual Split quantity authority uses an unsupported policy version.', 'wc-order-splitter'));
		}
		$source_id = absint($source->get_id());
		if (!$source_id) {
			self::reject('source_unpersisted', __('A persisted source order is required to derive Manual Split quantity authority.', 'wc-order-splitter'));
		}

		$lines = array();
		foreach ($source->get_items('line_item') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				self::reject('line_type_invalid', __('Manual Split quantity authority only supports WooCommerce product lines.', 'wc-order-splitter'));
			}
			$item_id = absint($item_id);
			try {
				$source_units = WCOS_Decimal::to_units($item->get_quantity(), self::PRECISION);
			} catch (Throwable $throwable) {
				self::reject('source_quantity_invalid', __('A source line has an invalid quantity for Manual Split.', 'wc-order-splitter'));
			}
			if (!$item_id || $source_units <= 0) {
				self::reject('source_quantity_invalid', __('Every Manual Split source line must have a positive quantity.', 'wc-order-splitter'));
			}

			$product = $item->get_product();
			if (!$product instanceof WC_Product) {
				if (0 !== ($source_units % WCOS_Decimal::factor(self::PRECISION))) {
					self::reject(
						'deleted_product_fractional_step_unprovable',
						__('A deleted or unavailable product has a fractional historical quantity whose Manual Split step cannot be proven.', 'wc-order-splitter'),
						array('source_order_id' => $source_id, 'source_item_id' => $item_id)
					);
				}
				$step_units = WCOS_Decimal::factor(self::PRECISION);
			} else {
				$raw_step = method_exists($product, 'get_purchase_quantity_step')
					? $product->get_purchase_quantity_step()
					: 1;
				$raw_step = apply_filters('woocommerce_quantity_input_step_admin', $raw_step, $product, 'edit');
				$step_units = self::exact_step_units($raw_step);
				if (0 !== ($step_units % WCOS_Decimal::factor(self::PRECISION))
					&& !WCOS_Split_Preflight::fractional_quantities_supported()) {
					self::reject(
						'fractional_step_unsupported',
						__('A product exposes a fractional Manual Split step, but the active WooCommerce stock-amount integration does not preserve fractional quantities.', 'wc-order-splitter'),
						array('source_order_id' => $source_id, 'source_item_id' => $item_id)
					);
				}
			}

			if (0 !== ($source_units % $step_units)) {
				self::reject(
					'source_quantity_step_mismatch',
					__('A source line quantity is not aligned to its current WooCommerce Manual Split step.', 'wc-order-splitter'),
					array('source_order_id' => $source_id, 'source_item_id' => $item_id)
				);
			}

			$maximum_units = self::LEGACY_POLICY_VERSION === $policy_version
				? ($source_units > $step_units ? $source_units - $step_units : 0)
				: $source_units;
			$lines[$item_id] = array(
				'source_order_id' => $source_id,
				'source_item_id' => $item_id,
				'product_id' => absint($item->get_product_id()),
				'variation_id' => absint($item->get_variation_id()),
				'source_quantity' => WCOS_Decimal::from_units($source_units, self::PRECISION),
				'source_quantity_units' => $source_units,
				'quantity_step' => WCOS_Decimal::from_units($step_units, self::PRECISION),
				'step_units' => $step_units,
				'maximum_quantity' => WCOS_Decimal::from_units($maximum_units, self::PRECISION),
				'maximum_quantity_units' => $maximum_units,
				'can_partially_split' => $maximum_units >= $step_units,
			);
		}
		ksort($lines, SORT_NUMERIC);

		$authority = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => $policy_version,
			'precision' => self::PRECISION,
			'source_order_id' => $source_id,
			'source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
			'lines' => $lines,
		);
		$authority['authority_fingerprint'] = self::fingerprint($authority);
		return self::assert_valid($authority);
	}

	public static function assert_valid(array $authority) {
		$required = array('schema_version', 'policy_version', 'precision', 'source_order_id', 'source_signature', 'lines', 'authority_fingerprint');
		foreach ($required as $field) {
			if (!array_key_exists($field, $authority)) {
				self::reject('authority_incomplete', __('Manual Split quantity authority is incomplete.', 'wc-order-splitter'));
			}
		}
		$policy_version = (int) $authority['policy_version'];
		if (self::SCHEMA_VERSION !== (int) $authority['schema_version']
			|| !in_array($policy_version, array(self::LEGACY_POLICY_VERSION, self::POLICY_VERSION), true)
			|| self::PRECISION !== (int) $authority['precision']
			|| absint($authority['source_order_id']) <= 0
			|| !self::is_fingerprint($authority['source_signature'])
			|| !self::is_fingerprint($authority['authority_fingerprint'])
			|| !is_array($authority['lines'])
			|| empty($authority['lines'])) {
			self::reject('authority_malformed', __('Manual Split quantity authority is malformed.', 'wc-order-splitter'));
		}

		$canonical_lines = array();
		foreach ($authority['lines'] as $raw_item_id => $line) {
			$item_id = absint($raw_item_id);
			if (!$item_id || !is_array($line) || isset($canonical_lines[$item_id])) {
				self::reject('line_authority_malformed', __('A Manual Split line authority is malformed.', 'wc-order-splitter'));
			}
			$line_required = array(
				'source_order_id', 'source_item_id', 'product_id', 'variation_id', 'source_quantity',
				'source_quantity_units', 'quantity_step', 'step_units', 'maximum_quantity',
				'maximum_quantity_units', 'can_partially_split',
			);
			foreach ($line_required as $field) {
				if (!array_key_exists($field, $line)) {
					self::reject('line_authority_incomplete', __('A Manual Split line authority is incomplete.', 'wc-order-splitter'));
				}
			}
			$source_units = self::positive_integer($line['source_quantity_units']);
			$step_units = self::positive_integer($line['step_units']);
			$maximum_units = self::nonnegative_integer($line['maximum_quantity_units']);
			$expected_maximum = self::LEGACY_POLICY_VERSION === $policy_version
				? ($source_units > $step_units ? $source_units - $step_units : 0)
				: $source_units;
			if (absint($line['source_order_id']) !== absint($authority['source_order_id'])
				|| absint($line['source_item_id']) !== $item_id
				|| (string) $line['source_quantity'] !== WCOS_Decimal::from_units($source_units, self::PRECISION)
				|| (string) $line['quantity_step'] !== WCOS_Decimal::from_units($step_units, self::PRECISION)
				|| (string) $line['maximum_quantity'] !== WCOS_Decimal::from_units($maximum_units, self::PRECISION)
				|| 0 !== ($source_units % $step_units)
				|| $maximum_units !== $expected_maximum
				|| (bool) $line['can_partially_split'] !== ($maximum_units >= $step_units)) {
				self::reject('line_authority_inconsistent', __('A Manual Split line authority is internally inconsistent.', 'wc-order-splitter'));
			}
			$canonical_lines[$item_id] = array(
				'source_order_id' => absint($line['source_order_id']),
				'source_item_id' => $item_id,
				'product_id' => absint($line['product_id']),
				'variation_id' => absint($line['variation_id']),
				'source_quantity' => (string) $line['source_quantity'],
				'source_quantity_units' => $source_units,
				'quantity_step' => (string) $line['quantity_step'],
				'step_units' => $step_units,
				'maximum_quantity' => (string) $line['maximum_quantity'],
				'maximum_quantity_units' => $maximum_units,
				'can_partially_split' => (bool) $line['can_partially_split'],
			);
		}
		ksort($canonical_lines, SORT_NUMERIC);

		$canonical = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => $policy_version,
			'precision' => self::PRECISION,
			'source_order_id' => absint($authority['source_order_id']),
			'source_signature' => strtolower((string) $authority['source_signature']),
			'lines' => $canonical_lines,
			'authority_fingerprint' => strtolower((string) $authority['authority_fingerprint']),
		);
		if (!hash_equals($canonical['authority_fingerprint'], self::fingerprint($canonical))) {
			self::reject('authority_fingerprint_mismatch', __('Manual Split quantity authority failed its integrity fingerprint.', 'wc-order-splitter'));
		}
		return $canonical;
	}

	public static function assert_current(WC_Order $source, array $frozen) {
		$frozen = self::assert_valid($frozen);
		$current = self::create_for_policy($source, $frozen['policy_version']);
		if (!hash_equals($frozen['authority_fingerprint'], $current['authority_fingerprint'])) {
			self::reject('authority_changed', __('The WooCommerce quantity-step authority changed after Review. Review the Manual Split plan again.', 'wc-order-splitter'));
		}
		return $frozen;
	}

	public static function assert_plan(array $plan, array $authority) {
		$authority = self::assert_valid($authority);
		$whole_line_policy = self::POLICY_VERSION === (int) $authority['policy_version'];
		$canonical = WCOS_Split_Plan::canonicalize_request($plan);
		$totals = array();
		foreach ($canonical as $items) {
			foreach ($items as $item_id => $quantity) {
				if (!isset($authority['lines'][$item_id])) {
					self::reject('plan_item_outside_authority', __('The Manual Split plan references an item outside the reviewed quantity authority.', 'wc-order-splitter'));
				}
				$quantity_units = WCOS_Decimal::to_units($quantity, self::PRECISION);
				$step_units = (int) $authority['lines'][$item_id]['step_units'];
				if ($quantity_units <= 0 || 0 !== ($quantity_units % $step_units)) {
					self::reject('plan_quantity_step_mismatch', __('A Manual Split quantity is not an exact multiple of the reviewed line step.', 'wc-order-splitter'));
				}
				$current = isset($totals[$item_id]) ? $totals[$item_id] : 0;
				if ($quantity_units > PHP_INT_MAX - $current) {
					self::reject('plan_quantity_overflow', __('The Manual Split quantity total exceeds the supported numeric range.', 'wc-order-splitter'));
				}
				$totals[$item_id] = $current + $quantity_units;
			}
		}

		$source_has_residual = false;
		foreach ($authority['lines'] as $item_id => $line) {
			$moved_units = isset($totals[$item_id]) ? $totals[$item_id] : 0;
			$residual_units = (int) $line['source_quantity_units'] - $moved_units;
			if ($moved_units > (int) $line['maximum_quantity_units']
				|| $residual_units < 0
				|| (!$whole_line_policy && $moved_units > 0 && $residual_units <= 0)
				|| 0 !== ($residual_units % (int) $line['step_units'])) {
				self::reject('plan_residual_invalid', __('The Manual Split plan exceeds reviewed quantity authority or leaves an invalid source residual.', 'wc-order-splitter'));
			}
			if ($residual_units > 0) {
				$source_has_residual = true;
			}
		}
		if ($whole_line_policy && !$source_has_residual) {
			self::reject('plan_source_order_empty', __('The Manual Split plan must leave positive product quantity on the source order.', 'wc-order-splitter'));
		}
		return $canonical;
	}

	public static function execution_policy(array $authority) {
		$authority = self::assert_valid($authority);
		return self::POLICY_VERSION === (int) $authority['policy_version']
			? WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
			: WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY;
	}

	public static function is_order_splittable(array $authority) {
		$authority = self::assert_valid($authority);
		if (self::LEGACY_POLICY_VERSION === (int) $authority['policy_version']) {
			foreach ($authority['lines'] as $line) {
				if (!empty($line['can_partially_split'])) {
					return true;
				}
			}
			return false;
		}

		$step_count = 0;
		foreach ($authority['lines'] as $line) {
			$line_steps = intdiv((int) $line['source_quantity_units'], (int) $line['step_units']);
			if ($line_steps > PHP_INT_MAX - $step_count) {
				self::reject('authority_step_count_overflow', __('Manual Split quantity authority exceeds the supported step-count range.', 'wc-order-splitter'));
			}
			$step_count += $line_steps;
			if ($step_count >= 2) {
				return true;
			}
		}
		return false;
	}

	public static function display_decimal($value) {
		$value = WCOS_Decimal::normalize($value, self::PRECISION);
		$value = rtrim(rtrim($value, '0'), '.');
		return '' === $value ? '0' : $value;
	}

	private static function exact_step_units($value) {
		if (is_int($value)) {
			$raw = (string) $value;
		} elseif (is_float($value)) {
			if (is_nan($value) || is_infinite($value)) {
				self::reject('quantity_step_invalid', __('The WooCommerce quantity step must be finite and positive.', 'wc-order-splitter'));
			}
			$raw = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
		} elseif (is_string($value) || is_numeric($value)) {
			$raw = trim((string) $value);
		} else {
			self::reject('quantity_step_invalid', __('The WooCommerce quantity step must be numeric.', 'wc-order-splitter'));
		}
		if (!preg_match('/^\+?(?:0|[1-9][0-9]*)(?:\.([0-9]*))?$/D', $raw, $matches)) {
			self::reject('quantity_step_invalid', __('The WooCommerce quantity step is not a representable positive decimal.', 'wc-order-splitter'));
		}
		$fraction = isset($matches[1]) ? $matches[1] : '';
		if (strlen($fraction) > self::PRECISION && '' !== trim(substr($fraction, self::PRECISION), '0')) {
			self::reject('quantity_step_precision_invalid', __('The WooCommerce quantity step exceeds the supported six-decimal precision.', 'wc-order-splitter'));
		}
		try {
			$units = WCOS_Decimal::to_units($raw, self::PRECISION);
		} catch (Throwable $throwable) {
			self::reject('quantity_step_invalid', __('The WooCommerce quantity step exceeds the supported numeric range.', 'wc-order-splitter'));
		}
		if ($units <= 0) {
			self::reject('quantity_step_invalid', __('The WooCommerce quantity step must be positive.', 'wc-order-splitter'));
		}
		return $units;
	}

	private static function fingerprint(array $authority) {
		unset($authority['authority_fingerprint']);
		return WCOS_Mutation_Fingerprint::create(
			'manual_split_quantity_authority_v1',
			absint(isset($authority['source_order_id']) ? $authority['source_order_id'] : 0),
			$authority
		);
	}

	private static function positive_integer($value) {
		if (!is_int($value) || $value <= 0) {
			self::reject('authority_units_invalid', __('Manual Split quantity authority contains invalid positive units.', 'wc-order-splitter'));
		}
		return $value;
	}

	private static function nonnegative_integer($value) {
		if (!is_int($value) || $value < 0) {
			self::reject('authority_units_invalid', __('Manual Split quantity authority contains invalid non-negative units.', 'wc-order-splitter'));
		}
		return $value;
	}

	private static function is_fingerprint($value) {
		return is_string($value) && 1 === preg_match('/^[a-f0-9]{64}$/D', strtolower($value));
	}

	private static function reject($reason, $message, array $report = array()) {
		throw new WCOS_Manual_Split_Quantity_Authority_Exception($reason, $message, $report);
	}
}
