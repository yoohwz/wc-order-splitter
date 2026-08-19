<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$compat_product = wcos_p2_adapter_product('WCOS Duplicate configured product', '15.00', 30);
$compat_order = wc_create_order();
$compat_order->set_status('processing');
$compat_order->set_currency('USD');
$compat_item_a = $compat_order->add_product($compat_product, 1);
$compat_item_b = $compat_order->add_product($compat_product, 2);
$compat_order->calculate_totals(false);
$compat_order->set_payment_method('bacs');
$compat_order->set_payment_method_title('Direct bank transfer');
$compat_order->set_transaction_id('paid-source-transaction');
$compat_order->set_date_paid(time());
$compat_order->save();
$compat_order = wc_get_order($compat_order->get_id());
$compat_order_id = $compat_order->get_id();

$item_a = $compat_order->get_item($compat_item_a);
$item_a->add_meta_data('_vendor_configuration', 'config-a', true);
$item_a->save();
$item_b = $compat_order->get_item($compat_item_b);
$item_b->add_meta_data('_vendor_configuration', 'config-b', true);
$item_b->save();
$compat_order = wc_get_order($compat_order_id);

$compat_adapter = new WCOS_Duplicate_WooCommerce_Adapter();
$unadapted = $compat_adapter->preflight($compat_order);
wcos_p2_adapter_assert(empty($unadapted['supported']) && 'unclassified_private_metadata' === $unadapted['reason'], 'Duplicate accepted configured private metadata without an adapter.');

$classification_filter = static function($classification, $key, $value, $context) {
    if ('_vendor_configuration' === $key
        && in_array($context, array(WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE, WCOS_Order_Item_Meta_Policy::CONTEXT_IDENTITY), true)) {
        return WCOS_Order_Item_Meta_Policy::CLASS_BUSINESS;
    }
    return $classification;
};
add_filter('wcos_order_item_meta_classification', $classification_filter, 10, 4);

$compat_operation = 'p2-duplicate-configured-' . wp_generate_uuid4();
try {
    $adapted = $compat_adapter->preflight(wc_get_order($compat_order_id));
    wcos_p2_adapter_assert(!empty($adapted['supported']), 'Explicit Duplicate metadata adapter did not unlock configured lines.');
    wcos_p2_adapter_assert(!empty($adapted['is_paid']) && !empty($adapted['has_transaction']), 'Paid-source Duplicate preflight lost payment-state disclosure.');

    /* Historical order items remain authoritative even when the catalog product is gone. */
    wp_delete_post($compat_product->get_id(), true);
    $deleted_report = $compat_adapter->preflight(wc_get_order($compat_order_id));
    wcos_p2_adapter_assert(!empty($deleted_report['supported']), 'Duplicate rejected configured historical lines after catalog product deletion.');
    wcos_p2_adapter_assert(2 === (int) $deleted_report['deleted_product_lines'], 'Duplicate did not report both deleted-product lines.');

    $target = $compat_adapter->duplicate(wc_get_order($compat_order_id), $compat_operation);
    $target = wc_get_order($target->get_id());
    wcos_p2_adapter_assert('pending' === $target->get_status(), 'Paid source did not duplicate to Pending payment.');
    wcos_p2_adapter_assert(!$target->is_paid(), 'Duplicate target inherited paid state.');
    wcos_p2_adapter_assert('' === (string) $target->get_transaction_id(), 'Duplicate target inherited paid transaction ID.');
    wcos_p2_adapter_assert(!$target->get_date_paid(), 'Duplicate target inherited date_paid.');
    wcos_p2_adapter_assert(2 === count($target->get_items('line_item')), 'Duplicate collapsed configured lines sharing one product ID.');

    $configs = array();
    foreach ($target->get_items('line_item') as $line) {
        $configs[] = (string) $line->get_meta('_vendor_configuration', true);
    }
    sort($configs, SORT_STRING);
    wcos_p2_adapter_assert(array('config-a', 'config-b') === $configs, 'Duplicate did not preserve configured-line business metadata exactly.');

    $target->delete(true);
    WCOS_Operation_Journal::delete(wc_get_order($compat_order_id), $compat_operation);
} finally {
    remove_filter('wcos_order_item_meta_classification', $classification_filter, 10);
    $source = wc_get_order($compat_order_id);
    if ($source) {
        $source->delete(true);
    }
    if (get_post($compat_product->get_id())) {
        wp_delete_post($compat_product->get_id(), true);
    }
}

echo "p2-duplicate-compatibility-ok\n";
