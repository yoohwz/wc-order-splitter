<?php

if (!defined('ABSPATH')) { exit(1); }

const WCOS_COMPAT_007_BASELINE_SHA = 'e1d8aeb8eff38f4ce69dad1a08993e17521c6359';
const WCOS_COMPAT_007_BASELINE_TREE = '75140a414cd637d134f860d8a70e7f92cbe4853c';
const WCOS_COMPAT_007_FIXTURE_OPTION = 'wcos_compat_007_upgrade_fixture';
const WCOS_COMPAT_007_MISSING_OPTION = '__wcos_compat_007_missing_option__';

if (!defined('WCOS_COMPAT_007_LEDGER_LIBRARY_ONLY')) { define('WCOS_COMPAT_007_LEDGER_LIBRARY_ONLY', true); }
require_once WP_PLUGIN_DIR . '/wc-order-splitter/tests/integration/compat-upgrade-fixture-ledger.php';

$wcos_compat_007_seed_arguments = isset($args) && is_array($args) ? array_values($args) : array();
$wcos_compat_007_seed_fault = isset($wcos_compat_007_seed_arguments[0]) ? (string) $wcos_compat_007_seed_arguments[0] : '';
wcos_compat_007_ledger_assert(in_array($wcos_compat_007_seed_fault, array('', 'early', 'middle', 'late'), true), 'Unknown WOS-COMPAT-007 seed fault point.');

function wcos_compat_007_seed_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_compat_007_seed_option_state($name) {
	$value = get_option($name, WCOS_COMPAT_007_MISSING_OPTION);
	return array(
		'exists' => WCOS_COMPAT_007_MISSING_OPTION !== $value,
		'value' => WCOS_COMPAT_007_MISSING_OPTION !== $value ? $value : null,
	);
}

function wcos_compat_007_seed_meta(WC_Data $item) {
	$meta = array();
	foreach ($item->get_meta_data() as $entry) {
		$data = $entry->get_data();
		$meta[] = array('key' => (string) $data['key'], 'value' => $data['value']);
	}
	return $meta;
}

function wcos_compat_007_seed_item_state(WC_Order_Item $item) {
	$state = array(
		'id' => absint($item->get_id()),
		'type' => (string) $item->get_type(),
		'name' => (string) $item->get_name(),
		'meta' => wcos_compat_007_seed_meta($item),
	);
	if ($item instanceof WC_Order_Item_Product) {
		$state += array(
			'product_id' => absint($item->get_product_id()),
			'variation_id' => absint($item->get_variation_id()),
			'quantity' => (string) $item->get_quantity(),
			'subtotal' => (string) $item->get_subtotal(),
			'total' => (string) $item->get_total(),
			'subtotal_tax' => (string) $item->get_subtotal_tax(),
			'total_tax' => (string) $item->get_total_tax(),
			'taxes' => $item->get_taxes(),
		);
	} elseif ($item instanceof WC_Order_Item_Shipping) {
		$state += array(
			'method_id' => (string) $item->get_method_id(),
			'instance_id' => absint($item->get_instance_id()),
			'total' => (string) $item->get_total(),
			'total_tax' => (string) $item->get_total_tax(),
			'taxes' => $item->get_taxes(),
		);
	} elseif ($item instanceof WC_Order_Item_Fee) {
		$state += array(
			'amount' => (string) $item->get_amount(),
			'total' => (string) $item->get_total(),
			'total_tax' => (string) $item->get_total_tax(),
			'taxes' => $item->get_taxes(),
		);
	} elseif ($item instanceof WC_Order_Item_Coupon) {
		$state += array(
			'code' => (string) $item->get_code(),
			'discount' => (string) $item->get_discount(),
			'discount_tax' => (string) $item->get_discount_tax(),
		);
	} elseif ($item instanceof WC_Order_Item_Tax) {
		$state += array(
			'rate_id' => absint($item->get_rate_id()),
			'tax_total' => (string) $item->get_tax_total(),
			'shipping_tax_total' => (string) $item->get_shipping_tax_total(),
		);
	}
	return $state;
}

