<?php

defined('ABSPATH') || exit;

final class WC_Order_Splitter_Mutation_Controller {
	private $engine;

	public function __construct() {
		$this->engine = new WC_Order_Splitter_Order_Mutation_Engine();

		add_action('wp_ajax_get_order_items', array($this, 'get_order_items'));
		add_action('wp_ajax_preview_order_split', array($this, 'preview_split'));
		add_action('wp_ajax_split_order', array($this, 'split_order'));
		add_action('wp_ajax_split_order_by_category', array($this, 'split_order_by_category'));
		add_action('wp_ajax_split_order_by_stock_status', array($this, 'split_order_by_stock_status'));
		add_action('woocommerce_order_action_yoos_duplicate_order', array($this, 'duplicate_order'));
		add_action('woocommerce_order_action_yoos_return_order', array($this, 'return_order'));
		add_action('wp_ajax_yoos_merge_order_preview', array($this, 'preview_merge'));
		add_action('wp_ajax_yoos_merge_order_action', array($this, 'merge_order'));
		add_action('wp_ajax_yoos_handle_bulk_action', array($this, 'bulk_return'));
		add_action('wc_order_splitter_return_order', array($this, 'process_queued_return'), 10, 2);
	}

	public function get_order_items() {
		$this->verify_split_nonce();
		try {
			$order = $this->get_order_from_request();
			WC_Order_Splitter_Mutation_Support::assert_can_manage_order($order);
			$items = array();
			foreach ($order->get_items('line_item') as $item_id => $item) {
				$product = $item->get_product();
				$category_names = array();
				$stock_status = '';
				if ($product) {
					$product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
					$terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
					if (!is_wp_error($terms)) {
						$category_names = array_values(array_unique((array) $terms));
					}
					$stock_status = $product->get_stock_status();
				}

				$stock_labels = array(
					'instock'     => __('In stock', 'wc-order-splitter'),
					'outofstock'  => __('Out of stock', 'wc-order-splitter'),
					'onbackorder' => __('On backorder', 'wc-order-splitter'),
					'onpreorder'  => __('On preorder', 'wc-order-splitter'),
				);
				$items[] = array(
					'id'           => (int) $item_id,
					'sku'          => $product ? $product->get_sku() : '',
					'name'         => $item->get_name(),
					'quantity'     => $item->get_quantity(),
					'category'     => empty($category_names) ? __('Uncategorized', 'wc-order-splitter') : implode(', ', $category_names),
					'stock_status' => isset($stock_labels[$stock_status]) ? $stock_labels[$stock_status] : ($stock_status ? $stock_status : __('Unknown', 'wc-order-splitter')),
				);
			}
			wp_send_json_success($items);
		} catch (Throwable $error) {
			$this->send_error($error);
		}
	}

	public function preview_split() {
		$this->verify_split_nonce();
		try {
			$order = $this->get_order_from_request();
			$mode = $this->get_split_mode();
			$plan = $this->build_plan($order, $mode);
			$policies = $this->split_policies();
			$preview = $this->engine->preview_split($order, $plan, $policies);
			$plan_hash = $this->plan_hash($mode, $plan, $policies);
			$preview['mode'] = $mode;
			$preview['plan_hash'] = $plan_hash;
			$preview['confirm_nonce'] = wp_create_nonce('wc_order_splitter_confirm_' . $order->get_id() . '_' . $plan_hash);
			$preview['idempotency_key'] = wp_generate_uuid4();
			wp_send_json_success($preview);
		} catch (Throwable $error) {
			$this->send_error($error);
		}
	}

	public function split_order() {
		$this->process_split('default');
	}

	public function split_order_by_category() {
		$this->process_split('category');
	}

	public function split_order_by_stock_status() {
		$this->process_split('stock-status');
	}

	public function duplicate_order($order) {
		try {
			$new_order = $this->engine->duplicate($order);
			wp_safe_redirect(add_query_arg('wcos_action_tip', 'duplicate', $new_order->get_edit_order_url()));
			exit;
		} catch (Throwable $error) {
			$order->add_order_note(sprintf(__('Duplicate failed: %s', 'wc-order-splitter'), $error->getMessage()), false);
			wp_safe_redirect($order->get_edit_order_url());
			exit;
		}
	}

	public function return_order($order) {
		try {
			$original = $this->engine->return_split_order($order);
			if ($original instanceof WC_Order) {
				WC_Order_Splitter_Charge_Integrity::normalize_after_return($original);
			}
			$url = $original ? $original->get_edit_order_url() : $this->orders_list_url();
			wp_safe_redirect(add_query_arg('wcos_action_tip', 'return', $url));
			exit;
		} catch (Throwable $error) {
			$order->add_order_note(sprintf(__('Return failed: %s', 'wc-order-splitter'), $error->getMessage()), false);
			wp_safe_redirect($order->get_edit_order_url());
			exit;
		}
	}

