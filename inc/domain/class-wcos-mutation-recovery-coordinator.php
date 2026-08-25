<?php

defined('ABSPATH') || exit;

/**
 * Coordinates automatic recovery only when a durable journal proves that a
 * compensating rollback is safe. Ambiguous states remain recovery_required.
 */
final class WCOS_Mutation_Recovery_Coordinator {

	private static $active = array();

	public static function bootstrap() {
		add_action('wcos_mutation_recovery_required', array(__CLASS__, 'handle'), 10, 3);
	}

	public static function handle(WC_Order $source, $operation_id, array $record) {
		$operation_id = sanitize_key($operation_id);
		$source_id = $source->get_id();
		$key = $source_id . ':' . $operation_id;
		if (isset(self::$active[$key])) {
			return;
		}
		$type = isset($record['type']) ? sanitize_key($record['type']) : '';
		if (!in_array($type, array('split', 'merge', 'return'), true)) {
			return;
		}
		if ('return' === $type) {
			self::handle_return($source, $operation_id, $record, $key);
			return;
		}
		if ('merge' === $type) {
			self::handle_merge($source, $operation_id, $record, $key);
			return;
		}

		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		if (array_key_exists('automatic_compensation_allowed', $context) && false === $context['automatic_compensation_allowed']) {
			return;
		}
		/* A confirmed after-write stock event is outside the order snapshot. */
		if (class_exists('WCOS_Stock_Side_Effect_Guard') && WCOS_Stock_Side_Effect_Guard::has_physical_write_active_scope()) {
			return;
		}
		if (empty($context['source_snapshot']) || empty($context['source_recovery_signature_after'])) {
			return;
		}

		self::$active[$key] = true;
		$lease_token = false;
		$acquired_here = false;
		try {
			if (!WCOS_Operation_Lock::is_current_owned_for($source_id, $operation_id)) {
				$lease_token = WCOS_Operation_Lock::acquire($source_id, $operation_id);
				if (false === $lease_token) {
					throw new RuntimeException(__('Automatic mutation recovery could not acquire the source-order lease for this operation.', 'wc-order-splitter'));
				}
				$acquired_here = true;
			}
			WCOS_Operation_Lock::assert_current_owned_for($source_id, $operation_id);

			$fresh_source = wc_get_order($source_id);
			if (!$fresh_source || 'shop_order' !== $fresh_source->get_type()) {
				throw new RuntimeException(__('The mutation source order disappeared before recovery could begin.', 'wc-order-splitter'));
			}
			$fresh_record = WCOS_Operation_Journal::get($fresh_source, $operation_id);
			if (!is_array($fresh_record)) {
				throw new RuntimeException(__('The mutation recovery journal disappeared before compensation.', 'wc-order-splitter'));
			}
			WCOS_Operation_Journal::assert_fingerprint($fresh_record, isset($record['fingerprint']) ? $record['fingerprint'] : '');

			$children = self::discover_children($fresh_source, $operation_id);
			WCOS_Split_Compensator::compensate($fresh_source, $children, $fresh_record);
		} catch (Throwable $throwable) {
			/* Keep the durable ambiguous state visible for a later safe retry. */
			do_action('wcos_mutation_compensation_error', $throwable, $source, $operation_id, $record);
		} finally {
			if ($acquired_here && false !== $lease_token) {
				WCOS_Operation_Lock::release($source_id, $lease_token);
			}
			unset(self::$active[$key]);
		}
	}

	private static function handle_return(WC_Order $child, $operation_id, array $record, $key) {
		self::$active[$key] = true;
		$lease = false;
		$original = null;
		$stock_guard = false;
		$fresh_child = null;
		$fresh_record = null;
		try {
			$fresh_child = wc_get_order($child->get_id());
			$fresh_record = $fresh_child instanceof WC_Order
				? WCOS_Operation_Journal::get($fresh_child, $operation_id)
				: null;
			if (!$fresh_child instanceof WC_Order || !is_array($fresh_record)) {
				throw new RuntimeException(__('The authoritative Return recovery journal is unavailable.', 'wc-order-splitter'));
			}
			WCOS_Operation_Journal::assert_fingerprint($fresh_record, isset($record['fingerprint']) ? $record['fingerprint'] : '');
			$pair = WCOS_Return_Journal_Context::pair_from_record($fresh_record);
			if (!is_array($pair)) {
				WCOS_Return_Compensator::manual_reconciliation($fresh_child, null, $fresh_record, 'corrupt_return_pair_authority');
				return;
			}
			$original = wc_get_order($pair['original_order_id']);
			if (!$original instanceof WC_Order || 'shop_order' !== $original->get_type()) {
				WCOS_Return_Compensator::manual_reconciliation($fresh_child, null, $fresh_record, 'missing_return_peer');
				return;
			}
			$participant_ids = array($pair['child_order_id'], $pair['original_order_id']);
			$owned = array_filter($participant_ids, static function($order_id) use ($operation_id) {
				return WCOS_Operation_Lock::is_current_owned_for($order_id, $operation_id);
			});
			if (count($owned) === count($participant_ids)) {
				$lease = WCOS_Multi_Order_Lease::adopt_current($participant_ids, $operation_id);
			} elseif (empty($owned)) {
				$lease = WCOS_Multi_Order_Lease::acquire($participant_ids, $operation_id);
			} else {
				throw new RuntimeException(__('Return recovery found an incomplete same-operation lease set.', 'wc-order-splitter'));
			}
			if (!$lease instanceof WCOS_Multi_Order_Lease) {
				throw new RuntimeException(__('Return recovery could not acquire both participant leases.', 'wc-order-splitter'));
			}
			$lease->assert_owned();
			$stock_guard = WCOS_Stock_Side_Effect_Guard::begin($operation_id);
			WCOS_Return_Compensator::recover($fresh_child, $original, $fresh_record, $lease);
		} catch (Throwable $throwable) {
			$before_write_rejection = $throwable instanceof WCOS_Unexpected_Stock_Mutation_Exception
				&& !WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($throwable->get_events());
			if (!$throwable instanceof WCOS_Return_Recovery_Interruption_Exception && !$before_write_rejection
				&& $lease instanceof WCOS_Multi_Order_Lease && $fresh_child instanceof WC_Order && is_array($fresh_record)) {
				WCOS_Return_Compensator::manual_reconciliation($fresh_child, $original, $fresh_record, 'return_recovery_validation_failed');
			}
			do_action('wcos_mutation_compensation_error', $throwable, $child, $operation_id, $record);
		} finally {
			if (false !== $stock_guard) { WCOS_Stock_Side_Effect_Guard::end($stock_guard); }
			if ($lease instanceof WCOS_Multi_Order_Lease) { $lease->release(); }
			unset(self::$active[$key]);
		}
	}

