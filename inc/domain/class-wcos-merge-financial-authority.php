<?php

defined('ABSPATH') || exit;

final class WCOS_Merge_Financial_Authority_Exception extends RuntimeException {
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
 * Canonical PII-minimized payment/refund authority for Merge participants.
 *
 * Raw transaction IDs, refund reasons, and refund metadata are used only in
 * request memory to create site-keyed fingerprints. They never enter Review,
 * Confirmation, journal, or recovery records.
 */
final class WCOS_Merge_Financial_Authority {

	const SCHEMA_VERSION = 1;
	const POLICY_VERSION = 1;
	const ALGORITHM = 'hmac-sha256';

	public static function has_history(WC_Order $order, $precision = null) {
		$precision = null === $precision
			? WCOS_Price_Precision_Scope::store_precision()
			: WCOS_Price_Precision_Scope::validate($precision);
		try {
			$total_refunded = WCOS_Decimal::to_units($order->get_total_refunded(), $precision);
		} catch (Throwable $throwable) {
			return true;
		}
		return (bool) $order->is_paid()
			|| null !== $order->get_date_paid()
			|| '' !== trim((string) $order->get_transaction_id())
			|| 0 !== $total_refunded
			|| !empty($order->get_refunds());
	}

	public static function freeze_pair(WC_Order $source, WC_Order $target, $precision) {
		$precision = WCOS_Price_Precision_Scope::validate($precision);
		if (self::has_history($source, $precision)) {
			throw new WCOS_Merge_Financial_Authority_Exception(
				'source_financial_history_not_movable',
				__('The Merge source owns payment or refund history and cannot be retired by this workflow.', 'wc-order-splitter')
			);
		}

		$source_authority = self::participant($source, $precision);
		$target_authority = self::participant($target, $precision);
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'algorithm' => self::ALGORITHM,
			'price_precision' => $precision,
			'source' => $source_authority,
			'target' => $target_authority,
			'source_financial_disposition' => 'reject_if_present',
			'target_financial_disposition' => !empty($target_authority['has_financial_history'])
				? 'preserve_exact_settlement_neutral_only'
				: 'ordinary_commercial_policy',
			'target_has_financial_history' => (bool) $target_authority['has_financial_history'],
			'payment_refund_api_disposition' => 'never',
		);
		$record['pair_financial_policy_fingerprint'] = self::pair_fingerprint($record);
		return self::canonicalize_pair($record);
	}

	public static function canonicalize_pair(array $record) {
		$expected = array(
			'algorithm', 'pair_financial_policy_fingerprint', 'payment_refund_api_disposition', 'policy_version',
			'price_precision', 'schema_version', 'source', 'source_financial_disposition', 'target',
			'target_financial_disposition', 'target_has_financial_history',
		);
		$actual = array_keys($record);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		if ($actual !== $expected
			|| self::SCHEMA_VERSION !== (int) $record['schema_version']
			|| self::POLICY_VERSION !== (int) $record['policy_version']
			|| self::ALGORITHM !== (string) $record['algorithm']
			|| 'reject_if_present' !== sanitize_key((string) $record['source_financial_disposition'])
			|| 'never' !== sanitize_key((string) $record['payment_refund_api_disposition'])
			|| !in_array($record['target_has_financial_history'], array(true, false), true)) {
			throw new InvalidArgumentException(__('The Merge financial pair authority is malformed.', 'wc-order-splitter'));
		}

		$source = self::canonicalize_participant($record['source']);
		$target = self::canonicalize_participant($record['target']);
		$precision = WCOS_Price_Precision_Scope::validate($record['price_precision']);
		$target_has_history = (bool) $record['target_has_financial_history'];
		$target_disposition = sanitize_key((string) $record['target_financial_disposition']);
		if (!empty($source['has_financial_history'])
			|| $target_has_history !== (bool) $target['has_financial_history']
			|| ($target_has_history && 'preserve_exact_settlement_neutral_only' !== $target_disposition)
			|| (!$target_has_history && 'ordinary_commercial_policy' !== $target_disposition)
			|| (int) $record['price_precision'] !== $precision
			|| WCOS_Decimal::normalize($source['total_refunded'], $precision) !== (string) $source['total_refunded']
			|| WCOS_Decimal::normalize($target['total_refunded'], $precision) !== (string) $target['total_refunded']) {
			throw new InvalidArgumentException(__('The Merge financial pair policy is internally inconsistent.', 'wc-order-splitter'));
		}

		$canonical = array(
			'schema_version' => self::SCHEMA_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'algorithm' => self::ALGORITHM,
			'price_precision' => $precision,
			'source' => $source,
			'target' => $target,
			'source_financial_disposition' => 'reject_if_present',
			'target_financial_disposition' => $target_disposition,
			'target_has_financial_history' => $target_has_history,
			'payment_refund_api_disposition' => 'never',
		);
		$stored = self::fingerprint(isset($record['pair_financial_policy_fingerprint']) ? $record['pair_financial_policy_fingerprint'] : '');
		$computed = self::pair_fingerprint($canonical);
		if ('' === $stored || !hash_equals($stored, $computed)) {
			throw new InvalidArgumentException(__('The Merge financial pair authority failed its keyed fingerprint.', 'wc-order-splitter'));
		}
		$canonical['pair_financial_policy_fingerprint'] = $computed;
		return $canonical;
	}

