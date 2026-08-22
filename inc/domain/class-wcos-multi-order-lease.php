<?php

defined('ABSPATH') || exit;

/**
 * Deterministic all-or-nothing composition of existing order-operation leases.
 *
 * This class owns no lock storage. Every participant is acquired, refreshed,
 * asserted, and released exclusively through WCOS_Operation_Lock.
 */
final class WCOS_Multi_Order_Lease {

	private $operation_id;
	private $leases;
	private $released = false;
	private $release_owned;

	private function __construct($operation_id, array $leases, $release_owned = true) {
		$this->operation_id = sanitize_key((string) $operation_id);
		$this->leases = $leases;
		$this->release_owned = (bool) $release_owned;
	}

	public static function acquire(array $order_ids, $operation_id, $ttl = WCOS_Operation_Lock::DEFAULT_TTL) {
		$order_ids = self::normalize_order_ids($order_ids);
		$operation_id = sanitize_key((string) $operation_id);
		if (empty($order_ids) || '' === $operation_id) {
			throw new InvalidArgumentException(__('Persisted participant order IDs and an operation ID are required.', 'wc-order-splitter'));
		}

		$leases = array();
		foreach ($order_ids as $order_id) {
			$token = WCOS_Operation_Lock::acquire($order_id, $operation_id, $ttl);
			if (false === $token) {
				self::release_tokens($leases);
				return false;
			}
			$leases[$order_id] = $token;
		}

		return new self($operation_id, $leases);
	}

	/**
	 * Borrow a complete lease set already owned by the same in-process operation.
	 * The borrower validates and refreshes the real tokens but never releases them.
	 */
	public static function adopt_current(array $order_ids, $operation_id) {
		$order_ids = self::normalize_order_ids($order_ids);
		$operation_id = sanitize_key((string) $operation_id);
		if (empty($order_ids) || '' === $operation_id) {
			throw new InvalidArgumentException(__('Persisted participant order IDs and an operation ID are required.', 'wc-order-splitter'));
		}
		$leases = array();
		foreach ($order_ids as $order_id) {
			$token = WCOS_Operation_Lock::current_token_for($order_id, $operation_id);
			if (false === $token) {
				return false;
			}
			$leases[$order_id] = $token;
		}
		return new self($operation_id, $leases, false);
	}

	public static function normalize_order_ids(array $order_ids) {
		$normalized = array_values(array_unique(array_filter(array_map('absint', $order_ids))));
		sort($normalized, SORT_NUMERIC);
		return $normalized;
	}

	public function order_ids() {
		return array_map('intval', array_keys($this->leases));
	}

	public function operation_id() {
		return $this->operation_id;
	}

	public function refresh($ttl = WCOS_Operation_Lock::DEFAULT_TTL) {
		if ($this->released) {
			return false;
		}
		foreach ($this->leases as $order_id => $token) {
			if (!WCOS_Operation_Lock::refresh($order_id, $token, $ttl, $this->operation_id)) {
				return false;
			}
		}
		$this->assert_owned();
		return true;
	}

	public function assert_owned() {
		if ($this->released) {
			throw new RuntimeException(__('The multi-order mutation lease has already been released.', 'wc-order-splitter'));
		}
		foreach ($this->leases as $order_id => $token) {
			if (!WCOS_Operation_Lock::is_owned($order_id, $token, $this->operation_id)) {
				throw new RuntimeException(__('A participant order lease is no longer owned by this operation.', 'wc-order-splitter'));
			}
		}
	}

	public function release() {
		if ($this->released) {
			return true;
		}
		$released = $this->release_owned ? self::release_tokens($this->leases) : true;
		$this->released = true;
		return $released;
	}

	private static function release_tokens(array $leases) {
		$order_ids = array_keys($leases);
		rsort($order_ids, SORT_NUMERIC);
		$released = true;
		foreach ($order_ids as $order_id) {
			if (!WCOS_Operation_Lock::release($order_id, $leases[$order_id])) {
				$released = false;
			}
		}
		return $released;
	}
}
