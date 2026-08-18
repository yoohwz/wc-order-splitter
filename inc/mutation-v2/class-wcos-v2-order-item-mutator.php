<?php
/**
 * Exact WooCommerce order-item creation and mutation adapter.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Creates new child items and applies precomputed historical values to source
 * items without pricing or tax recalculation.
 */
final class WCOS_V2_Order_Item_Mutator {

	/**
	 * Create a new child product item from an explicit line specification.
	 *
	 * @param array  $line_spec     Child line specification.
	 * @param string $operation_id  Operation ID.
	 * @return WC_Order_Item_Product|WP_Error
	 */
	public static function create_product(array $line_spec, $operation_id) {
		if (!isset($line_spec['action']) || 'create' !== $line_spec['action'] || (float) $line_spec['quantity'] <= 0) {
			return self::error('wcos_invalid_child_line', __('The child product line specification is invalid.', 'wc-order-splitter'));
		}

		$item   = new WC_Order_Item_Product();
		$result = self::set_product_props($item, $line_spec);

		if (is_wp_error($result)) {
			return $result;
		}

		foreach ((array) $line_spec['metadata'] as $record) {
			$key = isset($record['key']) ? (string) $record['key'] : '';

			if ('' === $key || !WCOS_V2_Metadata_Policy::should_copy($key)) {
				return self::error('wcos_invalid_child_metadata', __('The child product line contains invalid metadata.', 'wc-order-splitter'));
			}

			$item->add_meta_data($key, array_key_exists('value', $record) ? $record['value'] : null, false);
		}

		$item->add_meta_data('_wcos_v2_source_item_id', absint($line_spec['source_item_id']), true);
		$item->add_meta_data('_wcos_v2_operation_id', self::identifier($operation_id), true);
		self::set_reduced_stock($item, $line_spec['reduced_stock']);

		$identity = self::product_identity($item);

		if (is_wp_error($identity) || !hash_equals((string) $line_spec['identity'], (string) $identity)) {
			return self::error('wcos_child_line_identity_mismatch', __('The newly constructed child line does not match its commercial identity.', 'wc-order-splitter'));
		}

		return $item;
	}

	/**
	 * Apply an update specification to an existing source product item.
	 *
	 * @param WC_Order_Item_Product $item      Existing source item.
	 * @param array                 $line_spec Source line specification.
	 * @return true|WP_Error
	 */
	public static function update_product(WC_Order_Item_Product $item, array $line_spec) {
		if (!isset($line_spec['action']) || 'update' !== $line_spec['action'] || (float) $line_spec['quantity'] <= 0) {
			return self::error('wcos_invalid_source_line_update', __('The source product line update is invalid.', 'wc-order-splitter'));
		}

		$identity = self::product_identity($item);

		if (is_wp_error($identity)) {
			return $identity;
		}

		if (!hash_equals((string) $line_spec['identity'], (string) $identity)) {
			return self::error('wcos_source_line_identity_changed', __('A source order line changed after preflight.', 'wc-order-splitter'));
		}

		$result = self::set_product_props($item, $line_spec);

		if (is_wp_error($result)) {
			return $result;
		}

		self::set_reduced_stock($item, $line_spec['reduced_stock']);

		return true;
	}

	/**
	 * Restore a source product item from its immutable snapshot.
	 *
	 * @param WC_Order_Item_Product $item     Existing source item.
	 * @param array                 $snapshot Source line snapshot.
	 * @return true|WP_Error
	 */
	public static function restore_product(WC_Order_Item_Product $item, array $snapshot) {
		$result = self::set_product_props(
			$item,
			array(
				'name'          => $snapshot['name'],
				'product_id'    => $snapshot['product_id'],
				'variation_id'  => $snapshot['variation_id'],
				'tax_class'     => $snapshot['tax_class'],
				'quantity'      => $snapshot['quantity'],
				'subtotal'      => $snapshot['subtotal'],
				'total'         => $snapshot['total'],
				'subtotal_tax'  => $snapshot['subtotal_tax'],
				'total_tax'     => $snapshot['total_tax'],
				'taxes'         => $snapshot['taxes'],
			)
		);

		if (is_wp_error($result)) {
			return $result;
		}

		self::set_reduced_stock($item, $snapshot['reduced_stock']);

		return true;
	}

	/**
	 * Create a new child tax item from historical tax values.
	 *
	 * @param array $tax_spec Tax item specification.
	 * @return WC_Order_Item_Tax|WP_Error
	 */
	public static function create_tax(array $tax_spec) {
		if (!isset($tax_spec['action']) || 'create' !== $tax_spec['action']) {
			return self::error('wcos_invalid_child_tax', __('The child tax item specification is invalid.', 'wc-order-splitter'));
		}

		$item   = new WC_Order_Item_Tax();
		$result = self::set_tax_props($item, $tax_spec);

		return is_wp_error($result) ? $result : $item;
	}

