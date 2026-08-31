<?php

defined('ABSPATH') || exit;

/**
 * Gate-aware admin transport/UI for Category and Stock-status Split.
 *
 * The class may be loaded in every build, but bootstrap() registers hooks only
 * while the global Split workflow and at least one server-built strategy are
 * enabled. Request methods retain their own gate/nonce/authorization checks, so
 * route registration is never mutation authority by itself.
 */
final class WCOS_Split_Strategy_Admin_Controller {
	const REVIEW_ACTION = 'wcos_split_strategy_review';
	const CONFIRM_ACTION = 'wcos_split_strategy_confirm';
	const EXECUTE_ACTION = 'wcos_split_strategy_execute';

	private static $instance = null;
	private $registered = false;
	private $current_order = null;

	public static function bootstrap() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT)
			|| (!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY)
				&& !WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS))) {
			return null;
		}
		if (!self::$instance instanceof self) {
			self::$instance = new self();
		}
		self::$instance->register_hooks();
		return self::$instance;
	}

	public function register_hooks() {
		if ($this->registered
			|| !WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT)
			|| (!WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY)
				&& !WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS))) {
			return false;
		}
		add_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		add_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		add_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		add_action('woocommerce_order_item_add_action_buttons', array($this, 'render_launcher'), 30, 1);
		add_action('admin_footer', array($this, 'render_dialogs'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		$this->registered = true;
		return true;
	}

	public function unregister_hooks() {
		remove_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		remove_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		remove_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		remove_action('woocommerce_order_item_add_action_buttons', array($this, 'render_launcher'), 30);
		remove_action('admin_footer', array($this, 'render_dialogs'));
		remove_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		$this->registered = false;
		$this->current_order = null;
		return true;
	}

	public function review_request(array $request) {
		$strategy = $this->strategy_from_request($request);
		$this->assert_strategy_enabled($strategy);
		$order_id = isset($request['order_id']) ? absint($request['order_id']) : 0;
		$order = $this->authorized_order($request, $order_id);

		try {
			$review = (new WCOS_Split_Strategy_WooCommerce_Adapter())->review($order, $strategy);
		} catch (InvalidArgumentException $exception) {
			throw new WCOS_Split_Transport_Exception('invalid_strategy_review', $exception->getMessage(), 422, false);
		} catch (RuntimeException $exception) {
			throw new WCOS_Split_Transport_Exception('strategy_review_failed', $exception->getMessage(), 409, false);
		}

		if (empty($review['supported'])) {
			throw new WCOS_Split_Transport_Exception(
				'preflight_' . (isset($review['reason']) ? sanitize_key((string) $review['reason']) : 'unsupported'),
				isset($review['message']) ? (string) $review['message'] : __('This order is not supported by the selected Split strategy.', 'wc-order-splitter'),
				409,
				false,
				array('strategy' => $strategy)
			);
		}

		try {
			$stored = WCOS_Split_Strategy_Review_Store::create($order, $strategy, $review, get_current_user_id());
		} catch (WCOS_Split_Strategy_Review_Exception $exception) {
			throw $this->review_transport_exception($exception);
		} catch (RuntimeException $exception) {
			throw new WCOS_Split_Transport_Exception('review_store_failed', $exception->getMessage(), 500, true);
		}

		return array(
			'review_id' => $stored['review_id'],
			'review_token' => $stored['review_token'],
			'expires_at' => $stored['expires_at'],
			'strategy' => $strategy,
			'order_id' => $order->get_id(),
			'review' => $review,
		);
	}

	public function confirm_request(array $request) {
		$strategy = $this->strategy_from_request($request);
		$this->assert_strategy_enabled($strategy);
		$order_id = isset($request['order_id']) ? absint($request['order_id']) : 0;
		$order = $this->authorized_order($request, $order_id);
		$review_id = isset($request['review_id']) ? sanitize_key((string) $request['review_id']) : '';
		$review_token = isset($request['review_token']) ? (string) $request['review_token'] : '';
		$source_bucket_key = isset($request['source_bucket_key']) ? sanitize_key((string) $request['source_bucket_key']) : '';
		if ('' === $source_bucket_key) {
			throw new WCOS_Split_Transport_Exception('source_bucket_required', __('Choose one reviewed strategy bucket to remain on the source order.', 'wc-order-splitter'), 422, false);
		}

		try {
			$review = WCOS_Split_Strategy_Review_Store::verify(
				$order,
				$strategy,
				$review_id,
				$review_token,
				get_current_user_id()
			);
		} catch (WCOS_Split_Strategy_Review_Exception $exception) {
			throw $this->review_transport_exception($exception);
		}

		try {
			$confirmation = WCOS_Split_Strategy_Confirmation_Store::create(
				$order,
				$strategy,
				$review,
				$source_bucket_key,
				get_current_user_id()
			);
		} catch (WCOS_Split_Strategy_Confirmation_Exception $exception) {
			throw $this->confirmation_transport_exception($exception);
		} catch (InvalidArgumentException $exception) {
			throw new WCOS_Split_Transport_Exception('invalid_confirmation', $exception->getMessage(), 422, false);
		} catch (RuntimeException $exception) {
			throw new WCOS_Split_Transport_Exception('confirmation_store_failed', $exception->getMessage(), 500, true);
		}

		/*
		 * Review is single-use authority. If another Confirm request consumed it
		 * after our verify() but before this delete, discard the unexposed candidate
		 * confirmation so one Review cannot yield two usable operations.
		 */
		if (!WCOS_Split_Strategy_Review_Store::consume($review_id)) {
			WCOS_Split_Strategy_Confirmation_Store::delete($confirmation['operation_id']);
			throw new WCOS_Split_Transport_Exception(
				'review_already_consumed',
				__('This Split strategy Review was already consumed by another confirmation request. Review the order again.', 'wc-order-splitter'),
				409,
				false
			);
		}

		return array(
			'operation_id' => $confirmation['operation_id'],
			'confirmation_token' => $confirmation['confirmation_token'],
			'expires_at' => $confirmation['expires_at'],
			'strategy' => $strategy,
			'order_id' => $order->get_id(),
			'source_bucket_key' => $source_bucket_key,
		);
	}

	public function execute_request(array $request) {
		$strategy = $this->strategy_from_request($request);
		$this->assert_strategy_enabled($strategy);
		$order_id = isset($request['order_id']) ? absint($request['order_id']) : 0;
		$operation_id = isset($request['operation_id']) ? sanitize_key((string) $request['operation_id']) : '';
		$order = $this->authorized_order($request, $order_id, $operation_id);
		$confirmation_token = isset($request['confirmation_token']) ? (string) $request['confirmation_token'] : '';

		try {
			$confirmation = WCOS_Split_Strategy_Confirmation_Store::verify(
				$order,
				$operation_id,
				$confirmation_token,
				get_current_user_id()
			);
		} catch (WCOS_Split_Strategy_Confirmation_Exception $exception) {
			throw $this->confirmation_transport_exception($exception);
		}

		try {
			$confirmed_strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy(
				isset($confirmation['strategy']) ? $confirmation['strategy'] : ''
			);
		} catch (InvalidArgumentException $exception) {
			throw new WCOS_Split_Transport_Exception('confirmation_strategy_mismatch', __('The Split strategy confirmation has invalid semantic strategy authority.', 'wc-order-splitter'), 409, false);
		}
		if ($confirmed_strategy !== $strategy) {
			throw new WCOS_Split_Transport_Exception(
				'confirmation_strategy_mismatch',
				__('The confirmed Split strategy does not match the requested strategy.', 'wc-order-splitter'),
				409,
				false
			);
		}

		try {
			$children = (new WCOS_Mutation_Gateway())->split_strategy(
				$order,
				$strategy,
				$confirmation['plan'],
				$operation_id,
				$confirmation['price_precision'],
				$confirmation
			);
		} catch (WCOS_Split_Preflight_Exception $exception) {
			throw new WCOS_Split_Transport_Exception(
				'preflight_' . $exception->get_reason(),
				$exception->getMessage(),
				409,
				false,
				array('preflight' => $exception->get_report())
			);
		} catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
			throw new WCOS_Split_Transport_Exception(
				'manual_reconciliation_required',
				__('The strategy Split detected an unexpected physical-stock side effect and requires manual reconciliation.', 'wc-order-splitter'),
				409,
				false
			);
		} catch (RuntimeException $exception) {
			$message = $exception->getMessage();
			if (false !== strpos($message, 'Another order mutation is already in progress')) {
				throw new WCOS_Split_Transport_Exception('operation_busy', $message, 409, true);
			}
			if (false !== strpos($message, 'different mutation request')
				|| false !== strpos($message, 'does not match the durable operation journal')) {
				throw new WCOS_Split_Transport_Exception('operation_conflict', $message, 409, false);
			}
			throw new WCOS_Split_Transport_Exception('strategy_split_failed', $message, 409, true);
		}

		$result_children = array();
		foreach ($children as $child) {
			if (!$child instanceof WC_Order) {
				continue;
			}
			$result_children[] = array(
				'id' => $child->get_id(),
				'number' => (string) $child->get_order_number(),
				'status' => (string) $child->get_status(),
				'edit_url' => method_exists($child, 'get_edit_order_url') ? esc_url_raw((string) $child->get_edit_order_url()) : '',
			);
		}

		return array(
			'operation_id' => $operation_id,
			'status' => 'completed',
			'strategy' => $strategy,
			'source_order_id' => $order->get_id(),
			'children' => $result_children,
		);
	}

	public function ajax_review() {
		$this->send_ajax_request('review_request');
	}

	public function ajax_confirm() {
		$this->send_ajax_request('confirm_request');
	}

	public function ajax_execute() {
		$this->send_ajax_request('execute_request');
	}

	public function render_launcher($order) {
		if (!$order instanceof WC_Order || !WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT)) {
			return;
		}
		try {
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $order);
			$this->assert_status_enabled($order);
			$preflight = (new WCOS_Split_WooCommerce_Adapter())->preflight($order);
			if (empty($preflight['supported'])) {
				return;
			}
		} catch (Throwable $throwable) {
			return;
		}

		$this->current_order = $order;
		foreach ($this->enabled_strategies() as $strategy) {
			$dialog_id = $this->dialog_id($order->get_id(), $strategy);
			$description_id = $dialog_id . '-launcher-description';
			echo '<button type="button" class="button wcos-strategy-launcher" data-strategy="' . esc_attr($strategy) . '" aria-haspopup="dialog" aria-controls="' . esc_attr($dialog_id) . '" aria-describedby="' . esc_attr($description_id) . '">';
			echo esc_html($this->launcher_label($strategy));
			echo '</button>';
			echo '<span id="' . esc_attr($description_id) . '" class="description wcos-strategy-launcher-description">';
			echo esc_html($this->launcher_description($strategy));
			echo '</span>';
		}
	}

	public function render_dialogs() {
		if (!$this->current_order instanceof WC_Order) {
			return;
		}
		foreach ($this->enabled_strategies() as $strategy) {
			echo $this->dialog_html($this->current_order, $strategy); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function enqueue_assets() {
		if (empty($this->enabled_strategies()) || !$this->is_order_edit_screen()) {
			return;
		}
		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		wp_enqueue_style('wcos-split-strategy-admin', plugins_url('css/p2-split-strategy-admin.css', $plugin_file), array(), WC_ORDER_SPLITTER_VERSION);
		wp_enqueue_script('wcos-split-strategy-admin', plugins_url('js/p2-split-strategy-admin.js', $plugin_file), array(), WC_ORDER_SPLITTER_VERSION, true);
		wp_localize_script(
			'wcos-split-strategy-admin',
			'wcosSplitStrategyStrings',
			array(
				'reviewing' => __('Reviewing current strategy buckets…', 'wc-order-splitter'),
				'reviewReady' => __('Choose the one bucket that must remain on the source order.', 'wc-order-splitter'),
				'confirming' => __('Confirming the frozen strategy plan…', 'wc-order-splitter'),
				'confirmationReady' => __('The plan is frozen. Acknowledge the policy and execute when ready.', 'wc-order-splitter'),
				'executing' => __('Executing strategy Split…', 'wc-order-splitter'),
				'completed' => __('Strategy Split completed successfully.', 'wc-order-splitter'),
				'requestFailed' => __('The strategy Split request could not be completed.', 'wc-order-splitter'),
				'chooseBucket' => __('Choose a source bucket before confirming.', 'wc-order-splitter'),
				'bucketLines' => __('product lines', 'wc-order-splitter'),
				'bucketQuantity' => __('total quantity', 'wc-order-splitter'),
				'childOrder' => __('Child order', 'wc-order-splitter'),
				'reloadOrder' => __('Reload source order', 'wc-order-splitter'),
				'frozenStatus' => __('Frozen source and child status:', 'wc-order-splitter'),
				'shippingReplicated' => __('Historical shipping rows are replicated to every child.', 'wc-order-splitter'),
				'shippingSourceOnly' => __('Shipping remains only on the source.', 'wc-order-splitter'),
				'sourceOwnership' => __('Fees, coupons, refunds, and payment context remain source-owned.', 'wc-order-splitter'),
				'refundLinesPinned' => __('refund-affected line(s) are pinned to the source.', 'wc-order-splitter'),
				'nestedParent' => __('Nested Split records the actual source as immediate parent.', 'wc-order-splitter'),
			)
		);
	}

	public function dialog_html(WC_Order $order, $strategy) {
		$strategy = WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		if (!WCOS_Split_Strategy_Gates::enabled($strategy)) {
			return '';
		}
		$dialog_id = $this->dialog_id($order->get_id(), $strategy);
		$title_id = $dialog_id . '-title';
		$description_id = $dialog_id . '-description';
		$bucket_legend_id = $dialog_id . '-bucket-legend';
		$ack_id = $dialog_id . '-ack';
			$nonce = wp_create_nonce('wcos_split_strategy_order_' . $order->get_id());
			$commercial_policy = WCOS_Split_Commercial_Policy::freeze($order);
			$shipping_label = WCOS_Split_Commercial_Policy::SHIPPING_REPLICATE_TO_EACH_CHILD === $commercial_policy['shipping']
				? __('Historical shipping rows are replicated to every child.', 'wc-order-splitter')
				: __('Shipping remains only on the source.', 'wc-order-splitter');

		ob_start();
		?>
		<div id="<?php echo esc_attr($dialog_id); ?>" class="wcos-strategy-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($title_id); ?>" aria-describedby="<?php echo esc_attr($description_id); ?>" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-strategy="<?php echo esc_attr($strategy); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-review-action="<?php echo esc_attr(self::REVIEW_ACTION); ?>" data-confirm-action="<?php echo esc_attr(self::CONFIRM_ACTION); ?>" data-execute-action="<?php echo esc_attr(self::EXECUTE_ACTION); ?>" hidden>
			<div class="wcos-strategy-dialog__backdrop" aria-hidden="true"></div>
			<div class="wcos-strategy-dialog__panel" tabindex="-1">
				<div class="wcos-strategy-dialog__header">
					<div>
						<h2 id="<?php echo esc_attr($title_id); ?>"><?php echo esc_html($this->dialog_title($strategy)); ?></h2>
						<p id="<?php echo esc_attr($description_id); ?>"><?php echo esc_html($this->dialog_description($strategy)); ?></p>
					</div>
					<button type="button" class="button-link wcos-strategy-close" aria-label="<?php esc_attr_e('Close strategy Split dialog', 'wc-order-splitter'); ?>"><span aria-hidden="true">×</span></button>
				</div>

				<form class="wcos-strategy-form" aria-busy="false" novalidate>
					<div class="wcos-strategy-policy">
						<h3><?php esc_html_e('How this Split works', 'wc-order-splitter'); ?></h3>
							<p><?php esc_html_e('Review the current buckets, then choose exactly one bucket to remain on the source order. Every other reviewed bucket becomes a separate child order and its eligible product lines move in full.', 'wc-order-splitter'); ?></p>
							<p class="wcos-strategy-commercial-summary"><?php echo esc_html(sprintf(__('Frozen source and child status: %1$s. %2$s Fees, coupons, refunds, and payment context remain source-owned; %3$d refund-affected line(s) are pinned to the source. Nested Split records the actual source as immediate parent.', 'wc-order-splitter'), wc_get_order_status_name($commercial_policy['source_status']), $shipping_label, count($commercial_policy['refund_affected_item_ids']))); ?></p>
					</div>

					<div class="wcos-strategy-review-controls">
						<button type="button" class="button wcos-strategy-review-button"><?php esc_html_e('Review current buckets', 'wc-order-splitter'); ?></button>
					</div>

					<section class="wcos-strategy-review" hidden>
						<h3><?php esc_html_e('Reviewed buckets', 'wc-order-splitter'); ?></h3>
						<p class="wcos-strategy-review-summary"></p>
						<fieldset class="wcos-strategy-buckets" aria-labelledby="<?php echo esc_attr($bucket_legend_id); ?>">
							<legend id="<?php echo esc_attr($bucket_legend_id); ?>"><?php esc_html_e('Choose the bucket to keep on the source order', 'wc-order-splitter'); ?></legend>
							<div class="wcos-strategy-bucket-options"></div>
						</fieldset>
						<button type="button" class="button button-secondary wcos-strategy-confirm-button" disabled><?php esc_html_e('Confirm selected source bucket', 'wc-order-splitter'); ?></button>
					</section>

					<section class="wcos-strategy-confirmation" hidden>
						<h3><?php esc_html_e('Confirm execution', 'wc-order-splitter'); ?></h3>
						<p class="wcos-strategy-confirmation-summary"></p>
						<label class="wcos-strategy-confirm-label" for="<?php echo esc_attr($ack_id); ?>">
							<input id="<?php echo esc_attr($ack_id); ?>" type="checkbox" class="wcos-strategy-confirm-checkbox">
								<span><?php esc_html_e('I understand that every eligible reviewed bucket except the selected source bucket will move to child order(s) using the frozen Review and commercial policy.', 'wc-order-splitter'); ?></span>
						</label>
						<button type="button" class="button button-primary wcos-strategy-execute-button" disabled><?php esc_html_e('Execute strategy Split', 'wc-order-splitter'); ?></button>
					</section>

					<div class="wcos-strategy-status" role="status" aria-live="polite"></div>
					<div class="wcos-strategy-error" role="alert" tabindex="-1" hidden></div>
					<div class="wcos-strategy-result" tabindex="-1" hidden></div>

					<div class="wcos-strategy-dialog__actions">
						<button type="button" class="button wcos-strategy-cancel"><?php esc_html_e('Close', 'wc-order-splitter'); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function strategy_from_request(array $request) {
		$strategy = isset($request['strategy']) ? sanitize_key((string) $request['strategy']) : '';
		try {
			return WCOS_Split_Strategy_WooCommerce_Adapter::normalize_strategy($strategy);
		} catch (InvalidArgumentException $exception) {
			throw new WCOS_Split_Transport_Exception('invalid_strategy', $exception->getMessage(), 422, false);
		}
	}

	private function assert_strategy_enabled($strategy) {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT)) {
			throw new WCOS_Split_Transport_Exception('workflow_disabled', __('Split is not enabled for production use.', 'wc-order-splitter'), 503, false);
		}
		if (!WCOS_Split_Strategy_Gates::enabled($strategy)) {
			throw new WCOS_Split_Transport_Exception('strategy_disabled', __('This Split strategy is not enabled for production use.', 'wc-order-splitter'), 503, false);
		}
	}

	private function authorized_order(array $request, $order_id, $operation_id = '') {
		if (!$order_id) {
			throw new WCOS_Split_Transport_Exception('invalid_order', __('A valid order ID is required.', 'wc-order-splitter'), 400, false);
		}
		$nonce = isset($request['nonce']) ? sanitize_text_field((string) $request['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'wcos_split_strategy_order_' . $order_id)) {
			throw new WCOS_Split_Transport_Exception('invalid_nonce', __('The Split strategy request failed nonce verification.', 'wc-order-splitter'), 403, false);
		}
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			throw new WCOS_Split_Transport_Exception('order_not_found', __('The source order could not be found.', 'wc-order-splitter'), 404, false);
		}
		try {
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $order);
		} catch (Throwable $throwable) {
			throw new WCOS_Split_Transport_Exception('authorization_failed', __('You are not allowed to use a Split strategy on this order.', 'wc-order-splitter'), 403, false);
		}
		if ('' === sanitize_key((string) $operation_id) || !is_array(WCOS_Operation_Journal::get($order, $operation_id))) {
			$this->assert_status_enabled($order);
		}
		return $order;
	}

	private function assert_status_enabled(WC_Order $order) {
		$policy = WCOS_Split_Commercial_Policy::freeze($order);
		if (!in_array($order->get_status(), (array) $policy['allowed_statuses'], true)) {
			throw new WCOS_Split_Transport_Exception('status_disabled', __('This order status is disabled in the Order Splitter settings.', 'wc-order-splitter'), 409, false);
		}
	}

	private function review_transport_exception(WCOS_Split_Strategy_Review_Exception $exception) {
		$statuses = array(
			'invalid_identity' => 400,
			'invalid_token' => 403,
			'owner_mismatch' => 403,
			'expired' => 410,
			'source_changed' => 409,
			'commercial_policy_changed' => 409,
			'source_missing' => 404,
			'review_invalid' => 409,
		);
		$reason = $exception->get_reason();
		return new WCOS_Split_Transport_Exception('review_' . $reason, $exception->getMessage(), isset($statuses[$reason]) ? $statuses[$reason] : 409, false);
	}

	private function confirmation_transport_exception(WCOS_Split_Strategy_Confirmation_Exception $exception) {
		$statuses = array(
			'invalid_identity' => 400,
			'invalid_token' => 403,
			'owner_mismatch' => 403,
			'expired' => 410,
			'source_changed' => 409,
			'source_missing' => 404,
			'review_mismatch' => 409,
			'review_invalid' => 409,
			'review_incomplete' => 409,
			'planner_policy_changed' => 409,
			'split_policy_changed' => 409,
			'commercial_policy_changed' => 409,
			'execution_policy_mismatch' => 409,
			'journal_mismatch' => 409,
			'manual_reconciliation' => 409,
			'operation_closed' => 409,
			'journal_incomplete' => 409,
			'authority_incomplete' => 409,
		);
		$reason = $exception->get_reason();
		return new WCOS_Split_Transport_Exception('confirmation_' . $reason, $exception->getMessage(), isset($statuses[$reason]) ? $statuses[$reason] : 409, false);
	}

	private function send_ajax_request($method) {
		try {
			wp_send_json_success($this->{$method}(wp_unslash($_POST)));
		} catch (WCOS_Split_Transport_Exception $exception) {
			$this->send_transport_error($exception);
		} catch (Throwable $throwable) {
			$this->send_transport_error(new WCOS_Split_Transport_Exception('strategy_transport_failed', __('The strategy Split request could not be completed.', 'wc-order-splitter'), 500, true));
		}
	}

	private function send_transport_error(WCOS_Split_Transport_Exception $exception) {
		wp_send_json_error(
			array(
				'code' => $exception->get_error_code(),
				'message' => $exception->getMessage(),
				'retryable' => $exception->is_retryable(),
				'context' => $exception->get_context(),
			),
			$exception->get_http_status()
		);
	}

	private function enabled_strategies() {
		$strategies = array();
		foreach (array(WCOS_Split_Strategy_Gates::CATEGORY, WCOS_Split_Strategy_Gates::STOCK_STATUS) as $strategy) {
			if (WCOS_Split_Strategy_Gates::enabled($strategy)) {
				$strategies[] = $strategy;
			}
		}
		return $strategies;
	}

	private function dialog_id($order_id, $strategy) {
		return 'wcos-strategy-dialog-' . absint($order_id) . '-' . sanitize_key($strategy);
	}

	private function launcher_label($strategy) {
		return WCOS_Split_Strategy_Gates::CATEGORY === $strategy
			? __('Split by category', 'wc-order-splitter')
			: __('Split by stock status', 'wc-order-splitter');
	}

	private function launcher_description($strategy) {
		return WCOS_Split_Strategy_Gates::CATEGORY === $strategy
			? __('Review current product-category buckets before splitting whole product lines.', 'wc-order-splitter')
			: __('Review current product stock-status buckets before splitting whole product lines.', 'wc-order-splitter');
	}

	private function dialog_title($strategy) {
		return WCOS_Split_Strategy_Gates::CATEGORY === $strategy
			? __('Split order by category', 'wc-order-splitter')
			: __('Split order by stock status', 'wc-order-splitter');
	}

	private function dialog_description($strategy) {
		return WCOS_Split_Strategy_Gates::CATEGORY === $strategy
			? __('Review the current category classification, choose one category bucket to keep on the source order, then confirm the frozen plan.', 'wc-order-splitter')
			: __('Review the current stock-status classification, choose one status bucket to keep on the source order, then confirm the frozen plan.', 'wc-order-splitter');
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
