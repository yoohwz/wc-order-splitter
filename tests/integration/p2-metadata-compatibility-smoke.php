<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_p2_meta_line(WC_Order $order) {
	$items = $order->get_items('line_item');
	$item = reset($items);
	if (!$item instanceof WC_Order_Item_Product) {
		throw new RuntimeException('P2 metadata fixture lost its line item.');
	}
	return $item;
}

$adapter = new WCOS_Split_WooCommerce_Adapter();

/* Unknown private metadata must fail closed before any mutation record exists. */
$business_product = wcos_p2_adapter_product('WCOS P2 private business adapter', '9.00');
list($business_source, $business_item_id) = wcos_p2_adapter_order($business_product, 3);
$business_line = $business_source->get_item($business_item_id);
$business_line->add_meta_data('engraving', 'YES', true);
$business_line->add_meta_data('_third_party_bundle_config', array('bundle' => 17, 'slot' => 'a'), true);
$business_line->add_meta_data('_wcos_probe_internal', 'must-not-copy', true);
$business_line->save();
$business_source = wc_get_order($business_source->get_id());
$unknown_report = $adapter->preflight($business_source);
wcos_p2_adapter_assert(empty($unknown_report['supported']), 'Unknown private business metadata was silently accepted.');
wcos_p2_adapter_assert('unclassified_private_metadata' === $unknown_report['reason'], 'Unknown private metadata used the wrong preflight rejection reason.');
wcos_p2_adapter_assert(array('_third_party_bundle_config') === $unknown_report['unknown_private_meta_keys'], 'Protected mutation metadata leaked into the unknown-private compatibility report.');
$unknown_operation = 'p2-unknown-private-' . wp_generate_uuid4();
$unknown_rejected = false;
try {
	$adapter->split($business_source, array('child-one' => array($business_item_id => '1.000000')), $unknown_operation);
} catch (WCOS_Split_Preflight_Exception $exception) {
	$unknown_rejected = 'unclassified_private_metadata' === $exception->get_reason();
}
wcos_p2_adapter_assert($unknown_rejected, 'Unknown private metadata did not block Split before persistence.');
wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($business_source->get_id()), $unknown_operation), 'Unknown private metadata rejection created a journal.');

/* A third-party adapter may explicitly classify immutable private configuration as business. */
$business_adapter = static function($classification, $key) {
	return '_third_party_bundle_config' === $key ? WCOS_Order_Item_Meta_Policy::CLASS_BUSINESS : $classification;
};
add_filter('wcos_order_item_meta_classification', $business_adapter, 10, 6);
$adapted_report = $adapter->preflight(wc_get_order($business_source->get_id()));
wcos_p2_adapter_assert(!empty($adapted_report['supported']), 'Explicit private-business metadata adapter did not satisfy preflight.');
wcos_p2_adapter_assert(empty($adapted_report['unknown_private_meta_keys']), 'Explicit private-business key remained unclassified.');
$business_operation = 'p2-private-business-' . wp_generate_uuid4();
$business_children = $adapter->split(
	wc_get_order($business_source->get_id()),
	array('child-one' => array($business_item_id => '1.000000')),
	$business_operation
);
$business_child_line = wcos_p2_meta_line($business_children[0]);
wcos_p2_adapter_assert('YES' === $business_child_line->get_meta('engraving', true), 'Public business metadata was not copied to the child.');
wcos_p2_adapter_assert(
	array('bundle' => 17, 'slot' => 'a') === $business_child_line->get_meta('_third_party_bundle_config', true),
	'Explicit private business configuration was not copied exactly.'
);
wcos_p2_adapter_assert('' === $business_child_line->get_meta('_wcos_probe_internal', true), 'Protected mutation metadata was copied to the child.');
remove_filter('wcos_order_item_meta_classification', $business_adapter, 10);
wcos_p2_adapter_cleanup($business_source->get_id(), $business_operation);
wp_delete_post($business_product->get_id(), true);

