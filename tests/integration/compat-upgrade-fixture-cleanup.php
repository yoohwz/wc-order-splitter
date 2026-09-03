<?php

if (!defined('ABSPATH')) { exit(1); }

define('WCOS_COMPAT_007_LEDGER_LIBRARY_ONLY', true);
require_once WP_PLUGIN_DIR . '/wc-order-splitter/tests/integration/compat-upgrade-fixture-ledger.php';

function wcos_compat_007_cleanup_restore_options(array $fixture) {
	foreach (isset($fixture['options_before']) && is_array($fixture['options_before']) ? $fixture['options_before'] : array() as $name => $state) {
		if (!is_array($state)) { continue; }
		if (!empty($state['exists'])) { update_option($name, array_key_exists('value', $state) ? $state['value'] : null); }
		else { delete_option($name); }
	}
}

function wcos_compat_007_cleanup_order($order_id) {
	$order = wc_get_order(absint($order_id));
	if ($order instanceof WC_Order) { $order->delete(true); }
}

function wcos_compat_007_cleanup_product($product_id) {
	$product = wc_get_product(absint($product_id));
	if ($product instanceof WC_Product) { $product->delete(true); }
}

$ledger = wcos_compat_007_ledger_get(false);
$ledger_order_ids = !empty($ledger['order_ids']) ? (array) $ledger['order_ids'] : array();
$ledger_product_ids = !empty($ledger['product_ids']) ? (array) $ledger['product_ids'] : array();
$ledger_user_ids = !empty($ledger['user_ids']) ? (array) $ledger['user_ids'] : array();
$ledger_term_ids = !empty($ledger['term_ids']) ? (array) $ledger['term_ids'] : array();
$ledger_related_order_ids = wcos_compat_007_ledger_related_order_ids($ledger_order_ids, $ledger);
if (!empty($ledger)) { wcos_compat_007_ledger_delete_authorities($ledger); }

$fixture = get_option('wcos_compat_007_upgrade_fixture', array());
if (!empty($fixture)) {
	if (!is_array($fixture) || 'e1d8aeb8eff38f4ce69dad1a08993e17521c6359' !== (string) $fixture['baseline_sha']) {
		throw new RuntimeException('Refusing to clean an unauthenticated WOS-COMPAT-007 fixture.');
	}
	if (!empty($fixture['refund_id'])) { wcos_compat_007_cleanup_order($fixture['refund_id']); }
	foreach (isset($fixture['order_ids']) ? (array) $fixture['order_ids'] : array() as $order_id) { wcos_compat_007_cleanup_order($order_id); }
	foreach (isset($fixture['product_ids']) ? (array) $fixture['product_ids'] : array() as $product_id) { wcos_compat_007_cleanup_product($product_id); }
	if (!empty($fixture['term_id'])) { wp_delete_term(absint($fixture['term_id']), 'product_cat'); }
	wcos_compat_007_cleanup_restore_options($fixture);
	delete_option('wcos_compat_007_upgrade_fixture');
}

$legacy = get_option('wcos_compat_003_genuine_1_4_11_fixture', array());
if (!empty($legacy)) {
	if (!is_array($legacy) || 'e1d8aeb8eff38f4ce69dad1a08993e17521c6359' !== (string) $legacy['baseline_sha']) {
		throw new RuntimeException('Refusing to clean an unauthenticated exact-1.4.11 legacy fixture.');
	}
	wcos_compat_007_cleanup_order(isset($legacy['child_id']) ? $legacy['child_id'] : 0);
	wcos_compat_007_cleanup_order(isset($legacy['source_id']) ? $legacy['source_id'] : 0);
	wcos_compat_007_cleanup_product(isset($legacy['moved_product_id']) ? $legacy['moved_product_id'] : 0);
	wcos_compat_007_cleanup_product(isset($legacy['keep_product_id']) ? $legacy['keep_product_id'] : 0);
	foreach (isset($legacy['settings_before']) ? (array) $legacy['settings_before'] : array() as $name => $state) {
		if (!is_array($state)) { continue; }
		if (!empty($state['exists'])) { update_option($name, array_key_exists('value', $state) ? $state['value'] : null); }
		else { delete_option($name); }
	}
	delete_option('wcos_compat_003_genuine_1_4_11_fixture');
}

foreach ($ledger_related_order_ids as $order_id) { wcos_compat_007_cleanup_order($order_id); }
foreach (array_values(array_unique(array_filter(array_map('absint', $ledger_product_ids)))) as $product_id) { wcos_compat_007_cleanup_product($product_id); }
foreach (array_values(array_unique(array_filter(array_map('absint', $ledger_term_ids)))) as $term_id) { wp_delete_term($term_id, 'product_cat'); }
foreach (array_values(array_unique(array_filter(array_map('absint', $ledger_user_ids)))) as $user_id) {
	if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
	wp_delete_user($user_id);
}
if (!empty($ledger)) { wcos_compat_007_ledger_assert_authorities_absent($ledger); }
if (!empty($ledger)) { wcos_compat_007_ledger_restore_options($ledger); }
delete_option(WCOS_COMPAT_007_LEDGER_OPTION);

echo "compat-upgrade-fixture-cleanup-ok\n";
