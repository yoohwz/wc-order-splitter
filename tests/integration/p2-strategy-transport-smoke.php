<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_strategy_transport_expect($code, $http_status, callable $callback, $message) {
	try {
		$callback();
	} catch (WCOS_Split_Transport_Exception $exception) {
		wcos_p2_adapter_assert($code === $exception->get_error_code(), $message . ' Wrong code: ' . $exception->get_error_code());
		wcos_p2_adapter_assert($http_status === $exception->get_http_status(), $message . ' Wrong HTTP status.');
		return $exception;
	}
	throw new RuntimeException($message);
}

wcos_p2_adapter_assert(class_exists('WCOS_Split_Strategy_Review_Store'), 'Strategy Review store was not loaded.');
wcos_p2_adapter_assert(class_exists('WCOS_Split_Strategy_Admin_Controller'), 'Strategy transport controller contract was not loaded.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION), 'Hard-off strategy Review AJAX route was registered.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION), 'Hard-off strategy Confirm AJAX route was registered.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION), 'Hard-off strategy Execute AJAX route was registered.');

$transport_controller = new WCOS_Split_Strategy_Admin_Controller();
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION), 'Instantiating the hard-off strategy controller registered Review AJAX.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION), 'Instantiating the hard-off strategy controller registered Confirm AJAX.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION), 'Instantiating the hard-off strategy controller registered Execute AJAX.');

