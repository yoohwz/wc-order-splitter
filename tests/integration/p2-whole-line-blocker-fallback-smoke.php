<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_whole_line_primary_blocker_failure_filter($source_order_id) {
	return static function($pre_option, $option, $default_value) use ($source_order_id) {
		return array(
			'schema_version' => WCOS_Manual_Reconciliation_Blocker::SCHEMA_VERSION,
			'source_order_id' => absint($source_order_id),
			'revision' => 999999,
			'operations' => array(),
		);
	};
}

/*
 * First prove the exact crash window in isolation: destructive source state is
 * durable, primary blocker persistence fails, the fallback is durable, and the
 * process may die before the journal can transition to manual_reconciliation.
 * Preflight must still block from fallback evidence alone.
 */
$window_a = wcos_p2_adapter_product('WCOS whole-line blocker window A', '8.00');
$window_b = wcos_p2_adapter_product('WCOS whole-line blocker window B', '3.00');
list($window_source, $window_a_item, $window_b_item) = wcos_whole_line_runtime_order(
	$window_a,
	2,
	$window_b,
	2,
	'pending'
);
$window_source_id = $window_source->get_id();
$window_operation = 'p2-whole-line-blocker-window-' . wp_generate_uuid4();
$window_fingerprint = hash('sha256', $window_operation);
wcos_p2_adapter_assert(
	WCOS_Operation_Journal::start(
		$window_source,
		$window_operation,
		'split',
		array(
			'execution_policy' => WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			'fully_moved_item_ids' => array($window_a_item),
		),
		$window_fingerprint
	),
	'Unable to start the fallback crash-window journal fixture.'
);

/* Persist the destructive deletion while the journal is still only started. */
wcos_p2_adapter_assert(false !== $window_source->remove_item($window_a_item), 'Unable to stage the fallback crash-window source deletion.');
$window_source->save();
$window_source = wc_get_order($window_source_id);
wcos_p2_adapter_assert(!$window_source->get_item($window_a_item), 'Fallback crash-window source deletion did not persist.');

$window_option_key = WCOS_Manual_Reconciliation_Blocker::KEY_PREFIX . $window_source_id;
$window_meta_key = WCOS_Manual_Reconciliation_Blocker::FALLBACK_META_PREFIX . $window_operation;
$window_primary_failure_filter = wcos_whole_line_primary_blocker_failure_filter($window_source_id);
add_filter('pre_option_' . $window_option_key, $window_primary_failure_filter, 10, 3);
try {
	wcos_p2_adapter_assert(
		WCOS_Manual_Reconciliation_Blocker::block($window_source, $window_operation),
		'Fallback crash-window blocker could not persist after primary option failure.'
	);
} finally {
	remove_filter('pre_option_' . $window_option_key, $window_primary_failure_filter, 10);
}

$window_source = wc_get_order($window_source_id);
wcos_p2_adapter_assert(null === get_option($window_option_key, null), 'Fallback crash-window unexpectedly persisted its primary blocker option.');
$window_incident = $window_source->get_meta($window_meta_key, true);
wcos_p2_adapter_assert(
	is_array($window_incident)
	&& isset($window_incident['operation_id'])
	&& $window_operation === sanitize_key((string) $window_incident['operation_id']),
	'Fallback crash-window order metadata was not durably persisted.'
);
$window_record = WCOS_Operation_Journal::get($window_source, $window_operation);
wcos_p2_adapter_assert(
	is_array($window_record) && 'started' === $window_record['status'],
	'Fallback crash-window journal advanced unexpectedly before the simulated process death.'
);
wcos_p2_adapter_assert(
	empty($window_source->get_meta(WCOS_Operation_Journal::MANUAL_RECONCILIATION_META_KEY, true)),
	'Fallback crash-window unexpectedly wrote the manual-reconciliation journal index.'
);
$window_report = (new WCOS_Split_WooCommerce_Adapter())->preflight($window_source);
wcos_p2_adapter_assert(
	empty($window_report['supported']) && 'manual_reconciliation_required' === $window_report['reason'],
	'Fallback-only crash-window evidence did not keep the destructively modified source fail-closed.'
);
wcos_p2_adapter_assert(
	in_array($window_operation, (array) $window_report['manual_reconciliation_operation_ids'], true),
	'Fallback-only crash-window preflight omitted its PII-free operation ID.'
);

/* Finish the interrupted transition and prove authoritative cleanup. */
wcos_p2_adapter_assert(
	WCOS_Operation_Journal::mark_manual_reconciliation(
		$window_source,
		$window_operation,
		array(
			'reason' => 'primary-blocker-option-failure-crash-window',
			'execution_policy' => WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			'fully_moved_item_ids' => array($window_a_item),
			'automatic_compensation_allowed' => false,
		)
	),
	'Unable to finish the fallback crash-window manual-reconciliation transition.'
);
wcos_p2_adapter_assert(
	WCOS_Operation_Journal::mark_manual_reconciled(
		wc_get_order($window_source_id),
		$window_operation,
		array('reconciliation_note' => 'fallback-crash-window-test-cleanup')
	),
	'Unable to resolve the fallback crash-window fixture.'
);
$window_source = wc_get_order($window_source_id);
wcos_p2_adapter_assert(
	!WCOS_Manual_Reconciliation_Blocker::contains_operation($window_source_id, $window_operation),
	'Resolved fallback crash-window blocker remained persisted.'
);
wcos_p2_adapter_assert(empty($window_source->get_meta($window_meta_key, true)), 'Resolved fallback crash-window metadata remained persisted.');
WCOS_Operation_Journal::delete($window_source, $window_operation);
$window_source->delete(true);
wp_delete_post($window_a->get_id(), true);
wp_delete_post($window_b->get_id(), true);

/*
 * Now exercise the actual whole-line service recovery path: force primary
 * blocker-option persistence failure after a destructive source deletion has
 * already reached durable storage. The source must remain fail-closed through
 * the independent order-meta fallback while the journal also transitions.
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
$fallback_meta_key = WCOS_Manual_Reconciliation_Blocker::FALLBACK_META_PREFIX . $fallback_operation;
$fallback_injected = false;

$primary_failure_filter = wcos_whole_line_primary_blocker_failure_filter($fallback_source_id);
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
$fallback_incident = $fallback_source->get_meta($fallback_meta_key, true);
wcos_p2_adapter_assert(
	is_array($fallback_incident)
	&& isset($fallback_incident['operation_id'])
	&& $fallback_operation === sanitize_key((string) $fallback_incident['operation_id']),
	'Order-meta fallback incident was not durably persisted for the destructive operation.'
);

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
	'Split preflight did not reject a source protected by fallback blocker evidence.'
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
wcos_p2_adapter_assert(
	empty($fallback_source->get_meta($fallback_meta_key, true)),
	'Resolved fallback order metadata remained persisted after authoritative reconciliation.'
);

foreach (wcos_p2_adapter_children($fallback_source_id, $fallback_operation) as $fallback_child) {
	$fallback_child->delete(true);
}
WCOS_Operation_Journal::delete($fallback_source, $fallback_operation);
$fallback_source->delete(true);
wp_delete_post($fallback_a->get_id(), true);
wp_delete_post($fallback_b->get_id(), true);

echo "p2-whole-line-blocker-fallback-ok\n";
