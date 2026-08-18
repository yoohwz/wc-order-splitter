<?php
/**
 * Pure-PHP contract tests for the v2 mutation planner.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-amount-allocator.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-split-plan.php';

/**
 * Fail a test with a useful message.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure context.
 * @return void
 */
function wcos_v2_assert_same($expected, $actual, $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . PHP_EOL
			. 'Expected: ' . var_export($expected, true) . PHP_EOL
			. 'Actual:   ' . var_export($actual, true)
		);
	}
}

/**
 * Assert that a callback throws the expected exception class.
 *
 * @param string   $class    Exception class.
 * @param callable $callback Callback under test.
 * @param string   $message  Failure context.
 * @return void
 */
function wcos_v2_assert_throws($class, callable $callback, $message): void {
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

$allocation = WCOS_V2_Amount_Allocator::allocate(
	'10.00',
	array(
		'original' => 2,
		'child'    => 1,
	),
	2
);

wcos_v2_assert_same('6.67', $allocation['original'], 'Largest remainder must be deterministic.');
wcos_v2_assert_same('3.33', $allocation['child'], 'Child allocation must preserve the source amount.');
wcos_v2_assert_same(
	1000,
	WCOS_V2_Amount_Allocator::to_minor_units($allocation['original'], 2)
	+ WCOS_V2_Amount_Allocator::to_minor_units($allocation['child'], 2),
	'Positive amount conservation failed.'
);

$negative_allocation = WCOS_V2_Amount_Allocator::allocate(
	'-0.05',
	array(
		'first'  => 1,
		'second' => 1,
	),
	2
);

wcos_v2_assert_same('-0.03', $negative_allocation['first'], 'Negative tie-breaking must remain stable.');
wcos_v2_assert_same('-0.02', $negative_allocation['second'], 'Negative allocations must conserve sign and sum.');

$lines = array(
	array(
		'item_id'          => 101,
		'quantity'         => '3',
		'split_quantity'   => '1',
		'subtotal'         => '10.00',
		'total'            => '9.00',
		'subtotal_tax'     => '1.00',
		'total_tax'        => '0.90',
		'reduced_stock'    => '3',
		'taxes'            => array(
			'subtotal' => array(
				'1' => '0.70',
				'2' => '0.30',
			),
			'total'    => array(
				'1' => '0.63',
				'2' => '0.27',
			),
		),
	),
	array(
		'item_id'          => 102,
		'quantity'         => '1',
		'split_quantity'   => '0',
		'subtotal'         => '5.00',
		'total'            => '5.00',
		'subtotal_tax'     => '0.50',
		'total_tax'        => '0.50',
		'reduced_stock'    => null,
		'taxes'            => array(
			'subtotal' => array('1' => '0.50'),
			'total'    => array('1' => '0.50'),
		),
	),
);

$plan = WCOS_V2_Split_Plan::build($lines, 2);

wcos_v2_assert_same('4', $plan['source_quantity'], 'Source quantity summary is incorrect.');
wcos_v2_assert_same('1', $plan['split_quantity'], 'Split quantity summary is incorrect.');
wcos_v2_assert_same('3', $plan['original_quantity'], 'Original quantity summary is incorrect.');
wcos_v2_assert_same('6.67', $plan['lines'][101]['original']['subtotal'], 'Original subtotal allocation is incorrect.');
wcos_v2_assert_same('3.33', $plan['lines'][101]['child']['subtotal'], 'Child subtotal allocation is incorrect.');
wcos_v2_assert_same('2', $plan['lines'][101]['original']['reduced_stock'], 'Original reduced stock was not conserved.');
wcos_v2_assert_same('1', $plan['lines'][101]['child']['reduced_stock'], 'Child reduced stock was not conserved.');
wcos_v2_assert_same('0.47', $plan['lines'][101]['original']['taxes']['subtotal']['1'], 'Original per-rate tax allocation is incorrect.');
wcos_v2_assert_same('0.23', $plan['lines'][101]['child']['taxes']['subtotal']['1'], 'Child per-rate tax allocation is incorrect.');

$reversed_plan = WCOS_V2_Split_Plan::build(array_reverse($lines), 2);
wcos_v2_assert_same($plan['fingerprint'], $reversed_plan['fingerprint'], 'Fingerprint must not depend on input line order.');

wcos_v2_assert_throws(
	InvalidArgumentException::class,
	static function (): void {
		WCOS_V2_Split_Plan::build(
			array(
				array(
					'item_id'        => 1,
					'quantity'       => 1,
					'split_quantity' => 1,
				),
			),
			2
		);
	},
	'Splitting every source quantity must fail closed.'
);

wcos_v2_assert_throws(
	InvalidArgumentException::class,
	static function (): void {
		WCOS_V2_Amount_Allocator::allocate('1.00', array('invalid' => -1), 2);
	},
	'Negative allocation weights must be rejected.'
);

echo "WCOS v2 contract tests passed.\n";
