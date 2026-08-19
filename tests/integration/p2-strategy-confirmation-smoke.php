<?php

if (!defined('ABSPATH')) {
	exit(1);
}

wcos_p2_adapter_assert(class_exists('WCOS_Split_Strategy_Confirmation_Store'), 'Strategy confirmation store was not loaded by the plugin bootstrap.');
wcos_p2_adapter_assert(method_exists('WCOS_Split_Strategy_WooCommerce_Adapter', 'split_confirmed'), 'Confirmed strategy adapter boundary is missing.');

$confirmation_user_id = wp_insert_user(array(
	'user_login' => 'wcos_strategy_confirm_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-strategy-confirm-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
$other_user_id = wp_insert_user(array(
	'user_login' => 'wcos_strategy_other_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-strategy-other-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
wcos_p2_adapter_assert(!is_wp_error($confirmation_user_id) && !is_wp_error($other_user_id), 'Unable to create strategy confirmation test users.');

$strategy_confirmation_adapter = new WCOS_Split_Strategy_WooCommerce_Adapter();

/* Review -> confirmation must fail closed if the source changes before verify. */
$toctou_suffix = strtolower(wp_generate_password(6, false, false));
$toctou_keep_term = wp_insert_term('WCOS Confirm TOCTOU Keep ' . $toctou_suffix, 'product_cat');
$toctou_move_term = wp_insert_term('WCOS Confirm TOCTOU Move ' . $toctou_suffix, 'product_cat');
wcos_p2_adapter_assert(!is_wp_error($toctou_keep_term) && !is_wp_error($toctou_move_term), 'Unable to create strategy confirmation TOCTOU terms.');
$toctou_keep = wcos_p2_adapter_product('WCOS Confirm TOCTOU Keep', '8.00');
$toctou_move = wcos_p2_adapter_product('WCOS Confirm TOCTOU Move', '6.00');
wp_set_object_terms($toctou_keep->get_id(), array(absint($toctou_keep_term['term_id'])), 'product_cat');
wp_set_object_terms($toctou_move->get_id(), array(absint($toctou_move_term['term_id'])), 'product_cat');
$toctou_order = wc_create_order();
$toctou_order->set_status('pending');
$toctou_order->set_currency('USD');
$toctou_keep_item = $toctou_order->add_product($toctou_keep, 1);
$toctou_move_item = $toctou_order->add_product($toctou_move, 1);
$toctou_order->calculate_totals(false);
$toctou_order->save();
$toctou_review = $strategy_confirmation_adapter->review($toctou_order, WCOS_Split_Strategy_Gates::CATEGORY);
$toctou_keep_bucket = 'category-' . absint($toctou_keep_term['term_id']);
$toctou_confirmation = WCOS_Split_Strategy_Confirmation_Store::create(
	$toctou_order,
	WCOS_Split_Strategy_Gates::CATEGORY,
	$toctou_review,
	$toctou_keep_bucket,
	$confirmation_user_id
);
$toctou_order->set_customer_note('changed-after-strategy-confirmation');
$toctou_order->save();
$toctou_changed = false;
try {
	WCOS_Split_Strategy_Confirmation_Store::verify(
		wc_get_order($toctou_order->get_id()),
		$toctou_confirmation['operation_id'],
		$toctou_confirmation['confirmation_token'],
		$confirmation_user_id
	);
} catch (WCOS_Split_Strategy_Confirmation_Exception $exception) {
	$toctou_changed = 'source_changed' === $exception->get_reason();
}
wcos_p2_adapter_assert($toctou_changed, 'Strategy confirmation accepted a source order changed after Review.');
wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($toctou_order->get_id()), $toctou_confirmation['operation_id']), 'Strategy confirmation TOCTOU failure created a mutation journal.');
WCOS_Split_Strategy_Confirmation_Store::delete($toctou_confirmation['operation_id']);
$toctou_order->delete(true);
wp_delete_post($toctou_keep->get_id(), true);
wp_delete_post($toctou_move->get_id(), true);
wp_delete_term(absint($toctou_keep_term['term_id']), 'product_cat');
wp_delete_term(absint($toctou_move_term['term_id']), 'product_cat');

/* Confirmed Category authority is bound into the one durable Split journal. */
$category_suffix = strtolower(wp_generate_password(6, false, false));
$category_keep_term = wp_insert_term('WCOS Confirm Keep ' . $category_suffix, 'product_cat');
$category_move_term = wp_insert_term('WCOS Confirm Move ' . $category_suffix, 'product_cat');
wcos_p2_adapter_assert(!is_wp_error($category_keep_term) && !is_wp_error($category_move_term), 'Unable to create confirmed Category terms.');
$category_keep = wcos_p2_adapter_product('WCOS Confirm Category Keep', '12.00');
$category_move = wcos_p2_adapter_product('WCOS Confirm Category Move', '7.00');
wp_set_object_terms($category_keep->get_id(), array(absint($category_keep_term['term_id'])), 'product_cat');
wp_set_object_terms($category_move->get_id(), array(absint($category_move_term['term_id'])), 'product_cat');
$category_order = wc_create_order();
$category_order->set_status('pending');
$category_order->set_currency('USD');
$category_keep_item = $category_order->add_product($category_keep, 2);
$category_move_item = $category_order->add_product($category_move, 2);
$category_order->calculate_totals(false);
$category_order->set_billing_email('strategy-confirmation-private@example.test');
$category_order->save();
$category_order_id = $category_order->get_id();
$category_review = $strategy_confirmation_adapter->review($category_order, WCOS_Split_Strategy_Gates::CATEGORY);
$category_keep_bucket = 'category-' . absint($category_keep_term['term_id']);
$category_move_bucket = 'category-' . absint($category_move_term['term_id']);
$category_plan = $strategy_confirmation_adapter->build_plan($category_review, $category_keep_bucket);
$category_confirmation = WCOS_Split_Strategy_Confirmation_Store::create(
	$category_order,
	WCOS_Split_Strategy_Gates::CATEGORY,
	$category_review,
	$category_keep_bucket,
	$confirmation_user_id
);
$category_record_json = wp_json_encode($category_confirmation['record']);
wcos_p2_adapter_assert(false === strpos($category_record_json, 'strategy-confirmation-private@example.test'), 'Strategy confirmation record leaked billing PII.');

/* A raw unverified transient record is not execution authority. */
$raw_rejected = false;
try {
	$strategy_confirmation_adapter->split_confirmed(
		wc_get_order($category_order_id),
		WCOS_Split_Strategy_Gates::CATEGORY,
		$category_plan,
		$category_confirmation['operation_id'],
		$category_confirmation['record']['price_precision'],
		$category_confirmation['record']
	);
} catch (RuntimeException $exception) {
	$raw_rejected = false !== strpos($exception->getMessage(), 'verified Split strategy confirmation');
}
wcos_p2_adapter_assert($raw_rejected, 'Strategy adapter accepted an unverified transient confirmation record.');
wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($category_order_id), $category_confirmation['operation_id']), 'Unverified strategy authority created a mutation journal.');

