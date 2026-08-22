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

		$fresh_source = wc_get_order($source_id);
		$fresh_target = wc_get_order($target_id);
		if (!$fresh_source instanceof WC_Order || !$fresh_target instanceof WC_Order) {
			return false;
		}
		$pair_fingerprint = sanitize_key((string) $fresh_source->get_meta(self::SOURCE_PAIR_FINGERPRINT_META, true));
		if ((int) $fresh_source->get_meta(self::SOURCE_TARGET_META, true) === $target_id
			&& (string) $fresh_source->get_meta(self::SOURCE_OPERATION_META, true) === $operation_id
			&& '' !== $pair_fingerprint) {
			$fresh_source->delete_meta_data(self::SOURCE_TARGET_META);
			$fresh_source->delete_meta_data(self::SOURCE_OPERATION_META);
			$fresh_source->delete_meta_data(self::SOURCE_PAIR_FINGERPRINT_META);
			$fresh_source->save_meta_data();
		}

		$remaining_authorities = array();
		if ('' !== $pair_fingerprint) {
			$fresh_target->delete_meta_data_value(self::TARGET_AUTHORITY_META, self::authority_pointer($source_id, $operation_id, $pair_fingerprint));
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
			$fresh_target->delete_meta_data_value(self::TARGET_SOURCE_META, $source_id);
		}
		if (!$operation_still_referenced) {
			$fresh_target->delete_meta_data_value(self::TARGET_OPERATION_META, $operation_id);
		}
		$fresh_target->save_meta_data();
		return true;
	}

	public static function authorities(WC_Order $participant) {
		$participant_id = absint($participant->get_id());
		if (!$participant_id) {
			return array();
		}
		$authorities = array();
		$target_id = absint($participant->get_meta(self::SOURCE_TARGET_META, true));
		$source_operation = sanitize_key((string) $participant->get_meta(self::SOURCE_OPERATION_META, true));
		$source_pair_fingerprint = sanitize_key((string) $participant->get_meta(self::SOURCE_PAIR_FINGERPRINT_META, true));
		if ($target_id && '' !== $source_operation && '' !== $source_pair_fingerprint) {
			$authorities[] = array(
				'participant_order_id' => $participant_id,
				'participant_role' => 'source',
				'peer_order_id' => $target_id,
				'journal_source_order_id' => $participant_id,
				'operation_id' => $source_operation,
				'pair_fingerprint' => $source_pair_fingerprint,
			);
		}

		foreach (self::meta_values($participant, self::TARGET_AUTHORITY_META) as $pointer) {
			$parts = self::parse_authority_pointer($pointer);
			if (empty($parts)) {
				continue;
			}
			$authorities[] = array(
				'participant_order_id' => $participant_id,
				'participant_role' => 'target',
				'peer_order_id' => $parts['source_order_id'],
				'journal_source_order_id' => $parts['source_order_id'],
				'operation_id' => $parts['operation_id'],
				'pair_fingerprint' => $parts['pair_fingerprint'],
			);
		}

		return self::unique_authorities($authorities);
	}

	public static function unresolved_operation_ids(WC_Order $participant) {
		$active = array();
		foreach (self::authorities($participant) as $authority) {
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
			if (WCOS_Merge_Journal_Context::validates_participant(
				$record,
				$authority['participant_order_id'],
				$authority['participant_role'],
				$authority['peer_order_id'],
				$authority['pair_fingerprint'],
				$authority['operation_id']
			) && WCOS_Merge_Journal_Context::is_unsafe_record($record)) {
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
