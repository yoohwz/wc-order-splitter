<?php
/**
 * Adapter contract tests for the read-only WooCommerce split preflight.
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/');

class WP_Error {
	private $code;
	private $message;

	public function __construct($code = '', $message = '') {
		$this->code = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error($value) {
	return $value instanceof WP_Error;
}

function __($text, $domain = null) {
	return $text;
}

function apply_filters($tag, $value) {
	return $value;
}

function absint($value) {
	return abs((int) $value);
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

$GLOBALS['wcos_v2_test_options'] = array(
	'order_splitter_status_allowed' => array('wc-processing'),
);

function get_option($key, $default = false) {
	return array_key_exists($key, $GLOBALS['wcos_v2_test_options']) ? $GLOBALS['wcos_v2_test_options'][$key] : $default;
}

final class WCOS_V2_Test_Meta {
	public $key;
	public $value;

	public function __construct($key, $value) {
		$this->key = $key;
		$this->value = $value;
	}
}

class WC_Order_Item_Product {
	private $data;

	public function __construct(array $data) {
		$this->data = $data;
	}

	public function get_name() {
		return $this->data['name'];
	}

	public function get_product_id() {
		return $this->data['product_id'];
	}

	public function get_variation_id() {
		return $this->data['variation_id'];
	}

	public function get_tax_class() {
		return $this->data['tax_class'];
	}

	public function get_quantity() {
		return $this->data['quantity'];
	}

	public function get_subtotal() {
		return $this->data['subtotal'];
	}

	public function get_total() {
		return $this->data['total'];
	}

	public function get_subtotal_tax() {
		return $this->data['subtotal_tax'];
	}

	public function get_total_tax() {
		return $this->data['total_tax'];
	}

	public function get_taxes() {
		return $this->data['taxes'];
	}

	public function get_meta_data() {
		$result = array();

		foreach ($this->data['metadata'] as $record) {
			$result[] = new WCOS_V2_Test_Meta($record['key'], $record['value']);
		}

		return $result;
	}

	public function get_meta($key, $single = true) {
		foreach ($this->data['metadata'] as $record) {
			if ($key === $record['key']) {
				return $record['value'];
			}
		}

		return '';
	}
}

final class WCOS_V2_Test_Data_Store {
	private $stock_reduced;

	public function __construct($stock_reduced) {
		$this->stock_reduced = (bool) $stock_reduced;
	}

	public function get_stock_reduced($order_id) {
		return $this->stock_reduced;
	}
}

class WC_Order {
	private $data;
	private $data_store;

	public function __construct(array $data) {
		$this->data = $data;
		$this->data_store = new WCOS_V2_Test_Data_Store($data['order_stock_reduced']);
	}

	public function get_id() { return $this->data['order_id']; }
	public function get_type() { return $this->data['order_type']; }
	public function get_order_number() { return (string) $this->data['order_id']; }
	public function get_status() { return $this->data['status']; }
	public function get_currency() { return $this->data['currency']; }
	public function get_prices_include_tax() { return $this->data['prices_include_tax']; }
	public function get_customer_id() { return $this->data['customer_id']; }
	public function get_transaction_id() { return $this->data['transaction_id']; }
	public function get_refunds() { return $this->data['refunds']; }
	public function get_total_refunded() { return $this->data['total_refunded']; }
	public function get_subtotal() { return $this->data['amounts']['subtotal']; }
	public function get_discount_total() { return $this->data['amounts']['discount_total']; }
	public function get_discount_tax() { return $this->data['amounts']['discount_tax']; }
	public function get_shipping_total() { return $this->data['amounts']['shipping_total']; }
	public function get_shipping_tax() { return $this->data['amounts']['shipping_tax']; }
	public function get_cart_tax() { return $this->data['amounts']['cart_tax']; }
	public function get_total_tax() { return $this->data['amounts']['total_tax']; }
	public function get_total() { return $this->data['amounts']['total']; }
	public function get_data_store() { return $this->data_store; }

	public function get_items($type = 'line_item') {
		if ('line_item' === $type) {
			return $this->data['lines'];
		}

		return array();
	}
}

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-amount-allocator.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-metadata-policy.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-line-identity.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-split-plan.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-order-snapshot.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-split-preflight.php';

function wcos_v2_preflight_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_v2_test_order(array $overrides = array()): WC_Order {
	$lines = array(
		101 => new WC_Order_Item_Product(
			array(
				'name'          => 'Configured red variation',
				'product_id'    => 50,
				'variation_id'  => 501,
				'tax_class'     => 'reduced-rate',
				'quantity'      => '3',
				'subtotal'      => '10.00',
				'total'         => '9.00',
				'subtotal_tax'  => '1.00',
				'total_tax'     => '0.90',
				'taxes'         => array(
					'subtotal' => array('1' => '1.00'),
					'total'    => array('1' => '0.90'),
				),
				'metadata'      => array(
					array('key' => 'attribute_pa_color', 'value' => 'red'),
					array('key' => '_addon_configuration', 'value' => array('engraving' => 'A')),
					array('key' => '_reduced_stock', 'value' => '3'),
				),
			)
		),
		102 => new WC_Order_Item_Product(
			array(
				'name'          => 'Second product',
				'product_id'    => 60,
				'variation_id'  => 0,
				'tax_class'     => '',
				'quantity'      => '1',
				'subtotal'      => '5.00',
				'total'         => '5.00',
				'subtotal_tax'  => '0.50',
				'total_tax'     => '0.50',
				'taxes'         => array(
					'subtotal' => array('1' => '0.50'),
					'total'    => array('1' => '0.50'),
				),
				'metadata'      => array(),
			)
		),
	);

	$data = array(
		'order_id'             => 9001,
		'order_type'           => 'shop_order',
		'status'               => 'processing',
		'currency'             => 'USD',
		'prices_include_tax'   => false,
		'customer_id'          => 77,
		'transaction_id'       => 'txn_original_only',
		'refunds'              => array(),
		'total_refunded'       => '0.00',
		'order_stock_reduced'  => true,
		'lines'                => $lines,
		'amounts'              => array(
			'subtotal'       => '15.00',
			'discount_total' => '1.00',
			'discount_tax'   => '0.10',
			'shipping_total' => '5.00',
			'shipping_tax'   => '0.50',
			'cart_tax'       => '1.40',
			'total_tax'      => '1.90',
			'total'          => '20.90',
		),
	);

	$data = array_replace_recursive($data, $overrides);

	return new WC_Order($data);
}

$valid = WCOS_V2_Split_Preflight::validate(wcos_v2_test_order(), array(101 => '1'));
wcos_v2_preflight_assert(!is_wp_error($valid), 'A valid read-only preflight was rejected.');
wcos_v2_preflight_assert('1' === $valid['plan']['split_quantity'], 'The preflight produced an incorrect split quantity.');
wcos_v2_preflight_assert('1' === $valid['plan']['lines'][101]['child']['reduced_stock'], 'The preflight did not conserve the child stock marker.');
wcos_v2_preflight_assert('txn_original_only' === $valid['snapshot']['transaction_id'], 'The source transaction context was not snapshotted.');

$retry = WCOS_V2_Split_Preflight::validate(wcos_v2_test_order(), array(101 => '1'));
wcos_v2_preflight_assert($valid['fingerprint'] === $retry['fingerprint'], 'An identical request must have an identical fingerprint.');

$unknown_item = WCOS_V2_Split_Preflight::validate(wcos_v2_test_order(), array(999 => '1'));
wcos_v2_preflight_assert(is_wp_error($unknown_item) && 'wcos_unknown_order_item' === $unknown_item->get_error_code(), 'Unknown items must fail closed.');

$refunded = WCOS_V2_Split_Preflight::validate(
	wcos_v2_test_order(array('refunds' => array((object) array('id' => 1)))),
	array(101 => '1')
);
wcos_v2_preflight_assert(is_wp_error($refunded) && 'wcos_refunded_order_unsupported' === $refunded->get_error_code(), 'Refunded orders must fail closed.');

$unsupported_status = WCOS_V2_Split_Preflight::validate(
	wcos_v2_test_order(array('status' => 'cancelled')),
	array(101 => '1')
);
wcos_v2_preflight_assert(is_wp_error($unsupported_status) && 'wcos_unsupported_order_status' === $unsupported_status->get_error_code(), 'Unsupported statuses must fail closed.');

$all_items = WCOS_V2_Split_Preflight::validate(wcos_v2_test_order(), array(101 => '3', 102 => '1'));
wcos_v2_preflight_assert(is_wp_error($all_items) && 'wcos_invalid_split_plan' === $all_items->get_error_code(), 'Moving the whole order must fail closed.');

$bad_lines = wcos_v2_test_order();
$reflection = new ReflectionClass($bad_lines);
$data_property = $reflection->getProperty('data');
$data_property->setAccessible(true);
$bad_data = $data_property->getValue($bad_lines);
$bad_data['lines'][101] = new WC_Order_Item_Product(
	array(
		'name'          => 'Bad tax snapshot',
		'product_id'    => 50,
		'variation_id'  => 501,
		'tax_class'     => '',
		'quantity'      => '3',
		'subtotal'      => '10.00',
		'total'         => '9.00',
		'subtotal_tax'  => '1.00',
		'total_tax'     => '0.90',
		'taxes'         => array(
			'subtotal' => array('1' => '0.99'),
			'total'    => array('1' => '0.90'),
		),
		'metadata'      => array(),
	)
);
$bad_tax = WCOS_V2_Split_Preflight::validate(new WC_Order($bad_data), array(101 => '1'));
wcos_v2_preflight_assert(is_wp_error($bad_tax) && 'wcos_inconsistent_historical_tax' === $bad_tax->get_error_code(), 'Inconsistent historical tax data must fail closed.');

echo "WCOS v2 preflight contract tests passed.\n";
