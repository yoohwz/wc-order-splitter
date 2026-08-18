<?php

defined('ABSPATH') || exit;

final class WC_Order_Splitter_Order_Mutation_Engine {
	const SHIPPING_KEEP_ON_ORIGINAL       = 'keep_on_original';
	const SHIPPING_MOVE_TO_CHILD          = 'move_to_child';
	const SHIPPING_PROPORTIONAL           = 'proportional';
	const SHIPPING_EXPLICIT_ALLOCATION    = 'explicit_allocation';
	const SHIPPING_ZERO_VALUE_REFERENCE   = 'zero_value_reference_copy';

	const TAX_PRESERVE_HISTORICAL         = 'preserve_historical';
	const TAX_RECALCULATE_EXPLICITLY      = 'recalculate_explicitly';

	const EMAIL_SUPPRESS_ALL_CHILDREN      = 'suppress_all_children';
	const EMAIL_NOTIFY_ORIGINAL_ONLY       = 'notify_original_only';
	const EMAIL_NOTIFY_CHILDREN_ONLY       = 'notify_children_only';
	const EMAIL_NOTIFY_BOTH                = 'notify_both';

	const STATUS_PRESERVE                  = 'preserve';
	const STATUS_PENDING                   = 'pending';
	const STATUS_EXPLICIT_TARGET           = 'explicit_target_status';

	private $cloner;
	private $journal;

	public function __construct() {
		$this->cloner = new WC_Order_Splitter_Order_Item_Cloner();
		$this->journal = new WC_Order_Splitter_Operation_Journal();
	}

	public function split($source_order, $plan, $policies = array(), $idempotency_key = '') {
		$this->assert_split_order($source_order);
		$plan = $this->normalize_split_plan($source_order, $plan);
		$policies = $this->normalize_policies($policies);

		if ($idempotency_key) {
			$existing = $this->get_idempotent_result($source_order, 'split', $idempotency_key);
			if ($existing) {
				return $existing;
			}
		}

		$lock = new WC_Order_Splitter_Mutation_Lock();
		$lock->acquire_orders(array($source_order->get_id()));
		$created_orders = array();
		$original_mutated = false;
		$snapshot = WC_Order_Splitter_Mutation_Support::capture_order_snapshot($source_order);
		$before_quantities = WC_Order_Splitter_Mutation_Support::sum_line_quantities_by_identity(array($source_order));
		$before_reduced_stock = WC_Order_Splitter_Mutation_Support::sum_reduced_stock_by_identity(array($source_order));
		$before_physical_stock = $this->capture_physical_stock($source_order);
		$record = $this->journal->start($source_order, 'split', $snapshot, array(
			'plan'     => $plan,
			'policies' => $policies,
		));

		try {
			$allocations = $this->build_line_allocations($source_order, $plan);
			$destination_weights = $this->destination_weights($allocations);
			$coupon_allocations = $this->build_coupon_allocations($source_order, $destination_weights);
			$shipping_allocations = $this->build_shipping_allocations($source_order, $destination_weights, $policies);
			$tax_templates = $this->tax_templates(array($source_order));
			$source_stock_reduced = WC_Order_Splitter_Mutation_Support::get_stock_reduced($source_order);

			foreach (array_keys($plan) as $destination) {
				$child = wc_create_order(array('status' => self::STATUS_PENDING));
				if (is_wp_error($child)) {
					throw new WC_Order_Splitter_Mutation_Exception($child->get_error_message());
				}
				if (!$child instanceof WC_Order) {
					throw new WC_Order_Splitter_Mutation_Exception(__('WooCommerce could not create the split order.', 'wc-order-splitter'));
				}

				$this->cloner->copy_order_context($source_order, $child, 'wc-order-splitter');
				$child->update_meta_data(WC_Order_Splitter_Mutation_Support::META_OPERATION_ID, $record['id']);
				$child->update_meta_data(WC_Order_Splitter_Mutation_Support::META_ORIGINAL_ID, $source_order->get_id());

				foreach ($allocations as $item_id => $destinations) {
					if (!isset($destinations[$destination])) {
						continue;
					}
					$source_item = $source_order->get_item($item_id);
					if (!$source_item instanceof WC_Order_Item_Product) {
						throw new WC_Order_Splitter_Mutation_Exception(__('A source line item disappeared while the split was running.', 'wc-order-splitter'));
					}
					$data = $destinations[$destination];
					$new_item = $this->cloner->clone_product($source_item, $data['props'], false);
					if (isset($data['reduced_stock']) && null !== $data['reduced_stock']) {
						$new_item->update_meta_data('_reduced_stock', $data['reduced_stock']);
					}
					$child->add_item($new_item);
				}

				if (isset($coupon_allocations[$destination])) {
					foreach ($coupon_allocations[$destination] as $coupon_id => $coupon_data) {
						$source_coupon = $source_order->get_item($coupon_id);
						if ($source_coupon instanceof WC_Order_Item_Coupon) {
							$child->add_item($this->cloner->clone_coupon($source_coupon, $coupon_data));
						}
					}
				}

				if (isset($shipping_allocations[$destination])) {
					foreach ($shipping_allocations[$destination] as $shipping_id => $shipping_data) {
						$source_shipping = $source_order->get_item($shipping_id);
						if ($source_shipping instanceof WC_Order_Item_Shipping) {
							$new_shipping = $this->cloner->clone_shipping($source_shipping, $shipping_data);
							$new_shipping->update_meta_data(WC_Order_Splitter_Mutation_Support::META_ITEMS_SUMMARY, $this->items_summary_for_destination($source_order, $allocations, $destination));
							$child->add_item($new_shipping);
						}
					}
				}

				$this->rebuild_tax_items($child, $tax_templates);
				$this->calculate_order_totals($child, $policies['tax_policy']);
				$child->save();

				WC_Order_Splitter_Mutation_Support::set_stock_reduced($child, true);
				$this->set_child_status($source_order, $child, $policies);
				WC_Order_Splitter_Mutation_Support::set_stock_reduced($child, $source_stock_reduced);
				$child->save_meta_data();

				$created_orders[$destination] = $child;
			}

			$this->apply_original_line_allocations($source_order, $allocations);
			$this->apply_original_coupon_allocations($source_order, $coupon_allocations);
			$this->apply_original_shipping_allocations($source_order, $shipping_allocations);
			$this->rebuild_tax_items($source_order, $tax_templates);
			$this->calculate_order_totals($source_order, $policies['tax_policy']);
			$source_order->save();
			$original_mutated = true;

			$child_ids = array();
			foreach ($created_orders as $child) {
				$child_ids[] = $child->get_id();
			}
			$this->write_split_relations($source_order, $created_orders, $record['id']);

			$orders_after = array_merge(array($source_order), array_values($created_orders));
			WC_Order_Splitter_Mutation_Support::assert_totals_conserved($snapshot['totals'], $orders_after);
			WC_Order_Splitter_Mutation_Support::assert_map_conserved(
				$before_quantities,
				WC_Order_Splitter_Mutation_Support::sum_line_quantities_by_identity($orders_after),
				__('Line quantity', 'wc-order-splitter')
			);
			WC_Order_Splitter_Mutation_Support::assert_map_conserved(
				$before_reduced_stock,
				WC_Order_Splitter_Mutation_Support::sum_reduced_stock_by_identity($orders_after),
				__('Reduced stock', 'wc-order-splitter')
			);
			$this->assert_physical_stock_unchanged($before_physical_stock, $source_order);

			$record = $this->journal->complete($source_order, $record, $child_ids, array(
				'after_totals' => $this->aggregate_order_totals($orders_after),
			));
			if ($idempotency_key) {
				$this->store_idempotent_result($source_order, 'split', $idempotency_key, $child_ids, $record['id']);
			}

			$source_order->add_order_note(sprintf(
				__('Order split completed safely. Created orders: %s.', 'wc-order-splitter'),
				implode(', ', array_map(array($this, 'format_order_number'), array_values($created_orders)))
			), false);
			foreach ($created_orders as $child) {
				$child->add_order_note(sprintf(
					__('This order was split from order %s.', 'wc-order-splitter'),
					'#' . WC_Order_Splitter_Mutation_Support::order_number($source_order)
				), false);
			}

			return array(
				'operation_id' => $record['id'],
				'new_order_ids' => $child_ids,
			);
		} catch (Throwable $error) {
			$compensated = false;
			if (!$original_mutated) {
				$compensated = $this->delete_created_orders($created_orders);
			}
			$this->journal->fail($source_order, $record, $error, array(
				'compensated' => $compensated,
				'original_mutated' => $original_mutated,
			));
			throw $error;
		} finally {
			$lock->release_all();
		}
	}

