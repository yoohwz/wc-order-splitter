<?php

if (!defined('ABSPATH')) { exit(1); }

const WCOS_COMPAT_003_FIXTURE_OPTION = 'wcos_compat_003_genuine_1_4_11_fixture';

function wcos_compat_003_seal_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}

$fixture = get_option(WCOS_COMPAT_003_FIXTURE_OPTION, array());
wcos_compat_003_seal_assert(is_array($fixture) && !empty($fixture['source_id']), 'The genuine 1.4.11 fixture seed is unavailable.');
wcos_compat_003_seal_assert('e1d8aeb8eff38f4ce69dad1a08993e17521c6359' === $fixture['baseline_sha'], 'The fixture baseline SHA is not exact.');
wcos_compat_003_seal_assert('75140a414cd637d134f860d8a70e7f92cbe4853c' === $fixture['baseline_tree'], 'The fixture baseline tree is not exact.');
wcos_compat_003_seal_assert('1.4.11' === $fixture['baseline_version'], 'The fixture baseline version is not exact.');

$source = wc_get_order(absint($fixture['source_id']));
wcos_compat_003_seal_assert($source instanceof WC_Order, 'The exact 1.4.11 source disappeared after Split.');
$legacy_children = array_values(array_filter(array_map('absint', explode(',', (string) $source->get_meta('yoos_splitted_order', true)))));
wcos_compat_003_seal_assert(1 === count($legacy_children), 'The exact 1.4.11 Split did not create one reciprocal child.');
$child = wc_get_order(reset($legacy_children));
wcos_compat_003_seal_assert($child instanceof WC_Order, 'The exact 1.4.11 child is unavailable.');
wcos_compat_003_seal_assert((string) $source->get_id() === (string) $child->get_meta('yoos_original_order', true), 'The exact 1.4.11 child lacks its parent relation.');
wcos_compat_003_seal_assert(1 === count($child->get_items('line_item')), 'The exact 1.4.11 child product-line shape is unexpected.');
wcos_compat_003_seal_assert(1 === count($child->get_items('shipping')), 'The exact 1.4.11 child did not replicate shipping.');

$source_line = $source->get_item(absint($fixture['moved_source_item_id']));
$child_lines = $child->get_items('line_item');
$child_line = reset($child_lines);
wcos_compat_003_seal_assert($source_line instanceof WC_Order_Item_Product && $child_line instanceof WC_Order_Item_Product, 'The exact 1.4.11 product lines are unavailable.');
wcos_compat_003_seal_assert(1 === (int) $source_line->get_quantity() && 2 === (int) $child_line->get_quantity(), 'The exact 1.4.11 partial Split quantities are unexpected.');

$fixture['child_id'] = $child->get_id();
$fixture['child_item_id'] = $child_line->get_id();
$fixture['source_quantity_after_split'] = (string) $source_line->get_quantity();
$fixture['source_subtotal_after_split'] = (string) $source_line->get_subtotal();
$fixture['source_total_after_split'] = (string) $source_line->get_total();
$fixture['child_quantity_after_split'] = (string) $child_line->get_quantity();
$fixture['child_subtotal_after_split'] = (string) $child_line->get_subtotal();
$fixture['child_total_after_split'] = (string) $child_line->get_total();
wcos_compat_003_seal_assert(update_option(WCOS_COMPAT_003_FIXTURE_OPTION, $fixture, false), 'The exact 1.4.11 fixture could not be sealed.');

echo "compat-legacy-1-4-11-fixture-sealed source={$source->get_id()} child={$child->get_id()}\n";
