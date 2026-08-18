<?php

defined('ABSPATH') || exit;

/**
 * Creates new order-item objects instead of re-parenting persisted items.
 */
final class WCOS_Order_Item_Cloner {

	public static function product(WC_Order_Item_Product $source, array $overrides = array(), $copy_reduced_stock = false, $context = WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE) {
		$item = new WC_Order_Item_Product();
		$props = array(
			'name' => $source->get_name(),
			'product_id' => $source->get_product_id(),
			'variation_id' => $source->get_variation_id(),
			'quantity' => $source->get_quantity(),
			'tax_class' => $source->get_tax_class(),
			'subtotal' => $source->get_subtotal(),
			'total' => $source->get_total(),
			'taxes' => $source->get_taxes(),
			'subtotal_tax' => $source->get_subtotal_tax(),
			'total_tax' => $source->get_total_tax(),
		);
		self::assert_props($item->set_props(array_merge($props, $overrides)), 'product');
		WCOS_Order_Item_Meta_Policy::copy($source, $item, $context, array('_reduced_stock'));

		if ($copy_reduced_stock) {
			$reduced_stock = $source->get_meta('_reduced_stock', true);
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

	public static function tax(WC_Order_Item_Tax $source, $context = WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE) {
		$item = new WC_Order_Item_Tax();
		self::assert_props(
			$item->set_props(array(
				'rate_id' => $source->get_rate_id(),
				'label' => $source->get_label(),
				'compound' => $source->get_compound(),
				'tax_total' => $source->get_tax_total(),
				'shipping_tax_total' => $source->get_shipping_tax_total(),
				'rate_percent' => $source->get_rate_percent(),
			)),
			'tax'
		);
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
