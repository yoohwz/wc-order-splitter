<?php
/**
 * Pure-PHP bootstrap contract for WordPress versions that do not enforce the
 * Requires Plugins header.
 */

declare(strict_types=1);

$mode = isset($argv[1]) ? (string) $argv[1] : 'missing';

if (!in_array($mode, array('missing', 'active'), true)) {
	fwrite(STDERR, "Usage: php tests/bootstrap-contract.php [missing|active]\n");
	exit(2);
}

define('ABSPATH', dirname(__DIR__) . '/');

if ('active' === $mode) {
	class WooCommerce {}
}

$GLOBALS['wcos_bootstrap_actions']    = array();
$GLOBALS['wcos_bootstrap_filters']    = array();
$GLOBALS['wcos_bootstrap_options']    = array();
$GLOBALS['wcos_activation_callback']  = null;

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['wcos_bootstrap_actions'][$hook][] = array($callback, $priority, $accepted_args);
	return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['wcos_bootstrap_filters'][$hook][] = array($callback, $priority, $accepted_args);
	return true;
}

function register_activation_hook($file, $callback) {
	$GLOBALS['wcos_activation_callback'] = $callback;
}

function plugin_dir_path($file) {
	return rtrim(dirname($file), '/\\') . '/';
}

function plugin_basename($file) {
	return basename(dirname($file)) . '/' . basename($file);
}

function plugins_url($path = '', $file = '') {
	return 'https://example.test/wp-content/plugins/wc-order-splitter/' . ltrim((string) $path, '/');
}

function admin_url($path = '') {
	return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function esc_url($value) {
	return (string) $value;
}

function esc_html__($text, $domain = null) {
	return (string) $text;
}

function esc_html_e($text, $domain = null) {
	echo (string) $text;
}

function current_user_can($capability) {
	return true;
}

function add_option($key, $value, $deprecated = '', $autoload = 'yes') {
	if (array_key_exists($key, $GLOBALS['wcos_bootstrap_options'])) {
		return false;
	}

	$GLOBALS['wcos_bootstrap_options'][$key] = $value;
	return true;
}

function update_option($key, $value, $autoload = null) {
	$GLOBALS['wcos_bootstrap_options'][$key] = $value;
	return true;
}

function get_option($key, $default = false) {
	return array_key_exists($key, $GLOBALS['wcos_bootstrap_options'])
		? $GLOBALS['wcos_bootstrap_options'][$key]
		: $default;
}

function wp_enqueue_style($handle, $src = '', $deps = array(), $version = false) {
	return true;
}

function is_admin() {
	return true;
}

function wp_unslash($value) {
	return $value;
}

function sanitize_key($value) {
	return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function sanitize_text_field($value) {
	return trim(strip_tags((string) $value));
}

function wp_safe_redirect($location) {
	$GLOBALS['wcos_redirect'] = $location;
	return true;
}

function add_query_arg($args, $url) {
	return $url . '?' . http_build_query($args);
}

function get_current_screen() {
	return null;
}

function wc_get_page_screen_id($type) {
	return 'woocommerce_page_wc-orders';
}

function wc_get_order($order_id) {
	return false;
}

function wcos_bootstrap_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

require dirname(__DIR__) . '/wc-order-splitter.php';

wcos_bootstrap_assert('1.4.12' === WC_ORDER_SPLITTER_VERSION, 'Bootstrap version constant is incorrect.');
wcos_bootstrap_assert(false === WC_ORDER_SPLITTER_MUTATIONS_ENABLED, 'Bootstrap mutation flag is not false.');
wcos_bootstrap_assert(is_callable($GLOBALS['wcos_activation_callback']), 'Activation callback was not registered.');

call_user_func($GLOBALS['wcos_activation_callback']);
wcos_bootstrap_assert('1.4.12' === $GLOBALS['wcos_bootstrap_options']['wc_order_splitter_version'], 'Activation did not record the installed version.');
wcos_bootstrap_assert(array('wc-processing') === $GLOBALS['wcos_bootstrap_options']['order_splitter_status_allowed'], 'Activation did not seed safe status defaults.');

wcos_bootstrap_plugin();

if ('missing' === $mode) {
	wcos_bootstrap_assert(!class_exists('WC_Order_Splitter_Script', false), 'Read-only components loaded without WooCommerce.');
	wcos_bootstrap_assert(!empty($GLOBALS['wcos_bootstrap_actions']['admin_notices']), 'Missing WooCommerce did not register an administration notice.');
	wcos_bootstrap_assert(array('existing') === wcos_add_plugin_action_links(array('existing')), 'Settings link was added without WooCommerce.');
} else {
	wcos_bootstrap_assert(class_exists('WC_Order_Splitter_Script', false), 'Read-only loader did not load with WooCommerce active.');
	wcos_bootstrap_assert(class_exists('WooCommerce_Order_Splitter_Settings', false), 'Maintenance settings did not load with WooCommerce active.');
	wcos_bootstrap_assert(class_exists('WooCommerce_Order_Splitter_Edit_Order', false), 'Read-only relation renderer did not load with WooCommerce active.');
	wcos_bootstrap_assert(!class_exists('WooCommerce_Order_Splitter_Split_Order', false), 'A mutation handler loaded with WooCommerce active.');
	wcos_bootstrap_assert(2 === count(wcos_add_plugin_action_links(array('existing'))), 'Settings link was not added with WooCommerce active.');
}

echo sprintf("Order Splitter bootstrap contract passed in %s mode.\n", $mode);
