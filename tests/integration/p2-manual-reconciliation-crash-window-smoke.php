<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$crash_window_product = wcos_p2_adapter_product('WCOS P2 reconciliation crash window', '8.00', 30);
list($crash_window_source, $crash_window_item_id) = wcos_p2_adapter_order($crash_window_product, 4);
$crash_window_source_id = $crash_window_source->get_id();
$crash_window_operation = 'p2-reconciliation-window-' . wp_generate_uuid4();
$crash_window_adapter = new WCOS_Split_WooCommerce_Adapter();

$crash_window_children = $crash_window_adapter->split(
    $crash_window_source,
    array('child-one' => array($crash_window_item_id => '1.000000')),
    $crash_window_operation
);
wcos_p2_adapter_assert(1 === count($crash_window_children), 'Crash-window fixture did not create one Split child.');

$crash_window_source = wc_get_order($crash_window_source_id);
$crash_window_journal = WCOS_Operation_Journal::get($crash_window_source, $crash_window_operation);
wcos_p2_adapter_assert(is_array($crash_window_journal) && 'completed' === $crash_window_journal['status'], 'Crash-window fixture did not reach completed before blocker injection.');

/*
 * Simulate the exact durability window: the fail-closed blocker is persisted,
 * then the process dies before the journal can transition to
 * manual_reconciliation. The older order-meta index is therefore still absent.
 */
wcos_p2_adapter_assert(
    WCOS_Manual_Reconciliation_Blocker::block($crash_window_source, $crash_window_operation),
    'Unable to persist the first-phase reconciliation blocker.'
);
$crash_window_source = wc_get_order($crash_window_source_id);
wcos_p2_adapter_assert(
    empty($crash_window_source->get_meta(WCOS_Operation_Journal::MANUAL_RECONCILIATION_META_KEY, true)),
    'Crash-window fixture unexpectedly wrote the secondary journal index.'
);
$crash_window_journal = WCOS_Operation_Journal::get($crash_window_source, $crash_window_operation);
wcos_p2_adapter_assert('completed' === $crash_window_journal['status'], 'First-phase blocker unexpectedly mutated the journal.');

$blocked_report = $crash_window_adapter->preflight($crash_window_source);
wcos_p2_adapter_assert(empty($blocked_report['supported']), 'Preflight missed a first-phase reconciliation blocker.');
wcos_p2_adapter_assert('manual_reconciliation_required' === $blocked_report['reason'], 'Crash-window blocker used the wrong rejection reason.');
wcos_p2_adapter_assert(
    in_array($crash_window_operation, $blocked_report['manual_reconciliation_operation_ids'], true),
    'Crash-window blocker did not expose its PII-free operation ID.'
);

/* Finish the interrupted transition and prove a later explicit resolution clears it. */
wcos_p2_adapter_assert(
    WCOS_Operation_Journal::mark_manual_reconciliation(
        $crash_window_source,
        $crash_window_operation,
        array('reason' => 'two-phase-crash-window-test', 'automatic_compensation_allowed' => false)
    ),
    'Unable to finish the interrupted manual-reconciliation journal transition.'
);
wcos_p2_adapter_assert(
    WCOS_Operation_Journal::mark_manual_reconciled(
        wc_get_order($crash_window_source_id),
        $crash_window_operation,
        array('reconciliation_note' => 'two-phase-crash-window-resolved')
    ),
    'Unable to resolve the crash-window reconciliation fixture.'
);

$crash_window_source = wc_get_order($crash_window_source_id);
$resolved_report = $crash_window_adapter->preflight($crash_window_source);
wcos_p2_adapter_assert('manual_reconciliation_required' !== $resolved_report['reason'], 'Resolved crash-window blocker remained active.');
wcos_p2_adapter_assert(!WCOS_Manual_Reconciliation_Blocker::has_active($crash_window_source), 'Resolved crash-window blocker was not lazily cleared by journal revision.');

wcos_p2_adapter_cleanup($crash_window_source_id, $crash_window_operation);
wp_delete_post($crash_window_product->get_id(), true);

echo "p2-manual-reconciliation-crash-window-ok\n";
