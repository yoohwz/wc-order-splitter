<?php

defined('ABSPATH') || exit;

/**
 * Re-reads source state at Split commit boundaries so an external admin,
 * webhook, or extension cannot be overwritten by a stale in-memory order.
 */
final class WCOS_Mutation_Commit_Guard {

	public static function bootstrap() {
		add_action('wcos_split_mutation_checkpoint', array(__CLASS__, 'guard_split'), PHP_INT_MAX, 4);
	}

	public static function guard_split($stage, WC_Order $source, array $children, $operation_id) {
		if (!in_array($stage, array('before_child_save', 'before_source_save'), true)) {
			return;
		}

		$operation_id = sanitize_key($operation_id);
		$fresh_source = wc_get_order($source->get_id());
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
		foreach ($children as $child) {
			if (!$child instanceof WC_Order) {
				throw new RuntimeException(__('The split commit boundary received an invalid child order.', 'wc-order-splitter'));
			}
			WCOS_Order_Copy_Context::assert_matches($copy_context_signature, $child);
		}
	}
}
