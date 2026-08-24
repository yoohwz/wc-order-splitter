<?php

if (!defined('ABSPATH')) {
	exit(1);
}

wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT), 'Global Split workflow is not production-enabled while planner foundation is tested.');
wcos_p2_adapter_assert(WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE), 'Hardened Duplicate production gate was lost while planner foundation is tested.');
wcos_p2_adapter_assert(WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::MANUAL_QUANTITY), 'Manual quantity Split strategy gate is not enabled.');
$planner_category_enabled = WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::CATEGORY);
$planner_stock_enabled = WCOS_Split_Strategy_Gates::enabled(WCOS_Split_Strategy_Gates::STOCK_STATUS);
wcos_p2_adapter_assert($planner_category_enabled === $planner_stock_enabled, 'Category and Stock-status strategy gates diverged during planner acceptance.');
$stock_planner_reflection = new ReflectionClass('WCOS_Stock_Status_Split_Planner');
$supported_statuses_property = $stock_planner_reflection->getProperty('supported_statuses');
$supported_statuses_property->setAccessible(true);
wcos_p2_adapter_assert(
	array('instock', 'outofstock', 'onbackorder') === $supported_statuses_property->getValue(),
	'Stock-status planner accepted-status authority changed.'
);
wcos_p2_adapter_assert(!class_exists('WCOS_Category_Split_Admin_Controller'), 'Category planner foundation unexpectedly exposed an admin controller.');
wcos_p2_adapter_assert(!class_exists('WCOS_Stock_Status_Split_Admin_Controller'), 'Stock-status planner foundation unexpectedly exposed an admin controller.');
wcos_p2_adapter_assert(!method_exists('WCOS_Mutation_Gateway', 'category_split'), 'Category planner foundation unexpectedly exposed a mutation gateway method.');
wcos_p2_adapter_assert(!method_exists('WCOS_Mutation_Gateway', 'stock_status_split'), 'Stock-status planner foundation unexpectedly exposed a mutation gateway method.');

$term_suffix = strtolower(wp_generate_password(6, false, false));
$parent_term = wp_insert_term('WCOS Planner Parent ' . $term_suffix, 'product_cat');
wcos_p2_adapter_assert(!is_wp_error($parent_term), 'Unable to create planner parent category.');
$child_term = wp_insert_term(
	'WCOS Planner Child ' . $term_suffix,
	'product_cat',
	array('parent' => absint($parent_term['term_id']))
);
wcos_p2_adapter_assert(!is_wp_error($child_term), 'Unable to create planner child category.');
$other_term = wp_insert_term('WCOS Planner Other ' . $term_suffix, 'product_cat');
wcos_p2_adapter_assert(!is_wp_error($other_term), 'Unable to create planner peer category.');

$category_a = wcos_p2_adapter_product('WCOS Category planner A', '10.00');
$category_b = wcos_p2_adapter_product('WCOS Category planner B', '8.00');
wp_set_object_terms($category_a->get_id(), array(absint($parent_term['term_id']), absint($child_term['term_id'])), 'product_cat');
wp_set_object_terms($category_b->get_id(), array(absint($other_term['term_id'])), 'product_cat');

$category_order = wc_create_order();
$category_order->set_status('pending');
$category_order->set_currency('USD');
$category_a_item = $category_order->add_product($category_a, 2);
$category_b_item = $category_order->add_product($category_b, 3);
$category_order->calculate_totals(false);
$category_order->set_billing_first_name('PlannerPrivateProbe');
$category_order->set_billing_email('planner-private@example.test');
$category_order->save();
$category_order = wc_get_order($category_order->get_id());
$category_signature = WCOS_Order_Contract_Snapshot::source_signature($category_order);

