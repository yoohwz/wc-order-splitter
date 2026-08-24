<?php

defined('ABSPATH') || exit;

/**
 * Internal gates for individual Split planning strategies.
 *
 * The global WCOS_Feature_Gates::SPLIT gate controls the hardened mutation
 * workflow. Strategy gates separately control which server-built planners may
 * expose production surfaces. They are code-only and cannot be overridden by
 * options, constants, filters, mu-plugins, or wp-config.php.
 */
final class WCOS_Split_Strategy_Gates {
	const MANUAL_QUANTITY = 'manual_quantity';
	const CATEGORY = 'category';
	const STOCK_STATUS = 'stock_status';

	private static $states = array(
		self::MANUAL_QUANTITY => true,
		self::CATEGORY => true,
		self::STOCK_STATUS => false,
	);

	public static function enabled($strategy) {
		$strategy = sanitize_key((string) $strategy);
		return isset(self::$states[$strategy]) && true === self::$states[$strategy];
	}

	public static function assert_enabled($strategy) {
		if (!self::enabled($strategy)) {
			throw new RuntimeException(__('This Split planning strategy is not enabled for production use.', 'wc-order-splitter'));
		}
	}
}
