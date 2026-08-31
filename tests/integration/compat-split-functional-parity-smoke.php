<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_compat_split_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_compat_split_product($name, $price, $stock_status = 'instock') {
	$product = new WC_Product_Simple();
	$product->set_name($name);
	$product->set_regular_price($price);
	$product->set_stock_status($stock_status);
	$product->save();
	return $product;
}

function wcos_compat_split_order($status, array $product_quantities) {
	$order = wc_create_order();
	$order->set_currency('USD');
	foreach ($product_quantities as $entry) {
		$order->add_product($entry[0], $entry[1]);
	}
	$order->calculate_totals(false);
	$order->set_status($status);
	$order->save();
	return wc_get_order($order->get_id());
}

function wcos_compat_split_confirm(WC_Order $source, array $plan, $user_id) {
	$preflight = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($source);
	wcos_compat_split_assert(!empty($preflight['supported']), 'Manual Split preflight rejected a supported compatibility fixture: ' . (isset($preflight['reason']) ? $preflight['reason'] : 'unknown'));
	$confirmation = WCOS_Split_Confirmation_Store::create($source, $plan, $preflight, $user_id);
	return WCOS_Split_Confirmation_Store::verify($source, $confirmation['operation_id'], $confirmation['confirmation_token'], $user_id);
}

function wcos_compat_split_return(WC_Order $child, $user_id) {
	$report = WCOS_Return_Preflight::assert_supported($child, true);
	$authority = WCOS_Return_Review_Store::authority_from_preflight($child, $report);
	$created = WCOS_Return_Confirmation_Store::create($child, $authority, $user_id);
	$confirmed = WCOS_Return_Confirmation_Store::verify(
		$child,
		$created['operation_id'],
		$created['confirmation_token'],
		$user_id
	);
	return (new WCOS_Mutation_Gateway())->return_order(
		$child,
		$created['operation_id'],
		$confirmed['price_precision'],
		WCOS_Return_Confirmation_Store::operation_authority($confirmed)
	);
}

$previous_user_id = get_current_user_id();
$previous_allowed = get_option('order_splitter_status_allowed', array('wc-processing'));
$previous_shipping = get_option('order_splitter_exclude_shipping_fee', 'no');
$order_ids = array();
$product_ids = array();
$term_ids = array();
$user_id = 0;

$custom_status_filter = static function($statuses) {
	$statuses['wc-packed'] = 'Packed';
	return $statuses;
};
add_filter('wc_order_statuses', $custom_status_filter);
register_post_status('wc-packed', array(
	'label' => 'Packed',
	'public' => true,
	'exclude_from_search' => false,
	'show_in_admin_all_list' => true,
	'show_in_admin_status_list' => true,
));

