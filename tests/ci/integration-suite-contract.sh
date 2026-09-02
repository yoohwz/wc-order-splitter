#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
manifest="$repo_root/tests/ci/integration-suites.tsv"
runner="$repo_root/.github/scripts/run-integration-profile.sh"
baseline_sha=ab7b1db49ff7b82ad1bb7fae3bbbafd56a5eb328
baseline_tree=b44f54e597e8f03d6d83c30acf55eb162535e96d
baseline_paths=$(mktemp)
manifest_paths=$(mktemp)
trap 'rm -f "$baseline_paths" "$manifest_paths"' EXIT

git -C "$repo_root" cat-file -e "$baseline_sha^{commit}"
test "$(git -C "$repo_root" rev-parse "$baseline_sha^{tree}")" = "$baseline_tree"
git -C "$repo_root" show "$baseline_sha:.github/workflows/ci.yml" \
  | grep -Eo 'tests/integration/[A-Za-z0-9._-]+\.php' \
  | sort -u > "$baseline_paths"

awk -F'|' '
  /^[[:space:]]*#/ || NF == 0 { next }
  NF != 3 { exit 2 }
  $1 != "eval" && $1 != "support" { exit 3 }
  $2 !~ /(^|,)release(,|$)/ { exit 4 }
  { print $3 }
' "$manifest" | sort > "$manifest_paths"

test "$(wc -l < "$manifest_paths" | tr -d ' ')" -eq "$(sort -u "$manifest_paths" | wc -l | tr -d ' ')"
while IFS= read -r path; do test -f "$repo_root/$path"; done < "$manifest_paths"

missing=$(comm -23 "$baseline_paths" "$manifest_paths")
if [[ -n "$missing" ]]; then
  echo 'integration-suite-contract-error: RELEASE_CERT manifest dropped baseline suite artifacts:' >&2
  echo "$missing" >&2
  exit 1
fi
unbound=$(comm -13 "$baseline_paths" "$manifest_paths")
if [[ -n "$unbound" ]]; then
  echo 'integration-suite-contract-error: release manifest claims artifacts not invoked by RELEASE_CERT:' >&2
  echo "$unbound" >&2
  exit 1
fi

medium_list=$("$runner" MEDIUM_DOMAIN FINAL --list)
deep_precheck_list=$("$runner" HIGH_DEEP PRECHECK --list)
deep_final_list=$("$runner" HIGH_DEEP FINAL --list)
financial_list=$("$runner" HIGH_FINANCIAL FINAL --list)

printf '%s\n' "$medium_list" | grep -Fxq 'tests/integration/order-actions-launcher-row-smoke.php'
printf '%s\n' "$deep_precheck_list" | grep -Fxq 'tests/integration/governance-smoke.php'
printf '%s\n' "$deep_final_list" | grep -Fxq 'tests/integration/failure-matrix-smoke.php'
printf '%s\n' "$financial_list" | grep -Fxq 'tests/integration/compat-merge-financial-history-smoke.php'
printf '%s\n' "$financial_list" | grep -Fxq 'tests/integration/tax-variation-smoke.php'
printf '%s\n' "$financial_list" | grep -Fxq 'tests/integration/compensation-smoke.php'
printf '%s\n' "$financial_list" | grep -Fxq 'tests/integration/duplicate-smoke.php'
printf '%s\n' "$financial_list" | grep -Fxq 'tests/integration/return-enabled-production-smoke.php'
printf '%s\n' "$financial_list" | grep -Fxq 'tests/integration/bulk-return-enabled-controller-smoke.php'
printf '%s\n' "$financial_list" | grep -Fxq 'tests/integration/order-actions-launcher-row-smoke.php'

# Escaped-bug regression capital remains routed into durable suites.
grep -Fq 'cancelling nonzero per-rate taxes' "$repo_root/tests/integration/compat-merge-financial-history-smoke.php"
grep -Fq 'Raw item-name PII leaked into the canonical Merge plan.' "$repo_root/tests/integration/compat-merge-commercial-compatibility-smoke.php"
grep -Fq 'Enabled controller replay changed durable journal authority.' "$repo_root/tests/integration/merge-review-confirm-execute-smoke.php"
grep -Fq 'Genuine schema-v1 Return journal compatibility was not preserved.' "$repo_root/tests/integration/return-review-confirm-authority-smoke.php"
grep -Fq 'Refund-tax presentation filter changed canonical authority.' "$repo_root/tests/integration/compat-merge-financial-history-smoke.php"
grep -Fq 'Verify retry idempotency at every persistence boundary' <(git -C "$repo_root" show "$baseline_sha:.github/workflows/ci.yml")

echo integration-suite-contract-ok
