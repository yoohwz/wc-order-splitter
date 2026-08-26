<?php

if (!defined('ABSPATH')) { exit(1); }

function wcos_bulk_ui_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

$root = dirname(__DIR__, 2);
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_bulk_ui_assert(!empty($admins), 'Bulk Return UI readiness requires an administrator.');
wp_set_current_user(absint($admins[0]));
wcos_bulk_ui_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN), 'Production Bulk Return gate drifted on.');
wcos_bulk_ui_assert(null === WCOS_Bulk_Return_Admin_Controller::bootstrap(), 'Hard-off Bulk Return controller bootstrapped.');

$controller = new WCOS_Bulk_Return_Admin_Controller();
$template = $controller->dialog_html();
foreach (array('role="dialog"', 'aria-modal="true"', 'aria-labelledby="wcos-bulk-return-title"', 'aria-describedby="wcos-bulk-return-description"', 'aria-live="polite"', 'role="alert"', 'wcos-bulk-return-groups', 'wcos-bulk-return-acknowledge') as $needle) {
	wcos_bulk_ui_assert(false !== strpos($template, $needle), 'Bulk Return source dialog is missing accessibility/state contract: ' . $needle);
}
wcos_bulk_ui_assert(false === strpos($template, 'data-original'), 'Bulk Return template exposes original authority.');

$hpos_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
set_current_screen($hpos_screen);
unset($_GET['id']);
WCOS_Admin_Backbone_Modal_Assets::enqueue();
$controller->enqueue_assets();
wcos_bulk_ui_assert(!wp_script_is('wcos-bulk-return-admin', 'enqueued'), 'Hard-off Bulk Return script was enqueued.');
wcos_bulk_ui_assert(!wp_style_is('wcos-bulk-return-admin', 'enqueued'), 'Hard-off Bulk Return stylesheet was enqueued.');

$review_rejected = false;
global $wpdb;
$journal_count_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wcos_mutation_op_%'");
try {
	$controller->review_request(array('nonce' => wp_create_nonce(WCOS_Bulk_Return_Admin_Controller::NONCE_ACTION), 'child_order_ids' => array(1)));
} catch (WCOS_Bulk_Return_Transport_Exception $exception) {
	$review_rejected = 'workflow_disabled' === $exception->get_error_code();
}
wcos_bulk_ui_assert($review_rejected, 'Direct hard-off Bulk Return Review did not fail before persistence.');
$journal_count_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wcos_mutation_op_%'");
wcos_bulk_ui_assert($journal_count_before === $journal_count_after, 'Direct hard-off Bulk Return Review created a coordinator journal.');

$client = file_get_contents($root . '/js/p2-bulk-return-admin.js');
foreach (array('window.WCOSBackboneModal.open', 'var activeBatch = null;', 'function review()', 'function confirm()', 'function execute()', 'function resume()', "event.key === 'Escape' && busy", "content.setAttribute('aria-modal', 'true')", 'child_order_ids: ids', 'cursor: activeBatch.cursor', 'summary.groups || {}', 'counts.manual_reconciliation', 'counts.not_run_blocked') as $needle) {
	wcos_bulk_ui_assert(false !== strpos($client, $needle), 'Bulk Return client contract is missing: ' . $needle);
}
foreach (array('original_order_id:', 'source_order_id:', 'plan:', 'fingerprint:', 'retirement_policy:', 'localStorage', 'sessionStorage', 'document.cookie', '.innerHTML', 'console.') as $forbidden) {
	wcos_bulk_ui_assert(false === strpos($client, $forbidden), 'Bulk Return client authors/stores forbidden authority: ' . $forbidden);
}
$controller_source = file_get_contents($root . '/inc/backend/class-wcos-bulk-return-admin-controller.php');
wcos_bulk_ui_assert(false === strpos($controller_source, 'new WCOS_Return_Order_Service'), 'Bulk Return controller directly instantiates Return service.');
wcos_bulk_ui_assert(false === strpos($controller_source, 'new WCOS_Return_WooCommerce_Adapter'), 'Bulk Return controller directly instantiates Return adapter.');
wcos_bulk_ui_assert(false !== strpos($controller_source, 'new WCOS_Mutation_Gateway())->bulk_return_advance('), 'Bulk Return Execute does not enter the mutation gateway.');
wcos_bulk_ui_assert(false !== strpos($controller_source, "bulk_actions-edit-shop_order"), 'Legacy Orders-list bulk selector hook is absent.');
wcos_bulk_ui_assert(false !== strpos($controller_source, "bulk_actions-woocommerce_page_wc-orders"), 'HPOS Orders-list bulk selector hook is absent.');

foreach (array('inc/backend/actions/return-order-bulk-action.php', 'inc/backend/orders-bulk-return.php', 'js/bulk-return-action.js') as $legacy) {
	wcos_bulk_ui_assert(!file_exists($root . '/' . $legacy), 'Legacy Bulk Return runtime remains: ' . $legacy);
}
wcos_bulk_ui_assert(false === strpos(file_get_contents($root . '/inc/cores/script.php'), 'yoos_handle_bulk_action'), 'Legacy Bulk Return AJAX authority returned to bootstrap.');

echo "bulk-return-ui-readiness-ok production=hard-off hooks=0 assets=0 shared_backbone=1 legacy=removed pii=bounded\n";
