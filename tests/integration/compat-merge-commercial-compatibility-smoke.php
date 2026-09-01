<?php

if (!defined('ABSPATH')) {
	exit(1);
}

/** WOS-COMPAT-005 ordinary unpaid/non-refund Merge compatibility matrix. */
final class WCOS_Compat_Merge_Commercial_Matrix {
	private static $order_ids = array();
	private static $product_ids = array();
	private static $review_ids = array();
	private static $operation_ids = array();
	private static $old_statuses = array();
	private static $tax_class_slug = 'wos-compat-reduced';
	private static $tax_class_created = false;
	private static $operator_id = 0;
	private static $results = array();

	public static function run() {
		$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
		self::assert(!empty($admins), 'WOS-COMPAT-005 requires an administrator fixture.');
		self::$operator_id = absint($admins[0]);
		wp_set_current_user(self::$operator_id);
		self::$old_statuses = (array) get_option('order_splitter_status_allowed', array('wc-processing'));
		update_option('order_splitter_status_allowed', array('wc-pending', 'wc-on-hold'));
		if (!in_array(self::$tax_class_slug, WC_Tax::get_tax_class_slugs(), true)) {
			$created = WC_Tax::create_tax_class('WOS Compat Reduced', self::$tax_class_slug);
			self::assert(!is_wp_error($created), 'Tax-class fixture could not be created.');
			self::$tax_class_created = true;
		}

		try {
			self::status_context_charge_coalesce();
			self::fresh_line_and_identity_matrix();
			self::tax_template_isolation();
			self::fully_discounted_tax_and_pii_free_authority();
			self::stock_marker_matrix();
			self::paid_refund_boundary();
			self::review_confirm_drift_and_transient_versions();
			self::coalesced_recovery_and_response_loss();
			self::legacy_v1_durable_replay();
			self::non_regression_authority();
			echo wp_json_encode(array('status' => 'pass', 'task' => 'WOS-COMPAT-005', 'results' => self::$results), JSON_PRETTY_PRINT) . "\n";
		} finally {
			self::cleanup();
			update_option('order_splitter_status_allowed', self::$old_statuses);
			if (self::$tax_class_created) {
				WC_Tax::delete_tax_class_by('slug', self::$tax_class_slug);
			}
			wp_set_current_user(self::$operator_id);
		}
	}

	private static function product($label, $managed = true) {
		$product = new WC_Product_Simple();
		$product->set_name('WOS COMPAT 005 ' . $label);
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
		return $product;
	}

