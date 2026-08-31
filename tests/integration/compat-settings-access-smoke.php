<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_compat_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_compat_expect_runtime(callable $callback, $message) {
	try {
		$callback();
	} catch (RuntimeException $exception) {
		return;
	}
	throw new RuntimeException($message);
}

function wcos_compat_setting_ids(array $settings) {
	$ids = array();
	foreach ($settings as $setting) {
		if (is_array($setting) && isset($setting['id'])) {
			$ids[] = (string) $setting['id'];
		}
	}
	return $ids;
}

$previous_user = get_current_user_id();
$legacy_option_exists = false !== get_option('order_splitter_shop_manager_permission', false);
$legacy_option_value = get_option('order_splitter_shop_manager_permission', null);
$user_ids = array();
$order_ids = array();
$orders = array();
$admin_id = 0;
$manager_id = 0;
$custom_role = 'wcos_compat_capable_operator';

try {
	$admin_id = wp_insert_user(array(
		'user_login' => 'wcos_compat_admin_' . wp_generate_password(8, false),
		'user_pass' => wp_generate_password(24, true),
		'user_email' => 'wcos-compat-admin-' . wp_generate_uuid4() . '@example.test',
		'role' => 'administrator',
	));
	$manager_id = wp_insert_user(array(
		'user_login' => 'wcos_compat_manager_' . wp_generate_password(8, false),
		'user_pass' => wp_generate_password(24, true),
		'user_email' => 'wcos-compat-manager-' . wp_generate_uuid4() . '@example.test',
		'role' => 'shop_manager',
	));
	wcos_compat_assert(!is_wp_error($admin_id) && !is_wp_error($manager_id), 'Unable to create supported operator fixtures.');
	$user_ids[] = (int) $admin_id;
	$user_ids[] = (int) $manager_id;

	wp_set_current_user((int) $admin_id);
	foreach (array('source', 'target', 'child', 'original') as $label) {
		$order = wc_create_order(array('status' => 'pending'));
		$order->set_customer_note('WOS-COMPAT-001 ' . $label);
		$order->save();
		$orders[$label] = $order;
		$order_ids[] = $order->get_id();
	}

	foreach (array('absent', 'no', 'yes') as $legacy_state) {
		if ('absent' === $legacy_state) {
			delete_option('order_splitter_shop_manager_permission');
		} else {
			update_option('order_splitter_shop_manager_permission', $legacy_state);
		}

		foreach (array((int) $admin_id, (int) $manager_id) as $operator_id) {
			wp_set_current_user($operator_id);
			WCOS_Order_Mutation_Authorizer::assert_operator();
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $orders['source']);
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::DUPLICATE, $orders['source']);
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $orders['source'], $orders['target']);
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::RETURN_ORDER, $orders['child'], $orders['original']);
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::BULK_RETURN, $orders['child'], $orders['original']);

			$bulk_actions = (new WCOS_Bulk_Return_Admin_Controller())->register_bulk_action(array());
			wcos_compat_assert(isset($bulk_actions[WCOS_Bulk_Return_Admin_Controller::BULK_ACTION]), 'A supported operator could not see the Bulk Return surface for legacy option state ' . $legacy_state . '.');
		}
	}

	$shop_manager_role = get_role('shop_manager');
	wcos_compat_assert($shop_manager_role instanceof WP_Role, 'Shop Manager role is unavailable.');
	remove_role($custom_role);
	add_role($custom_role, 'WOS compatibility capable operator', $shop_manager_role->capabilities);
	$lower_id = wp_insert_user(array(
		'user_login' => 'wcos_compat_lower_' . wp_generate_password(8, false),
		'user_pass' => wp_generate_password(24, true),
		'user_email' => 'wcos-compat-lower-' . wp_generate_uuid4() . '@example.test',
		'role' => $custom_role,
	));
	wcos_compat_assert(!is_wp_error($lower_id), 'Unable to create capable lower-role fixture.');
	$user_ids[] = (int) $lower_id;
	wp_set_current_user((int) $lower_id);
	wcos_compat_expect_runtime(static function() { WCOS_Order_Mutation_Authorizer::assert_operator(); }, 'A capable lower role was accepted as an Order Splitter operator.');
	foreach (array(WCOS_Feature_Gates::SPLIT, WCOS_Feature_Gates::DUPLICATE) as $workflow) {
		wcos_compat_expect_runtime(static function() use ($workflow, $orders) {
			WCOS_Order_Mutation_Authorizer::assert_workflow($workflow, $orders['source']);
		}, 'A capable lower role gained ' . $workflow . ' authority.');
	}
	wcos_compat_expect_runtime(static function() use ($orders) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $orders['source'], $orders['target']);
	}, 'A capable lower role gained Merge authority.');
	wcos_compat_expect_runtime(static function() use ($orders) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::RETURN_ORDER, $orders['child'], $orders['original']);
	}, 'A capable lower role gained Return authority.');
	wcos_compat_expect_runtime(static function() use ($orders) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::BULK_RETURN, $orders['child'], $orders['original']);
	}, 'A capable lower role gained Bulk Return authority.');
	wcos_compat_assert(!isset((new WCOS_Bulk_Return_Admin_Controller())->register_bulk_action(array())[WCOS_Bulk_Return_Admin_Controller::BULK_ACTION]), 'A capable lower role could see the Bulk Return surface.');

	wp_set_current_user((int) $manager_id);
	$denied_order_id = $orders['target']->get_id();
	$deny_target_capability = static function($allcaps, $caps, $args) use (&$denied_order_id) {
		$requested = isset($args[0]) ? (string) $args[0] : '';
		$object_id = isset($args[2]) ? absint($args[2]) : 0;
		if ($denied_order_id === $object_id && in_array($requested, array('edit_shop_order', 'delete_shop_order'), true)) {
			foreach ((array) $caps as $capability) {
				$allcaps[$capability] = false;
			}
		}
		return $allcaps;
	};
	add_filter('user_has_cap', $deny_target_capability, 999, 3);
	wcos_compat_expect_runtime(static function() use ($orders) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $orders['source'], $orders['target']);
	}, 'Merge did not check capability on its target participant.');
	remove_filter('user_has_cap', $deny_target_capability, 999);

	$denied_order_id = $orders['original']->get_id();
	add_filter('user_has_cap', $deny_target_capability, 999, 3);
	wcos_compat_expect_runtime(static function() use ($orders) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::RETURN_ORDER, $orders['child'], $orders['original']);
	}, 'Return did not check capability on its original participant.');
	wcos_compat_expect_runtime(static function() use ($orders) {
		WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::BULK_RETURN, $orders['child'], $orders['original']);
	}, 'Bulk Return did not check capability on its original participant.');
	remove_filter('user_has_cap', $deny_target_capability, 999);

	$settings = new WooCommerce_Order_Splitter_Settings();
	wcos_compat_assert(!in_array('order_splitter_shop_manager_permission', wcos_compat_setting_ids($settings->get_advanced_settings()), true), 'Retired Shop Manager setting still renders.');
	$reintroduce_retired_setting = static function($fields) {
		$fields['legacy_permission'] = array('id' => 'order_splitter_shop_manager_permission', 'type' => 'checkbox');
		return $fields;
	};
	add_filter('advanced_settings', $reintroduce_retired_setting, 999);
	wcos_compat_assert(!in_array('order_splitter_shop_manager_permission', wcos_compat_setting_ids($settings->get_advanced_settings()), true), 'A filtered Settings definition reintroduced the retired permission control.');
	remove_filter('advanced_settings', $reintroduce_retired_setting, 999);
	delete_option('order_splitter_shop_manager_permission');
	WooCommerce_Order_Splitter_Settings::set_default_settings();
	wcos_compat_assert(false === get_option('order_splitter_shop_manager_permission', false), 'Activation defaults recreated the retired permission option.');

	$root = dirname(__DIR__, 2);
	$contract = file_get_contents($root . '/docs/1.4.11-to-1.5.x-functional-compatibility.md');
	$duplicate_service = file_get_contents($root . '/inc/domain/class-wcos-duplicate-order-service.php');
	wcos_compat_assert(false !== strpos($contract, 'Split child status') && false !== strpos($contract, 'Freeze the source order status'), 'Compatibility contract lost the Split source-status requirement.');
	wcos_compat_assert(false !== strpos($contract, 'Duplicate target status') && false !== strpos($contract, 'Keep `pending`'), 'Compatibility contract lost the independent Duplicate pending policy.');
	wcos_compat_assert(false !== strpos($duplicate_service, "'target_status' => 'pending'"), 'Duplicate runtime no longer reports its independent pending policy.');
	wcos_compat_assert(false === strpos(file_get_contents($root . '/inc/domain/class-wcos-order-mutation-authorizer.php'), 'order_splitter_shop_manager_permission'), 'Central authorizer still reads the retired option.');

	echo "compat-settings-access-ok roles=2 legacy_option_states=3 workflows=5 lower_role=denied cross_order=checked settings=retired status_policies=separate\n";
} finally {
	if ($admin_id) {
		wp_set_current_user((int) $admin_id);
	}
	foreach ($order_ids as $order_id) {
		$order = wc_get_order($order_id);
		if ($order instanceof WC_Order) {
			$order->delete(true);
		}
	}
	if (!function_exists('wp_delete_user')) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	foreach ($user_ids as $user_id) {
		wp_delete_user($user_id);
	}
	remove_role($custom_role);
	if ($legacy_option_exists) {
		update_option('order_splitter_shop_manager_permission', $legacy_option_value);
	} else {
		delete_option('order_splitter_shop_manager_permission');
	}
	wp_set_current_user($previous_user);
}
