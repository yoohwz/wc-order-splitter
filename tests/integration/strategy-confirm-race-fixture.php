<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$fixture_key = 'wcos_strategy_confirm_race_fixture';
$previous = get_option('order_splitter_status_allowed', array('wc-processing'));
update_option('order_splitter_status_allowed', array('wc-pending'));

if (!function_exists('wp_delete_user')) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}

$user_id = wp_insert_user(array(
	'user_login' => 'wcos_strategy_confirm_race_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-strategy-confirm-race-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
if (is_wp_error($user_id)) {
	fwrite(STDERR, "RACE_FIXTURE_USER_FAILED\n");
	exit(2);
}
wp_set_current_user($user_id);

$suffix = strtolower(wp_generate_password(6, false, false));
$keep_term = wp_insert_term('WCOS Race Keep ' . $suffix, 'product_cat');
$move_term = wp_insert_term('WCOS Race Move ' . $suffix, 'product_cat');
if (is_wp_error($keep_term) || is_wp_error($move_term)) {
	fwrite(STDERR, "RACE_FIXTURE_TERM_FAILED\n");
	exit(3);
}

$keep_product = new WC_Product_Simple();
$keep_product->set_name('WCOS Race Keep Product');
$keep_product->set_regular_price('10.00');
$keep_product->save();
$move_product = new WC_Product_Simple();
$move_product->set_name('WCOS Race Move Product');
$move_product->set_regular_price('7.00');
$move_product->save();
wp_set_object_terms($keep_product->get_id(), array(absint($keep_term['term_id'])), 'product_cat');
wp_set_object_terms($move_product->get_id(), array(absint($move_term['term_id'])), 'product_cat');

$order = wc_create_order();
$order->set_status('pending');
$order->set_currency('USD');
$order->add_product($keep_product, 1);
$order->add_product($move_product, 1);
$order->calculate_totals(false);
$order->save();

$adapter = new WCOS_Split_Strategy_WooCommerce_Adapter();
$review = $adapter->review($order, WCOS_Split_Strategy_Gates::CATEGORY);
if (empty($review['supported'])) {
	fwrite(STDERR, "RACE_FIXTURE_REVIEW_UNSUPPORTED\n");
	exit(4);
}
$stored = WCOS_Split_Strategy_Review_Store::create(
	$order,
	WCOS_Split_Strategy_Gates::CATEGORY,
	$review,
	$user_id
);

$fixture = array(
	'order_id' => $order->get_id(),
	'user_id' => absint($user_id),
	'review_id' => sanitize_key($stored['review_id']),
	'review_token' => (string) $stored['review_token'],
	'source_bucket_key' => 'category-' . absint($keep_term['term_id']),
	'keep_term_id' => absint($keep_term['term_id']),
	'move_term_id' => absint($move_term['term_id']),
	'keep_product_id' => $keep_product->get_id(),
	'move_product_id' => $move_product->get_id(),
	'previous_allowed_statuses' => $previous,
);
update_option($fixture_key, $fixture, false);

echo 'RACE_FIXTURE_READY order=' . $order->get_id() . ' review=' . $stored['review_id'] . "\n";
