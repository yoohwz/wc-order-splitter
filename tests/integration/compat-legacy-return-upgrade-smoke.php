<?php

if (!defined('ABSPATH')) { exit(1); }

function wcos_legacy_return_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_legacy_return_line(WC_Order $order, WC_Product $product, $quantity, $subtotal, $total, $label) {
	$item = new WC_Order_Item_Product();
	$item->set_props(array(
		'name' => 'Legacy line ' . $label,
		'product_id' => $product->get_id(),
		'variation_id' => 0,
		'quantity' => $quantity,
		'tax_class' => '',
		'subtotal' => $subtotal,
		'total' => $total,
		'subtotal_tax' => '0.00',
		'total_tax' => '0.00',
		'taxes' => array('subtotal' => array(), 'total' => array()),
	));
	$item->add_meta_data('legacy_configuration', $label, true);
	$order->add_item($item);
	$order->save();
	return absint($item->get_id());
}

function wcos_legacy_return_shipping(WC_Order $order, $label, $total = '4.00') {
	$item = new WC_Order_Item_Shipping();
	$item->set_props(array(
		'method_title' => 'Legacy shipping ' . $label,
		'method_id' => 'flat_rate',
		'instance_id' => 7,
		'total' => $total,
		'taxes' => array('total' => array()),
	));
	$item->add_meta_data('Items', 'Legacy private display ' . $label, true);
	$order->add_item($item);
	$order->save();
	return absint($item->get_id());
}

function wcos_legacy_return_rebuild(WC_Order $order) {
	WCOS_Order_Totals_Rebuilder::rebuild($order, 2);
	$order->save();
	return wc_get_order($order->get_id());
}

function wcos_legacy_return_shipping_state(WC_Order $order) {
	$state = array();
	foreach ($order->get_items('shipping') as $item_id => $item) {
		$meta = array();
		foreach ($item->get_meta_data() as $entry) {
			$data = $entry->get_data();
			$meta[] = array(
				'key' => (string) $data['key'],
				'value' => $data['value'],
			);
		}
		$state[absint($item_id)] = array(
			'name' => (string) $item->get_name(),
			'method_title' => (string) $item->get_method_title(),
			'method_id' => (string) $item->get_method_id(),
			'instance_id' => absint($item->get_instance_id()),
			'total' => WCOS_Decimal::normalize($item->get_total(), 2),
			'total_tax' => WCOS_Decimal::normalize($item->get_total_tax(), 2),
			'taxes' => $item->get_taxes(),
			'meta' => $meta,
		);
	}
	ksort($state, SORT_NUMERIC);
	return $state;
}

function wcos_legacy_return_fixture($label, $whole_line = false, $shipping = false, $paid_status = false) {
	$product = new WC_Product_Simple();
	$product->set_name('Legacy compatibility product ' . $label);
	$product->set_regular_price('10.00');
	$product->set_price('10.00');
	$product->set_manage_stock(false);
	wcos_legacy_return_assert($product->save() > 0, 'Legacy product fixture could not be saved.');
	$keep = new WC_Product_Simple();
	$keep->set_name('Legacy compatibility keep ' . $label);
	$keep->set_regular_price('2.00');
	$keep->set_price('2.00');
	$keep->set_manage_stock(false);
	wcos_legacy_return_assert($keep->save() > 0, 'Legacy keep product fixture could not be saved.');

	$source = wc_create_order();
	$source->set_status('pending');
	$source->set_currency('USD');
	$source->set_prices_include_tax(false);
	$source->set_payment_method('cod');
	$source->save();
	$residual_id = 0;
	if (!$whole_line) {
		$residual_id = wcos_legacy_return_line($source, $product, '1.000000', '10.00', '9.00', $label);
	}
	$keep_id = wcos_legacy_return_line($source, $keep, '1.000000', '2.00', '2.00', 'keep-' . $label);
	if ($shipping) { wcos_legacy_return_shipping($source, 'source-' . $label); }
	$source = wcos_legacy_return_rebuild($source);

	$child = wc_create_order();
	$child->set_status($paid_status ? 'processing' : 'pending');
	$child->set_currency('USD');
	$child->set_prices_include_tax(false);
	$child->set_payment_method('cod');
	$child->save();
	$child_item_id = wcos_legacy_return_line($child, $product, '2.000000', '20.00', '18.00', $label);
	if ($shipping) { wcos_legacy_return_shipping($child, 'child-' . $label); }
	$child = wcos_legacy_return_rebuild($child);
	$source->update_meta_data('yoos_splitted_order', $child->get_id());
	$source->save_meta_data();
	$child->update_meta_data('yoos_original_order', $source->get_id());
	$child->save_meta_data();

	return array(
		'product_ids' => array($product->get_id(), $keep->get_id()),
		'source_id' => $source->get_id(),
		'child_id' => $child->get_id(),
		'child_item_id' => $child_item_id,
		'residual_item_id' => $residual_id,
		'keep_item_id' => $keep_id,
		'operation_ids' => array(),
		'review_ids' => array(),
	);
}