	public function duplicate($source_order, $policies = array()) {
		WC_Order_Splitter_Mutation_Support::assert_can_manage_order($source_order);
		if (!WC_Order_Splitter_Mutation_Support::is_status_allowed($source_order)) {
			throw new WC_Order_Splitter_Mutation_Exception(__('This order status is not allowed for duplication.', 'wc-order-splitter'));
		}

		$lock = new WC_Order_Splitter_Mutation_Lock();
		$lock->acquire_orders(array($source_order->get_id()));
		$snapshot = WC_Order_Splitter_Mutation_Support::capture_order_snapshot($source_order);
		$record = $this->journal->start($source_order, 'duplicate', $snapshot, array());
		$new_order = null;

		try {
			$new_order = wc_create_order(array('status' => self::STATUS_PENDING));
			if (is_wp_error($new_order)) {
				throw new WC_Order_Splitter_Mutation_Exception($new_order->get_error_message());
			}
			$this->cloner->copy_order_context($source_order, $new_order, 'wc-order-splitter-duplicate');
			$this->cloner->clone_all_items($source_order, $new_order, false);
			$new_order->update_meta_data(WC_Order_Splitter_Mutation_Support::META_OPERATION_ID, $record['id']);
			$new_order->update_meta_data('_wc_order_splitter_duplicate_of', $source_order->get_id());
			$this->calculate_order_totals($new_order, self::TAX_PRESERVE_HISTORICAL);
			$new_order->save();
			WC_Order_Splitter_Mutation_Support::set_stock_reduced($new_order, false);

			WC_Order_Splitter_Mutation_Support::assert_totals_conserved($snapshot['totals'], array($new_order));
			$record = $this->journal->complete($source_order, $record, array($new_order->get_id()));
			$duplicates = (array) $source_order->get_meta('_wc_order_splitter_duplicates', true);
			$duplicates[] = $new_order->get_id();
			$source_order->update_meta_data('_wc_order_splitter_duplicates', array_values(array_unique(array_map('absint', $duplicates))));
			$source_order->save_meta_data();

			$source_order->add_order_note(sprintf(__('Order duplicated safely as %s.', 'wc-order-splitter'), '#' . $new_order->get_order_number()));
			$new_order->add_order_note(sprintf(__('This order is a duplicate of %s. Stock has not been reduced for the duplicate.', 'wc-order-splitter'), '#' . $source_order->get_order_number()));

			return $new_order;
		} catch (Throwable $error) {
			if ($new_order instanceof WC_Order) {
				$new_order->delete(true);
			}
			$this->journal->fail($source_order, $record, $error, array('compensated' => true));
			throw $error;
		} finally {
			$lock->release_all();
		}
	}