$transport_previous_user = get_current_user_id();
$transport_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$transport_manager_permission = get_option('order_splitter_shop_manager_permission', 'no');
$transport_admin_id = wp_insert_user(array(
	'user_login' => 'wcos_strategy_transport_admin_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-strategy-transport-admin-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
$transport_other_admin_id = wp_insert_user(array(
	'user_login' => 'wcos_strategy_transport_other_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-strategy-transport-other-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
$transport_subscriber_id = wp_insert_user(array(
	'user_login' => 'wcos_strategy_transport_sub_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-strategy-transport-sub-' . wp_generate_uuid4() . '@example.test',
	'role' => 'subscriber',
));
wcos_p2_adapter_assert(!is_wp_error($transport_admin_id) && !is_wp_error($transport_other_admin_id) && !is_wp_error($transport_subscriber_id), 'Unable to create strategy transport users.');

$transport_suffix = strtolower(wp_generate_password(6, false, false));
$transport_keep_term = wp_insert_term('WCOS Transport Keep ' . $transport_suffix, 'product_cat');
$transport_move_term = wp_insert_term('WCOS Transport Move ' . $transport_suffix, 'product_cat');
wcos_p2_adapter_assert(!is_wp_error($transport_keep_term) && !is_wp_error($transport_move_term), 'Unable to create strategy transport categories.');
$transport_keep = wcos_p2_adapter_product('WCOS Transport Keep', '11.00');
$transport_move = wcos_p2_adapter_product('WCOS Transport Move', '9.00');
wp_set_object_terms($transport_keep->get_id(), array(absint($transport_keep_term['term_id'])), 'product_cat');
wp_set_object_terms($transport_move->get_id(), array(absint($transport_move_term['term_id'])), 'product_cat');
$transport_order = wc_create_order();
$transport_order->set_status('pending');
$transport_order->set_currency('USD');
$transport_keep_item = $transport_order->add_product($transport_keep, 2);
$transport_move_item = $transport_order->add_product($transport_move, 2);
$transport_order->calculate_totals(false);
$transport_order->set_billing_email('strategy-transport-private@example.test');
$transport_order->save();
$transport_order_id = $transport_order->get_id();
$transport_nonce = wp_create_nonce('wcos_split_strategy_order_' . $transport_order_id);

$strategy_reflection = new ReflectionClass('WCOS_Split_Strategy_Gates');
$strategy_states_property = $strategy_reflection->getProperty('states');
$strategy_states_property->setAccessible(true);
$release_strategy_states = $strategy_states_property->getValue();

enable_strategy_transport_test:
try {
	update_option('order_splitter_status_allowed', array('wc-pending'));
	update_option('order_splitter_shop_manager_permission', 'no');
	wp_set_current_user($transport_admin_id);

	/* Real release state blocks direct controller use before nonce/order mutation. */
	wcos_strategy_transport_expect(
		'strategy_disabled',
		503,
		static function() use ($transport_controller, $transport_order_id, $transport_nonce) {
			$transport_controller->review_request(array(
				'order_id' => $transport_order_id,
				'nonce' => $transport_nonce,
				'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
			));
		},
		'Hard-off Category transport was directly usable.'
	);

	$test_strategy_states = $release_strategy_states;
	$test_strategy_states[WCOS_Split_Strategy_Gates::CATEGORY] = true;
	$strategy_states_property->setValue(null, $test_strategy_states);

	wcos_strategy_transport_expect(
		'invalid_nonce',
		403,
		static function() use ($transport_controller, $transport_order_id) {
			$transport_controller->review_request(array(
				'order_id' => $transport_order_id,
				'nonce' => 'invalid',
				'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
			));
		},
		'Strategy Review accepted an invalid nonce.'
	);

	wp_set_current_user($transport_subscriber_id);
	$subscriber_nonce = wp_create_nonce('wcos_split_strategy_order_' . $transport_order_id);
	wcos_strategy_transport_expect(
		'authorization_failed',
		403,
		static function() use ($transport_controller, $transport_order_id, $subscriber_nonce) {
			$transport_controller->review_request(array(
				'order_id' => $transport_order_id,
				'nonce' => $subscriber_nonce,
				'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
			));
		},
		'Strategy Review accepted an unauthorized user.'
	);

	wp_set_current_user($transport_admin_id);
	$transport_nonce = wp_create_nonce('wcos_split_strategy_order_' . $transport_order_id);
	$review_response = $transport_controller->review_request(array(
		'order_id' => $transport_order_id,
		'nonce' => $transport_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
	));
	wcos_p2_adapter_assert(!empty($review_response['review_id']) && !empty($review_response['review_token']), 'Strategy Review did not create opaque server authority.');
	wcos_p2_adapter_assert(!empty($review_response['review']['supported']), 'Strategy Review response is not supported.');
	wcos_p2_adapter_assert(false === strpos(wp_json_encode($review_response), 'strategy-transport-private@example.test'), 'Strategy Review transport leaked billing PII.');

	$keep_bucket = 'category-' . absint($transport_keep_term['term_id']);
	wcos_strategy_transport_expect(
		'review_invalid_token',
		403,
		static function() use ($transport_controller, $transport_order_id, $transport_nonce, $review_response, $keep_bucket) {
			$transport_controller->confirm_request(array(
				'order_id' => $transport_order_id,
				'nonce' => $transport_nonce,
				'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
				'review_id' => $review_response['review_id'],
				'review_token' => 'invalid',
				'source_bucket_key' => $keep_bucket,
			));
		},
		'Strategy Confirm accepted an invalid server Review token.'
	);

	wp_set_current_user($transport_other_admin_id);
	$other_nonce = wp_create_nonce('wcos_split_strategy_order_' . $transport_order_id);
	wcos_strategy_transport_expect(
		'review_owner_mismatch',
		403,
		static function() use ($transport_controller, $transport_order_id, $other_nonce, $review_response, $keep_bucket) {
			$transport_controller->confirm_request(array(
				'order_id' => $transport_order_id,
				'nonce' => $other_nonce,
				'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
				'review_id' => $review_response['review_id'],
				'review_token' => $review_response['review_token'],
				'source_bucket_key' => $keep_bucket,
			));
		},
		'Strategy Confirm accepted another user\'s Review authority.'
	);

	wp_set_current_user($transport_admin_id);
	$transport_nonce = wp_create_nonce('wcos_split_strategy_order_' . $transport_order_id);
	$transport_order = wc_get_order($transport_order_id);
	$transport_order->set_customer_note('transport-review-became-stale');
	$transport_order->save();
	wcos_strategy_transport_expect(
		'review_source_changed',
		409,
		static function() use ($transport_controller, $transport_order_id, $transport_nonce, $review_response, $keep_bucket) {
			$transport_controller->confirm_request(array(
				'order_id' => $transport_order_id,
				'nonce' => $transport_nonce,
				'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
				'review_id' => $review_response['review_id'],
				'review_token' => $review_response['review_token'],
				'source_bucket_key' => $keep_bucket,
			));
		},
		'Strategy Confirm accepted a Review after source-order change.'
	);
	WCOS_Split_Strategy_Review_Store::delete($review_response['review_id']);

	$review_response = $transport_controller->review_request(array(
		'order_id' => $transport_order_id,
		'nonce' => $transport_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
	));
	$confirm_response = $transport_controller->confirm_request(array(
		'order_id' => $transport_order_id,
		'nonce' => $transport_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
		'review_id' => $review_response['review_id'],
		'review_token' => $review_response['review_token'],
		'source_bucket_key' => $keep_bucket,
	));
	wcos_p2_adapter_assert(!empty($confirm_response['operation_id']) && !empty($confirm_response['confirmation_token']), 'Strategy Confirm did not create operation authority.');
	wcos_p2_adapter_assert(false === strpos(wp_json_encode($confirm_response), 'classification_fingerprint'), 'Strategy Confirm exposed internal classification authority to the client.');

	wcos_strategy_transport_expect(
		'review_expired',
		410,
		static function() use ($transport_controller, $transport_order_id, $transport_nonce, $review_response, $keep_bucket) {
			$transport_controller->confirm_request(array(
				'order_id' => $transport_order_id,
				'nonce' => $transport_nonce,
				'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
				'review_id' => $review_response['review_id'],
				'review_token' => $review_response['review_token'],
				'source_bucket_key' => $keep_bucket,
			));
		},
		'Consumed server Review authority was reusable.'
	);

	wcos_strategy_transport_expect(
		'confirmation_invalid_token',
		403,
		static function() use ($transport_controller, $transport_order_id, $transport_nonce, $confirm_response) {
			$transport_controller->execute_request(array(
				'order_id' => $transport_order_id,
				'nonce' => $transport_nonce,
				'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
				'operation_id' => $confirm_response['operation_id'],
				'confirmation_token' => 'invalid',
			));
		},
		'Strategy Execute accepted an invalid confirmation token.'
	);
	wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($transport_order_id), $confirm_response['operation_id']), 'Invalid transport confirmation created a mutation journal.');

	/* Execute must use only frozen server authority, not current catalog classification. */
	wp_set_object_terms($transport_move->get_id(), array(absint($transport_keep_term['term_id'])), 'product_cat');
	$execute_response = $transport_controller->execute_request(array(
		'order_id' => $transport_order_id,
		'nonce' => $transport_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
		'operation_id' => $confirm_response['operation_id'],
		'confirmation_token' => $confirm_response['confirmation_token'],
	));
	wcos_p2_adapter_assert('completed' === $execute_response['status'] && 1 === count($execute_response['children']), 'Confirmed strategy transport did not complete exactly one child.');
	$transport_child_id = absint($execute_response['children'][0]['id']);
	$transport_order = wc_get_order($transport_order_id);
	wcos_p2_adapter_assert($transport_order->get_item($transport_keep_item) instanceof WC_Order_Item_Product, 'Strategy transport removed the selected source bucket.');
	wcos_p2_adapter_assert(!$transport_order->get_item($transport_move_item), 'Strategy transport did not execute the frozen moved line.');
	$transport_journal = WCOS_Operation_Journal::get($transport_order, $confirm_response['operation_id']);
	wcos_p2_adapter_assert(is_array($transport_journal) && 'completed' === $transport_journal['status'], 'Strategy transport did not complete its durable journal.');
	wcos_p2_adapter_assert(WCOS_Split_Strategy_Gates::CATEGORY === $transport_journal['context']['strategy_authority']['strategy'], 'Strategy transport journal lost semantic Category authority.');

	WCOS_Split_Strategy_Confirmation_Store::delete($confirm_response['operation_id']);
	$retry_response = $transport_controller->execute_request(array(
		'order_id' => $transport_order_id,
		'nonce' => $transport_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
		'operation_id' => $confirm_response['operation_id'],
		'confirmation_token' => '',
	));
	wcos_p2_adapter_assert(1 === count($retry_response['children']) && $transport_child_id === absint($retry_response['children'][0]['id']), 'Strategy transport durable replay created a different child.');
	wcos_p2_adapter_assert(1 === count(wcos_p2_adapter_children($transport_order_id, $confirm_response['operation_id'])), 'Strategy transport replay created duplicate children.');
} finally {
	$strategy_states_property->setValue(null, $release_strategy_states);
	wp_set_current_user($transport_previous_user);
	update_option('order_splitter_status_allowed', $transport_allowed_statuses);
	update_option('order_splitter_shop_manager_permission', $transport_manager_permission);

	if (isset($confirm_response['operation_id'])) {
		WCOS_Split_Strategy_Confirmation_Store::delete($confirm_response['operation_id']);
	}
	if (isset($review_response['review_id'])) {
		WCOS_Split_Strategy_Review_Store::delete($review_response['review_id']);
	}
	if (isset($transport_order_id)) {
		$cleanup_order = wc_get_order($transport_order_id);
		if ($cleanup_order instanceof WC_Order && isset($confirm_response['operation_id'])) {
			wcos_p2_adapter_cleanup($transport_order_id, $confirm_response['operation_id']);
		} elseif ($cleanup_order instanceof WC_Order) {
			$cleanup_order->delete(true);
		}
	}
	if ($transport_keep instanceof WC_Product) {
		wp_delete_post($transport_keep->get_id(), true);
	}
	if ($transport_move instanceof WC_Product) {
		wp_delete_post($transport_move->get_id(), true);
	}
	if (!is_wp_error($transport_keep_term)) {
		wp_delete_term(absint($transport_keep_term['term_id']), 'product_cat');
	}
	if (!is_wp_error($transport_move_term)) {
		wp_delete_term(absint($transport_move_term['term_id']), 'product_cat');
	}
	if (function_exists('wp_delete_user')) {
		wp_delete_user($transport_admin_id);
		wp_delete_user($transport_other_admin_id);
		wp_delete_user($transport_subscriber_id);
	}
}

wcos_p2_adapter_assert(!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY), 'Category strategy gate was not restored after transport acceptance.');
wcos_p2_adapter_assert(!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS), 'Stock-status strategy gate was not restored after transport acceptance.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION), 'Strategy Review AJAX route was registered after transport acceptance.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION), 'Strategy Confirm AJAX route was registered after transport acceptance.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION), 'Strategy Execute AJAX route was registered after transport acceptance.');

echo "p2-strategy-transport-hard-off-ok\n";
