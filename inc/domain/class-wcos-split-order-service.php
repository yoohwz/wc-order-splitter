<?php

defined('ABSPATH') || exit;

/**
 * Shared quantity-split saga.
 *
 * Safety policy:
 * - the frozen commercial policy either keeps shipping on the source or
 *   replicates every historical shipping row to every child;
 * - fees, coupons, refunds, and payment ownership remain on the source;
 * - legacy Manual authority keeps a positive residual on every affected line;
 * - current Manual and explicit server-built strategy authority may transfer a whole line;
 * - child orders inherit the exact frozen source status;
 * - historical amounts/tax arrays are allocated at currency precision;
 * - reduced-stock markers are redistributed without touching physical stock;
 * - persisted children are reusable after a crash, preventing duplicate retry output.
 */
final class WCOS_Split_Order_Service {

	const TYPE = 'split';
	const POLICY_VERSION = 3;
	const LEGACY_POLICY_VERSION = 2;
	const RELATION_PARENT_META = '_wcos_parent_order_id';
	const RELATION_CHILDREN_META = '_wcos_child_order_ids';
	const OPERATION_META = '_wcos_operation_id';
	const CHILD_KEY_META = '_wcos_split_child_key';

	public function split(WC_Order $source, array $plan, $operation_id, $execution_policy = 'partial_lines_only', array $operation_context = array()) {
		$operation_id = sanitize_key($operation_id);
		$execution_policy = WCOS_Split_Execution_Policy::normalize($execution_policy);
		$operation_context = $this->normalize_operation_context($operation_context, $execution_policy);
		if (WCOS_Split_Execution_Policy::allows_whole_line_transfer($execution_policy)
			&& (!class_exists('WCOS_Stock_Side_Effect_Guard') || !WCOS_Stock_Side_Effect_Guard::has_active_scope())) {
			throw new RuntimeException(__('Whole-line Split requires an active request-local stock side-effect guard.', 'wc-order-splitter'));
		}
		if ('' === $operation_id) {
			throw new InvalidArgumentException(__('A split operation ID is required.', 'wc-order-splitter'));
		}
		if (!$source->get_id()) {
			throw new InvalidArgumentException(__('The source order must be persisted before splitting.', 'wc-order-splitter'));
		}

		$canonical_plan = WCOS_Split_Plan::canonicalize_request($plan);
		if (isset($operation_context['manual_quantity_authority'])) {
			$canonical_plan = WCOS_Manual_Split_Quantity_Authority::assert_plan(
				$canonical_plan,
				$operation_context['manual_quantity_authority']
			);
		}
		$existing = WCOS_Operation_Journal::get($source, $operation_id);
		if (is_array($existing)) {
			$commercial_policy = WCOS_Split_Commercial_Policy::from_journal($existing);
		} else {
			$commercial_policy = isset($operation_context['commercial_policy'])
				? WCOS_Split_Commercial_Policy::assert_current($source, $operation_context['commercial_policy'])
				: WCOS_Split_Commercial_Policy::freeze($source);
		}
		WCOS_Split_Commercial_Policy::assert_plan($canonical_plan, $commercial_policy);
		$legacy_journal = is_array($existing)
			&& (!isset($existing['context']) || !is_array($existing['context']) || !array_key_exists('execution_policy', $existing['context']));
		if ($legacy_journal && WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY !== $execution_policy) {
			throw new RuntimeException(__('A legacy Split journal cannot be resumed under the whole-line transfer policy.', 'wc-order-splitter'));
		}

		$legacy_commercial_journal = is_array($existing)
			&& (empty($existing['context']['commercial_policy']) || !is_array($existing['context']['commercial_policy']));
		$fingerprint_context = array(
			'policy_version' => $legacy_commercial_journal ? self::LEGACY_POLICY_VERSION : self::POLICY_VERSION,
			'plan' => $canonical_plan,
			'shipping_policy' => $commercial_policy['shipping'],
			'fee_policy' => 'keep_on_source',
			'child_status' => $commercial_policy['child_status'],
		);
		if (!$legacy_commercial_journal) {
			$fingerprint_context['commercial_policy'] = $commercial_policy;
		}
		if (!$legacy_journal) {
			$fingerprint_context['execution_policy'] = $execution_policy;
		}
		if (isset($operation_context['strategy_authority'])) {
			$fingerprint_context['strategy_authority'] = $operation_context['strategy_authority'];
		}
		if (isset($operation_context['manual_quantity_authority'])) {
			$fingerprint_context['manual_quantity_authority'] = $operation_context['manual_quantity_authority'];
		}
		$fingerprint = WCOS_Mutation_Fingerprint::create(self::TYPE, $source->get_id(), $fingerprint_context);

		if (is_array($existing)) {
			WCOS_Operation_Journal::assert_fingerprint($existing, $fingerprint);
			$status = isset($existing['status']) ? sanitize_key((string) $existing['status']) : '';
			if ('manual_reconciliation' === $status) {
				throw new RuntimeException(__('This Split operation requires manual reconciliation before it can continue.', 'wc-order-splitter'));
			}
			if ('manual_reconciled' === $status) {
				throw new RuntimeException(__('This Split operation was manually reconciled and is closed.', 'wc-order-splitter'));
			}
			if ('completed' === $status) {
				return $this->load_completed_children($source, $operation_id, $canonical_plan, $existing);
			}
		}

		$source_id = $source->get_id();
		$lease_token = WCOS_Operation_Lock::acquire($source_id, $operation_id);
		if (false === $lease_token) {
			throw new RuntimeException(__('Another order mutation is already in progress for this order.', 'wc-order-splitter'));
		}

		try {
			$source = wc_get_order($source_id);
			if (!$source) {
				throw new RuntimeException(__('The source order is no longer available.', 'wc-order-splitter'));
			}

			$record = WCOS_Operation_Journal::get($source, $operation_id);
			if (is_array($record)) {
				WCOS_Operation_Journal::assert_fingerprint($record, $fingerprint);
				$record_policy = isset($record['context']['execution_policy'])
					? WCOS_Split_Execution_Policy::normalize($record['context']['execution_policy'])
					: WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY;
				if ($record_policy !== $execution_policy) {
					throw new RuntimeException(__('The requested Split execution policy does not match the durable operation journal.', 'wc-order-splitter'));
				}
				$recovered = $this->try_finalize_persisted($source, $operation_id, $canonical_plan, $record);
				if (is_array($recovered)) {
					return $recovered;
				}

				$expected_signature = isset($record['context']['source_signature']) ? (string) $record['context']['source_signature'] : '';
				$current_signature = WCOS_Order_Contract_Snapshot::source_signature($source);
				if ('' === $expected_signature || !hash_equals($expected_signature, $current_signature)) {
					if ($this->whole_line_source_deletion_persisted($source, $record)) {
						$this->mark_whole_line_manual_reconciliation($source, $operation_id, $record, 'source_changed_without_conserved_commit');
					} else {
						WCOS_Operation_Journal::require_recovery(
							$source,
							$operation_id,
							array('reason' => 'source_changed_without_conserved_commit')
						);
					}
					throw new RuntimeException(__('The source order changed after splitting started and the persisted state is not a conserved commit.', 'wc-order-splitter'));
				}

				if (!WCOS_Operation_Journal::resume($source, $operation_id, array('retry_started_at' => gmdate('c')))) {
					throw new RuntimeException(__('Unable to resume the split operation journal.', 'wc-order-splitter'));
				}
				$normalized_plan = isset($record['context']['plan']) && is_array($record['context']['plan'])
					? $record['context']['plan']
					: WCOS_Split_Plan::normalize($source, $canonical_plan, $execution_policy);
				$before_contract = isset($record['context']['before_contract']) && is_array($record['context']['before_contract'])
					? $record['context']['before_contract']
					: WCOS_Order_Contract_Snapshot::aggregate(array($source));
				$source_stock_reduced = !empty($record['context']['source_stock_reduced']);
				$fully_moved_item_ids = isset($record['context']['fully_moved_item_ids'])
					? array_values(array_unique(array_filter(array_map('absint', (array) $record['context']['fully_moved_item_ids']))))
					: array();
			} else {
				WCOS_Split_Commercial_Policy::assert_current($source, $commercial_policy);
				$this->assert_supported_source($source, $commercial_policy);
				$normalized_plan = WCOS_Split_Plan::normalize($source, $canonical_plan, $execution_policy);
				WCOS_Split_Commercial_Policy::assert_plan($normalized_plan, $commercial_policy);
				$fully_moved_item_ids = WCOS_Split_Plan::fully_moved_item_ids($source, $normalized_plan, $execution_policy);
				$before_contract = WCOS_Order_Contract_Snapshot::aggregate(array($source));
				$source_stock_reduced = (bool) $source->get_data_store()->get_stock_reduced($source_id);
				$context = array(
					'plan' => $normalized_plan,
					'child_keys' => WCOS_Split_Plan::child_keys($normalized_plan),
					'execution_policy' => $execution_policy,
					'fully_moved_item_ids' => $fully_moved_item_ids,
					'source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
					'before_contract' => $before_contract,
					'source_stock_reduced' => $source_stock_reduced,
					'commercial_policy' => $commercial_policy,
				);
				if (isset($operation_context['strategy_authority'])) {
					$context['strategy_authority'] = $operation_context['strategy_authority'];
				}
				if (isset($operation_context['manual_quantity_authority'])) {
					$context['manual_quantity_authority'] = $operation_context['manual_quantity_authority'];
				}
				if (!WCOS_Operation_Journal::start($source, $operation_id, self::TYPE, $context, $fingerprint)) {
					throw new RuntimeException(__('Unable to start the split operation journal.', 'wc-order-splitter'));
				}
			}

			if (!WCOS_Operation_Lock::is_owned($source_id, $lease_token)) {
				throw new RuntimeException(__('The split operation lease was lost before allocation.', 'wc-order-splitter'));
			}

			$this->assert_supported_source($source, $commercial_policy);
			$execution_stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
			$tax_templates = WCOS_Tax_Item_Synchronizer::templates($source);
			$expected_children = $this->build_mutation($source, $normalized_plan, $operation_id, $tax_templates, $execution_policy, $commercial_policy);
			$existing_children = $this->discover_children($source, $operation_id);
			$children = $this->persist_or_reuse_children(
				$source,
				$operation_id,
				$normalized_plan,
				$expected_children,
				$existing_children,
				$commercial_policy
			);

			$this->apply_relations($source, $children);
			$this->assert_split_contract($before_contract, array_merge(array($source), array_values($children)), $source, $commercial_policy, count($children));
			WCOS_Order_Contract_Snapshot::assert_product_stock_equal(
				$execution_stock_before,
				WCOS_Order_Contract_Snapshot::product_stock($source)
			);

			do_action('wcos_split_mutation_checkpoint', 'before_source_save', $source, $children, $operation_id);
			if (!WCOS_Operation_Lock::refresh($source_id, $lease_token)) {
				throw new RuntimeException(__('The split operation lease could not be refreshed before source commit.', 'wc-order-splitter'));
			}
			$source->save();

			$child_ids = $this->child_ids($children);
			if (!WCOS_Operation_Journal::checkpoint(
				$source,
				$operation_id,
				'source_persisted',
				array(
					'target_order_ids' => $child_ids,
					'source_signature_after' => WCOS_Order_Contract_Snapshot::source_signature($source),
				)
			)) {
				throw new RuntimeException(__('The source order was saved but its commit checkpoint could not be recorded.', 'wc-order-splitter'));
			}
			do_action('wcos_split_mutation_checkpoint', 'after_source_save', $source, $children, $operation_id);

			$this->synchronize_child_stock_flags($children, $source_stock_reduced);
			if (!WCOS_Operation_Journal::checkpoint($source, $operation_id, 'stock_flags_synchronized', array('target_order_ids' => $child_ids))) {
				throw new RuntimeException(__('Child stock-state flags were synchronized but their checkpoint could not be recorded.', 'wc-order-splitter'));
			}

			$source = wc_get_order($source_id);
			$children = $this->reload_children($children);
			$this->verify_persisted_split($source, $children, $normalized_plan, $operation_id, $before_contract, $source_stock_reduced, $fully_moved_item_ids, $commercial_policy);
			WCOS_Order_Contract_Snapshot::assert_product_stock_equal(
				$execution_stock_before,
				WCOS_Order_Contract_Snapshot::product_stock($source)
			);
			do_action('wcos_split_mutation_checkpoint', 'after_persisted_verify', $source, $children, $operation_id);

			if (!WCOS_Operation_Journal::mark_committed($source, $operation_id, array('target_order_ids' => $this->child_ids($children)))) {
				throw new RuntimeException(__('The split passed verification but its commit point could not be recorded.', 'wc-order-splitter'));
			}
			if (!WCOS_Operation_Journal::complete($source, $operation_id, array('target_order_ids' => $this->child_ids($children)))) {
				throw new RuntimeException(__('The split committed but its operation journal could not be finalized.', 'wc-order-splitter'));
			}

			$this->add_notes_best_effort($source, $children, $commercial_policy);
			return array_values($children);
		} catch (Throwable $throwable) {
			$fresh_source = wc_get_order($source_id);
			$record = $fresh_source ? WCOS_Operation_Journal::get($fresh_source, $operation_id) : null;
			if ($fresh_source && is_array($record)) {
				try {
					$recovered = $this->try_finalize_persisted($fresh_source, $operation_id, $canonical_plan, $record);
					if (is_array($recovered)) {
						return $recovered;
					}
				} catch (Throwable $recovery_error) {
					if ($this->whole_line_source_deletion_persisted($fresh_source, $record)) {
						$this->mark_whole_line_manual_reconciliation($fresh_source, $operation_id, $record, 'whole_line_recovery_verification_failed', $recovery_error);
					} else {
						WCOS_Operation_Journal::require_recovery(
							$fresh_source,
							$operation_id,
							array(
								'error' => $throwable->getMessage(),
								'recovery_error' => $recovery_error->getMessage(),
							)
						);
					}
					throw $recovery_error;
				}

				$expected_signature = isset($record['context']['source_signature']) ? (string) $record['context']['source_signature'] : '';
				if ('' !== $expected_signature && hash_equals($expected_signature, WCOS_Order_Contract_Snapshot::source_signature($fresh_source))) {
					WCOS_Operation_Journal::fail(
						$fresh_source,
						$operation_id,
						array(
							'error' => $throwable->getMessage(),
							'target_order_ids' => $this->child_ids($this->discover_children($fresh_source, $operation_id)),
						)
					);
				} elseif ($this->whole_line_source_deletion_persisted($fresh_source, $record)) {
					$this->mark_whole_line_manual_reconciliation($fresh_source, $operation_id, $record, 'whole_line_source_state_ambiguous', $throwable);
				} else {
					WCOS_Operation_Journal::require_recovery($fresh_source, $operation_id, array('error' => $throwable->getMessage()));
				}
			}
			throw $throwable;
		} finally {
			WCOS_Operation_Lock::release($source_id, $lease_token);
		}
	}

