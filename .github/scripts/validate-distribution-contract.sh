#!/usr/bin/env bash

set -euo pipefail

fail() {
  echo "distribution-contract-error: $*" >&2
  exit 1
}

if test "$#" -ne 2; then
  fail "usage: $0 SOURCE_ROOT DISTRIBUTION_ROOT"
fi

source_root=$(cd "$1" && pwd -P)
distribution_parent=$(dirname "$2")
distribution_name=$(basename "$2")

mkdir -p "$distribution_parent"
distribution_parent=$(cd "$distribution_parent" && pwd -P)
distribution_root="$distribution_parent/$distribution_name"

case "$distribution_root/" in
  "$source_root/"*) fail "distribution root must be outside the source tree" ;;
esac

test -f "$source_root/.distignore" || fail "missing .distignore"
test -f "$source_root/wc-order-splitter.php" || fail "missing plugin entrypoint"
test -f "$source_root/readme.txt" || fail "missing readme.txt"
test -f "$source_root/changelog.txt" || fail "missing changelog.txt"

if test -e "$distribution_root" && find "$distribution_root" -mindepth 1 -print -quit | grep -q .; then
  fail "distribution root must be absent or empty: $distribution_root"
fi

mkdir -p "$distribution_root"
rsync -a "$source_root/" "$distribution_root/" --exclude-from="$source_root/.distignore"

plugin_version=$(sed -n 's/^ \* Version: //p' "$distribution_root/wc-order-splitter.php" | head -n 1)
stable_tag=$(sed -n 's/^Stable tag: //p' "$distribution_root/readme.txt" | head -n 1)

test -n "$plugin_version" || fail "plugin Version is empty"
test -n "$stable_tag" || fail "Stable tag is empty"
test "$plugin_version" = "$stable_tag" || fail "plugin Version and Stable tag differ"
test '1.5.0' = "$plugin_version" || fail "release candidate version must be 1.5.0"

grep -Fq 'Requires at least: 6.5' "$distribution_root/wc-order-splitter.php" || fail "plugin minimum WordPress version drifted"
grep -Fq 'Requires at least: 6.5' "$distribution_root/readme.txt" || fail "readme minimum WordPress version drifted"
grep -Fq 'Requires PHP: 7.4' "$distribution_root/wc-order-splitter.php" || fail "plugin minimum PHP version drifted"
grep -Fq 'Requires PHP: 7.4' "$distribution_root/readme.txt" || fail "readme minimum PHP version drifted"
grep -Fq 'Tested up to: 7.1' "$distribution_root/readme.txt" || fail "WordPress tested version drifted from release evidence"
grep -Fq 'WC tested up to: 11.0' "$distribution_root/readme.txt" || fail "WooCommerce tested version drifted from release evidence"

readme_changelog=$(sed -n '/^== Changelog ==$/,/^== Upgrade Notice ==$/p' "$distribution_root/readme.txt")
readme_public_versions=$(printf '%s\n' "$readme_changelog" | sed -n -E 's/^= ([0-9]+\.[0-9]+\.[0-9]+)( \([^)]*\))? =$/\1/p')
file_public_versions=$(sed -n -E 's/^= ([0-9]+\.[0-9]+\.[0-9]+)( \([^)]*\))? =$/\1/p' "$distribution_root/changelog.txt")

test "$(printf '%s\n' "$readme_public_versions" | sed -n '1p')" = '1.5.0' || fail "readme public changelog must start with 1.5.0"
test "$(printf '%s\n' "$readme_public_versions" | sed -n '2p')" = '1.4.11' || fail "readme public changelog must place 1.4.11 immediately after 1.5.0"
test "$(printf '%s\n' "$file_public_versions" | sed -n '1p')" = '1.5.0' || fail "changelog.txt must start with public release 1.5.0"
test "$(printf '%s\n' "$file_public_versions" | sed -n '2p')" = '1.4.11' || fail "changelog.txt must place 1.4.11 immediately after 1.5.0"

if grep -E '^= 1\.4\.(12|13|14|15)( \([^)]*\))? =$' "$distribution_root/readme.txt" "$distribution_root/changelog.txt"; then
  fail "unpublished 1.4.12-1.4.15 entries entered public release history"
fi

