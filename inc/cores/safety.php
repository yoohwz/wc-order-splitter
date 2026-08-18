<?php
/**
 * Scoped safety-maintenance administration notice.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Communicates the fail-closed maintenance state only where it is relevant.
 */
final class WCOS_Safety_Maintenance_Notice {

	/**
	 * Register the notice.
	 */
	public function __construct() {
		add_action('admin_notices', array($this, 'render'));
	}

	/**
	 * Render the notice on WooCommerce order and related settings screens.
	 *
	 * @return void
	 */
	public function render() {
		if (!current_user_can('manage_woocommerce') || !$this->is_relevant_screen()) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e('Order Splitter safety maintenance', 'wc-order-splitter'); ?></strong>
			</p>
			<p>
				<?php
				esc_html_e(
					'Order mutation tools are temporarily unavailable in version 1.4.12 while stock, tax, totals, and recovery safeguards are being rebuilt. Existing orders, settings, and split-order labels are unchanged.',
					'wc-order-splitter'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Determine whether the current screen is related to WooCommerce orders.
	 *
	 * @return bool
	 */
	private function is_relevant_screen() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (!$screen) {
			return false;
		}

		$hpos_screen_id = function_exists('wc_get_page_screen_id')
			? wc_get_page_screen_id('shop-order')
			: 'woocommerce_page_wc-orders';

		if (in_array($screen->id, array('shop_order', 'edit-shop_order', $hpos_screen_id), true)) {
			return true;
		}

		if ('woocommerce_page_wc-settings' !== $screen->id) {
			return false;
		}

		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

		return 'orders' === $tab;
	}
}

new WCOS_Safety_Maintenance_Notice();
