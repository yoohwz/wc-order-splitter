<?php

defined('ABSPATH') || exit;

/**
 * Versioned, domain-separated keyed compatibility signatures for Merge pairs.
 *
 * Normalized email/address values exist only in request memory. Returned records
 * contain HMAC digests and non-PII control fields only.
 */
final class WCOS_Merge_Context_Signature {

	const SCHEMA_VERSION = 1;
	const ALGORITHM = 'hmac-sha256';
	const PURPOSE_REGISTERED_IDENTITY = 'merge_registered_identity_v1';
	const PURPOSE_GUEST_IDENTITY = 'merge_guest_identity_v1';
	const PURPOSE_BILLING_CONTEXT = 'merge_billing_context_v1';
	const PURPOSE_SHIPPING_CONTEXT = 'merge_shipping_context_v1';
	const PURPOSE_PAYMENT_CONTEXT = 'merge_payment_context_v1';

	public static function compatibility(WC_Order $source, WC_Order $target) {
		$source_customer_id = absint($source->get_customer_id());
		$target_customer_id = absint($target->get_customer_id());
		$identity_type = '';
		$identity_digest = '';

		if ($source_customer_id || $target_customer_id) {
			if (!$source_customer_id || $source_customer_id !== $target_customer_id) {
				throw new RuntimeException(__('Merge requires both orders to belong to the same registered customer.', 'wc-order-splitter'));
			}
			$identity_type = 'registered';
			$identity_digest = self::digest(self::PURPOSE_REGISTERED_IDENTITY, array('customer_id' => $source_customer_id));
		} else {
			$source_email = self::normalized_guest_email($source);
			$target_email = self::normalized_guest_email($target);
			$source_guest = self::digest(self::PURPOSE_GUEST_IDENTITY, array('billing_email' => $source_email));
			$target_guest = self::digest(self::PURPOSE_GUEST_IDENTITY, array('billing_email' => $target_email));
			if (!hash_equals($source_guest, $target_guest)) {
				throw new RuntimeException(__('Merge requires guest orders to have the same nonblank billing identity.', 'wc-order-splitter'));
			}
			$identity_type = 'guest';
			$identity_digest = $source_guest;
		}

		$source_billing = self::address_digest($source, 'billing');
		$target_billing = self::address_digest($target, 'billing');
		$source_shipping = self::address_digest($source, 'shipping');
		$target_shipping = self::address_digest($target, 'shipping');
		$source_payment = self::payment_digest($source);
		$target_payment = self::payment_digest($target);

		foreach (array(
			'billing' => array($source_billing, $target_billing),
			'shipping' => array($source_shipping, $target_shipping),
			'payment' => array($source_payment, $target_payment),
		) as $context => $digests) {
			if (!hash_equals($digests[0], $digests[1])) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: incompatible order context type. */
						__('Merge requires matching keyed %s context on both orders.', 'wc-order-splitter'),
						$context
					)
				);
			}
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'algorithm' => self::ALGORITHM,
			'identity_type' => $identity_type,
			'customer_id' => 'registered' === $identity_type ? $source_customer_id : 0,
			'identity_digest' => $identity_digest,
			'billing_context_digest' => $source_billing,
			'shipping_context_digest' => $source_shipping,
			'payment_context_digest' => $source_payment,
		);
	}

	public static function assert_current(WC_Order $order, array $record) {
		if ((int) (isset($record['schema_version']) ? $record['schema_version'] : 0) !== self::SCHEMA_VERSION
			|| self::ALGORITHM !== (isset($record['algorithm']) ? (string) $record['algorithm'] : '')) {
			throw new RuntimeException(__('The Merge keyed-signature scheme no longer matches durable authority.', 'wc-order-splitter'));
		}

		$identity_type = isset($record['identity_type']) ? sanitize_key((string) $record['identity_type']) : '';
		if ('registered' === $identity_type) {
			$customer_id = absint($order->get_customer_id());
			$digest = self::digest(self::PURPOSE_REGISTERED_IDENTITY, array('customer_id' => $customer_id));
		} elseif ('guest' === $identity_type && 0 === absint($order->get_customer_id())) {
			$digest = self::digest(self::PURPOSE_GUEST_IDENTITY, array('billing_email' => self::normalized_guest_email($order)));
		} else {
			throw new RuntimeException(__('The Merge customer identity type changed after compatibility review.', 'wc-order-splitter'));
		}

		$expected = isset($record['identity_digest']) ? (string) $record['identity_digest'] : '';
		$billing_digest = isset($record['billing_context_digest']) ? (string) $record['billing_context_digest'] : '';
		$shipping_digest = isset($record['shipping_context_digest']) ? (string) $record['shipping_context_digest'] : '';
		$payment_digest = isset($record['payment_context_digest']) ? (string) $record['payment_context_digest'] : '';
		if ('' === $expected || !hash_equals($expected, $digest)
			|| '' === $billing_digest || !hash_equals($billing_digest, self::address_digest($order, 'billing'))
			|| '' === $shipping_digest || !hash_equals($shipping_digest, self::address_digest($order, 'shipping'))
			|| '' === $payment_digest || !hash_equals($payment_digest, self::payment_digest($order))) {
			throw new RuntimeException(__('The Merge customer, address, or payment context signature changed.', 'wc-order-splitter'));
		}
	}

	private static function normalized_guest_email(WC_Order $order) {
		$email = strtolower(trim((string) $order->get_billing_email()));
		$email = sanitize_email($email);
		if ('' === $email || !is_email($email)) {
			throw new RuntimeException(__('Guest Merge requires a valid nonblank billing email on both orders.', 'wc-order-splitter'));
		}
		return $email;
	}

	private static function address_digest(WC_Order $order, $type) {
		$type = 'shipping' === $type ? 'shipping' : 'billing';
		$purpose = 'shipping' === $type ? self::PURPOSE_SHIPPING_CONTEXT : self::PURPOSE_BILLING_CONTEXT;
		$address = $order->get_address($type);
		$normalized = array();
		foreach ((array) $address as $field => $value) {
			$field = sanitize_key((string) $field);
			$value = preg_replace('/\s+/u', ' ', trim((string) $value));
			if ('email' === $field) {
				$value = strtolower(sanitize_email($value));
			}
			if (in_array($field, array('country', 'state', 'postcode'), true)) {
				$value = strtoupper($value);
			}
			$normalized[$field] = array('present' => '' !== $value, 'value' => $value);
		}
		ksort($normalized, SORT_STRING);
		return self::digest($purpose, $normalized);
	}

	private static function payment_digest(WC_Order $order) {
		return self::digest(
			self::PURPOSE_PAYMENT_CONTEXT,
			array(
				'payment_method' => sanitize_key((string) $order->get_payment_method()),
				'payment_method_title' => trim((string) $order->get_payment_method_title()),
			)
		);
	}

	private static function digest($purpose, array $payload) {
		$secret = (string) wp_salt('auth');
		if ('' === $secret) {
			throw new RuntimeException(__('A site-owned secret is required for Merge context signatures.', 'wc-order-splitter'));
		}
		$document = array(
			'schema_version' => self::SCHEMA_VERSION,
			'purpose' => sanitize_key((string) $purpose),
			'payload' => self::canonicalize($payload),
		);
		$json = wp_json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json) || '' === $json) {
			throw new RuntimeException(__('Merge context could not be encoded for keyed signing.', 'wc-order-splitter'));
		}
		return hash_hmac('sha256', $json, $secret);
	}

	private static function canonicalize(array $value) {
		ksort($value, SORT_STRING);
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = self::canonicalize($item);
			}
		}
		return $value;
	}
}
