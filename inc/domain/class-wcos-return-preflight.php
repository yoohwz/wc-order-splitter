<?php

defined('ABSPATH') || exit;

/** Read-only, PII-free eligibility boundary for future hardened Return. */
final class WCOS_Return_Preflight {

	const POLICY_VERSION = 1;

	public static function policy() {
		return array(
			'policy_version' => self::POLICY_VERSION,
			'lineage' => 'direct_hardened_split_child_only',
			'scope' => 'all_child_product_lines',
			'legacy_lineage' => 'diagnostic_only',
			'child_shipping' => 'reject',
			'child_fees' => 'reject',
			'child_coupons' => 'reject',
			'refunds' => 'reject',
			'child_payment_transaction' => 'reject',
			'child_paid_date' => 'reject',
			'inherited_paid_status' => 'allow_only_with_authenticated_source_only_split_authority',
			'physical_stock' => 'read_only_no_write',
			'catalog_authority' => 'never',
			'nested_child' => 'reject',
		);
	}

	public static function assert_supported(WC_Order $child, $authorize = true) {
		$report = self::report($child, $authorize);
		if (empty($report['supported'])) {
			throw new WCOS_Return_Lineage_Exception(
				isset($report['reason']) ? $report['reason'] : 'unsupported',
				isset($report['message']) ? $report['message'] : __('This Split child is not eligible for hardened Return.', 'wc-order-splitter')
			);
		}
		return $report;
	}

	public static function report(WC_Order $child, $authorize = true) {
		$report = array(
			'supported' => false,
			'reason' => '',
			'message' => '',
			'child_order_id' => absint($child->get_id()),
			'source_order_id' => 0,
			'policy' => self::policy(),
			'lineage_authority' => array(),
			'return_plan' => array(),
		);

		try {
			$authority = WCOS_Return_Lineage_Authority::resolve($child);
		} catch (WCOS_Return_Lineage_Exception $exception) {
			return self::reject($report, $exception->get_reason(), $exception->getMessage());
		} catch (Throwable $throwable) {
			return self::reject($report, 'lineage_verification_failed', __('Return lineage could not be verified safely.', 'wc-order-splitter'));
		}

		$source = wc_get_order($authority['source_order_id']);
		if (!$source instanceof WC_Order) {
			return self::reject($report, 'source_missing', __('The Return original order is unavailable.', 'wc-order-splitter'));
		}
		$report['source_order_id'] = $source->get_id();

		if ($authorize) {
			try {
				WCOS_Order_Mutation_Authorizer::assert_return($child, $source);
			} catch (Throwable $throwable) {
				return self::reject($report, 'authorization_failed', __('The current operator is not authorized to Return this order pair.', 'wc-order-splitter'));
			}
		}

		try {
			WCOS_Return_Participation::assert_not_terminal($child);
		} catch (Throwable $throwable) {
			return self::reject($report, 'already_returned', __('This Split child already carries terminal Return participation.', 'wc-order-splitter'));
		}

		if (self::has_descendants($child)) {
			return self::reject($report, 'nested_or_parent_child', __('A Split child with descendant orders is outside the initial Return policy.', 'wc-order-splitter'));
		}
		if (self::has_unresolved_authority($child) || self::has_unresolved_authority($source)) {
			return self::reject($report, 'unresolved_mutation_authority', __('A Return participant has unresolved mutation or reconciliation authority.', 'wc-order-splitter'));
		}

		$unsupported_statuses = array('trash', 'cancelled', 'refunded', 'failed', 'checkout-draft');
		if (in_array(sanitize_key((string) $child->get_status()), $unsupported_statuses, true)) {
			return self::reject($report, 'unsupported_child_status', __('The Split child status is outside the initial Return policy.', 'wc-order-splitter'));
		}
		$has_paid_date = null !== $child->get_date_paid();
		$has_transaction = '' !== (string) $child->get_transaction_id();
		$paid_status_is_source_only = WCOS_Return_Lineage_Authority::proves_source_only_payment($authority);
		if ($has_paid_date || $has_transaction || ($child->is_paid() && !$paid_status_is_source_only)) {
			return self::reject($report, 'child_payment_ownership', __('A paid or transaction-bearing Split child cannot be returned in the initial policy.', 'wc-order-splitter'));
		}
		if (self::has_refunds($child) || self::has_refunds($source)) {
			return self::reject($report, 'refund_ownership_unsupported', __('Refund-bearing Return participants are outside the initial policy.', 'wc-order-splitter'));
		}
		if (!empty($child->get_items('shipping')) || !empty($child->get_items('fee')) || !empty($child->get_items('coupon'))
			|| 0 !== WCOS_Decimal::to_units($child->get_shipping_total(), $authority['price_precision'])) {
			return self::reject($report, 'child_charge_ownership', __('The Split child owns charges that the initial Return policy cannot transfer.', 'wc-order-splitter'));
		}
		if (self::has_inconsistent_stock_state($child)) {
			return self::reject($report, 'child_stock_state_inconsistent', __('The Split child has inconsistent reduced-stock ownership evidence.', 'wc-order-splitter'));
		}

		try {
			$plan = WCOS_Return_Plan::build($authority);
		} catch (Throwable $throwable) {
			return self::reject($report, 'return_plan_invalid', __('The exact all-or-nothing Return plan could not be built.', 'wc-order-splitter'));
		}

		$report['supported'] = true;
		$report['reason'] = 'supported';
		$report['message'] = __('This direct hardened Split child has exact read-only Return lineage and plan authority.', 'wc-order-splitter');
		$report['lineage_authority'] = $authority;
		$report['return_plan'] = $plan;
		return $report;
	}