$category_review = WCOS_Category_Split_Planner::review($category_order);
wcos_p2_adapter_assert(!empty($category_review['supported']), 'Deterministic category order failed planner review.');
wcos_p2_adapter_assert(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER === $category_review['execution_policy'], 'Category review is not bound to whole-line execution policy.');
$child_bucket = 'category-' . absint($child_term['term_id']);
$other_bucket = 'category-' . absint($other_term['term_id']);
wcos_p2_adapter_assert(isset($category_review['buckets'][$child_bucket]), 'Parent+child assignment did not resolve to the single leaf category.');
wcos_p2_adapter_assert(!isset($category_review['buckets']['category-' . absint($parent_term['term_id'])]), 'Ancestor category remained as a duplicate authority bucket.');
wcos_p2_adapter_assert(isset($category_review['buckets'][$other_bucket]), 'Peer category bucket is missing.');
wcos_p2_adapter_assert(!empty($category_review['classification_fingerprint']), 'Category review omitted classification fingerprint.');
$category_json = wp_json_encode($category_review);
wcos_p2_adapter_assert(false === strpos($category_json, 'PlannerPrivateProbe'), 'Category planner leaked billing PII.');
wcos_p2_adapter_assert(false === strpos($category_json, 'planner-private@example.test'), 'Category planner leaked billing email.');

$category_plan = WCOS_Category_Split_Planner::build_plan($category_review, $child_bucket);
wcos_p2_adapter_assert(1 === count($category_plan), 'Category planner did not create exactly one child bucket.');
wcos_p2_adapter_assert(isset($category_plan[$other_bucket][$category_b_item]), 'Category plan did not move the peer-category line.');
wcos_p2_adapter_assert('3.000000' === $category_plan[$other_bucket][$category_b_item], 'Category plan did not freeze the full historical line quantity.');
wcos_p2_adapter_assert(!isset($category_plan[$other_bucket][$category_a_item]), 'Category plan moved the selected source bucket.');
wcos_p2_adapter_assert($category_signature === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($category_order->get_id())), 'Category Review/build-plan mutated the source order.');

$category_tampered = $category_review;
$category_tampered['execution_policy'] = WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY;
$category_policy_rejected = false;
try {
	WCOS_Category_Split_Planner::build_plan($category_tampered, $child_bucket);
} catch (RuntimeException $exception) {
	$category_policy_rejected = false !== strpos($exception->getMessage(), 'whole-line execution policy');
}
wcos_p2_adapter_assert($category_policy_rejected, 'Category planner accepted a tampered execution policy.');

$category_evidence_tampered = $category_review;
$category_evidence_tampered['buckets'][$other_bucket]['items'][$category_b_item] = '2.000000';
$category_evidence_rejected = false;
try {
	WCOS_Category_Split_Planner::build_plan($category_evidence_tampered, $child_bucket);
} catch (RuntimeException $exception) {
	$category_evidence_rejected = false !== strpos($exception->getMessage(), 'integrity fingerprint');
}
wcos_p2_adapter_assert($category_evidence_rejected, 'Category planner accepted tampered frozen item allocation evidence.');

/* Term ID is authority: changing name and slug changes display data, not classification identity. */
$category_fingerprint_before_display_change = $category_review['classification_fingerprint'];
$updated_other = wp_update_term(
	absint($other_term['term_id']),
	'product_cat',
	array(
		'name' => 'Renamed Display Only ' . $term_suffix,
		'slug' => 'renamed-display-' . $term_suffix,
	)
);
wcos_p2_adapter_assert(!is_wp_error($updated_other), 'Unable to rename planner category display metadata.');
$category_review_after_display_change = WCOS_Category_Split_Planner::review(wc_get_order($category_order->get_id()));
wcos_p2_adapter_assert(!empty($category_review_after_display_change['supported']), 'Category review failed after a display-only term rename.');
wcos_p2_adapter_assert(
	$category_fingerprint_before_display_change === $category_review_after_display_change['classification_fingerprint'],
	'Category classification fingerprint changed when only term display metadata changed.'
);
wcos_p2_adapter_assert(
	'renamed-display-' . $term_suffix === $category_review_after_display_change['buckets'][$other_bucket]['term_slug'],
	'Category review did not refresh display metadata after the term rename.'
);
$category_plan_after_display_change = WCOS_Category_Split_Planner::build_plan($category_review, $child_bucket);
wcos_p2_adapter_assert($category_plan === $category_plan_after_display_change, 'Changing category display metadata altered the frozen reviewed plan.');

