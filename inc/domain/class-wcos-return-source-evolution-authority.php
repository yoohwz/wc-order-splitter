<?php

defined('ABSPATH') || exit;

/**
 * Authenticated source history across hardened Split operations and Returns.
 *
 * Source relation metadata is a pointer set only. Authority comes from one
 * continuous chain of completed source-keyed Split journals and child-keyed
 * Return terminal journals whose exact commercial/relation signatures join.
 */
final class WCOS_Return_Source_Evolution_Authority {

	const SCHEMA_VERSION = 2;
	const POLICY_VERSION = 2;
	const LEGACY_SCHEMA_VERSION = 1;
	const LEGACY_POLICY_VERSION = 1;
	const COMPATIBILITY_SCHEMA_VERSION = 3;
	const COMPATIBILITY_POLICY_VERSION = 1;
	const TERMINAL_SCHEMA_VERSION = 1;

	/** Create one pair-scoped source snapshot for authenticated 1.4.11 compatibility. */
	public static function legacy_compatibility_snapshot(WC_Order $original, $split_operation_id, $child_id, array $hardened_active_child_ids) {
		$original_id = absint($original->get_id());
		$child_id = absint($child_id);
		$split_operation_id = sanitize_key((string) $split_operation_id);
		if (!$original_id || !$child_id || $original_id === $child_id || '' === $split_operation_id) {
			throw new InvalidArgumentException(__('Legacy Return source-evolution identity is incomplete.', 'wc-order-splitter'));
		}
		return self::compatibility_authority(
			$original_id,
			$split_operation_id,
			0,
			'',
			self::sealed_signature('commercial', WCOS_Order_Contract_Snapshot::source_signature($original)),
			self::sealed_signature('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($original)),
			array($child_id),
			array(),
			array(),
			$hardened_active_child_ids
		);
	}

	public static function resolve(WC_Order $original, $split_operation_id, $base_commercial_signature, $base_relation_signature, array $initial_child_ids, array $split_lineages = array()) {
		$original_id = absint($original->get_id());
		$split_operation_id = sanitize_key((string) $split_operation_id);
		$initial_child_ids = self::ids($initial_child_ids);
		if (!$original_id || '' === $split_operation_id || empty($initial_child_ids) || empty($split_lineages)) {
			throw new RuntimeException(__('Return source-evolution base authority is incomplete.', 'wc-order-splitter'));
		}

		$lineages = array();
		$events = array();
		$all_target_ids = array();
		foreach ($split_lineages as $lineage) {
			$lineage = self::assert_split_lineage($lineage, $original_id);
			$operation_id = $lineage['operation_id'];
			if (isset($lineages[$operation_id])) {
				throw new RuntimeException(__('Split source evolution contains duplicate operation authority.', 'wc-order-splitter'));
			}
			foreach ($lineage['target_child_ids'] as $target_id) {
				if (isset($all_target_ids[$target_id])) {
					throw new RuntimeException(__('A hardened Split child has ambiguous operation ownership.', 'wc-order-splitter'));
				}
				$all_target_ids[$target_id] = $operation_id;
			}
			$lineages[$operation_id] = $lineage;
			$events[] = array(
				'type' => 'split',
				'operation_id' => $operation_id,
				'before_commercial_signature' => $lineage['before_commercial_signature'],
				'before_relation_signature' => $lineage['before_relation_signature'],
				'after_commercial_signature' => $lineage['after_commercial_signature'],
				'after_relation_signature' => $lineage['after_relation_signature'],
				'before_active_child_ids' => $lineage['before_active_child_ids'],
				'after_active_child_ids' => $lineage['after_active_child_ids'],
				'target_child_ids' => $lineage['target_child_ids'],
				'lineage_fingerprint' => $lineage['lineage_fingerprint'],
			);
		}
		if (!isset($lineages[$split_operation_id])) {
			throw new RuntimeException(__('The requested Split operation is absent from authenticated source lineage.', 'wc-order-splitter'));
		}
		$anchor = $lineages[$split_operation_id];
		if ($anchor['target_child_ids'] !== $initial_child_ids
			|| !hash_equals($anchor['after_commercial_signature'], self::sealed_signature('commercial', $base_commercial_signature))
			|| !hash_equals($anchor['after_relation_signature'], self::sealed_signature('relation', $base_relation_signature))) {
			throw new RuntimeException(__('The requested Split operation does not match its authenticated source transition.', 'wc-order-splitter'));
		}

		$returned_event_children = array();
		foreach (WCOS_Return_Participation::completed_authorities_for_original($original) as $pointer) {
			$child = wc_get_order($pointer['child_order_id']);
			$record = $child instanceof WC_Order ? WCOS_Operation_Journal::get($child, $pointer['operation_id']) : null;
			if (!$child instanceof WC_Order || !is_array($record)
				|| !WCOS_Return_Journal_Context::validates_participant(
					$record, $original_id, 'target', $child->get_id(), $pointer['pair_fingerprint'], $pointer['operation_id']
				)) {
				throw new RuntimeException(__('A Return source-evolution pointer lacks an authoritative child journal.', 'wc-order-splitter'));
			}
			$pair = WCOS_Return_Journal_Context::pair_from_record($record);
			$result = WCOS_Return_Journal_Context::terminal_result_from_record($record);
			if (is_array($pair)
				&& WCOS_Legacy_Return_Compatibility_Authority::LINEAGE_BASIS === (isset($pair['lineage_basis']) ? $pair['lineage_basis'] : '')) {
				$evolution = isset($result['source_evolution']) ? $result['source_evolution'] : array();
				self::assert_terminal_evolution($evolution, $pair);
				$before = $evolution['authority_before'];
				$after = $evolution['authority_after'];
				$events[] = array(
					'type' => 'compatibility_return',
					'operation_id' => sanitize_key((string) $result['operation_id']),
					'before_commercial_signature' => $before['original_commercial_signature'],
					'before_relation_signature' => $before['original_relation_signature'],
					'after_commercial_signature' => $after['original_commercial_signature'],
					'after_relation_signature' => $after['original_relation_signature'],
					'before_active_child_ids' => $before['hardened_active_child_ids'],
					'after_active_child_ids' => $after['hardened_active_child_ids'],
				);
				continue;
			}
			$operation_id = sanitize_key((string) $result['split_operation_id']);
			$child_id = absint($result['child_order_id']);
			if (!isset($lineages[$operation_id]) || !isset($all_target_ids[$child_id])
				|| $all_target_ids[$child_id] !== $operation_id || isset($returned_event_children[$child_id])) {
				throw new RuntimeException(__('A completed Return has ambiguous or missing hardened Split provenance.', 'wc-order-splitter'));
			}
			$state = WCOS_Return_Participation::state_for_pair($child, $original, $pointer['operation_id'], $pointer['pair_fingerprint']);
			if (empty($state['child']) || empty($state['original']) || empty($state['active_split_removed'])) {
				throw new RuntimeException(__('Completed Return participation is incomplete or the child is still active.', 'wc-order-splitter'));
			}
			$expected_child_signature = $lineages[$operation_id]['target_child_signatures'][$child_id];
			if (!is_array($pair) || !hash_equals(
				$pair['child_signature_before'], self::sealed_signature('child_commercial', $expected_child_signature)
			)) {
				throw new RuntimeException(__('A completed Return child signature does not match its Split journal.', 'wc-order-splitter'));
			}
			$evolution = $result['source_evolution'];
			self::assert_terminal_evolution($evolution, $pair);
			$before = $evolution['authority_before'];
			$after = $evolution['authority_after'];
			$events[] = array(
				'type' => 'return',
				'operation_id' => sanitize_key((string) $result['operation_id']),
				'split_operation_id' => $operation_id,
				'child_order_id' => $child_id,
				'pair_fingerprint' => self::fingerprint($result['pair_fingerprint']),
				'before_commercial_signature' => $before['original_commercial_signature'],
				'before_relation_signature' => $before['original_relation_signature'],
				'after_commercial_signature' => $after['original_commercial_signature'],
				'after_relation_signature' => $after['original_relation_signature'],
				'before_active_child_ids' => $before['active_child_ids'],
				'after_active_child_ids' => $after['active_child_ids'],
				'before_returned_child_ids' => $before['returned_child_ids'],
				'after_returned_child_ids' => $after['returned_child_ids'],
				'before_pair_fingerprints' => $before['completed_pair_fingerprints'],
				'after_pair_fingerprints' => $after['completed_pair_fingerprints'],
				'before_split_lineages' => self::authority_split_lineages($before),
				'after_split_lineages' => self::authority_split_lineages($after),
				'authority_after_fingerprint' => $after['authority_fingerprint'],
			);
			$returned_event_children[$child_id] = true;
		}

		$ordered = self::order_events($events);
		$current_active = array();
		$current_returned = array();
		$current_pairs = array();
		$current_lineages = array();
		$current_commercial = '';
		$current_relation = '';
		$last_return_authority = '';
		foreach ($ordered as $ordinal => $event) {
			if (0 === $ordinal) {
				if (!in_array($event['type'], array('split', 'compatibility_return'), true) || !empty($event['before_active_child_ids'])) {
					throw new RuntimeException(__('Hardened source evolution does not begin at an empty Split relation state.', 'wc-order-splitter'));
				}
				$current_commercial = $event['before_commercial_signature'];
				$current_relation = $event['before_relation_signature'];
			}
			if (!hash_equals($current_commercial, $event['before_commercial_signature'])
				|| !hash_equals($current_relation, $event['before_relation_signature'])
				|| $current_active !== $event['before_active_child_ids']) {
				throw new RuntimeException(__('Hardened source events do not form one exact state-transition chain.', 'wc-order-splitter'));
			}

			if ('split' === $event['type']) {
				if (array_intersect($event['target_child_ids'], array_merge($current_active, $current_returned))) {
					throw new RuntimeException(__('A Split source transition reuses an existing child identity.', 'wc-order-splitter'));
				}
				$expected_active = self::ids(array_merge($current_active, $event['target_child_ids']));
				if ($expected_active !== $event['after_active_child_ids']) {
					throw new RuntimeException(__('A Split source transition does not preserve all active hardened siblings.', 'wc-order-splitter'));
				}
				$current_lineages[] = $event['lineage_fingerprint'];
			} elseif ('compatibility_return' === $event['type']) {
				if ($event['after_active_child_ids'] !== $current_active) {
					throw new RuntimeException(__('Legacy compatibility Return changed unrelated hardened child authority.', 'wc-order-splitter'));
				}
			} else {
				$child_id = $event['child_order_id'];
				$expected_returned = self::ids(array_merge($current_returned, array($child_id)));
				$expected_active = array_values(array_filter($current_active, static function($candidate) use ($child_id) {
					return $candidate !== $child_id;
				}));
				$expected_pairs = array_values(array_unique(array_merge($current_pairs, array($event['pair_fingerprint']))));
				sort($expected_pairs, SORT_STRING);
				if (!in_array($child_id, $current_active, true)
					|| $event['before_returned_child_ids'] !== $current_returned
					|| $event['after_returned_child_ids'] !== $expected_returned
					|| $event['before_pair_fingerprints'] !== $current_pairs
					|| $event['after_pair_fingerprints'] !== $expected_pairs
					|| $event['after_active_child_ids'] !== $expected_active
					|| (!empty($event['before_split_lineages']) && $event['before_split_lineages'] !== $current_lineages)
					|| (!empty($event['after_split_lineages']) && $event['after_split_lineages'] !== $current_lineages)) {
					throw new RuntimeException(__('A completed Return does not consume the exact global hardened sibling state.', 'wc-order-splitter'));
				}
				$current_returned = $expected_returned;
				$current_pairs = $expected_pairs;
				$last_return_authority = $event['authority_after_fingerprint'];
			}
			$current_active = $event['after_active_child_ids'];
			$current_commercial = $event['after_commercial_signature'];
			$current_relation = $event['after_relation_signature'];
		}

		$actual_active = self::ids((array) $original->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true));
		$actual_commercial = self::sealed_signature('commercial', WCOS_Order_Contract_Snapshot::source_signature($original));
		$actual_relation = self::sealed_signature('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($original));
		$accounted = self::ids(array_merge($current_active, $current_returned));
		$targets = self::ids(array_keys($all_target_ids));
		if ($actual_active !== $current_active || $accounted !== $targets
			|| !hash_equals($current_commercial, $actual_commercial)
			|| !hash_equals($current_relation, $actual_relation)) {
			throw new RuntimeException(__('The current original order diverged from authenticated Split/Return source evolution.', 'wc-order-splitter'));
		}

		return self::authority(
			$original_id, $split_operation_id, count($current_returned), $last_return_authority,
			$current_commercial, $current_relation, $current_active, $current_returned, $current_pairs, $current_lineages
		);
	}

	public static function create_terminal_evolution(array $pair, WC_Order $original) {
		$before = isset($pair['source_evolution_authority']) && is_array($pair['source_evolution_authority'])
			? $pair['source_evolution_authority'] : array();
		self::assert_valid($before, $pair['original_order_id'], $pair['split_operation_id']);
		$child_id = absint($pair['child_order_id']);
		$active = array_values(array_filter($before['active_child_ids'], static function($candidate) use ($child_id) {
			return absint($candidate) !== $child_id;
		}));
		if (count($active) === count($before['active_child_ids']) || in_array($child_id, $before['returned_child_ids'], true)) {
			throw new RuntimeException(__('Return source evolution cannot consume an inactive or already-returned child.', 'wc-order-splitter'));
		}
		$returned = self::ids(array_merge($before['returned_child_ids'], array($child_id)));
		$pairs = array_values(array_unique(array_merge($before['completed_pair_fingerprints'], array($pair['pair_fingerprint']))));
		sort($pairs, SORT_STRING);
		if (self::COMPATIBILITY_SCHEMA_VERSION === (int) $before['schema_version']) {
			$after = self::compatibility_authority(
				$pair['original_order_id'], $pair['split_operation_id'], $before['sequence'] + 1,
				$before['authority_fingerprint'],
				self::sealed_signature('commercial', WCOS_Order_Contract_Snapshot::source_signature($original)),
				self::sealed_signature('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($original)),
				$active, $returned, $pairs, $before['hardened_active_child_ids']
			);
		} elseif (self::LEGACY_SCHEMA_VERSION === (int) $before['schema_version']) {
			$after = self::legacy_authority(
				$pair['original_order_id'], $pair['split_operation_id'], $before['sequence'] + 1,
				$before['authority_fingerprint'],
				self::sealed_signature('commercial', WCOS_Order_Contract_Snapshot::source_signature($original)),
				self::sealed_signature('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($original)),
				$active, $returned, $pairs
			);
		} else {
			$after = self::authority(
				$pair['original_order_id'], $pair['split_operation_id'], $before['sequence'] + 1,
				$before['authority_fingerprint'],
				self::sealed_signature('commercial', WCOS_Order_Contract_Snapshot::source_signature($original)),
				self::sealed_signature('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($original)),
				$active, $returned, $pairs, $before['split_lineage_fingerprints']
			);
		}
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
			|| (int) $after['schema_version'] !== (int) $before['schema_version']
			|| $after['sequence'] !== $before['sequence'] + 1
			|| !hash_equals($after['predecessor_fingerprint'], $before['authority_fingerprint'])
			|| (isset($before['split_lineage_fingerprints'])
				&& $before['split_lineage_fingerprints'] !== $after['split_lineage_fingerprints'])) {
			throw new RuntimeException(__('Return terminal source-evolution evidence failed integrity verification.', 'wc-order-splitter'));
		}
		if (self::COMPATIBILITY_SCHEMA_VERSION === (int) $before['schema_version']
			&& ($before['hardened_active_child_ids'] !== $after['hardened_active_child_ids']
				|| WCOS_Legacy_Return_Compatibility_Authority::LINEAGE_BASIS !== $before['lineage_basis']
				|| $before['lineage_basis'] !== $after['lineage_basis'])) {
			throw new RuntimeException(__('Legacy Return terminal evolution changed unrelated hardened lineage authority.', 'wc-order-splitter'));
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
		$schema = isset($authority['schema_version']) ? (int) $authority['schema_version'] : 0;
		if (self::LEGACY_SCHEMA_VERSION === $schema) {
			return self::assert_legacy_valid($authority, $original_order_id, $split_operation_id);
		}
		if (self::COMPATIBILITY_SCHEMA_VERSION === $schema) {
			return self::assert_compatibility_valid($authority, $original_order_id, $split_operation_id);
		}
		$expected = array(
			'active_child_ids', 'authority_fingerprint', 'completed_pair_fingerprints', 'original_commercial_signature',
			'original_order_id', 'original_relation_signature', 'policy_version', 'predecessor_fingerprint',
			'returned_child_ids', 'schema_version', 'sequence', 'split_lineage_fingerprints', 'split_operation_id',
		);
		$actual = array_keys($authority);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		$stored = self::fingerprint(isset($authority['authority_fingerprint']) ? $authority['authority_fingerprint'] : '');
		$copy = $authority;
		unset($copy['authority_fingerprint']);
		if ($actual !== $expected || self::SCHEMA_VERSION !== $schema
			|| self::POLICY_VERSION !== (int) $authority['policy_version']
			|| !$authority['original_order_id'] || '' === sanitize_key((string) $authority['split_operation_id'])
			|| !is_array($authority['active_child_ids']) || !is_array($authority['returned_child_ids'])
			|| !is_array($authority['completed_pair_fingerprints']) || !is_array($authority['split_lineage_fingerprints'])
			|| empty($authority['split_lineage_fingerprints'])
			|| self::ids($authority['active_child_ids']) !== array_values($authority['active_child_ids'])
			|| self::ids($authority['returned_child_ids']) !== array_values($authority['returned_child_ids'])
			|| array_intersect($authority['active_child_ids'], $authority['returned_child_ids'])
			|| '' === self::fingerprint($authority['original_commercial_signature'])
			|| '' === self::fingerprint($authority['original_relation_signature']) || '' === $stored
			|| !hash_equals($stored, WCOS_Mutation_Fingerprint::create('return_source_evolution_v2', absint($authority['original_order_id']), $copy))) {
			throw new RuntimeException(__('Return source-evolution authority failed integrity verification.', 'wc-order-splitter'));
		}
		foreach (array_merge($authority['completed_pair_fingerprints'], $authority['split_lineage_fingerprints']) as $value) {
			if ('' === self::fingerprint($value)) {
				throw new RuntimeException(__('Return source-evolution journal evidence is malformed.', 'wc-order-splitter'));
			}
		}
		if (count($authority['split_lineage_fingerprints']) !== count(array_unique($authority['split_lineage_fingerprints']))
			|| (int) $authority['sequence'] !== count($authority['returned_child_ids'])
			|| (int) $authority['sequence'] !== count($authority['completed_pair_fingerprints'])
			|| (0 === (int) $authority['sequence']) !== ('' === (string) $authority['predecessor_fingerprint'])
			|| ((int) $authority['sequence'] > 0 && '' === self::fingerprint($authority['predecessor_fingerprint']))
			|| ($original_order_id && absint($original_order_id) !== absint($authority['original_order_id']))
			|| ('' !== (string) $split_operation_id && sanitize_key((string) $split_operation_id) !== $authority['split_operation_id'])) {
			throw new RuntimeException(__('Return source-evolution authority is not canonical for this lineage.', 'wc-order-splitter'));
		}
		return true;
	}

	private static function assert_legacy_valid(array $authority, $original_order_id, $split_operation_id) {
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
		if ($actual !== $expected || self::LEGACY_POLICY_VERSION !== (int) $authority['policy_version']
			|| !$authority['original_order_id'] || '' === sanitize_key((string) $authority['split_operation_id'])
			|| !is_array($authority['active_child_ids']) || !is_array($authority['returned_child_ids'])
			|| !is_array($authority['completed_pair_fingerprints'])
			|| self::ids($authority['active_child_ids']) !== array_values($authority['active_child_ids'])
			|| self::ids($authority['returned_child_ids']) !== array_values($authority['returned_child_ids'])
			|| array_intersect($authority['active_child_ids'], $authority['returned_child_ids'])
			|| '' === self::fingerprint($authority['original_commercial_signature'])
			|| '' === self::fingerprint($authority['original_relation_signature']) || '' === $stored
			|| !hash_equals($stored, WCOS_Mutation_Fingerprint::create('return_source_evolution_v1', absint($authority['original_order_id']), $copy))) {
			throw new RuntimeException(__('Legacy Return source-evolution authority failed integrity verification.', 'wc-order-splitter'));
		}
		foreach ($authority['completed_pair_fingerprints'] as $value) {
			if ('' === self::fingerprint($value)) {
				throw new RuntimeException(__('Legacy Return source-evolution pair evidence is malformed.', 'wc-order-splitter'));
			}
		}
		if ((int) $authority['sequence'] !== count($authority['returned_child_ids'])
			|| (int) $authority['sequence'] !== count($authority['completed_pair_fingerprints'])
			|| (0 === (int) $authority['sequence']) !== ('' === (string) $authority['predecessor_fingerprint'])
			|| ((int) $authority['sequence'] > 0 && '' === self::fingerprint($authority['predecessor_fingerprint']))
			|| ($original_order_id && absint($original_order_id) !== absint($authority['original_order_id']))
			|| ('' !== (string) $split_operation_id && sanitize_key((string) $split_operation_id) !== $authority['split_operation_id'])) {
			throw new RuntimeException(__('Legacy Return source-evolution authority is not canonical for this lineage.', 'wc-order-splitter'));
		}
		return true;
	}

	private static function assert_compatibility_valid(array $authority, $original_order_id, $split_operation_id) {
		$expected = array(
			'active_child_ids', 'authority_fingerprint', 'completed_pair_fingerprints', 'hardened_active_child_ids',
			'lineage_basis', 'original_commercial_signature', 'original_order_id', 'original_relation_signature',
			'policy_version', 'predecessor_fingerprint', 'returned_child_ids', 'schema_version', 'sequence', 'split_operation_id',
		);
		$actual = array_keys($authority);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		$stored = self::fingerprint(isset($authority['authority_fingerprint']) ? $authority['authority_fingerprint'] : '');
		$copy = $authority;
		unset($copy['authority_fingerprint']);
		if ($actual !== $expected || self::COMPATIBILITY_POLICY_VERSION !== (int) $authority['policy_version']
			|| WCOS_Legacy_Return_Compatibility_Authority::LINEAGE_BASIS !== (isset($authority['lineage_basis']) ? $authority['lineage_basis'] : '')
			|| !$authority['original_order_id'] || '' === sanitize_key((string) $authority['split_operation_id'])
			|| !is_array($authority['active_child_ids']) || !is_array($authority['returned_child_ids'])
			|| !is_array($authority['completed_pair_fingerprints']) || !is_array($authority['hardened_active_child_ids'])
			|| self::ids($authority['active_child_ids']) !== array_values($authority['active_child_ids'])
			|| self::ids($authority['returned_child_ids']) !== array_values($authority['returned_child_ids'])
			|| self::ids($authority['hardened_active_child_ids']) !== array_values($authority['hardened_active_child_ids'])
			|| array_intersect($authority['active_child_ids'], $authority['returned_child_ids'])
			|| '' === self::fingerprint($authority['original_commercial_signature'])
			|| '' === self::fingerprint($authority['original_relation_signature']) || '' === $stored
			|| !hash_equals($stored, WCOS_Mutation_Fingerprint::create('return_source_evolution_legacy_compat_v1', absint($authority['original_order_id']), $copy))) {
			throw new RuntimeException(__('Legacy compatibility source-evolution authority failed integrity verification.', 'wc-order-splitter'));
		}
		foreach ($authority['completed_pair_fingerprints'] as $value) {
			if ('' === self::fingerprint($value)) {
				throw new RuntimeException(__('Legacy compatibility source-evolution pair evidence is malformed.', 'wc-order-splitter'));
			}
		}
		if ((int) $authority['sequence'] !== count($authority['returned_child_ids'])
			|| (int) $authority['sequence'] !== count($authority['completed_pair_fingerprints'])
			|| (0 === (int) $authority['sequence']) !== ('' === (string) $authority['predecessor_fingerprint'])
			|| ((int) $authority['sequence'] > 0 && '' === self::fingerprint($authority['predecessor_fingerprint']))
			|| ($original_order_id && absint($original_order_id) !== absint($authority['original_order_id']))
			|| ('' !== (string) $split_operation_id && sanitize_key((string) $split_operation_id) !== $authority['split_operation_id'])) {
			throw new RuntimeException(__('Legacy compatibility source-evolution authority is not canonical for this pair.', 'wc-order-splitter'));
		}
		return true;
	}

	private static function authority($original_id, $split_operation_id, $sequence, $predecessor, $commercial, $relation, array $active, array $returned, array $pairs, array $lineages) {
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
			'split_lineage_fingerprints' => array_values(array_map(array(__CLASS__, 'fingerprint'), $lineages)),
		);
		sort($authority['completed_pair_fingerprints'], SORT_STRING);
		$authority['authority_fingerprint'] = WCOS_Mutation_Fingerprint::create('return_source_evolution_v2', $authority['original_order_id'], $authority);
		self::assert_valid($authority, $original_id, $split_operation_id);
		return $authority;
	}

	private static function legacy_authority($original_id, $split_operation_id, $sequence, $predecessor, $commercial, $relation, array $active, array $returned, array $pairs) {
		$authority = array(
			'schema_version' => self::LEGACY_SCHEMA_VERSION,
			'policy_version' => self::LEGACY_POLICY_VERSION,
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

	private static function compatibility_authority($original_id, $split_operation_id, $sequence, $predecessor, $commercial, $relation, array $active, array $returned, array $pairs, array $hardened_active) {
		$authority = array(
			'schema_version' => self::COMPATIBILITY_SCHEMA_VERSION,
			'policy_version' => self::COMPATIBILITY_POLICY_VERSION,
			'lineage_basis' => WCOS_Legacy_Return_Compatibility_Authority::LINEAGE_BASIS,
			'original_order_id' => absint($original_id),
			'split_operation_id' => sanitize_key((string) $split_operation_id),
			'sequence' => absint($sequence),
			'predecessor_fingerprint' => self::fingerprint($predecessor),
			'original_commercial_signature' => self::fingerprint($commercial),
			'original_relation_signature' => self::fingerprint($relation),
			'active_child_ids' => self::ids($active),
			'returned_child_ids' => self::ids($returned),
			'completed_pair_fingerprints' => array_values(array_unique(array_map(array(__CLASS__, 'fingerprint'), $pairs))),
			'hardened_active_child_ids' => self::ids($hardened_active),
		);
		sort($authority['completed_pair_fingerprints'], SORT_STRING);
		$authority['authority_fingerprint'] = WCOS_Mutation_Fingerprint::create('return_source_evolution_legacy_compat_v1', $authority['original_order_id'], $authority);
		self::assert_valid($authority, $original_id, $split_operation_id);
		return $authority;
	}

	private static function assert_split_lineage($lineage, $original_id) {
		$expected = array(
			'after_active_child_ids', 'after_commercial_signature', 'after_relation_signature',
			'before_active_child_ids', 'before_commercial_signature', 'before_relation_signature',
			'lineage_fingerprint', 'operation_fingerprint', 'operation_id', 'plan_child_keys',
			'target_child_ids', 'target_child_keys', 'target_child_signatures',
		);
		if (!is_array($lineage)) {
			throw new RuntimeException(__('Split lineage evidence is malformed.', 'wc-order-splitter'));
		}
		$actual = array_keys($lineage);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		$operation_id = sanitize_key(isset($lineage['operation_id']) ? (string) $lineage['operation_id'] : '');
		$target_ids = isset($lineage['target_child_ids']) && is_array($lineage['target_child_ids']) ? self::ids($lineage['target_child_ids']) : array();
		$before_ids = isset($lineage['before_active_child_ids']) && is_array($lineage['before_active_child_ids']) ? self::ids($lineage['before_active_child_ids']) : array();
		$after_ids = isset($lineage['after_active_child_ids']) && is_array($lineage['after_active_child_ids']) ? self::ids($lineage['after_active_child_ids']) : array();
		$copy = $lineage;
		$stored = self::fingerprint(isset($copy['lineage_fingerprint']) ? $copy['lineage_fingerprint'] : '');
		unset($copy['lineage_fingerprint']);
		if ($actual !== $expected || '' === $operation_id || $operation_id !== $lineage['operation_id'] || empty($target_ids)
			|| $target_ids !== array_values($lineage['target_child_ids'])
			|| $before_ids !== array_values($lineage['before_active_child_ids'])
			|| $after_ids !== array_values($lineage['after_active_child_ids'])
			|| $after_ids !== self::ids(array_merge($before_ids, $target_ids))
			|| array_intersect($before_ids, $target_ids)
			|| '' === self::fingerprint($lineage['operation_fingerprint']) || '' === $stored
			|| !hash_equals($stored, WCOS_Mutation_Fingerprint::create('return_split_lineage_v1', $original_id, $copy))) {
			throw new RuntimeException(__('Split lineage evidence failed integrity verification.', 'wc-order-splitter'));
		}
		foreach (array('before_commercial_signature', 'before_relation_signature', 'after_commercial_signature', 'after_relation_signature') as $field) {
			if ('' === self::fingerprint($lineage[$field])) {
				throw new RuntimeException(__('Split lineage transition signatures are malformed.', 'wc-order-splitter'));
			}
		}
		if (!is_array($lineage['target_child_signatures']) || !is_array($lineage['target_child_keys']) || !is_array($lineage['plan_child_keys'])
			|| array_map('absint', array_keys($lineage['target_child_signatures'])) !== $target_ids
			|| array_map('absint', array_keys($lineage['target_child_keys'])) !== $target_ids) {
			throw new RuntimeException(__('Split lineage target authority is incomplete.', 'wc-order-splitter'));
		}
		foreach ($lineage['target_child_signatures'] as $signature) {
			if ('' === self::fingerprint($signature)) {
				throw new RuntimeException(__('Split lineage target signature is malformed.', 'wc-order-splitter'));
			}
		}
		$keys = array_values($lineage['target_child_keys']);
		sort($keys, SORT_STRING);
		if ($keys !== $lineage['plan_child_keys'] || count($keys) !== count(array_unique($keys))) {
			throw new RuntimeException(__('Split lineage child-key authority is ambiguous.', 'wc-order-splitter'));
		}
		return $lineage;
	}

	private static function order_events(array $events) {
		if (empty($events)) {
			throw new RuntimeException(__('Hardened source evolution contains no mutation events.', 'wc-order-splitter'));
		}
		$before = array();
		$after = array();
		foreach ($events as $index => $event) {
			$before_key = self::state_key($event['before_commercial_signature'], $event['before_relation_signature']);
			$after_key = self::state_key($event['after_commercial_signature'], $event['after_relation_signature']);
			$before[$before_key][] = $index;
			$after[$after_key][] = $index;
		}
		$roots = array();
		foreach ($events as $index => $event) {
			$key = self::state_key($event['before_commercial_signature'], $event['before_relation_signature']);
			if (!isset($after[$key])) {
				$roots[] = $index;
			}
		}
		if (1 !== count($roots)) {
			throw new RuntimeException(__('Hardened source evolution is disconnected or ambiguous.', 'wc-order-splitter'));
		}
		$ordered = array();
		$seen = array();
		$index = reset($roots);
		while (null !== $index) {
			if (isset($seen[$index])) {
				throw new RuntimeException(__('Hardened source evolution contains a cycle.', 'wc-order-splitter'));
			}
			$seen[$index] = true;
			$event = $events[$index];
			$ordered[] = $event;
			$key = self::state_key($event['after_commercial_signature'], $event['after_relation_signature']);
			$candidates = array_values(array_filter(isset($before[$key]) ? $before[$key] : array(), static function($candidate) use ($seen) {
				return !isset($seen[$candidate]);
			}));
			if (count($candidates) > 1) {
				throw new RuntimeException(__('Hardened source evolution contains a state-transition fork.', 'wc-order-splitter'));
			}
			$index = empty($candidates) ? null : reset($candidates);
		}
		if (count($ordered) !== count($events)) {
			throw new RuntimeException(__('Hardened source evolution contains disconnected events.', 'wc-order-splitter'));
		}
		return $ordered;
	}

	private static function authority_split_lineages(array $authority) {
		return isset($authority['split_lineage_fingerprints']) && is_array($authority['split_lineage_fingerprints'])
			? array_values($authority['split_lineage_fingerprints']) : array();
	}

	private static function state_key($commercial, $relation) {
		$commercial = self::fingerprint($commercial);
		$relation = self::fingerprint($relation);
		if ('' === $commercial || '' === $relation) {
			throw new RuntimeException(__('Source evolution state signatures are malformed.', 'wc-order-splitter'));
		}
		return $commercial . '|' . $relation;
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
