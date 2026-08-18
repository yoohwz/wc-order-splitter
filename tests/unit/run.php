<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/domain/class-wcos-amount-allocator.php';
require_once dirname(__DIR__, 2) . '/inc/domain/class-wcos-line-identity.php';
require_once dirname(__DIR__, 2) . '/inc/domain/class-wcos-mutation-contract.php';

$tests = array();

$tests['allocator preserves cents deterministically'] = static function() {
	$result = WCOS_Amount_Allocator::allocate('10.00', array('a' => 1, 'b' => 1, 'c' => 1), 2);
	assert_same(array('a' => '3.34', 'b' => '3.33', 'c' => '3.33'), $result);
};

$tests['allocator preserves negative amounts'] = static function() {
	$result = WCOS_Amount_Allocator::allocate('-1.00', array('a' => 1, 'b' => 3), 2);
	assert_same(array('a' => '-0.25', 'b' => '-0.75'), $result);
};

$tests['allocator supports stock precision'] = static function() {
	$result = WCOS_Amount_Allocator::allocate('1.000000', array('original' => 2, 'child' => 1), 6);
	assert_same('1.000000', decimal_sum($result, 6));
};

$tests['line identity ignores associative metadata order'] = static function() {
	$first = WCOS_Line_Identity::from_values(10, 20, 'reduced-rate', array('engraving' => 'A', 'gift' => array('wrap' => true, 'note' => 'Hi')));
	$second = WCOS_Line_Identity::from_values(10, 20, 'reduced-rate', array('gift' => array('note' => 'Hi', 'wrap' => true), 'engraving' => 'A'));
	assert_same($first, $second);
};

$tests['line identity distinguishes variations'] = static function() {
	$first = WCOS_Line_Identity::from_values(10, 20, '', array());
	$second = WCOS_Line_Identity::from_values(10, 21, '', array());
	assert_true($first !== $second, 'Different variations must not share an identity.');
};

$tests['mutation contract accepts conserved snapshot'] = static function() {
	$before = array(
		'line_subtotal' => '100.00',
		'line_total' => '90.00',
		'discount_total' => '10.00',
		'fees_total' => '2.00',
		'shipping_total' => '5.00',
		'tax_total' => '9.70',
		'grand_total' => '106.70',
		'stock_reduced' => '3.000000',
		'line_quantities' => array('line-a' => 2, 'line-b' => 1),
	);
	WCOS_Mutation_Contract::assert_conserved($before, $before, 2);
};

$tests['mutation contract rejects monetary drift'] = static function() {
	$before = array('grand_total' => '10.00');
	$after = array('grand_total' => '9.99');
	$thrown = false;
	try {
		WCOS_Mutation_Contract::assert_conserved($before, $after, 2);
	} catch (RuntimeException $exception) {
		$thrown = true;
	}
	assert_true($thrown, 'A one-cent drift must fail the contract.');
};

$failures = 0;
foreach ($tests as $name => $test) {
	try {
		$test();
		echo "PASS: {$name}\n";
	} catch (Throwable $throwable) {
		$failures++;
		fwrite(STDERR, "FAIL: {$name}: {$throwable->getMessage()}\n");
	}
}

if ($failures > 0) {
	exit(1);
}

function assert_same($expected, $actual) {
	if ($expected !== $actual) {
		throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function assert_true($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function decimal_sum(array $values, $precision) {
	$factor = 10 ** (int) $precision;
	$units = 0;
	foreach ($values as $value) {
		$units += (int) round(((float) $value) * $factor, 0, PHP_ROUND_HALF_UP);
	}
	return number_format($units / $factor, (int) $precision, '.', '');
}
