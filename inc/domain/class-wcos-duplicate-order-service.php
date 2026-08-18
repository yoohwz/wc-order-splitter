<?php

defined('ABSPATH') || exit;

/**
 * Duplicates an order without re-parenting persisted items or copying
 * stock-reduction/payment-transaction state.
 */
final class WCOS_Duplicate_Order_Service {

	const TYPE = 'duplicate';
	const POLICY_VERSION = 2;

	public function duplicate(WC_Order $source, $operation_id) {
		$this->assert_supported_source($source);
		$operation_id = sanitize_key($operation_id);
		if ('' === $operation_id) {
			throw new InvalidArgumentException(__('A duplicate operation ID is required.', 'wc-order-splitter'));
		}

		$source_id = $source->get_id();
		$fingerprint = WCOS_Mutation_Fingerprint::create(
			self::TYPE,
			$source_id,
			array(
				'policy_version' => self::POLICY_VERSION,
				'target_status' => 'pending',
				'copy_transaction_id' => false,
				'copy_reduced_stock' => false,
			)
		);

		$existing = WCOS_Operation_Journal::get($source, $operation_id);
		if (is_array($existing)) {
			WCOS_Operation_Journal::assert_fingerprint($existing, $fingerprint);
			if (isset($existing['status']) && 'completed' === $existing['status']) {
				$target = $this->load_target($source, $operation_id, $existing);
				if ($target) {
					$this->verify_target($source, $target, $existing);
					return $target;
				}
				throw new RuntimeException(__('The duplicate operation completed previously, but its target order is no longer available.', 'wc-order-splitter'));
			}
		}

		$lease_token = WCOS_Operation_Lock::acquire($source_id, $operation_id);
		if (false === $lease_token) {
			throw new RuntimeException(__('Another order mutation is already in progress for this order.', 'wc-order-splitter'));
		}

		$target = null;
		$target_persisted = false;
		try {
			$record = WCOS_Operation_Journal::get($source, $operation_id);
			if (is_array($record)) {
				WCOS_Operation_Journal::assert_fingerprint($record, $fingerprint);
				$target = $this->load_target($source, $operation_id, $record);
				if ($target) {
					$this->verify_target($source, $target, $record);
					if (!WCOS_Operation_Journal::mark_committed($source, $operation_id, array('target_order_id' => $target->get_id()))) {
						throw new RuntimeException(__('Unable to record the recovered duplicate commit point.', 'wc-order-splitter'));
					}
					if (!WCOS_Operation_Journal::complete($source, $operation_id, array('target_order_id' => $target->get_id(), 'recovered' => true))) {
						throw new RuntimeException(__('Unable to finalize the recovered duplicate operation.', 'wc-order-splitter'));
					}
					$this->add_notes_best_effort($source, $target);
					return $target;
				}

				if (isset($record['status']) && in_array($record['status'], array('committed', 'completed', 'recovery_required'), true)) {
					throw new RuntimeException(__('The duplicate journal indicates a persisted target, but no target order can be recovered.', 'wc-order-splitter'));
				}

				$expected_signature = isset($record['context']['source_signature']) ? (string) $record['context']['source_signature'] : '';
				if ('' === $expected_signature || !hash_equals($expected_signature, WCOS_Order_Contract_Snapshot::source_signature($source))) {
					WCOS_Operation_Journal::require_recovery($source, $operation_id, array('reason' => 'source_changed_before_retry'));
					throw new RuntimeException(__('The source order changed after the duplicate operation started; automatic retry is unsafe.', 'wc-order-splitter'));
				}

				if (!WCOS_Operation_Journal::resume($source, $operation_id, array('retry_started_at' => gmdate('c')))) {
					throw new RuntimeException(__('Unable to resume the duplicate operation journal.', 'wc-order-splitter'));
				}
			} else {
				$source_contract = WCOS_Order_Contract_Snapshot::aggregate(array($source));
				unset($source_contract['stock_reduced']);
				$context = array(
					'source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
					'source_contract' => $source_contract,
				);
				if (!WCOS_Operation_Journal::start($source, $operation_id, self::TYPE, $context, $fingerprint)) {
					throw new RuntimeException(__('Unable to start the duplicate operation journal.', 'wc-order-splitter'));
				}
			}

			if (!WCOS_Operation_Lock::is_owned($source_id, $lease_token)) {
				throw new RuntimeException(__('The duplicate operation lease was lost before target creation.', 'wc-order-splitter'));
			}

			$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
			$source_contract = WCOS_Order_Contract_Snapshot::aggregate(array($source));
			unset($source_contract['stock_reduced']);

			$target = $this->build_target($source, $operation_id);
			$target_contract = WCOS_Order_Contract_Snapshot::aggregate(array($target));
			unset($target_contract['stock_reduced']);
			WCOS_Mutation_Contract::assert_conserved($source_contract, $target_contract, wc_get_price_decimals());

			do_action('wcos_duplicate_mutation_checkpoint', 'before_target_save', $source, $target, $operation_id);
			$target->save();
			$target_persisted = true;

			if (!WCOS_Operation_Journal::checkpoint($source, $operation_id, 'target_persisted', array('target_order_id' => $target->get_id()))) {
				throw new RuntimeException(__('The duplicate target was saved but its checkpoint could not be recorded.', 'wc-order-splitter'));
			}
			do_action('wcos_duplicate_mutation_checkpoint', 'after_target_save', $source, $target, $operation_id);

			$target = wc_get_order($target->get_id());
			if (!$target) {
				throw new RuntimeException(__('The duplicate target could not be reloaded after persistence.', 'wc-order-splitter'));
			}

			$record = WCOS_Operation_Journal::get($source, $operation_id);
			$this->verify_target($source, $target, is_array($record) ? $record : array('context' => array('source_contract' => $source_contract)));
			WCOS_Order_Contract_Snapshot::assert_product_stock_equal($stock_before, WCOS_Order_Contract_Snapshot::product_stock($source));
			do_action('wcos_duplicate_mutation_checkpoint', 'after_target_verify', $source, $target, $operation_id);

			if (!WCOS_Operation_Journal::mark_committed($source, $operation_id, array('target_order_id' => $target->get_id()))) {
				throw new RuntimeException(__('The duplicate target passed verification but its commit point could not be recorded.', 'wc-order-splitter'));
			}
			if (!WCOS_Operation_Journal::complete($source, $operation_id, array('target_order_id' => $target->get_id()))) {
				throw new RuntimeException(__('The duplicate target committed but its operation journal could not be finalized.', 'wc-order-splitter'));
			}

			$this->add_notes_best_effort($source, $target);
			return $target;
		} catch (Throwable $throwable) {
			if ($target_persisted || ($target instanceof WC_Order && $target->get_id())) {
				WCOS_Operation_Journal::require_recovery(
					$source,
					$operation_id,
					array(
						'error' => $throwable->getMessage(),
						'target_order_id' => $target instanceof WC_Order ? $target->get_id() : 0,
					)
				);
			} else {
				WCOS_Operation_Journal::fail($source, $operation_id, array('error' => $throwable->getMessage()));
			}
			throw $throwable;
		} finally {
			WCOS_Operation_Lock::release($source_id, $lease_token);
		}
	}

