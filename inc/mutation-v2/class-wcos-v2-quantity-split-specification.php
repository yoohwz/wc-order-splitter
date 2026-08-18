<?php
/**
 * Conservation-first quantity split write specification.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || (PHP_SAPI === 'cli') || exit;

/**
 * Converts a strict read-only preflight result into explicit source and child
 * mutations. This class performs no WooCommerce writes.
 */
final class WCOS_V2_Quantity_Split_Specification {

	/**
	 * Build a one-child quantity split specification.
	 *
	 * @param array $preflight Result from WCOS_V2_Strict_Preflight::validate().
	 * @return array
	 */
	public static function build(array $preflight) {
		if (!isset($preflight['fingerprint_scope']) || 'complete_commercial_order_state' !== $preflight['fingerprint_scope']) {
			throw new InvalidArgumentException('A strict complete-state preflight is required.');
		}

		if (empty($preflight['fingerprint']) || empty($preflight['snapshot']) || empty($preflight['plan'])) {
			throw new InvalidArgumentException('The strict preflight result is incomplete.');
		}

		$snapshot  = $preflight['snapshot'];
		$plan      = $preflight['plan'];
		$precision = isset($plan['precision']) ? (int) $plan['precision'] : 2;

		if (!empty($snapshot['coupon_items'])) {
			throw new InvalidArgumentException('Coupon-bearing orders are not supported by the first safe quantity split adapter.');
		}

		if (!empty($snapshot['has_refunds'])) {
			throw new InvalidArgumentException('Refunded orders are not supported by the quantity split adapter.');
		}

		$source_lines = array();
		$child_lines  = array();
		$child_sums   = array(
			'subtotal'     => 0,
			'total'        => 0,
			'subtotal_tax' => 0,
			'total_tax'    => 0,
		);
		$child_tax_rates = array(
			'subtotal' => array(),
			'total'    => array(),
		);

		foreach ($snapshot['lines'] as $item_id => $line) {
			if (!isset($plan['lines'][$item_id])) {
				throw new LogicException(sprintf('The split plan is missing source item %d.', $item_id));
			}

			$planned = $plan['lines'][$item_id];

			if (!hash_equals((string) $line['identity'], (string) WCOS_V2_Line_Identity::from_snapshot($line)['signature'])) {
				throw new LogicException(sprintf('The commercial identity changed for source item %d.', $item_id));
			}

			$source_lines[(int) $item_id] = self::line_mutation($line, $planned['original'], false);

			if ((float) $planned['child']['quantity'] <= 0) {
				continue;
			}

			$child_line = self::line_mutation($line, $planned['child'], true);
			$child_lines[(int) $item_id] = $child_line;

			foreach (array_keys($child_sums) as $field) {
				$child_sums[$field] += WCOS_V2_Amount_Allocator::to_minor_units($child_line[$field], $precision);
			}

			foreach (array('subtotal', 'total') as $tax_context) {
				foreach ($child_line['taxes'][$tax_context] as $rate_id => $tax_amount) {
					$minor = WCOS_V2_Amount_Allocator::to_minor_units($tax_amount, $precision);

					if ($minor < 0) {
						throw new InvalidArgumentException('Negative product-line tax is not supported by the first safe quantity split adapter.');
					}

					if (!isset($child_tax_rates[$tax_context][$rate_id])) {
						$child_tax_rates[$tax_context][$rate_id] = 0;
					}

					$child_tax_rates[$tax_context][$rate_id] += $minor;
				}
			}
		}

		if (empty($child_lines)) {
			throw new InvalidArgumentException('The split specification does not contain a child product line.');
		}

		$child_amounts = self::child_amounts($child_sums, $precision);
		$source_amounts = self::source_amounts($snapshot['amounts'], $child_amounts, $precision);
		$tax_items = self::tax_item_mutations($snapshot['tax_items'], $child_tax_rates, $precision);

		$source_has_marker = false;

		foreach ($snapshot['lines'] as $line) {
			if (null !== $line['reduced_stock'] && (float) $line['reduced_stock'] > 0) {
				$source_has_marker = true;
				break;
			}
		}

		if (false === $snapshot['order_stock_reduced'] && $source_has_marker) {
			throw new LogicException('Item stock markers exist while the source order stock flag is false.');
		}

		$specification = array(
			'schema_version'       => 1,
			'operation_type'       => 'quantity_split_one_child',
			'source_order_id'      => (int) $snapshot['order_id'],
			'source_fingerprint'   => (string) $preflight['fingerprint'],
			'currency'             => (string) $snapshot['currency'],
			'precision'            => $precision,
			'prices_include_tax'   => (bool) $snapshot['prices_include_tax'],
			'customer_id'          => (int) $snapshot['customer_id'],
			'initial_child_status' => 'pending',
			'desired_child_status' => (string) $snapshot['status'],
			'settlement'           => array(
				'owner'                => 'source_order',
				'copy_payment_method'  => true,
				'copy_transaction_id'  => false,
			),
			'charge_policy'        => array(
				'shipping' => 'keep_on_source',
				'fees'     => 'keep_on_source',
				'coupons'  => 'reject_when_present',
				'refunds'  => 'reject_when_present',
			),
			'stock'                => array(
				'source_order_reduced' => (bool) $snapshot['order_stock_reduced'],
				'child_order_reduced'  => (bool) $snapshot['order_stock_reduced'],
				'physical_delta'       => '0',
			),
			'source'               => array(
				'lines'     => $source_lines,
				'amounts'   => $source_amounts,
				'tax_items' => $tax_items['source'],
			),
			'child'                => array(
				'lines'     => $child_lines,
				'amounts'   => $child_amounts,
				'tax_items' => $tax_items['child'],
			),
		);

		self::assert_conservation($snapshot, $specification);
		$specification['fingerprint'] = hash('sha256', self::canonical_json($specification));

		return $specification;
	}

