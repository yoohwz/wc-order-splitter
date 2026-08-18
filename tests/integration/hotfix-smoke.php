<?php
/**
 * WP-CLI integration smoke test for the fail-closed 1.4.12 hotfix.
 *
 * Usage:
 * wp eval-file tests/integration/hotfix-smoke.php legacy
 * wp eval-file tests/integration/hotfix-smoke.php hpos
 */

defined('ABSPATH') || exit;

/**
 * Fail the integration command when a contract is not satisfied.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 * @return void
 */
function wcos_hotfix_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

/**
 * Return all standard order IDs in the disposable integration environment.
 *
 * @return int[]
 */
function wcos_hotfix_order_ids() {
	$ids = wc_get_orders(
		array(
			'limit'  => -1,
			'return' => 'ids',
			'type'   => 'shop_order',
			'status' => array_keys(wc_get_order_statuses()),
		)
	);
	$ids = array_map('intval', $ids);
	sort($ids, SORT_NUMERIC);

	return $ids;
}

$storage_mode = isset($args[0]) ? sanitize_key($args[0]) : 'legacy';
$source_id    = 0;
$child_id     = 0;

try {
	wcos_hotfix_assert(class_exists('WooCommerce'), 'WooCommerce is not active.');
	wcos_hotfix_assert(defined('WC_ORDER_SPLITTER_VERSION') && '1.4.12' === WC_ORDER_SPLITTER_VERSION, 'The loaded plugin version is not 1.4.12.');
	wcos_hotfix_assert(defined('WC_ORDER_SPLITTER_MUTATIONS_ENABLED') && false === WC_ORDER_SPLITTER_MUTATIONS_ENABLED, 'The production mutation flag is not hardcoded false.');
	wcos_hotfix_assert(class_exists('WC_Order_Splitter_Script'), 'The fail-closed component loader is unavailable.');
	wcos_hotfix_assert(class_exists('WooCommerce_Order_Splitter_Settings'), 'The maintenance settings class is unavailable.');
	wcos_hotfix_assert(class_exists('WooCommerce_Order_Splitter_Edit_Order'), 'The read-only relationship renderer is unavailable.');

	if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
		$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		wcos_hotfix_assert(
			'hpos' === $storage_mode ? $hpos_enabled : !$hpos_enabled,
			sprintf('The active order storage does not match requested mode %s.', $storage_mode)
		);
	}

	$forbidden_classes = array(
		'WooCommerce_Order_Splitter_Split_Order',
		'WooCommerce_Order_Splitter_Split_Order_By_Category',
		'WooCommerce_Order_Splitter_Split_Order_By_Stock_Status',
		'WooCommerce_Order_Splitter_Duplicate_Order',
		'WooCommerce_Order_Splitter_Merge_Order',
		'WooCommerce_Order_Splitter_Return_Order',
		'WooCommerce_Order_Splitter_Return_Order_Bulk_Action',
		'WooCommerce_Order_Splitter_Edit_Order_Split_Button',
		'WooCommerce_Order_Splitter_Duplicate_Order_Option',
		'WooCommerce_Order_Splitter_Merge_Order_Option',
		'WooCommerce_Order_Splitter_Return_Order_Option',
	);

	foreach ($forbidden_classes as $class_name) {
		wcos_hotfix_assert(!class_exists($class_name, false), 'Forbidden mutation class was loaded: ' . $class_name);
	}

	$forbidden_actions = array(
		'wp_ajax_get_order_items',
		'wp_ajax_split_order',
		'wp_ajax_split_order_by_category',
		'wp_ajax_split_order_by_stock_status',
		'wp_ajax_yoos_merge_order',
		'wp_ajax_yoos_handle_bulk_action',
		'woocommerce_order_action_yoos_duplicate_order',
		'woocommerce_order_action_yoos_return_order',
	);

	foreach ($forbidden_actions as $hook_name) {
		wcos_hotfix_assert(false === has_action($hook_name), 'Forbidden mutation hook is registered: ' . $hook_name);
	}

	$http_requests = array();
	$http_guard = static function ($preempt, $parsed_args, $url) use (&$http_requests) {
		$http_requests[] = (string) $url;
		return new WP_Error('wcos_hotfix_unexpected_http', 'Unexpected HTTP request during version maintenance.');
	};
	add_filter('pre_http_request', $http_guard, PHP_INT_MAX, 3);

	update_option('wc_order_splitter_version', '0.0.0', false);
	$loader = new WC_Order_Splitter_Script();
	$loader->record_version();

	remove_filter('pre_http_request', $http_guard, PHP_INT_MAX);
	wcos_hotfix_assert(array() === $http_requests, 'Version maintenance attempted an external HTTP request.');
	wcos_hotfix_assert('1.4.12' === get_option('wc_order_splitter_version'), 'Version maintenance did not record 1.4.12.');

	$before_order_ids = wcos_hotfix_order_ids();

	$source = wc_create_order(array('status' => 'pending', 'created_via' => 'wcos-hotfix-integration'));
	$child  = wc_create_order(array('status' => 'pending', 'created_via' => 'wcos-hotfix-integration'));
	wcos_hotfix_assert($source instanceof WC_Order && $child instanceof WC_Order, 'Unable to create disposable relation orders.');

	$source_id = $source->get_id();
	$child_id  = $child->get_id();
	$source->update_meta_data('yoos_splitted_order', (string) $child_id);
	$child->update_meta_data('yoos_original_order', $source_id);
	$source->save();
	$child->save();

	$source_before = array(
		'status' => $source->get_status(),
		'total'  => $source->get_total(),
		'meta'   => $source->get_meta('yoos_splitted_order', true),
	);
	$child_before = array(
		'status' => $child->get_status(),
		'total'  => $child->get_total(),
		'meta'   => $child->get_meta('yoos_original_order', true),
	);

	$renderer = new WooCommerce_Order_Splitter_Edit_Order();
	ob_start();
	$renderer->render_edit_order_relations($source);
	$edit_html = (string) ob_get_clean();
	wcos_hotfix_assert(false !== strpos($edit_html, 'Split orders:'), 'Edit-order relationship output is missing its semantic child label.');
	wcos_hotfix_assert(false !== strpos($edit_html, '#' . $child->get_order_number()), 'Edit-order relationship output is missing the child order number.');

	ob_start();
	$renderer->render_hpos_relation_column('wcos_relations', $child);
	$column_html = (string) ob_get_clean();
	wcos_hotfix_assert(false !== strpos($column_html, 'Original:'), 'Order-list relationship output is missing its semantic original label.');
	wcos_hotfix_assert(false !== strpos($column_html, '#' . $source->get_order_number()), 'Order-list relationship output is missing the original order number.');

	$settings = new WooCommerce_Order_Splitter_Settings();
	$previous_get = $_GET;
	$_GET = array('section' => 'order_splitter');
	ob_start();
	$settings->render();
	$settings_html = (string) ob_get_clean();
	$_GET = $previous_get;
	wcos_hotfix_assert(false !== strpos($settings_html, 'Order Splitter safety maintenance'), 'The maintenance settings page did not render.');
	wcos_hotfix_assert(false !== strpos($settings_html, 'Disabled and removed from the release source'), 'The settings page does not report the fail-closed state.');

	$source = wc_get_order($source_id);
	$child  = wc_get_order($child_id);
	wcos_hotfix_assert($source instanceof WC_Order && $child instanceof WC_Order, 'Read-only rendering removed a test order.');
	wcos_hotfix_assert($source_before['status'] === $source->get_status(), 'Read-only rendering changed the source status.');
	wcos_hotfix_assert($source_before['total'] === $source->get_total(), 'Read-only rendering changed the source total.');
	wcos_hotfix_assert($source_before['meta'] === $source->get_meta('yoos_splitted_order', true), 'Read-only rendering changed source relation metadata.');
	wcos_hotfix_assert($child_before['status'] === $child->get_status(), 'Read-only rendering changed the child status.');
	wcos_hotfix_assert($child_before['total'] === $child->get_total(), 'Read-only rendering changed the child total.');
	wcos_hotfix_assert($child_before['meta'] === $child->get_meta('yoos_original_order', true), 'Read-only rendering changed child relation metadata.');

	$after_order_ids = wcos_hotfix_order_ids();
	wcos_hotfix_assert(count($after_order_ids) === count($before_order_ids) + 2, 'The hotfix created an unexpected number of orders.');

	if (defined('WP_CLI') && WP_CLI) {
		WP_CLI::success(sprintf('Order Splitter 1.4.12 read-only smoke test passed using %s storage.', $storage_mode));
	}
} finally {
	foreach (array_filter(array($child_id, $source_id)) as $cleanup_order_id) {
		$cleanup_order = wc_get_order($cleanup_order_id);

		if ($cleanup_order instanceof WC_Order) {
			$cleanup_order->delete(true);
		}
	}
}
