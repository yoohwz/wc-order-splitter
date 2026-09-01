<?php

defined('ABSPATH') || exit;

final class WCOS_Merge_Preflight_Exception extends RuntimeException {
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
 * Read-only pair compatibility contract for the first technical Merge tranche.
 * Reports and failure evidence are intentionally PII-free.
 */
final class WCOS_Merge_Preflight {

	const POLICY_VERSION = 4;
	const PREVIOUS_POLICY_VERSION = 2;
	const LEGACY_POLICY_VERSION = 1;

	public static function policy() {
		return array(
			'policy_version' => self::POLICY_VERSION,
			'commercial_scope' => 'ordinary_or_settlement_neutral_financial_target',
			'financial_source' => 'reject',
			'financial_target' => 'settlement_neutral_fresh_lines_only',
			'payment_refund_authority' => 'preserve_exact',
			'payment_refund_api' => 'never',
			'coupons' => 'retain_on_existing_order',
			'fees' => 'retain_on_existing_order',
			'source_shipping' => 'retain_on_retired_source',
			'target_charges_shipping' => 'preserve_target',
			'order_context' => 'keep_target_context',
			'target_status' => 'keep_target',
			'line_coalescing' => 'exact_canonical_identity_only',
			'historical_tax' => 'preserve_exact',
			'catalog_recalculation' => 'never',
			'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
		);
	}

	public static function assert_supported(WC_Order $source, WC_Order $target, $precision = null) {
		$report = self::report($source, $target, $precision);
		if (empty($report['supported'])) {
			throw new WCOS_Merge_Preflight_Exception(
				isset($report['reason']) ? $report['reason'] : 'unsupported',
				isset($report['message']) ? $report['message'] : __('This order pair is not supported by the hardened Merge foundation.', 'wc-order-splitter'),
				$report
			);
		}
		return $report;
	}

	public static function report(WC_Order $source, WC_Order $target, $precision = null) {
		$precision = null === $precision
			? WCOS_Price_Precision_Scope::store_precision()
			: WCOS_Price_Precision_Scope::validate($precision);
		$report = array(
			'supported' => false,
			'reason' => '',
			'message' => '',
			'source_order_id' => absint($source->get_id()),
			'target_order_id' => absint($target->get_id()),
			'price_precision' => $precision,
			'policy' => self::policy(),
			'retirement_candidates' => WCOS_Merge_Retirement_Policy::identifiers(),
			'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'context_authority' => array(),
			'source_signature' => '',
			'target_signature' => '',
			'pair_fingerprint' => '',
			'financial_authority' => array(),
			'financial_policy_fingerprint' => '',
			'target_has_financial_history' => false,
			'plan' => array(),
		);

		$source_id = $report['source_order_id'];
		$target_id = $report['target_order_id'];
		if (!$source_id || !$target_id || 'shop_order' !== $source->get_type() || 'shop_order' !== $target->get_type()) {
			return self::reject($report, 'unsupported_order_type', __('Merge requires two persisted WooCommerce shop orders.', 'wc-order-splitter'));
		}
		if ($source_id === $target_id) {
			return self::reject($report, 'same_order', __('An order cannot be merged into itself.', 'wc-order-splitter'));
		}

		$blocked = array_values(array_unique(array_merge(
			WCOS_Manual_Reconciliation_Blocker::active_operation_ids($source),
			WCOS_Manual_Reconciliation_Blocker::active_operation_ids($target)
		)));
		if (!empty($blocked)) {
			return self::reject($report, 'manual_reconciliation_required', __('One or both Merge participants have unresolved mutation authority.', 'wc-order-splitter'));
		}

		$source_status = WCOS_Merge_Canonical_Reader::status($source);
		$target_status = WCOS_Merge_Canonical_Reader::status($target);
		$unsupported_statuses = array('trash', 'cancelled', 'refunded', 'failed', 'checkout-draft');
		if (in_array($source_status, $unsupported_statuses, true)
			|| in_array($target_status, $unsupported_statuses, true)) {
			return self::reject($report, 'incompatible_status', __('Merge requires two independently safe, non-terminal order statuses.', 'wc-order-splitter'));
		}

		$source_currency = WCOS_Merge_Canonical_Reader::currency($source);
		$target_currency = WCOS_Merge_Canonical_Reader::currency($target);
		if ('' === $source_currency || $source_currency !== $target_currency) {
			return self::reject($report, 'incompatible_currency', __('Merge requires the same non-empty currency on both orders.', 'wc-order-splitter'));
		}
		if (WCOS_Merge_Canonical_Reader::prices_include_tax($source) !== WCOS_Merge_Canonical_Reader::prices_include_tax($target)) {
			return self::reject($report, 'incompatible_pricing_mode', __('Merge requires matching historical tax-inclusion modes.', 'wc-order-splitter'));
		}

		if (WCOS_Merge_Financial_Authority::has_history($source, $precision)) {
			return self::reject($report, 'source_financial_history_not_movable', __('The Merge source owns payment or refund history and cannot be retired by this workflow.', 'wc-order-splitter'));
		}
		if (empty(WCOS_Merge_Canonical_Reader::items($source, 'line_item'))) {
			return self::reject($report, 'no_source_lines', __('Merge requires at least one source product line.', 'wc-order-splitter'));
		}

		try {
			$financial_authority = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, $precision);
		} catch (WCOS_Merge_Financial_Authority_Exception $exception) {
			return self::reject($report, $exception->get_reason(), $exception->getMessage());
		} catch (Throwable $throwable) {
			return self::reject($report, 'malformed_refund_authority', __('The target refund structure is malformed or cannot be authenticated unambiguously.', 'wc-order-splitter'));
		}
		$financial_target = WCOS_Merge_Financial_Authority::target_has_history($financial_authority);
		if ($financial_target) {
			foreach (WCOS_Merge_Canonical_Reader::items($source, 'line_item') as $source_line) {
				if (0 !== WCOS_Decimal::to_units($source_line->get_total('edit'), $precision)) {
					return self::reject($report, 'financial_target_nonzero_source_total', __('A financially historical target can accept only source product lines with exact zero total.', 'wc-order-splitter'));
				}
				if (!WCOS_Merge_Commercial_Policy::financial_target_line_tax_is_neutral(
					$source_line->get_total_tax('edit'),
					(array) $source_line->get_taxes('edit'),
					$precision
				)) {
					return self::reject($report, 'financial_target_nonzero_source_tax', __('A financially historical target can accept only source product lines with exact zero total tax.', 'wc-order-splitter'));
				}
			}
		}

