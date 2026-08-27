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
require_once $root . 'class-wcos-price-precision-scope.php';
require_once $root . 'class-wcos-mutation-contract.php';
require_once $root . 'class-wcos-split-execution-policy.php';
require_once $root . 'class-wcos-split-plan.php';
require_once $root . 'class-wcos-manual-split-quantity-authority.php';
require_once $root . 'class-wcos-merge-retirement-policy.php';
require_once $root . 'class-wcos-merge-plan.php';
require_once $root . 'class-wcos-merge-recovery-state-graph.php';
require_once $root . 'class-wcos-return-participation.php';
require_once $root . 'class-wcos-return-plan.php';
require_once $root . 'class-wcos-return-retirement-policy.php';
require_once $root . 'class-wcos-return-recovery-state-graph.php';

$tests = array();

function manual_quantity_authority_fixture($policy_version = WCOS_Manual_Split_Quantity_Authority::POLICY_VERSION) {
	$legacy = WCOS_Manual_Split_Quantity_Authority::LEGACY_POLICY_VERSION === $policy_version;
	$authority = array(
		'schema_version' => WCOS_Manual_Split_Quantity_Authority::SCHEMA_VERSION,
		'policy_version' => $policy_version,
		'precision' => 6,
		'source_order_id' => 42,
		'source_signature' => str_repeat('a', 64),
		'lines' => array(
			11 => array(
				'source_order_id' => 42,
				'source_item_id' => 11,
				'product_id' => 101,
				'variation_id' => 0,
				'source_quantity' => '4.000000',
				'source_quantity_units' => 4000000,
				'quantity_step' => '1.000000',
				'step_units' => 1000000,
				'maximum_quantity' => $legacy ? '3.000000' : '4.000000',
				'maximum_quantity_units' => $legacy ? 3000000 : 4000000,
				'can_partially_split' => true,
			),
			22 => array(
				'source_order_id' => 42,
				'source_item_id' => 22,
				'product_id' => 202,
				'variation_id' => 203,
				'source_quantity' => '3.500000',
				'source_quantity_units' => 3500000,
				'quantity_step' => '0.250000',
				'step_units' => 250000,
				'maximum_quantity' => $legacy ? '3.250000' : '3.500000',
				'maximum_quantity_units' => $legacy ? 3250000 : 3500000,
				'can_partially_split' => true,
			),
		),
	);
	$authority['authority_fingerprint'] = WCOS_Mutation_Fingerprint::create(
		'manual_split_quantity_authority_v1',
		42,
		$authority
	);
	return $authority;
}

$tests['decimal rounds half up without binary floats'] = static function() {
	assert_same(1001, WCOS_Decimal::to_units('10.005', 2));
	assert_same(-1001, WCOS_Decimal::to_units('-10.005', 2));
	assert_same('10.01', WCOS_Decimal::normalize('10.005', 2));
};

$tests['decimal rejects exponent notation'] = static function() {
	assert_throws(static function() {
		WCOS_Decimal::to_units('1e3', 2);
	}, InvalidArgumentException::class);
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
	assert_same(array(
		'child-a' => array(11 => '1.000000'),
		'childb' => array(22 => '1.500000'),
	), $plan);
};

$tests['split request rejects normalized child key collisions'] = static function() {
	assert_throws(static function() {
		WCOS_Split_Plan::canonicalize_request(array(
			'Child A' => array(1 => 1),
			'childa' => array(2 => 1),
		));
	}, InvalidArgumentException::class);
};

$tests['split request rejects normalized source item ID collisions'] = static function() {
	assert_throws(static function() {
		WCOS_Split_Plan::canonicalize_request(array(
			'child-a' => array('01' => '0.25', 1 => '0.50'),
		));
	}, InvalidArgumentException::class);
};

$tests['manual split authority accepts exact mixed-line step multiples'] = static function() {
	$authority = manual_quantity_authority_fixture();
	$plan = WCOS_Manual_Split_Quantity_Authority::assert_plan(array(
		'child-2' => array(22 => '1.75'),
		'child-1' => array(11 => '1', 22 => '0.50'),
	), $authority);
	assert_same('1.000000', $plan['child-1'][11]);
	assert_same('0.500000', $plan['child-1'][22]);
	assert_same('1.750000', $plan['child-2'][22]);
};

