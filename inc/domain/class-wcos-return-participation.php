<?php

defined('ABSPATH') || exit;

/**
 * Non-executable Return relation vocabulary for later mutation milestones.
 *
 * WOS-RETURN-002 only reads and validates these keys. It never persists them.
 */
final class WCOS_Return_Participation {

	const SCHEMA_VERSION = 1;
	const CHILD_ORIGINAL_META = '_wcos_returned_to_order_id';
	const CHILD_OPERATION_META = '_wcos_return_operation_id';
	const CHILD_PAIR_FINGERPRINT_META = '_wcos_return_pair_fingerprint';
	const ORIGINAL_CHILD_META = '_wcos_returned_child_order_id';
	const ORIGINAL_OPERATION_META = '_wcos_returned_child_operation_id';

	public static function inspect(WC_Order $order) {
		$values = array(
			'returned_to_order_id' => self::scalar_positive_int($order->get_meta(self::CHILD_ORIGINAL_META, true)),
			'operation_id' => self::scalar_key($order->get_meta(self::CHILD_OPERATION_META, true)),
			'pair_fingerprint' => self::scalar_fingerprint($order->get_meta(self::CHILD_PAIR_FINGERPRINT_META, true)),
		);
		$present = 0;
		foreach ($values as $value) {
			if (null !== $value) {
				$present++;
			}
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'present' => $present > 0,
			'complete' => 3 === $present,
			'values' => $values,
		);
	}

	public static function assert_not_terminal(WC_Order $child) {
		$state = self::inspect($child);
		if (!empty($state['present'])) {
			throw new RuntimeException(__('This Split child already carries Return participation evidence.', 'wc-order-splitter'));
		}
	}

	private static function scalar_positive_int($value) {
		if ('' === $value || null === $value) {
			return null;
		}
		if (is_int($value) && $value > 0) {
			return $value;
		}
		if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
			return (int) $value;
		}
		throw new RuntimeException(__('Return participation contains a malformed order ID.', 'wc-order-splitter'));
	}

	private static function scalar_key($value) {
		if ('' === $value || null === $value) {
			return null;
		}
		if (!is_string($value) || sanitize_key($value) !== $value || '' === $value) {
			throw new RuntimeException(__('Return participation contains a malformed operation ID.', 'wc-order-splitter'));
		}
		return $value;
	}

	private static function scalar_fingerprint($value) {
		if ('' === $value || null === $value) {
			return null;
		}
		if (!is_string($value) || 1 !== preg_match('/^[0-9a-f]{64}$/D', $value)) {
			throw new RuntimeException(__('Return participation contains a malformed fingerprint.', 'wc-order-splitter'));
		}
		return $value;
	}
}
