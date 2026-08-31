<?php

defined('ABSPATH') || exit;

final class WCOS_Split_Confirmation_Exception extends RuntimeException {
    private $reason;

    public function __construct($reason, $message) {
        $this->reason = sanitize_key((string) $reason);
        parent::__construct((string) $message);
    }

    public function get_reason() {
        return $this->reason;
    }
}

/**
 * Short-lived non-PII confirmation record for review -> execute transport.
 *
 * The confirmation token is mandatory before the first mutation write. Once a
 * durable journal exists, that journal becomes the replay authority so an
 * interrupted operation can be resumed after the confirmation TTL expires.
 */
final class WCOS_Split_Confirmation_Store {
    const SCHEMA_VERSION = 3;
    const TTL = 1800;

    private static $verified_source_signatures = array();

    public static function create(WC_Order $source, array $plan, array $preflight, $user_id) {
        $user_id = absint($user_id);
        if (!$source->get_id() || !$user_id) {
            throw new InvalidArgumentException(__('A persisted order and signed-in user are required to confirm a Split plan.', 'wc-order-splitter'));
        }

        $operation_id = wp_generate_uuid4();
        $token = wp_generate_password(48, false, false);
        $precision = WCOS_Price_Precision_Scope::validate(isset($preflight['price_precision']) ? $preflight['price_precision'] : wc_get_price_decimals());
        $precision_token = WCOS_Price_Precision_Scope::begin($precision);
        try {
            $reviewed_signature = isset($preflight['source_signature']) ? (string) $preflight['source_signature'] : '';
			try {
				$commercial_policy = WCOS_Split_Commercial_Policy::assert_current(
					$source,
					isset($preflight['policy']) && is_array($preflight['policy']) ? $preflight['policy'] : array()
				);
				WCOS_Split_Commercial_Policy::assert_plan($plan, $commercial_policy);
			} catch (Throwable $throwable) {
				throw new WCOS_Split_Confirmation_Exception('commercial_policy_changed', $throwable->getMessage());
			}
			try {
				$manual_authority = WCOS_Manual_Split_Quantity_Authority::assert_valid(
					isset($preflight['manual_quantity_authority']) && is_array($preflight['manual_quantity_authority'])
						? $preflight['manual_quantity_authority']
						: array()
				);
				$plan = WCOS_Manual_Split_Quantity_Authority::assert_plan($plan, $manual_authority);
			} catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
				throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', $exception->getMessage());
			}

            /*
             * The passed source is the exact object the strict request parser
             * validated. Require it to match the fresh preflight snapshot first,
             * closing parser -> preflight races inside one Review request.
             */
            $parsed_signature = WCOS_Order_Contract_Snapshot::source_signature($source);
            if ('' === $reviewed_signature
				|| !hash_equals($reviewed_signature, $parsed_signature)
				|| !hash_equals($reviewed_signature, (string) $manual_authority['source_signature'])) {
                throw new WCOS_Split_Confirmation_Exception(
                    'source_changed',
                    __('The order changed while the Split plan was being reviewed. Review the current order state again before creating a confirmation.', 'wc-order-splitter')
                );
            }

            /*
             * Rehydrate again under the same reviewed precision and require the
             * database state to remain identical before issuing an operation ID
             * and token. Any later edit is caught by verify() before mutation.
             */
            $scoped_source = wc_get_order($source->get_id());
            if (!$scoped_source instanceof WC_Order) {
                throw new RuntimeException(__('The source order is no longer available.', 'wc-order-splitter'));
            }
            $current_signature = WCOS_Order_Contract_Snapshot::source_signature($scoped_source);
            if (!hash_equals($reviewed_signature, $current_signature)) {
                throw new WCOS_Split_Confirmation_Exception(
                    'source_changed',
                    __('The order changed while the Split confirmation was being created. Review the current order state again.', 'wc-order-splitter')
                );
            }
			try {
				WCOS_Manual_Split_Quantity_Authority::assert_current($scoped_source, $manual_authority);
			} catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
				throw new WCOS_Split_Confirmation_Exception('quantity_authority_changed', $exception->getMessage());
			}

