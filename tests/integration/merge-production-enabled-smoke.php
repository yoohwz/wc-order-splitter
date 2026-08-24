<?php

if (!defined('ABSPATH')) {
	exit(1);
}

final class WCOS_Merge_Production_Enabled_Matrix {
	private static $order_ids = array();
	private static $user_ids = array();
	private static $product_id = 0;
	private static $operations = array();
	private static $results = array();
	private static $admin_id = 0;
	private static $old_shop_permission = 'no';

	public static function run() {
		$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
		self::assert(!empty($admins), 'Administrator fixture unavailable.');
		self::$admin_id = (int) $admins[0];
		wp_set_current_user(self::$admin_id);
		self::$old_shop_permission = get_option('order_splitter_shop_manager_permission', 'no');
		self::create_product();
		try {
			self::search_and_permissions();
			self::successful_operation();
			self::drift_and_authority();
			self::unsupported_matrix();
			self::valid_recovery_after_errors();
			echo wp_json_encode(array('status' => 'pass', 'results' => self::$results), JSON_PRETTY_PRINT) . "\n";
		} finally {
			wp_set_current_user(self::$admin_id);
			update_option('order_splitter_shop_manager_permission', self::$old_shop_permission);
			if (!function_exists('wp_delete_user')) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			foreach (array_reverse(self::$user_ids) as $user_id) {
				wp_delete_user($user_id);
			}
			foreach (array_reverse(self::$order_ids) as $order_id) {
				$order = wc_get_order($order_id);
				if ($order instanceof WC_Order) {
					if (!empty(self::$operations[$order_id])) {
						WCOS_Operation_Journal::delete($order, self::$operations[$order_id]);
						WCOS_Merge_Confirmation_Store::delete(self::$operations[$order_id]);
					}
					$order->delete(true);
				}
			}
			if (self::$product_id) {
				wp_delete_post(self::$product_id, true);
			}
		}
	}

	private static function create_product() {
		$product = new WC_Product_Simple();
		$product->set_name('WOS-MERGE-008 Negative Matrix Product');
		$product->set_status('publish');
		$product->set_regular_price('10.00');
		$product->set_price('10.00');
		$product->set_tax_status('none');
		$product->set_manage_stock(true);
		$product->set_stock_quantity(100);
		self::$product_id = (int) $product->save();
		self::assert(self::$product_id > 0, 'Negative matrix product creation failed.');
	}

