<?php

$root = dirname(__DIR__, 2);

function wcos_upsell_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "premium-upsell-surface-smoke: FAIL: {$message}\n");
		exit(1);
	}
}

function wcos_upsell_read($path) {
	$content = file_get_contents($path);
	wcos_upsell_assert(false !== $content, 'Unable to read ' . $path);
	return $content;
}

if (!defined('ABSPATH')) {
	define('ABSPATH', $root . '/');
}

$wcos_upsell_can_manage = true;
function current_user_can($capability) {
	global $wcos_upsell_can_manage;
	return 'manage_woocommerce' === $capability && $wcos_upsell_can_manage;
}
function plugin_basename($path) {
	return basename($path);
}
function add_filter() {}
function add_action() {}
function esc_url($url) {
	return $url;
}
function esc_html__($text) {
	return $text;
}

$settings = wcos_upsell_read($root . '/inc/backend/settings.php');
$boundary = wcos_upsell_read($root . '/inc/backend/class-wcos-premium-upsell.php');
$bootstrap = wcos_upsell_read($root . '/inc/cores/script.php');
$client = wcos_upsell_read($root . '/js/post-action-tip.js');

wcos_upsell_assert(false !== strpos($boundary, "const PRODUCT_URL = 'https://yoohw.com/product/woocommerce-advanced-order-actions/';"), 'Canonical product URL missing.');
wcos_upsell_assert(false !== strpos($boundary, "current_user_can('manage_woocommerce')"), 'Promotion capability gate missing.');
wcos_upsell_assert(false !== strpos($boundary, "'split' => 3"), 'Split threshold must be 3.');
wcos_upsell_assert(false !== strpos($boundary, "'duplicate' => 2"), 'Duplicate threshold must be 2.');
wcos_upsell_assert(false !== strpos($boundary, "'merge' => 2"), 'Merge threshold must be 2.');
wcos_upsell_assert(false !== strpos($boundary, "'return' => 2"), 'Return threshold must be 2.');
wcos_upsell_assert(false === strpos($boundary, 'executeActions'), 'Presentation must not observe transport actions.');
wcos_upsell_assert(false !== strpos($boundary, 'Action Logs and guarded rollback for supported Split, Merge, Return and Duplicate workflows.'), 'Return positioning must remain qualified.');
wcos_upsell_assert(false !== strpos($boundary, 'Vendor and bundle routing require compatible marketplace or bundle integrations.'), 'Contextual vendor and bundle claim must state its integration dependency.');

wcos_upsell_assert(false !== strpos($settings, "\$sub_sub_tabs['premium'] = esc_html__('Upgrade'"), 'Historical premium section key must render Upgrade.');
wcos_upsell_assert(false !== strpos($settings, 'standalone premium replacement for Order Splitter'), 'Standalone replacement positioning missing.');
wcos_upsell_assert(false !== strpos($settings, "esc_html_e('Order Splitter'"), 'Order Splitter comparison column missing.');
wcos_upsell_assert(false !== strpos($settings, "esc_html_e('Advanced Order Actions'"), 'Advanced Order Actions comparison column missing.');
wcos_upsell_assert(false === strpos($settings, "esc_html__('Locked'"), 'Upgrade matrix must not use Locked.');
wcos_upsell_assert(false !== strpos($settings, 'require a compatible Stock Manager integration'), 'Integration-dependent capability qualification missing.');

wcos_upsell_assert(false !== strpos($bootstrap, "include_once \$root . 'backend/class-wcos-premium-upsell.php';"), 'Presentation boundary is not loaded.');
wcos_upsell_assert(false !== strpos($bootstrap, 'WCOS_Premium_Upsell::bootstrap();'), 'Presentation boundary is not bootstrapped.');

wcos_upsell_assert(false !== strpos($client, "detail.status !== 'completed'"), 'Verified terminal-success guard missing.');
wcos_upsell_assert(false !== strpos($client, 'seenOperations'), 'Operation replay deduplication state missing.');
wcos_upsell_assert(false === strpos($client, 'renderPendingTip'), 'Later-page advertising must not duplicate completion campaigns.');
wcos_upsell_assert(false !== strpos($client, 'hints: { splitRoutingDismissed: false }'), 'Split hint must have distinct browser-local dismissal state.');
wcos_upsell_assert(false !== strpos($client, 'button-link wcos-modal-upsell-dismiss'), 'Modal hint dismiss control missing.');
wcos_upsell_assert(false !== strpos($client, 'state.hints.splitRoutingDismissed = true;'), 'Split hint dismiss control must persist dismissal.');
wcos_upsell_assert(false === strpos($client, 'window.fetch'), 'Promotion must not observe, wrap or issue fetch requests.');
wcos_upsell_assert(false !== strpos($client, "document.addEventListener('wcos:operation-completed'"), 'Completion presentation listener missing.');
wcos_upsell_assert(false !== strpos($client, "document.addEventListener('wcos:split-method-chooser'"), 'Method chooser presentation listener missing.');
wcos_upsell_assert(false === strpos($client, '.wcos-split-launcher'), 'Obsolete external Split hint must not duplicate chooser card.');
wcos_upsell_assert(false === strpos($client, 'navigator.sendBeacon'), 'Telemetry via sendBeacon is forbidden.');
wcos_upsell_assert(false === strpos($client, 'XMLHttpRequest'), 'Upsell client must not create XHR telemetry.');
wcos_upsell_assert(false === strpos($client, 'utm_'), 'Tracking parameters are forbidden.');
wcos_upsell_assert(false === strpos($client, 'ref='), 'Referral parameters are forbidden.');

foreach (array('split' => 'split', 'split-strategy' => 'split', 'duplicate' => 'duplicate', 'merge' => 'merge', 'return' => 'return') as $file_action => $campaign) {
	$action_client = wcos_upsell_read($root . '/js/p2-' . $file_action . '-admin.js');
	wcos_upsell_assert(1 === substr_count($action_client, "new CustomEvent('wcos:operation-completed'"), 'Exactly one bounded completion notification is required per client.');
	wcos_upsell_assert(false !== strpos($action_client, "completedPresentation = { action: '" . $campaign . "', operationId: data.operation_id, status: data.status };"), 'Notification must contain only action, operation ID and server status.');
	wcos_upsell_assert(false !== strpos($action_client, 'if (!busy && completedPresentation)'), 'Completion notification must wait for busy cleanup.');
	wcos_upsell_assert(false === strpos($action_client, 'productUrl'), 'Commercial content must stay out of the action client.');
}
$bulk_client = wcos_upsell_read($root . '/js/p2-bulk-return-admin.js');
wcos_upsell_assert(false === strpos($bulk_client, 'wcos:operation-completed'), 'Bulk Return must not join the Edit Order campaign.');

require_once $root . '/inc/backend/class-wcos-premium-upsell.php';
$upsell = WCOS_Premium_Upsell::bootstrap();
$links = $upsell->add_plugin_action_link(array('<a href="settings">Settings</a>'));
wcos_upsell_assert(2 === count($links), 'Plugin action link was not inserted.');
wcos_upsell_assert(false !== strpos($links[1], WCOS_Premium_Upsell::product_url()), 'Plugin action link does not use the canonical product URL.');
wcos_upsell_assert(false === strpos($links[1], '?'), 'Plugin action link must not include query or tracking parameters.');

$wcos_upsell_can_manage = false;
$links_without_capability = $upsell->add_plugin_action_link(array('Settings'));
wcos_upsell_assert(array('Settings') === $links_without_capability, 'Plugin action link must be hidden without manage_woocommerce.');

echo "premium-upsell-surface-smoke: ok\n";
