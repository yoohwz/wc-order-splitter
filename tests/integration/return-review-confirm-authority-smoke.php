<?php

if (!defined('ABSPATH')) { exit(1); }

function wcos_return_authority_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

function wcos_return_authority_fixture($label, $strategy = 'manual_quantity') {
	$product = new WC_Product_Simple();
	$product->set_name('WCOS Return authority ' . $label);
	$product->set_regular_price('10.00'); $product->set_price('10.00'); $product->set_manage_stock(false);
	wcos_return_authority_assert($product->save() > 0, 'Return authority product fixture could not be saved.');
	$keep = new WC_Product_Simple();
	$keep->set_name('WCOS Return authority keep ' . $label);
	$keep->set_regular_price('3.00'); $keep->set_price('3.00'); $keep->set_manage_stock(false);
	wcos_return_authority_assert($keep->save() > 0, 'Return authority keep product could not be saved.');

	$original = wc_create_order();
	$original->set_status('pending'); $original->set_currency('USD'); $original->set_prices_include_tax(false);
	$original->set_billing_first_name('Private'); $original->set_billing_last_name('Return');
	$original->set_billing_email('return-authority-' . wp_generate_uuid4() . '@example.test');
	$original->set_billing_address_1('99 Private Return Street'); $original->set_payment_method('cod');
	$item_id = $original->add_product($product, 2);
	$keep_item_id = $original->add_product($keep, 1);
	$original->calculate_totals(false); $original->save();
	$split_operation = 'return-authority-split-' . wp_generate_uuid4();

	if ('manual_quantity' === $strategy) {
		$children = (new WCOS_Mutation_Gateway())->split(
			wc_get_order($original->get_id()),
			array('return-authority-child' => array($item_id => '1.000000')),
			$split_operation,
			2
		);
	} else {
		$children = (new WCOS_Split_WooCommerce_Adapter())->split(
			wc_get_order($original->get_id()),
			array('return-authority-child' => array($item_id => '2.000000')),
			$split_operation,
			2,
			WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER,
			array('strategy_authority' => array(
				'strategy' => $strategy,
				'planner_policy_version' => 'category' === $strategy ? WCOS_Category_Split_Planner::POLICY_VERSION : WCOS_Stock_Status_Split_Planner::POLICY_VERSION,
				'classification_fingerprint' => hash('sha256', 'return-authority-' . $strategy . '-' . $label),
				'source_bucket_key' => $strategy . '-source',
				'review_source_signature' => WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($original->get_id())),
			))
		);
	}
	wcos_return_authority_assert(1 === count($children), 'Return authority Split did not create exactly one child.');
	return array(
		'product_ids' => array($product->get_id(), $keep->get_id()),
		'original_id' => $original->get_id(),
		'child_id' => $children[0]->get_id(),
		'item_id' => $item_id,
		'keep_item_id' => $keep_item_id,
		'strategy' => $strategy,
		'review_ids' => array(),
		'operation_ids' => array(),
	);
}

function wcos_return_authority_request(array $fixture) {
	return array(
		'child_order_id' => $fixture['child_id'],
		'nonce' => wp_create_nonce('wcos_return_order_' . $fixture['child_id']),
	);
}

function wcos_return_authority_cleanup(array $fixture) {
	delete_option('wcos_manual_reconcile_block_' . absint($fixture['child_id']));
	delete_option('wcos_manual_reconcile_block_' . absint($fixture['original_id']));
	foreach (isset($fixture['review_ids']) ? $fixture['review_ids'] : array() as $review_id) {
		WCOS_Return_Review_Store::delete($review_id);
	}
	foreach (isset($fixture['operation_ids']) ? $fixture['operation_ids'] : array() as $operation_id) {
		WCOS_Return_Confirmation_Store::delete($operation_id);
		$child = wc_get_order($fixture['child_id']);
		if ($child instanceof WC_Order) { WCOS_Operation_Journal::delete($child, $operation_id); }
	}
	foreach (array($fixture['child_id'], $fixture['original_id']) as $order_id) {
		$order = wc_get_order($order_id);
		if ($order instanceof WC_Order) {
			$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
			foreach (is_array($summary) ? $summary : array() as $entry) {
				if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
			}
			$order->delete(true);
		}
	}
	foreach ($fixture['product_ids'] as $product_id) {
		$product = wc_get_product($product_id);
		if ($product instanceof WC_Product) { $product->delete(true); }
	}
}

function wcos_return_authority_transient($prefix, $id) {
	return $prefix . hash('sha256', sanitize_key((string) $id));
}

function wcos_return_authority_write_journal(WC_Order $child, $operation_id, array $record) {
	$key = 'wcos_mutation_op_' . hash('sha256', absint($child->get_id()) . '|' . sanitize_key((string) $operation_id));
	update_option($key, $record, false);
	wp_cache_delete($key, 'options');
}

