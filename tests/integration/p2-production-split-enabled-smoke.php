<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_p2_enabled_expect_transport($code, $http_status, callable $callback, $message) {
	try {
		$callback();
	} catch (WCOS_Split_Transport_Exception $exception) {
		wcos_p2_adapter_assert($code === $exception->get_error_code(), $message . ' Wrong code: ' . $exception->get_error_code());
		wcos_p2_adapter_assert($http_status === $exception->get_http_status(), $message . ' Wrong HTTP status.');
		return $exception;
	}
	throw new RuntimeException($message);
}

wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT), 'Manual quantity Split is not production-enabled in the enablement contract.');
wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE), 'Production Merge gate was lost while validating Split.');
wcos_p2_adapter_assert(WCOS_Feature_Gates::any_enabled(), 'Production gate set was reported as fully disabled.');
wcos_p2_adapter_assert(WC_Order_Splitter_Safety_Guard::mutations_enabled(), 'Safety guard did not reflect the approved Split gate.');
foreach (array(
	WCOS_Feature_Gates::RETURN_ORDER,
	WCOS_Feature_Gates::BULK_RETURN,
) as $disabled_workflow) {
	wcos_p2_adapter_assert(!WCOS_Feature_Gates::enabled($disabled_workflow), 'An unapproved mutation workflow is enabled alongside Split.');
}

$enabled_previous_user = get_current_user_id();
$enabled_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$enabled_manager_permission = get_option('order_splitter_shop_manager_permission', 'no');
$enabled_manage_stock = get_option('woocommerce_manage_stock', 'yes');
$enabled_operation = '';