	public static function target_has_history(array $record) {
		$record = self::canonicalize_pair($record);
		return (bool) $record['target_has_financial_history'];
	}

	public static function assert_current(WC_Order $source, WC_Order $target, array $frozen, $allow_source_retired = false) {
		$frozen = self::canonicalize_pair($frozen);
		$current = self::freeze_pair($source, $target, $frozen['price_precision']);
		if (!$allow_source_retired) {
			if ($current !== $frozen) {
				throw new RuntimeException(__('The Merge payment or refund authority changed after Review.', 'wc-order-splitter'));
			}
			return true;
		}

		$source_matches = $current['source'] === $frozen['source']
			|| ('trash' === sanitize_key((string) $current['source']['status'])
				&& self::participant_without_status($current['source']) === self::participant_without_status($frozen['source']));
		if (!$source_matches
			|| $current['target'] !== $frozen['target']
			|| (bool) $current['target_has_financial_history'] !== (bool) $frozen['target_has_financial_history']
			|| (string) $current['target_financial_disposition'] !== (string) $frozen['target_financial_disposition']) {
			throw new RuntimeException(__('The Merge payment or refund authority changed outside approved source retirement.', 'wc-order-splitter'));
		}
		return true;
	}

	private static function participant(WC_Order $order, $precision) {
		$order_id = absint($order->get_id());
		if (!$order_id || 'shop_order' !== $order->get_type()) {
			throw new WCOS_Merge_Financial_Authority_Exception(
				'malformed_refund_authority',
				__('Merge financial authority requires a persisted shop order.', 'wc-order-splitter')
			);
		}

		$refunds = $order->get_refunds();
		if (!is_array($refunds)) {
			self::malformed_refund();
		}
		$refund_structures = array();
		$refund_ids = array();
		$refund_total_units = 0;
		foreach ($refunds as $refund) {
			if (!$refund instanceof WC_Order_Refund || !absint($refund->get_id())
				|| absint($refund->get_parent_id()) !== $order_id
				|| 'shop_order_refund' !== (string) $refund->get_type()) {
				self::malformed_refund();
			}
			$refund_id = absint($refund->get_id());
			if (isset($refund_ids[$refund_id])) {
				self::malformed_refund();
			}
			$refund_ids[$refund_id] = true;
			$amount_units = WCOS_Decimal::to_units($refund->get_amount(), $precision);
			if ($amount_units < 0 || $refund_total_units > PHP_INT_MAX - $amount_units) {
				self::malformed_refund();
			}
			$refund_total_units += $amount_units;
			$refund_structures[$refund_id] = self::refund_structure($order, $refund, $precision);
		}
		ksort($refund_structures, SORT_NUMERIC);
		$refund_id_list = array_map('intval', array_keys($refund_structures));
		$total_refunded = WCOS_Decimal::normalize($order->get_total_refunded(), $precision);
		if (WCOS_Decimal::to_units($total_refunded, $precision) !== $refund_total_units) {
			self::malformed_refund();
		}

		$date_paid = $order->get_date_paid();
		$transaction_id = trim((string) $order->get_transaction_id());
		$participant = array(
			'order_id' => $order_id,
			'status' => sanitize_key((string) $order->get_status()),
			'is_paid' => (bool) $order->is_paid(),
			'has_paid_date' => null !== $date_paid,
			'paid_date_fingerprint' => null === $date_paid ? '' : self::digest(
				'merge_paid_date_v1',
				array('timestamp_utc' => (int) $date_paid->getTimestamp())
			),
			'has_transaction_id' => '' !== $transaction_id,
			'transaction_id_fingerprint' => '' === $transaction_id ? '' : self::digest(
				'merge_transaction_id_v1',
				array('transaction_id' => $transaction_id)
			),
			'refund_count' => count($refund_id_list),
			'refund_order_ids' => $refund_id_list,
			'total_refunded' => $total_refunded,
			'refund_structure_fingerprint' => self::digest(
				'merge_refund_structure_v1',
				array('order_id' => $order_id, 'refunds' => $refund_structures)
			),
		);
		$participant['has_financial_history'] = $participant['is_paid']
			|| $participant['has_paid_date']
			|| $participant['has_transaction_id']
			|| 0 !== WCOS_Decimal::to_units($participant['total_refunded'], $precision)
			|| 0 !== $participant['refund_count'];
		$participant['participant_financial_fingerprint'] = self::participant_fingerprint($participant);
		return self::canonicalize_participant($participant);
	}