function wcos_return_authority_freeze_recovery(WCOS_Return_Admin_Controller $controller, array $fixture, $operator_id) {
	$request = wcos_return_authority_request($fixture);
	$review = $controller->review_request($request);
	$confirm = $controller->confirm_request(array_merge($request, array(
		'review_id' => $review['review_id'],
		'review_token' => $review['review_token'],
	)));
	$confirmation = WCOS_Return_Confirmation_Store::verify(
		wc_get_order($fixture['child_id']),
		$confirm['operation_id'],
		$confirm['confirmation_token'],
		$operator_id
	);
	$interrupt = static function($stage) {
		if ('after_durable_preparation' === $stage) {
			throw new RuntimeException('deterministic-confirmed-return-recovery');
		}
	};
	remove_action('wcos_mutation_recovery_required', array('WCOS_Mutation_Recovery_Coordinator', 'handle'), 10);
	add_action('wcos_return_mutation_checkpoint', $interrupt, PHP_INT_MAX, 1);
	try {
		(new WCOS_Return_WooCommerce_Adapter())->return_order(
			wc_get_order($fixture['child_id']),
			$confirm['operation_id'],
			$confirmation['price_precision'],
			WCOS_Return_Confirmation_Store::operation_authority($confirmation)
		);
	} catch (Throwable $throwable) {
		// Expected after the confirmed journal and recovery snapshot are durable, before commercial writes.
	} finally {
		remove_action('wcos_return_mutation_checkpoint', $interrupt, PHP_INT_MAX);
		add_action('wcos_mutation_recovery_required', array('WCOS_Mutation_Recovery_Coordinator', 'handle'), 10, 3);
	}
	$journal = WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $confirm['operation_id']);
	wcos_return_authority_assert(is_array($journal) && 'recovery_required' === $journal['status'], 'Confirmed Return fixture did not freeze at recovery_required.');
	return array($confirm, $confirmation, $journal);
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_return_authority_assert(!empty($admins), 'Return authority smoke requires an administrator.');
$operator_id = absint($admins[0]);
wp_set_current_user($operator_id);
$controller = new WCOS_Return_Admin_Controller();
$fixtures = array();

wcos_return_authority_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::RETURN_ORDER), 'Production Return gate is not enabled.');
wcos_return_authority_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::BULK_RETURN), 'Bulk Return production gate drifted on.');
foreach (array(WCOS_Return_Admin_Controller::REVIEW_ACTION, WCOS_Return_Admin_Controller::CONFIRM_ACTION, WCOS_Return_Admin_Controller::EXECUTE_ACTION) as $action) {
	wcos_return_authority_assert(false !== has_action('wp_ajax_' . $action), 'Enabled Return AJAX hook is missing: ' . $action);
}

