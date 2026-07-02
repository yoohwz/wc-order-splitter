<?php

defined('ABSPATH') || exit;

if ( ! class_exists( 'YoOhw_WooCommerce_Settings_Tabs_Reorder' ) ) {
	class YoOhw_WooCommerce_Settings_Tabs_Reorder {
		public function __construct() {
			// Run late so we can fix bad outputs from earlier filters.
			add_filter( 'woocommerce_settings_tabs_array', array( $this, 'reorder_woocommerce_settings_tabs' ), 999 );
		}

		/**
		 * Reorder WooCommerce settings tabs safely.
		 *
		 * @param array|null $tabs
		 * @return array Always an array to avoid fatals in Woo views.
		 */
		public function reorder_woocommerce_settings_tabs( $tabs ) {
			// Coerce to array to avoid null propagating to the view.
			if ( ! is_array( $tabs ) ) {
				$tabs = array();
			}

			// If nothing to reorder, return an array (prevents fatal).
			if ( empty( $tabs ) ) {
				return $tabs; // still an array
			}

			// Only on WC settings screen (extra safety; if unavailable, still return array).
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( ! $screen || $screen->base !== 'woocommerce_page_wc-settings' ) {
					return $tabs;
				}
			}

			// Keep both 'checkout' (older) and 'payments' (newer) IDs.
			$desired_order = array(
				'general',
				'products',
				'tax',
				'shipping',
				'orders',          // custom (if present)
				'checkout',
				'payments',
				'account',
				'loyalty',         // custom (if present)
				'email',
				'integration',
				'site-visibility', // custom (if present)
				'advanced',
			);

			$reordered = array();

			foreach ( $desired_order as $key ) {
				if ( array_key_exists( $key, $tabs ) ) {
					$reordered[ $key ] = $tabs[ $key ];
					unset( $tabs[ $key ] );
				}
			}

			// Append anything left (from other plugins).
			foreach ( $tabs as $key => $label ) {
				$reordered[ $key ] = $label;
			}

			return $reordered; // always array
		}
	}

	new YoOhw_WooCommerce_Settings_Tabs_Reorder();
}
