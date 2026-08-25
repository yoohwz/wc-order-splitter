<?php

defined('ABSPATH') || exit;

/** Executes the transport-free Return saga while the production gate remains hard-off. */
final class WCOS_Return_Order_Service {

	const TYPE = 'return';
	const POLICY_VERSION = 1;

	public function return_order(WC_Order $child, $operation_id, $precision) {
		$operation_id = sanitize_key((string) $operation_id);
		$child_id = absint($child->get_id());
		$precision = WCOS_Price_Precision_Scope::validate($precision);
		if ('' === $operation_id || !$child_id || 'shop_order' !== $child->get_type()) {
			throw new InvalidArgumentException(__('Return requires a persisted Split child and an operation ID.', 'wc-order-splitter'));
		}

		$existing = WCOS_Operation_Journal::get($child, $operation_id);
		if (is_array($existing)) {
			return $this->replay_existing($child_id, $operation_id, $precision, $existing);
		}

		$report = WCOS_Return_Preflight::assert_supported($child, true);
		$plan = $report['return_plan'];
		if ((int) $plan['price_precision'] !== $precision) {
			throw new RuntimeException(__('Return price precision does not match its server-owned Split authority.', 'wc-order-splitter'));
		}
		$original_id = absint($plan['source_order_id']);
		$lease = WCOS_Multi_Order_Lease::acquire(array($child_id, $original_id), $operation_id);
		if (!$lease instanceof WCOS_Multi_Order_Lease) {
			throw new RuntimeException(__('Another mutation already owns a Return participant.', 'wc-order-splitter'));
		}
		$local_stock_guard = false;
		if (!WCOS_Stock_Side_Effect_Guard::has_active_scope()) {
			$local_stock_guard = WCOS_Stock_Side_Effect_Guard::begin($operation_id);
		}

		$record_started = false;
		try {
			$child = $this->load_order($child_id, 'child');
			$original = $this->load_order($original_id, 'original');
			$existing = WCOS_Operation_Journal::get($child, $operation_id);
			if (is_array($existing)) {
				return $this->replay_existing($child_id, $operation_id, $precision, $existing);
			}
			$lease->assert_owned();
			$locked_report = WCOS_Return_Preflight::assert_supported($child, true);
			$locked_plan = $locked_report['return_plan'];
			if ($original_id !== absint($locked_plan['source_order_id'])
				|| (int) $locked_plan['price_precision'] !== $precision
				|| !hash_equals(WCOS_Return_Plan::fingerprint($plan), WCOS_Return_Plan::fingerprint($locked_plan))) {
				throw new RuntimeException(__('The server-owned Return plan changed during locked preflight.', 'wc-order-splitter'));
			}
			$plan = $locked_plan;
			$lineage = $locked_report['lineage_authority'];
			$context = WCOS_Return_Journal_Context::create(
				$child,
				$original,
				$plan,
				$lineage,
				$lineage['source_evolution_authority']
			);
			$fingerprint = (string) $context['return_pair']['pair_fingerprint'];

			$this->event('before_journal_start', $child, $original, $operation_id);
			$this->lease_guard($lease);
			if (!WCOS_Operation_Journal::start($child, $operation_id, self::TYPE, $context, $fingerprint)) {
				$existing = WCOS_Operation_Journal::get($child, $operation_id);
				if (is_array($existing)) {
					return $this->replay_existing($child_id, $operation_id, $precision, $existing);
				}
				throw new RuntimeException(__('The authoritative Return journal could not be started.', 'wc-order-splitter'));
			}
			$record_started = true;
			$record = $this->checkpoint_state($child_id, $original_id, $operation_id, WCOS_Return_Recovery_State_Graph::ORIGINAL_STAGING, array(), array(), false);
			$snapshot = $record['context']['return_recovery_snapshot'];
			$this->event('after_durable_preparation', $child, $original, $operation_id);

			$original_templates = WCOS_Tax_Item_Synchronizer::templates($original);
			$child_templates = WCOS_Tax_Item_Synchronizer::templates($child);
			foreach (array_keys($child_templates) as $rate_id) {
				if (!isset($original_templates[$rate_id])) {
					throw new RuntimeException(__('A Return historical tax template disappeared from the original order.', 'wc-order-splitter'));
				}
			}
			$templates = $original_templates + $child_templates;
			$added_ids = array();
			$destination_ids = array();

			foreach ($plan['lines'] as $source_item_id => $line) {
				list($child, $original, $record) = $this->boundary($lease, $child_id, $original_id, $operation_id, 'before_original_line_write', $added_ids);
				$child_item = $child->get_item(absint($line['child_item_id']));
				$this->assert_plan_line($child_item, $line, $precision, true);
				if (WCOS_Return_Plan::DESTINATION_RESIDUAL_SOURCE_ITEM === $line['destination']) {
					$destination = $original->get_item(absint($line['destination_source_item_id']));
					if (!$destination instanceof WC_Order_Item_Product || (int) $destination->get_id() !== (int) $source_item_id) {
						throw new RuntimeException(__('A Return residual destination disappeared.', 'wc-order-splitter'));
					}
					$result = $destination->set_props(array(
						'quantity' => $this->add($destination->get_quantity(), $line['quantity'], 6),
						'subtotal' => $this->add($destination->get_subtotal(), $line['subtotal'], $precision),
						'total' => $this->add($destination->get_total(), $line['total'], $precision),
						'subtotal_tax' => $this->add($destination->get_subtotal_tax(), $line['subtotal_tax'], $precision),
						'total_tax' => $this->add($destination->get_total_tax(), $line['total_tax'], $precision),
						'taxes' => $this->add_taxes($destination->get_taxes(), $line['taxes'], $precision),
					));
					if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
					$destination->save();
					$destination_ids[$source_item_id] = absint($destination->get_id());
				} else {
					$destination = WCOS_Order_Item_Cloner::product($child_item, array(), false, WCOS_Order_Item_Meta_Policy::CONTEXT_RETURN);
					$destination->delete_meta_data('_reduced_stock');
					$original->add_item($destination);
					$original->save();
					if (!absint($destination->get_id())) { throw new RuntimeException(__('A fresh Return original line did not persist.', 'wc-order-splitter')); }
					$added_ids[] = absint($destination->get_id());
					$destination_ids[$source_item_id] = absint($destination->get_id());
				}
				$this->event('after_original_line_persistence', $child, $this->load_order($original_id, 'original'), $operation_id);
				$record = $this->checkpoint_state($child_id, $original_id, $operation_id, WCOS_Return_Recovery_State_Graph::ORIGINAL_STAGING, $added_ids, $destination_ids, false);
			}

			list($child, $original, $record) = $this->boundary($lease, $child_id, $original_id, $operation_id, 'before_original_money_tax_write', $added_ids);
			WCOS_Tax_Item_Synchronizer::synchronize($original, $templates, $precision, true, WCOS_Order_Item_Meta_Policy::CONTEXT_RETURN);
			foreach ($original->get_items('tax') as $tax_item) { $tax_item->save(); }
			WCOS_Order_Totals_Rebuilder::rebuild($original, $precision);
			$original->save();
			$record = $this->checkpoint_state($child_id, $original_id, $operation_id, WCOS_Return_Recovery_State_Graph::ORIGINAL_PERSISTED, $added_ids, $destination_ids, false);
			$this->event('after_original_persisted', $this->load_order($child_id, 'child'), $this->load_order($original_id, 'original'), $operation_id);

			foreach ($plan['lines'] as $line) {
				list($child, $original, $record) = $this->boundary($lease, $child_id, $original_id, $operation_id, 'before_child_ownership_write', $added_ids);
				$item = $child->get_item(absint($line['child_item_id']));
				$this->assert_plan_line($item, $line, $precision, false);
				$item->delete_meta_data('_reduced_stock');
				$item->save();
				$this->event('after_child_ownership_write', $this->load_order($child_id, 'child'), $original, $operation_id);
				$record = $this->checkpoint_state($child_id, $original_id, $operation_id, WCOS_Return_Recovery_State_Graph::CHILD_OWNERSHIP_NEUTRALIZING, $added_ids, $destination_ids, false);
			}
			list($child, $original, $record) = $this->boundary($lease, $child_id, $original_id, $operation_id, 'before_child_stock_flag_write', $added_ids);
			$child->get_data_store()->set_stock_reduced($child_id, false);
			$record = $this->checkpoint_state($child_id, $original_id, $operation_id, WCOS_Return_Recovery_State_Graph::CHILD_OWNERSHIP_NEUTRALIZED, $added_ids, $destination_ids, false);

			foreach ($plan['lines'] as $source_item_id => $line) {
				list($child, $original, $record) = $this->boundary($lease, $child_id, $original_id, $operation_id, 'before_original_ownership_write', $added_ids);
				$destination = $original->get_item(absint($destination_ids[$source_item_id]));
				if (!$destination instanceof WC_Order_Item_Product) { throw new RuntimeException(__('A Return ownership destination disappeared.', 'wc-order-splitter')); }
				$before = $destination->get_meta('_reduced_stock', true);
				$before = '' === $before || null === $before ? '0.000000' : WCOS_Decimal::normalize($before, 6);
				$child_reduced = null === $line['reduced_stock'] ? '0.000000' : $line['reduced_stock'];
				$combined = $this->add($before, $child_reduced, 6);
				$destination->delete_meta_data('_reduced_stock');
				if (0 !== WCOS_Decimal::to_units($combined, 6)) { $destination->add_meta_data('_reduced_stock', $combined, true); }
				$destination->save();
				$this->event('after_original_ownership_write', $child, $this->load_order($original_id, 'original'), $operation_id);
				$record = $this->checkpoint_state($child_id, $original_id, $operation_id, WCOS_Return_Recovery_State_Graph::ORIGINAL_OWNERSHIP_ACTIVATED, $added_ids, $destination_ids, false);
			}
			list($child, $original, $record) = $this->boundary($lease, $child_id, $original_id, $operation_id, 'before_original_stock_flag_write', $added_ids);
			$original->get_data_store()->set_stock_reduced($original_id, WCOS_Return_Recovery_Snapshot::has_active_operational_stock_ownership($this->load_order($original_id, 'original')));
			$record = $this->checkpoint_state($child_id, $original_id, $operation_id, WCOS_Return_Recovery_State_Graph::ORIGINAL_OWNERSHIP_ACTIVATED, $added_ids, $destination_ids, false);

			$child = $this->load_order($child_id, 'child');
			$original = $this->load_order($original_id, 'original');
			WCOS_Return_Recovery_Snapshot::assert_physical_stock_unchanged($snapshot, $child, $plan);
			list($child, $original, $record) = $this->boundary($lease, $child_id, $original_id, $operation_id, 'before_child_retirement', $added_ids);
			$this->event('before_non_force_child_retirement', $child, $original, $operation_id);
			$child->delete(false);
			$child = $this->load_order($child_id, 'child');
			if ('trash' !== $child->get_status()) { throw new RuntimeException(__('The approved non-force Return retirement did not archive the child.', 'wc-order-splitter')); }
			$this->event('after_non_force_child_retirement', $child, $original, $operation_id);
			$record = $this->checkpoint_state($child_id, $original_id, $operation_id, WCOS_Return_Recovery_State_Graph::CHILD_RETIRED, $added_ids, $destination_ids, true);

			$outcome = WCOS_Return_Compensator::recover($child, $this->load_order($original_id, 'original'), $record, $lease);
			if ('completed' !== $outcome) { throw new RuntimeException(__('The Return saga did not reach its verified completed state.', 'wc-order-splitter')); }
			$this->event('after_complete', $this->load_order($child_id, 'child'), $this->load_order($original_id, 'original'), $operation_id);
			return $this->completed_result($child_id, $original_id, $operation_id);
		} catch (Throwable $throwable) {
			$fresh_child = wc_get_order($child_id);
			$record = $fresh_child instanceof WC_Order ? WCOS_Operation_Journal::get($fresh_child, $operation_id) : null;
			if ($record_started && $fresh_child instanceof WC_Order && is_array($record)) {
				$status = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
				if (!in_array($status, array('completed', 'compensated', 'manual_reconciliation', 'manual_reconciled'), true)) {
					WCOS_Operation_Journal::require_recovery($fresh_child, $operation_id, array('error_code' => 'return_service_interrupted'));
				}
			}
			throw $throwable;
		} finally {
			if (false !== $local_stock_guard) { WCOS_Stock_Side_Effect_Guard::end($local_stock_guard); }
			$lease->release();
		}
	}

