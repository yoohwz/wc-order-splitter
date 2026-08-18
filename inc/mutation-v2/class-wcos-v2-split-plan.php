<?php
/**
 * Conservation-first split plan builder.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || (PHP_SAPI === 'cli') || exit;

/**
 * Builds a deterministic, side-effect-free quantity split plan.
 */
final class WCOS_V2_Split_Plan {

	/**
	 * Monetary fields that must be conserved for every order line.
	 *
	 * @var string[]
	 */
	private const AMOUNT_FIELDS = array(
		'subtotal',
		'total',
		'subtotal_tax',
		'total_tax',
	);

	/**
	 * Build a split plan from normalized order-line snapshots.
	 *
	 * Each line must contain item_id, quantity and split_quantity. Monetary
	 * fields and taxes are historical values; this class never recalculates tax.
	 *
	 * @param array $lines     Line snapshots.
	 * @param int   $precision Currency precision.
	 * @return array
	 * @throws InvalidArgumentException Invalid input.
	 * @throws LogicException           A conservation invariant failed.
	 */
	public static function build(array $lines, $precision = 2) {
		$precision = (int) $precision;

		if (empty($lines)) {
			throw new InvalidArgumentException('At least one source line is required.');
		}

		$planned_lines        = array();
		$total_quantity       = 0.0;
		$total_split_quantity = 0.0;
		$seen_item_ids        = array();

		foreach ($lines as $line) {
			if (!is_array($line)) {
				throw new InvalidArgumentException('Every line snapshot must be an array.');
			}

			$item_id = isset($line['item_id']) ? (int) $line['item_id'] : 0;

			if ($item_id <= 0 || isset($seen_item_ids[$item_id])) {
				throw new InvalidArgumentException('Every source line must have a unique positive item_id.');
			}

			$seen_item_ids[$item_id] = true;

			$quantity       = self::read_quantity($line, 'quantity');
			$split_quantity = self::read_quantity($line, 'split_quantity');

			if ($quantity <= 0) {
				throw new InvalidArgumentException('Source quantities must be greater than zero.');
			}

			if ($split_quantity < 0 || $split_quantity > $quantity) {
				throw new InvalidArgumentException('Split quantity must be between zero and the source quantity.');
			}

			$remaining_quantity = $quantity - $split_quantity;
			$weights            = array(
				'original' => $remaining_quantity,
				'child'    => $split_quantity,
			);
			$source             = array(
				'item_id'          => $item_id,
				'quantity'         => self::format_quantity($quantity),
				'split_quantity'   => self::format_quantity($split_quantity),
				'reduced_stock'    => null,
				'taxes'            => array(
					'subtotal' => array(),
					'total'    => array(),
				),
			);
			$original           = array(
				'quantity'      => self::format_quantity($remaining_quantity),
				'reduced_stock' => null,
				'taxes'         => array(
					'subtotal' => array(),
					'total'    => array(),
				),
			);
			$child              = array(
				'quantity'      => self::format_quantity($split_quantity),
				'reduced_stock' => null,
				'taxes'         => array(
					'subtotal' => array(),
					'total'    => array(),
			);

			foreach (self::AMOUNT_FIELDS as $field) {
				$amount = array_key_exists($field, $line) ? $line[$field] : '0';

				if (!is_numeric($amount)) {
					throw new InvalidArgumentException('Historical line amounts must be numeric.');
				}

				$normalized_amount = WCOS_V2_Amount_Allocator::from_minor_units(
					WCOS_V2_Amount_Allocator::to_minor_units($amount, $precision),
					$precision
				);
				$allocation        = WCOS_V2_Amount_Allocator::allocate($normalized_amount, $weights, $precision);

				$source[$field]   = $normalized_amount;
				$original[$field] = $allocation['original'];
				$child[$field]    = $allocation['child'];
			}

			$taxes = isset($line['taxes']) && is_array($line['taxes']) ? $line['taxes'] : array();

			foreach (array('subtotal', 'total') as $tax_context) {
				$rate_amounts = isset($taxes[$tax_context]) && is_array($taxes[$tax_context]) ? $taxes[$tax_context] : array();
				ksort($rate_amounts, SORT_NATURAL);

				foreach ($rate_amounts as $rate_id => $tax_amount) {
					if (!is_numeric($tax_amount)) {
						throw new InvalidArgumentException('Historical tax amounts must be numeric.');
					}

					$normalized_tax = WCOS_V2_Amount_Allocator::from_minor_units(
						WCOS_V2_Amount_Allocator::to_minor_units($tax_amount, $precision),
						$precision
					);
					$allocation     = WCOS_V2_Amount_Allocator::allocate($normalized_tax, $weights, $precision);
					$rate_key       = (string) $rate_id;

					$source['taxes'][$tax_context][$rate_key]   = $normalized_tax;
					$original['taxes'][$tax_context][$rate_key] = $allocation['original'];
					$child['taxes'][$tax_context][$rate_key]    = $allocation['child'];
				}
			}

			if (array_key_exists('reduced_stock', $line) && '' !== $line['reduced_stock'] && null !== $line['reduced_stock']) {
				if (!is_numeric($line['reduced_stock']) || (float) $line['reduced_stock'] < 0) {
					throw new InvalidArgumentException('Reduced stock must be a non-negative number or null.');
				}

				$reduced_stock          = (float) $line['reduced_stock'];
				$child_reduced_stock    = min($reduced_stock, $split_quantity);
				$original_reduced_stock = $reduced_stock - $child_reduced_stock;

				$source['reduced_stock']   = self::format_quantity($reduced_stock);
				$original['reduced_stock'] = self::format_quantity($original_reduced_stock);
				$child['reduced_stock']    = self::format_quantity($child_reduced_stock);
			}

			$planned_lines[$item_id] = array(
				'source'   => $source,
				'original' => $original,
				'child'    => $child,
			);

			$total_quantity       += $quantity;
			$total_split_quantity += $split_quantity;
		}

		if ($total_split_quantity <= 0) {
			throw new InvalidArgumentException('At least one positive split quantity is required.');
		}

		if ($total_split_quantity >= $total_quantity - 0.0000001) {
			throw new InvalidArgumentException('A split operation must leave at least one quantity on the original order.');
		}

		ksort($planned_lines, SORT_NUMERIC);

		$plan = array(
			'precision'             => $precision,
			'source_quantity'       => self::format_quantity($total_quantity),
			'split_quantity'        => self::format_quantity($total_split_quantity),
			'original_quantity'     => self::format_quantity($total_quantity - $total_split_quantity),
			'charge_policy'         => 'keep_shipping_fees_and_coupons_on_original',
			'tax_policy'            => 'preserve_historical_allocations',
			'lines'                 => $planned_lines,
		);

		self::assert_conservation($plan);

		$plan['fingerprint'] = hash('sha256', self::canonical_json($plan));

		return $plan;
	}

	/**
	 * Assert all line-level conservation invariants.
	 *
	 * @param array $plan Split plan.
	 * @return void
	 * @throws LogicException A conservation invariant failed.
	 */
	public static function assert_conservation(array $plan) {
		$precision = isset($plan['precision']) ? (int) $plan['precision'] : 2;

		foreach ($plan['lines'] as $item_id => $line) {
			$source_quantity   = (float) $line['source']['quantity'];
			$planned_quantity  = (float) $line['original']['quantity'] + (float) $line['child']['quantity'];

			if (abs($source_quantity - $planned_quantity) > 0.0000001) {
				throw new LogicException(sprintf('Quantity conservation failed for item %d.', $item_id));
			}

			foreach (self::AMOUNT_FIELDS as $field) {
				$source_minor = WCOS_V2_Amount_Allocator::to_minor_units($line['source'][$field], $precision);
				$after_minor  = WCOS_V2_Amount_Allocator::to_minor_units($line['original'][$field], $precision)
					+ WCOS_V2_Amount_Allocator::to_minor_units($line['child'][$field], $precision);

				if ($source_minor !== $after_minor) {
					throw new LogicException(sprintf('%s conservation failed for item %d.', $field, $item_id));
				}
			}

			foreach (array('subtotal', 'total') as $tax_context) {
				foreach ($line['source']['taxes'][$tax_context] as $rate_id => $source_tax) {
					$source_minor = WCOS_V2_Amount_Allocator::to_minor_units($source_tax, $precision);
					$after_minor  = WCOS_V2_Amount_Allocator::to_minor_units($line['original']['taxes'][$tax_context][$rate_id], $precision)
						+ WCOS_V2_Amount_Allocator::to_minor_units($line['child']['taxes'][$tax_context][$rate_id], $precision);

					if ($source_minor !== $after_minor) {
						throw new LogicException(sprintf('Tax conservation failed for item %d and rate %s.', $item_id, $rate_id));
					}
				}
			}

			if (null !== $line['source']['reduced_stock']) {
				$source_reduced = (float) $line['source']['reduced_stock'];
				$after_reduced  = (float) $line['original']['reduced_stock'] + (float) $line['child']['reduced_stock'];

				if (abs($source_reduced - $after_reduced) > 0.0000001) {
					throw new LogicException(sprintf('Reduced-stock conservation failed for item %d.', $item_id));
				}
			}
		}
	}

	/**
	 * Read and validate a quantity from a line snapshot.
	 *
	 * @param array  $line Line snapshot.
	 * @param string $key  Quantity key.
	 * @return float
	 */
	private static function read_quantity(array $line, $key) {
		if (!array_key_exists($key, $line) || !is_numeric($line[$key])) {
			throw new InvalidArgumentException(sprintf('%s must be numeric.', $key));
		}

		return (float) $line[$key];
	}

	/**
	 * Normalize a stock or item quantity without introducing locale formatting.
	 *
	 * @param float $quantity Quantity.
	 * @return string
	 */
	private static function format_quantity($quantity) {
		$formatted = number_format((float) $quantity, 6, '.', '');
		$formatted = rtrim(rtrim($formatted, '0'), '.');

		return '' === $formatted || '-0' === $formatted ? '0' : $formatted;
	}

	/**
	 * Encode canonical JSON for stable fingerprints.
	 *
	 * @param array $value Value to encode.
	 * @return string
	 */
	private static function canonical_json(array $value) {
		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		if (false === $json) {
			throw new LogicException('Unable to encode the split plan fingerprint.');
		}

		return $json;
	}
}