	public function merge($source_order, $target_order) {
		$this->assert_merge_compatible($source_order, $target_order);
		$lock = new WC_Order_Splitter_Mutation_Lock();
		$lock->acquire_orders(array($source_order->get_id(), $target_order->get_id()));

		$source_snapshot = WC_Order_Splitter_Mutation_Support::capture_order_snapshot($source_order);
		$target_snapshot = WC_Order_Splitter_Mutation_Support::capture_order_snapshot($target_order);
		$aggregate_before = WC_Order_Splitter_Mutation_Support::add_amount_maps($source_snapshot['totals'], $target_snapshot['totals']);
		$before_physical_stock = $this->capture_physical_stock_for_orders(array($source_order, $target_order));
		$record = $this->journal->start($source_order, 'merge', $source_snapshot, array(
			'target_order' => $target_order->get_id(),
			'target_snapshot_hash' => $target_snapshot['hash'],
		));
		$target_mutated = false;

		try {
			$target_map = array();
			foreach ($target_order->get_items('line_item') as $target_item) {
				$identity = WC_Order_Splitter_Mutation_Support::line_identity($target_item);
				if (!isset($target_map[$identity])) {
					$target_map[$identity] = array();
				}
				$target_map[$identity][] = $target_item;
			}

			foreach ($source_order->get_items('line_item') as $source_item) {
				$identity = WC_Order_Splitter_Mutation_Support::line_identity($source_item);
				$existing = isset($target_map[$identity]) && !empty($target_map[$identity]) ? reset($target_map[$identity]) : null;
				if ($existing instanceof WC_Order_Item_Product) {
					$this->merge_product_items($existing, $source_item);
				} else {
					$target_order->add_item($this->cloner->clone_product($source_item, array(), true));
				}
			}

			foreach (array('shipping', 'fee', 'coupon') as $type) {
				foreach ($source_order->get_items($type) as $source_item) {
					$target_order->add_item($this->cloner->clone_item($source_item));
				}
			}

			$tax_templates = $this->tax_templates(array($target_order, $source_order));
			$this->rebuild_tax_items($target_order, $tax_templates);
			$this->calculate_order_totals($target_order, self::TAX_PRESERVE_HISTORICAL);
			$target_order->save();
			$target_mutated = true;

			WC_Order_Splitter_Mutation_Support::assert_totals_conserved($aggregate_before, array($target_order));
			$this->assert_physical_stock_snapshot_unchanged($before_physical_stock);

			$merged_sources = (array) $target_order->get_meta('_wc_order_splitter_merged_sources', true);
			$merged_sources[] = $source_order->get_id();
			$target_order->update_meta_data('_wc_order_splitter_merged_sources', array_values(array_unique(array_map('absint', $merged_sources))));
			$target_order->save_meta_data();

			$source_order->update_meta_data(WC_Order_Splitter_Mutation_Support::META_MERGED_INTO, $target_order->get_id());
			foreach ($source_order->get_items('line_item') as $item) {
				$item->delete_meta_data('_reduced_stock');
				$item->save_meta_data();
			}
			WC_Order_Splitter_Mutation_Support::set_stock_reduced($source_order, false);
			$source_order->save_meta_data();

			$record = $this->journal->complete($source_order, $record, array($target_order->get_id()), array(
				'aggregate_before' => $aggregate_before,
				'aggregate_after'  => WC_Order_Splitter_Mutation_Support::order_totals($target_order),
			));

			$target_order->add_order_note(sprintf(__('Merged safely from order %s.', 'wc-order-splitter'), '#' . $source_order->get_order_number()));
			$source_order->add_order_note(sprintf(__('This order was merged into %s.', 'wc-order-splitter'), '#' . $target_order->get_order_number()));
			$source_order->delete(false);

			return $target_order;
		} catch (Throwable $error) {
			$compensated = false;
			if ($target_mutated) {
				$compensated = $this->restore_order_from_snapshot($target_order, $target_snapshot);
			}
			$this->journal->fail($source_order, $record, $error, array('target_compensated' => $compensated));
			throw $error;
		} finally {
			$lock->release_all();
		}
	}

	public function return_split_order($child_order) {
		WC_Order_Splitter_Mutation_Support::assert_can_manage_order($child_order);
		if ('yes' === $child_order->get_meta(WC_Order_Splitter_Mutation_Support::META_RETURNED, true)) {
			$original_id = absint($child_order->get_meta(WC_Order_Splitter_Mutation_Support::META_ORIGINAL_ID, true));
			return wc_get_order($original_id);
		}

		list($source_order, $record) = $this->journal->get_for_child($child_order);
		if (!$source_order || empty($record)) {
			list($source_order, $record) = $this->migrate_legacy_split_relation($child_order);
		}
		if (!$source_order || empty($record) || 'split' !== $record['type'] || 'completed' !== $record['status']) {
			throw new WC_Order_Splitter_Mutation_Exception(__('This order does not have a valid completed split relationship.', 'wc-order-splitter'));
		}
		if (!in_array($child_order->get_id(), array_map('absint', (array) $record['target_orders']), true)) {
			throw new WC_Order_Splitter_Mutation_Exception(__('This split order is not registered in its operation journal.', 'wc-order-splitter'));
		}
		WC_Order_Splitter_Mutation_Support::assert_can_manage_order($source_order);

		$lock = new WC_Order_Splitter_Mutation_Lock();
		$lock->acquire_orders(array($source_order->get_id(), $child_order->get_id()));
		$source_snapshot = WC_Order_Splitter_Mutation_Support::capture_order_snapshot($source_order);
		$child_snapshot = WC_Order_Splitter_Mutation_Support::capture_order_snapshot($child_order);
		$aggregate_before = WC_Order_Splitter_Mutation_Support::add_amount_maps($source_snapshot['totals'], $child_snapshot['totals']);
		$before_stock = $this->capture_physical_stock_for_orders(array($source_order, $child_order));
		$source_mutated = false;

		try {
			$source_map = array();
			foreach ($source_order->get_items('line_item') as $item) {
				$identity = WC_Order_Splitter_Mutation_Support::line_identity($item);
				if (!isset($source_map[$identity])) {
					$source_map[$identity] = $item;
				}
			}

			foreach ($child_order->get_items('line_item') as $child_item) {
				$identity = WC_Order_Splitter_Mutation_Support::line_identity($child_item);
				if (isset($source_map[$identity])) {
					$this->merge_product_items($source_map[$identity], $child_item);
				} else {
					$source_order->add_item($this->cloner->clone_product($child_item, array(), true));
				}
			}

			foreach (array('shipping', 'fee', 'coupon') as $type) {
				foreach ($child_order->get_items($type) as $item) {
					if ('shipping' === $type && 0.0 === (float) $item->get_total() && $item->get_meta(WC_Order_Splitter_Mutation_Support::META_ITEMS_SUMMARY, true)) {
						continue;
					}
					$source_order->add_item($this->cloner->clone_item($item));
				}
			}

			$tax_templates = $this->tax_templates(array($source_order, $child_order));
			$this->rebuild_tax_items($source_order, $tax_templates);
			$this->calculate_order_totals($source_order, self::TAX_PRESERVE_HISTORICAL);
			$source_order->save();
			$source_mutated = true;

			WC_Order_Splitter_Mutation_Support::assert_totals_conserved($aggregate_before, array($source_order));
			$this->assert_physical_stock_snapshot_unchanged($before_stock);

			foreach ($child_order->get_items('line_item') as $item) {
				$item->delete_meta_data('_reduced_stock');
				$item->save_meta_data();
			}
			WC_Order_Splitter_Mutation_Support::set_stock_reduced($child_order, false);
			$child_order->update_meta_data(WC_Order_Splitter_Mutation_Support::META_RETURNED, 'yes');
			$child_order->save_meta_data();

			$returned = isset($record['context']['returned_orders']) ? (array) $record['context']['returned_orders'] : array();
			$returned[] = $child_order->get_id();
			$this->journal->update($source_order, $record, array(
				'context' => array_merge((array) $record['context'], array('returned_orders' => array_values(array_unique(array_map('absint', $returned))))),
			));

			$source_order->add_order_note(sprintf(__('Returned items safely from split order %s.', 'wc-order-splitter'), '#' . $child_order->get_order_number()), false);
			$child_order->add_order_note(sprintf(__('This split order was returned to %s.', 'wc-order-splitter'), '#' . $source_order->get_order_number()), false);
			$child_order->delete(false);

			return $source_order;
		} catch (Throwable $error) {
			if ($source_mutated) {
				$this->restore_order_from_snapshot($source_order, $source_snapshot);
			}
			throw $error;
		} finally {
			$lock->release_all();
		}
	}

