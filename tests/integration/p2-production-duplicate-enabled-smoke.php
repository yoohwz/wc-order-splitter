<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_p2_duplicate_enabled_expect_transport($code, $http_status, callable $callback, $message) {
	try {
		$callback();
	} catch (WCOS_Duplicate_Transport_Exception $exception) {
		wcos_p2_adapter_assert($code === $exception->get_error_code(), $message . ' Wrong code: ' . $exception->get_error_code());
		wcos_p2_adapter_assert($http_status === $exception->get_http_status(), $message . ' Wrong HTTP status.');
		return $exception;
	}
	throw new RuntimeException($message);
}

wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT), 'Production Split gate was lost while enabling Duplicate.');
wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE), 'Hardened Duplicate is not production-enabled in the enablement contract.');
wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE), 'Production Merge gate was lost while validating Duplicate.');
wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER), 'Production Return gate was lost while validating Duplicate.');
wcos_p2_adapter_assert(WCOS_Feature_Gates::any_enabled(), 'Approved production gate set was reported as fully disabled.');
wcos_p2_adapter_assert(WC_Order_Splitter_Safety_Guard::mutations_enabled(), 'Safety guard did not reflect the approved Duplicate gate.');
wcos_p2_adapter_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN), 'Bulk Return is enabled alongside Duplicate.');

$duplicate_enabled_previous_user = get_current_user_id();
$duplicate_enabled_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$duplicate_enabled_manager_permission = get_option('order_splitter_shop_manager_permission', 'no');
$duplicate_enabled_manage_stock = get_option('woocommerce_manage_stock', 'yes');
$duplicate_enabled_operation = '';
$duplicate_enabled_target_id = 0;

