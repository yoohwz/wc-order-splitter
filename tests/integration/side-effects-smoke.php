<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_side_effect_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_side_effect_target($order) {
	if (!$order instanceof WC_Order) {
		return false;
	}
	return in_array(
		$order->get_created_via(),
		array('wc-order-splitter-duplicate', 'wc-order-splitter-split'),
		true
	);
}

$product = new WC_Product_Simple();
$product->set_name('WCOS side-effect product');
$product->set_regular_price('12.50');
$product_id = $product->save();

$duplicate_source = wc_create_order();
$duplicate_source->set_currency('USD');
$duplicate_source->add_product($product, 2);
$duplicate_source->calculate_totals(false);
$duplicate_source->save();

$split_source = wc_create_order();
$split_source->set_currency('USD');
$split_item_id = $split_source->add_product($product, 4);
$split_source->calculate_totals(false);
$split_source->save();

$new_order_events = array();
$status_events = array();
$unexpected_status_events = array();

$new_order_callback = static function($order_id, $order) use (&$new_order_events) {
	if (wcos_side_effect_target($order)) {
		$new_order_events[] = (int) $order_id;
	}
};
$status_callback = static function($order_id, $from, $to, $order) use (&$status_events) {
	if (wcos_side_effect_target($order)) {
		$status_events[] = array(
			'order_id' => (int) $order_id,
			'from' => (string) $from,
			'to' => (string) $to,
		);
	}
};
$unexpected_callback = static function($order_id, $order = null) use (&$unexpected_status_events) {
	if (!$order) {
		$order = wc_get_order($order_id);
	}
	if (wcos_side_effect_target($order)) {
		$unexpected_status_events[] = (int) $order_id;
	}
};

add_action('woocommerce_new_order', $new_order_callback, 10, 2);
add_action('woocommerce_order_status_changed', $status_callback, 10, 4);
foreach (array('processing', 'on-hold', 'completed', 'cancelled', 'failed', 'refunded') as $status) {
	add_action('woocommerce_order_status_' . $status, $unexpected_callback, 10, 2);
}

$duplicate_service = new WCOS_Duplicate_Order_Service();
$duplicate_operation = 'integration-side-effect-duplicate-' . wp_generate_uuid4();
$duplicate = $duplicate_service->duplicate($duplicate_source, $duplicate_operation);
wcos_side_effect_assert(1 === count($new_order_events), 'A normal duplicate did not emit exactly one new-order event.');
wcos_side_effect_assert($duplicate->get_id() === $new_order_events[0], 'The duplicate new-order event referenced the wrong target.');
$events_after_duplicate = count($new_order_events);
$status_after_duplicate = count($status_events);
$duplicate_retry = $duplicate_service->duplicate(wc_get_order($duplicate_source->get_id()), $duplicate_operation);
wcos_side_effect_assert($duplicate_retry->get_id() === $duplicate->get_id(), 'Duplicate retry returned a different target.');
wcos_side_effect_assert($events_after_duplicate === count($new_order_events), 'Duplicate retry emitted a second new-order event.');
wcos_side_effect_assert($status_after_duplicate === count($status_events), 'Duplicate retry emitted an additional status transition.');

$duplicate_recovery_operation = 'integration-side-effect-duplicate-recovery-' . wp_generate_uuid4();
$duplicate_fail_once = true;
$duplicate_failure = static function($stage) use (&$duplicate_fail_once) {
	if ($duplicate_fail_once && 'after_target_save' === $stage) {
		$duplicate_fail_once = false;
		throw new RuntimeException('Injected side-effect duplicate crash.');
	}
};
add_action('wcos_duplicate_mutation_checkpoint', $duplicate_failure, 10, 4);
try {
	$duplicate_service->duplicate(wc_get_order($duplicate_source->get_id()), $duplicate_recovery_operation);
} catch (RuntimeException $exception) {
	wcos_side_effect_assert(false !== strpos($exception->getMessage(), 'Injected side-effect duplicate crash'), 'Unexpected duplicate recovery failure.');
}
remove_action('wcos_duplicate_mutation_checkpoint', $duplicate_failure, 10);
wcos_side_effect_assert(2 === count($new_order_events), 'Interrupted duplicate did not emit exactly one durable target event.');
$events_before_duplicate_recovery = count($new_order_events);
$status_before_duplicate_recovery = count($status_events);
$recovered_duplicate = $duplicate_service->duplicate(wc_get_order($duplicate_source->get_id()), $duplicate_recovery_operation);
wcos_side_effect_assert($events_before_duplicate_recovery === count($new_order_events), 'Duplicate recovery emitted a second new-order event.');
wcos_side_effect_assert($status_before_duplicate_recovery === count($status_events), 'Duplicate recovery emitted an additional status transition.');