	public function build_category_plan($order) {
		$this->assert_split_order($order);
		$groups = array();
		foreach ($order->get_items('line_item') as $item_id => $item) {
			$product = $item->get_product();
			if (!$product) {
				$key = 'uncategorized';
			} else {
				$product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
				$term_ids = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
				if (is_wp_error($term_ids)) {
					throw new WC_Order_Splitter_Mutation_Exception($term_ids->get_error_message());
				}
				$term_ids = array_values(array_unique(array_map('absint', (array) $term_ids)));
				if (count($term_ids) > 1) {
					throw new WC_Order_Splitter_Mutation_Exception(__('A product belongs to multiple categories. Use quantity split for an explicit allocation.', 'wc-order-splitter'));
				}
				$key = empty($term_ids) ? 'uncategorized' : 'category-' . $term_ids[0];
			}
			if (!isset($groups[$key])) {
				$groups[$key] = array();
			}
			$groups[$key][$item_id] = (float) $item->get_quantity();
		}
		return $this->groups_to_plan($groups, __('There is no different category to split.', 'wc-order-splitter'));
	}

	public function build_stock_status_plan($order) {
		$this->assert_split_order($order);
		$groups = array();
		foreach ($order->get_items('line_item') as $item_id => $item) {
			$product = $item->get_product();
			$key = $product ? sanitize_key($product->get_stock_status()) : 'unknown';
			if (!isset($groups[$key])) {
				$groups[$key] = array();
			}
			$groups[$key][$item_id] = (float) $item->get_quantity();
		}
		return $this->groups_to_plan($groups, __('There is no different stock status to split.', 'wc-order-splitter'));
	}

	public function preview_split($order, $plan, $policies = array()) {
		$this->assert_split_order($order);
		$plan = $this->normalize_split_plan($order, $plan);
		$policies = $this->normalize_policies($policies);
		$allocations = $this->build_line_allocations($order, $plan);
		$preview = array(
			'original_order_id' => $order->get_id(),
			'currency' => $order->get_currency(),
			'policies' => $policies,
			'destinations' => array(),
		);
		foreach ($plan as $destination => $items) {
			$preview['destinations'][$destination] = array('items' => array(), 'subtotal' => 0, 'total' => 0);
			foreach ($allocations as $item_id => $destination_data) {
				if (!isset($destination_data[$destination])) {
					continue;
				}
				$item = $order->get_item($item_id);
				$data = $destination_data[$destination]['props'];
				$preview['destinations'][$destination]['items'][] = array(
					'item_id' => $item_id,
					'name' => $item ? $item->get_name() : '',
					'quantity' => $data['quantity'],
					'subtotal' => $data['subtotal'],
					'total' => $data['total'],
				);
				$preview['destinations'][$destination]['subtotal'] += (float) $data['subtotal'];
				$preview['destinations'][$destination]['total'] += (float) $data['total'] + (float) $data['total_tax'];
			}
		}
		return $preview;
	}

