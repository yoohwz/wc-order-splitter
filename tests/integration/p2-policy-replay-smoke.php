<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$policy_user_id = wp_insert_user(array(
    'user_login' => 'wcos_p2_policy_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24, true),
    'user_email' => 'wcos-p2-policy-' . wp_generate_uuid4() . '@example.test',
    'role' => 'administrator',
));
wcos_p2_adapter_assert(!is_wp_error($policy_user_id), 'Unable to create durable-policy test user.');

$policy_product = wcos_p2_adapter_product('WCOS P2 durable policy authority', '5.00');
list($policy_source, $policy_item_id) = wcos_p2_adapter_order($policy_product, 3);
$policy_source_id = $policy_source->get_id();
$policy_operation = wp_generate_uuid4();
$policy_plan = array('child-1' => array($policy_item_id => '1.000000'));
$policy_quantity_authority = WCOS_Manual_Split_Quantity_Authority::create($policy_source);
$policy_fingerprint = WCOS_Mutation_Fingerprint::create(
    'split',
    $policy_source_id,
    array('plan' => $policy_plan, 'policy-test' => true)
);

wcos_p2_adapter_assert(
    WCOS_Operation_Journal::start(
        $policy_source,
        $policy_operation,
        'split',
		array('plan' => $policy_plan, 'manual_quantity_authority' => $policy_quantity_authority),
        $policy_fingerprint
    ),
    'Unable to start durable-policy journal fixture.'
);

$policy_source = wc_get_order($policy_source_id);
$policy_record = WCOS_Operation_Journal::get($policy_source, $policy_operation);
wcos_p2_adapter_assert(
    isset($policy_record['context']['policy_version'])
        && (int) $policy_record['context']['policy_version'] === (int) WCOS_Split_Preflight::POLICY_VERSION,
    'Split journal did not capture the current preflight policy version.'
);
wcos_p2_adapter_assert(
    array_key_exists('price_precision', $policy_record['context']),
    'Split journal did not retain price precision alongside policy authority.'
);

$policy_replay = WCOS_Split_Confirmation_Store::verify(
    $policy_source,
    $policy_operation,
    '',
    $policy_user_id
);
wcos_p2_adapter_assert('journal' === $policy_replay['replay_authority'], 'Durable policy fixture did not replay from journal authority.');
wcos_p2_adapter_assert(
    (int) WCOS_Split_Preflight::POLICY_VERSION === (int) $policy_replay['policy_version'],
    'Durable replay did not return its bound policy version.'
);

/* Journal mutation APIs must not be able to rewrite the captured safety policy. */
wcos_p2_adapter_assert(
    false === WCOS_Operation_Journal::checkpoint(
        $policy_source,
        $policy_operation,
        'policy_tamper_attempt',
        array('policy_version' => WCOS_Split_Preflight::POLICY_VERSION + 1)
    ),
    'Journal checkpoint rewrote immutable Split policy authority.'
);
$policy_record = WCOS_Operation_Journal::get(wc_get_order($policy_source_id), $policy_operation);
wcos_p2_adapter_assert(
    (int) WCOS_Split_Preflight::POLICY_VERSION === (int) $policy_record['context']['policy_version'],
    'Immutable Split policy version changed after rejected checkpoint.'
);

/* Simulate a corrupted/legacy durable record whose policy no longer matches current code. */
$journal_key = 'wcos_mutation_op_' . hash('sha256', absint($policy_source_id) . '|' . sanitize_key($policy_operation));
$corrupt_record = $policy_record;
$corrupt_record['context']['policy_version'] = WCOS_Split_Preflight::POLICY_VERSION + 1;
update_option($journal_key, $corrupt_record, false);
wp_cache_delete($journal_key, 'options');

$policy_changed = false;
try {
    WCOS_Split_Confirmation_Store::verify(
        wc_get_order($policy_source_id),
        $policy_operation,
        '',
        $policy_user_id
    );
} catch (WCOS_Split_Confirmation_Exception $exception) {
    $policy_changed = 'policy_changed' === $exception->get_reason();
}
wcos_p2_adapter_assert($policy_changed, 'Durable replay crossed a changed Split safety policy.');

update_option($journal_key, $policy_record, false);
wp_cache_delete($journal_key, 'options');

$legacy_record = $policy_record;
unset($legacy_record['context']['manual_quantity_authority']);
update_option($journal_key, $legacy_record, false);
wp_cache_delete($journal_key, 'options');
$legacy_authority_rejected = false;
try {
	WCOS_Split_Confirmation_Store::verify(
		wc_get_order($policy_source_id),
		$policy_operation,
		'',
		$policy_user_id
	);
} catch (WCOS_Split_Confirmation_Exception $exception) {
	$legacy_authority_rejected = 'quantity_authority_incomplete' === $exception->get_reason();
}
wcos_p2_adapter_assert($legacy_authority_rejected, 'Durable Manual Split replay accepted a journal without quantity-step authority.');

update_option($journal_key, $policy_record, false);
wp_cache_delete($journal_key, 'options');
WCOS_Operation_Journal::delete(wc_get_order($policy_source_id), $policy_operation);
$policy_source = wc_get_order($policy_source_id);
if ($policy_source) {
    $policy_source->delete(true);
}
wp_delete_post($policy_product->get_id(), true);
wp_delete_user($policy_user_id);

echo "p2-durable-policy-binding-ok\n";
