<?php
/**
 * Lease-guarded recovery for interrupted one-child quantity split operations.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Inspects and safely rolls back non-committed quantity split operations.
 */
final class WCOS_V2_Interrupted_Operation_Recovery {

	/**
	 * Inspect a quantity split operation without changing order state.
	 *
	 * @param int    $order_id     Source order ID.
	 * @param string $operation_id Request-bound operation ID.
	 * @return array|WP_Error
	 */
	public static function inspect($order_id, $operation_id) {
		$operation_id = self::operation_id($operation_id);
		$order_id     = absint($order_id);

		if (!$order_id || is_wp_error($operation_id)) {
			return is_wp_error($operation_id)
				? $operation_id
				: self::error('wcos_recovery_order_invalid', __('The recovery source order ID is invalid.', 'wc-order-splitter'));
		}

		$source = wc_get_order($order_id);

		if (!$source instanceof WC_Order) {
			return self::error('wcos_recovery_source_missing', __('The recovery source order was not found.', 'wc-order-splitter'));
		}

		$record   = WCOS_V2_Operation_Ledger::find($source, $operation_id);
		$context  = WCOS_V2_Recovery_Context::find($source, $operation_id);
		$relation = WCOS_V2_Relation_Repository::find($source, $operation_id);

		if (is_wp_error($record)) {
			return $record;
		}
		if (is_wp_error($context)) {
			return $context;
		}
		if (is_wp_error($relation)) {
			return $relation;
		}

		$targets = self::find_targets($source, $operation_id, is_array($context) ? $context : array());

		return array(
			'operation_id'      => $operation_id,
			'source_order_id'   => $order_id,
			'record'            => $record,
			'recovery_context'  => $context,
			'relation'          => $relation,
			'target_order_ids'  => array_keys($targets),
			'lease'             => WCOS_V2_Lease_Lock::inspect($order_id),
		);
	}