function wcos_compat_007_seed_order_state(WC_Abstract_Order $order) {
	$items = array();
	foreach (array('line_item', 'shipping', 'fee', 'coupon', 'tax') as $type) {
		foreach ($order->get_items($type) as $item) {
			$items[] = wcos_compat_007_seed_item_state($item);
		}
	}
	usort($items, static function($left, $right) { return $left['id'] <=> $right['id']; });
	if ($order instanceof WC_Order_Refund) {
		return array(
			'id' => absint($order->get_id()), 'parent_id' => absint($order->get_parent_id('edit')),
			'currency' => (string) $order->get_currency('edit'), 'status' => (string) $order->get_status('edit'),
			'amount' => (string) $order->get_amount('edit'), 'reason' => (string) $order->get_reason('edit'),
			'refunded_by' => absint($order->get_refunded_by('edit')), 'refunded_payment' => (bool) $order->get_refunded_payment('edit'),
			'total' => (string) $order->get_total('edit'), 'total_tax' => (string) $order->get_total_tax('edit'),
			'shipping_total' => (string) $order->get_shipping_total('edit'), 'shipping_tax' => (string) $order->get_shipping_tax('edit'),
			'cart_tax' => (string) $order->get_cart_tax('edit'), 'meta' => wcos_compat_007_seed_meta($order), 'items' => $items,
		);
	}
	$refund_states = array();
	foreach ($order->get_refunds() as $refund) { $refund_states[$refund->get_id()] = wcos_compat_007_seed_order_state($refund); }
	ksort($refund_states, SORT_NUMERIC);
	$refund_ids = array_map(static function($refund) { return absint($refund->get_id()); }, $order->get_refunds());
	sort($refund_ids, SORT_NUMERIC);
	return array(
		'id' => absint($order->get_id()),
		'status' => (string) $order->get_status(),
		'currency' => (string) $order->get_currency(),
		'prices_include_tax' => (bool) $order->get_prices_include_tax(),
		'payment_method' => (string) $order->get_payment_method(),
		'transaction_id' => (string) $order->get_transaction_id(),
		'date_paid' => $order->get_date_paid() ? (int) $order->get_date_paid()->getTimestamp() : null,
		'total' => (string) $order->get_total(),
		'total_tax' => (string) $order->get_total_tax(),
		'discount_total' => (string) $order->get_discount_total(),
		'discount_tax' => (string) $order->get_discount_tax(),
		'shipping_total' => (string) $order->get_shipping_total(),
		'shipping_tax' => (string) $order->get_shipping_tax(),
		'cart_tax' => (string) $order->get_cart_tax(),
		'refund_ids' => $refund_ids,
		'refund_states' => $refund_states,
		'meta' => wcos_compat_007_seed_meta($order),
		'items' => $items,
	);
}

function wcos_compat_007_seed_product($label, $price, $stock_status = 'instock', $manage_stock = false) {
	$product = new WC_Product_Simple();
	$product->set_name('WOS COMPAT 007 ' . $label);
	$product->set_status('publish');
	$product->set_regular_price($price);
	$product->set_price($price);
	$product->set_tax_status('none');
	$product->set_manage_stock((bool) $manage_stock);
	if ($manage_stock) { $product->set_stock_quantity(40); }
	$product->set_stock_status($stock_status);
	wcos_compat_007_seed_assert($product->save() > 0, 'Unable to save exact-1.4.11 product fixture: ' . $label);
	wcos_compat_007_ledger_remember('product', $product->get_id());
	return $product;
}