$split_service = new WCOS_Split_Order_Service();
$split_operation = 'integration-side-effect-split-' . wp_generate_uuid4();
$split_plan = array(
	'child-one' => array($split_item_id => '1.000000'),
	'child-two' => array($split_item_id => '1.000000'),
);
$split_fail_once = true;
$split_failure = static function($stage) use (&$split_fail_once) {
	if ($split_fail_once && 'after_child_save' === $stage) {
		$split_fail_once = false;
		throw new RuntimeException('Injected side-effect split crash.');
	}
};
add_action('wcos_split_mutation_checkpoint', $split_failure, 10, 4);
try {
	$split_service->split($split_source, $split_plan, $split_operation);
} catch (RuntimeException $exception) {
	wcos_side_effect_assert(false !== strpos($exception->getMessage(), 'Injected side-effect split crash'), 'Unexpected split recovery failure.');
}
remove_action('wcos_split_mutation_checkpoint', $split_failure, 10);
wcos_side_effect_assert(3 === count($new_order_events), 'Interrupted split did not emit exactly one first-child event.');

$split_children = $split_service->split(wc_get_order($split_source->get_id()), $split_plan, $split_operation);
wcos_side_effect_assert(2 === count($split_children), 'Recovered split did not return exactly two children.');
wcos_side_effect_assert(4 === count($new_order_events), 'Recovered split emitted an unexpected number of new-order events.');
$split_ids = array_map(static function($order) { return $order->get_id(); }, $split_children);
sort($split_ids, SORT_NUMERIC);
$event_ids = $new_order_events;
sort($event_ids, SORT_NUMERIC);
wcos_side_effect_assert(count($event_ids) === count(array_unique($event_ids)), 'A mutation target emitted the new-order event more than once.');
foreach ($split_ids as $split_id) {
	wcos_side_effect_assert(in_array($split_id, $new_order_events, true), 'A split child is missing its single new-order event.');
}

$events_before_split_retry = count($new_order_events);
$status_before_split_retry = count($status_events);
$split_retry = $split_service->split(wc_get_order($split_source->get_id()), $split_plan, $split_operation);
wcos_side_effect_assert($split_ids === array_map(static function($order) { return $order->get_id(); }, $split_retry), 'Completed split retry returned a different target set.');
wcos_side_effect_assert($events_before_split_retry === count($new_order_events), 'Completed split retry emitted new-order events.');
wcos_side_effect_assert($status_before_split_retry === count($status_events), 'Completed split retry emitted status transitions.');
wcos_side_effect_assert(empty($unexpected_status_events), 'A hardened target entered a fulfillment/payment status implicitly.');

remove_action('woocommerce_new_order', $new_order_callback, 10);
remove_action('woocommerce_order_status_changed', $status_callback, 10);
foreach (array('processing', 'on-hold', 'completed', 'cancelled', 'failed', 'refunded') as $status) {
	remove_action('woocommerce_order_status_' . $status, $unexpected_callback, 10);
}

WCOS_Operation_Journal::delete($duplicate_source, $duplicate_operation);
WCOS_Operation_Journal::delete($duplicate_source, $duplicate_recovery_operation);
WCOS_Operation_Journal::delete($split_source, $split_operation);
foreach ($split_children as $child) {
	$child->delete(true);
}
$recovered_duplicate->delete(true);
$duplicate->delete(true);
$split_source->delete(true);
$duplicate_source->delete(true);
wp_delete_post($product_id, true);

echo "mutation-side-effects-idempotent-ok\n";