	private static function refund_structure(WC_Order $order, WC_Order_Refund $refund, $precision) {
		$items = array();
		$seen_item_ids = array();
		foreach (array('line_item', 'shipping', 'fee', 'tax') as $item_type) {
			foreach ($refund->get_items($item_type) as $refund_item_id => $refund_item) {
				$refund_item_id = absint($refund_item_id);
				$refunded_item_id = absint($refund_item->get_meta('_refunded_item_id', true));
				$referenced = $refunded_item_id ? $order->get_item($refunded_item_id) : false;
				if (!$refund_item instanceof WC_Order_Item || !$refund_item_id || isset($seen_item_ids[$refund_item_id])
					|| (int) $refund_item->get_order_id() !== (int) $refund->get_id()
					|| (string) $refund_item->get_type() !== $item_type
					|| !$referenced instanceof WC_Order_Item
					|| (int) $referenced->get_order_id() !== (int) $order->get_id()
					|| (string) $referenced->get_type() !== $item_type) {
					self::malformed_refund();
				}
				$seen_item_ids[$refund_item_id] = true;
				$items[$refund_item_id] = array(
					'refund_item_id' => $refund_item_id,
					'type' => $item_type,
					'refunded_item_id' => $refunded_item_id,
					'quantity' => method_exists($refund_item, 'get_quantity')
						? WCOS_Decimal::normalize($refund_item->get_quantity(), 6)
						: '0.000000',
					'total' => method_exists($refund_item, 'get_total')
						? WCOS_Decimal::normalize($refund_item->get_total(), $precision)
						: WCOS_Decimal::from_units(0, $precision),
					'total_tax' => method_exists($refund_item, 'get_total_tax')
						? WCOS_Decimal::normalize($refund_item->get_total_tax(), $precision)
						: WCOS_Decimal::from_units(0, $precision),
					'taxes' => method_exists($refund_item, 'get_taxes') ? self::canonicalize($refund_item->get_taxes()) : array(),
					'metadata_fingerprint' => self::metadata_fingerprint($refund_item->get_meta_data(), 'merge_refund_item_meta_v1'),
				);
			}
		}
		ksort($items, SORT_NUMERIC);
		$date_created = $refund->get_date_created();
		return array(
			'refund_id' => absint($refund->get_id()),
			'parent_order_id' => absint($refund->get_parent_id()),
			'status' => sanitize_key((string) $refund->get_status()),
			'amount' => WCOS_Decimal::normalize($refund->get_amount(), $precision),
			'reason_fingerprint' => self::digest('merge_refund_reason_v1', array('reason' => (string) $refund->get_reason())),
			'refunded_by' => absint($refund->get_refunded_by()),
			'date_created_fingerprint' => null === $date_created ? '' : self::digest(
				'merge_refund_date_v1',
				array('timestamp_utc' => (int) $date_created->getTimestamp())
			),
			'metadata_fingerprint' => self::metadata_fingerprint($refund->get_meta_data(), 'merge_refund_order_meta_v1'),
			'items' => $items,
		);
	}

