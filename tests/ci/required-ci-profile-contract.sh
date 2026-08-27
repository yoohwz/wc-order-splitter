#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
aggregator="$repo_root/.github/scripts/verify-required-ci.sh"

expect_success() {
  "$aggregator" "$@" >/dev/null
}

expect_failure() {
  if "$aggregator" "$@" >/dev/null 2>&1; then
    echo "required-ci-profile-contract-error: unexpectedly accepted: $*" >&2
    exit 1
  fi
}

expect_success success DIRECT_CSS_FAST strict_existing_css_modifications skipped skipped skipped skipped success
expect_success success FULL path_outside_direct_css_allowlist success success success success skipped

expect_failure failure DIRECT_CSS_FAST strict_existing_css_modifications skipped skipped skipped skipped success
expect_failure success '' missing_profile skipped skipped skipped skipped success
expect_failure success UNKNOWN unknown_profile skipped skipped skipped skipped success
expect_failure success DIRECT_CSS_FAST '' skipped skipped skipped skipped success
expect_failure success DIRECT_CSS_FAST path_outside_direct_css_allowlist skipped skipped skipped skipped success
expect_failure success DIRECT_CSS_FAST strict_existing_css_modifications success skipped skipped skipped success
expect_failure success DIRECT_CSS_FAST strict_existing_css_modifications skipped skipped skipped skipped failure
expect_failure success DIRECT_CSS_FAST strict_existing_css_modifications skipped skipped skipped skipped skipped
expect_failure success FULL '' success success success success skipped
expect_failure success FULL strict_existing_css_modifications success success success success skipped
expect_failure success FULL workflow_changed success success success success success
expect_failure success FULL workflow_changed skipped success success success skipped
expect_failure success FULL workflow_changed success failure success success skipped
expect_failure success FULL workflow_changed success success skipped success skipped
expect_failure success FULL workflow_changed success success success cancelled skipped

echo required-ci-profile-contract-ok
