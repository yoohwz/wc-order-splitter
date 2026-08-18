<?php
/**
 * Static release contract for the fail-closed 1.4.12 hotfix.
 */

declare(strict_types=1);

$root     = dirname(__DIR__);
$failures = array();

/**
 * Record a failed assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 * @return void
 */
function wcos_release_assert($condition, $message) {
	global $failures;

	if (!$condition) {
		$failures[] = $message;
	}
}

/**
 * Read a required repository file.
 *
 * @param string $path Absolute path.
 * @return string
 */
function wcos_release_read($path) {
	global $failures;

	if (!is_file($path)) {
		$failures[] = 'Required file is missing: ' . $path;
		return '';
	}

	$content = file_get_contents($path);

	if (false === $content) {
		$failures[] = 'Unable to read required file: ' . $path;
		return '';
	}

	return $content;
}

$plugin  = wcos_release_read($root . '/wc-order-splitter.php');
$script  = wcos_release_read($root . '/inc/cores/script.php');
$readme  = wcos_release_read($root . '/readme.txt');
$changes = wcos_release_read($root . '/changelog.txt');

wcos_release_assert(false !== strpos($plugin, 'Version: 1.4.12'), 'Plugin header must declare version 1.4.12.');
wcos_release_assert(false !== strpos($plugin, "define('WC_ORDER_SPLITTER_VERSION', '1.4.12');"), 'Runtime version constant must be 1.4.12.');
wcos_release_assert(false !== strpos($plugin, "define('WC_ORDER_SPLITTER_MUTATIONS_ENABLED', false);"), 'Production mutation constant must be hardcoded false.');
wcos_release_assert(false !== strpos($plugin, 'Plugin URI: https://github.com/yoohwz/wc-order-splitter'), 'Plugin URI must use the project-specific repository URL.');
wcos_release_assert(false === strpos($plugin, 'Plugin URI: https://wordpress.org/'), 'Plugin URI must not use a WordPress.org plugin URL.');
wcos_release_assert(false !== strpos($readme, 'Stable tag: 1.4.12'), 'WordPress.org stable tag must be 1.4.12.');
wcos_release_assert(false !== strpos($readme, 'WC tested up to: 11.0'), 'WooCommerce tested version must be documented as 11.0.');
wcos_release_assert(false !== strpos($changes, '= 1.4.12 (Aug 18, 2026) ='), 'Standalone changelog must contain the 1.4.12 release.');

$forbidden_paths = array(
	'inc/cores/api/push-subscription.php',
	'inc/backend/actions/duplicate-order.php',
	'inc/backend/actions/merge-order.php',
	'inc/backend/actions/return-order.php',
	'inc/backend/actions/return-order-bulk-action.php',
	'inc/backend/actions/split-order-by-default.php',
	'inc/backend/actions/split-order-by-category.php',
	'inc/backend/actions/split-order-by-stock-status.php',
	'inc/backend/actions/split-order-set-email-filters.php',
	'inc/backend/order-duplicate-option.php',
	'inc/backend/order-merge-option.php',
	'inc/backend/order-return-option.php',
	'inc/backend/order-split-button.php',
	'inc/backend/orders-bulk-return.php',
	'js/split-table.js',
	'js/merge-action.js',
	'js/bulk-return-action.js',
	'js/post-action-tip.js',
	'js/orders.js',
);

foreach ($forbidden_paths as $relative_path) {
	wcos_release_assert(!file_exists($root . '/' . $relative_path), 'Forbidden legacy release path exists: ' . $relative_path);
}

$forbidden_loader_fragments = array(
	'/actions/',
	'order-split-button.php',
	'order-duplicate-option.php',
	'order-merge-option.php',
	'order-return-option.php',
	'orders-bulk-return.php',
	'push-subscription.php',
);

foreach ($forbidden_loader_fragments as $fragment) {
	wcos_release_assert(false === strpos($script, $fragment), 'Fail-closed loader references forbidden runtime code: ' . $fragment);
}

$runtime_paths = array(
	$root . '/wc-order-splitter.php',
	$root . '/inc',
	$root . '/js',
	$root . '/readme.txt',
);
$forbidden_runtime_tokens = array(
	'yoexpress.top',
	'wp_ajax_split_order',
	'wp_ajax_split_order_by_category',
	'wp_ajax_split_order_by_stock_status',
	'woocommerce_order_action_yoos_duplicate_order',
	'yoos_merge_order',
	'yoos_handle_bulk_action',
);

foreach ($runtime_paths as $runtime_path) {
	$files = array();

	if (is_file($runtime_path)) {
		$files[] = $runtime_path;
	} elseif (is_dir($runtime_path)) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($runtime_path, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile()) {
				$files[] = $file->getPathname();
			}
		}
	}

	foreach ($files as $file) {
		$content = file_get_contents($file);

		if (false === $content) {
			$failures[] = 'Unable to scan runtime file: ' . $file;
			continue;
		}

		foreach ($forbidden_runtime_tokens as $token) {
			if (false !== strpos($content, $token)) {
				$failures[] = sprintf('Forbidden runtime token %s found in %s.', $token, $file);
			}
		}
	}
}

if (!empty($failures)) {
	fwrite(STDERR, "Release contract failed:\n- " . implode("\n- ", $failures) . "\n");
	exit(1);
}

echo "Order Splitter 1.4.12 release contract passed.\n";
