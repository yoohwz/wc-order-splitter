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

/** Gate-aware request boundary for a future Merge admin UI. */
final class WCOS_Merge_Admin_Controller {
	const REVIEW_ACTION = 'wcos_merge_review';
	const CONFIRM_ACTION = 'wcos_merge_confirm';
	const EXECUTE_ACTION = 'wcos_merge_execute';

	private static $instance = null;
	private $registered = false;

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
		add_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		add_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		add_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		$this->registered = true;
		return true;
	}

	public function unregister_hooks() {
		remove_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
		remove_action('wp_ajax_' . self::CONFIRM_ACTION, array($this, 'ajax_confirm'));
		remove_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
		$this->registered = false;
		return true;
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
		list($source, $target) = $this->authorized_pair($request);
		$operation_id = isset($request['operation_id']) ? sanitize_key((string) $request['operation_id']) : '';
		$token = isset($request['confirmation_token']) ? (string) $request['confirmation_token'] : '';
		try {
			$confirmation = WCOS_Merge_Confirmation_Store::verify($source, $target, $operation_id, $token, get_current_user_id());
		} catch (WCOS_Merge_Confirmation_Exception $exception) {
			throw $this->confirmation_exception($exception);
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

		return array(
			'operation_id' => $operation_id,
			'status' => sanitize_key(isset($result['status']) ? (string) $result['status'] : 'completed'),
			'source_order_id' => absint(isset($result['source_order_id']) ? $result['source_order_id'] : $source->get_id()),
			'target_order_id' => absint(isset($result['target_order_id']) ? $result['target_order_id'] : $target->get_id()),
			'retirement_policy' => sanitize_key(isset($result['retirement_policy']) ? (string) $result['retirement_policy'] : WCOS_Merge_Retirement_Policy::approved_identifier()),
		);
	}

	public function ajax_review() { $this->send_ajax('review_request'); }
	public function ajax_confirm() { $this->send_ajax('confirm_request'); }
	public function ajax_execute() { $this->send_ajax('execute_request'); }

	private function authorized_pair(array $request) {
		$source_id = isset($request['source_order_id']) ? absint($request['source_order_id']) : 0;
		$target_id = isset($request['target_order_id']) ? absint($request['target_order_id']) : 0;
		if (!$source_id || !$target_id || $source_id === $target_id) {
			throw new WCOS_Merge_Transport_Exception('invalid_pair', __('Merge requires two distinct order IDs.', 'wc-order-splitter'), 400, false);
		}
		$nonce = isset($request['nonce']) ? sanitize_text_field((string) $request['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'wcos_merge_orders_' . $source_id . '_' . $target_id)) {
			throw new WCOS_Merge_Transport_Exception('invalid_nonce', __('The Merge request failed nonce verification.', 'wc-order-splitter'), 403, false);
		}
		$source = wc_get_order($source_id);
		$target = wc_get_order($target_id);
		if (!$source instanceof WC_Order || !$target instanceof WC_Order) {
			throw new WCOS_Merge_Transport_Exception('participant_not_found', __('A Merge participant could not be found.', 'wc-order-splitter'), 404, false);
		}
		try {
			WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::MERGE, $source, $target);
		} catch (Throwable $throwable) {
			throw new WCOS_Merge_Transport_Exception('authorization_failed', __('You are not allowed to merge this order pair.', 'wc-order-splitter'), 403, false);
		}
		return array($source, $target);
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
		$projected_units = WCOS_Decimal::to_units($source->get_total(), $precision)
			+ WCOS_Decimal::to_units($target->get_total(), $precision);
		return array(
			'source' => array('id' => $source->get_id(), 'number' => (string) $source->get_order_number(), 'line_count' => count($source->get_items('line_item')), 'total' => (string) $source->get_total()),
			'target' => array('id' => $target->get_id(), 'number' => (string) $target->get_order_number(), 'line_count' => count($target->get_items('line_item')), 'total' => (string) $target->get_total()),
			'transferable_line_count' => count($report['plan']['lines']),
			'projected_active_target_total' => WCOS_Decimal::from_units($projected_units, $precision),
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
}
