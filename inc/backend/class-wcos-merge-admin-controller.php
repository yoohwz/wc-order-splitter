<?php

defined('ABSPATH') || exit;

final class WCOS_Merge_Transport_Exception extends RuntimeException {
	private $error_code;
	private $http_status;
	private $retryable;

	public function __construct($error_code, $message, $http_status = 400, $retryable = false) {
		$this->error_code = sanitize_key((string) $error_code);
		$this->http_status = max(400, min(599, (int) $http_status));
		$this->retryable = (bool) $retryable;
		parent::__construct((string) $message);
	}

	public function get_error_code() { return $this->error_code; }
	public function get_http_status() { return $this->http_status; }
	public function is_retryable() { return $this->retryable; }
}

/** Gate-aware request and presentation boundary for the future Merge workflow. */
final class WCOS_Merge_Admin_Controller {
	const SEARCH_ACTION = 'wcos_merge_target_search';
	const REVIEW_ACTION = 'wcos_merge_review';
	const CONFIRM_ACTION = 'wcos_merge_confirm';
	const EXECUTE_ACTION = 'wcos_merge_execute';
	const SEARCH_LIMIT = 20;
	const SEARCH_SCAN_LIMIT = 100;
	const SEARCH_MAX_PAGE = 5;
	const SEARCH_MAX_TERM_LENGTH = 40;

	private static $instance = null;
	private $registered = false;
	private $current_order = null;