	private static function canonicalize_participant($participant) {
		if (!is_array($participant)) {
			throw new InvalidArgumentException(__('The Merge participant financial authority is missing.', 'wc-order-splitter'));
		}
		$expected = array(
			'has_financial_history', 'has_paid_date', 'has_transaction_id', 'is_paid', 'order_id',
			'paid_date_fingerprint', 'participant_financial_fingerprint', 'refund_count', 'refund_order_ids',
			'refund_structure_fingerprint', 'status', 'total_refunded', 'transaction_id_fingerprint',
		);
		$actual = array_keys($participant);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		$ids = isset($participant['refund_order_ids']) && is_array($participant['refund_order_ids'])
			? array_values(array_unique(array_filter(array_map('absint', $participant['refund_order_ids']))))
			: array();
		sort($ids, SORT_NUMERIC);
		if ($actual !== $expected || !absint($participant['order_id']) || '' === sanitize_key((string) $participant['status'])
			|| !in_array($participant['is_paid'], array(true, false), true)
			|| !in_array($participant['has_paid_date'], array(true, false), true)
			|| !in_array($participant['has_transaction_id'], array(true, false), true)
			|| !in_array($participant['has_financial_history'], array(true, false), true)
			|| $ids !== array_values($participant['refund_order_ids'])
			|| count($ids) !== (int) $participant['refund_count']
			|| !self::is_optional_fingerprint($participant['paid_date_fingerprint'], (bool) $participant['has_paid_date'])
			|| !self::is_optional_fingerprint($participant['transaction_id_fingerprint'], (bool) $participant['has_transaction_id'])
			|| '' === self::fingerprint($participant['refund_structure_fingerprint'])) {
			throw new InvalidArgumentException(__('The Merge participant financial authority is malformed.', 'wc-order-splitter'));
		}

		$canonical = array(
			'order_id' => absint($participant['order_id']),
			'status' => sanitize_key((string) $participant['status']),
			'is_paid' => (bool) $participant['is_paid'],
			'has_paid_date' => (bool) $participant['has_paid_date'],
			'paid_date_fingerprint' => self::fingerprint($participant['paid_date_fingerprint']),
			'has_transaction_id' => (bool) $participant['has_transaction_id'],
			'transaction_id_fingerprint' => self::fingerprint($participant['transaction_id_fingerprint']),
			'refund_count' => count($ids),
			'refund_order_ids' => $ids,
			'total_refunded' => (string) $participant['total_refunded'],
			'refund_structure_fingerprint' => self::fingerprint($participant['refund_structure_fingerprint']),
			'has_financial_history' => (bool) $participant['has_financial_history'],
		);
		$stored = self::fingerprint($participant['participant_financial_fingerprint']);
		$computed = self::participant_fingerprint($canonical);
		if ('' === $stored || !hash_equals($stored, $computed)) {
			throw new InvalidArgumentException(__('The Merge participant financial fingerprint is invalid.', 'wc-order-splitter'));
		}
		$canonical['participant_financial_fingerprint'] = $computed;
		return $canonical;
	}

	private static function participant_without_status(array $participant) {
		unset($participant['status'], $participant['participant_financial_fingerprint']);
		return $participant;
	}

	private static function participant_fingerprint(array $participant) {
		unset($participant['participant_financial_fingerprint']);
		return self::digest('merge_participant_financial_v1', $participant);
	}

	private static function pair_fingerprint(array $record) {
		unset($record['pair_financial_policy_fingerprint']);
		return self::digest('merge_pair_financial_policy_v1', $record);
	}

	private static function metadata_fingerprint(array $metadata, $purpose) {
		$records = array();
		foreach ($metadata as $datum) {
			$data = is_object($datum) && method_exists($datum, 'get_data') ? $datum->get_data() : array();
			if (!isset($data['key']) || !array_key_exists('value', $data)) {
				self::malformed_refund();
			}
			$key = (string) $data['key'];
			$records[] = array(
				'key' => $key,
				'value_fingerprint' => self::digest(
					'merge_refund_meta_value_v1',
					array('key' => $key, 'serialized_value' => maybe_serialize($data['value']))
				),
			);
		}
		usort($records, static function(array $left, array $right) {
			$by_key = strcmp($left['key'], $right['key']);
			return 0 !== $by_key ? $by_key : strcmp($left['value_fingerprint'], $right['value_fingerprint']);
		});
		return self::digest($purpose, array('metadata' => $records));
	}

	private static function digest($purpose, array $payload) {
		$secret = (string) wp_salt('auth');
		$document = wp_json_encode(
			array(
				'schema_version' => self::SCHEMA_VERSION,
				'purpose' => sanitize_key((string) $purpose),
				'payload' => self::canonicalize($payload),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ('' === $secret || !is_string($document) || '' === $document) {
			throw new RuntimeException(__('Merge financial authority could not be keyed.', 'wc-order-splitter'));
		}
		return hash_hmac('sha256', $document, $secret);
	}

	private static function canonicalize($value) {
		if (!is_array($value)) {
			return $value;
		}
		if (!self::is_list($value)) {
			ksort($value, SORT_STRING);
		}
		foreach ($value as $key => $item) {
			$value[$key] = self::canonicalize($item);
		}
		return $value;
	}

	private static function is_list(array $value) {
		$expected = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expected++) {
				return false;
			}
		}
		return true;
	}

	private static function is_optional_fingerprint($value, $required) {
		$value = (string) $value;
		return $required ? '' !== self::fingerprint($value) : '' === $value;
	}

	private static function fingerprint($value) {
		$value = sanitize_key((string) $value);
		return 64 === strlen($value) && ctype_xdigit($value) ? strtolower($value) : '';
	}

	private static function malformed_refund() {
		throw new WCOS_Merge_Financial_Authority_Exception(
			'malformed_refund_authority',
			__('The target refund structure is malformed or cannot be authenticated unambiguously.', 'wc-order-splitter')
		);
	}
}
