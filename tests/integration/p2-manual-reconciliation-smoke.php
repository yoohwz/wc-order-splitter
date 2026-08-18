<?php

if (!defined('ABSPATH')) {
    exit(1);
}

/*
 * Extreme fallback: an integration reports that physical stock was already
 * written after the hardened Split journal has completed but while the service
 * still owns its source-order lease during best-effort order notes. The adapter
 * must persist manual_reconciliation before that lease is released.
 */
$manual_product = wcos_p2_adapter_product('WCOS P2 manual reconciliation', '8.00', 30);
list($manual_source, $manual_item_id) = wcos_p2_adapter_order($manual_product, 4);
$manual_source_id = $manual_source->get_id();
$manual_operation = 'p2-manual-reconciliation-' . wp_generate_uuid4();
$manual_injected = false;
$lease_owned_during_injection = false;

$manual_callback = static function($note_id, $note_order) use (&$manual_injected, &$lease_owned_during_injection, $manual_product, $manual_source_id, $manual_operation) {
    if ($manual_injected || !$note_order instanceof WC_Order) {
        return;
    }
    $manual_injected = true;
    $lease_owned_during_injection = WCOS_Operation_Lock::is_current_owned_for($manual_source_id, $manual_operation);
    WCOS_Stock_Side_Effect_Guard::record_product_stock_write($manual_product);
};
add_action('woocommerce_order_note_added', $manual_callback, 10, 2);

$manual_exception = false;
try {
    (new WCOS_Split_WooCommerce_Adapter())->split(
        wc_get_order($manual_source_id),
        array('child-one' => array($manual_item_id => '1.000000')),
        $manual_operation
    );
} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
    $manual_exception = WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($exception->get_events());
}
remove_action('woocommerce_order_note_added', $manual_callback, 10);

wcos_p2_adapter_assert($manual_injected && $manual_exception, 'After-write fallback did not surface a manual-reconciliation exception.');
wcos_p2_adapter_assert($lease_owned_during_injection, 'After-write evidence was injected after the Split service had already released its source lease.');

$manual_source = wc_get_order($manual_source_id);
$manual_record = WCOS_Operation_Journal::get($manual_source, $manual_operation);
wcos_p2_adapter_assert(is_array($manual_record), 'Manual-reconciliation journal disappeared.');
wcos_p2_adapter_assert('manual_reconciliation' === $manual_record['status'], 'Completed Split was not converted to manual_reconciliation.');
wcos_p2_adapter_assert(null === $manual_record['completed_at'], 'Manual-reconciliation state remained retention-terminal.');
wcos_p2_adapter_assert(false === $manual_record['context']['automatic_compensation_allowed'], 'Manual reconciliation accidentally allowed automatic compensation.');
wcos_p2_adapter_assert(
    'completed' === $manual_record['context']['previous_status'],
    'Manual-reconciliation journal did not preserve that the Split had already completed.'
);
wcos_p2_adapter_assert(WCOS_Operation_Journal::has_manual_reconciliation($manual_source), 'Order-level manual-reconciliation index was not persisted.');

$manual_report = (new WCOS_Split_WooCommerce_Adapter())->preflight($manual_source);
wcos_p2_adapter_assert(empty($manual_report['supported']), 'Preflight allowed a source with unresolved manual reconciliation.');
wcos_p2_adapter_assert('manual_reconciliation_required' === $manual_report['reason'], 'Manual-reconciliation preflight used the wrong rejection reason.');
wcos_p2_adapter_assert(1 === (int) $manual_report['manual_reconciliation_count'], 'Preflight did not report the unresolved incident count.');
wcos_p2_adapter_assert(
    array($manual_operation) === $manual_report['manual_reconciliation_operation_ids'],
    'Preflight did not expose the PII-free operation ID requiring reconciliation.'
);

$retry_rejected = false;
try {
    (new WCOS_Split_WooCommerce_Adapter())->split(
        wc_get_order($manual_source->get_id()),
        array('child-one' => array($manual_item_id => '1.000000')),
        $manual_operation
    );
} catch (WCOS_Split_Preflight_Exception $exception) {
    $retry_rejected = 'manual_reconciliation_required' === $exception->get_reason();
}
wcos_p2_adapter_assert($retry_rejected, 'Retry auto-finalized an operation requiring manual reconciliation.');

$new_operation_rejected = false;
try {
    (new WCOS_Split_WooCommerce_Adapter())->split(
        wc_get_order($manual_source->get_id()),
        array('child-two' => array($manual_item_id => '1.000000')),
        'p2-manual-block-new-' . wp_generate_uuid4()
    );
} catch (WCOS_Split_Preflight_Exception $exception) {
    $new_operation_rejected = 'manual_reconciliation_required' === $exception->get_reason();
}
wcos_p2_adapter_assert($new_operation_rejected, 'A new Split bypassed an unresolved manual-reconciliation incident.');

$now = time();
wcos_p2_adapter_assert(
    !WCOS_Operation_Journal_Retention::is_expired_terminal_record(
        array('status' => 'manual_reconciliation', 'completed_at' => gmdate('c', $now - (400 * DAY_IN_SECONDS))),
        $now
    ),
    'Manual-reconciliation journal became retention-purgeable.'
);

wcos_p2_adapter_assert(
    WCOS_Operation_Journal::mark_manual_reconciled(
        $manual_source,
        $manual_operation,
        array(
            'reconciled_by_user_id' => 1,
            'reconciliation_note' => 'integration-test-explicit-human-resolution',
        )
    ),
    'Explicit manual-reconciled transition failed.'
);
$manual_source = wc_get_order($manual_source->get_id());
$resolved = WCOS_Operation_Journal::get($manual_source, $manual_operation);
wcos_p2_adapter_assert('manual_reconciled' === $resolved['status'], 'Manual reconciliation did not reach its resolved terminal state.');
wcos_p2_adapter_assert(!empty($resolved['completed_at']), 'Resolved manual reconciliation has no terminal timestamp.');
wcos_p2_adapter_assert(!WCOS_Operation_Journal::has_manual_reconciliation($manual_source), 'Resolved incident remained in the order-level blocking index.');
wcos_p2_adapter_assert(
    WCOS_Operation_Journal_Retention::is_expired_terminal_record(
        array('status' => 'manual_reconciled', 'completed_at' => gmdate('c', $now - (100 * DAY_IN_SECONDS))),
        $now
    ),
    'Resolved manual-reconciliation state is not eligible for normal terminal retention.'
);

$resolved_report = (new WCOS_Split_WooCommerce_Adapter())->preflight($manual_source);
wcos_p2_adapter_assert('manual_reconciliation_required' !== $resolved_report['reason'], 'Resolved incident still blocked preflight as unresolved.');

wcos_p2_adapter_cleanup($manual_source->get_id(), $manual_operation);
wp_delete_post($manual_product->get_id(), true);

echo "p2-manual-reconciliation-state-ok\n";