	private static function order($label, array $overrides = array()) {
		$order = wc_create_order(array('status' => 'pending'));
		self::assert($order instanceof WC_Order, 'Order creation failed: ' . $label);
		self::$order_ids[] = $order->get_id();
		$order->set_currency(isset($overrides['currency']) ? $overrides['currency'] : 'USD');
		$order->set_prices_include_tax(true);
		$order->set_billing_first_name('Sandbox');
		$order->set_billing_last_name('Matrix');
		$order->set_billing_email(isset($overrides['email']) ? $overrides['email'] : 'wos-merge-production-enabled-matrix@example.test');
		$order->set_billing_address_1(isset($overrides['address']) ? $overrides['address'] : '7 Matrix Way');
		$order->set_billing_city('Testville');
		$order->set_billing_country('US');
		$order->set_shipping_first_name('Sandbox');
		$order->set_shipping_last_name('Matrix');
		$order->set_shipping_address_1(isset($overrides['address']) ? $overrides['address'] : '7 Matrix Way');
		$order->set_shipping_city('Testville');
		$order->set_shipping_country('US');
		$order->set_payment_method('cod');
		$order->set_payment_method_title('Cash on delivery');
		if (empty($overrides['no_lines'])) {
			$product = wc_get_product(self::$product_id);
			$item_id = $order->add_product($product, 1);
			$item = $order->get_item($item_id);
			$item->add_meta_data('Sandbox matrix', $label, true);
			$item->save();
		}
		if (!empty($overrides['coupon'])) {
			$coupon = new WC_Order_Item_Coupon();
			$coupon->set_code('sandbox-' . sanitize_key($label));
			$coupon->set_discount('1.00');
			$order->add_item($coupon);
		}
		if (!empty($overrides['fee'])) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name('Sandbox fee');
			$fee->set_amount('1.00');
			$fee->set_total('1.00');
			$order->add_item($fee);
		}
		if (!empty($overrides['shipping'])) {
			$shipping = new WC_Order_Item_Shipping();
			$shipping->set_method_title('Sandbox matrix shipping');
			$shipping->set_method_id('flat_rate');
			$shipping->set_total('2.00');
			$order->add_item($shipping);
		}
		$order->calculate_totals(false);
		if (!empty($overrides['transaction_id'])) {
			$order->set_transaction_id($overrides['transaction_id']);
		}
		$order->set_status(isset($overrides['status']) ? $overrides['status'] : 'on-hold');
		$order->save();
		if (!empty($overrides['refund'])) {
			$refund = wc_create_refund(array(
				'order_id' => $order->get_id(),
				'amount' => '1.00',
				'reason' => 'WOS-MERGE-008 sandbox refund fixture',
				'refund_payment' => false,
				'restock_items' => false,
			));
			self::assert($refund instanceof WC_Order_Refund, 'Refund fixture creation failed.');
		}
		return wc_get_order($order->get_id());
	}

	private static function pair($label, array $source_overrides = array(), array $target_overrides = array()) {
		return array(self::order($label . '-source', $source_overrides), self::order($label . '-target', $target_overrides));
	}

	private static function request(WC_Order $source, WC_Order $target) {
		return array(
			'source_order_id' => $source->get_id(),
			'target_order_id' => $target->get_id(),
			'nonce' => wp_create_nonce('wcos_merge_order_' . $source->get_id()),
		);
	}

	private static function search_and_permissions() {
		list($source, $target) = self::pair('search');
		$target->set_date_created('2020-01-01 00:00:00');
		$target->save();
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		$base = array('source_order_id' => $source->get_id(), 'nonce' => wp_create_nonce('wcos_merge_order_' . $source->get_id()), 'page' => 1);
		$browse = $controller->search_request(array_merge($base, array('term' => '')));
		self::assert(count($browse['results']) <= WCOS_Merge_Admin_Controller::SEARCH_LIMIT, 'Browse exceeded result limit.');
		self::assert(!in_array($target->get_id(), array_map(function($r){ return (int) $r['id']; }, $browse['results']), true), 'Old target remained in bounded recent browse.');
		foreach (array((string) $target->get_id(), '#' . $target->get_id()) as $term) {
			$exact = $controller->search_request(array_merge($base, array('term' => $term)));
			self::assert(1 === count($exact['results']) && $target->get_id() === (int) $exact['results'][0]['id'], 'Exact old target search failed: ' . $term);
			self::assert(array('id', 'number', 'status', 'currency') === array_keys($exact['results'][0]), 'Search result exceeded PII-free fields.');
			self::assert(false === strpos(wp_json_encode($exact), '@example.test') && false === strpos(wp_json_encode($exact), 'Matrix Way'), 'Search response exposed PII.');
		}
		$source_result = $controller->search_request(array_merge($base, array('term' => (string) $source->get_id())));
		self::assert(empty($source_result['results']), 'Search included current source.');
		self::expect_transport(function() use ($controller, $base) { $controller->search_request(array_merge($base, array('term' => str_repeat('x', 41)))); }, 'invalid_search');
		self::expect_transport(function() use ($controller, $base) { $controller->search_request(array_merge($base, array('term' => '', 'page' => 6))); }, 'invalid_search_page');

		$subscriber = wp_create_user('wcos-merge-007-sub-' . wp_generate_uuid4(), wp_generate_password(24), 'wos-merge-production-enabled-sub-' . wp_generate_uuid4() . '@example.test');
		self::assert(!is_wp_error($subscriber), 'Subscriber fixture creation failed.');
		self::$user_ids[] = (int) $subscriber;
		wp_set_current_user((int) $subscriber);
		$unauthorized = array('source_order_id' => $source->get_id(), 'nonce' => wp_create_nonce('wcos_merge_order_' . $source->get_id()), 'term' => (string) $target->get_id(), 'page' => 1);
		self::expect_transport(function() use ($controller, $unauthorized) { $controller->search_request($unauthorized); }, 'authorization_failed');

		$manager = wp_create_user('wcos-merge-007-manager-' . wp_generate_uuid4(), wp_generate_password(24), 'wos-merge-production-enabled-manager-' . wp_generate_uuid4() . '@example.test');
		self::assert(!is_wp_error($manager), 'Shop manager fixture creation failed.');
		self::$user_ids[] = (int) $manager;
		$user = get_user_by('id', (int) $manager);
		$user->set_role('shop_manager');
		wp_set_current_user((int) $manager);
		update_option('order_splitter_shop_manager_permission', 'no');
		$manager_request = self::request($source, $target);
		self::expect_transport(function() use ($controller, $manager_request) { $controller->review_request($manager_request); }, 'authorization_failed');
		update_option('order_splitter_shop_manager_permission', 'yes');
		$manager_request['nonce'] = wp_create_nonce('wcos_merge_order_' . $source->get_id());
		$manager_review = $controller->review_request($manager_request);
		self::assert(!empty($manager_review['review_id']), 'Enabled shop-manager semantics did not allow Review.');
		WCOS_Merge_Review_Store::delete($manager_review['review_id']);
		wp_set_current_user(self::$admin_id);
		update_option('order_splitter_shop_manager_permission', self::$old_shop_permission);

		self::$results['search_permissions'] = array(
			'browse_count' => count($browse['results']),
			'exact_numeric' => true,
			'exact_hash' => true,
			'old_target_outside_browse' => true,
			'pii_free' => true,
			'source_excluded' => true,
			'invalid_input_rejected' => true,
			'unauthorized_rejected' => true,
			'shop_manager_option_enforced' => true,
		);
	}

	private static function drift_and_authority() {
		$controller = WCOS_Merge_Admin_Controller::bootstrap();

		list($source, $target) = self::pair('target-drift');
		$request = self::request($source, $target);
		$review = $controller->review_request($request);
		self::change_first_line($target, 2);
		$target_after_drift = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($target->get_id()));
		self::expect_transport(function() use ($controller, $request, $review) {
			$controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
		}, array('review_authority_changed', 'review_pair_changed', 'review_target_changed'));
		self::assert($target_after_drift === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($target->get_id())), 'Rejected target drift changed target again.');
		self::assert(empty($source->get_meta(WCOS_Merge_Participation::SOURCE_OPERATION_META, false)), 'Target drift started a journal.');

		list($source, $target) = self::pair('source-drift');
		$request = self::request($source, $target);
		$review = $controller->review_request($request);
		self::change_first_line($source, 3);
		$source_after_drift = WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($source->get_id()));
		self::expect_transport(function() use ($controller, $request, $review) {
			$controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
		}, array('review_authority_changed', 'review_pair_changed', 'review_source_changed'));
		self::assert($source_after_drift === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($source->get_id())), 'Rejected source drift changed source again.');
		self::assert(empty($source->get_meta(WCOS_Merge_Participation::SOURCE_OPERATION_META, false)), 'Source drift started a journal.');

		$source = self::order('target-change-source');
		$target_a = self::order('target-change-a');
		$target_b = self::order('target-change-b');
		$request_a = self::request($source, $target_a);
		$review = $controller->review_request($request_a);
		$request_b = self::request($source, $target_b);
		self::expect_transport(function() use ($controller, $request_b, $review) {
			$controller->confirm_request(array_merge($request_b, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
		}, array('review_owner_mismatch', 'review_authority_changed'));
		self::assert(empty($source->get_meta(WCOS_Merge_Participation::SOURCE_OPERATION_META, false)), 'Target switch reused stale Review.');

		list($source, $target) = self::pair('review-reuse');
		$request = self::request($source, $target);
		$review = $controller->review_request($request);
		$confirmation = $controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
		self::expect_transport(function() use ($controller, $request, $review) {
			$controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
		}, array('review_expired', 'review_already_consumed'));
		WCOS_Merge_Confirmation_Store::delete($confirmation['operation_id']);

		$injected = self::request($source, $target);
		$injected['plan'] = array('client' => 'authority');
		self::expect_transport(function() use ($controller, $injected) { $controller->review_request($injected); }, 'unexpected_field');

		self::$results['authority_drift'] = array(
			'target_drift_fail_closed' => true,
			'source_drift_fail_closed' => true,
			'target_switch_discarded' => true,
			'review_single_use' => true,
			'client_authority_rejected' => true,
			'no_journal_on_rejection' => true,
		);
	}

	private static function successful_operation() {
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		list($source, $target) = self::pair('enabled-success');
		$source_items = array_values($source->get_items('line_item'));
		self::assert(1 === count($source_items), 'Enabled success source fixture is invalid.');
		$source_items[0]->update_meta_data('_reduced_stock', '1.000000');
		$source_items[0]->save();
		$second_id = $source->add_product(wc_get_product(self::$product_id), 2);
		$second = $source->get_item($second_id);
		self::assert($second instanceof WC_Order_Item_Product, 'Second enabled source line was not created.');
		$second->add_meta_data('Sandbox matrix', 'enabled-success-source-second', true);
		$second->add_meta_data('_reduced_stock', '2.000000', true);
		$second->save();
		$source->calculate_totals(false);
		$source->save();
		$source->get_data_store()->set_stock_reduced($source->get_id(), true);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());

		$before = WCOS_Order_Contract_Snapshot::aggregate(array($source, $target), 2);
		$stock_before = WCOS_Order_Contract_Snapshot::product_stock($source);
		$source_line_ids = array_map('absint', array_keys($source->get_items('line_item')));
		$target_line_ids_before = array_map('absint', array_keys($target->get_items('line_item')));
		$request = self::request($source, $target);
		$review = $controller->review_request($request);
		$confirmation = $controller->confirm_request(array_merge($request, array(
			'review_id' => $review['review_id'],
			'review_token' => $review['review_token'],
		)));
		self::$operations[$source->get_id()] = $confirmation['operation_id'];
		$result = $controller->execute_request(array_merge($request, array(
			'operation_id' => $confirmation['operation_id'],
			'confirmation_token' => $confirmation['confirmation_token'],
		)));
		self::assert('completed' === $result['status'], 'Production-enabled controller chain did not complete.');
		self::assert(false === strpos(wp_json_encode($result), '@example.test') && false === strpos(wp_json_encode($result), 'Matrix Way'), 'Production-enabled result exposed PII.');

		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		self::assert('trash' === $source->get_status(), 'Production-enabled source was not retired through trash archive.');
		self::assert('on-hold' === $target->get_status(), 'Production-enabled target status changed.');
		self::assert(2 === count($source->get_items('line_item')), 'Retired source commercial lines are not inspectable.');
		$target_line_ids_after = array_map('absint', array_keys($target->get_items('line_item')));
		$fresh_ids = array_values(array_diff($target_line_ids_after, $target_line_ids_before));
		self::assert(2 === count($fresh_ids) && 2 === count(array_unique($fresh_ids)), 'Production-enabled Merge coalesced or omitted fresh target lines.');
		self::assert(empty(array_intersect($source_line_ids, $fresh_ids)), 'Production-enabled Merge re-parented source item IDs.');
		foreach ($source->get_items('line_item') as $item) {
			self::assert($source->get_id() === $item->get_order_id(), 'A persisted source item changed ownership.');
			self::assert('' === (string) $item->get_meta('_reduced_stock', true), 'Retired source retained reduced-stock ownership.');
		}
		$target_reduced = array();
		foreach ($fresh_ids as $item_id) {
			$target_reduced[] = WCOS_Decimal::normalize($target->get_item($item_id)->get_meta('_reduced_stock', true), 6);
		}
		sort($target_reduced, SORT_STRING);
		self::assert(array('1.000000', '2.000000') === $target_reduced, 'Active target did not receive exact reduced-stock ownership once.');
		WCOS_Mutation_Contract::assert_conserved($before, WCOS_Order_Contract_Snapshot::aggregate(array($target), 2), 2);
		self::assert($stock_before === WCOS_Order_Contract_Snapshot::product_stock($target), 'Production-enabled Merge changed physical stock.');

		$journal = WCOS_Operation_Journal::get($source, $confirmation['operation_id']);
		self::assert(is_array($journal) && 'completed' === $journal['status'], 'Production-enabled source journal is not completed.');
		self::assert(null === WCOS_Operation_Journal::get($target, $confirmation['operation_id']), 'Production-enabled Merge created a target shadow journal.');
		$pair = WCOS_Merge_Journal_Context::assert_executable_policy($journal);
		self::assert(true === $pair['retirement_policy_selected'] && 'non_force_trash_archive' === $pair['retirement_policy_identifier'], 'Production-enabled journal lost retirement authority.');
		self::assert(array('source' => true, 'target' => true) === WCOS_Merge_Participation::state_for_pair($source, $target, $confirmation['operation_id'], $pair['pair_fingerprint']), 'Production-enabled reciprocal participation is incomplete.');

		$journal_before_replay = wp_json_encode($journal);
		$source_before_replay = WCOS_Merge_Recovery_Snapshot::participant_signature($source);
		$target_before_replay = WCOS_Merge_Recovery_Snapshot::participant_signature($target);
		$replay = $controller->execute_request(array_merge($request, array(
			'operation_id' => $confirmation['operation_id'],
			'confirmation_token' => $confirmation['confirmation_token'],
		)));
		self::assert($result === $replay, 'Production-enabled durable replay did not return the original result.');
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		self::assert($journal_before_replay === wp_json_encode(WCOS_Operation_Journal::get($source, $confirmation['operation_id'])), 'Durable replay changed the journal.');
		self::assert($source_before_replay === WCOS_Merge_Recovery_Snapshot::participant_signature($source), 'Durable replay changed source state.');
		self::assert($target_before_replay === WCOS_Merge_Recovery_Snapshot::participant_signature($target), 'Durable replay changed target state.');

		WCOS_Operation_Journal::delete($source, $confirmation['operation_id']);
		WCOS_Merge_Confirmation_Store::delete($confirmation['operation_id']);
		unset(self::$operations[$source->get_id()]);
		self::$results['successful_operation'] = array(
			'controller_chain' => true,
			'conservation' => true,
			'stock_neutral' => true,
			'reciprocal_relations' => true,
			'durable_replay' => true,
		);
	}

	private static function unsupported_matrix() {
		list($same_source, $same_target) = self::pair('same');
		self::expect_review_failure('same_order', $same_source, $same_source, 'invalid_pair');
		list($source, $target) = self::pair('status', array('status' => 'on-hold'), array('status' => 'processing'));
		self::expect_review_failure('incompatible_status', $source, $target, 'preflight_incompatible_status');
		list($source, $target) = self::pair('currency', array(), array('currency' => 'EUR'));
		self::expect_review_failure('different_currency', $source, $target, 'preflight_incompatible_currency');
		list($source, $target) = self::pair('context', array(), array('email' => 'different@example.test', 'address' => '99 Other Way'));
		self::expect_review_failure('incompatible_context', $source, $target, 'preflight_incompatible_pair_context');
		list($source, $target) = self::pair('paid', array(), array('transaction_id' => 'sandbox-transaction'));
		self::expect_review_failure('paid_transaction', $source, $target, 'preflight_paid_order_unsupported');
		list($source, $target) = self::pair('refund', array(), array('refund' => true));
		self::expect_review_failure('refunded', $source, $target, 'preflight_refund_policy_missing');
		list($source, $target) = self::pair('coupon', array(), array('coupon' => true));
		self::expect_review_failure('coupon', $source, $target, 'preflight_coupon_policy_missing');
		list($source, $target) = self::pair('fee', array(), array('fee' => true));
		self::expect_review_failure('fee', $source, $target, 'preflight_fee_policy_missing');
		list($source, $target) = self::pair('source-shipping', array('shipping' => true), array());
		self::expect_review_failure('source_shipping', $source, $target, 'preflight_source_shipping_policy_missing');
		list($source, $target) = self::pair('no-source-lines', array('no_lines' => true), array());
		self::expect_review_failure('no_source_lines', $source, $target, 'preflight_no_source_lines');
	}

	private static function valid_recovery_after_errors() {
		list($source, $target) = self::pair('recoverable-valid');
		$review = WCOS_Merge_Admin_Controller::bootstrap()->review_request(self::request($source, $target));
		self::assert(!empty($review['review_id']), 'UI/transport did not recover for a later valid selection.');
		WCOS_Merge_Review_Store::delete($review['review_id']);
		self::$results['recoverability'] = array('valid_review_after_rejections' => true);
	}

	private static function expect_review_failure($label, WC_Order $source, WC_Order $target, $expected_code) {
		$source_before = WCOS_Order_Contract_Snapshot::source_signature($source);
		$target_before = WCOS_Order_Contract_Snapshot::source_signature($target);
		$error = self::expect_transport(function() use ($source, $target) {
			WCOS_Merge_Admin_Controller::bootstrap()->review_request(self::request($source, $target));
		}, $expected_code);
		$source = wc_get_order($source->get_id());
		$target = wc_get_order($target->get_id());
		self::assert($source_before === WCOS_Order_Contract_Snapshot::source_signature($source), $label . ' rejection changed source.');
		self::assert($target_before === WCOS_Order_Contract_Snapshot::source_signature($target), $label . ' rejection changed target.');
		self::assert(empty($source->get_meta(WCOS_Merge_Participation::SOURCE_OPERATION_META, false)), $label . ' rejection started source authority.');
		self::assert(empty($target->get_meta(WCOS_Merge_Participation::TARGET_OPERATION_META, false)), $label . ' rejection started target authority.');
		self::assert(strlen($error['message']) <= 240 && false === strpos($error['message'], '@example.test') && false === strpos($error['message'], 'Matrix Way'), $label . ' error is unbounded or exposes PII.');
		self::$results['unsupported'][$label] = array('code' => $error['code'], 'no_mutation' => true, 'pii_free' => true);
	}

	private static function expect_transport(callable $callback, $expected_codes) {
		$expected_codes = (array) $expected_codes;
		try {
			$callback();
		} catch (WCOS_Merge_Transport_Exception $exception) {
			self::assert(in_array($exception->get_error_code(), $expected_codes, true), 'Unexpected transport code: ' . $exception->get_error_code());
			return array('code' => $exception->get_error_code(), 'message' => $exception->getMessage());
		}
		throw new RuntimeException('Expected transport rejection did not occur: ' . implode(',', $expected_codes));
	}

	private static function change_first_line(WC_Order $order, $quantity) {
		$items = $order->get_items('line_item');
		$item = reset($items);
		self::assert($item instanceof WC_Order_Item_Product, 'Drift line unavailable.');
		$item->set_quantity($quantity);
		$item->set_subtotal((string) (10 * $quantity));
		$item->set_total((string) (10 * $quantity));
		$item->save();
		$order = wc_get_order($order->get_id());
		$order->calculate_totals(false);
		$order->save();
	}

	private static function assert($condition, $message) {
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}
}

WCOS_Merge_Production_Enabled_Matrix::run();
