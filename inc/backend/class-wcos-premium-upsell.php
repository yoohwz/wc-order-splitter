<?php

defined('ABSPATH') || exit;

/**
 * Presentation-only discovery for the standalone Advanced Order Actions product.
 *
 * This boundary must not own or influence order-mutation authority. It renders
 * commercial discovery surfaces and supplies browser-local promotion settings.
 */
final class WCOS_Premium_Upsell {
	const PRODUCT_URL = 'https://yoohw.com/product/woocommerce-advanced-order-actions/';

	private static $instance = null;

	public static function bootstrap() {
		if (!self::$instance instanceof self) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function product_url() {
		return self::PRODUCT_URL;
	}

	private function __construct() {
		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		add_filter('plugin_action_links_' . plugin_basename($plugin_file), array($this, 'add_plugin_action_link'), 20);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function add_plugin_action_link($links) {
		if (!current_user_can('manage_woocommerce')) {
			return $links;
		}

		$product_link = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url(self::product_url()),
			esc_html__('Advanced Order Actions ↗', 'wc-order-splitter')
		);

		array_splice($links, min(1, count($links)), 0, array($product_link));
		return $links;
	}

	public static function render_settings_card($title, $description, $features = array()) {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}
		?>
		<div class="wcos-settings-card wcos-upgrade-card">
			<div>
				<h2><?php echo esc_html($title); ?></h2>
				<p><?php echo esc_html($description); ?></p>
				<?php if (!empty($features)) : ?>
					<ul class="wcos-feature-list">
						<?php foreach ($features as $feature) : ?>
							<li><?php echo esc_html($feature); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<div class="wcos-card-actions">
				<a class="button button-primary" href="<?php echo esc_url(self::product_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Explore Advanced Order Actions', 'wc-order-splitter'); ?></a>
			</div>
		</div>
		<?php
	}

	public function enqueue_assets() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		$is_settings_screen = $this->is_orders_settings_screen();
		$is_order_edit_screen = $this->is_order_edit_screen();
		if (!$is_settings_screen && !$is_order_edit_screen) {
			return;
		}

		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		wp_enqueue_style(
			'wcos-premium-upsell',
			plugins_url('css/premium-upsell.css', $plugin_file),
			array(),
			WC_ORDER_SPLITTER_VERSION
		);

		if (!$is_order_edit_screen) {
			return;
		}

		wp_enqueue_script(
			'wcos-premium-upsell',
			plugins_url('js/post-action-tip.js', $plugin_file),
			array('jquery'),
			WC_ORDER_SPLITTER_VERSION,
			true
		);

		wp_localize_script(
			'wcos-premium-upsell',
			'wcosPremiumUpsell',
			array(
				'productUrl' => self::product_url(),
				'ctaLabel' => __('Explore Advanced Order Actions →', 'wc-order-splitter'),
				'dismissLabel' => __('Dismiss', 'wc-order-splitter'),
				'splitHint' => __('Need more routing options? Advanced Order Actions adds product group, tag, attribute, and conditional routing. Vendor and bundle routing require compatible marketplace or bundle integrations.', 'wc-order-splitter'),
				'splitHintTitle' => __('Need more advanced routing?', 'wc-order-splitter'),
				'thresholds' => array(
					'split' => 3,
					'duplicate' => 2,
					'merge' => 2,
					'return' => 2,
				),
				'actionTips' => array(
					'split' => __('Splitting orders repeatedly? Advanced Order Actions can automate split or merge rules, show queued work and failure or skip reasons, and retry eligible failures.', 'wc-order-splitter'),
					'duplicate' => __('Need more control over duplicates? Advanced Order Actions can choose full or itemless contents, status, payment and customer-note behavior, preview the result, and run recoverable Bulk Duplicate batches.', 'wc-order-splitter'),
					'merge' => __('Merging orders regularly? Advanced Order Actions adds dry-run Merge previews and automatic same-customer merging within a configured time window.', 'wc-order-splitter'),
					'return' => __('Need more operational control? Advanced Order Actions adds Action Logs and guarded rollback for supported Split, Merge, Return and Duplicate workflows.', 'wc-order-splitter'),
				),
			)
		);
	}

	private function is_orders_settings_screen() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || 'woocommerce_page_wc-settings' !== $screen->id) {
			return false;
		}

		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
		return 'orders' === $tab;
	}

	private function is_order_edit_screen() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) {
			return false;
		}

		$hpos_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
		$hpos_order_id = isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0;

		return 'shop_order' === $screen->id || ($hpos_screen === $screen->id && $hpos_order_id > 0);
	}
}