$enabled_admin_id = wp_insert_user(array(
	'user_login' => 'wcos_p2_enabled_admin_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-p2-enabled-admin-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
$enabled_subscriber_id = wp_insert_user(array(
	'user_login' => 'wcos_p2_enabled_subscriber_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-p2-enabled-subscriber-' . wp_generate_uuid4() . '@example.test',
	'role' => 'subscriber',
));
wcos_p2_adapter_assert(!is_wp_error($enabled_admin_id) && !is_wp_error($enabled_subscriber_id), 'Unable to create production Split transport users.');

$enabled_product = wcos_p2_adapter_product('WCOS P2 production Split enabled', '12.00', 20);
list($enabled_source, $enabled_item_id) = wcos_p2_adapter_order($enabled_product, 4, 'pending');
$enabled_source_id = $enabled_source->get_id();
$enabled_source->set_billing_first_name('ProductionPrivateProbe');
$enabled_source->set_billing_email('production-private-probe@example.test');
$enabled_source->save();
$enabled_stock_before = wc_get_product($enabled_product->get_id())->get_stock_quantity();
$enabled_controller = new WCOS_Split_Admin_Controller();

try {
	update_option('woocommerce_manage_stock', 'yes');
	update_option('order_splitter_status_allowed', array('wc-pending'));
	update_option('order_splitter_shop_manager_permission', 'no');
	wp_set_current_user($enabled_admin_id);

	wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Admin_Controller::REVIEW_ACTION), 'Production Split review AJAX route is not registered.');
	wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Admin_Controller::EXECUTE_ACTION), 'Production Split execute AJAX route is not registered.');

	$enabled_source = wc_get_order($enabled_source_id);
	$enabled_nonce = wp_create_nonce('wcos_split_order_' . $enabled_source_id);
	$enabled_plan_json = wp_json_encode(array(
		'child-1' => array((string) $enabled_item_id => '1.000000'),
		'child-2' => array((string) $enabled_item_id => '1.000000'),
	));

	wcos_p2_enabled_expect_transport(
		'invalid_nonce',
		403,
		static function() use ($enabled_controller, $enabled_source_id, $enabled_plan_json) {
			$enabled_controller->review_request(array(
				'order_id' => $enabled_source_id,
				'nonce' => 'invalid',
				'plan' => $enabled_plan_json,
			));
		},
		'Production review accepted an invalid nonce.'
	);

	wp_set_current_user($enabled_subscriber_id);
	$subscriber_nonce = wp_create_nonce('wcos_split_order_' . $enabled_source_id);
	wcos_p2_enabled_expect_transport(
		'authorization_failed',
		403,
		static function() use ($enabled_controller, $enabled_source_id, $subscriber_nonce, $enabled_plan_json) {
			$enabled_controller->review_request(array(
				'order_id' => $enabled_source_id,
				'nonce' => $subscriber_nonce,
				'plan' => $enabled_plan_json,
			));
		},
		'Production review accepted a subscriber.'
	);

	wp_set_current_user($enabled_admin_id);
	$enabled_nonce = wp_create_nonce('wcos_split_order_' . $enabled_source_id);
	$source_signature_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($enabled_source_id));

	ob_start();
	$enabled_controller->render_launcher(wc_get_order($enabled_source_id));
	$launcher_html = ob_get_clean();
	wcos_p2_adapter_assert(false !== strpos($launcher_html, 'wcos-split-launcher'), 'Production Split launcher did not render after gate enablement.');
	wcos_p2_adapter_assert(false !== strpos($launcher_html, 'aria-controls="wcos-split-dialog-'), 'Production Split launcher is not bound to its dialog.');

	$preflight = (new WCOS_Mutation_Gateway())->split_preflight(wc_get_order($enabled_source_id));
	wcos_p2_adapter_assert(!empty($preflight['supported']), 'Production Gateway preflight rejected the valid source.');
	$dialog_html = $enabled_controller->dialog_html(wc_get_order($enabled_source_id), $preflight);
	wcos_p2_adapter_assert(false !== strpos($dialog_html, 'role="dialog"'), 'Production Split dialog lost accessible dialog semantics.');
	wcos_p2_adapter_assert(false === strpos($dialog_html, 'ProductionPrivateProbe'), 'Production Split dialog leaked billing PII.');
	wcos_p2_adapter_assert(false === strpos($dialog_html, 'production-private-probe@example.test'), 'Production Split dialog leaked billing email PII.');

	$review = $enabled_controller->review_request(array(
		'order_id' => $enabled_source_id,
		'nonce' => $enabled_nonce,
		'plan' => $enabled_plan_json,
	));
	$enabled_operation = $review['operation_id'];
	wcos_p2_adapter_assert(!empty($review['preflight']['supported']), 'Production review did not return a supported preflight.');
	wcos_p2_adapter_assert(2 === (int) $review['summary']['child_count'], 'Production review lost the two-child plan.');
	wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($enabled_source_id), $enabled_operation), 'Read-only production review created a journal before confirmation.');
	wcos_p2_adapter_assert($source_signature_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($enabled_source_id)), 'Read-only production review changed the source order.');

	wcos_p2_enabled_expect_transport(
		'confirmation_invalid_token',
		403,
		static function() use ($enabled_controller, $enabled_source_id, $enabled_nonce, $review) {
			$enabled_controller->execute_request(array(
				'order_id' => $enabled_source_id,
				'nonce' => $enabled_nonce,
				'operation_id' => $review['operation_id'],
				'confirmation_token' => 'invalid-confirmation-token',
			));
		},
		'Production execute accepted an invalid confirmation token.'
	);

	$result = $enabled_controller->execute_request(array(
		'order_id' => $enabled_source_id,
		'nonce' => $enabled_nonce,
		'operation_id' => $review['operation_id'],
		'confirmation_token' => $review['confirmation_token'],
	));
	wcos_p2_adapter_assert('completed' === $result['status'], 'Production controller did not report completed Split.');
	wcos_p2_adapter_assert(2 === count($result['children']), 'Production controller did not return exactly two children.');

	$enabled_source = wc_get_order($enabled_source_id);
	$source_item = $enabled_source->get_item($enabled_item_id);
	wcos_p2_adapter_assert(
		WCOS_Decimal::to_units('2.000000', 6) === WCOS_Decimal::to_units($source_item->get_quantity(), 6),
		'Production Split did not leave the expected source residual quantity.'
	);
	$children = wcos_p2_adapter_children($enabled_source_id, $enabled_operation);
	wcos_p2_adapter_assert(2 === count($children), 'Production relation repository did not find exactly two children.');
	$child_ids = array();
	foreach ($children as $child) {
		$child_ids[] = $child->get_id();
		wcos_p2_adapter_assert('pending' === $child->get_status(), 'Production Split child did not remain pending.');
		$child_items = array_values($child->get_items('line_item'));
		wcos_p2_adapter_assert(1 === count($child_items), 'Production Split child has an unexpected line count.');
		wcos_p2_adapter_assert(
			WCOS_Decimal::to_units('1.000000', 6) === WCOS_Decimal::to_units($child_items[0]->get_quantity(), 6),
			'Production Split child quantity is incorrect.'
		);
	}
	sort($child_ids, SORT_NUMERIC);
	wcos_p2_adapter_assert(2 === count(array_unique($child_ids)), 'Production Split returned duplicate child identities.');
	wcos_p2_adapter_assert(
		WCOS_Decimal::to_units($enabled_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($enabled_product->get_id())->get_stock_quantity(), 6),
		'Production Split changed physical stock.'
	);

	$journal = WCOS_Operation_Journal::get($enabled_source, $enabled_operation);
	wcos_p2_adapter_assert(is_array($journal) && 'completed' === $journal['status'], 'Production Split journal did not complete.');
	wcos_p2_adapter_assert((int) WCOS_Split_Preflight::POLICY_VERSION === (int) $journal['context']['policy_version'], 'Production Split journal lost its policy-version authority.');
	wcos_p2_adapter_assert(array_key_exists('price_precision', $journal['context']), 'Production Split journal lost its price precision.');

	$retry_result = $enabled_controller->execute_request(array(
		'order_id' => $enabled_source_id,
		'nonce' => $enabled_nonce,
		'operation_id' => $review['operation_id'],
		'confirmation_token' => $review['confirmation_token'],
	));
	$retry_child_ids = array_map('absint', wp_list_pluck($retry_result['children'], 'id'));
	sort($retry_child_ids, SORT_NUMERIC);
	wcos_p2_adapter_assert($child_ids === $retry_child_ids, 'Production controller retry did not return the original child set.');
	wcos_p2_adapter_assert(2 === count(wcos_p2_adapter_children($enabled_source_id, $enabled_operation)), 'Production controller retry created duplicate children.');
	wcos_p2_adapter_assert(
		WCOS_Decimal::to_units($enabled_stock_before, 6) === WCOS_Decimal::to_units(wc_get_product($enabled_product->get_id())->get_stock_quantity(), 6),
		'Production controller retry changed physical stock.'
	);

	wp_clear_scheduled_hook(WCOS_Operation_Journal_Retention::CRON_HOOK);
	WCOS_Operation_Journal_Retention::maybe_schedule();
	wcos_p2_adapter_assert(false !== wp_next_scheduled(WCOS_Operation_Journal_Retention::CRON_HOOK), 'Journal retention was not scheduled after a production workflow was enabled.');
	wp_clear_scheduled_hook(WCOS_Operation_Journal_Retention::CRON_HOOK);
} finally {
	if ($enabled_operation) {
		WCOS_Split_Confirmation_Store::delete($enabled_operation);
		wcos_p2_adapter_cleanup($enabled_source_id, $enabled_operation);
	} else {
		$source = wc_get_order($enabled_source_id);
		if ($source instanceof WC_Order) {
			$source->delete(true);
		}
	}
	wp_delete_post($enabled_product->get_id(), true);
	update_option('order_splitter_status_allowed', $enabled_allowed_statuses);
	update_option('order_splitter_shop_manager_permission', $enabled_manager_permission);
	update_option('woocommerce_manage_stock', $enabled_manage_stock);
	wp_set_current_user($enabled_previous_user);
	if (!function_exists('wp_delete_user')) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	if (!is_wp_error($enabled_admin_id)) {
		wp_delete_user($enabled_admin_id);
	}
	if (!is_wp_error($enabled_subscriber_id)) {
		wp_delete_user($enabled_subscriber_id);
	}
}

echo "p2-production-split-enabled-ok\n";
