<?php

defined('ABSPATH') || exit;

/**
 * Central fail-closed workflow gates.
 *
 * Split, Duplicate, and Merge are the approved production mutation workflows.
 * Return and Bulk Return remain internally hard-off. Gate state is code, not
 * constants/options/filters, so another plugin, mu-plugin, or wp-config.php
 * cannot opt an unfinished workflow into production.
 */
final class WCOS_Feature_Gates {

	const SPLIT = 'split';
	const DUPLICATE = 'duplicate';
	const MERGE = 'merge';
	const RETURN_ORDER = 'return';
	const BULK_RETURN = 'bulk_return';

	private static $states = array(
		self::SPLIT => true,
		self::DUPLICATE => true,
		self::MERGE => true,
		self::RETURN_ORDER => true,
		self::BULK_RETURN => false,
	);

	public static function enabled($workflow) {
		$workflow = sanitize_key((string) $workflow);
		return isset(self::$states[$workflow]) && true === self::$states[$workflow];
	}

	public static function assert_enabled($workflow) {
		if (!self::enabled($workflow)) {
			throw new RuntimeException(__('This order mutation workflow is not enabled for production use.', 'wc-order-splitter'));
		}
	}

	public static function any_enabled() {
		foreach (self::$states as $enabled) {
			if (true === $enabled) {
				return true;
			}
		}
		return false;
	}
}
