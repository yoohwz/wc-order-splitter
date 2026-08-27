<?php

defined('ABSPATH') || exit;

/**
 * Shared presentation coordinator for compact workflow launchers in Order actions.
 */
final class WCOS_Order_Actions_Launcher_Row {
	const HOOK = 'woocommerce_order_actions_end';

	private $renderers = array();
	private $registered = false;

	public function __construct(array $renderers) {
		foreach ($renderers as $renderer) {
			if (is_callable($renderer)) {
				$this->renderers[] = $renderer;
			}
		}
	}

	public function register_hooks() {
		if ($this->registered) {
			return false;
		}
		add_action(self::HOOK, array($this, 'render_launcher_row'), 20, 1);
		$this->registered = true;
		return true;
	}

	public function unregister_hooks() {
		remove_action(self::HOOK, array($this, 'render_launcher_row'), 20);
		$this->registered = false;
		return true;
	}

	public function render_launcher_row($order_id) {
		$order = wc_get_order(absint($order_id));
		if (!$order instanceof WC_Order) {
			return;
		}

		$controls = array();
		foreach ($this->renderers as $renderer) {
			ob_start();
			call_user_func($renderer, $order);
			$control = trim((string) ob_get_clean());
			if ('' !== $control) {
				$controls[] = $control;
			}
		}

		if (empty($controls)) {
			return;
		}

		$row_class = 1 === count($controls) ? ' wcos-order-actions-launcher-row--single' : '';
		echo '<li class="wide wcos-order-actions-launcher-row' . esc_attr($row_class) . '">';
		echo '<div class="wcos-order-actions-launchers" role="group" aria-label="' . esc_attr__('Order Splitter actions', 'wc-order-splitter') . '">';
		foreach ($controls as $control) {
			echo '<div class="wcos-order-actions-launcher-slot">';
			echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted controller-rendered markup.
			echo '</div>';
		}
		echo '</div>';
		echo '</li>';
	}
}
