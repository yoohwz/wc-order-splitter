<?php

if (!defined('ABSPATH')) {
    exit(1);
}

function wcos_whole_line_order(WC_Product $first, $first_qty, WC_Product $second, $second_qty, $status = 'processing') {
    $order = wc_create_order();
    $order->set_status($status);
    $order->set_currency('USD');
    $first_item_id = $order->add_product($first, $first_qty);
    $second_item_id = $order->add_product($second, $second_qty);
    $order->calculate_totals(false);
    $order->save();
    return array($order, (int) $first_item_id, (int) $second_item_id);
}

wcos_p2_adapter_assert(
    WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY === WCOS_Split_Execution_Policy::normalize(''),
    'Empty Split execution policy did not resolve to the production manual default.'
);
wcos_p2_adapter_assert(
    WCOS_Split_Execution_Policy::allows_whole_line_transfer(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER),
    'Whole-line execution policy was not recognized.'
);

/* Manual quantity policy must still reject residual=0. */
$manual_a = wcos_p2_adapter_product('WCOS whole-line manual A', '10.00');
$manual_b = wcos_p2_adapter_product('WCOS whole-line manual B', '5.00');
list($manual_source, $manual_a_item, $manual_b_item) = wcos_whole_line_order($manual_a, 2, $manual_b, 1, 'pending');
$manual_rejected = false;
try {
    WCOS_Split_Plan::normalize(
        $manual_source,
        array('child-1' => array($manual_a_item => '2.000000')),
        WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY
    );
} catch (InvalidArgumentException $exception) {
    $manual_rejected = false !== strpos($exception->getMessage(), 'positive quantity');
}
wcos_p2_adapter_assert($manual_rejected, 'Manual quantity Split policy accepted a full-line transfer.');

$all_lines_rejected = false;
try {
    WCOS_Split_Plan::normalize(
        $manual_source,
        array(
            'child-1' => array(
                $manual_a_item => '2.000000',
                $manual_b_item => '1.000000',
            ),
        ),
        WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
    );
} catch (InvalidArgumentException $exception) {
    $all_lines_rejected = false !== strpos($exception->getMessage(), 'at least one product line');
}
wcos_p2_adapter_assert($all_lines_rejected, 'Whole-line policy allowed every product line to leave the source order.');
$manual_source->delete(true);
wp_delete_post($manual_a->get_id(), true);
wp_delete_post($manual_b->get_id(), true);

/* Normal whole-line transfer conserves money/tax/quantity/_reduced_stock and physical stock. */
$product_a = wcos_p2_adapter_product('WCOS whole-line stock A', '12.00', 30);
$product_b = wcos_p2_adapter_product('WCOS whole-line stock B', '7.00', 40);
list($source, $a_item_id, $b_item_id) = wcos_whole_line_order($product_a, 2, $product_b, 3, 'processing');
$source_id = $source->get_id();
wc_reduce_stock_levels($source);
$source->get_data_store()->set_stock_reduced($source_id, true);
$source = wc_get_order($source_id);
$stock_after_sale_a = wc_get_product($product_a->get_id())->get_stock_quantity();
$stock_after_sale_b = wc_get_product($product_b->get_id())->get_stock_quantity();
$contract_before = WCOS_Order_Contract_Snapshot::aggregate(array($source));
$operation = 'p2-whole-line-' . wp_generate_uuid4();
$children = (new WCOS_Split_Order_Service())->split(
    $source,
    array('child-1' => array($a_item_id => '2.000000')),
    $operation,
    WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
);
wcos_p2_adapter_assert(1 === count($children), 'Whole-line Split did not create exactly one child.');
$child = wc_get_order($children[0]->get_id());
$source = wc_get_order($source_id);
wcos_p2_adapter_assert(!$source->get_item($a_item_id), 'Fully transferred source item remained persisted.');
wcos_p2_adapter_assert($source->get_item($b_item_id) instanceof WC_Order_Item_Product, 'Unmoved source item disappeared.');
wcos_p2_adapter_assert(1 === count($source->get_items('line_item')), 'Whole-line Split left an unexpected source line count.');
wcos_p2_adapter_assert(1 === count($child->get_items('line_item')), 'Whole-line child has an unexpected line count.');
$child_line = current($child->get_items('line_item'));
wcos_p2_adapter_assert(2 === (int) $child_line->get_quantity(), 'Whole-line child did not receive the full source line quantity.');
wcos_p2_adapter_assert('2.000000' === WCOS_Decimal::normalize($child_line->get_meta('_reduced_stock', true), 6), 'Whole-line child did not receive the full reduced-stock marker.');
wcos_p2_adapter_assert((bool) $source->get_data_store()->get_stock_reduced($source_id), 'Whole-line source lost its order-level stock-reduced flag.');
wcos_p2_adapter_assert((bool) $child->get_data_store()->get_stock_reduced($child->get_id()), 'Whole-line child did not receive the order-level stock-reduced flag.');
WCOS_Mutation_Contract::assert_conserved(
    $contract_before,
    WCOS_Order_Contract_Snapshot::aggregate(array($source, $child)),
    wc_get_price_decimals()
);
wcos_p2_adapter_assert($stock_after_sale_a == wc_get_product($product_a->get_id())->get_stock_quantity(), 'Whole-line Split changed physical stock for the moved line.');
wcos_p2_adapter_assert($stock_after_sale_b == wc_get_product($product_b->get_id())->get_stock_quantity(), 'Whole-line Split changed physical stock for the residual line.');
$record = WCOS_Operation_Journal::get($source, $operation);
wcos_p2_adapter_assert(is_array($record) && 'completed' === $record['status'], 'Whole-line Split journal did not complete.');
wcos_p2_adapter_assert(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER === $record['context']['execution_policy'], 'Whole-line execution policy was not durably journaled.');
wcos_p2_adapter_assert(array($a_item_id) === array_values($record['context']['fully_moved_item_ids']), 'Whole-line journal did not preserve the destructive source-item set.');

