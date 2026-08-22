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

	const POLICY_VERSION = 1;

	public static function policy() {
		return array(
			'policy_version' => self::POLICY_VERSION,
			'commercial_scope' => 'initial_safety_tranche_only',
			'paid_orders' => 'reject',
			'refunds' => 'reject',
			'coupons' => 'reject',
			'fees' => 'reject',
			'source_shipping' => 'reject',
			'line_coalescing' => 'never',
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

		$source_status = sanitize_key((string) $source->get_status());
		$target_status = sanitize_key((string) $target->get_status());
		$unsupported_statuses = array('trash', 'cancelled', 'refunded', 'failed', 'checkout-draft');
		if ($source_status !== $target_status
			|| in_array($source_status, $unsupported_statuses, true)
			|| in_array($target_status, $unsupported_statuses, true)) {
			return self::reject($report, 'incompatible_status', __('The initial Merge tranche requires matching compatible order statuses.', 'wc-order-splitter'));
		}

		$source_currency = (string) $source->get_currency();
		$target_currency = (string) $target->get_currency();
		if ('' === $source_currency || $source_currency !== $target_currency) {
			return self::reject($report, 'incompatible_currency', __('Merge requires the same non-empty currency on both orders.', 'wc-order-splitter'));
		}
		if ((bool) $source->get_prices_include_tax() !== (bool) $target->get_prices_include_tax()) {
			return self::reject($report, 'incompatible_pricing_mode', __('Merge requires matching historical tax-inclusion modes.', 'wc-order-splitter'));
		}

		if (self::has_paid_evidence($source) || self::has_paid_evidence($target)) {
			return self::reject($report, 'paid_order_unsupported', __('Paid or transaction-bearing orders are outside the initial Merge tranche.', 'wc-order-splitter'));
		}
		if (self::has_refund_evidence($source) || self::has_refund_evidence($target)) {
			return self::reject($report, 'refund_policy_missing', __('Refunded orders are outside the initial Merge tranche.', 'wc-order-splitter'));
		}
		if (!empty($source->get_items('coupon')) || !empty($target->get_items('coupon'))) {
			return self::reject($report, 'coupon_policy_missing', __('Coupon ownership is outside the initial Merge tranche.', 'wc-order-splitter'));
		}
		if (!empty($source->get_items('fee')) || !empty($target->get_items('fee'))) {
			return self::reject($report, 'fee_policy_missing', __('Fee ownership is outside the initial Merge tranche.', 'wc-order-splitter'));
		}
		if (!empty($source->get_items('shipping'))
			|| 0 !== WCOS_Decimal::to_units($source->get_shipping_total(), $precision)
			|| 0 !== WCOS_Decimal::to_units($source->get_shipping_tax(), $precision)) {
			return self::reject($report, 'source_shipping_policy_missing', __('Source shipping ownership is outside the initial Merge tranche.', 'wc-order-splitter'));
		}
		if (empty($source->get_items('line_item'))) {
			return self::reject($report, 'no_source_lines', __('Merge requires at least one source product line.', 'wc-order-splitter'));
		}

		try {
			$context_authority = WCOS_Merge_Context_Signature::compatibility($source, $target);
			self::assert_item_metadata_supported($source);
			self::assert_item_metadata_supported($target);
			WCOS_Order_Totals_Rebuilder::assert_consistent($source, $precision);
			WCOS_Order_Totals_Rebuilder::assert_consistent($target, $precision);
			$plan = WCOS_Merge_Plan::build($source, $target);
			$journal_context = WCOS_Merge_Journal_Context::create($source, $target, $plan, $context_authority, $precision);
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
		$report['message'] = __('This pair is compatible with the initial hard-off Merge safety tranche.', 'wc-order-splitter');
		$report['context_authority'] = $context_authority;
		$report['source_signature'] = $pair['source_signature'];
		$report['target_signature'] = $pair['target_signature'];
		$report['pair_fingerprint'] = $journal_context['merge_pair']['pair_fingerprint'];
		$report['plan'] = $plan;
		return $report;
	}

	private static function has_paid_evidence(WC_Order $order) {
		return $order->is_paid()
			|| null !== $order->get_date_paid()
			|| '' !== (string) $order->get_transaction_id();
	}

	private static function has_refund_evidence(WC_Order $order) {
		return $order->get_total_refunded() != 0 || !empty($order->get_refunds());
	}

	private static function assert_item_metadata_supported(WC_Order $order) {
		$unknown = array();
		$inconsistent = array();
		foreach (array('line_item', 'shipping', 'fee', 'tax', 'coupon') as $item_type) {
			foreach ($order->get_items($item_type) as $item) {
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
