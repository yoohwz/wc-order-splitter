<?php

if (!defined('ABSPATH')) {
    exit(1);
}

function wcos_p2_precision_build_order($precision, $currency, $line_amount, $tax_amount) {
    $precision = WCOS_Price_Precision_Scope::validate($precision);
    $product = new WC_Product_Simple();
    $product->set_name('WCOS P2 precision ' . $precision);
    $product->set_regular_price($line_amount);
    $product_id = $product->save();

    $order = wc_create_order();
    $order->set_status('pending');
    $order->set_currency($currency);
    $order->set_prices_include_tax(false);

    $line = new WC_Order_Item_Product();
    $line_result = $line->set_props(array(
        'name' => 'Precision line',
        'product_id' => $product_id,
        'quantity' => '3.000000',
        'tax_class' => '',
        'subtotal' => $line_amount,
        'total' => $line_amount,
        'taxes' => array(
            'subtotal' => array(700 + $precision => $tax_amount),
            'total' => array(700 + $precision => $tax_amount),
        ),
        'subtotal_tax' => $tax_amount,
        'total_tax' => $tax_amount,
    ));
    wcos_p2_adapter_assert(!is_wp_error($line_result), 'Unable to build the precision line item.');
    $order->add_item($line);

    $tax = new WC_Order_Item_Tax();
    $tax_result = $tax->set_props(array(
        'rate_id' => 700 + $precision,
        'label' => 'Precision historical rate',
        'compound' => false,
        'tax_total' => $tax_amount,
        'shipping_tax_total' => WCOS_Decimal::from_units(0, $precision),
        'rate_percent' => 10,
    ));
    wcos_p2_adapter_assert(!is_wp_error($tax_result), 'Unable to build the precision tax row.');
    $order->add_item($tax);

    $grand_units = WCOS_Decimal::to_units($line_amount, $precision) + WCOS_Decimal::to_units($tax_amount, $precision);
    $order_result = $order->set_props(array(
        'discount_total' => WCOS_Decimal::from_units(0, $precision),
        'discount_tax' => WCOS_Decimal::from_units(0, $precision),
        'shipping_total' => WCOS_Decimal::from_units(0, $precision),
        'shipping_tax' => WCOS_Decimal::from_units(0, $precision),
        'cart_tax' => $tax_amount,
        'total_tax' => $tax_amount,
        'total' => WCOS_Decimal::from_units($grand_units, $precision),
    ));
    wcos_p2_adapter_assert(!is_wp_error($order_result), 'Unable to set precision order totals.');
    $order->save();
    return array(wc_get_order($order->get_id()), $product_id, $line->get_id());
}

function wcos_p2_precision_assert_conserved(WC_Order $source, array $children, array $before, $precision) {
    $orders = array_merge(array($source), $children);
    $after = WCOS_Order_Contract_Snapshot::aggregate($orders, $precision);
    WCOS_Mutation_Contract::assert_conserved($before, $after, $precision);
}

$original_precision_option = get_option('woocommerce_price_num_decimals', 2);
$adapter = new WCOS_Split_WooCommerce_Adapter();