function wcos_legacy_return_execute(array &$fixture, WCOS_Return_Admin_Controller $controller) {
	$request = array(
		'child_order_id' => $fixture['child_id'],
		'nonce' => wp_create_nonce('wcos_return_order_' . $fixture['child_id']),
	);
	$review = $controller->review_request($request);
	$fixture['review_ids'][] = $review['review_id'];
	$confirm = $controller->confirm_request(array_merge($request, array(
		'review_id' => $review['review_id'],
		'review_token' => $review['review_token'],
	)));
	$fixture['operation_ids'][] = $confirm['operation_id'];
	$result = $controller->execute_request(array_merge($request, array(
		'operation_id' => $confirm['operation_id'],
		'confirmation_token' => $confirm['confirmation_token'],
	)));
	return array($review, $confirm, $result);
}

function wcos_legacy_return_capture_settings() {
	$missing = '__wcos_compat_003_missing_option__';
	$settings = array();
	foreach (array('order_splitter_status_allowed', 'order_splitter_exclude_shipping_fee') as $option_name) {
		$value = get_option($option_name, $missing);
		$settings[$option_name] = array(
			'exists' => $missing !== $value,
			'value' => $missing !== $value ? $value : null,
		);
	}
	return $settings;
}

function wcos_legacy_return_restore_settings(array $settings) {
	foreach ($settings as $option_name => $state) {
		if (!in_array($option_name, array('order_splitter_status_allowed', 'order_splitter_exclude_shipping_fee'), true) || !is_array($state)) {
			continue;
		}
		if (!empty($state['exists'])) {
			update_option($option_name, isset($state['value']) ? $state['value'] : null);
		} else {
			delete_option($option_name);
		}
	}
}

function wcos_legacy_return_cleanup(array $fixture) {
	if (!empty($fixture['settings_before']) && is_array($fixture['settings_before'])) {
		wcos_legacy_return_restore_settings($fixture['settings_before']);
	}
	if (!empty($fixture['baseline_sha'])) {
		delete_option('wcos_compat_003_genuine_1_4_11_fixture');
	}
	foreach (isset($fixture['review_ids']) ? $fixture['review_ids'] : array() as $review_id) { WCOS_Return_Review_Store::delete($review_id); }
	foreach (isset($fixture['split_confirmation_ids']) ? $fixture['split_confirmation_ids'] : array() as $operation_id) { WCOS_Split_Confirmation_Store::delete($operation_id); }
	foreach (isset($fixture['operation_ids']) ? $fixture['operation_ids'] : array() as $operation_id) {
		WCOS_Return_Confirmation_Store::delete($operation_id);
		$child = wc_get_order($fixture['child_id']);
		if ($child instanceof WC_Order) { WCOS_Operation_Journal::delete($child, $operation_id); }
	}
	$order_ids = array_merge(
		array(absint(isset($fixture['child_id']) ? $fixture['child_id'] : 0), absint(isset($fixture['source_id']) ? $fixture['source_id'] : 0)),
		isset($fixture['extra_order_ids']) ? array_map('absint', (array) $fixture['extra_order_ids']) : array()
	);
	foreach (array_values(array_unique(array_filter($order_ids))) as $order_id) {
		$order = wc_get_order($order_id);
		if ($order instanceof WC_Order) {
			$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
			foreach (is_array($summary) ? $summary : array() as $entry) {
				if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
			}
			$order->delete(true);
		}
	}
	$product_ids = array_merge(
		isset($fixture['product_ids']) ? (array) $fixture['product_ids'] : array(),
		isset($fixture['extra_product_ids']) ? (array) $fixture['extra_product_ids'] : array()
	);
	foreach (array_values(array_unique(array_filter(array_map('absint', $product_ids)))) as $product_id) {
		$product = wc_get_product($product_id);
		if ($product instanceof WC_Product) { $product->delete(true); }
	}
}

function wcos_legacy_return_reason(WC_Order $child) {
	$report = WCOS_Return_Preflight::report($child, true);
	return empty($report['supported']) ? (string) $report['reason'] : 'supported';
}

function wcos_legacy_return_add_sibling(array &$fixture, $label) {
	$source = wc_get_order($fixture['source_id']);
	$product = wc_get_product($fixture['product_ids'][0]);
	$child = wc_create_order();
	$child->set_status('pending');
	$child->set_currency($source->get_currency());
	$child->set_prices_include_tax($source->get_prices_include_tax());
	$child->set_payment_method('cod');
	$child->save();
	wcos_legacy_return_line($child, $product, '1.000000', '10.00', '9.00', $label);
	$child = wcos_legacy_return_rebuild($child);
	$child->update_meta_data('yoos_original_order', $source->get_id());
	$child->save_meta_data();
	$ids = WCOS_Legacy_Return_Compatibility_Authority::legacy_child_ids($source, true);
	$ids[] = $child->get_id();
	$source->update_meta_data('yoos_splitted_order', implode(',', $ids));
	$source->save_meta_data();
	$fixture['extra_order_ids'][] = $child->get_id();
	return $child->get_id();
}

