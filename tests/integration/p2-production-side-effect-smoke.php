<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_p2_side_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_p2_side_note_count($order_id) {
	return count(wc_get_order_notes(array('order_id' => absint($order_id), 'limit' => -1)));
}

$product = new WC_Product_Simple();
$product->set_name('WCOS P2 production side-effect product');
$product->set_regular_price('10.00');
$product_id = $product->save();

$source = wc_create_order();
$source->set_status('pending');
$source->set_currency('USD');
$item_id = $source->add_product($product, 4);
$source->calculate_totals(false);
$source->save();
$source_id = $source->get_id();
$source_note_before = wcos_p2_side_note_count($source_id);

/* Use a real active WooCommerce order.created webhook but intercept delivery scheduling. */
$webhook = new WC_Webhook();
$webhook->set_name('WCOS P2 order-created contract');
$webhook->set_status('active');
$webhook->set_topic('order.created');
$webhook->set_delivery_url('https://example.invalid/wcos-p2-webhook');
$webhook->set_secret('wcos-p2-test-secret');
$webhook->set_user_id(1);
$webhook->save();
$webhook->enqueue();

$core_webhook_scheduler_registered = false !== has_action('woocommerce_webhook_process_delivery', 'wc_webhook_process_delivery');
if ($core_webhook_scheduler_registered) {
	remove_action('woocommerce_webhook_process_delivery', 'wc_webhook_process_delivery', 10);
}

$new_order_ids = array();
$status_events = array();
$webhook_args = array();
$mail_calls = array();
$stock_before_writes = array();
$stock_after_writes = array();

$new_order_callback = static function($order_id, $order = null) use (&$new_order_ids) {
	if (!$order instanceof WC_Order) {
		$order = wc_get_order($order_id);
	}
	if ($order instanceof WC_Order && 'wc-order-splitter-split' === $order->get_created_via()) {
		$new_order_ids[] = absint($order_id);
	}
};
$status_callback = static function($order_id, $from, $to, $order) use (&$status_events) {
	if ($order instanceof WC_Order && 'wc-order-splitter-split' === $order->get_created_via()) {
		$status_events[] = array(absint($order_id), (string) $from, (string) $to);
	}
};
$webhook_callback = static function($active_webhook, $arg) use (&$webhook_args, $webhook) {
	if ($active_webhook instanceof WC_Webhook && $active_webhook->get_id() === $webhook->get_id()) {
		$webhook_args[] = absint($arg);
	}
};
$mail_callback = static function($return, $atts) use (&$mail_calls) {
	$mail_calls[] = is_array($atts) ? $atts : array();
	return true;
};
$stock_before_callback = static function($stock_product) use (&$stock_before_writes) {
	if ($stock_product instanceof WC_Product) {
		$stock_before_writes[] = $stock_product->get_id();
	}
};
$stock_after_callback = static function($stock_product) use (&$stock_after_writes) {
	if ($stock_product instanceof WC_Product) {
		$stock_after_writes[] = $stock_product->get_id();
	}
};

add_action('woocommerce_new_order', $new_order_callback, PHP_INT_MAX, 2);
add_action('woocommerce_order_status_changed', $status_callback, PHP_INT_MAX, 4);
add_action('woocommerce_webhook_process_delivery', $webhook_callback, PHP_INT_MAX, 2);
add_filter('pre_wp_mail', $mail_callback, PHP_INT_MAX, 2);
add_action('woocommerce_product_before_set_stock', $stock_before_callback, PHP_INT_MAX, 1);
add_action('woocommerce_variation_before_set_stock', $stock_before_callback, PHP_INT_MAX, 1);
add_action('woocommerce_product_set_stock', $stock_after_callback, PHP_INT_MAX, 1);
add_action('woocommerce_variation_set_stock', $stock_after_callback, PHP_INT_MAX, 1);

$operation_id = 'p2-side-effect-contract-' . wp_generate_uuid4();
$plan = array(
	'child-one' => array($item_id => '1.000000'),
	'child-two' => array($item_id => '1.000000'),
);
$adapter = new WCOS_Split_WooCommerce_Adapter();
$children = $adapter->split(wc_get_order($source_id), $plan, $operation_id);
wcos_p2_side_assert(2 === count($children), 'P2 side-effect contract did not create exactly two children.');
$child_ids = array_values(array_map(static function($child) { return $child->get_id(); }, $children));
sort($child_ids, SORT_NUMERIC);
$new_order_sorted = $new_order_ids;
sort($new_order_sorted, SORT_NUMERIC);
$webhook_sorted = $webhook_args;
sort($webhook_sorted, SORT_NUMERIC);