/* Multiple unrelated leaf categories are ambiguous and fail closed. */
$ambiguous_product = wcos_p2_adapter_product('WCOS Category ambiguous', '6.00');
wp_set_object_terms($ambiguous_product->get_id(), array(absint($child_term['term_id']), absint($other_term['term_id'])), 'product_cat');
list($ambiguous_order, $ambiguous_item_id) = wcos_p2_adapter_order($ambiguous_product, 2, 'pending');
$ambiguous_report = WCOS_Category_Split_Planner::review($ambiguous_order);
wcos_p2_adapter_assert(empty($ambiguous_report['supported']) && 'ambiguous_multiple_leaf_categories' === $ambiguous_report['reason'], 'Category planner guessed among multiple unrelated leaf categories.');
$ambiguous_order->delete(true);
wp_delete_post($ambiguous_product->get_id(), true);

/* Force a truly termless product: WooCommerce normally assigns its default product category. */
$uncategorized_product = wcos_p2_adapter_product('WCOS Category uncategorized', '4.00');
$categorized_product = wcos_p2_adapter_product('WCOS Category categorized', '5.00');
wp_delete_object_term_relationships($uncategorized_product->get_id(), 'product_cat');
$uncategorized_terms = wp_get_post_terms($uncategorized_product->get_id(), 'product_cat');
wcos_p2_adapter_assert(!is_wp_error($uncategorized_terms) && empty($uncategorized_terms), 'Unable to construct a truly termless Category planner fixture.');
wp_set_object_terms($categorized_product->get_id(), array(absint($child_term['term_id'])), 'product_cat');
$uncategorized_order = wc_create_order();
$uncategorized_order->set_status('pending');
$uncategorized_order->set_currency('USD');
$uncategorized_item = $uncategorized_order->add_product($uncategorized_product, 1);
$categorized_item = $uncategorized_order->add_product($categorized_product, 1);
$uncategorized_order->calculate_totals(false);
$uncategorized_order->save();
$uncategorized_report = WCOS_Category_Split_Planner::review(wc_get_order($uncategorized_order->get_id()));
wcos_p2_adapter_assert(!empty($uncategorized_report['supported']), 'Explicit uncategorized/category pair failed planner review.');
wcos_p2_adapter_assert(isset($uncategorized_report['buckets'][WCOS_Category_Split_Planner::UNCATEGORIZED_BUCKET]), 'Uncategorized line was silently dropped by Category planner.');
$uncategorized_order->delete(true);
wp_delete_post($uncategorized_product->get_id(), true);
wp_delete_post($categorized_product->get_id(), true);

/* Deleted catalog product cannot be reclassified from volatile catalog state. */
$deleted_category_product = wcos_p2_adapter_product('WCOS Category deleted product', '7.00');
$deleted_category_peer = wcos_p2_adapter_product('WCOS Category deleted peer', '9.00');
wp_set_object_terms($deleted_category_product->get_id(), array(absint($child_term['term_id'])), 'product_cat');
wp_set_object_terms($deleted_category_peer->get_id(), array(absint($other_term['term_id'])), 'product_cat');
$deleted_category_order = wc_create_order();
$deleted_category_order->set_status('pending');
$deleted_category_order->set_currency('USD');
$deleted_category_order->add_product($deleted_category_product, 1);
$deleted_category_order->add_product($deleted_category_peer, 1);
$deleted_category_order->calculate_totals(false);
$deleted_category_order->save();
wp_delete_post($deleted_category_product->get_id(), true);
$deleted_category_report = WCOS_Category_Split_Planner::review(wc_get_order($deleted_category_order->get_id()));
wcos_p2_adapter_assert(empty($deleted_category_report['supported']) && 'deleted_product_category_unavailable' === $deleted_category_report['reason'], 'Category planner guessed category for a deleted catalog product.');
$deleted_category_order->delete(true);
wp_delete_post($deleted_category_peer->get_id(), true);

