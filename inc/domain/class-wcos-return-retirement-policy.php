<?php

defined('ABSPATH') || exit;

/**
 * Hard-off Return retirement and operational stock-ownership policy.
 *
 * This class records the policy proven by the recovery harness. It does not
 * register a status, endpoint, adapter, or production write surface.
 */
final class WCOS_Return_Retirement_Policy {

	const SCHEMA_VERSION = 1;
	const NON_FORCE_TRASH_ARCHIVE = 'non_force_trash_archive';
	const APPROVED = self::NON_FORCE_TRASH_ARCHIVE;
	const STOCK_OWNERSHIP_POLICY = 'child_neutralize_then_original_activate';
	const ORDER_STOCK_FLAG_POLICY = 'child_false_original_true_when_owned';

	public static function candidates() {
		return array(
			self::NON_FORCE_TRASH_ARCHIVE => array(
				'identifier' => self::NON_FORCE_TRASH_ARCHIVE,
				'preserve_commercial_record' => true,
				'active_economic_owner_after' => false,
				'normal_active_status_after' => false,
				'hard_delete' => false,
				'reversible_for_compensation' => true,
				'production_registered' => false,
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
			throw new RuntimeException(__('Return recovery requires the approved non-force trash archive policy.', 'wc-order-splitter'));
		}
		return self::assert_candidate($identifier);
	}

	public static function assert_candidate($identifier) {
		$identifier = sanitize_key((string) $identifier);
		$candidates = self::candidates();
		if (!isset($candidates[$identifier])) {
			throw new InvalidArgumentException(__('Unknown Return child-retirement candidate.', 'wc-order-splitter'));
		}
		return $candidates[$identifier];
	}

	public static function stock_policy() {
		return array(
			'ownership_transfer_order' => self::STOCK_OWNERSHIP_POLICY,
			'order_stock_flag_policy' => self::ORDER_STOCK_FLAG_POLICY,
			'physical_stock' => 'unchanged',
			'child_operational_reduced_stock_after' => 'absent',
			'original_operational_owner_after' => 'exact_plan_destination',
		);
	}
}
