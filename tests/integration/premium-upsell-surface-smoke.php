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

$settings = wcos_upsell_read($root . '/inc/backend/settings.php');
$boundary = wcos_upsell_read($root . '/inc/backend/class-wcos-premium-upsell.php');
$script = wcos_upsell_read($root . '/inc/cores/script.php');
$client = wcos_upsell_read($root . '/js/post-action-tip.js');

wcos_upsell_assert(false !== strpos($boundary, "const PRODUCT_URL = 'https://yoohw.com/product/woocommerce-advanced-order-actions/';"), 'Canonical product URL missing.');
wcos_upsell_assert(false !== strpos($boundary, "current_user_can('manage_woocommerce')"), 'Promotion capability gate missing.');
wcos_upsell_assert(false !== strpos($boundary, "'split' => 3"), 'Split threshold must be 3.');
wcos_upsell_assert(false !== strpos($boundary, "'duplicate' => 2"), 'Duplicate threshold must be 2.');
wcos_upsell_assert(false !== strpos($boundary, "'merge' => 2"), 'Merge threshold must be 2.');
wcos_upsell_assert(false !== strpos($boundary, "'wcos_split_execute' => 'split'"), 'Manual Split execute mapping missing.');
wcos_upsell_assert(false !== strpos($boundary, "'wcos_split_strategy_execute' => 'split'"), 'Strategy Split execute mapping missing.');
wcos_upsell_assert(false !== strpos($boundary, "'wcos_duplicate_execute' => 'duplicate'"), 'Duplicate execute mapping missing.');
wcos_upsell_assert(false !== strpos($boundary, "'wcos_merge_execute' => 'merge'"), 'Merge execute mapping missing.');

wcos_upsell_assert(false !== strpos($settings, "$sub_sub_tabs['premium'] = esc_html__('Upgrade'"), 'Historical premium section key must render Upgrade.');
wcos_upsell_assert(false !== strpos($settings, 'standalone premium replacement for Order Splitter'), 'Standalone replacement positioning missing.');
wcos_upsell_assert(false !== strpos($settings, "esc_html_e('Order Splitter'"), 'Order Splitter comparison column missing.');
wcos_upsell_assert(false !== strpos($settings, "esc_html_e('Advanced Order Actions'"), 'Advanced Order Actions comparison column missing.');
wcos_upsell_assert(false === strpos($settings, "esc_html__('Locked'"), 'Upgrade matrix must not use Locked.');

wcos_upsell_assert(false !== strpos($script, "include_once $root . 'backend/class-wcos-premium-upsell.php';"), 'Presentation boundary is not loaded.');
wcos_upsell_assert(false !== strpos($script, 'WCOS_Premium_Upsell::bootstrap();'), 'Presentation boundary is not bootstrapped.');

wcos_upsell_assert(false !== strpos($client, 'payload.success !== true'), 'Success-only guard missing.');
wcos_upsell_assert(false !== strpos($client, 'seenOperations'), 'Operation replay deduplication state missing.');
wcos_upsell_assert(false !== strpos($client, 'renderPendingTip();'), 'Later-page pending promotion render missing.');
wcos_upsell_assert(false !== strpos($client, 'response.clone().json()'), 'Fetch observation must clone rather than consume the mutation response.');
wcos_upsell_assert(false === strpos($client, 'navigator.sendBeacon'), 'Telemetry via sendBeacon is forbidden.');
wcos_upsell_assert(false === strpos($client, 'XMLHttpRequest'), 'Upsell client must not create XHR telemetry.');
wcos_upsell_assert(false === strpos($client, 'utm_'), 'Tracking parameters are forbidden.');
wcos_upsell_assert(false === strpos($client, 'ref='), 'Referral parameters are forbidden.');

echo "premium-upsell-surface-smoke: ok\n";