try {
	$user_id = wp_insert_user(array(
		'user_login' => 'wcos_compat_split_' . wp_generate_password(8, false),
		'user_pass' => wp_generate_password(24, true),
		'user_email' => 'wcos-compat-split-' . wp_generate_uuid4() . '@example.test',
		'role' => 'administrator',
	));
	wcos_compat_split_assert(!is_wp_error($user_id), 'Unable to create the Split compatibility operator.');
	wp_set_current_user($user_id);
	$duplicate_policy = WCOS_Duplicate_Preflight::policy();
	wcos_compat_split_assert('pending' === $duplicate_policy['target_status'] && 'do_not_copy' === $duplicate_policy['payment_transaction'], 'Split payment ownership compatibility changed Duplicate policy.');
	update_option('order_splitter_status_allowed', array('wc-completed', 'packed', 'wc-on-hold', 'wc-not-registered'));
	update_option('order_splitter_exclude_shipping_fee', 'no');

	$product = wcos_compat_split_product('WCOS compatibility commercial line', '10.00');
	$product_ids[] = $product->get_id();
	$source = wcos_compat_split_order('completed', array(array($product, 5)));
	$order_ids[] = $source->get_id();
	$source_item_id = (int) key($source->get_items('line_item'));

	$source_item = $source->get_item($source_item_id);
	$source_item->set_subtotal('50.00');
	$source_item->set_total('45.00');
	$source_item->save();
	$source = wc_get_order($source->get_id());

	foreach (array(
		array('Historical flat rate', 'flat_rate', 17, '4.25', 'Package A', array('total' => array(303 => '0.50'))),
		array('Historical pickup', 'local_pickup', 23, '2.75', 'Package B', array('total' => array())),
	) as $shipping_row) {
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_props(array(
			'method_title' => $shipping_row[0],
			'method_id' => $shipping_row[1],
			'instance_id' => $shipping_row[2],
			'total' => $shipping_row[3],
			'taxes' => $shipping_row[5],
		));
		$shipping->add_meta_data('Package label', $shipping_row[4], true);
		$source->add_item($shipping);
	}
	$shipping_tax = new WC_Order_Item_Tax();
	$shipping_tax->set_props(array(
		'rate_id' => 303,
		'label' => 'Historical shipping tax',
		'tax_total' => '0.00',
		'shipping_tax_total' => '0.50',
		'compound' => false,
		'rate_percent' => 10,
	));
	$source->add_item($shipping_tax);
	foreach (array(array('Handling', '1.50'), array('Historical adjustment', '-0.50')) as $fee_row) {
		$fee = new WC_Order_Item_Fee();
		$fee->set_name($fee_row[0]);
		$fee->set_amount($fee_row[1]);
		$fee->set_total($fee_row[1]);
		$fee->set_taxes(array('total' => array()));
		$source->add_item($fee);
	}
	$coupon = new WC_Order_Item_Coupon();
	$coupon->set_code('historical-five');
	$coupon->set_discount('5.00');
	$coupon->set_discount_tax('0.00');
	$source->add_item($coupon);
	WCOS_Order_Totals_Rebuilder::rebuild($source);
	$source->save();
	$source = wc_get_order($source->get_id());

	$preflight = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($source);
	wcos_compat_split_assert(!empty($preflight['supported']), 'Completed order with source-owned commercial rows was rejected: ' . (isset($preflight['reason'], $preflight['message']) ? $preflight['reason'] . ' / ' . $preflight['message'] : 'unknown'));
	$policy = WCOS_Split_Commercial_Policy::assert_valid($preflight['policy']);
	wcos_compat_split_assert(WCOS_Split_Commercial_Policy::POLICY_VERSION === (int) $policy['policy_version'], 'Review did not freeze the current commercial policy version.');
	wcos_compat_split_assert(array('completed', 'on-hold', 'packed') === $policy['allowed_statuses'], 'Configured statuses were not normalized against registered WooCommerce statuses.');
	wcos_compat_split_assert('completed' === $policy['source_status'] && 'completed' === $policy['child_status'], 'Completed source status was not frozen for child inheritance.');
	wcos_compat_split_assert(WCOS_Split_Commercial_Policy::SHIPPING_REPLICATE_TO_EACH_CHILD === $policy['shipping'], 'Shipping replication setting was not frozen.');
	wcos_compat_split_assert('source_only' === $policy['negative_fees'] && 'source_only' === $policy['coupons'], 'Fee/coupon source ownership was not explicit.');
	wcos_compat_split_assert(false === strpos(wp_json_encode($policy), '@example.test'), 'Commercial policy leaked customer PII.');

	$plan = array(
		'child-commercial-a' => array($source_item_id => '2.000000'),
		'child-commercial-b' => array($source_item_id => '2.000000'),
	);
	$confirmation = WCOS_Split_Confirmation_Store::create($source, $plan, $preflight, $user_id);
	$verified = WCOS_Split_Confirmation_Store::verify($source, $confirmation['operation_id'], $confirmation['confirmation_token'], $user_id);
	$mail_calls = array();
	$mail_filter = static function($return, $atts) use (&$mail_calls) {
		$mail_calls[] = $atts;
		return true;
	};
	add_filter('pre_wp_mail', $mail_filter, 10, 2);
	$children = (new WCOS_Mutation_Gateway())->split_manual_confirmed(
		$source,
		$verified['plan'],
		$verified['operation_id'],
		$verified['price_precision'],
		$verified
	);
	remove_filter('pre_wp_mail', $mail_filter, 10);
	wcos_compat_split_assert(2 === count($children), 'Commercial parity Split did not create exactly two children.');
	$child = reset($children);
	foreach ($children as $commercial_child) {
		$order_ids[] = $commercial_child->get_id();
		wcos_compat_split_assert('completed' === $commercial_child->get_status(), 'Child did not inherit the exact completed source status.');
		wcos_compat_split_assert(2 === count($commercial_child->get_items('shipping')), 'Every historical shipping row was not replicated to every child.');
		wcos_compat_split_assert(empty($commercial_child->get_items('fee')) && empty($commercial_child->get_items('coupon')), 'Fee or coupon ownership escaped the source.');
		$commercial_shipping = array_values($commercial_child->get_items('shipping'));
		$commercial_shipping_taxes = $commercial_shipping[0]->get_taxes();
		wcos_compat_split_assert(isset($commercial_shipping_taxes['total'][303]) && 50 === WCOS_Decimal::to_units($commercial_shipping_taxes['total'][303], 2), 'Historical taxed shipping was not replicated exactly.');
	}
	wcos_compat_split_assert(empty($mail_calls), 'Status-preserving child persistence attempted to send transactional email.');
	$source = wc_get_order($source->get_id());
	wcos_compat_split_assert(2 === count($source->get_items('shipping')) && 2 === count($source->get_items('fee')) && 1 === count($source->get_items('coupon')), 'Source commercial row ownership changed.');
	$journal = WCOS_Operation_Journal::get($source, $confirmation['operation_id']);
	wcos_compat_split_assert($policy['policy_fingerprint'] === $journal['context']['commercial_policy']['policy_fingerprint'], 'Journal did not freeze exact commercial policy authority.');
	$replay = (new WCOS_Mutation_Gateway())->split_manual_confirmed($source, $verified['plan'], $verified['operation_id'], $verified['price_precision'], WCOS_Split_Confirmation_Store::verify($source, $verified['operation_id'], $confirmation['confirmation_token'], $user_id));
	$child_ids = array_map(static function(WC_Order $order) { return $order->get_id(); }, $children);
	$replay_ids = array_map(static function(WC_Order $order) { return $order->get_id(); }, $replay);
	sort($child_ids, SORT_NUMERIC);
	sort($replay_ids, SORT_NUMERIC);
	wcos_compat_split_assert($child_ids === $replay_ids, 'Completed replay changed the commercial child set or replicated extra shipping.');

	/* Nested Split keeps the actual child as immediate parent, never the root. */
	$nested_source = wc_get_order($child->get_id());
	$nested_item_id = (int) key($nested_source->get_items('line_item'));
	$nested_plan = array('grandchild' => array($nested_item_id => '1.000000'));
	$nested_verified = wcos_compat_split_confirm($nested_source, $nested_plan, $user_id);
	$grandchildren = (new WCOS_Mutation_Gateway())->split_manual_confirmed($nested_source, $nested_verified['plan'], $nested_verified['operation_id'], $nested_verified['price_precision'], $nested_verified);
	$grandchild = reset($grandchildren);
	$order_ids[] = $grandchild->get_id();
	wcos_compat_split_assert($child->get_id() === (int) $grandchild->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true), 'Nested Split did not record the actual source as immediate parent.');
	wcos_compat_split_assert($child->get_id() === (int) $grandchild->get_meta('yoos_original_order', true), 'Nested legacy relation did not point to the immediate source.');
	wcos_compat_split_assert(!in_array($grandchild->get_id(), (array) $source->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true), true), 'Nested descendant was flattened into the root child list.');

	/* The checked option keeps shipping only on the source. */
	update_option('order_splitter_exclude_shipping_fee', 'yes');
	$keep_source = wcos_compat_split_order('completed', array(array($product, 3)));
	$order_ids[] = $keep_source->get_id();
	$keep_item_id = (int) key($keep_source->get_items('line_item'));
	$keep_shipping = new WC_Order_Item_Shipping();
	$keep_shipping->set_method_title('Source only shipping');
	$keep_shipping->set_method_id('flat_rate');
	$keep_shipping->set_total('3.00');
	$keep_source->add_item($keep_shipping);
	$keep_source->calculate_totals(false);
	$keep_source->save();
	$keep_source = wc_get_order($keep_source->get_id());
	$keep_verified = wcos_compat_split_confirm($keep_source, array('keep-child' => array($keep_item_id => '1.000000')), $user_id);
	$keep_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed($keep_source, $keep_verified['plan'], $keep_verified['operation_id'], $keep_verified['price_precision'], $keep_verified);
	$keep_child = reset($keep_children);
	$order_ids[] = $keep_child->get_id();
	wcos_compat_split_assert(empty($keep_child->get_items('shipping')), 'Checked shipping exclusion did not keep shipping only on the source.');
	wcos_compat_split_assert(1 === count(wc_get_order($keep_source->get_id())->get_items('shipping')), 'Keep-on-source policy changed source shipping.');
	$keep_child = wc_get_order($keep_child->get_id());
	$completed_lineage = WCOS_Return_Lineage_Authority::resolve($keep_child);
	wcos_compat_split_assert($keep_child->is_paid() && null === $keep_child->get_date_paid() && '' === (string) $keep_child->get_transaction_id(), 'Completed Split child did not isolate paid-class status from independent payment evidence: ' . wp_json_encode(array(
		'status' => $keep_child->get_status(),
		'is_paid' => $keep_child->is_paid(),
		'date_paid' => $keep_child->get_date_paid() ? $keep_child->get_date_paid()->getTimestamp() : null,
		'transaction_id' => (string) $keep_child->get_transaction_id(),
	)));
	wcos_compat_split_assert(WCOS_Return_Lineage_Authority::proves_source_only_payment($completed_lineage), 'Completed Split child did not receive sealed source-only payment authority.');
	$completed_return = WCOS_Return_Preflight::report($keep_child, true);
	wcos_compat_split_assert(!empty($completed_return['supported']), 'Authenticated completed Split child was rejected solely because of its inherited paid-class status.');

	/* Paid-class processing status is operational state, not child payment ownership. */
	update_option('order_splitter_status_allowed', array('wc-completed', 'wc-processing', 'packed', 'wc-on-hold'));
	$payment_product = wcos_compat_split_product('WCOS source-only payment child', '13.00');
	$payment_product->set_manage_stock(true);
	$payment_product->set_stock_quantity(30);
	$payment_product->save();
	$product_ids[] = $payment_product->get_id();
	$processing_source = wcos_compat_split_order('processing', array(array($payment_product, 3)));
	$order_ids[] = $processing_source->get_id();
	$processing_item_id = (int) key($processing_source->get_items('line_item'));
	$processing_stock_before = WCOS_Order_Contract_Snapshot::product_stock($processing_source);
	$processing_reduced_before = WCOS_Order_Contract_Snapshot::aggregate(array($processing_source))['stock_reduced'];
	$processing_verified = wcos_compat_split_confirm($processing_source, array('processing-child' => array($processing_item_id => '1.000000')), $user_id);
	$processing_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed(
		$processing_source,
		$processing_verified['plan'],
		$processing_verified['operation_id'],
		$processing_verified['price_precision'],
		$processing_verified
	);
	$processing_child = wc_get_order(reset($processing_children)->get_id());
	$order_ids[] = $processing_child->get_id();
	$processing_source = wc_get_order($processing_source->get_id());
	wcos_compat_split_assert('processing' === $processing_child->get_status() && $processing_child->is_paid(), 'Processing Split child did not retain its paid-class source status.');
	wcos_compat_split_assert(null === $processing_child->get_date_paid() && '' === (string) $processing_child->get_transaction_id(), 'Processing Split child gained independent payment evidence.');
	wcos_compat_split_assert($processing_stock_before === WCOS_Order_Contract_Snapshot::product_stock($processing_source), 'Processing Split changed physical stock.');
	wcos_compat_split_assert($processing_reduced_before === WCOS_Order_Contract_Snapshot::aggregate(array($processing_source, $processing_child))['stock_reduced'], 'Processing Split changed family _reduced_stock ownership.');
	$processing_lineage = WCOS_Return_Lineage_Authority::resolve($processing_child);
	wcos_compat_split_assert(WCOS_Return_Lineage_Authority::proves_source_only_payment($processing_lineage), 'Processing Split child did not receive sealed source-only payment authority.');
	wcos_compat_split_assert(false === strpos(wp_json_encode($processing_lineage['payment_ownership_authority']), '@example.test'), 'Payment ownership authority leaked customer PII.');
	$processing_return = WCOS_Return_Preflight::report($processing_child, true);
	wcos_compat_split_assert(!empty($processing_return['supported']), 'Authenticated processing Split child was rejected solely because of its inherited paid-class status.');
	$bulk_review = WCOS_Bulk_Return_Review_Store::create(array($processing_child->get_id()), $user_id);
	wcos_compat_split_assert(!empty($bulk_review['plan']['all_eligible']), 'Authenticated processing Split child remained ineligible for Bulk Return.');
	WCOS_Bulk_Return_Review_Store::delete($bulk_review['review_id']);

	$processing_child->set_transaction_id('wcos-child-owned-transaction');
	$processing_child->save();
	$transaction_return = WCOS_Return_Preflight::report(wc_get_order($processing_child->get_id()), true);
	wcos_compat_split_assert(empty($transaction_return['supported']) && 'child_payment_ownership' === $transaction_return['reason'], 'Source-only policy bypassed child transaction ownership.');
	$processing_child = wc_get_order($processing_child->get_id());
	$processing_child->set_transaction_id('');
	$processing_child->save();
	$processing_child = wc_get_order($processing_child->get_id());
	$processing_child->set_date_paid(time());
	$processing_child->save();
	$paid_date_return = WCOS_Return_Preflight::report(wc_get_order($processing_child->get_id()), true);
	wcos_compat_split_assert(empty($paid_date_return['supported']) && 'child_payment_ownership' === $paid_date_return['reason'], 'Source-only policy bypassed child paid-date ownership.');
	$processing_child = wc_get_order($processing_child->get_id());
	$processing_child->set_date_paid(null);
	$processing_child->save();

	$processing_journal_key = 'wcos_mutation_op_' . hash('sha256', absint($processing_source->get_id()) . '|' . sanitize_key($processing_verified['operation_id']));
	$processing_journal = WCOS_Operation_Journal::get($processing_source, $processing_verified['operation_id']);
	$missing_policy_journal = $processing_journal;
	unset($missing_policy_journal['context']['commercial_policy']);
	update_option($processing_journal_key, $missing_policy_journal, false);
	wp_cache_delete($processing_journal_key, 'options');
	$missing_policy_return = WCOS_Return_Preflight::report(wc_get_order($processing_child->get_id()), true);
	update_option($processing_journal_key, $processing_journal, false);
	wp_cache_delete($processing_journal_key, 'options');
	wcos_compat_split_assert(empty($missing_policy_return['supported']) && 'split_fingerprint_mismatch' === $missing_policy_return['reason'], 'Missing current commercial policy obtained the paid-status exception.');

	$tampered_policy_journal = $processing_journal;
	$tampered_policy_journal['context']['commercial_policy']['payment'] = 'child_owned';
	$tampered_policy_journal['context']['commercial_policy']['policy_fingerprint'] = WCOS_Split_Commercial_Policy::fingerprint($tampered_policy_journal['context']['commercial_policy']);
	update_option($processing_journal_key, $tampered_policy_journal, false);
	wp_cache_delete($processing_journal_key, 'options');
	$tampered_policy_return = WCOS_Return_Preflight::report(wc_get_order($processing_child->get_id()), true);
	update_option($processing_journal_key, $processing_journal, false);
	wp_cache_delete($processing_journal_key, 'options');
	wcos_compat_split_assert(empty($tampered_policy_return['supported']) && 'split_commercial_policy_invalid' === $tampered_policy_return['reason'], 'Tampered commercial policy obtained the paid-status exception.');
	wcos_compat_split_assert(!empty(WCOS_Return_Preflight::report(wc_get_order($processing_child->get_id()), true)['supported']), 'Restored source-only payment authority did not recover deterministically.');
	update_option('order_splitter_status_allowed', array('wc-completed', 'packed', 'wc-on-hold', 'wc-not-registered'));

	/* Review-to-execute settings drift is rejected before journal start. */
	$drift_source = wcos_compat_split_order('completed', array(array($product, 3)));
	$order_ids[] = $drift_source->get_id();
	$drift_item_id = (int) key($drift_source->get_items('line_item'));
	$drift_preflight = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($drift_source);
	$drift_confirmation = WCOS_Split_Confirmation_Store::create($drift_source, array('drift-child' => array($drift_item_id => '1.000000')), $drift_preflight, $user_id);
	update_option('order_splitter_exclude_shipping_fee', 'no');
	$drift_rejected = false;
	try {
		WCOS_Split_Confirmation_Store::verify($drift_source, $drift_confirmation['operation_id'], $drift_confirmation['confirmation_token'], $user_id);
	} catch (WCOS_Split_Confirmation_Exception $exception) {
		$drift_rejected = 'commercial_policy_changed' === $exception->get_reason();
	}
	wcos_compat_split_assert($drift_rejected && !WCOS_Operation_Journal::get($drift_source, $drift_confirmation['operation_id']), 'Pre-journal shipping setting drift did not require fresh Review.');
	WCOS_Split_Confirmation_Store::delete($drift_confirmation['operation_id']);

	$status_drift_source = wcos_compat_split_order('completed', array(array($product, 3)));
	$order_ids[] = $status_drift_source->get_id();
	$status_drift_item_id = (int) key($status_drift_source->get_items('line_item'));
	$status_drift_preflight = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($status_drift_source);
	$status_drift_confirmation = WCOS_Split_Confirmation_Store::create($status_drift_source, array('status-drift-child' => array($status_drift_item_id => '1.000000')), $status_drift_preflight, $user_id);
	$status_drift_source->set_status('on-hold');
	$status_drift_source->save();
	$status_drift_rejected = false;
	try {
		WCOS_Split_Confirmation_Store::verify(wc_get_order($status_drift_source->get_id()), $status_drift_confirmation['operation_id'], $status_drift_confirmation['confirmation_token'], $user_id);
	} catch (WCOS_Split_Confirmation_Exception $exception) {
		$status_drift_rejected = 'commercial_policy_changed' === $exception->get_reason();
	}
	wcos_compat_split_assert($status_drift_rejected && !WCOS_Operation_Journal::get($status_drift_source, $status_drift_confirmation['operation_id']), 'Source-status drift did not fail before durable journal start.');
	WCOS_Split_Confirmation_Store::delete($status_drift_confirmation['operation_id']);

	$allowed_drift_source = wcos_compat_split_order('completed', array(array($product, 3)));
	$order_ids[] = $allowed_drift_source->get_id();
	$allowed_drift_item_id = (int) key($allowed_drift_source->get_items('line_item'));
	$allowed_drift_preflight = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($allowed_drift_source);
	$allowed_drift_confirmation = WCOS_Split_Confirmation_Store::create($allowed_drift_source, array('allowed-drift-child' => array($allowed_drift_item_id => '1.000000')), $allowed_drift_preflight, $user_id);
	update_option('order_splitter_status_allowed', array('wc-on-hold', 'packed'));
	$allowed_drift_rejected = false;
	try {
		WCOS_Split_Confirmation_Store::verify($allowed_drift_source, $allowed_drift_confirmation['operation_id'], $allowed_drift_confirmation['confirmation_token'], $user_id);
	} catch (WCOS_Split_Confirmation_Exception $exception) {
		$allowed_drift_rejected = 'commercial_policy_changed' === $exception->get_reason();
	}
	wcos_compat_split_assert($allowed_drift_rejected && !WCOS_Operation_Journal::get($allowed_drift_source, $allowed_drift_confirmation['operation_id']), 'Allowed-status setting drift did not require fresh Review.');
	WCOS_Split_Confirmation_Store::delete($allowed_drift_confirmation['operation_id']);
	update_option('order_splitter_status_allowed', array('wc-completed', 'packed', 'wc-on-hold', 'wc-not-registered'));

	/* A pre-policy transient cannot execute, while an old durable journal retains old semantics. */
	$stale_source = wcos_compat_split_order('completed', array(array($product, 3)));
	$order_ids[] = $stale_source->get_id();
	$stale_item_id = (int) key($stale_source->get_items('line_item'));
	$stale_preflight = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($stale_source);
	$stale_confirmation = WCOS_Split_Confirmation_Store::create($stale_source, array('stale-child' => array($stale_item_id => '1.000000')), $stale_preflight, $user_id);
	$stale_key = 'wcos_split_confirm_' . hash('sha256', sanitize_key($stale_confirmation['operation_id']));
	$stale_record = get_transient($stale_key);
	unset($stale_record['commercial_policy']);
	$stale_record['schema_version'] = WCOS_Split_Confirmation_Store::SCHEMA_VERSION - 1;
	set_transient($stale_key, $stale_record, WCOS_Split_Confirmation_Store::TTL);
	$stale_rejected = false;
	try {
		WCOS_Split_Confirmation_Store::verify($stale_source, $stale_confirmation['operation_id'], $stale_confirmation['confirmation_token'], $user_id);
	} catch (WCOS_Split_Confirmation_Exception $exception) {
		$stale_rejected = 'commercial_policy_changed' === $exception->get_reason();
	}
	wcos_compat_split_assert($stale_rejected, 'A pre-policy transient executed without durable journal authority.');
	WCOS_Split_Confirmation_Store::delete($stale_confirmation['operation_id']);

	$old_source = wcos_compat_split_order('pending', array(array($product, 3)));
	$order_ids[] = $old_source->get_id();
	$old_item_id = (int) key($old_source->get_items('line_item'));
	$old_shipping = new WC_Order_Item_Shipping();
	$old_shipping->set_method_title('Legacy source shipping');
	$old_shipping->set_method_id('flat_rate');
	$old_shipping->set_total('2.00');
	$old_source->add_item($old_shipping);
	$old_source->calculate_totals(false);
	$old_source->save();
	$old_source = wc_get_order($old_source->get_id());
	$old_operation = wp_generate_uuid4();
	$old_plan = array('legacy-child' => array($old_item_id => '1.000000'));
	$old_authority = WCOS_Manual_Split_Quantity_Authority::create($old_source);
	$old_authority['policy_version'] = WCOS_Manual_Split_Quantity_Authority::LEGACY_POLICY_VERSION;
	foreach ($old_authority['lines'] as &$old_line_authority) {
		$old_line_authority['maximum_quantity_units'] = $old_line_authority['source_quantity_units'] - $old_line_authority['step_units'];
		$old_line_authority['maximum_quantity'] = WCOS_Decimal::from_units($old_line_authority['maximum_quantity_units'], WCOS_Manual_Split_Quantity_Authority::PRECISION);
		$old_line_authority['can_partially_split'] = $old_line_authority['maximum_quantity_units'] >= $old_line_authority['step_units'];
	}
	unset($old_line_authority, $old_authority['authority_fingerprint']);
	$old_authority['authority_fingerprint'] = WCOS_Mutation_Fingerprint::create('manual_split_quantity_authority_v1', $old_source->get_id(), $old_authority);
	$old_authority = WCOS_Manual_Split_Quantity_Authority::assert_valid($old_authority);
	$old_execution_policy = WCOS_Manual_Split_Quantity_Authority::execution_policy($old_authority);
	$old_normalized_plan = WCOS_Split_Plan::normalize($old_source, $old_plan, $old_execution_policy);
	$legacy_policy = WCOS_Split_Commercial_Policy::legacy();
	$old_fingerprint = WCOS_Mutation_Fingerprint::create('split', $old_source->get_id(), array(
		'policy_version' => WCOS_Split_Order_Service::LEGACY_POLICY_VERSION,
		'plan' => $old_plan,
		'shipping_policy' => $legacy_policy['shipping'],
		'fee_policy' => 'keep_on_source',
		'child_status' => $legacy_policy['child_status'],
		'execution_policy' => $old_execution_policy,
		'manual_quantity_authority' => $old_authority,
	));
	$old_context = array(
		'plan' => $old_normalized_plan,
		'child_keys' => WCOS_Split_Plan::child_keys($old_normalized_plan),
		'execution_policy' => $old_execution_policy,
		'fully_moved_item_ids' => array(),
		'source_signature' => WCOS_Order_Contract_Snapshot::source_signature($old_source),
		'before_contract' => WCOS_Order_Contract_Snapshot::aggregate(array($old_source)),
		'source_stock_reduced' => (bool) $old_source->get_data_store()->get_stock_reduced($old_source->get_id()),
		'manual_quantity_authority' => $old_authority,
	);
	wcos_compat_split_assert(WCOS_Operation_Journal::start($old_source, $old_operation, 'split', $old_context, $old_fingerprint), 'Unable to create old Split journal fixture.');
	$old_journal_key = 'wcos_mutation_op_' . hash('sha256', absint($old_source->get_id()) . '|' . sanitize_key($old_operation));
	$old_record = WCOS_Operation_Journal::get($old_source, $old_operation);
	unset($old_record['context']['commercial_policy']);
	$old_record['context']['policy_version'] = WCOS_Split_Commercial_Policy::LEGACY_POLICY_VERSION;
	update_option($old_journal_key, $old_record, false);
	wp_cache_delete($old_journal_key, 'options');
	$old_record = WCOS_Operation_Journal::get(wc_get_order($old_source->get_id()), $old_operation);
	$old_replay = WCOS_Split_Confirmation_Store::verify(wc_get_order($old_source->get_id()), $old_operation, '', $user_id);
	wcos_compat_split_assert('journal' === $old_replay['replay_authority'] && 'pending' === $old_replay['commercial_policy']['child_status'], 'Old durable journal did not recover legacy Split policy.');
	$old_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed(wc_get_order($old_source->get_id()), $old_replay['plan'], $old_operation, $old_replay['price_precision'], $old_replay);
	$old_child = reset($old_children);
	$order_ids[] = $old_child->get_id();
	wcos_compat_split_assert('pending' === $old_child->get_status() && empty($old_child->get_items('shipping')), 'Old durable journal did not retain pending-child/keep-shipping semantics.');
	wcos_compat_split_assert(1 === count(wc_get_order($old_source->get_id())->get_items('shipping')), 'Old durable journal changed source shipping ownership.');
	$old_lineage = WCOS_Return_Lineage_Authority::resolve(wc_get_order($old_child->get_id()));
	wcos_compat_split_assert(!WCOS_Return_Lineage_Authority::proves_source_only_payment($old_lineage), 'Legacy Split journal obtained current source-only payment authority.');

	$current_without_policy = $old_record;
	$current_without_policy['context']['policy_version'] = WCOS_Split_Commercial_Policy::POLICY_VERSION;
	$current_policy_missing_rejected = false;
	try {
		WCOS_Split_Commercial_Policy::from_journal($current_without_policy);
	} catch (RuntimeException $exception) {
		$current_policy_missing_rejected = true;
	}
	wcos_compat_split_assert($current_policy_missing_rejected, 'A current-version journal without commercial policy was treated as legacy authority.');

	/* Custom registered statuses are valid when configured. */
	$custom_source = wcos_compat_split_order('packed', array(array($product, 2)));
	$order_ids[] = $custom_source->get_id();
	$custom_report = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($custom_source);
	wcos_compat_split_assert(!empty($custom_report['supported']) && 'packed' === $custom_report['policy']['child_status'], 'Configured custom status did not pass Split Review.');
	$custom_source->update_meta_data('yoos_original_order', 424242);
	$custom_source->save_meta_data();
	$custom_source = wc_get_order($custom_source->get_id());
	$custom_item_id = (int) key($custom_source->get_items('line_item'));
	$legacy_nested_verified = wcos_compat_split_confirm($custom_source, array('legacy-descendant' => array($custom_item_id => '1.000000')), $user_id);
	$legacy_descendants = (new WCOS_Mutation_Gateway())->split_manual_confirmed($custom_source, $legacy_nested_verified['plan'], $legacy_nested_verified['operation_id'], $legacy_nested_verified['price_precision'], $legacy_nested_verified);
	$legacy_descendant = reset($legacy_descendants);
	$order_ids[] = $legacy_descendant->get_id();
	wcos_compat_split_assert('packed' === $legacy_descendant->get_status() && $custom_source->get_id() === (int) $legacy_descendant->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true), 'Legacy Split child did not produce a status-preserving hardened immediate descendant.');
	wcos_compat_split_assert(424242 === (int) wc_get_order($custom_source->get_id())->get_meta('yoos_original_order', true), 'Nested Split rewrote legacy upstream relation evidence.');

	$on_hold_source = wcos_compat_split_order('on-hold', array(array($product, 3)));
	$order_ids[] = $on_hold_source->get_id();
	$on_hold_item_id = (int) key($on_hold_source->get_items('line_item'));
	$on_hold_verified = wcos_compat_split_confirm($on_hold_source, array('on-hold-child' => array($on_hold_item_id => '1.000000')), $user_id);
	$on_hold_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed($on_hold_source, $on_hold_verified['plan'], $on_hold_verified['operation_id'], $on_hold_verified['price_precision'], $on_hold_verified);
	$on_hold_child = reset($on_hold_children);
	$order_ids[] = $on_hold_child->get_id();
	wcos_compat_split_assert('on-hold' === $on_hold_child->get_status(), 'Manual Split child did not inherit configured on-hold status.');
	$on_hold_source = wc_get_order($on_hold_source->get_id());
	$second_on_hold_item_id = (int) key($on_hold_source->get_items('line_item'));
	$second_on_hold_verified = wcos_compat_split_confirm($on_hold_source, array('second-on-hold-child' => array($second_on_hold_item_id => '1.000000')), $user_id);
	$second_on_hold_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed($on_hold_source, $second_on_hold_verified['plan'], $second_on_hold_verified['operation_id'], $second_on_hold_verified['price_precision'], $second_on_hold_verified);
	$second_on_hold_child = reset($second_on_hold_children);
	$order_ids[] = $second_on_hold_child->get_id();
	$on_hold_relations = array_values(array_unique(array_map('absint', (array) wc_get_order($on_hold_source->get_id())->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true))));
	wcos_compat_split_assert(2 === count($on_hold_relations) && in_array($on_hold_child->get_id(), $on_hold_relations, true) && in_array($second_on_hold_child->get_id(), $on_hold_relations, true), 'A second valid Split overwrote an existing active descendant relation.');

	/* Repeated hardened Split operations share one authenticated global Return lineage. */
	foreach (array($on_hold_child, $second_on_hold_child) as $candidate) {
		$candidate_report = WCOS_Return_Preflight::report(wc_get_order($candidate->get_id()), true);
		wcos_compat_split_assert(!empty($candidate_report['supported']), 'A direct child from repeated Split failed ordinary Return preflight.');
		$candidate_bulk = WCOS_Bulk_Return_Review_Store::create(array($candidate->get_id()), $user_id);
		wcos_compat_split_assert(!empty($candidate_bulk['plan']['all_eligible']), 'A direct child from repeated Split failed Bulk Return preflight.');
		WCOS_Bulk_Return_Review_Store::delete($candidate_bulk['review_id']);
	}

	$on_hold_source = wc_get_order($on_hold_source->get_id());
	$clean_relations = $on_hold_source->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true);
	$on_hold_source->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, array_merge($clean_relations, array(999999991)));
	$on_hold_source->save_meta_data();
	$injected_report = WCOS_Return_Preflight::report(wc_get_order($on_hold_child->get_id()), true);
	wcos_compat_split_assert(empty($injected_report['supported']), 'An injected unrelated active relation ID obtained Return authority.');
	$on_hold_source = wc_get_order($on_hold_source->get_id());
	$on_hold_source->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, $clean_relations);
	$on_hold_source->save_meta_data();

	$provenance_tamper_cases = array(
		WCOS_Split_Order_Service::RELATION_PARENT_META => $on_hold_source->get_id() + 999,
		WCOS_Split_Order_Service::OPERATION_META => 'tampered-split-operation',
		WCOS_Split_Order_Service::CHILD_KEY_META => 'tampered-child-key',
	);
	foreach ($provenance_tamper_cases as $meta_key => $tampered_value) {
		$tampered_child = wc_get_order($second_on_hold_child->get_id());
		$original_value = $tampered_child->get_meta($meta_key, true);
		$tampered_child->update_meta_data($meta_key, $tampered_value);
		$tampered_child->save_meta_data();
		$tampered_report = WCOS_Return_Preflight::report(wc_get_order($on_hold_child->get_id()), true);
		wcos_compat_split_assert(empty($tampered_report['supported']), 'Repeated-Split Return failed open for tampered child provenance: ' . $meta_key);
		$tampered_child = wc_get_order($second_on_hold_child->get_id());
		$tampered_child->update_meta_data($meta_key, $original_value);
		$tampered_child->save_meta_data();
	}

	$second_split_operation = (string) wc_get_order($second_on_hold_child->get_id())->get_meta(WCOS_Split_Order_Service::OPERATION_META, true);
	$second_split_journal_key = 'wcos_mutation_op_' . hash('sha256', $on_hold_source->get_id() . '|' . sanitize_key($second_split_operation));
	$second_split_journal = get_option($second_split_journal_key);
	$tampered_second_split_journal = $second_split_journal;
	$tampered_second_split_journal['context']['target_order_ids'] = array();
	update_option($second_split_journal_key, $tampered_second_split_journal, false);
	wp_cache_delete($second_split_journal_key, 'options');
	$tampered_membership = WCOS_Return_Preflight::report(wc_get_order($on_hold_child->get_id()), true);
	wcos_compat_split_assert(empty($tampered_membership['supported']), 'Repeated-Split Return failed open for tampered journal target membership.');
	update_option($second_split_journal_key, $second_split_journal, false);
	wp_cache_delete($second_split_journal_key, 'options');

	wcos_compat_split_return(wc_get_order($on_hold_child->get_id()), $user_id);
	$after_first_operation_return = WCOS_Return_Preflight::report(wc_get_order($second_on_hold_child->get_id()), true);
	wcos_compat_split_assert(!empty($after_first_operation_return['supported']), 'Returning an earlier-operation child stranded a later-operation sibling.');
	wcos_compat_split_return(wc_get_order($second_on_hold_child->get_id()), $user_id);

	$reverse_source = wcos_compat_split_order('on-hold', array(array($product, 4)));
	$order_ids[] = $reverse_source->get_id();
	$reverse_item_id = (int) key($reverse_source->get_items('line_item'));
	$reverse_first = wcos_compat_split_confirm($reverse_source, array('reverse-first' => array($reverse_item_id => '1.000000')), $user_id);
	$reverse_first_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed($reverse_source, $reverse_first['plan'], $reverse_first['operation_id'], $reverse_first['price_precision'], $reverse_first);
	$reverse_first_child = reset($reverse_first_children);
	$order_ids[] = $reverse_first_child->get_id();
	$reverse_source = wc_get_order($reverse_source->get_id());
	$reverse_second = wcos_compat_split_confirm($reverse_source, array('reverse-second' => array($reverse_item_id => '1.000000')), $user_id);
	$reverse_second_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed($reverse_source, $reverse_second['plan'], $reverse_second['operation_id'], $reverse_second['price_precision'], $reverse_second);
	$reverse_second_child = reset($reverse_second_children);
	$order_ids[] = $reverse_second_child->get_id();
	wcos_compat_split_return(wc_get_order($reverse_second_child->get_id()), $user_id);
	$after_later_operation_return = WCOS_Return_Preflight::report(wc_get_order($reverse_first_child->get_id()), true);
	wcos_compat_split_assert(!empty($after_later_operation_return['supported']), 'Returning a later-operation child stranded an earlier-operation sibling.');
	wcos_compat_split_return(wc_get_order($reverse_first_child->get_id()), $user_id);

	$mixed_lineage_source = wcos_compat_split_order('on-hold', array(array($product, 7)));
	$order_ids[] = $mixed_lineage_source->get_id();
	$mixed_lineage_item_id = (int) key($mixed_lineage_source->get_items('line_item'));
	$mixed_first = wcos_compat_split_confirm($mixed_lineage_source, array(
		'mixed-a' => array($mixed_lineage_item_id => '1.000000'),
		'mixed-b' => array($mixed_lineage_item_id => '1.000000'),
	), $user_id);
	$mixed_first_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed($mixed_lineage_source, $mixed_first['plan'], $mixed_first['operation_id'], $mixed_first['price_precision'], $mixed_first);
	foreach ($mixed_first_children as $mixed_child) { $order_ids[] = $mixed_child->get_id(); }
	$mixed_lineage_source = wc_get_order($mixed_lineage_source->get_id());
	$mixed_second = wcos_compat_split_confirm($mixed_lineage_source, array('mixed-c' => array($mixed_lineage_item_id => '1.000000')), $user_id);
	$mixed_second_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed($mixed_lineage_source, $mixed_second['plan'], $mixed_second['operation_id'], $mixed_second['price_precision'], $mixed_second);
	$mixed_second_child = reset($mixed_second_children);
	$order_ids[] = $mixed_second_child->get_id();
	foreach (array_merge(array_values($mixed_first_children), array($mixed_second_child)) as $mixed_child) {
		$mixed_report = WCOS_Return_Preflight::report(wc_get_order($mixed_child->get_id()), true);
		wcos_compat_split_assert(!empty($mixed_report['supported']), 'Same-operation siblings plus a later Split child did not retain global Return eligibility.');
	}

	/* Category and Stock-status execution consume the same frozen policy. */
	$strategy_a = wcos_compat_split_product('WCOS strategy category source', '7.00', 'instock');
	$strategy_b = wcos_compat_split_product('WCOS strategy category child', '9.00', 'outofstock');
	$product_ids[] = $strategy_a->get_id();
	$product_ids[] = $strategy_b->get_id();
	$strategy_term = wp_insert_term('WCOS strategy source ' . wp_generate_password(5, false), 'product_cat');
	wcos_compat_split_assert(!is_wp_error($strategy_term), 'Unable to create strategy source category.');
	$term_ids[] = (int) $strategy_term['term_id'];
	wp_set_object_terms($strategy_a->get_id(), array((int) $strategy_term['term_id']), 'product_cat');

	$category_source = wcos_compat_split_order('completed', array(array($strategy_a, 1), array($strategy_b, 1)));
	$order_ids[] = $category_source->get_id();
	$category_review_success = WCOS_Category_Split_Planner::review($category_source);
	$category_confirmation_success = WCOS_Split_Strategy_Confirmation_Store::create($category_source, WCOS_Split_Strategy_Gates::CATEGORY, $category_review_success, 'category-' . (int) $strategy_term['term_id'], $user_id);
	$category_verified_success = WCOS_Split_Strategy_Confirmation_Store::verify($category_source, $category_confirmation_success['operation_id'], $category_confirmation_success['confirmation_token'], $user_id);
	$category_children_success = (new WCOS_Mutation_Gateway())->split_strategy($category_source, WCOS_Split_Strategy_Gates::CATEGORY, $category_verified_success['plan'], $category_verified_success['operation_id'], $category_verified_success['price_precision'], $category_verified_success);
	$category_child_success = reset($category_children_success);
	$order_ids[] = $category_child_success->get_id();
	wcos_compat_split_assert('completed' === $category_child_success->get_status(), 'Category Split child did not inherit configured completed status.');

	$stock_source = wcos_compat_split_order('packed', array(array($strategy_a, 1), array($strategy_b, 1)));
	$order_ids[] = $stock_source->get_id();
	$stock_review_success = WCOS_Stock_Status_Split_Planner::review($stock_source);
	$stock_confirmation_success = WCOS_Split_Strategy_Confirmation_Store::create($stock_source, WCOS_Split_Strategy_Gates::STOCK_STATUS, $stock_review_success, 'stock-instock', $user_id);
	$stock_verified_success = WCOS_Split_Strategy_Confirmation_Store::verify($stock_source, $stock_confirmation_success['operation_id'], $stock_confirmation_success['confirmation_token'], $user_id);
	$stock_children_success = (new WCOS_Mutation_Gateway())->split_strategy($stock_source, WCOS_Split_Strategy_Gates::STOCK_STATUS, $stock_verified_success['plan'], $stock_verified_success['operation_id'], $stock_verified_success['price_precision'], $stock_verified_success);
	$stock_child_success = reset($stock_children_success);
	$order_ids[] = $stock_child_success->get_id();
	wcos_compat_split_assert('packed' === $stock_child_success->get_status(), 'Stock-status Split child did not inherit the exact configured custom status.');

	/* Refund records remain source-owned and affected lines cannot enter a child. */
	$product_a = wcos_compat_split_product('WCOS refunded category A', '12.00', 'instock');
	$product_b = wcos_compat_split_product('WCOS unaffected category B', '8.00', 'outofstock');
	$product_ids[] = $product_a->get_id();
	$product_ids[] = $product_b->get_id();
	$term_a = wp_insert_term('WCOS refund A ' . wp_generate_password(5, false), 'product_cat');
	$term_b = wp_insert_term('WCOS refund B ' . wp_generate_password(5, false), 'product_cat');
	wcos_compat_split_assert(!is_wp_error($term_a) && !is_wp_error($term_b), 'Unable to create refund strategy categories.');
	$term_ids[] = (int) $term_a['term_id'];
	$term_ids[] = (int) $term_b['term_id'];
	wp_set_object_terms($product_a->get_id(), array((int) $term_a['term_id']), 'product_cat');
	wp_set_object_terms($product_b->get_id(), array((int) $term_b['term_id']), 'product_cat');
	update_option('order_splitter_status_allowed', array('wc-processing'));
	update_option('order_splitter_exclude_shipping_fee', 'yes');
	$refund_source = wcos_compat_split_order('processing', array(array($product_a, 2), array($product_b, 2)));
	$order_ids[] = $refund_source->get_id();
	$refund_items = array_keys($refund_source->get_items('line_item'));
	$affected_id = (int) $refund_items[0];
	$unaffected_id = (int) $refund_items[1];
	$refund = wc_create_refund(array(
		'order_id' => $refund_source->get_id(),
		'amount' => '12.00',
		'reason' => 'Compatibility affected line',
		'refund_payment' => false,
		'line_items' => array($affected_id => array('qty' => 1, 'refund_total' => '12.00', 'refund_tax' => array())),
	));
	wcos_compat_split_assert($refund instanceof WC_Order_Refund, 'Unable to create canonical refund fixture.');
	$refund_source = wc_get_order($refund_source->get_id());
	$refund_report = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($refund_source);
	wcos_compat_split_assert(!empty($refund_report['supported']) && array($affected_id) === $refund_report['policy']['refund_affected_item_ids'], 'Refund affect map was not derived canonically.');
	wcos_compat_split_assert(!empty($refund_report['policy']['refund_evidence'][0]['items']), 'Frozen refund authority omitted persisted refund item evidence.');
	$refund_drift_confirmation = WCOS_Split_Confirmation_Store::create($refund_source, array('refund-drift-child' => array($unaffected_id => '1.000000')), $refund_report, $user_id);
	$refund->set_amount('11.00');
	$refund->save();
	$refund_drift_rejected = false;
	try {
		WCOS_Split_Confirmation_Store::verify(wc_get_order($refund_source->get_id()), $refund_drift_confirmation['operation_id'], $refund_drift_confirmation['confirmation_token'], $user_id);
	} catch (WCOS_Split_Confirmation_Exception $exception) {
		$refund_drift_rejected = 'commercial_policy_changed' === $exception->get_reason();
	}
	wcos_compat_split_assert($refund_drift_rejected, 'Refund record drift after Review did not invalidate commercial policy authority.');
	WCOS_Split_Confirmation_Store::delete($refund_drift_confirmation['operation_id']);
	$refund->set_amount('12.00');
	$refund->save();
	$refund_source = wc_get_order($refund_source->get_id());
	$refund_report = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($refund_source);
	$affected_rejected = false;
	try {
		WCOS_Split_Confirmation_Store::create($refund_source, array('bad-child' => array($affected_id => '1.000000')), $refund_report, $user_id);
	} catch (WCOS_Split_Confirmation_Exception $exception) {
		$affected_rejected = 'commercial_policy_changed' === $exception->get_reason();
	}
	wcos_compat_split_assert($affected_rejected, 'Manual Split allowed refund-affected quantity to move.');

	$category_review = WCOS_Category_Split_Planner::review($refund_source);
	wcos_compat_split_assert(!empty($category_review['supported']), 'Refund source did not produce Category strategy buckets.');
	$affected_category = 'category-' . (int) $term_a['term_id'];
	$unaffected_category = 'category-' . (int) $term_b['term_id'];
	wcos_compat_split_assert(empty($category_review['buckets'][$unaffected_category]['source_eligible']) && !empty($category_review['buckets'][$unaffected_category]['source_restriction']), 'Category Review did not explain its refund-invalid source bucket.');
	$category_wrong_rejected = false;
	try {
		WCOS_Split_Strategy_Confirmation_Store::create($refund_source, WCOS_Split_Strategy_Gates::CATEGORY, $category_review, $unaffected_category, $user_id);
	} catch (WCOS_Split_Strategy_Confirmation_Exception $exception) {
		$category_wrong_rejected = 'review_invalid' === $exception->get_reason();
	}
	wcos_compat_split_assert($category_wrong_rejected, 'Category Split allowed a refund-affected bucket to become a child.');
	$category_good = WCOS_Split_Strategy_Confirmation_Store::create($refund_source, WCOS_Split_Strategy_Gates::CATEGORY, $category_review, $affected_category, $user_id);
	WCOS_Split_Strategy_Confirmation_Store::delete($category_good['operation_id']);

	$stock_review = WCOS_Stock_Status_Split_Planner::review($refund_source);
	wcos_compat_split_assert(!empty($stock_review['supported']), 'Refund source did not produce Stock-status strategy buckets.');
	wcos_compat_split_assert(empty($stock_review['buckets']['stock-outofstock']['source_eligible']) && !empty($stock_review['buckets']['stock-outofstock']['source_restriction']), 'Stock-status Review did not explain its refund-invalid source bucket.');
	$stock_wrong_rejected = false;
	try {
		WCOS_Split_Strategy_Confirmation_Store::create($refund_source, WCOS_Split_Strategy_Gates::STOCK_STATUS, $stock_review, 'stock-outofstock', $user_id);
	} catch (WCOS_Split_Strategy_Confirmation_Exception $exception) {
		$stock_wrong_rejected = 'review_invalid' === $exception->get_reason();
	}
	wcos_compat_split_assert($stock_wrong_rejected, 'Stock-status Split allowed a refund-affected bucket to become a child.');
	$stock_good = WCOS_Split_Strategy_Confirmation_Store::create($refund_source, WCOS_Split_Strategy_Gates::STOCK_STATUS, $stock_review, 'stock-instock', $user_id);
	WCOS_Split_Strategy_Confirmation_Store::delete($stock_good['operation_id']);

	$multi_refund_source = wcos_compat_split_order('processing', array(array($product_a, 2), array($product_b, 2)));
	$order_ids[] = $multi_refund_source->get_id();
	$multi_refund_item_ids = array_keys($multi_refund_source->get_items('line_item'));
	foreach ($multi_refund_item_ids as $multi_refund_item_id) {
		$multi_refund = wc_create_refund(array(
			'order_id' => $multi_refund_source->get_id(),
			'amount' => '1.00',
			'refund_payment' => false,
			'line_items' => array((int) $multi_refund_item_id => array('qty' => 1, 'refund_total' => '1.00', 'refund_tax' => array())),
		));
		wcos_compat_split_assert($multi_refund instanceof WC_Order_Refund, 'Unable to create cross-bucket refund fixture.');
	}
	$multi_refund_source = wc_get_order($multi_refund_source->get_id());
	$multi_category_review = WCOS_Category_Split_Planner::review($multi_refund_source);
	$multi_stock_review = WCOS_Stock_Status_Split_Planner::review($multi_refund_source);
	wcos_compat_split_assert(empty($multi_category_review['supported']) && 'refund_source_bucket_unavailable' === $multi_category_review['reason'], 'Category Review did not reject refund-affected lines spanning multiple buckets.');
	wcos_compat_split_assert(empty($multi_stock_review['supported']) && 'refund_source_bucket_unavailable' === $multi_stock_review['reason'], 'Stock-status Review did not reject refund-affected lines spanning multiple buckets.');

	$ambiguous_refund_source = wcos_compat_split_order('processing', array(array($product_a, 2)));
	$order_ids[] = $ambiguous_refund_source->get_id();
	$ambiguous_refund = wc_create_refund(array(
		'order_id' => $ambiguous_refund_source->get_id(),
		'amount' => '1.00',
		'refund_payment' => false,
	));
	wcos_compat_split_assert($ambiguous_refund instanceof WC_Order_Refund, 'Unable to create unattributed refund fixture.');
	$ambiguous_refund_report = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight(wc_get_order($ambiguous_refund_source->get_id()));
	wcos_compat_split_assert(empty($ambiguous_refund_report['supported']) && 'ambiguous_refund_provenance' === $ambiguous_refund_report['reason'], 'Unattributed refund amount did not fail closed before Split Review.');

	$refund_verified = wcos_compat_split_confirm($refund_source, array('refund-safe-child' => array($unaffected_id => '2.000000')), $user_id);
	$refund_children = (new WCOS_Mutation_Gateway())->split_manual_confirmed($refund_source, $refund_verified['plan'], $refund_verified['operation_id'], $refund_verified['price_precision'], $refund_verified);
	$refund_child = reset($refund_children);
	$order_ids[] = $refund_child->get_id();
	$refund_source = wc_get_order($refund_source->get_id());
	wcos_compat_split_assert(1 === count($refund_source->get_refunds()) && empty($refund_child->get_refunds()), 'Refund record ownership did not remain on the source.');
	wcos_compat_split_assert($refund_source->get_item($affected_id) instanceof WC_Order_Item_Product, 'Refund-affected source line was not pinned wholly to the source.');
	wcos_compat_split_assert(!$refund_source->get_item($unaffected_id), 'Unaffected whole line did not move under current Manual authority.');

	echo "compat-split-functional-parity-ok\n";
} finally {
	wp_set_current_user($previous_user_id);
	update_option('order_splitter_status_allowed', $previous_allowed);
	update_option('order_splitter_exclude_shipping_fee', $previous_shipping);
	remove_filter('wc_order_statuses', $custom_status_filter);
	foreach (array_reverse(array_values(array_unique(array_map('absint', $order_ids)))) as $order_id) {
		$order = wc_get_order($order_id);
		if ($order) {
			$order->delete(true);
		}
	}
	foreach (array_values(array_unique(array_map('absint', $product_ids))) as $product_id) {
		wp_delete_post($product_id, true);
	}
	foreach (array_values(array_unique(array_map('absint', $term_ids))) as $term_id) {
		wp_delete_term($term_id, 'product_cat');
	}
	if ($user_id && !is_wp_error($user_id)) {
		wp_delete_user($user_id);
	}
}