/* Stock-status planner freezes current catalog classification into evidence + explicit plan. */
$stock_in = wcos_p2_adapter_product('WCOS Stock planner in', '10.00');
$stock_out = wcos_p2_adapter_product('WCOS Stock planner out', '12.00');
$stock_in->set_stock_status('instock');
$stock_in->save();
$stock_out->set_stock_status('outofstock');
$stock_out->save();
$stock_order = wc_create_order();
$stock_order->set_status('pending');
$stock_order->set_currency('USD');
$stock_in_item = $stock_order->add_product($stock_in, 2);
$stock_out_item = $stock_order->add_product($stock_out, 1);
$stock_order->calculate_totals(false);
$stock_order->save();
$stock_order = wc_get_order($stock_order->get_id());
$stock_signature = WCOS_Order_Contract_Snapshot::source_signature($stock_order);

$stock_review = WCOS_Stock_Status_Split_Planner::review($stock_order);
wcos_p2_adapter_assert(!empty($stock_review['supported']), 'Two-status order failed Stock-status planner review.');
wcos_p2_adapter_assert(WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER === $stock_review['execution_policy'], 'Stock-status review is not bound to whole-line execution policy.');
wcos_p2_adapter_assert(isset($stock_review['buckets']['stock-instock']), 'In-stock bucket is missing.');
wcos_p2_adapter_assert(isset($stock_review['buckets']['stock-outofstock']), 'Out-of-stock bucket is missing.');
wcos_p2_adapter_assert(!empty($stock_review['classification_fingerprint']), 'Stock-status review omitted classification fingerprint.');
$stock_plan = WCOS_Stock_Status_Split_Planner::build_plan($stock_review, 'stock-instock');
wcos_p2_adapter_assert(isset($stock_plan['stock-outofstock'][$stock_out_item]), 'Stock-status plan did not move the reviewed out-of-stock line.');
wcos_p2_adapter_assert('1.000000' === $stock_plan['stock-outofstock'][$stock_out_item], 'Stock-status plan did not freeze full historical quantity.');

$stock_tampered = $stock_review;
$stock_tampered['execution_policy'] = WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY;
$stock_policy_rejected = false;
try {
	WCOS_Stock_Status_Split_Planner::build_plan($stock_tampered, 'stock-instock');
} catch (RuntimeException $exception) {
	$stock_policy_rejected = false !== strpos($exception->getMessage(), 'whole-line execution policy');
}
wcos_p2_adapter_assert($stock_policy_rejected, 'Stock-status planner accepted a tampered execution policy.');

$stock_evidence_tampered = $stock_review;
$stock_evidence_tampered['buckets']['stock-outofstock']['evidence'][$stock_out_item]['stock_owner_id'] = 999999;
$stock_evidence_rejected = false;
try {
	WCOS_Stock_Status_Split_Planner::build_plan($stock_evidence_tampered, 'stock-instock');
} catch (RuntimeException $exception) {
	$stock_evidence_rejected = false !== strpos($exception->getMessage(), 'integrity fingerprint');
}
wcos_p2_adapter_assert($stock_evidence_rejected, 'Stock-status planner accepted tampered frozen classification evidence.');

/* Catalog changes after Review must not rewrite the already reviewed plan. */
$stock_out = wc_get_product($stock_out->get_id());
$stock_out->set_stock_status('instock');
$stock_out->save();
$frozen_plan = WCOS_Stock_Status_Split_Planner::build_plan($stock_review, 'stock-instock');
wcos_p2_adapter_assert($stock_plan === $frozen_plan, 'Stock-status planner recomputed volatile catalog state while building a frozen Review plan.');
$new_stock_review = WCOS_Stock_Status_Split_Planner::review(wc_get_order($stock_order->get_id()));
wcos_p2_adapter_assert(empty($new_stock_review['supported']) && 'single_stock_status_bucket' === $new_stock_review['reason'], 'A fresh Stock-status Review did not observe the changed catalog classification.');
wcos_p2_adapter_assert($stock_signature === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($stock_order->get_id())), 'Stock-status Review/build-plan mutated the source order.');
$stock_order->delete(true);
wp_delete_post($stock_in->get_id(), true);
wp_delete_post($stock_out->get_id(), true);

