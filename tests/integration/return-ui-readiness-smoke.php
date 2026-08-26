<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_return_ui_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$root = dirname(__DIR__, 2);
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_return_ui_assert(!empty($admins), 'Return UI readiness requires an administrator fixture.');
wp_set_current_user(absint($admins[0]));

wcos_return_ui_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER), 'Production Return gate is not enabled.');
wcos_return_ui_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN), 'Bulk Return gate drifted on.');

$controller = new WCOS_Return_Admin_Controller();
$hook_contracts = array(
	'wp_ajax_' . WCOS_Return_Admin_Controller::REVIEW_ACTION => 'ajax_review',
	'wp_ajax_' . WCOS_Return_Admin_Controller::CONFIRM_ACTION => 'ajax_confirm',
	'wp_ajax_' . WCOS_Return_Admin_Controller::EXECUTE_ACTION => 'ajax_execute',
	'woocommerce_order_item_add_action_buttons' => 'render_launcher',
	'admin_footer' => 'render_dialog',
	'admin_enqueue_scripts' => 'enqueue_assets',
);
foreach ($hook_contracts as $hook => $method) {
	wcos_return_ui_assert(false !== has_action($hook), 'Enabled Return hook is missing: ' . $hook);
}

$order = wc_create_order();
$order->set_status('pending');
$order->set_currency('USD');
$order->set_billing_first_name('Private');
$order->set_billing_last_name('Return UI');
$order->set_billing_email('return-ui-' . wp_generate_uuid4() . '@example.test');
$order->set_billing_phone('555-0060');
$order->set_billing_address_1('60 Private Return Street');
$order->set_payment_method('cod');
$order->set_payment_method_title('Private Return Payment');
$order->save();

