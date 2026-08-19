<?php

if (!defined('ABSPATH')) {
    exit(1);
}

function wcos_p2_duplicate_note_count($order_id) {
    return count(wc_get_order_notes(array('order_id' => absint($order_id), 'limit' => -1)));
}

$side_product = wcos_p2_adapter_product('WCOS Duplicate side-effect product', '10.00', 20);
list($side_source, $side_item_id) = wcos_p2_adapter_order($side_product, 3, 'pending');
$side_source_id = $side_source->get_id();
$side_stock_before = wc_get_product($side_product->get_id())->get_stock_quantity();
$side_note_before = wcos_p2_duplicate_note_count($side_source_id);

$webhook = new WC_Webhook();
$webhook->set_name('WCOS Duplicate order-created contract');
$webhook->set_status('active');
$webhook->set_topic('order.created');
$webhook->set_delivery_url('https://example.invalid/wcos-duplicate-webhook');
$webhook->set_secret('wcos-duplicate-test-secret');
$webhook->set_user_id(1);
$webhook->save();
$webhook->enqueue();

$core_scheduler = false !== has_action('woocommerce_webhook_process_delivery', 'wc_webhook_process_delivery');
if ($core_scheduler) {
    remove_action('woocommerce_webhook_process_delivery', 'wc_webhook_process_delivery', 10);
}

$new_orders = array();
$status_events = array();
$webhook_args = array();
$mail_calls = array();
$stock_before_writes = array();
$stock_after_writes = array();

$new_order_callback = static function($order_id, $order = null) use (&$new_orders) {
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order_id);
    }
    if ($order instanceof WC_Order && 'wc-order-splitter-duplicate' === $order->get_created_via()) {
        $new_orders[] = absint($order_id);
    }
};
$status_callback = static function($order_id, $from, $to, $order) use (&$status_events) {
    if ($order instanceof WC_Order && 'wc-order-splitter-duplicate' === $order->get_created_via()) {
        $status_events[] = array(absint($order_id), (string) $from, (string) $to);
    }
};
$webhook_callback = static function($active_webhook, $arg) use (&$webhook_args, $webhook) {
    if ($active_webhook instanceof WC_Webhook && $active_webhook->get_id() === $webhook->get_id()) {
        $webhook_args[] = absint($arg);
    }
};
$mail_callback = static function($return, $atts) use (&$mail_calls) {
    $mail_calls[] = is_array($atts) ? $atts : array();
    return true;
};
$stock_before_callback = static function($stock_product) use (&$stock_before_writes) {
    if ($stock_product instanceof WC_Product) {
        $stock_before_writes[] = $stock_product->get_id();
    }
};
$stock_after_callback = static function($stock_product) use (&$stock_after_writes) {
    if ($stock_product instanceof WC_Product) {
        $stock_after_writes[] = $stock_product->get_id();
    }
};

add_action('woocommerce_new_order', $new_order_callback, PHP_INT_MAX, 2);
add_action('woocommerce_order_status_changed', $status_callback, PHP_INT_MAX, 4);
add_action('woocommerce_webhook_process_delivery', $webhook_callback, PHP_INT_MAX, 2);
add_filter('pre_wp_mail', $mail_callback, PHP_INT_MAX, 2);
add_action('woocommerce_product_before_set_stock', $stock_before_callback, PHP_INT_MAX, 1);
add_action('woocommerce_variation_before_set_stock', $stock_before_callback, PHP_INT_MAX, 1);
add_action('woocommerce_product_set_stock', $stock_after_callback, PHP_INT_MAX, 1);
add_action('woocommerce_variation_set_stock', $stock_after_callback, PHP_INT_MAX, 1);

$side_operation = 'p2-duplicate-side-' . wp_generate_uuid4();
$side_adapter = new WCOS_Duplicate_WooCommerce_Adapter();
$side_target = $side_adapter->duplicate(wc_get_order($side_source_id), $side_operation);
$side_target_id = $side_target->get_id();

