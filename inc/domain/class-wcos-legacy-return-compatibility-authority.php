<?php

defined('ABSPATH') || exit;

/**
 * Lazy, read-only authority for one corroborated public-Free 1.4.11 child.
 *
 * This adapter never rewrites legacy orders and never invents hardened Split
 * provenance. It seals the exact current pair, reciprocal legacy relation,
 * product-line restoration plan, stock markers, and retained child shipping.
 */
final class WCOS_Legacy_Return_Compatibility_Authority {

	const SCHEMA_VERSION = 2;
	const POLICY_VERSION = 1;
	const LINEAGE_BASIS = 'legacy_1_4_11_compatibility';
	const BASELINE_SHA = 'e1d8aeb8eff38f4ce69dad1a08993e17521c6359';
	const SHIPPING_SCHEMA_VERSION = 1;
	const PAYMENT_SCHEMA_VERSION = 1;

	public static function resolve(WC_Order $child) {
		$child_id = absint($child->get_id());
		if (!$child_id || 'shop_order' !== $child->get_type()) {
			self::reject('invalid_child', __('Legacy Return compatibility requires a persisted WooCommerce shop order child.', 'wc-order-splitter'));
		}
		if (self::has_any_hardened_child_authority($child)) {
			self::reject('hardened_lineage_partial', __('Partial or conflicting hardened Split metadata cannot fall back to legacy compatibility.', 'wc-order-splitter'));
		}

		$parent_values = self::meta_values($child, 'yoos_original_order');
		if (1 !== count($parent_values)) {
			self::reject(empty($parent_values) ? 'legacy_parent_missing' : 'legacy_parent_ambiguous', __('Legacy Return requires one unambiguous original-order pointer.', 'wc-order-splitter'));
		}
		$source_id = self::positive_int_scalar(reset($parent_values), 'legacy_parent_id');
		if ($source_id === $child_id) {
			self::reject('same_participant', __('A legacy Return child cannot be its own original order.', 'wc-order-splitter'));
		}
		$source = wc_get_order($source_id);
		if (!$source instanceof WC_Order || 'shop_order' !== $source->get_type()) {
			self::reject('source_missing', __('The legacy Return original order is unavailable.', 'wc-order-splitter'));
		}

		$legacy_child_ids = self::legacy_child_ids($source, true);
		if (!in_array($child_id, $legacy_child_ids, true)) {
			self::reject('legacy_reciprocal_missing', __('The original order does not corroborate this legacy child relationship.', 'wc-order-splitter'));
		}
		$structured_child_ids = self::structured_child_ids($source);
		if (in_array($child_id, $structured_child_ids, true)) {
			self::reject('hardened_lineage_partial', __('The original contains structured child authority that the legacy child does not carry.', 'wc-order-splitter'));
		}

		if ((string) $child->get_currency() !== (string) $source->get_currency()
			|| (bool) $child->get_prices_include_tax() !== (bool) $source->get_prices_include_tax()) {
			self::reject('commercial_context_mismatch', __('Legacy Return participants do not share one currency and tax-display authority.', 'wc-order-splitter'));
		}
		$precision = WCOS_Price_Precision_Scope::validate(wc_get_price_decimals());
		try {
			WCOS_Order_Totals_Rebuilder::assert_consistent($source, $precision);
			WCOS_Order_Totals_Rebuilder::assert_consistent($child, $precision);
		} catch (Throwable $throwable) {
			self::reject('commercial_state_inconsistent', __('Legacy Return participant totals are not internally consistent.', 'wc-order-splitter'));
		}

		$lines = self::line_authority($source, $child, $precision);
		$relation = array(
			'schema_version' => self::SCHEMA_VERSION,
			'lineage_basis' => self::LINEAGE_BASIS,
			'baseline_sha' => self::BASELINE_SHA,
			'child_order_id' => $child_id,
			'source_order_id' => $source_id,
			'legacy_parent_id' => $source_id,
			'legacy_child_ids' => $legacy_child_ids,
			'hardened_child_ids' => $structured_child_ids,
		);
		$relation['relation_fingerprint'] = WCOS_Mutation_Fingerprint::create('legacy_return_relation_v1', $child_id, $relation);
		$split_operation_id = sanitize_key('legacy-1-4-11-' . $source_id . '-' . $child_id);
		$split_child_key = sanitize_key('legacy-child-' . $child_id);
		$source_commercial = self::sealed('commercial', WCOS_Order_Contract_Snapshot::source_signature($source));
		$source_relation = self::sealed('relation', WCOS_Order_Mutation_Snapshot::split_owned_signature($source));
		$child_commercial = self::sealed('child_commercial', WCOS_Order_Contract_Snapshot::source_signature($child));
		$source_evolution = WCOS_Return_Source_Evolution_Authority::legacy_compatibility_snapshot(
			$source,
			$split_operation_id,
			$child_id,
			$structured_child_ids
		);
		$authority = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'lineage_basis' => self::LINEAGE_BASIS,
			'baseline_sha' => self::BASELINE_SHA,
			'child_order_id' => $child_id,
			'source_order_id' => $source_id,
			'split_operation_id' => $split_operation_id,
			'split_child_key' => $split_child_key,
			'price_precision' => $precision,
			'currency' => (string) $source->get_currency(),
			'prices_include_tax' => (bool) $source->get_prices_include_tax(),
			'execution_policy' => WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			'strategy' => self::LINEAGE_BASIS,
			'legacy_relation_authority' => $relation,
			'legacy_relation_authority_fingerprint' => $relation['relation_fingerprint'],
			'source_commercial_authority' => $source_commercial,
			'source_relation_authority' => $source_relation,
			'child_commercial_authority' => $child_commercial,
			'source_evolution_authority' => $source_evolution,
			'source_evolution_authority_fingerprint' => $source_evolution['authority_fingerprint'],
			'child_shipping_authority' => self::shipping_authority($child, $precision),
			'payment_ownership_authority' => self::payment_authority($source_id, $relation['relation_fingerprint']),
			'lines' => $lines,
		);
		$authority['authority_fingerprint'] = self::fingerprint($authority);
		return $authority;
	}

	public static function fingerprint(array $authority) {
		$copy = $authority;
		unset($copy['authority_fingerprint']);
		return WCOS_Mutation_Fingerprint::create(
			'legacy_return_compatibility_authority_v1',
			absint(isset($copy['child_order_id']) ? $copy['child_order_id'] : 0),
			self::canonicalize($copy)
		);
	}

	public static function is_authority(array $authority) {
		return self::SCHEMA_VERSION === (int) (isset($authority['schema_version']) ? $authority['schema_version'] : 0)
			&& self::POLICY_VERSION === (int) (isset($authority['policy_version']) ? $authority['policy_version'] : 0)
			&& self::LINEAGE_BASIS === sanitize_key(isset($authority['lineage_basis']) ? (string) $authority['lineage_basis'] : '');
	}

	public static function proves_source_only_payment(array $authority) {
		if (!self::is_authority($authority)
			|| empty($authority['authority_fingerprint'])
			|| !hash_equals((string) $authority['authority_fingerprint'], self::fingerprint($authority))
			|| empty($authority['payment_ownership_authority'])
			|| !is_array($authority['payment_ownership_authority'])) {
			return false;
		}
		$payment = $authority['payment_ownership_authority'];
		$stored = self::fingerprint_value(isset($payment['authority_fingerprint']) ? $payment['authority_fingerprint'] : '');
		$copy = $payment;
		unset($copy['authority_fingerprint']);
		return self::PAYMENT_SCHEMA_VERSION === (int) (isset($copy['schema_version']) ? $copy['schema_version'] : 0)
			&& self::LINEAGE_BASIS === (isset($copy['basis']) ? $copy['basis'] : '')
			&& 'source_only_legacy_split_inheritance' === (isset($copy['payment']) ? $copy['payment'] : '')
			&& 'must_be_empty' === (isset($copy['child_transaction_id']) ? $copy['child_transaction_id'] : '')
			&& 'must_be_absent' === (isset($copy['child_date_paid']) ? $copy['child_date_paid'] : '')
			&& '' !== $stored
			&& hash_equals($stored, WCOS_Mutation_Fingerprint::create('legacy_return_payment_authority_v1', absint($copy['source_order_id']), self::canonicalize($copy)));
	}

	public static function assert_child_shipping(WC_Order $child, array $authority, $precision) {
		self::assert_shipping_authority($authority, $child->get_id(), $precision);
		$current = self::shipping_authority($child, $precision);
		if ($current !== $authority) {
			throw new RuntimeException(__('Legacy child shipping changed after compatibility authority was frozen.', 'wc-order-splitter'));
		}
		return true;
	}

	public static function assert_shipping_authority(array $authority, $child_id, $precision) {
		$stored = self::fingerprint_value(isset($authority['authority_fingerprint']) ? $authority['authority_fingerprint'] : '');
		$copy = $authority;
		unset($copy['authority_fingerprint']);
		if (self::SHIPPING_SCHEMA_VERSION !== (int) (isset($copy['schema_version']) ? $copy['schema_version'] : 0)
			|| 'retain_immutable_on_retired_child' !== (isset($copy['policy']) ? $copy['policy'] : '')
			|| absint(isset($copy['child_order_id']) ? $copy['child_order_id'] : 0) !== absint($child_id)
			|| (int) (isset($copy['price_precision']) ? $copy['price_precision'] : -1) !== (int) $precision
			|| !isset($copy['rows']) || !is_array($copy['rows'])
			|| (int) (isset($copy['row_count']) ? $copy['row_count'] : -1) !== count($copy['rows'])
			|| '' === $stored
			|| !hash_equals($stored, WCOS_Mutation_Fingerprint::create('legacy_return_shipping_authority_v1', absint($child_id), self::canonicalize($copy)))) {
			throw new RuntimeException(__('Legacy child shipping authority failed integrity verification.', 'wc-order-splitter'));
		}
		return true;
	}

	public static function legacy_child_ids(WC_Order $source, $require_canonical = true) {
		$values = self::meta_values($source, 'yoos_splitted_order');
		if (empty($values)) {
			return array();
		}
		if (1 !== count($values)) {
			self::reject('legacy_relation_ambiguous', __('The legacy original relation is duplicated or malformed.', 'wc-order-splitter'));
		}
		$raw = reset($values);
		if (is_int($raw)) {
			return array(self::positive_int_scalar($raw, 'legacy_child_id'));
		}
		if (!is_string($raw)) {
			self::reject('legacy_relation_ambiguous', __('The legacy original relation is duplicated or malformed.', 'wc-order-splitter'));
		}
		if ('' === $raw) {
			return array();
		}
		$parts = explode(',', $raw);
		$ids = array();
		foreach ($parts as $part) {
			$id = self::positive_int_scalar($part, 'legacy_child_id');
			if (isset($ids[$id])) {
				self::reject('legacy_relation_duplicate', __('The legacy original relation contains duplicate child IDs.', 'wc-order-splitter'));
			}
			$ids[$id] = $id;
		}
		$ids = array_values($ids);
		if ($require_canonical && implode(',', $ids) !== $raw) {
			self::reject('legacy_relation_not_canonical', __('The legacy original relation is not canonical.', 'wc-order-splitter'));
		}
		return $ids;
	}

	public static function structured_child_ids(WC_Order $source) {
		$values = self::meta_values($source, WCOS_Split_Order_Service::RELATION_CHILDREN_META);
		if (empty($values)) {
			return array();
		}
		if (1 !== count($values)) {
			self::reject('hardened_relation_ambiguous', __('The structured original relation is duplicated or contradictory.', 'wc-order-splitter'));
		}
		$raw = reset($values);
		if ('' === $raw || null === $raw) {
			return array();
		}
		if (!is_array($raw)) {
			self::reject('hardened_relation_malformed', __('The structured original relation is malformed.', 'wc-order-splitter'));
		}
		$ids = array();
		foreach ($raw as $value) {
			$id = self::positive_int_scalar($value, 'hardened_child_id');
			if (isset($ids[$id])) {
				self::reject('hardened_relation_ambiguous', __('The structured original relation contains duplicate child IDs.', 'wc-order-splitter'));
			}
			$ids[$id] = $id;
		}
		$ids = array_values($ids);
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private static function line_authority(WC_Order $source, WC_Order $child, $precision) {
		$source_by_identity = array();
		foreach ($source->get_items('line_item') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				continue;
			}
			try {
				$identity = WCOS_Line_Identity::from_item($item);
			} catch (Throwable $throwable) {
				self::reject('source_line_identity_invalid', __('A legacy Return source line has non-canonical commercial identity.', 'wc-order-splitter'));
			}
			$source_by_identity[$identity][] = absint($item_id);
		}

		$lines = array();
		$used_destinations = array();
		foreach ($child->get_items('line_item') as $child_item_id => $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				self::reject('child_line_type_invalid', __('A legacy Return child contains an unsupported product-line type.', 'wc-order-splitter'));
			}
			try {
				$identity = WCOS_Line_Identity::from_item($item);
			} catch (Throwable $throwable) {
				self::reject('child_line_identity_invalid', __('A legacy Return child line has non-canonical commercial identity.', 'wc-order-splitter'));
			}
			$candidates = isset($source_by_identity[$identity]) ? array_values($source_by_identity[$identity]) : array();
			if (count($candidates) > 1) {
				self::reject('legacy_destination_ambiguous', __('Multiple original lines match one legacy child line; Return cannot guess a destination.', 'wc-order-splitter'));
			}
			$destination_id = empty($candidates) ? 0 : absint(reset($candidates));
			if ($destination_id && isset($used_destinations[$destination_id])) {
				self::reject('legacy_destination_ambiguous', __('Multiple legacy child lines compete for one original residual destination.', 'wc-order-splitter'));
			}
			if ($destination_id) {
				$used_destinations[$destination_id] = true;
			}
			$reduced = $item->get_meta('_reduced_stock', true);
			$reduced = '' === $reduced || null === $reduced ? null : WCOS_Decimal::normalize($reduced, 6);
			$line_key = absint($child_item_id);
			if (!$line_key || isset($lines[$line_key])) {
				self::reject('legacy_child_line_identity_ambiguous', __('Legacy child line IDs must be unique and persisted.', 'wc-order-splitter'));
			}
			$lines[$line_key] = array(
				'source_item_id' => $destination_id,
				'child_item_id' => $line_key,
				'product_id' => absint($item->get_product_id()),
				'variation_id' => absint($item->get_variation_id()),
				'tax_class' => (string) $item->get_tax_class(),
				'line_identity_authority' => WCOS_Return_Lineage_Authority::line_identity_authority($identity),
				'destination' => $destination_id ? WCOS_Return_Plan::DESTINATION_RESIDUAL_SOURCE_ITEM : WCOS_Return_Plan::DESTINATION_FRESH_SOURCE_ITEM,
				'destination_source_item_id' => $destination_id,
				'quantity' => WCOS_Decimal::normalize($item->get_quantity(), 6),
				'subtotal' => WCOS_Decimal::normalize($item->get_subtotal(), $precision),
				'total' => WCOS_Decimal::normalize($item->get_total(), $precision),
				'subtotal_tax' => WCOS_Decimal::normalize($item->get_subtotal_tax(), $precision),
				'total_tax' => WCOS_Decimal::normalize($item->get_total_tax(), $precision),
				'taxes' => self::canonical_taxes($item->get_taxes(), $precision),
				'reduced_stock' => $reduced,
			);
		}
		if (empty($lines)) {
			self::reject('child_lines_missing', __('Legacy Return requires at least one persisted child product line.', 'wc-order-splitter'));
		}
		ksort($lines, SORT_NUMERIC);
		return $lines;
	}

	private static function shipping_authority(WC_Order $child, $precision) {
		$rows = array();
		foreach ($child->get_items('shipping') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Shipping) {
				self::reject('legacy_shipping_type_invalid', __('Legacy child shipping contains an unsupported row type.', 'wc-order-splitter'));
			}
			$rows[absint($item_id)] = array(
				'row_fingerprint' => WCOS_Mutation_Fingerprint::create('legacy_return_shipping_row_v1', absint($item_id), array(
					'name_fingerprint' => hash('sha256', (string) $item->get_name()),
					'method_title_fingerprint' => hash('sha256', (string) $item->get_method_title()),
					'method_id_fingerprint' => hash('sha256', (string) $item->get_method_id()),
					'instance_id' => absint($item->get_instance_id()),
					'total' => WCOS_Decimal::normalize($item->get_total(), $precision),
					'total_tax' => WCOS_Decimal::normalize($item->get_total_tax(), $precision),
					'taxes' => self::canonical_taxes($item->get_taxes(), $precision),
					'business_metadata_fingerprint' => hash('sha256', wp_json_encode(self::canonicalize(WCOS_Order_Item_Meta_Policy::business_metadata($item)))),
				)),
			);
		}
		ksort($rows, SORT_NUMERIC);
		$authority = array(
			'schema_version' => self::SHIPPING_SCHEMA_VERSION,
			'policy' => 'retain_immutable_on_retired_child',
			'child_order_id' => absint($child->get_id()),
			'price_precision' => (int) $precision,
			'row_count' => count($rows),
			'rows' => $rows,
			'shipping_total' => WCOS_Decimal::normalize($child->get_shipping_total(), $precision),
			'shipping_tax' => WCOS_Decimal::normalize($child->get_shipping_tax(), $precision),
		);
		$authority['authority_fingerprint'] = WCOS_Mutation_Fingerprint::create('legacy_return_shipping_authority_v1', $child->get_id(), $authority);
		return $authority;
	}

	private static function payment_authority($source_id, $relation_fingerprint) {
		$authority = array(
			'schema_version' => self::PAYMENT_SCHEMA_VERSION,
			'basis' => self::LINEAGE_BASIS,
			'source_order_id' => absint($source_id),
			'payment' => 'source_only_legacy_split_inheritance',
			'inherited_paid_status' => 'not_independent_payment_ownership',
			'child_transaction_id' => 'must_be_empty',
			'child_date_paid' => 'must_be_absent',
			'legacy_relation_authority_fingerprint' => self::fingerprint_value($relation_fingerprint),
		);
		$authority['authority_fingerprint'] = WCOS_Mutation_Fingerprint::create('legacy_return_payment_authority_v1', $authority['source_order_id'], self::canonicalize($authority));
		return $authority;
	}

	private static function has_any_hardened_child_authority(WC_Order $child) {
		foreach (array(WCOS_Split_Order_Service::RELATION_PARENT_META, WCOS_Split_Order_Service::OPERATION_META, WCOS_Split_Order_Service::CHILD_KEY_META) as $key) {
			if (!empty(self::meta_values($child, $key))) {
				return true;
			}
		}
		return false;
	}

	private static function meta_values(WC_Order $order, $key) {
		$values = array();
		foreach ($order->get_meta_data() as $meta) {
			$data = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : array();
			if (isset($data['key']) && (string) $data['key'] === (string) $key && array_key_exists('value', $data)) {
				$values[] = $data['value'];
			}
		}
		return $values;
	}

	private static function positive_int_scalar($value, $field) {
		if ((is_int($value) && $value > 0) || (is_string($value) && 1 === preg_match('/^[1-9][0-9]*$/D', $value))) {
			return (int) $value;
		}
		self::reject('malformed_' . sanitize_key($field), __('Legacy Return relation metadata contains a malformed order ID.', 'wc-order-splitter'));
	}

	private static function canonical_taxes(array $taxes, $precision) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			foreach (isset($taxes[$bucket]) && is_array($taxes[$bucket]) ? $taxes[$bucket] : array() as $rate_id => $amount) {
				$rate_id = absint($rate_id);
				if (!$rate_id || isset($result[$bucket][$rate_id])) {
					self::reject('legacy_tax_authority_invalid', __('Legacy Return per-rate tax authority is malformed.', 'wc-order-splitter'));
				}
				$result[$bucket][$rate_id] = WCOS_Decimal::normalize($amount, $precision);
			}
			ksort($result[$bucket], SORT_NUMERIC);
		}
		return $result;
	}

	private static function sealed($domain, $fingerprint) {
		return WCOS_Return_Source_Evolution_Authority::sealed_signature($domain, $fingerprint);
	}

	private static function fingerprint_value($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}

	private static function canonicalize($value) {
		if (!is_array($value)) {
			return $value;
		}
		$is_list = true;
		$expected = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expected++) {
				$is_list = false;
				break;
			}
		}
		if (!$is_list) {
			ksort($value, SORT_STRING);
		}
		foreach ($value as $key => $item) {
			$value[$key] = self::canonicalize($item);
		}
		return $value;
	}

	private static function reject($reason, $message) {
		throw new WCOS_Return_Lineage_Exception($reason, $message);
	}
}
