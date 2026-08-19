<?php

if (!defined('ABSPATH')) {
	exit(1);
}

/*
 * Fault-inject primary blocker-option persistence failure after a destructive
 * whole-line source deletion has already reached durable storage. The source
 * must remain fail-closed through the independent order-meta fallback.
 */
$fallback_a = wcos_p2_adapter_product('WCOS whole-line blocker fallback A', '8.00');
$fallback_b = wcos_p2_adapter_product('WCOS whole-line blocker fallback B', '3.00');
list($fallback_source, $fallback_a_item, $fallback_b_item) = wcos_whole_line_runtime_order(
	$fallback_a,
	2,
	$fallback_b,
	2,
	'pending'
);
$fallback_source_id = $fallback_source->get_id();
$fallback_operation = 'p2-whole-line-blocker-fallback-' . wp_generate_uuid4();
$fallback_option_key = WCOS_Manual_Reconciliation_Blocker::KEY_PREFIX . $fallback_source_id;
$fallback_injected = false;

/*
 * Force every primary get_option() read to observe a synthetic record that does
 * not exist in the database. The primary CAS can therefore never match/update
 * a persisted row and must exhaust its retries before fallback storage is used.
 */
$primary_failure_filter = static function($pre_option, $option, $default_value) use ($fallback_source_id) {
	return array(
		'schema_version' => WCOS_Manual_Reconciliation_Blocker::SCHEMA_VERSION,
		'source_order_id' => $fallback_source_id,
		'revision' => 999999,
		'operations' => array(),
	);
};
add_filter('pre_option_' . $fallback_option_key, $primary_failure_filter, 10, 3);

$fallback_callback = static function($stage, $mutating_source, $children) use (&$fallback_injected) {
	if (!$fallback_injected && 'before_source_save' === $stage && $mutating_source instanceof WC_Order) {
		$fallback_injected = true;

		/* Persist the destructive source deletion first. */
		$mutating_source->save();

		/* Corrupt the child so persisted source + child evidence is ambiguous. */
		$child = reset($children);
		if ($child instanceof WC_Order) {
			$line = current($child->get_items('line_item'));
			if ($line instanceof WC_Order_Item_Product) {
				$line->set_quantity(((float) $line->get_quantity()) + 1);
				$line->save();
			}
		}

		throw new RuntimeException('Injected whole-line blocker primary persistence failure.');
	}
};
add_action('wcos_split_mutation_checkpoint', $fallback_callback, 10, 4);

$fallback_failed = false;
try {
	wcos_whole_line_runtime_call(
		$fallback_source,
		array('child-1' => array($fallback_a_item => '2.000000')),
		$fallback_operation
	);
} catch (RuntimeException $exception) {
	$fallback_failed = true;
} finally {
	remove_action('wcos_split_mutation_checkpoint', $fallback_callback, 10);
	remove_filter('pre_option_' . $fallback_option_key, $primary_failure_filter, 10);
}

wcos_p2_adapter_assert($fallback_injected && $fallback_failed, 'Primary blocker persistence fault fixture did not reach the destructive failure path.');
$fallback_source = wc_get_order($fallback_source_id);
wcos_p2_adapter_assert($fallback_source instanceof WC_Order, 'Fallback fixture source order disappeared.');
wcos_p2_adapter_assert(!$fallback_source->get_item($fallback_a_item), 'Fallback fixture did not persist the destructive source-item deletion.');
wcos_p2_adapter_assert(null === get_option($fallback_option_key, null), 'Primary blocker option unexpectedly persisted despite the injected CAS failure.');

$fallback_record = WCOS_Operation_Journal::get($fallback_source, $fallback_operation);
wcos_p2_adapter_assert(
	is_array($fallback_record) && 'manual_reconciliation' === $fallback_record['status'],
	'Fallback blocker fixture did not durably enter manual reconciliation.'
);
wcos_p2_adapter_assert(
	WCOS_Manual_Reconciliation_Blocker::contains_operation($fallback_source_id, $fallback_operation),
	'Fallback blocker was not visible through the unified retention/preflight authority.'
);
wcos_p2_adapter_assert(
	WCOS_Manual_Reconciliation_Blocker::has_active($fallback_source),
	'Fallback blocker did not keep the destructively modified source fail-closed.'
);

$fallback_report = (new WCOS_Split_WooCommerce_Adapter())->preflight($fallback_source);
wcos_p2_adapter_assert(
	empty($fallback_report['supported']) && 'manual_reconciliation_required' === $fallback_report['reason'],
	'Split preflight did not reject a source protected only by the blocker fallback store.'
);
wcos_p2_adapter_assert(
	in_array($fallback_operation, (array) $fallback_report['manual_reconciliation_operation_ids'], true),
	'Fallback blocker operation ID was not exposed in the PII-free preflight evidence.'
);

/* Resolution must clear the fallback only after a newer journal revision proves it. */
wcos_p2_adapter_assert(
	WCOS_Operation_Journal::mark_manual_reconciled(
		$fallback_source,
		$fallback_operation,
		array('reconciliation_note' => 'whole-line-blocker-fallback-test-cleanup')
	),
	'Unable to resolve the fallback blocker fixture.'
);
$fallback_source = wc_get_order($fallback_source_id);
wcos_p2_adapter_assert(
	!WCOS_Manual_Reconciliation_Blocker::contains_operation($fallback_source_id, $fallback_operation),
	'Resolved fallback blocker remained persisted after authoritative reconciliation.'
);
wcos_p2_adapter_assert(
	!WCOS_Manual_Reconciliation_Blocker::has_active($fallback_source),
	'Resolved fallback blocker remained active.'
);

foreach (wcos_p2_adapter_children($fallback_source_id, $fallback_operation) as $fallback_child) {
	$fallback_child->delete(true);
}
WCOS_Operation_Journal::delete($fallback_source, $fallback_operation);
$fallback_source->delete(true);
wp_delete_post($fallback_a->get_id(), true);
wp_delete_post($fallback_b->get_id(), true);

echo "p2-whole-line-blocker-fallback-ok\n";
