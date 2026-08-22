<?php

defined('ABSPATH') || exit;

/**
 * Queryable scalar participation metadata for a journal-authoritative Merge pair.
 *
 * These records are discovery indexes, never pair authority. Every candidate is
 * verified against the single source-keyed journal and its pair fingerprint.
 */
final class WCOS_Merge_Participation {

	const SCHEMA_VERSION = 1;
	const CORRUPT_OPERATION_ID = 'merge_participation_corrupt';
	const SOURCE_TARGET_META = '_wcos_merged_into_order_id';
	const SOURCE_OPERATION_META = '_wcos_merge_operation_id';
	const SOURCE_PAIR_FINGERPRINT_META = '_wcos_merge_pair_fingerprint';
	const TARGET_SOURCE_META = '_wcos_merged_source_order_id';
	const TARGET_OPERATION_META = '_wcos_merge_source_operation_id';
	const TARGET_AUTHORITY_META = '_wcos_merge_authority_pointer';

	public static function persist(WC_Order $source, WC_Order $target, $operation_id, $pair_fingerprint) {
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		$operation_id = sanitize_key((string) $operation_id);
		$pair_fingerprint = sanitize_key((string) $pair_fingerprint);
		if (!$source_id || !$target_id || $source_id === $target_id || '' === $operation_id || '' === $pair_fingerprint) {
			throw new InvalidArgumentException(__('Complete Merge participation authority is required.', 'wc-order-splitter'));
		}
		WCOS_Operation_Lock::assert_current_owned_for($source_id, $operation_id);
		WCOS_Operation_Lock::assert_current_owned_for($target_id, $operation_id);
		$record = WCOS_Operation_Journal::get($source, $operation_id);
		if (!is_array($record)
			|| !WCOS_Merge_Journal_Context::validates_participant($record, $source_id, 'source', $target_id, $pair_fingerprint, $operation_id)) {
			throw new RuntimeException(__('Merge participation does not match the authoritative source journal.', 'wc-order-splitter'));
		}

		$fresh_source = wc_get_order($source_id);
		$fresh_target = wc_get_order($target_id);
		if (!$fresh_source instanceof WC_Order || !$fresh_target instanceof WC_Order) {
			throw new RuntimeException(__('A Merge participant disappeared before participation could be persisted.', 'wc-order-splitter'));
		}

		self::set_exact_scalar($fresh_source, self::SOURCE_TARGET_META, $target_id);
		self::set_exact_scalar($fresh_source, self::SOURCE_OPERATION_META, $operation_id);
		self::set_exact_scalar($fresh_source, self::SOURCE_PAIR_FINGERPRINT_META, $pair_fingerprint);
		$fresh_source->save_meta_data();

		self::add_exact_repeatable($fresh_target, self::TARGET_SOURCE_META, $source_id);
		self::add_exact_repeatable($fresh_target, self::TARGET_OPERATION_META, $operation_id);
		self::add_exact_repeatable($fresh_target, self::TARGET_AUTHORITY_META, self::authority_pointer($source_id, $operation_id, $pair_fingerprint));
		$fresh_target->save_meta_data();

		$source_after = wc_get_order($source_id);
		$target_after = wc_get_order($target_id);
		if (!$source_after instanceof WC_Order || !$target_after instanceof WC_Order
			|| !self::source_values_match($source_after, $target_id, $operation_id, $pair_fingerprint)
			|| !self::target_values_contain($target_after, $source_id, $operation_id, $pair_fingerprint)) {
			throw new RuntimeException(__('Merge participation metadata could not be verified after persistence.', 'wc-order-splitter'));
		}
		return true;
	}

	public static function cleanup(WC_Order $source, WC_Order $target, $operation_id) {
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		$operation_id = sanitize_key((string) $operation_id);
		if (!$source_id || !$target_id || '' === $operation_id) {
			return false;
		}
		WCOS_Operation_Lock::assert_current_owned_for($source_id, $operation_id);
		WCOS_Operation_Lock::assert_current_owned_for($target_id, $operation_id);
		$record = WCOS_Operation_Journal::get($source, $operation_id);
		$pair = is_array($record) ? WCOS_Merge_Journal_Context::pair_from_record($record) : null;
		if (!is_array($pair)
			|| $source_id !== $pair['source_order_id']
			|| $target_id !== $pair['target_order_id']
			|| $operation_id !== sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : '')) {
			return false;
		}
		$pair_fingerprint = $pair['pair_fingerprint'];