	private function assert_supported_source(WC_Order $source) {
		if (!$source->get_id() || 'shop_order' !== $source->get_type()) {
			throw new InvalidArgumentException(__('Only persisted WooCommerce shop orders can be duplicated.', 'wc-order-splitter'));
		}
		if ('trash' === $source->get_status()) {
			throw new RuntimeException(__('Trashed orders cannot be duplicated.', 'wc-order-splitter'));
		}
		if ($source->get_total_refunded() != 0 || !empty($source->get_refunds())) {
			throw new RuntimeException(__('Refunded orders are not supported by the hardened duplicate engine.', 'wc-order-splitter'));
		}
		if (empty($source->get_items('line_item'))) {
			throw new RuntimeException(__('An order without product line items cannot be duplicated.', 'wc-order-splitter'));
		}
	}

	private function build_target(WC_Order $source, $operation_id) {
		$target = new WC_Order();
		$result = $target->set_props(array(
			'status' => 'pending',
			'customer_id' => $source->get_customer_id(),
			'currency' => $source->get_currency(),
			'prices_include_tax' => $source->get_prices_include_tax(),
			'discount_total' => $source->get_discount_total(),
			'discount_tax' => $source->get_discount_tax(),
			'shipping_total' => $source->get_shipping_total(),
			'shipping_tax' => $source->get_shipping_tax(),
			'cart_tax' => $source->get_cart_tax(),
			'total' => $source->get_total(),
			'total_tax' => $source->get_total_tax(),
			'payment_method' => $source->get_payment_method(),
			'payment_method_title' => $source->get_payment_method_title(),
			'customer_note' => $source->get_customer_note(),
			'created_via' => 'wc-order-splitter-duplicate',
		));
		if (is_wp_error($result)) {
			throw new RuntimeException($result->get_error_message());
		}
		$target->set_address($source->get_address('billing'), 'billing');
		$target->set_address($source->get_address('shipping'), 'shipping');

		foreach ($source->get_items('line_item') as $item) {
			$target->add_item(WCOS_Order_Item_Cloner::product($item, array(), false, WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE));
		}
		foreach ($source->get_items('shipping') as $item) {
			$target->add_item(WCOS_Order_Item_Cloner::shipping($item, WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE));
		}
		foreach ($source->get_items('fee') as $item) {
			$target->add_item(WCOS_Order_Item_Cloner::fee($item, WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE));
		}
		foreach ($source->get_items('tax') as $item) {
			$target->add_item(WCOS_Order_Item_Cloner::tax($item, WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE));
		}
		foreach ($source->get_items('coupon') as $item) {
			$target->add_item(WCOS_Order_Item_Cloner::coupon($item, WCOS_Order_Item_Meta_Policy::CONTEXT_DUPLICATE));
		}

		$target->update_meta_data('_wcos_duplicate_source_order', $source->get_id());
		$target->update_meta_data('_wcos_operation_id', $operation_id);
		return $target;
	}

