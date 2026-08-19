<?php

defined('ABSPATH') || exit;

/**
 * WooCommerce-facing adapter for the first P2 manual quantity-split workflow.
 *
 * The production gateway owns authorization/gating; this adapter owns
 * WooCommerce compatibility preflight and request-local side-effect evidence.
 * Integration tests may instantiate it directly while the production gate is
 * still hard-off.
 */
final class WCOS_Split_WooCommerce_Adapter {

    public function split(WC_Order $source, array $plan, $operation_id, $confirmed_precision = null) {
        $operation_id = sanitize_key((string) $operation_id);
        if ('' === $operation_id) {
            throw new InvalidArgumentException(__('A split operation ID is required.', 'wc-order-splitter'));
        }

        $source_id = $source->get_id();
        $precision = WCOS_Price_Precision_Scope::for_operation($source, $operation_id, $confirmed_precision);
        $precision_token = WCOS_Price_Precision_Scope::begin($precision);

        try {
            /*
             * WooCommerce derives line subtotal/total-tax props while hydrating
             * order items and that path calls wc_round_tax_total(). Reload only
             * after the operation precision is pinned so a retry cannot be
             * rounded by a changed ambient store precision before mutation code
             * even sees the source.
             */
            $source = wc_get_order($source_id);
            if (!$source instanceof WC_Order) {
                throw new RuntimeException(__('The source order is no longer available.', 'wc-order-splitter'));
            }

            $this->assert_verified_confirmation_source($source, $operation_id);
            $this->assert_supported($source, $precision);
            $stock_token = WCOS_Stock_Side_Effect_Guard::begin($operation_id);

            /*
             * Persist confirmed after-write evidence while the Split service's
             * order lease is still held. This closes the narrow gap between a
             * late third-party side effect and the adapter's outer postcondition
             * check after the service returns/releases its lease.
             */
            $boundary_guard = function() use ($source_id, $operation_id, $stock_token) {
                $events = WCOS_Stock_Side_Effect_Guard::events($stock_token);
                if (!empty($events) && WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events)) {
                    $this->mark_manual_stock_reconciliation(
                        $source_id,
                        $operation_id,
                        $events,
                        new RuntimeException(__('Unexpected physical-stock evidence was observed before the Split request boundary completed.', 'wc-order-splitter'))
                    );
                }
                WCOS_Stock_Side_Effect_Guard::assert_clean($stock_token);
            };
            add_action('wcos_split_mutation_checkpoint', $boundary_guard, PHP_INT_MAX, 4);
            add_action('woocommerce_order_note_added', $boundary_guard, PHP_INT_MAX, 2);

            try {
                $children = (new WCOS_Split_Order_Service())->split($source, $plan, $operation_id);
                WCOS_Stock_Side_Effect_Guard::assert_clean($stock_token);
                return $children;
            } catch (Throwable $throwable) {
                $events = WCOS_Stock_Side_Effect_Guard::events($stock_token);
                if (!empty($events) && WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events)) {
                    $this->mark_manual_stock_reconciliation($source_id, $operation_id, $events, $throwable);
                }
                if (!empty($events) && !$throwable instanceof WCOS_Unexpected_Stock_Mutation_Exception) {
                    throw new WCOS_Unexpected_Stock_Mutation_Exception($events, $throwable);
                }
                throw $throwable;
            } finally {
                remove_action('wcos_split_mutation_checkpoint', $boundary_guard, PHP_INT_MAX);
                remove_action('woocommerce_order_note_added', $boundary_guard, PHP_INT_MAX);
                WCOS_Stock_Side_Effect_Guard::end($stock_token);
            }
        } finally {
            WCOS_Price_Precision_Scope::end($precision_token);
        }
    }

    public function preflight(WC_Order $source, $operation_id = '', $confirmed_precision = null) {
        $source_id = $source->get_id();
        $precision = WCOS_Price_Precision_Scope::for_operation($source, $operation_id, $confirmed_precision);
        $precision_token = WCOS_Price_Precision_Scope::begin($precision);
        try {
            $source = $source_id ? wc_get_order($source_id) : $source;
            if (!$source instanceof WC_Order) {
                throw new RuntimeException(__('The source order is no longer available.', 'wc-order-splitter'));
            }
            $report = $this->apply_reconciliation_blocker(
                $source,
                WCOS_Split_Preflight::report($source, $precision)
            );
            /*
             * PII-free hash of the exact source state the server reviewed. The
             * confirmation store must compare this against its own scoped reload
             * before creating a token, closing Review -> confirmation TOCTOU.
             */
            $report['source_signature'] = WCOS_Order_Contract_Snapshot::source_signature($source);
            return $report;
        } finally {
            WCOS_Price_Precision_Scope::end($precision_token);
        }
    }

    private function assert_verified_confirmation_source(WC_Order $source, $operation_id) {
        if (!class_exists('WCOS_Split_Confirmation_Store')) {
            return;
        }
        $expected = WCOS_Split_Confirmation_Store::verified_source_signature($operation_id);
        if ('' === $expected) {
            return;
        }
        $actual = WCOS_Order_Contract_Snapshot::source_signature($source);
        if (!hash_equals($expected, $actual)) {
            throw new WCOS_Split_Preflight_Exception(
                'source_changed_after_confirmation',
                __('The source order changed after Split confirmation verification but before mutation began. Review the order again.', 'wc-order-splitter'),
                array(
                    'supported' => false,
                    'reason' => 'source_changed_after_confirmation',
                    'order_id' => $source->get_id(),
                )
            );
        }
    }

    private function assert_supported(WC_Order $source, $precision) {
        $report = $this->apply_reconciliation_blocker(
            $source,
            WCOS_Split_Preflight::report($source, $precision)
        );
        if (empty($report['supported'])) {
            throw new WCOS_Split_Preflight_Exception(
                isset($report['reason']) ? $report['reason'] : 'unsupported',
                isset($report['message']) ? $report['message'] : __('This order is not supported by the quantity-split adapter.', 'wc-order-splitter'),
                $report
            );
        }
        return $report;
    }

    private function apply_reconciliation_blocker(WC_Order $source, array $report) {
        if (!class_exists('WCOS_Manual_Reconciliation_Blocker')) {
            return $report;
        }

        $operation_ids = WCOS_Manual_Reconciliation_Blocker::active_operation_ids($source);
        if (empty($operation_ids)) {
            return $report;
        }

        $existing = isset($report['manual_reconciliation_operation_ids'])
            ? array_values(array_filter(array_map('sanitize_key', (array) $report['manual_reconciliation_operation_ids'])))
            : array();
        $operation_ids = array_values(array_unique(array_merge($existing, $operation_ids)));
        sort($operation_ids, SORT_STRING);

        $report['supported'] = false;
        $report['reason'] = 'manual_reconciliation_required';
        $report['message'] = sprintf(
            /* translators: %s: comma-separated mutation operation IDs requiring manual stock reconciliation. */
            __('This order has an unresolved mutation incident that requires manual stock reconciliation before another split can run. Operation ID(s): %s.', 'wc-order-splitter'),
            implode(', ', $operation_ids)
        );
        $report['manual_reconciliation_count'] = count($operation_ids);
        $report['manual_reconciliation_operation_ids'] = $operation_ids;
        return $report;
    }

    private function mark_manual_stock_reconciliation($source_id, $operation_id, array $events, Throwable $throwable) {
        $fresh = wc_get_order(absint($source_id));
        if (!$fresh instanceof WC_Order) {
            return false;
        }
        $record = WCOS_Operation_Journal::get($fresh, $operation_id);
        if (!is_array($record)) {
            return false;
        }

        /*
         * Persist the source-level blocker first. If the process dies before the
         * journal transition, the next preflight remains fail-closed rather than
         * missing a physical-stock incident because the secondary index was never
         * written.
         */
        if (!WCOS_Manual_Reconciliation_Blocker::block($fresh, $operation_id)) {
            do_action(
                'wcos_manual_reconciliation_record_error',
                $fresh,
                $operation_id,
                $events,
                $throwable
            );
            return false;
        }

        $updated = WCOS_Operation_Journal::mark_manual_reconciliation(
            $fresh,
            $operation_id,
            array(
                'reason' => 'unexpected_physical_stock_write',
                'automatic_compensation_allowed' => false,
                'stock_side_effects' => array_values($events),
                'error' => $throwable->getMessage(),
            )
        );

        if (!$updated) {
            /* Keep the first-phase blocker in place deliberately. */
            do_action(
                'wcos_manual_reconciliation_record_error',
                $fresh,
                $operation_id,
                $events,
                $throwable
            );
        }
        return $updated;
    }
}
