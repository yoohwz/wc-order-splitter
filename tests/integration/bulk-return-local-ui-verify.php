<?php

if (!defined('ABSPATH')) { exit(1); }
function wcos_bulk_ui_verify_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$manifest = get_option('wcos_bulk_return_local_ui_fixture', array());
wcos_bulk_ui_verify_assert(is_array($manifest) && 2 === count($manifest['child_ids']), 'Bulk UI manifest is unavailable.');
$reduced_units = 0;
foreach ($manifest['order_ids'] as $order_id) {
	$order = wc_get_order($order_id); wcos_bulk_ui_verify_assert($order instanceof WC_Order, 'Bulk UI order is unavailable before verification.');
	foreach ($order->get_items('line_item') as $item) { $reduced_units += WCOS_Decimal::to_units($item->get_meta('_reduced_stock', true) ?: '0', 6); }
}
foreach ($manifest['child_ids'] as $child_id) {
	$child = wc_get_order($child_id); wcos_bulk_ui_verify_assert($child instanceof WC_Order && 'trash' === $child->get_status(), 'Bulk UI child was not retired after completion.');
}
$original = wc_get_order($manifest['original_id']); $product = wc_get_product($manifest['product_id']);
wcos_bulk_ui_verify_assert($original instanceof WC_Order && 'trash' !== $original->get_status(), 'Bulk UI original is not active after completion.');
wcos_bulk_ui_verify_assert($product instanceof WC_Product && $manifest['product_stock_before'] === WCOS_Decimal::normalize($product->get_stock_quantity(), 6), 'Bulk UI flow changed physical product stock.');
wcos_bulk_ui_verify_assert($manifest['reduced_stock_before'] === WCOS_Decimal::from_units($reduced_units, 6), 'Bulk UI flow did not conserve aggregate reduced-stock ownership.');
echo 'BULK_UI_VERIFY_OK original=' . $manifest['original_id'] . ' children=2 stock=neutral reduced=conserved' . "\n";
