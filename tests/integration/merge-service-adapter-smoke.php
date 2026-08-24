<?php

if (!defined('ABSPATH')) {
	exit(1);
}

function wcos_merge_service_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_merge_service_product($name, $managed = true, $backorders = false, $stock = '40') {
	$product = new WC_Product_Simple();
	$product->set_name($name);
	$product->set_regular_price('10.00');
	$product->set_price('10.00');
	$product->set_manage_stock($managed);
	if ($managed) {
		$product->set_stock_quantity($stock);
		$product->set_backorders($backorders ? 'yes' : 'no');
	}
	wcos_merge_service_assert($product->save() > 0, 'Unable to save a Merge service product fixture.');
	return $product;
}

function wcos_merge_service_parent_managed_variation() {
	$parent = new WC_Product_Variable();
	$parent->set_name('Merge parent-managed variable');
	$parent->set_manage_stock(true);
	$parent->set_stock_quantity('31.5');
	wcos_merge_service_assert($parent->save() > 0, 'Unable to save the parent-managed Merge fixture.');

	$variation = new WC_Product_Variation();
	$variation->set_parent_id($parent->get_id());
	$variation->set_regular_price('10.00');
	$variation->set_price('10.00');
	$variation->set_manage_stock(false);
	wcos_merge_service_assert($variation->save() > 0, 'Unable to save the Merge variation fixture.');
	return array($parent, $variation);
}

function wcos_merge_service_order(WC_Product $product, $email, array $reduced_markers, $shipping = false, $target_amount = false, $multiple_rates = false) {
	$order = wc_create_order();
	$order->set_status('pending');
	$order->set_currency('USD');
	$order->set_prices_include_tax(false);
	$order->set_customer_id(0);
	$order->set_billing_first_name('Merge');
	$order->set_billing_last_name('Service');
	$order->set_billing_email($email);
	$order->set_billing_phone('+1 555 0142');
	$order->set_billing_address_1('42 Merge Service Way');
	$order->set_billing_city('Testville');
	$order->set_billing_state('CA');
	$order->set_billing_postcode('90001');
	$order->set_billing_country('US');
	$order->set_shipping_first_name('Merge');
	$order->set_shipping_last_name('Service');
	$order->set_shipping_address_1('42 Merge Service Way');
	$order->set_shipping_city('Testville');
	$order->set_shipping_state('CA');
	$order->set_shipping_postcode('90001');
	$order->set_shipping_country('US');
	$order->set_payment_method('cod');
	$order->set_payment_method_title('Cash on delivery');

	$tax_totals = array();
	foreach ($reduced_markers as $index => $marker) {
		$rate_id = $multiple_rates && 0 < $index ? 702 : 701;
		$line_tax = $target_amount ? '0.50' : '1.00';
		$item = new WC_Order_Item_Product();
		$item->set_name($target_amount ? 'Existing target line' : 'Identical historical source line');
		if ($product instanceof WC_Product_Variation) {
			$item->set_product_id($product->get_parent_id());
			$item->set_variation_id($product->get_id());
		} else {
			$item->set_product_id($product->get_id());
		}
		$item->set_quantity($target_amount ? '1' : ('fractional' === $marker ? '1.5' : '1'));
		$item->set_subtotal($target_amount ? '5.00' : '10.00');
		$item->set_total($target_amount ? '5.00' : '10.00');
		$item->set_subtotal_tax($target_amount ? '0.50' : '1.00');
		$item->set_total_tax($target_amount ? '0.50' : '1.00');
		$item->set_taxes(array(
			'subtotal' => array($rate_id => $line_tax),
			'total' => array($rate_id => $line_tax),
		));
		$tax_totals[$rate_id] = isset($tax_totals[$rate_id]) ? $tax_totals[$rate_id] + (float) $line_tax : (float) $line_tax;
		$item->add_meta_data('Merge fixture', 'preserved-business-value', true);
		if (null !== $marker) {
			$item->add_meta_data('_reduced_stock', 'fractional' === $marker ? '1.5' : WCOS_Decimal::normalize($marker, 6), true);
		}
		$order->add_item($item);
	}

	if ($shipping) {
		$shipping_item = new WC_Order_Item_Shipping();
		$shipping_props = $shipping_item->set_props(array(
			'method_title' => 'Supported target shipping',
			'method_id' => 'flat_rate',
			'instance_id' => 9,
			'total' => '3.00',
			'total_tax' => '0.30',
			'taxes' => array('total' => array(701 => '0.30')),
		));
		wcos_merge_service_assert(!is_wp_error($shipping_props), 'Unable to set supported target shipping fixture props.');
		$shipping_item->add_meta_data('Delivery window', 'morning', true);
		$order->add_item($shipping_item);
	}

	foreach ($tax_totals as $rate_id => $cart_tax) {
		$tax = new WC_Order_Item_Tax();
		$tax->set_rate_id($rate_id);
		$tax->set_label('Frozen historical rate ' . $rate_id);
		$tax->set_compound(false);
		$tax->set_rate_percent(10);
		$tax->set_tax_total(wc_format_decimal($cart_tax, 2));
		$tax->set_shipping_tax_total($shipping && 701 === (int) $rate_id ? '0.30' : '0.00');
		$order->add_item($tax);
	}
	WCOS_Order_Totals_Rebuilder::rebuild($order, 2);
	$order->save();
	$order->get_data_store()->set_stock_reduced($order->get_id(), !empty(array_filter($reduced_markers, static function($value) {
		return null !== $value;
	})));
	return wc_get_order($order->get_id());
}

