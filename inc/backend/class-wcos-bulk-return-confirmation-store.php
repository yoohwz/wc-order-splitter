<?php

defined('ABSPATH') || exit;

final class WCOS_Bulk_Return_Confirmation_Exception extends RuntimeException {
	private $reason;

	public function __construct($reason, $message) {
		$this->reason = sanitize_key((string) $reason);
		parent::__construct((string) $message);
	}

	public function get_reason() { return $this->reason; }
}

/** Creates and authenticates the durable batch coordinator at Confirm. */
final class WCOS_Bulk_Return_Confirmation_Store {
	public static function create($review_id, $review_token, $user_id) {
		$user_id = absint($user_id);
		try {
			$plan = WCOS_Bulk_Return_Review_Store::verify($review_id, $review_token, $user_id);
			if (!WCOS_Bulk_Return_Review_Store::consume($review_id, $review_token, $user_id)) {
				throw new WCOS_Bulk_Return_Review_Exception('already_consumed', __('This Bulk Return Review was already consumed.', 'wc-order-splitter'));
			}
		} catch (WCOS_Bulk_Return_Review_Exception $exception) {
			throw new WCOS_Bulk_Return_Confirmation_Exception($exception->get_reason(), $exception->getMessage());
		}

		$batch_id = wp_generate_uuid4();
		$raw_token = wp_generate_password(48, false, false);
		$operation_map = array();
		foreach ($plan['rows'] as $ordinal => $row) {
			$operation_map[$ordinal] = wp_generate_uuid4();
		}
		$context = WCOS_Bulk_Return_Journal_Context::create($plan, $batch_id, $user_id, $raw_token, $operation_map);
		$anchor = wc_get_order(absint($plan['canonical_child_ids'][0]));
		if (!$anchor instanceof WC_Order || !WCOS_Operation_Journal::start(
			$anchor,
			$batch_id,
			WCOS_Bulk_Return_Journal_Context::TYPE,
			$context,
			$context['bulk_return_batch']['authority_fingerprint']
		)) {
			throw new WCOS_Bulk_Return_Confirmation_Exception('coordinator_persistence_failed', __('The durable Bulk Return coordinator could not be created.', 'wc-order-splitter'));
		}
		$record = WCOS_Operation_Journal::get($anchor, $batch_id);
		try {
			$verified = is_array($record) ? WCOS_Bulk_Return_Journal_Context::verify_request($record, $raw_token, $user_id) : null;
		} catch (Throwable $throwable) {
			$verified = null;
		}
		if (!is_array($verified)) {
			throw new WCOS_Bulk_Return_Confirmation_Exception('coordinator_persistence_failed', __('The durable Bulk Return coordinator could not be authenticated after persistence.', 'wc-order-splitter'));
		}
		return array(
			'batch_id' => $batch_id,
			'batch_token' => $raw_token,
			'anchor_child_id' => $anchor->get_id(),
			'start_next_row_deadline' => (int) $verified['authority']['start_next_row_deadline'],
			'summary' => WCOS_Bulk_Return_Journal_Context::public_summary($verified),
		);
	}

	public static function verify($batch_id, $anchor_child_id, $raw_token, $user_id) {
		$batch_id = sanitize_key((string) $batch_id);
		$anchor = wc_get_order(absint($anchor_child_id));
		if (!$anchor instanceof WC_Order) {
			throw new WCOS_Bulk_Return_Confirmation_Exception('coordinator_missing', __('The Bulk Return coordinator anchor is unavailable.', 'wc-order-splitter'));
		}
		$record = WCOS_Operation_Journal::get($anchor, $batch_id);
		if (!is_array($record)) {
			throw new WCOS_Bulk_Return_Confirmation_Exception('coordinator_missing', __('The durable Bulk Return coordinator is unavailable.', 'wc-order-splitter'));
		}
		try {
			$verified = WCOS_Bulk_Return_Journal_Context::verify_request($record, $raw_token, $user_id);
		} catch (Throwable $throwable) {
			throw new WCOS_Bulk_Return_Confirmation_Exception('coordinator_invalid', __('The Bulk Return coordinator failed integrity or owner verification.', 'wc-order-splitter'));
		}
		if (sanitize_key((string) $verified['authority']['batch_id']) !== $batch_id
			|| absint($verified['authority']['anchor_child_id']) !== $anchor->get_id()) {
			throw new WCOS_Bulk_Return_Confirmation_Exception('coordinator_mismatch', __('The Bulk Return coordinator identity does not match this request.', 'wc-order-splitter'));
		}
		try {
			foreach ($verified['authority']['plan']['rows'] as $row) {
				$child = wc_get_order(absint($row['child_order_id']));
				$original = wc_get_order(absint($row['original_order_id']));
				if (!$child instanceof WC_Order || !$original instanceof WC_Order) {
					throw new RuntimeException(__('A Bulk Return participant is unavailable.', 'wc-order-splitter'));
				}
				WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::BULK_RETURN, $child, $original);
			}
		} catch (Throwable $throwable) {
			throw new WCOS_Bulk_Return_Confirmation_Exception('authorization_failed', __('The operator is no longer authorized for every Bulk Return participant.', 'wc-order-splitter'));
		}
		return array('anchor' => $anchor, 'record' => $record, 'verified' => $verified);
	}
}
