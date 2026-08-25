<?php

defined('ABSPATH') || exit;

/** Queryable Return participation indexes backed by one child-keyed journal. */
final class WCOS_Return_Participation {
	const SCHEMA_VERSION = 2;
	const CORRUPT_OPERATION_ID = 'return_participation_corrupt';
	const CHILD_ORIGINAL_META = '_wcos_returned_to_order_id';
	const CHILD_OPERATION_META = '_wcos_return_operation_id';
	const CHILD_PAIR_FINGERPRINT_META = '_wcos_return_pair_fingerprint';
	const ORIGINAL_CHILD_META = '_wcos_returned_child_order_id';
	const ORIGINAL_OPERATION_META = '_wcos_returned_child_operation_id';
	const ORIGINAL_AUTHORITY_META = '_wcos_return_authority_pointer';

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
		return array('schema_version' => self::SCHEMA_VERSION, 'present' => $present > 0, 'complete' => 3 === $present, 'values' => $values);
	}

	public static function assert_not_terminal(WC_Order $child) {
		if (!empty(self::inspect($child)['present'])) {
			throw new RuntimeException(__('This Split child already carries Return participation evidence.', 'wc-order-splitter'));
		}
	}

	public static function persist(WC_Order $child, WC_Order $original, $operation_id, $pair_fingerprint) {
		$child_id = absint($child->get_id());
		$original_id = absint($original->get_id());
		$operation_id = sanitize_key((string) $operation_id);
		$pair_fingerprint = self::normalized_fingerprint($pair_fingerprint);
		if (!$child_id || !$original_id || $child_id === $original_id || '' === $operation_id || '' === $pair_fingerprint) {
			throw new InvalidArgumentException(__('Complete Return participation authority is required.', 'wc-order-splitter'));
		}
		WCOS_Operation_Lock::assert_current_owned_for($child_id, $operation_id);
		WCOS_Operation_Lock::assert_current_owned_for($original_id, $operation_id);
		$record = WCOS_Operation_Journal::get($child, $operation_id);
		if (!is_array($record) || !WCOS_Return_Journal_Context::validates_participant($record, $child_id, 'source', $original_id, $pair_fingerprint, $operation_id)) {
			throw new RuntimeException(__('Return participation does not match the authoritative child journal.', 'wc-order-splitter'));
		}
		$fresh_child = wc_get_order($child_id);
		$fresh_original = wc_get_order($original_id);
		if (!$fresh_child instanceof WC_Order || !$fresh_original instanceof WC_Order) {
			throw new RuntimeException(__('A Return participant disappeared before relation persistence.', 'wc-order-splitter'));
		}
		self::set_exact_scalar($fresh_child, self::CHILD_ORIGINAL_META, $original_id);
		self::set_exact_scalar($fresh_child, self::CHILD_OPERATION_META, $operation_id);
		self::set_exact_scalar($fresh_child, self::CHILD_PAIR_FINGERPRINT_META, $pair_fingerprint);
		$fresh_child->save_meta_data();
		$record = WCOS_Operation_Journal::get($fresh_child, $operation_id);
		$state = WCOS_Return_Recovery_State_Graph::assert_record($record);
		if (WCOS_Return_Recovery_State_Graph::CHILD_RETIRED === $state) {
			$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
			$snapshot = isset($context['return_recovery_snapshot']) && is_array($context['return_recovery_snapshot']) ? $context['return_recovery_snapshot'] : array();
			$added_ids = isset($context['return_original_added_item_ids']) ? (array) $context['return_original_added_item_ids'] : array();
			if (empty($snapshot) || !WCOS_Operation_Journal::checkpoint($fresh_child, $operation_id, 'return_child_relation_partial', array(
				'return_recovery_state' => WCOS_Return_Recovery_State_Graph::CHILD_RELATION_PARTIAL,
				'return_forward_repair_allowed' => true,
				'return_child_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', wc_get_order($child_id)),
				'return_original_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', wc_get_order($original_id), $added_ids),
				'return_child_signature_after' => WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'child', wc_get_order($child_id)),
				'return_original_signature_after' => WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'original', wc_get_order($original_id), $added_ids),
			))) {
				throw new RuntimeException(__('Partial Return child participation could not be checkpointed.', 'wc-order-splitter'));
			}
		}
		do_action('wcos_return_recovery_checkpoint', 'after_one_reciprocal_relation', $fresh_child, $fresh_original, $operation_id);
		self::add_exact_repeatable($fresh_original, self::ORIGINAL_CHILD_META, $child_id);
		self::add_exact_repeatable($fresh_original, self::ORIGINAL_OPERATION_META, $operation_id);
		self::add_exact_repeatable($fresh_original, self::ORIGINAL_AUTHORITY_META, self::authority_pointer($child_id, $operation_id, $pair_fingerprint));
		$fresh_original->save_meta_data();
		do_action('wcos_return_recovery_checkpoint', 'after_both_relations_before_active_cleanup', $fresh_child, $fresh_original, $operation_id);
		$state = self::state_for_pair(wc_get_order($child_id), wc_get_order($original_id), $operation_id, $pair_fingerprint);
		if (empty($state['child']) || empty($state['original'])) {
			throw new RuntimeException(__('Return reciprocal participation could not be verified after persistence.', 'wc-order-splitter'));
		}
		return true;
	}

	public static function remove_active_split_relation(WC_Order $child, WC_Order $original, $operation_id, $pair_fingerprint) {
		$child = wc_get_order($child->get_id());
		$original = wc_get_order($original->get_id());
		if (!$child instanceof WC_Order || !$original instanceof WC_Order) {
			throw new RuntimeException(__('A Return participant disappeared before active Split cleanup.', 'wc-order-splitter'));
		}
		$state = self::state_for_pair($child, $original, $operation_id, $pair_fingerprint);
		if (empty($state['child']) || empty($state['original'])) {
			throw new RuntimeException(__('Return participation must be reciprocal before active Split cleanup.', 'wc-order-splitter'));
		}
		$children = self::canonical_ids($original->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true));
		$child_id = absint($child->get_id());
		if (!in_array($child_id, $children, true)) {
			return true;
		}
		$children = array_values(array_filter($children, static function($candidate) use ($child_id) { return $candidate !== $child_id; }));
		$fresh = wc_get_order($original->get_id());
		$fresh->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, $children);
		$fresh->save_meta_data();
		if (self::canonical_ids(wc_get_order($original->get_id())->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true)) !== $children) {
			throw new RuntimeException(__('The returned child could not be removed from active Split authority.', 'wc-order-splitter'));
		}
		return true;
	}

	public static function cleanup(WC_Order $child, WC_Order $original, $operation_id) {
		$operation_id = sanitize_key((string) $operation_id);
		$record = WCOS_Operation_Journal::get($child, $operation_id);
		$pair = is_array($record) ? WCOS_Return_Journal_Context::pair_from_record($record) : null;
		if (!is_array($pair)) {
			return false;
		}
		$child_id = $pair['child_order_id'];
		$original_id = $pair['original_order_id'];
		WCOS_Operation_Lock::assert_current_owned_for($child_id, $operation_id);
		WCOS_Operation_Lock::assert_current_owned_for($original_id, $operation_id);
		$fresh_child = wc_get_order($child_id);
		$fresh_original = wc_get_order($original_id);
		if (!$fresh_child instanceof WC_Order || !$fresh_original instanceof WC_Order) {
			return false;
		}
		self::delete_exact_value($fresh_child, self::CHILD_ORIGINAL_META, $original_id);
		self::delete_exact_value($fresh_child, self::CHILD_OPERATION_META, $operation_id);
		self::delete_exact_value($fresh_child, self::CHILD_PAIR_FINGERPRINT_META, $pair['pair_fingerprint']);
		$fresh_child->save_meta_data();
		self::delete_exact_value($fresh_original, self::ORIGINAL_AUTHORITY_META, self::authority_pointer($child_id, $operation_id, $pair['pair_fingerprint']));
		$remaining = self::parsed_original_authorities($fresh_original);
		if (empty($remaining['child_ids'][$child_id])) {
			self::delete_exact_value($fresh_original, self::ORIGINAL_CHILD_META, $child_id);
		}
		if (empty($remaining['operation_ids'][$operation_id])) {
			self::delete_exact_value($fresh_original, self::ORIGINAL_OPERATION_META, $operation_id);
		}
		$active = self::canonical_ids($fresh_original->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true));
		if (!in_array($child_id, $active, true)) {
			$active[] = $child_id;
			sort($active, SORT_NUMERIC);
			$fresh_original->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, $active);
		}
		$fresh_original->save_meta_data();
		$state = self::state_for_pair(wc_get_order($child_id), wc_get_order($original_id), $operation_id, $pair['pair_fingerprint']);
		return empty($state['child']) && empty($state['original']) && empty($state['active_split_removed']);
	}

	public static function state_for_pair(WC_Order $child, WC_Order $original, $operation_id, $pair_fingerprint) {
		$operation_id = sanitize_key((string) $operation_id);
		$pair_fingerprint = self::normalized_fingerprint($pair_fingerprint);
		$child_id = absint($child->get_id());
		$original_id = absint($original->get_id());
		$child_state = self::inspect($child);
		$active = self::canonical_ids($original->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true));
		return array(
			'child' => !empty($child_state['complete']) && $original_id === $child_state['values']['returned_to_order_id']
				&& $operation_id === $child_state['values']['operation_id'] && $pair_fingerprint === $child_state['values']['pair_fingerprint'],
			'original' => in_array((string) $child_id, array_map('strval', self::meta_values($original, self::ORIGINAL_CHILD_META)), true)
				&& in_array($operation_id, array_map('strval', self::meta_values($original, self::ORIGINAL_OPERATION_META)), true)
				&& in_array(self::authority_pointer($child_id, $operation_id, $pair_fingerprint), array_map('strval', self::meta_values($original, self::ORIGINAL_AUTHORITY_META)), true),
			'active_split_removed' => !in_array($child_id, $active, true),
		);
	}

	public static function completed_authorities_for_original(WC_Order $original, $split_operation_id = '') {
		$split_operation_id = sanitize_key((string) $split_operation_id);
		$parsed = self::parsed_original_authorities($original);
		$indexed_children = self::canonical_ids(self::meta_values($original, self::ORIGINAL_CHILD_META));
		$indexed_operations = self::canonical_keys(self::meta_values($original, self::ORIGINAL_OPERATION_META));
		if ($indexed_children !== array_keys($parsed['child_ids']) || $indexed_operations !== array_keys($parsed['operation_ids'])) {
			throw new RuntimeException(__('Return original participation indexes do not match authority pointers.', 'wc-order-splitter'));
		}
		$result = array();
		foreach ($parsed['authorities'] as $authority) {
			$child = wc_get_order($authority['child_order_id']);
			$record = $child instanceof WC_Order ? WCOS_Operation_Journal::get($child, $authority['operation_id']) : null;
			$pair = is_array($record) ? WCOS_Return_Journal_Context::pair_from_record($record) : null;
			if (!is_array($pair) || $pair['original_order_id'] !== absint($original->get_id()) || !hash_equals($pair['pair_fingerprint'], $authority['pair_fingerprint'])) {
				throw new RuntimeException(__('Return original participation points to invalid pair authority.', 'wc-order-splitter'));
			}
			if ('' !== $split_operation_id && $split_operation_id !== $pair['split_operation_id']) {
				continue;
			}
			if ('completed' !== sanitize_key(isset($record['status']) ? (string) $record['status'] : '')) {
				throw new RuntimeException(__('Return source evolution contains a nonterminal sibling operation.', 'wc-order-splitter'));
			}
			$result[] = $authority;
		}
		return $result;
	}

	public static function unresolved_operation_ids(WC_Order $participant) {
		$operation_ids = array();
		try {
			$child = self::inspect($participant);
			if (!empty($child['present'])) {
				if (empty($child['complete'])) {
					$operation_ids[] = self::CORRUPT_OPERATION_ID;
				} else {
					$record = WCOS_Operation_Journal::get($participant, $child['values']['operation_id']);
					if (!is_array($record) || WCOS_Return_Journal_Context::is_unsafe_record($record)) {
						$operation_ids[] = $child['values']['operation_id'];
					}
				}
			}
			foreach (self::parsed_original_authorities($participant)['authorities'] as $authority) {
				$child_order = wc_get_order($authority['child_order_id']);
				$record = $child_order instanceof WC_Order ? WCOS_Operation_Journal::get($child_order, $authority['operation_id']) : null;
				if (!is_array($record) || WCOS_Return_Journal_Context::is_unsafe_record($record)) {
					$operation_ids[] = $authority['operation_id'];
				}
			}
		} catch (Throwable $throwable) {
			$operation_ids[] = self::CORRUPT_OPERATION_ID;
		}
		return self::canonical_keys($operation_ids);
	}

	private static function parsed_original_authorities(WC_Order $original) {
		$authorities = array();
		$child_ids = array();
		$operation_ids = array();
		foreach (self::meta_values($original, self::ORIGINAL_AUTHORITY_META) as $pointer) {
			$authority = self::parse_authority_pointer($pointer);
			if (empty($authority)) {
				throw new RuntimeException(__('Return original participation contains a malformed authority pointer.', 'wc-order-splitter'));
			}
			$key = $authority['child_order_id'] . '|' . $authority['operation_id'];
			if (isset($authorities[$key]) && $authorities[$key] !== $authority) {
				throw new RuntimeException(__('Return original participation contains conflicting pair authority.', 'wc-order-splitter'));
			}
			$authorities[$key] = $authority;
			$child_ids[$authority['child_order_id']] = true;
			$operation_ids[$authority['operation_id']] = true;
		}
		ksort($authorities, SORT_STRING);
		ksort($child_ids, SORT_NUMERIC);
		ksort($operation_ids, SORT_STRING);
		return compact('authorities', 'child_ids', 'operation_ids');
	}

	private static function set_exact_scalar(WC_Order $order, $key, $value) {
		$current = self::meta_values($order, $key);
		if (count($current) > 1 || (!empty($current) && (string) reset($current) !== (string) $value)) {
			throw new RuntimeException(__('Conflicting Return scalar participation metadata already exists.', 'wc-order-splitter'));
		}
		$order->update_meta_data($key, $value);
	}

	private static function add_exact_repeatable(WC_Order $order, $key, $value) {
		if (!in_array((string) $value, array_map('strval', self::meta_values($order, $key)), true)) {
			$order->add_meta_data($key, $value, false);
		}
	}

	private static function delete_exact_value(WC_Order $order, $key, $value) {
		foreach ($order->get_meta_data() as $meta) {
			$data = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : array();
			if (isset($data['key']) && (string) $data['key'] === (string) $key && array_key_exists('value', $data) && (string) $data['value'] === (string) $value) {
				$order->delete_meta_data_value($key, $data['value']);
			}
		}
	}

	private static function authority_pointer($child_id, $operation_id, $pair_fingerprint) {
		return absint($child_id) . '|' . sanitize_key((string) $operation_id) . '|' . self::normalized_fingerprint($pair_fingerprint);
	}

	private static function parse_authority_pointer($pointer) {
		$parts = explode('|', (string) $pointer);
		if (3 !== count($parts)) {
			return array();
		}
		$child_order_id = absint($parts[0]);
		$operation_id = sanitize_key($parts[1]);
		$pair_fingerprint = self::normalized_fingerprint($parts[2]);
		if (!$child_order_id || '' === $operation_id || '' === $pair_fingerprint || self::authority_pointer($child_order_id, $operation_id, $pair_fingerprint) !== (string) $pointer) {
			return array();
		}
		return compact('child_order_id', 'operation_id', 'pair_fingerprint');
	}

	private static function meta_values(WC_Order $order, $key) {
		$values = array();
		foreach ($order->get_meta_data() as $meta) {
			$data = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : array();
			if (isset($data['key']) && (string) $data['key'] === (string) $key && array_key_exists('value', $data)) {
				$values[] = $data['value'];
			}
		}
		return $values;
	}

	private static function canonical_ids($value) {
		$ids = array_values(array_unique(array_filter(array_map('absint', is_array($value) ? $value : array()))));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private static function canonical_keys($value) {
		$keys = array_values(array_unique(array_filter(array_map('sanitize_key', is_array($value) ? $value : array()))));
		sort($keys, SORT_STRING);
		return $keys;
	}

	private static function scalar_positive_int($value) {
		if ('' === $value || null === $value) { return null; }
		if ((is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return (int) $value; }
		throw new RuntimeException(__('Return participation contains a malformed order ID.', 'wc-order-splitter'));
	}

	private static function scalar_key($value) {
		if ('' === $value || null === $value) { return null; }
		if (!is_string($value) || sanitize_key($value) !== $value || '' === $value) {
			throw new RuntimeException(__('Return participation contains a malformed operation ID.', 'wc-order-splitter'));
		}
		return $value;
	}

	private static function scalar_fingerprint($value) {
		if ('' === $value || null === $value) { return null; }
		$value = self::normalized_fingerprint($value);
		if ('' === $value) { throw new RuntimeException(__('Return participation contains a malformed fingerprint.', 'wc-order-splitter')); }
		return $value;
	}

	private static function normalized_fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
