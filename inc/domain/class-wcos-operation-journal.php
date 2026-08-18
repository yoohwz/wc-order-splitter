<?php

defined('ABSPATH') || exit;

/**
 * Stores a bounded operation journal on the source order for recovery/audit.
 */
final class WCOS_Operation_Journal {

	const META_KEY = '_wcos_operation_journal';
	const MAX_ENTRIES = 20;

	public static function start(WC_Order $order, $operation_id, $type, array $context = array()) {
		$operation_id = sanitize_key($operation_id);
		$existing = self::get($order, $operation_id);
		if (is_array($existing)) {
			return false;
		}

		return self::write($order, array(
			'operation_id' => $operation_id,
			'type' => sanitize_key($type),
			'status' => 'started',
			'started_at' => gmdate('c'),
			'completed_at' => null,
			'context' => $context,
		));
	}

	public static function complete(WC_Order $order, $operation_id, array $context = array()) {
		return self::transition($order, $operation_id, 'completed', $context);
	}

	public static function fail(WC_Order $order, $operation_id, array $context = array()) {
		return self::transition($order, $operation_id, 'failed', $context);
	}

	public static function get(WC_Order $order, $operation_id) {
		$operation_id = sanitize_key($operation_id);
		foreach (self::entries($order) as $entry) {
			if (isset($entry['operation_id']) && $entry['operation_id'] === $operation_id) {
				return $entry;
			}
		}
		return null;
	}

	public static function has_completed(WC_Order $order, $operation_id) {
		$entry = self::get($order, $operation_id);
		return is_array($entry) && isset($entry['status']) && 'completed' === $entry['status'];
	}

	private static function transition(WC_Order $order, $operation_id, $status, array $context) {
		$operation_id = sanitize_key($operation_id);
		$entries = self::entries($order);
		$found = false;
		foreach ($entries as &$entry) {
			if (isset($entry['operation_id']) && $entry['operation_id'] === $operation_id) {
				$entry['status'] = $status;
				$entry['completed_at'] = gmdate('c');
				$entry['context'] = array_merge(isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : array(), $context);
				$found = true;
				break;
			}
		}
		unset($entry);

		if (!$found) {
			return false;
		}

		$order->update_meta_data(self::META_KEY, array_slice($entries, -self::MAX_ENTRIES));
		$order->save_meta_data();
		return true;
	}

	private static function write(WC_Order $order, array $entry) {
		$entries = self::entries($order);
		$entries[] = $entry;
		$order->update_meta_data(self::META_KEY, array_slice($entries, -self::MAX_ENTRIES));
		$order->save_meta_data();
		return true;
	}

	private static function entries(WC_Order $order) {
		$entries = $order->get_meta(self::META_KEY, true);
		return is_array($entries) ? $entries : array();
	}
}
