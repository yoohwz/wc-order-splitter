<?php

if (!defined('ABSPATH')) {
	exit(1);
}

wcos_p2_adapter_assert(method_exists('WCOS_Split_Strategy_Admin_Controller', 'bootstrap'), 'Strategy UI gate-aware bootstrap is missing.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION), 'Strategy Review route is registered in the real hard-off release state.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION), 'Strategy Confirm route is registered in the real hard-off release state.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION), 'Strategy Execute route is registered in the real hard-off release state.');
wcos_p2_adapter_assert(null === WCOS_Split_Strategy_Admin_Controller::bootstrap(), 'Hard-off strategy UI bootstrap returned an active controller.');

$ui_previous_user = get_current_user_id();
$ui_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$ui_admin_id = wp_insert_user(array(
	'user_login' => 'wcos_strategy_ui_admin_' . wp_generate_password(8, false),
	'user_pass' => wp_generate_password(24, true),
	'user_email' => 'wcos-strategy-ui-' . wp_generate_uuid4() . '@example.test',
	'role' => 'administrator',
));
wcos_p2_adapter_assert(!is_wp_error($ui_admin_id), 'Unable to create strategy UI administrator.');

$ui_product_a = wcos_p2_adapter_product('WCOS Strategy UI A', '10.00');
$ui_product_b = wcos_p2_adapter_product('WCOS Strategy UI B', '7.00');
$ui_order = wc_create_order();
$ui_order->set_status('pending');
$ui_order->set_currency('USD');
$ui_order->add_product($ui_product_a, 1);
$ui_order->add_product($ui_product_b, 1);
$ui_order->calculate_totals(false);
$ui_order->save();
$ui_order_id = $ui_order->get_id();

$ui_strategy_reflection = new ReflectionClass('WCOS_Split_Strategy_Gates');
$ui_states_property = $ui_strategy_reflection->getProperty('states');
$ui_states_property->setAccessible(true);
$ui_release_states = $ui_states_property->getValue();
$ui_controller = null;

