<?php

if (!defined('ABSPATH')) { exit(1); }
function wcos_bulk_execute_post_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$fixture = get_option('wcos_bulk_return_execute_race_fixture', array());
wcos_bulk_execute_post_assert(isset($fixture['batches'][0], $fixture['batches'][1]), 'Bulk Execute race fixture is unavailable.');
wp_set_current_user(absint($fixture['user_id'])); $statuses = array(); $completed_child_journals = 0;
foreach ($fixture['batches'] as $batch) {
	$result = (new WCOS_Mutation_Gateway())->bulk_return_advance($batch['batch_id'], $batch['anchor_child_id'], $batch['batch_token'], absint($fixture['user_id']), 0);
	$statuses[] = sanitize_key((string) $result['status']);
	$coordinator = WCOS_Operation_Journal::get(wc_get_order($batch['anchor_child_id']), $batch['batch_id']);
	$verified = WCOS_Bulk_Return_Journal_Context::assert_record($coordinator);
	$operation_id = $verified['authority']['operation_map'][0]['operation_id'];
	$child_journal = WCOS_Operation_Journal::get(wc_get_order($fixture['child_id']), $operation_id);
	if (is_array($child_journal) && 'completed' === sanitize_key((string) $child_journal['status'])) { $completed_child_journals++; }
}
sort($statuses, SORT_STRING);
wcos_bulk_execute_post_assert(array('blocked', 'completed') === $statuses, 'Overlapping Bulk Return batches did not resolve to one completed and one blocked coordinator.');
wcos_bulk_execute_post_assert(1 === $completed_child_journals, 'Overlapping Bulk Return batches produced more than one completed child Return journal.');
$child = wc_get_order($fixture['child_id']); $original = wc_get_order($fixture['original_id']); $product = wc_get_product($fixture['product_id']);
wcos_bulk_execute_post_assert($child instanceof WC_Order && 'trash' === $child->get_status(), 'Overlapping Bulk Return did not preserve one retired child history.');
wcos_bulk_execute_post_assert($original instanceof WC_Order && 'trash' !== $original->get_status(), 'Overlapping Bulk Return retired the original.');
wcos_bulk_execute_post_assert($product instanceof WC_Product && $fixture['product_stock_before'] === WCOS_Decimal::normalize($product->get_stock_quantity(), 6), 'Overlapping Bulk Return changed physical stock.');
echo "bulk-return-execute-race-ok coordinators=2 completed=1 blocked=1 child_completed_journals=1 stock=neutral\n";
