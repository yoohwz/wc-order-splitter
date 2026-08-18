<?php
/**
 * Strict preflight fingerprint and source-integrity contracts.
 */

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/preflight-contract-test.php';
ob_end_clean();
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-strict-preflight.php';

function wcos_v2_strict_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$base = WCOS_V2_Strict_Preflight::validate(wcos_v2_test_order(), array(101 => '1'));
wcos_v2_strict_assert(!is_wp_error($base), 'A valid strict preflight was rejected.');
wcos_v2_strict_assert('complete_commercial_order_state' === $base['fingerprint_scope'], 'Strict fingerprint scope is missing.');
wcos_v2_strict_assert($base['base_fingerprint'] !== $base['fingerprint'], 'Strict fingerprint must bind more state than the base plan fingerprint.');

$changed_transaction = WCOS_V2_Strict_Preflight::validate(
	wcos_v2_test_order(array('transaction_id' => 'txn_changed')),
	array(101 => '1')
);
wcos_v2_strict_assert(!is_wp_error($changed_transaction), 'Changed transaction context produced an unexpected validation error.');
wcos_v2_strict_assert($base['fingerprint'] !== $changed_transaction['fingerprint'], 'Transaction context must participate in the operation fingerprint.');

$changed_line_order = wcos_v2_test_order();
$reflection = new ReflectionClass($changed_line_order);
$data_property = $reflection->getProperty('data');
$data_property->setAccessible(true);
$changed_data = $data_property->getValue($changed_line_order);
$changed_data['lines'][101] = new WC_Order_Item_Product(
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
			array('key' => '_addon_configuration', 'value' => array('engraving' => 'B')),
			array('key' => '_reduced_stock', 'value' => '3'),
		),
	)
);
$changed_identity = WCOS_V2_Strict_Preflight::validate(new WC_Order($changed_data), array(101 => '1'));
wcos_v2_strict_assert(!is_wp_error($changed_identity), 'Changed commercial metadata produced an unexpected validation error.');
wcos_v2_strict_assert($base['fingerprint'] !== $changed_identity['fingerprint'], 'Commercial metadata must participate in the operation fingerprint.');

$bad_stock_order = wcos_v2_test_order();
$bad_stock_reflection = new ReflectionClass($bad_stock_order);
$bad_stock_property = $bad_stock_reflection->getProperty('data');
$bad_stock_property->setAccessible(true);
$bad_stock_data = $bad_stock_property->getValue($bad_stock_order);
$bad_stock_data['lines'][101] = new WC_Order_Item_Product(
	array(
		'name'          => 'Invalid stock marker',
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
			array('key' => '_reduced_stock', 'value' => '4'),
		),
	)
);
$bad_stock = WCOS_V2_Strict_Preflight::validate(new WC_Order($bad_stock_data), array(101 => '1'));
wcos_v2_strict_assert(is_wp_error($bad_stock) && 'wcos_invalid_reduced_stock' === $bad_stock->get_error_code(), 'A stock marker greater than quantity must fail closed.');

$unknown_stock = new class($bad_stock_data) extends WC_Order {
	public function get_data_store() {
		return new stdClass();
	}
};
$unknown_stock_result = WCOS_V2_Strict_Preflight::validate($unknown_stock, array(101 => '1'));
wcos_v2_strict_assert(is_wp_error($unknown_stock_result) && 'wcos_unknown_stock_state' === $unknown_stock_result->get_error_code(), 'An unreadable order stock state must fail closed.');

echo "WCOS v2 strict preflight contract tests passed.\n";
