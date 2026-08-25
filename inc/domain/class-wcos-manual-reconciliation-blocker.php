<?php

defined('ABSPATH') || exit;

/**
 * Fail-closed source-order blocker for physical-stock incidents.
 *
 * The primary record is a non-autoloaded option so it remains independent of
 * order-storage mode. If that primary option cannot be persisted, a narrowly
 * scoped source-order metadata fallback is written before the journal may move
 * into manual_reconciliation. Preflight treats either store as authoritative.
 *
 * Records contain only source/operation identifiers, timestamps, and journal
 * revisions. No customer, address, payment, or catalog PII is stored here.
 */
final class WCOS_Manual_Reconciliation_Blocker {
    const SCHEMA_VERSION = 3;
    const KEY_PREFIX = 'wcos_manual_reconcile_block_';
    const FALLBACK_META_PREFIX = '_wcos_manual_reconcile_fallback_';

    public static function block(WC_Order $order, $operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        if (!$order->get_id() || '' === $operation_id) {
            return false;
        }

        $incident = self::incident($order, $operation_id);
        if (self::block_primary($order, $operation_id, $incident)) {
            return true;
        }

        return self::block_fallback($order, $operation_id, $incident);
    }

    /**
     * Persist one participant's blocker while keeping the source-keyed journal
     * as the only reconciliation authority.
     */
    public static function block_participant(WC_Order $participant, WC_Order $journal_source, $operation_id, $role, $peer_order_id, $pair_fingerprint) {
        $operation_id = sanitize_key((string) $operation_id);
        $role = sanitize_key((string) $role);
        $peer_order_id = absint($peer_order_id);
        $pair_fingerprint = sanitize_key((string) $pair_fingerprint);
        if (!$participant->get_id() || !$journal_source->get_id() || '' === $operation_id
            || !in_array($role, array('source', 'target'), true) || !$peer_order_id || '' === $pair_fingerprint) {
            return false;
        }

        $journal = class_exists('WCOS_Operation_Journal')
            ? WCOS_Operation_Journal::get($journal_source, $operation_id)
            : null;
        if (!is_array($journal) || !self::validates_pair_participant(
            $journal,
            $participant->get_id(),
            $role,
            $peer_order_id,
            $pair_fingerprint,
            $operation_id
        )) {
            return false;
        }

        $incident = self::incident(
            $participant,
            $operation_id,
            array(
                'participant_role' => $role,
                'peer_order_id' => $peer_order_id,
                'journal_source_order_id' => $journal_source->get_id(),
                'pair_fingerprint' => $pair_fingerprint,
            ),
            $journal
        );
        if (self::block_primary($participant, $operation_id, $incident)) {
            return true;
        }
        return self::block_fallback($participant, $operation_id, $incident);
    }

    public static function block_pair(WC_Order $source, WC_Order $target, $operation_id, $pair_fingerprint) {
        $source_blocked = self::block_participant(
            $source,
            $source,
            $operation_id,
            'source',
            $target->get_id(),
            $pair_fingerprint
        );
        $target_blocked = self::block_participant(
            $target,
            $source,
            $operation_id,
            'target',
            $source->get_id(),
            $pair_fingerprint
        );
        return $source_blocked && $target_blocked;
    }

    /**
     * Return unresolved operation IDs. Missing/non-manual journals are still
     * treated as unresolved because they can represent a crash after a blocker
     * was durably written but before the journal transition completed.
     *
     * Primary-option and fallback-meta incidents are unioned. A reconciliation
     * resolves an operation only when its journal revision is newer than every
     * persisted incident for that operation.
     */
    public static function active_operation_ids(WC_Order $order) {
        if (!$order->get_id()) {
            return array();
        }

        $fresh = wc_get_order($order->get_id());
        if (!$fresh instanceof WC_Order) {
            return array();
        }

        $incidents = self::all_incidents($fresh);
        $active = array();
        foreach ($incidents as $operation_id => $operation_incidents) {
            $latest_incident = self::latest_incident($operation_incidents);
            $journal = self::journal_for_incident($fresh, $operation_id, $latest_incident);
            if (self::journal_resolves_incident($journal, $latest_incident)) {
                $primary_cleared = self::clear_primary($fresh, $operation_id);
                $fallback_cleared = self::clear_fallback($fresh, $operation_id);
                if ($primary_cleared && $fallback_cleared) {
                    continue;
                }
            }

            $active[] = $operation_id;
        }

        if (class_exists('WCOS_Merge_Participation')) {
            $active = array_merge($active, WCOS_Merge_Participation::unresolved_operation_ids($fresh));
        }
        if (class_exists('WCOS_Return_Participation')) {
            $active = array_merge($active, WCOS_Return_Participation::unresolved_operation_ids($fresh));
        }

        $active = array_values(array_unique(array_filter(array_map('sanitize_key', $active))));
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

        $fresh = wc_get_order($order->get_id());
        if (!$fresh instanceof WC_Order) {
            return false;
        }

        $incidents = self::incidents_for_operation($fresh, $operation_id);
        if (empty($incidents)) {
            return true;
        }

        $latest_incident = self::latest_incident($incidents);
        $journal = self::journal_for_incident($fresh, $operation_id, $latest_incident);
        if (!self::journal_resolves_incident($journal, $latest_incident)) {
            return false;
        }

        $primary_cleared = self::clear_primary($fresh, $operation_id);
        $fallback_cleared = self::clear_fallback($fresh, $operation_id);
        return $primary_cleared && $fallback_cleared;
    }

