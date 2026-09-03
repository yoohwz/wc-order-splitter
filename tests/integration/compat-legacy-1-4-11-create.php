<?php

if (!defined('ABSPATH')) { exit(1); }

const WCOS_COMPAT_003_BASELINE_SHA = 'e1d8aeb8eff38f4ce69dad1a08993e17521c6359';
const WCOS_COMPAT_003_BASELINE_TREE = '75140a414cd637d134f860d8a70e7f92cbe4853c';
const WCOS_COMPAT_003_FIXTURE_OPTION = 'wcos_compat_003_genuine_1_4_11_fixture';
const WCOS_COMPAT_003_MISSING_OPTION = '__wcos_compat_003_missing_option__';

$wcos_compat_003_arguments = isset($args) && is_array($args) ? array_values($args) : array();
define('WCOS_COMPAT_007_LEGACY_FIXTURE_MODE', isset($wcos_compat_003_arguments[0]) && 'wos-compat-007' === (string) $wcos_compat_003_arguments[0]);
if (WCOS_COMPAT_007_LEGACY_FIXTURE_MODE) {
	define('WCOS_COMPAT_007_LEDGER_LIBRARY_ONLY', true);
	require_once WP_PLUGIN_DIR . '/wc-order-splitter/tests/integration/compat-upgrade-fixture-ledger.php';
}

function wcos_compat_003_baseline_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_compat_003_baseline_product($name, $price) {
	$product = new WC_Product_Simple();
	$product->set_name($name);
	$product->set_regular_price($price);
	$product->set_price($price);
	$product->set_manage_stock(false);
	wcos_compat_003_baseline_assert($product->save() > 0, 'The exact 1.4.11 fixture product could not be saved.');
	if (WCOS_COMPAT_007_LEGACY_FIXTURE_MODE) { wcos_compat_007_ledger_remember('product', $product->get_id()); }
	return $product;
}

function wcos_compat_003_baseline_line(WC_Order $order, WC_Product $product, $quantity, $subtotal, $total, $identity) {
	$item = new WC_Order_Item_Product();
	$item->set_props(array(
		'name' => $product->get_name(),
		'product_id' => $product->get_id(),
		'variation_id' => 0,
		'quantity' => $quantity,
		'tax_class' => '',
		'subtotal' => $subtotal,
		'total' => $total,
		'subtotal_tax' => '0.00',
		'total_tax' => '0.00',
		'taxes' => array('subtotal' => array(), 'total' => array()),
	));
	$item->add_meta_data('Legacy configuration', $identity, true);
	$order->add_item($item);
	$order->save();
	return absint($item->get_id());
}

function wcos_compat_003_baseline_shipping(WC_Order $order) {
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_props(array(
		'method_title' => 'Exact 1.4.11 flat rate',
		'method_id' => 'flat_rate',
		'instance_id' => 3,
		'total' => '4.00',
		'taxes' => array('total' => array()),
	));
	$shipping->add_meta_data('Items', 'Exact 1.4.11 fixture', true);
	$order->add_item($shipping);
	$order->save();
}

$baseline_plugin = 'wcos-legacy-1-4-11/wc-order-splitter.php';
wcos_compat_003_baseline_assert(is_plugin_active($baseline_plugin), 'The exact 1.4.11 baseline plugin is not active.');
wcos_compat_003_baseline_assert(!is_plugin_active('wc-order-splitter/wc-order-splitter.php'), 'The current plugin must remain inactive while the legacy fixture is created.');
wcos_compat_003_baseline_assert(defined('WC_ORDER_SPLITTER_VERSION') && '1.4.11' === WC_ORDER_SPLITTER_VERSION, 'The active baseline does not declare version 1.4.11.');
wcos_compat_003_baseline_assert(class_exists('WooCommerce_Order_Splitter_Split_Order'), 'The exact public 1.4.11 Split handler is unavailable.');

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_compat_003_baseline_assert(!empty($admins), 'The genuine upgrade fixture requires an administrator.');
wp_set_current_user(absint($admins[0]));

$product = wcos_compat_003_baseline_product('Exact 1.4.11 moved product', '10.00');
$keep = wcos_compat_003_baseline_product('Exact 1.4.11 retained product', '2.00');
$source = wc_create_order();
wcos_compat_003_baseline_assert($source instanceof WC_Order, 'The exact 1.4.11 source order could not be created.');
if (WCOS_COMPAT_007_LEGACY_FIXTURE_MODE) { wcos_compat_007_ledger_remember('order', $source->get_id()); }
$source->set_status('pending');
$source->set_currency(get_woocommerce_currency());
$source->set_prices_include_tax(wc_prices_include_tax());
$source->set_payment_method('cod');
if (WCOS_COMPAT_007_LEGACY_FIXTURE_MODE) { $source->update_meta_data('_wcos_compat_007_fixture', 'legacy-split-source'); }
$source->save();
$moved_item_id = wcos_compat_003_baseline_line($source, $product, 3, '30.00', '27.00', 'genuine-upgrade');
$keep_item_id = wcos_compat_003_baseline_line($source, $keep, 1, '2.00', '2.00', 'genuine-retained');
wcos_compat_003_baseline_shipping($source);
$source->calculate_totals(false);
$source->save();

$settings_before = array();
foreach (array('order_splitter_status_allowed', 'order_splitter_exclude_shipping_fee') as $option_name) {
	$value = get_option($option_name, WCOS_COMPAT_003_MISSING_OPTION);
	$settings_before[$option_name] = array(
		'exists' => WCOS_COMPAT_003_MISSING_OPTION !== $value,
		'value' => WCOS_COMPAT_003_MISSING_OPTION !== $value ? $value : null,
	);
}
update_option('order_splitter_status_allowed', array('wc-pending'));
update_option('order_splitter_exclude_shipping_fee', 'no');
update_option(WCOS_COMPAT_003_FIXTURE_OPTION, array(
	'authority_schema_version' => 1,
	'baseline_sha' => WCOS_COMPAT_003_BASELINE_SHA,
	'baseline_tree' => WCOS_COMPAT_003_BASELINE_TREE,
	'baseline_version' => WC_ORDER_SPLITTER_VERSION,
	'source_id' => $source->get_id(),
	'moved_product_id' => $product->get_id(),
	'keep_product_id' => $keep->get_id(),
	'moved_source_item_id' => $moved_item_id,
	'keep_source_item_id' => $keep_item_id,
	'physical_stock_before' => $product->get_stock_quantity(),
	'settings_before' => $settings_before,
), false);

$_POST = array(
	'nonce' => wp_create_nonce('split_order_nonce'),
	'order_id' => $source->get_id(),
	'split_data' => array(
		$moved_item_id => array('quantity' => 2, 'order' => '1'),
	),
);

/* The exact public handler terminates with wp_send_json_success(). */
(new WooCommerce_Order_Splitter_Split_Order())->order_splitter_callback();