/* WooCommerce cancellation/restock must restore each stock owner exactly once. */
$child->update_status('cancelled');
wcos_p2_adapter_assert(30 == wc_get_product($product_a->get_id())->get_stock_quantity(), 'Cancelling the whole-line child did not restore the moved product exactly once.');
wcos_p2_adapter_assert($stock_after_sale_b == wc_get_product($product_b->get_id())->get_stock_quantity(), 'Cancelling the whole-line child changed residual source stock.');
$source->update_status('cancelled');
wcos_p2_adapter_assert(40 == wc_get_product($product_b->get_id())->get_stock_quantity(), 'Cancelling the residual source did not restore its product exactly once.');
WCOS_Operation_Journal::delete(wc_get_order($source_id), $operation);
$child->delete(true);
wc_get_order($source_id)->delete(true);
wp_delete_post($product_a->get_id(), true);
wp_delete_post($product_b->get_id(), true);

/* Crash after source persistence but before the normal checkpoint must finalize from conserved persisted evidence. */
$crash_a = wcos_p2_adapter_product('WCOS whole-line crash A', '9.00');
$crash_b = wcos_p2_adapter_product('WCOS whole-line crash B', '4.00');
list($crash_source, $crash_a_item, $crash_b_item) = wcos_whole_line_order($crash_a, 2, $crash_b, 2, 'pending');
$crash_source_id = $crash_source->get_id();
$crash_operation = 'p2-whole-line-source-crash-' . wp_generate_uuid4();
$crash_injected = false;
$crash_callback = static function($stage, $mutating_source) use (&$crash_injected) {
    if (!$crash_injected && 'before_source_save' === $stage && $mutating_source instanceof WC_Order) {
        $crash_injected = true;
        $mutating_source->save();
        throw new RuntimeException('Injected whole-line post-source-persist crash.');
    }
};
add_action('wcos_split_mutation_checkpoint', $crash_callback, 10, 4);
$crash_children = (new WCOS_Split_Order_Service())->split(
    $crash_source,
    array('child-1' => array($crash_a_item => '2.000000')),
    $crash_operation,
    WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
);
remove_action('wcos_split_mutation_checkpoint', $crash_callback, 10);
wcos_p2_adapter_assert($crash_injected, 'Whole-line source-persist crash fixture did not fire.');
wcos_p2_adapter_assert(1 === count($crash_children), 'Whole-line source-persist crash did not recover its child set.');
$crash_source = wc_get_order($crash_source_id);
wcos_p2_adapter_assert(!$crash_source->get_item($crash_a_item), 'Recovered whole-line source retained the fully moved item.');
$crash_record = WCOS_Operation_Journal::get($crash_source, $crash_operation);
wcos_p2_adapter_assert(is_array($crash_record) && 'completed' === $crash_record['status'], 'Whole-line source-persist crash did not finalize the durable journal.');
wcos_p2_adapter_assert(!WCOS_Manual_Reconciliation_Blocker::has_active($crash_source), 'Conserved whole-line crash recovery incorrectly entered manual reconciliation.');
foreach ($crash_children as $crash_child) {
    $crash_child->delete(true);
}
WCOS_Operation_Journal::delete($crash_source, $crash_operation);
$crash_source->delete(true);
wp_delete_post($crash_a->get_id(), true);
wp_delete_post($crash_b->get_id(), true);

