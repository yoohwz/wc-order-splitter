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

	public function split(WC_Order $source, array $plan, $operation_id) {
		$operation_id = sanitize_key((string) $operation_id);
		if ('' === $operation_id) {
			throw new InvalidArgumentException(__('A split operation ID is required.', 'wc-order-splitter'));
		}

		WCOS_Split_Preflight::assert_supported($source);
		$token = WCOS_Stock_Side_Effect_Guard::begin($operation_id);

		try {
			$children = (new WCOS_Split_Order_Service())->split($source, $plan, $operation_id);
			WCOS_Stock_Side_Effect_Guard::assert_clean($token);
			return $children;
		} catch (Throwable $throwable) {
			$events = WCOS_Stock_Side_Effect_Guard::events($token);
			if (!empty($events)) {
				$this->mark_manual_stock_reconciliation($source->get_id(), $operation_id, $events, $throwable);
				if (!$throwable instanceof WCOS_Unexpected_Stock_Mutation_Exception) {
					throw new WCOS_Unexpected_Stock_Mutation_Exception($events, $throwable);
				}
			}
			throw $throwable;
		} finally {
			WCOS_Stock_Side_Effect_Guard::end($token);
		}
	}

	public function preflight(WC_Order $source) {
		return WCOS_Split_Preflight::report($source);
	}

	private function mark_manual_stock_reconciliation($source_id, $operation_id, array $events, Throwable $throwable) {
		$fresh = wc_get_order(absint($source_id));
		if (!$fresh instanceof WC_Order) {
			return;
		}
		$record = WCOS_Operation_Journal::get($fresh, $operation_id);
		if (!is_array($record)) {
			return;
		}
		$status = isset($record['status']) ? sanitize_key((string) $record['status']) : '';
		if (in_array($status, array('completed', 'compensated'), true)) {
			return;
		}
		WCOS_Operation_Journal::require_recovery(
			$fresh,
			$operation_id,
			array(
				'reason' => 'unexpected_physical_stock_write',
				'automatic_compensation_allowed' => false,
				'stock_side_effects' => array_values($events),
				'error' => $throwable->getMessage(),
			)
		);
	}
}