/* Confirmation ownership is explicit before the first journal exists. */
$owner_rejected = false;
try {
	WCOS_Split_Strategy_Confirmation_Store::verify(
		wc_get_order($category_order_id),
		$category_confirmation['operation_id'],
		$category_confirmation['confirmation_token'],
		$other_user_id
	);
} catch (WCOS_Split_Strategy_Confirmation_Exception $exception) {
	$owner_rejected = 'owner_mismatch' === $exception->get_reason();
}
wcos_p2_adapter_assert($owner_rejected, 'Strategy confirmation was usable by a different user before journal creation.');

$verified = WCOS_Split_Strategy_Confirmation_Store::verify(
	wc_get_order($category_order_id),
	$category_confirmation['operation_id'],
	$category_confirmation['confirmation_token'],
	$confirmation_user_id
);
wcos_p2_adapter_assert('confirmation' === $verified['replay_authority'], 'Fresh strategy verification did not use transient confirmation authority.');

/* Live taxonomy may change after confirmation; Execute consumes the frozen plan. */
wp_set_object_terms($category_move->get_id(), array(absint($category_keep_term['term_id'])), 'product_cat');
$children = $strategy_confirmation_adapter->split_confirmed(
	wc_get_order($category_order_id),
	WCOS_Split_Strategy_Gates::CATEGORY,
	$verified['plan'],
	$verified['operation_id'],
	$verified['price_precision'],
	$verified
);
wcos_p2_adapter_assert(1 === count($children), 'Confirmed Category strategy did not create exactly one child.');
$category_child_id = $children[0]->get_id();
$category_source = wc_get_order($category_order_id);
wcos_p2_adapter_assert($category_source->get_item($category_keep_item) instanceof WC_Order_Item_Product, 'Confirmed Category strategy removed the selected source bucket.');
wcos_p2_adapter_assert(!$category_source->get_item($category_move_item), 'Confirmed Category strategy did not execute the frozen moved line.');

