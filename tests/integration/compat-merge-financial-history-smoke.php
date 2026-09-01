<?php

if (!defined('ABSPATH')) {
	exit(1);
}

/** Synthetic read-only order view used only to prove malformed refund totals fail closed. */
final class WCOS_Compat_006_Malformed_Order extends WC_Order {
	public function get_total_refunded() {
		return '1.00';
	}

	public function get_refunds() {
		return array();
	}
}

/** WOS-COMPAT-006 paid/refund Merge compatibility matrix. */
final class WCOS_Compat_Merge_Financial_Matrix {
	private static $order_ids = array();
	private static $product_ids = array();
	private static $review_ids = array();
	private static $operation_ids = array();
	private static $operator_id = 0;
	private static $old_statuses = array();
	private static $results = array();

	public static function run() {
		$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
		self::assert(!empty($admins), 'WOS-COMPAT-006 requires an administrator fixture.');
		self::$operator_id = absint($admins[0]);
		wp_set_current_user(self::$operator_id);
		self::$old_statuses = (array) get_option('order_splitter_status_allowed', array('wc-processing'));
		update_option('order_splitter_status_allowed', array('wc-pending', 'wc-on-hold', 'wc-processing'));

		try {
			self::ordinary_regression();
			self::payment_evidence_success_matrix();
			self::multi_line_archival_success();
			self::refund_success_matrix();
			self::financial_rejection_matrix();
			self::financial_drift_and_transient_authority();
			self::response_loss_recovery_stock_and_concurrency();
			self::wos_compat_005_durable_replay();
			self::version_and_release_regression();
			echo wp_json_encode(array('status' => 'pass', 'task' => 'WOS-COMPAT-006', 'results' => self::$results), JSON_PRETTY_PRINT) . "\n";
		} finally {
			self::cleanup();
			update_option('order_splitter_status_allowed', self::$old_statuses);
			wp_set_current_user(self::$operator_id);
		}
	}

	private static function product($label, $managed = false) {
		$product = new WC_Product_Simple();
		$product->set_name('WOS COMPAT 006 ' . $label);
		$product->set_status('publish');
		$product->set_regular_price('10.00');
		$product->set_price('10.00');
		$product->set_tax_status('none');
		$product->set_manage_stock((bool) $managed);
		if ($managed) {
			$product->set_stock_quantity(100);
		}
		$product_id = (int) $product->save();
		self::assert($product_id > 0, 'Product fixture could not be saved: ' . $label);
		self::$product_ids[] = $product_id;
		return wc_get_product($product_id);
	}

	private static function order($label, $status = 'pending') {
		$order = wc_create_order(array('status' => 'pending'));
		self::assert($order instanceof WC_Order, 'Order fixture could not be created: ' . $label);
		self::$order_ids[] = $order->get_id();
		$order->set_currency('USD');
		$order->set_prices_include_tax(false);
		$order->set_billing_first_name('Financial');
		$order->set_billing_last_name('Compatibility');
		$order->set_billing_email('compat-006-' . sanitize_key($label) . '@example.test');
		$order->set_billing_address_1('6 Financial Boundary Way');
		$order->set_billing_city('Invariant City');
		$order->set_billing_country('US');
		$order->set_shipping_first_name('Financial');
		$order->set_shipping_last_name('Compatibility');
		$order->set_shipping_address_1('7 Settlement Neutral Road');
		$order->set_shipping_city('Invariant City');
		$order->set_shipping_country('US');
		$order->set_payment_method('bacs');
		$order->set_payment_method_title('Historical bank transfer');
		$order->set_status($status);
		$order->save();
		return wc_get_order($order->get_id());
	}

