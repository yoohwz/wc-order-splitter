<?php

defined('ABSPATH') || exit;

/** Closed, fingerprinted checkpoint vocabulary for Return pair recovery. */
final class WCOS_Return_Recovery_State_Graph {
	const PREPARED = 'prepared_no_write';
	const ORIGINAL_STAGING = 'original_commercial_staging';
	const ORIGINAL_PERSISTED = 'original_commercial_persisted';
	const CHILD_OWNERSHIP_NEUTRALIZING = 'child_stock_ownership_neutralizing';
	const CHILD_OWNERSHIP_NEUTRALIZED = 'child_stock_ownership_neutralized';
	const ORIGINAL_OWNERSHIP_ACTIVATED = 'original_stock_ownership_activated';
	const CHILD_RETIRED = 'child_retired';
	const CHILD_RELATION_PARTIAL = 'child_return_relation_partial';
	const CHILD_RELATION = 'child_return_relation_persisted';
	const ACTIVE_SPLIT_CLEANED = 'active_split_relation_cleaned';
	const RELATIONS_COMPLETE = 'return_relations_complete';
	const VERIFIED = 'pair_verified';
	const COMMITTED = 'committed';
	const COMPLETED = 'completed';
	const COMPENSATING = 'compensating';
	const ORIGINAL_RESTORED = 'original_restored';
	const CHILD_RESTORED = 'child_restored';
	const COMPENSATED = 'compensated';
	const MANUAL = 'manual_reconciliation';

	public static function stages() {
		return array(
			self::PREPARED, self::ORIGINAL_STAGING, self::ORIGINAL_PERSISTED,
			self::CHILD_OWNERSHIP_NEUTRALIZING, self::CHILD_OWNERSHIP_NEUTRALIZED,
			self::ORIGINAL_OWNERSHIP_ACTIVATED, self::CHILD_RETIRED, self::CHILD_RELATION_PARTIAL, self::CHILD_RELATION,
			self::ACTIVE_SPLIT_CLEANED, self::RELATIONS_COMPLETE, self::VERIFIED,
			self::COMMITTED, self::COMPLETED, self::COMPENSATING,
			self::ORIGINAL_RESTORED, self::CHILD_RESTORED, self::COMPENSATED, self::MANUAL,
		);
	}

	public static function seal_context(array $record, array $context) {
		if (!isset($context['return_recovery_state'])) {
			return $context;
		}
		$state = sanitize_key((string) $context['return_recovery_state']);
		if (!in_array($state, self::stages(), true)) {
			throw new InvalidArgumentException(__('Unknown Return recovery checkpoint state.', 'wc-order-splitter'));
		}
		$context['return_recovery_state'] = $state;
		$context['return_recovery_checkpoint_fingerprint'] = self::context_fingerprint($record, $context);
		return $context;
	}

	public static function assert_record(array $record) {
		$states = array();
		foreach (isset($record['checkpoints']) && is_array($record['checkpoints']) ? $record['checkpoints'] : array() as $checkpoint) {
			$context = isset($checkpoint['context']) && is_array($checkpoint['context']) ? $checkpoint['context'] : array();
			if (!isset($context['return_recovery_state'])) {
				continue;
			}
			$stored = self::fingerprint(isset($context['return_recovery_checkpoint_fingerprint']) ? $context['return_recovery_checkpoint_fingerprint'] : '');
			if ('' === $stored || !hash_equals($stored, self::context_fingerprint($record, $context))) {
				throw new RuntimeException(__('A Return recovery checkpoint failed its integrity fingerprint.', 'wc-order-splitter'));
			}
			$states[] = sanitize_key((string) $context['return_recovery_state']);
		}
		if (empty($states)) {
			throw new RuntimeException(__('The Return journal has no approved recovery checkpoint.', 'wc-order-splitter'));
		}
		$previous = null;
		foreach ($states as $state) {
			if (!in_array($state, self::stages(), true)
				|| (null !== $previous && !self::transition_allowed($previous, $state))) {
				throw new RuntimeException(__('The Return recovery checkpoint graph is invalid.', 'wc-order-splitter'));
			}
			$previous = $state;
		}
		return end($states);
	}

	public static function transition_allowed($from, $to) {
		$from = sanitize_key((string) $from);
		$to = sanitize_key((string) $to);
		if ($from === $to || self::MANUAL === $to) {
			return true;
		}
		$forward = array(
			self::PREPARED => array(self::ORIGINAL_STAGING, self::ORIGINAL_PERSISTED, self::COMPENSATING),
			self::ORIGINAL_STAGING => array(self::ORIGINAL_STAGING, self::ORIGINAL_PERSISTED, self::COMPENSATING),
			self::ORIGINAL_PERSISTED => array(self::CHILD_OWNERSHIP_NEUTRALIZING, self::CHILD_OWNERSHIP_NEUTRALIZED, self::COMPENSATING),
			self::CHILD_OWNERSHIP_NEUTRALIZING => array(self::CHILD_OWNERSHIP_NEUTRALIZED, self::COMPENSATING),
			self::CHILD_OWNERSHIP_NEUTRALIZED => array(self::ORIGINAL_OWNERSHIP_ACTIVATED, self::COMPENSATING),
			self::ORIGINAL_OWNERSHIP_ACTIVATED => array(self::CHILD_RETIRED, self::COMPENSATING),
			self::CHILD_RETIRED => array(self::CHILD_RELATION_PARTIAL, self::CHILD_RELATION, self::ACTIVE_SPLIT_CLEANED, self::COMPENSATING),
			self::CHILD_RELATION_PARTIAL => array(self::CHILD_RELATION, self::COMPENSATING),
			self::CHILD_RELATION => array(self::ACTIVE_SPLIT_CLEANED, self::RELATIONS_COMPLETE, self::COMPENSATING),
			self::ACTIVE_SPLIT_CLEANED => array(self::RELATIONS_COMPLETE, self::COMPENSATING),
			self::RELATIONS_COMPLETE => array(self::VERIFIED, self::COMPENSATING),
			self::VERIFIED => array(self::COMMITTED, self::COMPENSATING),
			self::COMMITTED => array(self::COMPLETED, self::COMPENSATING),
			self::COMPENSATING => array(self::ORIGINAL_RESTORED),
			self::ORIGINAL_RESTORED => array(self::CHILD_RESTORED),
			self::CHILD_RESTORED => array(self::COMPENSATED),
		);
		return isset($forward[$from]) && in_array($to, $forward[$from], true);
	}

	private static function context_fingerprint(array $record, array $context) {
		unset($context['return_recovery_checkpoint_fingerprint']);
		return WCOS_Mutation_Fingerprint::create(
			'return_recovery_checkpoint_v1',
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