function wcos_compat_007_seed_order($label, $status = 'pending') {
	$order = wc_create_order(array('status' => 'pending'));
	wcos_compat_007_seed_assert($order instanceof WC_Order, 'Unable to save exact-1.4.11 order fixture: ' . $label);
	wcos_compat_007_ledger_remember('order', $order->get_id());
	$order->set_currency('USD');
	$order->set_prices_include_tax(false);
	$order->set_payment_method('cod');
	$order->set_payment_method_title('Cash on delivery');
	$order->set_billing_first_name('Upgrade Fixture');
	$order->set_billing_email('wos-compat-007-' . sanitize_key($label) . '@example.test');
	$order->set_status($status);
	$order->update_meta_data('_wcos_compat_007_fixture', $label);
	$order->save();
	return wc_get_order($order->get_id());
}

function wcos_compat_007_seed_line(WC_Order $order, WC_Product $product, $quantity, $subtotal, $total, $label, $reduced_stock = null) {
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
	$item->add_meta_data('WOS baseline configuration', $label, true);
	if (null !== $reduced_stock) { $item->add_meta_data('_reduced_stock', $reduced_stock, true); }
	$order->add_item($item);
	$order->save();
	return absint($item->get_id());
}

function wcos_compat_007_seed_shipping(WC_Order $order, $label, $total = '3.00') {
	$item = new WC_Order_Item_Shipping();
	$item->set_props(array(
		'method_title' => 'Baseline shipping ' . $label,
		'method_id' => 'flat_rate',
		'instance_id' => 7,
		'total' => $total,
		'taxes' => array('total' => array()),
	));
	$item->add_meta_data('Items', 'Baseline shipping evidence ' . $label, true);
	$order->add_item($item);
	$order->save();
}

function wcos_compat_007_seed_fee(WC_Order $order, $label, $total) {
	$item = new WC_Order_Item_Fee();
	$item->set_name($label);
	$item->set_amount($total);
	$item->set_total($total);
	$item->set_taxes(array('total' => array()));
	$order->add_item($item);
	$order->save();
}

function wcos_compat_007_seed_coupon(WC_Order $order) {
	$item = new WC_Order_Item_Coupon();
	$item->set_code('baseline-five');
	$item->set_discount('5.00');
	$item->set_discount_tax('0.00');
	$order->add_item($item);
	$order->save();
}

if (defined('WCOS_COMPAT_007_SEED_LIBRARY_ONLY')) { return; }

$baseline_plugin = 'wcos-legacy-1-4-11/wc-order-splitter.php';
wcos_compat_007_seed_assert(is_plugin_active($baseline_plugin), 'The exact 1.4.11 in-place upgrade plugin is not active.');
wcos_compat_007_seed_assert(!is_plugin_active('wc-order-splitter/wc-order-splitter.php'), 'The mapped current plugin must remain inactive during baseline seeding.');
wcos_compat_007_seed_assert(defined('WC_ORDER_SPLITTER_VERSION') && '1.4.11' === WC_ORDER_SPLITTER_VERSION, 'The baseline seed did not execute under plugin version 1.4.11.');
wcos_compat_007_seed_assert(false === get_option(WCOS_COMPAT_007_FIXTURE_OPTION, false), 'A prior WOS-COMPAT-007 upgrade fixture still exists.');
wcos_compat_007_ledger_get(true);

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_compat_007_seed_assert(!empty($admins), 'The upgrade fixture requires an administrator.');
wp_set_current_user(absint($admins[0]));

$option_names = array(
	'order_splitter_status_allowed',
	'order_splitter_exclude_shipping_fee',
	'order_splitter_shop_manager_permission',
	'order_splitter_order_label',
	'order_splitter_disable_split_order_email',
);
$options_before = array();
foreach ($option_names as $option_name) { $options_before[$option_name] = wcos_compat_007_seed_option_state($option_name); }

$shop_manager_samples = array();
update_option('order_splitter_shop_manager_permission', 'yes');
$shop_manager_samples['yes'] = wcos_compat_007_seed_option_state('order_splitter_shop_manager_permission');
update_option('order_splitter_shop_manager_permission', 'no');
$shop_manager_samples['no'] = wcos_compat_007_seed_option_state('order_splitter_shop_manager_permission');
delete_option('order_splitter_shop_manager_permission');
$shop_manager_samples['absent'] = wcos_compat_007_seed_option_state('order_splitter_shop_manager_permission');

