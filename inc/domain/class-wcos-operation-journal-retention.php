<?php

defined('ABSPATH') || exit;

/**
 * Bounded retention for authoritative mutation journals.
 *
 * Only terminal records are eligible for deletion. Active, committed-but-not-
 * completed, recovery_required, and compensating records are never purged.
 * Scheduling remains dormant while every production workflow gate is hard-off.
 */
final class WCOS_Operation_Journal_Retention {
	const CRON_HOOK = 'wcos_cleanup_terminal_mutation_journals';
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

	public static function cleanup() {
		global $wpdb;

		$like = $wpdb->esc_like('wcos_mutation_op_') . '%';
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
				$like,
				self::BATCH_SIZE
			)
		);
		if (!is_array($keys)) {
			return 0;
		}

		$deleted = 0;
		$now = time();
		foreach ($keys as $key) {
			$record = get_option($key, null);
			if (!is_array($record) || !self::is_expired_terminal_record($record, $now)) {
				continue;
			}
			if (delete_option($key)) {
				$deleted++;
			}
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