wcos_p2_adapter_assert(array($side_target_id) === array_values($new_orders), 'Duplicate did not emit exactly one new-order event for its target.');
wcos_p2_adapter_assert(array($side_target_id) === array_values($webhook_args), 'Duplicate did not schedule exactly one active order.created webhook for its target.');
wcos_p2_adapter_assert(empty($status_events), 'Duplicate emitted an implicit target status transition.');
wcos_p2_adapter_assert(empty($mail_calls), 'Duplicate attempted to send an implicit email.');
wcos_p2_adapter_assert(empty($stock_before_writes) && empty($stock_after_writes), 'Safe Duplicate invoked a physical stock-write hook.');
wcos_p2_adapter_assert($side_note_before + 1 === wcos_p2_duplicate_note_count($side_source_id), 'Duplicate did not add exactly one source operation note.');
wcos_p2_adapter_assert(1 === wcos_p2_duplicate_note_count($side_target_id), 'Duplicate did not add exactly one target operation note.');
wcos_p2_adapter_assert($side_stock_before == wc_get_product($side_product->get_id())->get_stock_quantity(), 'Duplicate side-effect contract changed physical stock.');

$side_counts = array(
    'new_order' => count($new_orders),
    'webhook' => count($webhook_args),
    'status' => count($status_events),
    'mail' => count($mail_calls),
    'source_notes' => wcos_p2_duplicate_note_count($side_source_id),
    'target_notes' => wcos_p2_duplicate_note_count($side_target_id),
);
$side_retry = $side_adapter->duplicate(wc_get_order($side_source_id), $side_operation);
wcos_p2_adapter_assert($side_target_id === $side_retry->get_id(), 'Duplicate retry returned a different target.');
wcos_p2_adapter_assert($side_counts['new_order'] === count($new_orders), 'Duplicate retry emitted another new-order event.');
wcos_p2_adapter_assert($side_counts['webhook'] === count($webhook_args), 'Duplicate retry scheduled another webhook.');
wcos_p2_adapter_assert($side_counts['status'] === count($status_events), 'Duplicate retry emitted a status transition.');
wcos_p2_adapter_assert($side_counts['mail'] === count($mail_calls), 'Duplicate retry attempted to send email.');
wcos_p2_adapter_assert($side_counts['source_notes'] === wcos_p2_duplicate_note_count($side_source_id), 'Duplicate retry duplicated the source note.');
wcos_p2_adapter_assert($side_counts['target_notes'] === wcos_p2_duplicate_note_count($side_target_id), 'Duplicate retry duplicated the target note.');

remove_action('woocommerce_new_order', $new_order_callback, PHP_INT_MAX);
remove_action('woocommerce_order_status_changed', $status_callback, PHP_INT_MAX);
remove_action('woocommerce_webhook_process_delivery', $webhook_callback, PHP_INT_MAX);
remove_filter('pre_wp_mail', $mail_callback, PHP_INT_MAX);
remove_action('woocommerce_product_before_set_stock', $stock_before_callback, PHP_INT_MAX);
remove_action('woocommerce_variation_before_set_stock', $stock_before_callback, PHP_INT_MAX);
remove_action('woocommerce_product_set_stock', $stock_after_callback, PHP_INT_MAX);
remove_action('woocommerce_variation_set_stock', $stock_after_callback, PHP_INT_MAX);
if ($core_scheduler) {
    add_action('woocommerce_webhook_process_delivery', 'wc_webhook_process_delivery', 10, 2);
}
foreach ((array) $webhook->get_hooks() as $hook) {
    remove_action($hook, array($webhook, 'process'));
}
$webhook->delete(true);

/* A stock-write attempt inside Duplicate is blocked before the data store changes. */
$dirty_product = wcos_p2_adapter_product('WCOS Duplicate blocked stock', '8.00', 12);
list($dirty_source, $dirty_item_id) = wcos_p2_adapter_order($dirty_product, 2, 'pending');
$dirty_source_id = $dirty_source->get_id();
$dirty_stock_before = wc_get_product($dirty_product->get_id())->get_stock_quantity();
$dirty_operation = 'p2-duplicate-dirty-' . wp_generate_uuid4();
$dirty_injected = false;
$dirty_callback = static function($stage) use (&$dirty_injected, $dirty_product) {
    if (!$dirty_injected && 'before_target_save' === $stage) {
        $dirty_injected = true;
        wc_update_product_stock($dirty_product->get_id(), 1, 'decrease');
    }
};
add_action('wcos_duplicate_mutation_checkpoint', $dirty_callback, 10, 4);
$dirty_blocked = false;
try {
    $side_adapter->duplicate(wc_get_order($dirty_source_id), $dirty_operation);
} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
    $events = $exception->get_events();
    $dirty_blocked = !empty($events) && !WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events);
}
remove_action('wcos_duplicate_mutation_checkpoint', $dirty_callback, 10);
wcos_p2_adapter_assert($dirty_injected && $dirty_blocked, 'Duplicate did not block an in-request physical-stock attempt.');
wcos_p2_adapter_assert($dirty_stock_before == wc_get_product($dirty_product->get_id())->get_stock_quantity(), 'Blocked Duplicate stock attempt changed physical stock.');
wcos_p2_adapter_assert(empty(wcos_duplicate_targets($dirty_source_id, $dirty_operation)), 'Blocked pre-persistence Duplicate created a target.');
$dirty_record = WCOS_Operation_Journal::get(wc_get_order($dirty_source_id), $dirty_operation);
wcos_p2_adapter_assert(is_array($dirty_record) && 'failed' === $dirty_record['status'], 'Blocked Duplicate stock attempt did not leave a retriable failed journal.');
$dirty_target = $side_adapter->duplicate(wc_get_order($dirty_source_id), $dirty_operation);
wcos_p2_adapter_assert($dirty_target instanceof WC_Order, 'Duplicate retry did not recover after a blocked pre-write stock attempt.');
wcos_p2_adapter_assert(1 === count(wcos_duplicate_targets($dirty_source_id, $dirty_operation)), 'Duplicate retry created an unexpected target count.');
wcos_p2_adapter_assert($dirty_stock_before == wc_get_product($dirty_product->get_id())->get_stock_quantity(), 'Duplicate retry changed physical stock.');

