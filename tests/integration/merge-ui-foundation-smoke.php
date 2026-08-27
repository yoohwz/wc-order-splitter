<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_merge_ui_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_merge_ui_order($email, $address) {
	$order = wc_create_order();
	$order->set_status('processing');
	$order->set_currency('USD');
	$order->set_billing_first_name('Private');
	$order->set_billing_last_name('Fixture');
	$order->set_billing_email($email);
	$order->set_billing_phone('555-0104');
	$order->set_billing_address_1($address);
	$order->set_payment_method('cod');
	$order->set_payment_method_title('Private payment title');
	$order->save();
	return wc_get_order($order->get_id());
}

$root = dirname(__DIR__, 2);
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_merge_ui_assert(!empty($admins), 'Merge UI smoke requires an administrator fixture.');
wp_set_current_user(absint($admins[0]));

wcos_merge_ui_assert(true === WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE), 'Production Merge gate is not enabled.');
$controller = new WCOS_Merge_Admin_Controller();
wcos_merge_ui_assert(true === $controller->register_hooks(), 'Enabled Merge UI controller did not register hooks.');

$hook_contracts = array(
	'wp_ajax_' . WCOS_Merge_Admin_Controller::SEARCH_ACTION => 'ajax_search',
	'wp_ajax_' . WCOS_Merge_Admin_Controller::REVIEW_ACTION => 'ajax_review',
	'wp_ajax_' . WCOS_Merge_Admin_Controller::CONFIRM_ACTION => 'ajax_confirm',
	'wp_ajax_' . WCOS_Merge_Admin_Controller::EXECUTE_ACTION => 'ajax_execute',
	'admin_footer' => 'render_dialog',
	'admin_enqueue_scripts' => 'enqueue_assets',
);
foreach ($hook_contracts as $hook => $method) {
	wcos_merge_ui_assert(false !== has_action($hook, array($controller, $method)), 'Enabled Merge hook is missing: ' . $hook);
}
wcos_merge_ui_assert(false === has_action('woocommerce_order_item_add_action_buttons', array($controller, 'render_launcher')), 'Merge launcher remained registered in the Order items action area.');

wp_dequeue_script('wcos-merge-admin');
wp_dequeue_style('wcos-merge-admin');
$controller->enqueue_assets();
wcos_merge_ui_assert(!wp_script_is('wcos-merge-admin', 'enqueued'), 'Merge script was enqueued outside an order edit screen.');
wcos_merge_ui_assert(!wp_style_is('wcos-merge-admin', 'enqueued'), 'Merge stylesheet was enqueued outside an order edit screen.');

$old_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
update_option('order_splitter_status_allowed', array('wc-processing'));
$source = null;
$targets = array();

