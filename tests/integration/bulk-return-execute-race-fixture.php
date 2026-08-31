<?php

if (!defined('ABSPATH')) { exit(1); }

require_once __DIR__ . '/split-status-fixture-authority.php';
WCOS_Test_Split_Status_Fixture_Authority::allow(array('wc-processing'));
$key = 'wcos_bulk_return_execute_race_fixture';
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
if (empty($admins)) { fwrite(STDERR, "BULK_EXECUTE_ADMIN_MISSING\n"); exit(2); }
$user_id = absint($admins[0]); wp_set_current_user($user_id);
$product = new WC_Product_Simple(); $product->set_name('WCOS Bulk Execute Race'); $product->set_regular_price('11.00'); $product->set_price('11.00'); $product->set_manage_stock(true); $product->set_stock_quantity(40); $product->save();
$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD'); $item_id = $original->add_product($product, 2); $original->calculate_totals(false); $original->save(); $original->update_status('processing');
$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), array('bulk-execute-race-child' => array($item_id => '1.000000')), 'bulk-execute-race-split-' . wp_generate_uuid4(), 2);
if (1 !== count($children)) { fwrite(STDERR, "BULK_EXECUTE_SPLIT_FAILED\n"); exit(3); }
$child_id = $children[0]->get_id(); $batches = array();
for ($index = 0; $index < 2; $index++) {
	$review = WCOS_Bulk_Return_Review_Store::create(array($child_id), $user_id);
	$batches[] = WCOS_Bulk_Return_Confirmation_Store::create($review['review_id'], $review['review_token'], $user_id);
}
update_option($key, array(
	'user_id' => $user_id,
	'product_id' => $product->get_id(),
	'original_id' => $original->get_id(),
	'child_id' => $child_id,
	'product_stock_before' => WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6),
	'batches' => $batches,
), false);
echo "BULK_EXECUTE_FIXTURE_READY\n";