	public function assert_merge_compatible($source_order, $target_order) {
		WC_Order_Splitter_Mutation_Support::assert_can_manage_order($source_order);
		WC_Order_Splitter_Mutation_Support::assert_can_manage_order($target_order);
		if ($source_order->get_id() === $target_order->get_id()) {
			throw new WC_Order_Splitter_Mutation_Exception(__('An order cannot be merged into itself.', 'wc-order-splitter'));
		}
		if ($source_order->get_type() !== $target_order->get_type()) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Only orders of the same type can be merged.', 'wc-order-splitter'));
		}
		if ($source_order->get_currency() !== $target_order->get_currency()) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Orders with different currencies cannot be merged.', 'wc-order-splitter'));
		}
		if ((int) $source_order->get_customer_id() !== (int) $target_order->get_customer_id()) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Orders for different customers cannot be merged.', 'wc-order-splitter'));
		}
		if ($source_order->get_payment_method() !== $target_order->get_payment_method()) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Orders with different payment methods cannot be merged.', 'wc-order-splitter'));
		}
		if ($source_order->get_status() !== $target_order->get_status()) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Orders must have the same status before they can be merged.', 'wc-order-splitter'));
		}
		if (!empty($source_order->get_refunds()) || !empty($target_order->get_refunds())) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Refunded or partially refunded orders cannot be merged.', 'wc-order-splitter'));
		}
		$allow_paid = (bool) apply_filters('wc_order_splitter_merge_allow_paid_orders', false, $source_order, $target_order);
		if (!$allow_paid && ($source_order->is_paid() || $target_order->is_paid() || $source_order->get_transaction_id() || $target_order->get_transaction_id())) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Paid orders cannot be merged by the free safety workflow.', 'wc-order-splitter'));
		}
		if (WC_Order_Splitter_Mutation_Support::get_stock_reduced($source_order) !== WC_Order_Splitter_Mutation_Support::get_stock_reduced($target_order)) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Orders with different stock-reduction states cannot be merged.', 'wc-order-splitter'));
		}
		if ($this->normalized_address($source_order->get_address('billing')) !== $this->normalized_address($target_order->get_address('billing')) ||
			$this->normalized_address($source_order->get_address('shipping')) !== $this->normalized_address($target_order->get_address('shipping'))) {
			throw new WC_Order_Splitter_Mutation_Exception(__('Orders must have matching billing and shipping addresses before merging.', 'wc-order-splitter'));
		}
		if ($source_order->get_meta(WC_Order_Splitter_Mutation_Support::META_MERGED_INTO, true)) {
			throw new WC_Order_Splitter_Mutation_Exception(__('This source order has already been merged.', 'wc-order-splitter'));
		}
		return true;
	}

	public function format_order_number($order) {
		return '#' . WC_Order_Splitter_Mutation_Support::order_number($order);
	}

	private function assert_split_order($order) {
		WC_Order_Splitter_Mutation_Support::assert_can_manage_order($order);
		if (!WC_Order_Splitter_Mutation_Support::is_status_allowed($order)) {
			throw new WC_Order_Splitter_Mutation_Exception(__('This order status is not allowed for splitting.', 'wc-order-splitter'));
		}
		if (empty($order->get_items('line_item'))) {
			throw new WC_Order_Splitter_Mutation_Exception(__('This order has no product line items to split.', 'wc-order-splitter'));
		}
	}

	private function normalize_split_plan($order, $plan) {
		$source_quantities = array();
		foreach ($order->get_items('line_item') as $item_id => $item) {
			$source_quantities[(int) $item_id] = (float) $item->get_quantity();
		}

		$normalized = array();
		$totals = array_fill_keys(array_keys($source_quantities), 0);
		foreach ((array) $plan as $destination => $items) {
			$key = sanitize_key((string) $destination);
			if (!$key || 'original' === $key) {
				throw new WC_Order_Splitter_Mutation_Exception(__('Invalid split destination.', 'wc-order-splitter'));
			}
			foreach ((array) $items as $item_id => $quantity) {
				$item_id = absint($item_id);
				$quantity = (float) wc_format_decimal($quantity, 6);
				if (!$item_id || $quantity <= 0 || !isset($source_quantities[$item_id])) {
					throw new WC_Order_Splitter_Mutation_Exception(__('The split plan contains an invalid order item or quantity.', 'wc-order-splitter'));
				}
				if (!isset($normalized[$key])) {
					$normalized[$key] = array();
				}
				$normalized[$key][$item_id] = $quantity;
				$totals[$item_id] += $quantity;
			}
		}
		if (empty($normalized)) {
			throw new WC_Order_Splitter_Mutation_Exception(__('No quantities were selected for splitting.', 'wc-order-splitter'));
		}

		$remaining_total = 0;
		foreach ($source_quantities as $item_id => $quantity) {
			if ($totals[$item_id] - $quantity > 0.000001) {
				throw new WC_Order_Splitter_Mutation_Exception(__('A split quantity exceeds the source line quantity.', 'wc-order-splitter'));
			}
			$remaining_total += max(0, $quantity - $totals[$item_id]);
		}
		if ($remaining_total <= 0) {
			throw new WC_Order_Splitter_Mutation_Exception(__('At least one product quantity must remain on the original order.', 'wc-order-splitter'));
		}
		return $normalized;
	}

	private function normalize_policies($policies) {
		$defaults = array(
			'shipping_policy' => get_option('order_splitter_shipping_policy', self::SHIPPING_KEEP_ON_ORIGINAL),
			'tax_policy'      => self::TAX_PRESERVE_HISTORICAL,
			'email_policy'    => self::EMAIL_SUPPRESS_ALL_CHILDREN,
			'status_policy'   => self::STATUS_PRESERVE,
			'target_status'   => '',
			'shipping_allocations' => array(),
		);
		$policies = wp_parse_args((array) $policies, $defaults);
		$shipping = array(self::SHIPPING_KEEP_ON_ORIGINAL, self::SHIPPING_MOVE_TO_CHILD, self::SHIPPING_PROPORTIONAL, self::SHIPPING_EXPLICIT_ALLOCATION, self::SHIPPING_ZERO_VALUE_REFERENCE);
		$tax = array(self::TAX_PRESERVE_HISTORICAL, self::TAX_RECALCULATE_EXPLICITLY);
		$email = array(self::EMAIL_SUPPRESS_ALL_CHILDREN, self::EMAIL_NOTIFY_ORIGINAL_ONLY, self::EMAIL_NOTIFY_CHILDREN_ONLY, self::EMAIL_NOTIFY_BOTH);
		$status = array(self::STATUS_PRESERVE, self::STATUS_PENDING, self::STATUS_EXPLICIT_TARGET);
		if (!in_array($policies['shipping_policy'], $shipping, true) || !in_array($policies['tax_policy'], $tax, true) || !in_array($policies['email_policy'], $email, true) || !in_array($policies['status_policy'], $status, true)) {
			throw new WC_Order_Splitter_Mutation_Exception(__('An unsupported order mutation policy was requested.', 'wc-order-splitter'));
		}
		if (self::STATUS_EXPLICIT_TARGET === $policies['status_policy']) {
			$target = 'wc-' . sanitize_key(str_replace('wc-', '', $policies['target_status']));
			$statuses = wc_get_order_statuses();
			if (!isset($statuses[$target])) {
				throw new WC_Order_Splitter_Mutation_Exception(__('The requested target order status does not exist.', 'wc-order-splitter'));
			}
			$policies['target_status'] = str_replace('wc-', '', $target);
		}
		return $policies;
	}

	private function build_line_allocations($order, $plan) {
		$allocations = array();
		foreach ($order->get_items('line_item') as $item_id => $item) {
			$weights = array();
			$allocated = 0;
			foreach ($plan as $destination => $items) {
				if (isset($items[$item_id])) {
					$weights[$destination] = (float) $items[$item_id];
					$allocated += (float) $items[$item_id];
				}
			}
			$remaining = (float) $item->get_quantity() - $allocated;
			if ($remaining > 0) {
				$weights['original'] = $remaining;
			}
			if (empty($weights)) {
				continue;
			}

			$subtotals = WC_Order_Splitter_Mutation_Support::allocate_scalar($item->get_subtotal(), $weights);
			$subtotal_taxes = WC_Order_Splitter_Mutation_Support::allocate_scalar($item->get_subtotal_tax(), $weights);
			$totals = WC_Order_Splitter_Mutation_Support::allocate_scalar($item->get_total(), $weights);
			$total_taxes = WC_Order_Splitter_Mutation_Support::allocate_scalar($item->get_total_tax(), $weights);
			$taxes = WC_Order_Splitter_Mutation_Support::allocate_tax_array($item->get_taxes(), $weights);
			$reduced_stock = $item->get_meta('_reduced_stock', true);
			$reduced_allocations = ('' !== $reduced_stock && null !== $reduced_stock)
				? WC_Order_Splitter_Mutation_Support::allocate_scalar((float) $reduced_stock, $weights, 6)
				: array();

			foreach ($weights as $destination => $quantity) {
				$allocations[$item_id][$destination] = array(
					'props' => array(
						'quantity'     => $quantity,
						'subtotal'     => $subtotals[$destination],
						'subtotal_tax' => $subtotal_taxes[$destination],
						'total'        => $totals[$destination],
						'total_tax'    => $total_taxes[$destination],
						'taxes'        => $taxes[$destination],
					),
					'reduced_stock' => isset($reduced_allocations[$destination]) ? $reduced_allocations[$destination] : null,
				);
			}
		}
		return $allocations;
	}

	private function destination_weights($allocations) {
		$weights = array();
		foreach ($allocations as $destinations) {
			foreach ($destinations as $destination => $data) {
				if (!isset($weights[$destination])) {
					$weights[$destination] = 0;
				}
				$weights[$destination] += (float) $data['props']['quantity'];
			}
		}
		return $weights;
	}

	private function build_coupon_allocations($order, $weights) {
		$result = array();
		foreach ($order->get_items('coupon') as $coupon_id => $coupon) {
			$discounts = WC_Order_Splitter_Mutation_Support::allocate_scalar($coupon->get_discount(), $weights);
			$discount_taxes = WC_Order_Splitter_Mutation_Support::allocate_scalar($coupon->get_discount_tax(), $weights);
			foreach ($weights as $destination => $weight) {
				$result[$destination][$coupon_id] = array(
					'discount' => isset($discounts[$destination]) ? $discounts[$destination] : 0,
					'discount_tax' => isset($discount_taxes[$destination]) ? $discount_taxes[$destination] : 0,
				);
			}
		}
		return $result;
	}

	private function build_shipping_allocations($order, $weights, $policies) {
		$result = array();
		$policy = $policies['shipping_policy'];
		$child_destinations = array_values(array_diff(array_keys($weights), array('original')));

		foreach ($order->get_items('shipping') as $shipping_id => $shipping) {
			if (self::SHIPPING_KEEP_ON_ORIGINAL === $policy) {
				$result['original'][$shipping_id] = array('total' => $shipping->get_total(), 'taxes' => $shipping->get_taxes());
				continue;
			}
			if (self::SHIPPING_ZERO_VALUE_REFERENCE === $policy) {
				$result['original'][$shipping_id] = array('total' => $shipping->get_total(), 'taxes' => $shipping->get_taxes());
				foreach ($child_destinations as $destination) {
					$result[$destination][$shipping_id] = array('total' => 0, 'taxes' => WC_Order_Splitter_Mutation_Support::zero_tax_array_like($shipping->get_taxes()));
				}
				continue;
			}
			if (self::SHIPPING_MOVE_TO_CHILD === $policy) {
				if (1 !== count($child_destinations)) {
					throw new WC_Order_Splitter_Mutation_Exception(__('Moving all shipping to a child requires exactly one split order.', 'wc-order-splitter'));
				}
				$result[$child_destinations[0]][$shipping_id] = array('total' => $shipping->get_total(), 'taxes' => $shipping->get_taxes());
				continue;
			}
			if (self::SHIPPING_PROPORTIONAL === $policy) {
				$totals = WC_Order_Splitter_Mutation_Support::allocate_scalar($shipping->get_total(), $weights);
				$taxes = WC_Order_Splitter_Mutation_Support::allocate_tax_array($shipping->get_taxes(), $weights);
				foreach ($weights as $destination => $weight) {
					$result[$destination][$shipping_id] = array('total' => $totals[$destination], 'taxes' => $taxes[$destination]);
				}
				continue;
			}
			if (self::SHIPPING_EXPLICIT_ALLOCATION === $policy) {
				$explicit = isset($policies['shipping_allocations'][$shipping_id]) ? (array) $policies['shipping_allocations'][$shipping_id] : array();
				if (empty($explicit)) {
					throw new WC_Order_Splitter_Mutation_Exception(__('Explicit shipping allocation is missing for a shipping line.', 'wc-order-splitter'));
				}
				$total = 0;
				foreach ($explicit as $destination => $data) {
					if (!isset($weights[$destination])) {
						throw new WC_Order_Splitter_Mutation_Exception(__('Explicit shipping allocation references an unknown destination.', 'wc-order-splitter'));
					}
					$result[$destination][$shipping_id] = array(
						'total' => WC_Order_Splitter_Mutation_Support::decimal(isset($data['total']) ? $data['total'] : 0),
						'taxes' => isset($data['taxes']) ? $data['taxes'] : array('total' => array()),
					);
					$total += (float) $result[$destination][$shipping_id]['total'];
				}
				if (abs($total - (float) $shipping->get_total()) > pow(10, -WC_Order_Splitter_Mutation_Support::decimals())) {
					throw new WC_Order_Splitter_Mutation_Exception(__('Explicit shipping allocation does not conserve the shipping total.', 'wc-order-splitter'));
				}
			}
		}
		return $result;
	}

	private function apply_original_line_allocations($order, $allocations) {
		foreach ($order->get_items('line_item') as $item_id => $item) {
			if (!isset($allocations[$item_id])) {
				continue;
			}
			if (!isset($allocations[$item_id]['original'])) {
				$order->remove_item($item_id);
				continue;
			}
			$data = $allocations[$item_id]['original'];
			$item->set_props($data['props']);
			if (null !== $data['reduced_stock']) {
				$item->update_meta_data('_reduced_stock', $data['reduced_stock']);
			} else {
				$item->delete_meta_data('_reduced_stock');
			}
			$item->save();
		}
	}

	private function apply_original_coupon_allocations($order, $allocations) {
		foreach ($order->get_items('coupon') as $coupon_id => $coupon) {
			if (!isset($allocations['original'][$coupon_id])) {
				$order->remove_item($coupon_id);
				continue;
			}
			$coupon->set_props($allocations['original'][$coupon_id]);
			$coupon->save();
		}
	}

	private function apply_original_shipping_allocations($order, $allocations) {
		foreach ($order->get_items('shipping') as $shipping_id => $shipping) {
			if (!isset($allocations['original'][$shipping_id])) {
				$order->remove_item($shipping_id);
				continue;
			}
			$shipping->set_props($allocations['original'][$shipping_id]);
			$shipping->update_meta_data(WC_Order_Splitter_Mutation_Support::META_ITEMS_SUMMARY, $this->items_summary($order));
			$shipping->save();
		}
	}

	private function set_child_status($source_order, $child, $policies) {
		$status = $source_order->get_status();
		if (self::STATUS_PENDING === $policies['status_policy']) {
			$status = 'pending';
		} elseif (self::STATUS_EXPLICIT_TARGET === $policies['status_policy']) {
			$status = $policies['target_status'];
		}
		if ('pending' === $status) {
			return;
		}

		$callback = null;
		$hooks = array();
		if (in_array($policies['email_policy'], array(self::EMAIL_SUPPRESS_ALL_CHILDREN, self::EMAIL_NOTIFY_ORIGINAL_ONLY), true) && function_exists('WC') && WC()->mailer()) {
			$child_id = $child->get_id();
			$callback = function($enabled, $object = null) use ($child_id) {
				if ($object instanceof WC_Order && $object->get_id() === $child_id) {
					return false;
				}
				return $enabled;
			};
			foreach (WC()->mailer()->get_emails() as $email) {
				if (!empty($email->id)) {
					$hook = 'woocommerce_email_enabled_' . $email->id;
					add_filter($hook, $callback, 999, 2);
					$hooks[] = $hook;
				}
			}
		}

		try {
			$child->set_status($status);
			$child->save();
		} finally {
			if ($callback) {
				foreach ($hooks as $hook) {
					remove_filter($hook, $callback, 999);
				}
			}
		}
	}

	private function calculate_order_totals($order, $tax_policy) {
		if (self::TAX_RECALCULATE_EXPLICITLY === $tax_policy) {
			$order->calculate_taxes();
		}
		$order->calculate_totals(false);
	}

	private function rebuild_tax_items($order, $templates) {
		foreach ($order->get_items('tax') as $tax_id => $tax_item) {
			$order->remove_item($tax_id);
		}

		$cart = array();
		$shipping = array();
		foreach (array('line_item', 'fee') as $type) {
			foreach ($order->get_items($type) as $item) {
				$taxes = $item->get_taxes();
				foreach ((array) (isset($taxes['total']) ? $taxes['total'] : array()) as $rate_id => $amount) {
					if (!isset($cart[$rate_id])) {
						$cart[$rate_id] = 0;
					}
					$cart[$rate_id] = WC_Order_Splitter_Mutation_Support::decimal($cart[$rate_id] + $amount);
				}
			}
		}
		foreach ($order->get_items('shipping') as $item) {
			$taxes = $item->get_taxes();
			foreach ((array) (isset($taxes['total']) ? $taxes['total'] : array()) as $rate_id => $amount) {
				if (!isset($shipping[$rate_id])) {
					$shipping[$rate_id] = 0;
				}
				$shipping[$rate_id] = WC_Order_Splitter_Mutation_Support::decimal($shipping[$rate_id] + $amount);
			}
		}

		$rate_ids = array_unique(array_merge(array_keys($cart), array_keys($shipping)));
		foreach ($rate_ids as $rate_id) {
			$tax_total = isset($cart[$rate_id]) ? $cart[$rate_id] : 0;
			$shipping_total = isset($shipping[$rate_id]) ? $shipping[$rate_id] : 0;
			if (abs((float) $tax_total) < 0.000001 && abs((float) $shipping_total) < 0.000001) {
				continue;
			}
			if (isset($templates[$rate_id])) {
				$tax_item = $this->cloner->clone_tax($templates[$rate_id], array(
					'tax_total' => $tax_total,
					'shipping_tax_total' => $shipping_total,
				));
			} else {
				$tax_item = new WC_Order_Item_Tax();
				$tax_item->set_rate_id($rate_id);
				$tax_item->set_label(is_callable(array('WC_Tax', 'get_rate_label')) ? WC_Tax::get_rate_label($rate_id) : __('Tax', 'wc-order-splitter'));
				$tax_item->set_compound(false);
				$tax_item->set_tax_total($tax_total);
				$tax_item->set_shipping_tax_total($shipping_total);
			}
			$order->add_item($tax_item);
		}
	}

	private function tax_templates($orders) {
		$templates = array();
		foreach ($orders as $order) {
			foreach ($order->get_items('tax') as $item) {
				$rate_id = $item->get_rate_id();
				if (!isset($templates[$rate_id])) {
					$templates[$rate_id] = $item;
				}
			}
		}
		return $templates;
	}

	private function merge_product_items($target, $source) {
		$target->set_quantity((float) $target->get_quantity() + (float) $source->get_quantity());
		$target->set_subtotal(WC_Order_Splitter_Mutation_Support::decimal((float) $target->get_subtotal() + (float) $source->get_subtotal()));
		$target->set_subtotal_tax(WC_Order_Splitter_Mutation_Support::decimal((float) $target->get_subtotal_tax() + (float) $source->get_subtotal_tax()));
		$target->set_total(WC_Order_Splitter_Mutation_Support::decimal((float) $target->get_total() + (float) $source->get_total()));
		$target->set_total_tax(WC_Order_Splitter_Mutation_Support::decimal((float) $target->get_total_tax() + (float) $source->get_total_tax()));
		$target->set_taxes(WC_Order_Splitter_Mutation_Support::add_tax_arrays($target->get_taxes(), $source->get_taxes()));

		$target_reduced = $target->get_meta('_reduced_stock', true);
		$source_reduced = $source->get_meta('_reduced_stock', true);
		if ('' !== $target_reduced || '' !== $source_reduced) {
			$target->update_meta_data('_reduced_stock', (float) $target_reduced + (float) $source_reduced);
		}
		$target->save();
	}

	private function write_split_relations($source_order, $created_orders, $operation_id) {
		$child_ids = array();
		foreach ($created_orders as $child) {
			$child_ids[] = $child->get_id();
			$child->update_meta_data(WC_Order_Splitter_Mutation_Support::META_OPERATION_ID, $operation_id);
			$child->update_meta_data(WC_Order_Splitter_Mutation_Support::META_ORIGINAL_ID, $source_order->get_id());
			$child->update_meta_data('yoos_original_order', $source_order->get_id());
			$child->save_meta_data();
		}
		$source_order->update_meta_data('_wc_order_splitter_children', $child_ids);
		$source_order->update_meta_data('yoos_splitted_order', implode(',', $child_ids));
		$source_order->save_meta_data();
	}

	private function groups_to_plan($groups, $error_message) {
		if (count($groups) <= 1) {
			throw new WC_Order_Splitter_Mutation_Exception($error_message);
		}
		$keys = array_keys($groups);
		array_shift($keys);
		$plan = array();
		foreach ($keys as $key) {
			$plan[$key] = $groups[$key];
		}
		return $plan;
	}

	private function items_summary_for_destination($order, $allocations, $destination) {
		$parts = array();
		foreach ($allocations as $item_id => $destinations) {
			if (!isset($destinations[$destination])) {
				continue;
			}
			$item = $order->get_item($item_id);
			if ($item) {
				$parts[] = sprintf('%s × %s', $item->get_name(), wc_format_decimal($destinations[$destination]['props']['quantity'], 6));
			}
		}
		return implode(', ', $parts);
	}

	private function items_summary($order) {
		$parts = array();
		foreach ($order->get_items('line_item') as $item) {
			$parts[] = sprintf('%s × %s', $item->get_name(), wc_format_decimal($item->get_quantity(), 6));
		}
		return implode(', ', $parts);
	}

	private function get_idempotent_result($order, $type, $key) {
		$meta_key = '_wc_order_splitter_idempotency_' . hash('sha256', sanitize_text_field($key));
		$data = $order->get_meta($meta_key, true);
		if (!is_array($data) || $type !== (isset($data['type']) ? $data['type'] : '') || empty($data['order_ids'])) {
			return null;
		}
		foreach ((array) $data['order_ids'] as $order_id) {
			if (!wc_get_order($order_id)) {
				return null;
			}
		}
		return array('operation_id' => isset($data['operation_id']) ? $data['operation_id'] : '', 'new_order_ids' => array_map('absint', $data['order_ids']), 'idempotent_replay' => true);
	}

	private function store_idempotent_result($order, $type, $key, $order_ids, $operation_id) {
		$meta_key = '_wc_order_splitter_idempotency_' . hash('sha256', sanitize_text_field($key));
		$order->update_meta_data($meta_key, array(
			'type' => $type,
			'order_ids' => array_map('absint', $order_ids),
			'operation_id' => $operation_id,
			'created_at' => gmdate('c'),
		));
		$order->save_meta_data();
	}

	private function aggregate_order_totals($orders) {
		$totals = array();
		foreach ($orders as $order) {
			$totals = WC_Order_Splitter_Mutation_Support::add_amount_maps($totals, WC_Order_Splitter_Mutation_Support::order_totals($order));
		}
		return $totals;
	}

	private function normalized_address($address) {
		$normalized = array();
		foreach ((array) $address as $key => $value) {
			$normalized[$key] = is_scalar($value) ? trim((string) $value) : $value;
		}
		ksort($normalized);
		return wp_json_encode($normalized);
	}

	private function capture_physical_stock($order) {
		return $this->capture_physical_stock_for_orders(array($order));
	}

	private function capture_physical_stock_for_orders($orders) {
		$stock = array();
		foreach ($orders as $order) {
			foreach ($order->get_items('line_item') as $item) {
				$product = $item->get_product();
				if (!$product || !$product->managing_stock()) {
					continue;
				}
				$managed_id = method_exists($product, 'get_stock_managed_by_id') ? $product->get_stock_managed_by_id() : $product->get_id();
				$managed_product = wc_get_product($managed_id);
				if ($managed_product) {
					$stock[$managed_id] = $managed_product->get_stock_quantity();
				}
			}
		}
		ksort($stock);
		return $stock;
	}

	private function assert_physical_stock_unchanged($before, $order) {
		$this->assert_physical_stock_snapshot_unchanged($before);
	}

	private function assert_physical_stock_snapshot_unchanged($before) {
		foreach ($before as $product_id => $quantity) {
			$product = wc_get_product($product_id);
			$actual = $product ? $product->get_stock_quantity() : null;
			if ((string) $quantity !== (string) $actual) {
				throw new WC_Order_Splitter_Mutation_Exception(__('Physical product stock changed during an order mutation.', 'wc-order-splitter'), 0, array(
					'product_id' => $product_id,
					'before' => $quantity,
					'after' => $actual,
				));
			}
		}
	}

	private function delete_created_orders($orders) {
		$success = true;
		foreach ($orders as $order) {
			try {
				if ($order instanceof WC_Order) {
					$order->delete(true);
				}
			} catch (Throwable $error) {
				$success = false;
			}
		}
		return $success;
	}

	private function restore_order_from_snapshot($order, $snapshot) {
		try {
			foreach (array('line_item', 'shipping', 'fee', 'coupon', 'tax') as $type) {
				foreach ($order->get_items($type) as $item_id => $item) {
					$order->remove_item($item_id);
				}
			}
			foreach ($snapshot['items'] as $entry) {
				$item = $this->item_from_snapshot($entry);
				$order->add_item($item);
			}
			$this->calculate_order_totals($order, self::TAX_PRESERVE_HISTORICAL);
			$order->save();
			WC_Order_Splitter_Mutation_Support::set_stock_reduced($order, !empty($snapshot['stock_reduced']));
			return true;
		} catch (Throwable $error) {
			return false;
		}
	}

	private function item_from_snapshot($entry) {
		$type = isset($entry['type']) ? $entry['type'] : '';
		$props = isset($entry['props']) ? (array) $entry['props'] : array();
		if ('line_item' === $type) {
			$item = new WC_Order_Item_Product();
			$item->set_props(array_merge(array('name' => isset($entry['name']) ? $entry['name'] : ''), $props));
		} elseif ('shipping' === $type) {
			$item = new WC_Order_Item_Shipping();
			$item->set_props($props);
		} elseif ('fee' === $type) {
			$item = new WC_Order_Item_Fee();
			$item->set_name(isset($entry['name']) ? $entry['name'] : '');
			$item->set_props($props);
		} elseif ('coupon' === $type) {
			$item = new WC_Order_Item_Coupon();
			$item->set_props($props);
		} elseif ('tax' === $type) {
			$item = new WC_Order_Item_Tax();
			$item->set_props($props);
		} else {
			throw new WC_Order_Splitter_Mutation_Exception(__('Unsupported snapshot item type.', 'wc-order-splitter'));
		}
		foreach ((array) (isset($entry['meta']) ? $entry['meta'] : array()) as $meta) {
			if (isset($meta['key'])) {
				$item->add_meta_data($meta['key'], isset($meta['value']) ? $meta['value'] : null, false);
			}
		}
		return $item;
	}

	private function migrate_legacy_split_relation($child_order) {
		$original_id = absint($child_order->get_meta('yoos_original_order', true));
		if (!$original_id) {
			return array(null, array());
		}
		$source_order = wc_get_order($original_id);
		if (!$source_order) {
			return array(null, array());
		}
		$legacy_ids = array_filter(array_map('absint', explode(',', (string) $source_order->get_meta('yoos_splitted_order', true))));
		if (!in_array($child_order->get_id(), $legacy_ids, true)) {
			return array(null, array());
		}
		$snapshot = WC_Order_Splitter_Mutation_Support::capture_order_snapshot($source_order);
		$record = $this->journal->start($source_order, 'split', $snapshot, array('legacy_relation_migrated' => true));
		$record = $this->journal->complete($source_order, $record, $legacy_ids, array('legacy_relation_migrated' => true));
		foreach ($legacy_ids as $legacy_id) {
			$legacy_child = wc_get_order($legacy_id);
			if ($legacy_child) {
				$legacy_child->update_meta_data(WC_Order_Splitter_Mutation_Support::META_OPERATION_ID, $record['id']);
				$legacy_child->update_meta_data(WC_Order_Splitter_Mutation_Support::META_ORIGINAL_ID, $source_order->get_id());
				$legacy_child->save_meta_data();
			}
		}
		return array($source_order, $record);
	}
}