try {
	$email = 'merge-ui-private-' . wp_generate_uuid4() . '@example.test';
	$address = '46 Private Search Lane';
	$source = wcos_merge_ui_order($email, $address);
	$targets[] = wcos_merge_ui_order('merge-ui-target-' . wp_generate_uuid4() . '@example.test', 'Target Secret Street');
	$targets[0]->set_date_created(time() - DAY_IN_SECONDS);
	$targets[0]->save();
	for ($index = 0; $index <= WCOS_Merge_Admin_Controller::SEARCH_SCAN_LIMIT; $index++) {
		$targets[] = wcos_merge_ui_order('merge-ui-newer-' . $index . '-' . wp_generate_uuid4() . '@example.test', 'Newer Private Street');
	}

	ob_start();
	$controller->render_launcher($source);
	$enabled_launcher = (string) ob_get_clean();
	wcos_merge_ui_assert(false !== strpos($enabled_launcher, '>Merge</button>'), 'Enabled Merge launcher did not use its compact Order actions label.');
	wcos_merge_ui_assert(false === strpos($enabled_launcher, $email) && false === strpos($enabled_launcher, $address), 'Enabled Merge launcher exposed customer PII.');

	$order_screen_id = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
	set_current_screen($order_screen_id);
	$_GET['id'] = (string) $source->get_id();
	$controller->enqueue_assets();
	wcos_merge_ui_assert(wp_script_is('wcos-merge-admin', 'enqueued'), 'Enabled Merge script was not enqueued on the order edit screen.');
	wcos_merge_ui_assert(wp_style_is('wcos-merge-admin', 'enqueued'), 'Enabled Merge stylesheet was not enqueued on the order edit screen.');

	$template = $controller->dialog_html($source);
	wcos_merge_ui_assert(false !== strpos($template, 'wcos-merge-target-select'), 'Merge target selector template is missing.');
	wcos_merge_ui_assert(false !== strpos($template, 'wcos-merge-confirm-checkbox'), 'Merge acknowledgement template is missing.');
	wcos_merge_ui_assert(false !== strpos($template, 'aria-live="polite"'), 'Merge modal status is not announced.');
	wcos_merge_ui_assert(false !== strpos($template, 'non_force_trash_archive'), 'Approved retirement warning is missing.');
	wcos_merge_ui_assert(false !== strpos($template, 'data-source-order-id="' . $source->get_id() . '"'), 'Current order is not frozen as the Merge source.');
	wcos_merge_ui_assert(false === strpos($template, $email), 'Merge template exposed customer email.');
	wcos_merge_ui_assert(false === strpos($template, $address), 'Merge template exposed customer address.');
	wcos_merge_ui_assert(false === strpos($template, '555-0104'), 'Merge template exposed customer phone.');
	wcos_merge_ui_assert(false === strpos($template, 'Private payment title'), 'Merge template exposed payment identity.');

	$request = array(
		'source_order_id' => $source->get_id(),
		'nonce' => wp_create_nonce('wcos_merge_order_' . $source->get_id()),
		'term' => (string) $targets[0]->get_id(),
		'page' => 1,
	);
	$browse = $controller->search_request(array_merge($request, array('term' => '')));
	$old_target_in_browse = false;
	foreach ($browse['results'] as $result) {
		if ($targets[0]->get_id() === (int) $result['id']) {
			$old_target_in_browse = true;
		}
	}
	wcos_merge_ui_assert(!$old_target_in_browse, 'Old-target fixture was not outside the bounded recent browse window.');
	wcos_merge_ui_assert(count($browse['results']) <= WCOS_Merge_Admin_Controller::SEARCH_LIMIT, 'Target browse exceeded its result limit.');

	$search = $controller->search_request($request);
	$found_target = null;
	foreach ($search['results'] as $result) {
		if ($targets[0]->get_id() === (int) $result['id']) {
			$found_target = $result;
			break;
		}
	}
	wcos_merge_ui_assert(is_array($found_target), 'Bounded target search did not find the exact target identity.');
	wcos_merge_ui_assert(1 === count($search['results']) && false === $search['more'], 'Exact old-target search was not bounded to one result.');
	wcos_merge_ui_assert(array('id', 'number', 'status', 'currency') === array_keys($found_target), 'Target search returned fields outside the PII-free selector contract.');
	$search_json = wp_json_encode($search);
	wcos_merge_ui_assert(false === strpos($search_json, '@example.test'), 'Target search exposed customer email.');
	wcos_merge_ui_assert(false === strpos($search_json, 'Secret Street'), 'Target search exposed customer address.');
	wcos_merge_ui_assert(false === strpos($search_json, 'Private payment'), 'Target search exposed payment identity.');
	wcos_merge_ui_assert(false === strpos($search_json, '"id":' . $source->get_id()), 'Target search included the current source order.');

	$hash_search = $controller->search_request(array_merge($request, array('term' => '#' . $targets[0]->get_id())));
	wcos_merge_ui_assert(1 === count($hash_search['results']) && $targets[0]->get_id() === (int) $hash_search['results'][0]['id'], 'Hash-prefixed exact old-target ID did not resolve.');
	$source_search = $controller->search_request(array_merge($request, array('term' => (string) $source->get_id())));
	wcos_merge_ui_assert(empty($source_search['results']), 'Exact target search did not exclude the source order.');

	$bad_nonce = $request;
	$bad_nonce['nonce'] = 'invalid';
	$nonce_rejected = false;
	try {
		$controller->search_request($bad_nonce);
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$nonce_rejected = 'invalid_nonce' === $exception->get_error_code();
	}
	wcos_merge_ui_assert($nonce_rejected, 'Target search accepted an invalid nonce.');

	$subscriber_id = wp_create_user('wcos-merge-ui-' . wp_generate_uuid4(), wp_generate_password(24), 'merge-ui-subscriber-' . wp_generate_uuid4() . '@example.test');
	wcos_merge_ui_assert(!is_wp_error($subscriber_id), 'Unable to create target-search capability fixture.');
	wp_set_current_user($subscriber_id);
	$capability_request = $request;
	$capability_request['nonce'] = wp_create_nonce('wcos_merge_order_' . $source->get_id());
	$capability_rejected = false;
	try {
		$controller->search_request($capability_request);
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$capability_rejected = 'authorization_failed' === $exception->get_error_code();
	}
	wp_set_current_user(absint($admins[0]));
	if (!function_exists('wp_delete_user')) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	wp_delete_user($subscriber_id);
	wcos_merge_ui_assert($capability_rejected, 'Target search accepted an operator without order mutation capability.');

	$excessive = $request;
	$excessive['term'] = str_repeat('x', WCOS_Merge_Admin_Controller::SEARCH_MAX_TERM_LENGTH + 1);
	$excessive_rejected = false;
	try {
		$controller->search_request($excessive);
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$excessive_rejected = 'invalid_search' === $exception->get_error_code();
	}
	wcos_merge_ui_assert($excessive_rejected, 'Target search accepted an excessive query.');

	$client = file_get_contents($root . '/js/p2-merge-admin.js');
	wcos_merge_ui_assert(is_string($client) && '' !== $client, 'Merge UI client asset is missing.');
	foreach (array(
		'window.WCOSBackboneModal.open',
		'.selectWoo({',
		'var reviewAuthority = null;',
		'var confirmationAuthority = null;',
		'function invalidateReview()',
		"$(targetSelect).on('change'",
		'if (confirmationAuthority) {',
		'function executeSameOperation()',
		'operation_id: confirmationAuthority.operationId',
		'confirmation_token: confirmationAuthority.token',
		'review_id: reviewAuthority.reviewId',
		'review_token: reviewAuthority.token',
		'if (!error.retryable || !confirmationAuthority)',
		'confirmCheckbox.checked',
		'data.status !== \'completed\'',
	) as $needle) {
		wcos_merge_ui_assert(false !== strpos($client, $needle), 'Merge client state contract is missing: ' . $needle);
	}
	wcos_merge_ui_assert(1 === substr_count($client, "getAttribute('data-confirm-action')"), 'Merge retry path can issue more than one Confirm request site.');
	foreach (array('localStorage', 'sessionStorage', 'document.cookie', '.innerHTML') as $forbidden) {
		wcos_merge_ui_assert(false === strpos($client, $forbidden), 'Merge client uses forbidden state/rendering authority: ' . $forbidden);
	}
	foreach (array('plan:', 'tax:', 'line:', 'policy:', 'precision:', 'fingerprint:', 'total:') as $forbidden_payload) {
		wcos_merge_ui_assert(false === strpos($client, $forbidden_payload), 'Merge client authors forbidden request authority: ' . $forbidden_payload);
	}

	$controller_source = file_get_contents($root . '/inc/backend/class-wcos-merge-admin-controller.php');
	wcos_merge_ui_assert(false !== strpos($controller_source, 'WCOS_Order_Mutation_Authorizer::assert_merge_source($order);'), 'Launcher does not use bounded source-only authorization.');
	wcos_merge_ui_assert(false === strpos(substr($controller_source, strpos($controller_source, 'public function render_launcher'), 1500), 'merge_preflight'), 'Launcher performs pair preflight before target selection.');
	wcos_merge_ui_assert(false === strpos($controller_source, 'new WCOS_Merge_Order_Service'), 'Merge controller directly instantiates the Merge service.');
	wcos_merge_ui_assert(false === strpos($controller_source, 'new WCOS_Merge_WooCommerce_Adapter'), 'Merge controller directly instantiates the Merge adapter.');

	$asset_contract = file_get_contents($root . '/inc/backend/class-wcos-admin-backbone-modal-assets.php');
	wcos_merge_ui_assert(false !== strpos($asset_contract, "'wcos-merge-admin'"), 'Merge client is not bound to the shared Backbone modal dependency.');
	$css = file_get_contents($root . '/css/p2-merge-admin.css');
	wcos_merge_ui_assert(is_string($css) && false !== strpos($css, '.wcos-merge-backbone-modal .wc-backbone-modal-content'), 'Merge CSS does not target the shared WooCommerce Backbone shell.');
} finally {
	if ($source instanceof WC_Order) {
		$source->delete(true);
	}
	foreach ($targets as $target) {
		if ($target instanceof WC_Order) {
			$target->delete(true);
		}
	}
	update_option('order_splitter_status_allowed', $old_statuses);
}

echo "merge-ui-foundation-ok\n";
