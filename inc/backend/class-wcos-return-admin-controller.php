<?php

defined('ABSPATH') || exit;

final class WCOS_Return_Transport_Exception extends RuntimeException {
	private $error_code;
	private $http_status;
	private $retryable;

	public function __construct($error_code, $message, $http_status = 400, $retryable = false) {
		$this->error_code = sanitize_key((string) $error_code);
		$this->http_status = absint($http_status);
		$this->retryable = (bool) $retryable;
		parent::__construct((string) $message);
	}

	public function get_error_code() { return $this->error_code; }
	public function get_http_status() { return $this->http_status; }
	public function is_retryable() { return $this->retryable; }
}

/** Gate-aware request and presentation authority for hardened single-order Return. */
final class WCOS_Return_Admin_Controller {
	const REVIEW_ACTION = 'wcos_return_review';
	const CONFIRM_ACTION = 'wcos_return_confirm';
	const EXECUTE_ACTION = 'wcos_return_execute';

	private static $instance = null;
	private $registered = false;
	private $current_child = null;

	public static function bootstrap() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER)) {
			return null;
		}
		if (!self::$instance instanceof self) {
			self::$instance = new self();
		}
		self::$instance->register_hooks();
		return self::$instance;
	}

	public function register_hooks() {
		if ($this->registered || !WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER)) {
			return false;
		}
		add_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		add_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		add_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		add_action('woocommerce_order_item_add_action_buttons', array($this, 'render_launcher'), 23, 1);
		add_action('admin_footer', array($this, 'render_dialog'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		$this->registered = true;
		return true;
	}

	public function unregister_hooks() {
		remove_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		remove_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		remove_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		remove_action('woocommerce_order_item_add_action_buttons', array($this, 'render_launcher'), 23);
		remove_action('admin_footer', array($this, 'render_dialog'));
		remove_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		$this->registered = false;
		$this->current_child = null;
		return true;
	}

	public function review_request(array $request) {
		$this->reject_client_authority($request, array('action', 'nonce', 'child_order_id'));
		$child = $this->authorized_child($request);
		$report = (new WCOS_Return_WooCommerce_Adapter())->preflight($child);
		if (empty($report['supported'])) {
			throw new WCOS_Return_Transport_Exception(
				'preflight_' . sanitize_key(isset($report['reason']) ? (string) $report['reason'] : 'unsupported'),
				isset($report['message']) ? (string) $report['message'] : __('This child is not supported by hardened Return.', 'wc-order-splitter'),
				409,
				false
			);
		}
		try {
			$stored = WCOS_Return_Review_Store::create($child, $report, get_current_user_id());
		} catch (WCOS_Return_Review_Exception $exception) {
			throw $this->review_exception($exception);
		}
		$original = wc_get_order(absint($report['source_order_id']));
		if (!$original instanceof WC_Order) {
			throw new WCOS_Return_Transport_Exception('participant_not_found', __('The server-resolved Return original is unavailable.', 'wc-order-splitter'), 404, false);
		}
		return array(
			'review_id' => $stored['review_id'],
			'review_token' => $stored['review_token'],
			'expires_at' => $stored['expires_at'],
			'summary' => $this->review_summary($child, $original, $report),
		);
	}

	public function confirm_request(array $request) {
		$this->reject_client_authority($request, array('action', 'nonce', 'child_order_id', 'review_id', 'review_token'));
		$child = $this->authorized_child($request);
		$review_id = isset($request['review_id']) ? sanitize_key((string) $request['review_id']) : '';
		$review_token = isset($request['review_token']) ? (string) $request['review_token'] : '';
		try {
			$authority = WCOS_Return_Review_Store::verify($child, $review_id, $review_token, get_current_user_id());
			$confirmation = WCOS_Return_Confirmation_Store::create($child, $authority, get_current_user_id());
			if (!WCOS_Return_Review_Store::consume($child, $review_id, $review_token, get_current_user_id())) {
				WCOS_Return_Confirmation_Store::delete($confirmation['operation_id']);
				throw new WCOS_Return_Review_Exception('already_consumed', __('This Return Review was already consumed by another Confirm request.', 'wc-order-splitter'));
			}
		} catch (WCOS_Return_Review_Exception $exception) {
			throw $this->review_exception($exception);
		} catch (WCOS_Return_Confirmation_Exception $exception) {
			throw $this->confirmation_exception($exception);
		}

		return array(
			'operation_id' => $confirmation['operation_id'],
			'confirmation_token' => $confirmation['confirmation_token'],
			'expires_at' => $confirmation['expires_at'],
			'child_order_id' => absint($authority['child_order_id']),
			'original_order_id' => absint($authority['original_order_id']),
		);
	}

	public function execute_request(array $request) {
		$this->reject_client_authority($request, array('action', 'nonce', 'child_order_id', 'operation_id', 'confirmation_token'));
		$child = $this->authorized_child($request);
		$operation_id = isset($request['operation_id']) ? sanitize_key((string) $request['operation_id']) : '';
		$token = isset($request['confirmation_token']) ? (string) $request['confirmation_token'] : '';
		try {
			$confirmation = WCOS_Return_Confirmation_Store::verify($child, $operation_id, $token, get_current_user_id());
		} catch (WCOS_Return_Confirmation_Exception $exception) {
			throw $this->confirmation_exception($exception);
		}

		try {
			$result = (new WCOS_Mutation_Gateway())->return_order(
				$child,
				$operation_id,
				$confirmation['price_precision'],
				WCOS_Return_Confirmation_Store::operation_authority($confirmation)
			);
		} catch (WCOS_Return_Adapter_Exception $exception) {
			$error_code = $exception->get_error_code();
			$retryable = !in_array($error_code, array('return_manual_reconciliation', 'return_compensated', 'return_operation_closed'), true);
			throw new WCOS_Return_Transport_Exception($error_code, __('The hardened Return request did not complete automatically.', 'wc-order-splitter'), 409, $retryable);
		} catch (RuntimeException $exception) {
			if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER)) {
				throw new WCOS_Return_Transport_Exception('workflow_disabled', __('Hardened Return is not enabled for production use yet.', 'wc-order-splitter'), 503, false);
			}
			throw new WCOS_Return_Transport_Exception('return_failed', __('The hardened Return request did not complete automatically.', 'wc-order-splitter'), 409, true);
		}

		$original_id = absint(isset($result['original_order_id']) ? $result['original_order_id'] : $confirmation['original_order_id']);
		$persisted_original = wc_get_order($original_id);
		return array(
			'operation_id' => $operation_id,
			'status' => sanitize_key(isset($result['status']) ? (string) $result['status'] : 'completed'),
			'child_order_id' => absint(isset($result['child_order_id']) ? $result['child_order_id'] : $child->get_id()),
			'original_order_id' => $original_id,
			'original' => $persisted_original instanceof WC_Order ? array(
				'id' => $persisted_original->get_id(),
				'number' => (string) $persisted_original->get_order_number(),
				'status' => (string) $persisted_original->get_status(),
				'edit_url' => method_exists($persisted_original, 'get_edit_order_url') ? esc_url_raw((string) $persisted_original->get_edit_order_url()) : '',
			) : array('id' => $original_id, 'number' => (string) $original_id, 'status' => '', 'edit_url' => ''),
			'retirement_policy' => sanitize_key(isset($result['retirement_policy']) ? (string) $result['retirement_policy'] : WCOS_Return_Retirement_Policy::approved_identifier()),
		);
	}

	public function ajax_review() { $this->send_ajax('review_request'); }
	public function ajax_confirm() { $this->send_ajax('confirm_request'); }
	public function ajax_execute() { $this->send_ajax('execute_request'); }

	public function render_launcher($order) {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER) || !$order instanceof WC_Order) {
			return;
		}
		try {
			$report = (new WCOS_Return_WooCommerce_Adapter())->preflight($order);
			if (empty($report['supported'])) {
				return;
			}
			$original = wc_get_order(absint($report['source_order_id']));
			if (!$original instanceof WC_Order) {
				return;
			}
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::RETURN_ORDER, $order, $original);
		} catch (Throwable $throwable) {
			return;
		}

		$this->current_child = $order;
		$dialog_id = 'wcos-return-dialog-' . $order->get_id();
		$description_id = 'wcos-return-launcher-description-' . $order->get_id();
		echo '<button type="button" class="button wcos-return-launcher" aria-haspopup="dialog" aria-controls="' . esc_attr($dialog_id) . '" aria-describedby="' . esc_attr($description_id) . '">';
		echo esc_html__('Return to original order', 'wc-order-splitter');
		echo '</button>';
		echo '<span id="' . esc_attr($description_id) . '" class="description wcos-return-launcher-description">';
		echo esc_html__('Review the server-resolved original and historical Return summary before confirming.', 'wc-order-splitter');
		echo '</span>';
	}

	public function enqueue_assets() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER) || !$this->is_order_edit_screen()) {
			return;
		}
		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		wp_enqueue_style('wcos-return-admin', plugins_url('css/p2-return-admin.css', $plugin_file), array(), WC_ORDER_SPLITTER_VERSION);
		wp_enqueue_script('wcos-return-admin', plugins_url('js/p2-return-admin.js', $plugin_file), array(), WC_ORDER_SPLITTER_VERSION, true);
		wp_localize_script('wcos-return-admin', 'wcosReturnAdminStrings', array(
			'reviewing' => __('Reviewing Return authority…', 'wc-order-splitter'),
			'reviewReady' => __('The child passed server review. Acknowledge the immutable summary to confirm Return.', 'wc-order-splitter'),
			'confirming' => __('Confirming reviewed Return authority…', 'wc-order-splitter'),
			'confirmReady' => __('Return is confirmed. Execute this exact operation when ready.', 'wc-order-splitter'),
			'executing' => __('Returning operational ownership to the original order…', 'wc-order-splitter'),
			'retrying' => __('Retrying the same Return operation…', 'wc-order-splitter'),
			'completed' => __('Return completed. The child is retired and the original is active.', 'wc-order-splitter'),
			'requestFailed' => __('The Return request could not be completed.', 'wc-order-splitter'),
			'reviewReturn' => __('Review return', 'wc-order-splitter'),
			'confirmReturn' => __('Confirm return', 'wc-order-splitter'),
			'executeReturn' => __('Execute return', 'wc-order-splitter'),
			'retryReturn' => __('Retry same return', 'wc-order-splitter'),
			'newReviewRequired' => __('This Review is no longer valid. Close or explicitly review the child again.', 'wc-order-splitter'),
			'closedOperation' => __('This Return operation did not complete and cannot be restarted from this modal. Review the orders before taking another action.', 'wc-order-splitter'),
			'originalOrder' => __('Active original order', 'wc-order-splitter'),
			'childOrder' => __('Current child', 'wc-order-splitter'),
			'strategy' => __('Split strategy', 'wc-order-splitter'),
			'linesQuantity' => __('Returned lines / quantity', 'wc-order-splitter'),
			'historicalValues' => __('Historical subtotal / total / tax', 'wc-order-splitter'),
			'retirement' => __('Child retirement', 'wc-order-splitter'),
		));
	}

	public function render_dialog() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER) || !$this->current_child instanceof WC_Order) {
			return;
		}
		echo $this->dialog_html($this->current_child); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Direct presentation harness; production rendering remains guarded by render_dialog(). */
	public function dialog_html(WC_Order $child) {
		if (!$child->get_id() || 'shop_order' !== $child->get_type()) {
			return '';
		}
		$dialog_id = 'wcos-return-dialog-' . $child->get_id();
		$title_id = $dialog_id . '-title';
		$description_id = $dialog_id . '-description';
		$nonce = wp_create_nonce('wcos_return_order_' . $child->get_id());

		ob_start();
		?>
		<div id="<?php echo esc_attr($dialog_id); ?>" class="wcos-return-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($title_id); ?>" aria-describedby="<?php echo esc_attr($description_id); ?>" data-child-order-id="<?php echo esc_attr($child->get_id()); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-review-action="<?php echo esc_attr(self::REVIEW_ACTION); ?>" data-confirm-action="<?php echo esc_attr(self::CONFIRM_ACTION); ?>" data-execute-action="<?php echo esc_attr(self::EXECUTE_ACTION); ?>" hidden>
			<div class="wcos-return-dialog__backdrop" aria-hidden="true"></div>
			<div class="wcos-return-dialog__panel" tabindex="-1">
				<div class="wcos-return-dialog__header">
					<div>
						<h2 id="<?php echo esc_attr($title_id); ?>"><?php esc_html_e('Return to original order', 'wc-order-splitter'); ?></h2>
						<p id="<?php echo esc_attr($description_id); ?>"><?php
							/* translators: 1: Displayed child order number, 2: Internal child order ID. */
							echo esc_html(sprintf(__('Current child: order #%1$s (ID %2$d). The original is resolved only by server-held Split lineage during Review.', 'wc-order-splitter'), $child->get_order_number(), $child->get_id()));
						?></p>
					</div>
					<button type="button" class="button-link wcos-return-close" aria-label="<?php esc_attr_e('Close Return dialog', 'wc-order-splitter'); ?>"><span aria-hidden="true">×</span></button>
				</div>

				<div class="wcos-return-policy" aria-labelledby="<?php echo esc_attr($dialog_id . '-policy-title'); ?>">
					<h3 id="<?php echo esc_attr($dialog_id . '-policy-title'); ?>"><?php esc_html_e('Return safety policy', 'wc-order-splitter'); ?></h3>
					<ul>
						<li><?php esc_html_e('The server resolves the proven original order; this screen cannot select or replace it.', 'wc-order-splitter'); ?></li>
						<li><?php esc_html_e('Operational ownership moves back to the original using preserved historical line values and taxes; current catalog values are not reconstructed.', 'wc-order-splitter'); ?></li>
						<li><?php esc_html_e('Return is designed to be physical-stock neutral and transfers only accepted stock-reduction ownership markers.', 'wc-order-splitter'); ?></li>
						<li><?php esc_html_e('After completion, the child becomes non-owning and is archived/trash under non_force_trash_archive while its history remains preserved.', 'wc-order-splitter'); ?></li>
					</ul>
				</div>

				<div class="wcos-return-review" hidden>
					<h3><?php esc_html_e('Immutable server Review', 'wc-order-splitter'); ?></h3>
					<dl class="wcos-return-review-summary"></dl>
					<label class="wcos-return-confirm-label">
						<input type="checkbox" class="wcos-return-confirm-checkbox" />
						<span><?php esc_html_e('I reviewed the server-resolved original, historical values, physical-stock neutrality, and child retirement policy.', 'wc-order-splitter'); ?></span>
					</label>
				</div>

				<div class="wcos-return-status" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
				<div class="wcos-return-error notice notice-error inline" role="alert" tabindex="-1" hidden></div>
				<div class="wcos-return-result notice notice-success inline" role="status" tabindex="-1" hidden></div>

				<div class="wcos-return-dialog__actions">
					<button type="button" class="button wcos-return-cancel"><?php esc_html_e('Close', 'wc-order-splitter'); ?></button>
					<button type="button" class="button button-secondary wcos-return-review-button"><?php esc_html_e('Review return', 'wc-order-splitter'); ?></button>
					<button type="button" class="button button-secondary wcos-return-confirm-button" hidden disabled><?php esc_html_e('Confirm return', 'wc-order-splitter'); ?></button>
					<button type="button" class="button button-primary wcos-return-execute-button" hidden disabled><?php esc_html_e('Execute return', 'wc-order-splitter'); ?></button>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function authorized_child(array $request) {
		$child_id = isset($request['child_order_id']) ? absint($request['child_order_id']) : 0;
		if (!$child_id) {
			throw new WCOS_Return_Transport_Exception('invalid_child', __('A valid Return child order is required.', 'wc-order-splitter'), 400, false);
		}
		$nonce = isset($request['nonce']) ? sanitize_text_field((string) $request['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'wcos_return_order_' . $child_id)) {
			throw new WCOS_Return_Transport_Exception('invalid_nonce', __('The Return request failed nonce verification.', 'wc-order-splitter'), 403, false);
		}
		$child = wc_get_order($child_id);
		if (!$child instanceof WC_Order) {
			throw new WCOS_Return_Transport_Exception('participant_not_found', __('The Return child could not be found.', 'wc-order-splitter'), 404, false);
		}
		try {
			WCOS_Order_Mutation_Authorizer::assert_can_edit($child);
			WCOS_Order_Mutation_Authorizer::assert_can_delete($child);
		} catch (Throwable $throwable) {
			throw new WCOS_Return_Transport_Exception('authorization_failed', __('You are not allowed to Return this child.', 'wc-order-splitter'), 403, false);
		}
		return $child;
	}

	private function reject_client_authority(array $request, array $allowed) {
		foreach (array_keys($request) as $field) {
			if (!in_array((string) $field, $allowed, true)) {
				throw new WCOS_Return_Transport_Exception('unexpected_field', __('The Return request contains unsupported client authority.', 'wc-order-splitter'), 400, false);
			}
		}
	}

	private function review_summary(WC_Order $child, WC_Order $original, array $report) {
		$plan = $report['return_plan'];
		$is_legacy_compatibility = WCOS_Return_Plan::is_legacy_compatibility($plan);
		$precision = (int) $plan['price_precision'];
		$quantity_units = 0;
		$subtotal_units = 0;
		$total_units = 0;
		$tax_units = 0;
		foreach ($plan['lines'] as $line) {
			$quantity_units += WCOS_Decimal::to_units($line['quantity'], 6);
			$subtotal_units += WCOS_Decimal::to_units($line['subtotal'], $precision);
			$total_units += WCOS_Decimal::to_units($line['total'], $precision);
			$tax_units += WCOS_Decimal::to_units($line['total_tax'], $precision);
		}
		$residual_count = 0;
		$fresh_count = 0;
		foreach ($plan['lines'] as $line) {
			if (WCOS_Return_Plan::DESTINATION_RESIDUAL_SOURCE_ITEM === $line['destination']) { $residual_count++; }
			else { $fresh_count++; }
		}
		return array(
			'child' => array('id' => $child->get_id(), 'number' => (string) $child->get_order_number(), 'status' => (string) $child->get_status()),
			'original' => array('id' => $original->get_id(), 'number' => (string) $original->get_order_number(), 'status' => (string) $original->get_status()),
			'strategy' => sanitize_key((string) $plan['strategy']),
			'returned_line_count' => count($plan['lines']),
			'quantity' => WCOS_Decimal::from_units($quantity_units, 6),
			'historical_subtotal' => WCOS_Decimal::from_units($subtotal_units, $precision),
			'historical_total' => WCOS_Decimal::from_units($total_units, $precision),
			'historical_tax' => WCOS_Decimal::from_units($tax_units, $precision),
			'currency' => (string) $plan['currency'],
			'price_precision' => $precision,
			'retirement' => array('policy' => WCOS_Return_Retirement_Policy::approved_identifier(), 'child_status_after' => 'trash'),
			'compatibility' => array(
				'supported' => true,
				'reason' => 'supported',
				'lineage_basis' => $is_legacy_compatibility ? WCOS_Legacy_Return_Compatibility_Authority::LINEAGE_BASIS : 'hardened_split',
				'legacy_1_4_11_detected' => $is_legacy_compatibility,
				'residual_destination_count' => $residual_count,
				'fresh_destination_count' => $fresh_count,
				'child_shipping_disposition' => $is_legacy_compatibility ? 'retain_immutable_on_retired_child' : 'none',
			),
		);
	}

	private function review_exception(WCOS_Return_Review_Exception $exception) {
		$reason = $exception->get_reason();
		$status = 'invalid_identity' === $reason ? 400 : (in_array($reason, array('invalid_token', 'owner_mismatch'), true) ? 403 : (in_array($reason, array('expired', 'already_consumed'), true) ? 410 : 409));
		return new WCOS_Return_Transport_Exception('review_' . $reason, $exception->getMessage(), $status, false);
	}

	private function confirmation_exception(WCOS_Return_Confirmation_Exception $exception) {
		$reason = $exception->get_reason();
		$status = 'invalid_identity' === $reason ? 400 : (in_array($reason, array('invalid_token', 'owner_mismatch'), true) ? 403 : ('expired' === $reason ? 410 : 409));
		return new WCOS_Return_Transport_Exception('confirmation_' . $reason, $exception->getMessage(), $status, false);
	}

	private function send_ajax($method) {
		try {
			wp_send_json_success($this->{$method}(wp_unslash($_POST)));
		} catch (WCOS_Return_Transport_Exception $exception) {
			wp_send_json_error(array('code' => $exception->get_error_code(), 'message' => $exception->getMessage(), 'retryable' => $exception->is_retryable()), $exception->get_http_status());
		} catch (Throwable $throwable) {
			wp_send_json_error(array('code' => 'return_request_failed', 'message' => __('The Return request could not be completed.', 'wc-order-splitter'), 'retryable' => true), 500);
		}
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
