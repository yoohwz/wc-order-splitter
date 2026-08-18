<?php
/**
 * Pure-PHP contracts for semantic rollback snapshot comparison.
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/');

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct($code = '', $message = '', $data = array()) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}
}

function is_wp_error($value) {
	return $value instanceof WP_Error;
}

function __($text, $domain = null) {
	return $text;
}

function sanitize_key($value) {
	return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function wp_strip_all_tags($value) {
	return strip_tags((string) $value);
}

function wp_json_encode($value, $flags = 0) {
	return json_encode($value, $flags);
}

function wc_get_price_decimals() {
	return 2;
}

class WC_Order {}

final class WCOS_V2_Order_Snapshot {
	public static $current = array();

	public static function capture(WC_Order $order) {
		return self::$current;
	}
}

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-amount-allocator.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-metadata-policy.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-snapshot-comparator.php';

function wcos_v2_snapshot_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$snapshot = array(
	'order_id'            => 15001,
	'order_type'          => 'shop_order',
	'status'              => 'processing',
	'currency'            => 'USD',
	'prices_include_tax'  => false,
	'customer_id'         => 77,
	'transaction_id'      => 'txn-source',
	'has_refunds'         => false,
	'order_stock_reduced' => true,
	'amounts'             => array(
		'subtotal'       => '15.00',
		'discount_total' => '1.00',
		'discount_tax'   => '0.10',
		'shipping_total' => '5.00',
		'shipping_tax'   => '0.50',
		'cart_tax'       => '1.40',
		'total_tax'      => '1.90',
		'total'          => '20.90',
	),
	'lines' => array(
		101 => array(
			'item_id'       => 101,
			'name'          => 'Configured product',
			'product_id'    => 50,
			'variation_id'  => 501,
			'tax_class'     => 'reduced-rate',
			'identity'      => hash('sha256', 'line-identity'),
			'quantity'      => '3',
			'subtotal'      => '10.00',
			'total'         => '9.00',
			'subtotal_tax'  => '1.00',
			'total_tax'     => '0.90',
			'taxes'         => array(
				'subtotal' => array('2' => '0.40', '1' => '0.60'),
				'total'    => array('2' => '0.30', '1' => '0.60'),
			),
			'reduced_stock' => '3',
			'metadata'      => array(
				array('key' => '_addon_configuration', 'value' => array('engraving' => 'A', 'gift' => true)),
				array('key' => 'attribute_pa_color', 'value' => 'red'),
				array('key' => '_reduced_stock', 'value' => '3'),
			),
		),
	),
	'shipping_items' => array(
		201 => array(
			'type' => 'shipping',
			'data' => array(
				'id'        => 201,
				'order_id'  => 15001,
				'method_id' => 'flat_rate',
				'total'     => '5.00',
			),
			'metadata' => array(
				array('key' => 'Items', 'value' => 'Configured product × 3'),
			),
		),
	),
	'fee_items'    => array(),
	'coupon_items' => array(),
	'tax_items'    => array(
		301 => array(
			'type' => 'tax',
			'data' => array(
				'id'                 => 301,
				'order_id'           => 15001,
				'rate_id'            => 1,
				'tax_total'          => '1.40',
				'shipping_tax_total' => '0.50',
			),
			'metadata' => array(),
		),
	),
);

$current = $snapshot;
$current['amounts']['total'] = '20.900';
$current['lines'][101]['taxes'] = array(
	'total'    => array('1' => '0.600', '2' => '0.300'),
	'subtotal' => array('1' => '0.600', '2' => '0.400'),
);
$current['lines'][101]['metadata'] = array(
	array('key' => '_reduced_stock', 'value' => '3'),
	array('key' => 'attribute_pa_color', 'value' => 'red'),
	array('key' => '_addon_configuration', 'value' => array('gift' => true, 'engraving' => 'A')),
);
$current['shipping_items'][201]['data'] = array(
	'total'     => '5.00',
	'method_id' => 'flat_rate',
	'order_id'  => 99999,
	'id'        => 88888,
);
$current['shipping_items'][201]['metadata'] = array(
	array('key' => 'Items', 'value' => 'Configured product × 3'),
);
$current['tax_items'][301]['data'] = array(
	'shipping_tax_total' => '0.50',
	'tax_total'          => '1.40',
	'rate_id'            => 1,
	'order_id'           => 99999,
	'id'                 => 88888,
);
WCOS_V2_Order_Snapshot::$current = $current;

$result = WCOS_V2_Snapshot_Comparator::verify(new WC_Order(), $snapshot);
wcos_v2_snapshot_assert(true === $result, 'Equivalent business state with different serialization order was rejected.');

$amount_mismatch = $current;
$amount_mismatch['amounts']['total'] = '20.89';
WCOS_V2_Order_Snapshot::$current = $amount_mismatch;
$result = WCOS_V2_Snapshot_Comparator::verify(new WC_Order(), $snapshot);
wcos_v2_snapshot_assert(is_wp_error($result) && 'wcos_snapshot_amount_mismatch' === $result->get_error_code(), 'A changed order total was not detected.');

$identity_mismatch = $current;
$identity_mismatch['lines'][101]['identity'] = hash('sha256', 'different-line');
WCOS_V2_Order_Snapshot::$current = $identity_mismatch;
$result = WCOS_V2_Snapshot_Comparator::verify(new WC_Order(), $snapshot);
wcos_v2_snapshot_assert(is_wp_error($result) && 'wcos_snapshot_line_identity_mismatch' === $result->get_error_code(), 'A changed commercial line identity was not detected.');

$stock_mismatch = $current;
$stock_mismatch['lines'][101]['reduced_stock'] = '2';
WCOS_V2_Order_Snapshot::$current = $stock_mismatch;
$result = WCOS_V2_Snapshot_Comparator::verify(new WC_Order(), $snapshot);
wcos_v2_snapshot_assert(is_wp_error($result) && 'wcos_snapshot_line_stock_mismatch' === $result->get_error_code(), 'A changed stock marker was not detected.');

$metadata_mismatch = $current;
$metadata_mismatch['lines'][101]['metadata'][2]['value'] = array('gift' => true, 'engraving' => 'B');
WCOS_V2_Order_Snapshot::$current = $metadata_mismatch;
$result = WCOS_V2_Snapshot_Comparator::verify(new WC_Order(), $snapshot);
wcos_v2_snapshot_assert(is_wp_error($result) && 'wcos_snapshot_line_metadata_mismatch' === $result->get_error_code(), 'Changed commercial metadata was not detected.');

$shipping_mismatch = $current;
$shipping_mismatch['shipping_items'][201]['data']['total'] = '6.00';
WCOS_V2_Order_Snapshot::$current = $shipping_mismatch;
$result = WCOS_V2_Snapshot_Comparator::verify(new WC_Order(), $snapshot);
wcos_v2_snapshot_assert(is_wp_error($result) && 'wcos_snapshot_order_item_mismatch' === $result->get_error_code(), 'A changed source shipping item was not detected.');

echo "WCOS v2 snapshot-comparator contracts passed.\n";
