<?php

defined('ABSPATH') || exit;

/**
 * Re-reads source state and refreshes request-local lease ownership at Split
 * commit boundaries so stale workers cannot continue a long mutation.
 */
final class WCOS_Mutation_Commit_Guard {

	public static function bootstrap() {
		add_action('wcos_split_mutation_checkpoint', array(__CLASS__, 'guard_split'), PHP_INT_MAX, 4);
	}

	public static function guard_split($stage, WC_Order $source, array $children, $operation_id) {
		if (!in_array($stage, array('before_child_save', 'before_source_save'), true)) {
			return;
		}

		$source_id = $source->get_id();
		$operation_id = sanitize_key($operation_id);
		if (!WCOS_Operation_Lock::refresh_current_for($source_id, $operation_id)) {
			throw new RuntimeException(__('The split operation lease was lost before a persistence boundary.', 'wc-order-splitter'));
		}
		WCOS_Operation_Lock::assert_current_owned_for($source_id, $operation_id);

		$fresh_source = wc_get_order($source_id);
		if (!$fresh_source || 'shop_order' !== $fresh_source->get_type()) {
			throw new RuntimeException(__('The split source order is no longer available at the commit boundary.', 'wc-order-splitter'));
		}

		$record = WCOS_Operation_Journal::get($fresh_source, $operation_id);
		if (!is_array($record)) {
			throw new RuntimeException(__('The split operation journal is missing at the commit boundary.', 'wc-order-splitter'));
		}

		$expected_source_signature = isset($record['context']['source_signature'])
			? (string) $record['context']['source_signature']
			: '';
		if ('' === $expected_source_signature || !hash_equals($expected_source_signature, WCOS_Order_Contract_Snapshot::source_signature($fresh_source))) {
			throw new RuntimeException(__('The source order changed concurrently before the split could be committed.', 'wc-order-splitter'));
		}

		$copy_context_signature = isset($record['context']['source_copy_context_signature'])
			? (string) $record['context']['source_copy_context_signature']
			: '';
		if ('' === $copy_context_signature) {
			$copy_context_signature = WCOS_Order_Copy_Context::signature($fresh_source);
			if (!WCOS_Operation_Journal::checkpoint(
				$fresh_source,
				$operation_id,
				'copy_context_captured',
				array('source_copy_context_signature' => $copy_context_signature)
			)) {
				throw new RuntimeException(__('The split copy-context checkpoint could not be recorded.', 'wc-order-splitter'));
			}
		}

		WCOS_Order_Copy_Context::assert_matches($copy_context_signature, $fresh_source);
		$child_ids = array();
		foreach ($children as $child) {
			if (!$child instanceof WC_Order) {
				throw new RuntimeException(__('The split commit boundary received an invalid child order.', 'wc-order-splitter'));
			}
			WCOS_Order_Copy_Context::assert_matches($copy_context_signature, $child);
			if ($child->get_id()) {
				$child_ids[] = absint($child->get_id());
			}
		}

		if ('before_source_save' === $stage) {
			$child_ids = array_values(array_unique(array_filter($child_ids)));
			sort($child_ids, SORT_NUMERIC);
			$planned_source_signature = WCOS_Order_Contract_Snapshot::source_signature($source);
			$planned_recovery_signature = WCOS_Order_Mutation_Snapshot::split_owned_signature($source);

			if (!WCOS_Operation_Journal::checkpoint(
				$fresh_source,
				$operation_id,
				'source_commit_planned',
				array(
					'target_order_ids' => $child_ids,
					'source_signature_after' => $planned_source_signature,
					'source_recovery_signature_after' => $planned_recovery_signature,
				)
			)) {
				throw new RuntimeException(__('The planned Split source commit could not be checkpointed before persistence.', 'wc-order-splitter'));
			}
		}
	}
}
