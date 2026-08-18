<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('wc_get_price_decimals')) {
	function wc_get_price_decimals() {
		return 2;
	}
}

if (!function_exists('wc_format_decimal')) {
	function wc_format_decimal($number, $dp = false) {
		$dp = false === $dp ? 2 : (int) $dp;
		return number_format((float) $number, $dp, '.', '');
	}
}

if (!function_exists('maybe_serialize')) {
	function maybe_serialize($data) {
		return is_scalar($data) || null === $data ? (string) $data : serialize($data);
	}
}

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data) {
		return json_encode($data);
	}
}

if (!function_exists('apply_filters')) {
	function apply_filters($hook, $value) {
		return $value;
	}
}

if (!function_exists('__')) {
	function __($text) {
		return $text;
	}
}

class WC_Order_Splitter_Test_Meta {
	public $key;
	public $value;

	public function __construct($key, $value) {
		$this->key = $key;
		$this->value = $value;
	}
}

class WC_Order_Item_Product {
	private $product_id;
	private $variation_id;
	private $tax_class;
	private $name;
	private $meta;

	public function __construct($product_id, $variation_id, $tax_class, $name, $meta = array()) {
		$this->product_id = $product_id;
		$this->variation_id = $variation_id;
		$this->tax_class = $tax_class;
		$this->name = $name;
		$this->meta = $meta;
	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_variation_id() {
		return $this->variation_id;
	}

	public function get_tax_class() {
		return $this->tax_class;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_meta_data() {
		return $this->meta;
	}
}

require_once dirname(__DIR__) . '/inc/cores/order-mutation/class-mutation-support.php';