	private function normalize_operation_context(array $operation_context, $execution_policy) {
		foreach (array_keys($operation_context) as $key) {
			if (!in_array($key, array('strategy_authority', 'manual_quantity_authority', 'commercial_policy'), true)) {
				throw new InvalidArgumentException(__('Unknown Split operation authority context.', 'wc-order-splitter'));
			}
		}
		$has_strategy = array_key_exists('strategy_authority', $operation_context);
		$has_manual = array_key_exists('manual_quantity_authority', $operation_context);
		if ($has_strategy && $has_manual) {
			throw new InvalidArgumentException(__('A Split operation cannot carry both strategy and Manual quantity authority.', 'wc-order-splitter'));
		}
		$normalized = array();
		if (isset($operation_context['commercial_policy'])) {
			$normalized['commercial_policy'] = WCOS_Split_Commercial_Policy::assert_valid($operation_context['commercial_policy']);
		}
		if (!$has_strategy && !$has_manual) {
			return $normalized;
		}
		if ($has_strategy && (WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER !== $execution_policy
			|| !is_array($operation_context['strategy_authority'])
			|| empty($operation_context['strategy_authority']))) {
			throw new InvalidArgumentException(__('Semantic Split strategy authority requires the whole-line execution policy.', 'wc-order-splitter'));
		}
		if ($has_strategy) {
			$normalized['strategy_authority'] = $operation_context['strategy_authority'];
			return $normalized;
		}
		if (!is_array($operation_context['manual_quantity_authority'])) {
			throw new InvalidArgumentException(__('Manual quantity authority is malformed.', 'wc-order-splitter'));
		}
		$manual_authority = WCOS_Manual_Split_Quantity_Authority::assert_valid($operation_context['manual_quantity_authority']);
		$expected_policy = WCOS_Manual_Split_Quantity_Authority::execution_policy($manual_authority);
		if ($expected_policy !== $execution_policy) {
			throw new InvalidArgumentException(__('Manual quantity authority does not match the requested Split execution policy.', 'wc-order-splitter'));
		}
		$normalized['manual_quantity_authority'] = $manual_authority;
		return $normalized;
	}

