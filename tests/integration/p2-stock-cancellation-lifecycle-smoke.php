<?php

if (!defined('ABSPATH')) {
    exit(1);
}

function wcos_p2_stock_lifecycle_assert_marker(WC_Order_Item_Product $item, $expected, $message) {
    wcos_p2_adapter_assert(
        WCOS_Decimal::to_units($expected, 6) === WCOS_Decimal::to_units($item->get_meta('_reduced_stock', true), 6),
        $message
    );
}

function wcos_p2_stock_lifecycle_quantity($product) {
    $product = $product instanceof WC_Product ? wc_get_product($product->get_id()) : wc_get_product($product);
    return $product ? WCOS_Decimal::normalize($product->get_stock_quantity(), 6) : null;
}

$adapter = new WCOS_Split_WooCommerce_Adapter();
$manage_stock_before_lifecycle = get_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_manage_stock', 'yes');

try {
    /* Variation-owned stock: cancellation restores each allocated share exactly once. */
    list($variation_parent, $variation) = wcos_p2_stock_variable_pair('WCOS P2 variation cancellation', false, true, 0, 18);
    list($variation_source, $variation_item_id) = wcos_p2_adapter_order($variation, 4);
    $variation_source->update_status('processing');
    $variation_source = wc_get_order($variation_source->get_id());
    wcos_p2_adapter_assert('14.000000' === wcos_p2_stock_lifecycle_quantity($variation), 'Processing did not reduce variation-owned stock by four units.');
    wcos_p2_stock_lifecycle_assert_marker($variation_source->get_item($variation_item_id), '4', 'Variation-owned processing order lacks the full reduced-stock marker.');

    $variation_operation = 'p2-variation-cancel-' . wp_generate_uuid4();
    $variation_children = $adapter->split(
        $variation_source,
        array('child-one' => array($variation_item_id => '1.000000')),
        $variation_operation
    );
    $variation_source = wc_get_order($variation_source->get_id());
    $variation_child = wc_get_order($variation_children[0]->get_id());
    $variation_child_items = array_values($variation_child->get_items('line_item'));
    wcos_p2_stock_lifecycle_assert_marker($variation_source->get_item($variation_item_id), '3', 'Variation-owned source marker was not reduced to three.');
    wcos_p2_stock_lifecycle_assert_marker($variation_child_items[0], '1', 'Variation-owned child marker was not allocated one unit.');
    wcos_p2_adapter_assert('14.000000' === wcos_p2_stock_lifecycle_quantity($variation), 'Split changed variation-owned physical stock.');

    $variation_child->update_status('cancelled');
    wcos_p2_adapter_assert('15.000000' === wcos_p2_stock_lifecycle_quantity($variation), 'Cancelling variation child did not restore exactly one unit.');
    $variation_source->update_status('cancelled');
    wcos_p2_adapter_assert('18.000000' === wcos_p2_stock_lifecycle_quantity($variation), 'Cancelling variation source did not restore exactly the residual three units.');
    wcos_p2_adapter_cleanup($variation_source->get_id(), $variation_operation);
    wcos_p2_stock_delete_product($variation);
    wcos_p2_stock_delete_product($variation_parent);

    /* Parent-managed variation: every restore must target the parent stock owner. */
    list($parent_product, $parent_variation) = wcos_p2_stock_variable_pair('WCOS P2 parent cancellation', true, false, 25, 0);
    wcos_p2_adapter_assert($parent_product->get_id() === $parent_variation->get_stock_managed_by_id(), 'Parent-managed lifecycle fixture has the wrong stock owner.');
    list($parent_source, $parent_item_id) = wcos_p2_adapter_order($parent_variation, 4);
    $parent_source->update_status('processing');
    $parent_source = wc_get_order($parent_source->get_id());
    wcos_p2_adapter_assert('21.000000' === wcos_p2_stock_lifecycle_quantity($parent_product), 'Processing did not reduce parent-owned stock by four units.');
    wcos_p2_stock_lifecycle_assert_marker($parent_source->get_item($parent_item_id), '4', 'Parent-managed processing order lacks the full reduced-stock marker.');

    $parent_operation = 'p2-parent-cancel-' . wp_generate_uuid4();
    $parent_children = $adapter->split(
        $parent_source,
        array('child-one' => array($parent_item_id => '1.000000')),
        $parent_operation
    );
    $parent_source = wc_get_order($parent_source->get_id());
    $parent_child = wc_get_order($parent_children[0]->get_id());
    $parent_child_items = array_values($parent_child->get_items('line_item'));
    wcos_p2_stock_lifecycle_assert_marker($parent_source->get_item($parent_item_id), '3', 'Parent-managed source marker was not reduced to three.');
    wcos_p2_stock_lifecycle_assert_marker($parent_child_items[0], '1', 'Parent-managed child marker was not allocated one unit.');
    wcos_p2_adapter_assert('21.000000' === wcos_p2_stock_lifecycle_quantity($parent_product), 'Split changed parent-managed physical stock.');

    $parent_child->update_status('cancelled');
    wcos_p2_adapter_assert('22.000000' === wcos_p2_stock_lifecycle_quantity($parent_product), 'Cancelling parent-managed child did not restore one parent-owned unit.');
    $parent_source->update_status('cancelled');
    wcos_p2_adapter_assert('25.000000' === wcos_p2_stock_lifecycle_quantity($parent_product), 'Cancelling parent-managed source did not restore the residual parent-owned units.');
    wcos_p2_adapter_cleanup($parent_source->get_id(), $parent_operation);
    wcos_p2_stock_delete_product($parent_variation);
    wcos_p2_stock_delete_product($parent_product);

    /* Fractional managed stock under an explicit fractional-quantity integration contract. */
    remove_filter('woocommerce_stock_amount', 'intval');
    add_filter('woocommerce_stock_amount', 'floatval');

    $fractional = wcos_p2_adapter_product('WCOS P2 fractional cancellation', '4.00', '10.500000');
    list($fractional_source, $fractional_item_id) = wcos_p2_adapter_order($fractional, '3.500000');
    $fractional_source->update_status('processing');
    $fractional_source = wc_get_order($fractional_source->get_id());
    wcos_p2_adapter_assert('7.000000' === wcos_p2_stock_lifecycle_quantity($fractional), 'Processing did not reduce fractional stock by 3.5 units.');
    wcos_p2_stock_lifecycle_assert_marker($fractional_source->get_item($fractional_item_id), '3.500000', 'Fractional processing order lacks the 3.5 reduced-stock marker.');

    $fractional_operation = 'p2-fractional-cancel-' . wp_generate_uuid4();
    $fractional_children = $adapter->split(
        $fractional_source,
        array('child-one' => array($fractional_item_id => '1.250000')),
        $fractional_operation
    );
    $fractional_source = wc_get_order($fractional_source->get_id());
    $fractional_child = wc_get_order($fractional_children[0]->get_id());
    $fractional_child_items = array_values($fractional_child->get_items('line_item'));
    wcos_p2_stock_lifecycle_assert_marker($fractional_source->get_item($fractional_item_id), '2.250000', 'Fractional source marker was not reduced to 2.25.');
    wcos_p2_stock_lifecycle_assert_marker($fractional_child_items[0], '1.250000', 'Fractional child marker was not allocated 1.25.');
    wcos_p2_adapter_assert('7.000000' === wcos_p2_stock_lifecycle_quantity($fractional), 'Split changed fractional managed physical stock.');

    $fractional_child->update_status('cancelled');
    wcos_p2_adapter_assert('8.250000' === wcos_p2_stock_lifecycle_quantity($fractional), 'Cancelling fractional child did not restore exactly 1.25 units.');
    $fractional_source->update_status('cancelled');
    wcos_p2_adapter_assert('10.500000' === wcos_p2_stock_lifecycle_quantity($fractional), 'Cancelling fractional source did not restore the residual 2.25 units.');
    wcos_p2_adapter_cleanup($fractional_source->get_id(), $fractional_operation);
    wcos_p2_stock_delete_product($fractional);

    remove_filter('woocommerce_stock_amount', 'floatval');
    add_filter('woocommerce_stock_amount', 'intval');
} finally {
    if (has_filter('woocommerce_stock_amount', 'floatval')) {
        remove_filter('woocommerce_stock_amount', 'floatval');
        add_filter('woocommerce_stock_amount', 'intval');
    }
    update_option('woocommerce_manage_stock', $manage_stock_before_lifecycle);
}

echo "p2-stock-cancellation-lifecycle-ok\n";