$tests['manual split authority rejects sub-step and empty-order aggregate violations'] = static function() {
	$authority = manual_quantity_authority_fixture();
	foreach (array('0.000001', '0.1', '0.30') as $quantity) {
		assert_throws(static function() use ($authority, $quantity) {
			WCOS_Manual_Split_Quantity_Authority::assert_plan(array('child-1' => array(22 => $quantity)), $authority);
		}, WCOS_Manual_Split_Quantity_Authority_Exception::class);
	}
	assert_throws(static function() use ($authority) {
		WCOS_Manual_Split_Quantity_Authority::assert_plan(array(
			'child-1' => array(11 => '4.000000', 22 => '1.750000'),
			'child-2' => array(22 => '1.750000'),
		), $authority);
	}, WCOS_Manual_Split_Quantity_Authority_Exception::class);
};

$tests['manual split authority versions preserve replay and derive execution policy'] = static function() {
	$current = manual_quantity_authority_fixture();
	$legacy = manual_quantity_authority_fixture(WCOS_Manual_Split_Quantity_Authority::LEGACY_POLICY_VERSION);
	assert_same(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER, WCOS_Manual_Split_Quantity_Authority::execution_policy($current));
	assert_same(WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY, WCOS_Manual_Split_Quantity_Authority::execution_policy($legacy));
	assert_same(true, WCOS_Manual_Split_Quantity_Authority::is_order_splittable($current));
	WCOS_Manual_Split_Quantity_Authority::assert_plan(array('child-1' => array(11 => '4.000000')), $current);
	assert_throws(static function() use ($legacy) {
		WCOS_Manual_Split_Quantity_Authority::assert_plan(array('child-1' => array(11 => '4.000000')), $legacy);
	}, WCOS_Manual_Split_Quantity_Authority_Exception::class);
};

$tests['manual split authority fingerprint rejects browser or journal tampering'] = static function() {
	$authority = manual_quantity_authority_fixture();
	$authority['lines'][22]['step_units'] = 100000;
	assert_throws(static function() use ($authority) {
		WCOS_Manual_Split_Quantity_Authority::assert_valid($authority);
	}, WCOS_Manual_Split_Quantity_Authority_Exception::class);
};

$tests['mutation contract accepts conserved snapshot'] = static function() {
	$before = array(
		'line_subtotal' => '100.00',
		'line_total' => '90.00',
		'line_subtotal_tax' => '10.20',
		'line_total_tax' => '9.20',
		'discount_total' => '10.00',
		'discount_tax' => '1.00',
		'fees_total' => '2.00',
		'shipping_total' => '5.00',
		'tax_total' => '9.70',
		'grand_total' => '106.70',
		'stock_reduced' => '3.000000',
		'line_quantities' => array('line-a' => '2.000000', 'line-b' => '1.000000'),
		'line_tax_by_rate' => array(
			'101' => array('subtotal' => '4.70', 'total' => '4.20'),
			'202' => array('subtotal' => '5.50', 'total' => '5.00'),
		),
		'tax_by_rate' => array(
			'101' => array('cart' => '4.20', 'shipping' => '0.50'),
			'202' => array('cart' => '5.00', 'shipping' => '0.00'),
		),
		'currencies' => array('USD'),
	);
	WCOS_Mutation_Contract::assert_conserved($before, $before, 2);
};

$tests['mutation contract rejects monetary drift'] = static function() {
	assert_throws(static function() {
		WCOS_Mutation_Contract::assert_conserved(array('grand_total' => '10.00'), array('grand_total' => '9.99'), 2);
	}, RuntimeException::class);
};