	/**
	 * Build one explicit line mutation.
	 *
	 * @param array $source_line Source line snapshot.
	 * @param array $planned     Planned original or child allocation.
	 * @param bool  $is_child    Whether this is a newly constructed child item.
	 * @return array
	 */
	private static function line_mutation(array $source_line, array $planned, $is_child) {
		return array(
			'action'         => (float) $planned['quantity'] > 0 ? ($is_child ? 'create' : 'update') : 'remove',
			'source_item_id' => (int) $source_line['item_id'],
			'identity'       => (string) $source_line['identity'],
			'name'           => (string) $source_line['name'],
			'product_id'     => (int) $source_line['product_id'],
			'variation_id'   => (int) $source_line['variation_id'],
			'tax_class'      => (string) $source_line['tax_class'],
			'quantity'       => (string) $planned['quantity'],
			'subtotal'       => (string) $planned['subtotal'],
			'total'          => (string) $planned['total'],
			'subtotal_tax'   => (string) $planned['subtotal_tax'],
			'total_tax'      => (string) $planned['total_tax'],
			'taxes'          => $planned['taxes'],
			'reduced_stock'  => $planned['reduced_stock'],
			'metadata'       => $is_child
				? WCOS_V2_Metadata_Policy::normalize_records((array) $source_line['metadata'], false)
				: array(),
		);
	}

	/**
	 * Compute child order aggregate amounts in minor units.
	 *
	 * @param array $sums      Child line sums.
	 * @param int   $precision Currency precision.
	 * @return array
	 */
	private static function child_amounts(array $sums, $precision) {
		$discount_total = $sums['subtotal'] - $sums['total'];
		$discount_tax   = $sums['subtotal_tax'] - $sums['total_tax'];
		$grand_total    = $sums['total'] + $sums['total_tax'];

		return array(
			'subtotal'       => self::from_minor($sums['subtotal'], $precision),
			'discount_total' => self::from_minor($discount_total, $precision),
			'discount_tax'   => self::from_minor($discount_tax, $precision),
			'shipping_total' => self::from_minor(0, $precision),
			'shipping_tax'   => self::from_minor(0, $precision),
			'cart_tax'       => self::from_minor($sums['total_tax'], $precision),
			'total_tax'      => self::from_minor($sums['total_tax'], $precision),
			'total'          => self::from_minor($grand_total, $precision),
		);
	}

