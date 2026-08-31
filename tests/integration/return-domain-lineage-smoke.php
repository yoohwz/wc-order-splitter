<?php

if (!defined('ABSPATH')) {
	exit(1);
}

require_once __DIR__ . '/split-status-fixture-authority.php';
WCOS_Test_Split_Status_Fixture_Authority::allow(array('wc-pending'));

$GLOBALS['wcos_return_foundation_manifest'] = array(
	'orders' => array(),
	'products' => array(),
	'terms' => array(),
	'users' => array(),
	'journal_keys' => array(),
	'tax_classes' => array(),
);

function wcos_return_foundation_record($kind, $value) {
	$GLOBALS['wcos_return_foundation_manifest'][$kind][] = $value;
}

function wcos_return_foundation_assert_manifest_clean() {
	$manifest = $GLOBALS['wcos_return_foundation_manifest'];
	foreach (array_unique(array_map('absint', $manifest['orders'])) as $order_id) {
		wcos_return_foundation_assert(!$order_id || false === wc_get_order($order_id), 'Fixture manifest retained order ' . $order_id . '.');
	}
	foreach (array_unique(array_map('absint', $manifest['products'])) as $product_id) {
		wcos_return_foundation_assert(!$product_id || false === wc_get_product($product_id), 'Fixture manifest retained product ' . $product_id . '.');
	}
	foreach (array_unique(array_map('absint', $manifest['terms'])) as $term_id) {
		wcos_return_foundation_assert(!$term_id || null === get_term($term_id, 'product_cat'), 'Fixture manifest retained product category ' . $term_id . '.');
	}
	foreach (array_unique(array_map('absint', $manifest['users'])) as $user_id) {
		wcos_return_foundation_assert(!$user_id || false === get_user_by('id', $user_id), 'Fixture manifest retained user ' . $user_id . '.');
	}
	foreach (array_unique(array_map('strval', $manifest['journal_keys'])) as $journal_key) {
		wcos_return_foundation_assert(false === get_option($journal_key, false), 'Fixture manifest retained journal ' . $journal_key . '.');
	}
	$remaining_tax_classes = WC_Tax::get_tax_class_slugs();
	foreach (array_unique(array_map('strval', $manifest['tax_classes'])) as $tax_class) {
		wcos_return_foundation_assert(!in_array($tax_class, $remaining_tax_classes, true), 'Fixture manifest retained tax class ' . $tax_class . '.');
	}
	return $manifest;
}

function wcos_return_foundation_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_return_foundation_product($name, $price = '10.00') {
	$product = new WC_Product_Simple();
	$product->set_name($name);
	$product->set_regular_price($price);
	$product->set_manage_stock(true);
	$product->set_stock_quantity(50);
	$product->save();
	wcos_return_foundation_record('products', $product->get_id());
	return $product;
}

function wcos_return_foundation_journal_key($source_id, $operation_id) {
	return 'wcos_mutation_op_' . hash('sha256', absint($source_id) . '|' . sanitize_key($operation_id));
}

function wcos_return_foundation_reason(WC_Order $child) {
	$child_id = $child->get_id();
	WC_Cache_Helper::invalidate_cache_group('orders');
	$child = wc_get_order($child_id);
	try {
		WCOS_Return_Lineage_Authority::resolve($child);
	} catch (WCOS_Return_Lineage_Exception $exception) {
		return $exception->get_reason();
	}
	return 'accepted';
}

function wcos_return_foundation_assert_read_only(WC_Order $source, WC_Order $child, $operation_id, callable $callback, $message) {
	$source_id = $source->get_id();
	$child_id = $child->get_id();
	$source_signature = WCOS_Order_Mutation_Snapshot::split_owned_signature($source);
	$child_signature = WCOS_Order_Contract_Snapshot::source_signature($child);
	$child_relations = array(
		'parent' => $child->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true),
		'operation' => $child->get_meta(WCOS_Split_Order_Service::OPERATION_META, true),
		'key' => $child->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true),
		'legacy_parent' => $child->get_meta('yoos_original_order', true),
	);
	$journal = WCOS_Operation_Journal::get($source, $operation_id);
	$source_notes = count(wc_get_order_notes(array('order_id' => $source_id)));
	$child_notes = count(wc_get_order_notes(array('order_id' => $child_id)));
	$callback();
	$source_after = wc_get_order($source_id);
	$child_after = wc_get_order($child_id);
	wcos_return_foundation_assert($source_signature === WCOS_Order_Mutation_Snapshot::split_owned_signature($source_after), $message . ': source changed during read-only verification.');
	wcos_return_foundation_assert($child_signature === WCOS_Order_Contract_Snapshot::source_signature($child_after), $message . ': child changed during read-only verification.');
	wcos_return_foundation_assert($child_relations === array(
		'parent' => $child_after->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true),
		'operation' => $child_after->get_meta(WCOS_Split_Order_Service::OPERATION_META, true),
		'key' => $child_after->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true),
		'legacy_parent' => $child_after->get_meta('yoos_original_order', true),
	), $message . ': child relation meta changed during read-only verification.');
	wcos_return_foundation_assert($journal === WCOS_Operation_Journal::get($source_after, $operation_id), $message . ': journal changed during read-only verification.');
	wcos_return_foundation_assert($source_notes === count(wc_get_order_notes(array('order_id' => $source_id))), $message . ': source note was written.');
	wcos_return_foundation_assert($child_notes === count(wc_get_order_notes(array('order_id' => $child_id))), $message . ': child note was written.');
}