	private static function line(WC_Order $order, WC_Product $product, array $values = array()) {
		$item = new WC_Order_Item_Product();
		$item->set_name(isset($values['name']) ? $values['name'] : 'Exact configured financial-boundary line');
		$item->set_product_id($product->get_id());
		$item->set_quantity(isset($values['quantity']) ? $values['quantity'] : '1.000000');
		$item->set_subtotal(isset($values['subtotal']) ? $values['subtotal'] : '10.00');
		$item->set_total(isset($values['total']) ? $values['total'] : '0.00');
		$item->set_subtotal_tax(isset($values['subtotal_tax']) ? $values['subtotal_tax'] : '0.00');
		$item->set_total_tax(isset($values['total_tax']) ? $values['total_tax'] : '0.00');
		$item->set_taxes(isset($values['taxes']) ? $values['taxes'] : array('subtotal' => array(), 'total' => array()));
		foreach (isset($values['meta']) ? (array) $values['meta'] : array('Configuration' => 'financial-boundary') as $key => $value) {
			$item->add_meta_data((string) $key, $value, true);
		}
		if (array_key_exists('reduced_stock', $values) && null !== $values['reduced_stock']) {
			$item->add_meta_data('_reduced_stock', $values['reduced_stock'], true);
		}
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function shipping(WC_Order $order, $label, $total) {
		$item = new WC_Order_Item_Shipping();
		$item->set_method_title($label);
		$item->set_method_id('flat_rate');
		$item->set_instance_id(6);
		$item->set_total($total);
		$item->set_taxes(array('total' => array()));
		$item->add_meta_data('Carrier reference', 'financial-target-shipping', true);
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function fee(WC_Order $order, $label, $total) {
		$item = new WC_Order_Item_Fee();
		$item->set_name($label);
		$item->set_amount($total);
		$item->set_total($total);
		$item->set_total_tax('0.00');
		$item->set_taxes(array('total' => array()));
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function coupon(WC_Order $order, $code, $discount) {
		$item = new WC_Order_Item_Coupon();
		$item->set_code($code);
		$item->set_discount($discount);
		$item->set_discount_tax('0.00');
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function tax(WC_Order $order, $rate_id, $cart, $shipping) {
		$item = new WC_Order_Item_Tax();
		$item->set_rate_id((int) $rate_id);
		$item->set_label('Historical financial rate ' . (int) $rate_id);
		$item->set_compound(false);
		$item->set_tax_total($cart);
		$item->set_shipping_tax_total($shipping);
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function finalize(WC_Order $order) {
		WCOS_Order_Totals_Rebuilder::rebuild($order, 2);
		$order->save();
		$order = wc_get_order($order->get_id());
		WCOS_Order_Totals_Rebuilder::assert_consistent($order, 2);
		return $order;
	}

	private static function request(WC_Order $source, WC_Order $target) {
		return array(
			'source_order_id' => $source->get_id(),
			'target_order_id' => $target->get_id(),
			'nonce' => wp_create_nonce('wcos_merge_order_' . $source->get_id()),
		);
	}

	private static function execute_controller(WC_Order $source, WC_Order $target) {
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		$request = self::request($source, $target);
		$review = $controller->review_request($request);
		self::$review_ids[] = $review['review_id'];
		$confirmation = $controller->confirm_request(array_merge($request, array(
			'review_id' => $review['review_id'],
			'review_token' => $review['review_token'],
		)));
		self::$operation_ids[$source->get_id()][] = $confirmation['operation_id'];
		$result = $controller->execute_request(array_merge($request, array(
			'operation_id' => $confirmation['operation_id'],
			'confirmation_token' => $confirmation['confirmation_token'],
		)));
		return array($review, $confirmation, $result);
	}

	private static function create_refund(WC_Order $order, $amount, array $line_items = array(), $reason = 'WOS-COMPAT-006 refund fixture') {
		$args = array(
			'order_id' => $order->get_id(),
			'amount' => $amount,
			'reason' => $reason,
			'refund_payment' => false,
			'restock_items' => false,
		);
		if (!empty($line_items)) {
			$args['line_items'] = $line_items;
		}
		$refund = wc_create_refund($args);
		self::assert($refund instanceof WC_Order_Refund, 'Canonical refund fixture could not be created.');
		self::$order_ids[] = $refund->get_id();
		return $refund;
	}

	private static function ordinary_regression() {
		$product = self::product('ordinary-regression');
		$source = self::order('ordinary-source');
		$target = self::order('ordinary-target', 'on-hold');
		self::line($source, $product, array('subtotal' => '8.00', 'total' => '6.00'));
		$target_line = self::line($target, $product, array('subtotal' => '10.00', 'total' => '9.00'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		$line = reset($report['plan']['lines']);
		self::assert(empty($report['target_has_financial_history']), 'Ordinary target was classified as financial.');
		self::assert('coalesce' === $line['action'] && (int) $target_line->get_id() === (int) $line['target_item_id'], 'WOS-COMPAT-005 coalescing changed for an ordinary pair.');
		self::assert(WCOS_Merge_Preflight::POLICY_VERSION === (int) $report['policy']['policy_version'], 'Current Merge preflight version was not disclosed.');

		self::$results['ordinary_regression'] = array(
			'cases' => '1-2,54',
			'nonzero_source_supported' => true,
			'coalesce_unchanged' => true,
		);
	}

	private static function payment_evidence_success_matrix() {
		$product = self::product('payment-evidence');
		$evidence = array('transaction', 'paid_date', 'paid_status', 'transaction_and_paid_date');
		foreach ($evidence as $kind) {
			$source = self::order($kind . '-source');
			$target = self::order($kind . '-target', 'on-hold');
			self::line($source, $product, array(
				'subtotal' => '9.99', 'total' => '0.00', 'subtotal_tax' => '1.23', 'total_tax' => '0.00',
				'taxes' => array('subtotal' => array(606 => '1.23'), 'total' => array(606 => '0.00')),
			));
			$existing = self::line($target, $product, array('subtotal' => '15.00', 'total' => '15.00'));
			$source = self::finalize($source);
			$target = self::finalize($target);
			if (in_array($kind, array('transaction', 'transaction_and_paid_date'), true)) {
				$target->set_transaction_id('compat-006-' . $kind);
			}
			if (in_array($kind, array('paid_date', 'transaction_and_paid_date'), true)) {
				$target->set_date_paid(1725148800);
			}
			if ('paid_status' === $kind) {
				$target->set_status('processing');
			}
			$target->save();
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			$before = self::target_immutable_snapshot($target, array($existing->get_id()));
			$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
			$planned = array_values($report['plan']['lines']);
			self::assert(!empty($report['target_has_financial_history']), 'Payment evidence was not classified as target financial history: ' . $kind);
			self::assert('fresh_target_line_only' === $report['plan']['line_policy'] && 'fresh_target_line' === $planned[0]['action'], 'Financial target did not force a fresh line: ' . $kind);
			self::assert(empty($report['plan']['tax_template_rate_ids']) && 'preserve_target_rows_only' === $report['plan']['tax_template_policy'], 'Financial target allowed source tax-row materialization: ' . $kind);
			list($review, $confirmation, $result) = self::execute_controller($source, $target);
			self::assert('completed' === $result['status'], 'Payment-evidence Merge did not complete: ' . $kind);
			self::assert(!empty($review['summary']['target_financial_history_retained'])
				&& 'none' === $review['summary']['source_financial_history']
				&& 1 === (int) $review['summary']['settlement_neutral_line_count']
				&& 'fresh_target_line_only' === $review['summary']['financial_line_disposition']
				&& 'preserve_exact' === $review['summary']['target_financial_authority_disposition']
				&& 'unchanged' === $review['summary']['target_payable_tax_disposition']
				&& 'never' === $review['summary']['payment_refund_api_disposition'], 'Review omitted bounded financial disclosure: ' . $kind);
			$target = wc_get_order($target->get_id());
			self::assert($before === self::target_immutable_snapshot($target, array($existing->get_id())), 'Target financial authority changed: ' . $kind);
			self::assert(2 === count($target->get_items('line_item')), 'Financial target did not receive one independent fresh line: ' . $kind);
			$journal = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $confirmation['operation_id']);
			$pair = WCOS_Merge_Journal_Context::assert_executable_policy($journal);
			self::assert(WCOS_Merge_Order_Service::POLICY_VERSION === WCOS_Merge_Journal_Context::service_policy_for_pair($pair)
				&& isset($pair['financial_authority'])
				&& hash_equals($report['financial_policy_fingerprint'], $pair['financial_authority_fingerprint']), 'Durable journal omitted exact financial authority: ' . $kind);
			$journal_document = wp_json_encode($journal);
			self::assert(false === strpos($journal_document, 'compat-006-' . $kind)
				&& false === strpos($journal_document, '1725148800'), 'Durable journal retained raw payment evidence: ' . $kind);
		}

		self::$results['payment_evidence_success'] = array(
			'cases' => '3-6,11,26-36',
			'evidence' => $evidence,
			'fresh_only' => true,
			'target_financial_authority_exact' => true,
		);
	}

	private static function multi_line_archival_success() {
		$product = self::product('multi-line-archival');
		$source = self::order('multi-line-archival-source');
		$target = self::order('multi-line-archival-target', 'on-hold');
		self::line($source, $product, array(
			'name' => 'First fully discounted line',
			'subtotal' => '7.00',
			'total' => '0.00',
			'subtotal_tax' => '0.70',
			'total_tax' => '0.00',
			'taxes' => array('subtotal' => array(610 => '0.70'), 'total' => array(610 => '0.00')),
			'meta' => array('Configuration' => 'multi-first'),
		));
		self::line($source, $product, array(
			'name' => 'Second fully discounted line',
			'subtotal' => '5.00',
			'total' => '0.00',
			'meta' => array('Configuration' => 'multi-second'),
		));
		self::shipping($source, 'Source archived shipping', '4.00');
		self::fee($source, 'Source archived fee', '2.00');
		self::coupon($source, 'source-archived-coupon', '3.00');
		$existing = self::line($target, $product, array('total' => '10.00', 'meta' => array('Configuration' => 'target-existing')));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$target->set_transaction_id('compat-006-multi-line-archival');
		$target->save();
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$source_archival_before = array(
			'shipping' => self::items_snapshot($source, 'shipping'),
			'fee' => self::items_snapshot($source, 'fee'),
			'coupon' => self::items_snapshot($source, 'coupon'),
		);
		$target_before = self::target_immutable_snapshot($target, array($existing->get_id()));
		$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		$lines = array_values($report['plan']['lines']);
		self::assert(2 === count($lines)
			&& empty(array_filter($lines, static function(array $line) {
				return 'fresh_target_line' !== $line['action'] || 0 !== (int) $line['target_item_id'];
			})), 'A multi-line financial target plan did not force every neutral line fresh.');
		list($review, $confirmation, $result) = self::execute_controller($source, $target);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		self::assert('completed' === $result['status']
			&& 2 === (int) $review['summary']['settlement_neutral_line_count']
			&& 3 === count($target->get_items('line_item')), 'Multi-line settlement-neutral Merge did not complete with two fresh lines.');
		self::assert($target_before === self::target_immutable_snapshot($target, array($existing->get_id())), 'Multi-line archival Merge changed target settlement authority.');
		self::assert($source_archival_before === array(
			'shipping' => self::items_snapshot($source, 'shipping'),
			'fee' => self::items_snapshot($source, 'fee'),
			'coupon' => self::items_snapshot($source, 'coupon'),
		), 'Source shipping, fee, or coupon history did not remain on the retired source.');

		self::$results['multi_line_archival_success'] = array(
			'cases' => '10-12',
			'fresh_line_count' => 2,
			'source_charges_retained' => true,
			'target_settlement_unchanged' => true,
		);
	}

	private static function refund_success_matrix() {
		$product = self::product('refund-history');
		$kinds = array('product_line', 'shipping_fee', 'manual');
		foreach ($kinds as $kind) {
			$source = self::order('refund-' . $kind . '-source');
			$target = self::order('refund-' . $kind . '-target', 'on-hold');
			self::line($source, $product, array('subtotal' => '12.00', 'total' => '0.00', 'meta' => array('Configuration' => 'canonical-match')));
			$target_line = self::line($target, $product, array('subtotal' => '20.00', 'total' => '20.00', 'meta' => array('Configuration' => 'canonical-match')));
			$target_shipping = self::shipping($target, 'Target financial shipping', '3.00');
			$target_fee = self::fee($target, 'Target financial fee', '2.00');
			self::coupon($target, 'financial-target-coupon', '1.00');
			self::tax($target, 607, '0.00', '0.00');
			$source = self::finalize($source);
			$target = self::finalize($target);
			$refund_reason = '';
			if ('product_line' === $kind) {
				$refund_reason = 'Partial product-line refund';
				self::create_refund($target, '2.00', array(
					$target_line->get_id() => array('qty' => 1, 'refund_total' => '2.00', 'refund_tax' => array()),
				), $refund_reason);
			} elseif ('shipping_fee' === $kind) {
				$refund_reason = 'Partial shipping and fee refund';
				self::create_refund($target, '2.00', array(
					$target_shipping->get_id() => array('qty' => 0, 'refund_total' => '1.00', 'refund_tax' => array()),
					$target_fee->get_id() => array('qty' => 0, 'refund_total' => '1.00', 'refund_tax' => array()),
				), $refund_reason);
			} else {
				$refund_reason = 'Manual order-level refund';
				self::create_refund($target, '1.50', array(), $refund_reason);
			}
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			$existing_line_ids = array_map('absint', array_keys($target->get_items('line_item')));
			$before = self::target_immutable_snapshot($target, $existing_line_ids);
			$before_financial = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
			$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
			$planned = array_values($report['plan']['lines']);
			self::assert('fresh_target_line' === $planned[0]['action'] && 0 === (int) $planned[0]['target_item_id'], 'Refund-bearing canonical match was coalesced: ' . $kind);

			$api_events = 0;
			$api_probe = static function() use (&$api_events) {
				$api_events++;
			};
			$outbound_requests = 0;
			$http_probe = static function($preempt) use (&$outbound_requests) {
				$outbound_requests++;
				return new WP_Error('wos_compat_006_outbound_blocked', 'Unexpected outbound request during Merge.');
			};
			add_action('woocommerce_payment_complete', $api_probe, PHP_INT_MAX, 1);
			add_action('woocommerce_order_refunded', $api_probe, PHP_INT_MAX, 2);
			add_action('woocommerce_refund_created', $api_probe, PHP_INT_MAX, 2);
			add_filter('pre_http_request', $http_probe, PHP_INT_MAX, 3);
			try {
				list($review, $confirmation, $result) = self::execute_controller($source, $target);
			} finally {
				remove_action('woocommerce_payment_complete', $api_probe, PHP_INT_MAX);
				remove_action('woocommerce_order_refunded', $api_probe, PHP_INT_MAX);
				remove_action('woocommerce_refund_created', $api_probe, PHP_INT_MAX);
				remove_filter('pre_http_request', $http_probe, PHP_INT_MAX);
			}
			self::assert('completed' === $result['status'] && 0 === $api_events && 0 === $outbound_requests, 'Merge invoked a payment/refund lifecycle API or outbound request: ' . $kind);
			$journal = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $confirmation['operation_id']);
			self::assert(false === strpos(wp_json_encode($journal), $refund_reason), 'Durable journal retained a raw refund reason: ' . $kind);
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			self::assert($before === self::target_immutable_snapshot($target, $existing_line_ids), 'Refund target immutable history changed: ' . $kind);
			$after_financial = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
			self::assert($before_financial['target'] === $after_financial['target'], 'Target refund fingerprint changed: ' . $kind);
			self::assert(2 === count($target->get_items('line_item')), 'Refund target did not receive exactly one fresh source line: ' . $kind);
			self::assert(empty(array_diff($existing_line_ids, array_map('absint', array_keys($target->get_items('line_item'))))), 'A pre-existing refunded target line disappeared: ' . $kind);
			self::assert(empty(self::synthetic_financial_meta($target)), 'Synthetic settlement/payment metadata was created: ' . $kind);
		}

		self::$results['refund_success'] = array(
			'cases' => '7-9,26-36',
			'refund_kinds' => $kinds,
			'references_preserved' => true,
			'payment_refund_events' => 0,
		);
	}

	private static function financial_rejection_matrix() {
		$product = self::product('financial-rejections');
		$target_cases = array(
			'positive' => array(array('total' => '1.00'), 'financial_target_nonzero_source_total'),
			'negative' => array(array('total' => '-1.00'), 'financial_target_nonzero_source_total'),
			'tax' => array(array('total' => '0.00', 'subtotal_tax' => '0.01', 'total_tax' => '0.01', 'taxes' => array('subtotal' => array(608 => '0.01'), 'total' => array(608 => '0.01'))), 'financial_target_nonzero_source_tax'),
		);
		foreach ($target_cases as $label => $fixture) {
			$source = self::order('reject-' . $label . '-source');
			$target = self::order('reject-' . $label . '-target');
			self::line($source, $product, $fixture[0]);
			self::line($target, $product, array('total' => '10.00'));
			if ('tax' === $label) {
				self::tax($source, 608, '0.01', '0.00');
			}
			$source = self::finalize($source);
			$target = self::finalize($target);
			if ('tax' === $label) {
				$source_items = $source->get_items('line_item');
				$source_line = reset($source_items);
				$source_line->set_taxes(array('subtotal' => array(608 => '0.01'), 'total' => array(608 => '0.01')));
				$source_line->save();
				$source = wc_get_order($source->get_id());
			}
			$target->set_transaction_id('reject-' . $label);
			$target->save();
			self::expect_reason($source, wc_get_order($target->get_id()), $fixture[1]);
		}

		$source = self::order('reject-cancel-source');
		$target = self::order('reject-cancel-target');
		self::line($source, $product, array('name' => 'positive cancellation', 'total' => '2.00'));
		self::line($source, $product, array('name' => 'negative cancellation', 'total' => '-2.00'));
		self::line($target, $product, array('total' => '10.00'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$target->set_transaction_id('reject-cancellation');
		$target->save();
		self::expect_reason($source, wc_get_order($target->get_id()), 'financial_target_nonzero_source_total');

		$source = self::order('terminal-source');
		$target = self::order('terminal-target');
		self::line($source, $product);
		self::line($target, $product, array('total' => '10.00'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$target->set_status('refunded');
		$target->save();
		self::expect_reason($source, wc_get_order($target->get_id()), 'incompatible_status');

		$source = self::order('malformed-target-source');
		$target = self::order('malformed-target');
		self::line($source, $product);
		self::line($target, $product, array('total' => '10.00'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$malformed_target = new WCOS_Compat_006_Malformed_Order($target->get_id());
		self::assert('malformed_refund_authority' === WCOS_Merge_Preflight::report($source, $malformed_target, 2)['reason'], 'Malformed target refund total did not fail closed.');

		$source_history = array('transaction', 'paid_date', 'paid_status', 'refund', 'malformed_refund_total', 'neutral_transaction');
		foreach ($source_history as $kind) {
			$source = self::order('source-financial-' . $kind);
			$target = self::order('source-financial-' . $kind . '-target');
			self::line($source, $product, array('total' => 'refund' === $kind ? '10.00' : '0.00'));
			self::line($target, $product, array('total' => '10.00'));
			$source = self::finalize($source);
			$target = self::finalize($target);
			if (in_array($kind, array('transaction', 'neutral_transaction'), true)) {
				$source->set_transaction_id('source-financial-' . $kind);
				$source->save();
			} elseif ('paid_date' === $kind) {
				$source->set_date_paid(1725148800);
				$source->save();
			} elseif ('paid_status' === $kind) {
				$source->set_status('processing');
				$source->save();
			} elseif ('refund' === $kind) {
				self::create_refund($source, '1.00');
			}
			$source = wc_get_order($source->get_id());
			$source_view = 'malformed_refund_total' === $kind ? new WCOS_Compat_006_Malformed_Order($source->get_id()) : $source;
			self::expect_reason($source_view, $target, 'source_financial_history_not_movable', $source);
		}

		self::$results['financial_rejections'] = array(
			'cases' => '13-25',
			'per_line_neutrality' => true,
			'terminal_and_malformed_fail_closed' => true,
			'source_financial_history' => $source_history,
			'pre_journal_no_participation' => true,
		);
	}

	private static function financial_drift_and_transient_authority() {
		$product = self::product('financial-drift');
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		$drifts = array('transaction', 'paid_date_status', 'refund_added', 'refund_changed', 'refund_deleted', 'source_total_tax');
		foreach ($drifts as $kind) {
			$source = self::order('drift-' . $kind . '-source');
			$target = self::order('drift-' . $kind . '-target', 'on-hold');
			$source_line = self::line($source, $product);
			self::line($target, $product, array('total' => '10.00'));
			$source = self::finalize($source);
			$target = self::finalize($target);
			$target->set_transaction_id('drift-initial-' . $kind);
			$target->save();
			if (in_array($kind, array('refund_changed', 'refund_deleted'), true)) {
				self::create_refund($target, '1.00', array(), 'Refund before Review ' . $kind);
			}
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			$request = self::request($source, $target);
			$review = $controller->review_request($request);
			self::$review_ids[] = $review['review_id'];
			if ('transaction' === $kind) {
				$target->set_transaction_id('drift-changed-transaction');
				$target->save();
			} elseif ('paid_date_status' === $kind) {
				$target->set_date_paid(1725235200);
				$target->set_status('processing');
				$target->save();
			} elseif ('refund_added' === $kind) {
				self::create_refund($target, '1.00');
			} elseif ('refund_changed' === $kind) {
				$refunds = $target->get_refunds();
				$refund = reset($refunds);
				self::assert($refund instanceof WC_Order_Refund, 'Refund-change drift fixture is missing.');
				$refund->set_reason('Refund reason changed after Review');
				$refund->save();
			} elseif ('refund_deleted' === $kind) {
				$refunds = $target->get_refunds();
				$refund = reset($refunds);
				self::assert($refund instanceof WC_Order_Refund, 'Refund-delete drift fixture is missing.');
				$refund->delete(true);
			} else {
				$source_line = wc_get_order($source->get_id())->get_item($source_line->get_id());
				$source_line->set_taxes(array('subtotal' => array(609 => '0.01'), 'total' => array(609 => '0.01')));
				$source_line->save();
				$source = wc_get_order($source->get_id());
			}
			$before_journals = self::journal_option_count();
			self::expect_transport_one_of(static function() use ($controller, $request, $review) {
				$controller->confirm_request(array_merge($request, array(
					'review_id' => $review['review_id'],
					'review_token' => $review['review_token'],
				)));
			}, array('review_source_changed', 'review_target_changed', 'review_pair_changed', 'review_authority_changed'));
			self::assert($before_journals === self::journal_option_count(), 'Financial drift created a durable journal: ' . $kind);
		}

		$source = self::order('stale-review-source');
		$target = self::order('stale-review-target');
		self::line($source, $product);
		self::line($target, $product, array('total' => '10.00'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$target->set_transaction_id('stale-pre-006-review');
		$target->save();
		$report = WCOS_Merge_Preflight::assert_supported($source, wc_get_order($target->get_id()), 2);
		$stored = WCOS_Merge_Review_Store::create($source, wc_get_order($target->get_id()), $report, self::$operator_id);
		self::$review_ids[] = $stored['review_id'];
		$key = 'wcos_merge_review_' . hash('sha256', sanitize_key($stored['review_id']));
		$record = get_transient($key);
		$record['schema_version'] = 1;
		set_transient($key, $record, WCOS_Merge_Review_Store::TTL);
		$stale_rejected = false;
		try {
			WCOS_Merge_Review_Store::verify($source, wc_get_order($target->get_id()), $stored['review_id'], $stored['review_token'], self::$operator_id);
		} catch (WCOS_Merge_Review_Exception $exception) {
			$stale_rejected = 'invalid_token' === $exception->get_reason();
		}
		self::assert($stale_rejected, 'A pre-006 Review schema minted current financial authority.');

		$current = WCOS_Merge_Review_Store::create($source, wc_get_order($target->get_id()), $report, self::$operator_id);
		self::$review_ids[] = $current['review_id'];
		$confirmation = WCOS_Merge_Confirmation_Store::create($source, wc_get_order($target->get_id()), $current['authority'], self::$operator_id);
		self::$operation_ids[$source->get_id()][] = $confirmation['operation_id'];
		$confirmation_key = 'wcos_merge_confirm_' . hash('sha256', sanitize_key($confirmation['operation_id']));
		$confirmation_record = get_transient($confirmation_key);
		$confirmation_record['schema_version'] = WCOS_Merge_Confirmation_Store::PREVIOUS_SCHEMA_VERSION;
		set_transient($confirmation_key, $confirmation_record, WCOS_Merge_Confirmation_Store::TTL);
		$stale_confirmation_rejected = false;
		try {
			WCOS_Merge_Confirmation_Store::verify($source, wc_get_order($target->get_id()), $confirmation['operation_id'], $confirmation['confirmation_token'], self::$operator_id);
		} catch (WCOS_Merge_Confirmation_Exception $exception) {
			$stale_confirmation_rejected = in_array($exception->get_reason(), array('authority_incomplete', 'authority_changed'), true);
		}
		self::assert($stale_confirmation_rejected, 'A pre-006 Confirmation schema minted current financial authority.');

		self::$results['drift_and_transient'] = array(
			'cases' => '37-41',
			'drifts' => $drifts,
			'fresh_review_required' => true,
			'pre_006_transient_rejected' => true,
		);
	}

	private static function response_loss_recovery_stock_and_concurrency() {
		$product = self::product('recovery-stock', true);
		$source = self::order('response-loss-source');
		$target = self::order('response-loss-target', 'on-hold');
		self::line($source, $product, array('quantity' => '1.000000', 'subtotal' => '10.00', 'total' => '0.00', 'reduced_stock' => '0.500000'));
		$existing = self::line($target, $product, array('quantity' => '1.000000', 'subtotal' => '10.00', 'total' => '10.00'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$source->get_data_store()->set_stock_reduced($source->get_id(), true);
		$target->set_transaction_id('response-loss-financial-target');
		$target->save();
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$financial_before = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2)['target'];
		$physical_before = (string) wc_get_product($product->get_id())->get_stock_quantity();
		$operation_id = 'compat-006-response-loss-' . wp_generate_uuid4();
		self::$operation_ids[$source->get_id()][] = $operation_id;
		$hit = false;
		$interrupt = static function($stage) use (&$hit) {
			if (!$hit && 'after_complete' === $stage) {
				$hit = true;
				throw new WCOS_Merge_Recovery_Interruption_Exception('Injected WOS-COMPAT-006 response loss.');
			}
		};
		add_action('wcos_merge_mutation_checkpoint', $interrupt, 10, 4);
		try {
			(new WCOS_Merge_Order_Service())->merge($source, $target, $operation_id, 2);
		} catch (Throwable $throwable) {
			/* The exact durable terminal result is replayed below. */
		} finally {
			remove_action('wcos_merge_mutation_checkpoint', $interrupt, 10);
		}
		self::assert($hit, 'Financial response-loss checkpoint was not reached.');
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$line_ids_after_loss = array_map('absint', array_keys($target->get_items('line_item')));
		$first = (new WCOS_Merge_Order_Service())->merge($source, $target, $operation_id, 2);
		$second = (new WCOS_Merge_Order_Service())->merge(wc_get_order($source->get_id()), wc_get_order($target->get_id()), $operation_id, 2);
		$target = wc_get_order($target->get_id());
		self::assert('completed' === $first['status'] && $first === $second, 'Financial response-loss replay was not exact and idempotent.');
		self::assert($line_ids_after_loss === array_map('absint', array_keys($target->get_items('line_item'))) && 2 === count($line_ids_after_loss), 'Financial response-loss replay duplicated a fresh line.');
		self::assert($target->get_item($existing->get_id()) instanceof WC_Order_Item_Product, 'Response-loss replay changed the pre-existing target line.');
		$new_ids = array_values(array_diff($line_ids_after_loss, array($existing->get_id())));
		self::assert(1 === count($new_ids) && '0.500000' === WCOS_Decimal::normalize($target->get_item($new_ids[0])->get_meta('_reduced_stock', true), 6), 'Reduced-stock ownership did not move exactly once to the fresh line.');
		self::assert('' === (string) wc_get_order($source->get_id())->get_item(array_key_first(wc_get_order($source->get_id())->get_items('line_item')))->get_meta('_reduced_stock', true), 'Retired source retained reduced-stock ownership.');
		self::assert($physical_before === (string) wc_get_product($product->get_id())->get_stock_quantity(), 'Financial Merge changed physical stock.');
		$financial_after = WCOS_Merge_Financial_Authority::freeze_pair(wc_get_order($source->get_id()), $target, 2)['target'];
		self::assert($financial_before === $financial_after, 'Completed financial replay changed target financial authority.');

		$checkpoint_stages = array(
			'after_target_money_tax_persistence',
			'after_ownership_migration_before_retirement',
			'after_non_force_source_retirement',
		);
		$checkpoint_outcomes = array();
		foreach ($checkpoint_stages as $checkpoint_stage) {
			$crash_source = self::order('checkpoint-' . $checkpoint_stage . '-source');
			$crash_target = self::order('checkpoint-' . $checkpoint_stage . '-target');
			self::line($crash_source, $product, array('reduced_stock' => '0.250000'));
			$crash_existing = self::line($crash_target, $product, array('total' => '10.00'));
			$crash_source = self::finalize($crash_source);
			$crash_target = self::finalize($crash_target);
			$crash_source->get_data_store()->set_stock_reduced($crash_source->get_id(), true);
			$crash_target->set_transaction_id('checkpoint-financial-' . $checkpoint_stage);
			$crash_target->save();
			$crash_source = wc_get_order($crash_source->get_id());
			$crash_target = wc_get_order($crash_target->get_id());
			$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($crash_source);
			$target_before = WCOS_Merge_Recovery_Snapshot::participant_signature($crash_target);
			$target_settlement_before = self::target_immutable_snapshot($crash_target, array($crash_existing->get_id()));
			$target_financial_before = WCOS_Merge_Financial_Authority::freeze_pair($crash_source, $crash_target, 2)['target'];
			$physical_checkpoint_before = (string) wc_get_product($product->get_id())->get_stock_quantity();
			$crash_operation = 'compat-006-checkpoint-' . wp_generate_uuid4();
			self::$operation_ids[$crash_source->get_id()][] = $crash_operation;
			$crash_hit = false;
			$crash = static function($stage) use (&$crash_hit, $checkpoint_stage) {
				if (!$crash_hit && $checkpoint_stage === $stage) {
					$crash_hit = true;
					throw new WCOS_Merge_Recovery_Interruption_Exception('Injected WOS-COMPAT-006 checkpoint crash: ' . $checkpoint_stage);
				}
			};
			add_action('wcos_merge_mutation_checkpoint', $crash, 10, 4);
			try {
				(new WCOS_Merge_Order_Service())->merge($crash_source, $crash_target, $crash_operation, 2);
			} catch (Throwable $throwable) {
				/* Exact compensation or durable manual authority is asserted below. */
			} finally {
				remove_action('wcos_merge_mutation_checkpoint', $crash, 10);
			}
			self::assert($crash_hit, 'Financial checkpoint crash was not reached: ' . $checkpoint_stage);
			$fresh_crash_source = wc_get_order($crash_source->get_id());
			$fresh_crash_target = wc_get_order($crash_target->get_id());
			$record = WCOS_Operation_Journal::get($fresh_crash_source, $crash_operation);
			$status = is_array($record) ? sanitize_key((string) $record['status']) : '';
			self::assert(in_array($status, array('compensated', 'manual_reconciliation'), true), 'Financial checkpoint crash did not reach a deterministic safe outcome: ' . $checkpoint_stage);
			$checkpoint_outcomes[$checkpoint_stage] = $status;
			if ('compensated' === $status) {
				self::assert($source_before === WCOS_Merge_Recovery_Snapshot::participant_signature($fresh_crash_source)
					&& $target_before === WCOS_Merge_Recovery_Snapshot::participant_signature($fresh_crash_target), 'Financial checkpoint compensation did not restore both participants exactly: ' . $checkpoint_stage);
			} else {
				self::assert(in_array($crash_operation, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($fresh_crash_source), true)
					&& in_array($crash_operation, WCOS_Manual_Reconciliation_Blocker::active_operation_ids($fresh_crash_target), true)
					&& !empty(WCOS_Merge_Participation::authorities($fresh_crash_source))
					&& !empty(WCOS_Merge_Participation::authorities($fresh_crash_target)), 'Financial checkpoint ambiguity lacks pair-wide manual authority: ' . $checkpoint_stage);
			}
			self::assert($target_settlement_before === self::target_immutable_snapshot($fresh_crash_target, array($crash_existing->get_id()))
				&& $target_financial_before === WCOS_Merge_Financial_Authority::freeze_pair($fresh_crash_source, $fresh_crash_target, 2)['target']
				&& count($fresh_crash_target->get_items('line_item')) <= 2
				&& $physical_checkpoint_before === (string) wc_get_product($product->get_id())->get_stock_quantity(), 'Financial checkpoint safe outcome changed settlement authority, duplicated a line, or changed physical stock: ' . $checkpoint_stage);
		}

		$lease_source = self::order('overlap-source');
		$lease_target = self::order('overlap-target');
		self::line($lease_source, $product);
		self::line($lease_target, $product, array('total' => '10.00'));
		$lease_source = self::finalize($lease_source);
		$lease_target = self::finalize($lease_target);
		$lease_target->set_transaction_id('overlap-financial-target');
		$lease_target->save();
		$blocker = 'compat-006-overlap-blocker-' . wp_generate_uuid4();
		$blocked = 'compat-006-overlap-blocked-' . wp_generate_uuid4();
		$lease = WCOS_Multi_Order_Lease::acquire(array($lease_source->get_id(), $lease_target->get_id()), $blocker, 60);
		self::assert($lease instanceof WCOS_Multi_Order_Lease, 'Financial participant overlap fixture could not acquire its lease.');
		$blocked_before = self::journal_option_count();
		$rejected = false;
		try {
			(new WCOS_Merge_Order_Service())->merge($lease_source, wc_get_order($lease_target->get_id()), $blocked, 2);
		} catch (RuntimeException $exception) {
			$rejected = true;
		} finally {
			$lease->release();
		}
		self::assert($rejected && $blocked_before === self::journal_option_count(), 'Participant-overlap concurrency did not remain single-writer.');

		self::$results['recovery_stock_concurrency'] = array(
			'cases' => '44-49',
			'response_loss_idempotent' => true,
			'checkpoint_safe_outcomes' => $checkpoint_outcomes,
			'physical_stock_neutral' => true,
			'overlap_single_writer' => true,
		);
	}

	private static function wos_compat_005_durable_replay() {
		$product = self::product('previous-durable-replay');
		$source = self::order('previous-durable-source');
		$target = self::order('previous-durable-target', 'on-hold');
		$source_item = self::line($source, $product, array(
			'subtotal' => '8.00',
			'total' => '6.00',
			'meta' => array('Configuration' => 'previous-source-fresh'),
		));
		$target_item = self::line($target, $product, array(
			'subtotal' => '12.00',
			'total' => '10.00',
			'meta' => array('Configuration' => 'previous-target-distinct'),
		));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$source_id = (int) $source->get_id();
		$target_id = (int) $target->get_id();
		$current_report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		$previous_plan = self::previous_commercial_plan($current_report['plan']);
		$context_authority = WCOS_Merge_Context_Signature::disposition($source, $target);
		$authority = array(
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
			'target_signature' => WCOS_Order_Contract_Snapshot::source_signature($target),
			'plan_schema_version' => WCOS_Merge_Plan::PREVIOUS_SCHEMA_VERSION,
			'plan_fingerprint' => WCOS_Merge_Plan::fingerprint($previous_plan),
			'price_precision' => 2,
			'preflight_policy_version' => WCOS_Merge_Preflight::PREVIOUS_POLICY_VERSION,
			'context_signature_version' => WCOS_Merge_Context_Signature::SCHEMA_VERSION,
			'context_authority' => $context_authority,
			'context_authority_fingerprint' => WCOS_Merge_Context_Signature::authority_fingerprint($context_authority),
			'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
			'retirement_candidates' => WCOS_Merge_Retirement_Policy::identifiers(),
			'retirement_policy_selected' => true,
			'retirement_policy_identifier' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'archive_source_signature_before' => WCOS_Merge_Recovery_Snapshot::archive_commercial_signature($source),
			'active_ownership_before_signature' => WCOS_Merge_Commercial_Policy::expected_target_signature(
				$source,
				$target,
				2,
				WCOS_Merge_Preflight::PREVIOUS_POLICY_VERSION
			),
			'participation_schema_version' => WCOS_Merge_Participation::SCHEMA_VERSION,
		);
		$pair_fingerprint = WCOS_Mutation_Fingerprint::create('merge_pair_authority_v4', $source_id, $authority);
		$operation_id = wp_generate_uuid4();
		$previous_pair = array(
			'schema_version' => WCOS_Merge_Journal_Context::PREVIOUS_SCHEMA_VERSION,
			'authority' => $authority,
			'pair_fingerprint' => $pair_fingerprint,
		);
		$journal_context = array(
			'merge_pair' => $previous_pair,
			'merge_plan' => $previous_plan,
			'merge_confirmation_authority' => WCOS_Merge_Journal_Context::create_confirmation_handoff(array(
				'operation_id' => $operation_id,
				'operator_user_id' => self::$operator_id,
				'source_order_id' => $source_id,
				'target_order_id' => $target_id,
				'confirmation_schema_version' => WCOS_Merge_Confirmation_Store::PREVIOUS_SCHEMA_VERSION,
				'merge_service_policy_version' => WCOS_Merge_Order_Service::PREVIOUS_POLICY_VERSION,
				'preflight_policy_version' => WCOS_Merge_Preflight::PREVIOUS_POLICY_VERSION,
				'plan_schema_version' => WCOS_Merge_Plan::PREVIOUS_SCHEMA_VERSION,
				'plan_fingerprint' => $authority['plan_fingerprint'],
				'context_signature_version' => WCOS_Merge_Context_Signature::SCHEMA_VERSION,
				'context_authority_fingerprint' => $authority['context_authority_fingerprint'],
				'pair_fingerprint' => $pair_fingerprint,
				'price_precision' => 2,
				'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
				'retirement_policy' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			), $previous_pair),
		);
		self::$operation_ids[$source_id][] = $operation_id;
		$lease = WCOS_Multi_Order_Lease::acquire(array($source_id, $target_id), $operation_id, 60);
		self::assert($lease instanceof WCOS_Multi_Order_Lease, 'WOS-COMPAT-005 durable fixture could not acquire its pair lease.');
		$stock_guard = WCOS_Stock_Side_Effect_Guard::begin($operation_id);
		$added_item_id = 0;
		try {
			self::assert(WCOS_Operation_Journal::start($source, $operation_id, 'merge', $journal_context, $pair_fingerprint), 'WOS-COMPAT-005 durable journal could not be started.');
			$record = WCOS_Operation_Journal::get($source, $operation_id);
			$pair = WCOS_Merge_Journal_Context::assert_executable_policy($record);
			self::assert(WCOS_Merge_Order_Service::PREVIOUS_POLICY_VERSION === WCOS_Merge_Journal_Context::service_policy_for_pair($pair), 'WOS-COMPAT-005 tuple did not resolve to service policy v2.');
			self::merge_checkpoint($source, $target, $operation_id, WCOS_Merge_Recovery_State_Graph::NO_WRITE, array(), array(), false);

			$source_item = wc_get_order($source_id)->get_item($source_item->get_id());
			$clone = WCOS_Order_Item_Cloner::product($source_item, array(), true, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE);
			$target->add_item($clone);
			$target->save();
			$added_item_id = absint($clone->get_id());
			self::assert($added_item_id > 0 && $added_item_id !== $source_item->get_id(), 'WOS-COMPAT-005 frozen fresh-line action did not create an independent item.');
			self::merge_checkpoint(wc_get_order($source_id), wc_get_order($target_id), $operation_id, WCOS_Merge_Recovery_State_Graph::TARGET_STAGING, array($added_item_id), array(), false);

			$target = wc_get_order($target_id);
			WCOS_Tax_Item_Synchronizer::synchronize(
				$target,
				WCOS_Tax_Item_Synchronizer::templates_for_rates(wc_get_order($source_id), $previous_plan['tax_template_rate_ids']),
				2,
				true,
				WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE,
				true
			);
			WCOS_Order_Totals_Rebuilder::rebuild($target, 2);
			$target->save();
			self::merge_checkpoint(wc_get_order($source_id), wc_get_order($target_id), $operation_id, WCOS_Merge_Recovery_State_Graph::TARGET_PERSISTED, array($added_item_id), array(), false);

			$source = wc_get_order($source_id);
			$target = wc_get_order($target_id);
			$source->get_data_store()->set_stock_reduced($source_id, false);
			$target->get_data_store()->set_stock_reduced($target_id, (bool) $previous_plan['target_order_stock_reduced_after']);
			self::merge_checkpoint(wc_get_order($source_id), wc_get_order($target_id), $operation_id, WCOS_Merge_Recovery_State_Graph::SOURCE_OWNERSHIP_MIGRATED, array($added_item_id), array(), false);

			$source = wc_get_order($source_id);
			$source->delete(false);
			$source = wc_get_order($source_id);
			self::assert($source instanceof WC_Order && 'trash' === $source->get_status(), 'WOS-COMPAT-005 durable source was not non-force retired.');
			self::merge_checkpoint($source, wc_get_order($target_id), $operation_id, WCOS_Merge_Recovery_State_Graph::SOURCE_RETIRED, array($added_item_id), array(), true);
		} finally {
			WCOS_Stock_Side_Effect_Guard::end($stock_guard);
			$lease->release();
		}

		$source = wc_get_order($source_id);
		$target = wc_get_order($target_id);
		$line_ids_before = array_map('absint', array_keys($target->get_items('line_item')));
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		$request = array_merge(self::request($source, $target), array(
			'operation_id' => $operation_id,
			'confirmation_token' => '',
		));
		$result = $controller->execute_request($request);
		self::assert('completed' === $result['status'], 'WOS-COMPAT-005 recovery-pending durable journal did not complete.');
		$record = WCOS_Operation_Journal::get(wc_get_order($source_id), $operation_id);
		$pair = WCOS_Merge_Journal_Context::assert_executable_policy($record);
		self::assert('completed' === sanitize_key((string) $record['status'])
			&& WCOS_Merge_Order_Service::PREVIOUS_POLICY_VERSION === WCOS_Merge_Journal_Context::service_policy_for_pair($pair), 'Completed WOS-COMPAT-005 journal lost its frozen tuple.');
		self::assert($line_ids_before === array_map('absint', array_keys(wc_get_order($target_id)->get_items('line_item')))
			&& wc_get_order($target_id)->get_item($target_item->get_id()) instanceof WC_Order_Item_Product
			&& wc_get_order($target_id)->get_item($added_item_id) instanceof WC_Order_Item_Product, 'WOS-COMPAT-005 recovery duplicated or reinterpreted its fresh-line action.');
		$journal_before = wp_json_encode($record);
		$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source_id));
		$target_before = WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target_id));
		$replayed = $controller->execute_request($request);
		self::assert($result === $replayed
			&& $journal_before === wp_json_encode(WCOS_Operation_Journal::get(wc_get_order($source_id), $operation_id))
			&& $source_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source_id))
			&& $target_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target_id)), 'WOS-COMPAT-005 completed replay was not byte/semantic-idempotent.');

		self::$results['wos_compat_005_durable_replay'] = array(
			'cases' => '43',
			'pair_schema_version' => WCOS_Merge_Journal_Context::PREVIOUS_SCHEMA_VERSION,
			'plan_schema_version' => WCOS_Merge_Plan::PREVIOUS_SCHEMA_VERSION,
			'service_policy_version' => WCOS_Merge_Order_Service::PREVIOUS_POLICY_VERSION,
			'recovery_and_terminal_replay_exact' => true,
		);
	}

	private static function previous_commercial_plan(array $current) {
		$plan = $current;
		$plan['schema_version'] = WCOS_Merge_Plan::PREVIOUS_SCHEMA_VERSION;
		unset($plan['financial_authority'], $plan['tax_template_policy']);
		$policy = $plan['commercial_policy'];
		$policy['schema_version'] = WCOS_Merge_Commercial_Policy::PREVIOUS_SCHEMA_VERSION;
		$policy['policy_version'] = WCOS_Merge_Commercial_Policy::PREVIOUS_POLICY_VERSION;
		unset(
			$policy['financial_policy_fingerprint'],
			$policy['financial_target'],
			$policy['target_financial_history_disposition'],
			$policy['payment_refund_api_disposition']
		);
		$plan['commercial_policy'] = $policy;
		return WCOS_Merge_Plan::canonicalize_previous($plan);
	}

	private static function merge_checkpoint(WC_Order $source, WC_Order $target, $operation_id, $state, array $target_item_ids, array $target_tax_item_ids, $forward) {
		$context = array(
			'merge_recovery_state' => sanitize_key((string) $state),
			'merge_source_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($source),
			'merge_target_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($target),
			'merge_source_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($source),
			'merge_target_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($target),
			'merge_target_item_ids' => array_values(array_map('absint', $target_item_ids)),
			'merge_target_tax_item_ids' => array_values(array_map('absint', $target_tax_item_ids)),
			'merge_forward_repair_allowed' => (bool) $forward,
			'merge_retirement_candidate' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'merge_physical_stock_after_write' => false,
		);
		self::assert(WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_service_checkpoint', $context), 'Durable Merge checkpoint could not be persisted: ' . $state);
		$record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
		self::assert($state === WCOS_Merge_Recovery_State_Graph::assert_record($record), 'Durable Merge recovery graph did not reach: ' . $state);
	}

	private static function version_and_release_regression() {
		self::assert(WCOS_Merge_Preflight::POLICY_VERSION === 3
			&& WCOS_Merge_Preflight::PREVIOUS_POLICY_VERSION === 2
			&& WCOS_Merge_Plan::SCHEMA_VERSION === 4
			&& WCOS_Merge_Plan::PREVIOUS_SCHEMA_VERSION === 3
			&& WCOS_Merge_Journal_Context::SCHEMA_VERSION === 5
			&& WCOS_Merge_Journal_Context::PREVIOUS_SCHEMA_VERSION === 4
			&& WCOS_Merge_Order_Service::POLICY_VERSION === 3
			&& WCOS_Merge_Order_Service::PREVIOUS_POLICY_VERSION === 2, 'Merge durable version tuple is incomplete.');
		foreach (array(
			WCOS_Feature_Gates::SPLIT,
			WCOS_Feature_Gates::DUPLICATE,
			WCOS_Feature_Gates::MERGE,
			WCOS_Feature_Gates::RETURN_ORDER,
			WCOS_Feature_Gates::BULK_RETURN,
		) as $gate) {
			self::assert(WCOS_Feature_Gates::enabled($gate), 'Production workflow gate changed: ' . $gate);
		}
		foreach (array(
			WCOS_Split_Strategy_Gates::MANUAL_QUANTITY,
			WCOS_Split_Strategy_Gates::CATEGORY,
			WCOS_Split_Strategy_Gates::STOCK_STATUS,
		) as $gate) {
			self::assert(WCOS_Split_Strategy_Gates::enabled($gate), 'Production Split strategy gate changed: ' . $gate);
		}
		$root = dirname(__DIR__, 2);
		$plugin = file_get_contents($root . '/wc-order-splitter.php');
		$readme = file_get_contents($root . '/readme.txt');
		self::assert(false !== strpos($plugin, 'Version: 1.5.0') && false !== strpos($readme, 'Stable tag: 1.5.0'), 'Version or Stable tag changed during WOS-COMPAT-006.');
		self::assert(is_file($root . '/inc/backend/actions/merge-order.php') && false === has_action('wp_ajax_merge_order'), 'Legacy Merge handler was restored or registered.');

		self::$results['non_regression'] = array(
			'cases' => '42,50-57',
			'pre_005_v1_covered_by_existing_matrix' => true,
			'wos_compat_005_matrix_is_ci_dependency' => true,
			'gate_map_unchanged' => true,
			'version_release_unchanged' => true,
		);
	}

	private static function target_immutable_snapshot(WC_Order $target, array $existing_line_ids) {
		$date_paid = $target->get_date_paid();
		$refunds = array();
		foreach ($target->get_refunds() as $refund) {
			$refunds[(int) $refund->get_id()] = self::order_item_collection_snapshot($refund);
		}
		ksort($refunds, SORT_NUMERIC);
		$existing_lines = array();
		foreach ($existing_line_ids as $item_id) {
			$item = $target->get_item($item_id);
			$existing_lines[(int) $item_id] = $item instanceof WC_Order_Item ? self::item_snapshot($item) : null;
		}
		ksort($existing_lines, SORT_NUMERIC);
		return array(
			'status' => (string) $target->get_status(),
			'is_paid' => (bool) $target->is_paid(),
			'date_paid' => null === $date_paid ? null : (int) $date_paid->getTimestamp(),
			'transaction_id' => (string) $target->get_transaction_id(),
			'payment_method' => (string) $target->get_payment_method(),
			'payment_method_title' => (string) $target->get_payment_method_title(),
			'total' => WCOS_Decimal::normalize($target->get_total(), 2),
			'total_tax' => WCOS_Decimal::normalize($target->get_total_tax(), 2),
			'total_refunded' => WCOS_Decimal::normalize($target->get_total_refunded(), 2),
			'existing_lines' => $existing_lines,
			'shipping' => self::items_snapshot($target, 'shipping'),
			'fee' => self::items_snapshot($target, 'fee'),
			'coupon' => self::items_snapshot($target, 'coupon'),
			'tax' => self::items_snapshot($target, 'tax'),
			'refunds' => $refunds,
		);
	}

	private static function order_item_collection_snapshot(WC_Abstract_Order $order) {
		$date = $order->get_date_created();
		$result = array(
			'id' => (int) $order->get_id(),
			'parent_id' => (int) $order->get_parent_id(),
			'status' => (string) $order->get_status(),
			'amount' => $order instanceof WC_Order_Refund ? (string) $order->get_amount() : '',
			'reason' => $order instanceof WC_Order_Refund ? (string) $order->get_reason() : '',
			'date' => null === $date ? null : (int) $date->getTimestamp(),
			'meta' => self::meta_snapshot($order->get_meta_data()),
			'items' => array(),
		);
		foreach (array('line_item', 'shipping', 'fee', 'tax') as $type) {
			$result['items'][$type] = self::items_snapshot($order, $type);
		}
		return $result;
	}

	private static function item_snapshot(WC_Order_Item $item) {
		$data = $item->get_data();
		unset($data['order_id'], $data['meta_data']);
		return array('data' => $data, 'meta' => self::meta_snapshot($item->get_meta_data()));
	}

	private static function items_snapshot(WC_Abstract_Order $order, $type) {
		$result = array();
		foreach ($order->get_items($type) as $item_id => $item) {
			$result[(int) $item_id] = self::item_snapshot($item);
		}
		ksort($result, SORT_NUMERIC);
		return $result;
	}

	private static function meta_snapshot(array $metadata) {
		$result = array();
		foreach ($metadata as $datum) {
			$result[] = array('key' => (string) $datum->key, 'value' => $datum->value);
		}
		return $result;
	}

	private static function synthetic_financial_meta(WC_Order $order) {
		$matches = array();
		foreach ($order->get_meta_data() as $datum) {
			$key = strtolower((string) $datum->key);
			if (false !== strpos($key, 'settlement') || false !== strpos($key, 'credit') || false !== strpos($key, 'payment_ledger')) {
				$matches[] = $key;
			}
		}
		return array_values(array_unique($matches));
	}

	private static function expect_reason(WC_Order $source_view, WC_Order $target, $reason, ?WC_Order $persisted_source = null) {
		$persisted_source = $persisted_source instanceof WC_Order ? $persisted_source : $source_view;
		$source_id = $persisted_source->get_id();
		$target_id = $target->get_id();
		$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($persisted_source);
		$target_before = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
		$journal_count = self::journal_option_count();
		$report = WCOS_Merge_Preflight::report($source_view, $target, 2);
		self::assert($reason === $report['reason'], 'Unexpected Merge preflight reason: ' . $report['reason'] . ', expected ' . $reason);
		self::assert($journal_count === self::journal_option_count(), 'Rejected financial preflight created a journal.');
		self::assert($source_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source_id)), 'Rejected financial preflight changed source.');
		self::assert($target_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target_id)), 'Rejected financial preflight changed target.');
		self::assert(empty(WCOS_Merge_Participation::authorities(wc_get_order($source_id))) && empty(WCOS_Merge_Participation::authorities(wc_get_order($target_id))), 'Rejected financial preflight created participation authority.');
		self::assert(false === get_option('wcos_mutation_lock_' . $source_id, false) && false === get_option('wcos_mutation_lock_' . $target_id, false), 'Rejected financial preflight leaked a participant lease.');
	}

	private static function expect_transport_one_of(callable $callback, array $codes) {
		$actual = '';
		try {
			$callback();
		} catch (WCOS_Merge_Transport_Exception $exception) {
			$actual = $exception->get_error_code();
		}
		self::assert(in_array($actual, $codes, true), 'Unexpected Merge transport code: ' . $actual);
	}

	private static function journal_option_count() {
		global $wpdb;
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wcos_mutation_op_%'");
	}

	private static function cleanup() {
		foreach (array_values(array_unique(self::$review_ids)) as $review_id) {
			WCOS_Merge_Review_Store::delete($review_id);
		}
		foreach (self::$operation_ids as $source_id => $operation_ids) {
			$source = wc_get_order($source_id);
			foreach (array_values(array_unique($operation_ids)) as $operation_id) {
				if ($source instanceof WC_Order) {
					WCOS_Operation_Journal::delete($source, $operation_id);
				}
				WCOS_Merge_Confirmation_Store::delete($operation_id);
			}
		}
		foreach (array_reverse(array_values(array_unique(self::$order_ids))) as $order_id) {
			delete_option('wcos_manual_reconcile_block_' . $order_id);
			$order = wc_get_order($order_id);
			if ($order instanceof WC_Order) {
				$order->delete(true);
			}
		}
		foreach (array_reverse(array_values(array_unique(self::$product_ids))) as $product_id) {
			wp_delete_post($product_id, true);
		}
	}

	private static function assert($condition, $message) {
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}
}

WCOS_Compat_Merge_Financial_Matrix::run();
