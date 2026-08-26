<?php

if (!defined('ABSPATH')) { exit(1); }

function wcos_bulk_fail_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

function wcos_bulk_fail_fixture($label) {
	$product = new WC_Product_Simple();
	$product->set_name('WCOS Bulk fail-stop ' . $label); $product->set_regular_price('7.25'); $product->set_price('7.25'); $product->set_manage_stock(false);
	wcos_bulk_fail_assert($product->save() > 0, 'Bulk fail-stop product could not be saved.');
	$original = wc_create_order(); $original->set_status('pending'); $original->set_currency('USD'); $original->set_prices_include_tax(false);
	$item_id = $original->add_product($product, 2); $original->calculate_totals(false); $original->save();
	$children = (new WCOS_Mutation_Gateway())->split(wc_get_order($original->get_id()), array('bulk-fail-child' => array($item_id => '1.000000')), 'bulk-fail-split-' . wp_generate_uuid4(), 2);
	wcos_bulk_fail_assert(1 === count($children), 'Bulk fail-stop Split did not create one child.');
	return array('product_id' => $product->get_id(), 'original_id' => $original->get_id(), 'child_id' => $children[0]->get_id());
}

function wcos_bulk_fail_cleanup(array $fixtures) {
	foreach ($fixtures as $fixture) {
		foreach (array($fixture['child_id'], $fixture['original_id']) as $order_id) {
			$order = wc_get_order($order_id); if (!$order instanceof WC_Order) { continue; }
			$summary = $order->get_meta(WCOS_Operation_Journal::SUMMARY_META_KEY, true);
			foreach (is_array($summary) ? $summary : array() as $entry) { if (!empty($entry['operation_id'])) { WCOS_Operation_Journal::delete($order, $entry['operation_id']); } }
			delete_option('wcos_manual_reconcile_block_' . $order->get_id()); $order->delete(true);
		}
		$product = wc_get_product($fixture['product_id']); if ($product instanceof WC_Product) { $product->delete(true); }
	}
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wcos_bulk_fail_assert(!empty($admins), 'Bulk fail-stop smoke requires an administrator.');
$operator_id = absint($admins[0]); wp_set_current_user($operator_id);
$fixtures = array();

try {
	foreach (array('a', 'b', 'c') as $label) { $fixtures[] = wcos_bulk_fail_fixture($label); }
	$stored = WCOS_Bulk_Return_Review_Store::create(array_column($fixtures, 'child_id'), $operator_id);
	$confirmed = WCOS_Bulk_Return_Confirmation_Store::create($stored['review_id'], $stored['review_token'], $operator_id);
	$coordinator = WCOS_Bulk_Return_Confirmation_Store::verify($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id);
	$plan = $coordinator['verified']['authority']['plan'];
	$mapping = $coordinator['verified']['authority']['operation_map'];
	$orchestrator = new WCOS_Bulk_Return_Orchestrator();

	$first = $orchestrator->advance($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id, 0);
	wcos_bulk_fail_assert(1 === $first['cursor'] && 1 === $first['counts']['completed'], 'Bulk fail-stop first row did not complete.');
	$first_child = wc_get_order($plan['rows'][0]['child_order_id']);
	$first_signature = WCOS_Order_Contract_Snapshot::source_signature($first_child);

	$second_child = wc_get_order($plan['rows'][1]['child_order_id']);
	$second_items = $second_child->get_items('line_item');
	$second_item = reset($second_items);
	$second_item->set_total(WCOS_Decimal::from_units(WCOS_Decimal::to_units($second_item->get_total(), 2) + 1, 2));
	$second_item->save(); $second_child->save();

	$stopped = $orchestrator->advance($confirmed['batch_id'], $confirmed['anchor_child_id'], $confirmed['batch_token'], $operator_id, 1);
	wcos_bulk_fail_assert('blocked' === $stopped['status'] && 3 === $stopped['cursor'], 'Bulk Return did not stop after the first non-success row.');
	wcos_bulk_fail_assert(1 === $stopped['counts']['completed'] && 1 === $stopped['counts']['blocked'] && 1 === $stopped['counts']['not_run_blocked'], 'Bulk Return fail-stop aggregate is incorrect.');
	wcos_bulk_fail_assert($first_signature === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($plan['rows'][0]['child_order_id'])), 'Bulk Return reversed a completed sibling after later failure.');
	wcos_bulk_fail_assert(null === WCOS_Operation_Journal::get(wc_get_order($plan['rows'][1]['child_order_id']), $mapping[1]['operation_id']), 'Authority-drifted row created a child Return journal.');
	wcos_bulk_fail_assert(null === WCOS_Operation_Journal::get(wc_get_order($plan['rows'][2]['child_order_id']), $mapping[2]['operation_id']), 'not_run_blocked row created a child Return journal.');

	echo "bulk-return-fail-stop-ok originals=3 completed=1 blocked=1 not_run=1 rollback=0\n";
} finally {
	wcos_bulk_fail_cleanup($fixtures);
}
