<?php

if (!defined('ABSPATH')) {
	exit(1);
}

wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT), 'Sandbox candidate lost global Split enablement.');
wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE), 'Sandbox candidate lost Duplicate enablement.');
wcos_p2_adapter_assert(WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY), 'Sandbox candidate Category gate is not enabled.');
wcos_p2_adapter_assert(WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS), 'Sandbox candidate Stock-status gate is not enabled.');

$candidate_controller = WCOS_Split_Strategy_Admin_Controller::bootstrap();
wcos_p2_adapter_assert($candidate_controller instanceof WCOS_Split_Strategy_Admin_Controller, 'Sandbox candidate strategy controller did not bootstrap.');
wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION), 'Sandbox candidate Review route is not registered.');
wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION), 'Sandbox candidate Confirm route is not registered.');
wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION), 'Sandbox candidate Execute route is not registered.');

$candidate_previous_user = get_current_user_id();
$candidate_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$candidate_admin_id = wp_insert_user(array(
	'user_login' => 'wcos_sandbox_ui5_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-sandbox-ui5-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
wcos_p2_adapter_assert(!is_wp_error($candidate_admin_id), 'Unable to create sandbox UI5 administrator.');

$category_order_id = 0;
$category_operation_id = '';
$category_keep = null;
$category_move = null;
$category_keep_term = null;
$category_move_term = null;
$stock_order_id = 0;
$stock_operation_id = '';
$stock_keep = null;
$stock_move = null;

try {
	update_option('order_splitter_status_allowed', array('wc-pending'));
	wp_set_current_user($candidate_admin_id);

	/* Category: enabled Review -> Confirm -> Execute, frozen taxonomy and durable replay. */
	$suffix = strtolower(wp_generate_password(6, false, false));
	$category_keep_term = wp_insert_term('WCOS UI5 Keep ' . $suffix, 'product_cat');
	$category_move_term = wp_insert_term('WCOS UI5 Move ' . $suffix, 'product_cat');
	wcos_p2_adapter_assert(!is_wp_error($category_keep_term) && !is_wp_error($category_move_term), 'Unable to create UI5 Category terms.');
	$category_keep = wcos_p2_adapter_product('WCOS UI5 Category Keep', '12.00', 30);
	$category_move = wcos_p2_adapter_product('WCOS UI5 Category Move', '8.00', 40);
	wp_set_object_terms($category_keep->get_id(), array(absint($category_keep_term['term_id'])), 'product_cat');
	wp_set_object_terms($category_move->get_id(), array(absint($category_move_term['term_id'])), 'product_cat');

	$category_order = wc_create_order();
	$category_order->set_status('pending');
	$category_order->set_currency('USD');
	$category_keep_item = $category_order->add_product($category_keep, 2);
	$category_move_item = $category_order->add_product($category_move, 2);
	$category_order->calculate_totals(false);
	$category_order->save();
	$category_order_id = $category_order->get_id();
	$category_keep_stock = wc_get_product($category_keep->get_id())->get_stock_quantity();
	$category_move_stock = wc_get_product($category_move->get_id())->get_stock_quantity();
	$category_nonce = wp_create_nonce('wcos_split_strategy_order_' . $category_order_id);

	$category_review = $candidate_controller->review_request(array(
		'order_id' => $category_order_id,
		'nonce' => $category_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
	));
	$category_keep_bucket = 'category-' . absint($category_keep_term['term_id']);
	$category_confirmation = $candidate_controller->confirm_request(array(
		'order_id' => $category_order_id,
		'nonce' => $category_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
		'review_id' => $category_review['review_id'],
		'review_token' => $category_review['review_token'],
		'source_bucket_key' => $category_keep_bucket,
	));
	$category_operation_id = sanitize_key($category_confirmation['operation_id']);

	wp_set_object_terms($category_move->get_id(), array(absint($category_keep_term['term_id'])), 'product_cat');
	$category_result = $candidate_controller->execute_request(array(
		'order_id' => $category_order_id,
		'nonce' => $category_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
		'operation_id' => $category_operation_id,
		'confirmation_token' => $category_confirmation['confirmation_token'],
	));
	wcos_p2_adapter_assert('completed' === $category_result['status'] && 1 === count($category_result['children']), 'UI5 Category Split did not create exactly one child.');
	$category_child_id = absint($category_result['children'][0]['id']);
	$category_source = wc_get_order($category_order_id);
	wcos_p2_adapter_assert($category_source->get_item($category_keep_item) instanceof WC_Order_Item_Product, 'UI5 Category Split lost the retained source bucket.');
	wcos_p2_adapter_assert(!$category_source->get_item($category_move_item), 'UI5 Category Split ignored frozen Review classification.');
	wcos_p2_adapter_assert($category_keep_stock == wc_get_product($category_keep->get_id())->get_stock_quantity(), 'UI5 Category Split changed retained-product stock.');
	wcos_p2_adapter_assert($category_move_stock == wc_get_product($category_move->get_id())->get_stock_quantity(), 'UI5 Category Split changed moved-product stock.');
	$category_journal = WCOS_Operation_Journal::get($category_source, $category_operation_id);
	wcos_p2_adapter_assert(is_array($category_journal) && 'completed' === $category_journal['status'], 'UI5 Category journal did not complete.');
	wcos_p2_adapter_assert(WCOS_Split_Strategy_Gates::CATEGORY === $category_journal['context']['strategy_authority']['strategy'], 'UI5 Category journal lost semantic strategy authority.');

	WCOS_Split_Strategy_Confirmation_Store::delete($category_operation_id);
	$category_retry = $candidate_controller->execute_request(array(
		'order_id' => $category_order_id,
		'nonce' => $category_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::CATEGORY,
		'operation_id' => $category_operation_id,
		'confirmation_token' => '',
	));
	wcos_p2_adapter_assert(1 === count($category_retry['children']) && $category_child_id === absint($category_retry['children'][0]['id']), 'UI5 Category durable retry returned a different child.');
	wcos_p2_adapter_assert(1 === count(wcos_p2_adapter_children($category_order_id, $category_operation_id)), 'UI5 Category durable retry created duplicate children.');

	/* Stock status: enabled Review -> Confirm -> Execute uses frozen volatile status. */
	$stock_keep = wcos_p2_adapter_product('WCOS UI5 Stock Keep', '11.00');
	$stock_move = wcos_p2_adapter_product('WCOS UI5 Stock Move', '6.00');
	$stock_keep->set_stock_status('instock');
	$stock_keep->save();
	$stock_move->set_stock_status('outofstock');
	$stock_move->save();
	$stock_order = wc_create_order();
	$stock_order->set_status('pending');
	$stock_order->set_currency('USD');
	$stock_keep_item = $stock_order->add_product($stock_keep, 1);
	$stock_move_item = $stock_order->add_product($stock_move, 2);
	$stock_order->calculate_totals(false);
	$stock_order->save();
	$stock_order_id = $stock_order->get_id();
	$stock_nonce = wp_create_nonce('wcos_split_strategy_order_' . $stock_order_id);

	$stock_review = $candidate_controller->review_request(array(
		'order_id' => $stock_order_id,
		'nonce' => $stock_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::STOCK_STATUS,
	));
	$stock_confirmation = $candidate_controller->confirm_request(array(
		'order_id' => $stock_order_id,
		'nonce' => $stock_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::STOCK_STATUS,
		'review_id' => $stock_review['review_id'],
		'review_token' => $stock_review['review_token'],
		'source_bucket_key' => 'stock-instock',
	));
	$stock_operation_id = sanitize_key($stock_confirmation['operation_id']);
	$stock_move = wc_get_product($stock_move->get_id());
	$stock_move->set_stock_status('instock');
	$stock_move->save();

	$stock_result = $candidate_controller->execute_request(array(
		'order_id' => $stock_order_id,
		'nonce' => $stock_nonce,
		'strategy' => WCOS_Split_Strategy_Gates::STOCK_STATUS,
		'operation_id' => $stock_operation_id,
		'confirmation_token' => $stock_confirmation['confirmation_token'],
	));
	wcos_p2_adapter_assert('completed' === $stock_result['status'] && 1 === count($stock_result['children']), 'UI5 Stock-status Split did not create exactly one child.');
	$stock_source = wc_get_order($stock_order_id);
	wcos_p2_adapter_assert($stock_source->get_item($stock_keep_item) instanceof WC_Order_Item_Product, 'UI5 Stock-status Split lost selected source bucket.');
	wcos_p2_adapter_assert(!$stock_source->get_item($stock_move_item), 'UI5 Stock-status Split reclassified catalog state after Review.');
	$stock_journal = WCOS_Operation_Journal::get($stock_source, $stock_operation_id);
	wcos_p2_adapter_assert(is_array($stock_journal) && 'completed' === $stock_journal['status'], 'UI5 Stock-status journal did not complete.');
	wcos_p2_adapter_assert(WCOS_Split_Strategy_Gates::STOCK_STATUS === $stock_journal['context']['strategy_authority']['strategy'], 'UI5 Stock-status journal lost semantic strategy authority.');
} finally {
	wp_set_current_user($candidate_previous_user);
	update_option('order_splitter_status_allowed', $candidate_allowed_statuses);
	if ($category_order_id) {
		$source = wc_get_order($category_order_id);
		if ($source instanceof WC_Order && '' !== $category_operation_id) {
			WCOS_Split_Strategy_Confirmation_Store::delete($category_operation_id);
			wcos_p2_adapter_cleanup($category_order_id, $category_operation_id);
		} elseif ($source instanceof WC_Order) {
			$source->delete(true);
		}
	}
	if ($stock_order_id) {
		$source = wc_get_order($stock_order_id);
		if ($source instanceof WC_Order && '' !== $stock_operation_id) {
			WCOS_Split_Strategy_Confirmation_Store::delete($stock_operation_id);
			wcos_p2_adapter_cleanup($stock_order_id, $stock_operation_id);
		} elseif ($source instanceof WC_Order) {
			$source->delete(true);
		}
	}
	foreach (array($category_keep, $category_move, $stock_keep, $stock_move) as $product) {
		if ($product instanceof WC_Product && $product->get_id()) {
			wp_delete_post($product->get_id(), true);
		}
	}
	foreach (array($category_keep_term, $category_move_term) as $term) {
		if (is_array($term) && !empty($term['term_id'])) {
			wp_delete_term(absint($term['term_id']), 'product_cat');
		}
	}
	if (function_exists('wp_delete_user')) {
		wp_delete_user($candidate_admin_id);
	}
}

wcos_p2_adapter_assert(WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY), 'Sandbox Category gate changed during UI5 E2E.');
wcos_p2_adapter_assert(WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS), 'Sandbox Stock-status gate changed during UI5 E2E.');
wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION), 'Sandbox Review route disappeared after UI5 E2E.');
wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION), 'Sandbox Confirm route disappeared after UI5 E2E.');
wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION), 'Sandbox Execute route disappeared after UI5 E2E.');

echo "p2-strategy-sandbox-ui5-ok\n";
