<?php
/**
 * Guard optional integration settings sections from crafted admin URLs.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Redirects unavailable premium integration sections to the safe base section.
 */
final class WCOS_Settings_Section_Guard {

	/**
	 * Register guard and notice hooks.
	 */
	public function __construct() {
		add_action('admin_init', array($this, 'guard_section'), 1);
		add_action('admin_notices', array($this, 'render_notice'));
	}

	/**
	 * Prevent an undefined integration object from being called by settings.php.
	 *
	 * @return void
	 */
	public function guard_section() {
		if (!is_admin() || wp_doing_ajax() || 'GET' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET')) {
			return;
		}

		$page    = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		$tab     = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
		$section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';

		if ('wc-settings' !== $page || 'orders' !== $tab || '' === $section) {
			return;
		}

		$requires_premium_settings = in_array($section, array('cancellation', 'returns', 'reorder'), true);
		$requires_appearance       = 'appearance' === $section;
		$available                 = true;

		if ($requires_premium_settings) {
			$available = class_exists('WC_Order_Cancellation_Return_Premium_Settings');
		} elseif ($requires_appearance) {
			$available = class_exists('WC_Order_Cancellation_Return_Premium_Settings')
				&& class_exists('WC_Order_Cancellation_Return_Premium_Settings_Appearance');
		}

		if ($available) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => 'wc-settings',
					'tab'                 => 'orders',
					'section'             => 'order_splitter',
					'wcos_settings_error' => 'integration_unavailable',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Explain why the requested integration section was not rendered.
	 *
	 * @return void
	 */
	public function render_notice() {
		$error = isset($_GET['wcos_settings_error']) ? sanitize_key(wp_unslash($_GET['wcos_settings_error'])) : '';

		if ('integration_unavailable' !== $error) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>';
		esc_html_e('That optional order integration is not active, so its settings section could not be opened.', 'wc-order-splitter');
		echo '</p></div>';
	}
}

new WCOS_Settings_Section_Guard();
