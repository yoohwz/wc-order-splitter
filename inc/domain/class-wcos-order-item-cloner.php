<?php

defined('ABSPATH') || exit;

/**
 * Creates new order-item objects instead of re-parenting persisted items.
 */
final class WCOS_Order_Item_Cloner {

	public static function product(WC_Order_Item_Product $source, array $overrides = array(), $copy_reduced_stock = false, $context = WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE, $canonical_merge = false) {
		$item = new WC_Order_Item_Product();
		$read_context = $canonical_merge ? 'edit' : 'view';
		$props = array(
			'name' => $source->get_name($read_context),
			'product_id' => $source->get_product_id($read_context),
			'variation_id' => $source->get_variation_id($read_context),
			'quantity' => $source->get_quantity($read_context),
			'tax_class' => $source->get_tax_class($read_context),
			'subtotal' => $source->get_subtotal($read_context),
			'total' => $source->get_total($read_context),
			'taxes' => $source->get_taxes($read_context),
			'subtotal_tax' => $source->get_subtotal_tax($read_context),
			'total_tax' => $source->get_total_tax($read_context),
		);
		$write = static function() use ($item, $props, $overrides) {
			return $item->set_props(array_merge($props, $overrides));
		};
		$result = $canonical_merge ? WCOS_Merge_Canonical_Reader::without_presentation_filters($write) : $write();
		self::assert_props($result, 'product');
		WCOS_Order_Item_Meta_Policy::copy($source, $item, $context, array('_reduced_stock'));

		if ($copy_reduced_stock) {
			$reduced_stock = $source->get_meta('_reduced_stock', true, $read_context);
			if ('' !== $reduced_stock && is_numeric($reduced_stock)) {
				$item->add_meta_data('_reduced_stock', WCOS_Decimal::normalize($reduced_stock, 6), true);
			}
		}

		return $item;
	}

	public static function shipping(WC_Order_Item_Shipping $source, $context = WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE) {
		$item = new WC_Order_Item_Shipping();
		self::assert_props(
			$item->set_props(array(
				'method_title' => $source->get_method_title(),
				'method_id' => $source->get_method_id(),
				'instance_id' => $source->get_instance_id(),
				'total' => $source->get_total(),
				'taxes' => $source->get_taxes(),
				'total_tax' => $source->get_total_tax(),
			)),
			'shipping'
		);
		WCOS_Order_Item_Meta_Policy::copy($source, $item, $context);
		return $item;
	}

	public static function fee(WC_Order_Item_Fee $source, $context = WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE) {
		$item = new WC_Order_Item_Fee();
		self::assert_props(
			$item->set_props(array(
				'name' => $source->get_name(),
				'tax_class' => $source->get_tax_class(),
				'tax_status' => $source->get_tax_status(),
				'amount' => $source->get_amount(),
				'total' => $source->get_total(),
				'taxes' => $source->get_taxes(),
				'total_tax' => $source->get_total_tax(),
			)),
			'fee'
		);
		WCOS_Order_Item_Meta_Policy::copy($source, $item, $context);
		return $item;
	}

	public static function tax(WC_Order_Item_Tax $source, $context = WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE, $canonical_merge = false) {
		$item = new WC_Order_Item_Tax();
		$read_context = $canonical_merge ? 'edit' : 'view';
		$write = static function() use ($item, $source, $read_context) {
			return $item->set_props(array(
					'rate_id' => $source->get_rate_id($read_context),
					'label' => $source->get_label($read_context),
					'compound' => $source->get_compound($read_context),
					'tax_total' => $source->get_tax_total($read_context),
					'shipping_tax_total' => $source->get_shipping_tax_total($read_context),
					'rate_percent' => $source->get_rate_percent($read_context),
			));
		};
		$result = $canonical_merge ? WCOS_Merge_Canonical_Reader::without_presentation_filters($write) : $write();
		self::assert_props($result, 'tax');
		WCOS_Order_Item_Meta_Policy::copy($source, $item, $context);
		return $item;
	}

	public static function coupon(WC_Order_Item_Coupon $source, $context = WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE) {
		$item = new WC_Order_Item_Coupon();
		self::assert_props(
			$item->set_props(array(
				'code' => $source->get_code(),
				'discount' => $source->get_discount(),
				'discount_tax' => $source->get_discount_tax(),
			)),
			'coupon'
		);
		WCOS_Order_Item_Meta_Policy::copy($source, $item, $context);
		return $item;
	}

	private static function assert_props($result, $item_type) {
		if (is_wp_error($result)) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: order item type, 2: validation error. */
					__('Unable to clone %1$s order item: %2$s', 'wc-order-splitter'),
					$item_type,
					$result->get_error_message()
				)
			);
		}
	}
}