	public function preview_merge() {
		$this->verify_merge_nonce();
		try {
			list($source, $target) = $this->get_merge_orders();
			$this->engine->assert_merge_compatible($source, $target);
			$token = wp_create_nonce('wc_order_splitter_merge_confirm_' . $source->get_id() . '_' . $target->get_id());
			wp_send_json_success(array(
				'source_order_id' => $source->get_id(),
				'source_order_number' => $source->get_order_number(),
				'target_order_id' => $target->get_id(),
				'target_order_number' => $target->get_order_number(),
				'currency' => $source->get_currency(),
				'source_total' => $source->get_total(),
				'target_total' => $target->get_total(),
				'combined_total' => WC_Order_Splitter_Mutation_Support::decimal((float) $source->get_total() + (float) $target->get_total()),
				'source_items' => count($source->get_items('line_item')),
				'target_items' => count($target->get_items('line_item')),
				'confirm_nonce' => $token,
			));
		} catch (Throwable $error) {
			$this->send_error($error, true);
		}
	}

	public function merge_order() {
		$this->verify_merge_nonce();
		try {
			list($source, $target) = $this->get_merge_orders();
			$confirm_nonce = isset($_POST['confirm_nonce']) ? sanitize_text_field(wp_unslash($_POST['confirm_nonce'])) : '';
			if (!$confirm_nonce || !wp_verify_nonce($confirm_nonce, 'wc_order_splitter_merge_confirm_' . $source->get_id() . '_' . $target->get_id())) {
				throw new WC_Order_Splitter_Mutation_Exception(__('The merge preview expired or the target changed. Preview the merge again.', 'wc-order-splitter'));
			}
			$merged = $this->engine->merge($source, $target);
			wp_send_json_success(array(
				'message' => __('Orders merged successfully.', 'wc-order-splitter'),
				'redirect_url' => add_query_arg('wcos_action_tip', 'merge', $merged->get_edit_order_url()),
			));
		} catch (Throwable $error) {
			$this->send_error($error, true);
		}
	}