            $now = time();
            $record = array(
                'schema_version' => self::SCHEMA_VERSION,
                'operation_id' => $operation_id,
                'token_hash' => self::token_hash($token),
                'source_order_id' => $scoped_source->get_id(),
                'user_id' => $user_id,
                'source_signature' => $current_signature,
                'plan' => WCOS_Split_Plan::canonicalize_request($plan),
                'price_precision' => $precision,
                'policy_version' => isset($preflight['policy']['policy_version']) ? absint($preflight['policy']['policy_version']) : 0,
				'manual_quantity_authority' => $manual_authority,
				'manual_quantity_authority_fingerprint' => $manual_authority['authority_fingerprint'],
				'commercial_policy' => $commercial_policy,
                'created_at' => $now,
                'expires_at' => $now + self::TTL,
            );
        } finally {
            WCOS_Price_Precision_Scope::end($precision_token);
        }

        if (!set_transient(self::key($operation_id), $record, self::TTL)) {
            throw new RuntimeException(__('Unable to create the temporary Split confirmation record.', 'wc-order-splitter'));
        }

        return array(
            'operation_id' => $operation_id,
            'confirmation_token' => $token,
            'expires_at' => $record['expires_at'],
            'record' => $record,
        );
    }

    public static function verify(WC_Order $source, $operation_id, $token, $user_id) {
        $operation_id = sanitize_key((string) $operation_id);
        $token = (string) $token;
        $user_id = absint($user_id);
        unset(self::$verified_source_signatures[$operation_id]);
        if (!self::is_uuid($operation_id) || !$user_id) {
            throw new WCOS_Split_Confirmation_Exception('invalid_identity', __('The Split confirmation identity is invalid.', 'wc-order-splitter'));
        }

        $record = get_transient(self::key($operation_id));
        if (!is_array($record)) {
            return self::durable_replay($source, $operation_id);
        }
        if ('' === $token || !isset($record['token_hash']) || !hash_equals((string) $record['token_hash'], self::token_hash($token))) {
            throw new WCOS_Split_Confirmation_Exception('invalid_token', __('The Split confirmation token is invalid.', 'wc-order-splitter'));
        }
        if (absint($record['source_order_id']) !== $source->get_id() || absint($record['user_id']) !== $user_id) {
            throw new WCOS_Split_Confirmation_Exception('owner_mismatch', __('The Split confirmation does not belong to this user and order.', 'wc-order-splitter'));
        }
        if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
            self::delete($operation_id);
            return self::durable_replay($source, $operation_id);
        }
		$existing_journal = WCOS_Operation_Journal::get($source, $operation_id);
		if (is_array($existing_journal)) {
			return self::durable_replay($source, $operation_id);
		}
        if (!isset($record['policy_version']) || (int) $record['policy_version'] !== (int) WCOS_Split_Preflight::POLICY_VERSION) {
            throw new WCOS_Split_Confirmation_Exception('policy_changed', __('The Split safety policy changed after this plan was reviewed. Review the plan again before executing it.', 'wc-order-splitter'));
        }
		if (!isset($record['schema_version']) || self::SCHEMA_VERSION !== (int) $record['schema_version']) {
			throw new WCOS_Split_Confirmation_Exception('commercial_policy_changed', __('The Split confirmation predates the required commercial policy authority. Review the plan again.', 'wc-order-splitter'));
		}
		try {
			$commercial_policy = WCOS_Split_Commercial_Policy::assert_valid(
				isset($record['commercial_policy']) && is_array($record['commercial_policy']) ? $record['commercial_policy'] : array()
			);
		} catch (Throwable $throwable) {
			throw new WCOS_Split_Confirmation_Exception('commercial_policy_changed', $throwable->getMessage());
		}
		try {
			$manual_authority = WCOS_Manual_Split_Quantity_Authority::assert_valid(
				isset($record['manual_quantity_authority']) && is_array($record['manual_quantity_authority'])
					? $record['manual_quantity_authority']
					: array()
			);
		} catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
			throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', $exception->getMessage());
		}
		if (empty($record['manual_quantity_authority_fingerprint'])
			|| !hash_equals((string) $record['manual_quantity_authority_fingerprint'], (string) $manual_authority['authority_fingerprint'])) {
			throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', __('The Split confirmation quantity-step fingerprint is incomplete.', 'wc-order-splitter'));
		}

        $precision = WCOS_Price_Precision_Scope::validate(isset($record['price_precision']) ? $record['price_precision'] : null);
        $precision_token = WCOS_Price_Precision_Scope::begin($precision);
        try {
            $scoped_source = wc_get_order($source->get_id());
            if (!$scoped_source instanceof WC_Order) {
                throw new WCOS_Split_Confirmation_Exception('source_missing', __('The source order is no longer available.', 'wc-order-splitter'));
            }

            $journal = WCOS_Operation_Journal::get($scoped_source, $operation_id);
            if (!is_array($journal)) {
				try {
					WCOS_Split_Commercial_Policy::assert_current($scoped_source, $commercial_policy);
				} catch (Throwable $throwable) {
					throw new WCOS_Split_Confirmation_Exception('commercial_policy_changed', $throwable->getMessage());
				}
                $expected = isset($record['source_signature']) ? (string) $record['source_signature'] : '';
                $actual = WCOS_Order_Contract_Snapshot::source_signature($scoped_source);
                if ('' === $expected || !hash_equals($expected, $actual)) {
                    throw new WCOS_Split_Confirmation_Exception('source_changed', __('The order changed after the Split plan was reviewed. Review the plan again before executing it.', 'wc-order-splitter'));
                }
                self::$verified_source_signatures[$operation_id] = $expected;
				try {
					WCOS_Manual_Split_Quantity_Authority::assert_current($scoped_source, $manual_authority);
				} catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
					throw new WCOS_Split_Confirmation_Exception('quantity_authority_changed', $exception->getMessage());
				}
				try {
					WCOS_Manual_Split_Quantity_Authority::assert_plan(
						isset($record['plan']) && is_array($record['plan']) ? $record['plan'] : array(),
						$manual_authority
					);
				} catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
					throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', $exception->getMessage());
				}
            } else {
                $journal_context = isset($journal['context']) && is_array($journal['context']) ? $journal['context'] : array();
                if (!array_key_exists('price_precision', $journal_context)
                    || (int) $journal_context['price_precision'] !== $precision) {
                    throw new WCOS_Split_Confirmation_Exception('precision_mismatch', __('The Split confirmation precision no longer matches the durable operation journal.', 'wc-order-splitter'));
                }
                if (!array_key_exists('policy_version', $journal_context)
                    || (int) $journal_context['policy_version'] !== (int) $record['policy_version']) {
                    throw new WCOS_Split_Confirmation_Exception('policy_changed', __('The durable Split operation no longer matches the safety policy that was reviewed.', 'wc-order-splitter'));
                }
				if (empty($journal_context['manual_quantity_authority'])
					|| !is_array($journal_context['manual_quantity_authority'])) {
					throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', __('The durable Split operation is missing Manual quantity-step replay authority.', 'wc-order-splitter'));
				}
				try {
					$journal_authority = WCOS_Manual_Split_Quantity_Authority::assert_valid($journal_context['manual_quantity_authority']);
				} catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
					throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', $exception->getMessage());
				}
				if (!hash_equals($manual_authority['authority_fingerprint'], $journal_authority['authority_fingerprint'])) {
					throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', __('The durable Split quantity-step authority does not match its confirmation.', 'wc-order-splitter'));
				}
				self::assert_manual_execution_policy($journal_context, $journal_authority);
				try {
					$journal_commercial = WCOS_Split_Commercial_Policy::from_journal($journal);
				} catch (Throwable $throwable) {
					throw new WCOS_Split_Confirmation_Exception('commercial_policy_changed', $throwable->getMessage());
				}
				if ((string) $commercial_policy['policy_fingerprint'] !== (string) $journal_commercial['policy_fingerprint']) {
					throw new WCOS_Split_Confirmation_Exception('commercial_policy_changed', __('The durable Split commercial policy does not match its confirmation.', 'wc-order-splitter'));
				}
            }

            $record['operation_id'] = $operation_id;
            $record['plan'] = WCOS_Split_Plan::canonicalize_request(isset($record['plan']) && is_array($record['plan']) ? $record['plan'] : array());
            $record['price_precision'] = $precision;
			$record['manual_quantity_authority'] = $manual_authority;
			$record['commercial_policy'] = $commercial_policy;
            $record['replay_authority'] = is_array($journal) ? 'journal' : 'confirmation';
            return $record;
        } finally {
            WCOS_Price_Precision_Scope::end($precision_token);
        }
    }

    public static function verified_source_signature($operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        return isset(self::$verified_source_signatures[$operation_id])
            ? (string) self::$verified_source_signatures[$operation_id]
            : '';
    }

    public static function delete($operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        unset(self::$verified_source_signatures[$operation_id]);
        if (!self::is_uuid($operation_id)) {
            return false;
        }
        return delete_transient(self::key($operation_id));
    }

    private static function durable_replay(WC_Order $source, $operation_id) {
        unset(self::$verified_source_signatures[$operation_id]);
        $journal = WCOS_Operation_Journal::get($source, $operation_id);
        if (!is_array($journal)) {
            throw new WCOS_Split_Confirmation_Exception('expired', __('The Split confirmation expired. Review the plan again before executing it.', 'wc-order-splitter'));
        }
        if (!isset($journal['type']) || 'split' !== sanitize_key((string) $journal['type'])
            || !isset($journal['source_order_id']) || absint($journal['source_order_id']) !== $source->get_id()) {
            throw new WCOS_Split_Confirmation_Exception('journal_mismatch', __('The durable Split operation does not match this source order.', 'wc-order-splitter'));
        }

        $status = isset($journal['status']) ? sanitize_key((string) $journal['status']) : '';
        if ('manual_reconciliation' === $status) {
            throw new WCOS_Split_Confirmation_Exception('manual_reconciliation', __('This Split operation requires manual reconciliation before it can continue.', 'wc-order-splitter'));
        }
        if (in_array($status, array('manual_reconciled', 'compensated'), true)) {
            throw new WCOS_Split_Confirmation_Exception('operation_closed', __('This Split operation has been closed and cannot be replayed.', 'wc-order-splitter'));
        }

        $context = isset($journal['context']) && is_array($journal['context']) ? $journal['context'] : array();
        if (empty($context['plan'])
            || !is_array($context['plan'])
            || !array_key_exists('price_precision', $context)
            || !array_key_exists('policy_version', $context)) {
            throw new WCOS_Split_Confirmation_Exception('journal_incomplete', __('The durable Split operation is missing replay information and requires manual review.', 'wc-order-splitter'));
        }
		try {
			$commercial_policy = WCOS_Split_Commercial_Policy::from_journal($journal);
		} catch (Throwable $throwable) {
			throw new WCOS_Split_Confirmation_Exception('commercial_policy_changed', $throwable->getMessage());
		}

        $result = array(
            'schema_version' => self::SCHEMA_VERSION,
            'operation_id' => $operation_id,
            'source_order_id' => $source->get_id(),
            'plan' => WCOS_Split_Plan::canonicalize_request($context['plan']),
            'price_precision' => WCOS_Price_Precision_Scope::validate($context['price_precision']),
			'policy_version' => (int) $context['policy_version'],
			'commercial_policy' => $commercial_policy,
            'replay_authority' => 'journal',
        );
		if (!isset($context['manual_quantity_authority']) || !is_array($context['manual_quantity_authority'])) {
			throw new WCOS_Split_Confirmation_Exception(
				'quantity_authority_incomplete',
				__('The durable Manual Split operation predates required quantity-step authority and cannot be resumed automatically.', 'wc-order-splitter')
			);
		}
		try {
			$result['manual_quantity_authority'] = WCOS_Manual_Split_Quantity_Authority::assert_valid($context['manual_quantity_authority']);
			$result['plan'] = WCOS_Manual_Split_Quantity_Authority::assert_plan($result['plan'], $result['manual_quantity_authority']);
			self::assert_manual_execution_policy($context, $result['manual_quantity_authority']);
		} catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
			throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', $exception->getMessage());
		}
		return $result;
	}

	private static function assert_manual_execution_policy(array $context, array $manual_authority) {
		try {
			$expected = WCOS_Manual_Split_Quantity_Authority::execution_policy($manual_authority);
			$actual = WCOS_Split_Execution_Policy::normalize(
				isset($context['execution_policy']) ? $context['execution_policy'] : ''
			);
		} catch (Throwable $throwable) {
			throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', __('The durable Manual Split execution policy is invalid.', 'wc-order-splitter'));
		}
		if ($expected !== $actual) {
			throw new WCOS_Split_Confirmation_Exception('quantity_authority_incomplete', __('The durable Manual Split execution policy does not match its versioned quantity authority.', 'wc-order-splitter'));
		}
	}

    private static function token_hash($token) {
        return hash_hmac('sha256', (string) $token, wp_salt('auth'));
    }

    private static function key($operation_id) {
        return 'wcos_split_confirm_' . hash('sha256', sanitize_key((string) $operation_id));
    }

    private static function is_uuid($value) {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value);
    }
}
