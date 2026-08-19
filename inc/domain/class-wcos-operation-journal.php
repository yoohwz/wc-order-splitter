<?php

defined('ABSPATH') || exit;

/**
 * Durable idempotency and recovery journal.
 *
 * The authoritative record is stored in a non-autoloaded option keyed by the
 * source order and operation ID. A bounded order-meta summary is maintained for
 * admin/audit visibility, but it is never used to decide idempotency or
 * recovery. Authoritative checkpoints are never silently discarded.
 */
final class WCOS_Operation_Journal {

    const SCHEMA_VERSION = 2;
    const SUMMARY_META_KEY = '_wcos_operation_journal';
    const MANUAL_RECONCILIATION_META_KEY = '_wcos_manual_reconciliation_operations';
    const MAX_SUMMARY_ENTRIES = 20;

    public static function start(WC_Order $order, $operation_id, $type, array $context = array(), $fingerprint = '') {
        $operation_id = sanitize_key($operation_id);
        $type = sanitize_key($type);
        $fingerprint = sanitize_key($fingerprint);
        if (!$order->get_id() || '' === $operation_id || '' === $type || '' === $fingerprint) {
            return false;
        }

        if ('split' === $type && class_exists('WCOS_Order_Mutation_Snapshot')) {
            $snapshot = WCOS_Order_Mutation_Snapshot::capture_split_source($order);
            $context['source_snapshot'] = $snapshot;
            $context['source_snapshot_fingerprint'] = $snapshot['recovery_fingerprint'];
            $context['source_copy_context_signature'] = $snapshot['copy_context_signature'];
        }
        if (class_exists('WCOS_Price_Precision_Scope')) {
            $context['price_precision'] = WCOS_Price_Precision_Scope::current_or_store_precision();
        }
        if ('split' === $type && class_exists('WCOS_Split_Preflight')) {
            $context['policy_version'] = (int) WCOS_Split_Preflight::POLICY_VERSION;
        }

        $now = gmdate('c');
        $record = array(
            'schema_version' => self::SCHEMA_VERSION,
            'revision' => 1,
            'source_order_id' => $order->get_id(),
            'operation_id' => $operation_id,
            'type' => $type,
            'fingerprint' => $fingerprint,
            'status' => 'started',
            'stage' => 'started',
            'started_at' => $now,
            'updated_at' => $now,
            'completed_at' => null,
            'context' => $context,
            'checkpoints' => array(
                array(
                    'sequence' => 1,
                    'stage' => 'started',
                    'at' => $now,
                    'context' => array(),
                ),
            ),
        );

        if (!add_option(self::key($order->get_id(), $operation_id), $record, '', false)) {
            return false;
        }

        self::write_summary($order, $record);
        return true;
    }

    public static function get(WC_Order $order, $operation_id) {
        $operation_id = sanitize_key($operation_id);
        if (!$order->get_id() || '' === $operation_id) {
            return null;
        }

        $record = get_option(self::key($order->get_id(), $operation_id), null);
        return is_array($record) ? $record : null;
    }

    public static function assert_fingerprint(array $record, $fingerprint) {
        $stored = isset($record['fingerprint']) ? (string) $record['fingerprint'] : '';
        $fingerprint = sanitize_key($fingerprint);
        if ('' === $stored || '' === $fingerprint || !hash_equals($stored, $fingerprint)) {
            throw new RuntimeException(__('This operation ID was already used for a different mutation request.', 'wc-order-splitter'));
        }
    }

    public static function checkpoint(WC_Order $order, $operation_id, $stage, array $context = array()) {
        $stage = sanitize_key($stage);
        if ('' === $stage) {
            return false;
        }
        $context = self::enrich_recovery_context($order, $stage, $context);

        return self::mutate(
            $order,
            $operation_id,
            static function(array $record) use ($stage, $context) {
                $status = isset($record['status']) ? sanitize_key($record['status']) : '';
                if (!in_array($status, array('started', 'recovery_required', 'committed', 'compensating', 'manual_reconciliation'), true)) {
                    return false;
                }
                return self::append_checkpoint($record, $stage, $context);
            }
        );
    }

    public static function mark_committed(WC_Order $order, $operation_id, array $context = array()) {
        $context = self::enrich_recovery_context($order, 'source_committed', $context);
        return self::set_status(
            $order,
            $operation_id,
            'committed',
            'source_committed',
            $context,
            false,
            array('started', 'recovery_required', 'committed')
        );
    }

