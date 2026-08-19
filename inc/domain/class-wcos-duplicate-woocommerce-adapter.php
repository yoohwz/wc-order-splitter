<?php

defined('ABSPATH') || exit;

/**
 * WooCommerce-facing adapter for hardened single-order Duplicate.
 *
 * The production gateway owns feature gating/authorization. This adapter owns
 * compatibility preflight, precision scope, and request-local stock evidence.
 */
final class WCOS_Duplicate_WooCommerce_Adapter {

    public function duplicate(WC_Order $source, $operation_id, $confirmed_precision = null) {
        $operation_id = sanitize_key((string) $operation_id);
        if ('' === $operation_id) {
            throw new InvalidArgumentException(__('A duplicate operation ID is required.', 'wc-order-splitter'));
        }

        $source_id = $source->get_id();
        $precision = WCOS_Price_Precision_Scope::for_operation($source, $operation_id, $confirmed_precision);
        $precision_token = WCOS_Price_Precision_Scope::begin($precision);

        try {
            $source = $source_id ? wc_get_order($source_id) : $source;
            if (!$source instanceof WC_Order) {
                throw new RuntimeException(__('The source order is no longer available.', 'wc-order-splitter'));
            }

            $this->assert_verified_confirmation_source($source, $operation_id);
            WCOS_Duplicate_Preflight::assert_supported($source, $precision);
            $stock_token = WCOS_Stock_Side_Effect_Guard::begin($operation_id);

            $boundary_guard = function() use ($source_id, $operation_id, $stock_token) {
                $events = WCOS_Stock_Side_Effect_Guard::events($stock_token);
                if (!empty($events) && WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events)) {
                    $this->mark_manual_stock_reconciliation(
                        $source_id,
                        $operation_id,
                        $events,
                        new RuntimeException(__('Unexpected physical-stock evidence was observed before the Duplicate request boundary completed.', 'wc-order-splitter'))
                    );
                }
                WCOS_Stock_Side_Effect_Guard::assert_clean($stock_token);
            };
            add_action('wcos_duplicate_mutation_checkpoint', $boundary_guard, PHP_INT_MAX, 4);
            add_action('woocommerce_order_note_added', $boundary_guard, PHP_INT_MAX, 2);

            try {
                $target = (new WCOS_Duplicate_Order_Service())->duplicate($source, $operation_id);
                WCOS_Stock_Side_Effect_Guard::assert_clean($stock_token);
                return $target;
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
                remove_action('wcos_duplicate_mutation_checkpoint', $boundary_guard, PHP_INT_MAX);
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
            return WCOS_Duplicate_Preflight::report($source, $precision);
        } finally {
            WCOS_Price_Precision_Scope::end($precision_token);
        }
    }

    private function assert_verified_confirmation_source(WC_Order $source, $operation_id) {
        if (!class_exists('WCOS_Duplicate_Confirmation_Store')) {
            return;
        }
        $expected = WCOS_Duplicate_Confirmation_Store::verified_source_signature($operation_id);
        if ('' === $expected) {
            return;
        }
        $actual = WCOS_Order_Contract_Snapshot::source_signature($source);
        if (!hash_equals($expected, $actual)) {
            throw new WCOS_Duplicate_Preflight_Exception(
                'source_changed_after_confirmation',
                __('The source order changed after Duplicate confirmation verification but before mutation began. Review the order again.', 'wc-order-splitter'),
                array(
                    'supported' => false,
                    'reason' => 'source_changed_after_confirmation',
                    'order_id' => $source->get_id(),
                )
            );
        }
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

        if (!WCOS_Manual_Reconciliation_Blocker::block($fresh, $operation_id)) {
            do_action('wcos_manual_reconciliation_record_error', $fresh, $operation_id, $events, $throwable);
            return false;
        }

        $updated = WCOS_Operation_Journal::mark_manual_reconciliation(
            $fresh,
            $operation_id,
            array(
                'reason' => 'unexpected_physical_stock_write',
                'workflow' => 'duplicate',
                'automatic_compensation_allowed' => false,
                'stock_side_effects' => array_values($events),
                'error' => $throwable->getMessage(),
            )
        );
        if (!$updated) {
            do_action('wcos_manual_reconciliation_record_error', $fresh, $operation_id, $events, $throwable);
        }
        return $updated;
    }
}
