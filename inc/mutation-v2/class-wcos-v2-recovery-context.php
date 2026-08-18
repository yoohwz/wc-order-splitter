<?php
/**
 * Lease-guarded durable recovery contexts for order mutations.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Persists source snapshots, write specifications, targets, and execution phase.
 */
final class WCOS_V2_Recovery_Context {

	private const META_KEY     = '_wcos_v2_recovery_contexts';
	private const MAX_CONTEXTS = 5;

	/**
	 * Create or idempotently resume a prepared recovery context.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Exact request lease ID.
	 * @param array    $snapshot     Immutable pre-operation source snapshot.
	 * @param array    $specification Complete write specification.
	 * @return array|WP_Error
	 */
	public static function prepare(WC_Order $order, $operation_id, $lease_id, array $snapshot, array $specification) {
		$lease_check = self::assert_lease($order, $operation_id, $lease_id);

		if (is_wp_error($lease_check)) {
			return $lease_check;
		}

		$operation_id = self::identifier($operation_id);
		$contexts     = self::read_all($order);
		$fingerprint  = isset($specification['fingerprint']) ? self::fingerprint($specification['fingerprint']) : '';

		if ('' === $fingerprint || empty($snapshot) || empty($specification)) {
			return self::error('wcos_invalid_recovery_context', __('The order recovery context is incomplete.', 'wc-order-splitter'));
		}

		if (isset($contexts[$operation_id])) {
			$existing = self::normalize($contexts[$operation_id]);

			if (is_wp_error($existing)) {
				return $existing;
			}

			if (!hash_equals($existing['specification_fingerprint'], $fingerprint)) {
				return self::error('wcos_recovery_context_conflict', __('This operation ID already has a different recovery context.', 'wc-order-splitter'));
			}

			return $existing;
		}

		$now = time();
		$context = array(
			'schema_version'             => 1,
			'operation_id'               => $operation_id,
			'specification_fingerprint'  => $fingerprint,
			'phase'                      => 'prepared',
			'target_ids'                 => array(),
			'source_snapshot'            => $snapshot,
			'write_specification'        => $specification,
			'created_at'                 => $now,
			'updated_at'                 => $now,
		);

		$contexts[$operation_id] = $context;
		self::write_all($order, $contexts);

		return $context;
	}

	/**
	 * Record a newly created target order before the source is changed.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Lease ID.
	 * @param int      $target_id    Target order ID.
	 * @return array|WP_Error
	 */
	public static function add_target(WC_Order $order, $operation_id, $lease_id, $target_id) {
		$target_id = absint($target_id);

		if (!$target_id) {
			return self::error('wcos_invalid_recovery_target', __('The recovery target order ID is invalid.', 'wc-order-splitter'));
		}

		return self::mutate(
			$order,
			$operation_id,
			$lease_id,
			static function (array $context) use ($target_id) {
				if (!in_array($context['phase'], array('prepared', 'target_created'), true)) {
					throw new LogicException('A target can only be recorded before the source order is mutated.');
				}

				$context['target_ids'][] = $target_id;
				$context['target_ids']   = array_values(array_unique(array_map('intval', $context['target_ids'])));
				sort($context['target_ids'], SORT_NUMERIC);
				$context['phase'] = 'target_created';

				return $context;
			}
		);
	}

	/**
	 * Advance through the only permitted execution phases.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Lease ID.
	 * @param string   $next_phase   source_mutated or verified.
	 * @return array|WP_Error
	 */
	public static function advance(WC_Order $order, $operation_id, $lease_id, $next_phase) {
		$next_phase = self::identifier($next_phase);

		return self::mutate(
			$order,
			$operation_id,
			$lease_id,
			static function (array $context) use ($next_phase) {
				$transitions = array(
					'target_created' => 'source_mutated',
					'source_mutated' => 'verified',
				);

				if (!isset($transitions[$context['phase']]) || $transitions[$context['phase']] !== $next_phase) {
					throw new LogicException('The recovery context phase transition is invalid.');
				}

				$context['phase'] = $next_phase;

				return $context;
			}
		);
	}

	/**
	 * Find a recovery context without changing order state.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @return array|null|WP_Error
	 */
	public static function find(WC_Order $order, $operation_id) {
		$operation_id = self::identifier($operation_id);
		$contexts     = self::read_all($order);

		if (!isset($contexts[$operation_id])) {
			return null;
		}

		return self::normalize($contexts[$operation_id]);
	}

	/**
	 * Remove a completed or rolled-back recovery context under its exact lease.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Lease ID.
	 * @return true|WP_Error
	 */
	public static function remove(WC_Order $order, $operation_id, $lease_id) {
		$lease_check = self::assert_lease($order, $operation_id, $lease_id);

		if (is_wp_error($lease_check)) {
			return $lease_check;
		}

		$operation_id = self::identifier($operation_id);
		$contexts     = self::read_all($order);

		if (!isset($contexts[$operation_id])) {
			return true;
		}

		unset($contexts[$operation_id]);
		self::write_all($order, $contexts);

		return true;
	}

