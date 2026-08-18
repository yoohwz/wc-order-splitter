<?php

defined('ABSPATH') || exit;

/**
 * Bounded retention for authoritative mutation journals.
 *
 * Only terminal records are eligible for deletion. Active, committed-but-not-
 * completed, recovery_required, compensating, and manual-reconciliation states
 * are never purged. Scheduling remains dormant while every production workflow
 * gate is hard-off.
 */
final class WCOS_Operation_Journal_Retention {
	const CRON_HOOK = 'wcos_cleanup_terminal_mutation_journals';
	const CURSOR_OPTION = 'wcos_mutation_journal_cleanup_cursor';
	const DEFAULT_RETENTION_DAYS = 90;
	const BATCH_SIZE = 50;

	public static function bootstrap() {
		add_action('init', array(__CLASS__, 'maybe_schedule'), 20);
		add_action(self::CRON_HOOK, array(__CLASS__, 'cleanup'));
	}

	public static function maybe_schedule() {
		if (!class_exists('WCOS_Feature_Gates') || !WCOS_Feature_Gates::any_enabled()) {
			return;
		}
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK);
		}
	}

	/**
	 * Scan one bounded keyset page and persist the option-id cursor. The cursor is
	 * reset only after a later run reaches the end, so ineligible early records
	 * cannot starve expired records with higher option IDs forever.
	 */
	public static function cleanup() {
		global $wpdb;

		$like = $wpdb->esc_like('wcos_mutation_op_') . '%';
		$cursor = absint(get_option(self::CURSOR_OPTION, 0));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT %d",
				$like,
				$cursor,
				self::BATCH_SIZE
			),
			ARRAY_A
		);
		if (empty($rows)) {
			delete_option(self::CURSOR_OPTION);
			return 0;
		}

		$deleted = 0;
		$now = time();
		$last_option_id = $cursor;
		foreach ($rows as $row) {
			$last_option_id = max($last_option_id, absint($row['option_id']));
			$key = isset($row['option_name']) ? (string) $row['option_name'] : '';
			if ('' === $key) {
				continue;
			}
			$record = get_option($key, null);
			if (!is_array($record) || !self::is_expired_terminal_record($record, $now)) {
				continue;
			}
			if (delete_option($key)) {
				$deleted++;
			}
		}

		if ($last_option_id > $cursor) {
			update_option(self::CURSOR_OPTION, $last_option_id, false);
		}
		return $deleted;
	}

	public static function is_expired_terminal_record(array $record, $now = null) {
		$status = isset($record['status']) ? sanitize_key((string) $record['status']) : '';
		if (!in_array($status, array('completed', 'failed', 'compensated'), true)) {
			return false;
		}
		$completed_at = isset($record['completed_at']) ? (string) $record['completed_at'] : '';
		if ('' === $completed_at) {
			return false;
		}
		$completed_timestamp = strtotime($completed_at);
		if (false === $completed_timestamp) {
			return false;
		}
		$days = (int) apply_filters('wcos_mutation_journal_retention_days', self::DEFAULT_RETENTION_DAYS, $record);
		$days = max(7, min(365, $days));
		$now = null === $now ? time() : (int) $now;
		return $completed_timestamp <= ($now - ($days * DAY_IN_SECONDS));
	}
}