	private function assert_supported_source(WC_Order $source, array $commercial_policy) {
		if (!$source->get_id() || 'shop_order' !== $source->get_type()) {
			throw new InvalidArgumentException(__('Only persisted WooCommerce shop orders can be split.', 'wc-order-splitter'));
		}
		WCOS_Split_Commercial_Policy::assert_source_supported($source, $commercial_policy);
		if (empty($source->get_items('line_item'))) {
			throw new RuntimeException(__('An order without product line items cannot be split.', 'wc-order-splitter'));
		}

		WCOS_Order_Totals_Rebuilder::assert_consistent($source, wc_get_price_decimals());
		$this->assert_stock_state_consistent($source);
	}

	private function assert_stock_state_consistent(WC_Order $source) {
		$order_reduced = (bool) $source->get_data_store()->get_stock_reduced($source->get_id());
		foreach ($source->get_items('line_item') as $item) {
			$quantity_units = WCOS_Decimal::to_units($item->get_quantity(), 6);
			$reduced = $item->get_meta('_reduced_stock', true);
			if ('' === $reduced) {
				continue;
			}
			$reduced_units = WCOS_Decimal::to_units($reduced, 6);
			if (!$order_reduced || $reduced_units < 0 || $reduced_units > $quantity_units) {
				throw new RuntimeException(__('The source order contains inconsistent reduced-stock markers.', 'wc-order-splitter'));
			}
		}
	}

