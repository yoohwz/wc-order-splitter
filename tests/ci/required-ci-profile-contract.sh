#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
aggregator="$repo_root/.github/scripts/verify-required-ci.sh"

expect_success() { "$aggregator" "$@" >/dev/null; }
expect_failure() {
  if "$aggregator" "$@" >/dev/null 2>&1; then
    echo "required-ci-profile-contract-error: unexpectedly accepted: $*" >&2
    exit 1
  fi
}

# classifier profile reason stage review php foundation package release focused domain precheck direct final-authority
expect_success success DIRECT_FAST strict_existing_css_modifications FINAL false skipped skipped skipped skipped skipped skipped skipped success skipped
expect_success success LOW_FOCUSED low_non_normative_or_presentation FINAL false skipped skipped skipped skipped success skipped skipped skipped skipped
expect_success success MEDIUM_DOMAIN bounded_client_runtime FINAL false skipped skipped skipped skipped success success skipped skipped skipped
expect_success success MEDIUM_DOMAIN bounded_client_runtime FINAL true skipped skipped skipped skipped success success skipped skipped success
expect_success success HIGH_DEEP governance_or_ci_control_plane FINAL true success success success skipped skipped success skipped skipped success
expect_success success HIGH_FINANCIAL financial_or_stock_authority FINAL true success success success skipped skipped success skipped skipped success
expect_success success RELEASE_CERT release_or_package_authority FINAL true success success success success skipped skipped skipped skipped success
expect_success success FULL source_base_bootstrap FINAL false success success success success skipped skipped skipped skipped skipped

expect_failure failure LOW_FOCUSED low FINAL false skipped skipped skipped skipped success skipped skipped skipped skipped
expect_failure success '' missing FINAL false skipped skipped skipped skipped success skipped skipped skipped skipped
expect_failure success UNKNOWN unknown FINAL true skipped skipped skipped skipped skipped skipped skipped skipped success
expect_failure success LOW_FOCUSED low PRECHECK false skipped skipped skipped skipped success skipped skipped skipped skipped
expect_failure success HIGH_DEEP high PRECHECK true skipped skipped skipped skipped skipped success success skipped skipped
expect_failure success HIGH_DEEP high FINAL false success success success skipped skipped success skipped skipped success
expect_failure success HIGH_DEEP high FINAL true success success success skipped skipped success skipped skipped skipped
expect_failure success HIGH_DEEP high FINAL true success success success skipped skipped skipped skipped skipped success
expect_failure success RELEASE_CERT release FINAL true success success success skipped skipped skipped skipped skipped success
expect_failure success DIRECT_FAST path_outside_direct FINAL false skipped skipped skipped skipped skipped skipped skipped success skipped
expect_failure success DIRECT_FAST strict_existing_css_modifications FINAL false skipped skipped skipped skipped skipped skipped skipped skipped skipped
expect_failure success LOW_FOCUSED low FINAL false skipped skipped skipped skipped success success skipped skipped skipped

echo required-ci-profile-contract-ok
