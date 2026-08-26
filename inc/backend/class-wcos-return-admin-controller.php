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

/** Gate-aware request authority for future Return UI; no UI hooks belong here. */
final class WCOS_Return_Admin_Controller {
	const REVIEW_ACTION = 'wcos_return_review';
	const CONFIRM_ACTION = 'wcos_return_confirm';
	const EXECUTE_ACTION = 'wcos_return_execute';

	private static $instance = null;
	private $registered = false;

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
			throw new WCOS_Return_Transport_Exception($exception->get_error_code(), __('The hardened Return request did not complete automatically.', 'wc-order-splitter'), 409, true);
		} catch (RuntimeException $exception) {
			if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER)) {
				throw new WCOS_Return_Transport_Exception('workflow_disabled', __('Hardened Return is not enabled for production use yet.', 'wc-order-splitter'), 503, false);
			}
			throw new WCOS_Return_Transport_Exception('return_failed', __('The hardened Return request did not complete automatically.', 'wc-order-splitter'), 409, true);
		}

		return array(
			'operation_id' => $operation_id,
			'status' => sanitize_key(isset($result['status']) ? (string) $result['status'] : 'completed'),
			'child_order_id' => absint(isset($result['child_order_id']) ? $result['child_order_id'] : $child->get_id()),
			'original_order_id' => absint(isset($result['original_order_id']) ? $result['original_order_id'] : $confirmation['original_order_id']),
			'retirement_policy' => sanitize_key(isset($result['retirement_policy']) ? (string) $result['retirement_policy'] : WCOS_Return_Retirement_Policy::approved_identifier()),
		);
	}

	public function ajax_review() { $this->send_ajax('review_request'); }
	public function ajax_confirm() { $this->send_ajax('confirm_request'); }
	public function ajax_execute() { $this->send_ajax('execute_request'); }

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
			'compatibility' => array('supported' => true, 'reason' => 'supported'),
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
}