	private function build_mutation(WC_Order $source, array $plan, $operation_id, array $tax_templates, $execution_policy, array $commercial_policy) {
		$precision = wc_get_price_decimals();
		$children = array();
		foreach (WCOS_Split_Plan::child_keys($plan) as $child_key) {
			$children[$child_key] = $this->build_child_shell($source, $operation_id, $child_key, $commercial_policy);
		}

		foreach ($source->get_items('line_item') as $item_id => $source_item) {
			$this->allocate_line($source, $source_item, (int) $item_id, $plan, $children, $precision, $execution_policy);
		}

		if (empty($source->get_items('line_item'))) {
			throw new RuntimeException(__('Whole-line Split may not remove every product line from the source order.', 'wc-order-splitter'));
		}
		if (WCOS_Split_Commercial_Policy::SHIPPING_REPLICATE_TO_EACH_CHILD === $commercial_policy['shipping']) {
			foreach ($children as $child) {
				foreach ($source->get_items('shipping') as $shipping_item) {
					$child->add_item(WCOS_Order_Item_Cloner::shipping($shipping_item, WCOS_Order_Item_Meta_Policy::CONTEXT_SPLIT));
				}
			}
		}

		WCOS_Tax_Item_Synchronizer::synchronize($source, $tax_templates, $precision, true);
		WCOS_Order_Totals_Rebuilder::rebuild($source, $precision);
		foreach ($children as $child) {
			WCOS_Tax_Item_Synchronizer::synchronize($child, $tax_templates, $precision, false);
			WCOS_Order_Totals_Rebuilder::rebuild($child, $precision);
		}

		return $children;
	}

	private function build_child_shell(WC_Order $source, $operation_id, $child_key, array $commercial_policy) {
		$child = new WC_Order();
		$result = $child->set_props(array(
			'status' => $commercial_policy['child_status'],
			'customer_id' => $source->get_customer_id(),
			'currency' => $source->get_currency(),
			'prices_include_tax' => $source->get_prices_include_tax(),
			'payment_method' => $source->get_payment_method(),
			'payment_method_title' => $source->get_payment_method_title(),
			'customer_note' => $source->get_customer_note(),
			'created_via' => 'wc-order-splitter-split',
		));
		if (is_wp_error($result)) {
			throw new RuntimeException($result->get_error_message());
		}
		if ('source_only' === $commercial_policy['payment']
			&& 'keep_on_source' === $commercial_policy['payment_transaction']) {
			/* Paid-class status setters may infer a paid date; Split children own neither. */
			$child->set_date_paid(null);
			$child->set_transaction_id('');
		}
		$child->set_address($source->get_address('billing'), 'billing');
		$child->set_address($source->get_address('shipping'), 'shipping');
		$child->update_meta_data(self::RELATION_PARENT_META, $source->get_id());
		$child->update_meta_data(self::OPERATION_META, $operation_id);
		$child->update_meta_data(self::CHILD_KEY_META, $child_key);
		$child->update_meta_data('yoos_original_order', $source->get_id());
		return $child;
	}

