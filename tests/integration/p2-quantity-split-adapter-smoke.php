<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_p2_adapter_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_p2_adapter_product($name, $price = '10.00', $stock = null) {
	$product = new WC_Product_Simple();
	$product->set_name($name);
	$product->set_regular_price($price);
	if (null !== $stock) {
		$product->set_manage_stock(true);
		$product->set_stock_quantity($stock);
		$product->set_stock_status('instock');
	}
	$product->save();
	return $product;
}

function wcos_p2_adapter_order(WC_Product $product, $quantity, $status = 'pending') {
	$order = wc_create_order();
	$order->set_status($status);
	$order->set_currency('USD');
	$item_id = $order->add_product($product, $quantity);
	$order->calculate_totals(false);
	$order->save();
	return array($order, (int) $item_id);
}

function wcos_p2_adapter_children($source_id, $operation_id) {
	return WCOS_Order_Relation_Repository::find(
		array(
			array('key' => WCOS_Split_Order_Service::OPERATION_META, 'value' => sanitize_key($operation_id)),
			array('key' => WCOS_Split_Order_Service::RELATION_PARENT_META, 'value' => absint($source_id), 'type' => 'NUMERIC'),
		),
		-1
	);
}

function wcos_p2_adapter_cleanup($source_id, $operation_id = '') {
	$source = wc_get_order(absint($source_id));
	if ('' !== $operation_id) {
		foreach (wcos_p2_adapter_children($source_id, $operation_id) as $child) {
			$child->delete(true);
		}
		if ($source instanceof WC_Order) {
			WCOS_Operation_Journal::delete($source, $operation_id);
		}
	}
	if ($source instanceof WC_Order) {
		$source->delete(true);
	}
}

wcos_p2_adapter_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT), 'P2 quantity Split was production-enabled before its acceptance gate.');

$adapter = new WCOS_Split_WooCommerce_Adapter();

/* Read-only preflight: fractional quantities are supported and PII is not returned. */
$product = wcos_p2_adapter_product('WCOS P2 fractional product', '12.50', 50);
list($source, $item_id) = wcos_p2_adapter_order($product, '3.500000');
$source->set_billing_email('p2-private-preflight@example.test');
$source->save();
$report = $adapter->preflight(wc_get_order($source->get_id()));
wcos_p2_adapter_assert(!empty($report['supported']), 'A valid fractional-quantity source failed P2 preflight.');
wcos_p2_adapter_assert(1 === (int) $report['fractional_quantity_lines'], 'P2 preflight did not identify the fractional line.');
wcos_p2_adapter_assert('keep_on_source' === $report['policy']['shipping'], 'P2 preflight exposed the wrong shipping policy.');
wcos_p2_adapter_assert('no_write' === $report['policy']['physical_stock'], 'P2 preflight exposed the wrong stock policy.');
wcos_p2_adapter_assert(false === strpos(wp_json_encode($report), 'p2-private-preflight@example.test'), 'P2 preflight leaked customer PII.');

/* Production Gateway remains hard-off even though the adapter is testable internally. */
$gateway_blocked = false;
try {
	(new WCOS_Mutation_Gateway())->split(
		wc_get_order($source->get_id()),
		array('child-gateway' => array($item_id => '1.000000')),
		'p2-gateway-hard-off-' . wp_generate_uuid4()
	);
} catch (RuntimeException $exception) {
	$gateway_blocked = false !== strpos($exception->getMessage(), 'not enabled for production use');
}
wcos_p2_adapter_assert($gateway_blocked, 'The P2 Gateway allowed quantity Split while the production gate is hard-off.');