for stale_claim in \
  'Return and Bulk Return remain disabled' \
  'Return and Bulk Return remain unavailable' \
  'Category and Stock-status Split remain disabled'; do
  if grep -Fq "$stale_claim" "$distribution_root/readme.txt" "$distribution_root/changelog.txt"; then
    fail "stale disabled-feature claim entered public release copy: $stale_claim"
  fi
done

grep -Fq 'return WCOS_Feature_Gates::any_enabled();' "$distribution_root/inc/cores/safety.php" || fail "safety guard no longer follows feature gates"
grep -Fq 'self::SPLIT => true' "$distribution_root/inc/domain/class-wcos-feature-gates.php" || fail "Split gate drifted"
grep -Fq 'self::DUPLICATE => true' "$distribution_root/inc/domain/class-wcos-feature-gates.php" || fail "Duplicate gate drifted"
grep -Fq 'self::MERGE => true' "$distribution_root/inc/domain/class-wcos-feature-gates.php" || fail "Merge gate drifted"
grep -Fq 'self::RETURN_ORDER => true' "$distribution_root/inc/domain/class-wcos-feature-gates.php" || fail "Return gate drifted"
grep -Fq 'self::BULK_RETURN => true' "$distribution_root/inc/domain/class-wcos-feature-gates.php" || fail "Bulk Return production gate drifted"
grep -Fq 'self::MANUAL_QUANTITY => true' "$distribution_root/inc/domain/class-wcos-split-strategy-gates.php" || fail "Manual Quantity gate drifted"
grep -Fq 'self::CATEGORY => true' "$distribution_root/inc/domain/class-wcos-split-strategy-gates.php" || fail "Category gate drifted"
grep -Fq 'self::STOCK_STATUS => true' "$distribution_root/inc/domain/class-wcos-split-strategy-gates.php" || fail "Stock-status gate drifted"

for forbidden_path in \
  '.git' \
  '.github' \
  '.distignore' \
  '.gitignore' \
  '.wp-env.json' \
  'AGENTS.md' \
  'docs' \
  'tests' \
  'node_modules' \
  'package.json' \
  'package-lock.json' \
  'inc/backend/actions' \
  'inc/backend/orders-bulk-return.php' \
  'inc/backend/order-split-button.php' \
  'inc/backend/order-return-option.php' \
  'inc/backend/order-duplicate-option.php' \
  'inc/backend/order-merge-option.php' \
  'js/bulk-return-action.js' \
  'js/merge-action.js' \
  'js/post-action-tip.js' \
  'js/split-table.js' \
  'inc/mutation-v2'; do
  if test -e "$distribution_root/$forbidden_path"; then
    fail "unsafe or development-only path entered distribution: $forbidden_path"
  fi
done

