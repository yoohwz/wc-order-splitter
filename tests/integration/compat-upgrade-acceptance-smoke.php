<?php

if (!defined('ABSPATH')) { exit(1); }

if (!defined('WCOS_COMPAT_007_LEDGER_LIBRARY_ONLY')) { define('WCOS_COMPAT_007_LEDGER_LIBRARY_ONLY', true); }
require_once WP_PLUGIN_DIR . '/wc-order-splitter/tests/integration/compat-upgrade-fixture-ledger.php';

/** WOS-COMPAT-007 genuine 1.4.11 -> current-candidate integrated upgrade acceptance. */
final class WCOS_Compat_Upgrade_Acceptance_Smoke {
	const FIXTURE_OPTION = 'wcos_compat_007_upgrade_fixture';
	const LEGACY_FIXTURE_OPTION = 'wcos_compat_003_genuine_1_4_11_fixture';
	const BASELINE_SHA = 'e1d8aeb8eff38f4ce69dad1a08993e17521c6359';
	const BASELINE_TREE = '75140a414cd637d134f860d8a70e7f92cbe4853c';
	private static $fixture = array();
	private static $legacy_fixture = array();
	private static $order_ids = array();
	private static $product_ids = array();
	private static $user_ids = array();
	private static $review_ids = array();
	private static $bulk_review_ids = array();
	private static $operations = array();
	private static $previous_user = 0;
	private static $operator_id = 0;
	private static $fault_point = '';

	public static function run($candidate_sha, $storage, $fault_point = '') {
		self::$fixture = get_option(self::FIXTURE_OPTION, array());
		self::$legacy_fixture = get_option(self::LEGACY_FIXTURE_OPTION, array());
		self::$previous_user = get_current_user_id();
		$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
		self::assert(!empty($admins), 'The integrated upgrade fixture requires an administrator.');
		self::$operator_id = absint($admins[0]);
		self::$fault_point = (string) $fault_point;
		self::assert(in_array(self::$fault_point, array('', 'candidate-user', 'candidate-authority', 'candidate-target'), true), 'Unknown WOS-COMPAT-007 candidate fault point.');
		wp_set_current_user(self::$operator_id);
		if ('candidate-target' === self::$fault_point) {
			add_action('wcos_duplicate_mutation_checkpoint', array(__CLASS__, 'inject_candidate_target_fault'), PHP_INT_MAX, 4);
		}

		try {
			self::authenticate_upgrade($candidate_sha, $storage);
			self::verify_upgrade_time_integrity();
			self::verify_integrity_drift_detection();
			self::verify_settings_and_access();
			self::exercise_split_and_duplicate();
			self::exercise_mixed_bulk_review();
			self::exercise_merge_boundaries();
			self::exercise_genuine_legacy_return();
			echo wp_json_encode(array(
				'status' => 'pass',
				'task' => 'WOS-COMPAT-007',
				'baseline' => self::BASELINE_SHA,
				'candidate' => $candidate_sha,
				'storage' => $storage,
				'areas' => array('upgrade_integrity', 'settings_access', 'manual_category_stock_split', 'duplicate', 'mixed_bulk_review', 'ordinary_merge', 'financial_merge', 'legacy_return'),
			), JSON_PRETTY_PRINT) . "\n";
		} finally {
			try { self::cleanup(); }
			finally { remove_action('wcos_duplicate_mutation_checkpoint', array(__CLASS__, 'inject_candidate_target_fault'), PHP_INT_MAX); }
		}
	}

	public static function inject_candidate_target_fault($stage, $source, $target, $operation_id) {
		if ('candidate-target' === self::$fault_point && 'after_target_save' === (string) $stage) {
			throw new RuntimeException('Injected WOS-COMPAT-007 candidate-target failure after target persistence.');
		}
	}

	private static function authenticate_upgrade($candidate_sha, $storage) {
		self::assert(preg_match('/^[0-9a-f]{40}$/D', $candidate_sha), 'The candidate SHA binding is invalid.');
		self::assert(in_array($storage, array('legacy', 'hpos', 'hpos-sync', 'local'), true), 'The storage-mode binding is invalid.');
		self::assert(is_plugin_active('wcos-legacy-1-4-11/wc-order-splitter.php'), 'The in-place candidate is not active at the baseline plugin path.');
		self::assert(!is_plugin_active('wc-order-splitter/wc-order-splitter.php'), 'The mapped checkout must remain inactive during the isolated in-place upgrade proof.');
		self::assert(defined('WC_ORDER_SPLITTER_VERSION') && '1.5.0' === WC_ORDER_SPLITTER_VERSION, 'The replacement candidate does not declare version 1.5.0.');
		self::assert(is_array(self::$fixture) && 1 === (int) self::$fixture['authority_schema_version'], 'The WOS-COMPAT-007 baseline fixture is unavailable.');
		self::assert(self::BASELINE_SHA === (string) self::$fixture['baseline_sha'], 'The fixture baseline SHA is not the exact public 1.4.11 source.');
		self::assert(self::BASELINE_TREE === (string) self::$fixture['baseline_tree'], 'The fixture baseline tree is not exact.');
		self::assert('1.4.11' === (string) self::$fixture['baseline_version'], 'The fixture was not created by version 1.4.11.');
		self::assert(is_array(self::$legacy_fixture) && self::BASELINE_SHA === (string) self::$legacy_fixture['baseline_sha'], 'The genuine public Split-family fixture is unavailable.');
		foreach (array(WCOS_Feature_Gates::SPLIT, WCOS_Feature_Gates::DUPLICATE, WCOS_Feature_Gates::MERGE, WCOS_Feature_Gates::RETURN_ORDER, WCOS_Feature_Gates::BULK_RETURN) as $workflow) {
			self::assert(WCOS_Feature_Gates::enabled($workflow), 'Production workflow gate drifted during upgrade: ' . $workflow);
		}
		foreach (array(WCOS_Split_Strategy_Gates::MANUAL_QUANTITY, WCOS_Split_Strategy_Gates::CATEGORY, WCOS_Split_Strategy_Gates::STOCK_STATUS) as $strategy) {
			self::assert(WCOS_Split_Strategy_Gates::enabled($strategy), 'Production Split strategy gate drifted during upgrade: ' . $strategy);
		}
		self::assert(WC_Order_Splitter_Safety_Guard::mutations_enabled(), 'The safety guard disagrees with the code-owned workflow gates.');
	}