		try {
			$context_authority = WCOS_Merge_Context_Signature::disposition($source, $target);
			self::assert_item_metadata_supported($source);
			self::assert_item_metadata_supported($target);
			WCOS_Order_Totals_Rebuilder::assert_consistent($source, $precision, true);
			WCOS_Order_Totals_Rebuilder::assert_consistent($target, $precision, true);
			$plan = WCOS_Merge_Plan::build($source, $target, $precision);
			WCOS_Tax_Item_Synchronizer::templates_for_rates($source, $plan['tax_template_rate_ids'], true);
			$journal_context = WCOS_Merge_Journal_Context::create($source, $target, $plan, $context_authority, $precision);
		} catch (WCOS_Merge_Financial_Authority_Exception $exception) {
			return self::reject($report, $exception->get_reason(), $exception->getMessage());
		} catch (Throwable $throwable) {
			return self::reject(
				$report,
				'incompatible_pair_context',
				__('The order pair failed a hardened Merge compatibility check.', 'wc-order-splitter')
			);
		}

		$pair = $journal_context['merge_pair']['authority'];
		$report['supported'] = true;
		$report['reason'] = 'supported';
		$report['message'] = $financial_target
			? __('This pair is eligible for a settlement-neutral Merge that preserves target payment and refund history.', 'wc-order-splitter')
			: __('This ordinary unpaid pair is compatible with the hardened Merge commercial policy.', 'wc-order-splitter');
		$report['context_authority'] = $context_authority;
		$report['source_signature'] = $pair['source_signature'];
		$report['target_signature'] = $pair['target_signature'];
		$report['pair_fingerprint'] = $journal_context['merge_pair']['pair_fingerprint'];
		$report['financial_authority'] = $financial_authority;
		$report['financial_policy_fingerprint'] = $financial_authority['pair_financial_policy_fingerprint'];
		$report['target_has_financial_history'] = $financial_target;
		$report['plan'] = $plan;
		return $report;
	}

	private static function assert_item_metadata_supported(WC_Order $order) {
		$unknown = array();
		$inconsistent = array();
		foreach (array('line_item', 'shipping', 'fee', 'tax', 'coupon') as $item_type) {
			foreach (WCOS_Merge_Canonical_Reader::items($order, $item_type) as $item) {
				WCOS_Order_Item_Meta_Policy::business_metadata($item);
				$unknown = array_merge(
					$unknown,
					WCOS_Order_Item_Meta_Policy::unknown_private_keys($item, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE)
				);
				$inconsistent = array_merge(
					$inconsistent,
					WCOS_Order_Item_Meta_Policy::inconsistent_private_keys($item, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE)
				);
			}
		}
		if (!empty($inconsistent)) {
			throw new RuntimeException(__('Private item metadata classification is inconsistent between Merge copying and line identity.', 'wc-order-splitter'));
		}
		if (!empty($unknown)) {
			throw new RuntimeException(__('Private order-item metadata is not classified for Merge compatibility.', 'wc-order-splitter'));
		}
	}

	private static function reject(array $report, $reason, $message) {
		$report['supported'] = false;
		$report['reason'] = sanitize_key((string) $reason);
		$report['message'] = (string) $message;
		return $report;
	}
}
