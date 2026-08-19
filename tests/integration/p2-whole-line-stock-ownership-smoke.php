<?php

if (!defined('ABSPATH')) {
	exit(1);
}

/*
 * Whole-line stock-owner acceptance.
 *
 * These cases extend the already-green partial Split lifecycle contract to the
 * destructive whole-line policy used by future server-built strategies. They
 * prove that moving a full variation line preserves WooCommerce's true stock
 * owner and transfers the complete reduced-stock lifecycle marker exactly once.
 */

$manage_stock_before_whole_line_owner = get_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_manage_stock', 'yes');

try {
	/* Variation owns its own stock. */
	list($wl_variation_parent, $wl_variation) = wcos_p2_stock_variable_pair(
		'WCOS whole-line variation-owned',
		false,
		true,
		0,
		18
	);
	$wl_variation_residual = wcos_p2_adapter_product('WCOS whole-line variation residual', '6.00', 30);
	list($wl_variation_source, $wl_variation_item_id, $wl_variation_residual_item_id) = wcos_whole_line_runtime_order(
		$wl_variation,
		4,
		$wl_variation_residual,
		1,
		'pending'
	);
	$wl_variation_source_id = $wl_variation_source->get_id();
	$wl_variation_source->update_status('processing');
	$wl_variation_source = wc_get_order($wl_variation_source_id);

	wcos_p2_adapter_assert(
		'14.000000' === wcos_p2_stock_lifecycle_quantity($wl_variation),
		'Whole-line variation fixture did not reduce variation-owned stock by four.'
	);
	wcos_p2_adapter_assert(
		'29.000000' === wcos_p2_stock_lifecycle_quantity($wl_variation_residual),
		'Whole-line variation fixture did not reduce residual managed stock by one.'
	);
	wcos_p2_stock_lifecycle_assert_marker(
		$wl_variation_source->get_item($wl_variation_item_id),
		'4',
		'Whole-line variation source lacks the full reduced-stock marker before transfer.'
	);

	$wl_variation_operation = 'p2-whole-line-variation-owner-' . wp_generate_uuid4();
	$wl_variation_children = wcos_whole_line_runtime_call(
		$wl_variation_source,
		array('child-variation' => array($wl_variation_item_id => '4.000000')),
		$wl_variation_operation
	);
	wcos_p2_adapter_assert(1 === count($wl_variation_children), 'Whole-line variation transfer did not create one child.');
	$wl_variation_child = wc_get_order($wl_variation_children[0]->get_id());
	$wl_variation_source = wc_get_order($wl_variation_source_id);
	$wl_variation_child_items = array_values($wl_variation_child->get_items('line_item'));
	wcos_p2_adapter_assert(1 === count($wl_variation_child_items), 'Whole-line variation child has the wrong line count.');
	$wl_variation_child_item = $wl_variation_child_items[0];

	wcos_p2_adapter_assert(!$wl_variation_source->get_item($wl_variation_item_id), 'Whole-line variation source retained the moved line.');
	wcos_p2_adapter_assert(
		$wl_variation_source->get_item($wl_variation_residual_item_id) instanceof WC_Order_Item_Product,
		'Whole-line variation transfer removed the residual source line.'
	);
	wcos_p2_adapter_assert(
		$wl_variation->get_id() === $wl_variation_child_item->get_variation_id(),
		'Whole-line variation transfer lost the variation ID.'
	);
	wcos_p2_adapter_assert(
		$wl_variation_parent->get_id() === $wl_variation_child_item->get_product_id(),
		'Whole-line variation transfer lost the variation parent product ID.'
	);
	wcos_p2_stock_lifecycle_assert_marker(
		$wl_variation_child_item,
		'4',
		'Whole-line variation child did not receive the full reduced-stock marker.'
	);
	wcos_p2_adapter_assert(
		'14.000000' === wcos_p2_stock_lifecycle_quantity($wl_variation),
		'Whole-line transfer changed variation-owned physical stock.'
	);
	wcos_p2_adapter_assert(
		'29.000000' === wcos_p2_stock_lifecycle_quantity($wl_variation_residual),
		'Whole-line transfer changed residual physical stock.'
	);

	$wl_variation_child->update_status('cancelled');
	wcos_p2_adapter_assert(
		'18.000000' === wcos_p2_stock_lifecycle_quantity($wl_variation),
		'Cancelling the whole-line variation child did not restore exactly four variation-owned units.'
	);
	wcos_p2_adapter_assert(
		'29.000000' === wcos_p2_stock_lifecycle_quantity($wl_variation_residual),
		'Cancelling the whole-line variation child changed residual stock.'
	);
	$wl_variation_source->update_status('cancelled');
	wcos_p2_adapter_assert(
		'30.000000' === wcos_p2_stock_lifecycle_quantity($wl_variation_residual),
		'Cancelling the whole-line variation residual source did not restore its one unit.'
	);

	wcos_p2_adapter_cleanup($wl_variation_source_id, $wl_variation_operation);
	wcos_p2_stock_delete_product($wl_variation_residual);
	wcos_p2_stock_delete_product($wl_variation);
	wcos_p2_stock_delete_product($wl_variation_parent);

	/* Variation stock is owned by its parent product. */
	list($wl_parent_product, $wl_parent_variation) = wcos_p2_stock_variable_pair(
		'WCOS whole-line parent-owned',
		true,
		false,
		25,
		0
	);
	wcos_p2_adapter_assert(
		$wl_parent_product->get_id() === $wl_parent_variation->get_stock_managed_by_id(),
		'Whole-line parent-managed fixture resolved the wrong stock owner.'
	);
	$wl_parent_residual = wcos_p2_adapter_product('WCOS whole-line parent residual', '5.00', 30);
	list($wl_parent_source, $wl_parent_item_id, $wl_parent_residual_item_id) = wcos_whole_line_runtime_order(
		$wl_parent_variation,
		4,
		$wl_parent_residual,
		1,
		'pending'
	);
	$wl_parent_source_id = $wl_parent_source->get_id();
	$wl_parent_source->update_status('processing');
	$wl_parent_source = wc_get_order($wl_parent_source_id);

	wcos_p2_adapter_assert(
		'21.000000' === wcos_p2_stock_lifecycle_quantity($wl_parent_product),
		'Whole-line parent-managed fixture did not reduce parent stock by four.'
	);
	wcos_p2_adapter_assert(
		'29.000000' === wcos_p2_stock_lifecycle_quantity($wl_parent_residual),
		'Whole-line parent-managed fixture did not reduce residual stock by one.'
	);
	wcos_p2_stock_lifecycle_assert_marker(
		$wl_parent_source->get_item($wl_parent_item_id),
		'4',
		'Whole-line parent-managed source lacks the full reduced-stock marker before transfer.'
	);

	$wl_parent_operation = 'p2-whole-line-parent-owner-' . wp_generate_uuid4();
	$wl_parent_children = wcos_whole_line_runtime_call(
		$wl_parent_source,
		array('child-parent-owner' => array($wl_parent_item_id => '4.000000')),
		$wl_parent_operation
	);
	wcos_p2_adapter_assert(1 === count($wl_parent_children), 'Whole-line parent-managed transfer did not create one child.');
	$wl_parent_child = wc_get_order($wl_parent_children[0]->get_id());
	$wl_parent_source = wc_get_order($wl_parent_source_id);
	$wl_parent_child_items = array_values($wl_parent_child->get_items('line_item'));
	wcos_p2_adapter_assert(1 === count($wl_parent_child_items), 'Whole-line parent-managed child has the wrong line count.');
	$wl_parent_child_item = $wl_parent_child_items[0];

	wcos_p2_adapter_assert(!$wl_parent_source->get_item($wl_parent_item_id), 'Whole-line parent-managed source retained the moved line.');
	wcos_p2_adapter_assert(
		$wl_parent_source->get_item($wl_parent_residual_item_id) instanceof WC_Order_Item_Product,
		'Whole-line parent-managed transfer removed the residual source line.'
	);
	wcos_p2_adapter_assert(
		$wl_parent_variation->get_id() === $wl_parent_child_item->get_variation_id(),
		'Whole-line parent-managed transfer lost the variation ID.'
	);
	wcos_p2_adapter_assert(
		$wl_parent_product->get_id() === $wl_parent_child_item->get_product_id(),
		'Whole-line parent-managed transfer lost the parent product ID.'
	);
	wcos_p2_stock_lifecycle_assert_marker(
		$wl_parent_child_item,
		'4',
		'Whole-line parent-managed child did not receive the full reduced-stock marker.'
	);
	wcos_p2_adapter_assert(
		'21.000000' === wcos_p2_stock_lifecycle_quantity($wl_parent_product),
		'Whole-line parent-managed transfer changed parent physical stock.'
	);
	wcos_p2_adapter_assert(
		'29.000000' === wcos_p2_stock_lifecycle_quantity($wl_parent_residual),
		'Whole-line parent-managed transfer changed residual physical stock.'
	);

	$wl_parent_child->update_status('cancelled');
	wcos_p2_adapter_assert(
		'25.000000' === wcos_p2_stock_lifecycle_quantity($wl_parent_product),
		'Cancelling the whole-line parent-managed child did not restore exactly four parent-owned units.'
	);
	wcos_p2_adapter_assert(
		'29.000000' === wcos_p2_stock_lifecycle_quantity($wl_parent_residual),
		'Cancelling the whole-line parent-managed child changed residual stock.'
	);
	$wl_parent_source->update_status('cancelled');
	wcos_p2_adapter_assert(
		'30.000000' === wcos_p2_stock_lifecycle_quantity($wl_parent_residual),
		'Cancelling the whole-line parent-managed residual source did not restore its one unit.'
	);

	wcos_p2_adapter_cleanup($wl_parent_source_id, $wl_parent_operation);
	wcos_p2_stock_delete_product($wl_parent_residual);
	wcos_p2_stock_delete_product($wl_parent_variation);
	wcos_p2_stock_delete_product($wl_parent_product);
} finally {
	update_option('woocommerce_manage_stock', $manage_stock_before_whole_line_owner);
}

echo "p2-whole-line-stock-ownership-ok\n";
