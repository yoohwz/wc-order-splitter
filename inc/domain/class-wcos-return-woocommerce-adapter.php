<?php

defined('ABSPATH') || exit;

final class WCOS_Return_Adapter_Exception extends RuntimeException {
	private $error_code;
	private $report;

	public function __construct($error_code, $message, array $report = array(), ?Throwable $previous = null) {
		$this->error_code = sanitize_key((string) $error_code);
		$this->report = $report;
		parent::__construct((string) $message, 0, $previous);
	}

	public function get_error_code() { return $this->error_code; }
	public function get_report() { return $this->report; }
}

/** WooCommerce-facing, transport-free adapter for the hardened Return service. */
final class WCOS_Return_WooCommerce_Adapter {

	public function return_order(WC_Order $child, $operation_id, $confirmed_precision = null, array $confirmation_authority = array()) {
		$operation_id = sanitize_key((string) $operation_id);
		$child_id = absint($child->get_id());
		if ('' === $operation_id) { throw new WCOS_Return_Adapter_Exception('invalid_operation_id', __('A Return operation ID is required.', 'wc-order-splitter')); }
		if (!$child_id || 'shop_order' !== $child->get_type()) { throw new WCOS_Return_Adapter_Exception('invalid_child', __('Return requires one persisted Split child order.', 'wc-order-splitter')); }

		$child = wc_get_order($child_id);
		if (!$child instanceof WC_Order) { throw new WCOS_Return_Adapter_Exception('child_unavailable', __('The Return child is no longer available.', 'wc-order-splitter')); }
		$existing = WCOS_Operation_Journal::get($child, $operation_id);
		if (is_array($existing)) {
			$precision = WCOS_Price_Precision_Scope::for_operation($child, $operation_id, $confirmed_precision);
			if (null !== $confirmed_precision && WCOS_Price_Precision_Scope::validate($confirmed_precision) !== $precision) {
				throw new WCOS_Return_Adapter_Exception('price_precision_mismatch', __('Confirmed Return precision does not match durable operation authority.', 'wc-order-splitter'));
			}
		} else {
			try {
				$report = WCOS_Return_Preflight::assert_supported($child, true);
			} catch (WCOS_Return_Lineage_Exception $exception) {
				throw new WCOS_Return_Adapter_Exception('return_preflight_' . $exception->get_reason(), $exception->getMessage(), WCOS_Return_Preflight::report($child, true), $exception);
			}
			$precision = WCOS_Price_Precision_Scope::validate($report['return_plan']['price_precision']);
			if (null !== $confirmed_precision && WCOS_Price_Precision_Scope::validate($confirmed_precision) !== $precision) {
				throw new WCOS_Return_Adapter_Exception('price_precision_mismatch', __('Confirmed Return precision does not match historical Split authority.', 'wc-order-splitter'), $report);
			}
		}

		$precision_token = WCOS_Price_Precision_Scope::begin($precision);
		$stock_token = false;
		try {
			$stock_token = WCOS_Stock_Side_Effect_Guard::begin($operation_id);
			$marking_manual = false;
			$boundary_guard = function() use ($child_id, $operation_id, $stock_token, &$marking_manual) {
				if ($marking_manual) { return; }
				$events = WCOS_Stock_Side_Effect_Guard::events($stock_token);
				if (!empty($events) && WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events)) {
					$marking_manual = true;
					try { $this->mark_manual_stock_reconciliation($child_id, $operation_id); }
					finally { $marking_manual = false; }
				}
				WCOS_Stock_Side_Effect_Guard::assert_clean($stock_token);
			};
			add_action('wcos_return_mutation_checkpoint', $boundary_guard, PHP_INT_MAX, 4);
			add_action('wcos_return_recovery_checkpoint', $boundary_guard, PHP_INT_MAX, 4);
			try {
				$result = (new WCOS_Return_Order_Service())->return_order($child, $operation_id, $precision, $confirmation_authority);
				WCOS_Stock_Side_Effect_Guard::assert_clean($stock_token);
				return $result;
			} catch (WCOS_Return_Adapter_Exception $exception) {
				throw $exception;
			} catch (WCOS_Return_Lineage_Exception $exception) {
				throw new WCOS_Return_Adapter_Exception('return_preflight_' . $exception->get_reason(), $exception->getMessage(), WCOS_Return_Preflight::report($child, true), $exception);
			} catch (Throwable $throwable) {
				$events = WCOS_Stock_Side_Effect_Guard::events($stock_token);
				$after_write = !empty($events) && WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events);
				if ($after_write
					&& 'return_manual_reconciliation' !== $this->current_error_code($child_id, $operation_id)) {
					$this->mark_manual_stock_reconciliation($child_id, $operation_id);
				}
				if ($throwable instanceof WCOS_Unexpected_Stock_Mutation_Exception) {
					/*
					 * A blocked-before-write event is safe to retry only after this
					 * request's dirty observer scope has ended. The service has already
					 * released its pair lease here; dispatch the existing coordinator,
					 * never an adapter-owned compensation path.
					 */
					if (!$after_write) {
						WCOS_Stock_Side_Effect_Guard::end($stock_token);
						$stock_token = false;
						$fresh_child = wc_get_order($child_id);
						$record = $fresh_child instanceof WC_Order ? WCOS_Operation_Journal::get($fresh_child, $operation_id) : null;
						if (is_array($record)) { WCOS_Operation_Journal::fail($fresh_child, $operation_id, array('reason' => 'blocked_before_physical_stock_write')); }
					}
					throw $throwable;
				}
				$current_child = wc_get_order($child_id);
				$record = $current_child instanceof WC_Order ? WCOS_Operation_Journal::get($current_child, $operation_id) : null;
				$pair = is_array($record) ? WCOS_Return_Journal_Context::pair_from_record($record) : null;
				throw new WCOS_Return_Adapter_Exception(
					$this->current_error_code($child_id, $operation_id),
					__('The hardened Return request did not complete automatically.', 'wc-order-splitter'),
					array('child_order_id' => $child_id, 'source_order_id' => is_array($pair) ? $pair['original_order_id'] : 0, 'operation_id' => $operation_id),
					$throwable
				);
			} finally {
				remove_action('wcos_return_mutation_checkpoint', $boundary_guard, PHP_INT_MAX);
				remove_action('wcos_return_recovery_checkpoint', $boundary_guard, PHP_INT_MAX);
			}
		} finally {
			if (false !== $stock_token) { WCOS_Stock_Side_Effect_Guard::end($stock_token); }
			WCOS_Price_Precision_Scope::end($precision_token);
		}
	}

	public function preflight(WC_Order $child, $operation_id = '', $confirmed_precision = null) {
		$child_id = absint($child->get_id());
		$child = $child_id ? wc_get_order($child_id) : $child;
		if (!$child instanceof WC_Order) {
			return array('supported' => false, 'reason' => 'child_unavailable', 'message' => __('The Return child is no longer available.', 'wc-order-splitter'), 'child_order_id' => $child_id, 'source_order_id' => 0);
		}
		$report = WCOS_Return_Preflight::report($child, true);
		if (!empty($report['supported'])) {
			$precision = WCOS_Price_Precision_Scope::validate($report['return_plan']['price_precision']);
			$report['price_precision'] = $precision;
			if (null !== $confirmed_precision && WCOS_Price_Precision_Scope::validate($confirmed_precision) !== $precision) {
				$report['supported'] = false; $report['reason'] = 'price_precision_mismatch';
				$report['message'] = __('Confirmed Return precision does not match historical Split authority.', 'wc-order-splitter');
			}
		}
		return $report;
	}

	private function mark_manual_stock_reconciliation($child_id, $operation_id) {
		$child = wc_get_order(absint($child_id));
		$record = $child instanceof WC_Order ? WCOS_Operation_Journal::get($child, $operation_id) : null;
		$pair = is_array($record) ? WCOS_Return_Journal_Context::pair_from_record($record) : null;
		$original = is_array($pair) ? wc_get_order($pair['original_order_id']) : null;
		if ($child instanceof WC_Order && is_array($record)) {
			WCOS_Return_Compensator::manual_reconciliation($child, $original, $record, 'physical_stock_after_write');
		}
	}

	private function current_error_code($child_id, $operation_id) {
		$child = wc_get_order(absint($child_id));
		$record = $child instanceof WC_Order ? WCOS_Operation_Journal::get($child, $operation_id) : null;
		$status = is_array($record) && isset($record['status']) ? sanitize_key((string) $record['status']) : '';
		if ('manual_reconciliation' === $status) { return 'return_manual_reconciliation'; }
		if ('compensated' === $status) { return 'return_compensated'; }
		return 'return_execution_failed';
	}
}