$tests['mutation contract rejects line subtotal tax drift hidden by equal final tax'] = static function() {
	assert_throws(static function() {
		WCOS_Mutation_Contract::assert_conserved(
			array(
				'line_subtotal_tax' => '10.00',
				'line_total_tax' => '8.00',
				'line_tax_by_rate' => array(
					'101' => array('subtotal' => '4.00', 'total' => '3.00'),
					'202' => array('subtotal' => '6.00', 'total' => '5.00'),
				),
			),
			array(
				'line_subtotal_tax' => '10.00',
				'line_total_tax' => '8.00',
				'line_tax_by_rate' => array(
					'101' => array('subtotal' => '5.00', 'total' => '3.00'),
					'202' => array('subtotal' => '5.00', 'total' => '5.00'),
				),
			),
			2
		);
	}, RuntimeException::class);
};

$tests['mutation contract rejects per-rate tax-row drift hidden by equal aggregate tax'] = static function() {
	assert_throws(static function() {
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
	}, RuntimeException::class);
};

$tests['mutation contract rejects currency drift'] = static function() {
	assert_throws(static function() {
		WCOS_Mutation_Contract::assert_conserved(array('currencies' => array('USD')), array('currencies' => array('EUR')), 2);
	}, RuntimeException::class);
};

$tests['duplicate service and preflight policy versions stay aligned'] = static function() use ($root) {
	$service = file_get_contents($root . 'class-wcos-duplicate-order-service.php');
	$preflight = file_get_contents($root . 'class-wcos-duplicate-preflight.php');
	assert_true(is_string($service) && is_string($preflight), 'Unable to read Duplicate policy sources.');
	$service_match = array();
	$preflight_match = array();
	assert_true(1 === preg_match('/const POLICY_VERSION = ([0-9]+);/', $service, $service_match), 'Duplicate service policy version is missing.');
	assert_true(1 === preg_match('/const POLICY_VERSION = ([0-9]+);/', $preflight, $preflight_match), 'Duplicate preflight policy version is missing.');
	assert_same($service_match[1], $preflight_match[1]);
};

$tests['merge plan fingerprint is canonical and keeps identical lines distinct'] = static function() {
	$identity = WCOS_Line_Identity::from_values(10, 20, 'reduced-rate', array('engraving' => 'A'));
	$first = WCOS_Merge_Plan::canonicalize(101, 202, array(
		22 => array('source_item_id' => 22, 'line_identity' => $identity, 'quantity' => '1.000000', 'taxes' => array('total' => array(2 => '1.00'))),
		11 => array('taxes' => array('total' => array(2 => '1.00')), 'quantity' => '1.000000', 'line_identity' => $identity, 'source_item_id' => 11),
	));
	$second = WCOS_Merge_Plan::canonicalize(101, 202, array(
		11 => array('source_item_id' => 11, 'line_identity' => $identity, 'quantity' => '1.000000', 'taxes' => array('total' => array(2 => '1.00'))),
		22 => array('taxes' => array('total' => array(2 => '1.00')), 'quantity' => '1.000000', 'line_identity' => $identity, 'source_item_id' => 22),
	));
	assert_same(WCOS_Merge_Plan::fingerprint($first), WCOS_Merge_Plan::fingerprint($second));
	assert_same(array(11, 22), array_keys($first['lines']));
	assert_same(false, $first['coalesce_lines']);
	assert_same('fresh_target_line_per_source_line', $first['line_policy']);
};

$tests['merge plan rejects self merge and normalized item collisions'] = static function() {
	assert_throws(static function() {
		WCOS_Merge_Plan::canonicalize(10, 10, array(1 => array('source_item_id' => 1, 'line_identity' => str_repeat('a', 64))));
	}, InvalidArgumentException::class);
	assert_throws(static function() {
		WCOS_Merge_Plan::canonicalize(10, 11, array(
			'01' => array('source_item_id' => 1, 'line_identity' => str_repeat('a', 64)),
			1 => array('source_item_id' => 1, 'line_identity' => str_repeat('b', 64)),
		));
	}, InvalidArgumentException::class);
};

