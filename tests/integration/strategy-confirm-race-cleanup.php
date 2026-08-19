<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$fixture_key = 'wcos_strategy_confirm_race_fixture';
$fixture = get_option($fixture_key, array());
if (!is_array($fixture) || empty($fixture)) {
	echo "RACE_FIXTURE_ALREADY_CLEAN\n";
	exit(0);
}

if (!empty($fixture['review_id'])) {
	WCOS_Split_Strategy_Review_Store::delete($fixture['review_id']);
}

$order = !empty($fixture['order_id']) ? wc_get_order(absint($fixture['order_id'])) : false;
if ($order instanceof WC_Order) {
	$order->delete(true);
}
foreach (array('keep_product_id', 'move_product_id') as $product_key) {
	if (!empty($fixture[$product_key])) {
		wp_delete_post(absint($fixture[$product_key]), true);
	}
}
foreach (array('keep_term_id', 'move_term_id') as $term_key) {
	if (!empty($fixture[$term_key])) {
		wp_delete_term(absint($fixture[$term_key]), 'product_cat');
	}
}
if (array_key_exists('previous_allowed_statuses', $fixture)) {
	update_option('order_splitter_status_allowed', $fixture['previous_allowed_statuses']);
}
if (!function_exists('wp_delete_user')) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
if (!empty($fixture['user_id'])) {
	wp_delete_user(absint($fixture['user_id']));
}
delete_option($fixture_key);

echo "RACE_FIXTURE_CLEAN\n";
