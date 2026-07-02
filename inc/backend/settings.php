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

		$sub_sub_tabs['premium'] = esc_html__('Premium', 'wc-order-splitter');

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
			esc_html__('Need more order controls?', 'wc-order-splitter'),
			esc_html__('Premium adds status controls after splitting, duplicate/merge restrictions, editable order status expansion, product groups, tags, attributes, bundles, vendors, and automated split rules.', 'wc-order-splitter'),
			array(
				esc_html__('Set original and split order statuses after splitting.', 'wc-order-splitter'),
				esc_html__('Control which orders can be duplicated or merged.', 'wc-order-splitter'),
				esc_html__('Split by product group, tag, attribute, bundle, or vendor.', 'wc-order-splitter'),
			)
		);
	}

	public function output_automation_splitter_settings() {
		woocommerce_admin_fields($this->get_automation_splitter_settings());
		$this->render_upgrade_card(
			esc_html__('Automate repeated split workflows', 'wc-order-splitter'),
			esc_html__('Premium can schedule split actions after an order is created and apply rules based on quantity, product group, category, stock status, tag, attribute, bundle, or vendor.', 'wc-order-splitter'),
			array(
				esc_html__('Run split rules automatically after checkout.', 'wc-order-splitter'),
				esc_html__('Add a delay timer for order processing workflows.', 'wc-order-splitter'),
				esc_html__('Use WP-Cron based automation for repeatable operations.', 'wc-order-splitter'),
			)
		);
	}

	public function output_notifications_settings() {
		woocommerce_admin_fields($this->get_notifications_settings());
		$this->render_upgrade_card(
			esc_html__('Need split-order email controls?', 'wc-order-splitter'),
			esc_html__('Premium adds customer and administrator email controls so split orders can be communicated without duplicate or confusing order emails.', 'wc-order-splitter'),
			array(
				esc_html__('Choose whether customers receive the original order email, split order emails, or both.', 'wc-order-splitter'),
				esc_html__('Customize split-order email subject, heading, and message.', 'wc-order-splitter'),
				esc_html__('Enable email rules per split method.', 'wc-order-splitter'),
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
		return 'https://yoohw.com/product/woocommerce-advanced-order-actions/';
	}

	private function render_upgrade_card($title, $description, $features = array()) {
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
				<a class="button button-primary" href="<?php echo esc_url($this->get_premium_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Compare Premium', 'wc-order-splitter'); ?></a>
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
		$features = array(
			array(
				'feature' => esc_html__('Manual split by product and quantity', 'wc-order-splitter'),
				'free' => esc_html__('Included', 'wc-order-splitter'),
				'premium' => esc_html__('Included', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Split by category and stock status', 'wc-order-splitter'),
				'free' => esc_html__('Included', 'wc-order-splitter'),
				'premium' => esc_html__('Included', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Split by product group, tag, attribute, bundle, or vendor', 'wc-order-splitter'),
				'free' => esc_html__('Locked', 'wc-order-splitter'),
				'premium' => esc_html__('Included', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Automated split rules after order creation', 'wc-order-splitter'),
				'free' => esc_html__('Locked', 'wc-order-splitter'),
				'premium' => esc_html__('Included', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Order status controls after split, duplicate, and merge actions', 'wc-order-splitter'),
				'free' => esc_html__('Locked', 'wc-order-splitter'),
				'premium' => esc_html__('Included', 'wc-order-splitter'),
			),
			array(
				'feature' => esc_html__('Customer and admin split-order email controls', 'wc-order-splitter'),
				'free' => esc_html__('Locked', 'wc-order-splitter'),
				'premium' => esc_html__('Included', 'wc-order-splitter'),
			),
		);
		?>
		<div class="wcos-settings-card wcos-premium-intro">
			<div>
				<h2><?php esc_html_e('Premium options for high-volume order operations', 'wc-order-splitter'); ?></h2>
				<p><?php esc_html_e('The free plugin keeps manual splitting available. Premium is best for stores that repeatedly split by rules, need automated workflows, or need tighter email/status controls.', 'wc-order-splitter'); ?></p>
			</div>
			<div class="wcos-card-actions">
				<a class="button button-primary" href="<?php echo esc_url($this->get_premium_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Compare Premium', 'wc-order-splitter'); ?></a>
			</div>
		</div>

		<table class="widefat striped wcos-premium-matrix">
			<thead>
				<tr>
					<th><?php esc_html_e('Workflow', 'wc-order-splitter'); ?></th>
					<th><?php esc_html_e('Free', 'wc-order-splitter'); ?></th>
					<th><?php esc_html_e('Premium', 'wc-order-splitter'); ?></th>
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
				'name'     => esc_html__('Automation splitter', 'wc-order-splitter'),
				'type'     => 'title',
				'desc'     => esc_html__('Automation is available in Premium for stores that need repeatable split rules after checkout.', 'wc-order-splitter'),
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
				'desc'     => esc_html__('Split-order email controls are available in Premium for stores that need customer or administrator notification rules.', 'wc-order-splitter'),
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