$shipping_samples = array();
foreach (array('yes', 'no') as $shipping_value) {
	update_option('order_splitter_exclude_shipping_fee', $shipping_value);
	$shipping_samples[$shipping_value] = wcos_compat_007_seed_option_state('order_splitter_exclude_shipping_fee');
}
$email_samples = array();
foreach (array('none', 'for_customers', 'for_administrators', 'for_everyone') as $email_value) {
	update_option('order_splitter_disable_split_order_email', $email_value);
	$email_samples[$email_value] = wcos_compat_007_seed_option_state('order_splitter_disable_split_order_email');
}

update_option('order_splitter_status_allowed', array('wc-pending', 'wc-processing', 'wc-on-hold'));
update_option('order_splitter_exclude_shipping_fee', 'no');
update_option('order_splitter_shop_manager_permission', 'yes');
update_option('order_splitter_order_label', 'no');
update_option('order_splitter_disable_split_order_email', 'for_everyone');

$products = array();
$products['managed'] = wcos_compat_007_seed_product('managed line', '10.00', 'instock', true);
if ('early' === $wcos_compat_007_seed_fault) { throw new RuntimeException('Injected WOS-COMPAT-007 early seed failure.'); }
$products['category_a'] = wcos_compat_007_seed_product('category A', '7.00', 'instock');
$products['category_b'] = wcos_compat_007_seed_product('category B', '9.00', 'outofstock');
$products['commercial'] = wcos_compat_007_seed_product('commercial line', '6.00');
$products['financial'] = wcos_compat_007_seed_product('financial line', '5.00');

$term = wp_insert_term('WOS COMPAT 007 baseline category ' . wp_generate_password(6, false), 'product_cat');
wcos_compat_007_seed_assert(!is_wp_error($term), 'Unable to create the exact-1.4.11 category fixture.');
wcos_compat_007_ledger_remember('term', absint($term['term_id']));
wp_set_object_terms($products['category_a']->get_id(), array(absint($term['term_id'])), 'product_cat');
if ('middle' === $wcos_compat_007_seed_fault) { throw new RuntimeException('Injected WOS-COMPAT-007 middle seed failure.'); }

$orders = array();
$line_ids = array();

$orders['manual_source'] = wcos_compat_007_seed_order('manual-source');
$line_ids['manual_source'] = wcos_compat_007_seed_line($orders['manual_source'], $products['managed'], 4, '40.00', '36.00', 'manual-source');
wcos_compat_007_seed_shipping($orders['manual_source'], 'manual-source', '4.00');
$orders['manual_source']->calculate_totals(false);
$orders['manual_source']->save();
wc_reduce_stock_levels($orders['manual_source']);
$orders['manual_source']->get_data_store()->set_stock_reduced($orders['manual_source']->get_id(), true);
$orders['manual_source'] = wc_get_order($orders['manual_source']->get_id());

$orders['category_source'] = wcos_compat_007_seed_order('category-source');
wcos_compat_007_seed_line($orders['category_source'], $products['category_a'], 1, '7.00', '7.00', 'category-a');
wcos_compat_007_seed_line($orders['category_source'], $products['category_b'], 1, '9.00', '9.00', 'category-b');
$orders['category_source']->calculate_totals(false);
$orders['category_source']->save();

$orders['stock_source'] = wcos_compat_007_seed_order('stock-source');
wcos_compat_007_seed_line($orders['stock_source'], $products['category_a'], 1, '7.00', '7.00', 'stock-in');
wcos_compat_007_seed_line($orders['stock_source'], $products['category_b'], 1, '9.00', '9.00', 'stock-out');
$orders['stock_source']->calculate_totals(false);
$orders['stock_source']->save();

$orders['duplicate_source'] = wcos_compat_007_seed_order('duplicate-source', 'processing');
wcos_compat_007_seed_line($orders['duplicate_source'], $products['commercial'], 2, '12.00', '12.00', 'duplicate-source');
$orders['duplicate_source']->set_transaction_id('baseline-duplicate-transaction');
$orders['duplicate_source']->set_date_paid(strtotime('2026-01-15 00:00:00 UTC'));
$orders['duplicate_source']->calculate_totals(false);
$orders['duplicate_source']->save();

