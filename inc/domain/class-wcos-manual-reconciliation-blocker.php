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
            $incident = self::incident($order, $operation_id);
            if (!is_array($current)) {
                $record = array(
                    'schema_version' => self::SCHEMA_VERSION,
                    'source_order_id' => $order->get_id(),
                    'revision' => 1,
                    'operations' => array(
                        $operation_id => $incident,
                    ),
                );
                if (add_option($key, $record, '', false)) {
                    return self::contains($order->get_id(), $operation_id);
                }
                wp_cache_delete($key, 'options');
                continue;
            }

            $replacement = $current;
            $replacement['schema_version'] = self::SCHEMA_VERSION;
            $replacement['source_order_id'] = $order->get_id();
            $replacement['revision'] = isset($current['revision']) ? ((int) $current['revision'] + 1) : 2;
            $replacement['operations'] = isset($current['operations']) && is_array($current['operations'])
                ? $current['operations']
                : array();
            $replacement['operations'][$operation_id] = $incident;
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
     *
     * A manual_reconciled journal clears a blocker only when its journal revision
     * is newer than the revision captured by that stock incident. This prevents a
     * later incident on the same operation from being mistaken for an older
     * reconciliation merely because both happened in the same wall-clock second.
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
        foreach ($record['operations'] as $operation_id => $incident) {
            $operation_id = sanitize_key((string) $operation_id);
            if ('' === $operation_id) {
                continue;
            }
            $journal = class_exists('WCOS_Operation_Journal')
                ? WCOS_Operation_Journal::get($order, $operation_id)
                : null;
            if (self::journal_resolves_incident($journal, $incident)) {
                $resolved[] = $operation_id;
                continue;
            }

            $active[] = $operation_id;
        }

        foreach ($resolved as $operation_id) {
            self::clear($order, $operation_id);
        }

        $active = array_values(array_unique($active));
        sort($active, SORT_STRING);
        return $active;
    }

    public static function has_active(WC_Order $order) {
        return !empty(self::active_operation_ids($order));
    }

    /**
     * Eagerly clear one blocker only after the authoritative journal proves that
     * this exact incident has a newer manual_reconciled transition.
     */
    public static function resolve_if_reconciled(WC_Order $order, $operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        if (!$order->get_id() || '' === $operation_id) {
            return false;
        }

        $record = get_option(self::key($order->get_id()), null);
        if (!is_array($record) || empty($record['operations']) || !is_array($record['operations'])) {
            return true;
        }
        if (!isset($record['operations'][$operation_id])) {
            return true;
        }

        $journal = class_exists('WCOS_Operation_Journal')
            ? WCOS_Operation_Journal::get($order, $operation_id)
            : null;
        if (!self::journal_resolves_incident($journal, $record['operations'][$operation_id])) {
            return false;
        }

        return self::clear($order, $operation_id);
    }

    /**
     * Retention uses this to avoid deleting the authoritative resolution proof
     * while a blocker record still exists because an eager clear failed.
     */
    public static function contains_operation($source_order_id, $operation_id) {
        $source_order_id = absint($source_order_id);
        $operation_id = sanitize_key((string) $operation_id);
        if (!$source_order_id || '' === $operation_id) {
            return false;
        }
        return self::contains($source_order_id, $operation_id);
    }

    private static function incident(WC_Order $order, $operation_id) {
        $journal = class_exists('WCOS_Operation_Journal')
            ? WCOS_Operation_Journal::get($order, $operation_id)
            : null;
        return array(
            'blocked_at' => gmdate('c'),
            'journal_revision_at_block' => is_array($journal) && isset($journal['revision'])
                ? (int) $journal['revision']
                : 0,
        );
    }

    private static function journal_resolves_incident($journal, $incident) {
        if (!is_array($journal) || !isset($journal['status']) || !isset($journal['revision'])) {
            return false;
        }
        $status = sanitize_key((string) $journal['status']);
        $journal_revision = (int) $journal['revision'];
        $blocked_revision = is_array($incident) && isset($incident['journal_revision_at_block'])
            ? (int) $incident['journal_revision_at_block']
            : PHP_INT_MAX;

        return 'manual_reconciled' === $status && $journal_revision > $blocked_revision;
    }

    private static function clear(WC_Order $order, $operation_id) {
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