	private function allocate_line(WC_Order $source, WC_Order_Item_Product $source_item, $item_id, array $plan, array $children, $precision, $execution_policy) {
		$source_quantity_units = WCOS_Decimal::to_units($source_item->get_quantity(), 6);
		$weights = array();
		$split_units = 0;
		foreach ($plan as $child_key => $items) {
			if (!isset($items[$item_id])) {
				continue;
			}
			$units = WCOS_Decimal::to_units($items[$item_id], 6);
			$weights[$child_key] = WCOS_Decimal::from_units($units, 6);
			$split_units += $units;
		}
		if (0 === $split_units) {
			return;
		}

		$remaining_units = $source_quantity_units - $split_units;
		if ($remaining_units < 0) {
			throw new RuntimeException(__('A Split plan allocated more than the source line quantity.', 'wc-order-splitter'));
		}
		if (0 === $remaining_units && !WCOS_Split_Execution_Policy::allows_whole_line_transfer($execution_policy)) {
			throw new RuntimeException(__('The active Split policy does not allow a source line to be removed.', 'wc-order-splitter'));
		}
		if ($remaining_units > 0) {
			$weights = array_merge(array('source' => WCOS_Decimal::from_units($remaining_units, 6)), $weights);
		}

		$subtotals = WCOS_Amount_Allocator::allocate($source_item->get_subtotal(), $weights, $precision);
		$totals = WCOS_Amount_Allocator::allocate($source_item->get_total(), $weights, $precision);
		$tax_allocations = $this->allocate_tax_arrays($source_item->get_taxes(), $weights, $precision);
		$reduced_allocations = array();
		$reduced_stock = $source_item->get_meta('_reduced_stock', true);
		if ('' !== $reduced_stock) {
			$reduced_allocations = WCOS_Amount_Allocator::allocate($reduced_stock, $weights, 6);
		}

		foreach ($weights as $destination => $quantity) {
			if ('source' === $destination) {
				continue;
			}
			$taxes = $tax_allocations[$destination];
			$child_item = WCOS_Order_Item_Cloner::product(
				$source_item,
				array(
					'quantity' => $quantity,
					'subtotal' => $subtotals[$destination],
					'total' => $totals[$destination],
					'taxes' => $taxes,
					'subtotal_tax' => $this->sum_tax_bucket($taxes['subtotal'], $precision),
					'total_tax' => $this->sum_tax_bucket($taxes['total'], $precision),
				),
				false,
				WCOS_Order_Item_Meta_Policy::CONTEXT_SPLIT
			);
			$child_item->add_meta_data('_wcos_source_item_id', $item_id, true);
			$this->replace_reduced_stock($child_item, isset($reduced_allocations[$destination]) ? $reduced_allocations[$destination] : null);
			$children[$destination]->add_item($child_item);
		}

		if ($remaining_units > 0) {
			$source_taxes = $tax_allocations['source'];
			$source_result = $source_item->set_props(array(
				'quantity' => WCOS_Decimal::from_units($remaining_units, 6),
				'subtotal' => $subtotals['source'],
				'total' => $totals['source'],
				'taxes' => $source_taxes,
				'subtotal_tax' => $this->sum_tax_bucket($source_taxes['subtotal'], $precision),
				'total_tax' => $this->sum_tax_bucket($source_taxes['total'], $precision),
			));
			if (is_wp_error($source_result)) {
				throw new RuntimeException($source_result->get_error_message());
			}
			$this->replace_reduced_stock($source_item, isset($reduced_allocations['source']) ? $reduced_allocations['source'] : null);
			return;
		}

		/*
		 * WC_Order::remove_item() only marks this persisted item for deletion on
		 * the later source save. The product-stock lifecycle is deliberately not
		 * invoked here; the request-local stock guard remains the safety proof.
		 */
		if (false === $source->remove_item($item_id)) {
			throw new RuntimeException(__('The fully allocated source line could not be staged for removal.', 'wc-order-splitter'));
		}
	}

	private function allocate_tax_arrays(array $taxes, array $weights, $precision) {
		$allocations = array();
		foreach (array_keys($weights) as $destination) {
			$allocations[$destination] = array('subtotal' => array(), 'total' => array());
		}

		foreach (array('subtotal', 'total') as $bucket) {
			$values = isset($taxes[$bucket]) ? (array) $taxes[$bucket] : array();
			foreach ($values as $rate_id => $amount) {
				$parts = WCOS_Amount_Allocator::allocate($amount, $weights, $precision);
				foreach ($parts as $destination => $part) {
					$allocations[$destination][$bucket][$rate_id] = $part;
				}
			}
		}
		return $allocations;
	}

	private function sum_tax_bucket(array $values, $precision) {
		$units = 0;
		foreach ($values as $value) {
			$part = WCOS_Decimal::to_units($value, $precision);
			if ($part > 0 && $units > PHP_INT_MAX - $part) {
				throw new OverflowException('Line tax exceeds the supported integer range.');
			}
			if ($part < 0 && $units < -PHP_INT_MAX - $part) {
				throw new OverflowException('Line tax exceeds the supported integer range.');
			}
			$units += $part;
		}
		return WCOS_Decimal::from_units($units, $precision);
	}

	private function replace_reduced_stock(WC_Order_Item_Product $item, $value) {
		$item->delete_meta_data('_reduced_stock');
		if (null === $value || 0 === WCOS_Decimal::to_units($value, 6)) {
			return;
		}
		$item->add_meta_data('_reduced_stock', WCOS_Decimal::normalize($value, 6), true);
	}

	private function persist_or_reuse_children(WC_Order $source, $operation_id, array $plan, array $expected, array $existing, array $commercial_policy) {
		foreach ($existing as $child_key => $child) {
			if (!isset($expected[$child_key])) {
				throw new RuntimeException(__('An unexpected persisted child was found for this split operation.', 'wc-order-splitter'));
			}
		}

		$children = array();
		foreach (WCOS_Split_Plan::child_keys($plan) as $child_key) {
			if (isset($existing[$child_key])) {
				$this->verify_child_matches_expected($source, $existing[$child_key], $expected[$child_key], $operation_id, $child_key, $commercial_policy);
				$children[$child_key] = $existing[$child_key];
				continue;
			}

			$child = $expected[$child_key];
			do_action('wcos_split_mutation_checkpoint', 'before_child_save', $source, array($child_key => $child), $operation_id);
			$this->save_child_without_transactional_email($child);
			$child = wc_get_order($child->get_id());
			if (!$child) {
				throw new RuntimeException(__('A split child could not be reloaded after persistence.', 'wc-order-splitter'));
			}
			$this->verify_child_matches_expected($source, $child, $expected[$child_key], $operation_id, $child_key, $commercial_policy);
			$children[$child_key] = $child;

			if (!WCOS_Operation_Journal::checkpoint(
				$source,
				$operation_id,
				'child_persisted',
				array('target_order_ids' => $this->child_ids($children))
			)) {
				throw new RuntimeException(__('A split child was saved but its checkpoint could not be recorded.', 'wc-order-splitter'));
			}
			do_action('wcos_split_mutation_checkpoint', 'after_child_save', $source, $children, $operation_id);
		}

		return $children;
	}

