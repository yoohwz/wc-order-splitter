<?php

defined('ABSPATH') || exit;

/**
 * Central fail-closed workflow gates.
 *
 * Split, Duplicate, Merge, and Return are approved production mutation workflows.
 * Bulk Return is an approved production mutation workflow. Gate state is code,
 * not constants/options/filters, so another plugin, mu-plugin, or wp-config.php
 * cannot override the accepted production workflow map.
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
		self::BULK_RETURN => true,
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