	private function load_target(WC_Order $source, $operation_id, array $record) {
		$target_id = isset($record['context']['target_order_id']) ? absint($record['context']['target_order_id']) : 0;
		if ($target_id) {
			$target = wc_get_order($target_id);
			if ($target) {
				return $target;
			}
		}

		$orders = wc_get_orders(array(
			'limit' => 2,
			'return' => 'objects',
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key' => '_wcos_operation_id',
					'value' => $operation_id,
				),
				array(
					'key' => '_wcos_duplicate_source_order',
					'value' => $source->get_id(),
					'type' => 'NUMERIC',
				),
			),
		));
		if (count($orders) > 1) {
			WCOS_Operation_Journal::require_recovery($source, $operation_id, array('reason' => 'multiple_duplicate_targets'));
			throw new RuntimeException(__('Multiple duplicate targets were found for one operation ID.', 'wc-order-splitter'));
		}
		return !empty($orders) ? reset($orders) : false;
	}

	private function verify_target(WC_Order $source, WC_Order $target, array $record) {
		if ((int) $target->get_meta('_wcos_duplicate_source_order', true) !== $source->get_id()) {
			throw new RuntimeException(__('The recovered duplicate target does not reference the expected source order.', 'wc-order-splitter'));
		}
		if ('pending' !== $target->get_status()) {
			throw new RuntimeException(__('The hardened duplicate target must remain in pending status.', 'wc-order-splitter'));
		}
		if ('' !== (string) $target->get_transaction_id()) {
			throw new RuntimeException(__('Payment transaction state was copied to the duplicate target.', 'wc-order-splitter'));
		}
		if ((bool) $target->get_data_store()->get_stock_reduced($target->get_id())) {
			throw new RuntimeException(__('The duplicate target was incorrectly marked as stock-reduced.', 'wc-order-splitter'));
		}
		foreach ($target->get_items('line_item') as $item) {
			if ('' !== (string) $item->get_meta('_reduced_stock', true)) {
				throw new RuntimeException(__('A duplicate target line inherited reduced-stock state.', 'wc-order-splitter'));
			}
		}

		$source_contract = isset($record['context']['source_contract']) && is_array($record['context']['source_contract'])
			? $record['context']['source_contract']
			: WCOS_Order_Contract_Snapshot::aggregate(array($source));
		unset($source_contract['stock_reduced']);
		$target_contract = WCOS_Order_Contract_Snapshot::aggregate(array($target));
		unset($target_contract['stock_reduced']);
		WCOS_Mutation_Contract::assert_conserved($source_contract, $target_contract, wc_get_price_decimals());

		$source_item_ids = array();
		foreach (array('line_item', 'shipping', 'fee', 'tax', 'coupon') as $item_type) {
			$source_item_ids = array_merge($source_item_ids, array_keys($source->get_items($item_type)));
			foreach (array_keys($target->get_items($item_type)) as $target_item_id) {
				if ($target_item_id && in_array($target_item_id, $source_item_ids, true)) {
					throw new RuntimeException(__('A persisted order item was re-parented instead of cloned.', 'wc-order-splitter'));
				}
			}
		}
	}

	private function add_notes_best_effort(WC_Order $source, WC_Order $target) {
		try {
			$source->add_order_note(
				sprintf(
					/* translators: %s: duplicated order number. */
					__('Order duplicated safely as order #%s.', 'wc-order-splitter'),
					$target->get_order_number()
				)
			);
			$target->add_order_note(
				sprintf(
					/* translators: %s: source order number. */
					__('This order is a duplicate of order #%s. Stock-reduction and payment transaction state were not copied.', 'wc-order-splitter'),
					$source->get_order_number()
				)
			);
		} catch (Throwable $throwable) {
			do_action('wcos_duplicate_note_error', $throwable, $source, $target);
		}
	}
}