/* If a fully moved source line is already deleted but persisted children no longer conserve state, auto-compensation is forbidden. */
$bad_a = wcos_p2_adapter_product('WCOS whole-line ambiguous A', '8.00');
$bad_b = wcos_p2_adapter_product('WCOS whole-line ambiguous B', '3.00');
list($bad_source, $bad_a_item, $bad_b_item) = wcos_whole_line_order($bad_a, 2, $bad_b, 2, 'pending');
$bad_source_id = $bad_source->get_id();
$bad_operation = 'p2-whole-line-ambiguous-' . wp_generate_uuid4();
$bad_injected = false;
$bad_callback = static function($stage, $mutating_source, $children) use (&$bad_injected) {
    if (!$bad_injected && 'before_source_save' === $stage && $mutating_source instanceof WC_Order) {
        $bad_injected = true;
        $mutating_source->save();
        $child = reset($children);
        if ($child instanceof WC_Order) {
            $line = current($child->get_items('line_item'));
            $line->set_quantity(((float) $line->get_quantity()) + 1);
            $line->save();
        }
        throw new RuntimeException('Injected corrupted whole-line persisted state.');
    }
};
add_action('wcos_split_mutation_checkpoint', $bad_callback, 10, 4);
$bad_failed = false;
try {
    (new WCOS_Split_Order_Service())->split(
        $bad_source,
        array('child-1' => array($bad_a_item => '2.000000')),
        $bad_operation,
        WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER
    );
} catch (RuntimeException $exception) {
    $bad_failed = true;
}
remove_action('wcos_split_mutation_checkpoint', $bad_callback, 10);
wcos_p2_adapter_assert($bad_injected && $bad_failed, 'Corrupted whole-line persisted state did not fail closed.');
$bad_source = wc_get_order($bad_source_id);
wcos_p2_adapter_assert(!$bad_source->get_item($bad_a_item), 'Ambiguous whole-line fixture did not persist the destructive source deletion.');
$bad_record = WCOS_Operation_Journal::get($bad_source, $bad_operation);
wcos_p2_adapter_assert(is_array($bad_record) && 'manual_reconciliation' === $bad_record['status'], 'Ambiguous whole-line persisted state did not enter manual reconciliation.');
wcos_p2_adapter_assert(WCOS_Manual_Reconciliation_Blocker::has_active($bad_source), 'Ambiguous whole-line persisted state did not block later mutations.');
$blocked_report = (new WCOS_Split_WooCommerce_Adapter())->preflight($bad_source);
wcos_p2_adapter_assert(empty($blocked_report['supported']) && 'manual_reconciliation_required' === $blocked_report['reason'], 'Split preflight did not block the ambiguous whole-line source.');

WCOS_Operation_Journal::mark_manual_reconciled($bad_source, $bad_operation, array('reconciliation_note' => 'whole-line-ambiguity-test-cleanup'));
foreach (wcos_p2_adapter_children($bad_source_id, $bad_operation) as $bad_child) {
    $bad_child->delete(true);
}
WCOS_Operation_Journal::delete(wc_get_order($bad_source_id), $bad_operation);
wc_get_order($bad_source_id)->delete(true);
wp_delete_post($bad_a->get_id(), true);
wp_delete_post($bad_b->get_id(), true);

echo "p2-whole-line-transfer-ok\n";
