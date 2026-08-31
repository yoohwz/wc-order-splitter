<?php

if (!defined('ABSPATH')) { exit(1); }

require_once __DIR__ . '/split-status-fixture-authority.php';
WCOS_Test_Split_Status_Fixture_Authority::allow(array('wc-processing'));
$key = 'wcos_bulk_return_partial_local_ui_fixture';
if (get_option($key, false)) { fwrite(STDERR, "BULK_PARTIAL_UI_FIXTURE_EXISTS\n"); exit(2); }
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
if (empty($admins)) { fwrite(STDERR, "BULK_PARTIAL_UI_ADMIN_MISSING\n"); exit(3); }
$user_id = absint($admins[0]); wp_set_current_user($user_id);
$missing = '__wcos_bulk_partial_ui_missing__';
$allowed_before = get_option('order_splitter_status_allowed', $missing);
update_option('order_splitter_status_allowed', array('wc-processing'));

$product = new WC_Product_Simple();
$product->set_name('WOS-COMPAT-004 UI Fixture'); $product->set_regular_price('11.00'); $product->set_price('11.00');
$product->set_manage_stock(true); $product->set_stock_quantity(100); $product->set_backorders('yes'); $product->save();

$create = static function($label, $child_count) use ($product) {
	$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD');
	$original->set_billing_first_name('WOS-COMPAT-004'); $original->set_billing_last_name($label);
	$item_id = $original->add_product($product, $child_count + 1); $original->calculate_totals(false); $original->save(); $original->update_status('processing');
	$plan = array();
	for ($index = 0; $index < $child_count; $index++) { $plan['compat-004-' . sanitize_key($label) . '-' . $index] = array($item_id => '1.000000'); }
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), $plan, 'compat-004-ui-' . wp_generate_uuid4(), 2);
	if ($child_count !== count($children)) { throw new RuntimeException('BULK_PARTIAL_UI_SPLIT_FAILED_' . $label); }
	return array('original_id' => $original->get_id(), 'child_ids' => array_values(array_map(static function($child) { return $child->get_id(); }, $children)));
};

try {
	$mixed = $create('Mixed', 2);
	$mixed_skipped = wc_get_order($mixed['child_ids'][1]); $mixed_skipped->set_status('cancelled'); $mixed_skipped->save();
	$confirm_drift = $create('Confirm Drift', 1);
	$runtime = $create('Runtime Stop', 4);
	$runtime_skipped = wc_get_order($runtime['child_ids'][1]); $runtime_skipped->set_status('cancelled'); $runtime_skipped->save();
	$order_ids = array_merge(array($mixed['original_id']), $mixed['child_ids'], array($confirm_drift['original_id']), $confirm_drift['child_ids'], array($runtime['original_id']), $runtime['child_ids']);
	$reduced_units = 0;
	foreach ($order_ids as $order_id) {
		$order = wc_get_order($order_id);
		foreach ($order instanceof WC_Order ? $order->get_items('line_item') : array() as $item) { $reduced_units += WCOS_Decimal::to_units($item->get_meta('_reduced_stock', true) ?: '0', 6); }
	}
	$manifest = array(
		'user_id' => $user_id,
		'product_id' => $product->get_id(),
		'mixed' => $mixed,
		'all_skipped_ids' => array($mixed['original_id'], $mixed['child_ids'][1]),
		'confirm_drift' => $confirm_drift,
		'runtime' => $runtime,
		'order_ids' => $order_ids,
		'product_stock_before' => WCOS_Decimal::normalize(wc_get_product($product->get_id())->get_stock_quantity(), 6),
		'reduced_stock_before' => WCOS_Decimal::from_units($reduced_units, 6),
		'allowed_status_before_exists' => $missing !== $allowed_before,
		'allowed_status_before' => $missing !== $allowed_before ? $allowed_before : null,
		'created_at' => time(),
	);
	update_option($key, $manifest, false);
	echo 'BULK_PARTIAL_UI_FIXTURE_READY mixed=' . implode(',', $mixed['child_ids'])
		. ' all_skipped=' . implode(',', $manifest['all_skipped_ids'])
		. ' confirm_drift=' . $confirm_drift['child_ids'][0]
		. ' runtime=' . implode(',', $runtime['child_ids'])
		. ' stock=' . $manifest['product_stock_before'] . ' reduced=' . $manifest['reduced_stock_before'] . "\n";
} catch (Throwable $throwable) {
	if ($missing === $allowed_before) { delete_option('order_splitter_status_allowed'); } else { update_option('order_splitter_status_allowed', $allowed_before); }
	throw $throwable;
}
