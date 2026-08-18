<?php

/**
 * CI-only MU plugin installed before Order Splitter activation.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action(
	'doing_it_wrong_run',
	static function($function_name, $message, $version) {
		if ('_load_textdomain_just_in_time' !== $function_name || false === strpos((string) $message, 'woocommerce')) {
			return;
		}

		$record = array(
			'function' => (string) $function_name,
			'message' => wp_strip_all_tags((string) $message),
			'version' => (string) $version,
			'backtrace' => function_exists('wp_debug_backtrace_summary')
				? wp_debug_backtrace_summary(null, 0, false)
				: 'Backtrace unavailable.',
		);

		file_put_contents(
			WP_CONTENT_DIR . '/wcos-early-translation.jsonl',
			wp_json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
			FILE_APPEND | LOCK_EX
		);
	},
	10,
	3
);