/* Safe adapter path: physical stock remains unchanged and retry is at-most-once. */
$safe_operation = 'p2-safe-split-' . wp_generate_uuid4();
$stock_before = wc_get_product($product->get_id())->get_stock_quantity();
$children = $adapter->split(
	wc_get_order($source->get_id()),
	array('child-one' => array($item_id => '1.250000')),
	$safe_operation
);
wcos_p2_adapter_assert(1 === count($children), 'P2 adapter did not create exactly one safe split child.');
wcos_p2_adapter_assert('pending' === $children[0]->get_status(), 'P2 adapter child did not remain pending.');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units('2.250000', 6) === WCOS_Decimal::to_units(wc_get_order($source->get_id())->get_item($item_id)->get_quantity(), 6),
	'P2 adapter did not preserve the expected source residual quantity.'
);
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units($stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($product->get_id())->get_stock_quantity(), 6),
	'P2 adapter changed physical stock during a safe split.'
);
$retry = $adapter->split(
	wc_get_order($source->get_id()),
	array('child-one' => array($item_id => '1.250000')),
	$safe_operation
);
wcos_p2_adapter_assert($retry[0]->get_id() === $children[0]->get_id(), 'P2 adapter retry created a second child.');
$record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $safe_operation);
wcos_p2_adapter_assert(is_array($record) && 'completed' === $record['status'], 'P2 safe split did not finish with a completed durable journal.');
wcos_p2_adapter_cleanup($source->get_id(), $safe_operation);
wp_delete_post($product->get_id(), true);

/* Unsupported coupon policy fails before journal/children and leaves the source unchanged. */
$coupon_product = wcos_p2_adapter_product('WCOS P2 coupon rejection product');
list($coupon_source, $coupon_item_id) = wcos_p2_adapter_order($coupon_product, 3);
$coupon = new WC_Order_Item_Coupon();
$coupon->set_code('p2-coupon');
$coupon->set_discount('0');
$coupon->set_discount_tax('0');
$coupon_source->add_item($coupon);
$coupon_source->save();
$coupon_operation = 'p2-coupon-reject-' . wp_generate_uuid4();
$coupon_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($coupon_source->get_id()));
$coupon_rejected = false;
try {
	$adapter->split(
		wc_get_order($coupon_source->get_id()),
		array('child-one' => array($coupon_item_id => '1.000000')),
		$coupon_operation
	);
} catch (WCOS_Split_Preflight_Exception $exception) {
	$coupon_rejected = 'coupon_policy_missing' === $exception->get_reason();
}
wcos_p2_adapter_assert($coupon_rejected, 'P2 adapter did not fail closed on a coupon-bearing source.');
wcos_p2_adapter_assert(
	$coupon_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($coupon_source->get_id())),
	'Coupon preflight rejection changed the source order.'
);
wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($coupon_source->get_id()), $coupon_operation), 'Coupon preflight rejection created a mutation journal.');
wcos_p2_adapter_assert(empty(wcos_p2_adapter_children($coupon_source->get_id(), $coupon_operation)), 'Coupon preflight rejection created a split child.');
wcos_p2_adapter_cleanup($coupon_source->get_id());
wp_delete_post($coupon_product->get_id(), true);

/* Deleted catalog products remain splittable because order-time line state is authoritative. */
$deleted_product = wcos_p2_adapter_product('WCOS P2 deleted product', '9.00');
list($deleted_source, $deleted_item_id) = wcos_p2_adapter_order($deleted_product, 3);
$deleted_product_id = $deleted_product->get_id();
wp_delete_post($deleted_product_id, true);
$deleted_report = $adapter->preflight(wc_get_order($deleted_source->get_id()));
wcos_p2_adapter_assert(!empty($deleted_report['supported']), 'P2 preflight rejected a historical line whose catalog product was deleted.');
wcos_p2_adapter_assert(1 === (int) $deleted_report['deleted_product_lines'], 'P2 preflight did not identify the deleted-product line.');
$deleted_operation = 'p2-deleted-product-' . wp_generate_uuid4();
$deleted_children = $adapter->split(
	wc_get_order($deleted_source->get_id()),
	array('child-one' => array($deleted_item_id => '1.000000')),
	$deleted_operation
);
wcos_p2_adapter_assert(1 === count($deleted_children) && 1 === count($deleted_children[0]->get_items('line_item')), 'P2 adapter did not preserve a deleted-product order line.');
wcos_p2_adapter_cleanup($deleted_source->get_id(), $deleted_operation);