/* A third-party adapter may instead declare private cache/state as known operational. */
$operational_product = wcos_p2_adapter_product('WCOS P2 private operational adapter', '7.00');
list($operational_source, $operational_item_id) = wcos_p2_adapter_order($operational_product, 3);
$operational_line = $operational_source->get_item($operational_item_id);
$operational_line->add_meta_data('_third_party_render_cache', 'opaque-cache', true);
$operational_line->save();
$operational_source = wc_get_order($operational_source->get_id());
$operational_unknown = $adapter->preflight($operational_source);
wcos_p2_adapter_assert('unclassified_private_metadata' === $operational_unknown['reason'], 'Unknown operational private metadata did not fail closed initially.');
$operational_adapter = static function($known, $key) {
	return '_third_party_render_cache' === $key ? true : $known;
};
add_filter('wcos_order_item_private_meta_is_known_operational', $operational_adapter, 10, 6);
$operational_report = $adapter->preflight(wc_get_order($operational_source->get_id()));
wcos_p2_adapter_assert(!empty($operational_report['supported']), 'Known-operational metadata adapter did not satisfy preflight.');
$operational_operation = 'p2-private-operational-' . wp_generate_uuid4();
$operational_children = $adapter->split(
	wc_get_order($operational_source->get_id()),
	array('child-one' => array($operational_item_id => '1.000000')),
	$operational_operation
);
$operational_child_line = wcos_p2_meta_line($operational_children[0]);
wcos_p2_adapter_assert('' === $operational_child_line->get_meta('_third_party_render_cache', true), 'Known operational cache metadata was copied to the child.');
remove_filter('wcos_order_item_private_meta_is_known_operational', $operational_adapter, 10);
wcos_p2_adapter_cleanup($operational_source->get_id(), $operational_operation);
wp_delete_post($operational_product->get_id(), true);

/* Protected keys remain operational even if a hostile/buggy adapter tries to promote them. */
$protected_product = wcos_p2_adapter_product('WCOS P2 protected metadata', '5.00');
list($protected_source, $protected_item_id) = wcos_p2_adapter_order($protected_product, 3);
$protected_line = $protected_source->get_item($protected_item_id);
$protected_line->add_meta_data('_wcos_protected_probe', 'secret-internal', true);
$protected_line->save();
$promote_all = static function($classification) {
	return WCOS_Order_Item_Meta_Policy::CLASS_BUSINESS;
};
add_filter('wcos_order_item_meta_classification', $promote_all, PHP_INT_MAX, 6);
wcos_p2_adapter_assert(
	WCOS_Order_Item_Meta_Policy::CLASS_OPERATIONAL === WCOS_Order_Item_Meta_Policy::classify('_wcos_protected_probe', 'secret-internal', WCOS_Order_Item_Meta_Policy::CONTEXT_SPLIT, wcos_p2_meta_line(wc_get_order($protected_source->get_id()))),
	'Protected metadata was promoted to business by an adapter filter.'
);
$protected_report = $adapter->preflight(wc_get_order($protected_source->get_id()));
wcos_p2_adapter_assert(!empty($protected_report['supported']), 'Protected mutation metadata incorrectly required a third-party adapter.');
$protected_operation = 'p2-protected-meta-' . wp_generate_uuid4();
$protected_children = $adapter->split(
	wc_get_order($protected_source->get_id()),
	array('child-one' => array($protected_item_id => '1.000000')),
	$protected_operation
);
wcos_p2_adapter_assert('' === wcos_p2_meta_line($protected_children[0])->get_meta('_wcos_protected_probe', true), 'Protected metadata entered a split child after forced promotion.');
remove_filter('wcos_order_item_meta_classification', $promote_all, PHP_INT_MAX);
wcos_p2_adapter_cleanup($protected_source->get_id(), $protected_operation);
wp_delete_post($protected_product->get_id(), true);

echo "p2-metadata-compatibility-policy-ok\n";
