<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_merge_authority_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_merge_authority_order(WC_Product $product, $email, $quantity) {
	$order = wc_create_order();
	$order->set_status('pending');
	$order->set_currency('USD');
	$order->set_prices_include_tax(false);
	$order->set_billing_first_name('Authority');
	$order->set_billing_last_name('Fixture');
	$order->set_billing_email($email);
	$order->set_billing_address_1('1 Review Way');
	$order->set_billing_city('Testville');
	$order->set_billing_country('US');
	$order->set_shipping_first_name('Authority');
	$order->set_shipping_last_name('Fixture');
	$order->set_shipping_address_1('1 Review Way');
	$order->set_shipping_city('Testville');
	$order->set_shipping_country('US');
	$order->set_payment_method('cod');
	$order->set_payment_method_title('Cash on delivery');
	$order->add_product($product, $quantity);
	$order->calculate_totals(false);
	$order->save();
	return wc_get_order($order->get_id());
}

function wcos_merge_authority_pair(WC_Product $product, $label) {
	$email = 'merge-authority-' . $label . '-' . wp_generate_uuid4() . '@example.test';
	return array(
		wcos_merge_authority_order($product, $email, 1),
		wcos_merge_authority_order($product, $email, 2),
	);
}

function wcos_merge_authority_request(WC_Order $source, WC_Order $target) {
	return array(
		'source_order_id' => $source->get_id(),
		'target_order_id' => $target->get_id(),
		'nonce' => wp_create_nonce('wcos_merge_order_' . $source->get_id()),
	);
}

function wcos_merge_authority_cleanup(WC_Order $source, WC_Order $target, $operation_id = '') {
	$source = wc_get_order($source->get_id());
	if ($source instanceof WC_Order) {
		if ('' !== $operation_id) {
			WCOS_Operation_Journal::delete($source, $operation_id);
			WCOS_Merge_Confirmation_Store::delete($operation_id);
		}
		$source->delete(true);
	}
	$target = wc_get_order($target->get_id());
	if ($target instanceof WC_Order) {
		$target->delete(true);
	}
}

function wcos_merge_authority_write_journal(WC_Order $source, $operation_id, array $record) {
	$key = 'wcos_mutation_op_' . hash('sha256', absint($source->get_id()) . '|' . sanitize_key($operation_id));
	return update_option($key, $record, false);
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_merge_authority_assert(!empty($admins), 'Merge authority smoke requires an administrator fixture.');
$operator_id = absint($admins[0]);
wp_set_current_user($operator_id);
$original_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
update_option('order_splitter_status_allowed', array('wc-pending'));

wcos_merge_authority_assert(false === WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE), 'Merge gate unexpectedly enabled.');
wcos_merge_authority_assert(null === WCOS_Merge_Admin_Controller::bootstrap(), 'Hard-off Merge controller bootstrapped.');
$controller = new WCOS_Merge_Admin_Controller();
wcos_merge_authority_assert(false === $controller->register_hooks(), 'Hard-off Merge controller registered hooks.');
foreach (array(WCOS_Merge_Admin_Controller::SEARCH_ACTION, WCOS_Merge_Admin_Controller::REVIEW_ACTION, WCOS_Merge_Admin_Controller::CONFIRM_ACTION, WCOS_Merge_Admin_Controller::EXECUTE_ACTION) as $action) {
	wcos_merge_authority_assert(false === has_action('wp_ajax_' . $action), 'A hard-off Merge AJAX hook is production-visible.');
}

$product = new WC_Product_Simple();
$product->set_name('Merge Review authority fixture');
$product->set_regular_price('10.00');
$product->set_price('10.00');
$product->set_manage_stock(false);
wcos_merge_authority_assert($product->save() > 0, 'Unable to create Merge authority product.');

