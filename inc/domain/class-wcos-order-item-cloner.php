<?php

defined('ABSPATH') || exit;

/**
 * Creates new order-item objects instead of re-parenting persisted items.
 */
final class WCOS_Order_Item_Cloner {

	public static function product(WC_Order_Item_Product $source, array $overrides = array(), $copy_reduced_stock = false) {
		$item = new WC_Order_Item_Product();
		$props = array(
			'name' => $source->get_name(),
			'product_id' => $source->get_product_id(),
			'variation_id' => $source->get_variation_id(),
			'quantity' => $source->get_quantity(),
			'tax_class' => $source->get_tax_class(),
			'subtotal' => $source->get_subtotal(),
			'subtotal_tax' => $source->get_subtotal_tax(),
			'total' => $source->get_total(),
			'total_tax' => $source->get_total_tax(),
			'taxes' => $source->get_taxes(),
		);
		$item->set_props(array_merge($props, $overrides));
		self::copy_meta($source, $item, $copy_reduced_stock ? array() : array('_reduced_stock'));
		return $item;
	}

	public static function shipping(WC_Order_Item_Shipping $source) {
		$item = new WC_Order_Item_Shipping();
		$item->set_props(array(
			'method_title' => $source->get_method_title(),
			'method_id' => $source->get_method_id(),
			'instance_id' => $source->get_instance_id(),
			'total' => $source->get_total(),
			'total_tax' => $source->get_total_tax(),
			'taxes' => $source->get_taxes(),
		));
		self::copy_meta($source, $item);
		return $item;
	}

	public static function fee(WC_Order_Item_Fee $source) {
		$item = new WC_Order_Item_Fee();
		$item->set_props(array(
			'name' => $source->get_name(),
			'tax_class' => $source->get_tax_class(),
			'tax_status' => $source->get_tax_status(),
			'amount' => $source->get_amount(),
			'total' => $source->get_total(),
			'total_tax' => $source->get_total_tax(),
			'taxes' => $source->get_taxes(),
		));
		self::copy_meta($source, $item);
		return $item;
	}

	public static function tax(WC_Order_Item_Tax $source) {
		$item = new WC_Order_Item_Tax();
		$item->set_props(array(
			'rate_id' => $source->get_rate_id(),
			'label' => $source->get_label(),
			'compound' => $source->get_compound(),
			'tax_total' => $source->get_tax_total(),
			'shipping_tax_total' => $source->get_shipping_tax_total(),
			'rate_percent' => $source->get_rate_percent(),
		));
		self::copy_meta($source, $item);
		return $item;
	}

	public static function coupon(WC_Order_Item_Coupon $source) {
		$item = new WC_Order_Item_Coupon();
		$item->set_props(array(
			'code' => $source->get_code(),
			'discount' => $source->get_discount(),
			'discount_tax' => $source->get_discount_tax(),
		));
		self::copy_meta($source, $item);
		return $item;
	}

	private static function copy_meta(WC_Order_Item $source, WC_Order_Item $target, array $excluded_keys = array()) {
		foreach ($source->get_meta_data() as $meta) {
			if (in_array((string) $meta->key, $excluded_keys, true)) {
				continue;
			}
			$target->add_meta_data($meta->key, $meta->value);
		}
	}
}
