<?php

defined('ABSPATH') || exit;

/**
 * Hashes the order context intentionally copied to Duplicate/Split targets.
 * The journal stores only the hash, never plaintext customer addresses.
 */
final class WCOS_Order_Copy_Context {

	public static function signature(WC_Order $order) {
		return WCOS_Mutation_Fingerprint::create(
			'order_copy_context',
			0,
			array(
				'customer_id' => $order->get_customer_id(),
				'currency' => $order->get_currency(),
				'prices_include_tax' => (bool) $order->get_prices_include_tax(),
				'payment_method' => $order->get_payment_method(),
				'payment_method_title' => $order->get_payment_method_title(),
				'customer_note' => $order->get_customer_note(),
				'billing' => $order->get_address('billing'),
				'shipping' => $order->get_address('shipping'),
			)
		);
	}

	public static function assert_matches($expected_signature, WC_Order $order) {
		$expected_signature = sanitize_key((string) $expected_signature);
		$actual_signature = self::signature($order);
		if ('' === $expected_signature || !hash_equals($expected_signature, $actual_signature)) {
			throw new RuntimeException(__('Copied customer, address, currency, or payment context does not match the operation snapshot.', 'wc-order-splitter'));
		}
	}
}
