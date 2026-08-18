<?php
/**
 * Lease-guarded structured split-order relations.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Maintains reciprocal source/child relations and legacy compatibility metadata.
 */
final class WCOS_V2_Relation_Repository {

	private const SOURCE_META = '_wcos_v2_split_relations';
	private const CHILD_META  = '_wcos_v2_parent_relation';

	/**
	 * Stage a reciprocal relation before the source mutation begins.
	 *
	 * @param WC_Order $source       Source order.
	 * @param WC_Order $child        Child order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Exact source-order lease ID.
	 * @param string   $type         Relation type.
	 * @return array|WP_Error
	 */
	public static function stage(WC_Order $source, WC_Order $child, $operation_id, $lease_id, $type = 'quantity_split') {
		$lease_check = self::assert_lease($source, $operation_id, $lease_id);

		if (is_wp_error($lease_check)) {
			return $lease_check;
		}

		$source_id    = $source->get_id();
		$child_id     = $child->get_id();
		$operation_id = self::identifier($operation_id);
		$type         = self::identifier($type);

		if (!$source_id || !$child_id || $source_id === $child_id || '' === $operation_id || '' === $type) {
			return self::error('wcos_invalid_order_relation', __('The split-order relationship is invalid.', 'wc-order-splitter'));
		}

		$source_relations = self::source_relations($source);
		$child_relation   = self::child_relation($child);

		if (isset($source_relations[$operation_id])) {
			$existing = self::normalize_relation($source_relations[$operation_id]);

			if (is_wp_error($existing)) {
				return $existing;
			}

			if ((int) $existing['child_order_id'] !== $child_id || !hash_equals($existing['type'], $type)) {
				return self::error('wcos_relation_conflict', __('This operation ID is already related to another order.', 'wc-order-splitter'));
			}

			if (is_array($child_relation)
				&& (int) $child_relation['source_order_id'] === $source_id
				&& hash_equals($child_relation['operation_id'], $operation_id)
			) {
				return $existing;
			}
		}

		if (is_array($child_relation)) {
			if ((int) $child_relation['source_order_id'] !== $source_id || !hash_equals($child_relation['operation_id'], $operation_id)) {
				return self::error('wcos_child_relation_conflict', __('The target order already belongs to another split operation.', 'wc-order-splitter'));
			}
		}

		$now = time();
		$relation = array(
			'schema_version'  => 1,
			'operation_id'    => $operation_id,
			'type'            => $type,
			'status'          => 'staged',
			'source_order_id' => (int) $source_id,
			'child_order_id'  => (int) $child_id,
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		$source_relations[$operation_id] = $relation;
		$source->update_meta_data(self::SOURCE_META, $source_relations);
		$child->update_meta_data(self::CHILD_META, $relation);
		$child->save_meta_data();
		$source->save_meta_data();

		return $relation;
	}

	/**
	 * Commit a staged relation and publish legacy compatibility metadata.
	 *
	 * @param WC_Order $source       Source order.
	 * @param WC_Order $child        Child order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Lease ID.
	 * @return array|WP_Error
	 */
	public static function commit(WC_Order $source, WC_Order $child, $operation_id, $lease_id) {
		$lease_check = self::assert_lease($source, $operation_id, $lease_id);

		if (is_wp_error($lease_check)) {
			return $lease_check;
		}

		$operation_id     = self::identifier($operation_id);
		$source_relations = self::source_relations($source);
		$child_relation   = self::child_relation($child);

		if (!isset($source_relations[$operation_id]) || !is_array($child_relation)) {
			return self::error('wcos_relation_not_staged', __('The split-order relationship was not staged.', 'wc-order-splitter'));
		}

		$source_relation = self::normalize_relation($source_relations[$operation_id]);

		if (is_wp_error($source_relation)) {
			return $source_relation;
		}

		if ((int) $source_relation['source_order_id'] !== $source->get_id()
			|| (int) $source_relation['child_order_id'] !== $child->get_id()
			|| !hash_equals($source_relation['operation_id'], (string) $child_relation['operation_id'])
		) {
			return self::error('wcos_relation_mismatch', __('The reciprocal split-order relationship does not match.', 'wc-order-splitter'));
		}

		if ('committed' === $source_relation['status']) {
			return $source_relation;
		}

		if ('staged' !== $source_relation['status']) {
			return self::error('wcos_relation_state_conflict', __('The split-order relationship is in an invalid state.', 'wc-order-splitter'));
		}

		$source_relation['status']     = 'committed';
		$source_relation['updated_at'] = time();
		$source_relations[$operation_id] = $source_relation;

		$source->update_meta_data(self::SOURCE_META, $source_relations);
		$child->update_meta_data(self::CHILD_META, $source_relation);
		self::add_legacy_child($source, $child->get_id());
		$child->update_meta_data('yoos_original_order', $source->get_id());
		$child->save_meta_data();
		$source->save_meta_data();

		return $source_relation;
	}

	/**
	 * Remove a staged or committed relation during rollback.
	 *
	 * @param WC_Order $source       Source order.
	 * @param WC_Order $child        Child order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Lease ID.
	 * @return true|WP_Error
	 */
	public static function unlink(WC_Order $source, WC_Order $child, $operation_id, $lease_id) {
		$lease_check = self::assert_lease($source, $operation_id, $lease_id);

		if (is_wp_error($lease_check)) {
			return $lease_check;
		}

		$operation_id     = self::identifier($operation_id);
		$source_relations = self::source_relations($source);

		unset($source_relations[$operation_id]);

		if (empty($source_relations)) {
			$source->delete_meta_data(self::SOURCE_META);
		} else {
			$source->update_meta_data(self::SOURCE_META, $source_relations);
		}

		$child->delete_meta_data(self::CHILD_META);
		$child->delete_meta_data('yoos_original_order');
		self::remove_legacy_child($source, $child->get_id());
		$child->save_meta_data();
		$source->save_meta_data();

		return true;
	}

	/**
	 * Find a source relation by operation ID without changing state.
	 *
	 * @param WC_Order $source       Source order.
	 * @param string   $operation_id Operation ID.
	 * @return array|null|WP_Error
	 */
	public static function find(WC_Order $source, $operation_id) {
		$relations    = self::source_relations($source);
		$operation_id = self::identifier($operation_id);

		if (!isset($relations[$operation_id])) {
			return null;
		}

		return self::normalize_relation($relations[$operation_id]);
	}

	/**
	 * Read source relation collection.
	 *
	 * @param WC_Order $source Source order.
	 * @return array
	 */
	private static function source_relations(WC_Order $source) {
		$relations = $source->get_meta(self::SOURCE_META, true);

		return is_array($relations) ? $relations : array();
	}

	/**
	 * Read and normalize the child relation.
	 *
	 * @param WC_Order $child Child order.
	 * @return array|null
	 */
	private static function child_relation(WC_Order $child) {
		$relation = $child->get_meta(self::CHILD_META, true);

		if (!is_array($relation)) {
			return null;
		}

		$normalized = self::normalize_relation($relation);

		return is_wp_error($normalized) ? null : $normalized;
	}

	/**
	 * Validate a relation record.
	 *
	 * @param array $relation Relation.
	 * @return array|WP_Error
	 */
	private static function normalize_relation(array $relation) {
		$required = array('schema_version', 'operation_id', 'type', 'status', 'source_order_id', 'child_order_id', 'created_at', 'updated_at');

		foreach ($required as $field) {
			if (!array_key_exists($field, $relation)) {
				return self::error('wcos_corrupt_order_relation', __('A stored split-order relationship is incomplete.', 'wc-order-splitter'));
			}
		}

		$status = self::identifier($relation['status']);

		if (1 !== (int) $relation['schema_version'] || !in_array($status, array('staged', 'committed'), true)) {
			return self::error('wcos_corrupt_order_relation', __('A stored split-order relationship has an unsupported state.', 'wc-order-splitter'));
		}

		$normalized = array(
			'schema_version'  => 1,
			'operation_id'    => self::identifier($relation['operation_id']),
			'type'            => self::identifier($relation['type']),
			'status'          => $status,
			'source_order_id' => absint($relation['source_order_id']),
			'child_order_id'  => absint($relation['child_order_id']),
			'created_at'      => (int) $relation['created_at'],
			'updated_at'      => (int) $relation['updated_at'],
		);

		if ('' === $normalized['operation_id'] || '' === $normalized['type'] || !$normalized['source_order_id'] || !$normalized['child_order_id']) {
			return self::error('wcos_corrupt_order_relation', __('A stored split-order relationship has invalid identifiers.', 'wc-order-splitter'));
		}

		return $normalized;
	}

	/**
	 * Append a child ID to legacy compatibility metadata.
	 *
	 * @param WC_Order $source   Source order.
	 * @param int      $child_id Child order ID.
	 * @return void
	 */
	private static function add_legacy_child(WC_Order $source, $child_id) {
		$ids   = self::legacy_child_ids($source);
		$ids[] = absint($child_id);
		$ids   = array_values(array_unique(array_filter($ids)));
		sort($ids, SORT_NUMERIC);
		$source->update_meta_data('yoos_splitted_order', implode(',', $ids));
	}

	/**
	 * Remove a child ID from legacy compatibility metadata.
	 *
	 * @param WC_Order $source   Source order.
	 * @param int      $child_id Child order ID.
	 * @return void
	 */
	private static function remove_legacy_child(WC_Order $source, $child_id) {
		$child_id = absint($child_id);
		$ids      = array_values(array_filter(self::legacy_child_ids($source), static function ($id) use ($child_id) {
			return (int) $id !== $child_id;
		}));

		if (empty($ids)) {
			$source->delete_meta_data('yoos_splitted_order');
		} else {
			$source->update_meta_data('yoos_splitted_order', implode(',', $ids));
		}
	}

	/**
	 * Parse legacy child IDs.
	 *
	 * @param WC_Order $source Source order.
	 * @return int[]
	 */
	private static function legacy_child_ids(WC_Order $source) {
		$value = (string) $source->get_meta('yoos_splitted_order', true);
		$ids   = array_map('absint', array_filter(array_map('trim', explode(',', $value))));

		return array_values(array_unique(array_filter($ids)));
	}

	/**
	 * Verify the exact live source-order lease.
	 *
	 * @param WC_Order $source       Source order.
	 * @param string   $operation_id Operation ID.
	 * @param string   $lease_id     Lease ID.
	 * @return true|WP_Error
	 */
	private static function assert_lease(WC_Order $source, $operation_id, $lease_id) {
		$operation_id = self::identifier($operation_id);
		$lease_id     = self::identifier($lease_id);
		$lease        = WCOS_V2_Lease_Lock::inspect($source->get_id());

		if (null === $lease || (int) $lease['expires_at'] < time()) {
			return self::error('wcos_relation_lease_missing', __('A live source-order lease is required for relation changes.', 'wc-order-splitter'));
		}

		if (!hash_equals((string) $lease['operation_id'], $operation_id) || !hash_equals((string) $lease['lease_id'], $lease_id)) {
			return self::error('wcos_relation_lease_mismatch', __('The split-order relation lease does not belong to this request.', 'wc-order-splitter'));
		}

		return true;
	}

	/**
	 * Normalize an identifier.
	 *
	 * @param mixed $value Identifier.
	 * @return string
	 */
	private static function identifier($value) {
		$value = strtolower(trim((string) $value));

		return preg_replace('/[^a-z0-9._:-]/', '', $value);
	}

	/**
	 * Create a stable relation error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error($code, $message) {
		return new WP_Error(sanitize_key($code), wp_strip_all_tags((string) $message));
	}
}
