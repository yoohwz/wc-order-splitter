<?php

defined('ABSPATH') || exit;

/**
 * Emergency safety guard for order mutation workflows.
 *
 * Version 1.4.12 deliberately fails closed while the mutation engine is rebuilt
 * around stock, monetary, line-identity, and idempotency invariants.
 */
class WC_Order_Splitter_Safety_Guard {

	public function __construct() {
		add_action('admin_init', array($this, 'guard_unavailable_settings_sections'), 1);
		add_action('admin_notices', array($this, 'render_admin_notice'));
	}

	public static function mutations_enabled() {
		return false;
	}

	public function guard_unavailable_settings_sections() {
		if (!is_admin() || !isset($_GET['page'], $_GET['tab'], $_GET['section'])) {
			return;
		}

		$page = sanitize_key(wp_unslash($_GET['page']));
		$tab = sanitize_key(wp_unslash($_GET['tab']));
		$section = sanitize_key(wp_unslash($_GET['section']));

		if ('wc-settings' !== $page || 'orders' !== $tab) {
			return;
		}

		$premium_sections = array('cancellation', 'returns', 'reorder', 'appearance');
		if (!in_array($section, $premium_sections, true)) {
			return;
		}

		$premium_settings_available = class_exists('WC_Order_Cancellation_Return_Premium_Settings');
		$appearance_available = class_exists('WC_Order_Cancellation_Return_Premium_Settings_Appearance');

		if ($premium_settings_available && ('appearance' !== $section || $appearance_available)) {
			return;
		}

		$_GET['section'] = 'order_splitter';
	}

	public function render_admin_notice() {
		if (self::mutations_enabled() || !current_user_can('manage_woocommerce')) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) {
			return;
		}

		$hpos_order_screen_id = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
		$is_order_screen = in_array($screen->id, array('shop_order', 'edit-shop_order', $hpos_order_screen_id), true);
		$is_settings_screen = 'woocommerce_page_wc-settings' === $screen->id;

		if (!$is_order_screen && !$is_settings_screen) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e('Order Splitter safety mode is active.', 'wc-order-splitter'); ?></strong>
				<?php esc_html_e('Order mutation actions are temporarily disabled in this release while their stock, totals, tax, and rollback guarantees are being hardened. Existing orders and split-order relationship metadata are not changed by this safety mode.', 'wc-order-splitter'); ?>
			</p>
		</div>
		<?php
	}
}

new WC_Order_Splitter_Safety_Guard();
