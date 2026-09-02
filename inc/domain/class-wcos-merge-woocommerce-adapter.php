<?php

defined('ABSPATH') || exit;

final class WCOS_Merge_Adapter_Exception extends RuntimeException {
	private $error_code;
	private $report;

	public function __construct($error_code, $message, array $report = array(), ?Throwable $previous = null) {
		$this->error_code = sanitize_key((string) $error_code);
		$this->report = $report;
		parent::__construct((string) $message, 0, $previous);
	}

	public function get_error_code() {
		return $this->error_code;
	}

	public function get_report() {
		return $this->report;
	}
}

/** WooCommerce-facing, transport-free adapter for the hardened Merge service. */
final class WCOS_Merge_WooCommerce_Adapter {

	public function merge(WC_Order $source, WC_Order $target, $operation_id, $confirmed_precision = null, array $confirmation_authority = array()) {
		$operation_id = sanitize_key((string) $operation_id);
		if ('' === $operation_id) {
			throw new WCOS_Merge_Adapter_Exception('invalid_operation_id', __('A Merge operation ID is required.', 'wc-order-splitter'));
		}
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		if (!$source_id || !$target_id || $source_id === $target_id) {
			throw new WCOS_Merge_Adapter_Exception('invalid_participant_pair', __('Merge requires two distinct persisted shop orders.', 'wc-order-splitter'));
		}
		$participants = WCOS_Merge_Canonical_Reader::shop_order_pair($source_id, $target_id);
		if (!is_array($participants)) {
			throw new WCOS_Merge_Adapter_Exception('participant_unavailable', __('A Merge participant is no longer available.', 'wc-order-splitter'));
		}
		list($source, $target) = $participants;

		$precision = WCOS_Price_Precision_Scope::for_operation($source, $operation_id, $confirmed_precision);
		$precision_token = WCOS_Price_Precision_Scope::begin($precision);
		$stock_token = false;
		try {
			$existing = WCOS_Operation_Journal::get($source, $operation_id);
			if (!is_array($existing)) {
				WCOS_Merge_Preflight::assert_supported($source, $target, $precision);
			}

			$stock_token = WCOS_Stock_Side_Effect_Guard::begin($operation_id);
			$marking_manual = false;
			$boundary_guard = function() use ($source_id, $target_id, $operation_id, $stock_token, &$marking_manual) {
				if ($marking_manual) {
					return;
				}
				$events = WCOS_Stock_Side_Effect_Guard::events($stock_token);
				if (!empty($events) && WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events)) {
					$marking_manual = true;
					try {
						$this->mark_manual_stock_reconciliation($source_id, $target_id, $operation_id);
					} finally {
						$marking_manual = false;
					}
				}
				WCOS_Stock_Side_Effect_Guard::assert_clean($stock_token);
			};
			add_action('wcos_merge_mutation_checkpoint', $boundary_guard, PHP_INT_MAX, 4);
			add_action('wcos_merge_recovery_checkpoint', $boundary_guard, PHP_INT_MAX, 4);

			try {
				$result = (new WCOS_Merge_Order_Service())->merge($source, $target, $operation_id, $precision, $confirmation_authority);
				WCOS_Stock_Side_Effect_Guard::assert_clean($stock_token);
				return $result;
			} catch (WCOS_Merge_Adapter_Exception $exception) {
				throw $exception;
			} catch (WCOS_Merge_Preflight_Exception $exception) {
				throw new WCOS_Merge_Adapter_Exception(
					'merge_preflight_' . $exception->get_reason(),
					$exception->getMessage(),
					$exception->get_report(),
					$exception
				);
			} catch (Throwable $throwable) {
				$events = WCOS_Stock_Side_Effect_Guard::events($stock_token);
				if (!empty($events)
					&& WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events)
					&& 'merge_manual_reconciliation' !== $this->current_error_code($source_id, $operation_id)) {
					$this->mark_manual_stock_reconciliation($source_id, $target_id, $operation_id);
				}
				if ($throwable instanceof WCOS_Unexpected_Stock_Mutation_Exception) {
					throw $throwable;
				}
				throw new WCOS_Merge_Adapter_Exception(
					$this->current_error_code($source_id, $operation_id),
					__('The hardened Merge request did not complete automatically.', 'wc-order-splitter'),
					array('source_order_id' => $source_id, 'target_order_id' => $target_id, 'operation_id' => $operation_id),
					$throwable
				);
			} finally {
				remove_action('wcos_merge_mutation_checkpoint', $boundary_guard, PHP_INT_MAX);
				remove_action('wcos_merge_recovery_checkpoint', $boundary_guard, PHP_INT_MAX);
			}
		} finally {
			if (false !== $stock_token) {
				WCOS_Stock_Side_Effect_Guard::end($stock_token);
			}
			WCOS_Price_Precision_Scope::end($precision_token);
		}
	}

	public function preflight(WC_Order $source, WC_Order $target, $operation_id = '', $confirmed_precision = null) {
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		$participants = WCOS_Merge_Canonical_Reader::shop_order_pair($source_id, $target_id);
		$precision = is_array($participants)
			? WCOS_Price_Precision_Scope::for_operation($participants[0], $operation_id, $confirmed_precision)
			: (null === $confirmed_precision ? WCOS_Price_Precision_Scope::store_precision() : WCOS_Price_Precision_Scope::validate($confirmed_precision));
		$token = WCOS_Price_Precision_Scope::begin($precision);
		try {
			if (!is_array($participants)) {
				return array(
					'supported' => false,
					'reason' => 'participant_unavailable',
					'message' => __('A Merge participant is no longer available.', 'wc-order-splitter'),
					'source_order_id' => $source_id,
					'target_order_id' => $target_id,
					'price_precision' => $precision,
				);
			}
			list($source, $target) = $participants;
			return WCOS_Merge_Preflight::report($source, $target, $precision);
		} finally {
			WCOS_Price_Precision_Scope::end($token);
		}
	}

	private function mark_manual_stock_reconciliation($source_id, $target_id, $operation_id) {
		$source = WCOS_Merge_Canonical_Reader::order(absint($source_id));
		$target = WCOS_Merge_Canonical_Reader::order(absint($target_id));
		$record = $source instanceof WC_Order ? WCOS_Operation_Journal::get($source, $operation_id) : null;
		if ($source instanceof WC_Order && $target instanceof WC_Order && is_array($record)) {
			WCOS_Merge_Compensator::manual_reconciliation($source, $target, $record, 'physical_stock_after_write');
		}
	}

	private function current_error_code($source_id, $operation_id) {
		$source = WCOS_Merge_Canonical_Reader::order(absint($source_id));
		$record = $source instanceof WC_Order ? WCOS_Operation_Journal::get($source, $operation_id) : null;
		$status = is_array($record) && isset($record['status']) ? sanitize_key((string) $record['status']) : '';
		if ('manual_reconciliation' === $status) {
			return 'merge_manual_reconciliation';
		}
		if ('compensated' === $status) {
			return 'merge_compensated';
		}
		return 'merge_execution_failed';
	}
}