		$fresh_source = wc_get_order($source_id);
		$fresh_target = wc_get_order($target_id);
		if (!$fresh_source instanceof WC_Order || !$fresh_target instanceof WC_Order) {
			return false;
		}
		self::delete_exact_value($fresh_source, self::SOURCE_TARGET_META, $target_id);
		self::delete_exact_value($fresh_source, self::SOURCE_OPERATION_META, $operation_id);
		self::delete_exact_value($fresh_source, self::SOURCE_PAIR_FINGERPRINT_META, $pair_fingerprint);
		$fresh_source->save_meta_data();

		$remaining_authorities = array();
		if ('' !== $pair_fingerprint) {
			self::delete_exact_value($fresh_target, self::TARGET_AUTHORITY_META, self::authority_pointer($source_id, $operation_id, $pair_fingerprint));
		}
		foreach (self::meta_values($fresh_target, self::TARGET_AUTHORITY_META) as $pointer) {
			$authority = self::parse_authority_pointer($pointer);
			if (!empty($authority)) {
				$remaining_authorities[] = $authority;
			}
		}
		$source_still_referenced = false;
		$operation_still_referenced = false;
		foreach ($remaining_authorities as $authority) {
			$source_still_referenced = $source_still_referenced || $source_id === $authority['source_order_id'];
			$operation_still_referenced = $operation_still_referenced || $operation_id === $authority['operation_id'];
		}
		if (!$source_still_referenced) {
			self::delete_exact_value($fresh_target, self::TARGET_SOURCE_META, $source_id);
		}
		if (!$operation_still_referenced) {
			self::delete_exact_value($fresh_target, self::TARGET_OPERATION_META, $operation_id);
		}
		$fresh_target->save_meta_data();

		$source_after = wc_get_order($source_id);
		$target_after = wc_get_order($target_id);
		if (!$source_after instanceof WC_Order || !$target_after instanceof WC_Order) {
			return false;
		}
		$owned_pointer = self::authority_pointer($source_id, $operation_id, $pair_fingerprint);
		if (in_array((string) $target_id, array_map('strval', self::meta_values($source_after, self::SOURCE_TARGET_META)), true)
			|| in_array($operation_id, array_map('strval', self::meta_values($source_after, self::SOURCE_OPERATION_META)), true)
			|| in_array($pair_fingerprint, array_map('strval', self::meta_values($source_after, self::SOURCE_PAIR_FINGERPRINT_META)), true)
			|| in_array($owned_pointer, array_map('strval', self::meta_values($target_after, self::TARGET_AUTHORITY_META)), true)) {
			return false;
		}

