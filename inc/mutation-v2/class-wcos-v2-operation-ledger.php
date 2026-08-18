<?php
/**
 * Lease-guarded persistent mutation operation ledger.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Persists strict operation records on the source order.
 */
final class WCOS_V2_Operation_Ledger {

	private const META_KEY    = '_wcos_v2_operation_ledger';
	private const MAX_RECORDS = 25;

	/**
	 * Find a normalized operation record without changing order state.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @return array|null|WP_Error
	 */
	public static function find(WC_Order $order, $operation_id) {
		$operation_id = self::identifier($operation_id);

		if ('' === $operation_id) {
			return self::error('wcos_invalid_operation_id', __('The order operation ID is invalid.', 'wc-order-splitter'));
		}

		$records = self::read($order);

		if (!isset($records[$operation_id])) {
			return null;
		}

		try {
			return WCOS_V2_Operation_Record::normalize($records[$operation_id]);
		} catch (InvalidArgumentException $exception) {
			return self::error('wcos_corrupt_operation_record', $exception->getMessage());
		}
	}

	/**
	 * Begin an operation while holding its exact execution lease.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $fingerprint  Complete commercial-state fingerprint.
	 * @param string   $type         Operation type.
	 * @param string   $lease_id     Exact execution lease ID.
	 * @return array|WP_Error
	 */
	public static function begin(WC_Order $order, $operation_id, $fingerprint, $type, $lease_id) {
		$lease_check = self::assert_lease($order, $operation_id, $lease_id);

		if (is_wp_error($lease_check)) {
			return $lease_check;
		}

		$operation_id = self::identifier($operation_id);
		$records      = self::read($order);

		try {
			if (isset($records[$operation_id])) {
				return WCOS_V2_Operation_Record::resume($records[$operation_id], $operation_id, $fingerprint, $type);
			}

			$record = WCOS_V2_Operation_Record::begin($operation_id, $fingerprint, $type);
		} catch (InvalidArgumentException $exception) {
			return self::error('wcos_invalid_operation_record', $exception->getMessage());
		} catch (LogicException $exception) {
			return self::error('wcos_idempotency_conflict', $exception->getMessage());
		}

		$records[$operation_id] = $record;
		self::write($order, $records);

		return $record;
	}

	/**
	 * Commit an operation while holding its exact lease.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Exact execution lease ID.
	 * @param int[]    $target_ids   Created target order IDs.
	 * @return array|WP_Error
	 */
	public static function commit(WC_Order $order, $operation_id, $lease_id, array $target_ids) {
		return self::transition($order, $operation_id, $lease_id, 'committed', $target_ids, '');
	}

	/**
	 * Fail an operation while holding its exact lease.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Exact execution lease ID.
	 * @param string   $error_code   Stable terminal error code.
	 * @return array|WP_Error
	 */
	public static function fail(WC_Order $order, $operation_id, $lease_id, $error_code) {
		return self::transition($order, $operation_id, $lease_id, 'failed', array(), $error_code);
	}

	/**
	 * Persist a terminal transition.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Exact lease ID.
	 * @param string   $status       Terminal status.
	 * @param int[]    $target_ids   Target order IDs.
	 * @param string   $error_code   Error code.
	 * @return array|WP_Error
	 */
	private static function transition(WC_Order $order, $operation_id, $lease_id, $status, array $target_ids, $error_code) {
		$lease_check = self::assert_lease($order, $operation_id, $lease_id);

		if (is_wp_error($lease_check)) {
			return $lease_check;
		}

		$operation_id = self::identifier($operation_id);
		$records      = self::read($order);

		if (!isset($records[$operation_id])) {
			return self::error('wcos_operation_not_found', __('The order operation journal entry was not found.', 'wc-order-splitter'));
		}

		try {
			if ('committed' === $status) {
				$record = WCOS_V2_Operation_Record::commit($records[$operation_id], $target_ids);
			} else {
				$record = WCOS_V2_Operation_Record::fail($records[$operation_id], $error_code);
			}
		} catch (InvalidArgumentException $exception) {
			return self::error('wcos_invalid_operation_transition', $exception->getMessage());
		} catch (LogicException $exception) {
			return self::error('wcos_terminal_operation_conflict', $exception->getMessage());
		}

		$records[$operation_id] = $record;
		self::write($order, $records);

		return $record;
	}

	/**
	 * Verify that the caller owns a live exact lease for this order and operation.
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
			return self::error('wcos_operation_lease_missing', __('A live order operation lease is required.', 'wc-order-splitter'));
		}

		if (!hash_equals((string) $lease['operation_id'], $operation_id) || !hash_equals((string) $lease['lease_id'], $lease_id)) {
			return self::error('wcos_operation_lease_mismatch', __('The order operation lease does not belong to this request.', 'wc-order-splitter'));
		}

		return true;
	}

	/**
	 * Read normalized ledger data.
	 *
	 * @param WC_Order $order Source order.
	 * @return array
	 */
	private static function read(WC_Order $order) {
		$records = $order->get_meta(self::META_KEY, true);

		return is_array($records) ? $records : array();
	}

	/**
	 * Persist a bounded ledger using WooCommerce CRUD.
	 *
	 * @param WC_Order $order   Source order.
	 * @param array    $records Ledger records.
	 * @return void
	 */
	private static function write(WC_Order $order, array $records) {
		if (count($records) > self::MAX_RECORDS) {
			uasort(
				$records,
				static function (array $left, array $right) {
					return (int) $left['updated_at'] <=> (int) $right['updated_at'];
				}
			);
			$records = array_slice($records, -self::MAX_RECORDS, null, true);
		}

		$order->update_meta_data(self::META_KEY, $records);
		$order->save_meta_data();
	}

	/**
	 * Normalize an operation or lease identifier.
	 *
	 * @param mixed $value Identifier.
	 * @return string
	 */
	private static function identifier($value) {
		$value = strtolower(trim((string) $value));

		return preg_replace('/[^a-z0-9._:-]/', '', $value);
	}

	/**
	 * Create a stable ledger error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