function wcos_legacy_return_add_hardened_sibling(array &$fixture, $label) {
	$source = wc_get_order($fixture['source_id']);
	$keep = $source->get_item($fixture['keep_item_id']);
	$keep->set_quantity('3.000000');
	$keep->set_subtotal('6.00');
	$keep->set_total('6.00');
	$keep->save();
	$source = wcos_legacy_return_rebuild(wc_get_order($source->get_id()));
	update_option('order_splitter_status_allowed', array('wc-pending'));
	update_option('order_splitter_exclude_shipping_fee', 'yes');
	$plan = array($label => array($fixture['keep_item_id'] => '1.000000'));
	$preflight = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($source);
	wcos_legacy_return_assert(!empty($preflight['supported']), 'Mixed-lineage source failed hardened Split preflight: ' . $preflight['reason']);
	$created = WCOS_Split_Confirmation_Store::create($source, $plan, $preflight, get_current_user_id());
	$verified = WCOS_Split_Confirmation_Store::verify($source, $created['operation_id'], $created['confirmation_token'], get_current_user_id());
	$children = (new WCOS_Mutation_Gateway())->split_manual_confirmed(
		$source,
		$verified['plan'],
		$verified['operation_id'],
		$verified['price_precision'],
		$verified
	);
	wcos_legacy_return_assert(1 === count($children), 'Mixed-lineage Split did not create exactly one hardened sibling.');
	$child = reset($children);
	$fixture['extra_order_ids'][] = $child->get_id();
	$fixture['split_confirmation_ids'][] = $created['operation_id'];
	return $child->get_id();
}

function wcos_legacy_return_expect_confirm_drift(WCOS_Return_Admin_Controller $controller, array &$fixture, callable $drift, $label) {
	$child = wc_get_order($fixture['child_id']);
	$request = array(
		'child_order_id' => $child->get_id(),
		'nonce' => wp_create_nonce('wcos_return_order_' . $child->get_id()),
	);
	$review = $controller->review_request($request);
	$fixture['review_ids'][] = $review['review_id'];
	$drift();
	$rejected = false;
	try {
		$controller->confirm_request(array_merge($request, array(
			'review_id' => $review['review_id'],
			'review_token' => $review['review_token'],
		)));
	} catch (WCOS_Return_Transport_Exception $exception) {
		$rejected = 0 === strpos($exception->get_error_code(), 'review_');
	}
	wcos_legacy_return_assert($rejected, 'Compatibility Confirm did not reject ' . $label . ' drift after Review.');
}