	private static function handle_merge(WC_Order $source, $operation_id, array $record, $key) {
		self::$active[$key] = true;
		$lease = false;
		$target = null;
		$stock_guard = false;
		try {
			$fresh_source = wc_get_order($source->get_id());
			$fresh_record = $fresh_source instanceof WC_Order
				? WCOS_Operation_Journal::get($fresh_source, $operation_id)
				: null;
			if (!$fresh_source instanceof WC_Order || !is_array($fresh_record)) {
				throw new RuntimeException(__('The authoritative Merge recovery journal is unavailable.', 'wc-order-splitter'));
			}
			WCOS_Operation_Journal::assert_fingerprint($fresh_record, isset($record['fingerprint']) ? $record['fingerprint'] : '');
			$pair = WCOS_Merge_Journal_Context::pair_from_record($fresh_record);
			if (!is_array($pair)) {
				WCOS_Merge_Compensator::manual_reconciliation($fresh_source, null, $fresh_record, 'corrupt_pair_authority');
				return;
			}
			$target = wc_get_order($pair['target_order_id']);
			if (!$target instanceof WC_Order || 'shop_order' !== $target->get_type()) {
				WCOS_Merge_Compensator::manual_reconciliation($fresh_source, null, $fresh_record, 'missing_merge_peer');
				return;
			}

			$participant_ids = array($pair['source_order_id'], $pair['target_order_id']);
			$owned_by_operation = array_filter($participant_ids, static function($order_id) use ($operation_id) {
				return WCOS_Operation_Lock::is_current_owned_for($order_id, $operation_id);
			});
			if (count($owned_by_operation) === count($participant_ids)) {
				$lease = WCOS_Multi_Order_Lease::adopt_current($participant_ids, $operation_id);
			} elseif (empty($owned_by_operation)) {
				$lease = WCOS_Multi_Order_Lease::acquire($participant_ids, $operation_id);
			} else {
				throw new RuntimeException(__('Merge recovery found an incomplete same-operation participant lease set.', 'wc-order-splitter'));
			}
			if (!$lease instanceof WCOS_Multi_Order_Lease) {
				throw new RuntimeException(__('Merge recovery could not acquire both participant leases.', 'wc-order-splitter'));
			}
			$lease->assert_owned();
			$stock_guard = WCOS_Stock_Side_Effect_Guard::begin($operation_id);
			WCOS_Merge_Compensator::recover($fresh_source, $target, $fresh_record, $lease);
		} catch (Throwable $throwable) {
			$before_write_rejection = $throwable instanceof WCOS_Unexpected_Stock_Mutation_Exception
				&& !WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($throwable->get_events());
			if (!$throwable instanceof WCOS_Merge_Recovery_Interruption_Exception && !$before_write_rejection
				&& $lease instanceof WCOS_Multi_Order_Lease
				&& isset($fresh_source, $fresh_record)
				&& $fresh_source instanceof WC_Order && is_array($fresh_record)) {
				WCOS_Merge_Compensator::manual_reconciliation(
					$fresh_source,
					$target,
					$fresh_record,
					'merge_recovery_validation_failed'
				);
			}
			/* Lock contention before pair ownership remains safely retryable. */
			do_action('wcos_mutation_compensation_error', $throwable, $source, $operation_id, $record);
		} finally {
			if (false !== $stock_guard) {
				WCOS_Stock_Side_Effect_Guard::end($stock_guard);
			}
			if ($lease instanceof WCOS_Multi_Order_Lease) {
				$lease->release();
			}
			unset(self::$active[$key]);
		}
	}

	private static function discover_children(WC_Order $source, $operation_id) {
		$orders = WCOS_Order_Relation_Repository::find(
			array(
				array('key' => WCOS_Split_Order_Service::OPERATION_META, 'value' => $operation_id),
				array('key' => WCOS_Split_Order_Service::RELATION_PARENT_META, 'value' => $source->get_id(), 'type' => 'NUMERIC'),
			),
			-1
		);
		$children = array();
		foreach ($orders as $child) {
			$key = sanitize_key((string) $child->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true));
			if ('' === $key || isset($children[$key])) {
				throw new RuntimeException(__('The split recovery graph contains duplicate or missing child keys.', 'wc-order-splitter'));
			}
			$children[$key] = $child;
		}
		ksort($children, SORT_STRING);
		return $children;
	}
}
