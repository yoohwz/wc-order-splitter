<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$confirmation_precision_original = get_option('woocommerce_price_num_decimals', 2);
$confirmation_precision_operation = '';
$confirmation_precision_product_id = 0;
$confirmation_precision_source_id = 0;

try {
    update_option('woocommerce_price_num_decimals', '3');
    list($confirmation_precision_source, $confirmation_precision_product_id, $confirmation_precision_item_id) = wcos_p2_precision_build_order(
        3,
        'BHD',
        '10.001',
        '1.001'
    );
    $confirmation_precision_source_id = $confirmation_precision_source->get_id();
    $preflight = (new WCOS_Split_WooCommerce_Adapter())->preflight($confirmation_precision_source);
    wcos_p2_adapter_assert(3 === (int) $preflight['price_precision'], 'Confirmation precision fixture was not reviewed at three decimals.');

    $confirmation = WCOS_Split_Confirmation_Store::create(
        $confirmation_precision_source,
        array('child-1' => array($confirmation_precision_item_id => '1.000000')),
        $preflight,
        1
    );
    $confirmation_precision_operation = $confirmation['operation_id'];

    update_option('woocommerce_price_num_decimals', '0');
    wcos_p2_adapter_assert(0 === wc_get_price_decimals(), 'Confirmation precision fixture did not change the ambient store precision.');

    $verified = WCOS_Split_Confirmation_Store::verify(
        wc_get_order($confirmation_precision_source_id),
        $confirmation_precision_operation,
        $confirmation['confirmation_token'],
        1
    );
    wcos_p2_adapter_assert('confirmation' === $verified['replay_authority'], 'Pre-mutation confirmation unexpectedly used durable journal authority.');
    wcos_p2_adapter_assert(3 === (int) $verified['price_precision'], 'Confirmation verification lost the reviewed three-decimal precision.');
    wcos_p2_adapter_assert(0 === wc_get_price_decimals(), 'Confirmation verification leaked its reviewed precision into ambient request state.');
    wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($confirmation_precision_source_id), $confirmation_precision_operation), 'Confirmation verification created a mutation journal.');
} finally {
    if ($confirmation_precision_operation) {
        WCOS_Split_Confirmation_Store::delete($confirmation_precision_operation);
    }
    if ($confirmation_precision_source_id) {
        $confirmation_precision_source = wc_get_order($confirmation_precision_source_id);
        if ($confirmation_precision_source instanceof WC_Order) {
            $confirmation_precision_source->delete(true);
        }
    }
    if ($confirmation_precision_product_id) {
        wp_delete_post($confirmation_precision_product_id, true);
    }
    update_option('woocommerce_price_num_decimals', $confirmation_precision_original);
}

echo "p2-confirmation-precision-ok\n";
