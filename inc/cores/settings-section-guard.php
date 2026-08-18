<?php
/**
 * Guard optional settings sections supplied by companion plugins.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Prevent crafted settings URLs from invoking unavailable companion classes.
 */
final class WCOS_Settings_Section_Guard {

	/**
	 * Register the early settings guard and redirect notice.
	 */
	public function __construct() {
		add_action('admin_init', array($this, 'guard'), 1);
		add_action('admin_notices', array($this, 'render_redirect_notice'));
	}

	/**
	 * Redirect unsupported optional sections to the safe base section.
	 *
	 * @return void
	 */
	public function guard() {
		if (!is_admin() || !current_user_can('manage_woocommerce')) {
			return;
		}

		$page    = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		$tab     = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
		$section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';

		if ('wc-settings' !== $page || 'orders' !== $tab || '' === $section) {
			return;
		}

		$always_available = array(
			'order_splitter',
			'automation_splitter',
			'premium',
			'notifications',
			'cancel_return',
		);

		if (in_array($section, $always_available, true)) {
			return;
		}

		$premium_sections = array('cancellation', 'returns', 'reorder', 'appearance');
		$premium_ready    = class_exists('WC_Order_Cancellation_Return_Premium_Settings')
			&& class_exists('WC_Order_Cancellation_Return_Premium_Settings_Appearance');

		if ($premium_ready && in_array($section, $premium_sections, true)) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                    => 'wc-settings',
					'tab'                     => 'orders',
					'section'                 => 'order_splitter',
					'wcos_section_redirected' => '1',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Explain why an unavailable optional section was redirected.
	 *
	 * @return void
	 */
	public function render_redirect_notice() {
		if (!isset($_GET['wcos_section_redirected']) || '1' !== sanitize_text_field(wp_unslash($_GET['wcos_section_redirected']))) {
			return;
		}

		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>';
		esc_html_e('The requested optional settings section is unavailable, so you were returned to Order Splitter settings.', 'wc-order-splitter');
		echo '</p></div>';
	}
}

new WCOS_Settings_Section_Guard();