function wcos_return_foundation_cleanup(WC_Order $source, WC_Order $child, $operation_id, array $products, array $term_ids = array()) {
	WCOS_Operation_Journal::delete(wc_get_order($source->get_id()), $operation_id);
	$child = wc_get_order($child->get_id());
	if ($child) {
		$child->delete(true);
	}
	$source = wc_get_order($source->get_id());
	if ($source) {
		$source->delete(true);
	}
	foreach ($products as $product) {
		if ($product instanceof WC_Product) {
			wp_delete_post($product->get_id(), true);
		}
	}
	foreach ($term_ids as $term_id) {
		wp_delete_term(absint($term_id), 'product_cat');
	}
}

function wcos_return_foundation_strategy_case($strategy, $user_id) {
	$keep = wcos_return_foundation_product('WCOS Return keep ' . $strategy, '12.00');
	$move = wcos_return_foundation_product('WCOS Return move ' . $strategy, '7.00');
	$term_ids = array();
	if (WCOS_Split_Strategy_Gates::CATEGORY === $strategy) {
		$suffix = strtolower(wp_generate_password(6, false, false));
		$keep_term = wp_insert_term('WCOS Return Keep ' . $suffix, 'product_cat');
		$move_term = wp_insert_term('WCOS Return Move ' . $suffix, 'product_cat');
		wcos_return_foundation_assert(!is_wp_error($keep_term) && !is_wp_error($move_term), 'Unable to create Return Category terms.');
		$term_ids = array(absint($keep_term['term_id']), absint($move_term['term_id']));
		foreach ($term_ids as $term_id) {
			wcos_return_foundation_record('terms', $term_id);
		}
		wp_set_object_terms($keep->get_id(), array($term_ids[0]), 'product_cat');
		wp_set_object_terms($move->get_id(), array($term_ids[1]), 'product_cat');
		$source_bucket = 'category-' . $term_ids[0];
	} else {
		$keep->set_stock_status('instock');
		$keep->save();
		$move->set_stock_quantity(0);
		$move->set_stock_status('outofstock');
		$move->save();
		$source_bucket = 'stock-instock';
	}

	$source = wc_create_order();
	wcos_return_foundation_record('orders', $source->get_id());
	$source->set_status('pending');
	$source->set_currency('USD');
	$keep_item_id = $source->add_product($keep, 2);
	$move_item_id = $source->add_product($move, 2);
	$source->calculate_totals(false);
	$source->save();

	$adapter = new WCOS_Split_Strategy_WooCommerce_Adapter();
	$review = $adapter->review($source, $strategy);
	wcos_return_foundation_assert(!empty($review['supported']), 'Strategy review did not support Return provenance fixture: ' . wp_json_encode($review));
	$confirmation = WCOS_Split_Strategy_Confirmation_Store::create($source, $strategy, $review, $source_bucket, $user_id);
	$verified = WCOS_Split_Strategy_Confirmation_Store::verify(
		wc_get_order($source->get_id()),
		$confirmation['operation_id'],
		$confirmation['confirmation_token'],
		$user_id
	);
	$children = $adapter->split_confirmed(
		wc_get_order($source->get_id()),
		$strategy,
		$verified['plan'],
		$verified['operation_id'],
		$verified['price_precision'],
		$verified
	);
	wcos_return_foundation_assert(1 === count($children), 'Confirmed strategy Split did not produce one child.');
	$source = wc_get_order($source->get_id());
	$child = wc_get_order($children[0]->get_id());
	wcos_return_foundation_record('orders', $child->get_id());
	wcos_return_foundation_record('journal_keys', wcos_return_foundation_journal_key($source->get_id(), $verified['operation_id']));
	wcos_return_foundation_assert(!$source->get_item($move_item_id), 'Whole-line strategy source item still exists.');
	wcos_return_foundation_assert($source->get_item($keep_item_id) instanceof WC_Order_Item_Product, 'Strategy source residual item disappeared.');

	$authority = WCOS_Return_Lineage_Authority::resolve($child);
	wcos_return_foundation_assert($strategy === $authority['strategy'], 'Return lineage did not bind the exact strategy identity.');
	wcos_return_foundation_assert(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER === $authority['execution_policy'], 'Return lineage did not bind whole-line execution policy.');
	$line = reset($authority['lines']);
	wcos_return_foundation_assert(WCOS_Return_Plan::DESTINATION_FRESH_SOURCE_ITEM === $line['destination'], 'Whole-line Return plan did not require a fresh destination item.');
	wcos_return_foundation_assert($move_item_id === $line['source_item_id'], 'Whole-line Return lineage lost original source-item provenance.');
	$report = WCOS_Return_Preflight::report($child, true);
	wcos_return_foundation_assert(!empty($report['supported']), 'Confirmed strategy child failed Return preflight.');
	wcos_return_foundation_assert($authority['authority_fingerprint'] === $report['lineage_authority']['authority_fingerprint'], 'Return preflight changed strategy lineage authority.');

	WCOS_Split_Strategy_Confirmation_Store::delete($verified['operation_id']);
	wcos_return_foundation_cleanup($source, $child, $verified['operation_id'], array($keep, $move), $term_ids);
}

