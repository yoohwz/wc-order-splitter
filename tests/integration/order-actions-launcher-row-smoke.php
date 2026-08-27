<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_order_actions_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_order_actions_has_method($hook, $class, $method) {
	global $wp_filter;
	if (empty($wp_filter[$hook]) || empty($wp_filter[$hook]->callbacks)) {
		return false;
	}
	foreach ($wp_filter[$hook]->callbacks as $callbacks) {
		foreach ($callbacks as $callback) {
			$function = isset($callback['function']) ? $callback['function'] : null;
			if (is_array($function)
				&& isset($function[0], $function[1])
				&& $function[0] instanceof $class
				&& $method === $function[1]) {
				return true;
			}
		}
	}
	return false;
}

function wcos_order_actions_render(WCOS_Order_Actions_Launcher_Row $row, $order_id) {
	ob_start();
	$row->render_launcher_row($order_id);
	return (string) ob_get_clean();
}

$root = dirname(__DIR__, 2);
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_order_actions_assert(!empty($admins), 'Order actions launcher test requires an administrator fixture.');
$previous_user = get_current_user_id();
$previous_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$product = null;
$order = null;
$unsupported_order = null;

try {
	wp_set_current_user(absint($admins[0]));
	update_option('order_splitter_status_allowed', array('wc-processing'));

	wcos_order_actions_assert(wcos_order_actions_has_method('woocommerce_order_actions_end', 'WCOS_Order_Actions_Launcher_Row', 'render_launcher_row'), 'Shared launcher row is not registered on woocommerce_order_actions_end.');
	wcos_order_actions_assert(!wcos_order_actions_has_method('woocommerce_order_item_add_action_buttons', 'WCOS_Duplicate_Admin_Controller', 'render_launcher'), 'Duplicate launcher remained in the Order items action area.');
	wcos_order_actions_assert(!wcos_order_actions_has_method('woocommerce_order_item_add_action_buttons', 'WCOS_Merge_Admin_Controller', 'render_launcher'), 'Merge launcher remained in the Order items action area.');
	wcos_order_actions_assert(wcos_order_actions_has_method('woocommerce_order_item_add_action_buttons', 'WCOS_Split_Admin_Controller', 'render_launcher'), 'Split launcher placement changed as a side effect.');
	wcos_order_actions_assert(wcos_order_actions_has_method('woocommerce_order_item_add_action_buttons', 'WCOS_Split_Strategy_Admin_Controller', 'render_launcher'), 'Split strategy launcher placement changed as a side effect.');
	wcos_order_actions_assert(wcos_order_actions_has_method('woocommerce_order_item_add_action_buttons', 'WCOS_Return_Admin_Controller', 'render_launcher'), 'Return launcher placement changed as a side effect.');

	$product = new WC_Product_Simple();
	$product->set_name('WOS UI shared launcher fixture');
	$product->set_status('publish');
	$product->set_regular_price('12.50');
	$product->set_price('12.50');
	$product->save();

	$order = wc_create_order();
	$order->set_status('processing');
	$order->set_currency('USD');
	$order->set_payment_method('cod');
	$order->add_product($product, 1, array('subtotal' => '12.50', 'total' => '12.50'));
	$order->calculate_totals();
	$order->save();
	$order = wc_get_order($order->get_id());

	$duplicate = new WCOS_Duplicate_Admin_Controller();
	$merge = new WCOS_Merge_Admin_Controller();
	$merge->register_hooks();
	$row = new WCOS_Order_Actions_Launcher_Row(array(
		array($duplicate, 'render_launcher'),
		array($merge, 'render_launcher'),
	));
	$html = wcos_order_actions_render($row, $order->get_id());

	wcos_order_actions_assert(1 === substr_count($html, '<li class="wide wcos-order-actions-launcher-row'), 'Eligible launchers did not render exactly one shared Order actions row.');
	wcos_order_actions_assert(2 === substr_count($html, 'class="wcos-order-actions-launcher-slot"'), 'Duplicate and Merge did not receive two equal launcher slots.');
	wcos_order_actions_assert(false !== strpos($html, '>Duplicate</button>'), 'Duplicate did not use its compact launcher label.');
	wcos_order_actions_assert(false !== strpos($html, '>Merge</button>'), 'Merge did not use its compact launcher label.');
	wcos_order_actions_assert(false === strpos($html, '>Duplicate order</button>') && false === strpos($html, '>Merge into another order</button>'), 'A wide legacy launcher label remained in the compact row.');
	wcos_order_actions_assert(false !== strpos($html, 'aria-controls="wcos-duplicate-dialog-' . $order->get_id() . '"'), 'Duplicate launcher lost its dialog binding.');
	wcos_order_actions_assert(false !== strpos($html, 'aria-controls="wcos-merge-dialog-' . $order->get_id() . '"'), 'Merge launcher lost its source dialog binding.');
	wcos_order_actions_assert(false !== strpos($html, 'aria-describedby="wcos-duplicate-launcher-description-' . $order->get_id() . '"'), 'Duplicate launcher lost its description binding.');
	wcos_order_actions_assert(false !== strpos($html, 'aria-describedby="wcos-merge-launcher-description-' . $order->get_id() . '"'), 'Merge launcher lost its description binding.');

	ob_start();
	$duplicate->render_dialog();
	$duplicate_dialog = (string) ob_get_clean();
	ob_start();
	$merge->render_dialog();
	$merge_dialog = (string) ob_get_clean();
	wcos_order_actions_assert(false !== strpos($duplicate_dialog, 'data-order-id="' . $order->get_id() . '"'), 'Duplicate dialog lost the current order identity.');
	wcos_order_actions_assert(false !== strpos($merge_dialog, 'data-source-order-id="' . $order->get_id() . '"'), 'Merge dialog lost the current source order identity.');

	$unsupported_order = wc_create_order();
	$unsupported_order->set_status('processing');
	$unsupported_order->set_currency('USD');
	$unsupported_order->save();
	$disabled_html = wcos_order_actions_render($row, $unsupported_order->get_id());
	wcos_order_actions_assert(false !== strpos($disabled_html, 'wcos-duplicate-launcher'), 'Authorized unsupported Duplicate did not render its disabled launcher.');
	wcos_order_actions_assert(false !== strpos($disabled_html, 'disabled='), 'Unsupported Duplicate launcher did not remain disabled.');
	wcos_order_actions_assert(false !== strpos($disabled_html, 'An order without product line items cannot be duplicated.'), 'Disabled Duplicate preflight description was not preserved.');

	$one_button = new WCOS_Order_Actions_Launcher_Row(array(
		static function() { echo '<button type="button" class="button">Only</button>'; },
		static function() {},
	));
	$one_button_html = wcos_order_actions_render($one_button, $order->get_id());
	wcos_order_actions_assert(false !== strpos($one_button_html, 'wcos-order-actions-launcher-row--single'), 'One eligible launcher did not activate the full-row layout.');
	wcos_order_actions_assert(1 === substr_count($one_button_html, 'class="wcos-order-actions-launcher-slot"'), 'One-button state reserved an empty launcher slot.');

	$empty_row = new WCOS_Order_Actions_Launcher_Row(array(static function() {}, static function() {}));
	wcos_order_actions_assert('' === wcos_order_actions_render($empty_row, $order->get_id()), 'No-launcher state emitted an empty Order actions row.');
	wcos_order_actions_assert('' === wcos_order_actions_render($row, 0), 'Invalid order identity emitted a launcher row.');

	$shared_css = file_get_contents($root . '/css/p2-backbone-modal.css');
	wcos_order_actions_assert(is_string($shared_css) && false !== strpos($shared_css, '.wcos-order-actions-launcher-row .wcos-order-actions-launchers'), 'Shared order-edit stylesheet does not own launcher-row layout.');
	wcos_order_actions_assert(false !== strpos($shared_css, 'display: flex;') && false !== strpos($shared_css, 'gap: 6px;'), 'Shared launcher row does not use the required compact horizontal layout.');
	wcos_order_actions_assert(false !== strpos($shared_css, 'flex: 1 1 0;') && false !== strpos($shared_css, 'width: 100%;'), 'Launcher slots do not share width or fill the single-button row.');

	$duplicate_source = file_get_contents($root . '/inc/backend/class-wcos-duplicate-admin-controller.php');
	$merge_source = file_get_contents($root . '/inc/backend/class-wcos-merge-admin-controller.php');
	wcos_order_actions_assert(false === strpos($duplicate_source, "add_action('woocommerce_order_item_add_action_buttons', array(\$this, 'render_launcher')"), 'Duplicate source retained its old launcher hook.');
	wcos_order_actions_assert(false === strpos($merge_source, "add_action('woocommerce_order_item_add_action_buttons', array(\$this, 'render_launcher')"), 'Merge source retained its old launcher hook.');

	wcos_order_actions_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT), 'Split gate changed during launcher placement.');
	wcos_order_actions_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE), 'Duplicate gate changed during launcher placement.');
	wcos_order_actions_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE), 'Merge gate changed during launcher placement.');
	wcos_order_actions_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER), 'Return gate changed during launcher placement.');
	wcos_order_actions_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN), 'Bulk Return gate changed during launcher placement.');
} finally {
	if ($unsupported_order instanceof WC_Order) {
		$unsupported_order->delete(true);
	}
	if ($order instanceof WC_Order) {
		$order->delete(true);
	}
	if ($product instanceof WC_Product) {
		$product->delete(true);
	}
	update_option('order_splitter_status_allowed', $previous_statuses);
	wp_set_current_user($previous_user);
}

echo "order-actions-launcher-row-ok shared=1 both=1 single=1 empty=0 legacy_hpos_crud=1\n";