	public function bulk_return() {
		check_ajax_referer('yoos_handle_bulk_action', 'security');
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'wc-order-splitter')));
		}

		$order_ids = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_values(array_unique(array_map('absint', wp_unslash($_POST['order_ids'])))) : array();
		if (empty($order_ids)) {
			wp_send_json_error(array('message' => __('No orders selected.', 'wc-order-splitter')));
		}

		$queued = 0;
		$user_id = get_current_user_id();
		foreach ($order_ids as $order_id) {
			$order = wc_get_order($order_id);
			if (!$order || !WC_Order_Splitter_Mutation_Support::current_user_can_manage_order($order_id)) {
				continue;
			}
			if (function_exists('as_enqueue_async_action')) {
				$action_id = as_enqueue_async_action('wc_order_splitter_return_order', array($order_id, $user_id), 'wc-order-splitter', true);
				if ($action_id) {
					$queued++;
				}
			} else {
				try {
					$original = $this->engine->return_split_order($order);
					if ($original instanceof WC_Order) {
						WC_Order_Splitter_Charge_Integrity::normalize_after_return($original);
					}
					$queued++;
				} catch (Throwable $error) {
					// Continue so one invalid order does not prevent the remaining selections.
				}
			}
		}

		wp_send_json_success(array(
			'message' => sprintf(
				/* translators: %d: number of queued orders. */
				_n('%d return operation was queued.', '%d return operations were queued.', $queued, 'wc-order-splitter'),
				$queued
			),
			'queued' => $queued,
		));
	}

	public function process_queued_return($order_id, $user_id) {
		$order = wc_get_order(absint($order_id));
		if (!$order) {
			return;
		}
		$previous_user = get_current_user_id();
		if ($user_id) {
			wp_set_current_user(absint($user_id));
		}
		try {
			$original = $this->engine->return_split_order($order);
			if ($original instanceof WC_Order) {
				WC_Order_Splitter_Charge_Integrity::normalize_after_return($original);
			}
		} catch (Throwable $error) {
			$order->add_order_note(sprintf(__('Queued return failed: %s', 'wc-order-splitter'), $error->getMessage()), false);
			throw $error;
		} finally {
			wp_set_current_user($previous_user);
		}
	}

	private function process_split($mode) {
		$this->verify_split_nonce();
		try {
			$order = $this->get_order_from_request();
			WC_Order_Splitter_Mutation_Support::assert_can_manage_order($order);
			$idempotency_key = isset($_POST['idempotency_key']) ? sanitize_text_field(wp_unslash($_POST['idempotency_key'])) : '';
			if ($idempotency_key) {
				$replay = $this->get_split_replay($order, $idempotency_key);
				if ($replay) {
					wp_send_json_success($replay);
				}
			}
			$requested_mode = $this->get_split_mode();
			if ($mode !== $requested_mode) {
				throw new WC_Order_Splitter_Mutation_Exception(__('The split mode changed after preview. Preview the split again.', 'wc-order-splitter'));
			}
			$plan = $this->build_plan($order, $mode);
			$policies = $this->split_policies();
			$plan_hash = $this->plan_hash($mode, $plan, $policies);
			$confirm_nonce = isset($_POST['confirm_nonce']) ? sanitize_text_field(wp_unslash($_POST['confirm_nonce'])) : '';
			if (!$confirm_nonce || !wp_verify_nonce($confirm_nonce, 'wc_order_splitter_confirm_' . $order->get_id() . '_' . $plan_hash)) {
				throw new WC_Order_Splitter_Mutation_Exception(__('The split preview expired or the allocation changed. Preview the split again.', 'wc-order-splitter'));
			}
			if (!$idempotency_key) {
				throw new WC_Order_Splitter_Mutation_Exception(__('The split operation is missing its idempotency key. Preview the split again.', 'wc-order-splitter'));
			}
			$result = $this->engine->split($order, $plan, $policies, $idempotency_key);
			WC_Order_Splitter_Charge_Integrity::normalize_after_split($order, $result['new_order_ids']);
			wp_send_json_success($result);
		} catch (Throwable $error) {
			$this->send_error($error);
		}
	}

	private function get_split_replay($order, $idempotency_key) {
		$meta_key = '_wc_order_splitter_idempotency_' . hash('sha256', sanitize_text_field($idempotency_key));
		$data = $order->get_meta($meta_key, true);
		if (!is_array($data) || 'split' !== (isset($data['type']) ? $data['type'] : '') || empty($data['order_ids'])) {
			return null;
		}

		$order_ids = array_values(array_map('absint', (array) $data['order_ids']));
		foreach ($order_ids as $order_id) {
			if (!wc_get_order($order_id)) {
				return null;
			}
		}

		return array(
			'operation_id' => isset($data['operation_id']) ? $data['operation_id'] : '',
			'new_order_ids' => $order_ids,
			'idempotent_replay' => true,
		);
	}

	private function build_plan($order, $mode) {
		if ('category' === $mode) {
			return $this->engine->build_category_plan($order);
		}
		if ('stock-status' === $mode) {
			return $this->engine->build_stock_status_plan($order);
		}

		$raw = isset($_POST['split_data']) && is_array($_POST['split_data']) ? wc_clean(wp_unslash($_POST['split_data'])) : array();
		$plan = array();
		foreach ($raw as $item_id => $data) {
			$item_id = absint($item_id);
			$quantity = isset($data['quantity']) ? wc_format_decimal($data['quantity'], 6) : 0;
			$destination = isset($data['order']) ? sanitize_key($data['order']) : '';
			if ($item_id && (float) $quantity > 0 && $destination) {
				if (!isset($plan[$destination])) {
					$plan[$destination] = array();
				}
				$plan[$destination][$item_id] = $quantity;
			}
		}
		return $plan;
	}

	private function get_split_mode() {
		$mode = isset($_POST['split_mode']) ? sanitize_key(wp_unslash($_POST['split_mode'])) : 'default';
		return in_array($mode, array('default', 'category', 'stock-status'), true) ? $mode : 'default';
	}

	private function split_policies() {
		return array(
			'shipping_policy' => get_option('order_splitter_shipping_policy', WC_Order_Splitter_Order_Mutation_Engine::SHIPPING_KEEP_ON_ORIGINAL),
			'tax_policy' => WC_Order_Splitter_Order_Mutation_Engine::TAX_PRESERVE_HISTORICAL,
			'email_policy' => WC_Order_Splitter_Order_Mutation_Engine::EMAIL_SUPPRESS_ALL_CHILDREN,
			'status_policy' => WC_Order_Splitter_Order_Mutation_Engine::STATUS_PRESERVE,
		);
	}

	private function plan_hash($mode, $plan, $policies) {
		return hash('sha256', wp_json_encode(array($mode, $plan, $policies)));
	}

	private function get_order_from_request() {
		$order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Order not found.', 'wc-order-splitter'));
		}
		return $order;
	}

	private function get_merge_orders() {
		$source_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$target_id = isset($_POST['merge_order_id']) ? absint(wp_unslash($_POST['merge_order_id'])) : 0;
		$source = $source_id ? wc_get_order($source_id) : false;
		$target = $target_id ? wc_get_order($target_id) : false;
		if (!$source || !$target) {
			throw new WC_Order_Splitter_Mutation_Exception(__('The source or target order could not be found.', 'wc-order-splitter'));
		}
		return array($source, $target);
	}

	private function verify_split_nonce() {
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!$nonce || !wp_verify_nonce($nonce, 'split_order_nonce')) {
			wp_send_json_error(array('message' => __('Nonce verification failed.', 'wc-order-splitter')));
		}
	}

	private function verify_merge_nonce() {
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!$nonce || !wp_verify_nonce($nonce, 'yoos_merge_order_nonce')) {
			wp_send_json_error(array('message' => __('Nonce verification failed.', 'wc-order-splitter')));
		}
	}

	private function send_error(Throwable $error, $object_shape = false) {
		$message = $error->getMessage();
		if ($object_shape) {
			wp_send_json_error(array('message' => $message));
		}
		wp_send_json_error($message);
	}

	private function orders_list_url() {
		if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
			return admin_url('admin.php?page=wc-orders');
		}
		return admin_url('edit.php?post_type=shop_order');
	}
}

new WC_Order_Splitter_Mutation_Controller();