	/**
	 * Update a source tax item from an explicit specification.
	 *
	 * @param WC_Order_Item_Tax $item     Existing source tax item.
	 * @param array             $tax_spec Source tax specification.
	 * @return true|WP_Error
	 */
	public static function update_tax(WC_Order_Item_Tax $item, array $tax_spec) {
		if (!isset($tax_spec['action']) || 'update' !== $tax_spec['action']) {
			return self::error('wcos_invalid_source_tax', __('The source tax item update is invalid.', 'wc-order-splitter'));
		}

		if ((string) $item->get_rate_id() !== (string) $tax_spec['rate_id']) {
			return self::error('wcos_source_tax_rate_changed', __('A source tax item changed after preflight.', 'wc-order-splitter'));
		}

		return self::set_tax_props($item, $tax_spec);
	}

	/**
	 * Restore a source tax item from a generic order-item snapshot.
	 *
	 * @param WC_Order_Item_Tax $item     Existing source tax item.
	 * @param array             $snapshot Tax item snapshot.
	 * @return true|WP_Error
	 */
	public static function restore_tax(WC_Order_Item_Tax $item, array $snapshot) {
		$data = isset($snapshot['data']) && is_array($snapshot['data']) ? $snapshot['data'] : array();

		return self::set_tax_props(
			$item,
			array(
				'rate_id'            => isset($data['rate_id']) ? $data['rate_id'] : 0,
				'label'              => isset($data['label']) ? $data['label'] : '',
				'compound'           => !empty($data['compound']),
				'rate_code'          => isset($data['rate_code']) ? $data['rate_code'] : '',
				'rate_percent'       => isset($data['rate_percent']) ? $data['rate_percent'] : '',
				'tax_total'          => isset($data['tax_total']) ? $data['tax_total'] : '0',
				'shipping_tax_total' => isset($data['shipping_tax_total']) ? $data['shipping_tax_total'] : '0',
			)
		);
	}

	/**
	 * Verify a persisted product item against a specification.
	 *
	 * @param WC_Order_Item_Product $item      Persisted item.
	 * @param array                 $line_spec Expected values.
	 * @param int                   $precision Currency precision.
	 * @return true|WP_Error
	 */
	public static function verify_product(WC_Order_Item_Product $item, array $line_spec, $precision) {
		$identity = self::product_identity($item);

		if (is_wp_error($identity) || !hash_equals((string) $line_spec['identity'], (string) $identity)) {
			return self::error('wcos_persisted_line_identity_mismatch', __('A persisted order line has the wrong commercial identity.', 'wc-order-splitter'));
		}

		$checks = array(
			'quantity'     => $item->get_quantity(),
			'subtotal'     => $item->get_subtotal(),
			'total'        => $item->get_total(),
			'subtotal_tax' => $item->get_subtotal_tax(),
			'total_tax'    => $item->get_total_tax(),
		);

		foreach ($checks as $field => $actual) {
			if ('quantity' === $field) {
				if (abs((float) $actual - (float) $line_spec[$field]) > 0.0000001) {
					return self::error('wcos_persisted_line_quantity_mismatch', __('A persisted order line has an unexpected quantity.', 'wc-order-splitter'));
				}
				continue;
			}

			if (WCOS_V2_Amount_Allocator::to_minor_units($actual, $precision) !== WCOS_V2_Amount_Allocator::to_minor_units($line_spec[$field], $precision)) {
				return self::error('wcos_persisted_line_amount_mismatch', __('A persisted order line has an unexpected amount.', 'wc-order-splitter'));
			}
		}

		if (self::normalize_taxes($item->get_taxes(), $precision) !== self::normalize_taxes($line_spec['taxes'], $precision)) {
			return self::error('wcos_persisted_line_tax_mismatch', __('A persisted order line has unexpected historical tax allocations.', 'wc-order-splitter'));
		}

		$actual_stock = self::reduced_stock($item);
		$expected     = null === $line_spec['reduced_stock'] ? null : (string) $line_spec['reduced_stock'];

		if (null === $actual_stock ? null !== $expected : null === $expected || abs((float) $actual_stock - (float) $expected) > 0.0000001) {
			return self::error('wcos_persisted_line_stock_mismatch', __('A persisted order line has an unexpected stock marker.', 'wc-order-splitter'));
		}

		return true;
	}

