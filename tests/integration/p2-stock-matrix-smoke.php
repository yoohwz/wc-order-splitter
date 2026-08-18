<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_p2_stock_variable_pair($name, $parent_managed, $variation_managed, $parent_stock = 20, $variation_stock = 20) {
	$parent = new WC_Product_Variable();
	$parent->set_name($name . ' parent');
	if ($parent_managed) {
		$parent->set_manage_stock(true);
		$parent->set_stock_quantity($parent_stock);
		$parent->set_stock_status('instock');
	}
	$parent->save();

	$variation = new WC_Product_Variation();
	$variation->set_parent_id($parent->get_id());
	$variation->set_regular_price('10.00');
	$variation->set_manage_stock((bool) $variation_managed);
	if ($variation_managed) {
		$variation->set_stock_quantity($variation_stock);
		$variation->set_stock_status('instock');
	}
	$variation->save();
	WC_Product_Variable::sync($parent->get_id());

	return array(wc_get_product($parent->get_id()), wc_get_product($variation->get_id()));
}

function wcos_p2_stock_delete_product($product) {
	if ($product instanceof WC_Product && $product->get_id()) {
		wp_delete_post($product->get_id(), true);
	}
}

$adapter = new WCOS_Split_WooCommerce_Adapter();
$manage_stock_before = get_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_manage_stock', 'yes');

/* Managed stock lifecycle: an already-reduced processing order redistributes markers but not physical stock. */
$managed = wcos_p2_adapter_product('WCOS P2 managed lifecycle', '10.00', 30);
list($managed_source, $managed_item_id) = wcos_p2_adapter_order($managed, 4);
$managed_source->update_status('processing');
$managed_source = wc_get_order($managed_source->get_id());
$managed_item = $managed_source->get_item($managed_item_id);
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units('26', 6) === WCOS_Decimal::to_units(wc_get_product($managed->get_id())->get_stock_quantity(), 6),
	'WooCommerce did not reduce the managed-stock fixture before Split.'
);
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units('4', 6) === WCOS_Decimal::to_units($managed_item->get_meta('_reduced_stock', true), 6),
	'Managed-stock fixture does not have the expected reduced-stock marker.'
);
wcos_p2_adapter_assert((bool) $managed_source->get_data_store()->get_stock_reduced($managed_source->get_id()), 'Managed-stock fixture is not marked stock-reduced.');

$managed_operation = 'p2-managed-lifecycle-' . wp_generate_uuid4();
$managed_children = $adapter->split(
	$managed_source,
	array('child-one' => array($managed_item_id => '1.000000')),
	$managed_operation
);
$managed_source = wc_get_order($managed_source->get_id());
$managed_child = wc_get_order($managed_children[0]->get_id());
$managed_source_item = $managed_source->get_item($managed_item_id);
$managed_child_items = array_values($managed_child->get_items('line_item'));
wcos_p2_adapter_assert(1 === count($managed_child_items), 'Managed-stock child line count is invalid.');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units('3', 6) === WCOS_Decimal::to_units($managed_source_item->get_meta('_reduced_stock', true), 6),
	'Managed-stock source marker was not reduced to the residual quantity.'
);
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units('1', 6) === WCOS_Decimal::to_units($managed_child_items[0]->get_meta('_reduced_stock', true), 6),
	'Managed-stock child marker was not allocated exactly.'
);
wcos_p2_adapter_assert((bool) $managed_child->get_data_store()->get_stock_reduced($managed_child->get_id()), 'Managed-stock child did not inherit the stock-reduced lifecycle flag.');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units('26', 6) === WCOS_Decimal::to_units(wc_get_product($managed->get_id())->get_stock_quantity(), 6),
	'Split changed physical stock for an already-reduced managed order.'
);

/* Cancelling child then source must restore exactly the original four sold units once. */
$managed_child->update_status('cancelled');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units('27', 6) === WCOS_Decimal::to_units(wc_get_product($managed->get_id())->get_stock_quantity(), 6),
	'Cancelling the managed-stock child did not restore exactly its allocated unit.'
);
$managed_source->update_status('cancelled');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units('30', 6) === WCOS_Decimal::to_units(wc_get_product($managed->get_id())->get_stock_quantity(), 6),
	'Cancelling the managed-stock source did not restore the residual units exactly once.'
);
wcos_p2_adapter_cleanup($managed_source->get_id(), $managed_operation);
wcos_p2_stock_delete_product($managed);

/* Unmanaged stock: Split must preserve order quantities without creating stock markers. */
$unmanaged = wcos_p2_adapter_product('WCOS P2 unmanaged stock', '8.00');
list($unmanaged_source, $unmanaged_item_id) = wcos_p2_adapter_order($unmanaged, 4);
$unmanaged_report = $adapter->preflight(wc_get_order($unmanaged_source->get_id()));
wcos_p2_adapter_assert(1 === (int) $unmanaged_report['unmanaged_stock_lines'], 'P2 preflight did not classify an unmanaged-stock line.');
$unmanaged_operation = 'p2-unmanaged-' . wp_generate_uuid4();
$unmanaged_children = $adapter->split(
	wc_get_order($unmanaged_source->get_id()),
	array('child-one' => array($unmanaged_item_id => '1.000000')),
	$unmanaged_operation
);
$unmanaged_child_item = current($unmanaged_children[0]->get_items('line_item'));
wcos_p2_adapter_assert('' === $unmanaged_child_item->get_meta('_reduced_stock', true), 'Unmanaged-stock child received a synthetic reduced-stock marker.');
wcos_p2_adapter_cleanup($unmanaged_source->get_id(), $unmanaged_operation);
wcos_p2_stock_delete_product($unmanaged);

