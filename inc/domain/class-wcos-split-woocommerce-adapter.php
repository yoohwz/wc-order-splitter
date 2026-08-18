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

        $precision = WCOS_Price_Precision_Scope::for_operation($source, $operation_id, $confirmed_precision);
        $precision_token = WCOS_Price_Precision_Scope::begin($precision);

        try {
            WCOS_Split_Preflight::assert_supported($source, $precision);
            $stock_token = WCOS_Stock_Side_Effect_Guard::begin($operation_id);

            try {
                $children = (new WCOS_Split_Order_Service())->split($source, $plan, $operation_id);
                WCOS_Stock_Side_Effect_Guard::assert_clean($stock_token);
                return $children;
            } catch (Throwable $throwable) {
                $events = WCOS_Stock_Side_Effect_Guard::events($stock_token);
                if (!empty($events) && WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events)) {
                    $this->mark_manual_stock_reconciliation($source->get_id(), $operation_id, $events, $throwable);
                }
                if (!empty($events) && !$throwable instanceof WCOS_Unexpected_Stock_Mutation_Exception) {
                    throw new WCOS_Unexpected_Stock_Mutation_Exception($events, $throwable);
                }
                throw $throwable;
            } finally {
                WCOS_Stock_Side_Effect_Guard::end($stock_token);
            }
        } finally {
            WCOS_Price_Precision_Scope::end($precision_token);
        }
    }

    public function preflight(WC_Order $source, $operation_id = '', $confirmed_precision = null) {
        $precision = WCOS_Price_Precision_Scope::for_operation($source, $operation_id, $confirmed_precision);
        $precision_token = WCOS_Price_Precision_Scope::begin($precision);
        try {
            return WCOS_Split_Preflight::report($source, $precision);
        } finally {
            WCOS_Price_Precision_Scope::end($precision_token);
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
