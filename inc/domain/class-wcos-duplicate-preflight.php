<?php

defined('ABSPATH') || exit;

final class WCOS_Duplicate_Preflight_Exception extends RuntimeException {
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
 * Read-only production preflight for hardened single-order Duplicate.
 *
 * Reports intentionally contain no customer/address/payment plaintext.
 */
final class WCOS_Duplicate_Preflight {
    const POLICY_VERSION = 1;

    public static function policy() {
        return array(
            'policy_version' => self::POLICY_VERSION,
            'target_status' => 'pending',
            'payment_method' => 'copy',
            'payment_transaction' => 'do_not_copy',
            'stock_reduced' => 'do_not_copy',
            'physical_stock' => 'no_write',
            'historical_totals' => 'copy_exact',
            'historical_tax' => 'copy_exact',
            'shipping' => 'copy_exact',
            'fees' => 'copy_exact',
            'coupons' => 'copy_exact',
            'refunds' => 'reject',
            'order_custom_meta' => 'do_not_copy',
            'unknown_private_item_meta' => 'reject',
            'manual_reconciliation' => 'reject',
        );
    }

    public static function assert_supported(WC_Order $source, $precision = null) {
        $report = self::report($source, $precision);
        if (empty($report['supported'])) {
            throw new WCOS_Duplicate_Preflight_Exception(
                isset($report['reason']) ? $report['reason'] : 'unsupported',
                isset($report['message']) ? $report['message'] : __('This order is not supported by the hardened Duplicate workflow.', 'wc-order-splitter'),
                $report
            );
        }
        return $report;
    }

    public static function report(WC_Order $source, $precision = null) {
        $precision = null === $precision
            ? WCOS_Price_Precision_Scope::store_precision()
            : WCOS_Price_Precision_Scope::validate($precision);

        $report = array(
            'supported' => false,
            'reason' => '',
            'message' => '',
            'order_id' => absint($source->get_id()),
            'order_type' => sanitize_key((string) $source->get_type()),
            'status' => sanitize_key((string) $source->get_status()),
            'currency' => (string) $source->get_currency(),
            'price_precision' => $precision,
            'is_paid' => (bool) $source->is_paid(),
            'has_transaction' => '' !== (string) $source->get_transaction_id(),
            'line_count' => count($source->get_items('line_item')),
            'shipping_count' => count($source->get_items('shipping')),
            'fee_count' => count($source->get_items('fee')),
            'coupon_count' => count($source->get_items('coupon')),
            'refund_count' => count($source->get_refunds()),
            'deleted_product_lines' => 0,
            'fractional_quantity_lines' => 0,
            'manual_reconciliation_count' => 0,
            'manual_reconciliation_operation_ids' => array(),
            'unknown_private_meta_keys' => array(),
            'inconsistent_private_meta_keys' => array(),
            'source_signature' => '',
            'policy' => self::policy(),
        );

        if (!$source->get_id()) {
            return self::reject($report, 'unpersisted_order', __('The source order must be persisted before it can be duplicated.', 'wc-order-splitter'));
        }
        if ('shop_order' !== $source->get_type()) {
            return self::reject($report, 'unsupported_order_type', __('Only WooCommerce shop orders can be duplicated.', 'wc-order-splitter'));
        }
        if ('trash' === $source->get_status()) {
            return self::reject($report, 'trashed_order', __('Trashed orders cannot be duplicated.', 'wc-order-splitter'));
        }
        if ('' === (string) $source->get_currency()) {
            return self::reject($report, 'missing_currency', __('The source order does not have a currency.', 'wc-order-splitter'));
        }
        if (0 === $report['line_count']) {
            return self::reject($report, 'no_line_items', __('An order without product line items cannot be duplicated.', 'wc-order-splitter'));
        }
        if ($source->get_total_refunded() != 0 || $report['refund_count'] > 0) {
            return self::reject($report, 'refund_policy_missing', __('Refunded or partially refunded orders are not supported by the first hardened Duplicate workflow.', 'wc-order-splitter'));
        }

        if (class_exists('WCOS_Manual_Reconciliation_Blocker')) {
            $operation_ids = WCOS_Manual_Reconciliation_Blocker::active_operation_ids($source);
            if (!empty($operation_ids)) {
                $report['manual_reconciliation_count'] = count($operation_ids);
                $report['manual_reconciliation_operation_ids'] = $operation_ids;
                return self::reject(
                    $report,
                    'manual_reconciliation_required',
                    sprintf(
                        /* translators: %s: comma-separated mutation operation IDs. */
                        __('This order has unresolved stock-reconciliation evidence for operation(s): %s. Resolve it before duplicating the order.', 'wc-order-splitter'),
                        implode(', ', $operation_ids)
                    )
                );
            }
        }

        $unknown_private = array();
        $inconsistent_private = array();
        foreach (array('line_item', 'shipping', 'fee', 'tax', 'coupon') as $item_type) {
            foreach ($source->get_items($item_type) as $item) {
                try {
                    /* Validate every copied business-meta value before mutation. */
                    WCOS_Order_Item_Meta_Policy::business_metadata($item);
                } catch (Throwable $throwable) {
                    return self::reject($report, 'noncanonical_business_metadata', $throwable->getMessage());
                }

                $unknown_private = array_merge(
                    $unknown_private,
                    WCOS_Order_Item_Meta_Policy::unknown_private_keys($item, WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE)
                );

                if ($item instanceof WC_Order_Item_Product) {
                    foreach ($item->get_meta_data() as $meta) {
                        $key = (string) $meta->key;
                        if (0 !== strpos($key, '_') || WCOS_Order_Item_Meta_Policy::is_protected($key)) {
                            continue;
                        }
                        $duplicate_class = WCOS_Order_Item_Meta_Policy::classify(
                            $key,
                            $meta->value,
                            WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE,
                            $item
                        );
                        $identity_class = WCOS_Order_Item_Meta_Policy::classify(
                            $key,
                            $meta->value,
                            WCOS_Order_Item_Meta_Policy::CONTEXT_IDENTITY,
                            $item
                        );
                        if ($duplicate_class !== $identity_class) {
                            $inconsistent_private[] = $key;
                        }
                    }

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
                        if (!WCOS_Split_Preflight::fractional_quantities_supported()) {
                            return self::reject(
                                $report,
                                'fractional_quantity_unsupported',
                                __('This order contains fractional quantities but the active WooCommerce quantity integration no longer preserves fractional stock amounts.', 'wc-order-splitter')
                            );
                        }
                    }
                    if (!$item->get_product()) {
                        $report['deleted_product_lines']++;
                    }
                }
            }
        }