try {
	$ui_test_states = $ui_release_states;
	$ui_test_states[WCOS_Split_Strategy_Gates::CATEGORY] = true;
	$ui_test_states[WCOS_Split_Strategy_Gates::STOCK_STATUS] = true;
	$ui_states_property->setValue(null, $ui_test_states);
	update_option('order_splitter_status_allowed', array('wc-pending'));
	wp_set_current_user($ui_admin_id);

	$ui_controller = WCOS_Split_Strategy_Admin_Controller::bootstrap();
	wcos_p2_adapter_assert($ui_controller instanceof WCOS_Split_Strategy_Admin_Controller, 'Enabled test-only strategy UI did not bootstrap.');
	wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION, array($ui_controller, 'ajax_review')), 'Enabled strategy UI did not register Review AJAX.');
	wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION, array($ui_controller, 'ajax_confirm')), 'Enabled strategy UI did not register Confirm AJAX.');
	wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION, array($ui_controller, 'ajax_execute')), 'Enabled strategy UI did not register Execute AJAX.');
	wcos_p2_adapter_assert(false !== has_action('woocommerce_order_item_add_action_buttons', array($ui_controller, 'render_launcher')), 'Enabled strategy UI did not register its order launcher callback.');

	ob_start();
	$ui_controller->render_launcher(wc_get_order($ui_order_id));
	$launcher_html = (string) ob_get_clean();
	wcos_p2_adapter_assert(false !== strpos($launcher_html, 'wcos-strategy-launcher'), 'Strategy launcher markup is missing.');
	wcos_p2_adapter_assert(false !== strpos($launcher_html, 'Split by category'), 'Category strategy launcher is missing.');
	wcos_p2_adapter_assert(false !== strpos($launcher_html, 'Split by stock status'), 'Stock-status strategy launcher is missing.');
	wcos_p2_adapter_assert(false !== strpos($launcher_html, 'aria-haspopup="dialog"'), 'Strategy launchers do not expose dialog semantics.');
	wcos_p2_adapter_assert(false !== strpos($launcher_html, 'aria-controls="wcos-strategy-dialog-' . $ui_order_id . '-category"'), 'Category launcher is not bound to its dialog.');
	wcos_p2_adapter_assert(false !== strpos($launcher_html, 'aria-controls="wcos-strategy-dialog-' . $ui_order_id . '-stock_status"'), 'Stock-status launcher is not bound to its dialog.');

	foreach (array(WCOS_Split_Strategy_Gates::CATEGORY, WCOS_Split_Strategy_Gates::STOCK_STATUS) as $ui_strategy) {
		$dialog_html = $ui_controller->dialog_html(wc_get_order($ui_order_id), $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'role="dialog"'), 'Strategy dialog is missing role=dialog: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'aria-modal="true"'), 'Strategy dialog is missing aria-modal: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'aria-labelledby='), 'Strategy dialog is missing aria-labelledby: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'aria-describedby='), 'Strategy dialog is missing aria-describedby: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, '<fieldset class="wcos-strategy-buckets"'), 'Strategy dialog is missing semantic source-bucket fieldset: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, '<legend'), 'Strategy dialog is missing bucket legend: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'role="status" aria-live="polite"'), 'Strategy dialog is missing live status region: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'role="alert" tabindex="-1"'), 'Strategy dialog is missing focusable alert region: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'wcos-strategy-confirm-checkbox'), 'Strategy dialog is missing explicit execution acknowledgement: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'data-review-action="' . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION . '"'), 'Strategy dialog is missing server Review route authority: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'data-confirm-action="' . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION . '"'), 'Strategy dialog is missing server Confirm route authority: ' . $ui_strategy);
		wcos_p2_adapter_assert(false !== strpos($dialog_html, 'data-execute-action="' . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION . '"'), 'Strategy dialog is missing server Execute route authority: ' . $ui_strategy);
	}

	$js_path = dirname(__DIR__, 2) . '/js/p2-split-strategy-admin.js';
	$js = file_get_contents($js_path);
	wcos_p2_adapter_assert(is_string($js) && '' !== $js, 'Unable to read strategy admin client script.');
	foreach (array(
		'var reviewState = null;',
		'var confirmationState = null;',
		'var busy = false;',
		'var completed = false;',
		"body.set('action', action);",
		'document.createElement',
		'title.textContent =',
		'confirmationState = {',
		'reviewState = null;',
		'completed = true;',
		"event.key === 'Escape'",
		"event.key !== 'Tab'",
		'returnFocus.focus()',
		'field.disabled = busy || completed || !!confirmationState;',
		"dialog.querySelectorAll('input, button')",
		"typeof error.retryable !== 'boolean'",
		'error.retryable = true;',
	) as $needle) {
		wcos_p2_adapter_assert(false !== strpos($js, $needle), 'Strategy client state/accessibility contract is missing: ' . $needle);
	}
	wcos_p2_adapter_assert(false === strpos($js, '.innerHTML'), 'Strategy client uses innerHTML for server-provided display data.');
	wcos_p2_adapter_assert(false === strpos($js, 'window.alert'), 'Strategy client uses blocking window.alert().');
	wcos_p2_adapter_assert(false === strpos($js, 'JSON.stringify(plan'), 'Strategy client constructs or sends a client-authored mutation plan.');
	wcos_p2_adapter_assert(false === strpos($js, 'classification_fingerprint'), 'Strategy client treats classification fingerprint as client authority.');

	$css_path = dirname(__DIR__, 2) . '/css/p2-split-strategy-admin.css';
	$css = file_get_contents($css_path);
	wcos_p2_adapter_assert(is_string($css) && false !== strpos($css, '@media (max-width: 782px)'), 'Strategy UI is missing responsive admin styling.');
	wcos_p2_adapter_assert(false !== strpos($css, 'min-height: 44px'), 'Strategy UI is missing mobile-sized action targets.');
} finally {
	if ($ui_controller instanceof WCOS_Split_Strategy_Admin_Controller) {
		$ui_controller->unregister_hooks();
	}
	$ui_states_property->setValue(null, $ui_release_states);
	wp_set_current_user($ui_previous_user);
	update_option('order_splitter_status_allowed', $ui_allowed_statuses);
	$cleanup_ui_order = wc_get_order($ui_order_id);
	if ($cleanup_ui_order instanceof WC_Order) {
		$cleanup_ui_order->delete(true);
	}
	wp_delete_post($ui_product_a->get_id(), true);
	wp_delete_post($ui_product_b->get_id(), true);
	if (function_exists('wp_delete_user')) {
		wp_delete_user($ui_admin_id);
	}
}

wcos_p2_adapter_assert(!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY), 'Category gate was not restored after strategy UI readiness acceptance.');
wcos_p2_adapter_assert(!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS), 'Stock-status gate was not restored after strategy UI readiness acceptance.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION), 'Strategy Review AJAX remained registered after test-only UI scope.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION), 'Strategy Confirm AJAX remained registered after test-only UI scope.');
wcos_p2_adapter_assert(false === has_action('wp_ajax_' . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION), 'Strategy Execute AJAX remained registered after test-only UI scope.');

echo "p2-strategy-ui-readiness-ok\n";
