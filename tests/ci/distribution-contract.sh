#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
wcos_tmp_base=${TMPDIR:-/tmp}
fixture_root=$(mktemp -d "$wcos_tmp_base/wcos-distribution-contract.XXXXXX")

cleanup() {
  rm -rf "$fixture_root"
}
trap cleanup EXIT

make_source_fixture() {
  local name=$1
  local fixture_source="$fixture_root/$name/source"
  mkdir -p "$fixture_source"
  rsync -a "$repo_root/" "$fixture_source/" --exclude='.git' --exclude='node_modules'
  echo "$fixture_source"
}

expect_failure() {
  local expected=$1
  shift
  local log_file="$fixture_root/expected-failure.log"

  if "$@" >"$log_file" 2>&1; then
    echo "distribution-test-error: command unexpectedly succeeded: $*" >&2
    exit 1
  fi

  grep -Fq "$expected" "$log_file" || {
    cat "$log_file" >&2
    echo "distribution-test-error: expected failure text not found: $expected" >&2
    exit 1
  }
}

positive_source=$(make_source_fixture positive)
"$positive_source/.github/scripts/validate-distribution-contract.sh" \
  "$positive_source" "$fixture_root/positive/distribution/wc-order-splitter" >/dev/null

missing_source=$(make_source_fixture missing-required)
rm "$missing_source/inc/domain/class-wcos-return-preflight.php"
expect_failure 'required hardened runtime path is missing: inc/domain/class-wcos-return-preflight.php' \
  "$missing_source/.github/scripts/validate-distribution-contract.sh" \
  "$missing_source" "$fixture_root/missing-required/distribution/wc-order-splitter"

symlink_source=$(make_source_fixture symlink)
ln -s readme.txt "$symlink_source/unsafe-link"
expect_failure 'symbolic links entered the distribution tree' \
  "$symlink_source/.github/scripts/validate-distribution-contract.sh" \
  "$symlink_source" "$fixture_root/symlink/distribution/wc-order-splitter"

telemetry_source=$(make_source_fixture telemetry)
printf '%s\n' '<?php // yoexpress.top fixture' > "$telemetry_source/inc/wos-telemetry-contract-fixture.php"
expect_failure 'removed telemetry endpoint entered the distribution tree' \
  "$telemetry_source/.github/scripts/validate-distribution-contract.sh" \
  "$telemetry_source" "$fixture_root/telemetry/distribution/wc-order-splitter"

echo 'distribution-negative-contracts-ok'