	private static function variation_products($label) {
		$parent = new WC_Product_Variable();
		$parent->set_name('WOS COMPAT 005 ' . $label);
		$parent->set_status('publish');
		$parent_id = (int) $parent->save();
		self::assert($parent_id > 0, 'Variable product fixture could not be saved.');
		self::$product_ids[] = $parent_id;
		$variation_ids = array();
		for ($index = 0; $index < 2; $index++) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id($parent_id);
			$variation->set_status('publish');
			$variation->set_regular_price((string) (10 + $index));
			$variation->set_price((string) (10 + $index));
			$variation_id = (int) $variation->save();
			self::assert($variation_id > 0, 'Variation fixture could not be saved.');
			self::$product_ids[] = $variation_id;
			$variation_ids[] = $variation_id;
		}
		return array(wc_get_product($parent_id), $variation_ids);
	}

	private static function order($label, $status = 'pending', array $context = array()) {
		$order = wc_create_order(array('status' => 'pending'));
		self::assert($order instanceof WC_Order, 'Order fixture could not be created: ' . $label);
		self::$order_ids[] = $order->get_id();
		$order->set_currency(isset($context['currency']) ? $context['currency'] : 'USD');
		$order->set_prices_include_tax(isset($context['prices_include_tax']) ? (bool) $context['prices_include_tax'] : false);
		$order->set_customer_id(isset($context['customer_id']) ? absint($context['customer_id']) : 0);
		$order->set_billing_first_name(isset($context['first_name']) ? $context['first_name'] : 'Merge');
		$order->set_billing_last_name(isset($context['last_name']) ? $context['last_name'] : 'Compatibility');
		$order->set_billing_email(isset($context['email']) ? $context['email'] : 'merge-' . sanitize_key($label) . '@example.test');
		$order->set_billing_address_1(isset($context['billing_address']) ? $context['billing_address'] : '1 Source Street');
		$order->set_billing_city(isset($context['billing_city']) ? $context['billing_city'] : 'Source City');
		$order->set_billing_country('US');
		$order->set_shipping_first_name(isset($context['shipping_first_name']) ? $context['shipping_first_name'] : 'Shipping');
		$order->set_shipping_last_name(isset($context['shipping_last_name']) ? $context['shipping_last_name'] : 'Context');
		$order->set_shipping_address_1(isset($context['shipping_address']) ? $context['shipping_address'] : '2 Shipping Street');
		$order->set_shipping_city(isset($context['shipping_city']) ? $context['shipping_city'] : 'Shipping City');
		$order->set_shipping_country('US');
		$order->set_payment_method(isset($context['payment_method']) ? $context['payment_method'] : 'cod');
		$order->set_payment_method_title(isset($context['payment_title']) ? $context['payment_title'] : 'Cash on delivery');
		$order->set_status($status);
		$order->save();
		return wc_get_order($order->get_id());
	}

	private static function line(WC_Order $order, WC_Product $product, array $values = array()) {
		$item = new WC_Order_Item_Product();
		$item->set_name(isset($values['name']) ? $values['name'] : 'Exact historical configured line');
		$item->set_product_id($product->get_id());
		$item->set_variation_id(isset($values['variation_id']) ? absint($values['variation_id']) : 0);
		$item->set_tax_class(isset($values['tax_class']) ? $values['tax_class'] : '');
		$item->set_quantity(isset($values['quantity']) ? $values['quantity'] : '1.000000');
		$item->set_subtotal(isset($values['subtotal']) ? $values['subtotal'] : '10.00');
		$item->set_total(isset($values['total']) ? $values['total'] : '10.00');
		$item->set_subtotal_tax(isset($values['subtotal_tax']) ? $values['subtotal_tax'] : '0.00');
		$item->set_total_tax(isset($values['total_tax']) ? $values['total_tax'] : '0.00');
		$item->set_taxes(isset($values['taxes']) ? $values['taxes'] : array('subtotal' => array(), 'total' => array()));
		foreach (isset($values['meta']) ? (array) $values['meta'] : array('Configuration' => 'exact') as $key => $value) {
			$item->add_meta_data((string) $key, $value, true);
		}
		if (array_key_exists('reduced_stock', $values) && null !== $values['reduced_stock']) {
			$item->add_meta_data('_reduced_stock', $values['reduced_stock'], true);
		}
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function shipping(WC_Order $order, $label, $total, array $taxes = array(), array $meta = array()) {
		$item = new WC_Order_Item_Shipping();
		$item->set_method_title($label);
		$item->set_method_id('flat_rate');
		$item->set_instance_id(7);
		$item->set_total($total);
		$item->set_taxes(array('total' => $taxes));
		foreach ($meta as $key => $value) {
			$item->add_meta_data((string) $key, $value, true);
		}
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function fee(WC_Order $order, $label, $total, array $taxes = array()) {
		$item = new WC_Order_Item_Fee();
		$item->set_name($label);
		$item->set_amount($total);
		$item->set_total($total);
		$item->set_total_tax(self::sum_decimals($taxes, 2));
		$item->set_taxes(array('total' => $taxes));
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function coupon(WC_Order $order, $code, $discount, $discount_tax = '0.00') {
		$item = new WC_Order_Item_Coupon();
		$item->set_code($code);
		$item->set_discount($discount);
		$item->set_discount_tax($discount_tax);
		$order->add_item($item);
		$order->save();
		return $order->get_item($item->get_id());
	}

	private static function tax(WC_Order $order, $rate_id, $cart, $shipping, $label = '') {
		$item = new WC_Order_Item_Tax();
		$item->set_rate_id((int) $rate_id);
		$item->set_label('' === $label ? 'Historical rate ' . (int) $rate_id : $label);
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

	private static function context_snapshot(WC_Order $order) {
		return array(
			'customer_id' => (int) $order->get_customer_id(),
			'billing' => $order->get_address('billing'),
			'shipping' => $order->get_address('shipping'),
			'payment_method' => (string) $order->get_payment_method(),
			'payment_method_title' => (string) $order->get_payment_method_title(),
		);
	}

	private static function item_snapshot(WC_Order_Item $item) {
		$data = $item->get_data();
		unset($data['id'], $data['order_id'], $data['meta_data']);
		$meta = array();
		foreach ($item->get_meta_data() as $datum) {
			$meta[] = array('key' => (string) $datum->key, 'value' => $datum->value);
		}
		return array('data' => $data, 'meta' => $meta);
	}

	private static function items_snapshot(WC_Order $order, $type) {
		$result = array();
		foreach ($order->get_items($type) as $item_id => $item) {
			$result[(int) $item_id] = self::item_snapshot($item);
		}
		ksort($result, SORT_NUMERIC);
		return $result;
	}

	private static function tax_totals(WC_Order $order) {
		$result = array();
		foreach ($order->get_items('tax') as $item) {
			$result[(int) $item->get_rate_id()] = array(
				WCOS_Decimal::to_units($item->get_tax_total(), 2),
				WCOS_Decimal::to_units($item->get_shipping_tax_total(), 2),
			);
		}
		ksort($result, SORT_NUMERIC);
		return $result;
	}

	private static function normalized_taxes(array $taxes) {
		$result = array('subtotal' => array(), 'total' => array());
		foreach (array('subtotal', 'total') as $bucket) {
			foreach (isset($taxes[$bucket]) ? (array) $taxes[$bucket] : array() as $rate_id => $amount) {
				$result[$bucket][(int) $rate_id] = WCOS_Decimal::to_units($amount, 2);
			}
			ksort($result[$bucket], SORT_NUMERIC);
		}
		return $result;
	}

	private static function status_context_charge_coalesce() {
		$product = self::product('coalesced-commercial');
		$source = self::order('coalesce-source', 'pending', array(
			'customer_id' => 701,
			'email' => 'source-context@example.test',
			'billing_address' => '11 Source Billing Road',
			'shipping_address' => '12 Source Shipping Road',
			'payment_method' => 'bacs',
			'payment_title' => 'Historical bank transfer',
		));
		$target = self::order('coalesce-target', 'on-hold', array(
			'customer_id' => 0,
			'email' => 'target-context@example.test',
			'billing_address' => '21 Target Billing Road',
			'shipping_address' => '22 Target Shipping Road',
			'payment_method' => 'cod',
			'payment_title' => 'Target cash payment',
		));

		$source_line = self::line($source, $product, array(
			'quantity' => '1.000000', 'subtotal' => '12.35', 'total' => '10.11',
			'subtotal_tax' => '1.23', 'total_tax' => '1.01',
			'taxes' => array('subtotal' => array(991 => '1.23'), 'total' => array(991 => '1.01')),
			'reduced_stock' => '0.750000', 'meta' => array('Configuration' => array('finish' => 'matte')),
		));
		$target_line = self::line($target, $product, array(
			'quantity' => '2.000000', 'subtotal' => '25.00', 'total' => '20.00',
			'subtotal_tax' => '2.50', 'total_tax' => '2.00',
			'taxes' => array('subtotal' => array(991 => '2.50'), 'total' => array(991 => '2.00')),
			'reduced_stock' => '1.250000', 'meta' => array('Configuration' => array('finish' => 'matte')),
		));

		self::shipping($source, 'Source taxed shipping A', '3.00', array(992 => '0.50'), array('Carrier reference' => 'source-only-a'));
		self::shipping($source, 'Source shipping B', '1.00', array(), array('Carrier reference' => 'source-only-b'));
		self::fee($source, 'Source positive fee', '1.50', array(993 => '0.30'));
		self::coupon($source, 'source-history', '2.24', '0.22');
		self::tax($source, 991, '1.01', '0.00');
		self::tax($source, 992, '0.00', '0.50');
		self::tax($source, 993, '0.30', '0.00');

		self::shipping($target, 'Target taxed shipping', '5.00', array(994 => '0.40'), array('Carrier reference' => 'target-only'));
		self::fee($target, 'Target negative fee', '-0.50', array(995 => '-0.10'));
		self::coupon($target, 'target-history', '5.00', '0.50');
		self::tax($target, 991, '2.00', '0.00');
		self::tax($target, 994, '0.00', '0.40');
		self::tax($target, 995, '-0.10', '0.00');

		$source = self::finalize($source);
		$target = self::finalize($target);
		$source->get_data_store()->set_stock_reduced($source->get_id(), true);
		$target->get_data_store()->set_stock_reduced($target->get_id(), true);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());

		$source_context_before = self::context_snapshot($source);
		$target_context_before = self::context_snapshot($target);
		$source_charges_before = array(
			'shipping' => self::items_snapshot($source, 'shipping'),
			'fee' => self::items_snapshot($source, 'fee'),
			'coupon' => self::items_snapshot($source, 'coupon'),
		);
		$target_charges_before = array(
			'shipping' => self::items_snapshot($target, 'shipping'),
			'fee' => self::items_snapshot($target, 'fee'),
			'coupon' => self::items_snapshot($target, 'coupon'),
		);
		$source_archive_before = WCOS_Merge_Recovery_Snapshot::archive_commercial_signature($source);
		$target_expected = WCOS_Merge_Commercial_Policy::expected_target_contract($source, $target, 2);
		$physical_before = (string) wc_get_product($product->get_id())->get_stock_quantity();
		$target_line_ids_before = array_map('absint', array_keys($target->get_items('line_item')));

		$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		$planned = array_values($report['plan']['lines']);
		self::assert(WCOS_Merge_Preflight::POLICY_VERSION === (int) $report['policy']['policy_version'], 'Current Merge policy version was not disclosed.');
		self::assert(1 === count($planned) && 'coalesce' === $planned[0]['action'] && $target_line->get_id() === (int) $planned[0]['target_item_id'], 'Exact canonical line did not freeze one exact coalescing destination.');
		self::assert('keep_target_context' === $report['context_authority']['disposition'], 'Review did not freeze target-context-wins authority.');

		list($review, $confirmation, $result) = self::execute_controller($source, $target);
		self::assert(1 === (int) $review['summary']['coalesced_line_count'] && 0 === (int) $review['summary']['fresh_line_count'], 'Review summary did not disclose coalescing.');
		self::assert(!empty($review['summary']['source_shipping_retained']) && !empty($review['summary']['source_fees_retained']) && !empty($review['summary']['source_coupons_retained']), 'Review summary did not disclose retained source history.');
		self::assert('keep_target_context' === $review['summary']['target_context_disposition'] && 'keep_target' === $review['summary']['target_status_disposition'], 'Review summary did not disclose target authority.');
		self::assert('completed' === $result['status'], 'Ordinary commercial coalescing did not complete.');

		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		self::assert('trash' === $source->get_status() && 'on-hold' === $target->get_status(), 'Target status or source non-force retirement changed.');
		self::assert($source_context_before === self::context_snapshot($source), 'Archived source context changed.');
		self::assert($target_context_before === self::context_snapshot($target), 'Target context changed.');
		self::assert($source_charges_before === array('shipping' => self::items_snapshot($source, 'shipping'), 'fee' => self::items_snapshot($source, 'fee'), 'coupon' => self::items_snapshot($source, 'coupon')), 'Archived source shipping/fee/coupon history changed.');
		self::assert($target_charges_before === array('shipping' => self::items_snapshot($target, 'shipping'), 'fee' => self::items_snapshot($target, 'fee'), 'coupon' => self::items_snapshot($target, 'coupon')), 'Target shipping/fee/coupon history changed.');
		self::assert($source_archive_before === WCOS_Merge_Recovery_Snapshot::archive_commercial_signature($source), 'Source archive commercial signature changed.');
		self::assert($target_line_ids_before === array_map('absint', array_keys($target->get_items('line_item'))), 'Coalescing created or removed a target product line.');

		$coalesced = $target->get_item($target_line->get_id());
		self::assert($coalesced instanceof WC_Order_Item_Product, 'Coalesced target line disappeared.');
		$coalesced_quantity = WCOS_Decimal::normalize($coalesced->get_quantity(), 6);
		self::assert('3.000000' === $coalesced_quantity, 'Coalesced quantity is not exact additive history: ' . $coalesced_quantity);
		self::assert('37.35' === (string) $coalesced->get_subtotal() && '30.11' === (string) $coalesced->get_total(), 'Coalesced historical money is not exact additive history.');
		self::assert('3.73' === (string) $coalesced->get_subtotal_tax() && '3.01' === (string) $coalesced->get_total_tax(), 'Coalesced historical tax totals are not exact additive history.');
		self::assert(array('subtotal' => array(991 => 373), 'total' => array(991 => 301)) === self::normalized_taxes($coalesced->get_taxes()), 'Coalesced per-rate tax arrays are not exact additive history.');
		self::assert('2.000000' === WCOS_Decimal::normalize($coalesced->get_meta('_reduced_stock', true), 6), 'Coalesced reduced-stock ownership is not exact additive history.');
		$source_line_after = $source->get_item($source_line->get_id());
		self::assert($source_line_after instanceof WC_Order_Item_Product && $source->get_id() === $source_line_after->get_order_id(), 'Persisted source line was re-parented.');
		self::assert('' === (string) $source_line_after->get_meta('_reduced_stock', true), 'Archived source retained active reduced-stock ownership.');
		self::assert(false === (bool) $source->get_data_store()->get_stock_reduced($source->get_id()) && true === (bool) $target->get_data_store()->get_stock_reduced($target->get_id()), 'Order-level stock ownership flags did not consolidate.');
		self::assert($physical_before === (string) wc_get_product($product->get_id())->get_stock_quantity(), 'Merge changed physical product inventory.');
		self::assert($target_expected === WCOS_Order_Contract_Snapshot::aggregate(array($target), 2), 'Resulting target economy did not equal target plus source product history only.');

		$tax_totals = self::tax_totals($target);
		self::assert(isset($tax_totals[991], $tax_totals[994], $tax_totals[995]), 'Target product/fee/shipping tax rows are incomplete.');
		self::assert(array(301, 0) === $tax_totals[991], 'Target product tax row was not exact.');
		self::assert(array(0, 40) === $tax_totals[994], 'Target shipping tax row changed.');
		self::assert(array(-10, 0) === $tax_totals[995], 'Target fee tax row changed.');
		self::assert(!isset($tax_totals[992]) && !isset($tax_totals[993]), 'Source-only shipping/fee tax authority leaked into target.');
		$journal = WCOS_Operation_Journal::get($source, $confirmation['operation_id']);
		$pair = WCOS_Merge_Journal_Context::assert_executable_policy($journal);
		self::assert(WCOS_Merge_Preflight::POLICY_VERSION === (int) $pair['preflight_policy_version'] && WCOS_Merge_Plan::SCHEMA_VERSION === (int) $pair['plan_schema_version'], 'Durable journal did not bind the new version tuple.');

		self::$results['status_context_charge_coalesce'] = array(
			'cases' => '1,8-13,17-29,31,37-43,48-54,64,78',
			'target_status' => $target->get_status(),
			'coalesced_target_item_id' => $coalesced->get_id(),
			'source_archived' => true,
			'target_context_preserved' => true,
			'source_history_retained' => true,
			'physical_stock_neutral' => true,
		);
	}

	private static function fresh_line_and_identity_matrix() {
		$product = self::product('identity-matrix', false);
		$actions = array();

		$pair = self::identity_pair($product, 'exact', array(), array(array()));
		$actions['exact'] = self::single_action($pair[0], $pair[1]);

		list($variation_parent, $variation_ids) = self::variation_products('identity-variations');
		$pair = self::identity_pair($variation_parent, 'variation', array('variation_id' => $variation_ids[0]), array(array('variation_id' => $variation_ids[1])));
		$actions['variation'] = self::single_action($pair[0], $pair[1]);

		$pair = self::identity_pair($product, 'tax-class', array('tax_class' => self::$tax_class_slug), array(array('tax_class' => '')));
		$actions['tax_class'] = self::single_action($pair[0], $pair[1]);

		$pair = self::identity_pair($product, 'metadata', array('meta' => array('Configuration' => 'source')), array(array('meta' => array('Configuration' => 'target'))));
		$actions['metadata'] = self::single_action($pair[0], $pair[1]);

		$pair = self::identity_pair(
			$product,
			'tax-structure',
			array('taxes' => array('subtotal' => array(801 => '0.00'), 'total' => array(801 => '0.00'))),
			array(array('taxes' => array('subtotal' => array(802 => '0.00'), 'total' => array(802 => '0.00'))))
		);
		$actions['tax_structure'] = self::single_action($pair[0], $pair[1]);

		$pair = self::identity_pair($product, 'ambiguous', array(), array(array(), array()));
		$ambiguous_report = WCOS_Merge_Preflight::assert_supported($pair[0], $pair[1], 2);
		$ambiguous_line = reset($ambiguous_report['plan']['lines']);
		$actions['multiple_candidates'] = $ambiguous_line['action'];

		$pair = self::identity_pair($product, 'no-target-match', array('meta' => array('Configuration' => 'source-only')), array(array('meta' => array('Configuration' => 'target-only'))));
		$actions['no_match'] = self::single_action($pair[0], $pair[1]);

		self::assert('coalesce' === $actions['exact'], 'One exact canonical target did not coalesce.');
		foreach (array('variation', 'tax_class', 'metadata', 'tax_structure', 'multiple_candidates', 'no_match') as $case) {
			self::assert('fresh_target_line' === $actions[$case], 'Commercially different or ambiguous line was guessed: ' . $case);
		}

		$sequential_source = self::order('sequential-source');
		$sequential_target = self::order('sequential-target');
		self::line($sequential_source, $product);
		self::line($sequential_source, $product);
		$sequential_target_item = self::line($sequential_target, $product);
		$sequential_source = self::finalize($sequential_source);
		$sequential_target = self::finalize($sequential_target);
		$sequential = WCOS_Merge_Preflight::assert_supported($sequential_source, $sequential_target, 2)['plan'];
		$sequential_lines = array_values($sequential['lines']);
		self::assert(2 === count($sequential_lines)
			&& 'coalesce' === $sequential_lines[0]['action']
			&& 'coalesce' === $sequential_lines[1]['action']
			&& $sequential_target_item->get_id() === (int) $sequential_lines[0]['target_item_id']
			&& $sequential_lines[0]['target_after'] === $sequential_lines[1]['target_before'], 'Sequential duplicate source lines lost cumulative coalescing authority.');
		$policy_tamper = $sequential;
		$policy_tamper['commercial_policy']['physical_stock_disposition'] = 'mutate';
		$policy_tamper_rejected = false;
		try {
			WCOS_Merge_Plan::canonicalize_current($policy_tamper);
		} catch (InvalidArgumentException $exception) {
			$policy_tamper_rejected = true;
		}
		self::assert($policy_tamper_rejected, 'A mutated commercial-policy disposition remained executable authority.');
		$delta_tamper = $sequential;
		$delta_line_ids = array_keys($delta_tamper['lines']);
		$delta_tamper['lines'][$delta_line_ids[0]]['target_after']['quantity'] = '999.000000';
		$delta_tamper_rejected = false;
		try {
			WCOS_Merge_Plan::canonicalize_current($delta_tamper);
		} catch (InvalidArgumentException $exception) {
			$delta_tamper_rejected = true;
		}
		self::assert($delta_tamper_rejected, 'A mutated coalesced target-after delta remained executable authority.');

		$reverse_source = self::order('reverse-status-source', 'on-hold');
		$reverse_target = self::order('reverse-status-target', 'pending');
		self::line($reverse_source, $product, array('meta' => array('Configuration' => 'reverse-source')));
		self::line($reverse_target, $product, array('meta' => array('Configuration' => 'reverse-target')));
		$reverse_source = self::finalize($reverse_source);
		$reverse_target = self::finalize($reverse_target);
		self::assert('supported' === WCOS_Merge_Preflight::report($reverse_source, $reverse_target, 2)['reason'], 'Safe reverse status mismatch was rejected.');
		$matching_source = self::order('matching-status-source', 'pending');
		$matching_target = self::order('matching-status-target', 'pending');
		self::line($matching_source, $product);
		self::line($matching_target, $product);
		$matching_source = self::finalize($matching_source);
		$matching_target = self::finalize($matching_target);
		self::assert('supported' === WCOS_Merge_Preflight::report($matching_source, $matching_target, 2)['reason'], 'Matching safe status pair regressed.');

		foreach (array('cancelled', 'failed', 'refunded', 'checkout-draft', 'trash') as $status) {
			$unsafe_source = self::order('unsafe-source-' . $status, 'pending');
			$unsafe_target = self::order('unsafe-target-' . $status, 'pending');
			self::line($unsafe_source, $product, array('meta' => array('Configuration' => 'unsafe-source-' . $status)));
			self::line($unsafe_target, $product, array('meta' => array('Configuration' => 'unsafe-target-' . $status)));
			$unsafe_source = self::finalize($unsafe_source);
			$unsafe_target = self::finalize($unsafe_target);
			$unsafe_source->set_status($status);
			$unsafe_source->save();
			self::expect_reason(wc_get_order($unsafe_source->get_id()), $unsafe_target, 'incompatible_status');
		}

		remove_filter('woocommerce_stock_amount', 'intval');
		add_filter('woocommerce_stock_amount', 'floatval');
		try {
			$fractional_source = self::order('fractional-source', 'pending', array('email' => 'fractional-source@example.test'));
			$fractional_target = self::order('fractional-target', 'on-hold', array('email' => 'fractional-target@example.test'));
			self::line($fractional_source, $product, array('quantity' => '1.250000', 'subtotal' => '12.35', 'total' => '12.34'));
			$fractional_target_line = self::line($fractional_target, $product, array('quantity' => '2.500000', 'subtotal' => '25.00', 'total' => '24.99'));
			$fractional_source = self::finalize($fractional_source);
			$fractional_target = self::finalize($fractional_target);
			$fractional_operation = 'compat-005-fractional-' . wp_generate_uuid4();
			self::$operation_ids[$fractional_source->get_id()][] = $fractional_operation;
			$fractional_result = (new WCOS_Mutation_Gateway())->merge($fractional_source, $fractional_target, $fractional_operation, 2);
			self::assert('completed' === $fractional_result['status'], 'Fractional coalescing did not complete.');
			$fractional_target = wc_get_order($fractional_target->get_id());
			$fractional_line = $fractional_target->get_item($fractional_target_line->get_id());
			self::assert('3.750000' === WCOS_Decimal::normalize($fractional_line->get_quantity(), 6), 'Fractional coalesced quantity lost exact precision.');
			self::assert('37.35' === (string) $fractional_line->get_subtotal() && '37.33' === (string) $fractional_line->get_total(), 'Fractional historical money lost exact precision.');
		} finally {
			remove_filter('woocommerce_stock_amount', 'floatval');
			add_filter('woocommerce_stock_amount', 'intval');
		}

		self::$results['fresh_line_identity'] = array(
			'cases' => '2-5,30-40',
			'actions' => $actions,
			'sequential_coalescing' => true,
			'policy_and_delta_tamper_fail_closed' => true,
			'fractional_exact' => true,
			'unsafe_statuses_fail_closed' => true,
		);
	}

	private static function identity_pair(WC_Product $product, $label, array $source_values, array $target_values) {
		$source = self::order('identity-' . $label . '-source');
		$target = self::order('identity-' . $label . '-target');
		self::line($source, $product, $source_values);
		foreach (self::line_tax_rate_ids($source_values) as $rate_id) {
			self::tax($source, $rate_id, '0.00', '0.00');
		}
		foreach ($target_values as $values) {
			self::line($target, $product, $values);
			foreach (self::line_tax_rate_ids($values) as $rate_id) {
				if (!isset(self::tax_totals($target)[$rate_id])) {
					self::tax($target, $rate_id, '0.00', '0.00');
				}
			}
		}
		return array(self::finalize($source), self::finalize($target));
	}

	private static function line_tax_rate_ids(array $values) {
		$taxes = isset($values['taxes']) && is_array($values['taxes']) ? $values['taxes'] : array();
		$ids = array();
		foreach (array('subtotal', 'total') as $bucket) {
			$ids = array_merge($ids, array_map('intval', array_keys(isset($taxes[$bucket]) ? (array) $taxes[$bucket] : array())));
		}
		$ids = array_values(array_unique($ids));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private static function single_action(WC_Order $source, WC_Order $target) {
		$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		$line = reset($report['plan']['lines']);
		return sanitize_key((string) $line['action']);
	}

	private static function tax_template_isolation() {
		$product = self::product('fresh-tax-template');
		$source = self::order('fresh-tax-source', 'pending', array('email' => 'fresh-tax-source@example.test'));
		$target = self::order('fresh-tax-target', 'on-hold', array('email' => 'fresh-tax-target@example.test'));
		$source_line = self::line($source, $product, array(
			'name' => 'Fresh historical taxed line',
			'quantity' => '2', 'subtotal' => '19.99', 'total' => '18.88', 'subtotal_tax' => '1.99', 'total_tax' => '1.88',
			'taxes' => array('subtotal' => array(996 => '1.99'), 'total' => array(996 => '1.88')),
			'reduced_stock' => '1.000000', 'meta' => array('Configuration' => 'source-fresh'),
		));
		self::line($target, $product, array('name' => 'Different target line', 'meta' => array('Configuration' => 'target-different')));
		self::shipping($source, 'Source isolated shipping tax', '2.00', array(997 => '0.25'));
		self::fee($source, 'Source isolated fee tax', '1.00', array(998 => '0.20'));
		self::tax($source, 996, '1.88', '0.00', 'Source product template');
		self::tax($source, 997, '0.00', '0.25', 'Source shipping only');
		self::tax($source, 998, '0.20', '0.00', 'Source fee only');
		self::shipping($target, 'Target retained shipping tax', '4.00', array(999 => '0.40'));
		self::fee($target, 'Target retained fee tax', '0.50', array(1000 => '0.10'));
		self::tax($target, 999, '0.00', '0.40', 'Target shipping');
		self::tax($target, 1000, '0.10', '0.00', 'Target fee');
		$source = self::finalize($source);
		$target = self::finalize($target);
		$source->get_data_store()->set_stock_reduced($source->get_id(), true);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());

		$source_charge_before = array(self::items_snapshot($source, 'shipping'), self::items_snapshot($source, 'fee'));
		$target_charge_before = array(self::items_snapshot($target, 'shipping'), self::items_snapshot($target, 'fee'));
		$target_ids_before = array_map('absint', array_keys($target->get_items('line_item')));
		$physical_before = (string) wc_get_product($product->get_id())->get_stock_quantity();
		$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		$line = reset($report['plan']['lines']);
		self::assert('fresh_target_line' === $line['action'] && array(996) === $report['plan']['tax_template_rate_ids'], 'Fresh-line product tax template authority was not isolated.');

		$fresh_operation = 'compat-005-fresh-tax-' . wp_generate_uuid4();
		self::$operation_ids[$source->get_id()][] = $fresh_operation;
		$result = (new WCOS_Merge_Order_Service())->merge($source, $target, $fresh_operation, 2);
		self::assert('completed' === $result['status'], 'Fresh-line tax-template Merge did not complete.');
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$new_ids = array_values(array_diff(array_map('absint', array_keys($target->get_items('line_item'))), $target_ids_before));
		self::assert(1 === count($new_ids) && !in_array($source_line->get_id(), $new_ids, true), 'Fresh Merge did not create exactly one new target item object.');
		$fresh_line = $target->get_item($new_ids[0]);
		self::assert('19.99' === (string) $fresh_line->get_subtotal() && '18.88' === (string) $fresh_line->get_total(), 'Fresh line lost historical money.');
		self::assert(array('subtotal' => array(996 => 199), 'total' => array(996 => 188)) === self::normalized_taxes($fresh_line->get_taxes()), 'Fresh line lost historical per-rate taxes.');
		self::assert('1.000000' === WCOS_Decimal::normalize($fresh_line->get_meta('_reduced_stock', true), 6), 'Fresh line did not receive source reduced-stock ownership.');
		self::assert($source_charge_before === array(self::items_snapshot($source, 'shipping'), self::items_snapshot($source, 'fee')), 'Fresh path changed archived source charges.');
		self::assert($target_charge_before === array(self::items_snapshot($target, 'shipping'), self::items_snapshot($target, 'fee')), 'Fresh path changed target charges.');
		$taxes = self::tax_totals($target);
		self::assert(array(188, 0) === $taxes[996] && array(0, 40) === $taxes[999] && array(10, 0) === $taxes[1000], 'Fresh target tax rows are not exact.');
		self::assert(!isset($taxes[997]) && !isset($taxes[998]), 'Source shipping/fee-only rate was imported into fresh target.');
		self::assert($physical_before === (string) wc_get_product($product->get_id())->get_stock_quantity(), 'Fresh-line Merge changed physical stock.');

		$duplicate_source = self::order('duplicate-template-source');
		$duplicate_target = self::order('duplicate-template-target');
		self::line($duplicate_source, $product, array(
			'meta' => array('Configuration' => 'duplicate-template-source'),
			'subtotal_tax' => '0.40', 'total_tax' => '0.40',
			'taxes' => array('subtotal' => array(1001 => '0.40'), 'total' => array(1001 => '0.40')),
		));
		self::line($duplicate_target, $product, array('meta' => array('Configuration' => 'duplicate-template-target')));
		self::tax($duplicate_source, 1001, '0.20', '0.00', 'Conflicting template A');
		self::tax($duplicate_source, 1001, '0.20', '0.00', 'Conflicting template B');
		$duplicate_source = self::finalize($duplicate_source);
		$duplicate_target = self::finalize($duplicate_target);
		self::expect_reason($duplicate_source, $duplicate_target, 'incompatible_pair_context');

		self::$results['tax_template_isolation'] = array(
			'cases' => '43-48,49,52-53',
			'fresh_target_item_id' => $fresh_line->get_id(),
			'product_template_imported' => true,
			'source_charge_templates_excluded' => true,
			'duplicate_templates_fail_closed' => true,
		);
	}

	private static function fully_discounted_tax_and_pii_free_authority() {
		$product = self::product('fully-discounted-private-authority');
		$private_name = 'Personalized order for Private Customer 005';
		$source = self::order('fully-discounted-private-source');
		$target = self::order('fully-discounted-private-target', 'on-hold');
		self::line($source, $product, array(
			'name' => $private_name,
			'quantity' => '1.000000',
			'subtotal' => '10.00',
			'total' => '0.00',
			'subtotal_tax' => '1.00',
			'total_tax' => '0.00',
			'taxes' => array('subtotal' => array(1002 => '1.00'), 'total' => array(1002 => '0.00')),
			'meta' => array('Configuration' => 'private-source'),
		));
		self::line($target, $product, array(
			'name' => 'Different retained target line',
			'meta' => array('Configuration' => 'private-target'),
		));
		self::coupon($source, 'fully-discounted-history', '10.00', '1.00');
		self::tax($source, 1002, '0.00', '0.00', 'Fully discounted historical rate');
		$source = self::finalize($source);
		$target = self::finalize($target);

		$report = WCOS_Merge_Preflight::assert_supported($source, $target, 2);
		$line = reset($report['plan']['lines']);
		self::assert('fresh_target_line' === $line['action'] && array(1002) === $report['plan']['tax_template_rate_ids'], 'Fully discounted product-tax authority was not accepted exactly.');
		self::assert(false === strpos((string) wp_json_encode($report['plan']), $private_name), 'Raw item-name PII leaked into the canonical Merge plan.');

		$review = WCOS_Merge_Review_Store::create($source, $target, $report, self::$operator_id);
		self::$review_ids[] = $review['review_id'];
		self::assert(false === strpos((string) wp_json_encode($review['authority']), $private_name), 'Raw item-name PII leaked into Merge Review authority.');
		$confirmation = WCOS_Merge_Confirmation_Store::create($source, $target, $review['authority'], self::$operator_id);
		$operation_id = $confirmation['operation_id'];
		self::$operation_ids[$source->get_id()][] = $operation_id;
		self::assert(false === strpos((string) wp_json_encode($confirmation['record']), $private_name), 'Raw item-name PII leaked into Merge Confirmation authority.');
		self::assert(WCOS_Merge_Review_Store::consume($review['review_id']), 'PII-free Review authority could not be consumed.');

		$result = (new WCOS_Merge_Order_Service())->merge(
			$source,
			$target,
			$operation_id,
			2,
			WCOS_Merge_Confirmation_Store::operation_authority($confirmation['record'])
		);
		self::assert('completed' === $result['status'], 'Fully discounted zero-total-tax Merge did not complete.');
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$record = WCOS_Operation_Journal::get($source, $operation_id);
		self::assert(is_array($record) && 'completed' === sanitize_key((string) $record['status']), 'Fully discounted Merge did not persist a completed journal.');
		self::assert(false === strpos((string) wp_json_encode($record), $private_name), 'Raw item-name PII leaked into the durable Merge journal.');
		self::assert('trash' === $source->get_status(), 'Fully discounted Merge did not archive its source.');
		$taxes = self::tax_totals($target);
		self::assert(isset($taxes[1002]) && array(0, 0) === $taxes[1002], 'Fully discounted Merge did not materialize its exact zero-valued historical tax row.');
		self::assert($result === (new WCOS_Merge_Order_Service())->merge($source, $target, $operation_id, 2), 'Fully discounted Merge did not replay its terminal result exactly.');

		self::$results['fully_discounted_tax_and_pii_free_authority'] = array(
			'zero_total_product_tax_row_materialized' => true,
			'terminal_journal_status' => 'completed',
			'raw_item_name_excluded_from_plan_review_confirmation_journal' => true,
		);
	}

	private static function stock_marker_matrix() {
		$product = self::product('stock-marker-matrix');
		$source = self::order('target-marker-source');
		$target = self::order('target-marker-target', 'on-hold');
		self::line($source, $product);
		$target_line = self::line($target, $product, array('reduced_stock' => '0.500000'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$target->get_data_store()->set_stock_reduced($target->get_id(), true);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$physical_before = (string) wc_get_product($product->get_id())->get_stock_quantity();
		$operation_id = 'compat-005-target-marker-' . wp_generate_uuid4();
		self::$operation_ids[$source->get_id()][] = $operation_id;
		$result = (new WCOS_Merge_Order_Service())->merge($source, $target, $operation_id, 2);
		self::assert('completed' === $result['status'], 'Target-only reduced-stock ownership did not complete.');
		$target = wc_get_order($target->get_id());
		$target_line = $target->get_item($target_line->get_id());
		self::assert('0.500000' === WCOS_Decimal::normalize($target_line->get_meta('_reduced_stock', true), 6), 'Target-only reduced-stock marker changed during coalescing.');
		self::assert(true === (bool) $target->get_data_store()->get_stock_reduced($target->get_id()), 'Target-only order stock flag was cleared.');
		self::assert($physical_before === (string) wc_get_product($product->get_id())->get_stock_quantity(), 'Target-only marker Merge changed physical stock.');

		$invalid = array(
			'nonnumeric' => array('value' => 'owned', 'quantity' => '1.000000', 'flag' => true),
			'negative' => array('value' => '-0.000001', 'quantity' => '1.000000', 'flag' => true),
			'over_quantity' => array('value' => '1.000001', 'quantity' => '1.000000', 'flag' => true),
			'positive_without_flag' => array('value' => '0.500000', 'quantity' => '1.000000', 'flag' => false),
		);
		foreach ($invalid as $label => $fixture) {
			$bad_source = self::order('bad-source-marker-' . $label);
			$bad_target = self::order('bad-target-marker-' . $label);
			self::line($bad_source, $product, array('quantity' => $fixture['quantity'], 'reduced_stock' => $fixture['value']));
			self::line($bad_target, $product);
			$bad_source = self::finalize($bad_source);
			$bad_target = self::finalize($bad_target);
			$bad_source->get_data_store()->set_stock_reduced($bad_source->get_id(), (bool) $fixture['flag']);
			self::expect_reason(wc_get_order($bad_source->get_id()), wc_get_order($bad_target->get_id()), 'incompatible_pair_context');
		}

		$bad_source = self::order('target-marker-without-flag-source');
		$bad_target = self::order('target-marker-without-flag-target');
		self::line($bad_source, $product);
		self::line($bad_target, $product, array('reduced_stock' => '0.500000'));
		$bad_source = self::finalize($bad_source);
		$bad_target = self::finalize($bad_target);
		$bad_target->get_data_store()->set_stock_reduced($bad_target->get_id(), false);
		self::expect_reason($bad_source, wc_get_order($bad_target->get_id()), 'incompatible_pair_context');

		self::$results['stock_marker_matrix'] = array(
			'cases' => '54-63',
			'target_only_marker_preserved' => true,
			'malformed_markers_fail_closed' => array_keys($invalid),
			'order_line_ownership_consistency' => true,
			'physical_stock_neutral' => true,
		);
	}

	private static function paid_refund_boundary() {
		$product = self::product('paid-refund-boundary', false);
		foreach (array('source', 'target') as $participant) {
			$source = self::order('transaction-' . $participant . '-source');
			$target = self::order('transaction-' . $participant . '-target');
			self::line($source, $product);
			self::line($target, $product);
			$source = self::finalize($source);
			$target = self::finalize($target);
			$changed = 'source' === $participant ? $source : $target;
			$changed->set_transaction_id('compat-005-' . $participant . '-transaction');
			$changed->save();
			self::expect_reason(wc_get_order($source->get_id()), wc_get_order($target->get_id()), 'paid_order_unsupported');

			$source = self::order('paid-date-' . $participant . '-source');
			$target = self::order('paid-date-' . $participant . '-target');
			self::line($source, $product);
			self::line($target, $product);
			$source = self::finalize($source);
			$target = self::finalize($target);
			$changed = 'source' === $participant ? $source : $target;
			$changed->set_date_paid(time());
			$changed->save();
			self::expect_reason(wc_get_order($source->get_id()), wc_get_order($target->get_id()), 'paid_order_unsupported');

			$source = self::order('paid-status-' . $participant . '-source');
			$target = self::order('paid-status-' . $participant . '-target');
			self::line($source, $product);
			self::line($target, $product);
			$source = self::finalize($source);
			$target = self::finalize($target);
			$changed = 'source' === $participant ? $source : $target;
			$changed->set_status('processing');
			$changed->save();
			self::expect_reason(wc_get_order($source->get_id()), wc_get_order($target->get_id()), 'paid_order_unsupported');

			$source = self::order('refund-' . $participant . '-source');
			$target = self::order('refund-' . $participant . '-target');
			self::line($source, $product);
			self::line($target, $product);
			$source = self::finalize($source);
			$target = self::finalize($target);
			$changed = 'source' === $participant ? $source : $target;
			$refund = wc_create_refund(array(
				'amount' => '1.00',
				'reason' => 'WOS-COMPAT-005 refund boundary fixture',
				'order_id' => $changed->get_id(),
				'refund_payment' => false,
				'restock_items' => false,
			));
			self::assert($refund instanceof WC_Order_Refund, 'Refund boundary fixture could not be created.');
			self::expect_reason(wc_get_order($source->get_id()), wc_get_order($target->get_id()), 'refund_policy_missing');
		}

		self::$results['paid_refund_boundary'] = array(
			'cases' => '65-76',
			'participants' => array('source', 'target'),
			'paid_evidence' => array('transaction_id', 'date_paid', 'paid_status'),
			'refund_evidence' => true,
			'fail_closed_without_mutation' => true,
		);
	}

	private static function review_confirm_drift_and_transient_versions() {
		$product = self::product('review-confirm-drift', false);
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		$drifts = array(
			'source_status' => array('participant' => 'source', 'field' => 'status', 'value' => 'on-hold', 'code' => 'review_source_changed'),
			'target_status' => array('participant' => 'target', 'field' => 'status', 'value' => 'on-hold', 'code' => 'review_target_changed'),
			'source_context' => array('participant' => 'source', 'field' => 'billing_email', 'value' => 'source-drift@example.test', 'code' => 'review_source_changed'),
			'target_context' => array('participant' => 'target', 'field' => 'billing_email', 'value' => 'target-drift@example.test', 'code' => 'review_target_changed'),
		);
		foreach ($drifts as $label => $drift) {
			$source = self::order('review-' . $label . '-source');
			$target = self::order('review-' . $label . '-target');
			self::line($source, $product);
			self::line($target, $product);
			$source = self::finalize($source);
			$target = self::finalize($target);
			$request = self::request($source, $target);
			$review = $controller->review_request($request);
			self::$review_ids[] = $review['review_id'];
			$changed = 'source' === $drift['participant'] ? $source : $target;
			if ('status' === $drift['field']) {
				$changed->set_status($drift['value']);
			} else {
				$changed->set_billing_email($drift['value']);
			}
			$changed->save();
			$journal_count = self::journal_option_count();
			self::expect_transport_code(static function() use ($controller, $request, $review) {
				$controller->confirm_request(array_merge($request, array(
					'review_id' => $review['review_id'],
					'review_token' => $review['review_token'],
				)));
			}, $drift['code']);
			self::assert($journal_count === self::journal_option_count(), 'Review drift created a durable journal: ' . $label);
		}

		foreach (array('source', 'target') as $participant) {
			$source = self::order('permission-' . $participant . '-source');
			$target = self::order('permission-' . $participant . '-target');
			self::line($source, $product);
			self::line($target, $product);
			$source = self::finalize($source);
			$target = self::finalize($target);
			$request = self::request($source, $target);
			$review = $controller->review_request($request);
			self::$review_ids[] = $review['review_id'];
			$denied_order_id = 'source' === $participant ? $source->get_id() : $target->get_id();
			$deny_capability = static function($allcaps, $caps, $args) use ($denied_order_id) {
				$requested = isset($args[0]) ? (string) $args[0] : '';
				$object_id = isset($args[2]) ? absint($args[2]) : 0;
				if ($denied_order_id === $object_id && in_array($requested, array('edit_shop_order', 'delete_shop_order'), true)) {
					foreach ((array) $caps as $capability) {
						$allcaps[$capability] = false;
					}
				}
				return $allcaps;
			};
			$journal_count = self::journal_option_count();
			add_filter('user_has_cap', $deny_capability, 999, 3);
			try {
				self::expect_transport_code(static function() use ($controller, $request, $review) {
					$controller->confirm_request(array_merge($request, array(
						'review_id' => $review['review_id'],
						'review_token' => $review['review_token'],
					)));
				}, 'authorization_failed');
			} finally {
				remove_filter('user_has_cap', $deny_capability, 999);
			}
			self::assert($journal_count === self::journal_option_count(), 'Permission drift created a durable journal: ' . $participant);
		}

		$legacy_source = self::order('legacy-review-source');
		$legacy_target = self::order('legacy-review-target');
		self::line($legacy_source, $product);
		self::line($legacy_target, $product);
		$legacy_source = self::finalize($legacy_source);
		$legacy_target = self::finalize($legacy_target);
		$legacy_report = WCOS_Merge_Preflight::assert_supported($legacy_source, $legacy_target, 2);
		$legacy_review = WCOS_Merge_Review_Store::create($legacy_source, $legacy_target, $legacy_report, self::$operator_id);
		self::$review_ids[] = $legacy_review['review_id'];
		$legacy_review_record = get_transient(self::review_key($legacy_review['review_id']));
		$legacy_review_record['authority'] = self::downgrade_ephemeral_authority($legacy_review_record['authority']);
		set_transient(self::review_key($legacy_review['review_id']), $legacy_review_record, WCOS_Merge_Review_Store::TTL);
		$legacy_review_rejected = false;
		try {
			WCOS_Merge_Review_Store::verify($legacy_source, $legacy_target, $legacy_review['review_id'], $legacy_review['review_token'], self::$operator_id);
		} catch (WCOS_Merge_Review_Exception $exception) {
			$legacy_review_rejected = in_array($exception->get_reason(), array('authority_changed', 'review_invalid'), true);
		}
		self::assert($legacy_review_rejected, 'Legacy policy tuple was accepted from a temporary Review record.');

		$current_review = WCOS_Merge_Review_Store::create($legacy_source, $legacy_target, $legacy_report, self::$operator_id);
		self::$review_ids[] = $current_review['review_id'];
		$confirmation = WCOS_Merge_Confirmation_Store::create($legacy_source, $legacy_target, $current_review['authority'], self::$operator_id);
		self::$operation_ids[$legacy_source->get_id()][] = $confirmation['operation_id'];
		$confirmation_record = get_transient(self::confirmation_key($confirmation['operation_id']));
		$downgraded = self::downgrade_ephemeral_authority($confirmation_record);
		set_transient(self::confirmation_key($confirmation['operation_id']), $downgraded, WCOS_Merge_Confirmation_Store::TTL);
		$legacy_confirmation_rejected = false;
		try {
			WCOS_Merge_Confirmation_Store::verify($legacy_source, $legacy_target, $confirmation['operation_id'], $confirmation['confirmation_token'], self::$operator_id);
		} catch (WCOS_Merge_Confirmation_Exception $exception) {
			$legacy_confirmation_rejected = in_array($exception->get_reason(), array('authority_changed', 'authority_incomplete'), true);
		}
		self::assert($legacy_confirmation_rejected, 'Legacy policy tuple was accepted from a temporary Confirmation record.');

		self::$results['review_confirm_drift'] = array(
			'cases' => '79-83',
			'status_and_context_drift' => array_keys($drifts),
			'permission_drift' => array('source', 'target'),
			'legacy_review_rejected' => true,
			'legacy_confirmation_rejected' => true,
			'pre_journal_fail_closed' => true,
		);
	}

	private static function coalesced_recovery_and_response_loss() {
		$product = self::product('coalesced-recovery');
		$source = self::order('coalesced-recovery-source');
		$target = self::order('coalesced-recovery-target', 'on-hold');
		self::line($source, $product, array('quantity' => '1', 'subtotal' => '11.00', 'total' => '10.00'));
		self::line($target, $product, array('quantity' => '2', 'subtotal' => '22.00', 'total' => '20.00'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
		$target_before = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
		$operation_id = 'compat-005-coalesce-recovery-' . wp_generate_uuid4();
		self::$operation_ids[$source->get_id()][] = $operation_id;
		$checkpoint_hit = false;
		$interrupt = static function($stage) use (&$checkpoint_hit) {
			if (!$checkpoint_hit && 'after_target_line_checkpoint' === $stage) {
				$checkpoint_hit = true;
				throw new WCOS_Merge_Recovery_Interruption_Exception('Injected coalesced target checkpoint interruption.');
			}
		};
		add_action('wcos_merge_mutation_checkpoint', $interrupt, 10, 4);
		try {
			(new WCOS_Merge_Order_Service())->merge($source, $target, $operation_id, 2);
		} catch (Throwable $throwable) {
			/* Synchronous coordinator outcome is asserted below. */
		} finally {
			remove_action('wcos_merge_mutation_checkpoint', $interrupt, 10);
		}
		self::assert($checkpoint_hit, 'Coalesced target checkpoint interruption was not reached.');
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		$record = WCOS_Operation_Journal::get($source, $operation_id);
		self::assert(is_array($record) && 'compensated' === sanitize_key((string) $record['status']), 'Interrupted coalesced Merge did not compensate safely.');
		self::assert(hash_equals($source_before, WCOS_Merge_Recovery_Snapshot::participant_signature($source)), 'Coalesced compensation did not restore source exactly.');
		self::assert(hash_equals($target_before, WCOS_Merge_Recovery_Snapshot::participant_signature($target)), 'Coalesced compensation did not restore the modified target line exactly.');
		$closed_retry = false;
		try {
			(new WCOS_Merge_Order_Service())->merge($source, $target, $operation_id, 2);
		} catch (RuntimeException $exception) {
			$closed_retry = true;
		}
		self::assert($closed_retry, 'A compensated coalesced operation was reopened.');

		$response_source = self::order('coalesced-response-loss-source');
		$response_target = self::order('coalesced-response-loss-target', 'on-hold');
		self::line($response_source, $product, array('quantity' => '1', 'subtotal' => '11.00', 'total' => '10.00'));
		$response_target_line = self::line($response_target, $product, array('quantity' => '2', 'subtotal' => '22.00', 'total' => '20.00'));
		$response_source = self::finalize($response_source);
		$response_target = self::finalize($response_target);
		$response_operation = 'compat-005-coalesce-response-' . wp_generate_uuid4();
		self::$operation_ids[$response_source->get_id()][] = $response_operation;
		$response_loss_hit = false;
		$response_loss = static function($stage) use (&$response_loss_hit) {
			if (!$response_loss_hit && 'after_complete' === $stage) {
				$response_loss_hit = true;
				throw new WCOS_Merge_Recovery_Interruption_Exception('Injected coalesced response loss.');
			}
		};
		add_action('wcos_merge_mutation_checkpoint', $response_loss, 10, 4);
		try {
			(new WCOS_Merge_Order_Service())->merge($response_source, $response_target, $response_operation, 2);
		} catch (Throwable $throwable) {
			/* Completed durable result is replayed below. */
		} finally {
			remove_action('wcos_merge_mutation_checkpoint', $response_loss, 10);
		}
		self::assert($response_loss_hit, 'Coalesced response-loss boundary was not reached.');
		$response_source = wc_get_order($response_source->get_id());
		$response_target = wc_get_order($response_target->get_id());
		$after_loss = self::item_snapshot($response_target->get_item($response_target_line->get_id()));
		$first_replay = (new WCOS_Merge_Order_Service())->merge($response_source, $response_target, $response_operation, 2);
		$second_replay = (new WCOS_Merge_Order_Service())->merge(wc_get_order($response_source->get_id()), wc_get_order($response_target->get_id()), $response_operation, 2);
		$response_target = wc_get_order($response_target->get_id());
		$after_replay = self::item_snapshot($response_target->get_item($response_target_line->get_id()));
		self::assert('completed' === $first_replay['status'] && $first_replay === $second_replay, 'Coalesced response-loss replay did not return one exact terminal result.');
		self::assert($after_loss === $after_replay, 'Coalesced response-loss replay performed another target write.');
		self::assert('3.000000' === WCOS_Decimal::normalize($response_target->get_item($response_target_line->get_id())->get_quantity(), 6), 'Coalesced response-loss replay applied the source quantity more than once.');

		$lease_source = self::order('shared-lease-source');
		$lease_target = self::order('shared-lease-target');
		self::line($lease_source, $product);
		self::line($lease_target, $product);
		$lease_source = self::finalize($lease_source);
		$lease_target = self::finalize($lease_target);
		$lease_source_before = WCOS_Merge_Recovery_Snapshot::participant_signature($lease_source);
		$lease_target_before = WCOS_Merge_Recovery_Snapshot::participant_signature($lease_target);
		$blocker_operation = 'compat-005-blocker-' . wp_generate_uuid4();
		$blocked_operation = 'compat-005-blocked-' . wp_generate_uuid4();
		$lease = WCOS_Multi_Order_Lease::acquire(array($lease_target->get_id(), $lease_source->get_id()), $blocker_operation, 60);
		self::assert($lease instanceof WCOS_Multi_Order_Lease, 'Shared-participant lease fixture could not acquire the canonical pair.');
		$lease_rejected = false;
		try {
			(new WCOS_Merge_Order_Service())->merge($lease_source, $lease_target, $blocked_operation, 2);
		} catch (RuntimeException $exception) {
			$lease_rejected = true;
		} finally {
			$lease->release();
		}
		self::assert($lease_rejected && null === WCOS_Operation_Journal::get($lease_source, $blocked_operation), 'Shared-participant lease contention did not fail before journal authority.');
		self::assert(hash_equals($lease_source_before, WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($lease_source->get_id()))), 'Shared-participant contention changed source.');
		self::assert(hash_equals($lease_target_before, WCOS_Merge_Recovery_Snapshot::participant_signature(wc_get_order($lease_target->get_id()))), 'Shared-participant contention changed target.');

		self::$results['coalesced_recovery_response_loss'] = array(
			'cases' => '85-88',
			'checkpoint_compensated' => true,
			'exact_target_restore' => true,
			'response_loss_idempotent' => true,
			'shared_participant_lease_fail_closed' => true,
		);
	}

	private static function legacy_v1_durable_replay() {
		$product = self::product('legacy-durable-replay', false);
		$shared_context = array(
			'email' => 'legacy-durable@example.test',
			'billing_address' => '1 Legacy Durable Street',
			'billing_city' => 'Legacy City',
			'shipping_address' => '2 Legacy Durable Street',
			'shipping_city' => 'Legacy City',
			'payment_method' => 'cod',
			'payment_title' => 'Legacy cash payment',
		);
		$source = self::order('legacy-durable-source', 'pending', $shared_context);
		$target = self::order('legacy-durable-target', 'pending', $shared_context);
		$source_item = self::line($source, $product, array('quantity' => '1', 'subtotal' => '10.00', 'total' => '9.00'));
		$target_item = self::line($target, $product, array('quantity' => '2', 'subtotal' => '20.00', 'total' => '18.00'));
		$source = self::finalize($source);
		$target = self::finalize($target);
		$source_id = (int) $source->get_id();
		$target_id = (int) $target->get_id();
		$source_item = $source->get_item($source_item->get_id());
		$legacy_line = array(
			'source_item_id' => (int) $source_item->get_id(),
			'line_identity' => WCOS_Line_Identity::from_item($source_item),
			'product_id' => (int) $source_item->get_product_id(),
			'variation_id' => (int) $source_item->get_variation_id(),
			'tax_class' => (string) $source_item->get_tax_class(),
			'quantity' => WCOS_Decimal::normalize($source_item->get_quantity(), 6),
			'subtotal' => (string) $source_item->get_subtotal(),
			'subtotal_tax' => (string) $source_item->get_subtotal_tax(),
			'total' => (string) $source_item->get_total(),
			'total_tax' => (string) $source_item->get_total_tax(),
			'taxes' => $source_item->get_taxes(),
			'reduced_stock' => null,
		);
		$legacy_plan = WCOS_Merge_Plan::canonicalize(
			$source->get_id(),
			$target->get_id(),
			array($source_item->get_id() => $legacy_line)
		);
		$context_authority = WCOS_Merge_Context_Signature::compatibility($source, $target);
		$authority = array(
			'source_order_id' => (int) $source->get_id(),
			'target_order_id' => (int) $target->get_id(),
			'source_signature' => WCOS_Order_Contract_Snapshot::source_signature($source),
			'target_signature' => WCOS_Order_Contract_Snapshot::source_signature($target),
			'plan_schema_version' => WCOS_Merge_Plan::LEGACY_SCHEMA_VERSION,
			'plan_fingerprint' => WCOS_Merge_Plan::fingerprint($legacy_plan),
			'price_precision' => 2,
			'preflight_policy_version' => WCOS_Merge_Preflight::LEGACY_POLICY_VERSION,
			'context_signature_version' => WCOS_Merge_Context_Signature::LEGACY_SCHEMA_VERSION,
			'context_authority' => $context_authority,
			'context_authority_fingerprint' => WCOS_Merge_Context_Signature::authority_fingerprint($context_authority),
			'retirement_policy_schema_version' => WCOS_Merge_Retirement_Policy::SCHEMA_VERSION,
			'retirement_candidates' => WCOS_Merge_Retirement_Policy::identifiers(),
			'retirement_policy_selected' => true,
			'retirement_policy_identifier' => WCOS_Merge_Retirement_Policy::approved_identifier(),
			'archive_source_signature_before' => WCOS_Merge_Recovery_Snapshot::archive_commercial_signature($source),
			'active_ownership_before_signature' => WCOS_Merge_Recovery_Snapshot::active_economic_signature(array($source, $target), 2, $source->get_id()),
			'participation_schema_version' => WCOS_Merge_Participation::SCHEMA_VERSION,
		);
		$pair_fingerprint = WCOS_Mutation_Fingerprint::create('merge_pair_authority_v3', $source->get_id(), $authority);
		$journal_context = array(
			'merge_pair' => array(
				'schema_version' => WCOS_Merge_Journal_Context::LEGACY_SCHEMA_VERSION,
				'authority' => $authority,
				'pair_fingerprint' => $pair_fingerprint,
			),
			'merge_plan' => $legacy_plan,
		);
		$operation_id = 'compat-005-legacy-replay-' . wp_generate_uuid4();
		self::$operation_ids[$source->get_id()][] = $operation_id;
		$lease = WCOS_Multi_Order_Lease::acquire(array($source->get_id(), $target->get_id()), $operation_id, 60);
		self::assert($lease instanceof WCOS_Multi_Order_Lease, 'Legacy durable replay fixture could not acquire its pair lease.');
		$stock_guard = WCOS_Stock_Side_Effect_Guard::begin($operation_id);
		try {
			self::assert(WCOS_Operation_Journal::start($source, $operation_id, 'merge', $journal_context, $pair_fingerprint), 'Legacy durable journal could not be started.');
			$record = WCOS_Operation_Journal::get($source, $operation_id);
			$pair = WCOS_Merge_Journal_Context::assert_executable_policy($record);
			self::assert(WCOS_Merge_Order_Service::LEGACY_POLICY_VERSION === WCOS_Merge_Journal_Context::service_policy_for_pair($pair), 'Legacy durable tuple did not resolve to Merge service policy v1.');
			self::legacy_checkpoint($source, $target, $operation_id, WCOS_Merge_Recovery_State_Graph::NO_WRITE, array(), array(), false);

			$clone = WCOS_Order_Item_Cloner::product($source_item, array(), true, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE);
			$target->add_item($clone);
			$target->save();
			$added_item_id = absint($clone->get_id());
			self::assert($added_item_id > 0 && $added_item_id !== $source_item->get_id(), 'Legacy fresh target object was not persisted independently.');
			self::legacy_checkpoint($source, wc_get_order($target->get_id()), $operation_id, WCOS_Merge_Recovery_State_Graph::TARGET_STAGING, array($added_item_id), array(), false);

			$target = wc_get_order($target->get_id());
			$tax_ids_before = array_map('intval', array_keys($target->get_items('tax')));
			WCOS_Tax_Item_Synchronizer::synchronize($target, WCOS_Tax_Item_Synchronizer::templates($source), 2, true, WCOS_Order_Item_Meta_Policy::CONTEXT_MERGE);
			WCOS_Order_Totals_Rebuilder::rebuild($target, 2);
			$target->save();
			$target = wc_get_order($target->get_id());
			$added_tax_ids = array_values(array_diff(array_map('intval', array_keys($target->get_items('tax'))), $tax_ids_before));
			self::legacy_checkpoint($source, $target, $operation_id, WCOS_Merge_Recovery_State_Graph::TARGET_PERSISTED, array($added_item_id), $added_tax_ids, false);

			$source->get_data_store()->set_stock_reduced($source->get_id(), false);
			$target->get_data_store()->set_stock_reduced($target->get_id(), false);
			$source = wc_get_order($source->get_id());
			$target = wc_get_order($target->get_id());
			self::legacy_checkpoint($source, $target, $operation_id, WCOS_Merge_Recovery_State_Graph::SOURCE_OWNERSHIP_MIGRATED, array($added_item_id), $added_tax_ids, false);

			$source->delete(false);
			$source = wc_get_order($source_id);
			self::assert($source instanceof WC_Order && 'trash' === $source->get_status(), 'Legacy source was not non-force retired.');
			self::legacy_checkpoint($source, $target, $operation_id, WCOS_Merge_Recovery_State_Graph::SOURCE_RETIRED, array($added_item_id), $added_tax_ids, true);
			$record = WCOS_Operation_Journal::get($source, $operation_id);
			$outcome = WCOS_Merge_Compensator::recover($source, $target, $record, $lease);
			self::assert('completed' === $outcome, 'Authentic legacy durable journal did not complete through forward recovery.');
		} finally {
			WCOS_Stock_Side_Effect_Guard::end($stock_guard);
			$lease->release();
		}

		$source = wc_get_order($source_id);
		$target = wc_get_order($target_id);
		$record = WCOS_Operation_Journal::get($source, $operation_id);
		self::assert('completed' === sanitize_key((string) $record['status']), 'Legacy durable fixture did not persist terminal status.');
		self::assert(2 === count($target->get_items('line_item')) && $target->get_item($target_item->get_id()) instanceof WC_Order_Item_Product && $target->get_item($added_item_id) instanceof WC_Order_Item_Product, 'Legacy fresh-line semantics were silently reinterpreted as coalescing.');
		$target->set_status('cancelled');
		$target->set_billing_email('legacy-post-complete-drift@example.test');
		$target->save();
		update_option('order_splitter_status_allowed', array('wc-processing'));
		$first = (new WCOS_Merge_Order_Service())->merge($source, wc_get_order($target_id), $operation_id, 2);
		$second = (new WCOS_Merge_Order_Service())->merge(wc_get_order($source_id), wc_get_order($target_id), $operation_id, 2);
		self::assert('completed' === $first['status'] && $first === $second, 'Legacy completed journal did not replay its exact terminal result after current eligibility drift.');
		self::assert(2 === count(wc_get_order($target_id)->get_items('line_item')), 'Legacy completed replay performed a fresh write.');
		update_option('order_splitter_status_allowed', array('wc-pending', 'wc-on-hold'));

		self::$results['legacy_v1_durable_replay'] = array(
			'cases' => '77,89-90',
			'pair_schema_version' => WCOS_Merge_Journal_Context::LEGACY_SCHEMA_VERSION,
			'plan_schema_version' => WCOS_Merge_Plan::LEGACY_SCHEMA_VERSION,
			'service_policy_version' => WCOS_Merge_Order_Service::LEGACY_POLICY_VERSION,
			'fresh_line_semantics_preserved' => true,
			'exact_terminal_replay' => true,
		);
	}

	private static function non_regression_authority() {
		$product = self::product('non-regression', false);
		foreach (array('cancelled', 'failed', 'refunded', 'checkout-draft', 'trash') as $status) {
			$source = self::order('unsafe-target-' . $status . '-source');
			$target = self::order('unsafe-target-' . $status . '-target');
			self::line($source, $product);
			self::line($target, $product);
			$source = self::finalize($source);
			$target = self::finalize($target);
			$target->set_status($status);
			$target->save();
			self::expect_reason($source, wc_get_order($target->get_id()), 'incompatible_status');
		}

		$charge_source = self::order('source-charges-target-none-source');
		$charge_target = self::order('source-charges-target-none-target', 'on-hold');
		self::line($charge_source, $product, array('meta' => array('Configuration' => 'source-charge-line'), 'subtotal' => '10.00', 'total' => '8.50'));
		self::line($charge_target, $product, array('meta' => array('Configuration' => 'target-line')));
		self::shipping($charge_source, 'Archived source shipping', '2.00');
		self::fee($charge_source, 'Archived negative source fee', '-0.75');
		self::coupon($charge_source, 'archived-source-coupon', '1.50');
		$charge_source = self::finalize($charge_source);
		$charge_target = self::finalize($charge_target);
		$source_charges = array(
			'shipping' => self::items_snapshot($charge_source, 'shipping'),
			'fee' => self::items_snapshot($charge_source, 'fee'),
			'coupon' => self::items_snapshot($charge_source, 'coupon'),
		);
		$charge_operation = 'compat-005-source-charges-' . wp_generate_uuid4();
		self::$operation_ids[$charge_source->get_id()][] = $charge_operation;
		$charge_result = (new WCOS_Merge_Order_Service())->merge($charge_source, $charge_target, $charge_operation, 2);
		self::assert('completed' === $charge_result['status'], 'Source shipping/negative-fee/coupon with empty target charges did not Merge.');
		$charge_source = wc_get_order($charge_source->get_id());
		$charge_target = wc_get_order($charge_target->get_id());
		self::assert($source_charges === array(
			'shipping' => self::items_snapshot($charge_source, 'shipping'),
			'fee' => self::items_snapshot($charge_source, 'fee'),
			'coupon' => self::items_snapshot($charge_source, 'coupon'),
		), 'Source negative fee/shipping/coupon archival history changed.');
		self::assert(empty($charge_target->get_items('shipping')) && empty($charge_target->get_items('fee')) && empty($charge_target->get_items('coupon')), 'Source charges leaked into a target that had no charges.');

		$unknown_source = self::order('unknown-private-source');
		$unknown_target = self::order('unknown-private-target');
		$unknown_line = self::line($unknown_source, $product);
		self::line($unknown_target, $product);
		$unknown_source = self::finalize($unknown_source);
		$unknown_target = self::finalize($unknown_target);
		$unknown_line = $unknown_source->get_item($unknown_line->get_id());
		$unknown_line->add_meta_data('_unclassified_merge_state', 'unsafe', true);
		$unknown_line->save();
		self::expect_reason(wc_get_order($unknown_source->get_id()), $unknown_target, 'incompatible_pair_context');

		$duplicate_source = self::order('duplicate-pending-regression', 'on-hold');
		self::line($duplicate_source, $product, array('meta' => array('Configuration' => 'duplicate-regression')));
		$duplicate_source = self::finalize($duplicate_source);
		$duplicate_adapter = new WCOS_Duplicate_WooCommerce_Adapter();
		$duplicate_report = $duplicate_adapter->preflight($duplicate_source);
		self::assert(!empty($duplicate_report['supported']), 'Duplicate non-regression fixture failed preflight.');
		$duplicate_confirmation = WCOS_Duplicate_Confirmation_Store::create($duplicate_source, $duplicate_report, self::$operator_id);
		WCOS_Duplicate_Confirmation_Store::verify(
			$duplicate_source,
			$duplicate_confirmation['operation_id'],
			$duplicate_confirmation['confirmation_token'],
			self::$operator_id
		);
		self::$operation_ids[$duplicate_source->get_id()][] = $duplicate_confirmation['operation_id'];
		$duplicate_target = $duplicate_adapter->duplicate($duplicate_source, $duplicate_confirmation['operation_id'], $duplicate_report['price_precision']);
		self::assert($duplicate_target instanceof WC_Order && 'pending' === $duplicate_target->get_status(), 'Duplicate target no longer remains pending.');
		self::$order_ids[] = $duplicate_target->get_id();
		WCOS_Duplicate_Confirmation_Store::delete($duplicate_confirmation['operation_id']);

		$workflow_gates = array(
			WCOS_Feature_Gates::SPLIT,
			WCOS_Feature_Gates::DUPLICATE,
			WCOS_Feature_Gates::MERGE,
			WCOS_Feature_Gates::RETURN_ORDER,
			WCOS_Feature_Gates::BULK_RETURN,
		);
		foreach ($workflow_gates as $gate) {
			self::assert(WCOS_Feature_Gates::enabled($gate), 'Production workflow gate changed: ' . $gate);
		}
		$strategy_gates = array(
			WCOS_Split_Strategy_Gates::MANUAL_QUANTITY,
			WCOS_Split_Strategy_Gates::CATEGORY,
			WCOS_Split_Strategy_Gates::STOCK_STATUS,
		);
		foreach ($strategy_gates as $gate) {
			self::assert(WCOS_Split_Strategy_Gates::enabled($gate), 'Production Split strategy gate changed: ' . $gate);
		}
		$plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
		$readme_file = dirname(__DIR__, 2) . '/readme.txt';
		$plugin_source = file_get_contents($plugin_file);
		$readme_source = file_get_contents($readme_file);
		self::assert(false !== strpos($plugin_source, 'Version: 1.5.0') && false !== strpos($readme_source, 'Stable tag: 1.5.0'), 'Version or Stable tag changed during compatibility work.');

		self::$results['non_regression'] = array(
			'cases' => '5,17,24,79-86',
			'unsafe_targets_fail_closed' => true,
			'source_charge_archive_target_none' => true,
			'unknown_private_metadata_fail_closed' => true,
			'duplicate_target_status' => $duplicate_target->get_status(),
			'workflow_gates' => array_fill_keys($workflow_gates, true),
			'strategy_gates' => array_fill_keys($strategy_gates, true),
			'version_release_unchanged' => true,
		);
	}

	private static function legacy_checkpoint(WC_Order $source, WC_Order $target, $operation_id, $state, array $target_item_ids, array $target_tax_item_ids, $forward) {
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
		self::assert(WCOS_Operation_Journal::checkpoint($source, $operation_id, 'merge_service_checkpoint', $context), 'Legacy durable recovery checkpoint could not be persisted: ' . $state);
		$record = WCOS_Operation_Journal::get(wc_get_order($source->get_id()), $operation_id);
		self::assert($state === WCOS_Merge_Recovery_State_Graph::assert_record($record), 'Legacy durable recovery graph did not reach: ' . $state);
	}

	private static function downgrade_ephemeral_authority(array $authority) {
		$current_plan = isset($authority['plan']) && is_array($authority['plan']) ? $authority['plan'] : array();
		$authority['plan'] = WCOS_Merge_Plan::canonicalize(
			isset($current_plan['source_order_id']) ? $current_plan['source_order_id'] : 0,
			isset($current_plan['target_order_id']) ? $current_plan['target_order_id'] : 0,
			isset($current_plan['lines']) && is_array($current_plan['lines']) ? $current_plan['lines'] : array()
		);
		$authority['plan_fingerprint'] = WCOS_Merge_Plan::fingerprint($authority['plan']);
		$authority['merge_service_policy_version'] = WCOS_Merge_Order_Service::LEGACY_POLICY_VERSION;
		$authority['preflight_policy_version'] = WCOS_Merge_Preflight::LEGACY_POLICY_VERSION;
		$authority['plan_schema_version'] = WCOS_Merge_Plan::LEGACY_SCHEMA_VERSION;
		$authority['context_signature_version'] = WCOS_Merge_Context_Signature::LEGACY_SCHEMA_VERSION;
		return $authority;
	}

	private static function review_key($review_id) {
		return 'wcos_merge_review_' . hash('sha256', sanitize_key((string) $review_id));
	}

	private static function confirmation_key($operation_id) {
		return 'wcos_merge_confirm_' . hash('sha256', sanitize_key((string) $operation_id));
	}

	private static function journal_option_count() {
		global $wpdb;
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wcos_mutation_op_%'");
	}

	private static function expect_transport_code(callable $callback, $code) {
		$actual = '';
		try {
			$callback();
		} catch (WCOS_Merge_Transport_Exception $exception) {
			$actual = $exception->get_error_code();
		}
		self::assert($code === $actual, 'Unexpected Merge transport code: ' . $actual . ', expected ' . $code);
	}


	private static function expect_reason(WC_Order $source, WC_Order $target, $reason) {
		$source_before = self::rejection_snapshot($source);
		$target_before = self::rejection_snapshot($target);
		$report = WCOS_Merge_Preflight::report($source, $target, 2);
		self::assert($reason === $report['reason'], 'Unexpected Merge preflight reason: ' . $report['reason'] . ', expected ' . $reason);
		self::assert($source_before === self::rejection_snapshot(wc_get_order($source->get_id())), 'Rejected preflight changed source.');
		self::assert($target_before === self::rejection_snapshot(wc_get_order($target->get_id())), 'Rejected preflight changed target.');
	}

	private static function rejection_snapshot(WC_Order $order) {
		try {
			return WCOS_Merge_Recovery_Snapshot::participant_signature($order);
		} catch (Throwable $throwable) {
			$data = $order->get_data();
			unset($data['date_modified'], $data['meta_data']);
			$meta = array();
			foreach ($order->get_meta_data() as $datum) {
				$meta[] = array('key' => (string) $datum->key, 'value' => $datum->value);
			}
			return hash('sha256', wp_json_encode(array(
				'data' => $data,
				'meta' => $meta,
				'items' => array(
					'line_item' => self::items_snapshot($order, 'line_item'),
					'shipping' => self::items_snapshot($order, 'shipping'),
					'fee' => self::items_snapshot($order, 'fee'),
					'tax' => self::items_snapshot($order, 'tax'),
					'coupon' => self::items_snapshot($order, 'coupon'),
				),
			)));
		}
	}

	private static function sum_decimals(array $values, $precision) {
		$total = '0';
		foreach ($values as $value) {
			$total = WCOS_Merge_Commercial_Policy::add_decimal($total, $value, $precision);
		}
		return $total;
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
			$product = wc_get_product($product_id);
			if ($product instanceof WC_Product) {
				$product->delete(true);
			}
		}
	}

	private static function assert($condition, $message) {
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}
}

WCOS_Compat_Merge_Commercial_Matrix::run();