$orders['merge_source'] = wcos_compat_007_seed_order('merge-source', 'on-hold');
wcos_compat_007_seed_line($orders['merge_source'], $products['commercial'], 2, '12.00', '10.00', 'merge-source');
wcos_compat_007_seed_shipping($orders['merge_source'], 'merge-source', '3.00');
wcos_compat_007_seed_fee($orders['merge_source'], 'Baseline handling', '1.00');
wcos_compat_007_seed_fee($orders['merge_source'], 'Baseline adjustment', '-0.50');
wcos_compat_007_seed_coupon($orders['merge_source']);
$orders['merge_source']->calculate_totals(false);
$orders['merge_source']->save();
$orders['merge_target'] = wcos_compat_007_seed_order('merge-target', 'pending');
wcos_compat_007_seed_line($orders['merge_target'], $products['commercial'], 1, '6.00', '6.00', 'merge-target-distinct');
$orders['merge_target']->calculate_totals(false);
$orders['merge_target']->save();

$orders['financial_target'] = wcos_compat_007_seed_order('financial-target', 'processing');
$financial_target_line = wcos_compat_007_seed_line($orders['financial_target'], $products['financial'], 2, '10.00', '10.00', 'financial-target');
$orders['financial_target']->set_transaction_id('baseline-financial-target-transaction');
$orders['financial_target']->set_date_paid(strtotime('2026-01-16 00:00:00 UTC'));
$orders['financial_target']->calculate_totals(false);
$orders['financial_target']->save();
$refund = wc_create_refund(array(
	'order_id' => $orders['financial_target']->get_id(),
	'amount' => '2.00',
	'reason' => 'WOS COMPAT 007 baseline partial refund',
	'refund_payment' => false,
	'restock_items' => false,
	'line_items' => array($financial_target_line => array('qty' => 0, 'refund_total' => '2.00', 'refund_tax' => array())),
));
wcos_compat_007_seed_assert($refund instanceof WC_Order_Refund, 'Unable to create the exact-1.4.11 partial-refund fixture.');
wcos_compat_007_ledger_remember('order', $refund->get_id());

$orders['financial_neutral_source'] = wcos_compat_007_seed_order('financial-neutral-source');
wcos_compat_007_seed_line($orders['financial_neutral_source'], $products['financial'], 1, '5.00', '0.00', 'financial-neutral');
$orders['financial_neutral_source']->calculate_totals(false);
$orders['financial_neutral_source']->save();

$orders['financial_nonzero_source'] = wcos_compat_007_seed_order('financial-nonzero-source');
wcos_compat_007_seed_line($orders['financial_nonzero_source'], $products['financial'], 1, '5.00', '1.00', 'financial-nonzero');
$orders['financial_nonzero_source']->calculate_totals(false);
$orders['financial_nonzero_source']->save();

