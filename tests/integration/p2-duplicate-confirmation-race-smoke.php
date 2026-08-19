<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$race_previous_user = get_current_user_id();
$race_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$race_user_id = wp_insert_user(array(
    'user_login' => 'wcos_duplicate_race_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24, true),
    'user_email' => 'wcos-duplicate-race-' . wp_generate_uuid4() . '@example.test',
    'role' => 'administrator',
));
wcos_p2_adapter_assert(!is_wp_error($race_user_id), 'Unable to create Duplicate source-race test user.');

$race_product = wcos_p2_adapter_product('WCOS Duplicate source race', '9.00');
list($race_source, $race_item_id) = wcos_p2_adapter_order($race_product, 3, 'pending');
$race_source_id = $race_source->get_id();
$race_adapter = new WCOS_Duplicate_WooCommerce_Adapter();
$race_controller = new WCOS_Duplicate_Admin_Controller();
$race_operations = array();

try {
    update_option('order_splitter_status_allowed', array('wc-pending'));
    wp_set_current_user($race_user_id);

    /* Preflight -> confirmation issuance must fail if the persisted source changes. */
    $preflight = $race_adapter->preflight($race_source);
    $changed = wc_get_order($race_source_id);
    $changed_item = $changed->get_item($race_item_id);
    $changed_item->set_quantity(4);
    $changed_item->save();

    $create_rejected = false;
    try {
        WCOS_Duplicate_Confirmation_Store::create($race_source, $preflight, $race_user_id);
    } catch (WCOS_Duplicate_Confirmation_Exception $exception) {
        $create_rejected = 'source_changed' === $exception->get_reason();
    }
    wcos_p2_adapter_assert($create_rejected, 'Duplicate confirmation was issued after the source changed behind preflight.');

    /* Restore a clean fixture, review through transport, then edit before Execute. */
    $restored = wc_get_order($race_source_id);
    $restored_item = $restored->get_item($race_item_id);
    $restored_item->set_quantity(3);
    $restored_item->save();
    $restored = wc_get_order($race_source_id);
    $restored->calculate_totals(false);
    $restored->save();

    $nonce = wp_create_nonce('wcos_duplicate_order_' . $race_source_id);
    $review = $race_controller->review_request(array('order_id' => $race_source_id, 'nonce' => $nonce));
    $race_operations[] = $review['operation_id'];
    $after_review = wc_get_order($race_source_id);
    $after_review->set_customer_note('changed-after-duplicate-review');
    $after_review->save();

    $execute_rejected = false;
    try {
        $race_controller->execute_request(array(
            'order_id' => $race_source_id,
            'nonce' => $nonce,
            'operation_id' => $review['operation_id'],
            'confirmation_token' => $review['confirmation_token'],
        ));
    } catch (WCOS_Duplicate_Transport_Exception $exception) {
        $execute_rejected = 'confirmation_source_changed' === $exception->get_error_code()
            && 409 === $exception->get_http_status();
    }
    wcos_p2_adapter_assert($execute_rejected, 'Duplicate Execute accepted a source changed after Review.');
    wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($race_source_id), $review['operation_id']), 'Source-changed Duplicate Execute created a mutation journal.');
    wcos_p2_adapter_assert(empty(wcos_duplicate_targets($race_source_id, $review['operation_id'])), 'Source-changed Duplicate Execute created a target.');
} finally {
    foreach ($race_operations as $operation_id) {
        WCOS_Duplicate_Confirmation_Store::delete($operation_id);
    }
    update_option('order_splitter_status_allowed', $race_allowed_statuses);
    wp_set_current_user($race_previous_user);
    $source = wc_get_order($race_source_id);
    if ($source) {
        $source->delete(true);
    }
    wp_delete_post($race_product->get_id(), true);
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    wp_delete_user($race_user_id);
}

echo "p2-duplicate-confirmation-race-ok\n";
