<?php

defined('ABSPATH') || exit;

/**
 * Central fail-closed workflow gates.
 *
 * The global switch is a kill switch. Enabling it alone never enables a
 * workflow; the workflow-specific constant must also be explicitly true.
 */
final class WCOS_Feature_Gates {

	const SPLIT = 'split';
	const DUPLICATE = 'duplicate';
	const MERGE = 'merge';
	const RETURN_ORDER = 'return';
	const BULK_RETURN = 'bulk_return';

	public static function enabled($workflow) {
		if (!defined('WC_ORDER_SPLITTER_MUTATIONS_ENABLED') || true !== WC_ORDER_SPLITTER_MUTATIONS_ENABLED) {
			return false;
		}

		$constants = array(
			self::SPLIT => 'WC_ORDER_SPLITTER_SPLIT_ENABLED',
			self::DUPLICATE => 'WC_ORDER_SPLITTER_DUPLICATE_ENABLED',
			self::MERGE => 'WC_ORDER_SPLITTER_MERGE_ENABLED',
			self::RETURN_ORDER => 'WC_ORDER_SPLITTER_RETURN_ENABLED',
			self::BULK_RETURN => 'WC_ORDER_SPLITTER_BULK_RETURN_ENABLED',
		);

		$workflow = sanitize_key((string) $workflow);
		if (!isset($constants[$workflow])) {
			return false;
		}

		$constant = $constants[$workflow];
		return defined($constant) && true === constant($constant);
	}

	public static function any_enabled() {
		foreach (array(self::SPLIT, self::DUPLICATE, self::MERGE, self::RETURN_ORDER, self::BULK_RETURN) as $workflow) {
			if (self::enabled($workflow)) {
				return true;
			}
		}
		return false;
	}
}