	private function replay_existing($child_id, $operation_id, $precision, array $record) {
		$pair = WCOS_Return_Journal_Context::pair_from_record($record);
		if (!is_array($pair) || (int) $pair['child_order_id'] !== (int) $child_id || (int) $pair['price_precision'] !== (int) $precision) {
			throw new RuntimeException(__('This Return operation ID is bound to different participant or precision authority.', 'wc-order-splitter'));
		}
		$status = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
		if ('completed' !== $status) {
			$child = $this->load_order($child_id, 'child');
			if (!WCOS_Operation_Journal::require_recovery($child, $operation_id, array('reason' => 'service_replay'))) {
				throw new RuntimeException(__('The existing Return saga could not dispatch recovery.', 'wc-order-splitter'));
			}
			$record = WCOS_Operation_Journal::get($this->load_order($child_id, 'child'), $operation_id);
			$status = is_array($record) && isset($record['status']) ? sanitize_key((string) $record['status']) : '';
		}
		if ('completed' !== $status) {
			throw new RuntimeException('manual_reconciliation' === $status
				? __('The Return pair requires manual reconciliation.', 'wc-order-splitter')
				: __('The previous Return attempt did not complete and cannot be restarted as a new saga.', 'wc-order-splitter'));
		}
		return $this->completed_result($child_id, $pair['original_order_id'], $operation_id);
	}