    public static function complete(WC_Order $order, $operation_id, array $context = array()) {
        $context = self::enrich_recovery_context($order, 'completed', $context);
        return self::set_status(
            $order,
            $operation_id,
            'completed',
            'completed',
            $context,
            true,
            array('committed', 'completed')
        );
    }

    public static function fail(WC_Order $order, $operation_id, array $context = array()) {
        $current = self::get($order, $operation_id);
        if (!is_array($current)) {
            return false;
        }
        $status = isset($current['status']) ? sanitize_key($current['status']) : '';
        if (in_array($status, array('recovery_required', 'compensating'), true)) {
            self::dispatch_recovery($order, $operation_id, $current);
            return true;
        }
        if (in_array($status, array('failed', 'committed', 'completed', 'compensated', 'manual_reconciliation', 'manual_reconciled'), true)) {
            return true;
        }
        return self::set_status(
            $order,
            $operation_id,
            'failed',
            'failed',
            $context,
            true,
            array('started')
        );
    }

    public static function require_recovery(WC_Order $order, $operation_id, array $context = array()) {
        $current = self::get($order, $operation_id);
        if (!is_array($current)) {
            return false;
        }
        $status = isset($current['status']) ? sanitize_key($current['status']) : '';
        if (in_array($status, array('completed', 'compensated', 'manual_reconciliation', 'manual_reconciled'), true)) {
            return true;
        }
        $updated = self::set_status(
            $order,
            $operation_id,
            'recovery_required',
            'recovery_required',
            $context,
            false,
            array('started', 'failed', 'recovery_required', 'committed')
        );
        if ($updated) {
            $fresh_order = wc_get_order($order->get_id());
            $fresh = $fresh_order ? self::get($fresh_order, $operation_id) : null;
            if (is_array($fresh) && $fresh_order) {
                self::dispatch_recovery($fresh_order, $operation_id, $fresh);
            }
        }
        return $updated;
    }

    public static function mark_manual_reconciliation(WC_Order $order, $operation_id, array $context = array()) {
        $operation_id = sanitize_key($operation_id);
        $current = self::get($order, $operation_id);
        if (!is_array($current)) {
            return false;
        }

        $status = isset($current['status']) ? sanitize_key($current['status']) : '';
        if ('manual_reconciliation' === $status) {
            $updated = self::checkpoint($order, $operation_id, 'manual_reconciliation_evidence', $context);
            if ($updated) {
                self::update_manual_reconciliation_index($order, $operation_id, true);
            }
            return $updated;
        }
        if ('manual_reconciled' === $status) {
            return false;
        }

        $context = array_merge(
            array(
                'previous_status' => $status,
                'previous_completed_at' => isset($current['completed_at']) ? $current['completed_at'] : null,
                'automatic_compensation_allowed' => false,
            ),
            $context
        );

        $updated = self::set_status(
            $order,
            $operation_id,
            'manual_reconciliation',
            'manual_reconciliation',
            $context,
            false,
            array('started', 'failed', 'recovery_required', 'committed', 'completed', 'compensating', 'compensated')
        );
        if ($updated) {
            self::update_manual_reconciliation_index($order, $operation_id, true);
        }
        return $updated;
    }

    public static function mark_manual_reconciled(WC_Order $order, $operation_id, array $context = array()) {
        $operation_id = sanitize_key($operation_id);
        $updated = self::set_status(
            $order,
            $operation_id,
            'manual_reconciled',
            'manual_reconciled',
            $context,
            true,
            array('manual_reconciliation')
        );
        if ($updated) {
            self::update_manual_reconciliation_index($order, $operation_id, false);
        }
        return $updated;
    }