try {
	foreach (array('manual_quantity', 'category', 'stock_status') as $strategy) {
		$fixture = wcos_return_authority_fixture('strategy-' . $strategy, $strategy);
		$fixtures[] = $fixture;
		$index = count($fixtures) - 1;
		$request = wcos_return_authority_request($fixture);
		$review = $controller->review_request($request);
		$fixtures[$index]['review_ids'][] = $review['review_id'];
		wcos_return_authority_assert($strategy === $review['summary']['strategy'], 'Return Review lost exact Split strategy display authority.');
		wcos_return_authority_assert($fixture['original_id'] === $review['summary']['original']['id'], 'Return Review did not server-resolve the exact original.');
		$review_json = wp_json_encode($review);
		wcos_return_authority_assert(false === strpos($review_json, '@example.test') && false === strpos($review_json, 'Private Return Street'), 'Return Review payload exposed customer PII.');

		$review_record = get_transient(wcos_return_authority_transient('wcos_return_review_', $review['review_id']));
		wcos_return_authority_assert(is_array($review_record) && !empty($review_record['token_hash']), 'Return Review did not persist hash-only token authority.');
		wcos_return_authority_assert(false === strpos(wp_json_encode($review_record), $review['review_token']), 'Raw Return Review token was persisted.');

		$confirm = $controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
		$fixtures[$index]['operation_ids'][] = $confirm['operation_id'];
		wcos_return_authority_assert($fixture['original_id'] === $confirm['original_order_id'], 'Return Confirm changed server-resolved original authority.');
		$confirmation = WCOS_Return_Confirmation_Store::verify(wc_get_order($fixture['child_id']), $confirm['operation_id'], $confirm['confirmation_token'], $operator_id);
		$confirmation_again = WCOS_Return_Confirmation_Store::verify(wc_get_order($fixture['child_id']), $confirm['operation_id'], $confirm['confirmation_token'], $operator_id);
		wcos_return_authority_assert($confirmation['operation_id'] === $confirmation_again['operation_id'], 'Repeated Return Confirmation verification minted another operation.');

		$confirm_record = get_transient(wcos_return_authority_transient('wcos_return_confirm_', $confirm['operation_id']));
		wcos_return_authority_assert(is_array($confirm_record) && false === strpos(wp_json_encode($confirm_record), $confirm['confirmation_token']), 'Return Confirmation persisted its raw token.');
		wcos_return_authority_assert(false === strpos(wp_json_encode($confirm_record), '@example.test'), 'Return Confirmation persisted customer PII.');

		$execute_request = array_merge($request, array('operation_id' => $confirm['operation_id'], 'confirmation_token' => $confirm['confirmation_token']));
		$result = $controller->execute_request($execute_request);
		wcos_return_authority_assert('completed' === $result['status'], 'Enabled Return controller Execute did not complete: ' . $strategy);
		$child_after = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id']));
		$original_after = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id']));
		$replay = $controller->execute_request($execute_request);
		wcos_return_authority_assert($result === $replay, 'Enabled Return response-loss retry did not replay the exact terminal result.');
		wcos_return_authority_assert($child_after === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['child_id'])), 'Enabled Return replay repeated child commercial writes.');
		wcos_return_authority_assert($original_after === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fixture['original_id'])), 'Enabled Return replay repeated original commercial writes.');
		$journal = WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $confirm['operation_id']);
		wcos_return_authority_assert(is_array($journal) && 'completed' === $journal['status'], 'Enabled Return controller did not persist completed durable authority.');
	}

	$primary = wcos_return_authority_fixture('handoff', 'manual_quantity');
	$fixtures[] = $primary; $primary_index = count($fixtures) - 1;
	$request = wcos_return_authority_request($primary);
	$unexpected = $request; $unexpected['original_order_id'] = $primary['original_id'];
	$client_original_rejected = false;
	try { $controller->review_request($unexpected); }
	catch (WCOS_Return_Transport_Exception $exception) { $client_original_rejected = 'unexpected_field' === $exception->get_error_code(); }
	wcos_return_authority_assert($client_original_rejected, 'Client-supplied Return original authority was not rejected.');

	$review = $controller->review_request($request);
	$fixtures[$primary_index]['review_ids'][] = $review['review_id'];
	$wrong_token = false;
	try { WCOS_Return_Review_Store::verify(wc_get_order($primary['child_id']), $review['review_id'], 'wrong', $operator_id); }
	catch (WCOS_Return_Review_Exception $exception) { $wrong_token = 'invalid_token' === $exception->get_reason(); }
	wcos_return_authority_assert($wrong_token, 'Return Review accepted an invalid token.');
	$wrong_user = false;
	try { WCOS_Return_Review_Store::verify(wc_get_order($primary['child_id']), $review['review_id'], $review['review_token'], $operator_id + 99999); }
	catch (WCOS_Return_Review_Exception $exception) { $wrong_user = 'owner_mismatch' === $exception->get_reason(); }
	wcos_return_authority_assert($wrong_user, 'Return Review accepted the wrong operator.');

	$confirm = $controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
	$fixtures[$primary_index]['operation_ids'][] = $confirm['operation_id'];
	$replayed_review = false;
	try { $controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token']))); }
	catch (WCOS_Return_Transport_Exception $exception) { $replayed_review = in_array($exception->get_error_code(), array('review_expired', 'review_already_consumed'), true); }
	wcos_return_authority_assert($replayed_review, 'Consumed Return Review minted another Confirmation.');

	$wrong_confirmation = false;
	try { WCOS_Return_Confirmation_Store::verify(wc_get_order($primary['child_id']), $confirm['operation_id'], 'wrong', $operator_id); }
	catch (WCOS_Return_Confirmation_Exception $exception) { $wrong_confirmation = 'invalid_token' === $exception->get_reason(); }
	wcos_return_authority_assert($wrong_confirmation, 'Return Confirmation accepted an invalid token.');
	$confirmed = WCOS_Return_Confirmation_Store::verify(wc_get_order($primary['child_id']), $confirm['operation_id'], $confirm['confirmation_token'], $operator_id);
	$operation_authority = WCOS_Return_Confirmation_Store::operation_authority($confirmed);
	$result = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($primary['child_id']), $confirm['operation_id'], $confirmed['price_precision'], $operation_authority);
	wcos_return_authority_assert('completed' === $result['status'], 'Confirmed lower-level Return did not complete.');
	$journal = WCOS_Operation_Journal::get(wc_get_order($primary['child_id']), $confirm['operation_id']);
	$handoff = WCOS_Return_Journal_Context::confirmation_handoff_from_record($journal);
	$sealed_pair = WCOS_Return_Journal_Context::pair_from_record($journal);
	wcos_return_authority_assert(
		is_array($sealed_pair) && true === $sealed_pair['confirmation_required']
		&& WCOS_Return_Journal_Context::SCHEMA_VERSION === (int) $journal['context']['return_pair']['schema_version']
		&& WCOS_Return_Journal_Context::CONFIRMATION_PROVENANCE_SCHEMA_VERSION === (int) $sealed_pair['confirmation_provenance']['schema_version'],
		'Confirmed Return journal did not seal versioned Confirmation provenance into its pair authority.'
	);
	wcos_return_authority_assert(true === $journal['context']['return_confirmation_required'], 'Confirmed Return journal did not declare mandatory handoff authority.');
	wcos_return_authority_assert($confirm['operation_id'] === $handoff['operation_id'] && $operator_id === $handoff['operator_user_id'], 'Return journal did not bind exact operation/operator Confirmation authority.');
	wcos_return_authority_assert($confirmed['pair_fingerprint'] === $handoff['pair_fingerprint'] && $confirmed['plan_fingerprint'] === $handoff['plan_fingerprint'], 'Return journal handoff changed frozen pair/plan authority.');
	wcos_return_authority_assert(false === strpos(wp_json_encode($journal['context']['return_confirmation']), $confirm['confirmation_token']), 'Return journal stored the raw Confirmation token.');
	$tampered_handoff = $journal['context']['return_confirmation'];
	$tampered_handoff['authority_fingerprint'] = hash('sha256', 'immutable-overwrite-probe');
	wcos_return_authority_assert(
		false === WCOS_Operation_Journal::checkpoint(wc_get_order($primary['child_id']), $confirm['operation_id'], 'confirmation_overwrite_probe', array('return_confirmation' => $tampered_handoff)),
		'Return journal accepted a Confirmation handoff overwrite.'
	);
	$immutable_method = (new ReflectionClass('WCOS_Operation_Journal'))->getMethod('immutable_fields_match');
	$immutable_method->setAccessible(true);
	$removed_handoff = $journal;
	unset($removed_handoff['context']['return_confirmation']);
	wcos_return_authority_assert(false === $immutable_method->invoke(null, $journal, $removed_handoff), 'Return journal accepted Confirmation handoff removal.');
	$removed_marker = $journal;
	unset($removed_marker['context']['return_confirmation_required']);
	wcos_return_authority_assert(false === $immutable_method->invoke(null, $journal, $removed_marker), 'Return journal accepted Confirmation requirement removal.');
	$legacy_fingerprint_method = (new ReflectionClass('WCOS_Return_Journal_Context'))->getMethod('legacy_authority_fingerprint');
	$legacy_fingerprint_method->setAccessible(true);
	$legacy_journal = $journal;
	unset(
		$legacy_journal['context']['return_pair']['confirmation_provenance'],
		$legacy_journal['context']['return_confirmation_required'],
		$legacy_journal['context']['return_confirmation']
	);
	$legacy_journal['context']['return_pair']['schema_version'] = WCOS_Return_Journal_Context::LEGACY_SCHEMA_VERSION;
	$legacy_journal['context']['return_pair']['pair_fingerprint'] = $legacy_fingerprint_method->invoke(
		null,
		$legacy_journal['context']['return_pair']['authority']
	);
	$legacy_journal['fingerprint'] = $legacy_journal['context']['return_pair']['pair_fingerprint'];
	wcos_return_authority_assert(is_array(WCOS_Return_Journal_Context::pair_from_record($legacy_journal)), 'Genuine schema-v1 Return journal compatibility was not preserved.');
	wcos_return_authority_assert(null === WCOS_Return_Journal_Context::confirmation_handoff_if_required($legacy_journal), 'Genuine schema-v1 Return journal was incorrectly treated as Confirmation-required.');
	$legacy_with_confirmation = $legacy_journal;
	$legacy_with_confirmation['context']['return_confirmation_required'] = true;
	$legacy_with_confirmation['context']['return_confirmation'] = $journal['context']['return_confirmation'];
	wcos_return_authority_assert(
		false === $immutable_method->invoke(null, $legacy_journal, $legacy_with_confirmation),
		'Legacy Return journal accepted addition of both Confirmation fields.'
	);
	$legacy_addition_rejected = false;
	try { WCOS_Return_Journal_Context::confirmation_handoff_if_required($legacy_with_confirmation); }
	catch (Throwable $throwable) { $legacy_addition_rejected = true; }
	wcos_return_authority_assert($legacy_addition_rejected, 'Legacy Return journal addition was treated as valid Confirmation authority.');

	WCOS_Return_Confirmation_Store::delete($confirm['operation_id']);
	$durable = WCOS_Return_Confirmation_Store::verify(wc_get_order($primary['child_id']), $confirm['operation_id'], '', $operator_id);
	wcos_return_authority_assert('journal' === $durable['replay_authority'], 'Transient loss after journal start did not use durable Return authority.');
	$journal_before = wp_json_encode($journal);
	$result_replay = (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($primary['child_id']), $confirm['operation_id'], $durable['price_precision'], WCOS_Return_Confirmation_Store::operation_authority($durable));
	wcos_return_authority_assert($result === $result_replay, 'Durable confirmed Return replay did not return the stable terminal result.');
	wcos_return_authority_assert($journal_before === wp_json_encode(WCOS_Operation_Journal::get(wc_get_order($primary['child_id']), $confirm['operation_id'])), 'Durable confirmed replay changed journal authority.');

	$corrupt = WCOS_Operation_Journal::get(wc_get_order($primary['child_id']), $confirm['operation_id']);
	$corrupt['context']['return_confirmation']['authority_fingerprint'] = hash('sha256', 'corrupt-confirmation-handoff');
	update_option('wcos_mutation_op_' . hash('sha256', absint($primary['child_id']) . '|' . sanitize_key($confirm['operation_id'])), $corrupt, false);
	$child_before_quarantine = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($primary['child_id']));
	$original_before_quarantine = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($primary['original_id']));
	$handoff_quarantined = false;
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($primary['child_id']), $confirm['operation_id'], $durable['price_precision']); }
	catch (WCOS_Return_Adapter_Exception $exception) { $handoff_quarantined = 'return_manual_reconciliation' === $exception->get_error_code(); }
	$quarantined_journal = WCOS_Operation_Journal::get(wc_get_order($primary['child_id']), $confirm['operation_id']);
	wcos_return_authority_assert($handoff_quarantined && 'manual_reconciliation' === $quarantined_journal['status'], 'Corrupt durable Return Confirmation handoff was not quarantined.');
	wcos_return_authority_assert($child_before_quarantine === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($primary['child_id'])) && $original_before_quarantine === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($primary['original_id'])), 'Confirmation handoff quarantine changed commercial state.');

	$recovery = wcos_return_authority_fixture('confirmed-recovery-states', 'manual_quantity');
	$fixtures[] = $recovery; $recovery_index = count($fixtures) - 1;
	list($recovery_confirm, $recovery_confirmation, $recovery_journal) = wcos_return_authority_freeze_recovery($controller, $recovery, $operator_id);
	$fixtures[$recovery_index]['operation_ids'][] = $recovery_confirm['operation_id'];
	wcos_return_authority_assert(true === $recovery_journal['context']['return_confirmation_required'], 'Confirmed recovery journal lost mandatory handoff authority.');
	WCOS_Return_Confirmation_Store::delete($recovery_confirm['operation_id']);
	$recovery_closed = false;
	try {
		WCOS_Return_Confirmation_Store::verify(wc_get_order($recovery['child_id']), $recovery_confirm['operation_id'], '', $operator_id);
	} catch (WCOS_Return_Confirmation_Exception $exception) {
		$recovery_closed = 'operation_closed' === $exception->get_reason();
	}
	$recovery_after = WCOS_Operation_Journal::get(wc_get_order($recovery['child_id']), $recovery_confirm['operation_id']);
	wcos_return_authority_assert($recovery_closed && 'compensated' === $recovery_after['status'], 'Transient-loss confirmed recovery did not deterministically compensate and close.');
	$compensated_closed = false;
	try {
		WCOS_Return_Confirmation_Store::verify(wc_get_order($recovery['child_id']), $recovery_confirm['operation_id'], '', $operator_id);
	} catch (WCOS_Return_Confirmation_Exception $exception) {
		$compensated_closed = 'operation_closed' === $exception->get_reason();
	}
	wcos_return_authority_assert($compensated_closed, 'Compensated confirmed Return journal remained replayable.');
	$recovery_child = wc_get_order($recovery['child_id']);
	wcos_return_authority_assert(
		WCOS_Operation_Journal::mark_manual_reconciliation($recovery_child, $recovery_confirm['operation_id'], array('reason' => 'confirmed_return_manual_fixture')),
		'Unable to establish confirmed Return manual-reconciliation authority.'
	);
	$manual_closed = false;
	try {
		WCOS_Return_Confirmation_Store::verify(wc_get_order($recovery['child_id']), $recovery_confirm['operation_id'], '', $operator_id);
	} catch (WCOS_Return_Confirmation_Exception $exception) {
		$manual_closed = 'manual_reconciliation' === $exception->get_reason();
	}
	wcos_return_authority_assert($manual_closed, 'Manual-reconciliation confirmed Return journal did not fail closed.');
	wcos_return_authority_assert(
		WCOS_Operation_Journal::mark_manual_reconciled(wc_get_order($recovery['child_id']), $recovery_confirm['operation_id'], array('reason' => 'confirmed_return_manual_fixture_closed')),
		'Unable to close confirmed Return manual-reconciliation authority.'
	);
	$manual_reconciled_closed = false;
	try {
		WCOS_Return_Confirmation_Store::verify(wc_get_order($recovery['child_id']), $recovery_confirm['operation_id'], '', $operator_id);
	} catch (WCOS_Return_Confirmation_Exception $exception) {
		$manual_reconciled_closed = 'operation_closed' === $exception->get_reason();
	}
	wcos_return_authority_assert($manual_reconciled_closed, 'Manual-reconciled confirmed Return journal remained replayable.');

	foreach (array('corrupt', 'missing', 'stripped') as $confirmation_fault) {
		$fault = wcos_return_authority_fixture('confirmed-recovery-' . $confirmation_fault, 'manual_quantity');
		$fixtures[] = $fault; $fault_index = count($fixtures) - 1;
		list($fault_confirm, $fault_confirmation, $fault_journal) = wcos_return_authority_freeze_recovery($controller, $fault, $operator_id);
		$fixtures[$fault_index]['operation_ids'][] = $fault_confirm['operation_id'];
		WCOS_Return_Confirmation_Store::delete($fault_confirm['operation_id']);
		if ('missing' === $confirmation_fault) {
			unset($fault_journal['context']['return_confirmation']);
		} elseif ('stripped' === $confirmation_fault) {
			unset($fault_journal['context']['return_confirmation_required'], $fault_journal['context']['return_confirmation']);
		} else {
			$fault_journal['context']['return_confirmation']['authority_fingerprint'] = hash('sha256', 'confirmed-recovery-corruption');
		}
		wcos_return_authority_write_journal(wc_get_order($fault['child_id']), $fault_confirm['operation_id'], $fault_journal);
		$fault_child_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fault['child_id']));
		$fault_original_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fault['original_id']));
		WCOS_Mutation_Recovery_Coordinator::handle(wc_get_order($fault['child_id']), $fault_confirm['operation_id'], $fault_journal);
		$fault_after = WCOS_Operation_Journal::get(wc_get_order($fault['child_id']), $fault_confirm['operation_id']);
		wcos_return_authority_assert('manual_reconciliation' === $fault_after['status'], 'Confirmed recovery did not quarantine ' . $confirmation_fault . ' handoff authority.');
		wcos_return_authority_assert(
			$fault_child_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fault['child_id']))
			&& $fault_original_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($fault['original_id'])),
			'Confirmed recovery handoff quarantine changed commercial state: ' . $confirmation_fault
		);
	}

	$race = wcos_return_authority_fixture('sequential-race', 'manual_quantity');
	$fixtures[] = $race; $race_index = count($fixtures) - 1;
	$race_request = wcos_return_authority_request($race);
	$race_review = $controller->review_request($race_request);
	$fixtures[$race_index]['review_ids'][] = $race_review['review_id'];
	$authority_one = WCOS_Return_Review_Store::verify(wc_get_order($race['child_id']), $race_review['review_id'], $race_review['review_token'], $operator_id);
	$authority_two = WCOS_Return_Review_Store::verify(wc_get_order($race['child_id']), $race_review['review_id'], $race_review['review_token'], $operator_id);
	$candidate_one = WCOS_Return_Confirmation_Store::create(wc_get_order($race['child_id']), $authority_one, $operator_id);
	$candidate_two = WCOS_Return_Confirmation_Store::create(wc_get_order($race['child_id']), $authority_two, $operator_id);
	$fixtures[$race_index]['operation_ids'][] = $candidate_one['operation_id'];
	$fixtures[$race_index]['operation_ids'][] = $candidate_two['operation_id'];
	wcos_return_authority_assert(WCOS_Return_Review_Store::consume(wc_get_order($race['child_id']), $race_review['review_id'], $race_review['review_token'], $operator_id), 'First Return Review CAS consumption failed.');
	$second_consumed = WCOS_Return_Review_Store::consume(wc_get_order($race['child_id']), $race_review['review_id'], $race_review['review_token'], $operator_id);
	if (!$second_consumed) { WCOS_Return_Confirmation_Store::delete($candidate_two['operation_id']); }
	wcos_return_authority_assert(false === $second_consumed, 'One Return Review was consumed twice.');
	wcos_return_authority_assert(is_array(get_transient(wcos_return_authority_transient('wcos_return_confirm_', $candidate_one['operation_id']))), 'Winning Return Confirmation disappeared.');
	wcos_return_authority_assert(false === get_transient(wcos_return_authority_transient('wcos_return_confirm_', $candidate_two['operation_id'])), 'Losing Return Confirmation remained usable.');

	$expiry = wcos_return_authority_fixture('expiry-integrity', 'manual_quantity');
	$fixtures[] = $expiry; $expiry_index = count($fixtures) - 1;
	$expiry_request = wcos_return_authority_request($expiry);
	$expiry_review = $controller->review_request($expiry_request);
	$fixtures[$expiry_index]['review_ids'][] = $expiry_review['review_id'];
	$expiry_review_key = wcos_return_authority_transient('wcos_return_review_', $expiry_review['review_id']);
	$expiry_record = get_transient($expiry_review_key); $expiry_record['expires_at'] = time() - 1;
	set_transient($expiry_review_key, $expiry_record, WCOS_Return_Review_Store::TTL);
	$review_expired = false;
	try { WCOS_Return_Review_Store::verify(wc_get_order($expiry['child_id']), $expiry_review['review_id'], $expiry_review['review_token'], $operator_id); }
	catch (WCOS_Return_Review_Exception $exception) { $review_expired = 'expired' === $exception->get_reason(); }
	wcos_return_authority_assert($review_expired, 'Expired Return Review remained usable.');

	$fresh_review = $controller->review_request($expiry_request);
	$fixtures[$expiry_index]['review_ids'][] = $fresh_review['review_id'];
	$expiry_confirm = $controller->confirm_request(array_merge($expiry_request, array('review_id' => $fresh_review['review_id'], 'review_token' => $fresh_review['review_token'])));
	$fixtures[$expiry_index]['operation_ids'][] = $expiry_confirm['operation_id'];
	$expiry_confirm_key = wcos_return_authority_transient('wcos_return_confirm_', $expiry_confirm['operation_id']);
	$expiry_confirmation_record = get_transient($expiry_confirm_key);
	$expiry_confirmation_record['expires_at'] = time() - 1;
	set_transient($expiry_confirm_key, $expiry_confirmation_record, WCOS_Return_Confirmation_Store::TTL);
	$confirmation_expired = false;
	try { WCOS_Return_Confirmation_Store::verify(wc_get_order($expiry['child_id']), $expiry_confirm['operation_id'], $expiry_confirm['confirmation_token'], $operator_id); }
	catch (WCOS_Return_Confirmation_Exception $exception) { $confirmation_expired = 'expired' === $exception->get_reason(); }
	wcos_return_authority_assert($confirmation_expired && null === WCOS_Operation_Journal::get(wc_get_order($expiry['child_id']), $expiry_confirm['operation_id']), 'Expired Return Confirmation created or reused journal authority.');

	$conflict = wcos_return_authority_fixture('confirmation-conflict', 'manual_quantity');
	$fixtures[] = $conflict; $conflict_index = count($fixtures) - 1;
	$conflict_request = wcos_return_authority_request($conflict);
	$conflict_review = $controller->review_request($conflict_request);
	$fixtures[$conflict_index]['review_ids'][] = $conflict_review['review_id'];
	$conflict_confirm = $controller->confirm_request(array_merge($conflict_request, array('review_id' => $conflict_review['review_id'], 'review_token' => $conflict_review['review_token'])));
	$fixtures[$conflict_index]['operation_ids'][] = $conflict_confirm['operation_id'];
	$conflict_record = WCOS_Return_Confirmation_Store::verify(wc_get_order($conflict['child_id']), $conflict_confirm['operation_id'], $conflict_confirm['confirmation_token'], $operator_id);
	$conflict_authority = WCOS_Return_Confirmation_Store::operation_authority($conflict_record);
	$conflict_authority['plan_fingerprint'] = hash('sha256', 'client-conflict');
	$child_before_conflict = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($conflict['child_id']));
	$original_before_conflict = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($conflict['original_id']));
	$handoff_conflict_rejected = false;
	try { (new WCOS_Return_WooCommerce_Adapter())->return_order(wc_get_order($conflict['child_id']), $conflict_confirm['operation_id'], $conflict_record['price_precision'], $conflict_authority); }
	catch (WCOS_Return_Adapter_Exception $exception) { $handoff_conflict_rejected = true; }
	wcos_return_authority_assert($handoff_conflict_rejected && null === WCOS_Operation_Journal::get(wc_get_order($conflict['child_id']), $conflict_confirm['operation_id']), 'Conflicting Return Confirmation handoff reached journal start.');
	wcos_return_authority_assert($child_before_conflict === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($conflict['child_id'])) && $original_before_conflict === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($conflict['original_id'])), 'Conflicting Return Confirmation changed commercial state.');

	$precision_record = get_transient(wcos_return_authority_transient('wcos_return_confirm_', $conflict_confirm['operation_id']));
	$precision_record['price_precision'] = 3;
	set_transient(wcos_return_authority_transient('wcos_return_confirm_', $conflict_confirm['operation_id']), $precision_record, WCOS_Return_Confirmation_Store::TTL);
	$precision_rejected = false;
	try { WCOS_Return_Confirmation_Store::verify(wc_get_order($conflict['child_id']), $conflict_confirm['operation_id'], $conflict_confirm['confirmation_token'], $operator_id); }
	catch (WCOS_Return_Confirmation_Exception $exception) { $precision_rejected = in_array($exception->get_reason(), array('authority_changed', 'confirmation_invalid'), true); }
	wcos_return_authority_assert($precision_rejected, 'Return Confirmation precision/policy drift was accepted.');

	$drift = wcos_return_authority_fixture('drift', 'manual_quantity');
	$fixtures[] = $drift; $drift_index = count($fixtures) - 1;
	$drift_request = wcos_return_authority_request($drift);
	$drift_review = $controller->review_request($drift_request);
	$fixtures[$drift_index]['review_ids'][] = $drift_review['review_id'];
	$child = wc_get_order($drift['child_id']); $line = current($child->get_items('line_item'));
	$line->set_quantity(2); $line->save();
	$child_drift_rejected = false;
	try { $controller->confirm_request(array_merge($drift_request, array('review_id' => $drift_review['review_id'], 'review_token' => $drift_review['review_token']))); }
	catch (WCOS_Return_Transport_Exception $exception) { $child_drift_rejected = 0 === strpos($exception->get_error_code(), 'review_'); }
	wcos_return_authority_assert($child_drift_rejected, 'Child drift after Return Review was accepted.');

	$original_drift = wcos_return_authority_fixture('original-drift', 'manual_quantity');
	$fixtures[] = $original_drift; $original_drift_index = count($fixtures) - 1;
	$original_drift_request = wcos_return_authority_request($original_drift);
	$original_drift_review = $controller->review_request($original_drift_request);
	$fixtures[$original_drift_index]['review_ids'][] = $original_drift_review['review_id'];
	$original = wc_get_order($original_drift['original_id']); $original_line = current($original->get_items('line_item'));
	$original_line->add_meta_data('Unauthenticated original drift', 'reject', true); $original_line->save();
	$original_drift_rejected = false;
	try { $controller->confirm_request(array_merge($original_drift_request, array('review_id' => $original_drift_review['review_id'], 'review_token' => $original_drift_review['review_token']))); }
	catch (WCOS_Return_Transport_Exception $exception) { $original_drift_rejected = 0 === strpos($exception->get_error_code(), 'review_'); }
	wcos_return_authority_assert($original_drift_rejected, 'Original commercial/source-evolution drift after Return Review was accepted.');

	$wrong_child = wcos_return_authority_fixture('wrong-child', 'manual_quantity');
	$fixtures[] = $wrong_child; $wrong_child_index = count($fixtures) - 1;
	$wrong_child_request = wcos_return_authority_request($wrong_child);
	$wrong_child_review = $controller->review_request($wrong_child_request);
	$fixtures[$wrong_child_index]['review_ids'][] = $wrong_child_review['review_id'];
	$wrong_child_rejected = false;
	try { WCOS_Return_Review_Store::verify(wc_get_order($expiry['child_id']), $wrong_child_review['review_id'], $wrong_child_review['review_token'], $operator_id); }
	catch (WCOS_Return_Review_Exception $exception) { $wrong_child_rejected = 'owner_mismatch' === $exception->get_reason(); }
	wcos_return_authority_assert($wrong_child_rejected, 'Return Review accepted the wrong child participant.');

	$legacy_product = new WC_Product_Simple(); $legacy_product->set_name('WCOS Return legacy authority'); $legacy_product->set_regular_price('2.00'); $legacy_product->save();
	$legacy_original = wc_create_order(); $legacy_original->add_product($legacy_product, 1); $legacy_original->calculate_totals(false); $legacy_original->save();
	$legacy_child = wc_create_order(); $legacy_child->add_product($legacy_product, 1); $legacy_child->update_meta_data('yoos_original_order', $legacy_original->get_id()); $legacy_child->calculate_totals(false); $legacy_child->save();
	$legacy_rejected = false;
	try { $controller->review_request(array('child_order_id' => $legacy_child->get_id(), 'nonce' => wp_create_nonce('wcos_return_order_' . $legacy_child->get_id()))); }
	catch (WCOS_Return_Transport_Exception $exception) { $legacy_rejected = 0 === strpos($exception->get_error_code(), 'preflight_'); }
	wcos_return_authority_assert($legacy_rejected, 'Legacy yoos_* metadata minted Return Review authority.');
	$legacy_child->delete(true); $legacy_original->delete(true); $legacy_product->delete(true);

	echo "return-review-confirm-authority-ok strategies=3 enabled_execute=3 replay=3 durable_handoff=1 immutable_handoff=1 legacy_addition_rejected=1 confirmed_states=4 corrupt_recovery=3 stripped_confirmed_quarantined=1 cas_single_consume=1\n";
} finally {
	foreach (array_reverse($fixtures) as $fixture) {
		try { wcos_return_authority_cleanup($fixture); } catch (Throwable $throwable) {}
	}
}