$duplicate_enabled_admin_id = wp_insert_user(array(
	'user_login' => 'wcos_p2_duplicate_enabled_admin_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-p2-duplicate-enabled-admin-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
$duplicate_enabled_subscriber_id = wp_insert_user(array(
	'user_login' => 'wcos_p2_duplicate_enabled_subscriber_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-p2-duplicate-enabled-subscriber-' . wp_generate_uuid4() . '@example.test',
	'role' => 'subscriber',
));
wcos_p2_adapter_assert(!is_wp_error($duplicate_enabled_admin_id) && !is_wp_error($duplicate_enabled_subscriber_id), 'Unable to create production Duplicate transport users.');

$duplicate_enabled_product = wcos_p2_adapter_product('WCOS P2 production Duplicate enabled', '14.25', 30);
list($duplicate_enabled_source, $duplicate_enabled_item_id) = wcos_p2_adapter_order($duplicate_enabled_product, 3, 'pending');
$duplicate_enabled_source_id = $duplicate_enabled_source->get_id();
$duplicate_enabled_source->set_payment_method('cod');
$duplicate_enabled_source->set_payment_method_title('Cash on delivery');
$duplicate_enabled_source->set_transaction_id('source-duplicate-production-transaction');
$duplicate_enabled_source->set_billing_first_name('DuplicateProductionPrivateProbe');
$duplicate_enabled_source->set_billing_email('duplicate-production-private@example.test');
$duplicate_enabled_source->save();

wc_reduce_stock_levels($duplicate_enabled_source);
$duplicate_enabled_source->get_data_store()->set_stock_reduced($duplicate_enabled_source_id, true);
$duplicate_enabled_source = wc_get_order($duplicate_enabled_source_id);
$duplicate_enabled_stock_before = wc_get_product($duplicate_enabled_product->get_id())->get_stock_quantity();
$duplicate_enabled_signature_before = WCOS_Order_Contract_Snapshot::source_signature($duplicate_enabled_source);
$duplicate_enabled_controller = new WCOS_Duplicate_Admin_Controller();

try {
	update_option('woocommerce_manage_stock', 'yes');
	update_option('order_splitter_status_allowed', array('wc-pending'));
	update_option('order_splitter_shop_manager_permission', 'no');
	wp_set_current_user($duplicate_enabled_admin_id);

	wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Duplicate_Admin_Controller::REVIEW_ACTION), 'Production Duplicate review AJAX route is not registered.');
	wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Duplicate_Admin_Controller::EXECUTE_ACTION), 'Production Duplicate execute AJAX route is not registered.');

	wcos_p2_duplicate_enabled_expect_transport(
		'invalid_nonce',
		403,
		static function() use ($duplicate_enabled_controller, $duplicate_enabled_source_id) {
			$duplicate_enabled_controller->review_request(array(
				'order_id' => $duplicate_enabled_source_id,
				'nonce' => 'invalid',
			));
		},
		'Production Duplicate review accepted an invalid nonce.'
	);

	wp_set_current_user($duplicate_enabled_subscriber_id);
	$duplicate_enabled_subscriber_nonce = wp_create_nonce('wcos_duplicate_order_' . $duplicate_enabled_source_id);
	wcos_p2_duplicate_enabled_expect_transport(
		'authorization_failed',
		403,
		static function() use ($duplicate_enabled_controller, $duplicate_enabled_source_id, $duplicate_enabled_subscriber_nonce) {
			$duplicate_enabled_controller->review_request(array(
				'order_id' => $duplicate_enabled_source_id,
				'nonce' => $duplicate_enabled_subscriber_nonce,
			));
		},
		'Production Duplicate review accepted a subscriber.'
	);

	wp_set_current_user($duplicate_enabled_admin_id);
	$duplicate_enabled_nonce = wp_create_nonce('wcos_duplicate_order_' . $duplicate_enabled_source_id);

	ob_start();
	$duplicate_enabled_controller->render_launcher(wc_get_order($duplicate_enabled_source_id));
	$duplicate_enabled_launcher_html = ob_get_clean();
	wcos_p2_adapter_assert(false !== strpos($duplicate_enabled_launcher_html, 'wcos-duplicate-launcher'), 'Production Duplicate launcher did not render after gate enablement.');
	wcos_p2_adapter_assert(false !== strpos($duplicate_enabled_launcher_html, 'aria-controls="wcos-duplicate-dialog-'), 'Production Duplicate launcher is not bound to its dialog.');

	$duplicate_enabled_preflight = (new WCOS_Mutation_Gateway())->duplicate_preflight(wc_get_order($duplicate_enabled_source_id));
	wcos_p2_adapter_assert(!empty($duplicate_enabled_preflight['supported']), 'Production Duplicate Gateway preflight rejected the valid source.');
	$duplicate_enabled_dialog_html = $duplicate_enabled_controller->dialog_html(wc_get_order($duplicate_enabled_source_id), $duplicate_enabled_preflight);
	wcos_p2_adapter_assert(false !== strpos($duplicate_enabled_dialog_html, 'role="dialog"'), 'Production Duplicate dialog lost accessible dialog semantics.');
	wcos_p2_adapter_assert(false === strpos($duplicate_enabled_dialog_html, 'DuplicateProductionPrivateProbe'), 'Production Duplicate dialog leaked billing PII.');
	wcos_p2_adapter_assert(false === strpos($duplicate_enabled_dialog_html, 'duplicate-production-private@example.test'), 'Production Duplicate dialog leaked billing email PII.');

	$duplicate_enabled_review = $duplicate_enabled_controller->review_request(array(
		'order_id' => $duplicate_enabled_source_id,
		'nonce' => $duplicate_enabled_nonce,
	));
	$duplicate_enabled_operation = $duplicate_enabled_review['operation_id'];
	wcos_p2_adapter_assert(!empty($duplicate_enabled_review['preflight']['supported']), 'Production Duplicate review did not return a supported preflight.');
	wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($duplicate_enabled_source_id), $duplicate_enabled_operation), 'Read-only production Duplicate review created a journal.');
	wcos_p2_adapter_assert($duplicate_enabled_signature_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($duplicate_enabled_source_id)), 'Read-only production Duplicate review changed the source order.');

	wcos_p2_duplicate_enabled_expect_transport(
		'confirmation_invalid_token',
		403,
		static function() use ($duplicate_enabled_controller, $duplicate_enabled_source_id, $duplicate_enabled_nonce, $duplicate_enabled_review) {
			$duplicate_enabled_controller->execute_request(array(
				'order_id' => $duplicate_enabled_source_id,
				'nonce' => $duplicate_enabled_nonce,
				'operation_id' => $duplicate_enabled_review['operation_id'],
				'confirmation_token' => 'invalid-duplicate-confirmation-token',
			));
		},
		'Production Duplicate execute accepted an invalid confirmation token.'
	);

	$duplicate_enabled_result = $duplicate_enabled_controller->execute_request(array(
		'order_id' => $duplicate_enabled_source_id,
		'nonce' => $duplicate_enabled_nonce,
		'operation_id' => $duplicate_enabled_review['operation_id'],
		'confirmation_token' => $duplicate_enabled_review['confirmation_token'],
	));
	wcos_p2_adapter_assert('completed' === $duplicate_enabled_result['status'], 'Production Duplicate controller did not report completion.');
	$duplicate_enabled_target_id = absint($duplicate_enabled_result['target']['id']);
	wcos_p2_adapter_assert($duplicate_enabled_target_id > 0, 'Production Duplicate controller did not return a target order ID.');

	$duplicate_enabled_target = wc_get_order($duplicate_enabled_target_id);
	$duplicate_enabled_source = wc_get_order($duplicate_enabled_source_id);
	wcos_p2_adapter_assert($duplicate_enabled_target instanceof WC_Order, 'Production Duplicate target could not be reloaded.');
	wcos_p2_adapter_assert('pending' === $duplicate_enabled_target->get_status(), 'Production Duplicate target did not remain Pending payment.');
	wcos_p2_adapter_assert('wc-order-splitter-duplicate' === $duplicate_enabled_target->get_created_via(), 'Production Duplicate target has an unexpected created_via value.');
	wcos_p2_adapter_assert($duplicate_enabled_source_id === (int) $duplicate_enabled_target->get_meta('_wcos_duplicate_source_order', true), 'Production Duplicate target lost its source relation.');
	wcos_p2_adapter_assert($duplicate_enabled_operation === (string) $duplicate_enabled_target->get_meta('_wcos_operation_id', true), 'Production Duplicate target lost its operation relation.');
	wcos_p2_adapter_assert('' === (string) $duplicate_enabled_target->get_transaction_id(), 'Production Duplicate copied the source transaction ID.');
	wcos_p2_adapter_assert(!$duplicate_enabled_target->get_date_paid(), 'Production Duplicate copied the source paid state.');
	wcos_p2_adapter_assert(false === (bool) $duplicate_enabled_target->get_data_store()->get_stock_reduced($duplicate_enabled_target_id), 'Production Duplicate copied order-level stock-reduced state.');
	$duplicate_enabled_target_line = current($duplicate_enabled_target->get_items('line_item'));
	wcos_p2_adapter_assert($duplicate_enabled_target_line instanceof WC_Order_Item_Product, 'Production Duplicate target lost its product line.');
	wcos_p2_adapter_assert('' === (string) $duplicate_enabled_target_line->get_meta('_reduced_stock', true), 'Production Duplicate copied line _reduced_stock state.');
	wcos_p2_adapter_assert($duplicate_enabled_signature_before === WCOS_Order_Contract_Snapshot::source_signature($duplicate_enabled_source), 'Production Duplicate changed the source commercial state.');
	wcos_p2_adapter_assert(
		WCOS_Decimal::to_units($duplicate_enabled_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($duplicate_enabled_product->get_id())->get_stock_quantity(), 6),
		'Production Duplicate changed physical product stock.'
	);
	wcos_p2_adapter_assert(1 === count(wcos_duplicate_targets($duplicate_enabled_source_id, $duplicate_enabled_operation)), 'Production Duplicate created an unexpected target count.');

	$duplicate_enabled_journal = WCOS_Operation_Journal::get($duplicate_enabled_source, $duplicate_enabled_operation);
	wcos_p2_adapter_assert(is_array($duplicate_enabled_journal) && 'completed' === $duplicate_enabled_journal['status'], 'Production Duplicate journal did not complete.');
	wcos_p2_adapter_assert((int) WCOS_Duplicate_Preflight::POLICY_VERSION === (int) $duplicate_enabled_journal['context']['policy_version'], 'Production Duplicate journal lost policy-version authority.');
	wcos_p2_adapter_assert(array_key_exists('price_precision', $duplicate_enabled_journal['context']), 'Production Duplicate journal lost price precision.');

	$duplicate_enabled_retry = $duplicate_enabled_controller->execute_request(array(
		'order_id' => $duplicate_enabled_source_id,
		'nonce' => $duplicate_enabled_nonce,
		'operation_id' => $duplicate_enabled_review['operation_id'],
		'confirmation_token' => $duplicate_enabled_review['confirmation_token'],
	));
	wcos_p2_adapter_assert($duplicate_enabled_target_id === absint($duplicate_enabled_retry['target']['id']), 'Production Duplicate retry did not return the original target.');
	wcos_p2_adapter_assert(1 === count(wcos_duplicate_targets($duplicate_enabled_source_id, $duplicate_enabled_operation)), 'Production Duplicate retry created a second target.');
	wcos_p2_adapter_assert(
		WCOS_Decimal::to_units($duplicate_enabled_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($duplicate_enabled_product->get_id())->get_stock_quantity(), 6),
		'Production Duplicate retry changed physical product stock.'
	);
} finally {
	if ($duplicate_enabled_operation) {
		WCOS_Duplicate_Confirmation_Store::delete($duplicate_enabled_operation);
		$duplicate_enabled_source = wc_get_order($duplicate_enabled_source_id);
		if ($duplicate_enabled_source instanceof WC_Order) {
			WCOS_Operation_Journal::delete($duplicate_enabled_source, $duplicate_enabled_operation);
		}
	}
	if ($duplicate_enabled_target_id) {
		$duplicate_enabled_target = wc_get_order($duplicate_enabled_target_id);
		if ($duplicate_enabled_target instanceof WC_Order) {
			$duplicate_enabled_target->delete(true);
		}
	}
	$duplicate_enabled_source = wc_get_order($duplicate_enabled_source_id);
	if ($duplicate_enabled_source instanceof WC_Order) {
		$duplicate_enabled_source->delete(true);
	}
	wp_delete_post($duplicate_enabled_product->get_id(), true);
	update_option('order_splitter_status_allowed', $duplicate_enabled_allowed_statuses);
	update_option('order_splitter_shop_manager_permission', $duplicate_enabled_manager_permission);
	update_option('woocommerce_manage_stock', $duplicate_enabled_manage_stock);
	wp_set_current_user($duplicate_enabled_previous_user);
	if (!function_exists('wp_delete_user')) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	if (!is_wp_error($duplicate_enabled_admin_id)) {
		wp_delete_user($duplicate_enabled_admin_id);
	}
	if (!is_wp_error($duplicate_enabled_subscriber_id)) {
		wp_delete_user($duplicate_enabled_subscriber_id);
	}
}

echo "p2-production-duplicate-enabled-ok\n";
