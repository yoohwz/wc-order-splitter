<?php
/**
 * Pure-PHP contracts for one-child quantity split specifications.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-amount-allocator.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-metadata-policy.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-line-identity.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-split-plan.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-quantity-split-specification.php';

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure context.
 * @return void
 */
function wcos_v2_spec_assert_same($expected, $actual, $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . PHP_EOL
			. 'Expected: ' . var_export($expected, true) . PHP_EOL
			. 'Actual:   ' . var_export($actual, true)
		);
	}
}

/**
 * Assert an exception class.
 *
 * @param string   $class    Exception class.
 * @param callable $callback Callback.
 * @param string   $message  Failure context.
 * @return void
 */
function wcos_v2_spec_assert_throws($class, callable $callback, $message): void {
	try {
		$callback();
	} catch (Throwable $throwable) {
		if ($throwable instanceof $class) {
			return;
		}

		throw new RuntimeException($message . ': unexpected ' . get_class($throwable));
	}

	throw new RuntimeException($message . ': no exception was thrown.');
}

$line_one = array(
	'item_id'       => 101,
	'name'          => 'Configured variation',
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
	'reduced_stock' => '3',
	'metadata'      => array(
		array('key' => '_addon_configuration', 'value' => array('engraving' => 'A')),
		array('key' => '_reduced_stock', 'value' => '3'),
	),
);
$line_one['identity'] = WCOS_V2_Line_Identity::from_snapshot($line_one)['signature'];

$line_two = array(
	'item_id'       => 102,
	'name'          => 'Remaining product',
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
	'reduced_stock' => null,
	'metadata'      => array(),
);
$line_two['identity'] = WCOS_V2_Line_Identity::from_snapshot($line_two)['signature'];

$snapshot = array(
	'order_id'            => 9001,
	'order_type'          => 'shop_order',
	'status'              => 'processing',
	'currency'            => 'USD',
	'prices_include_tax'  => false,
	'customer_id'         => 77,
	'transaction_id'      => 'txn-source-only',
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
	'lines'          => array(
		101 => $line_one,
		102 => $line_two,
	),
	'shipping_items' => array(
		201 => array(
			'type' => 'shipping',
			'data' => array('total' => '5.00', 'taxes' => array('total' => array('1' => '0.50'))),
			'metadata' => array(),
		),
	),
	'fee_items'      => array(),
	'coupon_items'   => array(),
	'tax_items'      => array(
		301 => array(
			'type' => 'tax',
			'data' => array(
				'rate_id'            => 1,
				'label'              => 'Test tax',
				'compound'           => false,
				'rate_code'          => 'US-TEST-1',
				'rate_percent'       => '10.0000',
				'tax_total'          => '1.40',
				'shipping_tax_total' => '0.50',
			),
			'metadata' => array(),
		),
	),
);

$plan = WCOS_V2_Split_Plan::build(
	array(
		array_merge($line_one, array('split_quantity' => '1')),
		array_merge($line_two, array('split_quantity' => '0')),
	),
	2
);

$preflight = array(
	'fingerprint_scope' => 'complete_commercial_order_state',
	'fingerprint'       => hash('sha256', 'strict-preflight-state'),
	'snapshot'          => $snapshot,
	'plan'              => $plan,
);

$specification = WCOS_V2_Quantity_Split_Specification::build($preflight);

