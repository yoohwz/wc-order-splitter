<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$product_a = wcos_p2_adapter_product('WCOS whole-line plan A', '10.00');
$product_b = wcos_p2_adapter_product('WCOS whole-line plan B', '5.00');
$order = wc_create_order();
$order->set_status('pending');
$order->set_currency('USD');
$item_a = $order->add_product($product_a, 2);
$item_b = $order->add_product($product_b, 1);
$order->calculate_totals(false);
$order->save();
$order = wc_get_order($order->get_id());

wcos_p2_adapter_assert(
	WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY === WCOS_Split_Execution_Policy::normalize(''),
	'Whole-line policy default no longer preserves manual quantity Split semantics.'
);
wcos_p2_adapter_assert(
	WCOS_Split_Execution_Policy::allows_whole_line_transfer(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER),
	'Whole-line execution policy is not recognized.'
);

$manual_rejected = false;
try {
	WCOS_Split_Plan::normalize(
		$order,
		array('child-1' => array($item_a => '2.000000'))
	);
} catch (InvalidArgumentException $exception) {
	$manual_rejected = false !== strpos($exception->getMessage(), 'positive quantity');
}
wcos_p2_adapter_assert($manual_rejected, 'Default/manual Split policy accepted residual=0.');

$whole_plan = WCOS_Split_Plan::normalize(
	$order,
	array('child-1' => array($item_a => '2.000000')),
	WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
);
wcos_p2_adapter_assert('2.000000' === $whole_plan['child-1'][$item_a], 'Whole-line plan did not preserve the full source quantity.');
wcos_p2_adapter_assert(
	array($item_a) === WCOS_Split_Plan::fully_moved_item_ids(
		$order,
		$whole_plan,
		WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
	),
	'Whole-line plan did not identify its destructive source-item set deterministically.'
);

$all_lines_rejected = false;
try {
	WCOS_Split_Plan::normalize(
		$order,
		array(
			'child-1' => array(
				$item_a => '2.000000',
				$item_b => '1.000000',
			),
		),
		WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
	);
} catch (InvalidArgumentException $exception) {
	$all_lines_rejected = false !== strpos($exception->getMessage(), 'at least one product line');
}
wcos_p2_adapter_assert($all_lines_rejected, 'Whole-line policy allowed every product line to leave the source.');

$overallocated_rejected = false;
try {
	WCOS_Split_Plan::normalize(
		$order,
		array('child-1' => array($item_a => '2.000001')),
		WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
	);
} catch (InvalidArgumentException $exception) {
	$overallocated_rejected = false !== strpos($exception->getMessage(), 'more than the source line quantity');
}
wcos_p2_adapter_assert($overallocated_rejected, 'Whole-line policy allowed allocation beyond source quantity.');

$order->delete(true);
wp_delete_post($product_a->get_id(), true);
wp_delete_post($product_b->get_id(), true);

echo "p2-whole-line-plan-ok\n";

require __DIR__ . '/p2-whole-line-runtime-smoke.php';
require __DIR__ . '/p2-whole-line-stock-ownership-smoke.php';
