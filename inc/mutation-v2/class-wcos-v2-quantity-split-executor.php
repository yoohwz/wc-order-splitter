<?php
/**
 * Idempotent one-child quantity split executor with durable rollback context.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Orchestrates the first safe runtime adapter without registering an entry point.
 */
final class WCOS_V2_Quantity_Split_Executor {

	/**
	 * Execute a partial-line one-child quantity split.
	 *
	 * This method is intentionally not connected to AJAX, REST, cron, CLI, or
	 * admin actions. Callers must perform authorization before invoking it.
	 *
	 * @param int    $order_id             Source order ID.
	 * @param array  $requested_quantities Source item ID => quantity to move.
	 * @param string $operation_id         Stable client-generated idempotency ID.
	 * @return array|WP_Error
	 */
	public static function execute($order_id, array $requested_quantities, $operation_id) {
		$order_id     = absint($order_id);
		$operation_id = self::identifier($operation_id);

		if (!$order_id || strlen($operation_id) < 8) {
			return self::error('wcos_invalid_operation_request', __('The quantity split operation identity is invalid.', 'wc-order-splitter'));
		}

		$source = wc_get_order($order_id);

		if (!$source instanceof WC_Order) {
			return self::error('wcos_source_order_not_found', __('The source order was not found.', 'wc-order-splitter'));
		}

		$existing = WCOS_V2_Operation_Ledger::find($source, $operation_id);

		if (is_wp_error($existing)) {
			return $existing;
		}

		if (is_array($existing)) {
			return self::existing_result($source, $existing);
		}

		$preflight = WCOS_V2_Execution_Preflight::validate($source, $requested_quantities);

		if (is_wp_error($preflight)) {
			return $preflight;
		}

		try {
			$specification = WCOS_V2_Execution_Specification::build($preflight);
			self::assert_runtime_scope($specification);
		} catch (InvalidArgumentException $exception) {
			return self::error('wcos_quantity_specification_rejected', $exception->getMessage());
		} catch (LogicException $exception) {
			return self::error('wcos_quantity_specification_invariant', $exception->getMessage());
		}

		$lease_id = 'lease:' . wp_generate_uuid4();
		$lease    = WCOS_V2_Lease_Lock::acquire($order_id, $operation_id, $lease_id, 300);

		if (is_wp_error($lease)) {
			return $lease;
		}

		$notification_scope = null;
		$child              = null;
		$ledger_started     = false;
		$source_write_attempted = false;
		$committed          = false;
		$cleanup_warning    = null;

		try {
			/* A competing request may have committed between the first lookup and lease acquisition. */
			$source = WCOS_V2_Mutation_Failure::unwrap(wc_get_order($order_id));
			if (!$source instanceof WC_Order) {
				throw new WCOS_V2_Mutation_Failure('wcos_source_order_not_found', __('The source order disappeared before execution.', 'wc-order-splitter'));
			}

			$race_record = WCOS_V2_Operation_Ledger::find($source, $operation_id);
			WCOS_V2_Mutation_Failure::unwrap($race_record);
			if (is_array($race_record)) {
				return self::existing_result($source, $race_record);
			}

			$locked_preflight = WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Execution_Preflight::validate($source, $requested_quantities)
			);

			if (!hash_equals((string) $preflight['fingerprint'], (string) $locked_preflight['fingerprint'])) {
				throw new WCOS_V2_Mutation_Failure(
					'wcos_source_changed_after_preflight',
					__('The source order changed before the operation lease was acquired.', 'wc-order-splitter')
				);
			}

			$locked_specification = WCOS_V2_Execution_Specification::build($locked_preflight);
			self::assert_runtime_scope($locked_specification);

			if (!hash_equals((string) $specification['fingerprint'], (string) $locked_specification['fingerprint'])) {
				throw new WCOS_V2_Mutation_Failure(
					'wcos_specification_changed_after_lock',
					__('The quantity split specification changed after the operation was locked.', 'wc-order-splitter')
				);
			}

			$record = WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Operation_Ledger::begin(
					$source,
					$operation_id,
					$locked_preflight['fingerprint'],
					'quantity_split_one_child',
					$lease_id
				)
			);
			$ledger_started = true;

			if ('preparing' !== $record['status']) {
				return self::existing_result($source, $record);
			}

			WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Recovery_Context::prepare(
					$source,
					$operation_id,
					$lease_id,
					$locked_preflight['snapshot'],
					$locked_specification
				)
			);

			$notification_scope = new WCOS_V2_Notification_Scope($operation_id);
			$child = WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Order_Mutator::create_child($source, $locked_specification, $operation_id)
			);

			WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Recovery_Context::add_target($source, $operation_id, $lease_id, $child->get_id())
			);

			WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Relation_Repository::stage($source, $child, $operation_id, $lease_id, 'quantity_split')
			);

			$source_write_attempted = true;
			$source = WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Order_Mutator::update_source($source, $locked_specification)
			);

			WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Recovery_Context::advance($source, $operation_id, $lease_id, 'source_mutated')
			);

			$source = wc_get_order($order_id);
			$child  = wc_get_order($child->get_id());

			if (!$source instanceof WC_Order || !$child instanceof WC_Order) {
				throw new WCOS_V2_Mutation_Failure('wcos_mutated_order_reload_failed', __('A mutated order could not be reloaded for verification.', 'wc-order-splitter'));
			}

			WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Postcondition_Verifier::verify(
					$source,
					$child,
					$locked_preflight['snapshot'],
					$locked_specification,
					$operation_id
				)
			);

			WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Recovery_Context::advance($source, $operation_id, $lease_id, 'verified')
			);

			WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Relation_Repository::commit($source, $child, $operation_id, $lease_id)
			);

			$record = WCOS_V2_Mutation_Failure::unwrap(
				WCOS_V2_Operation_Ledger::commit($source, $operation_id, $lease_id, array($child->get_id()))
			);
			$committed = true;

			$cleanup = WCOS_V2_Recovery_Context::remove($source, $operation_id, $lease_id);
			if (is_wp_error($cleanup)) {
				$cleanup_warning = array(
					'code'    => $cleanup->get_error_code(),
					'message' => $cleanup->get_error_message(),
				);
			}

			return array(
				'success'                   => true,
				'idempotent'                => false,
				'operation_id'              => $operation_id,
				'source_order_id'           => $order_id,
				'target_order_ids'          => array($child->get_id()),
				'child_status'              => $child->get_status(),
				'desired_child_status'      => $locked_specification['desired_child_status'],
				'status_promotion_required' => $child->get_status() !== $locked_specification['desired_child_status'],
				'specification_fingerprint' => $locked_specification['fingerprint'],
				'cleanup_warning'            => $cleanup_warning,
				'record'                     => $record,
			);
		} catch (Throwable $throwable) {
			$failure = $throwable instanceof WCOS_V2_Mutation_Failure
				? $throwable
				: new WCOS_V2_Mutation_Failure('wcos_unexpected_mutation_failure', $throwable->getMessage(), array(), $throwable);

			if ($committed) {
				return self::error(
					'wcos_committed_with_cleanup_warning',
					__('The order split committed, but a post-commit cleanup step failed.', 'wc-order-splitter'),
					array(
						'operation_id' => $operation_id,
						'target_ids'   => $child instanceof WC_Order ? array($child->get_id()) : array(),
						'warning'      => $failure->get_error_key(),
					)
				);
			}

			$rollback = self::rollback(
				$order_id,
				$operation_id,
				$lease_id,
				$preflight['snapshot'],
				$child,
				$source_write_attempted,
				$ledger_started,
				$failure->get_error_key()
			);
			$data = $failure->get_error_data();
			$data['rollback'] = $rollback;

			return self::error($failure->get_error_key(), $failure->getMessage(), $data);
		} finally {
			if ($notification_scope instanceof WCOS_V2_Notification_Scope) {
				$notification_scope->close();
			}

			WCOS_V2_Lease_Lock::release($order_id, $operation_id, $lease_id);
		}
	}

	/**
	 * Return a terminal idempotency result without executing writes.
	 *
	 * @param WC_Order $source Source order.
	 * @param array    $record Operation record.
	 * @return array|WP_Error
	 */
	private static function existing_result(WC_Order $source, array $record) {
		if ('failed' === $record['status']) {
			return self::error(
				'wcos_operation_already_failed',
				__('This operation ID previously failed and cannot be executed again. Use a new operation ID.', 'wc-order-splitter'),
				array('record' => $record)
			);
		}

		if ('preparing' === $record['status']) {
			$context = WCOS_V2_Recovery_Context::find($source, $record['operation_id']);

			return self::error(
				'wcos_operation_recovery_required',
				__('This operation was interrupted and requires recovery before another mutation can run.', 'wc-order-splitter'),
				array(
					'record'   => $record,
					'recovery' => is_wp_error($context) ? null : $context,
				)
			);
		}

		if ('committed' !== $record['status'] || 1 !== count($record['target_ids'])) {
			return self::error('wcos_committed_operation_invalid', __('The committed operation record is invalid.', 'wc-order-splitter'));
		}

		$child_id = (int) reset($record['target_ids']);
		$child    = wc_get_order($child_id);
		$relation = WCOS_V2_Relation_Repository::find($source, $record['operation_id']);

		if (!$child instanceof WC_Order || is_wp_error($relation) || !is_array($relation)
			|| 'committed' !== $relation['status'] || (int) $relation['child_order_id'] !== $child_id
		) {
			return self::error(
				'wcos_committed_operation_inconsistent',
				__('The committed operation no longer has a valid child order relationship.', 'wc-order-splitter'),
				array('record' => $record)
			);
		}

		return array(
			'success'          => true,
			'idempotent'       => true,
			'operation_id'     => $record['operation_id'],
			'source_order_id'  => $source->get_id(),
			'target_order_ids' => array($child_id),
			'child_status'     => $child->get_status(),
			'record'           => $record,
		);
	}

	/**
	 * Reject unsupported runtime scope before any write occurs.
	 *
	 * @param array $specification Execution specification.
	 * @return void
	 */
	private static function assert_runtime_scope(array $specification) {
		if ('quantity_split_one_child' !== $specification['operation_type']) {
			throw new InvalidArgumentException('Only a one-child quantity split is supported.');
		}

		foreach ($specification['source']['lines'] as $line) {
			if ('remove' === $line['action']) {
				throw new InvalidArgumentException('The first runtime adapter supports partial line quantities only.');
			}
		}

		if ('pending' !== $specification['initial_child_status']) {
			throw new InvalidArgumentException('The first runtime adapter requires a neutral pending child status.');
		}
	}

	/**
	 * Perform compensating rollback while the exact source lease is still held.
	 *
	 * @param int           $order_id              Source order ID.
	 * @param string        $operation_id           Operation ID.
	 * @param string        $lease_id               Lease ID.
	 * @param array         $snapshot               Source snapshot.
	 * @param WC_Order|null $known_child            Known child order.
	 * @param bool          $source_write_attempted Whether source writes began.
	 * @param bool          $ledger_started         Whether a preparing record exists.
	 * @param string        $failure_code           Original failure code.
	 * @return array
	 */
	private static function rollback($order_id, $operation_id, $lease_id, array $snapshot, $known_child, $source_write_attempted, $ledger_started, $failure_code) {
		$errors  = array();
		$targets = self::find_targets($order_id, $operation_id, $known_child);
		$source  = wc_get_order($order_id);
		$source_restored = !$source_write_attempted;

		if (!$source instanceof WC_Order) {
			$errors[] = 'source_missing';
		} elseif ($source_write_attempted) {
			$restored = WCOS_V2_Order_Mutator::restore_source($source, $snapshot);

			if (is_wp_error($restored)) {
				$errors[] = $restored->get_error_code();
			} else {
				$source         = $restored;
				$source_restored = self::snapshot_matches($source, $snapshot);

				if (!$source_restored) {
					$errors[] = 'source_restore_verification_failed';
				}
			}
		}

		/* Never delete the child if source restoration is uncertain. */
		if ($source_restored && $source instanceof WC_Order) {
			foreach ($targets as $target) {
				$unlink = WCOS_V2_Relation_Repository::unlink($source, $target, $operation_id, $lease_id);

				if (is_wp_error($unlink)) {
					$errors[] = $unlink->get_error_code();
					continue;
				}

				$deleted = WCOS_V2_Order_Mutator::delete_child($target);
				if (is_wp_error($deleted)) {
					$errors[] = $deleted->get_error_code();
				}
			}
		}

		$source = wc_get_order($order_id);
		$rollback_complete = empty($errors) && $source instanceof WC_Order;

		if ($ledger_started && $source instanceof WC_Order) {
			$terminal_code = $rollback_complete ? $failure_code : 'wcos_rollback_incomplete';
			$failed = WCOS_V2_Operation_Ledger::fail($source, $operation_id, $lease_id, $terminal_code);

			if (is_wp_error($failed)) {
				$errors[] = $failed->get_error_code();
				$rollback_complete = false;
			}
		}

		if ($rollback_complete && $source instanceof WC_Order) {
			$removed = WCOS_V2_Recovery_Context::remove($source, $operation_id, $lease_id);
			if (is_wp_error($removed)) {
				$errors[] = $removed->get_error_code();
				$rollback_complete = false;
			}
		}

		return array(
			'complete'          => $rollback_complete,
			'source_restored'   => $source_restored,
			'target_order_ids'  => array_map(static function (WC_Order $order) {
				return $order->get_id();
			}, $targets),
			'errors'            => array_values(array_unique($errors)),
		);
	}

	/**
	 * Locate all target orders associated with an operation.
	 *
	 * @param int           $source_id   Source order ID.
	 * @param string        $operation_id Operation ID.
	 * @param WC_Order|null $known_child Known target.
	 * @return WC_Order[]
	 */
	private static function find_targets($source_id, $operation_id, $known_child) {
		$targets = array();

		if ($known_child instanceof WC_Order) {
			$targets[$known_child->get_id()] = $known_child;
		}

		$source = wc_get_order($source_id);
		if ($source instanceof WC_Order) {
			$context = WCOS_V2_Recovery_Context::find($source, $operation_id);
			if (is_array($context)) {
				foreach ($context['target_ids'] as $target_id) {
					$target = wc_get_order($target_id);
					if ($target instanceof WC_Order) {
						$targets[$target->get_id()] = $target;
					}
				}
			}
		}

		$query_targets = wc_get_orders(
			array(
				'limit'      => 10,
				'return'     => 'objects',
				'type'       => 'shop_order',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => '_wcos_v2_operation_id',
						'value' => self::identifier($operation_id),
					),
					array(
						'key'   => '_wcos_v2_source_order_id',
						'value' => absint($source_id),
					),
				),
			)
		);

		foreach ($query_targets as $target) {
			if ($target instanceof WC_Order && $target->get_id() !== (int) $source_id) {
				$targets[$target->get_id()] = $target;
			}
		}

		ksort($targets, SORT_NUMERIC);

		return array_values($targets);
	}

	/**
	 * Compare restored source snapshot sections.
	 *
	 * @param WC_Order $source   Restored source.
	 * @param array    $snapshot Original snapshot.
	 * @return bool
	 */
	private static function snapshot_matches(WC_Order $source, array $snapshot) {
		$current = WCOS_V2_Order_Snapshot::capture($source);
		$fields  = array(
			'order_id',
			'order_type',
			'status',
			'currency',
			'prices_include_tax',
			'customer_id',
			'transaction_id',
			'has_refunds',
			'order_stock_reduced',
			'amounts',
			'lines',
			'shipping_items',
			'fee_items',
			'coupon_items',
			'tax_items',
		);

		foreach ($fields as $field) {
			if (self::canonical_json($current[$field]) !== self::canonical_json($snapshot[$field])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Canonically encode a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function canonical_json($value) {
		$value = self::canonicalize($value);
		$json  = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		return is_string($json) ? $json : '';
	}

	/**
	 * Recursively sort associative arrays.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalize($value) {
		if (!is_array($value)) {
			return $value;
		}

		$result = array();
		foreach ($value as $key => $nested) {
			$result[$key] = self::canonicalize($nested);
		}

		if (array() !== $result && array_keys($result) !== range(0, count($result) - 1)) {
			ksort($result, SORT_STRING);
		}

		return $result;
	}

	/**
	 * Normalize an operation ID.
	 *
	 * @param mixed $value ID.
	 * @return string
	 */
	private static function identifier($value) {
		$value = strtolower(trim((string) $value));

		return preg_replace('/[^a-z0-9._:-]/', '', $value);
	}

	/**
	 * Create a stable executor error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $data    Error data.
	 * @return WP_Error
	 */
	private static function error($code, $message, array $data = array()) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message), $data);
	}
}
