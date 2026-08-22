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
		'nonce' => wp_create_nonce('wcos_merge_orders_' . $source->get_id() . '_' . $target->get_id()),
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

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_merge_authority_assert(!empty($admins), 'Merge authority smoke requires an administrator fixture.');
$operator_id = absint($admins[0]);
wp_set_current_user($operator_id);

wcos_merge_authority_assert(false === WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE), 'Merge gate unexpectedly enabled.');
wcos_merge_authority_assert(null === WCOS_Merge_Admin_Controller::bootstrap(), 'Hard-off Merge controller bootstrapped.');
$controller = new WCOS_Merge_Admin_Controller();
wcos_merge_authority_assert(false === $controller->register_hooks(), 'Hard-off Merge controller registered hooks.');
foreach (array(WCOS_Merge_Admin_Controller::REVIEW_ACTION, WCOS_Merge_Admin_Controller::CONFIRM_ACTION, WCOS_Merge_Admin_Controller::EXECUTE_ACTION) as $action) {
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
	WCOS_Merge_Review_Store::claim($race_source, $race_target, $race_review['review_id'], $race_review['review_token'], $operator_id);
	$second_claim_rejected = false;
	try {
		WCOS_Merge_Review_Store::claim($race_source, $race_target, $race_review['review_id'], $race_review['review_token'], $operator_id);
	} catch (WCOS_Merge_Review_Exception $exception) {
		$second_claim_rejected = 'already_consumed' === $exception->get_reason();
	}
	wcos_merge_authority_assert($second_claim_rejected, 'Two racing Confirm claims acquired one Merge Review.');
	WCOS_Merge_Review_Store::release_claim($race_review['review_id']);
	WCOS_Merge_Review_Store::delete($race_review['review_id']);

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
	wcos_merge_authority_assert(null === WCOS_Operation_Journal::get(wc_get_order($durable_target->get_id()), $durable_confirm['operation_id']), 'A forbidden target shadow journal was created.');
	WCOS_Merge_Confirmation_Store::delete($durable_confirm['operation_id']);
	$journal_replay = WCOS_Merge_Confirmation_Store::verify(wc_get_order($durable_source->get_id()), wc_get_order($durable_target->get_id()), $durable_confirm['operation_id'], '', $operator_id);
	wcos_merge_authority_assert('journal' === $journal_replay['replay_authority'] && 'completed' === $journal_replay['journal_status'], 'Transient loss did not defer to completed source journal authority.');
} finally {
	foreach ($pairs as $pair) {
		wcos_merge_authority_cleanup($pair[0], $pair[1], $pair[2]);
	}
	$product->delete(true);
}

echo "merge-review-confirm-execute-authority-ok\n";
