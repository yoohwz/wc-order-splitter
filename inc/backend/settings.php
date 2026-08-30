<?php

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Settings {

	public function __construct() {
			add_filter('woocommerce_settings_tabs_array', array($this, 'add_orders_settings_tab'), 30);
			add_action('woocommerce_settings_tabs_orders', array($this, 'order_splitter_settings_tab'), 9);
			add_action('woocommerce_update_options_orders', array($this, 'update_order_splitter_settings'));
			add_action('admin_init', array($this, 'handle_settings_actions'));

		if (class_exists('WC_Order_Cancellation_Return_Premium_Settings')) {
			$wc_order_cancel_return_settings = new WC_Order_Cancellation_Return_Premium_Settings();
		} elseif (class_exists('WC_Order_Cancellation_Return_Settings')) {
			$wc_order_cancel_return_settings = new WC_Order_Cancellation_Return_Settings();
		}

		if (isset($wc_order_cancel_return_settings)) {
			add_action('woocommerce_admin_field_available_time', array($wc_order_cancel_return_settings, 'render_available_time_field'));
		}
	}

	public function add_orders_settings_tab($settings_tabs) {
		$settings_tabs['orders'] = esc_html__('Orders', 'wc-order-splitter');
		return $settings_tabs;
	}

	public function order_splitter_settings_tab() {
		$current_section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : 'order_splitter';

		if (class_exists('WC_Order_Cancellation_Return_Premium_Settings')) {
			// If the premium class exists, use it
			$wcocrp_settings = new WC_Order_Cancellation_Return_Premium_Settings();
			$wcocrp_settings_appearance = new WC_Order_Cancellation_Return_Premium_Settings_Appearance();
		}

		$this->render_onboarding_card();

		// Output the sub-sub-tabs
		echo '<ul class="subsubsub">';
		$this->output_sub_sub_tabs($current_section);
		echo '</ul><br class="clear" />';

		// Output the settings for the current sub-sub-tab
		if ('automation_splitter' === $current_section) {
			$this->output_automation_splitter_settings();
		} elseif ('cancel_return' === $current_section) {
			$this->output_cancel_return_settings();
		} elseif ('cancellation' === $current_section) {
			$wcocrp_settings->yowcocr_settings_orders_add_cancellation_section();
		} elseif ('returns' === $current_section) {
			$wcocrp_settings->yowcocr_settings_orders_add_return_section();
		} elseif ('reorder' === $current_section) {
			$wcocrp_settings->yowcocr_settings_orders_add_reorder_section();
		} elseif ('appearance' === $current_section) {
			$wcocrp_settings_appearance->conditionally_display_appearance_form();
		} elseif ('premium' === $current_section) {
			$this->output_premium_settings();
		} elseif ('notifications' === $current_section) {
			$this->output_notifications_settings();
		} else {
			$this->output_order_splitter_settings();
		}
	}

	public function output_sub_sub_tabs($current_section) {
		$sub_sub_tabs = array(
			'order_splitter'      => esc_html__('General', 'wc-order-splitter'),
			'automation_splitter' => esc_html__('Automation', 'wc-order-splitter'),
		);

		// Keep the historical section key for URL/backward compatibility.
		$sub_sub_tabs['premium'] = esc_html__('Upgrade', 'wc-order-splitter');

		// Free cancel/return plugin: add a single tab (optional)
		if ( is_plugin_active('wc-order-cancellation-return/wc-order-cancellation-return.php') ) {
			$sub_sub_tabs['cancel_return'] = esc_html__('Cancel & Return', 'wc-order-splitter');
		}

		// Premium cancel/return plugin: add its sub-tabs (do NOT overwrite your base tabs)
		if (
			is_plugin_active('wc-order-cancellation-return-premium/wc-order-cancellation-return-premium.php')
			&& get_option('wc_order_cancellation_return_premium_license_status') === 'activated'
		) {
			// If you don't want the free "Cancel & Return" tab when premium is active, remove it.
			unset($sub_sub_tabs['cancel_return']);

			$wcocrp_tabs = array(
				'cancellation' => esc_html__('Cancellation', 'wc-order-splitter'),
				'returns'      => esc_html__('Returns', 'wc-order-splitter'),
				'reorder'      => esc_html__('Reorder', 'wc-order-splitter'),
				'appearance'   => esc_html__('Appearance', 'wc-order-splitter'),
			);

			// Append after your base tabs
			$sub_sub_tabs = array_merge($sub_sub_tabs, $wcocrp_tabs);
		}

		// Always keep Notifications at the end
		$sub_sub_tabs['notifications'] = esc_html__('Notifications', 'wc-order-splitter');

		$count = count($sub_sub_tabs);
		$i = 1;

		foreach ($sub_sub_tabs as $section_id => $section_label) {
			$class = ($current_section === $section_id) ? 'current' : '';
			echo '<li><a href="' . esc_url($this->get_settings_url($section_id)) . '" class="' . esc_attr($class) . '">' . esc_html($section_label) . '</a>';
			if ($i < $count) {
				echo ' | ';
			}
			echo '</li>';
			$i++;
		}
	}

	public function output_order_splitter_settings() {
		woocommerce_admin_fields($this->get_split_order_settings());
		woocommerce_admin_fields($this->get_advanced_settings());
		$this->render_upgrade_card(
			esc_html__('When manual order actions become repetitive', 'wc-order-splitter'),
			esc_html__('Order Splitter keeps supported operations available on demand. Advanced Order Actions is the standalone path to previews, recoverable Bulk Duplicate, automation, and operational visibility when those tasks need to scale.', 'wc-order-splitter'),
			array(
				esc_html__('Preview Split and Merge results before changing orders.', 'wc-order-splitter'),
				esc_html__('Configure Duplicate contents and behavior, or run recoverable Bulk Duplicate batches.', 'wc-order-splitter'),
				esc_html__('Inspect Action Logs, queue states, failure or skip reasons, retries, and guarded rollback.', 'wc-order-splitter'),
			)
		);
	}

	public function output_automation_splitter_settings() {
		woocommerce_admin_fields($this->get_automation_splitter_settings());
		$this->render_upgrade_card(
			esc_html__('Automate repetitive order operations', 'wc-order-splitter'),
			esc_html__('Advanced Order Actions can use prioritized rules to split, merge, or skip new orders, then track work through an Automation Queue.', 'wc-order-splitter'),
			array(
				esc_html__('Build rules with priorities and ALL or ANY conditions.', 'wc-order-splitter'),
				esc_html__('Choose Split, Merge, or Skip actions for matching orders.', 'wc-order-splitter'),
				esc_html__('See pending, running, completed, failed, and skipped states with reasons and retry controls.', 'wc-order-splitter'),
			)
		);
	}

	public function output_notifications_settings() {
		woocommerce_admin_fields($this->get_notifications_settings());
		$this->render_upgrade_card(
			esc_html__('Control split-order communication before customers see it', 'wc-order-splitter'),
			esc_html__('Advanced Order Actions adds customer and administrator sending modes, per-method content controls, and live WooCommerce email previews.', 'wc-order-splitter'),
			array(
				esc_html__('Choose which original or split orders generate customer email.', 'wc-order-splitter'),
				esc_html__('Customize subject, heading, and message per split method.', 'wc-order-splitter'),
				esc_html__('Preview processing, on-hold, and completed customer emails.', 'wc-order-splitter'),
			)
		);
	}

	public function output_cancel_return_settings() {
		if (class_exists('WC_Order_Cancellation_Return_Premium_Settings')) {
			// If the premium class exists, use it
			$wc_order_cancel_return_settings = new WC_Order_Cancellation_Return_Premium_Settings();
		} elseif (class_exists('WC_Order_Cancellation_Return_Settings')) {
			// If the regular class exists, use it
			$wc_order_cancel_return_settings = new WC_Order_Cancellation_Return_Settings();
		} else {
			// If neither plugin is active, display a notice
			echo '<div class="notice notice-error"><p>' . esc_html__('The Cancel / Return plugin is not activated.', 'wc-order-splitter') . '</p></div>';
			return;
		}

		// Output cancel and return order settings from the appropriate plugin
		if (isset($wc_order_cancel_return_settings)) {
			$wc_order_cancel_return_settings->yoocr_wc_order_cancellation_return_settings_orders_add_cancellation_section();
			$wc_order_cancel_return_settings->yoocr_wc_order_cancellation_return_settings_orders_add_return_section();
		}
	}

	private function get_settings_url($section = 'order_splitter', $args = array()) {
		$query_args = array_merge(
			array(
				'page' => 'wc-settings',
				'tab' => 'orders',
			),
			$args
		);

		if ($section) {
			$query_args['section'] = sanitize_key($section);
		}

		return add_query_arg($query_args, admin_url('admin.php'));
	}

	private function get_premium_url() {
		if (class_exists('WCOS_Premium_Upsell')) {
			return WCOS_Premium_Upsell::product_url();
		}

		return 'https://yoohw.com/product/woocommerce-advanced-order-actions/';
	}

	private function render_upgrade_card($title, $description, $features = array()) {
		if (class_exists('WCOS_Premium_Upsell')) {
			WCOS_Premium_Upsell::render_settings_card($title, $description, $features);
			return;
		}

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
				<a class="button button-primary" href="<?php echo esc_url($this->get_premium_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Explore Advanced Order Actions', 'wc-order-splitter'); ?></a>
			</div>
		</div>
		<?php
	}

	public function handle_settings_actions() {
		if (empty($_GET['wcos_action']) || 'dismiss_onboarding' !== sanitize_key(wp_unslash($_GET['wcos_action']))) {
			return;
		}

		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		check_admin_referer('wcos_dismiss_onboarding');
		update_user_meta(get_current_user_id(), 'wcos_settings_onboarding_dismissed', 'yes');
		wp_safe_redirect($this->get_settings_url());
		exit;
	}

	private function render_onboarding_card() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		if ('yes' === get_user_meta(get_current_user_id(), 'wcos_settings_onboarding_dismissed', true)) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			$this->get_settings_url('order_splitter', array('wcos_action' => 'dismiss_onboarding')),
			'wcos_dismiss_onboarding'
		);
		?>
		<div class="wcos-settings-card wcos-onboarding-card">
			<div>
				<h2><?php esc_html_e('Configure Order Splitter before the first split', 'wc-order-splitter'); ?></h2>
				<p><?php esc_html_e('Choose allowed order statuses, decide how shipping fees should be handled, and review the available split methods before using this on live orders.', 'wc-order-splitter'); ?></p>
			</div>
			<div class="wcos-card-actions">
				<a class="button button-primary" href="<?php echo esc_url($this->get_settings_url('order_splitter')); ?>"><?php esc_html_e('Review settings', 'wc-order-splitter'); ?></a>
				<a class="wcos-dismiss-link" href="<?php echo esc_url($dismiss_url); ?>"><?php esc_html_e('Dismiss', 'wc-order-splitter'); ?></a>
			</div>
		</div>
		<?php
	}

	public function output_premium_settings() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		$features = array(
			array(
				'feature' => esc_html__('On-demand order operations', 'wc-order-splitter'),
				'free' => esc_html__('Split, Duplicate, Merge, Return, and Bulk Return for supported orders.', 'wc-order-splitter'),
				'premium' => esc_html__('Expanded Split, Duplicate, and Merge controls for repeated operational workflows.', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Split routing', 'wc-order-splitter'),
				'free' => esc_html__('Manual quantity, category, and stock status.', 'wc-order-splitter'),
				'premium' => esc_html__('Product group, tag, attribute, plus conditional vendor and bundle routing.', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Duplicate workflow', 'wc-order-splitter'),
				'free' => esc_html__('Single reviewed Duplicate.', 'wc-order-splitter'),
				'premium' => esc_html__('Full or itemless contents, status, payment and customer-note choices, preview, and recoverable Bulk Duplicate.', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Merge workflow', 'wc-order-splitter'),
				'free' => esc_html__('Single reviewed manual Merge.', 'wc-order-splitter'),
				'premium' => esc_html__('Dry-run preview and automatic same-customer merge within a configured time window.', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Automation', 'wc-order-splitter'),
				'free' => esc_html__('On-demand operations.', 'wc-order-splitter'),
				'premium' => esc_html__('Rule Builder with priorities, ALL/ANY conditions, Split/Merge/Skip actions, queue visibility, and retry.', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Before-action visibility', 'wc-order-splitter'),
				'free' => esc_html__('Server review and confirmation for hardened workflows.', 'wc-order-splitter'),
				'premium' => esc_html__('Split and Merge dry-run previews before an operation changes orders.', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Operational history', 'wc-order-splitter'),
				'free' => esc_html__('Durable operation safety, replay, and recovery foundations.', 'wc-order-splitter'),
				'premium' => esc_html__('Action Logs, guarded rollback, Automation Queue state, failure or skip reasons, and retry controls.', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Split-order notifications', 'wc-order-splitter'),
				'free' => esc_html__('Standard on-demand workflow behavior.', 'wc-order-splitter'),
				'premium' => esc_html__('Customer/admin sending modes, per-method content, and live WooCommerce email previews.', 'wc-order-splitter'),
			),
		);
		?>
		<div class="wcos-settings-card wcos-premium-intro">
			<div>
				<h2><?php esc_html_e('Upgrade to WooCommerce Advanced Order Actions', 'wc-order-splitter'); ?></h2>
				<p><?php esc_html_e('Advanced Order Actions is a standalone premium replacement for Order Splitter. It is designed for stores that have outgrown on-demand manual operations and need automation, bulk duplication, previews, and deeper operational control. When activated, it runs independently and Order Splitter is deactivated.', 'wc-order-splitter'); ?></p>
			</div>
			<div class="wcos-card-actions">
				<a class="button button-primary" href="<?php echo esc_url($this->get_premium_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Explore Advanced Order Actions', 'wc-order-splitter'); ?></a>
			</div>
		</div>

		<table class="widefat striped wcos-premium-matrix">
			<thead>
				<tr>
					<th><?php esc_html_e('Workflow', 'wc-order-splitter'); ?></th>
					<th><?php esc_html_e('Order Splitter', 'wc-order-splitter'); ?></th>
					<th><?php esc_html_e('Advanced Order Actions', 'wc-order-splitter'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($features as $feature) : ?>
					<tr>
						<td><?php echo esc_html($feature['feature']); ?></td>
						<td><?php echo esc_html($feature['free']); ?></td>
						<td><?php echo esc_html($feature['premium']); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description wcos-upgrade-qualification"><?php esc_html_e('Vendor and bundle routing require compatible marketplace or bundle plugins. Inventory readiness, location, and reconciliation capabilities require a compatible Stock Manager integration.', 'wc-order-splitter'); ?></p>
		<?php
	}

	public function get_split_order_settings() {
		$settings = array(
			'section_title' => array(
				'name'     => esc_html__('Split orders', 'wc-order-splitter'),
				'type'     => 'title',
				'id'       => 'order_splitter_section_title',
			),
			'order_status' => array(
				'name'     => esc_html__('Allowed status', 'wc-order-splitter'),
				'type'     => 'multiselect',
				'desc_tip' => esc_html__('Choose order statuses that allow for splitting and duplication.', 'wc-order-splitter'),
				'id'       => 'order_splitter_status_allowed',
				'options'  => wc_get_order_statuses(),
				'default'  => array('wc-processing'),
				'custom_attributes' => array(
					'data-placeholder' => esc_html__('Select order statuses', 'wc-order-splitter')
				),
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width:300px;',
			),
			'exclude_shipping' => array(
				'name'     => esc_html__('Excluded fee', 'wc-order-splitter'),
				'type'     => 'checkbox',
				'desc'     => esc_html__('Exclude shipping fees for the split order', 'wc-order-splitter'),
				'desc_tip' => esc_html__('If checked, the original order will keep its shipping fee, and the split orders won\'t include any shipping charges.', 'wc-order-splitter'),
				'id'       => 'order_splitter_exclude_shipping_fee',
				'default'  => 'no',
			),
			'section_end' => array(
				'type' => 'sectionend',
				'id'   => 'order_splitter_section_end'
			)
		);
		return apply_filters('order_splitter_settings', $settings);
	}

	public function get_advanced_settings() {
		$settings = array(
			'section_title' => array(
				'name'     => esc_html__('Advanced', 'wc-order-splitter'),
				'type'     => 'title',
				'id'       => 'advanced_order_splitter_section_title'
			),
			'order_label' => array(
				'name'     => esc_html__('Order labels', 'wc-order-splitter'),
				'type'     => 'checkbox',
				'desc'     => esc_html__('Enable the labels for split orders', 'wc-order-splitter'),
				'id'       => 'order_splitter_order_label',
				'default'  => 'yes',
			),
			'allow_split_orders' => array(
				'name'     => esc_html__('Permission', 'wc-order-splitter'),
				'type'     => 'checkbox',
				'desc'     => esc_html__('Enable the shop manager to split orders', 'wc-order-splitter'),
				'id'       => 'order_splitter_shop_manager_permission',
				'default'  => 'no',
			),
			'section_end' => array(
				'type' => 'sectionend',
				'id'   => 'advanced_order_splitter_section_end'
			)
		);
		return apply_filters('advanced_settings', $settings);
	}

	public function get_automation_splitter_settings() {
		$settings = array(
			'section_title' => array(
				'name'     => esc_html__('Automation', 'wc-order-splitter'),
				'type'     => 'title',
				'desc'     => esc_html__('Order Splitter keeps supported order operations on demand; no automation rule is configured on this screen.', 'wc-order-splitter'),
				'id'       => 'automation_splitter_section_title',
			),
			'section_end' => array(
				'type' => 'sectionend',
				'id'   => 'automation_splitter_section_end'
			)
		);

		return apply_filters('automation_splitter_settings', $settings);
	}

	public function get_notifications_settings() {
		$settings = array(
			'section_title' => array(
				'name'     => esc_html__('Split order email', 'wc-order-splitter'),
				'type'     => 'title',
				'desc'     => esc_html__('Order Splitter keeps its on-demand notification behavior unchanged; this screen has no configurable split-order email rules.', 'wc-order-splitter'),
				'id'       => 'notifications_order_splitter_section_title'
			),
			'section_end' => array(
				'type' => 'sectionend',
				'id'   => 'notifications_order_splitter_section_end'
			)
		);

		return apply_filters('notifications_settings', $settings);
	}

	public function update_order_splitter_settings() {
		$current_section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : 'order_splitter';

		if (class_exists('WC_Order_Cancellation_Return_Premium_Settings')) {
			// If the premium class exists, use it
			$wcocrp_settings = new WC_Order_Cancellation_Return_Premium_Settings();
			$wcocrp_settings_appearance = new WC_Order_Cancellation_Return_Premium_Settings_Appearance();
		}

		if ('cancel_return' === $current_section) {
			// Handle both regular and premium cancel/return settings
			if (class_exists('WC_Order_Cancellation_Return_Settings')) {
				$wc_order_cancel_return_settings = new WC_Order_Cancellation_Return_Settings();
			}
	
			if (isset($wc_order_cancel_return_settings)) {
				$wc_order_cancel_return_settings->yoocr_wc_order_cancellation_return_settings_orders_cancellation_update();
				$wc_order_cancel_return_settings->yoocr_wc_order_cancellation_return_settings_orders_return_update();
			}
		} elseif ('cancellation' === $current_section) {
			woocommerce_update_options($wcocrp_settings->get_cancellation_customer_actions_settings());
			woocommerce_update_options($wcocrp_settings->get_cancellation_manager_actions_settings());
		} elseif ('returns' === $current_section) {
			woocommerce_update_options($wcocrp_settings->get_return_customer_actions_settings());
			woocommerce_update_options($wcocrp_settings->get_return_attachments_settings());
			woocommerce_update_options($wcocrp_settings->get_return_manager_actions_settings());
		} elseif ('reorder' === $current_section) {
			woocommerce_update_options($wcocrp_settings->get_reorder_settings());
		} elseif ('appearance' === $current_section) {
			$wcocrp_settings_appearance->conditionally_save_customization_settings();
		} elseif ('automation_splitter' === $current_section || 'premium' === $current_section || 'notifications' === $current_section) {
			return;
		} else {
			woocommerce_update_options($this->get_split_order_settings());
			woocommerce_update_options($this->get_advanced_settings());
		}
	}

	public static function set_default_settings() {
		$default_settings = array(
			'order_splitter_status_allowed' => array('wc-processing'),
			'order_splitter_exclude_shipping_fee' => 'no',
			'order_splitter_disable_split_order_email' => 'none',
			'order_splitter_shop_manager_permission' => 'no',
			'order_splitter_order_label' => 'yes',
		);

		foreach ($default_settings as $key => $value) {
			if (get_option($key, false) === false) {
				add_option($key, $value);
			}
		}
	}
}

new WooCommerce_Order_Splitter_Settings();
