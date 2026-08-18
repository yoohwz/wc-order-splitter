<?php
/**
 * Pure-PHP stock consistency and service-boundary contracts.
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/');

class WP_Error {
	private $code;
	private $message;

	public function __construct($code = '', $message = '') {
		$this->code    = (string) $code;
		$this->message = (string) $message;
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

function esc_html__($text, $domain = null) {
	return $text;
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

$GLOBALS['wcos_v2_stock_filters'] = array();

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['wcos_v2_stock_filters'][$tag][] = array($callback, $priority, $accepted_args);
	return true;
}

function remove_filter($tag, $callback, $priority = 10) {
	if (empty($GLOBALS['wcos_v2_stock_filters'][$tag])) {
		return false;
	}

	foreach ($GLOBALS['wcos_v2_stock_filters'][$tag] as $index => $registered) {
		if ($registered[0] === $callback && $registered[1] === $priority) {
			unset($GLOBALS['wcos_v2_stock_filters'][$tag][$index]);
			return true;
		}
	}

	return false;
}

class WC_Product {
	private $id;
	private $managed;
	private $stock_owner_id;

	public function __construct($id, $managed = true, $stock_owner_id = null) {
		$this->id             = (int) $id;
		$this->managed        = (bool) $managed;
		$this->stock_owner_id = null === $stock_owner_id ? (int) $id : (int) $stock_owner_id;
	}

	public function managing_stock() {
		return $this->managed;
	}

	public function get_stock_managed_by_id() {
		return $this->stock_owner_id;
	}

	public function get_id() {
		return $this->id;
	}
}

class WC_Order_Item_Product {
	private $product;
	private $product_id;
	private $variation_id;
	private $quantity;
	private $marker;

	public function __construct($product, $quantity, $marker = null, $variation_id = 0) {
		$this->product      = $product;
		$this->product_id   = $product instanceof WC_Product ? $product->get_id() : 999;
		$this->variation_id = (int) $variation_id;
		$this->quantity     = $quantity;
		$this->marker       = $marker;
	}

	public function get_product() {
		return $this->product;
	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_variation_id() {
		return $this->variation_id;
	}

	public function get_quantity() {
		return $this->quantity;
	}

	public function get_meta($key, $single = true) {
		return '_reduced_stock' === $key && null !== $this->marker ? $this->marker : '';
	}
}

final class WCOS_V2_Test_Stock_Data_Store {
	private $reduced;

	public function __construct($reduced) {
		$this->reduced = (bool) $reduced;
	}

	public function get_stock_reduced($order_id) {
		return $this->reduced;
	}
}

class WC_Order {
	private $id;
	private $items;
	private $data_store;

	public function __construct($id, array $items, $reduced) {
		$this->id         = (int) $id;
		$this->items      = $items;
		$this->data_store = new WCOS_V2_Test_Stock_Data_Store($reduced);
	}

	public function get_id() {
		return $this->id;
	}

	public function get_items($type = 'line_item') {
		return 'line_item' === $type ? $this->items : array();
	}

	public function get_data_store() {
		return $this->data_store;
	}
}

$GLOBALS['wcos_v2_stock_orders'] = array();

function wc_get_order($order_id) {
	return isset($GLOBALS['wcos_v2_stock_orders'][(int) $order_id])
		? $GLOBALS['wcos_v2_stock_orders'][(int) $order_id]
		: false;
}

final class WCOS_V2_Quantity_Split_Service {
	public static $executions = 0;

	public static function create_operation_id($order_id, array $quantities, $nonce = null) {
		return 'qsplit.bound.operation';
	}

	public static function validate_operation_id($order_id, array $quantities, $operation_id) {
		return 'qsplit.bound.operation' === $operation_id
			? $operation_id
			: new WP_Error('wcos_operation_payload_mismatch', 'Operation mismatch.');
	}

	public static function execute($order_id, array $quantities, $operation_id) {
		++self::$executions;
		return array('success' => true, 'operation_id' => $operation_id);
	}
}

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-stock-safety-scope.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-safe-quantity-split-service.php';

function wcos_v2_stock_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$product = new WC_Product(50, true);
$valid_reduced = new WC_Order(
	13001,
	array(
		101 => new WC_Order_Item_Product($product, '3', '3'),
		102 => new WC_Order_Item_Product($product, '1', '1'),
	),
	true
);
$GLOBALS['wcos_v2_stock_orders'][13001] = $valid_reduced;

$context = WCOS_V2_Stock_Safety_Scope::validate($valid_reduced);
wcos_v2_stock_assert(!is_wp_error($context), 'A valid fully reduced order was rejected.');
wcos_v2_stock_assert(true === $context['order_stock_reduced'], 'The order stock flag was not captured.');
wcos_v2_stock_assert('3' === $context['lines'][101]['reduced_stock'], 'The full item stock marker was not captured.');

$result = WCOS_V2_Safe_Quantity_Split_Service::execute(13001, array(101 => '1'), 'qsplit.bound.operation');
wcos_v2_stock_assert(!is_wp_error($result) && true === $result['success'], 'The valid stock-gated request did not reach the executor.');
wcos_v2_stock_assert(1 === WCOS_V2_Quantity_Split_Service::$executions, 'The base service execution count is incorrect.');
wcos_v2_stock_assert(true === $result['stock_safety']['validated'], 'The stock safety evidence is missing.');
wcos_v2_stock_assert(empty($GLOBALS['wcos_v2_stock_filters']['wcos_v2_safe_quantity_split_statuses']), 'The stock safety filter leaked after execution.');

$valid_unreduced = new WC_Order(13002, array(201 => new WC_Order_Item_Product($product, '2', null)), false);
wcos_v2_stock_assert(!is_wp_error(WCOS_V2_Stock_Safety_Scope::validate($valid_unreduced)), 'A valid wholly unreduced order was rejected.');

$partial = new WC_Order(13003, array(301 => new WC_Order_Item_Product($product, '3', '2')), true);
$partial_result = WCOS_V2_Stock_Safety_Scope::validate($partial);
wcos_v2_stock_assert(is_wp_error($partial_result) && 'wcos_partial_stock_marker_unsupported' === $partial_result->get_error_code(), 'A partial stock marker must fail closed.');

$missing_marker = new WC_Order(13004, array(401 => new WC_Order_Item_Product($product, '3', null)), true);
$missing_result = WCOS_V2_Stock_Safety_Scope::validate($missing_marker);
wcos_v2_stock_assert(is_wp_error($missing_result) && 'wcos_missing_stock_marker' === $missing_result->get_error_code(), 'A fully reduced managed order without item markers must fail closed.');

$flag_mismatch = new WC_Order(13005, array(501 => new WC_Order_Item_Product($product, '3', '3')), false);
$flag_result = WCOS_V2_Stock_Safety_Scope::validate($flag_mismatch);
wcos_v2_stock_assert(is_wp_error($flag_result) && 'wcos_stock_flag_marker_mismatch' === $flag_result->get_error_code(), 'Item markers with an unreduced order flag must fail closed.');

$deleted_product = new WC_Order(13006, array(601 => new WC_Order_Item_Product(false, '1', null)), false);
$deleted_result = WCOS_V2_Stock_Safety_Scope::validate($deleted_product);
wcos_v2_stock_assert(is_wp_error($deleted_result) && 'wcos_deleted_product_unsupported' === $deleted_result->get_error_code(), 'A deleted product must fail closed.');

$unmanaged = new WC_Product(70, false);
$unmanaged_marker = new WC_Order(13007, array(701 => new WC_Order_Item_Product($unmanaged, '1', '1')), true);
$GLOBALS['wcos_v2_stock_orders'][13007] = $unmanaged_marker;
$unmanaged_result = WCOS_V2_Safe_Quantity_Split_Service::execute(13007, array(701 => '1'), 'qsplit.bound.operation');
wcos_v2_stock_assert(is_wp_error($unmanaged_result) && 'wcos_unmanaged_product_stock_marker' === $unmanaged_result->get_error_code(), 'An unmanaged product with a stock marker must fail at the authoritative service boundary.');
wcos_v2_stock_assert(1 === WCOS_V2_Quantity_Split_Service::$executions, 'An unsafe unmanaged-stock request reached the base service.');

$scope = new WCOS_V2_Stock_Safety_Scope(13003);
$safe_statuses = array('processing');
wcos_v2_stock_assert(array() === $scope->filter_statuses($safe_statuses, $partial), 'The scoped filter did not reject unsafe stock state.');
wcos_v2_stock_assert($safe_statuses === $scope->filter_statuses($safe_statuses, $valid_reduced), 'The scoped filter affected another source order.');
$scope->close();

echo "WCOS v2 stock-safety contracts passed.\n";
