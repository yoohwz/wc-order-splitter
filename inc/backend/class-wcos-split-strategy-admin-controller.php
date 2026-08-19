<?php

defined('ABSPATH') || exit;

/**
 * Hard-off admin transport contract for future Category/Stock-status Split.
 *
 * This class intentionally registers no WordPress hooks. A later production
 * enablement milestone may register strategy-specific routes only after its
 * exact gate, UI, accessibility, and Human Gate acceptance. Direct method calls
 * still enforce strategy gate, nonce, authorization, order-status policy, and
 * server-side Review/Confirmation authority.
 */
final class WCOS_Split_Strategy_Admin_Controller {
	const REVIEW_ACTION = 'wcos_split_strategy_review';
	const CONFIRM_ACTION = 'wcos_split_strategy_confirm';
	const EXECUTE_ACTION = 'wcos_split_strategy_execute';

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
		 * The server-side Review is single-use confirmation authority. Two
		 * concurrent Confirm requests may both pass verify(), but only the request
		 * that consumes the Review may keep and return its new confirmation. The
		 * loser deletes its unexposed confirmation token and fails closed.
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
		$order = $this->authorized_order($request, $order_id);
		$operation_id = isset($request['operation_id']) ? sanitize_key((string) $request['operation_id']) : '';
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
			throw new WCOS_Split_Transport_Exception(
				'workflow_disabled',
				__('Split is not enabled for production use.', 'wc-order-splitter'),
				503,
				false
			);
		}
		if (!WCOS_Split_Strategy_Gates::enabled($strategy)) {
			throw new WCOS_Split_Transport_Exception(
				'strategy_disabled',
				__('This Split strategy is not enabled for production use.', 'wc-order-splitter'),
				503,
				false
			);
		}
	}

	private function authorized_order(array $request, $order_id) {
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
		$this->assert_status_enabled($order);
		return $order;
	}

	private function assert_status_enabled(WC_Order $order) {
		$allowed = (array) get_option('order_splitter_status_allowed', array('wc-processing'));
		$status = 'wc-' . $order->get_status();
		if (!in_array($status, $allowed, true)) {
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
			'source_missing' => 404,
			'review_invalid' => 409,
		);
		$reason = $exception->get_reason();
		return new WCOS_Split_Transport_Exception(
			'review_' . $reason,
			$exception->getMessage(),
			isset($statuses[$reason]) ? $statuses[$reason] : 409,
			false
		);
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
			'execution_policy_mismatch' => 409,
			'journal_mismatch' => 409,
			'manual_reconciliation' => 409,
			'operation_closed' => 409,
			'journal_incomplete' => 409,
			'authority_incomplete' => 409,
		);
		$reason = $exception->get_reason();
		return new WCOS_Split_Transport_Exception(
			'confirmation_' . $reason,
			$exception->getMessage(),
			isset($statuses[$reason]) ? $statuses[$reason] : 409,
			false
		);
	}
}
