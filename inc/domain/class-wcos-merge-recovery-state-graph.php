<?php

defined('ABSPATH') || exit;

/**
 * Closed vocabulary and transition graph for durable Merge recovery states.
 */
final class WCOS_Merge_Recovery_State_Graph {
	const NO_WRITE = 'no_commercial_write';
	const TARGET_STAGING = 'target_staging';
	const TARGET_PERSISTED = 'target_persisted';
	const SOURCE_OWNERSHIP_MIGRATING = 'source_ownership_migrating';
	const SOURCE_OWNERSHIP_MIGRATED = 'source_ownership_migrated';
	const SOURCE_RETIRED = 'source_retired';
	const SOURCE_RELATION = 'source_relation_persisted';
	const RELATIONS_COMPLETE = 'relations_complete';
	const VERIFIED = 'commercial_verified';
	const COMMITTED = 'committed';
	const COMPLETED = 'completed';
	const COMPENSATING = 'compensating';
	const SOURCE_RESTORED = 'source_restored';
	const TARGET_RESTORED = 'target_restored';
	const COMPENSATED = 'compensated';
	const MANUAL = 'manual_reconciliation';

	public static function stages() {
		return array(
				self::NO_WRITE, self::TARGET_STAGING, self::TARGET_PERSISTED, self::SOURCE_OWNERSHIP_MIGRATING,
			self::SOURCE_OWNERSHIP_MIGRATED, self::SOURCE_RETIRED, self::SOURCE_RELATION,
			self::RELATIONS_COMPLETE, self::VERIFIED, self::COMMITTED, self::COMPLETED,
			self::COMPENSATING, self::SOURCE_RESTORED, self::TARGET_RESTORED,
			self::COMPENSATED, self::MANUAL,
		);
	}

	public static function assert_record(array $record) {
		$states = array();
		foreach (isset($record['checkpoints']) && is_array($record['checkpoints']) ? $record['checkpoints'] : array() as $checkpoint) {
			$context = isset($checkpoint['context']) && is_array($checkpoint['context']) ? $checkpoint['context'] : array();
			if (isset($context['merge_recovery_state'])) {
				$stored = self::fingerprint(isset($context['merge_recovery_checkpoint_fingerprint']) ? $context['merge_recovery_checkpoint_fingerprint'] : '');
				$expected = self::context_fingerprint($record, $context);
				if ('' === $stored || !hash_equals($stored, $expected)) {
					throw new RuntimeException(__('A Merge recovery checkpoint failed its integrity fingerprint.', 'wc-order-splitter'));
				}
				$states[] = sanitize_key((string) $context['merge_recovery_state']);
			}
		}
		if (empty($states)) {
			throw new RuntimeException(__('The Merge recovery journal has no approved checkpoint graph.', 'wc-order-splitter'));
		}
		$previous = null;
		foreach ($states as $state) {
			if (!in_array($state, self::stages(), true)
				|| (null !== $previous && !self::transition_allowed($previous, $state))) {
				throw new RuntimeException(__('The Merge recovery checkpoint graph is invalid.', 'wc-order-splitter'));
			}
			$previous = $state;
		}
		return end($states);
	}

	public static function seal_context(array $record, array $context) {
		if (!isset($context['merge_recovery_state'])) {
			return $context;
		}
		$state = sanitize_key((string) $context['merge_recovery_state']);
		if (!in_array($state, self::stages(), true)) {
			throw new InvalidArgumentException(__('Unknown Merge recovery checkpoint state.', 'wc-order-splitter'));
		}
		$context['merge_recovery_state'] = $state;
		$context['merge_recovery_checkpoint_fingerprint'] = self::context_fingerprint($record, $context);
		return $context;
	}

	public static function transition_allowed($from, $to) {
		$from = sanitize_key((string) $from);
		$to = sanitize_key((string) $to);
		if ($from === $to || self::MANUAL === $to) {
			return true;
		}
		$forward = array(
			self::NO_WRITE => array(self::TARGET_STAGING, self::TARGET_PERSISTED, self::COMPENSATING),
			self::TARGET_STAGING => array(self::TARGET_STAGING, self::TARGET_PERSISTED, self::COMPENSATING),
			self::TARGET_PERSISTED => array(self::SOURCE_OWNERSHIP_MIGRATING, self::SOURCE_OWNERSHIP_MIGRATED, self::COMPENSATING),
			self::SOURCE_OWNERSHIP_MIGRATING => array(self::SOURCE_OWNERSHIP_MIGRATED, self::COMPENSATING),
			self::SOURCE_OWNERSHIP_MIGRATED => array(self::SOURCE_RETIRED, self::COMPENSATING),
			self::SOURCE_RETIRED => array(self::SOURCE_RELATION, self::RELATIONS_COMPLETE, self::COMPENSATING),
			self::SOURCE_RELATION => array(self::RELATIONS_COMPLETE, self::COMPENSATING),
			self::RELATIONS_COMPLETE => array(self::VERIFIED, self::COMPENSATING),
			self::VERIFIED => array(self::COMMITTED, self::COMPENSATING),
			self::COMMITTED => array(self::COMPLETED, self::COMPENSATING),
			self::COMPENSATING => array(self::SOURCE_RESTORED),
			self::SOURCE_RESTORED => array(self::TARGET_RESTORED),
			self::TARGET_RESTORED => array(self::COMPENSATED),
		);
		return isset($forward[$from]) && in_array($to, $forward[$from], true);
	}

	private static function context_fingerprint(array $record, array $context) {
		unset($context['merge_recovery_checkpoint_fingerprint']);
		return WCOS_Mutation_Fingerprint::create(
			'merge_recovery_checkpoint_v1',
			absint(isset($record['source_order_id']) ? $record['source_order_id'] : 0),
			array(
				'operation_id' => sanitize_key(isset($record['operation_id']) ? (string) $record['operation_id'] : ''),
				'pair_fingerprint' => sanitize_key(isset($record['fingerprint']) ? (string) $record['fingerprint'] : ''),
				'context' => $context,
			)
		);
	}

	private static function fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}
}
