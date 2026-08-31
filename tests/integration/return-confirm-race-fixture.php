<?php

if (!defined('ABSPATH')) { exit(1); }

require_once __DIR__ . '/split-status-fixture-authority.php';
WCOS_Test_Split_Status_Fixture_Authority::allow(array('wc-pending'));

$fixture_key = 'wcos_return_confirm_race_fixture';
if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
$user_id = wp_insert_user(array(
	'user_login' => 'wcos_return_confirm_race_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-return-confirm-race-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
if (is_wp_error($user_id)) { fwrite(STDERR, "RETURN_RACE_USER_FAILED\n"); exit(2); }
wp_set_current_user($user_id);

$product = new WC_Product_Simple(); $product->set_name('WCOS Return Confirm Race'); $product->set_regular_price('8.00'); $product->set_price('8.00'); $product->save();
$order = wc_create_order(); $order->set_status('pending'); $order->set_currency('USD');
$item_id = $order->add_product($product, 2); $order->calculate_totals(false); $order->save();
$children = (new WCOS_Mutation_Gateway())->split(
	wc_get_order($order->get_id()),
	array('return-race-child' => array($item_id => '1.000000')),
	'return-race-split-' . wp_generate_uuid4(),
	2
);
if (1 !== count($children)) { fwrite(STDERR, "RETURN_RACE_SPLIT_FAILED\n"); exit(3); }
$child = $children[0];
$controller = new WCOS_Return_Admin_Controller();
$review = $controller->review_request(array(
	'child_order_id' => $child->get_id(),
	'nonce' => wp_create_nonce('wcos_return_order_' . $child->get_id()),
));
update_option($fixture_key, array(
	'user_id' => absint($user_id),
	'product_id' => $product->get_id(),
	'original_id' => $order->get_id(),
	'child_id' => $child->get_id(),
	'review_id' => sanitize_key((string) $review['review_id']),
	'review_token' => (string) $review['review_token'],
), false);
echo 'RETURN_RACE_FIXTURE_READY child=' . $child->get_id() . ' review=' . $review['review_id'] . "\n";