    /**
     * Retention uses this to avoid deleting the authoritative resolution proof
     * while either blocker store still exists because cleanup failed.
     */
    public static function contains_operation($source_order_id, $operation_id, $journal_source_order_id = 0) {
        $source_order_id = absint($source_order_id);
        $operation_id = sanitize_key((string) $operation_id);
        $journal_source_order_id = absint($journal_source_order_id);
        if (!$source_order_id || '' === $operation_id) {
            return false;
        }

        $primary = self::primary_incident($source_order_id, $operation_id);
        if (is_array($primary) && self::matches_journal_source($primary, $journal_source_order_id, $source_order_id)) {
            return true;
        }

        $order = wc_get_order($source_order_id);
        if (!$order instanceof WC_Order) {
            return false;
        }
        $fallback = $order->get_meta(self::fallback_key($operation_id), true);
        return is_array($fallback)
            && self::contains_fallback($order, $operation_id)
            && self::matches_journal_source($fallback, $journal_source_order_id, $source_order_id);
    }

    public static function resolve_pair_if_reconciled(WC_Order $journal_source, $operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        $journal = class_exists('WCOS_Operation_Journal')
            ? WCOS_Operation_Journal::get($journal_source, $operation_id)
            : null;
        $pair = is_array($journal) ? self::pair_participant_ids($journal) : null;
        if (!is_array($pair)) {
            return self::resolve_if_reconciled($journal_source, $operation_id);
        }

        $resolved = true;
        foreach ($pair as $participant_id) {
            $participant = wc_get_order($participant_id);
            if (!$participant instanceof WC_Order || !self::resolve_if_reconciled($participant, $operation_id)) {
                $resolved = false;
            }
        }
        return $resolved;
    }

    private static function block_primary(WC_Order $order, $operation_id, array $incident) {
        $key = self::key($order->get_id());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $current = get_option($key, null);
            if (!is_array($current)) {
                $record = array(
                    'schema_version' => self::SCHEMA_VERSION,
                    'source_order_id' => $order->get_id(),
                    'participant_order_id' => $order->get_id(),
                    'revision' => 1,
                    'operations' => array(
                        $operation_id => $incident,
                    ),
                );
                if (add_option($key, $record, '', false)) {
                    return self::contains_primary($order->get_id(), $operation_id);
                }
                wp_cache_delete($key, 'options');
                continue;
            }

            $replacement = $current;
            $replacement['schema_version'] = self::SCHEMA_VERSION;
            $replacement['source_order_id'] = $order->get_id();
            $replacement['participant_order_id'] = $order->get_id();
            $replacement['revision'] = isset($current['revision']) ? ((int) $current['revision'] + 1) : 2;
            $replacement['operations'] = isset($current['operations']) && is_array($current['operations'])
                ? $current['operations']
                : array();
            $replacement['operations'][$operation_id] = $incident;
            ksort($replacement['operations'], SORT_STRING);

            if (self::compare_and_swap($key, $current, $replacement)) {
                return self::contains_primary($order->get_id(), $operation_id);
            }
        }