	/**
	 * Roll back an interrupted operation to the immutable source snapshot.
	 *
	 * Committed operations are never reversed by this service. They require a
	 * separately authorized business reversal workflow.
	 *
	 * @param int    $order_id     Source order ID.
	 * @param string $operation_id Request-bound operation ID.
	 * @return array|WP_Error
	 */
	public static function rollback($order_id, $operation_id) {
		$operation_id = self::operation_id($operation_id);
		$order_id     = absint($order_id);

		if (!$order_id || is_wp_error($operation_id)) {
			return is_wp_error($operation_id)
				? $operation_id
				: self::error('wcos_recovery_order_invalid', __('The recovery source order ID is invalid.', 'wc-order-splitter'));
		}

		$source = wc_get_order($order_id);

		if (!$source instanceof WC_Order) {
			return self::error('wcos_recovery_source_missing', __('The recovery source order was not found.', 'wc-order-splitter'));
		}

		$record = WCOS_V2_Operation_Ledger::find($source, $operation_id);

		if (is_wp_error($record)) {
			return $record;
		}

		if (!is_array($record)) {
			return self::error('wcos_recovery_record_missing', __('No operation journal record exists for this recovery request.', 'wc-order-splitter'));
		}

		if ('committed' === $record['status']) {
			return self::error('wcos_recovery_committed_forbidden', __('A committed split operation cannot be rolled back by interrupted-operation recovery.', 'wc-order-splitter'));
		}

		$context = WCOS_V2_Recovery_Context::find($source, $operation_id);

		if (is_wp_error($context)) {
			return $context;
		}

		if ('failed' === $record['status'] && null === $context) {
			return array(
				'success'          => true,
				'idempotent'       => true,
				'operation_id'     => $operation_id,
				'source_order_id'  => $order_id,
				'target_order_ids' => array(),
				'record'           => $record,
			);
		}

		$lease_id = 'recovery:' . wp_generate_uuid4();
		$lease    = WCOS_V2_Lease_Lock::acquire($order_id, $operation_id, $lease_id, 300);

		if (is_wp_error($lease)) {
			return $lease;
		}

		try {
			$source = wc_get_order($order_id);

			if (!$source instanceof WC_Order) {
				return self::error('wcos_recovery_source_missing', __('The recovery source order disappeared after the lease was acquired.', 'wc-order-splitter'));
			}

			$record  = WCOS_V2_Operation_Ledger::find($source, $operation_id);
			$context = WCOS_V2_Recovery_Context::find($source, $operation_id);

			if (is_wp_error($record)) {
				return $record;
			}
			if (is_wp_error($context)) {
				return $context;
			}
			if (!is_array($record)) {
				return self::error('wcos_recovery_record_missing', __('The operation journal record disappeared after the lease was acquired.', 'wc-order-splitter'));
			}
			if ('committed' === $record['status']) {
				return self::error('wcos_recovery_committed_forbidden', __('The operation committed before recovery acquired its lease.', 'wc-order-splitter'));
			}
			if (null === $context) {
				return 'failed' === $record['status']
					? array(
						'success'          => true,
						'idempotent'       => true,
						'operation_id'     => $operation_id,
						'source_order_id'  => $order_id,
						'target_order_ids' => array(),
						'record'           => $record,
					)
					: self::error('wcos_recovery_context_missing', __('A preparing operation is missing its durable recovery context.', 'wc-order-splitter'));
			}

			$snapshot      = (array) $context['source_snapshot'];
			$specification = (array) $context['write_specification'];

			if (empty($snapshot) || empty($specification['source_fingerprint']) || empty($specification['fingerprint'])) {
				return self::error('wcos_recovery_context_invalid', __('The durable recovery context is incomplete.', 'wc-order-splitter'));
			}

			if (!hash_equals((string) $record['fingerprint'], (string) $specification['source_fingerprint'])) {
				return self::error('wcos_recovery_fingerprint_conflict', __('The operation journal and recovery specification fingerprints do not match.', 'wc-order-splitter'));
			}

			$relation = WCOS_V2_Relation_Repository::find($source, $operation_id);

			if (is_wp_error($relation)) {
				return $relation;
			}

			$targets = self::find_targets($source, $operation_id, $context);

			if (count($targets) > 1) {
				return self::error('wcos_recovery_multiple_targets', __('The one-child recovery context resolved to multiple target orders.', 'wc-order-splitter'));
			}

			$expected_target_ids = array_values(array_unique(array_map('absint', (array) $context['target_ids'])));
			$missing_target_ids  = array_values(array_diff($expected_target_ids, array_keys($targets)));

			$source_original = WCOS_V2_Snapshot_Comparator::verify($source, $snapshot);
			$source_planned  = WCOS_V2_Specification_Comparator::verify_source($source, $snapshot, $specification);
			$matches_original = true === $source_original;
			$matches_planned  = true === $source_planned;

			if (!$matches_original && !$matches_planned) {
				return self::error(
					'wcos_recovery_source_diverged',
					__('The source order matches neither its original snapshot nor the planned mutated state. Automatic recovery stopped.', 'wc-order-splitter'),
					array(
						'original_error' => is_wp_error($source_original) ? $source_original->get_error_code() : '',
						'planned_error'  => is_wp_error($source_planned) ? $source_planned->get_error_code() : '',
					)
				);
			}

			if (!empty($missing_target_ids)) {
				/*
				 * A previous recovery may have deleted the target and crashed before
				 * terminal journal cleanup. That state is safe only when the source
				 * is fully restored and no structured relation remains.
				 */
				if (!$matches_original || null !== $relation) {
					return self::error(
						'wcos_recovery_target_missing',
						__('A recorded split target is missing while source or relation state is not fully restored.', 'wc-order-splitter'),
						array('missing_target_ids' => $missing_target_ids)
					);
				}
			}

			foreach ($targets as $target) {
				if ('yes' === (string) $target->get_meta('_wcos_v2_quarantined', true)) {
					$target_check = WCOS_V2_Child_Quarantine::verify($target, $operation_id);
				} else {
					$target_check = WCOS_V2_Specification_Comparator::verify_child($target, $specification, $operation_id);
				}

				if (is_wp_error($target_check)) {
					return self::error(
						'wcos_recovery_target_diverged',
						__('The split target changed after interruption. Automatic recovery stopped.', 'wc-order-splitter'),
						array('target_id' => $target->get_id(), 'reason' => $target_check->get_error_code())
					);
				}
			}

			if ($matches_planned) {
				$source = WCOS_V2_Order_Mutator::restore_source($source, $snapshot);

				if (is_wp_error($source)) {
					return $source;
				}

				$source_original = WCOS_V2_Snapshot_Comparator::verify($source, $snapshot);

				if (is_wp_error($source_original)) {
					return self::error(
						'wcos_recovery_source_restore_unverified',
						__('The source order rollback could not be verified.', 'wc-order-splitter'),
						array('reason' => $source_original->get_error_code())
					);
				}
			}

			$quarantined_ids = array();

			foreach ($targets as $target_id => $target) {
				if ('yes' !== (string) $target->get_meta('_wcos_v2_quarantined', true)) {
					$quarantined = WCOS_V2_Child_Quarantine::apply(
						$target,
						$specification,
						$operation_id,
						'interrupted_operation_rollback'
					);

					if (is_wp_error($quarantined)) {
						$compensation = self::compensate_quarantine_failure(
							$source,
							$target,
							$snapshot,
							$specification,
							$operation_id
						);

						return self::error(
							'wcos_recovery_quarantine_failed',
							__('Child stock ownership could not be quarantined; recovery applied the available compensation and stopped.', 'wc-order-splitter'),
							array(
								'target_id'    => $target->get_id(),
								'reason'       => $quarantined->get_error_code(),
								'compensation' => $compensation,
							)
						);
					}

					$target = $quarantined;
					$targets[$target_id] = $target;
				}

				$quarantined_ids[] = $target->get_id();

				$unlink = WCOS_V2_Relation_Repository::unlink($source, $target, $operation_id, $lease_id);

				if (is_wp_error($unlink)) {
					return self::error(
						'wcos_recovery_relation_cleanup_failed',
						__('The target is stock-safe but its reciprocal relation could not be removed.', 'wc-order-splitter'),
						array('target_id' => $target->get_id(), 'reason' => $unlink->get_error_code(), 'quarantined' => true)
					);
				}

				$deleted = WCOS_V2_Order_Mutator::delete_child($target);

				if (is_wp_error($deleted)) {
					return self::error(
						'wcos_recovery_target_delete_failed',
						__('The target is stock-safe but could not be deleted. The recovery context was retained.', 'wc-order-splitter'),
						array('target_id' => $target->get_id(), 'reason' => $deleted->get_error_code(), 'quarantined' => true)
					);
				}
			}

			$source = wc_get_order($order_id);

			if (!$source instanceof WC_Order) {
				return self::error('wcos_recovery_source_missing', __('The restored source order disappeared before terminal cleanup.', 'wc-order-splitter'));
			}

			$source_check = WCOS_V2_Snapshot_Comparator::verify($source, $snapshot);

			if (is_wp_error($source_check)) {
				return self::error('wcos_recovery_terminal_source_mismatch', __('The restored source changed before terminal recovery cleanup.', 'wc-order-splitter'));
			}

			$residual_targets = self::find_targets($source, $operation_id, $context);

			if (!empty($residual_targets)) {
				return self::error(
					'wcos_recovery_residual_target',
					__('A split target still exists after recovery cleanup.', 'wc-order-splitter'),
					array('target_order_ids' => array_keys($residual_targets))
				);
			}

			if ('preparing' === $record['status']) {
				$record = WCOS_V2_Operation_Ledger::fail(
					$source,
					$operation_id,
					$lease_id,
					'wcos_interrupted_operation_rolled_back'
				);

				if (is_wp_error($record)) {
					return $record;
				}
			}

			$removed = WCOS_V2_Recovery_Context::remove($source, $operation_id, $lease_id);

			if (is_wp_error($removed)) {
				return $removed;
			}

			return array(
				'success'                 => true,
				'idempotent'              => false,
				'operation_id'            => $operation_id,
				'source_order_id'         => $order_id,
				'deleted_target_ids'      => array_keys($targets),
				'quarantined_target_ids'  => $quarantined_ids,
				'source_restored'         => true,
				'record'                  => $record,
			);
		} finally {
			WCOS_V2_Lease_Lock::release($order_id, $operation_id, $lease_id);
		}
	}

