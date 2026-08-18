<?php
/**
 * Persistent operation journal for idempotent order mutations.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Records preparing, committed and failed mutation operations on the source order.
 */
final class WCOS_V2_Operation_Journal {

	private const META_KEY    = '_wcos_v2_operation_journal';
	private const MAX_RECORDS = 20;

	/**
	 * Begin an operation or return its existing idempotent record.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation UUID.
	 * @param string   $fingerprint  Immutable request fingerprint.
	 * @param string   $type         Mutation type.
	 * @return array|WP_Error
	 */
	public static function begin(WC_Order $order, $operation_id, $fingerprint, $type) {
		$operation_id = sanitize_key($operation_id);
		$fingerprint  = sanitize_text_field($fingerprint);
		$type         = sanitize_key($type);

		if ('' === $operation_id || '' === $fingerprint || '' === $type) {
			return new WP_Error(
				'wcos_invalid_operation_journal',
				esc_html__('The order operation journal data is invalid.', 'wc-order-splitter')
			);
		}

		$journal = self::read($order);

		if (isset($journal[$operation_id])) {
			$record = $journal[$operation_id];

			if (!isset($record['fingerprint']) || !hash_equals((string) $record['fingerprint'], $fingerprint)) {
				return new WP_Error(
					'wcos_idempotency_conflict',
					esc_html__('This operation token was already used with different order data.', 'wc-order-splitter')
				);
			}

			return $record;
		}

		$record = array(
			'operation_id' => $operation_id,
			'type'         => $type,
			'fingerprint'  => $fingerprint,
			'status'       => 'preparing',
			'target_ids'   => array(),
			'error_code'   => '',
			'created_at'   => time(),
			'updated_at'   => time(),
		);

		$journal[$operation_id] = $record;
		self::write($order, $journal);

		return $record;
	}

	/**
	 * Mark an operation as committed.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation UUID.
	 * @param int[]    $target_ids   Created target orders.
	 * @return array|WP_Error
	 */
	public static function commit(WC_Order $order, $operation_id, array $target_ids) {
		return self::transition($order, $operation_id, 'committed', $target_ids, '');
	}

	/**
	 * Mark an operation as failed.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation UUID.
	 * @param string   $error_code   Stable error code.
	 * @return array|WP_Error
	 */
	public static function fail(WC_Order $order, $operation_id, $error_code) {
		return self::transition($order, $operation_id, 'failed', array(), sanitize_key($error_code));
	}

	/**
	 * Find an operation record.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation UUID.
	 * @return array|null
	 */
	public static function find(WC_Order $order, $operation_id) {
		$journal      = self::read($order);
		$operation_id = sanitize_key($operation_id);

		return isset($journal[$operation_id]) ? $journal[$operation_id] : null;
	}

	/**
	 * Transition a journal record.
	 *
	 * @param WC_Order $order        Source order.
	 * @param string   $operation_id Operation UUID.
	 * @param string   $status       Target status.
	 * @param int[]    $target_ids   Created target orders.
	 * @param string   $error_code   Stable error code.
	 * @return array|WP_Error
	 */
	private static function transition(WC_Order $order, $operation_id, $status, array $target_ids, $error_code) {
		$operation_id = sanitize_key($operation_id);
		$journal      = self::read($order);

		if (!isset($journal[$operation_id])) {
			return new WP_Error(
				'wcos_operation_not_found',
				esc_html__('The order operation journal entry was not found.', 'wc-order-splitter')
			);
		}

		$target_ids = array_values(array_unique(array_filter(array_map('absint', $target_ids))));

		$journal[$operation_id]['status']     = sanitize_key($status);
		$journal[$operation_id]['target_ids'] = $target_ids;
		$journal[$operation_id]['error_code'] = sanitize_key($error_code);
		$journal[$operation_id]['updated_at'] = time();

		self::write($order, $journal);

		return $journal[$operation_id];
	}

	/**
	 * Read and normalize journal data.
	 *
	 * @param WC_Order $order Source order.
	 * @return array
	 */
	private static function read(WC_Order $order) {
		$journal = $order->get_meta(self::META_KEY, true);

		return is_array($journal) ? $journal : array();
	}

	/**
	 * Persist a capped journal without saving unrelated order data.
	 *
	 * @param WC_Order $order   Source order.
	 * @param array    $journal Journal records.
	 * @return void
	 */
	private static function write(WC_Order $order, array $journal) {
		if (count($journal) > self::MAX_RECORDS) {
			$journal = array_slice($journal, -self::MAX_RECORDS, null, true);
		}

		$order->update_meta_data(self::META_KEY, $journal);
		$order->save_meta_data();
	}
}