function wcos_merge_service_pair(WC_Product $product, $label, array $source_markers = array('1'), $shipping = false, $multiple_rates = false) {
	$email = 'merge-service-' . $label . '-' . wp_generate_uuid4() . '@example.test';
	return array(
		wcos_merge_service_order($product, $email, $source_markers, false, false, $multiple_rates),
		wcos_merge_service_order($product, $email, array(null), $shipping, true),
	);
}

function wcos_merge_service_cleanup(WC_Order $source, WC_Order $target, $operation_id) {
	delete_option('wcos_manual_reconcile_block_' . $source->get_id());
	delete_option('wcos_manual_reconcile_block_' . $target->get_id());
	$fresh_source = wc_get_order($source->get_id());
	if ($fresh_source instanceof WC_Order) {
		WCOS_Operation_Journal::delete($fresh_source, $operation_id);
		$fresh_source->delete(true);
	}
	$fresh_target = wc_get_order($target->get_id());
	if ($fresh_target instanceof WC_Order) {
		$fresh_target->delete(true);
	}
}

function wcos_merge_service_status(WC_Order $source, $operation_id) {
	$record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
	return is_array($record) && isset($record['status']) ? sanitize_key((string) $record['status']) : '';
}

function wcos_merge_service_assert_manual_pair(WC_Order $source, WC_Order $target, $operation_id) {
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_service_assert('manual_reconciliation' === wcos_merge_service_status($source, $operation_id), 'Immutable drift did not enter manual reconciliation.');
	wcos_merge_service_assert(in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($source), true), 'Source lacks pair-wide manual authority.');
	wcos_merge_service_assert(in_array($operation_id, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($target), true), 'Target lacks pair-wide manual authority.');
}

$manage_stock_before = get_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_manage_stock', 'yes');
$products = array();
$suite = isset($args[0]) ? sanitize_key((string) $args[0]) : 'all';
$forward_suites = array(
	'forward_before_forward_relations' => 'before_forward_relations',
	'forward_after_one_reciprocal_relation' => 'after_one_reciprocal_relation',
	'forward_after_both_relations_before_verification' => 'after_both_relations_before_verification',
	'forward_after_verification_before_commit' => 'after_verification_before_commit',
	'forward_after_commit_before_complete' => 'after_commit_before_complete',
);
wcos_merge_service_assert(in_array($suite, array_merge(array('all', 'core', 'crash_pre', 'response_loss', 'lease_loss', 'stock_guard_before', 'stock_guard_after', 'drift_stock', 'checkpoint_drift'), array_keys($forward_suites)), true), 'Unknown Merge service smoke suite.');