	private function verify_child_matches_expected(WC_Order $source, WC_Order $child, WC_Order $expected, $operation_id, $child_key, array $commercial_policy) {
		if ((int) $child->get_meta(self::RELATION_PARENT_META, true) !== $source->get_id()
			|| (string) $child->get_meta(self::OPERATION_META, true) !== $operation_id
			|| (string) $child->get_meta(self::CHILD_KEY_META, true) !== $child_key) {
			throw new RuntimeException(__('A persisted split child has invalid operation relations.', 'wc-order-splitter'));
		}
		if ((string) $commercial_policy['child_status'] !== $child->get_status()) {
			throw new RuntimeException(__('A Split child does not have the frozen source status.', 'wc-order-splitter'));
		}
		if ('source_only' === $commercial_policy['payment']
			&& 'keep_on_source' === $commercial_policy['payment_transaction']
			&& (null !== $child->get_date_paid('edit') || '' !== (string) $child->get_transaction_id('edit'))) {
			throw new RuntimeException(__('A Split child unexpectedly owns payment transaction or paid-date evidence.', 'wc-order-splitter'));
		}
		$expected_shipping_count = WCOS_Split_Commercial_Policy::SHIPPING_REPLICATE_TO_EACH_CHILD === $commercial_policy['shipping']
			? count($source->get_items('shipping'))
			: 0;
		if ($expected_shipping_count !== count($child->get_items('shipping'))
			|| !empty($child->get_items('fee')) || !empty($child->get_items('coupon'))) {
			throw new RuntimeException(__('A Split child does not match frozen shipping, fee, or coupon ownership.', 'wc-order-splitter'));
		}
		if ($this->shipping_evidence($expected) !== $this->shipping_evidence($child)) {
			throw new RuntimeException(__('A Split child shipping row differs from the frozen historical shipping evidence.', 'wc-order-splitter'));
		}
		$this->assert_conserved(
			WCOS_Order_Contract_Snapshot::aggregate(array($expected)),
			array($child)
		);
	}

	private function apply_relations(WC_Order $source, array $children) {
		$child_ids = $this->child_ids($children);
		$current = array_filter(array_map('absint', (array) $source->get_meta(self::RELATION_CHILDREN_META, true)));
		$source->update_meta_data(self::RELATION_CHILDREN_META, array_values(array_unique(array_merge($current, $child_ids))));

		$legacy = array_filter(array_map('absint', explode(',', (string) $source->get_meta('yoos_splitted_order', true))));
		$source->update_meta_data('yoos_splitted_order', implode(',', array_values(array_unique(array_merge($legacy, $child_ids)))));
	}

	private function synchronize_child_stock_flags(array $children, $source_stock_reduced) {
		foreach ($children as $child) {
			$child->get_data_store()->set_stock_reduced($child->get_id(), (bool) $source_stock_reduced);
		}
	}

	private function try_finalize_persisted(WC_Order $source, $operation_id, array $canonical_plan, array $record) {
		$commercial_policy = WCOS_Split_Commercial_Policy::from_journal($record);
		$children = $this->discover_children($source, $operation_id);
		if (empty($children)) {
			return null;
		}
		$expected_keys = WCOS_Split_Plan::child_keys($canonical_plan);
		if (array_keys($children) !== $expected_keys) {
			return null;
		}
		$before_contract = isset($record['context']['before_contract']) && is_array($record['context']['before_contract'])
			? $record['context']['before_contract']
			: null;
		if (!$before_contract) {
			return null;
		}

		try {
			$this->assert_split_contract($before_contract, array_merge(array($source), array_values($children)), $source, $commercial_policy, count($children));
		} catch (Throwable $throwable) {
			return null;
		}

		$source_stock_reduced = !empty($record['context']['source_stock_reduced']);
		$fully_moved_item_ids = isset($record['context']['fully_moved_item_ids'])
			? array_values(array_unique(array_filter(array_map('absint', (array) $record['context']['fully_moved_item_ids']))))
			: array();
		$this->apply_relations($source, $children);
		$source->save_meta_data();
		$this->synchronize_child_stock_flags($children, $source_stock_reduced);
		$this->verify_persisted_split($source, $children, $canonical_plan, $operation_id, $before_contract, $source_stock_reduced, $fully_moved_item_ids, $commercial_policy);

		if (!WCOS_Operation_Journal::mark_committed($source, $operation_id, array('target_order_ids' => $this->child_ids($children), 'recovered' => true))) {
			throw new RuntimeException(__('Unable to record the recovered split commit point.', 'wc-order-splitter'));
		}
		if (!WCOS_Operation_Journal::complete($source, $operation_id, array('target_order_ids' => $this->child_ids($children), 'recovered' => true))) {
			throw new RuntimeException(__('Unable to finalize the recovered split operation.', 'wc-order-splitter'));
		}
		$this->add_notes_best_effort($source, $children, $commercial_policy);
		return array_values($children);
	}

