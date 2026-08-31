<?php

if (!defined('ABSPATH')) { exit(1); }
$phase = isset($args[0]) ? sanitize_key((string) $args[0]) : '';
$manifest = get_option('wcos_bulk_return_partial_local_ui_fixture', array());
if (!is_array($manifest) || empty($manifest['confirm_drift']['child_ids'][0]) || empty($manifest['runtime']['child_ids'][2])) { fwrite(STDERR, "BULK_PARTIAL_UI_MANIFEST_MISSING\n"); exit(2); }
if ('confirm_drift' === $phase) {
	$child = wc_get_order(absint($manifest['confirm_drift']['child_ids'][0]));
	if (!$child instanceof WC_Order || 'trash' === $child->get_status()) { fwrite(STDERR, "BULK_PARTIAL_UI_CONFIRM_CHILD_INVALID\n"); exit(3); }
	$child->set_status('on-hold'); $child->save();
	echo 'BULK_PARTIAL_UI_CONFIRM_DRIFT_READY child=' . $child->get_id() . "\n";
	exit(0);
}
if ('runtime_drift' === $phase) {
	$completed = wc_get_order(absint($manifest['runtime']['child_ids'][0]));
	$current = wc_get_order(absint($manifest['runtime']['child_ids'][2]));
	if (!$completed instanceof WC_Order || 'trash' !== $completed->get_status() || !$current instanceof WC_Order || 'trash' === $current->get_status()) { fwrite(STDERR, "BULK_PARTIAL_UI_RUNTIME_PHASE_INVALID\n"); exit(4); }
	$current->set_status('on-hold'); $current->save();
	echo 'BULK_PARTIAL_UI_RUNTIME_DRIFT_READY completed=' . $completed->get_id() . ' current=' . $current->get_id() . "\n";
	exit(0);
}
fwrite(STDERR, "BULK_PARTIAL_UI_PHASE_INVALID\n"); exit(5);