        $unknown_private = array_values(array_unique(array_map('strval', $unknown_private)));
        sort($unknown_private, SORT_STRING);
        $inconsistent_private = array_values(array_unique(array_map('strval', $inconsistent_private)));
        sort($inconsistent_private, SORT_STRING);
        $report['unknown_private_meta_keys'] = $unknown_private;
        $report['inconsistent_private_meta_keys'] = $inconsistent_private;

        if (!empty($inconsistent_private)) {
            return self::reject(
                $report,
                'inconsistent_private_metadata_classification',
                __('A private line-item metadata adapter classifies the same key differently for Duplicate copying and canonical line identity.', 'wc-order-splitter')
            );
        }
        if (!empty($unknown_private)) {
            return self::reject(
                $report,
                'unclassified_private_metadata',
                __('The source order contains private order-item metadata that has not been classified by a compatibility adapter.', 'wc-order-splitter')
            );
        }

        try {
            WCOS_Order_Totals_Rebuilder::assert_consistent($source, $precision);
            $report['source_signature'] = WCOS_Order_Contract_Snapshot::source_signature($source);
        } catch (Throwable $throwable) {
            return self::reject($report, 'inconsistent_source', __('The source order cannot be proven internally consistent for Duplicate.', 'wc-order-splitter'));
        }

        $report['supported'] = true;
        $report['reason'] = 'supported';
        $report['message'] = __('This order is compatible with the current hardened Duplicate policy.', 'wc-order-splitter');
        return $report;
    }

    private static function reject(array $report, $reason, $message) {
        $report['supported'] = false;
        $report['reason'] = sanitize_key((string) $reason);
        $report['message'] = (string) $message;
        return $report;
    }
}
