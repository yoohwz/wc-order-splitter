<?php

if (!defined('ABSPATH')) {
	exit(1);
}

/**
 * Test-only scope for the configured Split status prerequisite.
 *
 * Each wp-cli integration fixture runs in its own process. The shutdown
 * restore keeps the database option unchanged even when an assertion exits
 * the fixture early.
 */
final class WCOS_Test_Split_Status_Fixture_Authority {
	const OPTION = 'order_splitter_status_allowed';

	private static $states = array();

	public static function allow(array $statuses) {
		$statuses = array_values(array_unique(array_filter(array_map('sanitize_key', $statuses))));
		if (empty($statuses)) {
			throw new InvalidArgumentException('A Split status fixture must allow at least one status.');
		}

		$token = wp_generate_uuid4();
		$missing = '__wcos_split_status_fixture_missing_' . $token;
		$previous = get_option(self::OPTION, $missing);
		self::$states[$token] = array(
			'existed' => $missing !== $previous,
			'value' => $previous,
		);
		update_option(self::OPTION, $statuses);
		register_shutdown_function(array(__CLASS__, 'restore'), $token);
		return $token;
	}

	public static function restore($token) {
		$token = (string) $token;
		if (!isset(self::$states[$token])) {
			return;
		}
		$state = self::$states[$token];
		unset(self::$states[$token]);
		if ($state['existed']) {
			update_option(self::OPTION, $state['value']);
			return;
		}
		delete_option(self::OPTION);
	}
}
