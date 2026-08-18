<?php
/**
 * Read-only original and split-order relationship labels.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

class WooCommerce_Order_Splitter_Edit_Order {

	/**
	 * Register native legacy and HPOS order-screen hooks.
	 */
	public function __construct() {
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_edit_order_relations'));

		add_filter('manage_edit-shop_order_columns', array($this, 'add_relation_column'), 20);
		add_action('manage_shop_order_posts_custom_column', array($this, 'render_legacy_relation_column'), 20, 2);

		add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_relation_column'), 20);
		add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_hpos_relation_column'), 20, 2);
	}

	/**
	 * Add a semantic relationship column to the order list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_relation_column($columns) {
		if ('yes' !== get_option('order_splitter_order_label', 'yes') || !is_array($columns)) {
			return $columns;
		}

		$result   = array();
		$inserted = false;

		foreach ($columns as $key => $label) {
			$result[$key] = $label;

			if ('order_status' === $key) {
				$result['wcos_relations'] = esc_html__('Order relations', 'wc-order-splitter');
				$inserted = true;
			}
		}

		if (!$inserted) {
			$result['wcos_relations'] = esc_html__('Order relations', 'wc-order-splitter');
		}

		return $result;
	}

	/**
	 * Render the legacy posts-table relationship column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Legacy order post ID.
	 * @return void
	 */
	public function render_legacy_relation_column($column, $post_id) {
		if ('wcos_relations' !== $column) {
			return;
		}

		$this->render_relation_links(wc_get_order(absint($post_id)));
	}

	/**
	 * Render the HPOS relationship column.
	 *
	 * @param string         $column Column key.
	 * @param WC_Order|mixed $order  Order object.
	 * @return void
	 */
	public function render_hpos_relation_column($column, $order) {
		if ('wcos_relations' !== $column) {
			return;
		}

		if (!$order instanceof WC_Order) {
			$order = wc_get_order(absint($order));
		}

		$this->render_relation_links($order);
	}

	/**
	 * Render relationship links below the order details on the edit screen.
	 *
	 * @param WC_Order|mixed $order Current order.
	 * @return void
	 */
	public function render_edit_order_relations($order) {
		if ('yes' !== get_option('order_splitter_order_label', 'yes') || !$order instanceof WC_Order) {
			return;
		}

		$relations = $this->get_relations($order);

		if (!$relations['original'] && empty($relations['children'])) {
			return;
		}
		?>
		<div class="order_data_column" style="width: 100%; clear: both; padding-top: 12px;">
			<h3><?php esc_html_e('Order relations', 'wc-order-splitter'); ?></h3>
			<?php $this->render_relation_list($relations); ?>
		</div>
		<?php
	}

	/**
	 * Render compact relationship links for an order-list cell.
	 *
	 * @param WC_Order|mixed $order Order object.
	 * @return void
	 */
	private function render_relation_links($order) {
		if ('yes' !== get_option('order_splitter_order_label', 'yes') || !$order instanceof WC_Order) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		$relations = $this->get_relations($order);

		if (!$relations['original'] && empty($relations['children'])) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		$this->render_relation_list($relations);
	}

	/**
	 * Render accessible relationship labels and links.
	 *
	 * @param array $relations Normalized relationship data.
	 * @return void
	 */
	private function render_relation_list(array $relations) {
		if ($relations['original']) {
			echo '<div><strong>' . esc_html__('Original:', 'wc-order-splitter') . '</strong> ';
			$this->render_order_reference($relations['original']);
			echo '</div>';
		}

		if (!empty($relations['children'])) {
			echo '<div><strong>' . esc_html__('Split orders:', 'wc-order-splitter') . '</strong> ';

			$first = true;
			foreach ($relations['children'] as $child_id) {
				if (!$first) {
					echo esc_html__(', ', 'wc-order-splitter');
				}

				$this->render_order_reference($child_id);
				$first = false;
			}

			echo '</div>';
		}
	}

	/**
	 * Render a related order using its public order number when resolvable.
	 *
	 * @param int $order_id Related order ID.
	 * @return void
	 */
	private function render_order_reference($order_id) {
		$related = wc_get_order(absint($order_id));

		if (!$related instanceof WC_Order) {
			echo '<span>#' . esc_html(absint($order_id)) . '</span>';
			return;
		}

		printf(
			'<a href="%1$s">#%2$s</a>',
			esc_url($related->get_edit_order_url()),
			esc_html($related->get_order_number())
		);
	}

	/**
	 * Normalize existing legacy relationship metadata.
	 *
	 * @param WC_Order $order Order.
	 * @return array{original:int,children:int[]}
	 */
	private function get_relations(WC_Order $order) {
		$original = absint($order->get_meta('yoos_original_order', true));
		$value    = (string) $order->get_meta('yoos_splitted_order', true);
		$children = array();

		foreach (explode(',', $value) as $candidate) {
			$candidate = absint(trim($candidate));

			if ($candidate && $candidate !== $order->get_id()) {
				$children[] = $candidate;
			}
		}

		$children = array_values(array_unique($children));
		sort($children, SORT_NUMERIC);

		if ($original === $order->get_id()) {
			$original = 0;
		}

		return array(
			'original' => $original,
			'children' => $children,
		);
	}
}

new WooCommerce_Order_Splitter_Edit_Order();
