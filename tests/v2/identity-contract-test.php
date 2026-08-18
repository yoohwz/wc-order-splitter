<?php
/**
 * Pure-PHP identity contract tests for mutation v2.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-metadata-policy.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-line-identity.php';

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure context.
 * @return void
 */
function wcos_v2_identity_assert_same($expected, $actual, $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message);
	}
}

$red = WCOS_V2_Line_Identity::build(
	50,
	501,
	'reduced-rate',
	array(
		array('key' => 'attribute_pa_color', 'value' => 'red'),
		array('key' => '_reduced_stock', 'value' => 2),
		array('key' => '_addon_configuration', 'value' => array('engraving' => 'A')),
	)
);

$red_reordered = WCOS_V2_Line_Identity::build(
	50,
	501,
	'reduced-rate',
	array(
		array('key' => '_addon_configuration', 'value' => array('engraving' => 'A')),
		array('key' => '_reduced_stock', 'value' => 999),
		array('key' => 'attribute_pa_color', 'value' => 'red'),
	)
);

$blue = WCOS_V2_Line_Identity::build(
	50,
	502,
	'reduced-rate',
	array(
		array('key' => 'attribute_pa_color', 'value' => 'blue'),
		array('key' => '_addon_configuration', 'value' => array('engraving' => 'A')),
	)
);

$different_addon = WCOS_V2_Line_Identity::build(
	50,
	501,
	'reduced-rate',
	array(
		array('key' => 'attribute_pa_color', 'value' => 'red'),
		array('key' => '_addon_configuration', 'value' => array('engraving' => 'B')),
	)
);

$different_tax_class = WCOS_V2_Line_Identity::build(
	50,
	501,
	'',
	array(
		array('key' => 'attribute_pa_color', 'value' => 'red'),
		array('key' => '_addon_configuration', 'value' => array('engraving' => 'A')),
	)
);

wcos_v2_identity_assert_same(
	$red['signature'],
	$red_reordered['signature'],
	'Technical stock metadata and metadata record order must not change commercial identity.'
);

wcos_v2_identity_assert_same(
	false,
	hash_equals($red['signature'], $blue['signature']),
	'Different variations must never share an identity.'
);

wcos_v2_identity_assert_same(
	false,
	hash_equals($red['signature'], $different_addon['signature']),
	'Different configured-product metadata must never share an identity.'
);

wcos_v2_identity_assert_same(
	false,
	hash_equals($red['signature'], $different_tax_class['signature']),
	'Different historical tax classes must never share an identity.'
);

$duplicate_metadata = WCOS_V2_Metadata_Policy::normalize_records(
	array(
		array('key' => 'choice', 'value' => 'one'),
		array('key' => 'choice', 'value' => 'two'),
	),
	true
);

wcos_v2_identity_assert_same(2, count($duplicate_metadata), 'Duplicate metadata keys must not be collapsed.');
wcos_v2_identity_assert_same(false, WCOS_V2_Metadata_Policy::should_copy('_reduced_stock'), 'Stock lifecycle metadata must not be blindly copied.');
wcos_v2_identity_assert_same(true, WCOS_V2_Metadata_Policy::should_copy('_addon_configuration'), 'Protected business metadata must remain copyable.');

echo "WCOS v2 identity contract tests passed.\n";
