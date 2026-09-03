#!/usr/bin/env bash
# Product assertions preserved from the accepted pre-reset CI workflow.
set -euo pipefail

# Install early-translation monitor
npx wp-env run cli wp eval '
  wp_mkdir_p(WPMU_PLUGIN_DIR);
  @unlink(WP_CONTENT_DIR . "/wcos-early-translation.jsonl");
  $source = WP_PLUGIN_DIR . "/wc-order-splitter/tests/integration/early-translation-monitor.php";
  $target = WPMU_PLUGIN_DIR . "/wcos-early-translation-monitor.php";
  if (!copy($source, $target)) { exit(1); }
'


# Activate Order Splitter after the in-place fixture upgrade
npx wp-env run cli wp plugin activate wc-order-splitter

# Reject early WooCommerce translation loading
npx wp-env run cli wp eval '
  $log = WP_CONTENT_DIR . "/wcos-early-translation.jsonl";
  if (file_exists($log) && filesize($log) > 0) {
    fwrite(STDERR, file_get_contents($log));
    exit(1);
  }
  echo "early-translation-loading-ok\n";
'


# Verify plugin, storage mode, and enabled production gate state
npx wp-env run cli wp plugin is-active woocommerce
npx wp-env run cli wp plugin is-active wc-order-splitter
npx wp-env run cli wp eval '
  $expected = "'"$STORAGE"'";
  $hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
  if (("legacy" === $expected && $hpos) || ("legacy" !== $expected && !$hpos)) { exit(1); }
  if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT)) { exit(1); }
  if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE)) { exit(1); }
  if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE)) { exit(1); }
  if (!WCOS_Feature_Gates::any_enabled()) { exit(1); }
  if (!WC_Order_Splitter_Safety_Guard::mutations_enabled()) { exit(1); }
  if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER)) { exit(1); }
  if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN)) { exit(1); }
  if (!(WCOS_Bulk_Return_Admin_Controller::bootstrap() instanceof WCOS_Bulk_Return_Admin_Controller)) { exit(1); }
  foreach (array(
    WCOS_Bulk_Return_Admin_Controller::REVIEW_ACTION,
    WCOS_Bulk_Return_Admin_Controller::CONFIRM_ACTION,
    WCOS_Bulk_Return_Admin_Controller::EXECUTE_ACTION,
    WCOS_Bulk_Return_Admin_Controller::RESUME_ACTION
  ) as $bulk_return_action) {
    if (false === has_action("wp_ajax_" . $bulk_return_action)) { exit(1); }
  }
  if (!(WCOS_Return_Admin_Controller::bootstrap() instanceof WCOS_Return_Admin_Controller)) { exit(1); }
  foreach (array(
    WCOS_Return_Admin_Controller::REVIEW_ACTION,
    WCOS_Return_Admin_Controller::CONFIRM_ACTION,
    WCOS_Return_Admin_Controller::EXECUTE_ACTION
  ) as $return_action) {
    if (false === has_action("wp_ajax_" . $return_action)) { exit(1); }
  }
  if (!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::MANUAL_QUANTITY)) { exit(1); }
  if (!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY)) { exit(1); }
  if (!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS)) { exit(1); }
  if (false === has_action("wp_ajax_" . WCOS_Split_Strategy_Admin_Controller::REVIEW_ACTION)) { exit(1); }
  if (false === has_action("wp_ajax_" . WCOS_Split_Strategy_Admin_Controller::CONFIRM_ACTION)) { exit(1); }
  if (false === has_action("wp_ajax_" . WCOS_Split_Strategy_Admin_Controller::EXECUTE_ACTION)) { exit(1); }
  foreach (array(
    WCOS_Merge_Admin_Controller::SEARCH_ACTION,
    WCOS_Merge_Admin_Controller::REVIEW_ACTION,
    WCOS_Merge_Admin_Controller::CONFIRM_ACTION,
    WCOS_Merge_Admin_Controller::EXECUTE_ACTION
  ) as $merge_action) {
    if (false === has_action("wp_ajax_" . $merge_action)) { exit(1); }
  }
  $has_controller_method = static function($hook, $class, $method) {
    global $wp_filter;
    if (empty($wp_filter[$hook]) || empty($wp_filter[$hook]->callbacks)) { return false; }
    foreach ($wp_filter[$hook]->callbacks as $callbacks) {
      foreach ($callbacks as $callback) {
        $function = isset($callback["function"]) ? $callback["function"] : null;
        if (is_array($function)
          && isset($function[0], $function[1])
          && $function[0] instanceof $class
          && $method === $function[1]) { return true; }
      }
    }
    return false;
  };
  foreach (array(
    "wp_ajax_" . WCOS_Merge_Admin_Controller::SEARCH_ACTION => "ajax_search",
    "wp_ajax_" . WCOS_Merge_Admin_Controller::REVIEW_ACTION => "ajax_review",
    "wp_ajax_" . WCOS_Merge_Admin_Controller::CONFIRM_ACTION => "ajax_confirm",
    "wp_ajax_" . WCOS_Merge_Admin_Controller::EXECUTE_ACTION => "ajax_execute",
    "admin_footer" => "render_dialog",
    "admin_enqueue_scripts" => "enqueue_assets"
  ) as $hook => $method) {
    if (!$has_controller_method($hook, "WCOS_Merge_Admin_Controller", $method)) { exit(1); }
  }
  if (!$has_controller_method("woocommerce_order_actions_end", "WCOS_Order_Actions_Launcher_Row", "render_launcher_row")) { exit(1); }
  if ($has_controller_method("woocommerce_order_item_add_action_buttons", "WCOS_Duplicate_Admin_Controller", "render_launcher")) { exit(1); }
  if ($has_controller_method("woocommerce_order_item_add_action_buttons", "WCOS_Merge_Admin_Controller", "render_launcher")) { exit(1); }
  foreach (array(
    "WC_ORDER_SPLITTER_MUTATIONS_ENABLED",
    "WC_ORDER_SPLITTER_SPLIT_ENABLED",
    "WC_ORDER_SPLITTER_DUPLICATE_ENABLED",
    "WC_ORDER_SPLITTER_MERGE_ENABLED",
    "WC_ORDER_SPLITTER_RETURN_ENABLED",
    "WC_ORDER_SPLITTER_BULK_RETURN_ENABLED"
  ) as $legacy_gate) {
    if (defined($legacy_gate)) { exit(1); }
  }
  if (false === has_action("wp_ajax_" . WCOS_Split_Admin_Controller::REVIEW_ACTION)) { exit(1); }
  if (false === has_action("wp_ajax_" . WCOS_Split_Admin_Controller::EXECUTE_ACTION)) { exit(1); }
  if (false === has_action("wp_ajax_" . WCOS_Duplicate_Admin_Controller::REVIEW_ACTION)) { exit(1); }
  if (false === has_action("wp_ajax_" . WCOS_Duplicate_Admin_Controller::EXECUTE_ACTION)) { exit(1); }
  $legacy_hooks = array(
    "wp_ajax_split_order",
    "wp_ajax_split_order_by_category",
    "wp_ajax_split_order_by_stock_status",
    "wp_ajax_merge_order",
    "wp_ajax_yoos_merge_order_action",
    "woocommerce_order_action_yoos_duplicate_order",
    "woocommerce_order_action_yoos_return_order"
  );
  foreach ($legacy_hooks as $hook) {
    if (false !== has_action($hook)) { exit(1); }
  }
  echo "storage-and-enabled-production-state-ok\n";
'