$journal = WCOS_Operation_Journal::get($category_source, $verified['operation_id']);
$expected_authority = WCOS_Split_Strategy_Confirmation_Store::operation_authority($verified);
wcos_p2_adapter_assert(is_array($journal) && 'completed' === $journal['status'], 'Confirmed strategy journal did not complete.');
wcos_p2_adapter_assert(isset($journal['context']['strategy_authority']) && $expected_authority === $journal['context']['strategy_authority'], 'Confirmed strategy semantic authority was not durably journaled.');
wcos_p2_adapter_assert(WCOS_Split_Strategy_Gates::CATEGORY === $journal['context']['strategy_authority']['strategy'], 'Durable strategy identity is incorrect.');
wcos_p2_adapter_assert($category_keep_bucket === $journal['context']['strategy_authority']['source_bucket_key'], 'Durable source-bucket authority is incorrect.');
wcos_p2_adapter_assert($category_review['classification_fingerprint'] === $journal['context']['strategy_authority']['classification_fingerprint'], 'Durable classification fingerprint is incorrect.');

$tampered_authority = $expected_authority;
$tampered_authority['source_bucket_key'] = $category_move_bucket;
wcos_p2_adapter_assert(
	!WCOS_Operation_Journal::checkpoint(
		$category_source,
		$verified['operation_id'],
		'strategy_authority_tamper',
		array('strategy_authority' => $tampered_authority)
	),
	'Durable strategy authority was mutable after journal creation.'
);

/* After transient deletion, the same Split journal is the only replay authority. */
WCOS_Split_Strategy_Confirmation_Store::delete($verified['operation_id']);
$durable = WCOS_Split_Strategy_Confirmation_Store::verify(
	wc_get_order($category_order_id),
	$verified['operation_id'],
	'',
	$confirmation_user_id
);
wcos_p2_adapter_assert('journal' === $durable['replay_authority'], 'Strategy replay did not fall back to the durable Split journal.');
wcos_p2_adapter_assert($expected_authority === WCOS_Split_Strategy_Confirmation_Store::operation_authority($durable), 'Durable strategy replay returned different semantic authority.');
$retry = $strategy_confirmation_adapter->split_confirmed(
	wc_get_order($category_order_id),
	WCOS_Split_Strategy_Gates::CATEGORY,
	$durable['plan'],
	$durable['operation_id'],
	$durable['price_precision'],
	$durable
);
wcos_p2_adapter_assert(1 === count($retry) && $category_child_id === $retry[0]->get_id(), 'Durable strategy replay created a different child order.');

/* The same operation ID cannot be replayed under another strategy identity. */
$wrong_strategy = $durable;
$wrong_strategy['strategy'] = WCOS_Split_Strategy_Gates::STOCK_STATUS;
$strategy_swap_rejected = false;
try {
	$strategy_confirmation_adapter->split_confirmed(
		wc_get_order($category_order_id),
		WCOS_Split_Strategy_Gates::STOCK_STATUS,
		$wrong_strategy['plan'],
		$wrong_strategy['operation_id'],
		$wrong_strategy['price_precision'],
		$wrong_strategy
	);
} catch (RuntimeException $exception) {
	$strategy_swap_rejected = false !== strpos($exception->getMessage(), 'does not match the durable operation journal')
		|| false !== strpos($exception->getMessage(), 'different mutation request');
}
wcos_p2_adapter_assert($strategy_swap_rejected, 'Durable Category operation was replayable as Stock-status.');

$wrong_bucket = $durable;
$wrong_bucket['source_bucket_key'] = $category_move_bucket;
$bucket_swap_rejected = false;
try {
	$strategy_confirmation_adapter->split_confirmed(
		wc_get_order($category_order_id),
		WCOS_Split_Strategy_Gates::CATEGORY,
		$wrong_bucket['plan'],
		$wrong_bucket['operation_id'],
		$wrong_bucket['price_precision'],
		$wrong_bucket
	);
} catch (RuntimeException $exception) {
	$bucket_swap_rejected = false !== strpos($exception->getMessage(), 'does not match the durable operation journal')
		|| false !== strpos($exception->getMessage(), 'different mutation request');
}
wcos_p2_adapter_assert($bucket_swap_rejected, 'Durable strategy operation accepted a different source bucket.');
wcos_p2_adapter_assert(1 === count(wcos_p2_adapter_children($category_order_id, $durable['operation_id'])), 'Rejected strategy-authority replay created an extra child.');

wcos_p2_adapter_cleanup($category_order_id, $durable['operation_id']);
wp_delete_post($category_keep->get_id(), true);
wp_delete_post($category_move->get_id(), true);
wp_delete_term(absint($category_keep_term['term_id']), 'product_cat');
wp_delete_term(absint($category_move_term['term_id']), 'product_cat');
wp_delete_user($confirmation_user_id);
wp_delete_user($other_user_id);

echo "p2-strategy-confirmation-authority-ok\n";
