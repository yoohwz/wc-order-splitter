<?php

defined('ABSPATH') || exit;

final class WCOS_Bulk_Return_Transport_Exception extends RuntimeException {
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

/** Gate-aware Orders-list transport and presentation for hardened Bulk Return. */
final class WCOS_Bulk_Return_Admin_Controller {
	const REVIEW_ACTION = 'wcos_bulk_return_review';
	const CONFIRM_ACTION = 'wcos_bulk_return_confirm';
	const EXECUTE_ACTION = 'wcos_bulk_return_execute';
	const RESUME_ACTION = 'wcos_bulk_return_resume';
	const BULK_ACTION = 'wcos_bulk_return';
	const NONCE_ACTION = 'wcos_bulk_return_orders';

	private static $instance = null;
	private $registered = false;

	public static function bootstrap() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN)) { return null; }
		if (!self::$instance instanceof self) { self::$instance = new self(); }
		self::$instance->register_hooks();
		return self::$instance;
	}

	public function register_hooks() {
		if ($this->registered || !WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN)) { return false; }
		add_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		add_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		add_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		add_action('wp_ajax_' . self::RESUME_ACTION, array($this, 'ajax_resume'));
		add_filter('bulk_actions-edit-shop_order', array($this, 'register_bulk_action'));
		add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'register_bulk_action'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('admin_footer', array($this, 'render_dialog'));
		$this->registered = true;
		return true;
	}

	public function unregister_hooks() {
		remove_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		remove_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		remove_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		remove_action('wp_ajax_' . self::RESUME_ACTION, array($this, 'ajax_resume'));
		remove_filter('bulk_actions-edit-shop_order', array($this, 'register_bulk_action'));
		remove_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'register_bulk_action'));
		remove_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		remove_action('admin_footer', array($this, 'render_dialog'));
		$this->registered = false;
		return true;
	}

	public function register_bulk_action(array $actions) {
		if (!$this->is_supported_operator()) {
			return $actions;
		}

		if (WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN)) {
			$actions[self::BULK_ACTION] = __('Return to original order', 'wc-order-splitter');
		}
		return $actions;
	}

	public function review_request(array $request) {
		$this->assert_gate();
		$this->reject_client_authority($request, array('action', 'nonce', 'child_order_ids'));
		$this->assert_nonce($request);
		$ids = isset($request['child_order_ids']) && is_array($request['child_order_ids']) ? $request['child_order_ids'] : array();
		try {
			$stored = WCOS_Bulk_Return_Review_Store::create($ids, get_current_user_id());
		} catch (WCOS_Bulk_Return_Review_Exception $exception) {
			throw $this->review_exception($exception);
		} catch (WCOS_Bulk_Return_Batch_Exception $exception) {
			throw new WCOS_Bulk_Return_Transport_Exception('review_' . $exception->get_reason(), $exception->getMessage(), 409, false);
		}
		return array(
			'review_id' => $stored['review_id'],
			'review_token' => $stored['review_token'],
			'expires_at' => $stored['expires_at'],
			'summary' => $this->review_summary($stored['plan']),
		);
	}

	public function confirm_request(array $request) {
		$this->assert_gate();
		$this->reject_client_authority($request, array('action', 'nonce', 'review_id', 'review_token'));
		$this->assert_nonce($request);
		try {
			return WCOS_Bulk_Return_Confirmation_Store::create(
				isset($request['review_id']) ? sanitize_key((string) $request['review_id']) : '',
				isset($request['review_token']) ? (string) $request['review_token'] : '',
				get_current_user_id()
			);
		} catch (WCOS_Bulk_Return_Confirmation_Exception $exception) {
			throw $this->confirmation_exception($exception);
		}
	}

	public function execute_request(array $request) {
		$this->assert_gate();
		$this->reject_client_authority($request, array('action', 'nonce', 'batch_id', 'batch_token', 'anchor_child_id', 'cursor'));
		$this->assert_nonce($request);
		try {
			return (new WCOS_Mutation_Gateway())->bulk_return_advance(
				isset($request['batch_id']) ? sanitize_key((string) $request['batch_id']) : '',
				isset($request['anchor_child_id']) ? absint($request['anchor_child_id']) : 0,
				isset($request['batch_token']) ? (string) $request['batch_token'] : '',
				get_current_user_id(),
				isset($request['cursor']) ? (int) $request['cursor'] : -1
			);
		} catch (WCOS_Bulk_Return_Orchestrator_Exception $exception) {
			throw new WCOS_Bulk_Return_Transport_Exception('execute_' . $exception->get_reason(), $exception->getMessage(), 409, $exception->is_retryable());
		}
	}

	public function resume_request(array $request) {
		$this->assert_gate();
		$this->reject_client_authority($request, array('action', 'nonce', 'batch_id', 'batch_token', 'anchor_child_id'));
		$this->assert_nonce($request);
		try {
			return (new WCOS_Bulk_Return_Orchestrator())->resume(
				isset($request['batch_id']) ? sanitize_key((string) $request['batch_id']) : '',
				isset($request['anchor_child_id']) ? absint($request['anchor_child_id']) : 0,
				isset($request['batch_token']) ? (string) $request['batch_token'] : '',
				get_current_user_id()
			);
		} catch (WCOS_Bulk_Return_Orchestrator_Exception $exception) {
			throw new WCOS_Bulk_Return_Transport_Exception('resume_' . $exception->get_reason(), $exception->getMessage(), 409, $exception->is_retryable());
		}
	}

	public function ajax_review() { $this->send_ajax('review_request'); }
	public function ajax_confirm() { $this->send_ajax('confirm_request'); }
	public function ajax_execute() { $this->send_ajax('execute_request'); }
	public function ajax_resume() { $this->send_ajax('resume_request'); }

	public function enqueue_assets() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN) || !$this->is_supported_operator() || !$this->is_orders_list_screen()) { return; }
		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		wp_enqueue_style('wcos-bulk-return-admin', plugins_url('css/p2-bulk-return-admin.css', $plugin_file), array('wcos-admin-backbone-modal'), WC_ORDER_SPLITTER_VERSION);
		wp_enqueue_script('wcos-bulk-return-admin', plugins_url('js/p2-bulk-return-admin.js', $plugin_file), array('jquery', 'wcos-admin-backbone-modal'), WC_ORDER_SPLITTER_VERSION, true);
		wp_localize_script('wcos-bulk-return-admin', 'wcosBulkReturnAdmin', array(
			'bulkAction' => self::BULK_ACTION,
			'title' => __('Bulk Return', 'wc-order-splitter'),
			'reviewing' => __('Reviewing the selected Return children…', 'wc-order-splitter'),
			'confirming' => __('Confirming the exact reviewed batch…', 'wc-order-splitter'),
			'executing' => __('Executing one child Return…', 'wc-order-splitter'),
			'resume' => __('Resume durable batch', 'wc-order-splitter'),
			'review' => __('Review batch', 'wc-order-splitter'),
			'confirm' => __('Confirm batch', 'wc-order-splitter'),
			'execute' => __('Execute next child', 'wc-order-splitter'),
			'retry' => __('Retry current child', 'wc-order-splitter'),
			'failed' => __('The Bulk Return request could not be completed.', 'wc-order-splitter'),
			'mixedBlocked' => __('Every selected row must be eligible. Close this dialog, change the selection, and Review again.', 'wc-order-splitter'),
			'selectedLabel' => __('Selected', 'wc-order-splitter'),
			'canonicalLabel' => __('Canonical', 'wc-order-splitter'),
			'duplicatesLabel' => __('Duplicates', 'wc-order-splitter'),
			'maximumLabel' => __('Maximum', 'wc-order-splitter'),
			'originalLabel' => __('Original', 'wc-order-splitter'),
			'childrenLabel' => __('children', 'wc-order-splitter'),
			'childLabel' => __('Child', 'wc-order-splitter'),
			'linesLabel' => __('lines', 'wc-order-splitter'),
			'ineligibleLabel' => __('Ineligible', 'wc-order-splitter'),
			'completedLabel' => __('Completed', 'wc-order-splitter'),
			'inProgressLabel' => __('In progress', 'wc-order-splitter'),
			'blockedLabel' => __('Blocked', 'wc-order-splitter'),
			'manualLabel' => __('Manual reconciliation', 'wc-order-splitter'),
			'notRunLabel' => __('Not run', 'wc-order-splitter'),
			'completedStatus' => __('Bulk Return completed.', 'wc-order-splitter'),
			'stoppedStatus' => __('Bulk Return stopped. Remaining rows were not run.', 'wc-order-splitter'),
			'readyPrefix' => __('Ready to execute child', 'wc-order-splitter'),
			'ofLabel' => __('of', 'wc-order-splitter'),
			'reviewAgain' => __('Review again after closing this dialog.', 'wc-order-splitter'),
			'retryCurrent' => __('Retry the same durable current row.', 'wc-order-splitter'),
			'cannotContinue' => __('The batch cannot continue automatically.', 'wc-order-splitter'),
			'readingProgress' => __('Reading durable Bulk Return progress…', 'wc-order-splitter'),
		));
	}

	public function render_dialog() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN) || !$this->is_supported_operator() || !$this->is_orders_list_screen()) { return; }
		echo $this->dialog_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function dialog_html() {
		$dialog_id = 'wcos-bulk-return-dialog';
		ob_start();
		?>
		<div id="<?php echo esc_attr($dialog_id); ?>" class="wcos-bulk-return-dialog" role="dialog" aria-modal="true" aria-labelledby="wcos-bulk-return-title" aria-describedby="wcos-bulk-return-description" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>" data-review-action="<?php echo esc_attr(self::REVIEW_ACTION); ?>" data-confirm-action="<?php echo esc_attr(self::CONFIRM_ACTION); ?>" data-execute-action="<?php echo esc_attr(self::EXECUTE_ACTION); ?>" data-resume-action="<?php echo esc_attr(self::RESUME_ACTION); ?>" hidden>
			<div class="wcos-bulk-return-dialog__panel">
				<header><h2 id="wcos-bulk-return-title"><?php esc_html_e('Return selected children to original orders', 'wc-order-splitter'); ?></h2><p id="wcos-bulk-return-description"><?php esc_html_e('Review all server-resolved originals and immutable historical values before confirming. Each request advances at most one child and stops after the first non-success.', 'wc-order-splitter'); ?></p></header>
				<section class="wcos-bulk-return-review" hidden><h3><?php esc_html_e('Batch Review', 'wc-order-splitter'); ?></h3><div class="wcos-bulk-return-counts"></div><div class="wcos-bulk-return-groups"></div><div class="wcos-bulk-return-rows"></div><label class="wcos-bulk-return-ack"><input type="checkbox" class="wcos-bulk-return-acknowledge" /> <span><?php esc_html_e('I understand completed children remain completed if a later row fails, and later rows will not run after the first non-success.', 'wc-order-splitter'); ?></span></label></section>
				<div class="wcos-bulk-return-status" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
				<div class="wcos-bulk-return-error notice notice-error inline" role="alert" tabindex="-1" hidden></div>
				<div class="wcos-bulk-return-results" role="status" aria-live="polite"></div>
				<footer><button type="button" class="button button-large modal-close wcos-bulk-return-close"><?php esc_html_e('Close', 'wc-order-splitter'); ?></button><button type="button" class="button button-secondary wcos-bulk-return-review-button"><?php esc_html_e('Review batch', 'wc-order-splitter'); ?></button><button type="button" class="button button-secondary wcos-bulk-return-confirm-button" hidden disabled><?php esc_html_e('Confirm batch', 'wc-order-splitter'); ?></button><button type="button" class="button button-primary wcos-bulk-return-execute-button" hidden disabled><?php esc_html_e('Execute next child', 'wc-order-splitter'); ?></button></footer>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function review_summary(array $plan) {
		$rows = array();
		$groups = array();
		foreach ($plan['rows'] as $ordinal => $row) {
			$summary = isset($row['summary']) && is_array($row['summary']) ? $row['summary'] : array();
			$summary['ordinal'] = (int) $ordinal;
			$summary['eligible'] = !empty($row['eligible']);
			$summary['reason'] = sanitize_key((string) $row['reason']);
			$summary['message'] = (string) $row['message'];
			$rows[] = $summary;
			$original_id = absint($row['original_order_id']);
			if ($original_id) { $groups[$original_id][] = absint($row['child_order_id']); }
		}
		ksort($groups, SORT_NUMERIC);
		return array(
			'selected_count' => (int) $plan['selected_count'],
			'canonical_count' => (int) $plan['canonical_count'],
			'duplicate_count' => (int) $plan['duplicate_count'],
			'max_children' => (int) $plan['max_children'],
			'all_eligible' => !empty($plan['all_eligible']),
			'groups' => $groups,
			'rows' => $rows,
			'failure_policy' => (string) $plan['failure_policy'],
			'atomicity' => (string) $plan['atomicity'],
		);
	}

	private function assert_gate() {
		if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN)) {
			throw new WCOS_Bulk_Return_Transport_Exception('workflow_disabled', __('Bulk Return is not enabled for production use.', 'wc-order-splitter'), 503, false);
		}
	}

	private function assert_nonce(array $request) {
		$nonce = isset($request['nonce']) ? sanitize_text_field((string) $request['nonce']) : '';
		if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			throw new WCOS_Bulk_Return_Transport_Exception('invalid_nonce', __('The Bulk Return request failed nonce verification.', 'wc-order-splitter'), 403, false);
		}
	}

	private function reject_client_authority(array $request, array $allowed) {
		foreach (array_keys($request) as $field) {
			if (!in_array((string) $field, $allowed, true)) {
				throw new WCOS_Bulk_Return_Transport_Exception('unexpected_field', __('The Bulk Return request contains unsupported client authority.', 'wc-order-splitter'), 400, false);
			}
		}
	}

	private function review_exception(WCOS_Bulk_Return_Review_Exception $exception) {
		$reason = $exception->get_reason();
		$status = in_array($reason, array('invalid_identity', 'invalid_selection', 'batch_too_large'), true) ? 400 : (in_array($reason, array('invalid_token', 'owner_mismatch'), true) ? 403 : (in_array($reason, array('expired', 'already_consumed'), true) ? 410 : 409));
		return new WCOS_Bulk_Return_Transport_Exception('review_' . $reason, $exception->getMessage(), $status, false);
	}

	private function confirmation_exception(WCOS_Bulk_Return_Confirmation_Exception $exception) {
		$reason = $exception->get_reason();
		$status = in_array($reason, array('invalid_identity'), true) ? 400 : (in_array($reason, array('invalid_token', 'owner_mismatch'), true) ? 403 : (in_array($reason, array('expired', 'already_consumed'), true) ? 410 : 409));
		return new WCOS_Bulk_Return_Transport_Exception('confirmation_' . $reason, $exception->getMessage(), $status, false);
	}

	private function send_ajax($method) {
		try {
			wp_send_json_success($this->{$method}(wp_unslash($_POST)));
		} catch (WCOS_Bulk_Return_Transport_Exception $exception) {
			wp_send_json_error(array('code' => $exception->get_error_code(), 'message' => $exception->getMessage(), 'retryable' => $exception->is_retryable()), $exception->get_http_status());
		} catch (Throwable $throwable) {
			wp_send_json_error(array('code' => 'bulk_return_request_failed', 'message' => __('The Bulk Return request could not be completed.', 'wc-order-splitter'), 'retryable' => true), 500);
		}
	}

	private function is_orders_list_screen() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) { return false; }
		$hpos = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
		return 'edit-shop_order' === $screen->id || ($hpos === $screen->id && empty($_GET['id']));
	}

	private function is_supported_operator() {
		try {
			WCOS_Order_Mutation_Authorizer::assert_operator();
			return true;
		} catch (Throwable $throwable) {
			return false;
		}
	}
}
