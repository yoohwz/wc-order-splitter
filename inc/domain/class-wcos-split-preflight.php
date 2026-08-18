<?php

defined('ABSPATH') || exit;

final class WCOS_Split_Preflight_Exception extends RuntimeException {
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
 * Read-only production preflight for the first P2 quantity-split surface.
 *
 * Reports intentionally contain no customer/address/payment plaintext. They
 * expose only compatibility facts needed by a future confirmation UI.
 */
final class WCOS_Split_Preflight {
	const POLICY_VERSION = 2;

	public static function policy() {
		return array(
			'policy_version' => self::POLICY_VERSION,
			'shipping' => 'keep_on_source',
			'fees' => 'keep_on_source',
			'negative_fees' => 'reject',
			'coupons' => 'reject',
			'refunds' => 'reject',
			'payment' => 'source_only',
			'payment_transaction' => 'keep_on_source',
			'child_status' => 'pending',
			'tax' => 'preserve_historical',
			'physical_stock' => 'no_write',
			'nested_split' => 'reject',
		);
	}

	public static function assert_supported(WC_Order $source) {
		$report = self::report($source);
		if (empty($report['supported'])) {
			throw new WCOS_Split_Preflight_Exception(
				isset($report['reason']) ? $report['reason'] : 'unsupported',
				isset($report['message']) ? $report['message'] : __('This order is not supported by the quantity-split adapter.', 'wc-order-splitter'),
				$report
			);
		}
		return $report;
	}

	public static function report(WC_Order $source) {
		$report = array(
			'supported' => false,
			'reason' => '',
			'message' => '',
			'order_id' => absint($source->get_id()),
			'order_type' => sanitize_key((string) $source->get_type()),
			'status' => sanitize_key((string) $source->get_status()),
			'currency' => (string) $source->get_currency(),
			'prices_include_tax' => (bool) $source->get_prices_include_tax(),
			'is_paid' => (bool) $source->is_paid(),
			'line_count' => count($source->get_items('line_item')),
			'shipping_count' => count($source->get_items('shipping')),
			'fee_count' => count($source->get_items('fee')),
			'negative_fee_count' => 0,
			'coupon_count' => count($source->get_items('coupon')),
			'refund_count' => count($source->get_refunds()),
			'has_transaction' => '' !== (string) $source->get_transaction_id(),
			'deleted_product_lines' => 0,
			'fractional_quantity_lines' => 0,
			'managed_stock_lines' => 0,
			'unmanaged_stock_lines' => 0,
			'backorder_lines' => 0,
			'policy' => self::policy(),
		);

		foreach ($source->get_items('fee') as $fee) {
			if (WCOS_Decimal::to_units($fee->get_total(), wc_get_price_decimals()) < 0) {
				$report['negative_fee_count']++;
			}
		}

		if (!$source->get_id()) {
			return self::reject($report, 'unpersisted_order', __('The source order must be persisted before it can be split.', 'wc-order-splitter'));
		}
		if ('shop_order' !== $source->get_type()) {
			return self::reject($report, 'unsupported_order_type', __('Only WooCommerce shop orders can be split.', 'wc-order-splitter'));
		}
		if (!in_array($source->get_status(), array('pending', 'on-hold', 'processing'), true)) {
			return self::reject($report, 'unsupported_status', __('This order status is not supported by the quantity-split policy.', 'wc-order-splitter'));
		}
		if ('' === (string) $source->get_currency()) {
			return self::reject($report, 'missing_currency', __('The source order does not have a currency.', 'wc-order-splitter'));
		}
		if ($report['negative_fee_count'] > 0) {
			return self::reject($report, 'negative_fee_policy_missing', __('Orders containing negative fee rows are not supported until an explicit discount-like fee policy is implemented.', 'wc-order-splitter'));
		}
		if ($report['coupon_count'] > 0) {
			return self::reject($report, 'coupon_policy_missing', __('Orders containing coupon rows are not supported until a coupon allocation policy is implemented.', 'wc-order-splitter'));
		}
		if ($source->get_total_refunded() != 0 || $report['refund_count'] > 0) {
			return self::reject($report, 'refund_policy_missing', __('Refunded or partially refunded orders are not supported until a refund allocation policy is implemented.', 'wc-order-splitter'));
		}
		if (!empty($source->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true)) || !empty($source->get_meta('yoos_original_order', true))) {
			return self::reject($report, 'nested_split', __('Nested splitting of an existing split child is not supported.', 'wc-order-splitter'));
		}
		if (0 === $report['line_count']) {
			return self::reject($report, 'no_line_items', __('An order without product line items cannot be split.', 'wc-order-splitter'));
		}

		$order_stock_reduced = (bool) $source->get_data_store()->get_stock_reduced($source->get_id());
		foreach ($source->get_items('line_item') as $item) {
			try {
				$quantity_units = WCOS_Decimal::to_units($item->get_quantity(), 6);
			} catch (Throwable $throwable) {
				return self::reject($report, 'invalid_line_quantity', __('A source line contains an invalid quantity.', 'wc-order-splitter'));
			}
			if ($quantity_units <= 0) {
				return self::reject($report, 'invalid_line_quantity', __('Every source line must have a positive quantity.', 'wc-order-splitter'));
			}
			if (0 !== ($quantity_units % 1000000)) {
				$report['fractional_quantity_lines']++;
			}

			$product = $item->get_product();
			if (!$product) {
				$report['deleted_product_lines']++;
			} elseif ($product->managing_stock()) {
				$report['managed_stock_lines']++;
				if ($product->is_on_backorder($item->get_quantity())) {
					$report['backorder_lines']++;
				}
			} else {
				$report['unmanaged_stock_lines']++;
			}

			$reduced = $item->get_meta('_reduced_stock', true);
			if ('' === $reduced) {
				continue;
			}
			try {
				$reduced_units = WCOS_Decimal::to_units($reduced, 6);
			} catch (Throwable $throwable) {
				return self::reject($report, 'invalid_stock_marker', __('A source line contains a non-numeric reduced-stock marker.', 'wc-order-splitter'));
			}
			if (!$order_stock_reduced || $reduced_units < 0 || $reduced_units > $quantity_units) {
				return self::reject($report, 'invalid_stock_marker', __('The source order contains inconsistent reduced-stock markers.', 'wc-order-splitter'));
			}
		}

		try {
			WCOS_Order_Totals_Rebuilder::assert_consistent($source, wc_get_price_decimals());
		} catch (Throwable $throwable) {
			return self::reject($report, 'inconsistent_totals', __('The source order totals are internally inconsistent and cannot be split safely.', 'wc-order-splitter'));
		}

		$report['supported'] = true;
		$report['reason'] = 'supported';
		$report['message'] = __('This order is compatible with the current quantity-split policy.', 'wc-order-splitter');
		return $report;
	}

	private static function reject(array $report, $reason, $message) {
		$report['supported'] = false;
		$report['reason'] = sanitize_key((string) $reason);
		$report['message'] = (string) $message;
		return $report;
	}
}