for required_path in \
  'wc-order-splitter.php' \
  'readme.txt' \
  'inc/backend/class-wcos-split-admin-controller.php' \
  'inc/backend/class-wcos-split-confirmation-store.php' \
  'inc/backend/class-wcos-split-request-parser.php' \
  'inc/domain/class-wcos-feature-gates.php' \
  'inc/domain/class-wcos-mutation-gateway.php' \
  'inc/domain/class-wcos-split-woocommerce-adapter.php' \
  'inc/domain/class-wcos-split-order-service.php' \
  'css/p2-split-admin.css' \
  'js/p2-split-admin.js' \
  'inc/backend/class-wcos-duplicate-admin-controller.php' \
  'inc/backend/class-wcos-duplicate-confirmation-store.php' \
  'inc/domain/class-wcos-duplicate-preflight.php' \
  'inc/domain/class-wcos-duplicate-woocommerce-adapter.php' \
  'inc/domain/class-wcos-duplicate-order-service.php' \
  'css/p2-duplicate-admin.css' \
  'js/p2-duplicate-admin.js' \
  'inc/backend/class-wcos-split-strategy-review-store.php' \
  'inc/backend/class-wcos-split-strategy-confirmation-store.php' \
  'inc/backend/class-wcos-split-strategy-admin-controller.php' \
  'inc/backend/class-wcos-merge-review-store.php' \
  'inc/backend/class-wcos-merge-confirmation-store.php' \
  'inc/backend/class-wcos-merge-admin-controller.php' \
  'css/p2-merge-admin.css' \
  'js/p2-merge-admin.js' \
  'inc/domain/class-wcos-split-strategy-gates.php' \
  'inc/domain/class-wcos-category-split-planner.php' \
  'inc/domain/class-wcos-stock-status-split-planner.php' \
  'inc/domain/class-wcos-split-strategy-woocommerce-adapter.php' \
  'inc/domain/class-wcos-multi-order-lease.php' \
  'inc/domain/class-wcos-merge-plan.php' \
  'inc/domain/class-wcos-merge-context-signature.php' \
  'inc/domain/class-wcos-merge-journal-context.php' \
  'inc/domain/class-wcos-merge-participation.php' \
  'inc/domain/class-wcos-merge-recovery-snapshot.php' \
  'inc/domain/class-wcos-merge-recovery-state-graph.php' \
  'inc/domain/class-wcos-merge-commit-guard.php' \
  'inc/domain/class-wcos-merge-compensator.php' \
  'inc/domain/class-wcos-merge-preflight.php' \
  'inc/domain/class-wcos-merge-retirement-policy.php' \
  'inc/domain/class-wcos-merge-order-service.php' \
  'inc/domain/class-wcos-merge-woocommerce-adapter.php' \
  'inc/domain/class-wcos-return-participation.php' \
  'inc/domain/class-wcos-return-plan.php' \
  'inc/domain/class-wcos-return-lineage-authority.php' \
  'inc/domain/class-wcos-return-preflight.php' \
  'inc/domain/class-wcos-return-retirement-policy.php' \
  'inc/domain/class-wcos-return-source-evolution-authority.php' \
  'inc/domain/class-wcos-return-journal-context.php' \
  'inc/domain/class-wcos-return-recovery-snapshot.php' \
  'inc/domain/class-wcos-return-recovery-state-graph.php' \
  'inc/domain/class-wcos-return-commit-guard.php' \
  'inc/domain/class-wcos-return-compensator.php' \
  'inc/domain/class-wcos-return-order-service.php' \
  'inc/domain/class-wcos-return-woocommerce-adapter.php' \
  'inc/backend/class-wcos-return-review-store.php' \
  'inc/backend/class-wcos-return-confirmation-store.php' \
  'inc/backend/class-wcos-return-admin-controller.php' \
  'css/p2-return-admin.css' \
  'js/p2-return-admin.js' \
  'inc/domain/class-wcos-bulk-return-batch-plan.php' \
  'inc/domain/class-wcos-bulk-return-journal-context.php' \
  'inc/domain/class-wcos-bulk-return-orchestrator.php' \
  'inc/backend/class-wcos-bulk-return-review-store.php' \
  'inc/backend/class-wcos-bulk-return-confirmation-store.php' \
  'inc/backend/class-wcos-bulk-return-admin-controller.php' \
  'css/p2-bulk-return-admin.css' \
  'js/p2-bulk-return-admin.js' \
  'css/p2-split-strategy-admin.css' \
  'js/p2-split-strategy-admin.js'; do
  if test ! -e "$distribution_root/$required_path"; then
    fail "required hardened runtime path is missing: $required_path"
  fi
done

distribution_symlink=$(find "$distribution_root" -type l -print -quit)
if test -n "$distribution_symlink"; then
  fail "symbolic links entered the distribution tree"
fi

telemetry_host='yoexpress''.''top'
if grep -R --line-number --fixed-strings "$telemetry_host" "$distribution_root"; then
  fail "removed telemetry endpoint entered the distribution tree"
fi

if grep -R --line-number --fixed-strings 'WC_Order_Splitter_Push_Subscription' "$distribution_root"; then
  fail "removed telemetry class entered the distribution tree"
fi

if grep -R --line-number -E 'WC_ORDER_SPLITTER_(MUTATIONS|SPLIT|DUPLICATE|MERGE|RETURN|BULK_RETURN)_ENABLED' \
  "$distribution_root/wc-order-splitter.php" "$distribution_root/inc/cores" "$distribution_root/inc/domain"; then
  fail "externally overrideable mutation gates entered the distribution tree"
fi

if grep -R --line-number --fixed-strings 'wc-merged' "$distribution_root/wc-order-splitter.php" "$distribution_root/inc"; then
  fail "custom production merged order status entered the distribution tree"
fi

if grep -R --line-number --fixed-strings 'wp_ajax_yoos_handle_bulk_action' "$distribution_root"; then
  fail "legacy Bulk Return AJAX authority entered the distribution tree"
fi

while IFS= read -r -d '' php_file; do
  php -l "$php_file" >/dev/null
done < <(find "$distribution_root" -type f -name '*.php' -print0)

echo "distribution-contract-ok version=$plugin_version root=$distribution_root"
