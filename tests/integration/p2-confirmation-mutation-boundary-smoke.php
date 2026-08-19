<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$boundary_previous_user = get_current_user_id();
$boundary_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$boundary_user_id = wp_insert_user(array(
    'user_login' => 'wcos_confirmation_boundary_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24, true),
    'user_email' => 'wcos-confirmation-boundary-' . wp_generate_uuid4() . '@example.test',
    'role' => 'administrator',
));
wcos_p2_adapter_assert(!is_wp_error($boundary_user_id), 'Unable to create confirmation-boundary user.');

$split_product = wcos_p2_adapter_product('WCOS Split verify boundary', '10.00');
list($split_source, $split_item_id) = wcos_p2_adapter_order($split_product, 3, 'pending');
$split_source_id = $split_source->get_id();
$split_operation = '';

$duplicate_product = wcos_p2_adapter_product('WCOS Duplicate verify boundary', '11.00');
list($duplicate_source, $duplicate_item_id) = wcos_p2_adapter_order($duplicate_product, 2, 'pending');
$duplicate_source_id = $duplicate_source->get_id();
$duplicate_operation = '';

try {
    update_option('order_splitter_status_allowed', array('wc-pending'));
    wp_set_current_user($boundary_user_id);

    /* Split: verify succeeds, source changes, adapter must still fail before journal/child persistence. */
    $split_plan = array('child-1' => array($split_item_id => '1.000000'));
    $split_preflight = (new WCOS_Split_WooCommerce_Adapter())->preflight($split_source);
    $split_confirmation = WCOS_Split_Confirmation_Store::create(
        $split_source,
        $split_plan,
        $split_preflight,
        $boundary_user_id
    );
    $split_operation = $split_confirmation['operation_id'];
    $split_verified = WCOS_Split_Confirmation_Store::verify(
        wc_get_order($split_source_id),
        $split_operation,
        $split_confirmation['confirmation_token'],
        $boundary_user_id
    );
    wcos_p2_adapter_assert('confirmation' === $split_verified['replay_authority'], 'Split verify-boundary fixture did not use transient confirmation authority.');
    wcos_p2_adapter_assert(
        '' !== WCOS_Split_Confirmation_Store::verified_source_signature($split_operation),
        'Split verify did not publish request-local source authority.'
    );

    $changed_split = wc_get_order($split_source_id);
    $changed_split->set_customer_note('changed-after-split-verify');
    $changed_split->save();

    $split_rejected = false;
    try {
        (new WCOS_Mutation_Gateway())->split(
            wc_get_order($split_source_id),
            $split_verified['plan'],
            $split_operation,
            $split_verified['price_precision']
        );
    } catch (WCOS_Split_Preflight_Exception $exception) {
        $split_rejected = 'source_changed_after_confirmation' === $exception->get_reason();
    }
    wcos_p2_adapter_assert($split_rejected, 'Split crossed a source edit after confirmation verify.');
    wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($split_source_id), $split_operation), 'Split verify-boundary rejection created a journal.');
    wcos_p2_adapter_assert(empty(wcos_p2_adapter_children($split_source_id, $split_operation)), 'Split verify-boundary rejection created a child.');

    /* Duplicate: same request-local authority must block before journal/target persistence. */
    $duplicate_preflight = (new WCOS_Duplicate_WooCommerce_Adapter())->preflight($duplicate_source);
    $duplicate_confirmation = WCOS_Duplicate_Confirmation_Store::create(
        $duplicate_source,
        $duplicate_preflight,
        $boundary_user_id
    );
    $duplicate_operation = $duplicate_confirmation['operation_id'];
    $duplicate_verified = WCOS_Duplicate_Confirmation_Store::verify(
        wc_get_order($duplicate_source_id),
        $duplicate_operation,
        $duplicate_confirmation['confirmation_token'],
        $boundary_user_id
    );
    wcos_p2_adapter_assert('confirmation' === $duplicate_verified['replay_authority'], 'Duplicate verify-boundary fixture did not use transient confirmation authority.');
    wcos_p2_adapter_assert(
        '' !== WCOS_Duplicate_Confirmation_Store::verified_source_signature($duplicate_operation),
        'Duplicate verify did not publish request-local source authority.'
    );

    $changed_duplicate = wc_get_order($duplicate_source_id);
    $changed_duplicate->set_customer_note('changed-after-duplicate-verify');
    $changed_duplicate->save();

    $duplicate_rejected = false;
    try {
        (new WCOS_Duplicate_WooCommerce_Adapter())->duplicate(
            wc_get_order($duplicate_source_id),
            $duplicate_operation,
            $duplicate_verified['price_precision']
        );
    } catch (WCOS_Duplicate_Preflight_Exception $exception) {
        $duplicate_rejected = 'source_changed_after_confirmation' === $exception->get_reason();
    }
    wcos_p2_adapter_assert($duplicate_rejected, 'Duplicate crossed a source edit after confirmation verify.');
    wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($duplicate_source_id), $duplicate_operation), 'Duplicate verify-boundary rejection created a journal.');
    wcos_p2_adapter_assert(empty(wcos_duplicate_targets($duplicate_source_id, $duplicate_operation)), 'Duplicate verify-boundary rejection created a target.');
} finally {
    if ($split_operation) {
        WCOS_Split_Confirmation_Store::delete($split_operation);
    }
    if ($duplicate_operation) {
        WCOS_Duplicate_Confirmation_Store::delete($duplicate_operation);
    }
    $split_source = wc_get_order($split_source_id);
    if ($split_source) {
        $split_source->delete(true);
    }
    $duplicate_source = wc_get_order($duplicate_source_id);
    if ($duplicate_source) {
        $duplicate_source->delete(true);
    }
    wp_delete_post($split_product->get_id(), true);
    wp_delete_post($duplicate_product->get_id(), true);
    update_option('order_splitter_status_allowed', $boundary_allowed_statuses);
    wp_set_current_user($boundary_previous_user);
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    wp_delete_user($boundary_user_id);
}

echo "p2-confirmation-mutation-boundary-ok\n";
