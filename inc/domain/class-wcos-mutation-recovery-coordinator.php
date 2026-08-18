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
		$source_id = $source->get_id();
		$key = $source_id . ':' . $operation_id;
		if (isset(self::$active[$key])) {
			return;
		}
		if (!isset($record['type']) || 'split' !== sanitize_key($record['type'])) {
			return;
		}

		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		if (empty($context['source_snapshot']) || empty($context['source_recovery_signature_after'])) {
			return;
		}

		self::$active[$key] = true;
		$lease_token = false;
		$acquired_here = false;
		try {
			if (!WCOS_Operation_Lock::is_current_owned($source_id)) {
				$lease_token = WCOS_Operation_Lock::acquire($source_id, $operation_id);
				if (false === $lease_token) {
					throw new RuntimeException(__('Automatic mutation recovery could not acquire the source-order lease.', 'wc-order-splitter'));
				}
				$acquired_here = true;
			}
			WCOS_Operation_Lock::assert_current_owned($source_id);

			$fresh_source = wc_get_order($source_id);
			if (!$fresh_source || 'shop_order' !== $fresh_source->get_type()) {
				throw new RuntimeException(__('The mutation source order disappeared before recovery could begin.', 'wc-order-splitter'));
			}
			$fresh_record = WCOS_Operation_Journal::get($fresh_source, $operation_id);
			if (!is_array($fresh_record)) {
				throw new RuntimeException(__('The mutation recovery journal disappeared before compensation.', 'wc-order-splitter'));
			}
			WCOS_Operation_Journal::assert_fingerprint($fresh_record, isset($record['fingerprint']) ? $record['fingerprint'] : '');

			$children = self::discover_children($fresh_source, $operation_id);
			WCOS_Split_Compensator::compensate($fresh_source, $children, $fresh_record);
		} catch (Throwable $throwable) {
			/* Keep the durable ambiguous state visible for a later safe retry. */
			do_action('wcos_mutation_compensation_error', $throwable, $source, $operation_id, $record);
		} finally {
			if ($acquired_here && false !== $lease_token) {
				WCOS_Operation_Lock::release($source_id, $lease_token);
			}
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