	public static function bootstrap() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE)) {
			return null;
		}
		if (!self::$instance instanceof self) {
			self::$instance = new self();
		}
		self::$instance->register_hooks();
		return self::$instance;
	}

	public function register_hooks() {
		if ($this->registered || !WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE)) {
			return false;
		}
		add_action('wp_ajax_' . self::SEARCH_ACTION, array($this, 'ajax_search'));
		add_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		add_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		add_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		add_action('admin_footer', array($this, 'render_dialog'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		$this->registered = true;
		return true;
	}

	public function unregister_hooks() {
		remove_action('wp_ajax_' . self::SEARCH_ACTION, array($this, 'ajax_search'));
		remove_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		remove_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		remove_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		remove_action('admin_footer', array($this, 'render_dialog'));
		remove_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		$this->registered = false;
		return true;
	}

	public function search_request(array $request) {
		$this->reject_client_authority($request, array('action', 'nonce', 'source_order_id', 'term', 'page'));
		$source = $this->authorized_source($request);
		$term = isset($request['term']) ? sanitize_text_field((string) $request['term']) : '';
		if (strlen($term) > self::SEARCH_MAX_TERM_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $term)) {
			throw new WCOS_Merge_Transport_Exception('invalid_search', __('The Merge target search is not valid.', 'wc-order-splitter'), 400, false);
		}
		$page = isset($request['page']) ? absint($request['page']) : 1;
		if ($page < 1 || $page > self::SEARCH_MAX_PAGE) {
			throw new WCOS_Merge_Transport_Exception('invalid_search_page', __('The Merge target search page is out of range.', 'wc-order-splitter'), 400, false);
		}
		return $this->search_targets($source, $term, $page);
	}

	/** Bounded PII-free selector data; production access is only through the gated AJAX hook. */
	private function search_targets(WC_Order $source, $term = '', $page = 1) {
		$term = strtolower(trim((string) $term));
		$page = max(1, min(self::SEARCH_MAX_PAGE, (int) $page));
		if (preg_match('/^#?([1-9][0-9]*)$/D', $term, $matches)) {
			$target = wc_get_order(absint($matches[1]));
			return array(
				'results' => $this->is_searchable_target($source, $target) ? array($this->search_result($target)) : array(),
				'more' => false,
			);
		}
		$statuses = array_map(
			function ($status) { return 0 === strpos((string) $status, 'wc-') ? substr((string) $status, 3) : (string) $status; },
			(array) get_option('order_splitter_status_allowed', array('wc-processing'))
		);
		$orders = wc_get_orders(
			array(
				'type' => 'shop_order',
				'status' => array_values(array_filter(array_map('sanitize_key', $statuses))),
				'exclude' => array($source->get_id()),
				'limit' => self::SEARCH_SCAN_LIMIT,
				'orderby' => 'date',
				'order' => 'DESC',
				'return' => 'objects',
			)
		);
		$matches = array();
		foreach ((array) $orders as $order) {
			if (!$this->is_searchable_target($source, $order)) {
				continue;
			}
			$id = (string) $order->get_id();
			$number = (string) $order->get_order_number();
			if ('' !== $term && false === strpos(strtolower($id), $term) && false === strpos(strtolower($number), ltrim($term, '#'))) {
				continue;
			}
			$matches[] = $this->search_result($order);
		}
		$offset = ($page - 1) * self::SEARCH_LIMIT;
		return array(
			'results' => array_slice($matches, $offset, self::SEARCH_LIMIT),
			'more' => count($matches) > $offset + self::SEARCH_LIMIT,
		);
	}

	public function review_request(array $request) {
		$this->reject_client_authority($request, array('action', 'nonce', 'source_order_id', 'target_order_id'));
		list($source, $target) = $this->authorized_pair($request);
		try {
			$report = (new WCOS_Mutation_Gateway())->merge_preflight($source, $target);
		} catch (Throwable $throwable) {
			throw new WCOS_Merge_Transport_Exception('review_failed', __('Unable to review this Merge pair.', 'wc-order-splitter'), 409, false);
		}
		if (empty($report['supported'])) {
			throw new WCOS_Merge_Transport_Exception(
				'preflight_' . sanitize_key(isset($report['reason']) ? (string) $report['reason'] : 'unsupported'),
				isset($report['message']) ? (string) $report['message'] : __('This Merge pair is not supported.', 'wc-order-splitter'),
				409,
				false
			);
		}
		try {
			$stored = WCOS_Merge_Review_Store::create($source, $target, $report, get_current_user_id());
		} catch (WCOS_Merge_Review_Exception $exception) {
			throw $this->review_exception($exception);
		}

		return array(
			'review_id' => $stored['review_id'],
			'review_token' => $stored['review_token'],
			'expires_at' => $stored['expires_at'],
			'summary' => $this->review_summary($source, $target, $report),
		);
	}

	public function confirm_request(array $request) {
		$this->reject_client_authority($request, array('action', 'nonce', 'source_order_id', 'target_order_id', 'review_id', 'review_token'));
		list($source, $target) = $this->authorized_pair($request);
		$review_id = isset($request['review_id']) ? sanitize_key((string) $request['review_id']) : '';
		$review_token = isset($request['review_token']) ? (string) $request['review_token'] : '';
		try {
			$authority = WCOS_Merge_Review_Store::verify($source, $target, $review_id, $review_token, get_current_user_id());
			$confirmation = WCOS_Merge_Confirmation_Store::create($source, $target, $authority, get_current_user_id());
			if (!WCOS_Merge_Review_Store::consume($review_id)) {
				WCOS_Merge_Confirmation_Store::delete($confirmation['operation_id']);
				throw new WCOS_Merge_Review_Exception('already_consumed', __('This Merge Review was already consumed. Review the pair again.', 'wc-order-splitter'));
			}
		} catch (WCOS_Merge_Review_Exception $exception) {
			throw $this->review_exception($exception);
		} catch (WCOS_Merge_Confirmation_Exception $exception) {
			throw $this->confirmation_exception($exception);
		}

		return array(
			'operation_id' => $confirmation['operation_id'],
			'confirmation_token' => $confirmation['confirmation_token'],
			'expires_at' => $confirmation['expires_at'],
			'source_order_id' => $source->get_id(),
			'target_order_id' => $target->get_id(),
		);
	}

	public function execute_request(array $request) {
		$this->reject_client_authority($request, array('action', 'nonce', 'source_order_id', 'target_order_id', 'operation_id', 'confirmation_token'));
		$operation_id = isset($request['operation_id']) ? sanitize_key((string) $request['operation_id']) : '';
		$token = isset($request['confirmation_token']) ? (string) $request['confirmation_token'] : '';
		list($source, $target) = $this->authorized_pair($request, false);
		$journal = WCOS_Operation_Journal::get($source, $operation_id);
		if (is_array($journal)) {
			// Durable replay authority is fully self-verified before stale UI status is relaxed.
			$confirmation = $this->verified_confirmation($source, $target, $operation_id, $token);
		} else {
			// A first Execute remains bound to the current source/target surface eligibility.
			$this->assert_status_enabled($source);
			$this->assert_status_enabled($target);
			$confirmation = $this->verified_confirmation($source, $target, $operation_id, $token);
		}

		try {
			$result = (new WCOS_Mutation_Gateway())->merge(
				$source,
				$target,
				$operation_id,
				$confirmation['price_precision'],
				WCOS_Merge_Confirmation_Store::operation_authority($confirmation)
			);
		} catch (RuntimeException $exception) {
			if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE)) {
				throw new WCOS_Merge_Transport_Exception('workflow_disabled', __('Hardened Merge is not enabled for production use yet.', 'wc-order-splitter'), 503, false);
			}
			throw new WCOS_Merge_Transport_Exception('merge_failed', __('The hardened Merge request did not complete automatically.', 'wc-order-splitter'), 409, true);
		}

		$target_id = absint(isset($result['target_order_id']) ? $result['target_order_id'] : $target->get_id());
		$persisted_target = wc_get_order($target_id);
		if (!$persisted_target instanceof WC_Order) {
			$persisted_target = $target;
		}
		return array(
			'operation_id' => $operation_id,
			'status' => sanitize_key(isset($result['status']) ? (string) $result['status'] : 'completed'),
			'source_order_id' => absint(isset($result['source_order_id']) ? $result['source_order_id'] : $source->get_id()),
			'target_order_id' => $persisted_target->get_id(),
			'target' => array(
				'id' => $persisted_target->get_id(),
				'number' => (string) $persisted_target->get_order_number(),
				'status' => (string) $persisted_target->get_status(),
				'edit_url' => method_exists($persisted_target, 'get_edit_order_url') ? esc_url_raw((string) $persisted_target->get_edit_order_url()) : '',
			),
			'retirement_policy' => sanitize_key(isset($result['retirement_policy']) ? (string) $result['retirement_policy'] : WCOS_Merge_Retirement_Policy::approved_identifier()),
		);
	}

	public function ajax_search() { $this->send_ajax('search_request'); }
	public function ajax_review() { $this->send_ajax('review_request'); }
	public function ajax_confirm() { $this->send_ajax('confirm_request'); }
	public function ajax_execute() { $this->send_ajax('execute_request'); }

	public function render_launcher($order) {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE) || !$order instanceof WC_Order) {
			return;
		}
		try {
			WCOS_Order_Mutation_Authorizer::assert_merge_source($order);
			$this->assert_status_enabled($order);
		} catch (Throwable $throwable) {
			return;
		}
		$this->current_order = $order;
		$dialog_id = 'wcos-merge-dialog-' . $order->get_id();
		$description_id = 'wcos-merge-launcher-description-' . $order->get_id();
		echo '<button type="button" class="button wcos-merge-launcher" aria-haspopup="dialog" aria-controls="' . esc_attr($dialog_id) . '" aria-describedby="' . esc_attr($description_id) . '">';
		echo esc_html__('Merge', 'wc-order-splitter');
		echo '</button>';
		echo '<span id="' . esc_attr($description_id) . '" class="description wcos-merge-launcher-description">';
		echo esc_html__('Select a target order, then review the server-owned Merge plan.', 'wc-order-splitter');
		echo '</span>';
	}

	public function enqueue_assets() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE) || !$this->is_order_edit_screen()) {
			return;
		}
		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		wp_enqueue_style('wcos-merge-admin', plugins_url('css/p2-merge-admin.css', $plugin_file), array(), WC_ORDER_SPLITTER_VERSION);
		wp_enqueue_script('wcos-merge-admin', plugins_url('js/p2-merge-admin.js', $plugin_file), array('jquery', 'selectWoo'), WC_ORDER_SPLITTER_VERSION, true);
		wp_localize_script('wcos-merge-admin', 'wcosMergeAdminStrings', array(
			'searching' => __('Searching orders…', 'wc-order-splitter'),
			'reviewing' => __('Reviewing Merge pair…', 'wc-order-splitter'),
			'reviewReady' => __('The pair passed server review. Confirm the acknowledgement to merge.', 'wc-order-splitter'),
			'confirming' => __('Confirming reviewed Merge authority…', 'wc-order-splitter'),
			'executing' => __('Merging orders…', 'wc-order-splitter'),
			'retrying' => __('Retrying the same Merge operation…', 'wc-order-splitter'),
			'completed' => __('Orders merged successfully. The source order was retired under the approved policy.', 'wc-order-splitter'),
			'requestFailed' => __('The Merge request could not be completed.', 'wc-order-splitter'),
			'selectTarget' => __('Select a target order first.', 'wc-order-splitter'),
			'retryMerge' => __('Retry merge', 'wc-order-splitter'),
			'confirmMerge' => __('Confirm and merge', 'wc-order-splitter'),
			'closedOperation' => __('The Merge operation closed without a completed Merge. Review the orders before trying again.', 'wc-order-splitter'),
			'targetOrder' => __('Active target order', 'wc-order-splitter'),
		));
	}

	public function render_dialog() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE) || !$this->current_order instanceof WC_Order) {
			return;
		}
		echo $this->dialog_html($this->current_order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Direct template harness; production rendering remains guarded by render_dialog(). */
	public function dialog_html(WC_Order $source) {
		if (!$source->get_id() || 'shop_order' !== $source->get_type()) {
			return '';
		}
		$dialog_id = 'wcos-merge-dialog-' . $source->get_id();
		$title_id = $dialog_id . '-title';
		$description_id = $dialog_id . '-description';
		$nonce = wp_create_nonce('wcos_merge_order_' . $source->get_id());

		ob_start();
		?>
		<div id="<?php echo esc_attr($dialog_id); ?>" class="wcos-merge-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($title_id); ?>" aria-describedby="<?php echo esc_attr($description_id); ?>" data-source-order-id="<?php echo esc_attr($source->get_id()); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-search-action="<?php echo esc_attr(self::SEARCH_ACTION); ?>" data-review-action="<?php echo esc_attr(self::REVIEW_ACTION); ?>" data-confirm-action="<?php echo esc_attr(self::CONFIRM_ACTION); ?>" data-execute-action="<?php echo esc_attr(self::EXECUTE_ACTION); ?>" hidden>
			<div class="wcos-merge-dialog__backdrop" aria-hidden="true"></div>
			<div class="wcos-merge-dialog__panel" tabindex="-1">
				<div class="wcos-merge-dialog__header">
					<div>
						<h2 id="<?php echo esc_attr($title_id); ?>"><?php esc_html_e('Merge into another order', 'wc-order-splitter'); ?></h2>
						<p id="<?php echo esc_attr($description_id); ?>"><?php echo esc_html(sprintf(__('Current source: order #%1$s (ID %2$d). Select the active target that will receive its supported historical lines.', 'wc-order-splitter'), $source->get_order_number(), $source->get_id())); ?></p>
					</div>
					<button type="button" class="button-link wcos-merge-close" aria-label="<?php esc_attr_e('Close Merge dialog', 'wc-order-splitter'); ?>"><span aria-hidden="true">×</span></button>
				</div>

				<div class="wcos-merge-target-field">
					<label for="<?php echo esc_attr($dialog_id . '-target'); ?>"><?php esc_html_e('Target order', 'wc-order-splitter'); ?></label>
					<select id="<?php echo esc_attr($dialog_id . '-target'); ?>" class="wcos-merge-target-select" data-placeholder="<?php esc_attr_e('Search by order ID or number', 'wc-order-splitter'); ?>"></select>
					<p class="description"><?php esc_html_e('Search results contain only order identity, status, and currency. Selecting a target does not authorize Merge.', 'wc-order-splitter'); ?></p>
				</div>

				<div class="wcos-merge-policy" aria-labelledby="<?php echo esc_attr($dialog_id . '-policy-title'); ?>">
					<h3 id="<?php echo esc_attr($dialog_id . '-policy-title'); ?>"><?php esc_html_e('Retirement policy', 'wc-order-splitter'); ?></h3>
					<p><?php esc_html_e('After a successful Merge, the current source order is archived/trash using non_force_trash_archive. The selected target remains the active order.', 'wc-order-splitter'); ?></p>
				</div>

				<div class="wcos-merge-review" hidden>
					<h3><?php esc_html_e('Server-owned Review', 'wc-order-splitter'); ?></h3>
					<dl class="wcos-merge-review-summary"></dl>
					<label class="wcos-merge-confirm-label">
						<input type="checkbox" class="wcos-merge-confirm-checkbox" />
						<span><?php esc_html_e('I reviewed this source/target pair and understand that the source will be retired while the target remains active.', 'wc-order-splitter'); ?></span>
					</label>
				</div>

				<div class="wcos-merge-status" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
				<div class="wcos-merge-error notice notice-error inline" role="alert" tabindex="-1" hidden></div>
				<div class="wcos-merge-result notice notice-success inline" tabindex="-1" hidden></div>

				<div class="wcos-merge-dialog__actions">
					<button type="button" class="button wcos-merge-cancel"><?php esc_html_e('Cancel', 'wc-order-splitter'); ?></button>
					<button type="button" class="button button-secondary wcos-merge-review-button" disabled><?php esc_html_e('Review merge', 'wc-order-splitter'); ?></button>
					<button type="button" class="button button-primary wcos-merge-execute-button" disabled><?php esc_html_e('Confirm and merge', 'wc-order-splitter'); ?></button>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function authorized_source(array $request, $require_status = true) {
		$source_id = isset($request['source_order_id']) ? absint($request['source_order_id']) : 0;
		if (!$source_id) {
			throw new WCOS_Merge_Transport_Exception('invalid_source', __('A valid Merge source order is required.', 'wc-order-splitter'), 400, false);
		}
		$nonce = isset($request['nonce']) ? sanitize_text_field((string) $request['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'wcos_merge_order_' . $source_id)) {
			throw new WCOS_Merge_Transport_Exception('invalid_nonce', __('The Merge request failed nonce verification.', 'wc-order-splitter'), 403, false);
		}
		$source = wc_get_order($source_id);
		if (!$source instanceof WC_Order) {
			throw new WCOS_Merge_Transport_Exception('participant_not_found', __('The Merge source order could not be found.', 'wc-order-splitter'), 404, false);
		}
		try {
			WCOS_Order_Mutation_Authorizer::assert_merge_source($source);
		} catch (Throwable $throwable) {
			throw new WCOS_Merge_Transport_Exception('authorization_failed', __('You are not allowed to merge this source order.', 'wc-order-splitter'), 403, false);
		}
		if ($require_status) {
			$this->assert_status_enabled($source);
		}
		return $source;
	}

	private function authorized_pair(array $request, $require_status = true) {
		$source = $this->authorized_source($request, $require_status);
		$target_id = isset($request['target_order_id']) ? absint($request['target_order_id']) : 0;
		if (!$target_id || $source->get_id() === $target_id) {
			throw new WCOS_Merge_Transport_Exception('invalid_pair', __('Merge requires two distinct order IDs.', 'wc-order-splitter'), 400, false);
		}
		$target = wc_get_order($target_id);
		if (!$target instanceof WC_Order) {
			throw new WCOS_Merge_Transport_Exception('participant_not_found', __('A Merge participant could not be found.', 'wc-order-splitter'), 404, false);
		}
		try {
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $source, $target);
		} catch (Throwable $throwable) {
			throw new WCOS_Merge_Transport_Exception('authorization_failed', __('You are not allowed to merge this order pair.', 'wc-order-splitter'), 403, false);
		}
		if ($require_status) {
			$this->assert_status_enabled($target);
		}
		return array($source, $target);
	}

	private function verified_confirmation(WC_Order $source, WC_Order $target, $operation_id, $token) {
		try {
			return WCOS_Merge_Confirmation_Store::verify($source, $target, $operation_id, $token, get_current_user_id());
		} catch (WCOS_Merge_Confirmation_Exception $exception) {
			throw $this->confirmation_exception($exception);
		}
	}

	private function is_searchable_target(WC_Order $source, $target) {
		return $target instanceof WC_Order
			&& 'shop_order' === $target->get_type()
			&& $target->get_id() !== $source->get_id()
			&& current_user_can('edit_shop_order', $target->get_id())
			&& $this->status_enabled($target);
	}

	private function search_result(WC_Order $order) {
		return array(
			'id' => $order->get_id(),
			'number' => (string) $order->get_order_number(),
			'status' => (string) $order->get_status(),
			'currency' => (string) $order->get_currency(),
		);
	}

	private function status_enabled(WC_Order $order) {
		$allowed = (array) get_option('order_splitter_status_allowed', array('wc-processing'));
		return in_array('wc-' . $order->get_status(), $allowed, true);
	}

	private function assert_status_enabled(WC_Order $order) {
		if (!$this->status_enabled($order)) {
			throw new WCOS_Merge_Transport_Exception('status_disabled', __('This order status is disabled in the Order Splitter settings.', 'wc-order-splitter'), 409, false);
		}
	}

	private function reject_client_authority(array $request, array $allowed) {
		foreach (array_keys($request) as $field) {
			if (!in_array((string) $field, $allowed, true)) {
				throw new WCOS_Merge_Transport_Exception('unexpected_field', __('The Merge request contains an unsupported field.', 'wc-order-splitter'), 400, false);
			}
		}
	}

	private function review_summary(WC_Order $source, WC_Order $target, array $report) {
		$precision = (int) $report['price_precision'];
		$financial_target = !empty($report['target_has_financial_history']);
		$projected = WCOS_Merge_Commercial_Policy::expected_target_contract($source, $target, $precision);
		$coalesced = 0;
		$fresh = 0;
		foreach ($report['plan']['lines'] as $line) {
			if ('coalesce' === sanitize_key(isset($line['action']) ? (string) $line['action'] : '')) {
				$coalesced++;
			} else {
				$fresh++;
			}
		}
		return array(
			'source' => array('id' => $source->get_id(), 'number' => (string) $source->get_order_number(), 'status' => (string) $source->get_status(), 'line_count' => count($source->get_items('line_item')), 'total' => (string) $source->get_total()),
			'target' => array('id' => $target->get_id(), 'number' => (string) $target->get_order_number(), 'status' => (string) $target->get_status(), 'line_count' => count($target->get_items('line_item')), 'total' => (string) $target->get_total()),
			'transferable_line_count' => count($report['plan']['lines']),
			'coalesced_line_count' => $coalesced,
			'fresh_line_count' => $fresh,
			'projected_active_target_total' => (string) $projected['grand_total'],
			'source_shipping_retained' => !empty($source->get_items('shipping')),
			'source_fees_retained' => !empty($source->get_items('fee')),
			'source_coupons_retained' => !empty($source->get_items('coupon')),
			'target_charges_shipping_disposition' => 'preserve_target',
			'target_context_disposition' => 'keep_target_context',
			'target_status_disposition' => 'keep_target',
			'target_financial_history_retained' => $financial_target,
			'source_financial_history' => 'none',
			'settlement_neutral_line_count' => $financial_target ? count($report['plan']['lines']) : 0,
			'financial_line_disposition' => $financial_target ? 'fresh_target_line_only' : 'ordinary_commercial_policy',
			'target_financial_authority_disposition' => $financial_target ? 'preserve_exact' : 'absent',
			'target_payable_tax_disposition' => $financial_target ? 'unchanged' : 'historical_product_values_added',
			'payment_refund_api_disposition' => 'never',
			'currency' => (string) $source->get_currency(),
			'price_precision' => $precision,
			'compatibility' => array('supported' => true, 'reason' => 'supported'),
			'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
		);
	}

	private function review_exception(WCOS_Merge_Review_Exception $exception) {
		$reason = $exception->get_reason();
		$status = in_array($reason, array('invalid_identity'), true) ? 400 : (in_array($reason, array('invalid_token', 'owner_mismatch'), true) ? 403 : (in_array($reason, array('expired', 'already_consumed'), true) ? 410 : 409));
		return new WCOS_Merge_Transport_Exception('review_' . $reason, $exception->getMessage(), $status, false);
	}

	private function confirmation_exception(WCOS_Merge_Confirmation_Exception $exception) {
		$reason = $exception->get_reason();
		$status = 'invalid_identity' === $reason ? 400 : (in_array($reason, array('invalid_token', 'owner_mismatch'), true) ? 403 : ('expired' === $reason ? 410 : 409));
		return new WCOS_Merge_Transport_Exception('confirmation_' . $reason, $exception->getMessage(), $status, false);
	}

	private function send_ajax($method) {
		try {
			wp_send_json_success($this->{$method}(wp_unslash($_POST)));
		} catch (WCOS_Merge_Transport_Exception $exception) {
			wp_send_json_error(array('code' => $exception->get_error_code(), 'message' => $exception->getMessage(), 'retryable' => $exception->is_retryable()), $exception->get_http_status());
		} catch (Throwable $throwable) {
			wp_send_json_error(array('code' => 'merge_request_failed', 'message' => __('The Merge request could not be completed.', 'wc-order-splitter'), 'retryable' => true), 500);
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