		$remaining = self::parsed_target_authorities($target_after);
		if (empty($remaining['source_ids'][$source_id])
			&& in_array((string) $source_id, array_map('strval', self::meta_values($target_after, self::TARGET_SOURCE_META)), true)) {
			return false;
		}
		if (empty($remaining['operation_ids'][$operation_id])
			&& in_array($operation_id, array_map('strval', self::meta_values($target_after, self::TARGET_OPERATION_META)), true)) {
			return false;
		}
		return true;
	}

	public static function authorities(WC_Order $participant) {
		$participant_id = absint($participant->get_id());
		if (!$participant_id) {
			return array();
		}
		$authorities = array();
		$source_target_values = self::meta_values($participant, self::SOURCE_TARGET_META);
		$source_operation_values = self::meta_values($participant, self::SOURCE_OPERATION_META);
		$source_fingerprint_values = self::meta_values($participant, self::SOURCE_PAIR_FINGERPRINT_META);
		$has_source_evidence = !empty($source_target_values) || !empty($source_operation_values) || !empty($source_fingerprint_values);
		$target_id = 1 === count($source_target_values) ? absint(reset($source_target_values)) : 0;
		$source_operation = 1 === count($source_operation_values) ? sanitize_key((string) reset($source_operation_values)) : '';
		$source_pair_fingerprint = 1 === count($source_fingerprint_values) ? self::normalized_fingerprint(reset($source_fingerprint_values)) : '';
		if ($has_source_evidence && $target_id && '' !== $source_operation && '' !== $source_pair_fingerprint
			&& 1 === count($source_target_values) && 1 === count($source_operation_values) && 1 === count($source_fingerprint_values)
			&& (string) reset($source_target_values) === (string) $target_id
			&& (string) reset($source_operation_values) === $source_operation
			&& (string) reset($source_fingerprint_values) === $source_pair_fingerprint) {
			$authorities[] = array(
				'participant_order_id' => $participant_id,
				'participant_role' => 'source',
				'peer_order_id' => $target_id,
				'journal_source_order_id' => $participant_id,
				'operation_id' => $source_operation,
				'pair_fingerprint' => $source_pair_fingerprint,
			);
		} elseif ($has_source_evidence) {
			$authorities = array_merge($authorities, self::corrupt_authorities($participant_id, $source_operation_values));
		}

		$target_source_values = self::meta_values($participant, self::TARGET_SOURCE_META);
		$target_operation_values = self::meta_values($participant, self::TARGET_OPERATION_META);
		$target_pointer_values = self::meta_values($participant, self::TARGET_AUTHORITY_META);
		$parsed_targets = array();
		$target_pointer_keys = array();
		foreach ($target_pointer_values as $pointer) {
			$parts = self::parse_authority_pointer($pointer);
			if (empty($parts)) {
				$pointer_parts = explode('|', (string) $pointer);
				$operation_values = isset($pointer_parts[1]) ? array($pointer_parts[1]) : array();
				$authorities = array_merge($authorities, self::corrupt_authorities($participant_id, $operation_values));
				continue;
			}
			$key = $parts['source_order_id'] . '|' . $parts['operation_id'];
			if (isset($target_pointer_keys[$key]) && $target_pointer_keys[$key] !== $parts['pair_fingerprint']) {
				$authorities = array_merge($authorities, self::corrupt_authorities($participant_id, array($parts['operation_id'])));
			}
			$target_pointer_keys[$key] = $parts['pair_fingerprint'];
			$parsed_targets[] = $parts;
			$authorities[] = array(
				'participant_order_id' => $participant_id,
				'participant_role' => 'target',
				'peer_order_id' => $parts['source_order_id'],
				'journal_source_order_id' => $parts['source_order_id'],
				'operation_id' => $parts['operation_id'],
				'pair_fingerprint' => $parts['pair_fingerprint'],
			);
		}
		$indexed_source_ids = self::normalized_scalar_set($target_source_values, 'absint');
		$indexed_operation_ids = self::normalized_scalar_set($target_operation_values, 'sanitize_key');
		$pointer_source_ids = array();
		$pointer_operation_ids = array();
		foreach ($parsed_targets as $parts) {
			$pointer_source_ids[] = $parts['source_order_id'];
			$pointer_operation_ids[] = $parts['operation_id'];
		}
		$pointer_source_ids = self::normalized_scalar_set($pointer_source_ids, 'absint');
		$pointer_operation_ids = self::normalized_scalar_set($pointer_operation_ids, 'sanitize_key');
		if ($indexed_source_ids !== $pointer_source_ids || $indexed_operation_ids !== $pointer_operation_ids
			|| count($target_source_values) !== count($indexed_source_ids)
			|| count($target_operation_values) !== count($indexed_operation_ids)
			|| !self::scalar_values_are_canonical($target_source_values, 'absint')
			|| !self::scalar_values_are_canonical($target_operation_values, 'sanitize_key')
			|| count($target_pointer_values) !== count(array_unique(array_map('strval', $target_pointer_values)))) {
			$authorities = array_merge($authorities, self::corrupt_authorities($participant_id, $target_operation_values));
		}

		return self::unique_authorities($authorities);
	}

	public static function unresolved_operation_ids(WC_Order $participant) {
		$active = array();
		foreach (self::authorities($participant) as $authority) {
			if ('corrupt' === $authority['participant_role']) {
				$active[] = $authority['operation_id'];
				continue;
			}
			$source = wc_get_order($authority['journal_source_order_id']);
			if (!$source instanceof WC_Order) {
				$active[] = $authority['operation_id'];
				continue;
			}
			$record = WCOS_Operation_Journal::get($source, $authority['operation_id']);
			if (!is_array($record)) {
				$active[] = $authority['operation_id'];
				continue;
			}
			if (!WCOS_Merge_Journal_Context::validates_participant(
				$record,
				$authority['participant_order_id'],
				$authority['participant_role'],
				$authority['peer_order_id'],
				$authority['pair_fingerprint'],
				$authority['operation_id']
			) || WCOS_Merge_Journal_Context::is_unsafe_record($record)) {
				$active[] = $authority['operation_id'];
			}
		}
		$active = array_values(array_unique(array_filter(array_map('sanitize_key', $active))));
		sort($active, SORT_STRING);
		return $active;
	}

	public static function find_targets($source_order_id, $operation_id) {
		$source_order_id = absint($source_order_id);
		$operation_id = sanitize_key((string) $operation_id);
		if (!$source_order_id || '' === $operation_id) {
			return array();
		}
		$candidates = WCOS_Order_Relation_Repository::find(
			array(
				array('key' => self::TARGET_SOURCE_META, 'value' => $source_order_id, 'type' => 'NUMERIC'),
				array('key' => self::TARGET_OPERATION_META, 'value' => $operation_id),
			),
			-1
		);
		$source = wc_get_order($source_order_id);
		$record = $source instanceof WC_Order ? WCOS_Operation_Journal::get($source, $operation_id) : null;
		if (!is_array($record)) {
			return array();
		}
		return array_values(array_filter($candidates, static function($candidate) use ($record, $source_order_id, $operation_id) {
			if (!$candidate instanceof WC_Order) {
				return false;
			}
			foreach (self::authorities($candidate) as $authority) {
				if ('target' === $authority['participant_role']
					&& $source_order_id === $authority['journal_source_order_id']
					&& $operation_id === $authority['operation_id']
					&& WCOS_Merge_Journal_Context::validates_participant(
						$record,
						$candidate->get_id(),
						'target',
						$source_order_id,
						$authority['pair_fingerprint'],
						$operation_id
					)) {
					return true;
				}
			}
			return false;
		}));
	}

	/**
	 * Exact reciprocal relation state for one authoritative pair.
	 *
	 * Values are booleans so recovery never treats unrelated target authorities
	 * as belonging to the operation being repaired.
	 */
	public static function state_for_pair(WC_Order $source, WC_Order $target, $operation_id, $pair_fingerprint) {
		$operation_id = sanitize_key((string) $operation_id);
		$pair_fingerprint = self::normalized_fingerprint($pair_fingerprint);
		if ('' === $operation_id || '' === $pair_fingerprint) {
			return array('source' => false, 'target' => false);
		}
		return array(
			'source' => self::source_values_match($source, $target->get_id(), $operation_id, $pair_fingerprint),
			'target' => self::target_values_contain($target, $source->get_id(), $operation_id, $pair_fingerprint),
		);
	}

	private static function set_exact_scalar(WC_Order $order, $key, $value) {
		$current = self::meta_values($order, $key);
		if (count($current) > 1 || (!empty($current) && (string) reset($current) !== (string) $value)) {
			throw new RuntimeException(__('Conflicting Merge scalar participation metadata already exists.', 'wc-order-splitter'));
		}
		$order->update_meta_data($key, $value);
	}

	private static function add_exact_repeatable(WC_Order $order, $key, $value) {
		foreach (self::meta_values($order, $key) as $current) {
			if ((string) $current === (string) $value) {
				return;
			}
		}
		$order->add_meta_data($key, $value, false);
	}

	private static function delete_exact_value(WC_Order $order, $key, $value) {
		$key = (string) $key;
		$value = (string) $value;
		foreach ($order->get_meta_data() as $meta) {
			if (!is_object($meta) || !method_exists($meta, 'get_data')) {
				continue;
			}
			$data = $meta->get_data();
			if (isset($data['key']) && $key === (string) $data['key']
				&& array_key_exists('value', $data) && $value === (string) $data['value']) {
				$order->delete_meta_data_value($key, $data['value']);
			}
		}
	}

	private static function source_values_match(WC_Order $source, $target_id, $operation_id, $pair_fingerprint) {
		return (int) $source->get_meta(self::SOURCE_TARGET_META, true) === (int) $target_id
			&& (string) $source->get_meta(self::SOURCE_OPERATION_META, true) === (string) $operation_id
			&& (string) $source->get_meta(self::SOURCE_PAIR_FINGERPRINT_META, true) === (string) $pair_fingerprint;
	}

	private static function target_values_contain(WC_Order $target, $source_id, $operation_id, $pair_fingerprint) {
		return in_array((string) $source_id, array_map('strval', self::meta_values($target, self::TARGET_SOURCE_META)), true)
			&& in_array((string) $operation_id, array_map('strval', self::meta_values($target, self::TARGET_OPERATION_META)), true)
			&& in_array(self::authority_pointer($source_id, $operation_id, $pair_fingerprint), array_map('strval', self::meta_values($target, self::TARGET_AUTHORITY_META)), true);
	}

	private static function authority_pointer($source_order_id, $operation_id, $pair_fingerprint) {
		return absint($source_order_id) . '|' . sanitize_key((string) $operation_id) . '|' . sanitize_key((string) $pair_fingerprint);
	}

	private static function parse_authority_pointer($pointer) {
		$parts = explode('|', (string) $pointer);
		if (3 !== count($parts)) {
			return array();
		}
		$source_order_id = absint($parts[0]);
		$operation_id = sanitize_key($parts[1]);
		$pair_fingerprint = sanitize_key($parts[2]);
		if (!$source_order_id || '' === $operation_id || '' === $pair_fingerprint
			|| self::authority_pointer($source_order_id, $operation_id, $pair_fingerprint) !== (string) $pointer) {
			return array();
		}
		return compact('source_order_id', 'operation_id', 'pair_fingerprint');
	}

	private static function parsed_target_authorities(WC_Order $target) {
		$source_ids = array();
		$operation_ids = array();
		foreach (self::meta_values($target, self::TARGET_AUTHORITY_META) as $pointer) {
			$authority = self::parse_authority_pointer($pointer);
			if (empty($authority)) {
				continue;
			}
			$source_ids[$authority['source_order_id']] = true;
			$operation_ids[$authority['operation_id']] = true;
		}
		return array('source_ids' => $source_ids, 'operation_ids' => $operation_ids);
	}

	private static function corrupt_authorities($participant_id, array $operation_values) {
		$operation_ids = self::normalized_scalar_set($operation_values, 'sanitize_key');
		if (empty($operation_ids)) {
			$operation_ids = array(self::CORRUPT_OPERATION_ID);
		}
		$authorities = array();
		foreach ($operation_ids as $operation_id) {
			$authorities[] = array(
				'participant_order_id' => absint($participant_id),
				'participant_role' => 'corrupt',
				'peer_order_id' => 0,
				'journal_source_order_id' => 0,
				'operation_id' => $operation_id,
				'pair_fingerprint' => '',
			);
		}
		return $authorities;
	}

	private static function normalized_scalar_set(array $values, $normalizer) {
		$normalized = array();
		foreach ($values as $value) {
			$value = call_user_func($normalizer, $value);
			if (0 === $value || '' === $value) {
				continue;
			}
			$normalized[] = $value;
		}
		$normalized = array_values(array_unique($normalized));
		sort($normalized, SORT_STRING);
		return $normalized;
	}

	private static function scalar_values_are_canonical(array $values, $normalizer) {
		foreach ($values as $value) {
			if ((string) $value !== (string) call_user_func($normalizer, $value)) {
				return false;
			}
		}
		return true;
	}

	private static function normalized_fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}

	private static function meta_values(WC_Order $order, $key) {
		$values = array();
		$key = (string) $key;
		foreach ($order->get_meta_data() as $meta) {
			if (!is_object($meta) || !method_exists($meta, 'get_data')) {
				continue;
			}
			$data = $meta->get_data();
			if (!isset($data['key']) || (string) $data['key'] !== $key || !array_key_exists('value', $data)) {
				continue;
			}
			$values[] = $data['value'];
		}
		return $values;
	}

	private static function unique_authorities(array $authorities) {
		$unique = array();
		foreach ($authorities as $authority) {
			$key = implode(':', array(
				$authority['participant_order_id'],
				$authority['participant_role'],
				$authority['journal_source_order_id'],
				$authority['operation_id'],
			));
			$unique[$key] = $authority;
		}
		ksort($unique, SORT_STRING);
		return array_values($unique);
	}
}