function wcos_legacy_return_verify_genuine_upgrade(array &$fixture, WCOS_Return_Admin_Controller $controller) {
	if (empty($fixture)) { return false; }
	wcos_legacy_return_assert('e1d8aeb8eff38f4ce69dad1a08993e17521c6359' === (string) $fixture['baseline_sha'], 'The genuine upgrade fixture is not bound to the exact 1.4.11 SHA.');
	wcos_legacy_return_assert('75140a414cd637d134f860d8a70e7f92cbe4853c' === (string) $fixture['baseline_tree'], 'The genuine upgrade fixture is not bound to the exact 1.4.11 tree.');
	wcos_legacy_return_assert('1.4.11' === (string) $fixture['baseline_version'], 'The genuine upgrade fixture was not created by plugin version 1.4.11.');
	$fixture['product_ids'] = array(absint($fixture['moved_product_id']), absint($fixture['keep_product_id']));
	$fixture['review_ids'] = array();
	$fixture['operation_ids'] = array();

	$source = wc_get_order(absint($fixture['source_id']));
	$child = wc_get_order(absint($fixture['child_id']));
	wcos_legacy_return_assert($source instanceof WC_Order && $child instanceof WC_Order, 'The genuine 1.4.11 upgrade participants are unavailable.');
	$source_shipping = wcos_legacy_return_shipping_state($source);
	$child_shipping = wcos_legacy_return_shipping_state($child);
	$physical_before = wc_get_product(absint($fixture['moved_product_id']))->get_stock_quantity();
	$read_only_before = array(
		'source_commercial' => WCOS_Order_Contract_Snapshot::source_signature($source),
		'child_commercial' => WCOS_Order_Contract_Snapshot::source_signature($child),
		'legacy_children' => (string) $source->get_meta('yoos_splitted_order', true),
		'legacy_parent' => (string) $child->get_meta('yoos_original_order', true),
		'hardened_parent' => $child->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true),
		'journal_summary' => $child->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true),
	);

	$request = array(
		'child_order_id' => $child->get_id(),
		'nonce' => wp_create_nonce('wcos_return_order_' . $child->get_id()),
	);
	$review = $controller->review_request($request);
	$fixture['review_ids'][] = $review['review_id'];
	$source_after_review = wc_get_order($source->get_id());
	$child_after_review = wc_get_order($child->get_id());
	$read_only_after = array(
		'source_commercial' => WCOS_Order_Contract_Snapshot::source_signature($source_after_review),
		'child_commercial' => WCOS_Order_Contract_Snapshot::source_signature($child_after_review),
		'legacy_children' => (string) $source_after_review->get_meta('yoos_splitted_order', true),
		'legacy_parent' => (string) $child_after_review->get_meta('yoos_original_order', true),
		'hardened_parent' => $child_after_review->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true),
		'journal_summary' => $child_after_review->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true),
	);
	wcos_legacy_return_assert($read_only_before === $read_only_after, 'Read-only Review eagerly migrated or changed the genuine 1.4.11 orders.');
	wcos_legacy_return_assert(!empty($review['summary']['compatibility']['legacy_1_4_11_detected']), 'The genuine 1.4.11 Review did not disclose compatibility provenance.');

	$confirm = $controller->confirm_request(array_merge($request, array(
		'review_id' => $review['review_id'],
		'review_token' => $review['review_token'],
	)));
	$fixture['operation_ids'][] = $confirm['operation_id'];
	$result = $controller->execute_request(array_merge($request, array(
		'operation_id' => $confirm['operation_id'],
		'confirmation_token' => $confirm['confirmation_token'],
	)));
	wcos_legacy_return_assert('completed' === $result['status'], 'The genuine exact-1.4.11 upgrade Return did not complete.');

	$source_after = wc_get_order($source->get_id());
	$child_after = wc_get_order($child->get_id());
	$restored = $source_after->get_item(absint($fixture['moved_source_item_id']));
	wcos_legacy_return_assert($restored instanceof WC_Order_Item_Product, 'The genuine 1.4.11 residual source item disappeared.');
	wcos_legacy_return_assert(
		WCOS_Decimal::from_units(WCOS_Decimal::to_units($fixture['source_quantity_after_split'], 6) + WCOS_Decimal::to_units($fixture['child_quantity_after_split'], 6), 6) === WCOS_Decimal::normalize($restored->get_quantity(), 6),
		'The genuine 1.4.11 quantity was not restored exactly.'
	);
	wcos_legacy_return_assert(
		WCOS_Decimal::from_units(WCOS_Decimal::to_units($fixture['source_subtotal_after_split'], 2) + WCOS_Decimal::to_units($fixture['child_subtotal_after_split'], 2), 2) === WCOS_Decimal::normalize($restored->get_subtotal(), 2)
		&& WCOS_Decimal::from_units(WCOS_Decimal::to_units($fixture['source_total_after_split'], 2) + WCOS_Decimal::to_units($fixture['child_total_after_split'], 2), 2) === WCOS_Decimal::normalize($restored->get_total(), 2),
		'The genuine 1.4.11 money was not restored from persisted historical values.'
	);
	wcos_legacy_return_assert('trash' === $child_after->get_status(), 'The genuine 1.4.11 child was not retired non-force.');
	wcos_legacy_return_assert($source_shipping === wcos_legacy_return_shipping_state($source_after), 'The genuine 1.4.11 source shipping changed.');
	wcos_legacy_return_assert($child_shipping === wcos_legacy_return_shipping_state($child_after), 'The genuine 1.4.11 child shipping history changed.');
	wcos_legacy_return_assert(array() === WCOS_Legacy_Return_Compatibility_Authority::legacy_child_ids($source_after, true), 'The genuine 1.4.11 relation was not retired exactly.');
	wcos_legacy_return_assert(empty($child_after->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true)), 'The genuine 1.4.11 child was rewritten as a hardened Split child.');
	wcos_legacy_return_assert($physical_before === wc_get_product(absint($fixture['moved_product_id']))->get_stock_quantity(), 'The genuine 1.4.11 Return changed physical stock.');

	$journal = WCOS_Operation_Journal::get($child_after, $confirm['operation_id']);
	wcos_legacy_return_assert(is_array($journal) && 'completed' === $journal['status'], 'The genuine 1.4.11 Return did not use the current durable journal.');
	wcos_legacy_return_assert(WCOS_Legacy_Return_Compatibility_Authority::LINEAGE_BASIS === $journal['context']['return_pair']['authority']['lineage_basis'], 'The genuine upgrade journal lost compatibility provenance.');
	return true;
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_legacy_return_assert(!empty($admins), 'Legacy Return smoke requires an administrator.');
wp_set_current_user(absint($admins[0]));
$controller = new WCOS_Return_Admin_Controller();
$suite_settings_before = wcos_legacy_return_capture_settings();
$fixtures = array();

