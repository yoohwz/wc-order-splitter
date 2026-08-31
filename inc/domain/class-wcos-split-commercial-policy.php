<?php

defined('ABSPATH') || exit;

/**
 * Canonical, PII-free commercial authority shared by every Split strategy.
 *
 * A fresh Review freezes this record. Before a journal exists the live settings
 * and source state must still match it; after journal start the frozen record is
 * the only replay authority.
 */
final class WCOS_Split_Commercial_Policy {
	const SCHEMA_VERSION = 1;
	const POLICY_VERSION = 7;
	const LEGACY_POLICY_VERSION = 6;
	const SHIPPING_KEEP_ON_SOURCE = 'keep_on_source';
	const SHIPPING_REPLICATE_TO_EACH_CHILD = 'replicate_to_each_child';

	public static function freeze(WC_Order $source) {
		$refunds = self::refund_authority($source);
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'source_order_id' => absint($source->get_id()),
			'source_status' => sanitize_key((string) $source->get_status()),
			'allowed_statuses' => self::configured_allowed_statuses(),
			'child_status' => sanitize_key((string) $source->get_status()),
			'shipping' => 'yes' === get_option('order_splitter_exclude_shipping_fee', 'no')
				? self::SHIPPING_KEEP_ON_SOURCE
				: self::SHIPPING_REPLICATE_TO_EACH_CHILD,
			'fees' => 'source_only',
			'negative_fees' => 'source_only',
			'coupons' => 'source_only',
			'refunds' => 'source_only_affected_lines_pinned',
			'refund_count' => $refunds['refund_count'],
			'refund_record_ids' => $refunds['refund_record_ids'],
			'refund_affected_item_ids' => $refunds['affected_item_ids'],
			'refund_evidence' => $refunds['evidence'],
			'nested_split' => 'allow_immediate_parent_lineage',
			'immediate_parent_order_id' => absint($source->get_id()),
			'payment' => 'source_only',
			'payment_transaction' => 'keep_on_source',
			'tax' => 'preserve_historical',
			'physical_stock' => 'no_write',
		);
		$record['policy_fingerprint'] = self::fingerprint($record);
		return $record;
	}

	public static function legacy() {
		$record = array(
			'schema_version' => 0,
			'policy_version' => self::LEGACY_POLICY_VERSION,
			'source_order_id' => 0,
			'source_status' => '',
			'allowed_statuses' => array('on-hold', 'pending', 'processing'),
			'child_status' => 'pending',
			'shipping' => self::SHIPPING_KEEP_ON_SOURCE,
			'fees' => 'keep_on_source',
			'negative_fees' => 'reject',
			'coupons' => 'reject',
			'refunds' => 'reject',
			'refund_count' => 0,
			'refund_record_ids' => array(),
			'refund_affected_item_ids' => array(),
			'refund_evidence' => array(),
			'nested_split' => 'reject',
			'immediate_parent_order_id' => 0,
			'payment' => 'source_only',
			'payment_transaction' => 'keep_on_source',
			'tax' => 'preserve_historical',
			'physical_stock' => 'no_write',
		);
		$record['policy_fingerprint'] = self::fingerprint($record);
		return $record;
	}

	public static function from_journal(array $record) {
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		if (isset($context['commercial_policy']) && is_array($context['commercial_policy'])) {
			$policy = self::assert_valid($context['commercial_policy']);
			if ((int) (isset($context['policy_version']) ? $context['policy_version'] : 0) !== (int) $policy['policy_version']) {
				throw new RuntimeException(__('The durable Split journal policy versions do not match.', 'wc-order-splitter'));
			}
			return $policy;
		}
		if (self::LEGACY_POLICY_VERSION === (int) (isset($context['policy_version']) ? $context['policy_version'] : 0)) {
			return self::legacy();
		}
		throw new RuntimeException(__('The durable Split journal is missing its required commercial policy authority.', 'wc-order-splitter'));
	}

	public static function assert_valid(array $record) {
		$version = isset($record['policy_version']) ? (int) $record['policy_version'] : 0;
		if (self::LEGACY_POLICY_VERSION === $version && 0 === (int) (isset($record['schema_version']) ? $record['schema_version'] : -1)) {
			$legacy = self::legacy();
			if ($record !== $legacy) {
				throw new RuntimeException(__('The legacy Split commercial policy record is malformed.', 'wc-order-splitter'));
			}
			return $legacy;
		}
		if (self::POLICY_VERSION !== $version
			|| self::SCHEMA_VERSION !== (int) (isset($record['schema_version']) ? $record['schema_version'] : 0)) {
			throw new RuntimeException(__('The Split commercial policy version is not supported.', 'wc-order-splitter'));
		}
		$stored = isset($record['policy_fingerprint']) ? sanitize_key((string) $record['policy_fingerprint']) : '';
		$actual = self::fingerprint($record);
		if ('' === $stored || !hash_equals($stored, $actual)) {
			throw new RuntimeException(__('The Split commercial policy failed its integrity fingerprint.', 'wc-order-splitter'));
		}
		if (empty($record['source_order_id']) || '' === sanitize_key((string) $record['source_status'])
			|| sanitize_key((string) $record['source_status']) !== sanitize_key((string) $record['child_status'])
			|| empty($record['allowed_statuses']) || !is_array($record['allowed_statuses'])
			|| !isset($record['refund_record_ids'], $record['refund_affected_item_ids'], $record['refund_evidence'])
			|| !is_array($record['refund_record_ids']) || !is_array($record['refund_affected_item_ids']) || !is_array($record['refund_evidence'])
			|| !in_array($record['shipping'], array(self::SHIPPING_KEEP_ON_SOURCE, self::SHIPPING_REPLICATE_TO_EACH_CHILD), true)) {
			throw new RuntimeException(__('The Split commercial policy is incomplete.', 'wc-order-splitter'));
		}
		$allowed_statuses = array_values(array_unique(array_map(array(__CLASS__, 'normalize_status'), $record['allowed_statuses'])));
		sort($allowed_statuses, SORT_STRING);
		$refund_ids = array_values(array_unique(array_map('absint', $record['refund_record_ids'])));
		$affected_ids = array_values(array_unique(array_map('absint', $record['refund_affected_item_ids'])));
		sort($refund_ids, SORT_NUMERIC);
		sort($affected_ids, SORT_NUMERIC);
		$evidence_ids = array();
		foreach ($record['refund_evidence'] as $refund_evidence) {
			if (!is_array($refund_evidence) || empty($refund_evidence['refund_id']) || !isset($refund_evidence['amount'], $refund_evidence['items']) || !is_array($refund_evidence['items'])) {
				throw new RuntimeException(__('The Split refund authority is malformed.', 'wc-order-splitter'));
			}
			$evidence_ids[] = absint($refund_evidence['refund_id']);
		}
		sort($evidence_ids, SORT_NUMERIC);
		if ($allowed_statuses !== array_values($record['allowed_statuses'])
			|| !in_array(sanitize_key((string) $record['source_status']), $allowed_statuses, true)
			|| $refund_ids !== array_values($record['refund_record_ids'])
			|| $affected_ids !== array_values($record['refund_affected_item_ids'])
			|| $evidence_ids !== $refund_ids
			|| count($refund_ids) !== (int) $record['refund_count']
			|| absint($record['immediate_parent_order_id']) !== absint($record['source_order_id'])
			|| 'source_only' !== $record['fees'] || 'source_only' !== $record['negative_fees']
			|| 'source_only' !== $record['coupons'] || 'source_only_affected_lines_pinned' !== $record['refunds']
			|| 'allow_immediate_parent_lineage' !== $record['nested_split']
			|| 'source_only' !== $record['payment'] || 'keep_on_source' !== $record['payment_transaction']
			|| 'preserve_historical' !== $record['tax'] || 'no_write' !== $record['physical_stock']) {
			throw new RuntimeException(__('The Split commercial policy is internally inconsistent.', 'wc-order-splitter'));
		}
		return $record;
	}

	public static function assert_current(WC_Order $source, array $frozen) {
		$frozen = self::assert_valid($frozen);
		if (self::POLICY_VERSION !== (int) $frozen['policy_version']) {
			throw new RuntimeException(__('A legacy durable Split policy cannot authorize a new operation.', 'wc-order-splitter'));
		}
		$current = self::freeze($source);
		if (!hash_equals((string) $frozen['policy_fingerprint'], (string) $current['policy_fingerprint'])) {
			throw new RuntimeException(__('The order status, Split settings, refund state, or lineage changed after Review. Review the order again.', 'wc-order-splitter'));
		}
		return $frozen;
	}

	public static function assert_source_supported(WC_Order $source, array $policy) {
		$policy = self::assert_valid($policy);
		if (self::LEGACY_POLICY_VERSION === (int) $policy['policy_version']) {
			if (!in_array($source->get_status(), array('pending', 'on-hold', 'processing'), true)) {
				throw new RuntimeException(__('This order status is not supported by the legacy Split policy.', 'wc-order-splitter'));
			}
			if (!empty($source->get_items('coupon')) || $source->get_total_refunded() != 0 || !empty($source->get_refunds())
				|| !empty($source->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true))
				|| !empty($source->get_meta('yoos_original_order', true))) {
				throw new RuntimeException(__('The source no longer satisfies its legacy Split commercial policy.', 'wc-order-splitter'));
			}
			return;
		}

		$status = sanitize_key((string) $source->get_status());
		if ($status !== sanitize_key((string) $policy['source_status'])
			|| !in_array($status, array_map('sanitize_key', (array) $policy['allowed_statuses']), true)) {
			throw new RuntimeException(__('This order status is not enabled by the frozen Split policy.', 'wc-order-splitter'));
		}
	}

	public static function assert_plan(array $plan, array $policy) {
		$policy = self::assert_valid($policy);
		$affected = array_fill_keys(array_map('absint', (array) $policy['refund_affected_item_ids']), true);
		foreach ($plan as $items) {
			foreach ((array) $items as $item_id => $quantity) {
				if (isset($affected[absint($item_id)]) && WCOS_Decimal::to_units($quantity, 6) > 0) {
					throw new RuntimeException(__('Refund-affected product lines must remain wholly on the Split source order.', 'wc-order-splitter'));
				}
			}
		}
		return $plan;
	}

	public static function annotate_strategy_buckets(array $buckets, array $policy) {
		$policy = self::assert_valid($policy);
		$affected = array_values(array_unique(array_map('absint', (array) $policy['refund_affected_item_ids'])));
		sort($affected, SORT_NUMERIC);
		foreach ($buckets as $bucket_key => $bucket) {
			$bucket_item_ids = array_values(array_unique(array_map('absint', array_keys(isset($bucket['items']) && is_array($bucket['items']) ? $bucket['items'] : array()))));
			sort($bucket_item_ids, SORT_NUMERIC);
			$missing = array_values(array_diff($affected, $bucket_item_ids));
			$buckets[$bucket_key]['source_eligible'] = empty($missing);
			$buckets[$bucket_key]['refund_affected_item_ids'] = array_values(array_intersect($affected, $bucket_item_ids));
			$buckets[$bucket_key]['source_restriction'] = empty($missing)
				? ''
				: __('This bucket cannot remain on the source because another bucket contains a refund-affected product line.', 'wc-order-splitter');
		}
		return $buckets;
	}

	public static function has_eligible_strategy_source_bucket(array $buckets) {
		foreach ($buckets as $bucket) {
			if (!empty($bucket['source_eligible'])) {
				return true;
			}
		}
		return false;
	}

	public static function fingerprint(array $record) {
		$copy = $record;
		unset($copy['policy_fingerprint']);
		$order_id = isset($copy['source_order_id']) ? absint($copy['source_order_id']) : 0;
		return WCOS_Mutation_Fingerprint::create('split_commercial_policy', $order_id, $copy);
	}

	private static function configured_allowed_statuses() {
		$registered = array_keys((array) wc_get_order_statuses());
		$registered = array_map(array(__CLASS__, 'normalize_status'), $registered);
		$registered = array_values(array_unique(array_filter($registered)));
		$allowed = array();
		foreach ((array) get_option('order_splitter_status_allowed', array('wc-processing')) as $status) {
			$status = self::normalize_status($status);
			if ('' !== $status && in_array($status, $registered, true)) {
				$allowed[] = $status;
			}
		}
		$allowed = array_values(array_unique($allowed));
		sort($allowed, SORT_STRING);
		return $allowed;
	}

	private static function normalize_status($status) {
		$status = sanitize_key((string) $status);
		return 0 === strpos($status, 'wc-') ? substr($status, 3) : $status;
	}

	private static function refund_authority(WC_Order $source) {
		$refund_ids = array();
		$affected = array();
		$evidence = array();
		$refunds = $source->get_refunds();
		foreach ($refunds as $refund) {
			if (!$refund instanceof WC_Order_Refund || !$refund->get_id()) {
				throw new RuntimeException(__('The order contains ambiguous refund provenance.', 'wc-order-splitter'));
			}
			$refund_ids[] = absint($refund->get_id());
			$refund_evidence = array(
				'refund_id' => absint($refund->get_id()),
				'amount' => WCOS_Decimal::normalize($refund->get_amount(), wc_get_price_decimals()),
				'items' => array(),
			);
			foreach (array('line_item', 'fee', 'shipping', 'tax') as $item_type) {
				foreach ($refund->get_items($item_type) as $refund_item) {
					$refund_evidence['items'][] = array(
						'item_id' => absint($refund_item->get_id()),
						'type' => sanitize_key((string) $item_type),
						'refunded_item_id' => absint($refund_item->get_meta('_refunded_item_id', true)),
						'quantity' => method_exists($refund_item, 'get_quantity') ? WCOS_Decimal::normalize($refund_item->get_quantity(), 6) : '0',
						'total' => method_exists($refund_item, 'get_total') ? WCOS_Decimal::normalize($refund_item->get_total(), wc_get_price_decimals()) : '0',
						'total_tax' => method_exists($refund_item, 'get_total_tax') ? WCOS_Decimal::normalize($refund_item->get_total_tax(), wc_get_price_decimals()) : '0',
						'taxes' => method_exists($refund_item, 'get_taxes') ? $refund_item->get_taxes() : array(),
					);
				}
			}
			usort($refund_evidence['items'], static function(array $left, array $right) {
				return $left['item_id'] <=> $right['item_id'];
			});
			if (0 !== WCOS_Decimal::to_units($refund_evidence['amount'], wc_get_price_decimals()) && empty($refund_evidence['items'])) {
				throw new RuntimeException(__('A refund amount cannot be attributed to a persisted source-owned order component.', 'wc-order-splitter'));
			}
			$evidence[] = $refund_evidence;
			foreach ($refund->get_items('line_item') as $refund_item) {
				$source_item_id = absint($refund_item->get_meta('_refunded_item_id', true));
				if (!$source_item_id || !$source->get_item($source_item_id) instanceof WC_Order_Item_Product) {
					throw new RuntimeException(__('A refund product line cannot be mapped unambiguously to its source line.', 'wc-order-splitter'));
				}
				$affected[] = $source_item_id;
			}
		}
		if ($source->get_total_refunded() != 0 && empty($refunds)) {
			throw new RuntimeException(__('The order refund total has no canonical refund record.', 'wc-order-splitter'));
		}
		$refund_ids = array_values(array_unique($refund_ids));
		$affected = array_values(array_unique($affected));
		sort($refund_ids, SORT_NUMERIC);
		sort($affected, SORT_NUMERIC);
		usort($evidence, static function(array $left, array $right) {
			return $left['refund_id'] <=> $right['refund_id'];
		});
		return array(
			'refund_count' => count($refund_ids),
			'refund_record_ids' => $refund_ids,
			'affected_item_ids' => $affected,
			'evidence' => $evidence,
		);
	}
}