try {
    foreach (array(
        array('precision' => 0, 'currency' => 'JPY', 'line' => '10', 'tax' => '1'),
        array('precision' => 3, 'currency' => 'BHD', 'line' => '10.001', 'tax' => '1.001'),
    ) as $case) {
        update_option('woocommerce_price_num_decimals', (string) $case['precision']);
        wcos_p2_adapter_assert($case['precision'] === wc_get_price_decimals(), 'WooCommerce did not expose the requested precision fixture.');

        list($source, $product_id, $item_id) = wcos_p2_precision_build_order(
            $case['precision'],
            $case['currency'],
            $case['line'],
            $case['tax']
        );
        WCOS_Order_Totals_Rebuilder::assert_consistent($source, $case['precision']);
        $before = WCOS_Order_Contract_Snapshot::aggregate(array($source), $case['precision']);
        $operation = 'p2-price-precision-' . $case['precision'] . '-' . wp_generate_uuid4();
        $report = $adapter->preflight($source, $operation);
        wcos_p2_adapter_assert($case['precision'] === (int) $report['price_precision'], 'Preflight reported the wrong price precision.');

        $children = $adapter->split(
            $source,
            array('child-one' => array($item_id => '1.000000')),
            $operation
        );
        wcos_p2_adapter_assert(1 === count($children), 'Precision Split did not create exactly one child.');

        $source = wc_get_order($source->get_id());
        $child = wc_get_order($children[0]->get_id());
        wcos_p2_precision_assert_conserved($source, array($child), $before, $case['precision']);
        WCOS_Order_Totals_Rebuilder::assert_consistent($source, $case['precision']);
        WCOS_Order_Totals_Rebuilder::assert_consistent($child, $case['precision']);

        $record = WCOS_Operation_Journal::get($source, $operation);
        wcos_p2_adapter_assert(
            is_array($record) && $case['precision'] === (int) $record['context']['price_precision'],
            'Durable journal did not capture the mutation price precision.'
        );

        if (0 === $case['precision']) {
            foreach (array($source, $child) as $order) {
                foreach ($order->get_items('line_item') as $line) {
                    wcos_p2_adapter_assert(false === strpos((string) $line->get_total(), '.'), 'Zero-decimal Split persisted a fractional line total.');
                }
            }
        } else {
            $source_lines = $source->get_items('line_item');
            $child_lines = $child->get_items('line_item');
            $source_line = reset($source_lines);
            $child_line = reset($child_lines);
            wcos_p2_adapter_assert('6.667' === WCOS_Decimal::normalize($source_line->get_total(), 3), 'Three-decimal source allocation drifted.');
            wcos_p2_adapter_assert('3.334' === WCOS_Decimal::normalize($child_line->get_total(), 3), 'Three-decimal child allocation drifted.');
            wcos_p2_adapter_assert('0.667' === WCOS_Decimal::normalize($source_line->get_total_tax(), 3), 'Three-decimal source tax allocation drifted.');
            wcos_p2_adapter_assert('0.334' === WCOS_Decimal::normalize($child_line->get_total_tax(), 3), 'Three-decimal child tax allocation drifted.');
        }

        wcos_p2_adapter_cleanup($source->get_id(), $operation);
        wp_delete_post($product_id, true);
    }

    /* Retry must use the journal-captured precision, not the store setting at retry time. */
    update_option('woocommerce_price_num_decimals', '3');
    list($retry_source, $retry_product_id, $retry_item_id) = wcos_p2_precision_build_order(3, 'BHD', '10.001', '1.001');
    $retry_before = WCOS_Order_Contract_Snapshot::aggregate(array($retry_source), 3);
    $retry_operation = 'p2-price-precision-retry-' . wp_generate_uuid4();
    $fail_once = true;
    $crash = static function($stage) use (&$fail_once) {
        if ($fail_once && 'after_child_save' === $stage) {
            $fail_once = false;
            throw new RuntimeException('Injected precision retry crash.');
        }
    };
    add_action('wcos_split_mutation_checkpoint', $crash, 10, 4);
    $crashed = false;
    try {
        $adapter->split(
            $retry_source,
            array('child-one' => array($retry_item_id => '1.000000')),
            $retry_operation
        );
    } catch (RuntimeException $exception) {
        $crashed = false !== strpos($exception->getMessage(), 'Injected precision retry crash');
    }
    remove_action('wcos_split_mutation_checkpoint', $crash, 10);
    wcos_p2_adapter_assert($crashed, 'Precision retry fixture did not crash at the intended boundary.');

    $retry_record = WCOS_Operation_Journal::get(wc_get_order($retry_source->get_id()), $retry_operation);
    wcos_p2_adapter_assert(is_array($retry_record) && 3 === (int) $retry_record['context']['price_precision'], 'Interrupted operation did not capture three-decimal precision.');
    $persisted_children = wcos_p2_adapter_children($retry_source->get_id(), $retry_operation);
    wcos_p2_adapter_assert(1 === count($persisted_children), 'Precision crash did not preserve exactly one reusable child.');

    update_option('woocommerce_price_num_decimals', '0');
    wcos_p2_adapter_assert(0 === wc_get_price_decimals(), 'Retry fixture did not change the ambient store precision.');
    $retry_report = $adapter->preflight(wc_get_order($retry_source->get_id()), $retry_operation);
    wcos_p2_adapter_assert(3 === (int) $retry_report['price_precision'], 'Retry preflight did not pin the journal-captured precision.');
    wcos_p2_adapter_assert(0 === wc_get_price_decimals(), 'Preflight precision scope leaked into the ambient request state.');

    $retry_children = $adapter->split(
        wc_get_order($retry_source->get_id()),
        array('child-one' => array($retry_item_id => '1.000000')),
        $retry_operation
    );
    wcos_p2_adapter_assert(1 === count($retry_children), 'Precision retry did not return one child.');
    wcos_p2_adapter_assert($persisted_children[0]->get_id() === $retry_children[0]->get_id(), 'Precision retry created a duplicate child.');
    wcos_p2_adapter_assert(0 === wc_get_price_decimals(), 'Mutation precision scope leaked after retry completion.');

    $retry_source = wc_get_order($retry_source->get_id());
    $retry_child = wc_get_order($retry_children[0]->get_id());
    wcos_p2_precision_assert_conserved($retry_source, array($retry_child), $retry_before, 3);
    $retry_record = WCOS_Operation_Journal::get($retry_source, $retry_operation);
    wcos_p2_adapter_assert('completed' === $retry_record['status'], 'Precision retry did not complete the original journal.');
    wcos_p2_adapter_assert(3 === (int) $retry_record['context']['price_precision'], 'Retry mutated the journal precision contract.');

    wcos_p2_adapter_cleanup($retry_source->get_id(), $retry_operation);
    wp_delete_post($retry_product_id, true);
} finally {
    update_option('woocommerce_price_num_decimals', $original_precision_option);
}

echo "p2-price-precision-ok\n";