$orders['financial_history_source'] = wcos_compat_007_seed_order('financial-history-source', 'processing');
wcos_compat_007_seed_line($orders['financial_history_source'], $products['financial'], 1, '5.00', '0.00', 'financial-history-source');
$orders['financial_history_source']->set_transaction_id('baseline-financial-source-transaction');
$orders['financial_history_source']->set_date_paid(strtotime('2026-01-17 00:00:00 UTC'));
$orders['financial_history_source']->calculate_totals(false);
$orders['financial_history_source']->save();
// Persist historical two-rate tax data without consulting current tax tables.
$orders['tax_history'] = wcos_compat_007_seed_order('tax-history');
$tax_line_id = wcos_compat_007_seed_line($orders['tax_history'], $products['commercial'], 2, '12.00', '10.00', 'historical-tax');
$tax_line = $orders['tax_history']->get_item($tax_line_id);
$tax_line->set_taxes(array('subtotal' => array(781001 => '1.20', 781002 => '0.60'), 'total' => array(781001 => '1.00', 781002 => '0.50')));
$tax_line->save();
wcos_compat_007_seed_shipping($orders['tax_history'], 'tax-history', '3.00');
foreach ($orders['tax_history']->get_items('shipping') as $shipping) {
	$shipping->set_taxes(array('total' => array(781001 => '0.30', 781002 => '0.15')));
	$shipping->save();
}
wcos_compat_007_seed_fee($orders['tax_history'], 'Baseline taxed fee', '2.00');
foreach ($orders['tax_history']->get_items('fee') as $fee) {
	$fee->set_taxes(array('total' => array(781001 => '0.20', 781002 => '0.10')));
	$fee->save();
}
foreach (array(781001 => array('1.20', '0.30'), 781002 => array('0.60', '0.15')) as $rate_id => $amounts) {
	$tax = new WC_Order_Item_Tax();
	$tax->set_rate_id($rate_id);
	$tax->set_label('Baseline historical rate ' . $rate_id);
	$tax->set_tax_total($amounts[0]);
	$tax->set_shipping_tax_total($amounts[1]);
	$orders['tax_history']->add_item($tax);
}
$orders['tax_history']->set_cart_tax('1.80');
$orders['tax_history']->set_shipping_tax('0.45');
$orders['tax_history']->calculate_totals(false);
$orders['tax_history']->save();

// The actual public-handler Split family needs the same complete before/after proof.
$legacy = get_option('wcos_compat_003_genuine_1_4_11_fixture', array());
foreach (array('legacy_source' => 'source_id', 'legacy_child' => 'child_id') as $key => $field) {
	if (empty($legacy[$field])) { continue; } // Early/middle/late setup-fault runs do not create a family.
	$orders[$key] = wc_get_order(absint($legacy[$field]));
	wcos_compat_007_seed_assert($orders[$key] instanceof WC_Order, 'The genuine legacy family is unavailable before upgrade.');
}
if ('late' === $wcos_compat_007_seed_fault) { throw new RuntimeException('Injected WOS-COMPAT-007 late seed failure.'); }

$order_states = array();
foreach ($orders as $key => $order) {
	$order = wc_get_order($order->get_id());
	$orders[$key] = $order;
	$order_states[$key] = wcos_compat_007_seed_order_state($order);
}

$product_ids = array();
$physical_stock = array();
foreach ($products as $key => $product) {
	$product_ids[$key] = absint($product->get_id());
	$persisted_product = wc_get_product($product->get_id());
	$physical_stock[$key] = $persisted_product instanceof WC_Product ? $persisted_product->get_stock_quantity() : null;
}
$order_ids = array();
foreach ($orders as $key => $order) { $order_ids[$key] = absint($order->get_id()); }

update_option(WCOS_COMPAT_007_FIXTURE_OPTION, array(
	'authority_schema_version' => 1,
	'baseline_sha' => WCOS_COMPAT_007_BASELINE_SHA,
	'baseline_tree' => WCOS_COMPAT_007_BASELINE_TREE,
	'baseline_version' => WC_ORDER_SPLITTER_VERSION,
	'baseline_plugin' => $baseline_plugin,
	'created_at' => time(),
	'options_before' => $options_before,
	'shop_manager_samples' => $shop_manager_samples,
	'shipping_samples' => $shipping_samples,
	'email_samples' => $email_samples,
	'options_after_seed' => array_combine($option_names, array_map('wcos_compat_007_seed_option_state', $option_names)),
	'product_ids' => $product_ids,
	'physical_stock_before' => $physical_stock,
	'term_id' => absint($term['term_id']),
	'order_ids' => $order_ids,
	'line_ids' => $line_ids,
	'refund_id' => absint($refund->get_id()),
	'order_states_before_upgrade' => $order_states,
), false);

echo 'compat-upgrade-1-4-11-seed-ok orders=' . count($order_ids) . ' products=' . count($product_ids) . " settings=5\n";
