<?php

defined('ABSPATH') || exit;

/**
 * Versioned, domain-separated keyed compatibility signatures for Merge pairs.
 *
 * Normalized email/address values exist only in request memory. Returned records
 * contain HMAC digests and non-PII control fields only.
 */
final class WCOS_Merge_Context_Signature {

	const SCHEMA_VERSION = 2;
	const LEGACY_SCHEMA_VERSION = 1;
	const ALGORITHM = 'hmac-sha256';
	const PURPOSE_REGISTERED_IDENTITY = 'merge_registered_identity_v1';
	const PURPOSE_GUEST_IDENTITY = 'merge_guest_identity_v1';
	const PURPOSE_BILLING_CONTEXT = 'merge_billing_context_v1';
	const PURPOSE_SHIPPING_CONTEXT = 'merge_shipping_context_v1';
	const PURPOSE_PAYMENT_CONTEXT = 'merge_payment_context_v1';
	const PURPOSE_CONTEXT_AUTHORITY = 'merge_context_authority_v1';
	const PURPOSE_SOURCE_IDENTITY = 'merge_source_identity_v2';
	const PURPOSE_TARGET_IDENTITY = 'merge_target_identity_v2';
	const PURPOSE_SOURCE_BILLING_CONTEXT = 'merge_source_billing_context_v2';
	const PURPOSE_TARGET_BILLING_CONTEXT = 'merge_target_billing_context_v2';
	const PURPOSE_SOURCE_SHIPPING_CONTEXT = 'merge_source_shipping_context_v2';
	const PURPOSE_TARGET_SHIPPING_CONTEXT = 'merge_target_shipping_context_v2';
	const PURPOSE_SOURCE_PAYMENT_CONTEXT = 'merge_source_payment_context_v2';
	const PURPOSE_TARGET_PAYMENT_CONTEXT = 'merge_target_payment_context_v2';
	const PURPOSE_DISPOSITION_AUTHORITY = 'merge_context_disposition_authority_v2';

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
			$identity_digest = self::digest(self::PURPOSE_REGISTERED_IDENTITY, array('customer_id' => $source_customer_id), self::LEGACY_SCHEMA_VERSION);
		} else {
			$source_email = self::normalized_guest_email($source);
			$target_email = self::normalized_guest_email($target);
			$source_guest = self::digest(self::PURPOSE_GUEST_IDENTITY, array('billing_email' => $source_email), self::LEGACY_SCHEMA_VERSION);
			$target_guest = self::digest(self::PURPOSE_GUEST_IDENTITY, array('billing_email' => $target_email), self::LEGACY_SCHEMA_VERSION);
			if (!hash_equals($source_guest, $target_guest)) {
				throw new RuntimeException(__('Merge requires guest orders to have the same nonblank billing identity.', 'wc-order-splitter'));
			}
			$identity_type = 'guest';
			$identity_digest = $source_guest;
		}

		$source_billing = self::address_digest($source, 'billing', self::LEGACY_SCHEMA_VERSION);
		$target_billing = self::address_digest($target, 'billing', self::LEGACY_SCHEMA_VERSION);
		$source_shipping = self::address_digest($source, 'shipping', self::LEGACY_SCHEMA_VERSION);
		$target_shipping = self::address_digest($target, 'shipping', self::LEGACY_SCHEMA_VERSION);
		$source_payment = self::payment_digest($source, self::LEGACY_SCHEMA_VERSION);
		$target_payment = self::payment_digest($target, self::LEGACY_SCHEMA_VERSION);

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
			'schema_version' => self::LEGACY_SCHEMA_VERSION,
			'algorithm' => self::ALGORITHM,
			'identity_type' => $identity_type,
			'customer_id' => 'registered' === $identity_type ? $source_customer_id : 0,
			'identity_digest' => $identity_digest,
			'billing_context_digest' => $source_billing,
			'shipping_context_digest' => $source_shipping,
			'payment_context_digest' => $source_payment,
		);
	}

	/** PII-free independent source/target signatures for keep-target-context. */
	public static function disposition(WC_Order $source, WC_Order $target) {
		$source_identity = self::identity_digest($source, 'source');
		$target_identity = self::identity_digest($target, 'target');
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'algorithm' => self::ALGORITHM,
			'disposition' => 'keep_target_context',
			'source_identity_type' => $source_identity['type'],
			'source_identity_digest' => $source_identity['digest'],
			'source_billing_context_digest' => self::address_digest($source, 'billing', self::SCHEMA_VERSION, 'source'),
			'source_shipping_context_digest' => self::address_digest($source, 'shipping', self::SCHEMA_VERSION, 'source'),
			'source_payment_context_digest' => self::payment_digest($source, self::SCHEMA_VERSION, 'source'),
			'target_identity_type' => $target_identity['type'],
			'target_identity_digest' => $target_identity['digest'],
			'target_billing_context_digest' => self::address_digest($target, 'billing', self::SCHEMA_VERSION, 'target'),
			'target_shipping_context_digest' => self::address_digest($target, 'shipping', self::SCHEMA_VERSION, 'target'),
			'target_payment_context_digest' => self::payment_digest($target, self::SCHEMA_VERSION, 'target'),
		);
	}

	public static function assert_current(WC_Order $order, array $record, $role = '') {
		$schema = (int) (isset($record['schema_version']) ? $record['schema_version'] : 0);
		if (!in_array($schema, array(self::LEGACY_SCHEMA_VERSION, self::SCHEMA_VERSION), true)
			|| self::ALGORITHM !== (isset($record['algorithm']) ? (string) $record['algorithm'] : '')) {
			throw new RuntimeException(__('The Merge keyed-signature scheme no longer matches durable authority.', 'wc-order-splitter'));
		}
		if (self::SCHEMA_VERSION === $schema) {
			self::assert_disposition_current($order, $record, $role);
			return;
		}

		$identity_type = isset($record['identity_type']) ? sanitize_key((string) $record['identity_type']) : '';
		if ('registered' === $identity_type) {
			$customer_id = absint($order->get_customer_id());
			$digest = self::digest(self::PURPOSE_REGISTERED_IDENTITY, array('customer_id' => $customer_id), self::LEGACY_SCHEMA_VERSION);
		} elseif ('guest' === $identity_type && 0 === absint($order->get_customer_id())) {
			$digest = self::digest(self::PURPOSE_GUEST_IDENTITY, array('billing_email' => self::normalized_guest_email($order)), self::LEGACY_SCHEMA_VERSION);
		} else {
			throw new RuntimeException(__('The Merge customer identity type changed after compatibility review.', 'wc-order-splitter'));
		}

		$expected = isset($record['identity_digest']) ? (string) $record['identity_digest'] : '';
		$billing_digest = isset($record['billing_context_digest']) ? (string) $record['billing_context_digest'] : '';
		$shipping_digest = isset($record['shipping_context_digest']) ? (string) $record['shipping_context_digest'] : '';
		$payment_digest = isset($record['payment_context_digest']) ? (string) $record['payment_context_digest'] : '';
		if ('' === $expected || !hash_equals($expected, $digest)
			|| '' === $billing_digest || !hash_equals($billing_digest, self::address_digest($order, 'billing', self::LEGACY_SCHEMA_VERSION))
			|| '' === $shipping_digest || !hash_equals($shipping_digest, self::address_digest($order, 'shipping', self::LEGACY_SCHEMA_VERSION))
			|| '' === $payment_digest || !hash_equals($payment_digest, self::payment_digest($order, self::LEGACY_SCHEMA_VERSION))) {
			throw new RuntimeException(__('The Merge customer, address, or payment context signature changed.', 'wc-order-splitter'));
		}
	}

	public static function authority_fingerprint(array $authority) {
		$schema = (int) (isset($authority['schema_version']) ? $authority['schema_version'] : 0);
		if (self::LEGACY_SCHEMA_VERSION === $schema) {
			return self::digest(self::PURPOSE_CONTEXT_AUTHORITY, $authority, self::LEGACY_SCHEMA_VERSION);
		}
		if (self::SCHEMA_VERSION === $schema) {
			return self::digest(self::PURPOSE_DISPOSITION_AUTHORITY, $authority, self::SCHEMA_VERSION);
		}
		throw new RuntimeException(__('The Merge context authority schema is unsupported.', 'wc-order-splitter'));
	}

	private static function normalized_guest_email(WC_Order $order) {
		$email = strtolower(trim((string) $order->get_billing_email()));
		$email = sanitize_email($email);
		if ('' === $email || !is_email($email)) {
			throw new RuntimeException(__('Guest Merge requires a valid nonblank billing email on both orders.', 'wc-order-splitter'));
		}
		return $email;
	}

	private static function address_digest(WC_Order $order, $type, $schema_version, $role = '') {
		$type = 'shipping' === $type ? 'shipping' : 'billing';
		if (self::SCHEMA_VERSION === (int) $schema_version) {
			$purpose = 'source' === $role
				? ('shipping' === $type ? self::PURPOSE_SOURCE_SHIPPING_CONTEXT : self::PURPOSE_SOURCE_BILLING_CONTEXT)
				: ('shipping' === $type ? self::PURPOSE_TARGET_SHIPPING_CONTEXT : self::PURPOSE_TARGET_BILLING_CONTEXT);
		} else {
			$purpose = 'shipping' === $type ? self::PURPOSE_SHIPPING_CONTEXT : self::PURPOSE_BILLING_CONTEXT;
		}
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
		return self::digest($purpose, $normalized, $schema_version);
	}

	private static function payment_digest(WC_Order $order, $schema_version, $role = '') {
		$purpose = self::SCHEMA_VERSION === (int) $schema_version
			? ('source' === $role ? self::PURPOSE_SOURCE_PAYMENT_CONTEXT : self::PURPOSE_TARGET_PAYMENT_CONTEXT)
			: self::PURPOSE_PAYMENT_CONTEXT;
		return self::digest(
			$purpose,
			array(
				'payment_method' => sanitize_key((string) $order->get_payment_method()),
				'payment_method_title' => trim((string) $order->get_payment_method_title()),
			),
			$schema_version
		);
	}

	private static function identity_digest(WC_Order $order, $role) {
		$customer_id = absint($order->get_customer_id());
		$type = $customer_id ? 'registered' : 'guest';
		$payload = $customer_id
			? array('customer_id' => $customer_id)
			: array('billing_email' => self::normalized_guest_email($order));
		$purpose = 'source' === $role ? self::PURPOSE_SOURCE_IDENTITY : self::PURPOSE_TARGET_IDENTITY;
		return array('type' => $type, 'digest' => self::digest($purpose, $payload, self::SCHEMA_VERSION));
	}

	private static function assert_disposition_current(WC_Order $order, array $record, $role) {
		$role = sanitize_key((string) $role);
		if (!in_array($role, array('source', 'target'), true)
			|| 'keep_target_context' !== sanitize_key(isset($record['disposition']) ? (string) $record['disposition'] : '')) {
			throw new RuntimeException(__('The Merge context disposition is invalid.', 'wc-order-splitter'));
		}
		$identity = self::identity_digest($order, $role);
		$prefix = $role . '_';
		$expected_type = sanitize_key(isset($record[$prefix . 'identity_type']) ? (string) $record[$prefix . 'identity_type'] : '');
		$expected_identity = isset($record[$prefix . 'identity_digest']) ? (string) $record[$prefix . 'identity_digest'] : '';
		$billing = isset($record[$prefix . 'billing_context_digest']) ? (string) $record[$prefix . 'billing_context_digest'] : '';
		$shipping = isset($record[$prefix . 'shipping_context_digest']) ? (string) $record[$prefix . 'shipping_context_digest'] : '';
		$payment = isset($record[$prefix . 'payment_context_digest']) ? (string) $record[$prefix . 'payment_context_digest'] : '';
		if ($expected_type !== $identity['type'] || '' === $expected_identity || !hash_equals($expected_identity, $identity['digest'])
			|| '' === $billing || !hash_equals($billing, self::address_digest($order, 'billing', self::SCHEMA_VERSION, $role))
			|| '' === $shipping || !hash_equals($shipping, self::address_digest($order, 'shipping', self::SCHEMA_VERSION, $role))
			|| '' === $payment || !hash_equals($payment, self::payment_digest($order, self::SCHEMA_VERSION, $role))) {
			throw new RuntimeException(__('The Merge participant context signature changed.', 'wc-order-splitter'));
		}
	}

	private static function digest($purpose, array $payload, $schema_version) {
		$secret = (string) wp_salt('auth');
		if ('' === $secret) {
			throw new RuntimeException(__('A site-owned secret is required for Merge context signatures.', 'wc-order-splitter'));
		}
		$document = array(
			'schema_version' => (int) $schema_version,
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