$tests['return plan canonicalization ignores incidental line ordering'] = static function() {
	$line_a = array(
		'source_item_id' => 11,
		'child_item_id' => 101,
		'product_id' => 1001,
		'variation_id' => 0,
		'tax_class' => '',
		'destination' => WCOS_Return_Plan::DESTINATION_RESIDUAL_SOURCE_ITEM,
		'destination_source_item_id' => 11,
		'line_identity_authority' => str_repeat('a', 64),
		'quantity' => '1.000000',
		'subtotal' => '5.00',
		'total' => '4.50',
		'subtotal_tax' => '0.50',
		'total_tax' => '0.45',
		'taxes' => array('total' => array(2 => '0.45'), 'subtotal' => array(2 => '0.50')),
		'reduced_stock' => '1.000000',
	);
	$line_b = array(
		'source_item_id' => 22,
		'child_item_id' => 202,
		'product_id' => 1002,
		'variation_id' => 2002,
		'tax_class' => 'reduced-rate',
		'destination' => WCOS_Return_Plan::DESTINATION_FRESH_SOURCE_ITEM,
		'destination_source_item_id' => 0,
		'line_identity_authority' => str_repeat('b', 64),
		'quantity' => '2.000000',
		'subtotal' => '8.00',
		'total' => '8.00',
		'subtotal_tax' => '0.00',
		'total_tax' => '0.00',
		'taxes' => array('subtotal' => array(), 'total' => array()),
		'reduced_stock' => null,
	);
	$base = array(
		'authority_fingerprint' => str_repeat('c', 64),
		'child_order_id' => 302,
		'source_order_id' => 301,
		'split_operation_id' => 'split-operation',
		'split_child_key' => 'child-a',
		'price_precision' => 2,
		'currency' => 'USD',
		'prices_include_tax' => false,
		'execution_policy' => 'allow_whole_line_transfer',
		'strategy' => 'category',
		'source_commercial_authority' => str_repeat('d', 64),
		'source_relation_authority' => str_repeat('f', 64),
		'child_commercial_authority' => str_repeat('e', 64),
	);
	$first = $base;
	$first['lines'] = array(22 => $line_b, 11 => $line_a);
	$second = $base;
	$second['lines'] = array(11 => $line_a, 22 => $line_b);
	$first_plan = WCOS_Return_Plan::build($first);
	$second_plan = WCOS_Return_Plan::build($second);
	assert_same(array(11, 22), array_keys($first_plan['lines']));
	assert_same($first_plan['plan_fingerprint'], $second_plan['plan_fingerprint']);
};

$tests['return plan binds destination and historical ownership evidence'] = static function() {
	$authority = array(
		'authority_fingerprint' => str_repeat('a', 64),
		'child_order_id' => 42,
		'source_order_id' => 41,
		'split_operation_id' => 'split-operation',
		'split_child_key' => 'child-a',
		'price_precision' => 2,
		'currency' => 'USD',
		'prices_include_tax' => false,
		'execution_policy' => 'partial_lines_only',
		'strategy' => 'manual_quantity',
		'source_commercial_authority' => str_repeat('b', 64),
		'source_relation_authority' => str_repeat('e', 64),
		'child_commercial_authority' => str_repeat('c', 64),
		'lines' => array(9 => array(
			'source_item_id' => 9,
			'child_item_id' => 90,
			'product_id' => 900,
			'variation_id' => 0,
			'tax_class' => '',
			'destination' => WCOS_Return_Plan::DESTINATION_RESIDUAL_SOURCE_ITEM,
			'destination_source_item_id' => 9,
			'line_identity_authority' => str_repeat('d', 64),
			'quantity' => '1.000000',
			'subtotal' => '10.00',
			'total' => '9.00',
			'subtotal_tax' => '1.00',
			'total_tax' => '0.90',
			'taxes' => array('subtotal' => array(1 => '1.00'), 'total' => array(1 => '0.90')),
			'reduced_stock' => '1.000000',
			'customer_name' => 'must-not-enter-return-plan',
		)),
	);
	$first = WCOS_Return_Plan::build($authority);
	assert_true(false === strpos(json_encode($first), 'must-not-enter-return-plan'), 'Return plan copied an undeclared line field.');
	$authority['lines'][9]['total'] = '8.99';
	$second = WCOS_Return_Plan::build($authority);
	assert_true($first['plan_fingerprint'] !== $second['plan_fingerprint'], 'Return plan fingerprint ignored historical money drift.');
	$authority['lines'][9]['destination'] = WCOS_Return_Plan::DESTINATION_FRESH_SOURCE_ITEM;
	assert_throws(static function() use ($authority) {
		WCOS_Return_Plan::build($authority);
	}, InvalidArgumentException::class);
};