try {
	$template = $controller->dialog_html($order);
	wcos_return_ui_assert(false !== strpos($template, 'role="dialog"'), 'Return source template is not a dialog.');
	wcos_return_ui_assert(false !== strpos($template, 'aria-modal="true"'), 'Return source template is not modal.');
	wcos_return_ui_assert(false !== strpos($template, 'aria-labelledby="wcos-return-dialog-' . $order->get_id() . '-title"'), 'Return source template is not title-labelled.');
	wcos_return_ui_assert(false !== strpos($template, 'aria-describedby="wcos-return-dialog-' . $order->get_id() . '-description"'), 'Return source template is not description-labelled.');
	wcos_return_ui_assert(false !== strpos($template, 'data-child-order-id="' . $order->get_id() . '"'), 'Return source template does not freeze current child identity.');
	wcos_return_ui_assert(false !== strpos($template, 'wcos-return-review-summary'), 'Return Review summary surface is missing.');
	wcos_return_ui_assert(false !== strpos($template, 'wcos-return-confirm-checkbox'), 'Return explicit acknowledgement is missing.');
	wcos_return_ui_assert(false !== strpos($template, 'aria-live="polite"'), 'Return modal-local status is not announced.');
	wcos_return_ui_assert(false !== strpos($template, 'role="alert"'), 'Return modal-local errors lack alert semantics.');
	wcos_return_ui_assert(false !== strpos($template, 'non_force_trash_archive'), 'Return child retirement policy is not explicit.');
	wcos_return_ui_assert(false !== strpos($template, 'physical-stock neutral'), 'Return physical-stock neutrality is not explicit.');
	wcos_return_ui_assert(false === strpos($template, 'data-original'), 'Return template exposed client-selectable original authority.');
	foreach (array('@example.test', 'Private Return Street', '555-0060', 'Private Return Payment') as $private_value) {
		wcos_return_ui_assert(false === strpos($template, $private_value), 'Return template exposed customer/payment PII: ' . $private_value);
	}

	ob_start();
	$controller->render_launcher($order);
	$launcher = (string) ob_get_clean();
	wcos_return_ui_assert('' === $launcher, 'Ineligible non-Split order exposed a Return launcher.');

	$order_screen_id = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
	set_current_screen($order_screen_id);
	$_GET['id'] = (string) $order->get_id();
	WCOS_Admin_Backbone_Modal_Assets::enqueue();
	$controller->enqueue_assets();
	WCOS_Admin_Backbone_Modal_Assets::bind_workflow_dependencies();
	wcos_return_ui_assert(wp_script_is('wcos-return-admin', 'enqueued'), 'Enabled Return script was not enqueued on an order screen.');
	wcos_return_ui_assert(wp_style_is('wcos-return-admin', 'enqueued'), 'Enabled Return stylesheet was not enqueued on an order screen.');
	wcos_return_ui_assert(wp_script_is('wcos-admin-backbone-modal', 'enqueued'), 'Shared Backbone modal bridge was not enqueued with Return.');
	$registered_scripts = wp_scripts();
	$return_dependencies = isset($registered_scripts->registered['wcos-return-admin']) ? (array) $registered_scripts->registered['wcos-return-admin']->deps : array();
	wcos_return_ui_assert(in_array('wcos-admin-backbone-modal', $return_dependencies, true), 'Return client is not bound to the shared Backbone modal dependency at runtime.');
	$return_script_data = isset($registered_scripts->registered['wcos-return-admin']->extra['data']) ? (string) $registered_scripts->registered['wcos-return-admin']->extra['data'] : '';
	foreach (array('@example.test', 'Private Return Street', '555-0060', 'Private Return Payment') as $private_value) {
		wcos_return_ui_assert(false === strpos($return_script_data, $private_value), 'Return localized script data exposed customer/payment PII: ' . $private_value);
	}

	$client = file_get_contents($root . '/js/p2-return-admin.js');
	wcos_return_ui_assert(is_string($client) && '' !== $client, 'Return admin client is missing.');
	foreach (array(
		'window.WCOSBackboneModal.open',
		"var phase = 'initial';",
		'var reviewAuthority = null;',
		'var confirmationAuthority = null;',
		'function reviewReturn()',
		'function confirmReturn()',
		'function executeSameOperation()',
		'function executeReturn()',
		'review_id: reviewAuthority.reviewId',
		'review_token: reviewAuthority.token',
		'operation_id: confirmationAuthority.operationId',
		'confirmation_token: confirmationAuthority.token',
		"setPhase('confirmed');",
		"setPhase('completed');",
		"setPhase('closed');",
		'if (error.retryable && confirmationAuthority)',
		"data.status !== 'completed'",
		"if ('Escape' === event.key && busy)",
		"content.setAttribute('role', 'dialog');",
		"content.setAttribute('aria-modal', 'true');",
		"content.setAttribute('aria-labelledby', title.id);",
		"content.setAttribute('aria-describedby', description.id);",
	) as $needle) {
		wcos_return_ui_assert(false !== strpos($client, $needle), 'Return client state/accessibility contract is missing: ' . $needle);
	}
	wcos_return_ui_assert(1 === substr_count($client, "getAttribute('data-review-action')"), 'Return client has more than one Review request site.');
	wcos_return_ui_assert(1 === substr_count($client, "getAttribute('data-confirm-action')"), 'Return client has more than one Confirm request site.');
	wcos_return_ui_assert(1 === substr_count($client, "getAttribute('data-execute-action')"), 'Return client has more than one Execute request site.');
	foreach (array('original_order_id:', 'source_order_id:', 'plan:', 'amount:', 'tax:', 'precision:', 'fingerprint:', 'retirement_policy:', 'stock:', 'line:', 'localStorage', 'sessionStorage', 'document.cookie', '.innerHTML', 'console.') as $forbidden) {
		wcos_return_ui_assert(false === strpos($client, $forbidden), 'Return client authors or stores forbidden authority: ' . $forbidden);
	}

	$controller_source = file_get_contents($root . '/inc/backend/class-wcos-return-admin-controller.php');
	wcos_return_ui_assert(false === strpos($controller_source, 'new WCOS_Return_Order_Service'), 'Return controller directly instantiates the Return service.');
	wcos_return_ui_assert(false !== strpos($controller_source, 'new WCOS_Mutation_Gateway())->return_order('), 'Return Execute does not enter through the mutation gateway.');
	wcos_return_ui_assert(false !== strpos($controller_source, "add_action('woocommerce_order_item_add_action_buttons', array(\$this, 'render_launcher'), 23, 1);"), 'Return launcher hook is missing from the gated controller.');
	wcos_return_ui_assert(false !== strpos($controller_source, 'WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::RETURN_ORDER, $order, $original);'), 'Return launcher does not use server-resolved pair authorization.');

	$asset_contract = file_get_contents($root . '/inc/backend/class-wcos-admin-backbone-modal-assets.php');
	wcos_return_ui_assert(false !== strpos($asset_contract, "'wcos-return-admin'"), 'Return client is not bound to the shared Backbone modal dependency.');
	$css = file_get_contents($root . '/css/p2-return-admin.css');
	wcos_return_ui_assert(is_string($css) && false !== strpos($css, '.wcos-return-backbone-modal .wc-backbone-modal-content'), 'Return CSS does not target the shared WooCommerce Backbone shell.');

	foreach (array(
		$root . '/inc/backend/actions/return-order.php',
		$root . '/inc/backend/actions/return-order-bulk-action.php',
		$root . '/inc/backend/orders-bulk-return.php',
		$root . '/js/bulk-return-action.js',
	) as $legacy_path) {
		wcos_return_ui_assert(!file_exists($legacy_path) || false === strpos(file_get_contents($root . '/inc/cores/script.php'), basename($legacy_path)), 'Legacy Return runtime path was reintroduced: ' . basename($legacy_path));
	}
} finally {
	$order->delete(true);
}

echo "return-ui-readiness-ok production_enabled=1 ineligible_launcher=hidden assets=enabled shared_modal=1 pii_free=1 client_authority=bounded\n";