	private function verify_persisted_split(WC_Order $source, array $children, array $plan, $operation_id, array $before_contract, $source_stock_reduced, array $fully_moved_item_ids = array(), array $commercial_policy = array()) {
		if (!$source) {
			throw new RuntimeException(__('The source order could not be reloaded after split persistence.', 'wc-order-splitter'));
		}
		if (array_keys($children) !== WCOS_Split_Plan::child_keys($plan)) {
			throw new RuntimeException(__('The persisted split child set does not match the normalized plan.', 'wc-order-splitter'));
		}
		if (empty($source->get_items('line_item'))) {
			throw new RuntimeException(__('The persisted source order no longer contains a product line.', 'wc-order-splitter'));
		}
		foreach ($fully_moved_item_ids as $source_item_id) {
			if ($source->get_item(absint($source_item_id)) instanceof WC_Order_Item_Product) {
				throw new RuntimeException(__('A whole-line Split source item remained persisted after full transfer.', 'wc-order-splitter'));
			}
		}
		$commercial_policy = empty($commercial_policy) ? WCOS_Split_Commercial_Policy::legacy() : WCOS_Split_Commercial_Policy::assert_valid($commercial_policy);
		$this->assert_split_contract($before_contract, array_merge(array($source), array_values($children)), $source, $commercial_policy, count($children));

		$relation_ids = array_filter(array_map('absint', (array) $source->get_meta(self::RELATION_CHILDREN_META, true)));
		foreach ($children as $child_key => $child) {
			if (!in_array($child->get_id(), $relation_ids, true)) {
				throw new RuntimeException(__('A split child is missing from the source relation graph.', 'wc-order-splitter'));
			}
			if ((int) $child->get_meta(self::RELATION_PARENT_META, true) !== $source->get_id()
				|| (string) $child->get_meta(self::OPERATION_META, true) !== $operation_id
				|| (string) $child->get_meta(self::CHILD_KEY_META, true) !== $child_key) {
				throw new RuntimeException(__('A split child has an invalid persisted relation graph.', 'wc-order-splitter'));
			}
			if ((string) $commercial_policy['child_status'] !== $child->get_status()) {
				throw new RuntimeException(__('A persisted Split child lost its frozen source status.', 'wc-order-splitter'));
			}
			$flag = (bool) $child->get_data_store()->get_stock_reduced($child->get_id());
			if ($flag !== (bool) $source_stock_reduced) {
				throw new RuntimeException(__('A split child has an inconsistent order-level stock flag.', 'wc-order-splitter'));
			}
		}
	}

	private function load_completed_children(WC_Order $source, $operation_id, array $canonical_plan, array $record) {
		$children = $this->discover_children($source, $operation_id);
		if (array_keys($children) !== WCOS_Split_Plan::child_keys($canonical_plan)) {
			throw new RuntimeException(__('The completed split journal no longer has its full child-order set.', 'wc-order-splitter'));
		}
		return array_values($children);
	}

	private function discover_children(WC_Order $source, $operation_id) {
		$orders = wc_get_orders(array(
			'limit' => -1,
			'return' => 'objects',
			'meta_query' => array(
				'relation' => 'AND',
				array('key' => self::OPERATION_META, 'value' => $operation_id),
				array('key' => self::RELATION_PARENT_META, 'value' => $source->get_id(), 'type' => 'NUMERIC'),
			),
		));
		$children = array();
		foreach ($orders as $child) {
			$child_key = sanitize_key((string) $child->get_meta(self::CHILD_KEY_META, true));
			if ('' === $child_key || isset($children[$child_key])) {
				throw new RuntimeException(__('Duplicate or missing split child keys require manual recovery.', 'wc-order-splitter'));
			}
			$children[$child_key] = $child;
		}
		ksort($children, SORT_STRING);
		return $children;
	}

	private function reload_children(array $children) {
		$reloaded = array();
		foreach ($children as $child_key => $child) {
			$order = wc_get_order($child->get_id());
			if (!$order) {
				throw new RuntimeException(__('A split child disappeared during persisted verification.', 'wc-order-splitter'));
			}
			$reloaded[$child_key] = $order;
		}
		return $reloaded;
	}

	private function assert_conserved(array $before_contract, array $orders) {
		$after_contract = WCOS_Order_Contract_Snapshot::aggregate($orders);
		WCOS_Mutation_Contract::assert_conserved($before_contract, $after_contract, wc_get_price_decimals());
	}

	private function assert_split_contract(array $before_contract, array $orders, WC_Order $source, array $commercial_policy, $child_count) {
		$expected = $before_contract;
		if (WCOS_Split_Commercial_Policy::SHIPPING_REPLICATE_TO_EACH_CHILD === $commercial_policy['shipping']) {
			$precision = wc_get_price_decimals();
			$multiplier = absint($child_count);
			$shipping_units = $this->checked_multiply(WCOS_Decimal::to_units($source->get_shipping_total(), $precision), $multiplier);
			$shipping_tax_units = $this->checked_multiply(WCOS_Decimal::to_units($source->get_shipping_tax(), $precision), $multiplier);
			$expected['shipping_total'] = WCOS_Decimal::from_units(
				$this->checked_add(WCOS_Decimal::to_units(isset($expected['shipping_total']) ? $expected['shipping_total'] : 0, $precision), $shipping_units),
				$precision
			);
			$expected['tax_total'] = WCOS_Decimal::from_units(
				$this->checked_add(WCOS_Decimal::to_units(isset($expected['tax_total']) ? $expected['tax_total'] : 0, $precision), $shipping_tax_units),
				$precision
			);
			$expected['grand_total'] = WCOS_Decimal::from_units(
				$this->checked_add(
					$this->checked_add(WCOS_Decimal::to_units(isset($expected['grand_total']) ? $expected['grand_total'] : 0, $precision), $shipping_units),
					$shipping_tax_units
				),
				$precision
			);
			foreach ($source->get_items('shipping') as $shipping_item) {
				$taxes = $shipping_item->get_taxes();
				foreach (isset($taxes['total']) ? (array) $taxes['total'] : (array) $taxes as $rate_id => $amount) {
					$rate_id = (string) $rate_id;
					if (!isset($expected['tax_by_rate'][$rate_id])) {
						$expected['tax_by_rate'][$rate_id] = array('cart' => '0', 'shipping' => '0');
					}
					$units = $this->checked_add(
						WCOS_Decimal::to_units($expected['tax_by_rate'][$rate_id]['shipping'], $precision),
						$this->checked_multiply(WCOS_Decimal::to_units($amount, $precision), $multiplier)
					);
					$expected['tax_by_rate'][$rate_id]['shipping'] = WCOS_Decimal::from_units($units, $precision);
				}
			}
		}
		$this->assert_conserved($expected, $orders);
	}