        return false;
    }

    private static function block_fallback(WC_Order $order, $operation_id, array $incident) {
        try {
            $fresh = wc_get_order($order->get_id());
            if (!$fresh instanceof WC_Order) {
                return false;
            }

            $incident['operation_id'] = $operation_id;
            $fresh->update_meta_data(self::fallback_key($operation_id), $incident);
            $fresh->save_meta_data();

            $reloaded = wc_get_order($order->get_id());
            return $reloaded instanceof WC_Order && self::contains_fallback($reloaded, $operation_id);
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function incident(WC_Order $order, $operation_id, array $authority = array(), $journal = null) {
        if (!is_array($journal)) {
            $journal = class_exists('WCOS_Operation_Journal')
                ? WCOS_Operation_Journal::get($order, $operation_id)
                : null;
        }
        return array_merge(array(
            'operation_id' => sanitize_key((string) $operation_id),
            'participant_order_id' => $order->get_id(),
            'participant_role' => 'source',
            'peer_order_id' => 0,
            'journal_source_order_id' => $order->get_id(),
            'pair_fingerprint' => '',
            'blocked_at' => gmdate('c'),
            'journal_revision_at_block' => is_array($journal) && isset($journal['revision'])
                ? (int) $journal['revision']
                : 0,
        ), $authority);
    }

    private static function all_incidents(WC_Order $order) {
        $incidents = array();

        $primary = get_option(self::key($order->get_id()), null);
        if (is_array($primary) && !empty($primary['operations']) && is_array($primary['operations'])) {
            foreach ($primary['operations'] as $operation_id => $incident) {
                $operation_id = sanitize_key((string) $operation_id);
                if ('' === $operation_id || !is_array($incident)) {
                    continue;
                }
                $incidents[$operation_id][] = $incident;
            }
        }

        foreach (self::fallback_incidents($order) as $operation_id => $incident) {
            $incidents[$operation_id][] = $incident;
        }

        ksort($incidents, SORT_STRING);
        return $incidents;
    }

    private static function incidents_for_operation(WC_Order $order, $operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        $all = self::all_incidents($order);
        return isset($all[$operation_id]) ? $all[$operation_id] : array();
    }

    private static function fallback_incidents(WC_Order $order) {
        $incidents = array();
        foreach ($order->get_meta_data() as $meta) {
            if (!is_object($meta) || !method_exists($meta, 'get_data')) {
                continue;
            }
            $data = $meta->get_data();
            $key = isset($data['key']) ? (string) $data['key'] : '';
            if (0 !== strpos($key, self::FALLBACK_META_PREFIX)) {
                continue;
            }

            $operation_id = sanitize_key(substr($key, strlen(self::FALLBACK_META_PREFIX)));
            $value = isset($data['value']) && is_array($data['value']) ? $data['value'] : array();
            if ('' === $operation_id || empty($value)) {
                continue;
            }
            $value['operation_id'] = $operation_id;
            $incidents[$operation_id] = $value;
        }
        ksort($incidents, SORT_STRING);
        return $incidents;
    }

    private static function latest_incident(array $incidents) {
        $latest = array('journal_revision_at_block' => PHP_INT_MAX);
        $latest_revision = -1;
        foreach ($incidents as $incident) {
            if (!is_array($incident)) {
                continue;
            }
            $revision = isset($incident['journal_revision_at_block'])
                ? (int) $incident['journal_revision_at_block']
                : PHP_INT_MAX;
            if ($revision > $latest_revision) {
                $latest = $incident;
                $latest_revision = $revision;
            }
        }
        return $latest;
    }

    private static function journal_resolves_incident($journal, $incident) {
        if (!is_array($journal) || !isset($journal['status']) || !isset($journal['revision'])) {
            return false;
        }

        $journal_source_id = is_array($incident) && isset($incident['journal_source_order_id'])
            ? absint($incident['journal_source_order_id'])
            : 0;
        $participant_id = is_array($incident) && isset($incident['participant_order_id'])
            ? absint($incident['participant_order_id'])
            : $journal_source_id;
        $role = is_array($incident) && isset($incident['participant_role'])
            ? sanitize_key((string) $incident['participant_role'])
            : 'source';
        $peer_id = is_array($incident) && isset($incident['peer_order_id'])
            ? absint($incident['peer_order_id'])
            : 0;
        $pair_fingerprint = is_array($incident) && isset($incident['pair_fingerprint'])
            ? sanitize_key((string) $incident['pair_fingerprint'])
            : '';
        if ('' !== $pair_fingerprint) {
            if ($journal_source_id !== absint(isset($journal['source_order_id']) ? $journal['source_order_id'] : 0)
                || !self::validates_pair_participant(
                    $journal,
                    $participant_id,
                    $role,
                    $peer_id,
                    $pair_fingerprint,
                    isset($incident['operation_id']) ? $incident['operation_id'] : ''
                )) {
                return false;
            }
        }
        $status = sanitize_key((string) $journal['status']);
        $journal_revision = (int) $journal['revision'];
        $blocked_revision = is_array($incident) && isset($incident['journal_revision_at_block'])
            ? (int) $incident['journal_revision_at_block']
            : PHP_INT_MAX;

        return 'manual_reconciled' === $status && $journal_revision > $blocked_revision;
    }

    private static function clear_primary(WC_Order $order, $operation_id) {
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

            /*
             * Re-read and re-validate the exact current incident on every CAS
             * attempt. A concurrent block() may replace incident A with a newer
             * incident B between the caller's resolution check and this clear.
             * An older reconciliation must never delete that newer blocker.
             */
            $journal = self::journal_for_incident($order, $operation_id, $current['operations'][$operation_id]);
            if (!self::journal_resolves_incident($journal, $current['operations'][$operation_id])) {
                return false;
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

    private static function clear_fallback(WC_Order $order, $operation_id) {
        $operation_id = sanitize_key((string) $operation_id);
        if ('' === $operation_id) {
            return false;
        }

        try {
            $fresh = wc_get_order($order->get_id());
            if (!$fresh instanceof WC_Order) {
                return false;
            }

            $key = self::fallback_key($operation_id);
            $incident = $fresh->get_meta($key, true);
            if (!is_array($incident) || empty($incident)) {
                return true;
            }

            $journal = self::journal_for_incident($fresh, $operation_id, $incident);
            if (!self::journal_resolves_incident($journal, $incident)) {
                return false;
            }

            $fresh->delete_meta_data($key);
            $fresh->save_meta_data();
            $reloaded = wc_get_order($order->get_id());
            return $reloaded instanceof WC_Order && !self::contains_fallback($reloaded, $operation_id);
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function contains_primary($order_id, $operation_id) {
        return is_array(self::primary_incident($order_id, $operation_id));
    }

    private static function primary_incident($order_id, $operation_id) {
        $record = get_option(self::key($order_id), null);
        return is_array($record)
            && isset($record['operations'])
            && is_array($record['operations'])
            && isset($record['operations'][$operation_id])
            && is_array($record['operations'][$operation_id])
                ? $record['operations'][$operation_id]
                : null;
    }

    private static function contains_fallback(WC_Order $order, $operation_id) {
        $incident = $order->get_meta(self::fallback_key($operation_id), true);
        return is_array($incident)
            && !empty($incident)
            && isset($incident['operation_id'])
            && sanitize_key((string) $incident['operation_id']) === sanitize_key((string) $operation_id);
    }

    private static function journal_for_incident(WC_Order $participant, $operation_id, $incident) {
        if (!class_exists('WCOS_Operation_Journal')) {
            return null;
        }
        $journal_source_id = is_array($incident) && isset($incident['journal_source_order_id'])
            ? absint($incident['journal_source_order_id'])
            : $participant->get_id();
        $journal_source = wc_get_order($journal_source_id);
        return $journal_source instanceof WC_Order
            ? WCOS_Operation_Journal::get($journal_source, $operation_id)
            : null;
    }

    private static function matches_journal_source(array $incident, $expected_source_id, $participant_id) {
        if (!$expected_source_id) {
            return true;
        }
        $actual = isset($incident['journal_source_order_id'])
            ? absint($incident['journal_source_order_id'])
            : absint($participant_id);
        return $actual === $expected_source_id;
    }

    private static function validates_pair_participant(array $journal, $participant_id, $role, $peer_id, $pair_fingerprint, $operation_id) {
        $type = sanitize_key(isset($journal['type']) ? (string) $journal['type'] : '');
        if ('merge' === $type && class_exists('WCOS_Merge_Journal_Context')) {
            return WCOS_Merge_Journal_Context::validates_participant(
                $journal, $participant_id, $role, $peer_id, $pair_fingerprint, $operation_id
            );
        }
        if ('return' === $type && class_exists('WCOS_Return_Journal_Context')) {
            return WCOS_Return_Journal_Context::validates_participant(
                $journal, $participant_id, $role, $peer_id, $pair_fingerprint, $operation_id
            );
        }
        return false;
    }

    private static function pair_participant_ids(array $journal) {
        $type = sanitize_key(isset($journal['type']) ? (string) $journal['type'] : '');
        if ('merge' === $type && class_exists('WCOS_Merge_Journal_Context')) {
            $pair = WCOS_Merge_Journal_Context::pair_from_record($journal);
            return is_array($pair) ? array(absint($pair['source_order_id']), absint($pair['target_order_id'])) : null;
        }
        if ('return' === $type && class_exists('WCOS_Return_Journal_Context')) {
            $pair = WCOS_Return_Journal_Context::pair_from_record($journal);
            return is_array($pair) ? array(absint($pair['child_order_id']), absint($pair['original_order_id'])) : null;
        }
        return null;
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

    private static function fallback_key($operation_id) {
        return self::FALLBACK_META_PREFIX . sanitize_key((string) $operation_id);
    }
}