	/**
	 * Set product properties without recalculation.
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @param array                 $data Values.
	 * @return true|WP_Error
	 */
	private static function set_product_props(WC_Order_Item_Product $item, array $data) {
		$required = array('name', 'product_id', 'variation_id', 'tax_class', 'quantity', 'subtotal', 'total', 'subtotal_tax', 'total_tax', 'taxes');

		foreach ($required as $field) {
			if (!array_key_exists($field, $data)) {
				return self::error('wcos_incomplete_product_item', __('The order product item data is incomplete.', 'wc-order-splitter'));
			}
		}

		$result = $item->set_props(
			array(
				'name'         => (string) $data['name'],
				'product_id'   => absint($data['product_id']),
				'variation_id' => absint($data['variation_id']),
				'tax_class'    => (string) $data['tax_class'],
				'quantity'     => $data['quantity'],
				'subtotal'     => $data['subtotal'],
				'total'        => $data['total'],
				'subtotal_tax' => $data['subtotal_tax'],
				'total_tax'    => $data['total_tax'],
				'taxes'        => $data['taxes'],
			)
		);

		return is_wp_error($result) ? $result : true;
	}

	/**
	 * Set historical tax item properties.
	 *
	 * @param WC_Order_Item_Tax $item Tax item.
	 * @param array             $data Values.
	 * @return true|WP_Error
	 */
	private static function set_tax_props(WC_Order_Item_Tax $item, array $data) {
		if (!isset($data['rate_id']) || !is_numeric($data['rate_id'])) {
			return self::error('wcos_invalid_tax_rate', __('The order tax rate ID is invalid.', 'wc-order-splitter'));
		}

		try {
			$item->set_rate_id(absint($data['rate_id']));
			$item->set_label(isset($data['label']) ? (string) $data['label'] : '');
			$item->set_compound(!empty($data['compound']));
			$item->set_tax_total(isset($data['tax_total']) ? $data['tax_total'] : '0');
			$item->set_shipping_tax_total(isset($data['shipping_tax_total']) ? $data['shipping_tax_total'] : '0');

			if (method_exists($item, 'set_rate_code') && isset($data['rate_code'])) {
				$item->set_rate_code((string) $data['rate_code']);
			}

			if (method_exists($item, 'set_rate_percent') && isset($data['rate_percent']) && '' !== (string) $data['rate_percent']) {
				$item->set_rate_percent($data['rate_percent']);
			}
		} catch (Exception $exception) {
			return self::error('wcos_invalid_tax_item', $exception->getMessage());
		}

		return true;
	}

	/**
	 * Build an exact commercial identity from an order item.
	 *
	 * @param WC_Order_Item_Product $item Product item.
	 * @return string|WP_Error
	 */
	private static function product_identity(WC_Order_Item_Product $item) {
		$metadata = array();

		foreach ($item->get_meta_data() as $record) {
			$metadata[] = array(
				'key'   => (string) $record->key,
				'value' => $record->value,
			);
		}

		try {
			$identity = WCOS_V2_Line_Identity::build(
				$item->get_product_id(),
				$item->get_variation_id(),
				$item->get_tax_class(),
				$metadata
			);
		} catch (LogicException $exception) {
			return self::error('wcos_line_identity_failed', $exception->getMessage());
		}

		return $identity['signature'];
	}

	/**
	 * Set or remove the item stock marker explicitly.
	 *
	 * @param WC_Order_Item_Product $item  Product item.
	 * @param mixed                 $value Reduced stock value.
	 * @return void
	 */
	private static function set_reduced_stock(WC_Order_Item_Product $item, $value) {
		$item->delete_meta_data('_reduced_stock');

		if (null !== $value && '' !== $value) {
			$item->add_meta_data('_reduced_stock', $value, true);
		}
	}

	/**
	 * Read a nullable stock marker.
	 *
	 * @param WC_Order_Item_Product $item Product item.
	 * @return string|null
	 */
	private static function reduced_stock(WC_Order_Item_Product $item) {
		$value = $item->get_meta('_reduced_stock', true);

		return '' === $value || null === $value ? null : (string) $value;
	}

	/**
	 * Normalize per-rate tax arrays to integer minor units.
	 *
	 * @param array $taxes     Taxes.
	 * @param int   $precision Currency precision.
	 * @return array
	 */
	private static function normalize_taxes($taxes, $precision) {
		$result = array('subtotal' => array(), 'total' => array());

		foreach (array('subtotal', 'total') as $context) {
			$values = isset($taxes[$context]) && is_array($taxes[$context]) ? $taxes[$context] : array();

			foreach ($values as $rate_id => $amount) {
				$result[$context][(string) $rate_id] = WCOS_V2_Amount_Allocator::to_minor_units($amount, $precision);
			}

			ksort($result[$context], SORT_NATURAL);
		}

		return $result;
	}

	/**
	 * Normalize an operation ID.
	 *
	 * @param mixed $value ID.
	 * @return string
	 */
	private static function identifier($value) {
		$value = strtolower(trim((string) $value));

		return preg_replace('/[^a-z0-9._:-]/', '', $value);
	}

	/**
	 * Create a stable adapter error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