	private static function verify_upgrade_time_integrity() {
		self::assert(isset(self::$fixture['order_ids']['legacy_source'], self::$fixture['order_ids']['legacy_child'], self::$fixture['order_ids']['tax_history']), 'The complete historical upgrade snapshot is missing.');
		foreach (self::$fixture['order_ids'] as $key => $order_id) {
			$order = wc_get_order(absint($order_id));
			self::assert($order instanceof WC_Order, 'A pre-upgrade order disappeared: ' . $key);
			self::assert(self::$fixture['order_states_before_upgrade'][$key] === self::order_state($order), 'Upgrade changed pre-existing order state before mutation: ' . $key);
			self::assert('' === (string) $order->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true), 'Upgrade forged hardened parent lineage: ' . $key);
			self::assert(empty($order->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true)), 'Upgrade forged hardened child lineage: ' . $key);
			self::assert(empty($order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true)), 'Upgrade manufactured a current journal summary: ' . $key);
		}
		foreach (self::$fixture['product_ids'] as $key => $product_id) {
			$product = wc_get_product(absint($product_id));
			self::assert($product instanceof WC_Product, 'A pre-upgrade product disappeared: ' . $key);
			self::assert(self::$fixture['physical_stock_before'][$key] === $product->get_stock_quantity(), 'Plugin replacement changed physical stock: ' . $key);
		}
		foreach (self::$fixture['options_after_seed'] as $name => $state) {
			self::assert($state === self::option_state($name), 'Plugin replacement changed a genuine 1.4.11 setting: ' . $name);
		}
		$legacy_source = wc_get_order(absint(self::$legacy_fixture['source_id']));
		$legacy_child = wc_get_order(absint(self::$legacy_fixture['child_id']));
		self::assert($legacy_source instanceof WC_Order && $legacy_child instanceof WC_Order, 'The genuine 1.4.11 Split family disappeared.');
		self::assert((string) $legacy_source->get_id() === (string) $legacy_child->get_meta('yoos_original_order', true), 'The genuine legacy reciprocal parent relation changed.');
		self::assert(in_array($legacy_child->get_id(), array_map('absint', explode(',', (string) $legacy_source->get_meta('yoos_splitted_order', true))), true), 'The genuine legacy child relation changed.');
		self::assert('' === (string) $legacy_child->get_meta(WCOS_Split_Order_Service::RELATION_PARENT_META, true), 'Upgrade rewrote legacy lineage as hardened history.');
		self::assert(!class_exists('WooCommerce_Order_Splitter_Split_Order_Set_Email_Filters', false), 'The retired global email-recipient option regained runtime authority.');
	}

	private static function expect_integrity_drift(callable $change, callable $restore, $key, $label) {
		try {
			$change();
			$rejected = false;
			try { self::verify_upgrade_time_integrity(); }
			catch (RuntimeException $exception) {
				if ('Upgrade changed pre-existing order state before mutation: ' . $key !== $exception->getMessage()) { throw $exception; }
				$rejected = true;
			}
			self::assert($rejected, 'The upgrade snapshot accepted injected drift: ' . $label);
		} finally {
			$restore();
		}
		self::verify_upgrade_time_integrity();
		echo 'compat-upgrade-integrity-drift-rejected case=' . $label . "\n";
	}

	private static function verify_integrity_drift_detection() {
		// Mutate only disposable fixture rows, then restore and re-read before operations.
		$refund = wc_get_order(absint(self::$fixture['refund_id']));
		self::assert($refund instanceof WC_Order_Refund, 'The historical refund is unavailable.');
		$amount = $refund->get_amount('edit');
		self::expect_integrity_drift(
			static function() use ($refund) { $refund->set_amount('2.01'); $refund->save(); },
			static function() use ($refund, $amount) { $refund->set_amount($amount); $refund->save(); },
			'financial_target', 'refund-amount'
		);
		$refund_items = $refund->get_items('line_item');
		$refund_line = reset($refund_items);
		self::assert($refund_line instanceof WC_Order_Item_Product, 'The historical refund line is unavailable.');
		$reference = $refund_line->get_meta('_refunded_item_id', true);
		self::assert(absint($reference) > 0, 'The historical refund reference is unavailable.');
		self::expect_integrity_drift(
			static function() use ($refund_line) { $refund_line->update_meta_data('_refunded_item_id', 0); $refund_line->save(); },
			static function() use ($refund_line, $reference) { $refund_line->update_meta_data('_refunded_item_id', $reference); $refund_line->save(); },
			'financial_target', 'refund-line-reference'
		);
		foreach (array('legacy_source', 'legacy_child') as $key) {
			$order = wc_get_order(absint(self::$fixture['order_ids'][$key]));
			$total = $order->get_total('edit');
			self::expect_integrity_drift(
				static function() use ($order) { $order->set_total('999.99'); $order->save(); },
				static function() use ($order, $total) { $order->set_total($total); $order->save(); },
				$key, $key . '-total'
			);
		}
		$tax_order = wc_get_order(absint(self::$fixture['order_ids']['tax_history']));
		$rows = array_values($tax_order->get_items('tax'));
		self::assert(2 === count($rows) && 0 < (float) $tax_order->get_total_tax('edit'), 'The historical two-rate tax fixture is empty.');
		$first = $rows[0]->get_tax_total('edit');
		$second = $rows[1]->get_tax_total('edit');
		self::expect_integrity_drift(
			static function() use ($rows) { $rows[0]->set_tax_total('1.21'); $rows[0]->save(); $rows[1]->set_tax_total('0.59'); $rows[1]->save(); },
			static function() use ($rows, $first, $second) { $rows[0]->set_tax_total($first); $rows[0]->save(); $rows[1]->set_tax_total($second); $rows[1]->save(); },
			'tax_history', 'per-rate-tax-rows'
		);
		$lines = array_values($tax_order->get_items('line_item'));
		$line = $lines[0];
		$taxes = $line->get_taxes('edit');
		self::assert(2 === count($taxes['total']), 'The historical line tax distribution is empty.');
		self::expect_integrity_drift(
			static function() use ($line, $taxes) { $taxes['total'] = array(781001 => '1.01', 781002 => '0.49'); $line->set_taxes($taxes); $line->save(); },
			static function() use ($line, $taxes) { $line->set_taxes($taxes); $line->save(); },
			'tax_history', 'per-rate-line-tax'
		);
	}

	private static function verify_settings_and_access() {
		self::assert(array('wc-pending', 'wc-processing', 'wc-on-hold') === get_option('order_splitter_status_allowed'), 'Allowed statuses did not retain their baseline value.');
		self::assert('no' === get_option('order_splitter_exclude_shipping_fee'), 'Shipping preference did not retain its baseline value.');
		self::assert('no' === get_option('order_splitter_order_label'), 'Order-label preference did not retain its baseline value.');
		self::assert('for_everyone' === get_option('order_splitter_disable_split_order_email'), 'Historical hidden email option was destructively rewritten.');
		self::assert(!in_array('order_splitter_shop_manager_permission', self::setting_ids((new WooCommerce_Order_Splitter_Settings())->get_advanced_settings()), true), 'The retired Shop Manager setting rendered after upgrade.');
		self::assert(isset(self::$fixture['shipping_samples']['yes'], self::$fixture['shipping_samples']['no']), 'The exact baseline did not record both shipping setting states.');
		self::assert(isset(self::$fixture['email_samples']['none'], self::$fixture['email_samples']['for_customers'], self::$fixture['email_samples']['for_administrators'], self::$fixture['email_samples']['for_everyone']), 'The exact baseline did not record the historical hidden email values.');
		$policy_source = wc_get_order(absint(self::$fixture['order_ids']['manual_source']));
		foreach (array('yes' => WCOS_Split_Commercial_Policy::SHIPPING_KEEP_ON_SOURCE, 'no' => WCOS_Split_Commercial_Policy::SHIPPING_REPLICATE_TO_EACH_CHILD) as $value => $expected) {
			update_option('order_splitter_exclude_shipping_fee', $value);
			self::assert($expected === WCOS_Split_Commercial_Policy::freeze($policy_source)['shipping'], 'Upgraded shipping setting lost its reviewed meaning: ' . $value);
		}
		update_option('order_splitter_exclude_shipping_fee', 'no');

		$manager_id = wp_insert_user(array(
			'user_login' => 'wcos_compat_007_manager_' . wp_generate_password(8, false),
			'user_pass' => wp_generate_password(24, true),
			'user_email' => 'wos-compat-007-manager-' . wp_generate_uuid4() . '@example.test',
			'role' => 'shop_manager',
		));
		self::assert(!is_wp_error($manager_id), 'Unable to create the Shop Manager access fixture after upgrade.');
		self::$user_ids[] = absint($manager_id);
		wcos_compat_007_ledger_remember('user', $manager_id);
		if ('candidate-user' === self::$fault_point) { throw new RuntimeException('Injected WOS-COMPAT-007 candidate-user failure.'); }
		$subscriber_id = wp_insert_user(array(
			'user_login' => 'wcos_compat_007_subscriber_' . wp_generate_password(8, false),
			'user_pass' => wp_generate_password(24, true),
			'user_email' => 'wos-compat-007-subscriber-' . wp_generate_uuid4() . '@example.test',
			'role' => 'subscriber',
		));
		self::assert(!is_wp_error($subscriber_id), 'Unable to create the Subscriber access fixture after upgrade.');
		self::$user_ids[] = absint($subscriber_id);
		wcos_compat_007_ledger_remember('user', $subscriber_id);
		foreach (array('yes', 'no', 'absent') as $state) {
			self::assert(isset(self::$fixture['shop_manager_samples'][$state]), 'The baseline did not record Shop Manager setting state: ' . $state);
			if ('absent' === $state) { delete_option('order_splitter_shop_manager_permission'); }
			else { update_option('order_splitter_shop_manager_permission', $state); }
			foreach (array(self::$operator_id, absint($manager_id)) as $user_id) {
				wp_set_current_user($user_id);
				WCOS_Order_Mutation_Authorizer::assert_operator();
			}
			wp_set_current_user(absint($subscriber_id));
			$denied = false;
			try { WCOS_Order_Mutation_Authorizer::assert_operator(); }
			catch (RuntimeException $exception) { $denied = true; }
			self::assert($denied, 'A lower-privilege role gained authority for legacy option state: ' . $state);
		}
		wp_set_current_user(self::$operator_id);
		update_option('order_splitter_shop_manager_permission', 'yes');
	}

	private static function exercise_split_and_duplicate() {
		$gateway = new WCOS_Mutation_Gateway();
		$manual_source = wc_get_order(absint(self::$fixture['order_ids']['manual_source']));
		$manual_item_id = absint(self::$fixture['line_ids']['manual_source']);
		$stock_before = wc_get_product(absint(self::$fixture['product_ids']['managed']))->get_stock_quantity();
		$reduced_before = WCOS_Order_Contract_Snapshot::aggregate(array($manual_source))['stock_reduced'];
		$manual_preflight = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($manual_source);
		self::assert(!empty($manual_preflight['supported']), 'The upgraded historical Manual Split source was rejected: ' . wp_json_encode($manual_preflight));
		$manual_created = WCOS_Split_Confirmation_Store::create($manual_source, array('upgrade-manual-child' => array($manual_item_id => '2.000000')), $manual_preflight, self::$operator_id);
		self::remember_operation('split', $manual_source->get_id(), $manual_created['operation_id']);
		$manual_verified = WCOS_Split_Confirmation_Store::verify($manual_source, $manual_created['operation_id'], $manual_created['confirmation_token'], self::$operator_id);
		$manual_children = $gateway->split_manual_confirmed(wc_get_order($manual_source->get_id()), $manual_verified['plan'], $manual_verified['operation_id'], $manual_verified['price_precision'], $manual_verified);
		self::remember_orders($manual_children);
		self::assert(1 === count($manual_children), 'The upgraded historical Manual Split did not create one child.');
		$manual_child = reset($manual_children);
		$manual_replay_authority = WCOS_Split_Confirmation_Store::verify(wc_get_order($manual_source->get_id()), $manual_created['operation_id'], $manual_created['confirmation_token'], self::$operator_id);
		$manual_replay = $gateway->split_manual_confirmed(wc_get_order($manual_source->get_id()), $manual_replay_authority['plan'], $manual_replay_authority['operation_id'], $manual_replay_authority['price_precision'], $manual_replay_authority);
		self::assert($manual_child->get_id() === reset($manual_replay)->get_id(), 'Manual Split response-loss replay created a different child.');
		self::assert($stock_before === wc_get_product(absint(self::$fixture['product_ids']['managed']))->get_stock_quantity(), 'Manual Split changed physical stock after upgrade.');
		self::assert($reduced_before === WCOS_Order_Contract_Snapshot::aggregate(array(wc_get_order($manual_source->get_id()), wc_get_order($manual_child->get_id())))['stock_reduced'], 'Manual Split changed family _reduced_stock ownership.');

		$category_source = wc_get_order(absint(self::$fixture['order_ids']['category_source']));
		$category_review = WCOS_Category_Split_Planner::review($category_source);
		self::assert(!empty($category_review['supported']), 'Category Review rejected a pre-upgrade order: ' . wp_json_encode($category_review));
		$category_created = WCOS_Split_Strategy_Confirmation_Store::create($category_source, WCOS_Split_Strategy_Gates::CATEGORY, $category_review, 'category-' . absint(self::$fixture['term_id']), self::$operator_id);
		self::remember_operation('strategy', $category_source->get_id(), $category_created['operation_id']);
		$category_verified = WCOS_Split_Strategy_Confirmation_Store::verify($category_source, $category_created['operation_id'], $category_created['confirmation_token'], self::$operator_id);
		$category_children = $gateway->split_strategy(wc_get_order($category_source->get_id()), WCOS_Split_Strategy_Gates::CATEGORY, $category_verified['plan'], $category_verified['operation_id'], $category_verified['price_precision'], $category_verified);
		self::remember_orders($category_children);
		self::assert(1 === count($category_children), 'Category Split failed on a pre-upgrade order.');

		$stock_source = wc_get_order(absint(self::$fixture['order_ids']['stock_source']));
		$stock_review = WCOS_Stock_Status_Split_Planner::review($stock_source);
		self::assert(!empty($stock_review['supported']), 'Stock-status Review rejected a pre-upgrade order: ' . wp_json_encode($stock_review));
		$stock_created = WCOS_Split_Strategy_Confirmation_Store::create($stock_source, WCOS_Split_Strategy_Gates::STOCK_STATUS, $stock_review, 'stock-instock', self::$operator_id);
		self::remember_operation('strategy', $stock_source->get_id(), $stock_created['operation_id']);
		$stock_verified = WCOS_Split_Strategy_Confirmation_Store::verify($stock_source, $stock_created['operation_id'], $stock_created['confirmation_token'], self::$operator_id);
		$stock_children = $gateway->split_strategy(wc_get_order($stock_source->get_id()), WCOS_Split_Strategy_Gates::STOCK_STATUS, $stock_verified['plan'], $stock_verified['operation_id'], $stock_verified['price_precision'], $stock_verified);
		self::remember_orders($stock_children);
		self::assert(1 === count($stock_children), 'Stock-status Split failed on a pre-upgrade order.');

		$new_order = wc_create_order(array('status' => 'pending'));
		self::assert($new_order instanceof WC_Order, 'Unable to create the post-upgrade standalone order.');
		self::remember_order($new_order->get_id());
		$new_item_id = $new_order->add_product(wc_get_product(absint(self::$fixture['product_ids']['commercial'])), 2);
		$new_order->calculate_totals(false);
		$new_order->save();
		$new_order = wc_get_order($new_order->get_id());
		$new_preflight = (new WCOS_Split_WooCommerce_Adapter())->manual_preflight($new_order);
		self::assert(!empty($new_preflight['supported']), 'Manual Review rejected a post-upgrade order: ' . wp_json_encode($new_preflight));
		$new_created = WCOS_Split_Confirmation_Store::create($new_order, array('post-upgrade-child' => array($new_item_id => '1.000000')), $new_preflight, self::$operator_id);
		self::remember_operation('split', $new_order->get_id(), $new_created['operation_id']);
		$new_verified = WCOS_Split_Confirmation_Store::verify($new_order, $new_created['operation_id'], $new_created['confirmation_token'], self::$operator_id);
		$new_children = $gateway->split_manual_confirmed(wc_get_order($new_order->get_id()), $new_verified['plan'], $new_verified['operation_id'], $new_verified['price_precision'], $new_verified);
		self::remember_orders($new_children);
		self::assert(1 === count($new_children), 'Manual Split failed on a post-upgrade order.');

		$duplicate_source = wc_get_order(absint(self::$fixture['order_ids']['duplicate_source']));
		$duplicate_before = self::order_state($duplicate_source);
		$duplicate_controller = new WCOS_Duplicate_Admin_Controller();
		$duplicate_request = array('order_id' => $duplicate_source->get_id(), 'nonce' => wp_create_nonce('wcos_duplicate_order_' . $duplicate_source->get_id()));
		$duplicate_review = $duplicate_controller->review_request($duplicate_request);
		self::remember_operation('duplicate', $duplicate_source->get_id(), $duplicate_review['operation_id']);
		if ('candidate-authority' === self::$fault_point) { throw new RuntimeException('Injected WOS-COMPAT-007 candidate-authority failure.'); }
		$duplicate_result = $duplicate_controller->execute_request(array_merge($duplicate_request, array('operation_id' => $duplicate_review['operation_id'], 'confirmation_token' => $duplicate_review['confirmation_token'])));
		self::remember_order(isset($duplicate_result['target']['id']) ? $duplicate_result['target']['id'] : 0);
		$duplicate_target = wc_get_order(absint($duplicate_result['target']['id']));
		self::assert($duplicate_target instanceof WC_Order && 'pending' === $duplicate_target->get_status(), 'Duplicate did not retain the intentional pending future-creation policy.');
		self::assert('' === (string) $duplicate_target->get_transaction_id() && !$duplicate_target->get_date_paid(), 'Duplicate copied historical payment authority.');
		self::assert($duplicate_before === self::order_state(wc_get_order($duplicate_source->get_id())), 'Duplicate rewrote the pre-existing source.');
		$duplicate_replay = $duplicate_controller->execute_request(array_merge($duplicate_request, array('operation_id' => $duplicate_review['operation_id'], 'confirmation_token' => $duplicate_review['confirmation_token'])));
		self::assert(absint($duplicate_result['target']['id']) === absint($duplicate_replay['target']['id']), 'Duplicate response-loss replay created another target.');
	}

	private static function exercise_mixed_bulk_review() {
		$legacy_child = wc_get_order(absint(self::$legacy_fixture['child_id']));
		$manual_source = wc_get_order(absint(self::$fixture['order_ids']['manual_source']));
		$current_child_ids = array_map('absint', (array) $manual_source->get_meta(WCOS_Split_Order_Service::RELATION_CHILDREN_META, true));
		$current_child = wc_get_order(reset($current_child_ids));
		self::assert($legacy_child instanceof WC_Order && $current_child instanceof WC_Order, 'Mixed-lineage Bulk Return fixtures are unavailable.');
		$current_child->set_status('cancelled');
		$current_child->save();
		$review = WCOS_Bulk_Return_Review_Store::create(array($legacy_child->get_id(), $current_child->get_id()), self::$operator_id);
		self::remember_review('bulk-review', $review['review_id'], $current_child->get_id());
		self::assert(1 === (int) $review['plan']['eligible_count'] && 1 === (int) $review['plan']['skipped_count'], 'Mixed legacy/current Bulk Return Review did not disclose Eligible plus Skipped.');
		self::assert(empty($legacy_child->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true)), 'Read-only Bulk Return Review created a legacy-child journal.');
		$current_child->set_status('pending');
		$current_child->save();
	}

	private static function exercise_merge_boundaries() {
		$ordinary_source = wc_get_order(absint(self::$fixture['order_ids']['merge_source']));
		$ordinary_target = wc_get_order(absint(self::$fixture['order_ids']['merge_target']));
		$target_status = $ordinary_target->get_status();
		$target_context = array($ordinary_target->get_billing_email(), $ordinary_target->get_payment_method());
		list($ordinary_review, $ordinary_confirmation, $ordinary_result) = self::execute_merge($ordinary_source, $ordinary_target);
		self::assert('completed' === $ordinary_result['status'], 'Ordinary Merge did not complete on upgraded historical orders.');
		self::assert($target_status === wc_get_order($ordinary_target->get_id())->get_status(), 'Ordinary Merge changed target status authority.');
		self::assert($target_context === array(wc_get_order($ordinary_target->get_id())->get_billing_email(), wc_get_order($ordinary_target->get_id())->get_payment_method()), 'Ordinary Merge changed target order-level context.');
		self::assert(1 === count(wc_get_order($ordinary_source->get_id())->get_items('shipping')) && 2 === count(wc_get_order($ordinary_source->get_id())->get_items('fee')) && 1 === count(wc_get_order($ordinary_source->get_id())->get_items('coupon')), 'Ordinary Merge moved source-owned charges.');
		$ordinary_replay = WCOS_Merge_Admin_Controller::bootstrap()->execute_request(array(
			'source_order_id' => $ordinary_source->get_id(),
			'target_order_id' => $ordinary_target->get_id(),
			'nonce' => wp_create_nonce('wcos_merge_order_' . $ordinary_source->get_id()),
			'operation_id' => $ordinary_confirmation['operation_id'],
			'confirmation_token' => $ordinary_confirmation['confirmation_token'],
		));
		self::assert($ordinary_result['target_order_id'] === $ordinary_replay['target_order_id'], 'Ordinary Merge replay changed the target.');

		$financial_source = wc_get_order(absint(self::$fixture['order_ids']['financial_neutral_source']));
		$financial_target = wc_get_order(absint(self::$fixture['order_ids']['financial_target']));
		$financial_before = array(
			'status' => $financial_target->get_status(),
			'transaction_id' => $financial_target->get_transaction_id(),
			'date_paid' => $financial_target->get_date_paid()->getTimestamp(),
			'total' => (string) $financial_target->get_total(),
			'total_tax' => (string) $financial_target->get_total_tax(),
			'refund_ids' => self::refund_ids($financial_target),
			'refund_states' => self::refund_states($financial_target),
		);
		list($financial_review, $financial_confirmation, $financial_result) = self::execute_merge($financial_source, $financial_target);
		self::assert('completed' === $financial_result['status'] && !empty($financial_review['summary']['target_financial_history_retained']), 'Financial-target neutral Merge did not complete with explicit retained authority.');
		$financial_target = wc_get_order($financial_target->get_id());
		$financial_after = array(
			'status' => $financial_target->get_status(),
			'transaction_id' => $financial_target->get_transaction_id(),
			'date_paid' => $financial_target->get_date_paid()->getTimestamp(),
			'total' => (string) $financial_target->get_total(),
			'total_tax' => (string) $financial_target->get_total_tax(),
			'refund_ids' => self::refund_ids($financial_target),
			'refund_states' => self::refund_states($financial_target),
		);
		self::assert($financial_before === $financial_after, 'Financial-target Merge changed payment/refund/status/payable authority.');

		foreach (array('financial_nonzero_source', 'financial_history_source') as $blocked_key) {
			$blocked_source = wc_get_order(absint(self::$fixture['order_ids'][$blocked_key]));
			$blocked = false;
			try {
				WCOS_Merge_Admin_Controller::bootstrap()->review_request(array(
					'source_order_id' => $blocked_source->get_id(),
					'target_order_id' => $financial_target->get_id(),
					'nonce' => wp_create_nonce('wcos_merge_order_' . $blocked_source->get_id()),
				));
			} catch (WCOS_Merge_Transport_Exception $exception) {
				$blocked = 0 === strpos($exception->get_error_code(), 'preflight_');
			}
			self::assert($blocked, 'Financial Merge boundary accepted blocked source: ' . $blocked_key);
		}
	}

	private static function exercise_genuine_legacy_return() {
		$source = wc_get_order(absint(self::$legacy_fixture['source_id']));
		$child = wc_get_order(absint(self::$legacy_fixture['child_id']));
		$before = array('source' => self::order_state($source), 'child' => self::order_state($child));
		$controller = WCOS_Return_Admin_Controller::bootstrap();
		$request = array('child_order_id' => $child->get_id(), 'nonce' => wp_create_nonce('wcos_return_order_' . $child->get_id()));
		$review = $controller->review_request($request);
		self::remember_review('return-review', $review['review_id'], $child->get_id());
		self::assert($before === array('source' => self::order_state(wc_get_order($source->get_id())), 'child' => self::order_state(wc_get_order($child->get_id()))), 'Legacy Return Review eagerly rewrote historical orders.');
		self::assert(!empty($review['summary']['compatibility']['legacy_1_4_11_detected']), 'Legacy Return Review did not disclose exact compatibility provenance.');
		$confirmation = $controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
		self::remember_operation('return', $child->get_id(), $confirmation['operation_id']);
		$result = $controller->execute_request(array_merge($request, array('operation_id' => $confirmation['operation_id'], 'confirmation_token' => $confirmation['confirmation_token'])));
		self::assert('completed' === $result['status'], 'Genuine 1.4.11 child Return did not complete through the hardened controller.');
	}

	private static function execute_merge(WC_Order $source, WC_Order $target) {
		$controller = WCOS_Merge_Admin_Controller::bootstrap();
		$request = array(
			'source_order_id' => $source->get_id(),
			'target_order_id' => $target->get_id(),
			'nonce' => wp_create_nonce('wcos_merge_order_' . $source->get_id()),
		);
		$review = $controller->review_request($request);
		self::remember_review('merge-review', $review['review_id'], $source->get_id());
		$confirmation = $controller->confirm_request(array_merge($request, array('review_id' => $review['review_id'], 'review_token' => $review['review_token'])));
		self::remember_operation('merge', $source->get_id(), $confirmation['operation_id']);
		$result = $controller->execute_request(array_merge($request, array('operation_id' => $confirmation['operation_id'], 'confirmation_token' => $confirmation['confirmation_token'])));
		return array($review, $confirmation, $result);
	}

	private static function remember_operation($type, $order_id, $operation_id) {
		$operation = array('type' => sanitize_key((string) $type), 'order_id' => absint($order_id), 'operation_id' => sanitize_key((string) $operation_id));
		self::$operations[] = $operation;
		wcos_compat_007_ledger_remember_authority($operation['type'], $operation['operation_id'], $operation['order_id']);
	}

	private static function remember_review($type, $review_id, $order_id) {
		$type = sanitize_key((string) $type);
		$review_id = sanitize_key((string) $review_id);
		if ('bulk-review' === $type) { self::$bulk_review_ids[] = $review_id; }
		else { self::$review_ids[] = array('type' => str_replace('-review', '', $type), 'id' => $review_id); }
		wcos_compat_007_ledger_remember_authority($type, $review_id, $order_id);
	}

	private static function remember_order($order_id) {
		$order_id = absint($order_id);
		self::assert($order_id > 0, 'A candidate-created order did not expose a durable ID.');
		self::$order_ids[] = $order_id;
		wcos_compat_007_ledger_remember('order', $order_id);
	}

	private static function remember_orders($orders) {
		foreach ((array) $orders as $order) {
			if ($order instanceof WC_Order) { self::remember_order($order->get_id()); }
		}
	}

	private static function option_state($name) {
		$missing = '__wcos_compat_007_missing_option__';
		$value = get_option($name, $missing);
		return array('exists' => $missing !== $value, 'value' => $missing !== $value ? $value : null);
	}

	private static function setting_ids(array $settings) {
		$ids = array();
		foreach ($settings as $setting) { if (is_array($setting) && isset($setting['id'])) { $ids[] = (string) $setting['id']; } }
		return $ids;
	}

	private static function item_meta(WC_Data $item) {
		$meta = array();
		foreach ($item->get_meta_data() as $entry) { $data = $entry->get_data(); $meta[] = array('key' => (string) $data['key'], 'value' => $data['value']); }
		return $meta;
	}

	private static function item_state(WC_Order_Item $item) {
		$state = array('id' => absint($item->get_id()), 'type' => (string) $item->get_type(), 'name' => (string) $item->get_name(), 'meta' => self::item_meta($item));
		if ($item instanceof WC_Order_Item_Product) {
			$state += array('product_id' => absint($item->get_product_id()), 'variation_id' => absint($item->get_variation_id()), 'quantity' => (string) $item->get_quantity(), 'subtotal' => (string) $item->get_subtotal(), 'total' => (string) $item->get_total(), 'subtotal_tax' => (string) $item->get_subtotal_tax(), 'total_tax' => (string) $item->get_total_tax(), 'taxes' => $item->get_taxes());
		} elseif ($item instanceof WC_Order_Item_Shipping) {
			$state += array('method_id' => (string) $item->get_method_id(), 'instance_id' => absint($item->get_instance_id()), 'total' => (string) $item->get_total(), 'total_tax' => (string) $item->get_total_tax(), 'taxes' => $item->get_taxes());
		} elseif ($item instanceof WC_Order_Item_Fee) {
			$state += array('amount' => (string) $item->get_amount(), 'total' => (string) $item->get_total(), 'total_tax' => (string) $item->get_total_tax(), 'taxes' => $item->get_taxes());
		} elseif ($item instanceof WC_Order_Item_Coupon) {
			$state += array('code' => (string) $item->get_code(), 'discount' => (string) $item->get_discount(), 'discount_tax' => (string) $item->get_discount_tax());
		} elseif ($item instanceof WC_Order_Item_Tax) {
			$state += array('rate_id' => absint($item->get_rate_id()), 'tax_total' => (string) $item->get_tax_total(), 'shipping_tax_total' => (string) $item->get_shipping_tax_total());
		}
		return $state;
	}

	private static function refund_ids(WC_Order $order) {
		$ids = array_map(static function($refund) { return absint($refund->get_id()); }, $order->get_refunds());
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private static function refund_states(WC_Order $order) {
		$states = array();
		foreach ($order->get_refunds() as $refund) { $states[$refund->get_id()] = self::order_state($refund); }
		ksort($states, SORT_NUMERIC);
		return $states;
	}

	private static function order_state(WC_Abstract_Order $order) {
		$items = array();
		foreach (array('line_item', 'shipping', 'fee', 'coupon', 'tax') as $type) { foreach ($order->get_items($type) as $item) { $items[] = self::item_state($item); } }
		usort($items, static function($left, $right) { return $left['id'] <=> $right['id']; });
		if ($order instanceof WC_Order_Refund) {
			return array(
				'id' => absint($order->get_id()), 'parent_id' => absint($order->get_parent_id('edit')),
				'currency' => (string) $order->get_currency('edit'), 'status' => (string) $order->get_status('edit'),
				'amount' => (string) $order->get_amount('edit'), 'reason' => (string) $order->get_reason('edit'),
				'refunded_by' => absint($order->get_refunded_by('edit')), 'refunded_payment' => (bool) $order->get_refunded_payment('edit'),
				'total' => (string) $order->get_total('edit'), 'total_tax' => (string) $order->get_total_tax('edit'),
				'shipping_total' => (string) $order->get_shipping_total('edit'), 'shipping_tax' => (string) $order->get_shipping_tax('edit'),
				'cart_tax' => (string) $order->get_cart_tax('edit'), 'meta' => self::item_meta($order), 'items' => $items,
			);
		}
		return array(
			'id' => absint($order->get_id()), 'status' => (string) $order->get_status(), 'currency' => (string) $order->get_currency(), 'prices_include_tax' => (bool) $order->get_prices_include_tax(),
			'payment_method' => (string) $order->get_payment_method(), 'transaction_id' => (string) $order->get_transaction_id(), 'date_paid' => $order->get_date_paid() ? (int) $order->get_date_paid()->getTimestamp() : null,
			'total' => (string) $order->get_total(), 'total_tax' => (string) $order->get_total_tax(), 'discount_total' => (string) $order->get_discount_total(), 'discount_tax' => (string) $order->get_discount_tax(),
			'shipping_total' => (string) $order->get_shipping_total(), 'shipping_tax' => (string) $order->get_shipping_tax(), 'cart_tax' => (string) $order->get_cart_tax(), 'refund_ids' => self::refund_ids($order), 'refund_states' => self::refund_states($order), 'meta' => self::item_meta($order), 'items' => $items,
		);
	}

	private static function restore_options() {
		if (empty(self::$fixture['options_before']) || !is_array(self::$fixture['options_before'])) { return; }
		foreach (self::$fixture['options_before'] as $name => $state) {
			if (!empty($state['exists'])) { update_option($name, array_key_exists('value', $state) ? $state['value'] : null); }
			else { delete_option($name); }
		}
		foreach (isset(self::$legacy_fixture['settings_before']) ? (array) self::$legacy_fixture['settings_before'] : array() as $name => $state) {
			if (!is_array($state)) { continue; }
			if (!empty($state['exists'])) { update_option($name, array_key_exists('value', $state) ? $state['value'] : null); }
			else { delete_option($name); }
		}
	}

	private static function cleanup() {
		wp_set_current_user(self::$operator_id);
		$ledger = wcos_compat_007_ledger_get(true);
		$all_order_ids = array_merge(self::$order_ids, isset($ledger['order_ids']) ? (array) $ledger['order_ids'] : array());
		if (!empty(self::$fixture['order_ids'])) { $all_order_ids = array_merge($all_order_ids, array_values(self::$fixture['order_ids'])); }
		if (!empty(self::$fixture['refund_id'])) { $all_order_ids[] = self::$fixture['refund_id']; }
		if (!empty(self::$legacy_fixture)) {
			$all_order_ids[] = isset(self::$legacy_fixture['source_id']) ? self::$legacy_fixture['source_id'] : 0;
			$all_order_ids[] = isset(self::$legacy_fixture['child_id']) ? self::$legacy_fixture['child_id'] : 0;
		}
		$all_order_ids = wcos_compat_007_ledger_related_order_ids($all_order_ids, $ledger);
		wcos_compat_007_ledger_delete_authorities($ledger);
		foreach (self::$review_ids as $review) {
			if ('merge' === $review['type']) { WCOS_Merge_Review_Store::delete($review['id']); }
			elseif ('return' === $review['type']) { WCOS_Return_Review_Store::delete($review['id']); }
		}
		foreach (self::$bulk_review_ids as $review_id) { WCOS_Bulk_Return_Review_Store::delete($review_id); }
		foreach (self::$operations as $operation) {
			if ('split' === $operation['type']) { WCOS_Split_Confirmation_Store::delete($operation['operation_id']); }
			elseif ('strategy' === $operation['type']) { WCOS_Split_Strategy_Confirmation_Store::delete($operation['operation_id']); }
			elseif ('duplicate' === $operation['type']) { WCOS_Duplicate_Confirmation_Store::delete($operation['operation_id']); }
			elseif ('merge' === $operation['type']) { WCOS_Merge_Confirmation_Store::delete($operation['operation_id']); }
			elseif ('return' === $operation['type']) { WCOS_Return_Confirmation_Store::delete($operation['operation_id']); }
			$order = wc_get_order($operation['order_id']);
			if ($order instanceof WC_Order) { WCOS_Operation_Journal::delete($order, $operation['operation_id']); }
		}

		foreach (array_values(array_unique(array_filter(array_map('absint', $all_order_ids)))) as $order_id) {
			$order = wc_get_order($order_id);
			if (!$order instanceof WC_Order) { continue; }
			foreach ((array) $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true) as $entry) {
				if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); }
			}
			$order->delete(true);
		}
		if (!empty(self::$fixture['product_ids'])) { self::$product_ids = array_merge(self::$product_ids, array_values(self::$fixture['product_ids'])); }
		if (!empty(self::$legacy_fixture)) {
			self::$product_ids[] = isset(self::$legacy_fixture['moved_product_id']) ? self::$legacy_fixture['moved_product_id'] : 0;
			self::$product_ids[] = isset(self::$legacy_fixture['keep_product_id']) ? self::$legacy_fixture['keep_product_id'] : 0;
		}
		foreach (array_values(array_unique(array_filter(array_map('absint', self::$product_ids)))) as $product_id) {
			$product = wc_get_product($product_id);
			if ($product instanceof WC_Product) { $product->delete(true); }
		}
		if (!empty(self::$fixture['term_id'])) { wp_delete_term(absint(self::$fixture['term_id']), 'product_cat'); }
		foreach (self::$user_ids as $user_id) {
			if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
			wp_delete_user($user_id);
		}
		wcos_compat_007_ledger_assert_authorities_absent($ledger);
		self::restore_options();
		delete_option(self::FIXTURE_OPTION);
		delete_option(self::LEGACY_FIXTURE_OPTION);
		wp_set_current_user(self::$previous_user);
	}

	private static function assert($condition, $message) {
		if (!$condition) { throw new RuntimeException($message); }
	}
}

if (defined('WCOS_COMPAT_007_ACCEPTANCE_LIBRARY_ONLY')) { return; }

$arguments = isset($args) && is_array($args) ? array_values($args) : array();
WCOS_Compat_Upgrade_Acceptance_Smoke::run(
	isset($arguments[0]) ? (string) $arguments[0] : '',
	isset($arguments[1]) ? (string) $arguments[1] : '',
	isset($arguments[2]) ? (string) $arguments[2] : ''
);