	/**
	 * Restore child ownership and return source to planned state after a failed
	 * quarantine, so stock markers remain conserved while manual review occurs.
	 *
	 * @param WC_Order $source        Source order currently restored to snapshot.
	 * @param WC_Order $target        Partially quarantined target.
	 * @param array    $snapshot      Original snapshot.
	 * @param array    $specification Execution specification.
	 * @param string   $operation_id  Operation ID.
	 * @return array
	 */
	private static function compensate_quarantine_failure(WC_Order $source, WC_Order $target, array $snapshot, array $specification, $operation_id) {
		$result = array(
			'child_stock_restored' => false,
			'source_plan_restored' => false,
			'errors'               => array(),
		);

		$target = WCOS_V2_Child_Stock_Ownership::restore($target, $specification, $operation_id);

		if (is_wp_error($target)) {
			$result['errors'][] = $target->get_error_code();
			return $result;
		}

		$result['child_stock_restored'] = true;
		$source = WCOS_V2_Order_Mutator::update_source($source, $specification);

		if (is_wp_error($source)) {
			$result['errors'][] = $source->get_error_code();
			return $result;
		}

		$source_check = WCOS_V2_Specification_Comparator::verify_source($source, $snapshot, $specification);

		if (is_wp_error($source_check)) {
			$result['errors'][] = $source_check->get_error_code();
			return $result;
		}

		$result['source_plan_restored'] = true;

		return $result;
	}