try {
	$managed = wcos_merge_service_product('Merge managed fixture');
	$products[] = $managed;

	if (in_array($suite, array('all', 'core'), true)) {
	wcos_merge_service_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::MERGE), 'Production Merge gate is not enabled for adapter evidence.');

	/* Success: identical source lines stay distinct, historical tax/shipping survive, retry is stable. */
	list($source, $target) = wcos_merge_service_pair($managed, 'success', array('1.5', '2'), true, true);
	$operation_id = 'merge-service-success-' . wp_generate_uuid4();
	$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
	$source_line_ids = array_map('intval', array_keys($source->get_items('line_item')));
	$source_line_identities = array_map(static function($item) { return WCOS_Line_Identity::from_item($item); }, array_values($source->get_items('line_item')));
	$shipping_before = WCOS_Merge_Recovery_Snapshot::participant_checkpoint($target)['order_props']['shipping_total'];
	$result = (new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	$retry = (new WCOS_Merge_WooCommerce_Adapter())->merge(wc_get_order($source->get_id()), wc_get_order($target->get_id()), $operation_id, 2);
	wcos_merge_service_assert($result === $retry, 'Completed Merge retry did not return the stable result.');
	$target_before_lifecycle = wc_get_order($target->get_id());
	$item_ids_before_lifecycle = array_map('intval', array_keys($target_before_lifecycle->get_items('line_item')));
	$tax_ids_before_lifecycle = array_map('intval', array_keys($target_before_lifecycle->get_items('tax')));
	$completed_record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
	$completed_pair = WCOS_Merge_Journal_Context::assert_executable_policy($completed_record);
	$terminal_authority = WCOS_Merge_Journal_Context::terminal_result_from_record($completed_record);
	wcos_merge_service_assert($result['target_item_ids'] === $terminal_authority['target_item_ids'], 'Terminal result did not bind the operation-owned target lines.');
	wcos_merge_service_assert(false === strpos(wp_json_encode($completed_record['context']['merge_terminal_result']), '@example.test'), 'Terminal result persisted fixture PII.');
	$tampered_terminal_record = $completed_record;
	$tampered_terminal_record['context']['merge_terminal_result']['target_order_id']++;
	$terminal_tamper_rejected = false;
	try {
		WCOS_Merge_Journal_Context::terminal_result_from_record($tampered_terminal_record);
	} catch (RuntimeException $exception) {
		$terminal_tamper_rejected = true;
	}
	wcos_merge_service_assert($terminal_tamper_rejected, 'Tampered Merge terminal result did not fail self-verification.');
	$relation_before_lifecycle = WCOS_Merge_Participation::state_for_pair(wc_get_order($source->get_id()), $target_before_lifecycle, $operation_id, $completed_pair['pair_fingerprint']);
	$ownership_before_lifecycle = array();
	foreach ($target_before_lifecycle->get_items('line_item') as $item) {
		$ownership_before_lifecycle[(int) $item->get_id()] = (string) $item->get_meta('_reduced_stock', true);
	}
	$target_before_lifecycle->set_status('processing');
	$target_before_lifecycle->save();
	$lifecycle_retry = (new WCOS_Merge_WooCommerce_Adapter())->merge(wc_get_order($source->get_id()), wc_get_order($target->get_id()), $operation_id, 2);
	$target_after_lifecycle = wc_get_order($target->get_id());
	$ownership_after_lifecycle = array();
	foreach ($target_after_lifecycle->get_items('line_item') as $item) {
		$ownership_after_lifecycle[(int) $item->get_id()] = (string) $item->get_meta('_reduced_stock', true);
	}
	wcos_merge_service_assert($result === $lifecycle_retry, 'Post-completion target lifecycle progression lost the stable terminal result.');
	wcos_merge_service_assert($item_ids_before_lifecycle === array_map('intval', array_keys($target_after_lifecycle->get_items('line_item'))), 'Terminal replay added target lines after lifecycle progression.');
	wcos_merge_service_assert($tax_ids_before_lifecycle === array_map('intval', array_keys($target_after_lifecycle->get_items('tax'))), 'Terminal replay added target tax rows after lifecycle progression.');
	wcos_merge_service_assert($relation_before_lifecycle === WCOS_Merge_Participation::state_for_pair(wc_get_order($source->get_id()), $target_after_lifecycle, $operation_id, $completed_pair['pair_fingerprint']), 'Terminal replay changed reciprocal relations after lifecycle progression.');
	wcos_merge_service_assert($ownership_before_lifecycle === $ownership_after_lifecycle, 'Terminal replay changed stock ownership after lifecycle progression.');
	wcos_merge_service_assert('completed' === $result['status'], 'Merge service did not complete.');
	wcos_merge_service_assert('non_force_trash_archive' === $result['retirement_policy'], 'Result lost the binding retirement policy.');
	wcos_merge_service_assert(2 === count($result['target_item_ids']), 'Merge did not create one fresh target line per source line.');
	wcos_merge_service_assert(2 === count(array_unique($result['target_item_ids'])), 'Merge coalesced or reused a target line.');
	wcos_merge_service_assert(empty(array_intersect($source_line_ids, $result['target_item_ids'])), 'Merge re-parented a persisted source item.');
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_service_assert('trash' === $source->get_status(), 'Approved non-force retirement did not trash the source.');
	wcos_merge_service_assert($shipping_before === WCOS_Merge_Recovery_Snapshot::participant_checkpoint($target)['order_props']['shipping_total'], 'Supported target shipping total changed.');
	wcos_merge_service_assert(3 === count($target->get_items('line_item')), 'Target line count is not additive.');
	$target_line_identities = array();
	foreach ($result['target_item_ids'] as $item_id) {
		$target_line_identities[] = WCOS_Line_Identity::from_item($target->get_item($item_id));
	}
	sort($source_line_identities, SORT_STRING);
	sort($target_line_identities, SORT_STRING);
	wcos_merge_service_assert($source_line_identities === $target_line_identities, 'Fresh target lines lost exact variation/business-metadata identity.');
	$target_rate_ids = array_map(static function($item) { return (int) $item->get_rate_id(); }, array_values($target->get_items('tax')));
	sort($target_rate_ids, SORT_NUMERIC);
	wcos_merge_service_assert(array(701, 702) === $target_rate_ids, 'Merge did not preserve multiple historical tax rates.');
	wcos_merge_service_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($target), 'Successful Merge changed physical stock.');
	foreach ($source->get_items('line_item') as $item) {
		wcos_merge_service_assert('' === (string) $item->get_meta('_reduced_stock', true), 'Retired source retained stock ownership.');
	}
	$target_reduced = array();
	foreach ($result['target_item_ids'] as $item_id) {
		$target_reduced[] = WCOS_Decimal::normalize($target->get_item($item_id)->get_meta('_reduced_stock', true), 6);
	}
	sort($target_reduced, SORT_STRING);
	wcos_merge_service_assert(array('1.500000', '2.000000') === $target_reduced, 'Target did not receive exact line stock ownership.');
	wcos_merge_service_cleanup($source, $target, $operation_id);
	}

	if (in_array($suite, array('all', 'crash_pre'), true)) {
	/* Exercise every remaining material crash boundary through the real adapter/service. */
	$compensation_windows = array(
		'before_target_write',
		'after_first_target_line_persistence',
		'after_all_target_lines_before_target_money',
		'after_target_money_tax_persistence',
		'after_ownership_migration_before_retirement',
		'before_source_retirement',
		'after_non_force_source_retirement',
	);
	foreach ($compensation_windows as $stage_under_test) {
		list($source, $target) = wcos_merge_service_pair($managed, 'crash-' . $stage_under_test, array('1', '2'));
		$operation_id = 'merge-service-crash-' . $stage_under_test . '-' . wp_generate_uuid4();
		$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
		$hit = false;
		$crash = static function($stage) use ($stage_under_test, &$hit) {
			if (!$hit && $stage_under_test === $stage) {
				$hit = true;
				throw new WCOS_Merge_Recovery_Interruption_Exception('Injected service crash at ' . $stage_under_test);
			}
		};
		add_action('wcos_merge_mutation_checkpoint', $crash, 10, 4);
		try {
			(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
		} catch (Throwable $throwable) {
			/* The synchronous recovery result is asserted below. */
		}
		remove_action('wcos_merge_mutation_checkpoint', $crash, 10);
		wcos_merge_service_assert($hit, 'Real service crash boundary did not execute: ' . $stage_under_test);
		$status = wcos_merge_service_status($source, $operation_id);
		wcos_merge_service_assert(in_array($status, array('compensated', 'manual_reconciliation'), true), 'Real service crash did not reach a safe outcome: ' . $stage_under_test);
		$fresh_source = wc_get_order($source->get_id());
		wcos_merge_service_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($fresh_source), 'Real service crash changed physical stock: ' . $stage_under_test);
		if ('manual_reconciliation' === $status) {
			wcos_merge_service_assert_manual_pair($source, $target, $operation_id);
		}
		wcos_merge_service_cleanup($source, $target, $operation_id);
	}

	/* A failure before journal authority performs no commercial or journal write. */
	list($source, $target) = wcos_merge_service_pair($managed, 'before-journal', array('1'));
	$operation_id = 'merge-service-before-journal-' . wp_generate_uuid4();
	$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
	$target_before = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
	$before_journal_hit = false;
	$before_journal = static function($stage) use (&$before_journal_hit) {
		if ('before_journal_start' === $stage) {
			$before_journal_hit = true;
			throw new WCOS_Merge_Recovery_Interruption_Exception('Injected crash before journal start.');
		}
	};
	add_action('wcos_merge_mutation_checkpoint', $before_journal, 10, 4);
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	} catch (Throwable $throwable) {
		/* No journal exists yet. */
	}
	remove_action('wcos_merge_mutation_checkpoint', $before_journal, 10);
	wcos_merge_service_assert($before_journal_hit, 'Before-journal service crash did not execute.');
	wcos_merge_service_assert(null === WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id), 'Before-journal crash created authority.');
	wcos_merge_service_assert(hash_equals($source_before, WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source->get_id()))), 'Before-journal crash changed source.');
	wcos_merge_service_assert(hash_equals($target_before, WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target->get_id()))), 'Before-journal crash changed target.');
	wcos_merge_service_cleanup($source, $target, $operation_id);
	}

	if ('all' === $suite || isset($forward_suites[$suite])) {
	/* Post-retirement forward-repair windows complete idempotently on retry. */
	$forward_windows = 'all' === $suite ? array(
		'before_forward_relations',
		'after_one_reciprocal_relation',
		'after_both_relations_before_verification',
		'after_verification_before_commit',
		'after_commit_before_complete',
	) : array($forward_suites[$suite]);
	foreach ($forward_windows as $stage_under_test) {
		list($source, $target) = wcos_merge_service_pair($managed, 'forward-' . $stage_under_test);
		$operation_id = 'merge-service-forward-' . $stage_under_test . '-' . wp_generate_uuid4();
		$hit = false;
		$crash = static function($stage) use ($stage_under_test, &$hit) {
			if (!$hit && $stage_under_test === $stage) {
				$hit = true;
				throw new WCOS_Merge_Recovery_Interruption_Exception('Injected forward crash at ' . $stage_under_test);
			}
		};
		add_action('wcos_merge_recovery_checkpoint', $crash, 10, 4);
		try {
			(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
		} catch (Throwable $throwable) {
			/* Retry below after removing the one-shot fault. */
		}
		remove_action('wcos_merge_recovery_checkpoint', $crash, 10);
		wcos_merge_service_assert($hit, 'Real forward service crash boundary did not execute: ' . $stage_under_test);
		$result = (new WCOS_Merge_WooCommerce_Adapter())->merge(wc_get_order($source->get_id()), wc_get_order($target->get_id()), $operation_id, 2);
		wcos_merge_service_assert('completed' === $result['status'], 'Real forward service crash did not complete on retry: ' . $stage_under_test);
		wcos_merge_service_cleanup($source, $target, $operation_id);
	}
	}

	if (in_array($suite, array('all', 'response_loss'), true)) {
	/* Response loss after complete replays the exact bounded result without writes. */
	list($source, $target) = wcos_merge_service_pair($managed, 'response-loss');
	$operation_id = 'merge-service-response-loss-' . wp_generate_uuid4();
	$response_loss_hit = false;
	$response_loss = static function($stage) use (&$response_loss_hit) {
		if ('after_complete' === $stage) {
			$response_loss_hit = true;
			throw new WCOS_Merge_Recovery_Interruption_Exception('Injected response loss after complete.');
		}
	};
	add_action('wcos_merge_mutation_checkpoint', $response_loss, 10, 4);
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	} catch (Throwable $throwable) {
		/* The completed replay is asserted below. */
	}
	remove_action('wcos_merge_mutation_checkpoint', $response_loss, 10);
	wcos_merge_service_assert($response_loss_hit, 'Post-complete response-loss boundary did not execute.');
	$result = (new WCOS_Merge_WooCommerce_Adapter())->merge(wc_get_order($source->get_id()), wc_get_order($target->get_id()), $operation_id, 2);
	wcos_merge_service_assert('completed' === $result['status'], 'Post-complete response loss did not replay safely.');
	wcos_merge_service_cleanup($source, $target, $operation_id);
	}

	if (in_array($suite, array('all', 'lease_loss'), true)) {
	/* Losing both participant leases between durable boundaries stops writes and recovers safely. */
	list($source, $target) = wcos_merge_service_pair($managed, 'lease-loss');
	$operation_id = 'merge-service-lease-loss-' . wp_generate_uuid4();
	$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
	$lease_loss_hit = false;
	$lease_loss = static function($stage, $event_source, $event_target, $event_operation) use (&$lease_loss_hit) {
		if (!$lease_loss_hit && 'before_target_money_tax_write' === $stage) {
			$lease_loss_hit = true;
			foreach (array($event_source->get_id(), $event_target->get_id()) as $order_id) {
				$token = WCOS_Operation_Lock::current_token_for($order_id, $event_operation);
				if (false !== $token) {
					WCOS_Operation_Lock::release($order_id, $token);
				}
			}
		}
	};
	add_action('wcos_merge_mutation_checkpoint', $lease_loss, 10, 4);
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	} catch (Throwable $throwable) {
		/* Recovery reacquires the canonical pair after the lost lease. */
	}
	remove_action('wcos_merge_mutation_checkpoint', $lease_loss, 10);
	wcos_merge_service_assert($lease_loss_hit, 'Real service lease-loss boundary did not execute.');
	wcos_merge_service_assert(in_array(wcos_merge_service_status($source, $operation_id), array('compensated', 'manual_reconciliation'), true), 'Lease loss did not preserve safe durable authority.');
	wcos_merge_service_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock(wc_get_order($source->get_id())), 'Lease-loss recovery changed physical stock.');
	wcos_merge_service_cleanup($source, $target, $operation_id);
	}

	if (in_array($suite, array('all', 'stock_guard_before', 'stock_guard_after'), true)) {
	/* Before-write attempts are blocked; after-write evidence becomes pair-wide manual-only. */
	$stock_phases = 'stock_guard_before' === $suite ? array('blocked_before_write') : ('stock_guard_after' === $suite ? array('observed_after_write') : array('blocked_before_write', 'observed_after_write'));
	foreach ($stock_phases as $stock_phase) {
		list($source, $target) = wcos_merge_service_pair($managed, 'stock-guard-' . $stock_phase);
		$operation_id = 'merge-service-stock-guard-' . $stock_phase . '-' . wp_generate_uuid4();
		$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
		$stock_hook_hit = false;
		$stock_fault = static function($stage) use (&$stock_hook_hit, $stock_phase, $managed) {
			if (!$stock_hook_hit && 'after_first_target_line_persistence' === $stage) {
				$stock_hook_hit = true;
				do_action('blocked_before_write' === $stock_phase ? 'woocommerce_product_before_set_stock' : 'woocommerce_product_set_stock', $managed);
			}
		};
		add_action('wcos_merge_mutation_checkpoint', $stock_fault, 10, 4);
		try {
			(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
		} catch (Throwable $throwable) {
			/* Stable recovery/manual outcome is asserted below. */
		}
		remove_action('wcos_merge_mutation_checkpoint', $stock_fault, 10);
		wcos_merge_service_assert($stock_hook_hit, 'Real service stock-guard boundary did not execute: ' . $stock_phase);
		if ('blocked_before_write' === $stock_phase) {
			try {
				(new WCOS_Merge_WooCommerce_Adapter())->merge(wc_get_order($source->get_id()), wc_get_order($target->get_id()), $operation_id, 2);
			} catch (Throwable $throwable) {
				/* A compensated saga intentionally remains non-restartable. */
			}
			$blocked_status = wcos_merge_service_status($source, $operation_id);
			wcos_merge_service_assert(in_array($blocked_status, array('compensated', 'manual_reconciliation'), true), 'Blocked stock attempt did not reach a safe recovery outcome.');
			if ('manual_reconciliation' === $blocked_status) {
				wcos_merge_service_assert_manual_pair($source, $target, $operation_id);
			}
		} else {
			wcos_merge_service_assert_manual_pair($source, $target, $operation_id);
		}
		wcos_merge_service_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock(wc_get_order($source->get_id())), 'Stock guard fixture changed physical stock: ' . $stock_phase);
		wcos_merge_service_cleanup($source, $target, $operation_id);
	}
	}

	if (in_array($suite, array('all', 'crash_pre'), true)) {
	/* Real partial ownership crash: first source write and checkpoint are durable before the second boundary. */
	list($source, $target) = wcos_merge_service_pair($managed, 'partial-ownership', array('1', '2'));
	$operation_id = 'merge-service-partial-' . wp_generate_uuid4();
	$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
	$ownership_boundaries = 0;
	$partial_observed = false;
	$partial_crash = static function($stage, $event_source) use (&$ownership_boundaries, &$partial_observed) {
		if ('before_source_line_ownership_write' !== $stage) {
			return;
		}
		$ownership_boundaries++;
		if (2 !== $ownership_boundaries) {
			return;
		}
		$markers = array_map(static function($item) {
			return (string) $item->get_meta('_reduced_stock', true);
		}, array_values(wc_get_order($event_source->get_id())->get_items('line_item')));
		$partial_observed = 1 === count(array_filter($markers, static function($marker) { return '' === $marker; }))
			&& 1 === count(array_filter($markers, static function($marker) { return '' !== $marker; }));
		throw new WCOS_Merge_Recovery_Interruption_Exception('Injected crash after one durable ownership checkpoint.');
	};
	add_action('wcos_merge_mutation_checkpoint', $partial_crash, 10, 4);
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	} catch (Throwable $throwable) {
		/* The durable journal outcome is asserted below. */
	}
	remove_action('wcos_merge_mutation_checkpoint', $partial_crash, 10);
	wcos_merge_service_assert($partial_observed, 'Crash fixture did not observe a genuinely partial durable source ownership migration.');
	wcos_merge_service_assert('compensated' === wcos_merge_service_status($source, $operation_id), 'Partial ownership crash did not compensate safely.');
	$source = wc_get_order($source->get_id());
	$target = wc_get_order($target->get_id());
	wcos_merge_service_assert(1 === count($target->get_items('line_item')), 'Compensation did not remove operation-owned target lines.');
	foreach ($source->get_items('line_item') as $item) {
		wcos_merge_service_assert('' !== (string) $item->get_meta('_reduced_stock', true), 'Compensation did not restore source stock ownership.');
	}
	wcos_merge_service_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($source), 'Partial ownership recovery changed physical stock.');
	$retry_failed_closed = false;
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	} catch (WCOS_Merge_Adapter_Exception $exception) {
		$retry_failed_closed = 'merge_compensated' === $exception->get_error_code();
	}
	wcos_merge_service_assert($retry_failed_closed, 'Compensated operation retry did not fail closed with a stable code.');
	wcos_merge_service_cleanup($source, $target, $operation_id);
	}

	if (in_array($suite, array('all', 'drift_stock', 'checkpoint_drift'), true)) {
	/* Persisted checkpoint-owned drift is rejected at the next service boundary before another commercial write. */
	list($source, $target) = wcos_merge_service_pair($managed, 'checkpoint-economic-drift', array('1'));
	$operation_id = 'merge-service-checkpoint-drift-' . wp_generate_uuid4();
	$preexisting_target_ids = array_map('intval', array_keys($target->get_items('line_item')));
	$drifted_total = '';
	$drifted_checkpoint = array();
	$tax_count_at_drift = 0;
	$checkpoint_drift_hit = false;
	$checkpoint_drift = static function($stage, $event_source, $event_target) use (&$checkpoint_drift_hit, &$drifted_total, &$drifted_checkpoint, &$tax_count_at_drift, $preexisting_target_ids) {
		if ($checkpoint_drift_hit || 'after_target_line_checkpoint' !== $stage) {
			return;
		}
		$checkpoint_drift_hit = true;
		$fresh_target = wc_get_order($event_target->get_id());
		$line = $fresh_target->get_item($preexisting_target_ids[0]);
		$line->set_quantity(7);
		$line->set_subtotal('70.00');
		$line->set_total('70.00');
		$line->save();
		WCOS_Order_Totals_Rebuilder::rebuild($fresh_target, 2);
		$fresh_target->save();
		$fresh_target = wc_get_order($event_target->get_id());
		$drifted_total = (string) $fresh_target->get_total();
		$drifted_checkpoint = WCOS_Merge_Recovery_Snapshot::participant_checkpoint($fresh_target);
		$tax_count_at_drift = count($fresh_target->get_items('tax'));
	};
	add_action('wcos_merge_mutation_checkpoint', $checkpoint_drift, 10, 4);
	try {
		(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
	} catch (Throwable $throwable) {
		/* Pair-wide manual authority and untouched drift are asserted below. */
	}
	remove_action('wcos_merge_mutation_checkpoint', $checkpoint_drift, 10);
	wcos_merge_service_assert($checkpoint_drift_hit, 'Persisted checkpoint-owned drift boundary did not execute.');
	wcos_merge_service_assert_manual_pair($source, $target, $operation_id);
	$fresh_target = wc_get_order($target->get_id());
	$fresh_line = $fresh_target->get_item($preexisting_target_ids[0]);
	$checkpoint_after_rejection = WCOS_Merge_Recovery_Snapshot::participant_checkpoint($fresh_target);
	unset($drifted_checkpoint['relation_meta'], $checkpoint_after_rejection['relation_meta']);
	wcos_merge_service_assert('7' === (string) $fresh_line->get_quantity(), 'Recovery overwrote persisted target quantity drift.');
	wcos_merge_service_assert($drifted_total === (string) $fresh_target->get_total(), 'Recovery overwrote persisted target amount drift.');
	wcos_merge_service_assert($drifted_checkpoint === $checkpoint_after_rejection, 'Recovery changed checkpoint-owned target commercial state after rejecting drift.');
	wcos_merge_service_assert($tax_count_at_drift === count($fresh_target->get_items('tax')), 'The rejected boundary persisted new tax rows.');
	wcos_merge_service_cleanup(wc_get_order($source->get_id()), $fresh_target, $operation_id);
	}

	if (in_array($suite, array('all', 'drift_stock'), true)) {
	/* Independent immutable-context drifts fail closed before automatic recovery writes and remain untouched. */
	$drift_cases = array(
		'source_billing' => static function(WC_Order $source, WC_Order $target) {
			$source->set_billing_address_1('Externally changed billing address');
			$source->save();
		},
		'target_payment' => static function(WC_Order $source, WC_Order $target) {
			$target->set_payment_method_title('Externally changed payment title');
			$target->save();
		},
		'target_shipping_item' => static function(WC_Order $source, WC_Order $target) {
			$shipping_items = array_values($target->get_items('shipping'));
			$shipping = $shipping_items[0];
			$shipping->set_method_title('Externally changed shipping method');
			$shipping->save();
			$line_items = array_values($target->get_items('line_item'));
			$line = $line_items[0];
			$line->set_name('Externally changed target item');
			$line->update_meta_data('Merge fixture', 'external-item-state');
			$line->save();
		},
	);
	foreach ($drift_cases as $label => $mutate) {
		list($source, $target) = wcos_merge_service_pair($managed, 'drift-' . $label, array('1'), 'target_shipping_item' === $label);
		$operation_id = 'merge-service-drift-' . $label . '-' . wp_generate_uuid4();
		$source_lines_before = array_values($source->get_items('line_item'));
		$source_quantity = (string) $source_lines_before[0]->get_quantity();
		$target_total_at_drift = '';
		$drift_once = true;
		$drift = static function($stage, $event_source, $event_target) use (&$drift_once, &$target_total_at_drift, $mutate) {
			if ($drift_once && 'after_target_money_tax_persistence' === $stage) {
				$drift_once = false;
				$fresh_target = wc_get_order($event_target->get_id());
				$target_total_at_drift = (string) $fresh_target->get_total();
				$mutate(wc_get_order($event_source->get_id()), $fresh_target);
				throw new WCOS_Merge_Recovery_Interruption_Exception('Injected immutable participant drift.');
			}
		};
		add_action('wcos_merge_mutation_checkpoint', $drift, 10, 4);
		try {
			(new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
		} catch (Throwable $throwable) {
			/* Manual state is asserted after the recovery coordinator returns. */
		}
		remove_action('wcos_merge_mutation_checkpoint', $drift, 10);
		wcos_merge_service_assert_manual_pair($source, $target, $operation_id);
		$fresh_source = wc_get_order($source->get_id());
		$fresh_target = wc_get_order($target->get_id());
		$fresh_source_lines = array_values($fresh_source->get_items('line_item'));
		wcos_merge_service_assert($source_quantity === (string) $fresh_source_lines[0]->get_quantity(), 'Immutable drift fixture changed source quantity.');
		if ('source_billing' === $label) {
			wcos_merge_service_assert('Externally changed billing address' === $fresh_source->get_billing_address_1(), 'Recovery overwrote external billing state.');
		} elseif ('target_payment' === $label) {
			wcos_merge_service_assert('Externally changed payment title' === $fresh_target->get_payment_method_title(), 'Recovery overwrote external payment state.');
		} else {
			$fresh_shipping_items = array_values($fresh_target->get_items('shipping'));
			$fresh_target_lines = array_values($fresh_target->get_items('line_item'));
			wcos_merge_service_assert('Externally changed shipping method' === $fresh_shipping_items[0]->get_method_title(), 'Recovery overwrote external target shipping state.');
			wcos_merge_service_assert('Externally changed target item' === $fresh_target_lines[0]->get_name(), 'Recovery overwrote external target item state.');
		}
		wcos_merge_service_assert($target_total_at_drift === (string) $fresh_target->get_total(), 'Immutable-context recovery overwrote target totals.');
		wcos_merge_service_cleanup($fresh_source, $fresh_target, $operation_id);
	}

	/* Service-level stock matrix: unmanaged, backorder, fractional, parent-managed variation, and deleted product. */
	$unmanaged = wcos_merge_service_product('Merge unmanaged fixture', false);
	$backorder = wcos_merge_service_product('Merge backorder fixture', true, true, '-2.5');
	list($parent, $variation) = wcos_merge_service_parent_managed_variation();
	$deleted = wcos_merge_service_product('Merge deleted-product fixture');
	$products = array_merge($products, array($unmanaged, $backorder, $variation, $parent));
	$stock_cases = array(
		'unmanaged' => array($unmanaged, array(null), false),
		'backorder_fractional' => array($backorder, array('fractional'), false),
		'parent_managed_variation' => array($variation, array('1'), false),
		'deleted_product' => array($deleted, array('1'), true),
	);
	foreach ($stock_cases as $label => $case) {
		list($case_product, $markers, $delete_product) = $case;
		list($source, $target) = wcos_merge_service_pair($case_product, 'stock-' . $label, $markers);
		$operation_id = 'merge-service-stock-' . $label . '-' . wp_generate_uuid4();
		if ($delete_product) {
			$case_product->delete(true);
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
		}
		$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
		$result = (new WCOS_Merge_WooCommerce_Adapter())->merge($source, $target, $operation_id, 2);
		wcos_merge_service_assert('completed' === $result['status'], 'Stock matrix Merge did not complete: ' . $label);
		$fresh_target = wc_get_order($target->get_id());
		wcos_merge_service_assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($fresh_target), 'Stock matrix changed physical stock: ' . $label);
		wcos_merge_service_cleanup(wc_get_order($source->get_id()), $fresh_target, $operation_id);
	}
	}
} finally {
	update_option('woocommerce_manage_stock', $manage_stock_before);
	foreach ($products as $product) {
		if ($product instanceof WC_Product && $product->get_id() && wc_get_product($product->get_id())) {
			$product->delete(true);
		}
	}
}

echo "merge-service-adapter-ok\n";
