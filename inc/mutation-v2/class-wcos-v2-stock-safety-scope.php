<?php
/**
 * Scoped stock consistency gate for quantity split execution.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Rejects unsafe product or stock-marker state during every internal preflight.
 */
final class WCOS_V2_Stock_Safety_Scope {

	/**
	 * Source order ID.
	 *
	 * @var int
	 */
	private $order_id;

	/**
	 * Whether the filter is active.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Create and open the stock safety scope.
	 *
	 * @param int $order_id Source order ID.
	 */
	public function __construct($order_id) {
		$this->order_id = absint($order_id);

		if (!$this->order_id) {
			throw new InvalidArgumentException('A valid source order ID is required for stock safety scoping.');
		}

		$this->open();
	}

	/**
	 * Register the preflight status gate.
	 *
	 * @return void
	 */
	public function open() {
		if ($this->active) {
			return;
		}

		add_filter('wcos_v2_safe_quantity_split_statuses', array($this, 'filter_statuses'), PHP_INT_MAX, 2);
		$this->active = true;
	}

	/**
	 * Remove the preflight status gate.
	 *
	 * @return void
	 */
	public function close() {
		if (!$this->active) {
			return;
		}

		remove_filter('wcos_v2_safe_quantity_split_statuses', array($this, 'filter_statuses'), PHP_INT_MAX);
		$this->active = false;
	}

	/**
	 * Return no safe statuses when the exact source order has unsafe stock state.
	 *
	 * @param string[] $statuses Safe statuses.
	 * @param WC_Order $order    Source order.
	 * @return string[]
	 */
	public function filter_statuses($statuses, $order) {
		if (!$order instanceof WC_Order || $order->get_id() !== $this->order_id) {
			return $statuses;
		}

		$result = self::validate($order);

		return is_wp_error($result) ? array() : $statuses;
	}

	/**
	 * Validate product existence and WooCommerce stock markers.
	 *
	 * The first runtime adapter accepts only complete stock markers: a managed
	 * line is either wholly reduced or wholly unreduced. Partial markers require
	 * a separate allocation policy and therefore fail closed.
	 *
	 * @param WC_Order $order Source order.
	 * @return array|WP_Error Stable stock context on success.
	 */
	public static function validate(WC_Order $order) {
		$data_store = $order->get_data_store();

		if (!is_object($data_store) || !method_exists($data_store, 'get_stock_reduced')) {
			return self::error('wcos_stock_state_unreadable', __('The active WooCommerce order store cannot read stock state safely.', 'wc-order-splitter'));
		}

		$order_reduced = (bool) $data_store->get_stock_reduced($order->get_id());
		$context       = array(
			'order_id'            => $order->get_id(),
			'order_stock_reduced' => $order_reduced,
			'lines'               => array(),
		);

		foreach ($order->get_items('line_item') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				return self::error('wcos_invalid_product_line', __('The order contains an unsupported product line type.', 'wc-order-splitter'));
			}

			$product = $item->get_product();

			if (!$product instanceof WC_Product) {
				return self::error(
					'wcos_deleted_product_unsupported',
					sprintf(
						/* translators: %d: WooCommerce order item ID. */
						__('Order item %d no longer has a resolvable product and cannot be split safely.', 'wc-order-splitter'),
						(int) $item_id
					)
				);
			}

			$quantity = (float) $item->get_quantity();
			$marker   = $item->get_meta('_reduced_stock', true);
			$marker   = '' === $marker || null === $marker ? null : (float) $marker;
			$managed  = (bool) $product->managing_stock();

			if ($quantity <= 0) {
				return self::error('wcos_invalid_stock_quantity', __('The order contains a non-positive product quantity.', 'wc-order-splitter'));
			}

			if (null !== $marker && ($marker < 0 || abs($marker - $quantity) > 0.0000001)) {
				return self::error(
					'wcos_partial_stock_marker_unsupported',
					sprintf(
						/* translators: %d: WooCommerce order item ID. */
						__('Order item %d has a partial stock marker and requires manual review.', 'wc-order-splitter'),
						(int) $item_id
					)
				);
			}

			if ($order_reduced && $managed && null === $marker) {
				return self::error(
					'wcos_missing_stock_marker',
					sprintf(
						/* translators: %d: WooCommerce order item ID. */
						__('Managed-stock order item %d is missing its reduced-stock marker.', 'wc-order-splitter'),
						(int) $item_id
					)
				);
			}

			if (!$order_reduced && null !== $marker && $marker > 0) {
				return self::error(
					'wcos_stock_flag_marker_mismatch',
					sprintf(
						/* translators: %d: WooCommerce order item ID. */
						__('Order item %d has a stock marker while the order stock flag is not reduced.', 'wc-order-splitter'),
						(int) $item_id
					)
				);
			}

			$stock_owner_id = method_exists($product, 'get_stock_managed_by_id')
				? absint($product->get_stock_managed_by_id())
				: absint($product->get_id());

			$context['lines'][(int) $item_id] = array(
				'product_id'          => (int) $item->get_product_id(),
				'variation_id'        => (int) $item->get_variation_id(),
				'stock_managed'       => $managed,
				'stock_managed_by_id' => $stock_owner_id,
				'quantity'            => self::quantity($quantity),
				'reduced_stock'       => null === $marker ? null : self::quantity($marker),
			);
		}

		ksort($context['lines'], SORT_NUMERIC);

		return $context;
	}

	/**
	 * Normalize a stock quantity.
	 *
	 * @param float $quantity Quantity.
	 * @return string
	 */
	private static function quantity($quantity) {
		$value = number_format((float) $quantity, 12, '.', '');
		$value = rtrim(rtrim($value, '0'), '.');

		return '' === $value ? '0' : $value;
	}

	/**
	 * Create a stable stock safety error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}

	/**
	 * Ensure the filter cannot leak beyond object lifetime.
	 */
	public function __destruct() {
		$this->close();
	}
}
