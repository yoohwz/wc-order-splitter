<?php

defined('ABSPATH') || exit;

/** Binding source-retirement policy and the retained comparison evidence. */
final class WCOS_Merge_Retirement_Policy {

	const SCHEMA_VERSION = 2;
	const NON_FORCE_TRASH_ARCHIVE = 'non_force_trash_archive';
	const DEDICATED_MERGED_ARCHIVE = 'dedicated_merged_archive';
	const APPROVED = self::NON_FORCE_TRASH_ARCHIVE;

	public static function candidates() {
		return array(
			self::NON_FORCE_TRASH_ARCHIVE => array(
				'identifier' => self::NON_FORCE_TRASH_ARCHIVE,
				'preserve_commercial_record' => true,
				'active_economic_owner_after' => false,
				'normal_active_status_after' => false,
				'hard_delete' => false,
				'production_selected' => true,
			),
			self::DEDICATED_MERGED_ARCHIVE => array(
				'identifier' => self::DEDICATED_MERGED_ARCHIVE,
				'preserve_commercial_record' => true,
				'active_economic_owner_after' => false,
				'normal_active_status_after' => false,
				'hard_delete' => false,
				'production_selected' => false,
			),
		);
	}

	public static function identifiers() {
		$identifiers = array_keys(self::candidates());
		sort($identifiers, SORT_STRING);
		return $identifiers;
	}

	public static function approved_identifier() {
		return self::APPROVED;
	}

	public static function assert_approved($identifier) {
		$identifier = sanitize_key((string) $identifier);
		if (self::APPROVED !== $identifier) {
			throw new RuntimeException(__('Executable Merge authority requires the approved non-force trash archive policy.', 'wc-order-splitter'));
		}
		return self::assert_candidate($identifier);
	}

	public static function assert_candidate($identifier) {
		$identifier = sanitize_key((string) $identifier);
		$candidates = self::candidates();
		if (!isset($candidates[$identifier])) {
			throw new InvalidArgumentException(__('Unknown Merge source-retirement candidate.', 'wc-order-splitter'));
		}
		return $candidates[$identifier];
	}

	public static function assert_archive_preserved($before_signature, $after_signature) {
		$before_signature = sanitize_key((string) $before_signature);
		$after_signature = sanitize_key((string) $after_signature);
		if ('' === $before_signature || !hash_equals($before_signature, $after_signature)) {
			throw new RuntimeException(__('The retired source commercial archive no longer matches its pre-Merge evidence.', 'wc-order-splitter'));
		}
	}

	public static function assert_active_ownership_conserved($before_active_signature, $after_target_signature) {
		$before_active_signature = sanitize_key((string) $before_active_signature);
		$after_target_signature = sanitize_key((string) $after_target_signature);
		if ('' === $before_active_signature || !hash_equals($before_active_signature, $after_target_signature)) {
			throw new RuntimeException(__('The active target does not conserve the pre-Merge active economic ownership contract.', 'wc-order-splitter'));
		}
	}
}