	private function completed_result($child_id, $original_id, $operation_id) {
		$child = $this->load_order($child_id, 'child');
		$record = WCOS_Operation_Journal::get($child, $operation_id);
		$pair = is_array($record) ? WCOS_Return_Journal_Context::pair_from_record($record) : null;
		if (!is_array($pair) || 'completed' !== sanitize_key((string) $record['status'])
			|| WCOS_Return_Recovery_State_Graph::COMPLETED !== WCOS_Return_Recovery_State_Graph::assert_record($record)
			|| (int) $pair['child_order_id'] !== (int) $child_id || (int) $pair['original_order_id'] !== (int) $original_id) {
			throw new RuntimeException(__('Completed Return authority failed replay verification.', 'wc-order-splitter'));
		}
		return WCOS_Return_Journal_Context::terminal_result_from_record($record);
	}

	private function checkpoint_state($child_id, $original_id, $operation_id, $state, array $added_ids, array $destination_ids, $forward) {
		$child = $this->load_order($child_id, 'child');
		$original = $this->load_order($original_id, 'original');
		$record = WCOS_Operation_Journal::get($child, $operation_id);
		$snapshot = is_array($record) && isset($record['context']['return_recovery_snapshot']) ? $record['context']['return_recovery_snapshot'] : array();
		if (empty($snapshot)) { throw new RuntimeException(__('The Return recovery snapshot disappeared before checkpointing.', 'wc-order-splitter')); }
		$added_ids = $this->ids($added_ids);
		ksort($destination_ids, SORT_NUMERIC);
		$context = array(
			'return_recovery_state' => sanitize_key((string) $state),
			'return_forward_repair_allowed' => (bool) $forward,
			'return_original_added_item_ids' => $added_ids,
			'return_destination_item_ids' => $destination_ids,
			'return_physical_stock_after_write' => false,
			'return_child_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'child', $child),
			'return_original_state_after' => WCOS_Return_Recovery_Snapshot::participant_checkpoint($snapshot, 'original', $original, $added_ids),
			'return_child_signature_after' => WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'child', $child),
			'return_original_signature_after' => WCOS_Return_Recovery_Snapshot::participant_signature($snapshot, 'original', $original, $added_ids),
		);
		if (!WCOS_Operation_Journal::checkpoint($child, $operation_id, 'return_service_checkpoint', $context)) {
			throw new RuntimeException(__('A durable Return service checkpoint could not be persisted.', 'wc-order-splitter'));
		}
		$record = WCOS_Operation_Journal::get($this->load_order($child_id, 'child'), $operation_id);
		WCOS_Return_Recovery_State_Graph::assert_record($record);
		return $record;
	}

	private function boundary(WCOS_Multi_Order_Lease $lease, $child_id, $original_id, $operation_id, $stage, array $added_ids) {
		$child = $this->load_order($child_id, 'child');
		$original = $this->load_order($original_id, 'original');
		$record = WCOS_Operation_Journal::get($child, $operation_id);
		if (!is_array($record)) { throw new RuntimeException(__('The Return journal disappeared before a commercial write.', 'wc-order-splitter')); }
		$this->event($stage, $child, $original, $operation_id);
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$snapshot = isset($context['return_recovery_snapshot']) ? $context['return_recovery_snapshot'] : array();
		$child_signature = isset($context['return_child_signature_after']) ? (string) $context['return_child_signature_after'] : '';
		$original_signature = isset($context['return_original_signature_after']) ? (string) $context['return_original_signature_after'] : '';
		if (empty($snapshot) || '' === $child_signature || '' === $original_signature) { throw new RuntimeException(__('Return durable participant checkpoint authority is incomplete.', 'wc-order-splitter')); }
		return WCOS_Return_Commit_Guard::assert_boundary(
			$lease, $child, $original, $record,
			$child_signature,
			$original_signature,
			$added_ids, 'none'
		);
	}

	private function assert_plan_line($item, array $line, $precision, $check_reduced_stock) {
		$current_reduced = $item instanceof WC_Order_Item_Product ? $item->get_meta('_reduced_stock', true) : null;
		$current_reduced = '' === $current_reduced || null === $current_reduced ? null : WCOS_Decimal::normalize($current_reduced, 6);
		if (!$item instanceof WC_Order_Item_Product
			|| (int) $item->get_id() !== (int) $line['child_item_id']
			|| (int) $line['product_id'] !== (int) $item->get_product_id()
			|| (int) $line['variation_id'] !== (int) $item->get_variation_id()
			|| (string) $line['tax_class'] !== (string) $item->get_tax_class()
			|| (string) $line['quantity'] !== WCOS_Decimal::normalize($item->get_quantity(), 6)
			|| (string) $line['subtotal'] !== WCOS_Decimal::normalize($item->get_subtotal(), $precision)
			|| (string) $line['subtotal_tax'] !== WCOS_Decimal::normalize($item->get_subtotal_tax(), $precision)
			|| (string) $line['total'] !== WCOS_Decimal::normalize($item->get_total(), $precision)
			|| (string) $line['total_tax'] !== WCOS_Decimal::normalize($item->get_total_tax(), $precision)
			|| !hash_equals(
				WCOS_Mutation_Fingerprint::create('return_plan_line_taxes', $item->get_id(), $line['taxes']),
				WCOS_Mutation_Fingerprint::create('return_plan_line_taxes', $item->get_id(), $this->add_taxes(array(), $item->get_taxes(), $precision))
			)
			|| ($check_reduced_stock && $line['reduced_stock'] !== $current_reduced)) {
			throw new RuntimeException(__('A Return child line changed after its server-owned plan was bound.', 'wc-order-splitter'));
		}
	}

	private function add($left, $right, $precision) {
		return WCOS_Decimal::from_units(WCOS_Decimal::to_units($left, $precision) + WCOS_Decimal::to_units($right, $precision), $precision);
	}

	private function add_taxes(array $left, array $right, $precision) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			$rate_ids = array_unique(array_merge(array_keys(isset($left[$bucket]) ? $left[$bucket] : array()), array_keys(isset($right[$bucket]) ? $right[$bucket] : array())));
			foreach ($rate_ids as $rate_id) {
				$result[$bucket][(int) $rate_id] = $this->add(isset($left[$bucket][$rate_id]) ? $left[$bucket][$rate_id] : '0', isset($right[$bucket][$rate_id]) ? $right[$bucket][$rate_id] : '0', $precision);
			}
			ksort($result[$bucket], SORT_NUMERIC);
		}
		return $result;
	}

	private function lease_guard(WCOS_Multi_Order_Lease $lease) {
		if (!$lease->refresh()) { throw new RuntimeException(__('A Return participant lease expired before journal start.', 'wc-order-splitter')); }
		WCOS_Stock_Side_Effect_Guard::assert_current_clean();
	}

	private function load_order($order_id, $role) {
		$order = wc_get_order(absint($order_id));
		if (!$order instanceof WC_Order || 'shop_order' !== $order->get_type()) {
			throw new RuntimeException('original' === $role ? __('The Return original is no longer available.', 'wc-order-splitter') : __('The Return child is no longer available.', 'wc-order-splitter'));
		}
		return $order;
	}

	private function ids(array $ids) { $ids = array_values(array_unique(array_filter(array_map('absint', $ids)))); sort($ids, SORT_NUMERIC); return $ids; }

	private function event($stage, WC_Order $child, WC_Order $original, $operation_id) {
		do_action('wcos_return_mutation_checkpoint', sanitize_key((string) $stage), $child, $original, sanitize_key((string) $operation_id));
	}
}