wcos_return_foundation_assert(class_exists('WCOS_Return_Lineage_Authority'), 'Return lineage class is not loaded.');
wcos_return_foundation_assert(class_exists('WCOS_Return_Preflight'), 'Return preflight class is not loaded.');
wcos_return_foundation_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER), 'Production Return gate is not enabled.');
wcos_return_foundation_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN), 'Production Bulk Return gate is not enabled.');

$user_id = wp_insert_user(array(
	'user_login' => 'wcos_return_foundation_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-return-foundation-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
wcos_return_foundation_assert(!is_wp_error($user_id), 'Unable to create Return foundation user.');
wcos_return_foundation_record('users', $user_id);
wp_set_current_user($user_id);

/* Manual Quantity: partial residual, per-rate tax, metadata and stock marker. */
$product_a = wcos_return_foundation_product('WCOS Return manual A', '10.00');
$product_b = wcos_return_foundation_product('WCOS Return manual B', '4.00');
$source = wc_create_order();
wcos_return_foundation_record('orders', $source->get_id());
$source->set_status('pending');
$source->set_currency('USD');
$source->set_billing_email('return-private-' . wp_generate_uuid4() . '@example.test');
$source->set_customer_note('return-private-customer-note');
$item_a = new WC_Order_Item_Product();
$item_a_result = $item_a->set_props(array(
	'name' => $product_a->get_name(),
	'product_id' => $product_a->get_id(),
	'quantity' => '3.000000',
	'subtotal' => '30.00',
	'total' => '27.00',
	'subtotal_tax' => '3.00',
	'total_tax' => '2.70',
	'taxes' => array('subtotal' => array(1 => '3.00'), 'total' => array(1 => '2.70')),
));
wcos_return_foundation_assert(!is_wp_error($item_a_result), 'Unable to construct Manual Return line A.');
$item_a->add_meta_data('fulfillment_group', 'north', true);
$item_a->add_meta_data('_reduced_stock', '2.400000', true);
$source->add_item($item_a);
$item_b = new WC_Order_Item_Product();
$item_b_result = $item_b->set_props(array(
	'name' => $product_b->get_name(),
	'product_id' => $product_b->get_id(),
	'quantity' => '2.000000',
	'subtotal' => '8.00',
	'total' => '8.00',
	'subtotal_tax' => '0.00',
	'total_tax' => '0.00',
	'taxes' => array('subtotal' => array(), 'total' => array()),
));
wcos_return_foundation_assert(!is_wp_error($item_b_result), 'Unable to construct Manual Return line B.');
$source->add_item($item_b);
$tax = new WC_Order_Item_Tax();
$tax->set_rate_id(1);
$tax->set_label('Return historical rate');
$tax->set_tax_total('2.70');
$tax->set_shipping_tax_total('0.00');
$source->add_item($tax);
WCOS_Order_Totals_Rebuilder::rebuild($source, 2);
$source->save();
$item_a_id = $item_a->get_id();
$item_b_id = $item_b->get_id();
$source->get_data_store()->set_stock_reduced($source->get_id(), true);
$source = wc_get_order($source->get_id());
WCOS_Order_Totals_Rebuilder::assert_consistent($source, 2);
$manual_split_preflight = WCOS_Split_Preflight::report($source, 2);
wcos_return_foundation_assert(!empty($manual_split_preflight['supported']), 'Manual Return fixture failed Split preflight: ' . wp_json_encode($manual_split_preflight));
$operation_id = 'return-lineage-manual-' . wp_generate_uuid4();
$children = (new WCOS_Mutation_Gateway())->split(
	$source,
	array(
		'manual-child' => array($item_a_id => '1.000000'),
		'manual-sibling' => array($item_a_id => '1.000000'),
	),
	$operation_id,
	2
);
wcos_return_foundation_assert(2 === count($children), 'Manual Split did not create the expected Return fixture children.');
$source = wc_get_order($source->get_id());
$child = null;
$sibling = null;
foreach ($children as $candidate) {
	$candidate = wc_get_order($candidate->get_id());
	if ('manual-child' === $candidate->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true)) {
		$child = $candidate;
	} else {
		$sibling = $candidate;
	}
}
wcos_return_foundation_assert($child instanceof WC_Order && $sibling instanceof WC_Order, 'Manual Split child keys were not persisted exactly.');
wcos_return_foundation_record('orders', $child->get_id());
wcos_return_foundation_record('orders', $sibling->get_id());
wcos_return_foundation_record('journal_keys', wcos_return_foundation_journal_key($source->get_id(), $operation_id));
$authority = WCOS_Return_Lineage_Authority::resolve($child);
wcos_return_foundation_assert('manual_quantity' === $authority['strategy'], 'Manual Return lineage strategy is incorrect.');
wcos_return_foundation_assert(WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY === $authority['execution_policy'], 'Manual Return lineage execution policy is incorrect.');
wcos_return_foundation_assert(isset($authority['lines'][$item_a_id]), 'Manual Return lineage omitted its source item.');
$manual_line = $authority['lines'][$item_a_id];
wcos_return_foundation_assert(WCOS_Return_Plan::DESTINATION_RESIDUAL_SOURCE_ITEM === $manual_line['destination'], 'Manual Return plan did not bind the residual source item.');
wcos_return_foundation_assert('1.000000' === $manual_line['quantity'], 'Manual Return quantity authority is incorrect.');
wcos_return_foundation_assert('10.00' === $manual_line['subtotal'], 'Manual Return subtotal allocation is incorrect.');
wcos_return_foundation_assert('9.00' === $manual_line['total'], 'Manual Return total allocation is incorrect.');
wcos_return_foundation_assert('1.00' === $manual_line['taxes']['subtotal'][1], 'Manual Return per-rate subtotal tax is incorrect.');
wcos_return_foundation_assert('0.90' === $manual_line['taxes']['total'][1], 'Manual Return per-rate total tax is incorrect.');
wcos_return_foundation_assert('0.800000' === $manual_line['reduced_stock'], 'Manual Return reduced-stock ownership allocation is incorrect.');
$report = WCOS_Return_Preflight::report($child, true);
wcos_return_foundation_assert(!empty($report['supported']), 'Manual hardened Split child failed Return preflight.');
wcos_return_foundation_assert($authority['authority_fingerprint'] === $report['lineage_authority']['authority_fingerprint'], 'Return preflight changed Manual lineage authority.');
$payload = wp_json_encode(array($authority, $report['return_plan']));
wcos_return_foundation_assert(false === strpos($payload, 'return-private-'), 'Return authority leaked billing/customer plaintext.');
wcos_return_foundation_assert(false === strpos($payload, 'customer-note'), 'Return authority leaked customer note plaintext.');
$durable_split_record = WCOS_Operation_Journal::get($source, $operation_id);
$raw_source_signature = $durable_split_record['context']['source_signature_after'];
$raw_child_signature = WCOS_Order_Contract_Snapshot::source_signature($child);
$raw_line_identity = $durable_split_record['context']['source_snapshot']['line_items'][$item_a_id]['identity'];
$raw_snapshot_fingerprint = $durable_split_record['context']['source_snapshot']['recovery_fingerprint'];
wcos_return_foundation_assert(false === strpos($payload, $raw_source_signature), 'Return authority exposed an unsalted source commercial digest.');
wcos_return_foundation_assert(false === strpos($payload, $raw_child_signature), 'Return authority exposed an unsalted child commercial digest.');
wcos_return_foundation_assert(false === strpos($payload, $raw_line_identity), 'Return authority exposed an unsalted business-metadata identity digest.');
wcos_return_foundation_assert(false === strpos($payload, $raw_snapshot_fingerprint), 'Return authority exposed an unsalted source snapshot digest.');

/* Current catalog is not authority: lineage resolution must not dereference order products. */
$catalog_accesses = 0;
$catalog_observer = static function($product) use (&$catalog_accesses) {
	$catalog_accesses++;
	return $product;
};
add_filter('woocommerce_order_item_product', $catalog_observer, 10, 1);
$catalog_independent = WCOS_Return_Lineage_Authority::resolve(wc_get_order($child->get_id()));
remove_filter('woocommerce_order_item_product', $catalog_observer, 10);
wcos_return_foundation_assert(0 === $catalog_accesses, 'Return lineage dereferenced the current product catalog.');
wcos_return_foundation_assert($authority['authority_fingerprint'] === $catalog_independent['authority_fingerprint'], 'Return lineage changed without current catalog authority.');

/* Tamper matrix; every rejection is read-only. */
$child_line = current($child->get_items('line_item'));
$original_quantity = $child_line->get_quantity();
$child_line->set_quantity('2.000000');
$child_line->save();
$child = wc_get_order($child->get_id());
WCOS_Order_Totals_Rebuilder::rebuild($child, 2);
$child->save();
$child = wc_get_order($child->get_id());
wcos_return_foundation_assert_read_only($source, $child, $operation_id, static function() use ($child) {
	wcos_return_foundation_assert('child_line_drift' === wcos_return_foundation_reason($child), 'Changed child quantity was not rejected.');
}, 'child quantity tamper');
$child_line = current(wc_get_order($child->get_id())->get_items('line_item'));
$child_line->set_quantity($original_quantity);
$child_line->save();
$child = wc_get_order($child->get_id());
WCOS_Order_Totals_Rebuilder::rebuild($child, 2);
$child->save();

$child = wc_get_order($child->get_id());
$child_line = current($child->get_items('line_item'));
$original_subtotal = $child_line->get_subtotal();
$original_total = $child_line->get_total();
$child_line->set_subtotal('11.00');
$child_line->set_total('10.00');
$child_line->save();
$child = wc_get_order($child->get_id());
WCOS_Order_Totals_Rebuilder::rebuild($child, 2);
$child->save();
wcos_return_foundation_assert('child_line_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Changed child money was not rejected.');
$child_line = current(wc_get_order($child->get_id())->get_items('line_item'));
$child_line->set_subtotal($original_subtotal);
$child_line->set_total($original_total);
$child_line->save();
$child = wc_get_order($child->get_id());
WCOS_Order_Totals_Rebuilder::rebuild($child, 2);
$child->save();

$child = wc_get_order($child->get_id());
$child_line = current($child->get_items('line_item'));
$child_tax = current($child->get_items('tax'));
$child_line->set_taxes(array('subtotal' => array(1 => '1.01'), 'total' => array(1 => '0.91')));
$child_line->save();
$child_tax->set_tax_total('0.91');
$child_tax->save();
$child = wc_get_order($child->get_id());
WCOS_Order_Totals_Rebuilder::rebuild($child, 2);
$child->save();
wcos_return_foundation_assert('child_line_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Changed child per-rate tax was not rejected.');
$child = wc_get_order($child->get_id());
$child_line = current($child->get_items('line_item'));
$child_tax = current($child->get_items('tax'));
$child_line->set_taxes(array('subtotal' => array(1 => '1.00'), 'total' => array(1 => '0.90')));
$child_line->save();
$child_tax->set_tax_total('0.90');
$child_tax->save();
$child = wc_get_order($child->get_id());
WCOS_Order_Totals_Rebuilder::rebuild($child, 2);
$child->save();

$child_line = current(wc_get_order($child->get_id())->get_items('line_item'));
$child_line->add_meta_data('tampered_business_identity', 'changed', true);
$child_line->save();
wcos_return_foundation_assert('child_line_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Changed child business metadata was not rejected.');
$child_line->delete_meta_data('tampered_business_identity');
$child_line->save();

$child_line = current(wc_get_order($child->get_id())->get_items('line_item'));
$child_line->add_meta_data('_unknown_return_private', 'changed', true);
$child_line->save();
wcos_return_foundation_assert('child_line_private_meta_unsupported' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Unknown private child metadata was not rejected.');
$child_line->delete_meta_data('_unknown_return_private');
$child_line->save();

$child_line = current(wc_get_order($child->get_id())->get_items('line_item'));
$original_tax_class = $child_line->get_tax_class();
$tampered_tax_class = WC_Tax::create_tax_class('WCOS Return Tampered ' . wp_generate_password(6, false, false));
wcos_return_foundation_assert(!is_wp_error($tampered_tax_class), 'Unable to create a disposable tax class for lineage tamper evidence.');
wcos_return_foundation_record('tax_classes', $tampered_tax_class['slug']);
$child_line->set_tax_class($tampered_tax_class['slug']);
$child_line->save();
wcos_return_foundation_assert('child_line_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Changed child tax class was not rejected.');
$child_line->set_tax_class($original_tax_class);
$child_line->save();
WC_Tax::delete_tax_class_by('slug', $tampered_tax_class['slug']);

$child_line = current(wc_get_order($child->get_id())->get_items('line_item'));
$original_variation_id = $child_line->get_variation_id();
$tampered_variable = new WC_Product_Variable();
$tampered_variable->set_name('WCOS Return tampered variation parent');
$tampered_variable->save();
wcos_return_foundation_record('products', $tampered_variable->get_id());
$tampered_variation = new WC_Product_Variation();
$tampered_variation->set_parent_id($tampered_variable->get_id());
$tampered_variation->set_regular_price('1.00');
$tampered_variation->save();
wcos_return_foundation_record('products', $tampered_variation->get_id());
$child_line->set_variation_id($tampered_variation->get_id());
$child_line->save();
wcos_return_foundation_assert('child_line_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Changed child variation was not rejected.');
$child_line->set_variation_id($original_variation_id);
$child_line->save();
wp_delete_post($tampered_variation->get_id(), true);
wp_delete_post($tampered_variable->get_id(), true);

$child = wc_get_order($child->get_id());
$child_line = current($child->get_items('line_item'));
$child_line->delete_meta_data('_wcos_source_item_id');
$child_line->save();
wcos_return_foundation_assert('child_line_provenance_invalid' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Missing child source-item provenance was not rejected.');
$child_line->add_meta_data('_wcos_source_item_id', $item_a_id, true);
$child_line->save();

$child = wc_get_order($child->get_id());
$child_line = current($child->get_items('line_item'));
$original_source_item_id = $child_line->get_meta('_wcos_source_item_id', true);
$child_line->update_meta_data('_wcos_source_item_id', $item_b_id);
$child_line->save();
$child = wc_get_order($child->get_id());
wcos_return_foundation_assert('child_line_provenance_mismatch' === wcos_return_foundation_reason($child), 'Wrong child source-item provenance was not rejected.');
$child_line = current($child->get_items('line_item'));
$child_line->update_meta_data('_wcos_source_item_id', $original_source_item_id);
$child_line->save();

$child = wc_get_order($child->get_id());
$original_parent = $child->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true);
$child->update_meta_data(WCOS_Split_Order_Service::RELATION_PARENT_META, 999999999);
$child->save_meta_data();
wcos_return_foundation_assert('source_missing' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Forged parent ID was not rejected.');
$child->update_meta_data(WCOS_Split_Order_Service::RELATION_PARENT_META, $original_parent);
$child->save_meta_data();

$child = wc_get_order($child->get_id());
$original_operation = $child->get_meta(WCOS_Split_Order_Service::OPERATION_META, true);
$child->update_meta_data(WCOS_Split_Order_Service::OPERATION_META, 'wrong-operation');
$child->save_meta_data();
wcos_return_foundation_assert('journal_missing' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Wrong Split operation ID was not rejected.');
$child->update_meta_data(WCOS_Split_Order_Service::OPERATION_META, $original_operation);
$child->save_meta_data();

$child = wc_get_order($child->get_id());
$original_key = $child->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true);
$child->update_meta_data(WCOS_Split_Order_Service::CHILD_KEY_META, 'wrong-child');
$child->save_meta_data();
wcos_return_foundation_assert('child_key_not_in_plan' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Wrong Split child key was not rejected.');
$child->update_meta_data(WCOS_Split_Order_Service::CHILD_KEY_META, $original_key);
$child->save_meta_data();

$sibling = wc_get_order($sibling->get_id());
$sibling_key = $sibling->get_meta(WCOS_Split_Order_Service::CHILD_KEY_META, true);
$sibling->update_meta_data(WCOS_Split_Order_Service::CHILD_KEY_META, $original_key);
$sibling->save_meta_data();
wcos_return_foundation_assert('journal_target_provenance_mismatch' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Duplicate persisted Split child key was not rejected.');
$sibling->update_meta_data(WCOS_Split_Order_Service::CHILD_KEY_META, $sibling_key);
$sibling->save_meta_data();

$source = wc_get_order($source->get_id());
$relations = $source->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true);
$source->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, array());
$source->save_meta_data();
wcos_return_foundation_assert('source_relation_mismatch' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Missing source relation was not rejected.');
$source->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, $relations);
$source->save_meta_data();

$source = wc_get_order($source->get_id());
$source->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, array_merge($relations, array(999999996)));
$source->save_meta_data();
wcos_return_foundation_assert('source_relation_target_mismatch' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Unrelated source child relation was not rejected.');
$source->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, $relations);
$source->save_meta_data();

$source = wc_get_order($source->get_id());
$residual_line = $source->get_item($item_a_id);
$residual_quantity = $residual_line->get_quantity();
$residual_line->set_quantity('0.500000');
$residual_line->save();
wcos_return_foundation_assert('source_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Residual source line drift was not rejected.');
$residual_line->set_quantity($residual_quantity);
$residual_line->save();

$source = wc_get_order($source->get_id());
$fee = new WC_Order_Item_Fee();
$fee->set_props(array('name' => 'Return drift fee', 'amount' => '0.00', 'total' => '0.00', 'total_tax' => '0.00', 'taxes' => array('total' => array())));
$source->add_item($fee);
$source->save();
wcos_return_foundation_assert('source_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Source fee context drift was not rejected.');
$source->remove_item($fee->get_id());
$source->save();

$source = wc_get_order($source->get_id());
$shipping = new WC_Order_Item_Shipping();
$shipping->set_props(array('method_title' => 'Return drift shipping', 'method_id' => 'flat_rate', 'total' => '0.00', 'total_tax' => '0.00', 'taxes' => array('total' => array())));
$source->add_item($shipping);
$source->save();
wcos_return_foundation_assert('source_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Source shipping context drift was not rejected.');
$source->remove_item($shipping->get_id());
$source->save();

$source = wc_get_order($source->get_id());
$coupon = new WC_Order_Item_Coupon();
$coupon->set_props(array('code' => 'return-drift', 'discount' => '0.00', 'discount_tax' => '0.00'));
$source->add_item($coupon);
$source->save();
wcos_return_foundation_assert('source_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Source coupon context drift was not rejected.');
$source->remove_item($coupon->get_id());
$source->save();

$source = wc_get_order($source->get_id());
$source->set_transaction_id('return-drift-transaction');
$source->save();
wcos_return_foundation_assert('source_drift' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Source payment context drift was not rejected.');
$source->set_transaction_id('');
$source->save();

$child = wc_get_order($child->get_id());
$legacy_parent = $child->get_meta('yoos_original_order', true);
$child->update_meta_data('yoos_original_order', 999999998);
$child->save_meta_data();
wcos_return_foundation_assert('legacy_lineage_conflict' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Conflicting legacy parent was not rejected.');
$child->update_meta_data('yoos_original_order', $legacy_parent);
$child->save_meta_data();

$journal_key = wcos_return_foundation_journal_key($source->get_id(), $operation_id);
$journal = get_option($journal_key);
delete_option($journal_key);
wcos_return_foundation_assert('journal_missing' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Missing Split journal was not rejected.');
add_option($journal_key, $journal, '', false);

$tampered = $journal;
$tampered['status'] = 'started';
update_option($journal_key, $tampered, false);
wcos_return_foundation_assert('journal_not_completed' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Nonterminal Split journal was not rejected.');
update_option($journal_key, $journal, false);

$tampered = $journal;
$tampered['type'] = 'duplicate';
update_option($journal_key, $tampered, false);
wcos_return_foundation_assert('journal_wrong_type' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Wrong journal type was not rejected.');
update_option($journal_key, $journal, false);

$tampered = $journal;
$tampered['context']['target_order_ids'] = array($child->get_id(), $child->get_id());
update_option($journal_key, $tampered, false);
wcos_return_foundation_assert('target_order_ids_duplicate' === wcos_return_foundation_reason(wc_get_order($child->get_id())), 'Duplicate journal target membership was not rejected.');
update_option($journal_key, $journal, false);

$child = wc_get_order($child->get_id());
$child->update_meta_data(WCOS_Return_Participation::CHILD_ORIGINAL_META, $source->get_id());
$child->update_meta_data(WCOS_Return_Participation::CHILD_OPERATION_META, 'return-operation');
$child->update_meta_data(WCOS_Return_Participation::CHILD_PAIR_FINGERPRINT_META, str_repeat('a', 64));
$child->save_meta_data();
$terminal_report = WCOS_Return_Preflight::report(wc_get_order($child->get_id()), true);
wcos_return_foundation_assert(empty($terminal_report['supported']) && 'already_returned' === $terminal_report['reason'], 'Terminal Return participation was not rejected.');
$child->delete_meta_data(WCOS_Return_Participation::CHILD_ORIGINAL_META);
$child->delete_meta_data(WCOS_Return_Participation::CHILD_OPERATION_META);
$child->delete_meta_data(WCOS_Return_Participation::CHILD_PAIR_FINGERPRINT_META);
$child->save_meta_data();

$child = wc_get_order($child->get_id());
$child->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, array(999999995));
$child->save_meta_data();
$nested_report = WCOS_Return_Preflight::report(wc_get_order($child->get_id()), true);
wcos_return_foundation_assert(empty($nested_report['supported']) && 'nested_or_parent_child' === $nested_report['reason'], 'Nested/descendant Return participation was not rejected.');
$child->delete_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META);
$child->save_meta_data();

$child = wc_get_order($child->get_id());
$child->add_meta_data(WCOS_Merge_Participation::TARGET_OPERATION_META, 'conflicting-merge-operation', false);
$child->save_meta_data();
$merge_participation_report = WCOS_Return_Preflight::report(wc_get_order($child->get_id()), true);
wcos_return_foundation_assert(empty($merge_participation_report['supported']) && 'unresolved_mutation_authority' === $merge_participation_report['reason'], 'Conflicting Merge participation was not rejected.');
$child->delete_meta_data(WCOS_Merge_Participation::TARGET_OPERATION_META);
$child->save_meta_data();

$child = wc_get_order($child->get_id());
$child->set_transaction_id('return-child-transaction');
$child->save();
$transaction_report = WCOS_Return_Preflight::report(wc_get_order($child->get_id()), true);
wcos_return_foundation_assert(empty($transaction_report['supported']) && 'child_payment_ownership' === $transaction_report['reason'], 'Transaction-bearing Return child was not rejected.');
$child->set_transaction_id('');
$child->save();

$child = wc_get_order($child->get_id());
$child_fee = new WC_Order_Item_Fee();
$child_fee->set_props(array('name' => 'Unsupported child fee', 'amount' => '0.00', 'total' => '0.00', 'total_tax' => '0.00', 'taxes' => array('total' => array())));
$child->add_item($child_fee);
$child->save();
$charge_report = WCOS_Return_Preflight::report(wc_get_order($child->get_id()), true);
wcos_return_foundation_assert(empty($charge_report['supported']) && 'child_charge_ownership' === $charge_report['reason'], 'Charge-owning Return child was not rejected.');
$child->remove_item($child_fee->get_id());
$child->save();

/* Legacy-only metadata remains explicitly non-executable. */
$legacy = wc_create_order();
wcos_return_foundation_record('orders', $legacy->get_id());
$legacy->update_meta_data('yoos_original_order', $source->get_id());
$legacy->save();
$legacy_report = WCOS_Return_Preflight::report(wc_get_order($legacy->get_id()), true);
wcos_return_foundation_assert(empty($legacy_report['supported']) && 'legacy_lineage_not_authoritative' === $legacy_report['reason'], 'Legacy-only Return lineage became executable.');
$legacy->delete(true);

$sibling->delete(true);
wcos_return_foundation_cleanup($source, wc_get_order($child->get_id()), $operation_id, array($product_a, $product_b));

/* Current production Category and Stock-status authority after DB reread. */
wcos_return_foundation_strategy_case(WCOS_Split_Strategy_Gates::CATEGORY, $user_id);
wcos_return_foundation_strategy_case(WCOS_Split_Strategy_Gates::STOCK_STATUS, $user_id);

wp_delete_user($user_id);

$fixture_manifest = wcos_return_foundation_assert_manifest_clean();
echo 'return-domain-lineage-fixture-manifest=' . hash('sha256', wp_json_encode($fixture_manifest)) . "\n";
echo "return-domain-lineage-foundation-ok\n";
