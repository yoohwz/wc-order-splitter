<?php

defined('ABSPATH') || exit;

/**
 * Presentation-only promotion boundary for the standalone Advanced Order Actions product.
 *
 * This class must not own or influence order mutation authority. It only renders
 * commercial discovery surfaces and supplies local-only browser configuration.
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
		add_filter('plugin_action_links_' . plugin_basename(dirname(__DIR__, 2) . '/wc-order-splitter.php'), array($this, 'add_plugin_action_link'), 20);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function add_plugin_action_link($links) {
		if (!current_user_can('manage_woocommerce')) {
			return $links;
		}

		$link = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url(self::product_url()),
			esc_html__('Advanced Order Actions ↗', 'wc-order-splitter')
		);

		$insert_at = min(1, count($links));
		array_splice($links, $insert_at, 0, array($link));

		return $links;
	}

	public static function render_settings_card($title, $description, $features = array(), $cta_label = '') {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		if ('' === $cta_label) {
			$cta_label = esc_html__('Explore Advanced Order Actions', 'wc-order-splitter');
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
				<a class="button button-primary" href="<?php echo esc_url(self::product_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($cta_label); ?></a>
			</div>
		</div>
		<?php
	}

	public function enqueue_assets() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		$is_settings = $this->is_orders_settings_screen();
		$is_order_edit = $this->is_order_edit_screen();
		if (!$is_settings && !$is_order_edit) {
			return;
		}

		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		wp_enqueue_style(
			'wcos-premium-upsell',
			plugins_url('css/premium-upsell.css', $plugin_file),
			array(),
			WC_ORDER_SPLITTER_VERSION
		);

		if (!$is_order_edit) {
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
				'ctaLabel' => __('Explore Advanced Order Actions', 'wc-order-splitter'),
				'dismissLabel' => __('Dismiss', 'wc-order-splitter'),
				'splitHint' => __('Need more routing options? Advanced Order Actions adds product group, tag, attribute, vendor, bundle, and compatible-integration split modes.', 'wc-order-splitter'),
				'splitHintCta' => __('See advanced split methods', 'wc-order-splitter'),
				'thresholds' => array(
					'split' => 3,
					'duplicate' => 2,
					'merge' => 2,
				),
				'executeActions' => array(
					'wcos_split_execute' => 'split',
					'wcos_split_strategy_execute' => 'split',
					'wcos_duplicate_execute' => 'duplicate',
					'wcos_merge_execute' => 'merge',
				),
				'actionTips' => array(
					'split' => __('Doing this repeatedly? Advanced Order Actions can automate split or merge rules, queue scheduled work, and retry eligible failures.', 'wc-order-splitter'),
					'duplicate' => __('Need more control over duplicates? Advanced Order Actions can choose contents, status and payment behavior, preview the result, and process recoverable Bulk Duplicate batches.', 'wc-order-splitter'),
					'merge' => __('Merging orders regularly? Advanced Order Actions adds dry-run Merge previews and automatic same-customer merging within a configured time window.', 'wc-order-splitter'),
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