/* Variation-owned stock: exact variation identity is preserved and variation stock is untouched. */
list($variation_parent, $variation) = wcos_p2_stock_variable_pair('WCOS P2 variation managed', false, true, 0, 18);
list($variation_source, $variation_item_id) = wcos_p2_adapter_order($variation, 4);
$variation_report = $adapter->preflight(wc_get_order($variation_source->get_id()));
wcos_p2_adapter_assert(1 === (int) $variation_report['managed_stock_lines'], 'P2 preflight did not classify a variation-managed line.');
$variation_stock_before = wc_get_product($variation->get_id())->get_stock_quantity();
$variation_operation = 'p2-variation-managed-' . wp_generate_uuid4();
$variation_children = $adapter->split(
	wc_get_order($variation_source->get_id()),
	array('child-one' => array($variation_item_id => '1.000000')),
	$variation_operation
);
$variation_child_item = current($variation_children[0]->get_items('line_item'));
wcos_p2_adapter_assert($variation->get_id() === $variation_child_item->get_variation_id(), 'Split collapsed a variation-managed line to its parent product.');
wcos_p2_adapter_assert($variation_parent->get_id() === $variation_child_item->get_product_id(), 'Split lost the variation parent product ID.');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units($variation_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($variation->get_id())->get_stock_quantity(), 6),
	'Split changed variation-owned physical stock.'
);
wcos_p2_adapter_cleanup($variation_source->get_id(), $variation_operation);
wcos_p2_stock_delete_product($variation);
wcos_p2_stock_delete_product($variation_parent);

/* Parent-managed variation: WooCommerce stock ownership must resolve to the parent and remain untouched. */
list($parent_managed, $parent_variation) = wcos_p2_stock_variable_pair('WCOS P2 parent managed', true, false, 25, 0);
wcos_p2_adapter_assert($parent_managed->get_id() === $parent_variation->get_stock_managed_by_id(), 'WooCommerce fixture did not resolve variation stock ownership to the parent.');
list($parent_source, $parent_item_id) = wcos_p2_adapter_order($parent_variation, 4);
$parent_report = $adapter->preflight(wc_get_order($parent_source->get_id()));
wcos_p2_adapter_assert(1 === (int) $parent_report['managed_stock_lines'], 'P2 preflight did not classify a parent-managed variation as managed stock.');
$parent_stock_before = wc_get_product($parent_managed->get_id())->get_stock_quantity();
$parent_operation = 'p2-parent-managed-' . wp_generate_uuid4();
$parent_children = $adapter->split(
	wc_get_order($parent_source->get_id()),
	array('child-one' => array($parent_item_id => '1.000000')),
	$parent_operation
);
$parent_child_item = current($parent_children[0]->get_items('line_item'));
wcos_p2_adapter_assert($parent_variation->get_id() === $parent_child_item->get_variation_id(), 'Split lost parent-managed variation identity.');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units($parent_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($parent_managed->get_id())->get_stock_quantity(), 6),
	'Split changed parent-managed physical stock.'
);
$parent_guard = WCOS_Stock_Side_Effect_Guard::begin('p2-parent-owner-guard-' . wp_generate_uuid4());
$parent_owner_detected = false;
try {
	wc_update_product_stock($parent_variation->get_id(), 1, 'decrease');
} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
	$events = $exception->get_events();
	$parent_owner_detected = !empty($events) && absint($events[0]['stock_owner_id']) === $parent_managed->get_id();
}
WCOS_Stock_Side_Effect_Guard::end($parent_guard);
wcos_p2_adapter_assert($parent_owner_detected, 'P2 stock guard did not identify the parent stock owner for a variation.');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units($parent_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($parent_managed->get_id())->get_stock_quantity(), 6),
	'Parent-managed stock changed despite the pre-write guard.'
);
wcos_p2_adapter_cleanup($parent_source->get_id(), $parent_operation);
wcos_p2_stock_delete_product($parent_variation);
wcos_p2_stock_delete_product($parent_managed);

/* Backorders: a backordered managed line is identified and Split itself does not move stock. */
$backorder = wcos_p2_adapter_product('WCOS P2 backorder', '5.00', 0);
$backorder->set_backorders('yes');
$backorder->set_stock_status('onbackorder');
$backorder->save();
list($backorder_source, $backorder_item_id) = wcos_p2_adapter_order($backorder, 3);
$backorder_report = $adapter->preflight(wc_get_order($backorder_source->get_id()));
wcos_p2_adapter_assert(1 === (int) $backorder_report['managed_stock_lines'], 'P2 preflight did not classify the backorder as managed stock.');
wcos_p2_adapter_assert(1 === (int) $backorder_report['backorder_lines'], 'P2 preflight did not identify an on-backorder line.');
$backorder_operation = 'p2-backorder-' . wp_generate_uuid4();
$backorder_children = $adapter->split(
	wc_get_order($backorder_source->get_id()),
	array('child-one' => array($backorder_item_id => '1.000000')),
	$backorder_operation
);
wcos_p2_adapter_assert(1 === count($backorder_children), 'P2 adapter failed to split an allowed backorder line.');
wcos_p2_adapter_assert(
	0 === WCOS_Decimal::to_units(wc_get_product($backorder->get_id())->get_stock_quantity(), 6),
	'Split changed physical stock for a pending backorder.'
);
wcos_p2_adapter_cleanup($backorder_source->get_id(), $backorder_operation);
wcos_p2_stock_delete_product($backorder);

update_option('woocommerce_manage_stock', $manage_stock_before);

echo "p2-stock-matrix-ok\n";
