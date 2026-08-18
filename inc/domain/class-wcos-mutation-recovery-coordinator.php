<?php

defined('ABSPATH') || exit;

/**
 * Coordinates automatic recovery only when a durable journal proves that a
 * compensating rollback is safe. Ambiguous states remain recovery_required.
 */
final class WCOS_Mutation_Recovery_Coordinator {

	private static $active = array();

	public static function bootstrap() {
		add_action('wcos_mutation_recovery_required', array(__CLASS__, 'handle'), 10, 3);
	}

	public static function handle(WC_Order $source, $operation_id, array $record) {
		$operation_id = sanitize_key($operation_id);
		$key = $source->get_id() . ':' . $operation_id;
		if (isset(self::$active[$key])) {
			return;
		}
		if (!isset($record['type']) || 'split' !== sanitize_key($record['type'])) {
			return;
		}

		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		if (empty($context['source_snapshot']) || empty($context['source_signature_after'])) {
			return;
		}

		self::$active[$key] = true;
		try {
			$children = self::discover_children($source, $operation_id);
			WCOS_Split_Compensator::compensate($source, $children, $record);
		} catch (Throwable $throwable) {
			/*
			 * Deliberately do not overwrite the journal here: keeping the original
			 * recovery_required/compensating state is safer than hiding an
			 * ambiguous compensation failure. Surface it to diagnostic observers.
			 */
			do_action('wcos_mutation_compensation_error', $throwable, $source, $operation_id, $record);
		} finally {
			unset(self::$active[$key]);
		}
	}

	private static function discover_children(WC_Order $source, $operation_id) {
		$orders = WCOS_Order_Relation_Repository::find(
			array(
				array('key' => WCOS_Split_Order_Service::OPERATION_META, 'value' => $operation_id),
				array('key' => WCOS_Split_Order_Service::RELATION_PARENT_META, 'value' => $source->get_id(), 'type' => 'NUMERIC'),
			),
			-1
		);
		$children = array();
		foreach ($orders as $child) {
			$key = sanitize_key((string) $child->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true));
			if ('' === $key || isset($children[$key])) {
				throw new RuntimeException(__('The split recovery graph contains duplicate or missing child keys.', 'wc-order-splitter'));
			}
			$children[$key] = $child;
		}
		ksort($children, SORT_STRING);
		return $children;
	}
}
