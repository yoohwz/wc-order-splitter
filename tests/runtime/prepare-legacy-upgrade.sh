#!/usr/bin/env bash
# Product assertions preserved from the accepted pre-reset CI workflow.
set -euo pipefail

# Stage exact public 1.4.11 baseline
baseline_sha=e1d8aeb8eff38f4ce69dad1a08993e17521c6359
baseline_tree=75140a414cd637d134f860d8a70e7f92cbe4853c
baseline_dir=tests/integration/.wcos-compat-003-baseline
test "$(git rev-parse "$baseline_sha")" = "$baseline_sha"
test "$(git rev-parse "$baseline_sha^{tree}")" = "$baseline_tree"
test ! -e "$baseline_dir"
mkdir -p "$baseline_dir"
git archive "$baseline_sha" | tar -x -C "$baseline_dir"
test "$(sed -n 's/^ \* Version: //p' "$baseline_dir/wc-order-splitter.php" | head -n 1)" = 1.4.11


# Install exact public 1.4.11 baseline beside the current checkout
npx wp-env run cli wp eval '
  $source = WP_PLUGIN_DIR . "/wc-order-splitter/tests/integration/.wcos-compat-003-baseline";
  $target = WP_PLUGIN_DIR . "/wcos-legacy-1-4-11";
  if (!is_dir($source) || file_exists($target) || !wp_mkdir_p($target)) { exit(1); }
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($iterator as $entry) {
    $relative = substr($entry->getPathname(), strlen($source) + 1);
    $destination = $target . "/" . $relative;
    if ($entry->isDir()) {
      if (!wp_mkdir_p($destination)) { exit(1); }
    } elseif (!copy($entry->getPathname(), $destination)) {
      exit(1);
    }
  }
'


# Create a genuine Split fixture with exact public 1.4.11
npx wp-env run cli wp plugin activate wcos-legacy-1-4-11
npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-legacy-1-4-11-create.php
npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-legacy-1-4-11-seal.php
npx wp-env run cli wp plugin deactivate wcos-legacy-1-4-11


# Remove exact public 1.4.11 before current runtime validation
npx wp-env run cli wp plugin delete wcos-legacy-1-4-11