	private static function has_descendants(WC_Order $child) {
		$structured = $child->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true);
		if (is_array($structured) && !empty(array_filter(array_map('absint', $structured)))) {
			return true;
		}
		$legacy = (string) $child->get_meta('yoos_splitted_order', true);
		return '' !== trim($legacy, " ,\t\n\r\0\x0B");
	}

	private static function has_unresolved_authority(WC_Order $order) {
		if (WCOS_Manual_Reconciliation_Blocker::has_active($order)) {
			return true;
		}
		if (class_exists('WCOS_Merge_Participation') && !empty(WCOS_Merge_Participation::unresolved_operation_ids($order))) {
			return true;
		}
		if (class_exists('WCOS_Return_Participation') && !empty(WCOS_Return_Participation::unresolved_operation_ids($order))) {
			return true;
		}
		$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
		foreach (is_array($summary) ? $summary : array() as $entry) {
			if ('bulk_return_batch' === sanitize_key(isset($entry['type']) ? (string) $entry['type'] : '')) {
				/* A batch coordinator is optimistic coordination, never a reservation. */
				continue;
			}
			$status = sanitize_key(isset($entry['status']) ? (string) $entry['status'] : '');
			if (!in_array($status, array('completed', 'compensated', 'manual_reconciled'), true)) {
				return true;
			}
		}
		return false;
	}

	private static function has_refunds(WC_Order $order) {
		return $order->get_total_refunded() != 0 || !empty($order->get_refunds());
	}

	private static function has_inconsistent_stock_state(WC_Order $child) {
		$order_reduced = (bool) $child->get_data_store()->get_stock_reduced($child->get_id());
		foreach ($child->get_items('line_item') as $item) {
			$value = $item->get_meta('_reduced_stock', true);
			if ('' === $value || null === $value) {
				continue;
			}
			try {
				$reduced = WCOS_Decimal::to_units($value, 6);
				$quantity = WCOS_Decimal::to_units($item->get_quantity(), 6);
			} catch (Throwable $throwable) {
				return true;
			}
			if (!$order_reduced || $reduced < 0 || $reduced > $quantity) {
				return true;
			}
		}
		return false;
	}

	private static function reject(array $report, $reason, $message) {
		$report['supported'] = false;
		$report['reason'] = sanitize_key((string) $reason);
		$report['message'] = (string) $message;
		return $report;
	}
}
