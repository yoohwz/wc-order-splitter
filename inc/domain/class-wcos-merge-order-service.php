<?php

defined('ABSPATH') || exit;

/**
 * Executes the approved two-order Merge saga without exposing a transport.
 */
final class WCOS_Merge_Order_Service {

	const TYPE = 'merge';
	const POLICY_VERSION = 3;
	const PREVIOUS_POLICY_VERSION = 2;
	const LEGACY_POLICY_VERSION = 1;

	public function merge(WC_Order $source, WC_Order $target, $operation_id, $precision, array $confirmation_authority = array()) {
		$operation_id = sanitize_key((string) $operation_id);
		$source_id = absint($source->get_id());
		$target_id = absint($target->get_id());
		$precision = WCOS_Price_Precision_Scope::validate($precision);
		if ('' === $operation_id || !$source_id || !$target_id || $source_id === $target_id) {
			throw new InvalidArgumentException(__('Merge requires two distinct persisted orders and an operation ID.', 'wc-order-splitter'));
		}

		$existing = WCOS_Operation_Journal::get($source, $operation_id);
		if (is_array($existing)) {
			return $this->replay_existing($source_id, $target_id, $operation_id, $existing);
		}

		$lease = WCOS_Multi_Order_Lease::acquire(array($source_id, $target_id), $operation_id);
		if (!$lease instanceof WCOS_Multi_Order_Lease) {
			throw new RuntimeException(__('Another mutation already owns a Merge participant.', 'wc-order-splitter'));
		}
		$local_stock_guard = false;
		if (!WCOS_Stock_Side_Effect_Guard::has_active_scope()) {
			$local_stock_guard = WCOS_Stock_Side_Effect_Guard::begin($operation_id);
		}

		$record_started = false;
		try {
			$source = $this->load_order($source_id, 'source');
			$target = $this->load_order($target_id, 'target');
			$existing = WCOS_Operation_Journal::get($source, $operation_id);
			if (is_array($existing)) {
				return $this->replay_existing($source_id, $target_id, $operation_id, $existing);
			}

			$lease->assert_owned();
			$report = WCOS_Merge_Preflight::assert_supported($source, $target, $precision);
			$plan = WCOS_Merge_Plan::build($source, $target, $precision);
			if (!hash_equals(WCOS_Merge_Plan::fingerprint($report['plan']), WCOS_Merge_Plan::fingerprint($plan))) {
				throw new RuntimeException(__('The server-owned Merge plan changed during locked preflight.', 'wc-order-splitter'));
			}
			$journal_context = WCOS_Merge_Journal_Context::create_executable(
				$source,
				$target,
				$plan,
				$report['context_authority'],
				$precision
			);
			$journal_context['merge_plan'] = $plan;
			$fingerprint = (string) $journal_context['merge_pair']['pair_fingerprint'];
			if (!empty($confirmation_authority)) {
				$journal_context['merge_confirmation_authority'] = $this->assert_confirmation_authority(
					$confirmation_authority,
					$operation_id,
					$source_id,
					$target_id,
					$precision,
					$plan,
					$journal_context['merge_pair']
				);
			}

			$this->event('before_journal_start', $source, $target, $operation_id);
			$this->lease_guard($lease);
			if (!WCOS_Operation_Journal::start($source, $operation_id, self::TYPE, $journal_context, $fingerprint)) {
				$existing = WCOS_Operation_Journal::get($source, $operation_id);
				if (is_array($existing)) {
					return $this->replay_existing($source_id, $target_id, $operation_id, $existing);
				}
				throw new RuntimeException(__('The authoritative Merge journal could not be started.', 'wc-order-splitter'));
			}
			$record_started = true;
			$record = $this->checkpoint_state(
				$source_id,
				$target_id,
				$operation_id,
				WCOS_Merge_Recovery_State_Graph::NO_WRITE,
				array(),
				array(),
				false
			);
			WCOS_Merge_Journal_Context::assert_executable_policy($record);
			$snapshot = $record['context']['merge_recovery_snapshot'];
			$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);

			$this->event('before_target_write', $source, $target, $operation_id);
			$target_item_ids = array();
			$processed_line_count = 0;
			foreach ($plan['lines'] as $source_item_id => $line_authority) {
				list($source, $target, $record) = $this->boundary($lease, $source_id, $target_id, $operation_id, 'before_target_line_write');
				$source_item = $source->get_item((int) $source_item_id);
				$this->assert_plan_line($source_item, $line_authority, true, $precision);
				if ('coalesce' === sanitize_key((string) $line_authority['action'])) {
					$target_item = $target->get_item(absint($line_authority['target_item_id']));
					$this->assert_target_plan_line($target_item, $line_authority['target_before'], $precision);
					$this->apply_target_line_state($target_item, $line_authority['target_after']);
					$target_item->save();
					$this->assert_target_plan_line($target->get_item(absint($line_authority['target_item_id'])), $line_authority['target_after'], $precision);
				} else {
					$clone = WCOS_Order_Item_Cloner::product(
						$source_item,
						array(),
						true,
						WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE
					);
					$target->add_item($clone);
					$target->save();
					$target_item_id = absint($clone->get_id());
					if (!$target_item_id) {
						throw new RuntimeException(__('A fresh Merge target line did not persist.', 'wc-order-splitter'));
					}
					$target_item_ids[] = $target_item_id;
				}
				$processed_line_count++;
				$this->event(1 === $processed_line_count ? 'after_first_target_line_persistence' : 'after_target_line_persistence', $source, $target, $operation_id);
				$record = $this->checkpoint_state(
					$source_id,
					$target_id,
					$operation_id,
					WCOS_Merge_Recovery_State_Graph::TARGET_STAGING,
					$target_item_ids,
					array(),
					false
				);
				$this->event('after_target_line_checkpoint', $source, $target, $operation_id);
			}

			$source = $this->load_order($source_id, 'source');
			$target = $this->load_order($target_id, 'target');
			$this->event('after_all_target_lines_before_target_money', $source, $target, $operation_id);
			list($source, $target, $record) = $this->boundary($lease, $source_id, $target_id, $operation_id, 'before_target_money_tax_write');
			$tax_ids_before = array_map('intval', array_keys($target->get_items('tax')));
			$tax_template_policy = sanitize_key(isset($plan['tax_template_policy']) ? (string) $plan['tax_template_policy'] : '');
			if ('import_source_product_rates' === $tax_template_policy) {
				WCOS_Tax_Item_Synchronizer::synchronize(
					$target,
					WCOS_Tax_Item_Synchronizer::templates_for_rates($source, $plan['tax_template_rate_ids']),
					$precision,
					true,
					WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE,
					true
				);
			} elseif ('preserve_target_rows_only' !== $tax_template_policy) {
				throw new RuntimeException(__('The Merge tax-template policy is not executable.', 'wc-order-splitter'));
			}
			WCOS_Order_Totals_Rebuilder::rebuild($target, $precision);
			$target->save();
			$target = $this->load_order($target_id, 'target');
			WCOS_Merge_Financial_Authority::assert_current($source, $target, $plan['financial_authority']);
			$target_tax_item_ids = array_values(array_diff(array_map('intval', array_keys($target->get_items('tax'))), $tax_ids_before));
			sort($target_tax_item_ids, SORT_NUMERIC);
			$this->event('after_target_money_tax_persistence', $source, $target, $operation_id);
			$record = $this->checkpoint_state(
				$source_id,
				$target_id,
				$operation_id,
				WCOS_Merge_Recovery_State_Graph::TARGET_PERSISTED,
				$target_item_ids,
				$target_tax_item_ids,
				false
			);

			$source_before_flag = (bool) $snapshot['source']['order_stock_reduced'];
			$target_before_flag = (bool) $snapshot['target']['order_stock_reduced'];
			if ($source_before_flag !== (bool) $plan['source_order_stock_reduced']
				|| $target_before_flag !== (bool) $plan['target_order_stock_reduced']) {
				throw new RuntimeException(__('Merge stock-ownership flags changed after planning.', 'wc-order-splitter'));
			}
			foreach ($plan['lines'] as $source_item_id => $line_authority) {
				list($source, $target, $record) = $this->boundary($lease, $source_id, $target_id, $operation_id, 'before_source_line_ownership_write');
				$source_item = $source->get_item((int) $source_item_id);
				$this->assert_plan_line($source_item, $line_authority, false, $precision);
				if ('' !== (string) $source_item->get_meta('_reduced_stock', true)) {
					$source_item->delete_meta_data('_reduced_stock');
					$source_item->save();
				}
				$this->event('after_source_line_ownership_write', $source, $target, $operation_id);
				$record = $this->checkpoint_state(
					$source_id,
					$target_id,
					$operation_id,
					WCOS_Merge_Recovery_State_Graph::SOURCE_OWNERSHIP_MIGRATING,
					$target_item_ids,
					$target_tax_item_ids,
					false
				);
			}

			list($source, $target, $record) = $this->boundary($lease, $source_id, $target_id, $operation_id, 'before_source_order_stock_flag_write');
			$source->get_data_store()->set_stock_reduced($source_id, false);
			$record = $this->checkpoint_state($source_id, $target_id, $operation_id, WCOS_Merge_Recovery_State_Graph::SOURCE_OWNERSHIP_MIGRATING, $target_item_ids, $target_tax_item_ids, false);

			list($source, $target, $record) = $this->boundary($lease, $source_id, $target_id, $operation_id, 'before_target_order_stock_flag_write');
			$target->get_data_store()->set_stock_reduced($target_id, (bool) $plan['target_order_stock_reduced_after']);
			$record = $this->checkpoint_state($source_id, $target_id, $operation_id, WCOS_Merge_Recovery_State_Graph::SOURCE_OWNERSHIP_MIGRATED, $target_item_ids, $target_tax_item_ids, false);
			$source = $this->load_order($source_id, 'source');
			$target = $this->load_order($target_id, 'target');
			$this->event('after_ownership_migration_before_retirement', $source, $target, $operation_id);
			WCOS_Order_Contract_Snapshot::assert_product_stock_equal($stock_before, WCOS_Order_Contract_Snapshot::product_stock($source));

			list($source, $target, $record) = $this->boundary($lease, $source_id, $target_id, $operation_id, 'before_source_retirement');
			WCOS_Merge_Recovery_Snapshot::assert_archive_preserved($snapshot, $source);
			$this->event('before_non_force_source_retirement', $source, $target, $operation_id);
			$source->delete(false);
			$source = $this->load_order($source_id, 'source');
			if ('trash' !== $source->get_status()) {
				throw new RuntimeException(__('The approved non-force Merge retirement did not archive the source.', 'wc-order-splitter'));
			}
			WCOS_Merge_Recovery_Snapshot::assert_archive_preserved($snapshot, $source);
			$this->event('after_non_force_source_retirement', $source, $target, $operation_id);
			$record = $this->checkpoint_state(
				$source_id,
				$target_id,
				$operation_id,
				WCOS_Merge_Recovery_State_Graph::SOURCE_RETIRED,
				$target_item_ids,
				$target_tax_item_ids,
				true
			);

			$source = $this->load_order($source_id, 'source');
			$target = $this->load_order($target_id, 'target');
			$outcome = WCOS_Merge_Compensator::recover($source, $target, $record, $lease);
			if ('completed' !== $outcome) {
				throw new RuntimeException(__('The Merge saga did not reach its verified completed state.', 'wc-order-splitter'));
			}
			$this->event('after_complete', $source, $target, $operation_id);
			return $this->completed_result($source_id, $target_id, $operation_id);
		} catch (Throwable $throwable) {
			$fresh_source = wc_get_order($source_id);
			$record = $fresh_source instanceof WC_Order ? WCOS_Operation_Journal::get($fresh_source, $operation_id) : null;
			if ($record_started && $fresh_source instanceof WC_Order && is_array($record)) {
				$status = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
				if (!in_array($status, array('completed', 'compensated', 'manual_reconciliation', 'manual_reconciled'), true)) {
					WCOS_Operation_Journal::require_recovery($fresh_source, $operation_id, array('error_code' => 'merge_service_interrupted'));
				}
			}
			throw $throwable;
		} finally {
			if (false !== $local_stock_guard) {
				WCOS_Stock_Side_Effect_Guard::end($local_stock_guard);
			}
			$lease->release();
		}
	}

	private function replay_existing($source_id, $target_id, $operation_id, array $record) {
		$pair = WCOS_Merge_Journal_Context::assert_executable_policy($record);
		if ($pair['source_order_id'] !== $source_id || $pair['target_order_id'] !== $target_id) {
			throw new RuntimeException(__('This Merge operation ID is already bound to a different participant pair.', 'wc-order-splitter'));
		}
		$status = sanitize_key(isset($record['status']) ? (string) $record['status'] : '');
		if ('completed' !== $status) {
			$source = $this->load_order($source_id, 'source');
			if (!WCOS_Operation_Journal::require_recovery($source, $operation_id, array('reason' => 'service_replay'))) {
				throw new RuntimeException(__('The existing Merge saga could not dispatch recovery.', 'wc-order-splitter'));
			}
			$record = WCOS_Operation_Journal::get($this->load_order($source_id, 'source'), $operation_id);
			$status = is_array($record) && isset($record['status']) ? sanitize_key((string) $record['status']) : '';
		}
		if ('completed' !== $status) {
			throw new RuntimeException(
				'manual_reconciliation' === $status
					? __('The Merge pair requires manual reconciliation.', 'wc-order-splitter')
					: __('The previous Merge attempt did not complete and cannot be restarted as a new saga.', 'wc-order-splitter')
			);
		}
		return $this->completed_result($source_id, $target_id, $operation_id);
	}

	private function completed_result($source_id, $target_id, $operation_id) {
		$source = $this->load_order($source_id, 'source');
		$record = WCOS_Operation_Journal::get($source, $operation_id);
		$pair = is_array($record) ? WCOS_Merge_Journal_Context::assert_executable_policy($record) : null;
		if (!is_array($record) || !is_array($pair) || 'completed' !== sanitize_key((string) $record['status'])
			|| WCOS_Merge_Recovery_State_Graph::COMPLETED !== WCOS_Merge_Recovery_State_Graph::assert_record($record)
			|| (int) $pair['source_order_id'] !== (int) $source_id
			|| (int) $pair['target_order_id'] !== (int) $target_id) {
			throw new RuntimeException(__('Completed Merge authority failed replay verification.', 'wc-order-splitter'));
		}
		$result = WCOS_Merge_Journal_Context::terminal_result_from_record($record);
		unset($result['schema_version'], $result['result_fingerprint']);
		return $result;
	}

	private function checkpoint_state($source_id, $target_id, $operation_id, $state, array $target_item_ids, array $target_tax_item_ids, $forward) {
		$source = $this->load_order($source_id, 'source');
		$target = $this->load_order($target_id, 'target');
		$context = array(
			'merge_recovery_state' => sanitize_key((string) $state),
			'merge_source_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($source),
			'merge_target_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($target),
			'merge_source_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($source),
			'merge_target_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($target),
			'merge_target_item_ids' => $this->ids($target_item_ids),
			'merge_target_tax_item_ids' => $this->ids($target_tax_item_ids),
			'merge_forward_repair_allowed' => (bool) $forward,
			'merge_retirement_candidate' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'merge_physical_stock_after_write' => false,
		);
		if (!WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_service_checkpoint', $context)) {
			throw new RuntimeException(__('A durable Merge service checkpoint could not be persisted.', 'wc-order-splitter'));
		}
		$record = WCOS_Operation_Journal::get($this->load_order($source_id, 'source'), $operation_id);
		if (!is_array($record)) {
			throw new RuntimeException(__('The Merge service checkpoint could not be reloaded.', 'wc-order-splitter'));
		}
		WCOS_Merge_Recovery_State_Graph::assert_record($record);
		return $record;
	}

	private function boundary(WCOS_Multi_Order_Lease $lease, $source_id, $target_id, $operation_id, $stage) {
		$source = $this->load_order($source_id, 'source');
		$target = $this->load_order($target_id, 'target');
		$record = WCOS_Operation_Journal::get($source, $operation_id);
		if (!is_array($record)) {
			throw new RuntimeException(__('The Merge journal disappeared before a commercial write.', 'wc-order-splitter'));
		}
		$this->event($stage, $source, $target, $operation_id);
		$context = isset($record['context']) && is_array($record['context']) ? $record['context'] : array();
		$expected_source_signature = isset($context['merge_source_signature_after']) ? (string) $context['merge_source_signature_after'] : '';
		$expected_target_signature = isset($context['merge_target_signature_after']) ? (string) $context['merge_target_signature_after'] : '';
		$recovery_state = WCOS_Merge_Recovery_State_Graph::assert_record($record);
		$service_states = array(
			WCOS_Merge_Recovery_State_Graph::NO_WRITE,
			WCOS_Merge_Recovery_State_Graph::TARGET_STAGING,
			WCOS_Merge_Recovery_State_Graph::TARGET_PERSISTED,
			WCOS_Merge_Recovery_State_Graph::SOURCE_OWNERSHIP_MIGRATING,
			WCOS_Merge_Recovery_State_Graph::SOURCE_OWNERSHIP_MIGRATED,
		);
		if (!in_array($recovery_state, $service_states, true)) {
			throw new RuntimeException(__('The Merge service reached a write boundary outside its durable commercial stages.', 'wc-order-splitter'));
		}
		return WCOS_Merge_Commit_Guard::assert_boundary(
			$lease,
			$source,
			$target,
			$record,
			$expected_source_signature,
			$expected_target_signature,
			'none'
		);
	}

	private function assert_plan_line($item, array $authority, $check_reduced_stock = true, $precision = null) {
		$precision = WCOS_Price_Precision_Scope::validate($precision);
		if (!$item instanceof WC_Order_Item_Product
			|| (int) $item->get_id() !== (int) $authority['source_item_id']
			|| !hash_equals((string) $authority['line_identity'], WCOS_Line_Identity::from_item($item))
			|| (int) $authority['product_id'] !== (int) $item->get_product_id()
			|| (int) $authority['variation_id'] !== (int) $item->get_variation_id()
			|| (string) $authority['tax_class'] !== (string) $item->get_tax_class()
			|| !hash_equals((string) $authority['commercial_identity'], WCOS_Merge_Commercial_Policy::line_identity($item))
			|| (string) $authority['quantity'] !== WCOS_Decimal::normalize($item->get_quantity(), 6)
			|| WCOS_Decimal::to_units($authority['subtotal'], $precision) !== WCOS_Decimal::to_units($item->get_subtotal(), $precision)
			|| WCOS_Decimal::to_units($authority['subtotal_tax'], $precision) !== WCOS_Decimal::to_units($item->get_subtotal_tax(), $precision)
			|| WCOS_Decimal::to_units($authority['total'], $precision) !== WCOS_Decimal::to_units($item->get_total(), $precision)
			|| WCOS_Decimal::to_units($authority['total_tax'], $precision) !== WCOS_Decimal::to_units($item->get_total_tax(), $precision)
			|| self::tax_units($authority['taxes'], $precision) !== self::tax_units($item->get_taxes(), $precision)) {
			throw new RuntimeException(__('A Merge source line changed after its server-owned plan was bound.', 'wc-order-splitter'));
		}
		if ($check_reduced_stock) {
			$current = $item->get_meta('_reduced_stock', true);
			$current = '' === $current || null === $current ? null : WCOS_Decimal::normalize($current, 6);
			if ($authority['reduced_stock'] !== $current) {
				throw new RuntimeException(__('A Merge source reduced-stock marker changed after planning.', 'wc-order-splitter'));
			}
		}
	}

	private function assert_target_plan_line($item, array $authority, $precision) {
		$precision = WCOS_Price_Precision_Scope::validate($precision);
		if (!$item instanceof WC_Order_Item_Product
			|| !hash_equals((string) $authority['line_identity'], WCOS_Line_Identity::from_item($item))
			|| !hash_equals((string) $authority['commercial_identity'], WCOS_Merge_Commercial_Policy::line_identity($item))
			|| (int) $authority['product_id'] !== (int) $item->get_product_id()
			|| (int) $authority['variation_id'] !== (int) $item->get_variation_id()
			|| (string) $authority['tax_class'] !== (string) $item->get_tax_class()
			|| (string) $authority['quantity'] !== WCOS_Decimal::normalize($item->get_quantity(), 6)
			|| WCOS_Decimal::to_units($authority['subtotal'], $precision) !== WCOS_Decimal::to_units($item->get_subtotal(), $precision)
			|| WCOS_Decimal::to_units($authority['subtotal_tax'], $precision) !== WCOS_Decimal::to_units($item->get_subtotal_tax(), $precision)
			|| WCOS_Decimal::to_units($authority['total'], $precision) !== WCOS_Decimal::to_units($item->get_total(), $precision)
			|| WCOS_Decimal::to_units($authority['total_tax'], $precision) !== WCOS_Decimal::to_units($item->get_total_tax(), $precision)
			|| self::tax_units($authority['taxes'], $precision) !== self::tax_units($item->get_taxes(), $precision)) {
			throw new RuntimeException(__('A Merge target line changed after its coalescing authority was bound.', 'wc-order-splitter'));
		}
		$current = $item->get_meta('_reduced_stock', true);
		$current = '' === $current || null === $current ? null : WCOS_Decimal::normalize($current, 6);
		if ($authority['reduced_stock'] !== $current) {
			throw new RuntimeException(__('A Merge target reduced-stock marker changed after planning.', 'wc-order-splitter'));
		}
	}

	private function apply_target_line_state(WC_Order_Item_Product $item, array $authority) {
		$result = $item->set_props(array(
			'quantity' => $authority['quantity'],
			'subtotal' => $authority['subtotal'],
			'subtotal_tax' => $authority['subtotal_tax'],
			'total' => $authority['total'],
			'total_tax' => $authority['total_tax'],
			'taxes' => $authority['taxes'],
		));
		if (is_wp_error($result)) {
			throw new RuntimeException($result->get_error_message());
		}
		if (null === $authority['reduced_stock']) {
			$item->delete_meta_data('_reduced_stock');
		} else {
			$item->update_meta_data('_reduced_stock', $authority['reduced_stock']);
		}
	}

	private static function tax_units(array $taxes, $precision) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			foreach (isset($taxes[$bucket]) ? (array) $taxes[$bucket] : array() as $rate_id => $amount) {
				$result[$bucket][(int) $rate_id] = WCOS_Decimal::to_units($amount, $precision);
			}
			ksort($result[$bucket], SORT_NUMERIC);
		}
		return $result;
	}

	private function assert_confirmation_authority(array $authority, $operation_id, $source_id, $target_id, $precision, array $plan, array $pair) {
		$required = array(
			'confirmation_schema_version', 'operation_id', 'operator_user_id', 'source_order_id', 'target_order_id',
			'source_signature', 'target_signature', 'plan', 'plan_fingerprint', 'pair_fingerprint',
			'context_authority_fingerprint', 'price_precision', 'preflight_policy_version', 'plan_schema_version',
			'merge_service_policy_version', 'context_signature_version', 'retirement_policy_schema_version', 'retirement_policy',
		);
		foreach ($required as $field) {
			if (!array_key_exists($field, $authority)) {
				throw new RuntimeException(__('The Merge Confirmation authority is incomplete.', 'wc-order-splitter'));
			}
		}
		$pair_authority = isset($pair['authority']) && is_array($pair['authority']) ? $pair['authority'] : array();
		if (sanitize_key((string) $authority['operation_id']) !== $operation_id
			|| !absint($authority['operator_user_id'])
			|| absint($authority['source_order_id']) !== $source_id
			|| absint($authority['target_order_id']) !== $target_id
			|| (int) $authority['price_precision'] !== (int) $precision
			|| (int) $authority['merge_service_policy_version'] !== self::POLICY_VERSION
			|| (int) $authority['preflight_policy_version'] !== (int) WCOS_Merge_Preflight::POLICY_VERSION
			|| (int) $authority['plan_schema_version'] !== (int) WCOS_Merge_Plan::SCHEMA_VERSION
			|| (int) $authority['context_signature_version'] !== (int) WCOS_Merge_Context_Signature::SCHEMA_VERSION
			|| (int) $authority['retirement_policy_schema_version'] !== (int) WCOS_Merge_Retirement_Policy::SCHEMA_VERSION
			|| WCOS_Merge_Retirement_Policy::approved_identifier() !== sanitize_key((string) $authority['retirement_policy'])
			|| $authority['plan'] !== $plan
			|| !hash_equals((string) $authority['plan_fingerprint'], WCOS_Merge_Plan::fingerprint($plan))
			|| !hash_equals((string) $authority['pair_fingerprint'], (string) $pair['pair_fingerprint'])
			|| !hash_equals((string) $authority['source_signature'], (string) $pair_authority['source_signature'])
			|| !hash_equals((string) $authority['target_signature'], (string) $pair_authority['target_signature'])
			|| !hash_equals((string) $authority['context_authority_fingerprint'], (string) $pair_authority['context_authority_fingerprint'])) {
			throw new RuntimeException(__('The Merge Confirmation no longer matches locked server authority.', 'wc-order-splitter'));
		}
		return WCOS_Merge_Journal_Context::create_confirmation_handoff(
			array(
				'operation_id' => $operation_id,
				'operator_user_id' => absint($authority['operator_user_id']),
				'source_order_id' => $source_id,
				'target_order_id' => $target_id,
				'confirmation_schema_version' => absint($authority['confirmation_schema_version']),
				'merge_service_policy_version' => self::POLICY_VERSION,
				'preflight_policy_version' => WCOS_Merge_Preflight::POLICY_VERSION,
				'plan_schema_version' => WCOS_Merge_Plan::SCHEMA_VERSION,
				'plan_fingerprint' => sanitize_key((string) $authority['plan_fingerprint']),
				'context_signature_version' => WCOS_Merge_Context_Signature::SCHEMA_VERSION,
				'context_authority_fingerprint' => sanitize_key((string) $authority['context_authority_fingerprint']),
				'pair_fingerprint' => sanitize_key((string) $authority['pair_fingerprint']),
				'price_precision' => $precision,
				'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
				'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			),
			$pair
		);
	}

	private function lease_guard(WCOS_Multi_Order_Lease $lease) {
		if (!$lease->refresh()) {
			throw new RuntimeException(__('A Merge participant lease expired before journal start.', 'wc-order-splitter'));
		}
		WCOS_Stock_Side_Effect_Guard::assert_current_clean();
	}

	private function load_order($order_id, $role) {
		$order = wc_get_order(absint($order_id));
		if (!$order instanceof WC_Order || 'shop_order' !== $order->get_type()) {
			throw new RuntimeException('target' === $role
				? __('The Merge target is no longer available.', 'wc-order-splitter')
				: __('The Merge source is no longer available.', 'wc-order-splitter'));
		}
		return $order;
	}

	private function ids(array $ids) {
		$ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private function event($stage, WC_Order $source, WC_Order $target, $operation_id) {
		do_action('wcos_merge_mutation_checkpoint', sanitize_key((string) $stage), $source, $target, sanitize_key((string) $operation_id));
	}
}