/* Confirmed after-write evidence must persist manual reconciliation and block future mutations. */
$manual_product = wcos_p2_adapter_product('WCOS Duplicate manual reconciliation', '9.00', 14);
list($manual_source, $manual_item_id) = wcos_p2_adapter_order($manual_product, 2, 'pending');
$manual_source_id = $manual_source->get_id();
$manual_operation = 'p2-duplicate-manual-' . wp_generate_uuid4();
$manual_injected = false;
$manual_callback = static function($note_id, $note_order) use (&$manual_injected, $manual_product) {
    if ($manual_injected || !$note_order instanceof WC_Order) {
        return;
    }
    $manual_injected = true;
    WCOS_Stock_Side_Effect_Guard::record_product_stock_write($manual_product);
};
add_action('woocommerce_order_note_added', $manual_callback, 10, 2);
$manual_blocked = false;
try {
    $side_adapter->duplicate(wc_get_order($manual_source_id), $manual_operation);
} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
    $manual_blocked = WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($exception->get_events());
}
remove_action('woocommerce_order_note_added', $manual_callback, 10);
wcos_p2_adapter_assert($manual_injected && $manual_blocked, 'Duplicate after-write evidence did not surface manual reconciliation.');
$manual_source = wc_get_order($manual_source_id);
$manual_record = WCOS_Operation_Journal::get($manual_source, $manual_operation);
wcos_p2_adapter_assert(is_array($manual_record) && 'manual_reconciliation' === $manual_record['status'], 'Duplicate after-write evidence did not persist manual_reconciliation.');
wcos_p2_adapter_assert(WCOS_Manual_Reconciliation_Blocker::has_active($manual_source), 'Duplicate manual-reconciliation blocker was not persisted.');
$manual_report = $side_adapter->preflight($manual_source);
wcos_p2_adapter_assert(empty($manual_report['supported']) && 'manual_reconciliation_required' === $manual_report['reason'], 'Duplicate preflight did not block unresolved manual reconciliation.');

$side_source = wc_get_order($side_source_id);
WCOS_Operation_Journal::delete($side_source, $side_operation);
$side_target = wc_get_order($side_target_id);
if ($side_target) {
    $side_target->delete(true);
}
if ($side_source) {
    $side_source->delete(true);
}
wp_delete_post($side_product->get_id(), true);

$dirty_source = wc_get_order($dirty_source_id);
WCOS_Operation_Journal::delete($dirty_source, $dirty_operation);
foreach (wcos_duplicate_targets($dirty_source_id, $dirty_operation) as $target) {
    $target->delete(true);
}
if ($dirty_source) {
    $dirty_source->delete(true);
}
wp_delete_post($dirty_product->get_id(), true);

$manual_source = wc_get_order($manual_source_id);
WCOS_Operation_Journal::mark_manual_reconciled($manual_source, $manual_operation, array('reconciliation_note' => 'duplicate-side-effect-test-resolved'));
WCOS_Operation_Journal::delete($manual_source, $manual_operation);
foreach (wcos_duplicate_targets($manual_source_id, $manual_operation) as $target) {
    $target->delete(true);
}
if ($manual_source) {
    $manual_source->delete(true);
}
wp_delete_post($manual_product->get_id(), true);

echo "p2-duplicate-side-effect-contract-ok\n";
