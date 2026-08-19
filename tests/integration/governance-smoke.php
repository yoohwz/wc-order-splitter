<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_governance_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_governance_expect_runtime(callable $callback, $message) {
	try {
		$callback();
	} catch (RuntimeException $exception) {
		return $exception;
	}
	throw new RuntimeException($message);
}

/* External configuration must never alter the internally approved gate set. */
foreach (array(
	'WC_ORDER_SPLITTER_MUTATIONS_ENABLED',
	'WC_ORDER_SPLITTER_SPLIT_ENABLED',
	'WC_ORDER_SPLITTER_DUPLICATE_ENABLED',
) as $constant) {
	if (!defined($constant)) {
		define($constant, true);
	}
}
wcos_governance_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT), 'Approved manual quantity Split gate is not enabled.');
wcos_governance_assert(WCOS_Feature_Gates::any_enabled(), 'Approved production workflow set was reported as entirely disabled.');
wcos_governance_assert(WC_Order_Splitter_Safety_Guard::mutations_enabled(), 'Safety guard did not reflect the approved production gate set.');
foreach (array(
	WCOS_Feature_Gates::DUPLICATE,
	WCOS_Feature_Gates::MERGE,
	WCOS_Feature_Gates::RETURN_ORDER,
	WCOS_Feature_Gates::BULK_RETURN,
) as $disabled_workflow) {
	wcos_governance_assert(!WCOS_Feature_Gates::enabled($disabled_workflow), 'An unapproved mutation workflow became production-enabled.');
}

$order = wc_create_order();
$order->set_status('pending');
$order->save();

$admin_id = wp_insert_user(array(
	'user_login' => 'wcos_admin_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-admin-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
$manager_id = wp_insert_user(array(
	'user_login' => 'wcos_manager_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-manager-' . wp_generate_uuid4() . '@example.test',
	'role' => 'shop_manager',
));
$subscriber_id = wp_insert_user(array(
	'user_login' => 'wcos_subscriber_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-subscriber-' . wp_generate_uuid4() . '@example.test',
	'role' => 'subscriber',
));
wcos_governance_assert(!is_wp_error($admin_id) && !is_wp_error($manager_id) && !is_wp_error($subscriber_id), 'Unable to create governance test users.');

update_option('order_splitter_shop_manager_permission', 'no');
wp_set_current_user($admin_id);
WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $order);

wp_set_current_user($manager_id);
wcos_governance_expect_runtime(
	static function() use ($order) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $order);
	},
	'Shop manager policy did not fail closed.'
);
update_option('order_splitter_shop_manager_permission', 'yes');
WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $order);

wp_set_current_user($subscriber_id);
wcos_governance_expect_runtime(
	static function() use ($order) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::DUPLICATE, $order);
	},
	'Insufficient order capability was accepted.'
);

wp_set_current_user($admin_id);
wcos_governance_expect_runtime(
	static function() use ($order) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $order, $order);
	},
	'Self-merge authorization was accepted.'
);
$gateway = new WCOS_Mutation_Gateway();
wcos_governance_expect_runtime(
	static function() use ($gateway, $order) {
		$gateway->duplicate($order, 'governance-disabled-' . wp_generate_uuid4());
	},
	'The mandatory gateway did not keep Duplicate hard-off.'
);

/* Public business metadata is copied; private metadata needs an explicit adapter. */
$source_item = new WC_Order_Item_Product();
$source_item->add_meta_data('engraving', 'Ada', true);
$source_item->add_meta_data('_vendor_configuration', 'gold', true);
$source_item->add_meta_data('_reduced_stock', '2', true);
$source_item->add_meta_data('_wcos_internal_state', 'forbidden', true);

$classification_filter = static function($classification, $key) {
	if ('_vendor_configuration' === $key || '_reduced_stock' === $key || '_wcos_internal_state' === $key) {
		return WCOS_Order_Item_Meta_Policy::CLASS_BUSINESS;
	}
	return $classification;
};
$operational_filter = static function($is_operational) {
	return false;
};
add_filter('wcos_order_item_meta_classification', $classification_filter, 10, 2);
add_filter('wcos_order_item_meta_is_operational', $operational_filter, 10, 1);

$target_item = new WC_Order_Item_Product();
WCOS_Order_Item_Meta_Policy::copy(
	$source_item,
	$target_item,
	WCOS_Order_Item_Meta_Policy::CONTEXT_SPLIT
);
wcos_governance_assert('Ada' === $target_item->get_meta('engraving', true), 'Public business metadata was not copied.');
wcos_governance_assert('gold' === $target_item->get_meta('_vendor_configuration', true), 'Explicit integration metadata adapter was not honored.');
wcos_governance_assert('' === $target_item->get_meta('_reduced_stock', true), 'Protected reduced-stock metadata was re-enabled by a filter.');
wcos_governance_assert('' === $target_item->get_meta('_wcos_internal_state', true), 'Protected mutation metadata was re-enabled by a filter.');

$identity_metadata = WCOS_Order_Item_Meta_Policy::business_metadata($source_item);
wcos_governance_assert(isset($identity_metadata['engraving']), 'Public business metadata is missing from line identity input.');
wcos_governance_assert(isset($identity_metadata['_vendor_configuration']), 'Opted-in integration metadata is missing from line identity input.');
wcos_governance_assert(!isset($identity_metadata['_reduced_stock']), 'Reduced-stock metadata entered commercial line identity.');
wcos_governance_assert(!isset($identity_metadata['_wcos_internal_state']), 'Mutation metadata entered commercial line identity.');

remove_filter('wcos_order_item_meta_classification', $classification_filter, 10);
remove_filter('wcos_order_item_meta_is_operational', $operational_filter, 10);

/* Non-canonical object/resource business metadata must fail closed. */
$complex_item = new WC_Order_Item_Product();
$complex_item->add_meta_data('complex_configuration', (object) array('tier' => 'gold'), true);
wcos_governance_expect_runtime(
	static function() use ($complex_item) {
		WCOS_Order_Item_Meta_Policy::business_metadata($complex_item);
	},
	'Object-valued business metadata was accepted into line identity.'
);

$complex_operational = static function($classification, $key) {
	return 'complex_configuration' === $key
		? WCOS_Order_Item_Meta_Policy::CLASS_OPERATIONAL
		: $classification;
};
add_filter('wcos_order_item_meta_classification', $complex_operational, 10, 2);
$complex_identity = WCOS_Order_Item_Meta_Policy::business_metadata($complex_item);
wcos_governance_assert(!isset($complex_identity['complex_configuration']), 'Explicit operational classification did not exclude non-canonical metadata.');
remove_filter('wcos_order_item_meta_classification', $complex_operational, 10);

$order->delete(true);
if (!function_exists('wp_delete_user')) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
wp_delete_user($admin_id);
wp_delete_user($manager_id);
wp_delete_user($subscriber_id);
wp_set_current_user(0);

echo "mutation-governance-and-metadata-policy-ok\n";