wcos_p2_side_assert($child_ids === $new_order_sorted, 'P2 Split did not emit exactly one WooCommerce new-order event per child.');
wcos_p2_side_assert($child_ids === $webhook_sorted, 'Active order.created webhook was not scheduled exactly once per child.');
wcos_p2_side_assert(empty($status_events), 'P2 Split emitted an implicit child status transition.');
wcos_p2_side_assert(empty($mail_calls), 'P2 Split attempted to send an email for pending children or operation notes.');
wcos_p2_side_assert(empty($stock_before_writes) && empty($stock_after_writes), 'P2 safe Split invoked a physical stock-write hook.');
wcos_p2_side_assert($source_note_before + 1 === wcos_p2_side_note_count($source_id), 'P2 Split did not add exactly one operation note to the source.');
foreach ($child_ids as $child_id) {
	wcos_p2_side_assert(1 === wcos_p2_side_note_count($child_id), 'P2 Split did not add exactly one operation note to a child.');
}

$counts_before_retry = array(
	'new_order' => count($new_order_ids),
	'webhook' => count($webhook_args),
	'status' => count($status_events),
	'mail' => count($mail_calls),
	'source_notes' => wcos_p2_side_note_count($source_id),
);
$child_notes_before_retry = array();
foreach ($child_ids as $child_id) {
	$child_notes_before_retry[$child_id] = wcos_p2_side_note_count($child_id);
}
$retry = $adapter->split(wc_get_order($source_id), $plan, $operation_id);
$retry_ids = array_values(array_map(static function($child) { return $child->get_id(); }, $retry));
sort($retry_ids, SORT_NUMERIC);
wcos_p2_side_assert($child_ids === $retry_ids, 'P2 completed retry returned a different child set.');
wcos_p2_side_assert($counts_before_retry['new_order'] === count($new_order_ids), 'P2 completed retry emitted another new-order event.');
wcos_p2_side_assert($counts_before_retry['webhook'] === count($webhook_args), 'P2 completed retry scheduled another webhook delivery.');
wcos_p2_side_assert($counts_before_retry['status'] === count($status_events), 'P2 completed retry emitted a status transition.');
wcos_p2_side_assert($counts_before_retry['mail'] === count($mail_calls), 'P2 completed retry attempted to send email.');
wcos_p2_side_assert($counts_before_retry['source_notes'] === wcos_p2_side_note_count($source_id), 'P2 completed retry duplicated the source operation note.');
foreach ($child_ids as $child_id) {
	wcos_p2_side_assert($child_notes_before_retry[$child_id] === wcos_p2_side_note_count($child_id), 'P2 completed retry duplicated a child operation note.');
}

remove_action('woocommerce_new_order', $new_order_callback, PHP_INT_MAX);
remove_action('woocommerce_order_status_changed', $status_callback, PHP_INT_MAX);
remove_action('woocommerce_webhook_process_delivery', $webhook_callback, PHP_INT_MAX);
remove_filter('pre_wp_mail', $mail_callback, PHP_INT_MAX);
remove_action('woocommerce_product_before_set_stock', $stock_before_callback, PHP_INT_MAX);
remove_action('woocommerce_variation_before_set_stock', $stock_before_callback, PHP_INT_MAX);
remove_action('woocommerce_product_set_stock', $stock_after_callback, PHP_INT_MAX);
remove_action('woocommerce_variation_set_stock', $stock_after_callback, PHP_INT_MAX);
if ($core_webhook_scheduler_registered) {
	add_action('woocommerce_webhook_process_delivery', 'wc_webhook_process_delivery', 10, 2);
}
foreach ((array) $webhook->get_hooks() as $hook) {
	remove_action($hook, array($webhook, 'process'));
}
$webhook->delete(true);

$source = wc_get_order($source_id);
WCOS_Operation_Journal::delete($source, $operation_id);
foreach ($child_ids as $child_id) {
	$child = wc_get_order($child_id);
	if ($child) {
		$child->delete(true);
	}
}
if ($source) {
	$source->delete(true);
}
wp_delete_post($product_id, true);

echo "p2-production-side-effect-contract-ok\n";