$pairs = array();
try {
	list($source, $target) = wcos_merge_authority_pair($product, 'primary');
	$pairs[] = array($source, $target, '');
	$request = wcos_merge_authority_request($source, $target);
	$review = $controller->review_request($request);
	wcos_merge_authority_assert(!empty($review['review_id']) && !empty($review['review_token']), 'Valid Merge Review was not created.');
	$review_json = wp_json_encode($review);
	wcos_merge_authority_assert(false === strpos($review_json, '@example.test') && false === strpos($review_json, 'Review Way'), 'Merge Review response exposed customer PII.');
	$review_record = get_transient('wcos_merge_review_' . hash('sha256', $review['review_id']));
	wcos_merge_authority_assert(is_array($review_record) && !empty($review_record['token_hash']), 'Merge Review hash authority was not stored.');
	wcos_merge_authority_assert(WCOS_Merge_Order_Service::POLICY_VERSION === (int) $review_record['authority']['merge_service_policy_version'], 'Merge Review did not bind current Merge service policy authority.');
	wcos_merge_authority_assert(false === strpos(wp_json_encode($review_record), $review['review_token']), 'Raw Merge Review token was persisted.');
	$wrong_token_rejected = false;
	try {
		WCOS_Merge_Review_Store::verify($source, $target, $review['review_id'], 'wrong-token', $operator_id);
	} catch (WCOS_Merge_Review_Exception $exception) {
		$wrong_token_rejected = 'invalid_token' === $exception->get_reason();
	}
	wcos_merge_authority_assert($wrong_token_rejected, 'Invalid Merge Review token was accepted.');
	$wrong_user_rejected = false;
	try {
		WCOS_Merge_Review_Store::verify($source, $target, $review['review_id'], $review['review_token'], $operator_id + 100000);
	} catch (WCOS_Merge_Review_Exception $exception) {
		$wrong_user_rejected = 'owner_mismatch' === $exception->get_reason();
	}
	wcos_merge_authority_assert($wrong_user_rejected, 'Merge Review accepted the wrong operator.');

	$bad_request = $request;
	$bad_request['plan'] = array('client' => 'authority');
	$unexpected_rejected = false;
	try {
		$controller->review_request($bad_request);
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$unexpected_rejected = 'unexpected_field' === $exception->get_error_code();
	}
	wcos_merge_authority_assert($unexpected_rejected, 'Client-authored Merge plan input was not rejected.');

	$confirm = $controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
	$pairs[0][2] = $confirm['operation_id'];
	wcos_merge_authority_assert(!empty($confirm['operation_id']) && !empty($confirm['confirmation_token']), 'Valid Merge Confirmation was not created.');
	$confirm_record = get_transient('wcos_merge_confirm_' . hash('sha256', $confirm['operation_id']));
	wcos_merge_authority_assert(is_array($confirm_record) && !empty($confirm_record['token_hash']), 'Merge Confirmation hash authority was not stored.');
	wcos_merge_authority_assert(false === strpos(wp_json_encode($confirm_record), $confirm['confirmation_token']), 'Raw Merge Confirmation token was persisted.');
	wcos_merge_authority_assert(false === strpos(wp_json_encode($confirm_record), '@example.test'), 'Merge Confirmation stored plaintext customer PII.');
	wcos_merge_authority_assert(false === get_transient('wcos_merge_review_' . hash('sha256', $review['review_id'])), 'Consumed Merge Review remained usable.');

	$replay_rejected = false;
	try {
		$controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$replay_rejected = in_array($exception->get_error_code(), array('review_expired', 'review_already_consumed'), true);
	}
	wcos_merge_authority_assert($replay_rejected, 'A consumed Merge Review produced another Confirmation.');

	$source_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($source->get_id()));
	$target_before = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($target->get_id()));
	$gate_rejected = false;
	try {
		$controller->execute_request(array_merge($request, array('operation_id' => $confirm['operation_id'], 'confirmation_token' => $confirm['confirmation_token'])));
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$gate_rejected = 'workflow_disabled' === $exception->get_error_code();
	}
	wcos_merge_authority_assert($gate_rejected, 'Controller Execute did not stop at the Merge gateway hard-off boundary.');
	wcos_merge_authority_assert(null === WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $confirm['operation_id']), 'Gate-off Execute created a Merge journal.');
	wcos_merge_authority_assert($source_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($source->get_id())), 'Gate-off Execute changed the source.');
	wcos_merge_authority_assert($target_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($target->get_id())), 'Gate-off Execute changed the target.');
	$second_gate_rejected = false;
	try {
		$controller->execute_request(array_merge($request, array('operation_id' => $confirm['operation_id'], 'confirmation_token' => $confirm['confirmation_token'])));
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$second_gate_rejected = 'workflow_disabled' === $exception->get_error_code();
	}
	wcos_merge_authority_assert($second_gate_rejected && null === WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $confirm['operation_id']), 'Repeated gate-off Execute changed operation authority.');

	list($surface_source, $surface_target) = wcos_merge_authority_pair($product, 'first-execute-surface');
	$pairs[] = array($surface_source, $surface_target, '');
	$surface_request = wcos_merge_authority_request($surface_source, $surface_target);
	$surface_review = $controller->review_request($surface_request);
	$surface_confirm = $controller->confirm_request(array_merge($surface_request, array('review_id' => $surface_review['review_id'], 'review_token' => $surface_review['review_token'])));
	$pairs[count($pairs) - 1][2] = $surface_confirm['operation_id'];
	$surface_source->set_status('on-hold');
	$surface_source->save();
	$first_execute_status_rejected = false;
	try {
		$controller->execute_request(array_merge($surface_request, array('operation_id' => $surface_confirm['operation_id'], 'confirmation_token' => $surface_confirm['confirmation_token'])));
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$first_execute_status_rejected = 'status_disabled' === $exception->get_error_code();
	}
	wcos_merge_authority_assert($first_execute_status_rejected, 'First Execute without a durable journal bypassed current surface-status eligibility.');
	wcos_merge_authority_assert(null === WCOS_Operation_Journal::get(wc_get_order($surface_source->get_id()), $surface_confirm['operation_id']), 'Rejected first Execute created a durable journal.');

	$original_confirm_record = $confirm_record;
	$confirm_record['price_precision'] = 3;
	set_transient('wcos_merge_confirm_' . hash('sha256', $confirm['operation_id']), $confirm_record, WCOS_Merge_Confirmation_Store::TTL);
	$precision_drift_rejected = false;
	try {
		WCOS_Merge_Confirmation_Store::verify($source, $target, $confirm['operation_id'], $confirm['confirmation_token'], $operator_id);
	} catch (WCOS_Merge_Confirmation_Exception $exception) {
		$precision_drift_rejected = in_array($exception->get_reason(), array('authority_changed', 'pair_changed'), true);
	}
	wcos_merge_authority_assert($precision_drift_rejected, 'Merge Confirmation precision drift was accepted.');
	set_transient('wcos_merge_confirm_' . hash('sha256', $confirm['operation_id']), $original_confirm_record, WCOS_Merge_Confirmation_Store::TTL);

	list($race_source, $race_target) = wcos_merge_authority_pair($product, 'race');
	$pairs[] = array($race_source, $race_target, '');
	$race_request = wcos_merge_authority_request($race_source, $race_target);
	$race_review = $controller->review_request($race_request);
	$race_authority_one = WCOS_Merge_Review_Store::verify($race_source, $race_target, $race_review['review_id'], $race_review['review_token'], $operator_id);
	$race_authority_two = WCOS_Merge_Review_Store::verify($race_source, $race_target, $race_review['review_id'], $race_review['review_token'], $operator_id);
	$race_candidate_one = WCOS_Merge_Confirmation_Store::create($race_source, $race_target, $race_authority_one, $operator_id);
	$race_candidate_two = WCOS_Merge_Confirmation_Store::create($race_source, $race_target, $race_authority_two, $operator_id);
	wcos_merge_authority_assert(WCOS_Merge_Review_Store::consume($race_review['review_id']), 'The first racing Confirm did not consume the Review.');
	$second_consumed = WCOS_Merge_Review_Store::consume($race_review['review_id']);
	if (!$second_consumed) {
		WCOS_Merge_Confirmation_Store::delete($race_candidate_two['operation_id']);
	}
	wcos_merge_authority_assert(false === $second_consumed, 'Two racing Confirm candidates consumed one Merge Review.');
	wcos_merge_authority_assert(is_array(get_transient('wcos_merge_confirm_' . hash('sha256', $race_candidate_one['operation_id']))), 'The winning racing Confirmation did not survive.');
	wcos_merge_authority_assert(false === get_transient('wcos_merge_confirm_' . hash('sha256', $race_candidate_two['operation_id'])), 'The losing racing Confirmation was not deleted.');
	global $wpdb;
	$claim_option_count = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like('wcos_merge_review_' . 'claim_') . '%'
	));
	wcos_merge_authority_assert(0 === $claim_option_count, 'A persistent Merge Review coordination option remained.');
	WCOS_Merge_Confirmation_Store::delete($race_candidate_one['operation_id']);

	list($drift_source, $drift_target) = wcos_merge_authority_pair($product, 'drift');
	$pairs[] = array($drift_source, $drift_target, '');
	$drift_request = wcos_merge_authority_request($drift_source, $drift_target);
	$drift_review = $controller->review_request($drift_request);
	$drift_item = current($drift_target->get_items('line_item'));
	$drift_item->set_quantity(3);
	$drift_item->save();
	$target_drift_rejected = false;
	try {
		$controller->confirm_request(array_merge($drift_request, array('review_id' => $drift_review['review_id'], 'review_token' => $drift_review['review_token'])));
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$target_drift_rejected = in_array($exception->get_error_code(), array('review_target_changed', 'review_pair_changed', 'review_authority_changed'), true);
	}
	wcos_merge_authority_assert($target_drift_rejected, 'Target drift after Review was not rejected.');
	WCOS_Merge_Review_Store::delete($drift_review['review_id']);

	list($source_drift_source, $source_drift_target) = wcos_merge_authority_pair($product, 'source-drift');
	$pairs[] = array($source_drift_source, $source_drift_target, '');
	$source_drift_request = wcos_merge_authority_request($source_drift_source, $source_drift_target);
	$source_drift_review = $controller->review_request($source_drift_request);
	$source_drift_item = current($source_drift_source->get_items('line_item'));
	$source_drift_item->set_quantity(4);
	$source_drift_item->save();
	$source_drift_rejected = false;
	try {
		$controller->confirm_request(array_merge($source_drift_request, array('review_id' => $source_drift_review['review_id'], 'review_token' => $source_drift_review['review_token'])));
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$source_drift_rejected = in_array($exception->get_error_code(), array('review_source_changed', 'review_pair_changed', 'review_authority_changed'), true);
	}
	wcos_merge_authority_assert($source_drift_rejected, 'Source drift after Review was not rejected.');
	WCOS_Merge_Review_Store::delete($source_drift_review['review_id']);

	list($expiry_source, $expiry_target) = wcos_merge_authority_pair($product, 'expiry');
	$pairs[] = array($expiry_source, $expiry_target, '');
	$expiry_request = wcos_merge_authority_request($expiry_source, $expiry_target);
	$expiry_review = $controller->review_request($expiry_request);
	$expiry_review_key = 'wcos_merge_review_' . hash('sha256', $expiry_review['review_id']);
	$expiry_review_record = get_transient($expiry_review_key);
	$expiry_review_record['expires_at'] = time() - 1;
	set_transient($expiry_review_key, $expiry_review_record, WCOS_Merge_Review_Store::TTL);
	$review_expired = false;
	try {
		WCOS_Merge_Review_Store::verify($expiry_source, $expiry_target, $expiry_review['review_id'], $expiry_review['review_token'], $operator_id);
	} catch (WCOS_Merge_Review_Exception $exception) {
		$review_expired = 'expired' === $exception->get_reason();
	}
	wcos_merge_authority_assert($review_expired, 'Expired Merge Review was accepted.');

	$expiry_review = $controller->review_request($expiry_request);
	$expiry_confirm = $controller->confirm_request(array_merge($expiry_request, array('review_id' => $expiry_review['review_id'], 'review_token' => $expiry_review['review_token'])));
	$pairs[count($pairs) - 1][2] = $expiry_confirm['operation_id'];
	$expiry_confirm_key = 'wcos_merge_confirm_' . hash('sha256', $expiry_confirm['operation_id']);
	$expiry_confirm_record = get_transient($expiry_confirm_key);
	$expiry_confirm_record['expires_at'] = time() - 1;
	set_transient($expiry_confirm_key, $expiry_confirm_record, WCOS_Merge_Confirmation_Store::TTL);
	$confirmation_expired = false;
	try {
		WCOS_Merge_Confirmation_Store::verify($expiry_source, $expiry_target, $expiry_confirm['operation_id'], $expiry_confirm['confirmation_token'], $operator_id);
	} catch (WCOS_Merge_Confirmation_Exception $exception) {
		$confirmation_expired = 'expired' === $exception->get_reason();
	}
	wcos_merge_authority_assert($confirmation_expired, 'Expired pre-journal Merge Confirmation was accepted.');

	list($durable_source, $durable_target) = wcos_merge_authority_pair($product, 'durable');
	$pairs[] = array($durable_source, $durable_target, '');
	$durable_request = wcos_merge_authority_request($durable_source, $durable_target);
	$durable_review = $controller->review_request($durable_request);
	$durable_confirm = $controller->confirm_request(array_merge($durable_request, array('review_id' => $durable_review['review_id'], 'review_token' => $durable_review['review_token'])));
	$pairs[count($pairs) - 1][2] = $durable_confirm['operation_id'];
	$durable_record = WCOS_Merge_Confirmation_Store::verify($durable_source, $durable_target, $durable_confirm['operation_id'], $durable_confirm['confirmation_token'], $operator_id);
	$result = (new WCOS_Merge_WooCommerce_Adapter())->merge(
		$durable_source,
		$durable_target,
		$durable_confirm['operation_id'],
		$durable_record['price_precision'],
		WCOS_Merge_Confirmation_Store::operation_authority($durable_record)
	);
	wcos_merge_authority_assert('completed' === $result['status'], 'Confirmed Merge did not establish completed durable replay authority.');
	$journal = WCOS_Operation_Journal::get(wc_get_order($durable_source->get_id()), $durable_confirm['operation_id']);
	wcos_merge_authority_assert(is_array($journal) && 'completed' === $journal['status'], 'Confirmed Merge source journal is missing.');
	wcos_merge_authority_assert(false === strpos(wp_json_encode($journal), $durable_confirm['confirmation_token']), 'Raw Confirmation token entered the durable journal.');
	$handoff = isset($journal['context']['merge_confirmation_authority']) ? $journal['context']['merge_confirmation_authority'] : array();
	wcos_merge_authority_assert(
		isset($handoff['schema_version'], $handoff['authority'], $handoff['authority_fingerprint'])
		&& WCOS_Merge_Journal_Context::CONFIRMATION_HANDOFF_SCHEMA_VERSION === (int) $handoff['schema_version']
		&& WCOS_Merge_Order_Service::POLICY_VERSION === (int) $handoff['authority']['merge_service_policy_version'],
		'Confirmed Merge journal is missing bounded service-policy handoff authority.'
	);
	wcos_merge_authority_assert(false === strpos(wp_json_encode($handoff), '@example.test'), 'Durable Merge Confirmation handoff stored plaintext customer PII.');
	wcos_merge_authority_assert(null === WCOS_Operation_Journal::get(wc_get_order($durable_target->get_id()), $durable_confirm['operation_id']), 'A forbidden target shadow journal was created.');
	$durable_source = wc_get_order($durable_source->get_id());
	$durable_target = wc_get_order($durable_target->get_id());
	wcos_merge_authority_assert('trash' === $durable_source->get_status(), 'Completed confirmed Merge did not retire the source to trash.');
	$durable_target->set_status('on-hold');
	$durable_target->save();
	$durable_target = wc_get_order($durable_target->get_id());
	$replay_journal_before = wp_json_encode(WCOS_Operation_Journal::get($durable_source, $durable_confirm['operation_id']));
	$replay_source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($durable_source);
	$replay_target_before = WCOS_Merge_Recovery_Snapshot::participant_signature($durable_target);
	$replay_target_line_ids_before = array_map('absint', array_keys($durable_target->get_items('line_item')));
	$replay_target_tax_ids_before = array_map('absint', array_keys($durable_target->get_items('tax')));
	$replay_stock_before = $product->get_stock_quantity();
	$controller_replay_reached_gateway = false;
	try {
		$controller->execute_request(array_merge($durable_request, array('operation_id' => $durable_confirm['operation_id'], 'confirmation_token' => $durable_confirm['confirmation_token'])));
	} catch (WCOS_Merge_Transport_Exception $exception) {
		$controller_replay_reached_gateway = 'workflow_disabled' === $exception->get_error_code();
	}
	wcos_merge_authority_assert($controller_replay_reached_gateway, 'Completed Merge controller replay was blocked by stale source/target surface status before the gateway.');
	$durable_source = wc_get_order($durable_source->get_id());
	$durable_target = wc_get_order($durable_target->get_id());
	wcos_merge_authority_assert($replay_journal_before === wp_json_encode(WCOS_Operation_Journal::get($durable_source, $durable_confirm['operation_id'])), 'Controller replay changed or created durable journal authority.');
	wcos_merge_authority_assert($replay_source_before === WCOS_Merge_Recovery_Snapshot::participant_signature($durable_source), 'Controller replay changed retired-source item, tax, relation, lifecycle, or stock ownership.');
	wcos_merge_authority_assert($replay_target_before === WCOS_Merge_Recovery_Snapshot::participant_signature($durable_target), 'Controller replay changed target item, tax, relation, lifecycle, or stock ownership.');
	wcos_merge_authority_assert($replay_target_line_ids_before === array_map('absint', array_keys($durable_target->get_items('line_item'))), 'Controller replay created a second target line item operation.');
	wcos_merge_authority_assert($replay_target_tax_ids_before === array_map('absint', array_keys($durable_target->get_items('tax'))), 'Controller replay created a second target tax operation.');
	wcos_merge_authority_assert($replay_stock_before === wc_get_product($product->get_id())->get_stock_quantity(), 'Controller replay changed physical stock ownership.');
	wcos_merge_authority_assert(null === WCOS_Operation_Journal::get($durable_target, $durable_confirm['operation_id']), 'Controller replay created a target shadow journal.');
	$matched_journal_replay = WCOS_Merge_Confirmation_Store::verify(wc_get_order($durable_source->get_id()), wc_get_order($durable_target->get_id()), $durable_confirm['operation_id'], $durable_confirm['confirmation_token'], $operator_id);
	wcos_merge_authority_assert('completed' === $matched_journal_replay['journal_status'], 'Matching surviving Confirmation did not defer to the completed journal.');
	$durable_confirm_key = 'wcos_merge_confirm_' . hash('sha256', $durable_confirm['operation_id']);
	$surviving_confirmation = get_transient($durable_confirm_key);
	$surviving_confirmation['user_id'] = $operator_id + 100000;
	set_transient($durable_confirm_key, $surviving_confirmation, WCOS_Merge_Confirmation_Store::TTL);
	$mismatched_transient_rejected = false;
	try {
		WCOS_Merge_Confirmation_Store::verify(wc_get_order($durable_source->get_id()), wc_get_order($durable_target->get_id()), $durable_confirm['operation_id'], $durable_confirm['confirmation_token'], $operator_id);
	} catch (WCOS_Merge_Confirmation_Exception $exception) {
		$mismatched_transient_rejected = 'journal_mismatch' === $exception->get_reason();
	}
	wcos_merge_authority_assert($mismatched_transient_rejected, 'A surviving mismatched Confirmation overrode durable journal authority.');
	set_transient($durable_confirm_key, $durable_record, WCOS_Merge_Confirmation_Store::TTL);
	$original_journal = $journal;
	foreach (array('operator_user_id' => $operator_id + 100000, 'merge_service_policy_version' => WCOS_Merge_Order_Service::POLICY_VERSION + 1) as $field => $tampered_value) {
		$tampered_journal = $original_journal;
		$tampered_journal['context']['merge_confirmation_authority']['authority'][$field] = $tampered_value;
		wcos_merge_authority_write_journal(wc_get_order($durable_source->get_id()), $durable_confirm['operation_id'], $tampered_journal);
		$handoff_tamper_rejected = false;
		try {
			WCOS_Merge_Confirmation_Store::verify(wc_get_order($durable_source->get_id()), wc_get_order($durable_target->get_id()), $durable_confirm['operation_id'], '', $operator_id);
		} catch (WCOS_Merge_Confirmation_Exception $exception) {
			$handoff_tamper_rejected = 'journal_mismatch' === $exception->get_reason();
		}
		wcos_merge_authority_assert($handoff_tamper_rejected, 'Durable Merge handoff tamper was trusted for ' . $field . '.');
		wcos_merge_authority_write_journal(wc_get_order($durable_source->get_id()), $durable_confirm['operation_id'], $original_journal);
	}
	WCOS_Merge_Confirmation_Store::delete($durable_confirm['operation_id']);
	$journal_replay = WCOS_Merge_Confirmation_Store::verify(wc_get_order($durable_source->get_id()), wc_get_order($durable_target->get_id()), $durable_confirm['operation_id'], '', $operator_id);
	wcos_merge_authority_assert('journal' === $journal_replay['replay_authority'] && 'completed' === $journal_replay['journal_status'], 'Transient loss did not defer to completed source journal authority.');

	list($recovery_source, $recovery_target) = wcos_merge_authority_pair($product, 'recovery-dispatch');
	$pairs[] = array($recovery_source, $recovery_target, '');
	$recovery_request = wcos_merge_authority_request($recovery_source, $recovery_target);
	$recovery_review = $controller->review_request($recovery_request);
	$recovery_confirm = $controller->confirm_request(array_merge($recovery_request, array('review_id' => $recovery_review['review_id'], 'review_token' => $recovery_review['review_token'])));
	$pairs[count($pairs) - 1][2] = $recovery_confirm['operation_id'];
	$recovery_confirmation = WCOS_Merge_Confirmation_Store::verify($recovery_source, $recovery_target, $recovery_confirm['operation_id'], $recovery_confirm['confirmation_token'], $operator_id);
	$interrupt_before_write = static function($stage) {
		if ('before_target_write' === $stage) {
			throw new RuntimeException('deterministic-confirmed-recovery-dispatch');
		}
	};
	remove_action('wcos_mutation_recovery_required', array('WCOS_Mutation_Recovery_Coordinator', 'handle'), 10);
	add_action('wcos_merge_mutation_checkpoint', $interrupt_before_write, PHP_INT_MAX, 1);
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge(
			$recovery_source,
			$recovery_target,
			$recovery_confirm['operation_id'],
			$recovery_confirmation['price_precision'],
			WCOS_Merge_Confirmation_Store::operation_authority($recovery_confirmation)
		);
	} catch (Throwable $throwable) {
		// Expected deterministic interruption after the confirmed journal and recovery snapshot exist.
	}
	remove_action('wcos_merge_mutation_checkpoint', $interrupt_before_write, PHP_INT_MAX);
	add_action('wcos_mutation_recovery_required', array('WCOS_Mutation_Recovery_Coordinator', 'handle'), 10, 3);
	$recovery_before = WCOS_Operation_Journal::get(wc_get_order($recovery_source->get_id()), $recovery_confirm['operation_id']);
	wcos_merge_authority_assert(is_array($recovery_before) && 'recovery_required' === $recovery_before['status'], 'Confirmed recovery fixture did not stop at recovery_required.');
	$dispatched_closed = false;
	try {
		WCOS_Merge_Confirmation_Store::verify(wc_get_order($recovery_source->get_id()), wc_get_order($recovery_target->get_id()), $recovery_confirm['operation_id'], $recovery_confirm['confirmation_token'], $operator_id);
	} catch (WCOS_Merge_Confirmation_Exception $exception) {
		$dispatched_closed = 'operation_closed' === $exception->get_reason();
	}
	$recovery_after = WCOS_Operation_Journal::get(wc_get_order($recovery_source->get_id()), $recovery_confirm['operation_id']);
	wcos_merge_authority_assert($dispatched_closed && is_array($recovery_after) && 'compensated' === $recovery_after['status'], 'Recovery replay returned stale recovery_required instead of reloaded compensated authority.');
	$compensated_closed = false;
	try {
		WCOS_Merge_Confirmation_Store::verify(wc_get_order($recovery_source->get_id()), wc_get_order($recovery_target->get_id()), $recovery_confirm['operation_id'], '', $operator_id);
	} catch (WCOS_Merge_Confirmation_Exception $exception) {
		$compensated_closed = 'operation_closed' === $exception->get_reason();
	}
	wcos_merge_authority_assert($compensated_closed, 'Compensated confirmed Merge journal was replayable.');

	list($manual_source, $manual_target) = wcos_merge_authority_pair($product, 'manual-replay');
	$pairs[] = array($manual_source, $manual_target, '');
	$manual_request = wcos_merge_authority_request($manual_source, $manual_target);
	$manual_review = $controller->review_request($manual_request);
	$manual_confirm = $controller->confirm_request(array_merge($manual_request, array('review_id' => $manual_review['review_id'], 'review_token' => $manual_review['review_token'])));
	$pairs[count($pairs) - 1][2] = $manual_confirm['operation_id'];
	$manual_confirmation = WCOS_Merge_Confirmation_Store::verify($manual_source, $manual_target, $manual_confirm['operation_id'], $manual_confirm['confirmation_token'], $operator_id);
	(new WCOS_Merge_WooCommerce_Adapter())->merge($manual_source, $manual_target, $manual_confirm['operation_id'], $manual_confirmation['price_precision'], WCOS_Merge_Confirmation_Store::operation_authority($manual_confirmation));
	$manual_source = wc_get_order($manual_source->get_id());
	wcos_merge_authority_assert(WCOS_Operation_Journal::mark_manual_reconciliation($manual_source, $manual_confirm['operation_id'], array('reason' => 'confirmed_replay_fixture')), 'Unable to establish confirmed manual-reconciliation authority.');
	$manual_closed = false;
	try {
		WCOS_Merge_Confirmation_Store::verify($manual_source, wc_get_order($manual_target->get_id()), $manual_confirm['operation_id'], '', $operator_id);
	} catch (WCOS_Merge_Confirmation_Exception $exception) {
		$manual_closed = 'manual_reconciliation' === $exception->get_reason();
	}
	wcos_merge_authority_assert($manual_closed, 'Manual-reconciliation confirmed Merge journal did not fail closed.');
	wcos_merge_authority_assert(WCOS_Operation_Journal::mark_manual_reconciled($manual_source, $manual_confirm['operation_id'], array('reason' => 'confirmed_replay_fixture_closed')), 'Unable to close confirmed manual-reconciliation authority.');
	$manual_reconciled_closed = false;
	try {
		WCOS_Merge_Confirmation_Store::verify($manual_source, wc_get_order($manual_target->get_id()), $manual_confirm['operation_id'], '', $operator_id);
	} catch (WCOS_Merge_Confirmation_Exception $exception) {
		$manual_reconciled_closed = 'operation_closed' === $exception->get_reason();
	}
	wcos_merge_authority_assert($manual_reconciled_closed, 'Manual-reconciled confirmed Merge journal was replayable.');
} finally {
	foreach ($pairs as $pair) {
		wcos_merge_authority_cleanup($pair[0], $pair[1], $pair[2]);
	}
	$product->delete(true);
	update_option('order_splitter_status_allowed', $original_allowed_statuses);
}

echo "merge-review-confirm-execute-authority-ok\n";