try {
	$genuine = get_option('wcos_compat_003_genuine_1_4_11_fixture', array());
	if (!empty($genuine)) {
		$fixtures[] =& $genuine;
		wcos_legacy_return_verify_genuine_upgrade($genuine, $controller);
	}

	$partial = wcos_legacy_return_fixture('partial', false, true, true);
	$fixtures[] =& $partial;
	$before_child = wc_get_order($partial['child_id']);
	$before_source = wc_get_order($partial['source_id']);
	$source_shipping = wcos_legacy_return_shipping_state($before_source);
	$before_stock = WCOS_Order_Contract_Snapshot::product_stock($before_child);
	$preflight = WCOS_Return_Preflight::report($before_child, true);
	wcos_legacy_return_assert(!empty($preflight['supported']), 'Corroborated partial legacy child was not eligible: ' . $preflight['reason']);
	wcos_legacy_return_assert(WCOS_Legacy_Return_Compatibility_Authority::LINEAGE_BASIS === $preflight['lineage_authority']['lineage_basis'], 'Legacy lineage basis was not explicit.');
	wcos_legacy_return_assert(WCOS_Return_Plan::COMPATIBILITY_SCHEMA_VERSION === $preflight['return_plan']['schema_version'], 'Legacy Return plan did not use its compatibility schema.');
	wcos_legacy_return_assert(WCOS_Return_Plan::DESTINATION_RESIDUAL_SOURCE_ITEM === reset($preflight['return_plan']['lines'])['destination'], 'Legacy partial line did not bind its unique residual destination.');
	$preflight_json = wp_json_encode($preflight);
	wcos_legacy_return_assert(false === strpos($preflight_json, 'Legacy private display'), 'Legacy compatibility authority persisted raw shipping metadata.');
	list($review, $confirm, $result) = wcos_legacy_return_execute($partial, $controller);
	wcos_legacy_return_assert('completed' === $result['status'], 'Legacy partial Return did not complete.');
	wcos_legacy_return_assert(!empty($review['summary']['compatibility']['legacy_1_4_11_detected']), 'Legacy Review summary did not disclose compatibility lineage.');
	$source_after = wc_get_order($partial['source_id']);
	$child_after = wc_get_order($partial['child_id']);
	$residual = $source_after->get_item($partial['residual_item_id']);
	wcos_legacy_return_assert($residual instanceof WC_Order_Item_Product && '3.000000' === WCOS_Decimal::normalize($residual->get_quantity(), 6), 'Legacy partial quantity was not restored exactly.');
	wcos_legacy_return_assert('30.00' === WCOS_Decimal::normalize($residual->get_subtotal(), 2) && '27.00' === WCOS_Decimal::normalize($residual->get_total(), 2), 'Legacy partial historical money was not restored exactly.');
	wcos_legacy_return_assert('trash' === $child_after->get_status(), 'Legacy child was not retired through non-force Trash.');
	wcos_legacy_return_assert((string) $partial['source_id'] === (string) $child_after->get_meta('yoos_original_order', true), 'Legacy child historical parent evidence was erased.');
	wcos_legacy_return_assert(array() === WCOS_Legacy_Return_Compatibility_Authority::legacy_child_ids($source_after, true), 'Returned legacy relation was not retired exactly.');
	wcos_legacy_return_assert(empty($child_after->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true)), 'Legacy Return forged hardened parent metadata.');
	wcos_legacy_return_assert(WCOS_Order_Contract_Snapshot::product_stock($child_after) === $before_stock, 'Legacy Return changed physical product stock.');
	$source_shipping_after = wcos_legacy_return_shipping_state($source_after);
	wcos_legacy_return_assert($source_shipping === $source_shipping_after, 'Legacy Return changed source shipping.');
	wcos_legacy_return_assert(!empty($child_after->get_items('shipping')), 'Legacy child shipping history was removed.');
	$journal = WCOS_Operation_Journal::get($child_after, $confirm['operation_id']);
	wcos_legacy_return_assert(is_array($journal) && 'completed' === $journal['status'], 'Legacy Return did not complete one durable journal.');
	wcos_legacy_return_assert(WCOS_Legacy_Return_Compatibility_Authority::LINEAGE_BASIS === $journal['context']['return_pair']['authority']['lineage_basis'], 'Legacy journal lost compatibility basis.');
	wcos_legacy_return_assert(false === strpos(wp_json_encode($journal), 'Legacy private display'), 'Legacy Return journal contains raw shipping metadata.');
	$replay = $controller->execute_request(array(
		'child_order_id' => $partial['child_id'],
		'nonce' => wp_create_nonce('wcos_return_order_' . $partial['child_id']),
		'operation_id' => $confirm['operation_id'],
		'confirmation_token' => $confirm['confirmation_token'],
	));
	wcos_legacy_return_assert($result === $replay, 'Legacy Return response-loss replay changed its terminal result.');

	$whole = wcos_legacy_return_fixture('whole', true, false, false);
	$fixtures[] =& $whole;
	$whole_report = WCOS_Return_Preflight::report(wc_get_order($whole['child_id']), true);
	wcos_legacy_return_assert(!empty($whole_report['supported']) && WCOS_Return_Plan::DESTINATION_FRESH_SOURCE_ITEM === reset($whole_report['return_plan']['lines'])['destination'], 'Legacy whole-line movement did not bind a fresh destination.');
	list($whole_review, $whole_confirm, $whole_result) = wcos_legacy_return_execute($whole, $controller);
	$whole_source = wc_get_order($whole['source_id']);
	$restored = array_values(array_filter($whole_source->get_items('line_item'), static function($item) use ($whole) { return (int) $item->get_product_id() === (int) $whole['product_ids'][0]; }));
	wcos_legacy_return_assert('completed' === $whole_result['status'] && 1 === count($restored), 'Legacy whole-line Return did not create exactly one fresh source item.');
	wcos_legacy_return_assert((int) $restored[0]->get_id() !== (int) $whole['child_item_id'], 'Legacy whole-line Return re-parented a persisted child item.');
	wcos_legacy_return_assert('whole' === $restored[0]->get_meta('legacy_configuration', true), 'Legacy whole-line Return lost approved business metadata.');

	$one_sided = wcos_legacy_return_fixture('one-sided');
	$fixtures[] =& $one_sided;
	$one_source = wc_get_order($one_sided['source_id']);
	$one_source->update_meta_data('yoos_splitted_order', '');
	$one_source->save_meta_data();
	$one_report = WCOS_Return_Preflight::report(wc_get_order($one_sided['child_id']), true);
	wcos_legacy_return_assert(empty($one_report['supported']) && 'legacy_reciprocal_missing' === $one_report['reason'], 'One-sided legacy relation did not fail closed.');

	$conflict = wcos_legacy_return_fixture('conflict');
	$fixtures[] =& $conflict;
	$conflict_child = wc_get_order($conflict['child_id']);
	$conflict_child->update_meta_data(WCOS_Split_Order_Service::OPERATION_META, 'partial-hardened-authority');
	$conflict_child->save_meta_data();
	$conflict_report = WCOS_Return_Preflight::report(wc_get_order($conflict['child_id']), true);
	wcos_legacy_return_assert(empty($conflict_report['supported']) && 'hardened_lineage_partial' === $conflict_report['reason'], 'Partial hardened lineage fell back to legacy compatibility.');

	$parent_missing = wcos_legacy_return_fixture('parent-missing');
	$fixtures[] =& $parent_missing;
	$parent_missing_child = wc_get_order($parent_missing['child_id']);
	$parent_missing_child->delete_meta_data('yoos_original_order');
	$parent_missing_child->save_meta_data();
	wcos_legacy_return_assert('lineage_missing' === wcos_legacy_return_reason(wc_get_order($parent_missing['child_id'])), 'A source-only reciprocal relation became executable without child parent evidence.');

	$malformed_parent = wcos_legacy_return_fixture('malformed-parent');
	$fixtures[] =& $malformed_parent;
	$malformed_child = wc_get_order($malformed_parent['child_id']);
	$malformed_child->update_meta_data('yoos_original_order', '0');
	$malformed_child->save_meta_data();
	wcos_legacy_return_assert('malformed_legacy_parent_id' === wcos_legacy_return_reason(wc_get_order($malformed_parent['child_id'])), 'A non-positive legacy parent ID did not fail closed.');
	$malformed_child->update_meta_data('yoos_original_order', (string) $malformed_parent['child_id']);
	$malformed_child->save_meta_data();
	wcos_legacy_return_assert('same_participant' === wcos_legacy_return_reason(wc_get_order($malformed_parent['child_id'])), 'A self-parent legacy relation did not fail closed.');
	$malformed_child->update_meta_data('yoos_original_order', '2147483647');
	$malformed_child->save_meta_data();
	wcos_legacy_return_assert('source_missing' === wcos_legacy_return_reason(wc_get_order($malformed_parent['child_id'])), 'A missing legacy source did not fail closed.');

	$structured_conflict = wcos_legacy_return_fixture('structured-conflict');
	$fixtures[] =& $structured_conflict;
	$structured_source = wc_get_order($structured_conflict['source_id']);
	$structured_source->update_meta_data(WCOS_Split_Order_Service::RELATION_CHILDREN_META, array($structured_conflict['child_id']));
	$structured_source->save_meta_data();
	wcos_legacy_return_assert('hardened_lineage_partial' === wcos_legacy_return_reason(wc_get_order($structured_conflict['child_id'])), 'Conflicting structured source evidence did not block legacy fallback.');

	$ambiguous = wcos_legacy_return_fixture('ambiguous');
	$fixtures[] =& $ambiguous;
	$ambiguous_source = wc_get_order($ambiguous['source_id']);
	wcos_legacy_return_line($ambiguous_source, wc_get_product($ambiguous['product_ids'][0]), '1.000000', '10.00', '9.00', 'ambiguous');
	wcos_legacy_return_rebuild($ambiguous_source);
	wcos_legacy_return_assert('legacy_destination_ambiguous' === wcos_legacy_return_reason(wc_get_order($ambiguous['child_id'])), 'Multiple compatible residual lines did not fail closed.');

	$catalog = wcos_legacy_return_fixture('catalog-independent');
	$fixtures[] =& $catalog;
	$catalog_accesses = 0;
	$catalog_observer = static function($product) use (&$catalog_accesses) { $catalog_accesses++; return $product; };
	add_filter('woocommerce_order_item_product', $catalog_observer, 10, 1);
	$catalog_authority = WCOS_Legacy_Return_Compatibility_Authority::resolve(wc_get_order($catalog['child_id']));
	remove_filter('woocommerce_order_item_product', $catalog_observer, 10);
	wcos_legacy_return_assert(0 === $catalog_accesses && WCOS_Legacy_Return_Compatibility_Authority::is_authority($catalog_authority), 'Legacy authority consulted the current product catalog.');

	$charges = wcos_legacy_return_fixture('charges');
	$fixtures[] =& $charges;
	$charges_child = wc_get_order($charges['child_id']);
	$fee = new WC_Order_Item_Fee();
	$fee->set_name('Unsupported legacy child fee');
	$fee->set_amount('1.00');
	$fee->set_total('1.00');
	$fee->set_taxes(array('total' => array()));
	$charges_child->add_item($fee);
	$coupon = new WC_Order_Item_Coupon();
	$coupon->set_code('unsupported-legacy-coupon');
	$coupon->set_discount('1.00');
	$coupon->set_discount_tax('0.00');
	$charges_child->add_item($coupon);
	wcos_legacy_return_rebuild($charges_child);
	wcos_legacy_return_assert('child_charge_ownership' === wcos_legacy_return_reason(wc_get_order($charges['child_id'])), 'Legacy child fee/coupon ownership did not fail closed.');

	$transaction = wcos_legacy_return_fixture('transaction');
	$fixtures[] =& $transaction;
	$transaction_child = wc_get_order($transaction['child_id']);
	$transaction_child->set_transaction_id('legacy-independent-transaction');
	$transaction_child->save();
	wcos_legacy_return_assert('child_payment_ownership' === wcos_legacy_return_reason(wc_get_order($transaction['child_id'])), 'Legacy child transaction ownership did not fail closed.');

	$paid_date = wcos_legacy_return_fixture('paid-date');
	$fixtures[] =& $paid_date;
	$paid_child = wc_get_order($paid_date['child_id']);
	$paid_child->set_date_paid(time());
	$paid_child->save();
	wcos_legacy_return_assert('child_payment_ownership' === wcos_legacy_return_reason(wc_get_order($paid_date['child_id'])), 'Legacy child paid-date ownership did not fail closed.');

	$refund = wcos_legacy_return_fixture('refund');
	$fixtures[] =& $refund;
	$created_refund = wc_create_refund(array(
		'order_id' => $refund['child_id'],
		'amount' => '1.00',
		'reason' => 'Compatibility ownership fixture',
		'refund_payment' => false,
	));
	wcos_legacy_return_assert($created_refund instanceof WC_Order_Refund, 'Legacy refund fixture could not be created.');
	wcos_legacy_return_assert('refund_ownership_unsupported' === wcos_legacy_return_reason(wc_get_order($refund['child_id'])), 'Legacy child refund ownership did not fail closed.');

	$stock = wcos_legacy_return_fixture('stock');
	$fixtures[] =& $stock;
	$stock_source = wc_get_order($stock['source_id']);
	$stock_child = wc_get_order($stock['child_id']);
	$stock_source_line = $stock_source->get_item($stock['residual_item_id']);
	$stock_child_line = $stock_child->get_item($stock['child_item_id']);
	$stock_source_line->add_meta_data('_reduced_stock', '1.000000', true);
	$stock_source_line->save();
	$stock_child_line->add_meta_data('_reduced_stock', '2.000000', true);
	$stock_child_line->save();
	$stock_source->get_data_store()->set_stock_reduced($stock_source->get_id(), true);
	$stock_child->get_data_store()->set_stock_reduced($stock_child->get_id(), true);
	list($stock_review, $stock_confirm, $stock_result) = wcos_legacy_return_execute($stock, $controller);
	$stock_source = wc_get_order($stock['source_id']);
	$stock_child = wc_get_order($stock['child_id']);
	wcos_legacy_return_assert('completed' === $stock_result['status'] && '3.000000' === WCOS_Decimal::normalize($stock_source->get_item($stock['residual_item_id'])->get_meta('_reduced_stock', true), 6), 'Legacy Return did not conserve `_reduced_stock` exactly once.');
	wcos_legacy_return_assert((bool) $stock_source->get_data_store()->get_stock_reduced($stock_source->get_id()) && !(bool) $stock_child->get_data_store()->get_stock_reduced($stock_child->get_id()), 'Legacy Return order-level stock flags do not match line ownership.');

	$bad_stock = wcos_legacy_return_fixture('bad-stock');
	$fixtures[] =& $bad_stock;
	$bad_stock_child = wc_get_order($bad_stock['child_id']);
	$bad_stock_line = $bad_stock_child->get_item($bad_stock['child_item_id']);
	$bad_stock_line->add_meta_data('_reduced_stock', '3.000000', true);
	$bad_stock_line->save();
	$bad_stock_child->get_data_store()->set_stock_reduced($bad_stock_child->get_id(), true);
	wcos_legacy_return_assert('child_stock_state_inconsistent' === wcos_legacy_return_reason(wc_get_order($bad_stock['child_id'])), 'Over-quantity legacy stock ownership did not fail closed.');

	$taxed_shipping = wcos_legacy_return_fixture('taxed-shipping', false, true, false);
	$fixtures[] =& $taxed_shipping;
	$taxed_child = wc_get_order($taxed_shipping['child_id']);
	$rate_id = 811;
	$extra_shipping = new WC_Order_Item_Shipping();
	$extra_shipping->set_props(array(
		'method_title' => 'Legacy taxed pickup',
		'method_id' => 'local_pickup',
		'instance_id' => 12,
		'total' => '3.00',
		'taxes' => array('total' => array($rate_id => '0.30')),
	));
	$extra_shipping->add_meta_data('Items', 'Private immutable taxed history', true);
	$taxed_child->add_item($extra_shipping);
	$tax = new WC_Order_Item_Tax();
	$tax->set_props(array('rate_id' => $rate_id, 'label' => 'Legacy shipping tax', 'tax_total' => '0.00', 'shipping_tax_total' => '0.30', 'compound' => false, 'rate_percent' => 10));
	$taxed_child->add_item($tax);
	$taxed_child = wcos_legacy_return_rebuild($taxed_child);
	$taxed_shipping_before = wcos_legacy_return_shipping_state($taxed_child);
	list($taxed_review, $taxed_confirm, $taxed_result) = wcos_legacy_return_execute($taxed_shipping, $controller);
	wcos_legacy_return_assert('completed' === $taxed_result['status'] && 2 === count($taxed_shipping_before), 'Multiple/taxed legacy child shipping did not complete.');
	wcos_legacy_return_assert($taxed_shipping_before === wcos_legacy_return_shipping_state(wc_get_order($taxed_shipping['child_id'])), 'Multiple/taxed legacy child shipping changed during Return.');

	$descendant = wcos_legacy_return_fixture('descendant');
	$fixtures[] =& $descendant;
	$descendant_child = wc_get_order($descendant['child_id']);
	$descendant_child->update_meta_data('yoos_splitted_order', '999999');
	$descendant_child->save_meta_data();
	wcos_legacy_return_assert('nested_or_parent_child' === wcos_legacy_return_reason(wc_get_order($descendant['child_id'])), 'Legacy child active-descendant safety was weakened.');

	$siblings_a = wcos_legacy_return_fixture('siblings-a');
	$fixtures[] =& $siblings_a;
	$sibling_a_id = wcos_legacy_return_add_sibling($siblings_a, 'siblings-a-secondary');
	wcos_legacy_return_assert('supported' === wcos_legacy_return_reason(wc_get_order($sibling_a_id)), 'Second legacy sibling was not initially eligible.');
	wcos_legacy_return_execute($siblings_a, $controller);
	$siblings_a_source = wc_get_order($siblings_a['source_id']);
	wcos_legacy_return_assert(array($sibling_a_id) === WCOS_Legacy_Return_Compatibility_Authority::legacy_child_ids($siblings_a_source, true), 'Returning legacy sibling A changed sibling B relation.');
	wcos_legacy_return_assert('supported' === wcos_legacy_return_reason(wc_get_order($sibling_a_id)), 'Returning legacy sibling A invalidated sibling B.');

	$siblings_b = wcos_legacy_return_fixture('siblings-b');
	$fixtures[] =& $siblings_b;
	$sibling_b_primary = $siblings_b['child_id'];
	$sibling_b_secondary = wcos_legacy_return_add_sibling($siblings_b, 'siblings-b-secondary');
	$siblings_b['child_id'] = $sibling_b_secondary;
	$siblings_b['extra_order_ids'] = array($sibling_b_primary);
	wcos_legacy_return_execute($siblings_b, $controller);
	$siblings_b_source = wc_get_order($siblings_b['source_id']);
	wcos_legacy_return_assert(array($sibling_b_primary) === WCOS_Legacy_Return_Compatibility_Authority::legacy_child_ids($siblings_b_source, true), 'Returning legacy sibling B changed sibling A relation.');
	wcos_legacy_return_assert('supported' === wcos_legacy_return_reason(wc_get_order($sibling_b_primary)), 'Returning legacy sibling B invalidated sibling A.');

	$mixed_legacy_first = wcos_legacy_return_fixture('mixed-legacy-first');
	$fixtures[] =& $mixed_legacy_first;
	$mixed_hardened_id = wcos_legacy_return_add_hardened_sibling($mixed_legacy_first, 'mixed-current-after-legacy');
	$mixed_hardened_before = WCOS_Return_Lineage_Authority::resolve(wc_get_order($mixed_hardened_id));
	wcos_legacy_return_assert(WCOS_Return_Lineage_Authority::SCHEMA_VERSION === (int) $mixed_hardened_before['schema_version'], 'Valid hardened sibling fell back to compatibility authority.');
	wcos_legacy_return_execute($mixed_legacy_first, $controller);
	wcos_legacy_return_assert('supported' === wcos_legacy_return_reason(wc_get_order($mixed_hardened_id)), 'Returning legacy first invalidated the current hardened sibling.');

	$mixed_hardened_first = wcos_legacy_return_fixture('mixed-hardened-first');
	$fixtures[] =& $mixed_hardened_first;
	$mixed_legacy_id = $mixed_hardened_first['child_id'];
	$mixed_hardened_second_id = wcos_legacy_return_add_hardened_sibling($mixed_hardened_first, 'mixed-current-first');
	$mixed_hardened_first['child_id'] = $mixed_hardened_second_id;
	$mixed_hardened_first['extra_order_ids'] = array($mixed_legacy_id);
	wcos_legacy_return_execute($mixed_hardened_first, $controller);
	wcos_legacy_return_assert('supported' === wcos_legacy_return_reason(wc_get_order($mixed_legacy_id)), 'Returning current hardened first invalidated the remaining legacy sibling.');

	$relation_drift = wcos_legacy_return_fixture('relation-drift');
	$fixtures[] =& $relation_drift;
	wcos_legacy_return_expect_confirm_drift($controller, $relation_drift, static function() use ($relation_drift) {
		$source = wc_get_order($relation_drift['source_id']);
		$source->update_meta_data('yoos_splitted_order', $relation_drift['child_id'] . ',999999');
		$source->save_meta_data();
	}, 'legacy relation');

	$source_drift = wcos_legacy_return_fixture('source-drift');
	$fixtures[] =& $source_drift;
	wcos_legacy_return_expect_confirm_drift($controller, $source_drift, static function() use ($source_drift) {
		$source = wc_get_order($source_drift['source_id']);
		$item = $source->get_item($source_drift['keep_item_id']);
		$item->set_quantity('2.000000');
		$item->set_subtotal('4.00');
		$item->set_total('4.00');
		$item->save();
		wcos_legacy_return_rebuild($source);
	}, 'source commercial');

	$child_drift = wcos_legacy_return_fixture('child-drift');
	$fixtures[] =& $child_drift;
	wcos_legacy_return_expect_confirm_drift($controller, $child_drift, static function() use ($child_drift) {
		$child = wc_get_order($child_drift['child_id']);
		$item = $child->get_item($child_drift['child_item_id']);
		$item->set_quantity('1.000000');
		$item->set_subtotal('10.00');
		$item->set_total('9.00');
		$item->save();
		wcos_legacy_return_rebuild($child);
	}, 'child commercial');

	echo "compat-legacy-return-upgrade-ok\n";
} finally {
	wcos_legacy_return_restore_settings($suite_settings_before);
	foreach ($fixtures as $fixture) { wcos_legacy_return_cleanup($fixture); }
}
