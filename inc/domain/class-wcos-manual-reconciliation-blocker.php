<?php

defined('ABSPATH') || exit;

/**
 * Fail-closed source-order blocker for physical-stock incidents.
 *
 * This record is intentionally persisted before the journal transitions into
 * manual_reconciliation. If the process dies between those two writes, the
 * source remains blocked rather than creating a false-negative preflight gap.
 * The record contains only source/operation identifiers and timestamps.
 */
final class WCOS_Manual_Reconciliation_Blocker {
    const SCHEMA_VERSION = 1;
    const KEY_PREFIX = 'wcos_manual_reconcile_block_';

    public static function block(WC_Order $order, $operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        if (!$order->get_id() || '' === $operation_id) {
            return false;
        }

        $key = self::key($order->get_id());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $current = get_option($key, null);
            if (!is_array($current)) {
                $record = array(
                    'schema_version' => self::SCHEMA_VERSION,
                    'source_order_id' => $order->get_id(),
                    'revision' => 1,
                    'operations' => array(
                        $operation_id => array('blocked_at' => gmdate('c')),
                    ),
                );
                if (add_option($key, $record, '', false)) {
                    return self::contains($order->get_id(), $operation_id);
                }
                wp_cache_delete($key, 'options');
                continue;
            }

            if (isset($current['operations'][$operation_id])) {
                return true;
            }

            $replacement = $current;
            $replacement['schema_version'] = self::SCHEMA_VERSION;
            $replacement['source_order_id'] = $order->get_id();
            $replacement['revision'] = isset($current['revision']) ? ((int) $current['revision'] + 1) : 2;
            $replacement['operations'] = isset($current['operations']) && is_array($current['operations'])
                ? $current['operations']
                : array();
            $replacement['operations'][$operation_id] = array('blocked_at' => gmdate('c'));
            ksort($replacement['operations'], SORT_STRING);

            if (self::compare_and_swap($key, $current, $replacement)) {
                return self::contains($order->get_id(), $operation_id);
            }
        }

        return false;
    }

    /**
     * Return unresolved operation IDs. Missing/non-manual journals are still
     * treated as unresolved because they can represent a crash after the blocker
     * was durably written but before the journal transition completed.
     */
    public static function active_operation_ids(WC_Order $order) {
        if (!$order->get_id()) {
            return array();
        }

        $record = get_option(self::key($order->get_id()), null);
        if (!is_array($record) || empty($record['operations']) || !is_array($record['operations'])) {
            return array();
        }

        $active = array();
        $resolved = array();
        foreach (array_keys($record['operations']) as $operation_id) {
            $operation_id = sanitize_key((string) $operation_id);
            if ('' === $operation_id) {
                continue;
            }
            $journal = class_exists('WCOS_Operation_Journal')
                ? WCOS_Operation_Journal::get($order, $operation_id)
                : null;
            $status = is_array($journal) && isset($journal['status'])
                ? sanitize_key((string) $journal['status'])
                : '';

            if ('manual_reconciled' === $status) {
                $resolved[] = $operation_id;
                continue;
            }

            $active[] = $operation_id;
        }

        foreach ($resolved as $operation_id) {
            self::clear_resolved($order, $operation_id);
        }

        $active = array_values(array_unique($active));
        sort($active, SORT_STRING);
        return $active;
    }

    public static function has_active(WC_Order $order) {
        return !empty(self::active_operation_ids($order));
    }

    private static function clear_resolved(WC_Order $order, $operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        $key = self::key($order->get_id());

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $current = get_option($key, null);
            if (!is_array($current) || empty($current['operations']) || !is_array($current['operations'])) {
                return true;
            }
            if (!isset($current['operations'][$operation_id])) {
                return true;
            }

            $replacement = $current;
            unset($replacement['operations'][$operation_id]);
            if (empty($replacement['operations'])) {
                global $wpdb;
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                        $key,
                        maybe_serialize($current)
                    )
                );
                wp_cache_delete($key, 'options');
                if (1 === $deleted || null === get_option($key, null)) {
                    return true;
                }
                continue;
            }

            $replacement['revision'] = isset($current['revision']) ? ((int) $current['revision'] + 1) : 2;
            if (self::compare_and_swap($key, $current, $replacement)) {
                return true;
            }
        }

        return false;
    }

    private static function contains($order_id, $operation_id) {
        $record = get_option(self::key($order_id), null);
        return is_array($record)
            && isset($record['operations'])
            && is_array($record['operations'])
            && isset($record['operations'][$operation_id]);
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

    private static function key($order_id) {
        return self::KEY_PREFIX . absint($order_id);
    }
}