/* Core WooCommerce stock writes are blocked before the data store changes. */
$guard_product = wcos_p2_adapter_product('WCOS P2 stock guard product', '5.00', 10);
$guard_stock_before = wc_get_product($guard_product->get_id())->get_stock_quantity();
$guard_token = WCOS_Stock_Side_Effect_Guard::begin('p2-stock-guard-' . wp_generate_uuid4());
$guard_detected = false;
try {
	wc_update_product_stock($guard_product->get_id(), 1, 'decrease');
} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
	$events = $exception->get_events();
	$guard_detected = !empty($events)
		&& WCOS_Stock_Side_Effect_Guard::PHASE_BLOCKED_BEFORE_WRITE === $events[0]['phase']
		&& absint($events[0]['stock_owner_id']) === $guard_product->get_id();
}
WCOS_Stock_Side_Effect_Guard::end($guard_token);
wcos_p2_adapter_assert($guard_detected, 'P2 request-local guard missed a WooCommerce stock-write attempt.');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units($guard_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($guard_product->get_id())->get_stock_quantity(), 6),
	'P2 pre-write guard allowed physical stock to change.'
);

/* Ambient catalog differences are ignored only while a clean request-local proof is active. */
$ambient_token = WCOS_Stock_Side_Effect_Guard::begin('p2-ambient-proof-' . wp_generate_uuid4());
WCOS_Order_Contract_Snapshot::assert_product_stock_equal(array(100 => '10.000000'), array(100 => '9.000000'));
WCOS_Stock_Side_Effect_Guard::end($ambient_token);
$ambient_rejected_without_guard = false;
try {
	WCOS_Order_Contract_Snapshot::assert_product_stock_equal(array(100 => '10.000000'), array(100 => '9.000000'));
} catch (RuntimeException $exception) {
	$ambient_rejected_without_guard = true;
}
wcos_p2_adapter_assert($ambient_rejected_without_guard, 'P1 fallback stock comparison was unexpectedly disabled outside a P2 adapter scope.');

/* A blocked stock-write attempt dirties the request and cannot pass conservation. */
$contract_token = WCOS_Stock_Side_Effect_Guard::begin('p2-contract-proof-' . wp_generate_uuid4());
try {
	wc_update_product_stock($guard_product->get_id(), 1, 'decrease');
} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
	/* Expected: the attempt is recorded and physical stock is unchanged. */
}
$contract_blocked = false;
try {
	WCOS_Mutation_Contract::assert_conserved(array(), array(), wc_get_price_decimals());
} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
	$contract_blocked = true;
}
WCOS_Stock_Side_Effect_Guard::end($contract_token);
wcos_p2_adapter_assert($contract_blocked, 'A request-local stock-write attempt passed the mutation conservation contract.');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units($guard_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($guard_product->get_id())->get_stock_quantity(), 6),
	'Conservation stock-write proof changed physical stock.'
);
wp_delete_post($guard_product->get_id(), true);