	/**
	 * Mutate one context under the exact operation lease.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Lease ID.
	 * @param callable $callback     Context mutator.
	 * @return array|WP_Error
	 */
	private static function mutate(WC_Order $order, $operation_id, $lease_id, callable $callback) {
		$lease_check = self::assert_lease($order, $operation_id, $lease_id);

		if (is_wp_error($lease_check)) {
			return $lease_check;
		}

		$operation_id = self::identifier($operation_id);
		$contexts     = self::read_all($order);

		if (!isset($contexts[$operation_id])) {
			return self::error('wcos_recovery_context_not_found', __('The order recovery context was not found.', 'wc-order-splitter'));
		}

		$context = self::normalize($contexts[$operation_id]);

		if (is_wp_error($context)) {
			return $context;
		}

		try {
			$context = $callback($context);
		} catch (LogicException $exception) {
			return self::error('wcos_recovery_phase_conflict', $exception->getMessage());
		}

		$context['updated_at']    = time();
		$contexts[$operation_id] = $context;
		self::write_all($order, $contexts);

		return $context;
	}

	/**
	 * Validate a persisted context.
	 *
	 * @param array $context Context.
	 * @return array|WP_Error
	 */
	private static function normalize(array $context) {
		$required = array(
			'schema_version',
			'operation_id',
			'specification_fingerprint',
			'phase',
			'target_ids',
			'source_snapshot',
			'write_specification',
			'created_at',
			'updated_at',
		);

		foreach ($required as $field) {
			if (!array_key_exists($field, $context)) {
				return self::error('wcos_corrupt_recovery_context', __('A stored order recovery context is incomplete.', 'wc-order-splitter'));
			}
		}

		$phase = self::identifier($context['phase']);

		if (1 !== (int) $context['schema_version'] || !in_array($phase, array('prepared', 'target_created', 'source_mutated', 'verified'), true)) {
			return self::error('wcos_corrupt_recovery_context', __('A stored order recovery context has an unsupported state.', 'wc-order-splitter'));
		}

		$target_ids = array_values(array_unique(array_filter(array_map('intval', (array) $context['target_ids']), static function ($value) {
			return $value > 0;
		})));
		sort($target_ids, SORT_NUMERIC);

		if ('prepared' !== $phase && empty($target_ids)) {
			return self::error('wcos_corrupt_recovery_context', __('A stored order recovery context is missing its target order.', 'wc-order-splitter'));
		}

		return array(
			'schema_version'            => 1,
			'operation_id'              => self::identifier($context['operation_id']),
			'specification_fingerprint' => self::fingerprint($context['specification_fingerprint']),
			'phase'                     => $phase,
			'target_ids'                => $target_ids,
			'source_snapshot'           => (array) $context['source_snapshot'],
			'write_specification'       => (array) $context['write_specification'],
			'created_at'                => (int) $context['created_at'],
			'updated_at'                => (int) $context['updated_at'],
		);
	}

	/**
	 * Verify an exact live operation lease.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Lease ID.
	 * @return true|WP_Error
	 */
	private static function assert_lease(WC_Order $order, $operation_id, $lease_id) {
		$operation_id = self::identifier($operation_id);
		$lease_id     = self::identifier($lease_id);
		$lease        = WCOS_V2_Lease_Lock::inspect($order->get_id());

		if (null === $lease || (int) $lease['expires_at'] < time()) {
			return self::error('wcos_recovery_lease_missing', __('A live order operation lease is required for recovery data.', 'wc-order-splitter'));
		}

		if (!hash_equals((string) $lease['operation_id'], $operation_id) || !hash_equals((string) $lease['lease_id'], $lease_id)) {
			return self::error('wcos_recovery_lease_mismatch', __('The recovery context lease does not belong to this request.', 'wc-order-splitter'));
		}

		return true;
	}

	/**
	 * Read all contexts.
	 *
	 * @param WC_Order $order Source order.
	 * @return array
	 */
	private static function read_all(WC_Order $order) {
		$contexts = $order->get_meta(self::META_KEY, true);

		return is_array($contexts) ? $contexts : array();
	}

	/**
	 * Persist a bounded context collection.
	 *
	 * @param WC_Order $order    Source order.
	 * @param array    $contexts Contexts.
	 * @return void
	 */
	private static function write_all(WC_Order $order, array $contexts) {
		if (count($contexts) > self::MAX_CONTEXTS) {
			uasort(
				$contexts,
				static function (array $left, array $right) {
					return (int) $left['updated_at'] <=> (int) $right['updated_at'];
				}
			);
			$contexts = array_slice($contexts, -self::MAX_CONTEXTS, null, true);
		}

		if (empty($contexts)) {
			$order->delete_meta_data(self::META_KEY);
		} else {
			$order->update_meta_data(self::META_KEY, $contexts);
		}

		$order->save_meta_data();
	}

	/**
	 * Normalize an ID.
	 *
	 * @param mixed $value ID.
	 * @return string
	 */
	private static function identifier($value) {
		$value = strtolower(trim((string) $value));

		return preg_replace('/[^a-z0-9._:-]/', '', $value);
	}

	/**
	 * Normalize a SHA-256 fingerprint.
	 *
	 * @param mixed $value Fingerprint.
	 * @return string
	 */
	private static function fingerprint($value) {
		$value = strtolower(trim((string) $value));

		return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
	}

	/**
	 * Create a stable recovery error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
