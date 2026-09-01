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
	private static $tax_rate_ids = array();
	private static $shipping_method_ids = array();
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
			self::target_tax_row_distribution_preservation();
			self::refund_success_matrix();
			self::refunded_payment_authority();
			self::refund_projection_drift_authority();
			self::unfiltered_refund_projection_authority();
			self::canonical_merge_authority_filters();
			self::refund_tax_distribution_drift_authority();
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
		if ($product instanceof WC_Product_Variation) {
			$item->set_product_id($product->get_parent_id('edit'));
			$item->set_variation_id($product->get_id());
		} else {
			$item->set_product_id($product->get_id());
		}
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

	private static function shipping(WC_Order $order, $label, $total, array $taxes = array(), $instance_id = 6) {
		$item = new WC_Order_Item_Shipping();
		$item->set_method_title($label);
		$item->set_method_id('flat_rate');
		$item->set_instance_id(absint($instance_id));
		$item->set_total($total);
		$item->set_taxes(array('total' => $taxes));
		$item->add_meta_data('Carrier reference', 'financial-target-shipping', true);
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function shipping_method($tax_status) {
		$zone = new WC_Shipping_Zone(0);
		$instance_id = absint($zone->add_shipping_method('flat_rate'));
		self::assert($instance_id > 0, 'Shipping-method fixture could not be created.');
		update_option('woocommerce_flat_rate_' . $instance_id . '_settings', array('tax_status' => (string) $tax_status));
		self::$shipping_method_ids[] = $instance_id;
		return $instance_id;
	}

	private static function fee(WC_Order $order, $label, $total, array $taxes = array()) {
		$item = new WC_Order_Item_Fee();
		$item->set_name($label);
		$item->set_amount($total);
		$item->set_total($total);
		$item->set_taxes(array('total' => $taxes));
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

	private static function tax(WC_Order $order, $rate_id, $cart, $shipping, $native_rate = false) {
		$item = new WC_Order_Item_Tax();
		if ($native_rate) {
			$item->set_rate((int) $rate_id);
		} else {
			$item->set_rate_id((int) $rate_id);
			$item->set_label('Historical financial rate ' . (int) $rate_id);
			$item->set_compound(false);
		}
		$item->set_tax_total($cart);
		$item->set_shipping_tax_total($shipping);
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function native_tax_rate($label) {
		$rate_id = WC_Tax::_insert_tax_rate(array(
			'tax_rate_country' => '',
			'tax_rate_state' => '',
			'tax_rate' => '10.0000',
			'tax_rate_name' => (string) $label,
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_order' => 0,
			'tax_rate_class' => '',
		));
		self::assert(absint($rate_id) > 0, 'Native refund tax-rate fixture could not be created.');
		self::$tax_rate_ids[] = absint($rate_id);
		return absint($rate_id);
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
			$after_financial = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2, true);
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

	private static function refunded_payment_authority() {
		$product = self::product('refunded-payment-authority');
		$eligible = array();
		foreach (array(false, true) as $refunded_payment) {
			$label = $refunded_payment ? 'true' : 'false';
			$source = self::order('refunded-payment-' . $label . '-source');
			$target = self::order('refunded-payment-' . $label . '-target', 'on-hold');
			self::line($source, $product, array('subtotal' => '4.00', 'total' => '0.00'));
			$existing = self::line($target, $product, array('subtotal' => '10.00', 'total' => '10.00'));
			$source = self::finalize($source);
			$target = self::finalize($target);
			$refund = self::create_refund($target, '1.00', array(), 'Refunded-payment ' . $label . ' authority');
			$refund = self::set_refunded_payment($refund, $refunded_payment);
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			$before = self::target_immutable_snapshot($target, array($existing->get_id()));
			$before_authority = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
			$projection = self::refund_financial_projection($target, $refund);
			$projection_keys = array_keys($projection);
			sort($projection_keys, SORT_STRING);
			$expected_projection_keys = array(
				'amount', 'cart_tax', 'currency', 'date_created_fingerprint', 'discount_tax', 'discount_total', 'items',
				'metadata_fingerprint', 'parent_order_id', 'prices_include_tax', 'reason_fingerprint', 'refund_id',
				'refunded_by', 'refunded_payment', 'shipping_tax', 'shipping_total', 'status', 'total', 'total_tax',
			);
			sort($expected_projection_keys, SORT_STRING);
			self::assert($refunded_payment === $refund->get_refunded_payment('edit')
				&& $expected_projection_keys === $projection_keys
				&& WCOS_Merge_Preflight::assert_supported($source, $target, 2)['supported'], 'Persisted refunded_payment=' . $label . ' authority or explicit refund projection was not eligible.');

			$api_events = 0;
			$api_probe = static function() use (&$api_events) {
				$api_events++;
			};
			$outbound_requests = 0;
			$http_probe = static function() use (&$outbound_requests) {
				$outbound_requests++;
				return new WP_Error('wos_compat_006_refunded_payment_outbound_blocked', 'Unexpected outbound request during Merge.');
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
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			$persisted_refund = wc_get_order($refund->get_id());
			$after_authority = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2, true);
			self::assert('completed' === $result['status']
				&& $persisted_refund instanceof WC_Order_Refund
				&& $refunded_payment === $persisted_refund->get_refunded_payment('edit')
				&& 0 === $api_events
				&& 0 === $outbound_requests, 'Merge changed refunded-payment authority or invoked a payment/refund API: ' . $label);
			self::assert($before === self::target_immutable_snapshot($target, array($existing->get_id()))
				&& $before_authority['target'] === $after_authority['target'], 'Merge did not preserve refunded_payment=' . $label . ' authority exactly.');
			$eligible[$label] = true;
		}

		$drifts = array();
		foreach (array(
			array(false, true),
			array(true, false),
		) as $direction) {
			foreach (array('confirm', 'execute', 'direct_gateway') as $path) {
				$key = ($direction[0] ? 'true' : 'false') . '_to_' . ($direction[1] ? 'true' : 'false') . '_' . $path;
				self::refunded_payment_drift_case($product, $direction[0], $direction[1], $path);
				$drifts[$key] = true;
			}
		}

		self::$results['refunded_payment_authority'] = array(
			'cases' => '61-68',
			'eligible' => $eligible,
			'explicit_projection_keyset' => true,
			'drifts' => $drifts,
			'payment_refund_events' => 0,
			'pre_lease_gateway_rejection' => true,
		);
	}

	private static function refunded_payment_drift_case(WC_Product $product, $from, $to, $path) {
		$label = ($from ? 'true' : 'false') . '-to-' . ($to ? 'true' : 'false') . '-' . $path;
		$source = self::order('refunded-payment-drift-' . $label . '-source');
		$target = self::order('refunded-payment-drift-' . $label . '-target', 'on-hold');
		self::line($source, $product, array('subtotal' => '3.00', 'total' => '0.00'));
		self::line($target, $product, array('subtotal' => '10.00', 'total' => '10.00'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$refund = self::set_refunded_payment(self::create_refund($target, '1.00'), $from);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$before_authority = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		$request = self::request($source, $target);
		$review = $controller->review_request($request);
		self::$review_ids[] = $review['review_id'];

		$confirmation = null;
		$direct_authority = array();
		if ('confirm' !== $path) {
			if ('direct_gateway' === $path) {
				$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
				$stored_review = WCOS_Merge_Review_Store::create($source, $target, $report, self::$operator_id);
				self::$review_ids[] = $stored_review['review_id'];
				$confirmation = WCOS_Merge_Confirmation_Store::create($source, $target, $stored_review['authority'], self::$operator_id);
				$direct_authority = WCOS_Merge_Confirmation_Store::operation_authority($confirmation['record']);
			} else {
				$confirmation = $controller->confirm_request(array_merge($request, array(
					'review_id' => $review['review_id'],
					'review_token' => $review['review_token'],
				)));
			}
			self::$operation_ids[$source->get_id()][] = $confirmation['operation_id'];
		}

		self::set_refunded_payment($refund, $to);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$after_authority = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
		self::assert($before_authority['target']['refund_structure_fingerprint'] !== $after_authority['target']['refund_structure_fingerprint']
			&& $before_authority['target']['participant_financial_fingerprint'] !== $after_authority['target']['participant_financial_fingerprint'], 'refunded_payment drift did not change financial authority: ' . $label);
		$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
		$target_before = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
		$before_journals = self::journal_option_count();

		if ('confirm' === $path) {
			self::expect_transport_one_of(static function() use ($controller, $request, $review) {
				$controller->confirm_request(array_merge($request, array(
					'review_id' => $review['review_id'],
					'review_token' => $review['review_token'],
				)));
			}, array('review_target_changed', 'review_pair_changed', 'review_authority_changed'));
		} elseif ('execute' === $path) {
			self::expect_transport_one_of(static function() use ($controller, $request, $confirmation) {
				$controller->execute_request(array_merge($request, array(
					'operation_id' => $confirmation['operation_id'],
					'confirmation_token' => $confirmation['confirmation_token'],
				)));
			}, array('confirmation_authority_changed'));
		} else {
			$lease_events = 0;
			$lease_probe = static function() use (&$lease_events) {
				$lease_events++;
			};
			$source_lease_hook = 'add_option_wcos_mutation_lock_' . $source->get_id();
			$target_lease_hook = 'add_option_wcos_mutation_lock_' . $target->get_id();
			add_action($source_lease_hook, $lease_probe, PHP_INT_MAX, 2);
			add_action($target_lease_hook, $lease_probe, PHP_INT_MAX, 2);
			$rejected = false;
			try {
				(new WCOS_Mutation_Gateway())->merge($source, $target, $confirmation['operation_id'], 2, $direct_authority);
			} catch (WCOS_Merge_Adapter_Exception $exception) {
				$rejected = true;
			} finally {
				remove_action($source_lease_hook, $lease_probe, PHP_INT_MAX);
				remove_action($target_lease_hook, $lease_probe, PHP_INT_MAX);
			}
			self::assert($rejected && 0 === $lease_events, 'Direct production gateway did not reject refunded_payment drift before lease: ' . $label);
		}

		self::assert($before_journals === self::journal_option_count()
			&& $source_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source->get_id()))
			&& $target_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target->get_id()))
			&& empty(WCOS_Merge_Participation::authorities(wc_get_order($source->get_id())))
			&& empty(WCOS_Merge_Participation::authorities(wc_get_order($target->get_id())))
			&& false === get_option('wcos_mutation_lock_' . $source->get_id(), false)
			&& false === get_option('wcos_mutation_lock_' . $target->get_id(), false), 'refunded_payment drift crossed the pre-journal participant boundary: ' . $label);
	}

	private static function set_refunded_payment(WC_Order_Refund $refund, $value) {
		$refund = wc_get_order($refund->get_id());
		self::assert($refund instanceof WC_Order_Refund, 'Refunded-payment fixture is unavailable.');
		$refund->set_refunded_payment((bool) $value);
		$refund->save();
		$refund = wc_get_order($refund->get_id());
		self::assert($refund instanceof WC_Order_Refund
			&& (bool) $value === $refund->get_refunded_payment('edit'), 'Refunded-payment fixture did not persist canonical authority.');
		return $refund;
	}

	private static function refund_projection_drift_authority() {
		$product = self::product('refund-projection-drift');
		$drifts = array(
			'currency' => static function(WC_Order_Refund $refund) {
				$refund->set_currency('EUR');
			},
			'prices_include_tax' => static function(WC_Order_Refund $refund) {
				$refund->set_prices_include_tax(!$refund->get_prices_include_tax('edit'));
			},
			'total' => static function(WC_Order_Refund $refund) {
				$refund->set_total('-1.01');
			},
			'tax_aggregate' => static function(WC_Order_Refund $refund) {
				$refund->set_cart_tax('-0.01');
			},
			'shipping_cart_tax_distribution' => static function(WC_Order_Refund $refund) {
				$refund->set_cart_tax('-0.01');
				$refund->set_shipping_tax('0.01');
			},
		);

		foreach ($drifts as $kind => $mutate) {
			$source = self::order('refund-projection-' . $kind . '-source');
			$target = self::order('refund-projection-' . $kind . '-target', 'on-hold');
			self::line($source, $product, array('subtotal' => '2.00', 'total' => '0.00'));
			self::line($target, $product, array('subtotal' => '10.00', 'total' => '10.00'));
			$source = self::finalize($source);
			$target = self::finalize($target);
			$refund = self::create_refund($target, '1.00', array(), 'Refund projection drift ' . $kind);
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			$before_authority = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
			$controller = WCOS_Merge_Admin_Controller::bootstrap();
			$request = self::request($source, $target);
			$review = $controller->review_request($request);
			self::$review_ids[] = $review['review_id'];

			$refund = wc_get_order($refund->get_id());
			self::assert($refund instanceof WC_Order_Refund, 'Refund projection drift fixture is unavailable: ' . $kind);
			$mutate($refund);
			$refund->save();
			$refund = wc_get_order($refund->get_id());
			$target = wc_get_order($target->get_id());
			$after_authority = null;
			$malformed_after_drift = false;
			try {
				$after_authority = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
			} catch (WCOS_Merge_Financial_Authority_Exception $exception) {
				$malformed_after_drift = 'malformed_refund_authority' === $exception->get_reason();
			}
			$authority_changed = $malformed_after_drift || (is_array($after_authority)
				&& $before_authority['target']['refund_structure_fingerprint'] !== $after_authority['target']['refund_structure_fingerprint']
				&& $before_authority['target']['participant_financial_fingerprint'] !== $after_authority['target']['participant_financial_fingerprint']);
			self::assert($authority_changed, 'First-class refund projection drift did not change or invalidate authority: ' . $kind);
			$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
			$target_before = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
			$before_journals = self::journal_option_count();
			self::expect_transport_one_of(static function() use ($controller, $request, $review) {
				$controller->confirm_request(array_merge($request, array(
					'review_id' => $review['review_id'],
					'review_token' => $review['review_token'],
				)));
			}, array('review_target_changed', 'review_pair_changed', 'review_authority_changed'));
			self::assert($before_journals === self::journal_option_count()
				&& $source_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source->get_id()))
				&& $target_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target->get_id()))
				&& empty(WCOS_Merge_Participation::authorities(wc_get_order($source->get_id())))
				&& empty(WCOS_Merge_Participation::authorities(wc_get_order($target->get_id()))), 'First-class refund projection drift crossed the pre-journal boundary: ' . $kind);
		}

		self::$results['refund_projection_drift'] = array(
			'cases' => '71-75',
			'drifts' => array_keys($drifts),
			'confirm_rejected_pre_journal' => true,
		);
	}

	private static function refund_financial_projection(WC_Order $order, WC_Order_Refund $refund) {
		$method = new ReflectionMethod(WCOS_Merge_Financial_Authority::class, 'refund_structure');
		$method->setAccessible(true);
		$projection = $method->invoke(null, $order, $refund, 2);
		self::assert(is_array($projection), 'Refund financial projection could not be inspected.');
		return $projection;
	}

	private static function unfiltered_refund_projection_authority() {
		$product = self::product('unfiltered-refund-projection');
		$source = self::order('unfiltered-refund-projection-source');
		$target = self::order('unfiltered-refund-projection-target', 'on-hold');
		self::line($source, $product, array('subtotal' => '2.00', 'total' => '0.00'));
		$target_line = self::line($target, $product, array('subtotal' => '10.00', 'total' => '10.00'));
		$canonical_shipping_method_id = self::shipping_method('none');
		$filtered_shipping_method_id = self::shipping_method('taxable');
		$target_shipping = self::shipping($target, 'Unfiltered refund shipping', '3.00', array(), $canonical_shipping_method_id);
		$source = self::finalize($source);
		$target = self::finalize($target);
		$refund = self::create_refund($target, '1.50', array(
			$target_line->get_id() => array('qty' => 1, 'refund_total' => '1.00', 'refund_tax' => array()),
			$target_shipping->get_id() => array('qty' => 0, 'refund_total' => '0.50', 'refund_tax' => array()),
		), 'Unfiltered refund projection authority');
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$refund = wc_get_order($refund->get_id());
		self::assert($refund instanceof WC_Order_Refund
			&& 'completed' === $refund->get_status('edit'), 'Cross-store refund fixture did not retain WooCommerce completed-status authority.');
		$refund_lines = $refund->get_items('line_item');
		$refund_line = reset($refund_lines);
		self::assert($refund_line instanceof WC_Order_Item_Product, 'Unfiltered refund-item fixture is missing.');
		$refund_shipping_items = $refund->get_items('shipping');
		$refund_shipping = reset($refund_shipping_items);
		self::assert($refund_shipping instanceof WC_Order_Item_Shipping, 'Unfiltered refund-shipping fixture is missing.');
		$baseline = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
		$baseline_projection = self::refund_financial_projection($target, $refund);
		$baseline_shipping_projection = array_values(array_filter($baseline_projection['items'], static function($item) {
			return 'shipping' === $item['type'];
		}));
		self::assert(1 === count($baseline_shipping_projection)
			&& 'none' === $baseline_shipping_projection[0]['tax_status'], 'Canonical shipping tax-status fixture was not projected from the persisted instance ID.');

		$cpt_query_cache_exercised = false;
		$data_store = $target->get_data_store();
		if ($data_store instanceof WC_Order_Data_Store_CPT) {
			$cpt_query_cache_exercised = true;
			$poison_calls = 0;
			$poison_query_cache = static function($posts, $query) use (&$poison_calls) {
				$poison_calls++;
				return array(999999);
			};
			add_filter('posts_pre_query', $poison_query_cache, PHP_INT_MAX, 2);
			try {
				$poisoned_ids = $data_store->query(array(
					'type' => 'shop_order_refund',
					'status' => 'any',
					'parent' => absint($target->get_id()),
					'limit' => -1,
					'orderby' => 'ID',
					'order' => 'ASC',
					'return' => 'ids',
					'paginate' => false,
					'no_found_rows' => true,
					'suppress_filters' => true,
					'cache_results' => true,
				));
			} finally {
				remove_filter('posts_pre_query', $poison_query_cache, PHP_INT_MAX);
			}
			self::assert(array(999999) === array_map('absint', (array) $poisoned_ids)
				&& 1 === $poison_calls, 'Legacy refund-query cache poison fixture was not established.');
			self::assert(array(absint($refund->get_id())) === array_map('absint', array_keys(WCOS_Merge_Canonical_Reader::refunds($target))), 'Canonical refund authority consumed a pre-poisoned WP_Query result cache.');
		}

		$query_hook_calls = 0;
		$query_filters = array();
		foreach (array(
			'woocommerce_order_query_args',
			'woocommerce_order_query',
			'woocommerce_get_wp_query_args',
			'woocommerce_order_data_store_cpt_get_orders_query',
			'woocommerce_orders_table_datastore_get_orders_query',
			'woocommerce_hpos_pre_query',
			'parse_query',
			'pre_get_posts',
			'posts_pre_query',
		) as $query_hook) {
			$query_filter = static function($value = null) use (&$query_hook_calls, $query_hook) {
				$query_hook_calls++;
				if (in_array($query_hook, array('parse_query', 'pre_get_posts'), true)) {
					return $value;
				}
				return in_array($query_hook, array('woocommerce_hpos_pre_query', 'posts_pre_query'), true)
					? array(999999)
					: array('include' => array(999999), 'post__in' => array(999999));
			};
			add_filter($query_hook, $query_filter, PHP_INT_MAX, 99);
			$query_filters[] = array($query_hook, $query_filter);
		}
		try {
			$with_query_filters = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
			$canonical_refund_ids = array_map('absint', array_keys(WCOS_Merge_Canonical_Reader::refunds($target)));
			self::assert($baseline === $with_query_filters
				&& array(absint($refund->get_id())) === $canonical_refund_ids
				&& 0 === $query_hook_calls, 'Order-query hooks omitted or injected canonical persisted refund authority.');
		} finally {
			foreach (array_reverse($query_filters) as $query_filter) {
				remove_filter($query_filter[0], $query_filter[1], PHP_INT_MAX);
			}
		}

		$currency_filter = static function($value, $object) use ($refund) {
			return $object instanceof WC_Order_Refund && $object->get_id() === $refund->get_id() ? 'EUR' : $value;
		};
		$amount_filter = static function($value, $object) use ($refund) {
			return $object instanceof WC_Order_Refund && $object->get_id() === $refund->get_id() ? '999.99' : $value;
		};
		$payment_filter = static function($value, $object) use ($refund) {
			return $object instanceof WC_Order_Refund && $object->get_id() === $refund->get_id() ? !$value : $value;
		};
		$item_total_filter = static function($value, $object) use ($refund_line) {
			return $object instanceof WC_Order_Item_Product && $object->get_id() === $refund_line->get_id() ? '-999.99' : $value;
		};
		$order_status_filter = static function($value, $object) use ($target) {
			return $object instanceof WC_Order && $object->get_id() === $target->get_id() ? 'processing' : $value;
		};
		$transaction_filter = static function($value, $object) use ($target) {
			return $object instanceof WC_Order && $object->get_id() === $target->get_id() ? 'filtered-transaction-id' : $value;
		};
		$order_item_ids = array_map('absint', array(
			$target_line->get_id(),
			$target_shipping->get_id(),
			$refund_line->get_id(),
			$refund_shipping->get_id(),
		));
		$order_id_filter = static function($value, $object) use ($order_item_ids) {
			return $object instanceof WC_Order_Item && in_array(absint($object->get_id()), $order_item_ids, true) ? 999999 : $value;
		};
		$refunded_item_filter = static function($value, $object) use ($refund_line, $refund_shipping) {
			return $object instanceof WC_Order_Item
				&& in_array(absint($object->get_id()), array(absint($refund_line->get_id()), absint($refund_shipping->get_id())), true)
				? 999999
				: $value;
		};
		$instance_id_filter = static function($value, $object) use ($refund_shipping, $filtered_shipping_method_id) {
			return $object instanceof WC_Order_Item_Shipping && absint($object->get_id()) === absint($refund_shipping->get_id())
				? $filtered_shipping_method_id
				: $value;
		};
		$order_items_filter = static function($items, $order) use ($refund) {
			return $order instanceof WC_Order_Refund && absint($order->get_id()) === absint($refund->get_id()) ? array() : $items;
		};
		add_filter('woocommerce_order_refund_get_currency', $currency_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_refund_get_amount', $amount_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_refund_get_refunded_payment', $payment_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_item_get_total', $item_total_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_get_status', $order_status_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_get_transaction_id', $transaction_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_item_get_order_id', $order_id_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_item_get__refunded_item_id', $refunded_item_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_item_get_instance_id', $instance_id_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_get_items', $order_items_filter, PHP_INT_MAX, 2);
		try {
			$filtered_refund = wc_get_order($refund->get_id());
			$filtered_line = $filtered_refund->get_item($refund_line->get_id());
			$filtered_shipping = $filtered_refund->get_item($refund_shipping->get_id());
			$filtered_target = wc_get_order($target->get_id());
			self::assert('EUR' === $filtered_refund->get_currency()
				&& '999.99' === WCOS_Decimal::normalize($filtered_refund->get_amount(), 2)
				&& $filtered_refund->get_refunded_payment() !== $filtered_refund->get_refunded_payment('edit')
				&& '-999.99' === WCOS_Decimal::normalize($filtered_line->get_total(), 2)
				&& 'processing' === $filtered_target->get_status()
				&& 'filtered-transaction-id' === $filtered_target->get_transaction_id()
				&& 999999 === (int) $filtered_line->get_order_id()
				&& (int) $refund->get_id() === (int) $filtered_line->get_order_id('edit')
				&& 999999 === (int) $filtered_line->get_meta('_refunded_item_id', true)
				&& (int) $target_line->get_id() === (int) $filtered_line->get_meta('_refunded_item_id', true, 'edit')
				&& $filtered_shipping_method_id === absint($filtered_shipping->get_instance_id())
				&& $canonical_shipping_method_id === absint($filtered_shipping->get_instance_id('edit'))
				&& 'taxable' === (string) $filtered_shipping->get_tax_status('edit')
				&& array() === $filtered_refund->get_items(array('line_item', 'shipping', 'fee', 'tax')), 'Representative view filters were not active for the unfiltered-authority regression.');
			$with_filters = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
			self::assert($baseline === $with_filters, 'Presentation filters changed canonical order, refund, or refund-item authority.');
		} finally {
			remove_filter('woocommerce_order_refund_get_currency', $currency_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_refund_get_amount', $amount_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_refund_get_refunded_payment', $payment_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_item_get_total', $item_total_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_get_status', $order_status_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_get_transaction_id', $transaction_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_item_get_order_id', $order_id_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_item_get__refunded_item_id', $refunded_item_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_item_get_instance_id', $instance_id_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_get_items', $order_items_filter, PHP_INT_MAX);
		}

		$date_source = self::order('masked-refund-date-source');
		$date_target = self::order('masked-refund-date-target', 'on-hold');
		self::line($date_source, $product, array('subtotal' => '2.00', 'total' => '0.00'));
		self::line($date_target, $product, array('subtotal' => '10.00', 'total' => '10.00'));
		$date_source = self::finalize($date_source);
		$date_target = self::finalize($date_target);
		$date_refund = self::create_refund($date_target, '1.00', array(), 'Masked refund date authority');
		$date_source = wc_get_order($date_source->get_id());
		$date_target = wc_get_order($date_target->get_id());
		$date_refund = wc_get_order($date_refund->get_id());
		$old_date = $date_refund->get_date_created('edit');
		self::assert($old_date instanceof WC_DateTime, 'Masked refund-date fixture lacks a persisted date.');
		$old_timestamp = (int) $old_date->getTimestamp();
		$before_authority = WCOS_Merge_Financial_Authority::freeze_pair($date_source, $date_target, 2);
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		$request = self::request($date_source, $date_target);
		$review = $controller->review_request($request);
		self::$review_ids[] = $review['review_id'];
		$date_filter = static function($value, $object) use ($date_refund, $old_date) {
			return $object instanceof WC_Order_Refund && $object->get_id() === $date_refund->get_id() ? clone $old_date : $value;
		};
		add_filter('woocommerce_order_refund_get_date_created', $date_filter, PHP_INT_MAX, 2);
		try {
			$date_refund = wc_get_order($date_refund->get_id());
			$date_refund->set_date_created($old_timestamp + HOUR_IN_SECONDS);
			$date_refund->save();
			$date_refund = wc_get_order($date_refund->get_id());
			$date_target = wc_get_order($date_target->get_id());
			self::assert($old_timestamp + HOUR_IN_SECONDS === (int) $date_refund->get_date_created('edit')->getTimestamp()
				&& $old_timestamp === (int) $date_refund->get_date_created()->getTimestamp(), 'Refund date filter did not mask the persisted drift in view context.');
			$after_authority = WCOS_Merge_Financial_Authority::freeze_pair($date_source, $date_target, 2);
			self::assert($before_authority['target']['refund_structure_fingerprint'] !== $after_authority['target']['refund_structure_fingerprint'], 'Masked persisted refund date drift did not change canonical authority.');
			$before_journals = self::journal_option_count();
			self::expect_transport_one_of(static function() use ($controller, $request, $review) {
				$controller->confirm_request(array_merge($request, array(
					'review_id' => $review['review_id'],
					'review_token' => $review['review_token'],
				)));
			}, array('review_target_changed', 'review_pair_changed', 'review_authority_changed'));
			self::assert($before_journals === self::journal_option_count()
				&& empty(WCOS_Merge_Participation::authorities(wc_get_order($date_source->get_id())))
				&& empty(WCOS_Merge_Participation::authorities(wc_get_order($date_target->get_id())))
				&& false === get_option('wcos_mutation_lock_' . $date_source->get_id(), false)
				&& false === get_option('wcos_mutation_lock_' . $date_target->get_id(), false), 'Masked refund date drift crossed the pre-lease or pre-journal boundary.');
		} finally {
			remove_filter('woocommerce_order_refund_get_date_created', $date_filter, PHP_INT_MAX);
		}

		self::$results['unfiltered_refund_projection'] = array(
			'cases' => '76-78',
			'cross_store_completed_status_bound' => true,
			'cpt_prepoisoned_query_cache_bypassed' => $cpt_query_cache_exercised ? true : 'not_applicable',
			'order_view_filters_ignored' => true,
			'refund_view_filters_ignored' => true,
			'refund_item_view_filters_ignored' => true,
			'refund_item_ownership_filters_ignored' => true,
			'refund_reference_meta_filters_ignored' => true,
			'shipping_instance_filters_ignored' => true,
			'refund_item_collection_filters_ignored' => true,
			'refund_query_filters_ignored' => true,
			'masked_date_drift_rejected_pre_lease' => true,
		);
	}

	private static function canonical_merge_authority_filters() {
		$product = self::product('canonical-authority-filters', true);
		$source = self::order('canonical-authority-filters-source');
		$target = self::order('canonical-authority-filters-target', 'on-hold');
		$source_line = self::line($source, $product, array(
			'subtotal' => '6.00',
			'total' => '4.00',
			'reduced_stock' => '1.000000',
			'meta' => array('Configuration' => 'canonical-source'),
		));
		$target_line = self::line($target, $product, array(
			'subtotal' => '10.00',
			'total' => '9.00',
			'reduced_stock' => '1.000000',
			'meta' => array('Configuration' => 'canonical-source'),
		));
		$target_shipping = self::shipping($target, 'Canonical target shipping', '3.00');
		$target_fee = self::fee($target, 'Canonical target fee', '2.00');
		$target_coupon = self::coupon($target, 'canonical-target-coupon', '1.00');
		$target_tax = self::tax($target, 8806, '0.00', '0.00');
		$source = self::finalize($source);
		$target = self::finalize($target);
		$source->get_data_store()->set_stock_reduced($source->get_id(), true);
		$target->get_data_store()->set_stock_reduced($target->get_id(), true);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$baseline_report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		$baseline_source_signature = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
		$baseline_target_signature = WCOS_Merge_Recovery_Snapshot::participant_signature($target);

		$original_registry_filter = static function($value) { return $value; };
		$injected_registry_filter = static function($value) { return $value; };
		$injected_registry_hook = 'woocommerce_order_get_customer_note';
		add_filter('woocommerce_order_get_total', $original_registry_filter, 7, 1);
		$registry_exception = false;
		try {
			WCOS_Merge_Canonical_Reader::without_presentation_filters(static function() use ($injected_registry_filter, $injected_registry_hook) {
				add_filter('woocommerce_order_get_total', $injected_registry_filter, 13, 1);
				add_filter($injected_registry_hook, $injected_registry_filter, 17, 1);
				throw new RuntimeException('canonical-reader-registry-probe');
			});
		} catch (RuntimeException $exception) {
			$registry_exception = 'canonical-reader-registry-probe' === $exception->getMessage();
		}
		self::assert($registry_exception
			&& 7 === has_filter('woocommerce_order_get_total', $original_registry_filter)
			&& false === has_filter('woocommerce_order_get_total', $injected_registry_filter)
			&& false === has_filter($injected_registry_hook, $injected_registry_filter), 'Canonical read isolation did not restore the exact request-local hook registry after failure.');
		remove_filter('woocommerce_order_get_total', $original_registry_filter, 7);

		$search_query_calls = 0;
		$search_query_filters = array();
		foreach (array(
			'woocommerce_order_query_args',
			'woocommerce_order_query',
			'woocommerce_get_wp_query_args',
			'woocommerce_order_data_store_cpt_get_orders_query',
			'woocommerce_orders_table_datastore_get_orders_query',
			'woocommerce_hpos_pre_query',
			'parse_query',
			'pre_get_posts',
			'posts_pre_query',
		) as $search_query_hook) {
			$search_query_filter = static function($value = null) use (&$search_query_calls, $search_query_hook) {
				$search_query_calls++;
				return in_array($search_query_hook, array('parse_query', 'pre_get_posts'), true) ? $value : array();
			};
			add_filter($search_query_hook, $search_query_filter, PHP_INT_MAX, 99);
			$search_query_filters[] = array($search_query_hook, $search_query_filter);
		}
		try {
			$search = WCOS_Merge_Admin_Controller::bootstrap()->search_request(array(
				'source_order_id' => $source->get_id(),
				'nonce' => wp_create_nonce('wcos_merge_order_' . $source->get_id()),
				'term' => '',
				'page' => 1,
			));
			$search_ids = array_map('absint', wp_list_pluck($search['results'], 'id'));
			self::assert(in_array(absint($target->get_id()), $search_ids, true)
				&& 0 === $search_query_calls, 'Order-query hooks omitted an eligible persisted Merge search target.');
		} finally {
			foreach (array_reverse($search_query_filters) as $search_query_filter) {
				remove_filter($search_query_filter[0], $search_query_filter[1], PHP_INT_MAX);
			}
		}
		$number_alias = 'filtered-only-order-alias';
		$order_number_filter = static function($number, $order) use ($target, $number_alias) {
			return $order instanceof WC_Order && absint($order->get_id()) === absint($target->get_id()) ? $number_alias : $number;
		};
		add_filter('woocommerce_order_number', $order_number_filter, PHP_INT_MAX, 2);
		try {
			$alias_search = WCOS_Merge_Admin_Controller::bootstrap()->search_request(array(
				'source_order_id' => $source->get_id(),
				'nonce' => wp_create_nonce('wcos_merge_order_' . $source->get_id()),
				'term' => $number_alias,
				'page' => 1,
			));
			self::assert($number_alias === (string) wc_get_order($target->get_id())->get_order_number()
				&& array() === $alias_search['results'], 'A presentation-only order number changed canonical search reachability.');
		} finally {
			remove_filter('woocommerce_order_number', $order_number_filter, PHP_INT_MAX);
		}

		$stock_parent = new WC_Product_Variable();
		$stock_parent->set_name('WOS COMPAT 006 canonical parent-managed stock');
		$stock_parent->set_status('publish');
		$stock_parent->set_manage_stock(true);
		$stock_parent->set_stock_quantity(37);
		$stock_parent_id = absint($stock_parent->save());
		self::$product_ids[] = $stock_parent_id;
		$stock_variation = new WC_Product_Variation();
		$stock_variation->set_parent_id($stock_parent_id);
		$stock_variation->set_status('publish');
		$stock_variation->set_regular_price('10.00');
		$stock_variation->set_price('10.00');
		$stock_variation_id = absint($stock_variation->save());
		self::$product_ids[] = $stock_variation_id;
		$stock_variation = wc_get_product($stock_variation_id);
		$stock_order = self::order('canonical-parent-managed-stock');
		self::line($stock_order, $stock_variation, array('subtotal' => '10.00', 'total' => '10.00'));
		$stock_order = self::finalize($stock_order);
		$stock_baseline = WCOS_Merge_Canonical_Reader::product_stock($stock_order);
		$variation_manage_filter = static function() { return false; };
		$variation_parent_filter = static function() { return 999999; };
		$parent_manage_filter = static function() { return false; };
		add_filter('woocommerce_product_variation_get_manage_stock', $variation_manage_filter, PHP_INT_MAX, 1);
		add_filter('woocommerce_product_variation_get_parent_id', $variation_parent_filter, PHP_INT_MAX, 1);
		add_filter('woocommerce_product_get_manage_stock', $parent_manage_filter, PHP_INT_MAX, 1);
		try {
			$filtered_variation = wc_get_product($stock_variation_id);
			self::assert(false === $filtered_variation->get_manage_stock()
				&& 999999 === absint($filtered_variation->get_parent_id())
				&& array($stock_parent_id => '37.000000') === $stock_baseline
				&& $stock_baseline === WCOS_Merge_Canonical_Reader::product_stock(wc_get_order($stock_order->get_id())), 'Product view filters changed canonical parent-managed stock conservation authority.');
		} finally {
			remove_filter('woocommerce_product_variation_get_manage_stock', $variation_manage_filter, PHP_INT_MAX);
			remove_filter('woocommerce_product_variation_get_parent_id', $variation_parent_filter, PHP_INT_MAX);
			remove_filter('woocommerce_product_get_manage_stock', $parent_manage_filter, PHP_INT_MAX);
		}
		$participant_ids = array(absint($source->get_id()), absint($target->get_id()));
		$item_ids = array_map('absint', array(
			$source_line->get_id(),
			$target_line->get_id(),
			$target_shipping->get_id(),
			$target_fee->get_id(),
			$target_coupon->get_id(),
			$target_tax->get_id(),
		));
		$filters = array();
		$add_filter = static function($hook, $callback, $accepted_args = 2) use (&$filters) {
			add_filter($hook, $callback, PHP_INT_MAX, $accepted_args);
			$filters[] = array($hook, $callback);
		};
		$order_mask = static function($replacement) use ($participant_ids) {
			return static function($value, $object) use ($participant_ids, $replacement) {
				return $object instanceof WC_Order && in_array(absint($object->get_id()), $participant_ids, true)
					? $replacement
					: $value;
			};
		};
		$item_mask = static function($replacement) use ($item_ids) {
			return static function($value, $object) use ($item_ids, $replacement) {
				return $object instanceof WC_Order_Item && in_array(absint($object->get_id()), $item_ids, true)
					? $replacement
					: $value;
			};
		};

		$order_masks = array(
			'woocommerce_order_get_status' => 'processing',
			'woocommerce_order_get_currency' => 'EUR',
			'woocommerce_order_get_prices_include_tax' => true,
			'woocommerce_order_get_customer_id' => 999999,
			'woocommerce_order_get_billing_first_name' => 'Filtered Billing',
			'woocommerce_order_get_billing_last_name' => 'View',
			'woocommerce_order_get_billing_company' => 'Filtered Company',
			'woocommerce_order_get_billing_address_1' => 'Filtered billing address',
			'woocommerce_order_get_billing_address_2' => 'Filtered billing address 2',
			'woocommerce_order_get_billing_city' => 'Filtered City',
			'woocommerce_order_get_billing_state' => 'CA',
			'woocommerce_order_get_billing_postcode' => '99999',
			'woocommerce_order_get_billing_country' => 'CA',
			'woocommerce_order_get_billing_email' => 'filtered@example.test',
			'woocommerce_order_get_billing_phone' => '+1-filtered',
			'woocommerce_order_get_shipping_first_name' => 'Filtered Shipping',
			'woocommerce_order_get_shipping_last_name' => 'View',
			'woocommerce_order_get_shipping_company' => 'Filtered Carrier',
			'woocommerce_order_get_shipping_address_1' => 'Filtered shipping address',
			'woocommerce_order_get_shipping_address_2' => 'Filtered shipping address 2',
			'woocommerce_order_get_shipping_city' => 'Filtered Shipping City',
			'woocommerce_order_get_shipping_state' => 'NY',
			'woocommerce_order_get_shipping_postcode' => '11111',
			'woocommerce_order_get_shipping_country' => 'GB',
			'woocommerce_order_get_payment_method' => 'cod',
			'woocommerce_order_get_payment_method_title' => 'Filtered payment',
			'woocommerce_order_get_discount_total' => '901.01',
			'woocommerce_order_get_discount_tax' => '902.02',
			'woocommerce_order_get_shipping_total' => '903.03',
			'woocommerce_order_get_shipping_tax' => '904.04',
			'woocommerce_order_get_cart_tax' => '905.05',
			'woocommerce_order_get_total_tax' => '906.06',
			'woocommerce_order_get_total' => '907.07',
		);
		foreach ($order_masks as $hook => $replacement) {
			$add_filter($hook, $order_mask($replacement));
		}

		$item_masks = array(
			'woocommerce_order_item_get_name' => 'Filtered item name',
			'woocommerce_order_item_get_product_id' => 999991,
			'woocommerce_order_item_get_variation_id' => 999992,
			'woocommerce_order_item_get_tax_class' => 'filtered-rate',
			'woocommerce_order_item_get_quantity' => '99.000000',
			'woocommerce_order_item_get_subtotal' => '911.11',
			'woocommerce_order_item_get_subtotal_tax' => '912.12',
			'woocommerce_order_item_get_total' => '913.13',
			'woocommerce_order_item_get_total_tax' => '914.14',
			'woocommerce_order_item_get_taxes' => array('subtotal' => array(999 => '9.99'), 'total' => array(999 => '8.88')),
			'woocommerce_order_item_get__reduced_stock' => '77.000000',
			'woocommerce_order_item_get_method_id' => 'filtered_shipping',
			'woocommerce_order_item_get_instance_id' => 999993,
			'woocommerce_order_item_get_amount' => '915.15',
			'woocommerce_order_item_get_discount' => '916.16',
			'woocommerce_order_item_get_discount_tax' => '917.17',
			'woocommerce_order_item_get_rate_id' => 999994,
			'woocommerce_order_item_get_tax_total' => '918.18',
			'woocommerce_order_item_get_shipping_tax_total' => '919.19',
		);
		foreach ($item_masks as $hook => $replacement) {
			$add_filter($hook, $item_mask($replacement));
		}
		$order_items_filter = static function($items, $order) use ($participant_ids) {
			return $order instanceof WC_Order && in_array(absint($order->get_id()), $participant_ids, true)
				? array()
				: $items;
		};
		$add_filter('woocommerce_order_get_items', $order_items_filter, 3);

		try {
			$filtered_source = wc_get_order($source->get_id());
			$filtered_target = wc_get_order($target->get_id());
			$filtered_source_line = $filtered_source->get_item($source_line->get_id());
			$order_class = get_class($filtered_source);
			$hydrated_under_filters = new $order_class($source->get_id());
			$canonically_hydrated = WCOS_Merge_Canonical_Reader::order($source->get_id());
			self::assert($filtered_source_line instanceof WC_Order_Item_Product
				&& 'EUR' === $filtered_source->get_currency()
				&& 'USD' === $filtered_source->get_currency('edit')
				&& true === $filtered_source->get_prices_include_tax()
				&& false === $filtered_source->get_prices_include_tax('edit')
				&& 999999 === absint($filtered_source->get_customer_id())
				&& 'filtered@example.test' === $filtered_source->get_billing_email()
				&& 'bacs' === $filtered_source->get_payment_method('edit')
				&& '907.07' === WCOS_Decimal::normalize($filtered_source->get_total(), 2)
				&& 'Filtered item name' === $filtered_source_line->get_name()
				&& 999991 === absint($filtered_source_line->get_product_id())
				&& $product->get_id() === absint($filtered_source_line->get_product_id('edit'))
				&& '99.000000' === WCOS_Decimal::normalize($filtered_source_line->get_quantity(), 6)
				&& '77.000000' === WCOS_Decimal::normalize($filtered_source_line->get_meta('_reduced_stock', true), 6)
				&& '1.000000' === WCOS_Decimal::normalize($filtered_source_line->get_meta('_reduced_stock', true, 'edit'), 6)
				&& array() === $filtered_source->get_items('line_item')
				&& array() === $filtered_target->get_items(array('line_item', 'shipping', 'fee', 'coupon', 'tax')), 'Representative Merge presentation filters were not active.');
			self::assert($hydrated_under_filters instanceof WC_Order
				&& $canonically_hydrated instanceof WC_Order
				&& '1809.09' === WCOS_Decimal::normalize($hydrated_under_filters->get_total_tax('edit'), 2)
				&& '0.00' === WCOS_Decimal::normalize($canonically_hydrated->get_total_tax('edit'), 2), 'Canonical order hydration did not isolate WooCommerce setter-time presentation getters.');
			$filtered_report = WCOS_Merge_Preflight::assert_supported($filtered_source, $filtered_target, 2);
			self::assert($baseline_report === $filtered_report
				&& $baseline_source_signature === WCOS_Merge_Recovery_Snapshot::participant_signature($filtered_source)
				&& $baseline_target_signature === WCOS_Merge_Recovery_Snapshot::participant_signature($filtered_target), 'Presentation filters changed current/fresh Merge authority.');

			$controller = WCOS_Merge_Admin_Controller::bootstrap();
			$request = self::request($filtered_source, $filtered_target);
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
			$persisted_source = wc_get_order($source->get_id());
			$persisted_target = wc_get_order($target->get_id());
			self::assert('completed' === $result['status']
				&& 'trash' === WCOS_Merge_Canonical_Reader::status($persisted_source)
				&& 'processing' === $persisted_source->get_status()
				&& 'on-hold' === WCOS_Merge_Canonical_Reader::status($persisted_target)
				&& 1 === count(WCOS_Merge_Canonical_Reader::items($persisted_target, 'line_item'))
				&& 1 === count(WCOS_Merge_Canonical_Reader::items($persisted_target, 'shipping'))
				&& 1 === count(WCOS_Merge_Canonical_Reader::items($persisted_target, 'fee'))
				&& 1 === count(WCOS_Merge_Canonical_Reader::items($persisted_target, 'coupon'))
				&& 1 === count(WCOS_Merge_Canonical_Reader::items($persisted_target, 'tax')), 'Filtered Review/Confirm/Execute did not preserve canonical Merge behavior.');
		} finally {
			foreach (array_reverse($filters) as $filter) {
				remove_filter($filter[0], $filter[1], PHP_INT_MAX);
			}
		}

		$terminal_source = self::order('canonical-terminal-source');
		$terminal_target = self::order('canonical-terminal-source-target', 'on-hold');
		self::line($terminal_source, $product, array('subtotal' => '1.00', 'total' => '0.00'));
		self::line($terminal_target, $product, array('subtotal' => '2.00', 'total' => '2.00'));
		$terminal_source = self::finalize($terminal_source);
		$terminal_target = self::finalize($terminal_target);
		$terminal_source->set_status('refunded');
		$terminal_source->save();
		$terminal_status_filter = static function($value, $object) use ($terminal_source) {
			return $object instanceof WC_Order && absint($object->get_id()) === absint($terminal_source->get_id()) ? 'pending' : $value;
		};
		add_filter('woocommerce_order_get_status', $terminal_status_filter, PHP_INT_MAX, 2);
		try {
			$terminal_source = wc_get_order($terminal_source->get_id());
			self::assert('pending' === $terminal_source->get_status()
				&& 'refunded' === WCOS_Merge_Canonical_Reader::status($terminal_source)
				&& 'incompatible_status' === WCOS_Merge_Preflight::report($terminal_source, $terminal_target, 2)['reason'], 'A filtered terminal source status was not rejected from persisted authority.');
			self::expect_transport_one_of(static function() use ($terminal_source, $terminal_target) {
				WCOS_Merge_Admin_Controller::bootstrap()->review_request(self::request($terminal_source, $terminal_target));
			}, array('status_disabled'));
		} finally {
			remove_filter('woocommerce_order_get_status', $terminal_status_filter, PHP_INT_MAX);
		}

		$prelease_source = self::order('canonical-prelease-source');
		$prelease_target = self::order('canonical-prelease-target', 'on-hold');
		self::line($prelease_source, $product, array('subtotal' => '1.00', 'total' => '0.00'));
		self::line($prelease_target, $product, array('subtotal' => '2.00', 'total' => '2.00'));
		$prelease_source = self::finalize($prelease_source);
		$prelease_target = self::finalize($prelease_target);
		$prelease_report = WCOS_Merge_Preflight::assert_supported($prelease_source, $prelease_target, 2);
		$prelease_review = WCOS_Merge_Review_Store::create($prelease_source, $prelease_target, $prelease_report, self::$operator_id);
		self::$review_ids[] = $prelease_review['review_id'];
		$prelease_confirmation = WCOS_Merge_Confirmation_Store::create($prelease_source, $prelease_target, $prelease_review['authority'], self::$operator_id);
		self::$operation_ids[$prelease_source->get_id()][] = $prelease_confirmation['operation_id'];
		$prelease_authority = WCOS_Merge_Confirmation_Store::operation_authority($prelease_confirmation['record']);
		$prelease_target->set_status('cancelled');
		$prelease_target->save();
		$prelease_status_filter = static function($value, $object) use ($prelease_target) {
			return $object instanceof WC_Order && absint($object->get_id()) === absint($prelease_target->get_id()) ? 'on-hold' : $value;
		};
		$lease_events = 0;
		$lease_probe = static function() use (&$lease_events) { $lease_events++; };
		add_filter('woocommerce_order_get_status', $prelease_status_filter, PHP_INT_MAX, 2);
		add_action('add_option_wcos_mutation_lock_' . $prelease_source->get_id(), $lease_probe, PHP_INT_MAX, 2);
		add_action('add_option_wcos_mutation_lock_' . $prelease_target->get_id(), $lease_probe, PHP_INT_MAX, 2);
		try {
			$rejected_code = '';
			try {
				(new WCOS_Mutation_Gateway())->merge(
					wc_get_order($prelease_source->get_id()),
					wc_get_order($prelease_target->get_id()),
					$prelease_confirmation['operation_id'],
					2,
					$prelease_authority
				);
			} catch (WCOS_Merge_Adapter_Exception $exception) {
				$rejected_code = $exception->get_error_code();
			} catch (WCOS_Merge_Preflight_Exception $exception) {
				$rejected_code = 'merge_preflight_' . $exception->get_reason();
			}
			self::assert('on-hold' === wc_get_order($prelease_target->get_id())->get_status()
				&& 'cancelled' === WCOS_Merge_Canonical_Reader::status(wc_get_order($prelease_target->get_id()))
				&& 'merge_preflight_incompatible_status' === $rejected_code
				&& 0 === $lease_events
				&& null === WCOS_Operation_Journal::get(wc_get_order($prelease_source->get_id()), $prelease_confirmation['operation_id']), 'A filtered terminal target crossed the direct-gateway pre-lease boundary.');
		} finally {
			remove_filter('woocommerce_order_get_status', $prelease_status_filter, PHP_INT_MAX);
			remove_action('add_option_wcos_mutation_lock_' . $prelease_source->get_id(), $lease_probe, PHP_INT_MAX);
			remove_action('add_option_wcos_mutation_lock_' . $prelease_target->get_id(), $lease_probe, PHP_INT_MAX);
		}

		$currency_source = self::order('canonical-currency-source');
		$currency_target = self::order('canonical-currency-target', 'on-hold');
		self::line($currency_source, $product, array('subtotal' => '1.00', 'total' => '0.00'));
		self::line($currency_target, $product, array('subtotal' => '2.00', 'total' => '2.00'));
		$currency_source = self::finalize($currency_source);
		$currency_target = self::finalize($currency_target);
		$currency_target->set_currency('EUR');
		$currency_target->save();
		$currency_filter = static function($value, $object) use ($currency_target) {
			return $object instanceof WC_Order && absint($object->get_id()) === absint($currency_target->get_id()) ? 'USD' : $value;
		};
		add_filter('woocommerce_order_get_currency', $currency_filter, PHP_INT_MAX, 2);
		try {
			$currency_target = wc_get_order($currency_target->get_id());
			self::assert('USD' === $currency_target->get_currency()
				&& 'EUR' === WCOS_Merge_Canonical_Reader::currency($currency_target)
				&& 'incompatible_currency' === WCOS_Merge_Preflight::report($currency_source, $currency_target, 2)['reason'], 'A filtered currency mismatch did not fail closed.');
		} finally {
			remove_filter('woocommerce_order_get_currency', $currency_filter, PHP_INT_MAX);
		}

		$tax_source = self::order('canonical-tax-mode-source');
		$tax_target = self::order('canonical-tax-mode-target', 'on-hold');
		self::line($tax_source, $product, array('subtotal' => '1.00', 'total' => '0.00'));
		self::line($tax_target, $product, array('subtotal' => '2.00', 'total' => '2.00'));
		$tax_source = self::finalize($tax_source);
		$tax_target = self::finalize($tax_target);
		$tax_target->set_prices_include_tax(true);
		$tax_target->save();
		$tax_mode_filter = static function($value, $object) use ($tax_target) {
			return $object instanceof WC_Order && absint($object->get_id()) === absint($tax_target->get_id()) ? false : $value;
		};
		add_filter('woocommerce_order_get_prices_include_tax', $tax_mode_filter, PHP_INT_MAX, 2);
		try {
			$tax_target = wc_get_order($tax_target->get_id());
			self::assert(false === $tax_target->get_prices_include_tax()
				&& true === WCOS_Merge_Canonical_Reader::prices_include_tax($tax_target)
				&& 'incompatible_pricing_mode' === WCOS_Merge_Preflight::report($tax_source, $tax_target, 2)['reason'], 'A filtered tax-mode mismatch did not fail closed.');
		} finally {
			remove_filter('woocommerce_order_get_prices_include_tax', $tax_mode_filter, PHP_INT_MAX);
		}

		self::$results['canonical_merge_authority_filters'] = array(
			'cases' => '79-93',
			'order_and_item_view_filters_ignored' => true,
			'collection_filters_ignored' => true,
			'identity_address_payment_filters_ignored' => true,
			'target_charges_and_tax_filters_ignored' => true,
			'review_confirm_execute_under_filters' => true,
			'current_hydration_filters_bypassed' => true,
			'terminal_source_rejected' => true,
			'terminal_target_rejected_pre_lease' => true,
			'currency_and_tax_mode_masks_rejected' => true,
			'exact_hook_registry_restored' => true,
			'query_and_order_number_search_filters_ignored' => true,
			'parent_managed_stock_filters_ignored' => true,
		);
	}

	private static function refund_tax_distribution_drift_authority() {
		$product = self::product('refund-tax-distribution-drift');
		$source = self::order('refund-tax-distribution-drift-source');
		$target = self::order('refund-tax-distribution-drift-target', 'on-hold');
		$rate_id = self::native_tax_rate('WOS COMPAT 006 native refund rate');
		self::line($source, $product, array('subtotal' => '0.00', 'total' => '0.00'));
		$target_line = self::line($target, $product, array(
			'subtotal' => '10.00',
			'total' => '10.00',
			'subtotal_tax' => '0.50',
			'total_tax' => '0.50',
			'taxes' => array('subtotal' => array($rate_id => '0.50'), 'total' => array($rate_id => '0.50')),
		));
		$target_shipping = self::shipping($target, 'Native taxed target shipping', '3.00', array($rate_id => '0.20'));
		$target_fee = self::fee($target, 'Native taxed target fee', '2.00', array($rate_id => '0.10'));
		$target_tax = self::tax($target, $rate_id, '0.60', '0.20', true);
		$source = self::finalize($source);
		$target = self::finalize(wc_get_order($target->get_id()));
		$refund = self::create_refund($target, '3.68', array(
			$target_line->get_id() => array('qty' => 1, 'refund_total' => '2.00', 'refund_tax' => array($rate_id => '0.10')),
			$target_shipping->get_id() => array('qty' => 0, 'refund_total' => '1.00', 'refund_tax' => array($rate_id => '0.05')),
			$target_fee->get_id() => array('qty' => 0, 'refund_total' => '0.50', 'refund_tax' => array($rate_id => '0.03')),
		), 'Refund-tax distribution authority fixture');
		$refund_taxes = $refund->get_items('tax');
		$refund_tax = reset($refund_taxes);
		$refund_lines = $refund->get_items('line_item');
		$refund_line = reset($refund_lines);
		$refund_shipping_items = $refund->get_items('shipping');
		$refund_shipping = reset($refund_shipping_items);
		$refund_fees = $refund->get_items('fee');
		$refund_fee = reset($refund_fees);
		self::assert($target_tax instanceof WC_Order_Item_Tax
			&& $refund_tax instanceof WC_Order_Item_Tax
			&& $rate_id === absint($refund_tax->get_rate_id('edit'))
			&& 0 === absint($refund_tax->get_meta('_refunded_item_id', true))
			&& '3.68' === WCOS_Decimal::normalize($refund->get_amount(), 2)
			&& '-0.13' === WCOS_Decimal::normalize($refund_tax->get_tax_total('edit'), 2)
			&& '-0.05' === WCOS_Decimal::normalize($refund_tax->get_shipping_tax_total('edit'), 2), 'Refund-tax fixture did not preserve unmodified native WooCommerce tax authority.');
		self::assert($refund_line instanceof WC_Order_Item_Product
			&& $target_line->get_id() === absint($refund_line->get_meta('_refunded_item_id', true))
			&& $refund_shipping instanceof WC_Order_Item_Shipping
			&& $target_shipping->get_id() === absint($refund_shipping->get_meta('_refunded_item_id', true))
			&& $refund_fee instanceof WC_Order_Item_Fee
			&& $target_fee->get_id() === absint($refund_fee->get_meta('_refunded_item_id', true)), 'Native line, shipping, or fee refund reference semantics changed.');

		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		self::assert(WCOS_Merge_Preflight::assert_supported($source, $target, 2)['supported'], 'A standard native taxed partial refund was not eligible.');
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		$request = self::request($source, $target);
		$review = $controller->review_request($request);
		self::$review_ids[] = $review['review_id'];
		$before_drift = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
		$tax_total_filter = static function($value, $object) use ($refund_tax) {
			return $object instanceof WC_Order_Item_Tax && $object->get_id() === $refund_tax->get_id() ? '-999.99' : $value;
		};
		$tax_collection_filter = static function($items, $order, $types) use ($target, $target_tax) {
			if ($order instanceof WC_Order && absint($order->get_id()) === absint($target->get_id())
				&& in_array('tax', (array) $types, true)) {
				$items[999999] = $target_tax;
			}
			return $items;
		};
		add_filter('woocommerce_order_item_get_tax_total', $tax_total_filter, PHP_INT_MAX, 2);
		add_filter('woocommerce_order_get_items', $tax_collection_filter, PHP_INT_MAX, 3);
		try {
			$filtered_refund_tax = wc_get_order($refund->get_id())->get_item($refund_tax->get_id());
			$filtered_target_taxes = wc_get_order($target->get_id())->get_items('tax');
			self::assert('-999.99' === WCOS_Decimal::normalize($filtered_refund_tax->get_tax_total(), 2)
				&& '-0.13' === WCOS_Decimal::normalize($filtered_refund_tax->get_tax_total('edit'), 2)
				&& isset($filtered_target_taxes[999999]), 'Refund-tax presentation filters were not active for the canonical-authority regression.');
			self::assert($before_drift === WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2), 'Refund-tax presentation filter changed canonical authority.');
		} finally {
			remove_filter('woocommerce_order_item_get_tax_total', $tax_total_filter, PHP_INT_MAX);
			remove_filter('woocommerce_order_get_items', $tax_collection_filter, PHP_INT_MAX);
		}
		$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
		$before_journals = self::journal_option_count();

		$refund_tax = wc_get_order($refund->get_id())->get_item($refund_tax->get_id());
		$refund_tax->set_tax_total('-0.12');
		$refund_tax->set_shipping_tax_total('-0.06');
		$refund_tax->save();
		$target = wc_get_order($target->get_id());
		$after_drift = WCOS_Merge_Financial_Authority::freeze_pair($source, $target, 2);
		$target_after_drift = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
		self::assert('3.68' === WCOS_Decimal::normalize($target->get_total_refunded(), 2)
			&& $before_drift['target']['refund_structure_fingerprint'] !== $after_drift['target']['refund_structure_fingerprint'], 'Aggregate-preserving refund-tax distribution drift did not change financial authority.');
		self::expect_transport_one_of(static function() use ($controller, $request, $review) {
			$controller->confirm_request(array_merge($request, array(
				'review_id' => $review['review_id'],
				'review_token' => $review['review_token'],
			)));
		}, array('review_target_changed', 'review_pair_changed', 'review_authority_changed'));
		self::assert($before_journals === self::journal_option_count()
			&& $source_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source->get_id()))
			&& $target_after_drift === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target->get_id())), 'Confirm rejection changed a participant or created a journal after refund-tax drift.');

		$refund_tax = wc_get_order($refund->get_id())->get_item($refund_tax->get_id());
		$refund_tax->set_tax_total('-0.13');
		$refund_tax->set_shipping_tax_total('-0.05');
		$refund_tax->save();
		$target = wc_get_order($target->get_id());
		$review = $controller->review_request(self::request($source, $target));
		self::$review_ids[] = $review['review_id'];
		$confirmation = $controller->confirm_request(array_merge(self::request($source, $target), array(
			'review_id' => $review['review_id'],
			'review_token' => $review['review_token'],
		)));
		self::$operation_ids[$source->get_id()][] = $confirmation['operation_id'];

		$refund_tax = wc_get_order($refund->get_id())->get_item($refund_tax->get_id());
		$refund_tax->set_tax_total('-0.12');
		$refund_tax->set_shipping_tax_total('-0.06');
		$refund_tax->save();
		$target = wc_get_order($target->get_id());
		$target_after_drift = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
		self::expect_transport_one_of(static function() use ($controller, $source, $target, $confirmation) {
			$controller->execute_request(array_merge(self::request($source, $target), array(
				'operation_id' => $confirmation['operation_id'],
				'confirmation_token' => $confirmation['confirmation_token'],
			)));
		}, array('confirmation_authority_changed'));
		self::assert($before_journals === self::journal_option_count()
			&& null === WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $confirmation['operation_id'])
			&& empty(WCOS_Merge_Participation::authorities(wc_get_order($source->get_id())))
			&& empty(WCOS_Merge_Participation::authorities(wc_get_order($target->get_id())))
			&& false === get_option('wcos_mutation_lock_' . $source->get_id(), false)
			&& false === get_option('wcos_mutation_lock_' . $target->get_id(), false)
			&& $source_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source->get_id()))
			&& $target_after_drift === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target->get_id())), 'Production Execute rejection crossed the pre-journal refund-tax authority boundary.');

		$refund_tax = wc_get_order($refund->get_id())->get_item($refund_tax->get_id());
		$refund_tax->set_tax_total('-0.13');
		$refund_tax->set_shipping_tax_total('-0.05');
		$refund_tax->save();
		$target = wc_get_order($target->get_id());
		$rate_drift_review = $controller->review_request(self::request($source, $target));
		self::$review_ids[] = $rate_drift_review['review_id'];
		$refund_tax = wc_get_order($refund->get_id())->get_item($refund_tax->get_id());
		$refund_tax->set_rate_id($rate_id + 1000000);
		$refund_tax->save();
		$target = wc_get_order($target->get_id());
		$rate_drift_target = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
		self::expect_transport_one_of(static function() use ($controller, $source, $target, $rate_drift_review) {
			$controller->confirm_request(array_merge(self::request($source, $target), array(
				'review_id' => $rate_drift_review['review_id'],
				'review_token' => $rate_drift_review['review_token'],
			)));
		}, array('review_target_changed', 'review_pair_changed', 'review_authority_changed', 'malformed_refund_authority'));
		self::assert($before_journals === self::journal_option_count()
			&& $rate_drift_target === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target->get_id())), 'Refund tax rate drift crossed the pre-journal boundary.');

		$refund_tax = wc_get_order($refund->get_id())->get_item($refund_tax->get_id());
		$refund_tax->set_rate_id($rate_id);
		$refund_tax->save();
		$target = wc_get_order($target->get_id());
		$duplicate_tax = self::tax($target, $rate_id, '0.00', '0.00', true);
		$ambiguous_before = WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target->get_id()));
		self::assert('malformed_refund_authority' === WCOS_Merge_Preflight::report($source, wc_get_order($target->get_id()), 2)['reason']
			&& $before_journals === self::journal_option_count()
			&& $ambiguous_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target->get_id())), 'Ambiguous target tax-rate authority did not fail closed without mutation.');
		$target = wc_get_order($target->get_id());
		$target->remove_item($duplicate_tax->get_id());
		$target->save();

		$refund_tax = wc_get_order($refund->get_id())->get_item($refund_tax->get_id());
		$refund_tax->set_rate_id(0);
		$refund_tax->save();
		$target = wc_get_order($target->get_id());
		$missing_before = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
		self::assert('malformed_refund_authority' === WCOS_Merge_Preflight::report($source, $target, 2)['reason']
			&& $before_journals === self::journal_option_count()
			&& $missing_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target->get_id())), 'Missing refund tax-rate authority did not fail closed without mutation.');

		self::$results['refund_tax_distribution_drift'] = array(
			'cases' => '60',
			'native_product_shipping_fee_refund' => true,
			'native_tax_reference_by_rate' => true,
			'target_tax_collection_filters_ignored' => true,
			'aggregate_refund_unchanged' => true,
			'confirm_rejected' => true,
			'production_gateway_rejected_pre_journal' => true,
			'rate_id_drift_rejected' => true,
			'ambiguous_rate_authority_rejected' => true,
			'missing_rate_authority_rejected' => true,
		);
	}

	private static function target_tax_row_distribution_preservation() {
		$product = self::product('target-tax-row-distribution');
		$source = self::order('target-tax-row-distribution-source');
		$target = self::order('target-tax-row-distribution-target', 'on-hold');
		self::line($source, $product, array(
			'name' => 'Exact zero-tax source line',
			'subtotal' => '0.00',
			'total' => '0.00',
			'subtotal_tax' => '0.00',
			'total_tax' => '0.00',
		));
		$target_line = self::line($target, $product, array(
			'name' => 'Historical target per-rate allocation',
			'subtotal' => '10.00',
			'total' => '10.00',
			'subtotal_tax' => '0.30',
			'total_tax' => '0.30',
			'taxes' => array(
				'subtotal' => array(609 => '0.10', 610 => '0.20'),
				'total' => array(609 => '0.10', 610 => '0.20'),
			),
		));
		self::tax($target, 609, '0.20', '0.00');
		self::tax($target, 610, '0.10', '0.00');
		$source = self::finalize($source);
		$target = self::finalize($target);
		$target->set_transaction_id('compat-006-target-tax-row-distribution');
		$target->save();
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		WCOS_Order_Totals_Rebuilder::assert_consistent($target, 2);
		$before = self::target_immutable_snapshot($target, array($target_line->get_id()));
		$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		self::assert('preserve_target_rows_only' === $report['plan']['tax_template_policy']
			&& empty($report['plan']['tax_template_rate_ids']), 'Financial target plan did not bind exact target tax-row preservation.');
		$policy_tamper = $report['plan'];
		$policy_tamper['tax_template_policy'] = 'import_source_product_rates';
		$policy_tamper_rejected = false;
		try {
			WCOS_Merge_Plan::canonicalize_current($policy_tamper);
		} catch (InvalidArgumentException $exception) {
			$policy_tamper_rejected = false !== strpos($exception->getMessage(), 'financial plan');
		}
		self::assert($policy_tamper_rejected, 'Durable financial-target plan allowed tax-row preservation policy tampering.');
		list($review, $confirmation, $result) = self::execute_controller($source, $target);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$journal = WCOS_Operation_Journal::get($source, $confirmation['operation_id']);
		self::assert('completed' === $result['status']
			&& 'completed' === sanitize_key(isset($journal['status']) ? (string) $journal['status'] : ''), 'Financial target with historical per-rate distribution did not complete.');
		self::assert($before === self::target_immutable_snapshot($target, array($target_line->get_id())), 'Financial Merge normalized pre-existing target tax rows.');
		self::assert(empty(WCOS_Manual_Reconciliation_Blocker::active_operation_ids($source))
			&& empty(WCOS_Manual_Reconciliation_Blocker::active_operation_ids($target)), 'Successful tax-row-preserving Merge left manual authority behind.');
		self::assert(!empty($review['summary']['target_financial_history_retained'])
			&& 'unchanged' === $review['summary']['target_payable_tax_disposition'], 'Review did not disclose exact target tax-row preservation.');

		self::$results['target_tax_row_distribution'] = array(
			'cases' => '59',
			'aggregate_consistent_per_rate_distribution_preserved' => true,
			'production_gateway_completed' => true,
			'manual_reconciliation' => false,
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

		$source = self::order('reject-cancelling-tax-rates-source');
		$target = self::order('reject-cancelling-tax-rates-target');
		$source_line = self::line($source, $product, array(
			'name' => 'Cancelling per-rate source taxes',
			'subtotal' => '0.00',
			'total' => '0.00',
			'subtotal_tax' => '0.00',
			'total_tax' => '0.00',
		));
		$target_line = self::line($target, $product, array(
			'name' => 'Existing financial target tax authority',
			'subtotal' => '10.00',
			'total' => '10.00',
			'subtotal_tax' => '0.30',
			'total_tax' => '0.30',
			'taxes' => array(
				'subtotal' => array(609 => '0.10', 610 => '0.20'),
				'total' => array(609 => '0.10', 610 => '0.20'),
			),
		));
		self::tax($target, 609, '0.10', '0.00');
		self::tax($target, 610, '0.20', '0.00');
		$source = self::finalize($source);
		$target = self::finalize($target);
		$target->set_transaction_id('reject-cancelling-tax-rates');
		$target->save();
		$target = wc_get_order($target->get_id());
		$neutral_plan = WCOS_Merge_Plan::build($source, $target, 2);
		$neutral_line_id = (int) $source_line->get_id();
		$neutral_plan['lines'][$neutral_line_id]['taxes']['total'] = array(609 => '0.01', 610 => '-0.01');
		$neutral_plan['lines'][$neutral_line_id]['target_after']['taxes']['total'] = array(609 => '0.01', 610 => '-0.01');
		$durable_plan_rejected = false;
		try {
			WCOS_Merge_Plan::canonicalize_current($neutral_plan);
		} catch (InvalidArgumentException $exception) {
			$durable_plan_rejected = false !== strpos($exception->getMessage(), 'settlement-neutral');
		}
		self::assert($durable_plan_rejected, 'Canonical financial-target plan accepted cancelling nonzero per-rate taxes.');

		$source_line = $source->get_item($neutral_line_id);
		$source_line->set_taxes(array(
			'subtotal' => array(609 => '0.01', 610 => '-0.01'),
			'total' => array(609 => '0.01', 610 => '-0.01'),
		));
		$source_line->save();
		self::tax($source, 609, '0.01', '0.00');
		self::tax($source, 610, '-0.01', '0.00');
		$source = wc_get_order($source->get_id());
		WCOS_Order_Totals_Rebuilder::assert_consistent($source, 2);
		$target_tax_before = self::target_immutable_snapshot($target, array($target_line->get_id()));
		$domain_contract_rejected = false;
		try {
			WCOS_Merge_Commercial_Policy::expected_target_contract($source, $target, 2);
		} catch (InvalidArgumentException $exception) {
			$domain_contract_rejected = false !== strpos($exception->getMessage(), 'settlement-neutral');
		}
		self::assert($domain_contract_rejected, 'Financial target expected contract accepted cancelling nonzero per-rate taxes.');
		self::expect_reason($source, $target, 'financial_target_nonzero_source_tax', null, true);
		self::assert($target_tax_before === self::target_immutable_snapshot(wc_get_order($target->get_id()), array($target_line->get_id())), 'Rejected cancelling-rate Merge changed target settlement authority.');

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
			'cases' => '13-25,58',
			'per_line_neutrality' => true,
			'per_rate_tax_neutrality' => true,
			'production_service_pre_journal_rejection' => true,
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
		$record['schema_version'] = 2;
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
		$confirmation_record['schema_version'] = 2;
		set_transient($confirmation_key, $confirmation_record, WCOS_Merge_Confirmation_Store::TTL);
		$stale_confirmation_rejected = false;
		try {
			WCOS_Merge_Confirmation_Store::verify($source, wc_get_order($target->get_id()), $confirmation['operation_id'], $confirmation['confirmation_token'], self::$operator_id);
		} catch (WCOS_Merge_Confirmation_Exception $exception) {
			$stale_confirmation_rejected = in_array($exception->get_reason(), array('authority_incomplete', 'authority_changed'), true);
		}
		self::assert($stale_confirmation_rejected, 'A pre-006 Confirmation schema minted current financial authority.');

		$pre_closure_review = WCOS_Merge_Review_Store::create($source, wc_get_order($target->get_id()), $report, self::$operator_id);
		self::$review_ids[] = $pre_closure_review['review_id'];
		$pre_closure_review_key = 'wcos_merge_review_' . hash('sha256', sanitize_key($pre_closure_review['review_id']));
		$pre_closure_review_record = get_transient($pre_closure_review_key);
		$pre_closure_review_record['authority']['plan']['financial_authority']['schema_version'] = 2;
		$pre_closure_review_record['authority']['plan']['financial_authority']['policy_version'] = 2;
		set_transient($pre_closure_review_key, $pre_closure_review_record, WCOS_Merge_Review_Store::TTL);
		$pre_closure_review_rejected = false;
		try {
			WCOS_Merge_Review_Store::verify($source, wc_get_order($target->get_id()), $pre_closure_review['review_id'], $pre_closure_review['review_token'], self::$operator_id);
		} catch (WCOS_Merge_Review_Exception $exception) {
			$pre_closure_review_rejected = in_array($exception->get_reason(), array('review_invalid', 'authority_changed'), true);
		}
		self::assert($pre_closure_review_rejected, 'A pre-closure WOS-COMPAT-006 Review authority minted a schema-v2 refund operation.');

		$pre_closure_confirmation_review = WCOS_Merge_Review_Store::create($source, wc_get_order($target->get_id()), $report, self::$operator_id);
		self::$review_ids[] = $pre_closure_confirmation_review['review_id'];
		$pre_closure_confirmation = WCOS_Merge_Confirmation_Store::create($source, wc_get_order($target->get_id()), $pre_closure_confirmation_review['authority'], self::$operator_id);
		self::$operation_ids[$source->get_id()][] = $pre_closure_confirmation['operation_id'];
		$pre_closure_confirmation_key = 'wcos_merge_confirm_' . hash('sha256', sanitize_key($pre_closure_confirmation['operation_id']));
		$pre_closure_confirmation_record = get_transient($pre_closure_confirmation_key);
		$pre_closure_confirmation_record['plan']['financial_authority']['schema_version'] = 2;
		$pre_closure_confirmation_record['plan']['financial_authority']['policy_version'] = 2;
		set_transient($pre_closure_confirmation_key, $pre_closure_confirmation_record, WCOS_Merge_Confirmation_Store::TTL);
		$pre_closure_confirmation_rejected = false;
		try {
			WCOS_Merge_Confirmation_Store::verify($source, wc_get_order($target->get_id()), $pre_closure_confirmation['operation_id'], $pre_closure_confirmation['confirmation_token'], self::$operator_id);
		} catch (WCOS_Merge_Confirmation_Exception $exception) {
			$pre_closure_confirmation_rejected = in_array($exception->get_reason(), array('authority_incomplete', 'authority_changed'), true);
		}
		self::assert($pre_closure_confirmation_rejected, 'A pre-closure WOS-COMPAT-006 Confirmation authority minted a schema-v2 refund operation.');

		self::$results['drift_and_transient'] = array(
			'cases' => '37-41,60,69-70',
			'drifts' => $drifts,
			'fresh_review_required' => true,
			'pre_006_transient_rejected' => true,
			'cc9_transient_rejected' => true,
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
		$financial_after = WCOS_Merge_Financial_Authority::freeze_pair(wc_get_order($source->get_id()), $target, 2, true)['target'];
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
				&& $target_financial_before === WCOS_Merge_Financial_Authority::freeze_pair($fresh_crash_source, $fresh_crash_target, 2, true)['target']
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
		$legacy_projection_reads = 0;
		$legacy_order_total_filter = static function($value) use (&$legacy_projection_reads) {
			$legacy_projection_reads++;
			return $value;
		};
		$legacy_line_total_filter = static function($value) use (&$legacy_projection_reads) {
			$legacy_projection_reads++;
			return $value;
		};
		$legacy_customer_note_filter = static function($value) use (&$legacy_projection_reads) {
			$legacy_projection_reads++;
			return 'legacy-filtered-customer-note';
		};
		add_filter('woocommerce_order_get_total', $legacy_order_total_filter, 10, 1);
		add_filter('woocommerce_order_item_get_total', $legacy_line_total_filter, 10, 1);
		add_filter('woocommerce_order_get_customer_note', $legacy_customer_note_filter, 10, 1);
		self::assert('legacy-filtered-customer-note' === $source->get_customer_note()
			&& '' === $source->get_customer_note('edit'), 'WOS-COMPAT-005 durable fixture did not establish a changed stable legacy view projection.');
		$source_id = (int) $source->get_id();
		$target_id = (int) $target->get_id();
		$current_report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		$previous_plan = self::previous_commercial_plan($current_report['plan']);
		$context_authority = WCOS_Merge_Context_Signature::previous_disposition($source, $target);
		$authority = array(
			'source_order_id' => $source_id,
			'target_order_id' => $target_id,
			'source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
			'target_signature' => WCOS_Order_Contract_Snapshot::source_signature($target),
			'plan_schema_version' => WCOS_Merge_Plan::PREVIOUS_SCHEMA_VERSION,
			'plan_fingerprint' => WCOS_Merge_Plan::fingerprint($previous_plan),
			'price_precision' => 2,
			'preflight_policy_version' => WCOS_Merge_Preflight::PREVIOUS_POLICY_VERSION,
			'context_signature_version' => WCOS_Merge_Context_Signature::PREVIOUS_SCHEMA_VERSION,
			'context_authority' => $context_authority,
			'context_authority_fingerprint' => WCOS_Merge_Context_Signature::authority_fingerprint($context_authority),
			'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
			'retirement_candidates' => WCOS_Merge_Retirement_Policy::identifiers(),
			'retirement_policy_selected' => true,
			'retirement_policy_identifier' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'archive_source_signature_before' => WCOS_Merge_Recovery_Snapshot::archive_commercial_signature(
				$source,
				WCOS_Merge_Recovery_Snapshot::PREVIOUS_SCHEMA_VERSION
			),
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
				'context_signature_version' => WCOS_Merge_Context_Signature::PREVIOUS_SCHEMA_VERSION,
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
			self::assert(isset($record['context']['merge_recovery_snapshot']['schema_version'])
				&& WCOS_Merge_Recovery_Snapshot::PREVIOUS_SCHEMA_VERSION === (int) $record['context']['merge_recovery_snapshot']['schema_version'], 'WOS-COMPAT-005 durable fixture did not persist a true recovery snapshot v4.');
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
		$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source_id), WCOS_Merge_Recovery_Snapshot::PREVIOUS_SCHEMA_VERSION);
		$target_before = WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target_id), WCOS_Merge_Recovery_Snapshot::PREVIOUS_SCHEMA_VERSION);
		$replayed = $controller->execute_request($request);
		self::assert($result === $replayed
			&& $journal_before === wp_json_encode(WCOS_Operation_Journal::get(wc_get_order($source_id), $operation_id))
			&& $source_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($source_id), WCOS_Merge_Recovery_Snapshot::PREVIOUS_SCHEMA_VERSION)
			&& $target_before === WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($target_id), WCOS_Merge_Recovery_Snapshot::PREVIOUS_SCHEMA_VERSION), 'WOS-COMPAT-005 completed replay was not byte/semantic-idempotent.');
		remove_filter('woocommerce_order_get_total', $legacy_order_total_filter, 10);
		remove_filter('woocommerce_order_item_get_total', $legacy_line_total_filter, 10);
		remove_filter('woocommerce_order_get_customer_note', $legacy_customer_note_filter, 10);
		self::assert($legacy_projection_reads > 0, 'WOS-COMPAT-005 durable replay did not exercise its stable legacy view projection.');

		self::$results['wos_compat_005_durable_replay'] = array(
			'cases' => '43',
			'pair_schema_version' => WCOS_Merge_Journal_Context::PREVIOUS_SCHEMA_VERSION,
			'plan_schema_version' => WCOS_Merge_Plan::PREVIOUS_SCHEMA_VERSION,
			'service_policy_version' => WCOS_Merge_Order_Service::PREVIOUS_POLICY_VERSION,
			'recovery_and_terminal_replay_exact' => true,
			'legacy_projection_filters_stable' => true,
			'legacy_projection_value_changed' => true,
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
		$record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
		$snapshot_schema = isset($record['context']['merge_recovery_snapshot']['schema_version'])
			? (int) $record['context']['merge_recovery_snapshot']['schema_version']
			: WCOS_Merge_Recovery_Snapshot::SCHEMA_VERSION;
		$context = array(
			'merge_recovery_state' => sanitize_key((string) $state),
			'merge_source_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($source, $snapshot_schema),
			'merge_target_signature_after' => WCOS_Merge_Recovery_Snapshot::participant_signature($target, $snapshot_schema),
			'merge_source_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($source, $snapshot_schema),
			'merge_target_state_after' => WCOS_Merge_Recovery_Snapshot::participant_checkpoint($target, $snapshot_schema),
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
		self::assert(WCOS_Merge_Preflight::POLICY_VERSION === 4
			&& WCOS_Merge_Preflight::PREVIOUS_POLICY_VERSION === 2
			&& WCOS_Merge_Plan::SCHEMA_VERSION === 5
			&& WCOS_Merge_Plan::PREVIOUS_SCHEMA_VERSION === 3
			&& WCOS_Merge_Context_Signature::SCHEMA_VERSION === 3
			&& WCOS_Merge_Context_Signature::PREVIOUS_SCHEMA_VERSION === 2
			&& WCOS_Merge_Journal_Context::SCHEMA_VERSION === 6
			&& WCOS_Merge_Journal_Context::PREVIOUS_SCHEMA_VERSION === 4
			&& WCOS_Merge_Order_Service::POLICY_VERSION === 4
			&& WCOS_Merge_Order_Service::PREVIOUS_POLICY_VERSION === 2
			&& WCOS_Merge_Financial_Authority::SCHEMA_VERSION === 3
			&& WCOS_Merge_Financial_Authority::POLICY_VERSION === 3
			&& WCOS_Merge_Recovery_Snapshot::SCHEMA_VERSION === 5
			&& WCOS_Merge_Recovery_Snapshot::PREVIOUS_SCHEMA_VERSION === 4
			&& WCOS_Merge_Review_Store::SCHEMA_VERSION === 3
			&& WCOS_Merge_Confirmation_Store::SCHEMA_VERSION === 3, 'Merge durable version tuple or refund-authority namespace is incomplete.');
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
		$date_paid = $target->get_date_paid('edit');
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
			'status' => (string) $target->get_status('edit'),
			'is_paid' => in_array((string) $target->get_status('edit'), wc_get_is_paid_statuses(), true),
			'date_paid' => null === $date_paid ? null : (int) $date_paid->getTimestamp(),
			'transaction_id' => (string) $target->get_transaction_id('edit'),
			'payment_method' => (string) $target->get_payment_method('edit'),
			'payment_method_title' => (string) $target->get_payment_method_title('edit'),
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
		$date = $order->get_date_created('edit');
		$result = array(
			'id' => (int) $order->get_id(),
			'parent_id' => (int) $order->get_parent_id(),
			'status' => (string) $order->get_status(),
			'currency' => (string) $order->get_currency('edit'),
			'prices_include_tax' => (bool) $order->get_prices_include_tax('edit'),
			'amount' => $order instanceof WC_Order_Refund ? (string) $order->get_amount() : '',
			'total' => (string) $order->get_total('edit'),
			'discount_total' => (string) $order->get_discount_total('edit'),
			'discount_tax' => (string) $order->get_discount_tax('edit'),
			'shipping_total' => (string) $order->get_shipping_total('edit'),
			'shipping_tax' => (string) $order->get_shipping_tax('edit'),
			'cart_tax' => (string) $order->get_cart_tax('edit'),
			'total_tax' => (string) $order->get_total_tax('edit'),
			'reason' => $order instanceof WC_Order_Refund ? (string) $order->get_reason() : '',
			'refunded_payment' => $order instanceof WC_Order_Refund ? (bool) $order->get_refunded_payment('edit') : false,
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

	private static function expect_reason(WC_Order $source_view, WC_Order $target, $reason, ?WC_Order $persisted_source = null, $exercise_service = false) {
		$persisted_source = $persisted_source instanceof WC_Order ? $persisted_source : $source_view;
		$source_id = $persisted_source->get_id();
		$target_id = $target->get_id();
		$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($persisted_source);
		$target_before = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
		$journal_count = self::journal_option_count();
		$report = WCOS_Merge_Preflight::report($source_view, $target, 2);
		self::assert($reason === $report['reason'], 'Unexpected Merge preflight reason: ' . $report['reason'] . ', expected ' . $reason);
		if ($exercise_service) {
			$operation_id = 'compat-006-rejected-' . wp_generate_uuid4();
			$service_reason = '';
			try {
				(new WCOS_Mutation_Gateway())->merge($persisted_source, $target, $operation_id, 2);
			} catch (WCOS_Merge_Preflight_Exception $exception) {
				$service_reason = $exception->get_reason();
			}
			self::assert($reason === $service_reason, 'Production Merge service did not reject before journal with the expected reason.');
			self::assert(null === WCOS_Operation_Journal::get(wc_get_order($source_id), $operation_id), 'Rejected production Merge service created a journal.');
		}
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
		foreach (array_reverse(array_values(array_unique(self::$tax_rate_ids))) as $tax_rate_id) {
			WC_Tax::_delete_tax_rate($tax_rate_id);
		}
		$zone = new WC_Shipping_Zone(0);
		foreach (array_reverse(array_values(array_unique(self::$shipping_method_ids))) as $instance_id) {
			$zone->delete_shipping_method($instance_id);
			delete_option('woocommerce_flat_rate_' . $instance_id . '_settings');
		}
	}

	private static function assert($condition, $message) {
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}
}

WCOS_Compat_Merge_Financial_Matrix::run();
