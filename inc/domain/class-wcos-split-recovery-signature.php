<?php

defined('ABSPATH') || exit;

/**
 * Relation-aware signatures used only for recovery/compensation safety checks.
 */
final class WCOS_Split_Recovery_Signature {

	public static function source(WC_Order $order) {
		return WCOS_Order_Mutation_Snapshot::split_owned_signature($order);
	}

	public static function child(WC_Order $order) {
		return WCOS_Mutation_Fingerprint::create(
			'split_child_owned_state',
			$order->get_id(),
			array(
				'order_signature' => WCOS_Order_Contract_Snapshot::source_signature($order),
				'created_via' => $order->get_created_via(),
				'parent_order_id' => absint($order->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true)),
				'operation_id' => sanitize_key((string) $order->get_meta(WCOS_Split_Order_Service::OPERATION_META, true)),
				'child_key' => sanitize_key((string) $order->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true)),
			)
		);
	}
}