$tests['merge retirement policy binds non-force trash archive'] = static function() {
	$candidates = WCOS_Merge_Retirement_Policy::candidates();
	assert_same(array('dedicated_merged_archive', 'non_force_trash_archive'), WCOS_Merge_Retirement_Policy::identifiers());
	assert_same('non_force_trash_archive', WCOS_Merge_Retirement_Policy::approved_identifier());
	foreach ($candidates as $candidate) {
		assert_same(true, $candidate['preserve_commercial_record']);
		assert_same(false, $candidate['active_economic_owner_after']);
		assert_same(false, $candidate['normal_active_status_after']);
		assert_same(false, $candidate['hard_delete']);
	}
	assert_same(true, $candidates['non_force_trash_archive']['production_selected']);
	assert_same(false, $candidates['dedicated_merged_archive']['production_selected']);
	WCOS_Merge_Retirement_Policy::assert_approved('non_force_trash_archive');
	assert_throws(static function() {
		WCOS_Merge_Retirement_Policy::assert_approved('dedicated_merged_archive');
	}, RuntimeException::class);
	WCOS_Merge_Retirement_Policy::assert_archive_preserved(str_repeat('a', 64), str_repeat('a', 64));
	WCOS_Merge_Retirement_Policy::assert_active_ownership_conserved(str_repeat('b', 64), str_repeat('b', 64));
};

$tests['merge recovery graph accepts forward and source-first compensation paths'] = static function() {
	assert_true(WCOS_Merge_Recovery_State_Graph::transition_allowed('no_commercial_write', 'target_staging'), 'Initial target staging transition was rejected.');
	assert_true(WCOS_Merge_Recovery_State_Graph::transition_allowed('target_staging', 'target_staging'), 'Resumable target staging checkpoint was rejected.');
	assert_true(WCOS_Merge_Recovery_State_Graph::transition_allowed('target_staging', 'target_persisted'), 'Target staging completion was rejected.');
	assert_true(WCOS_Merge_Recovery_State_Graph::transition_allowed('no_commercial_write', 'target_persisted'), 'Initial target transition was rejected.');
	assert_true(WCOS_Merge_Recovery_State_Graph::transition_allowed('source_retired', 'source_relation_persisted'), 'Reciprocal relation transition was rejected.');
	assert_true(WCOS_Merge_Recovery_State_Graph::transition_allowed('commercial_verified', 'committed'), 'Verified commit transition was rejected.');
	assert_true(WCOS_Merge_Recovery_State_Graph::transition_allowed('compensating', 'source_restored'), 'Source-first compensation was rejected.');
	assert_true(WCOS_Merge_Recovery_State_Graph::transition_allowed('source_restored', 'target_restored'), 'Target cleanup after source restore was rejected.');
	assert_same(false, WCOS_Merge_Recovery_State_Graph::transition_allowed('target_persisted', 'completed'));
	assert_same(false, WCOS_Merge_Recovery_State_Graph::transition_allowed('compensating', 'target_restored'));
};

$tests['merge recovery checkpoints are self-verifying'] = static function() {
	$record = array(
		'source_order_id' => 40,
		'operation_id' => 'merge-recovery-unit',
		'fingerprint' => str_repeat('a', 64),
		'checkpoints' => array(),
	);
	$context = WCOS_Merge_Recovery_State_Graph::seal_context($record, array(
		'merge_recovery_state' => WCOS_Merge_Recovery_State_Graph::NO_WRITE,
		'merge_source_signature_after' => str_repeat('b', 64),
	));
	$record['checkpoints'][] = array('context' => $context);
	assert_same(WCOS_Merge_Recovery_State_Graph::NO_WRITE, WCOS_Merge_Recovery_State_Graph::assert_record($record));
	$record['checkpoints'][0]['context']['merge_source_signature_after'] = str_repeat('c', 64);
	assert_throws(static function() use ($record) {
		WCOS_Merge_Recovery_State_Graph::assert_record($record);
	}, RuntimeException::class);
};

