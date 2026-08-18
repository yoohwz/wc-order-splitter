<?php
/**
 * Immutable WooCommerce order snapshots for mutation planning.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Captures the complete pre-operation values needed by a mutation plan.
 */
final class WCOS_V2_Order_Snapshot {

	/**
	 * Capture an order without changing it.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	public static function capture(WC_Order $order) {
		$lines = array();

		foreach ($order->get_items('line_item') as $item_id => $item) {
			$metadata = array();

			foreach ($item->get_meta_data() as $meta) {
				$metadata[] = array(
					'key'   => (string) $meta->key,
					'value' => $meta->value,
				);
			}

			$line = array(
				'item_id'       => (int) $item_id,
				'name'          => $item->get_name(),
				'product_id'    => (int) $item->get_product_id(),
				'variation_id'  => (int) $item->get_variation_id(),
				'tax_class'     => (string) $item->get_tax_class(),
				'quantity'      => (string) $item->get_quantity(),
				'subtotal'      => (string) $item->get_subtotal(),
				'total'         => (string) $item->get_total(),
				'subtotal_tax'  => (string) $item->get_subtotal_tax(),
				'total_tax'     => (string) $item->get_total_tax(),
				'taxes'         => self::normalize_taxes($item->get_taxes()),
				'reduced_stock' => self::read_reduced_stock($item),
				'metadata'      => $metadata,
			);
			$identity = WCOS_V2_Line_Identity::from_snapshot($line);

			$line['identity'] = $identity['signature'];
			$lines[(int) $item_id] = $line;
		}

		ksort($lines, SORT_NUMERIC);

		return array(
			'order_id'             => $order->get_id(),
			'order_type'           => $order->get_type(),
			'order_number'         => $order->get_order_number(),
			'status'               => $order->get_status(),
			'currency'             => $order->get_currency(),
			'prices_include_tax'   => (bool) $order->get_prices_include_tax(),
			'customer_id'          => $order->get_customer_id(),
			'transaction_id'       => $order->get_transaction_id(),
			'has_refunds'          => count($order->get_refunds()) > 0 || (float) $order->get_total_refunded() !== 0.0,
			'order_stock_reduced'  => self::read_order_stock_reduced($order),
			'amounts'              => array(
				'subtotal'       => (string) $order->get_subtotal(),
				'discount_total' => (string) $order->get_discount_total(),
				'discount_tax'   => (string) $order->get_discount_tax(),
				'shipping_total' => (string) $order->get_shipping_total(),
				'shipping_tax'   => (string) $order->get_shipping_tax(),
				'cart_tax'       => (string) $order->get_cart_tax(),
				'total_tax'      => (string) $order->get_total_tax(),
				'total'          => (string) $order->get_total(),
			),
			'lines'                => $lines,
			'shipping_items'       => self::capture_items($order->get_items('shipping')),
			'fee_items'            => self::capture_items($order->get_items('fee')),
			'coupon_items'         => self::capture_items($order->get_items('coupon')),
			'tax_items'            => self::capture_items($order->get_items('tax')),
		);
	}

	/**
	 * Capture non-product items as immutable data arrays.
	 *
	 * @param WC_Order_Item[] $items Order items.
	 * @return array
	 */
	private static function capture_items($items) {
		$result = array();

		foreach ($items as $item_id => $item) {
			$data = method_exists($item, 'get_data') ? $item->get_data() : array();
			$meta = array();

			foreach ($item->get_meta_data() as $record) {
				$meta[] = array(
					'key'   => (string) $record->key,
					'value' => $record->value,
				);
			}

			$result[(int) $item_id] = array(
				'type'     => $item->get_type(),
				'data'     => $data,
				'metadata' => $meta,
			);
		}

		ksort($result, SORT_NUMERIC);

		return $result;
	}

	/**
	 * Normalize WooCommerce line-tax arrays while retaining rate IDs.
	 *
	 * @param array $taxes Tax data.
	 * @return array
	 */
	private static function normalize_taxes($taxes) {
		$normalized = array(
			'subtotal' => array(),
			'total'    => array(),
		);

		foreach (array('subtotal', 'total') as $context) {
			$values = isset($taxes[$context]) && is_array($taxes[$context]) ? $taxes[$context] : array();

			foreach ($values as $rate_id => $amount) {
				$normalized[$context][(string) $rate_id] = (string) $amount;
			}

			ksort($normalized[$context], SORT_NATURAL);
		}

		return $normalized;
	}

	/**
	 * Read the item stock marker without treating missing metadata as zero.
	 *
	 * @param WC_Order_Item_Product $item Product line.
	 * @return string|null
	 */
	private static function read_reduced_stock(WC_Order_Item_Product $item) {
		$value = $item->get_meta('_reduced_stock', true);

		return '' === $value || null === $value ? null : (string) $value;
	}

	/**
	 * Read the order-level stock flag through the active data store.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool|null
	 */
	private static function read_order_stock_reduced(WC_Order $order) {
		$data_store = $order->get_data_store();

		if (!is_object($data_store) || !method_exists($data_store, 'get_stock_reduced')) {
			return null;
		}

		return (bool) $data_store->get_stock_reduced($order->get_id());
	}
}