	/**
	 * Locate exact operation targets from durable IDs and operation metadata.
	 *
	 * @param WC_Order $source       Source order.
	 * @param string   $operation_id Operation ID.
	 * @param array    $context      Recovery context.
	 * @return WC_Order[] Keyed by target order ID.
	 */
	private static function find_targets(WC_Order $source, $operation_id, array $context) {
		$targets = array();

		foreach ((array) (isset($context['target_ids']) ? $context['target_ids'] : array()) as $target_id) {
			$target = wc_get_order(absint($target_id));

			if ($target instanceof WC_Order && $target->get_id() !== $source->get_id()) {
				$targets[$target->get_id()] = $target;
			}
		}

		$queried = wc_get_orders(
			array(
				'limit'      => 10,
				'return'     => 'objects',
				'type'       => 'shop_order',
				'status'     => array_keys(wc_get_order_statuses()),
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => '_wcos_v2_operation_id',
						'value' => $operation_id,
					),
					array(
						'key'   => '_wcos_v2_source_order_id',
						'value' => $source->get_id(),
					),
				),
			)
		);

		foreach ($queried as $target) {
			if ($target instanceof WC_Order && $target->get_id() !== $source->get_id()) {
				$targets[$target->get_id()] = $target;
			}
		}

		ksort($targets, SORT_NUMERIC);

		return $targets;
	}

	/**
	 * Validate a request-bound quantity split operation ID.
	 *
	 * @param mixed $value Operation ID.
	 * @return string|WP_Error
	 */
	private static function operation_id($value) {
		$value = strtolower(trim((string) $value));

		if (!preg_match('/^qsplit\.[1-9][0-9]*\.[a-f0-9]{64}\.[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value)) {
			return self::error('wcos_recovery_operation_id_invalid', __('The recovery operation ID is invalid.', 'wc-order-splitter'));
		}

		return $value;
	}

	/**
	 * Create a stable recovery error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $data    Recovery data.
	 * @return WP_Error
	 */
	private static function error($code, $message, array $data = array()) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message), $data);
	}
}