$deleted_stock = wcos_p2_adapter_product('WCOS Stock planner deleted', '8.00');
$deleted_stock_peer = wcos_p2_adapter_product('WCOS Stock planner deleted peer', '9.00');
$deleted_stock->set_stock_status('outofstock');
$deleted_stock->save();
$deleted_stock_peer->set_stock_status('instock');
$deleted_stock_peer->save();
$deleted_stock_order = wc_create_order();
$deleted_stock_order->set_status('pending');
$deleted_stock_order->set_currency('USD');
$deleted_stock_order->add_product($deleted_stock, 1);
$deleted_stock_order->add_product($deleted_stock_peer, 1);
$deleted_stock_order->calculate_totals(false);
$deleted_stock_order->save();
wp_delete_post($deleted_stock->get_id(), true);
$deleted_stock_report = WCOS_Stock_Status_Split_Planner::review(wc_get_order($deleted_stock_order->get_id()));
wcos_p2_adapter_assert(empty($deleted_stock_report['supported']) && 'deleted_product_stock_status_unavailable' === $deleted_stock_report['reason'], 'Stock-status planner guessed status for a deleted catalog product.');
$deleted_stock_order->delete(true);
wp_delete_post($deleted_stock_peer->get_id(), true);

/* Parent-managed variation identity and stock-owner authority are deterministic. */
list($planner_parent_product, $planner_parent_variation) = wcos_p2_stock_variable_pair(
	'WCOS Stock planner parent-managed',
	true,
	false,
	0,
	0
);
$planner_parent_peer = wcos_p2_adapter_product('WCOS Stock planner parent peer', '9.00');
$planner_parent_variation = wc_get_product($planner_parent_variation->get_id());
$planner_parent_peer->set_stock_status('instock');
$planner_parent_peer->save();
$planner_parent_order = wc_create_order();
$planner_parent_order->set_status('pending');
$planner_parent_order->set_currency('USD');
$planner_parent_item = $planner_parent_order->add_product($planner_parent_variation, 2);
$planner_parent_order->add_product($planner_parent_peer, 1);
$planner_parent_order->calculate_totals(false);
$planner_parent_order->save();
$planner_parent_review = WCOS_Stock_Status_Split_Planner::review(wc_get_order($planner_parent_order->get_id()));
wcos_p2_adapter_assert(
	!empty($planner_parent_review['supported']),
	'Parent-managed variation failed Stock-status Review: ' . (isset($planner_parent_review['reason']) ? $planner_parent_review['reason'] : 'missing_reason')
);
$planner_parent_evidence = $planner_parent_review['buckets']['stock-outofstock']['evidence'][$planner_parent_item];
wcos_p2_adapter_assert($planner_parent_product->get_id() === $planner_parent_evidence['product_id'], 'Parent-managed Review lost parent product identity.');
wcos_p2_adapter_assert($planner_parent_variation->get_id() === $planner_parent_evidence['variation_id'], 'Parent-managed Review lost variation identity.');
wcos_p2_adapter_assert($planner_parent_variation->get_id() === $planner_parent_evidence['catalog_object_id'], 'Parent-managed Review resolved the wrong catalog object.');
wcos_p2_adapter_assert($planner_parent_product->get_id() === $planner_parent_evidence['stock_owner_id'], 'Parent-managed Review resolved the wrong stock owner.');
$planner_parent_order->delete(true);
wcos_p2_stock_delete_product($planner_parent_peer);
wcos_p2_stock_delete_product($planner_parent_variation);
wcos_p2_stock_delete_product($planner_parent_product);

$category_order->delete(true);
wp_delete_post($category_a->get_id(), true);
wp_delete_post($category_b->get_id(), true);
wp_delete_term(absint($child_term['term_id']), 'product_cat');
wp_delete_term(absint($other_term['term_id']), 'product_cat');
wp_delete_term(absint($parent_term['term_id']), 'product_cat');

echo "p2-strategy-planners-ok\n";