	/**
	 * Subtract child aggregates from the source order exactly.
	 *
	 * @param array $source    Source order amounts.
	 * @param array $child     Child order amounts.
	 * @param int   $precision Currency precision.
	 * @return array
	 */
	private static function source_amounts(array $source, array $child, $precision) {
		$result = array();

		foreach (array('subtotal', 'discount_total', 'discount_tax', 'cart_tax', 'total_tax', 'total') as $field) {
			$result[$field] = self::from_minor(
				WCOS_V2_Amount_Allocator::to_minor_units($source[$field], $precision)
				- WCOS_V2_Amount_Allocator::to_minor_units($child[$field], $precision),
				$precision
			);
		}

		$result['shipping_total'] = self::normalize_amount($source['shipping_total'], $precision);
		$result['shipping_tax']   = self::normalize_amount($source['shipping_tax'], $precision);

		return $result;
	}

	/**
	 * Build explicit source tax-item updates and new child tax items.
	 *
	 * @param array $source_tax_items Source tax item snapshots.
	 * @param array $child_tax_rates  Child per-rate tax totals.
	 * @param int   $precision        Currency precision.
	 * @return array
	 */
	private static function tax_item_mutations(array $source_tax_items, array $child_tax_rates, $precision) {
		$by_rate = array();

		foreach ($source_tax_items as $item_id => $tax_item) {
			$data    = isset($tax_item['data']) && is_array($tax_item['data']) ? $tax_item['data'] : array();
			$rate_id = isset($data['rate_id']) ? (string) $data['rate_id'] : '';

			if ('' === $rate_id) {
				throw new LogicException(sprintf('Tax item %d does not expose a rate ID.', $item_id));
			}

			if (isset($by_rate[$rate_id])) {
				throw new LogicException(sprintf('Multiple source tax items use rate ID %s.', $rate_id));
			}

			$by_rate[$rate_id] = array(
				'item_id'  => (int) $item_id,
				'data'     => $data,
				'metadata' => isset($tax_item['metadata']) ? (array) $tax_item['metadata'] : array(),
			);
		}

		$source_mutations = array();
		$child_mutations  = array();

		foreach ($by_rate as $rate_id => $tax_item) {
			$data              = $tax_item['data'];
			$source_tax_minor  = WCOS_V2_Amount_Allocator::to_minor_units(isset($data['tax_total']) ? $data['tax_total'] : '0', $precision);
			$source_ship_minor = WCOS_V2_Amount_Allocator::to_minor_units(isset($data['shipping_tax_total']) ? $data['shipping_tax_total'] : '0', $precision);
			$child_tax_minor   = isset($child_tax_rates['total'][$rate_id]) ? (int) $child_tax_rates['total'][$rate_id] : 0;

			if ($child_tax_minor > $source_tax_minor) {
				throw new LogicException(sprintf('Child tax exceeds source tax for rate ID %s.', $rate_id));
			}

			$source_mutations[$tax_item['item_id']] = array(
				'action'             => 'update',
				'rate_id'            => $rate_id,
				'label'              => isset($data['label']) ? (string) $data['label'] : '',
				'compound'           => !empty($data['compound']),
				'rate_code'          => isset($data['rate_code']) ? (string) $data['rate_code'] : '',
				'rate_percent'       => isset($data['rate_percent']) ? (string) $data['rate_percent'] : '',
				'tax_total'          => self::from_minor($source_tax_minor - $child_tax_minor, $precision),
				'shipping_tax_total' => self::from_minor($source_ship_minor, $precision),
			);

			if (0 !== $child_tax_minor) {
				$child_mutations[$rate_id] = array(
					'action'             => 'create',
					'rate_id'            => $rate_id,
					'label'              => isset($data['label']) ? (string) $data['label'] : '',
					'compound'           => !empty($data['compound']),
					'rate_code'          => isset($data['rate_code']) ? (string) $data['rate_code'] : '',
					'rate_percent'       => isset($data['rate_percent']) ? (string) $data['rate_percent'] : '',
					'tax_total'          => self::from_minor($child_tax_minor, $precision),
					'shipping_tax_total' => self::from_minor(0, $precision),
				);
			}
		}

		foreach ($child_tax_rates['total'] as $rate_id => $minor) {
			if (0 !== (int) $minor && !isset($by_rate[(string) $rate_id])) {
				throw new LogicException(sprintf('The source order has no tax item for child rate ID %s.', $rate_id));
			}
		}

		ksort($source_mutations, SORT_NUMERIC);
		ksort($child_mutations, SORT_NATURAL);

		return array(
			'source' => $source_mutations,
			'child'  => $child_mutations,
		);
	}

