<?php

defined('ABSPATH') || exit;

/**
 * Authenticated chain from immutable Split authority through completed sibling Returns.
 *
 * Completed child-keyed Return journals remain the authority. Original-order
 * metadata is only a queryable pointer set and cannot mint an evolution.
 */
final class WCOS_Return_Source_Evolution_Authority {

	const SCHEMA_VERSION = 1;
	const POLICY_VERSION = 1;
	const TERMINAL_SCHEMA_VERSION = 1;

	public static function resolve(WC_Order $original, $split_operation_id, $base_commercial_signature, $base_relation_signature, array $initial_child_ids) {
		$original_id = absint($original->get_id());
		$split_operation_id = sanitize_key((string) $split_operation_id);
		$initial_child_ids = self::ids($initial_child_ids);
		if (!$original_id || '' === $split_operation_id || empty($initial_child_ids)) {
			throw new RuntimeException(__('Return source-evolution base authority is incomplete.', 'wc-order-splitter'));
		}
		$current = self::authority(
			$original_id,
			$split_operation_id,
			0,
			'',
			self::sealed_signature('commercial', $base_commercial_signature),
			self::sealed_signature('relation', $base_relation_signature),
			$initial_child_ids,
			array(),
			array()
		);

		$terminals = array();
		foreach (WCOS_Return_Participation::completed_authorities_for_original($original, $split_operation_id) as $pointer) {
			$child = wc_get_order($pointer['child_order_id']);
			$record = $child instanceof WC_Order
				? WCOS_Operation_Journal::get($child, $pointer['operation_id'])
				: null;
			if (!$child instanceof WC_Order || !is_array($record)
				|| !WCOS_Return_Journal_Context::validates_participant(
					$record,
					$original_id,
					'target',
					$child->get_id(),
					$pointer['pair_fingerprint'],
					$pointer['operation_id']
				)) {
				throw new RuntimeException(__('A Return source-evolution pointer lacks an authoritative child journal.', 'wc-order-splitter'));
			}
			$result = WCOS_Return_Journal_Context::terminal_result_from_record($record);
			if ($split_operation_id !== sanitize_key((string) $result['split_operation_id'])) {
				throw new RuntimeException(__('A completed Return belongs to a different Split lineage.', 'wc-order-splitter'));
			}
			$state = WCOS_Return_Participation::state_for_pair($child, $original, $pointer['operation_id'], $pointer['pair_fingerprint']);
			if (empty($state['child']) || empty($state['original']) || empty($state['active_split_removed'])) {
				throw new RuntimeException(__('Completed Return participation is incomplete or the child is still active.', 'wc-order-splitter'));
			}
			$sequence = absint(isset($result['source_evolution']['authority_after']['sequence']) ? $result['source_evolution']['authority_after']['sequence'] : 0);
			if (!$sequence || isset($terminals[$sequence])) {
				throw new RuntimeException(__('Return source-evolution sequence is duplicated or malformed.', 'wc-order-splitter'));
			}
			$terminals[$sequence] = $result;
		}
		ksort($terminals, SORT_NUMERIC);

		foreach ($terminals as $sequence => $result) {
			$evolution = $result['source_evolution'];
			self::assert_terminal_evolution($evolution);
			$before = $evolution['authority_before'];
			$after = $evolution['authority_after'];
			if ($sequence !== $current['sequence'] + 1
				|| !hash_equals($current['authority_fingerprint'], $before['authority_fingerprint'])
				|| !hash_equals($current['authority_fingerprint'], $after['predecessor_fingerprint'])
				|| in_array(absint($result['child_order_id']), $current['returned_child_ids'], true)
				|| !in_array(absint($result['child_order_id']), $after['returned_child_ids'], true)) {
				throw new RuntimeException(__('Completed sibling Returns do not form one authenticated source-evolution chain.', 'wc-order-splitter'));
			}
			$current = $after;
		}

		self::assert_valid($current, $original_id, $split_operation_id);
		if (!hash_equals($current['original_commercial_signature'], self::sealed_signature('commercial', WCOS_Order_Contract_Snapshot::source_signature($original)))
			|| !hash_equals($current['original_relation_signature'], self::sealed_signature('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($original)))) {
			throw new RuntimeException(__('The current original order does not match authenticated Return source evolution.', 'wc-order-splitter'));
		}
		$actual_active = self::ids((array) $original->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true));
		if ($actual_active !== $current['active_child_ids']) {
			throw new RuntimeException(__('The active Split-child relation set diverged from Return source evolution.', 'wc-order-splitter'));
		}
		return $current;
	}

	public static function create_terminal_evolution(array $pair, WC_Order $original) {
		$before = isset($pair['source_evolution_authority']) && is_array($pair['source_evolution_authority'])
			? $pair['source_evolution_authority'] : array();
		self::assert_valid($before, $pair['original_order_id'], $pair['split_operation_id']);
		$child_id = absint($pair['child_order_id']);
		$active = array_values(array_filter(
			$before['active_child_ids'],
			static function($candidate) use ($child_id) {
				return absint($candidate) !== $child_id;
			}
		));
		if (count($active) === count($before['active_child_ids']) || in_array($child_id, $before['returned_child_ids'], true)) {
			throw new RuntimeException(__('Return source evolution cannot consume an inactive or already-returned child.', 'wc-order-splitter'));
		}
		$returned = self::ids(array_merge($before['returned_child_ids'], array($child_id)));
		$pairs = array_values(array_unique(array_merge($before['completed_pair_fingerprints'], array($pair['pair_fingerprint']))));
		sort($pairs, SORT_STRING);
		$after = self::authority(
			$pair['original_order_id'],
			$pair['split_operation_id'],
			$before['sequence'] + 1,
			$before['authority_fingerprint'],
			self::sealed_signature('commercial', WCOS_Order_Contract_Snapshot::source_signature($original)),
			self::sealed_signature('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($original)),
			$active,
			$returned,
			$pairs
		);
		$terminal = array(
			'schema_version' => self::TERMINAL_SCHEMA_VERSION,
			'authority_before' => $before,
			'authority_after' => $after,
		);
		$terminal['evolution_fingerprint'] = self::terminal_fingerprint($terminal);
		return $terminal;
	}

	public static function assert_terminal_evolution($terminal, array $pair = array()) {
		if (!is_array($terminal) || self::TERMINAL_SCHEMA_VERSION !== (int) (isset($terminal['schema_version']) ? $terminal['schema_version'] : 0)
			|| !isset($terminal['authority_before'], $terminal['authority_after'])
			|| !is_array($terminal['authority_before']) || !is_array($terminal['authority_after'])) {
			throw new RuntimeException(__('Return terminal source-evolution evidence has an invalid schema.', 'wc-order-splitter'));
		}
		$before = $terminal['authority_before'];
		$after = $terminal['authority_after'];
		self::assert_valid($before);
		self::assert_valid($after, $before['original_order_id'], $before['split_operation_id']);
		$stored = self::fingerprint(isset($terminal['evolution_fingerprint']) ? $terminal['evolution_fingerprint'] : '');
		if ('' === $stored || !hash_equals($stored, self::terminal_fingerprint($terminal))
			|| $after['sequence'] !== $before['sequence'] + 1
			|| !hash_equals($after['predecessor_fingerprint'], $before['authority_fingerprint'])) {
			throw new RuntimeException(__('Return terminal source-evolution evidence failed integrity verification.', 'wc-order-splitter'));
		}
		if (!empty($pair)) {
			$child_id = absint($pair['child_order_id']);
			if (!hash_equals($before['authority_fingerprint'], $pair['source_evolution_authority_fingerprint'])
				|| !in_array($child_id, $before['active_child_ids'], true)
				|| in_array($child_id, $after['active_child_ids'], true)
				|| !in_array($child_id, $after['returned_child_ids'], true)
				|| !in_array($pair['pair_fingerprint'], $after['completed_pair_fingerprints'], true)) {
				throw new RuntimeException(__('Return terminal evolution does not consume the exact pair authority.', 'wc-order-splitter'));
			}
		}
		return true;
	}

	public static function assert_valid(array $authority, $original_order_id = 0, $split_operation_id = '') {
		$expected = array(
			'active_child_ids', 'authority_fingerprint', 'completed_pair_fingerprints', 'original_commercial_signature',
			'original_order_id', 'original_relation_signature', 'policy_version', 'predecessor_fingerprint',
			'returned_child_ids', 'schema_version', 'sequence', 'split_operation_id',
		);
		$actual = array_keys($authority);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		$stored = self::fingerprint(isset($authority['authority_fingerprint']) ? $authority['authority_fingerprint'] : '');
		$copy = $authority;
		unset($copy['authority_fingerprint']);
		if ($actual !== $expected || self::SCHEMA_VERSION !== (int) $authority['schema_version']
			|| self::POLICY_VERSION !== (int) $authority['policy_version']
			|| !$authority['original_order_id'] || '' === sanitize_key((string) $authority['split_operation_id'])
			|| !is_array($authority['active_child_ids']) || !is_array($authority['returned_child_ids'])
			|| !is_array($authority['completed_pair_fingerprints'])
			|| self::ids($authority['active_child_ids']) !== array_values($authority['active_child_ids'])
			|| self::ids($authority['returned_child_ids']) !== array_values($authority['returned_child_ids'])
			|| array_intersect($authority['active_child_ids'], $authority['returned_child_ids'])
			|| '' === self::fingerprint($authority['original_commercial_signature'])
			|| '' === self::fingerprint($authority['original_relation_signature'])
			|| '' === $stored
			|| !hash_equals($stored, WCOS_Mutation_Fingerprint::create('return_source_evolution_v1', absint($authority['original_order_id']), $copy))) {
			throw new RuntimeException(__('Return source-evolution authority failed integrity verification.', 'wc-order-splitter'));
		}
		foreach ($authority['completed_pair_fingerprints'] as $fingerprint) {
			if ('' === self::fingerprint($fingerprint)) {
				throw new RuntimeException(__('Return source-evolution pair evidence is malformed.', 'wc-order-splitter'));
			}
		}
		if ((int) $authority['sequence'] !== count($authority['returned_child_ids'])
			|| (int) $authority['sequence'] !== count($authority['completed_pair_fingerprints'])
			|| (0 === (int) $authority['sequence']) !== ('' === (string) $authority['predecessor_fingerprint'])
			|| ((int) $authority['sequence'] > 0 && '' === self::fingerprint($authority['predecessor_fingerprint']))
			|| ($original_order_id && absint($original_order_id) !== absint($authority['original_order_id']))
			|| ('' !== (string) $split_operation_id && sanitize_key((string) $split_operation_id) !== $authority['split_operation_id'])) {
			throw new RuntimeException(__('Return source-evolution authority is not canonical for this lineage.', 'wc-order-splitter'));
		}
		return true;
	}

	private static function authority($original_id, $split_operation_id, $sequence, $predecessor, $commercial, $relation, array $active, array $returned, array $pairs) {
		$authority = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'original_order_id' => absint($original_id),
			'split_operation_id' => sanitize_key((string) $split_operation_id),
			'sequence' => absint($sequence),
			'predecessor_fingerprint' => self::fingerprint($predecessor),
			'original_commercial_signature' => self::fingerprint($commercial),
			'original_relation_signature' => self::fingerprint($relation),
			'active_child_ids' => self::ids($active),
			'returned_child_ids' => self::ids($returned),
			'completed_pair_fingerprints' => array_values(array_unique(array_map(array(__CLASS__, 'fingerprint'), $pairs))),
		);
		sort($authority['completed_pair_fingerprints'], SORT_STRING);
		$authority['authority_fingerprint'] = WCOS_Mutation_Fingerprint::create('return_source_evolution_v1', $authority['original_order_id'], $authority);
		self::assert_valid($authority, $original_id, $split_operation_id);
		return $authority;
	}

	private static function terminal_fingerprint(array $terminal) {
		unset($terminal['evolution_fingerprint']);
		$original_id = isset($terminal['authority_after']['original_order_id']) ? $terminal['authority_after']['original_order_id'] : 0;
		return WCOS_Mutation_Fingerprint::create('return_terminal_evolution_v1', absint($original_id), $terminal);
	}

	private static function ids(array $ids) {
		$ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private static function fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}

	public static function sealed_signature($domain, $value) {
		$value = self::fingerprint($value);
		if ('' === $value) {
			throw new InvalidArgumentException(__('Return source-evolution input signature is malformed.', 'wc-order-splitter'));
		}
		return hash_hmac('sha256', 'wcos_return_evolution_' . sanitize_key((string) $domain) . '|' . $value, wp_salt('auth'));
	}
}