    public static function manual_reconciliation_records(WC_Order $order) {
        if (!$order->get_id()) {
            return array();
        }
        $operation_ids = $order->get_meta(self::MANUAL_RECONCILIATION_META_KEY, true);
        $operation_ids = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $operation_ids))));
        $records = array();
        foreach ($operation_ids as $operation_id) {
            $record = self::get($order, $operation_id);
            if (is_array($record) && isset($record['status']) && 'manual_reconciliation' === sanitize_key($record['status'])) {
                $records[$operation_id] = $record;
            }
        }
        ksort($records, SORT_STRING);
        return $records;
    }

    public static function has_manual_reconciliation(WC_Order $order) {
        return !empty(self::manual_reconciliation_records($order));
    }

    public static function resume(WC_Order $order, $operation_id, array $context = array()) {
        return self::set_status(
            $order,
            $operation_id,
            'started',
            'resumed',
            $context,
            false,
            array('started', 'failed', 'recovery_required')
        );
    }

    public static function mark_compensating(WC_Order $order, $operation_id, array $context = array()) {
        return self::set_status(
            $order,
            $operation_id,
            'compensating',
            'compensating',
            $context,
            false,
            array('started', 'failed', 'recovery_required', 'compensating')
        );
    }

    public static function mark_compensated(WC_Order $order, $operation_id, array $context = array()) {
        return self::set_status(
            $order,
            $operation_id,
            'compensated',
            'compensated',
            $context,
            true,
            array('compensating', 'compensated')
        );
    }

    public static function delete(WC_Order $order, $operation_id) {
        $operation_id = sanitize_key($operation_id);
        if (!$order->get_id() || '' === $operation_id) {
            return false;
        }
        $deleted = delete_option(self::key($order->get_id(), $operation_id));
        if ($deleted) {
            self::update_manual_reconciliation_index($order, $operation_id, false);
        }
        return $deleted;
    }

    private static function set_status(WC_Order $order, $operation_id, $status, $stage, array $context, $terminal, array $allowed_from) {
        $status = sanitize_key($status);
        $stage = sanitize_key($stage);
        return self::mutate(
            $order,
            $operation_id,
            static function(array $record) use ($status, $stage, $context, $terminal, $allowed_from) {
                $current_status = isset($record['status']) ? sanitize_key($record['status']) : '';
                if (!in_array($current_status, $allowed_from, true)) {
                    return false;
                }
                $record['status'] = $status;
                $record['completed_at'] = $terminal ? gmdate('c') : null;
                return self::append_checkpoint($record, $stage, $context);
            }
        );
    }

    private static function enrich_recovery_context(WC_Order $order, $stage, array $context) {
        $stage = sanitize_key($stage);
        $target_ids = isset($context['target_order_ids'])
            ? array_values(array_unique(array_filter(array_map('absint', (array) $context['target_order_ids']))))
            : array();
        if (!empty($target_ids) && class_exists('WCOS_Order_Contract_Snapshot')) {
            $signatures = array();
            foreach ($target_ids as $target_id) {
                $target = wc_get_order($target_id);
                if ($target instanceof WC_Order) {
                    $signatures[$target_id] = WCOS_Order_Contract_Snapshot::source_signature($target);
                }
            }
            ksort($signatures, SORT_NUMERIC);
            $context['target_order_ids'] = $target_ids;
            $context['child_signatures'] = $signatures;
        }

        if (in_array($stage, array('source_persisted', 'stock_flags_synchronized', 'source_committed', 'completed'), true)
            && class_exists('WCOS_Order_Mutation_Snapshot')) {
            $fresh = wc_get_order($order->get_id());
            if ($fresh instanceof WC_Order) {
                $context['source_signature_after'] = WCOS_Order_Contract_Snapshot::source_signature($fresh);
                $context['source_recovery_signature_after'] = WCOS_Order_Mutation_Snapshot::split_owned_signature($fresh);
            }
        }
        return $context;
    }

    private static function append_checkpoint(array $record, $stage, array $context) {
        $now = gmdate('c');
        $record['stage'] = sanitize_key($stage);
        $record['updated_at'] = $now;
        $record['context'] = array_merge(
            isset($record['context']) && is_array($record['context']) ? $record['context'] : array(),
            $context
        );
        $checkpoints = isset($record['checkpoints']) && is_array($record['checkpoints']) ? $record['checkpoints'] : array();
        $checkpoints[] = array(
            'sequence' => count($checkpoints) + 1,
            'stage' => sanitize_key($stage),
            'at' => $now,
            'context' => $context,
        );
        $record['checkpoints'] = $checkpoints;
        return $record;
    }

    private static function mutate(WC_Order $order, $operation_id, callable $mutator) {
        $operation_id = sanitize_key($operation_id);
        if (!$order->get_id() || '' === $operation_id) {
            return false;
        }

        $key = self::key($order->get_id(), $operation_id);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $current = get_option($key, null);
            if (!is_array($current)) {
                return false;
            }

            $replacement = $mutator($current);
            if (!is_array($replacement)) {
                return false;
            }
            if (!self::immutable_fields_match($current, $replacement)) {
                return false;
            }

            $replacement['schema_version'] = self::SCHEMA_VERSION;
            $replacement['revision'] = isset($current['revision']) ? ((int) $current['revision'] + 1) : 2;
            if (self::compare_and_swap($key, $current, $replacement)) {
                self::write_summary($order, $replacement);
                return true;
            }
        }

        return false;
    }

    private static function immutable_fields_match(array $current, array $replacement) {
        foreach (array('source_order_id', 'operation_id', 'type', 'fingerprint') as $field) {
            if (!array_key_exists($field, $current)
                || !array_key_exists($field, $replacement)
                || (string) $current[$field] !== (string) $replacement[$field]) {
                return false;
            }
        }

        $current_context = isset($current['context']) && is_array($current['context']) ? $current['context'] : array();
        $replacement_context = isset($replacement['context']) && is_array($replacement['context']) ? $replacement['context'] : array();
        foreach (array('price_precision', 'policy_version') as $field) {
            if (array_key_exists($field, $current_context)) {
                if (!array_key_exists($field, $replacement_context)
                    || (int) $current_context[$field] !== (int) $replacement_context[$field]) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function compare_and_swap($key, array $current, array $replacement) {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize($replacement),
                $key,
                maybe_serialize($current)
            )
        );

        wp_cache_delete($key, 'options');
        return 1 === $updated;
    }

    private static function dispatch_recovery(WC_Order $order, $operation_id, array $record) {
        do_action('wcos_mutation_recovery_required', $order, sanitize_key($operation_id), $record);
    }

    private static function update_manual_reconciliation_index(WC_Order $order, $operation_id, $add) {
        $operation_id = sanitize_key($operation_id);
        if (!$order->get_id() || '' === $operation_id) {
            return;
        }

        $fresh = wc_get_order($order->get_id());
        if (!$fresh instanceof WC_Order) {
            return;
        }

        $operation_ids = $fresh->get_meta(self::MANUAL_RECONCILIATION_META_KEY, true);
        $operation_ids = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $operation_ids))));
        if ($add) {
            $operation_ids[] = $operation_id;
            $operation_ids = array_values(array_unique($operation_ids));
            sort($operation_ids, SORT_STRING);
            $fresh->update_meta_data(self::MANUAL_RECONCILIATION_META_KEY, $operation_ids);
        } else {
            $operation_ids = array_values(array_filter(
                $operation_ids,
                static function($candidate) use ($operation_id) {
                    return $candidate !== $operation_id;
                }
            ));
            if (empty($operation_ids)) {
                $fresh->delete_meta_data(self::MANUAL_RECONCILIATION_META_KEY);
            } else {
                $fresh->update_meta_data(self::MANUAL_RECONCILIATION_META_KEY, $operation_ids);
            }
        }
        $fresh->save_meta_data();
    }

    private static function write_summary(WC_Order $order, array $record) {
        $entries = $order->get_meta(self::SUMMARY_META_KEY, true);
        $entries = is_array($entries) ? $entries : array();
        $summary = array(
            'operation_id' => isset($record['operation_id']) ? $record['operation_id'] : '',
            'type' => isset($record['type']) ? $record['type'] : '',
            'fingerprint' => isset($record['fingerprint']) ? $record['fingerprint'] : '',
            'status' => isset($record['status']) ? $record['status'] : '',
            'stage' => isset($record['stage']) ? $record['stage'] : '',
            'revision' => isset($record['revision']) ? (int) $record['revision'] : 0,
            'started_at' => isset($record['started_at']) ? $record['started_at'] : null,
            'updated_at' => isset($record['updated_at']) ? $record['updated_at'] : null,
            'completed_at' => isset($record['completed_at']) ? $record['completed_at'] : null,
        );

        $replaced = false;
        foreach ($entries as $index => $entry) {
            if (isset($entry['operation_id']) && $entry['operation_id'] === $summary['operation_id']) {
                $entries[$index] = $summary;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $entries[] = $summary;
        }

        $order->update_meta_data(self::SUMMARY_META_KEY, array_slice($entries, -self::MAX_SUMMARY_ENTRIES));
        $order->save_meta_data();
    }

    private static function key($order_id, $operation_id) {
        return 'wcos_mutation_op_' . hash('sha256', absint($order_id) . '|' . sanitize_key($operation_id));
    }
}