$tests['return retirement policy binds non-force trash and stock ownership transfer'] = static function() {
	$candidates = WCOS_Return_Retirement_Policy::candidates();
	$stock_policy = WCOS_Return_Retirement_Policy::stock_policy();
	assert_same('non_force_trash_archive', WCOS_Return_Retirement_Policy::approved_identifier());
	assert_same('child_neutralize_then_original_activate', $stock_policy['ownership_transfer_order']);
	assert_same('child_false_original_true_when_owned', $stock_policy['order_stock_flag_policy']);
	assert_same(true, $candidates['non_force_trash_archive']['preserve_commercial_record']);
	assert_same(false, $candidates['non_force_trash_archive']['hard_delete']);
	WCOS_Return_Retirement_Policy::assert_approved('non_force_trash_archive');
	assert_throws(static function() {
		WCOS_Return_Retirement_Policy::assert_approved('hard_delete');
	}, RuntimeException::class);
};

$tests['return recovery graph accepts forward and original-first compensation paths'] = static function() {
	assert_true(WCOS_Return_Recovery_State_Graph::transition_allowed('prepared_no_write', 'original_commercial_staging'), 'Initial original staging transition was rejected.');
	assert_true(WCOS_Return_Recovery_State_Graph::transition_allowed('original_commercial_staging', 'original_commercial_staging'), 'Resumable original staging checkpoint was rejected.');
	assert_true(WCOS_Return_Recovery_State_Graph::transition_allowed('original_commercial_persisted', 'child_stock_ownership_neutralizing'), 'Child ownership neutralization transition was rejected.');
	assert_true(WCOS_Return_Recovery_State_Graph::transition_allowed('child_stock_ownership_neutralized', 'original_stock_ownership_activated'), 'Original ownership activation transition was rejected.');
	assert_true(WCOS_Return_Recovery_State_Graph::transition_allowed('return_relations_complete', 'pair_verified'), 'Return verification transition was rejected.');
	assert_true(WCOS_Return_Recovery_State_Graph::transition_allowed('child_retired', 'child_return_relation_partial'), 'Partial child relation checkpoint was rejected.');
	assert_true(WCOS_Return_Recovery_State_Graph::transition_allowed('child_return_relation_partial', 'child_return_relation_persisted'), 'Partial reciprocal relation completion was rejected.');
	assert_true(WCOS_Return_Recovery_State_Graph::transition_allowed('compensating', 'original_restored'), 'Original-first compensation was rejected.');
	assert_true(WCOS_Return_Recovery_State_Graph::transition_allowed('original_restored', 'child_restored'), 'Child restore after original was rejected.');
	assert_same(false, WCOS_Return_Recovery_State_Graph::transition_allowed('compensating', 'child_restored'));
	assert_same(false, WCOS_Return_Recovery_State_Graph::transition_allowed('child_retired', 'completed'));
};

$tests['return recovery checkpoints are self-verifying'] = static function() {
	$record = array(
		'source_order_id' => 42,
		'operation_id' => 'return-recovery-unit',
		'fingerprint' => str_repeat('a', 64),
		'checkpoints' => array(),
	);
	$context = WCOS_Return_Recovery_State_Graph::seal_context($record, array(
		'return_recovery_state' => WCOS_Return_Recovery_State_Graph::PREPARED,
		'return_recovery_snapshot_fingerprint' => str_repeat('b', 64),
	));
	$record['checkpoints'][] = array('context' => $context);
	assert_same(WCOS_Return_Recovery_State_Graph::PREPARED, WCOS_Return_Recovery_State_Graph::assert_record($record));
	$record['checkpoints'][0]['context']['return_recovery_snapshot_fingerprint'] = str_repeat('c', 64);
	assert_throws(static function() use ($record) {
		WCOS_Return_Recovery_State_Graph::assert_record($record);
	}, RuntimeException::class);
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
