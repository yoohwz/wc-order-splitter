<?php

if (!defined('ABSPATH')) { exit(1); }

$arguments = isset($args) && is_array($args) ? array_values($args) : array();
$source_name = isset($arguments[0]) ? (string) $arguments[0] : '';
$fault_point = isset($arguments[1]) ? (string) $arguments[1] : '';
$target = WP_PLUGIN_DIR . '/wcos-legacy-1-4-11';

function wcos_compat_007_assert_stage_target($target) {
	$plugin_dir = untrailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
	$normalized = wp_normalize_path($target);
	if ($plugin_dir . '/wcos-legacy-1-4-11' !== $normalized || $plugin_dir !== wp_normalize_path(dirname($target))) {
		throw new RuntimeException('Refusing an unauthenticated WOS-COMPAT-007 stage target.');
	}
}

function wcos_compat_007_remove_stage_target($target) {
	wcos_compat_007_assert_stage_target($target);
	if (!file_exists($target) && !is_link($target)) { return; }
	if (is_link($target) || !is_dir($target)) { throw new RuntimeException('Refusing an unexpected WOS-COMPAT-007 stage target type.'); }
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $entry) {
		$path = $entry->getPathname();
		if ($entry->isLink()) {
			if (!unlink($path)) { throw new RuntimeException('Unable to remove a link from the WOS-COMPAT-007 stage target.'); }
		} elseif ($entry->isDir()) {
			if (!rmdir($path)) { throw new RuntimeException('Unable to remove a directory from the WOS-COMPAT-007 stage target.'); }
		} elseif (!unlink($path)) {
			throw new RuntimeException('Unable to remove a file from the WOS-COMPAT-007 stage target.');
		}
	}
	if (!rmdir($target) || file_exists($target) || is_link($target)) { throw new RuntimeException('The WOS-COMPAT-007 stage target survived cleanup.'); }
}

if ('cleanup-target' === $source_name) {
	wcos_compat_007_remove_stage_target($target);
	echo "compat-upgrade-stage-target-cleanup-ok\n";
	return;
}
if ('assert-target-absent' === $source_name) {
	wcos_compat_007_assert_stage_target($target);
	if (file_exists($target) || is_link($target)) { throw new RuntimeException('The WOS-COMPAT-007 stage target is still present.'); }
	echo "compat-upgrade-stage-target-absent-ok\n";
	return;
}
if ('assert-target-partial' === $source_name) {
	wcos_compat_007_assert_stage_target($target);
	if (!is_dir($target) || file_exists($target . '/wc-order-splitter.php')) { throw new RuntimeException('The deterministic partial-copy fixture is not isolated before plugin recognition.'); }
	echo "compat-upgrade-stage-target-partial-ok\n";
	return;
}

$allowed = array('.wcos-compat-007-baseline-stage', '.wcos-compat-007-candidate-stage');
if (!in_array($source_name, $allowed, true)) {
	throw new RuntimeException('The upgrade stage source is not allowed.');
}
if (!in_array($fault_point, array('', 'partial-target'), true)) {
	throw new RuntimeException('The upgrade stage fault point is not allowed.');
}

$source = WP_PLUGIN_DIR . '/wc-order-splitter/tests/integration/' . $source_name;
wcos_compat_007_assert_stage_target($target);
if (!is_dir($source) || file_exists($target) || is_link($target) || !wp_mkdir_p($target)) {
	throw new RuntimeException('The upgrade stage cannot be copied to its isolated plugin path.');
}
if ('partial-target' === $fault_point) { throw new RuntimeException('Injected WOS-COMPAT-007 partial-target copy failure.'); }

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $entry) {
	$relative = substr($entry->getPathname(), strlen($source) + 1);
	$destination = $target . '/' . $relative;
	if ($entry->isDir()) {
		if (!wp_mkdir_p($destination)) { throw new RuntimeException('Unable to create an upgrade-stage directory.'); }
	} elseif (!copy($entry->getPathname(), $destination)) {
		throw new RuntimeException('Unable to copy an upgrade-stage file.');
	}
}

echo 'compat-upgrade-copy-stage-ok source=' . $source_name . "\n";
