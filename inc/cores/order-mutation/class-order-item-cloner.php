<?php

defined('ABSPATH') || exit;

final class WC_Order_Splitter_Order_Item_Cloner {
	const META_SOURCE_ITEM_ID = '_wc_order_splitter_source_item_id';

	public function copy_order_context($source, $target, $created_via) {
		$target->set_currency($source->get_currency());
		$target->set_customer_id($source->get_customer_id());
		$target->set_address($source->get_address('billing'), 'billing');
		$target->set_address($source->get_address('shipping'), 'shipping');
		$target->set_customer_note($source->get_customer_note());
		$target->set_payment_method($source->get_payment_method());
		$target->set_payment_method_title($source->get_payment_method_title());
		$target->set_customer_ip_address($source->get_customer_ip_address());
		$target->set_customer_user_agent($source->get_customer_user_agent());
		if (is_callable(array($target, 'set_created_via'))) {
			$target->set_created_via($created_via);
		} else {
			$target->update_meta_data(WC_Order_Splitter_Mutation_Support::META_CREATED_VIA, $created_via);
		}
	}

	public function clone_item($source, $overrides = array(), $include_reduced_stock = false) {
		if ($source instanceof WC_Order_Item_Product) {
			return $this->clone_product($source, $overrides, $include_reduced_stock);
		}
		if ($source instanceof WC_Order_Item_Shipping) {
			return $this->clone_shipping($source, $overrides);
		}
		if ($source instanceof WC_Order_Item_Fee) {
			return $this->clone_fee($source, $overrides);
		}
		if ($source instanceof WC_Order_Item_Coupon) {
			return $this->clone_coupon($source, $overrides);
		}
		if ($source instanceof WC_Order_Item_Tax) {
			return $this->clone_tax($source, $overrides);
		}

		throw new WC_Order_Splitter_Mutation_Exception(__('Unsupported WooCommerce order item type.', 'wc-order-splitter'));
	}

	public function clone_product($source, $overrides = array(), $include_reduced_stock = false) {
		$item = new WC_Order_Item_Product();
		$props = array(
			'name'         => $source->get_name(),
			'product_id'   => $source->get_product_id(),
			'variation_id' => $source->get_variation_id(),
			'quantity'     => $source->get_quantity(),
			'tax_class'    => $source->get_tax_class(),
			'subtotal'     => $source->get_subtotal(),
			'subtotal_tax' => $source->get_subtotal_tax(),
			'total'        => $source->get_total(),
			'total_tax'    => $source->get_total_tax(),
			'taxes'        => $source->get_taxes(),
		);
		$item->set_props(array_merge($props, $overrides));
		WC_Order_Splitter_Mutation_Support::copy_item_meta($source, $item, false);

		if ($include_reduced_stock) {
			$reduced_stock = $source->get_meta('_reduced_stock', true);
			if ('' !== $reduced_stock && null !== $reduced_stock) {
				$item->update_meta_data('_reduced_stock', $reduced_stock);
			}
		}

		return $item;
	}

	public function clone_shipping($source, $overrides = array()) {
		$item = new WC_Order_Item_Shipping();
		$props = array(
			'method_title' => $source->get_method_title(),
			'method_id'    => $source->get_method_id(),
			'instance_id'  => $source->get_instance_id(),
			'total'        => $source->get_total(),
			'taxes'        => $source->get_taxes(),
		);
		$item->set_props(array_merge($props, $overrides));
		WC_Order_Splitter_Mutation_Support::copy_item_meta($source, $item, false);
		$this->set_source_item_reference($source, $item, !empty($overrides));
		return $item;
	}

	public function clone_fee($source, $overrides = array()) {
		$item = new WC_Order_Item_Fee();
		$props = array(
			'name'       => $source->get_name(),
			'tax_class'  => $source->get_tax_class(),
			'tax_status' => $source->get_tax_status(),
			'total'      => $source->get_total(),
			'total_tax'  => $source->get_total_tax(),
			'taxes'      => $source->get_taxes(),
		);
		$item->set_props(array_merge($props, $overrides));
		WC_Order_Splitter_Mutation_Support::copy_item_meta($source, $item, false);
		$this->set_source_item_reference($source, $item, !empty($overrides));
		return $item;
	}

	public function clone_coupon($source, $overrides = array()) {
		$item = new WC_Order_Item_Coupon();
		$props = array(
			'code'         => $source->get_code(),
			'discount'     => $source->get_discount(),
			'discount_tax' => $source->get_discount_tax(),
		);
		$item->set_props(array_merge($props, $overrides));
		WC_Order_Splitter_Mutation_Support::copy_item_meta($source, $item, false);
		$this->set_source_item_reference($source, $item, !empty($overrides));
		return $item;
	}

	public function clone_tax($source, $overrides = array()) {
		$item = new WC_Order_Item_Tax();
		$props = array(
			'rate_id'            => $source->get_rate_id(),
			'label'              => $source->get_label(),
			'compound'           => $source->get_compound(),
			'tax_total'          => $source->get_tax_total(),
			'shipping_tax_total' => $source->get_shipping_tax_total(),
		);
		$item->set_props(array_merge($props, $overrides));
		if (is_callable(array($source, 'get_rate_percent')) && is_callable(array($item, 'set_rate_percent'))) {
			$item->set_rate_percent($source->get_rate_percent());
		}
		WC_Order_Splitter_Mutation_Support::copy_item_meta($source, $item, false);
		return $item;
	}

	public function clone_all_items($source_order, $target_order, $include_reduced_stock = false) {
		foreach (array('line_item', 'shipping', 'fee', 'coupon', 'tax') as $type) {
			foreach ($source_order->get_items($type) as $source_item) {
				$target_order->add_item($this->clone_item($source_item, array(), $include_reduced_stock));
			}
		}
	}

	private function set_source_item_reference($source, $target, $reset_reference = false) {
		$source_item_id = 0;
		if (!$reset_reference) {
			$source_item_id = absint($source->get_meta(self::META_SOURCE_ITEM_ID, true));
		}
		if (!$source_item_id) {
			$source_item_id = absint($source->get_id());
		}
		if ($source_item_id) {
			$target->update_meta_data(self::META_SOURCE_ITEM_ID, $source_item_id);
		}
	}
}
