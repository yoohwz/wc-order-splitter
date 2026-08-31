<?php

if (!defined('ABSPATH')) { exit(1); }

require_once __DIR__ . '/split-status-fixture-authority.php';
WCOS_Test_Split_Status_Fixture_Authority::allow(array('wc-pending'));
if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
$key = 'wcos_bulk_return_confirm_race_fixture';
$user_id = wp_insert_user(array('user_login' => 'wcos_bulk_confirm_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(24, true), 'user_email' => 'bulk-confirm-' . wp_generate_uuid4() . '@example.test', 'role' => 'administrator'));
if (is_wp_error($user_id)) { fwrite(STDERR, "BULK_CONFIRM_USER_FAILED\n"); exit(2); }
wp_set_current_user($user_id);
$product = new WC_Product_Simple(); $product->set_name('WCOS Bulk Confirm Race'); $product->set_regular_price('9.00'); $product->set_price('9.00'); $product->save();
$order = wc_create_order(); $order->set_status('pending'); $order->set_currency('USD'); $item_id = $order->add_product($product, 2); $order->calculate_totals(false); $order->save();
$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($order->get_id()), array('bulk-confirm-child' => array($item_id => '1.000000')), 'bulk-confirm-split-' . wp_generate_uuid4(), 2);
if (1 !== count($children)) { fwrite(STDERR, "BULK_CONFIRM_SPLIT_FAILED\n"); exit(3); }
$review = WCOS_Bulk_Return_Review_Store::create(array($children[0]->get_id()), $user_id);
update_option($key, array('user_id' => absint($user_id), 'product_id' => $product->get_id(), 'original_id' => $order->get_id(), 'child_id' => $children[0]->get_id(), 'review_id' => $review['review_id'], 'review_token' => $review['review_token']), false);
echo "BULK_CONFIRM_FIXTURE_READY\n";
