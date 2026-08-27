<?php

defined('ABSPATH') || exit;

/**
 * Immutable policy selector for one Split operation.
 *
 * Versioned Manual quantity authority and server-built planners may select the
 * explicit whole-line policy. Browser input never supplies this value.
 */
final class WCOS_Split_Execution_Policy {
	const PARTIAL_LINES_ONLY = 'partial_lines_only';
	const ALLOW_WHOLE_LINE_TRANSFER = 'allow_whole_line_transfer';

	public static function normalize($policy) {
		$policy = sanitize_key((string) $policy);
		if ('' === $policy) {
			$policy = self::PARTIAL_LINES_ONLY;
		}
		if (!in_array($policy, array(self::PARTIAL_LINES_ONLY, self::ALLOW_WHOLE_LINE_TRANSFER), true)) {
			throw new InvalidArgumentException(__('Unknown Split execution policy.', 'wc-order-splitter'));
		}
		return $policy;
	}

	public static function allows_whole_line_transfer($policy) {
		return self::ALLOW_WHOLE_LINE_TRANSFER === self::normalize($policy);
	}
}
