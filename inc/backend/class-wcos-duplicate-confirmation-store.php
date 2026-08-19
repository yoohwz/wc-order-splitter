<?php

defined('ABSPATH') || exit;

final class WCOS_Duplicate_Confirmation_Exception extends RuntimeException {
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
 * Short-lived non-PII confirmation record for Duplicate review -> execute.
 * A durable duplicate journal becomes replay authority once mutation starts.
 */
final class WCOS_Duplicate_Confirmation_Store {
    const SCHEMA_VERSION = 1;
    const TTL = 1800;

    public static function create(WC_Order $source, array $preflight, $user_id) {
        $user_id = absint($user_id);
        if (!$source->get_id() || !$user_id) {
            throw new InvalidArgumentException(__('A persisted order and signed-in user are required to confirm Duplicate.', 'wc-order-splitter'));
        }

        $operation_id = wp_generate_uuid4();
        $token = wp_generate_password(48, false, false);
        $precision = WCOS_Price_Precision_Scope::validate(isset($preflight['price_precision']) ? $preflight['price_precision'] : wc_get_price_decimals());
        $precision_token = WCOS_Price_Precision_Scope::begin($precision);
        try {
            $reviewed_signature = isset($preflight['source_signature']) ? (string) $preflight['source_signature'] : '';
            $parsed_signature = WCOS_Order_Contract_Snapshot::source_signature($source);
            if ('' === $reviewed_signature || !hash_equals($reviewed_signature, $parsed_signature)) {
                throw new WCOS_Duplicate_Confirmation_Exception(
                    'source_changed',
                    __('The order changed while Duplicate was being reviewed. Review the current order state again.', 'wc-order-splitter')
                );
            }

            $scoped_source = wc_get_order($source->get_id());
            if (!$scoped_source instanceof WC_Order) {
                throw new WCOS_Duplicate_Confirmation_Exception('source_missing', __('The source order is no longer available.', 'wc-order-splitter'));
            }
            $current_signature = WCOS_Order_Contract_Snapshot::source_signature($scoped_source);
            if (!hash_equals($reviewed_signature, $current_signature)) {
                throw new WCOS_Duplicate_Confirmation_Exception(
                    'source_changed',
                    __('The order changed while the Duplicate confirmation was being created. Review it again.', 'wc-order-splitter')
                );
            }

            $now = time();
            $record = array(
                'schema_version' => self::SCHEMA_VERSION,
                'operation_id' => $operation_id,
                'token_hash' => self::token_hash($token),
                'source_order_id' => $scoped_source->get_id(),
                'user_id' => $user_id,
                'source_signature' => $current_signature,
                'price_precision' => $precision,
                'policy_version' => isset($preflight['policy']['policy_version']) ? absint($preflight['policy']['policy_version']) : 0,
                'created_at' => $now,
                'expires_at' => $now + self::TTL,
            );
        } finally {
            WCOS_Price_Precision_Scope::end($precision_token);
        }

        if (!set_transient(self::key($operation_id), $record, self::TTL)) {
            throw new RuntimeException(__('Unable to create the temporary Duplicate confirmation record.', 'wc-order-splitter'));
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
        if (!self::is_uuid($operation_id) || !$user_id) {
            throw new WCOS_Duplicate_Confirmation_Exception('invalid_identity', __('The Duplicate confirmation identity is invalid.', 'wc-order-splitter'));
        }

        $record = get_transient(self::key($operation_id));
        if (!is_array($record)) {
            return self::durable_replay($source, $operation_id);
        }
        if ('' === $token || !isset($record['token_hash']) || !hash_equals((string) $record['token_hash'], self::token_hash($token))) {
            throw new WCOS_Duplicate_Confirmation_Exception('invalid_token', __('The Duplicate confirmation token is invalid.', 'wc-order-splitter'));
        }
        if (absint($record['source_order_id']) !== $source->get_id() || absint($record['user_id']) !== $user_id) {
            throw new WCOS_Duplicate_Confirmation_Exception('owner_mismatch', __('The Duplicate confirmation does not belong to this user and order.', 'wc-order-splitter'));
        }
        if (empty($record['expires_at']) || (int) $record['expires_at'] < time()) {
            self::delete($operation_id);
            return self::durable_replay($source, $operation_id);
        }
        if (!isset($record['policy_version']) || (int) $record['policy_version'] !== (int) WCOS_Duplicate_Preflight::POLICY_VERSION) {
            throw new WCOS_Duplicate_Confirmation_Exception('policy_changed', __('The Duplicate safety policy changed after review. Review the order again.', 'wc-order-splitter'));
        }

        $precision = WCOS_Price_Precision_Scope::validate(isset($record['price_precision']) ? $record['price_precision'] : null);
        $precision_token = WCOS_Price_Precision_Scope::begin($precision);
        try {
            $scoped_source = wc_get_order($source->get_id());
            if (!$scoped_source instanceof WC_Order) {
                throw new WCOS_Duplicate_Confirmation_Exception('source_missing', __('The source order is no longer available.', 'wc-order-splitter'));
            }

            $journal = WCOS_Operation_Journal::get($scoped_source, $operation_id);
            if (!is_array($journal)) {
                $expected = isset($record['source_signature']) ? (string) $record['source_signature'] : '';
                $actual = WCOS_Order_Contract_Snapshot::source_signature($scoped_source);
                if ('' === $expected || !hash_equals($expected, $actual)) {
                    throw new WCOS_Duplicate_Confirmation_Exception('source_changed', __('The source order changed after Duplicate review. Review it again before executing.', 'wc-order-splitter'));
                }
            } else {
                self::assert_journal_identity($scoped_source, $operation_id, $journal);
                $context = isset($journal['context']) && is_array($journal['context']) ? $journal['context'] : array();
                if (!array_key_exists('price_precision', $context) || (int) $context['price_precision'] !== $precision) {
                    throw new WCOS_Duplicate_Confirmation_Exception('precision_mismatch', __('The Duplicate confirmation precision no longer matches the durable operation journal.', 'wc-order-splitter'));
                }
                if (!array_key_exists('policy_version', $context) || (int) $context['policy_version'] !== (int) $record['policy_version']) {
                    throw new WCOS_Duplicate_Confirmation_Exception('policy_changed', __('The durable Duplicate operation no longer matches the policy that was reviewed.', 'wc-order-splitter'));
                }
            }

            $record['operation_id'] = $operation_id;
            $record['price_precision'] = $precision;
            $record['replay_authority'] = is_array($journal) ? 'journal' : 'confirmation';
            return $record;
        } finally {
            WCOS_Price_Precision_Scope::end($precision_token);
        }
    }

    public static function delete($operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        return self::is_uuid($operation_id) ? delete_transient(self::key($operation_id)) : false;
    }

    private static function durable_replay(WC_Order $source, $operation_id) {
        $journal = WCOS_Operation_Journal::get($source, $operation_id);
        if (!is_array($journal)) {
            throw new WCOS_Duplicate_Confirmation_Exception('expired', __('The Duplicate confirmation expired. Review the order again before executing.', 'wc-order-splitter'));
        }
        self::assert_journal_identity($source, $operation_id, $journal);

        $status = isset($journal['status']) ? sanitize_key((string) $journal['status']) : '';
        if ('manual_reconciliation' === $status) {
            throw new WCOS_Duplicate_Confirmation_Exception('manual_reconciliation', __('This Duplicate operation requires manual reconciliation before it can continue.', 'wc-order-splitter'));
        }
        if (in_array($status, array('manual_reconciled', 'compensated'), true)) {
            throw new WCOS_Duplicate_Confirmation_Exception('operation_closed', __('This Duplicate operation has been closed and cannot be replayed.', 'wc-order-splitter'));
        }

        $context = isset($journal['context']) && is_array($journal['context']) ? $journal['context'] : array();
        if (!array_key_exists('price_precision', $context) || !array_key_exists('policy_version', $context)) {
            throw new WCOS_Duplicate_Confirmation_Exception('journal_incomplete', __('The durable Duplicate operation is missing replay authority and requires manual review.', 'wc-order-splitter'));
        }
        if ((int) $context['policy_version'] !== (int) WCOS_Duplicate_Preflight::POLICY_VERSION) {
            throw new WCOS_Duplicate_Confirmation_Exception('policy_changed', __('The Duplicate safety policy changed after this durable operation started.', 'wc-order-splitter'));
        }

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'operation_id' => $operation_id,
            'source_order_id' => $source->get_id(),
            'price_precision' => WCOS_Price_Precision_Scope::validate($context['price_precision']),
            'policy_version' => (int) $context['policy_version'],
            'replay_authority' => 'journal',
        );
    }

    private static function assert_journal_identity(WC_Order $source, $operation_id, array $journal) {
        if (!isset($journal['type']) || 'duplicate' !== sanitize_key((string) $journal['type'])
            || !isset($journal['source_order_id']) || absint($journal['source_order_id']) !== $source->get_id()
            || !isset($journal['operation_id']) || sanitize_key((string) $journal['operation_id']) !== $operation_id) {
            throw new WCOS_Duplicate_Confirmation_Exception('journal_mismatch', __('The durable Duplicate operation does not match this source order.', 'wc-order-splitter'));
        }
    }

    private static function token_hash($token) {
        return hash_hmac('sha256', (string) $token, wp_salt('auth'));
    }

    private static function key($operation_id) {
        return 'wcos_duplicate_confirm_' . hash('sha256', sanitize_key((string) $operation_id));
    }

    private static function is_uuid($value) {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $value);
    }
}