wcos_v2_spec_assert_same('quantity_split_one_child', $specification['operation_type'], 'The operation type is incorrect.');
wcos_v2_spec_assert_same('pending', $specification['initial_child_status'], 'The child must be created in a neutral pending state.');
wcos_v2_spec_assert_same('processing', $specification['desired_child_status'], 'The desired child status must retain source context.');
wcos_v2_spec_assert_same(false, $specification['settlement']['copy_transaction_id'], 'A transaction ID must never be copied.');
wcos_v2_spec_assert_same('keep_on_source', $specification['charge_policy']['shipping'], 'Shipping ownership is incorrect.');
wcos_v2_spec_assert_same('3.33', $specification['child']['lines'][101]['subtotal'], 'Child line subtotal allocation is incorrect.');
wcos_v2_spec_assert_same('3.00', $specification['child']['lines'][101]['total'], 'Child line total allocation is incorrect.');
wcos_v2_spec_assert_same('0.30', $specification['child']['lines'][101]['total_tax'], 'Child line tax allocation is incorrect.');
wcos_v2_spec_assert_same('1', $specification['child']['lines'][101]['reduced_stock'], 'Child reduced-stock allocation is incorrect.');
wcos_v2_spec_assert_same('2', $specification['source']['lines'][101]['reduced_stock'], 'Source reduced-stock allocation is incorrect.');
wcos_v2_spec_assert_same('3.30', $specification['child']['amounts']['total'], 'Child grand total is incorrect.');
wcos_v2_spec_assert_same('17.60', $specification['source']['amounts']['total'], 'Source grand total is incorrect.');
wcos_v2_spec_assert_same('0.33', $specification['child']['amounts']['discount_total'], 'Child historical line discount is incorrect.');
wcos_v2_spec_assert_same('0.67', $specification['source']['amounts']['discount_total'], 'Source historical line discount is incorrect.');
wcos_v2_spec_assert_same('0.30', $specification['child']['tax_items']['1']['tax_total'], 'Child tax item allocation is incorrect.');
wcos_v2_spec_assert_same('1.10', $specification['source']['tax_items'][301]['tax_total'], 'Source tax item allocation is incorrect.');
wcos_v2_spec_assert_same('0.50', $specification['source']['tax_items'][301]['shipping_tax_total'], 'Source shipping tax must remain unchanged.');
wcos_v2_spec_assert_same('0.00', $specification['child']['tax_items']['1']['shipping_tax_total'], 'Child must not receive source shipping tax.');
wcos_v2_spec_assert_same('0', $specification['stock']['physical_delta'], 'A split must not change physical stock.');
wcos_v2_spec_assert_same(1, count($specification['child']['lines'][101]['metadata']), 'Technical stock metadata must not be copied.');
wcos_v2_spec_assert_same('_addon_configuration', $specification['child']['lines'][101]['metadata'][0]['key'], 'Business metadata was not preserved.');

WCOS_V2_Quantity_Split_Specification::assert_conservation($snapshot, $specification);

$repeat = WCOS_V2_Quantity_Split_Specification::build($preflight);
wcos_v2_spec_assert_same($specification['fingerprint'], $repeat['fingerprint'], 'An identical specification must have a stable fingerprint.');

$full_line_plan = WCOS_V2_Split_Plan::build(
	array(
		array_merge($line_one, array('split_quantity' => '3')),
		array_merge($line_two, array('split_quantity' => '0')),
	),
	2
);
$full_line_preflight         = $preflight;
$full_line_preflight['plan'] = $full_line_plan;
$full_line_spec              = WCOS_V2_Quantity_Split_Specification::build($full_line_preflight);
wcos_v2_spec_assert_same('remove', $full_line_spec['source']['lines'][101]['action'], 'A fully moved line must be explicitly removed from the source.');
wcos_v2_spec_assert_same('create', $full_line_spec['child']['lines'][101]['action'], 'A fully moved line must be newly created on the child.');

$coupon_preflight = $preflight;
$coupon_preflight['snapshot']['coupon_items'] = array(
	401 => array('type' => 'coupon', 'data' => array('code' => 'TEST')),
);
wcos_v2_spec_assert_throws(
	InvalidArgumentException::class,
	static function () use ($coupon_preflight): void {
		WCOS_V2_Quantity_Split_Specification::build($coupon_preflight);
	},
	'Coupon-bearing orders must fail closed in the first adapter.'
);

$missing_tax_preflight = $preflight;
$missing_tax_preflight['snapshot']['tax_items'] = array();
wcos_v2_spec_assert_throws(
	LogicException::class,
	static function () use ($missing_tax_preflight): void {
		WCOS_V2_Quantity_Split_Specification::build($missing_tax_preflight);
	},
	'A child tax rate without a source tax item must fail closed.'
);

$stock_mismatch_preflight = $preflight;
$stock_mismatch_preflight['snapshot']['order_stock_reduced'] = false;
wcos_v2_spec_assert_throws(
	LogicException::class,
	static function () use ($stock_mismatch_preflight): void {
		WCOS_V2_Quantity_Split_Specification::build($stock_mismatch_preflight);
	},
	'Item stock markers with a false order stock flag must fail closed.'
);

echo "WCOS v2 quantity-split specification contracts passed.\n";