	/**
	 * Verify all specification-level conservation invariants.
	 *
	 * @param array $snapshot      Source snapshot.
	 * @param array $specification Write specification.
	 * @return void
	 */
	public static function assert_conservation(array $snapshot, array $specification) {
		$precision = (int) $specification['precision'];

		foreach ($snapshot['lines'] as $item_id => $source_line) {
			$source_mutation = $specification['source']['lines'][$item_id];
			$child_mutation  = isset($specification['child']['lines'][$item_id]) ? $specification['child']['lines'][$item_id] : null;
			$after_quantity  = (float) $source_mutation['quantity'] + (null === $child_mutation ? 0.0 : (float) $child_mutation['quantity']);

			if (abs((float) $source_line['quantity'] - $after_quantity) > 0.0000001) {
				throw new LogicException(sprintf('Quantity conservation failed for item %d.', $item_id));
			}

			foreach (array('subtotal', 'total', 'subtotal_tax', 'total_tax') as $field) {
				$before = WCOS_V2_Amount_Allocator::to_minor_units($source_line[$field], $precision);
				$after  = WCOS_V2_Amount_Allocator::to_minor_units($source_mutation[$field], $precision);

				if (null !== $child_mutation) {
					$after += WCOS_V2_Amount_Allocator::to_minor_units($child_mutation[$field], $precision);
				}

				if ($before !== $after) {
					throw new LogicException(sprintf('%s conservation failed for item %d.', $field, $item_id));
				}
			}

			if (null !== $source_line['reduced_stock']) {
				$after_stock = (float) $source_mutation['reduced_stock'] + (null === $child_mutation || null === $child_mutation['reduced_stock'] ? 0.0 : (float) $child_mutation['reduced_stock']);

				if (abs((float) $source_line['reduced_stock'] - $after_stock) > 0.0000001) {
					throw new LogicException(sprintf('Reduced-stock conservation failed for item %d.', $item_id));
				}
			}
		}

		foreach (array('subtotal', 'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'cart_tax', 'total_tax', 'total') as $field) {
			$before = WCOS_V2_Amount_Allocator::to_minor_units($snapshot['amounts'][$field], $precision);
			$after  = WCOS_V2_Amount_Allocator::to_minor_units($specification['source']['amounts'][$field], $precision)
				+ WCOS_V2_Amount_Allocator::to_minor_units($specification['child']['amounts'][$field], $precision);

			if ($before !== $after) {
				throw new LogicException(sprintf('Order-level %s conservation failed.', $field));
			}
		}

		if ('0' !== $specification['stock']['physical_delta']) {
			throw new LogicException('A quantity split specification must have zero physical stock delta.');
		}
	}

	/**
	 * Normalize a decimal amount.
	 *
	 * @param mixed $amount    Amount.
	 * @param int   $precision Currency precision.
	 * @return string
	 */
	private static function normalize_amount($amount, $precision) {
		return self::from_minor(WCOS_V2_Amount_Allocator::to_minor_units($amount, $precision), $precision);
	}

	/**
	 * Convert minor units to a normalized decimal amount.
	 *
	 * @param int $minor     Minor units.
	 * @param int $precision Currency precision.
	 * @return string
	 */
	private static function from_minor($minor, $precision) {
		return WCOS_V2_Amount_Allocator::from_minor_units((int) $minor, (int) $precision);
	}

	/**
	 * Encode a deterministic specification fingerprint.
	 *
	 * @param array $value Specification.
	 * @return string
	 */
	private static function canonical_json(array $value) {
		$value = self::canonicalize($value);
		$json  = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		if (false === $json) {
			throw new LogicException('Unable to encode the quantity split specification.');
		}

		return $json;
	}

	/**
	 * Recursively sort associative structures.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalize($value) {
		if (!is_array($value)) {
			return $value;
		}

		$result = array();

		foreach ($value as $key => $nested) {
			$result[$key] = self::canonicalize($nested);
		}

		if (array() !== $result && array_keys($result) !== range(0, count($result) - 1)) {
			ksort($result, SORT_STRING);
		}

		return $result;
	}
}
