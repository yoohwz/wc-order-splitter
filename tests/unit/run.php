<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('__')) {
	function __($text, $domain = null) {
		return $text;
	}
}
if (!function_exists('sanitize_key')) {
	function sanitize_key($key) {
		return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
	}
}
if (!function_exists('absint')) {
	function absint($value) {
		return abs((int) $value);
	}
}

$root = dirname(__DIR__, 2) . '/inc/domain/';
require_once $root . 'class-wcos-decimal.php';
require_once $root . 'class-wcos-amount-allocator.php';
require_once $root . 'class-wcos-line-identity.php';
require_once $root . 'class-wcos-mutation-fingerprint.php';
require_once $root . 'class-wcos-mutation-contract.php';
require_once $root . 'class-wcos-split-plan.php';

$tests = array();

$tests['decimal rounds half up without binary floats'] = static function() {
	assert_same(1001, WCOS_Decimal::to_units('10.005', 2));
	assert_same(-1001, WCOS_Decimal::to_units('-10.005', 2));
	assert_same('10.01', WCOS_Decimal::normalize('10.005', 2));
};

$tests['decimal rejects exponent notation'] = static function() {
	assert_throws(
		static function() {
			WCOS_Decimal::to_units('1e3', 2);
		},
		InvalidArgumentException::class
	);
};

$tests['allocator preserves cents deterministically'] = static function() {
	$result = WCOS_Amount_Allocator::allocate('10.00', array('a' => '1', 'b' => '1', 'c' => '1'), 2);
	assert_same(array('a' => '3.34', 'b' => '3.33', 'c' => '3.33'), $result);
};

$tests['allocator residual tie-break ignores associative input order'] = static function() {
	$first = WCOS_Amount_Allocator::allocate('0.01', array('b' => '1', 'a' => '1'), 2);
	$second = WCOS_Amount_Allocator::allocate('0.01', array('a' => '1', 'b' => '1'), 2);
	assert_same('0.01', $first['a']);
	assert_same('0.00', $first['b']);
	assert_same($first['a'], $second['a']);
	assert_same($first['b'], $second['b']);
};

$tests['allocator preserves negative amounts'] = static function() {
	$result = WCOS_Amount_Allocator::allocate('-1.00', array('a' => '1', 'b' => '3'), 2);
	assert_same(array('a' => '-0.25', 'b' => '-0.75'), $result);
};

$tests['allocator supports fractional stock weights'] = static function() {
	$result = WCOS_Amount_Allocator::allocate('1.000000', array('original' => '0.333333', 'child' => '0.666667'), 6);
	assert_same('1.000000', decimal_sum($result, 6));
	assert_same(array('original' => '0.333333', 'child' => '0.666667'), $result);
};

$tests['allocator handles large exact currency values'] = static function() {
	$result = WCOS_Amount_Allocator::allocate('123456789.01', array('a' => '2', 'b' => '3'), 2);
	assert_same('123456789.01', decimal_sum($result, 2));
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

$tests['mutation fingerprint canonicalizes maps'] = static function() {
	$first = WCOS_Mutation_Fingerprint::create('split', 10, array('b' => 2, 'a' => array('y' => 2, 'x' => 1)));
	$second = WCOS_Mutation_Fingerprint::create('split', 10, array('a' => array('x' => 1, 'y' => 2), 'b' => 2));
	assert_same($first, $second);
};

$tests['mutation fingerprint preserves list order'] = static function() {
	$first = WCOS_Mutation_Fingerprint::create('split', 10, array('items' => array(1, 2)));
	$second = WCOS_Mutation_Fingerprint::create('split', 10, array('items' => array(2, 1)));
	assert_true($first !== $second, 'List order must remain significant.');
};

$tests['split request canonicalization is deterministic'] = static function() {
	$plan = WCOS_Split_Plan::canonicalize_request(array(
		'Child B' => array(22 => '1.5'),
		'child-a' => array(11 => 1),
	));
	assert_same(
		array(
			'child-a' => array(11 => '1.000000'),
			'childb' => array(22 => '1.500000'),
		),
		$plan
	);
};

$tests['split request rejects normalized key collisions'] = static function() {
	assert_throws(
		static function() {
			WCOS_Split_Plan::canonicalize_request(array(
				'Child A' => array(1 => 1),
				'childa' => array(2 => 1),
			));
		},
		InvalidArgumentException::class
	);
};

$tests['mutation contract accepts conserved snapshot'] = static function() {
	$before = array(
		'line_subtotal' => '100.00',
		'line_total' => '90.00',
		'discount_total' => '10.00',
		'discount_tax' => '1.00',
		'fees_total' => '2.00',
		'shipping_total' => '5.00',
		'tax_total' => '9.70',
		'grand_total' => '106.70',
		'stock_reduced' => '3.000000',
		'line_quantities' => array('line-a' => '2.000000', 'line-b' => '1.000000'),
		'tax_by_rate' => array(
			'101' => array('cart' => '4.20', 'shipping' => '0.50'),
			'202' => array('cart' => '5.00', 'shipping' => '0.00'),
		),
		'currencies' => array('USD'),
	);
	WCOS_Mutation_Contract::assert_conserved($before, $before, 2);
};

$tests['mutation contract rejects monetary drift'] = static function() {
	assert_throws(
		static function() {
			WCOS_Mutation_Contract::assert_conserved(
				array('grand_total' => '10.00'),
				array('grand_total' => '9.99'),
				2
			);
		},
		RuntimeException::class
	);
};

$tests['mutation contract rejects per-rate tax drift hidden by equal aggregate tax'] = static function() {
	assert_throws(
		static function() {
			WCOS_Mutation_Contract::assert_conserved(
				array(
					'tax_total' => '10.00',
					'tax_by_rate' => array(
						'101' => array('cart' => '4.00', 'shipping' => '0.00'),
						'202' => array('cart' => '6.00', 'shipping' => '0.00'),
					),
				),
				array(
					'tax_total' => '10.00',
					'tax_by_rate' => array(
						'101' => array('cart' => '5.00', 'shipping' => '0.00'),
						'202' => array('cart' => '5.00', 'shipping' => '0.00'),
					),
				),
				2
			);
		},
		RuntimeException::class
	);
};

$tests['mutation contract rejects currency drift'] = static function() {
	assert_throws(
		static function() {
			WCOS_Mutation_Contract::assert_conserved(
				array('currencies' => array('USD')),
				array('currencies' => array('EUR')),
				2
			);
		},
		RuntimeException::class
	);
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

function assert_throws(callable $callback, $expected_class) {
	try {
		$callback();
	} catch (Throwable $throwable) {
		if ($throwable instanceof $expected_class) {
			return;
		}
		throw new RuntimeException('Expected ' . $expected_class . ', got ' . get_class($throwable));
	}
	throw new RuntimeException('Expected exception ' . $expected_class . ' was not thrown.');
}

function decimal_sum(array $values, $precision) {
	$units = 0;
	foreach ($values as $value) {
		$units += WCOS_Decimal::to_units($value, (int) $precision);
	}
	return WCOS_Decimal::from_units($units, (int) $precision);
}