/* Actual adapter injection: stock attempt is blocked, journal stays retriable, then retry completes at-most-once. */
$dirty_product = wcos_p2_adapter_product('WCOS P2 blocked split product', '7.00', 20);
list($dirty_source, $dirty_item_id) = wcos_p2_adapter_order($dirty_product, 4);
$dirty_operation = 'p2-blocked-split-' . wp_generate_uuid4();
$dirty_stock_before = wc_get_product($dirty_product->get_id())->get_stock_quantity();
$dirty_injected = false;
$dirty_callback = static function($stage) use (&$dirty_injected, $dirty_product) {
	if (!$dirty_injected && 'before_source_save' === $stage) {
		$dirty_injected = true;
		wc_update_product_stock($dirty_product->get_id(), 1, 'decrease');
	}
};
add_action('wcos_split_mutation_checkpoint', $dirty_callback, 10, 4);
$dirty_blocked = false;
try {
	$adapter->split(
		wc_get_order($dirty_source->get_id()),
		array('child-one' => array($dirty_item_id => '1.000000')),
		$dirty_operation
	);
} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
	$events = $exception->get_events();
	$dirty_blocked = !empty($events) && !WCOS_Stock_Side_Effect_Guard::events_require_manual_reconciliation($events);
}
remove_action('wcos_split_mutation_checkpoint', $dirty_callback, 10);
wcos_p2_adapter_assert($dirty_injected && $dirty_blocked, 'P2 adapter did not block an in-request stock-write attempt.');
wcos_p2_adapter_assert(
	WCOS_Decimal::to_units($dirty_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($dirty_product->get_id())->get_stock_quantity(), 6),
	'Injected Split stock attempt changed physical stock.'
);
$dirty_record = WCOS_Operation_Journal::get(wc_get_order($dirty_source->get_id()), $dirty_operation);
wcos_p2_adapter_assert(is_array($dirty_record) && 'failed' === $dirty_record['status'], 'Blocked stock attempt did not leave the Split operation in a retriable failed state.');
$existing_children = wcos_p2_adapter_children($dirty_source->get_id(), $dirty_operation);
wcos_p2_adapter_assert(1 === count($existing_children), 'Interrupted Split did not preserve exactly one reusable child.');
$dirty_retry = $adapter->split(
	wc_get_order($dirty_source->get_id()),
	array('child-one' => array($dirty_item_id => '1.000000')),
	$dirty_operation
);
wcos_p2_adapter_assert(1 === count($dirty_retry) && $dirty_retry[0]->get_id() === $existing_children[0]->get_id(), 'Blocked stock-attempt retry duplicated the child order.');
$dirty_record = WCOS_Operation_Journal::get(wc_get_order($dirty_source->get_id()), $dirty_operation);
wcos_p2_adapter_assert('completed' === $dirty_record['status'], 'Blocked stock-attempt retry did not complete the original operation.');
wcos_p2_adapter_cleanup($dirty_source->get_id(), $dirty_operation);
wp_delete_post($dirty_product->get_id(), true);

/* A confirmed after-write fallback event disables automatic order-only compensation. */
$fallback_product = wcos_p2_adapter_product('WCOS P2 fallback stock evidence', '6.00', 11);
$fallback_token = WCOS_Stock_Side_Effect_Guard::begin('p2-after-write-fallback-' . wp_generate_uuid4());
WCOS_Stock_Side_Effect_Guard::record_product_stock_write($fallback_product);
wcos_p2_adapter_assert(WCOS_Stock_Side_Effect_Guard::has_physical_write_active_scope(), 'P2 fallback after-write evidence was not classified for manual reconciliation.');
WCOS_Stock_Side_Effect_Guard::end($fallback_token);
wp_delete_post($fallback_product->get_id(), true);

/* Retention remains dormant while production gates are hard-off and never purges active recovery. */
wp_clear_scheduled_hook(WCOS_Operation_Journal_Retention::CRON_HOOK);
WCOS_Operation_Journal_Retention::maybe_schedule();
wcos_p2_adapter_assert(false === wp_next_scheduled(WCOS_Operation_Journal_Retention::CRON_HOOK), 'Journal cleanup was scheduled while every production workflow gate is hard-off.');
$now = time();
wcos_p2_adapter_assert(
	WCOS_Operation_Journal_Retention::is_expired_terminal_record(array('status' => 'completed', 'completed_at' => gmdate('c', $now - (100 * DAY_IN_SECONDS))), $now),
	'Expired completed mutation journal was not eligible for retention cleanup.'
);
wcos_p2_adapter_assert(
	!WCOS_Operation_Journal_Retention::is_expired_terminal_record(array('status' => 'completed', 'completed_at' => gmdate('c', $now - DAY_IN_SECONDS)), $now),
	'Recent completed mutation journal was eligible for premature cleanup.'
);
wcos_p2_adapter_assert(
	!WCOS_Operation_Journal_Retention::is_expired_terminal_record(array('status' => 'recovery_required', 'completed_at' => gmdate('c', $now - (200 * DAY_IN_SECONDS))), $now),
	'Recovery-required mutation journal was eligible for destructive cleanup.'
);

echo "p2-quantity-split-adapter-foundation-ok\n";
