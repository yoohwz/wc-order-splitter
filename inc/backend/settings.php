<?php
/**
 * Read-only safety-maintenance settings page.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Settings {

	/**
	 * Register the shared WooCommerce Orders settings tab.
	 */
	public function __construct() {
		add_filter('woocommerce_settings_tabs_array', array($this, 'add_orders_settings_tab'), 30);
		add_action('woocommerce_settings_tabs_orders', array($this, 'render'), 9);
	}

	/**
	 * Add the Orders tab only when no companion extension has added it already.
	 *
	 * @param array $settings_tabs WooCommerce settings tabs.
	 * @return array
	 */
	public function add_orders_settings_tab($settings_tabs) {
		if (!is_array($settings_tabs)) {
			$settings_tabs = array();
		}

		if (!isset($settings_tabs['orders'])) {
			$settings_tabs['orders'] = esc_html__('Orders', 'wc-order-splitter');
		}

		return $settings_tabs;
	}

	/**
	 * Render the maintenance state for sections owned by this plugin.
	 *
	 * @return void
	 */
	public function render() {
		$section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : 'order_splitter';
		$owned_sections = array(
			'',
			'order_splitter',
			'automation_splitter',
			'premium',
			'notifications',
		);

		if (!in_array($section, $owned_sections, true)) {
			return;
		}
		?>
		<div class="wcos-settings-card">
			<div>
				<h2><?php esc_html_e('Order Splitter safety maintenance', 'wc-order-splitter'); ?></h2>
				<p>
					<?php
					esc_html_e(
						'Version 1.4.12 is a fail-closed maintenance release. Split, duplicate, merge, return, and bulk-return handlers are not registered while the replacement order mutation engine is being verified.',
						'wc-order-splitter'
					);
					?>
				</p>
				<p>
					<?php
					esc_html_e(
						'Existing settings and split-order relationship metadata remain stored. This release does not rewrite or delete existing WooCommerce orders.',
						'wc-order-splitter'
					);
					?>
				</p>
			</div>
		</div>

		<table class="widefat striped" style="max-width: 760px; margin-top: 16px;">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e('Installed release', 'wc-order-splitter'); ?></th>
					<td><code><?php echo esc_html(WC_ORDER_SPLITTER_VERSION); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Order mutation handlers', 'wc-order-splitter'); ?></th>
					<td><?php esc_html_e('Disabled and removed from the release source', 'wc-order-splitter'); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Existing relationship data', 'wc-order-splitter'); ?></th>
					<td><?php esc_html_e('Preserved for read-only labels and future migration', 'wc-order-splitter'); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Automatic external subscription request', 'wc-order-splitter'); ?></th>
					<td><?php esc_html_e('Removed', 'wc-order-splitter'); ?></td>
				</tr>
			</tbody>
		</table>

		<p style="margin-top: 16px;">
			<a class="button" href="<?php echo esc_url('https://docs.yoohw.com/category/woocommerce-advanced-order-actions/'); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e('Documentation', 'wc-order-splitter'); ?>
			</a>
			<a class="button" href="<?php echo esc_url('https://yoohw.com/support/'); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e('Support', 'wc-order-splitter'); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Seed defaults for compatibility with existing installations and callers.
	 *
	 * @return void
	 */
	public static function set_default_settings() {
		add_option('order_splitter_status_allowed', array('wc-processing'));
		add_option('order_splitter_exclude_shipping_fee', 'no');
		add_option('order_splitter_shop_manager_permission', 'no');
		add_option('order_splitter_order_label', 'yes');
	}
}

new WooCommerce_Order_Splitter_Settings();