	private function checked_multiply($value, $multiplier) {
		$value = (int) $value;
		$multiplier = absint($multiplier);
		if (0 === $value || 0 === $multiplier) {
			return 0;
		}
		$minimum = -PHP_INT_MAX - 1;
		if (($value > 0 && $value > intdiv(PHP_INT_MAX, $multiplier))
			|| ($value < 0 && $value < intdiv($minimum, $multiplier))) {
			throw new OverflowException('Replicated shipping amount exceeds the supported integer range.');
		}
		return $value * $multiplier;
	}

	private function checked_add($left, $right) {
		$left = (int) $left;
		$right = (int) $right;
		$minimum = -PHP_INT_MAX - 1;
		if (($right > 0 && $left > PHP_INT_MAX - $right)
			|| ($right < 0 && $left < $minimum - $right)) {
			throw new OverflowException('Replicated shipping amount exceeds the supported integer range.');
		}
		return $left + $right;
	}

	private function save_child_without_transactional_email(WC_Order $child) {
		$operation_id = (string) $child->get_meta(self::OPERATION_META, true);
		$callback = static function($enabled, $object = null) use ($operation_id) {
			return $object instanceof WC_Order
				&& 'wc-order-splitter-split' === $object->get_created_via()
				&& $operation_id === (string) $object->get_meta(WCOS_Split_Order_Service::OPERATION_META, true)
				? false
				: $enabled;
		};
		$email_ids = array(
			'new_order',
			'cancelled_order',
			'failed_order',
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
		);
		foreach ($email_ids as $email_id) {
			add_filter('woocommerce_email_enabled_' . $email_id, $callback, PHP_INT_MAX, 2);
		}
		try {
			$child->save();
		} finally {
			foreach ($email_ids as $email_id) {
				remove_filter('woocommerce_email_enabled_' . $email_id, $callback, PHP_INT_MAX);
			}
		}
	}

	private function shipping_evidence(WC_Order $order) {
		$evidence = array();
		foreach ($order->get_items('shipping') as $item) {
			$evidence[] = array(
				'name' => (string) $item->get_name(),
				'method_title' => (string) $item->get_method_title(),
				'method_id' => (string) $item->get_method_id(),
				'instance_id' => absint($item->get_instance_id()),
				'total' => (string) $item->get_total(),
				'total_tax' => (string) $item->get_total_tax(),
				'taxes' => $item->get_taxes(),
				'meta' => WCOS_Order_Item_Meta_Policy::business_metadata($item),
			);
		}
		return $evidence;
	}

	private function child_ids(array $children) {
		$ids = array();
		foreach ($children as $child) {
			if ($child instanceof WC_Order && $child->get_id()) {
				$ids[] = $child->get_id();
			}
		}
		return array_values(array_unique(array_map('absint', $ids)));
	}

	private function whole_line_source_deletion_persisted(WC_Order $source, array $record) {
		$policy = isset($record['context']['execution_policy'])
			? WCOS_Split_Execution_Policy::normalize($record['context']['execution_policy'])
			: WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY;
		if (!WCOS_Split_Execution_Policy::allows_whole_line_transfer($policy)) {
			return false;
		}
		$item_ids = isset($record['context']['fully_moved_item_ids'])
			? array_values(array_unique(array_filter(array_map('absint', (array) $record['context']['fully_moved_item_ids']))))
			: array();
		if (empty($item_ids)) {
			return false;
		}
		foreach ($item_ids as $item_id) {
			if (!$source->get_item($item_id)) {
				return true;
			}
		}
		return false;
	}

	private function mark_whole_line_manual_reconciliation(WC_Order $source, $operation_id, array $record, $reason, $throwable = null) {
		if (!class_exists('WCOS_Manual_Reconciliation_Blocker') || !WCOS_Manual_Reconciliation_Blocker::block($source, $operation_id)) {
			return false;
		}
		$context = array(
			'reason' => sanitize_key((string) $reason),
			'workflow' => 'split',
			'execution_policy' => isset($record['context']['execution_policy']) ? $record['context']['execution_policy'] : '',
			'fully_moved_item_ids' => isset($record['context']['fully_moved_item_ids']) ? $record['context']['fully_moved_item_ids'] : array(),
			'automatic_compensation_allowed' => false,
		);
		if ($throwable) {
			$context['error'] = $throwable->getMessage();
		}
		return WCOS_Operation_Journal::mark_manual_reconciliation($source, $operation_id, $context);
	}

	private function add_notes_best_effort(WC_Order $source, array $children, array $commercial_policy) {
		try {
			$status = wc_get_order_status_name((string) $commercial_policy['child_status']);
			$numbers = array();
			foreach ($children as $child) {
				$numbers[] = '#' . $child->get_order_number();
				$child->add_order_note(
					sprintf(
						/* translators: 1: source order number, 2: preserved order status. */
						__('This order was split safely from order #%1$s with source status %2$s preserved.', 'wc-order-splitter'),
						$source->get_order_number(),
						$status
					)
				);
			}
			$source->add_order_note(
				sprintf(
					/* translators: 1: preserved order status, 2: comma-separated child order numbers. */
					__('Order split safely into %1$s children: %2$s', 'wc-order-splitter'),
					$status,
					implode(', ', $numbers)
				)
			);
		} catch (Throwable $throwable) {
			do_action('wcos_split_note_error', $throwable, $source, $children);
		}
	}
}
