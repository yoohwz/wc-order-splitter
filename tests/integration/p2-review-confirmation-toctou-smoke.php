<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$review_user_id = wp_insert_user(array(
    'user_login' => 'wcos_p2_review_race_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24, true),
    'user_email' => 'wcos-p2-review-race-' . wp_generate_uuid4() . '@example.test',
    'role' => 'administrator',
));
wcos_p2_adapter_assert(!is_wp_error($review_user_id), 'Unable to create review-race test user.');

$review_product = wcos_p2_adapter_product('WCOS P2 review confirmation race', '10.00');
list($review_source, $review_item_id) = wcos_p2_adapter_order($review_product, 5);
$review_source_id = $review_source->get_id();
$review_adapter = new WCOS_Split_WooCommerce_Adapter();
$review_preflight = $review_adapter->preflight($review_source);
wcos_p2_adapter_assert(!empty($review_preflight['supported']), 'Review-race fixture failed initial preflight.');
wcos_p2_adapter_assert(
    !empty($review_preflight['source_signature']),
    'PII-free reviewed source signature was not returned by preflight.'
);
$review_plan = array('child-1' => array($review_item_id => '1.000000'));

/*
 * Simulate a concurrent editor after server preflight/parser but before the
 * confirmation store creates its operation identity. Keep the original order
 * object stale exactly as the review controller would during this window.
 */
$concurrent_source = wc_get_order($review_source_id);
$concurrent_item = $concurrent_source->get_item($review_item_id);
$concurrent_item->set_quantity(6);
$concurrent_item->save();

$race_rejected = false;
try {
    WCOS_Split_Confirmation_Store::create(
        $review_source,
        $review_plan,
        $review_preflight,
        $review_user_id
    );
} catch (WCOS_Split_Confirmation_Exception $exception) {
    $race_rejected = 'source_changed' === $exception->get_reason();
}
wcos_p2_adapter_assert(
    $race_rejected,
    'Confirmation token was issued after the source changed behind the reviewed plan.'
);

$review_source = wc_get_order($review_source_id);
$review_source->delete(true);
wp_delete_post($review_product->get_id(), true);
wp_delete_user($review_user_id);

echo "p2-review-confirmation-toctou-ok\n";
