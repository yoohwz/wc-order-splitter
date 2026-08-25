#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
ci_workflow="$repo_root/.github/workflows/ci.yml"
main_workflow="$repo_root/.github/workflows/main-attestation.yml"
package_workflow="$repo_root/.github/workflows/build-plugin.yml"
distribution_script="$repo_root/.github/scripts/validate-distribution-contract.sh"

assert_contains() {
  local file=$1
  local text=$2
  grep -Fq -- "$text" "$file" || {
    echo "workflow-contract-error: expected '$text' in $file" >&2
    exit 1
  }
}

assert_absent() {
  local file=$1
  local text=$2
  if grep -Fq -- "$text" "$file"; then
    echo "workflow-contract-error: forbidden '$text' in $file" >&2
    exit 1
  fi
}

ruby -e 'require "yaml"; ARGV.each { |file| Psych.parse_file(file) }' \
  "$ci_workflow" "$main_workflow" "$package_workflow"

assert_contains "$ci_workflow" 'pull_request:'
assert_contains "$ci_workflow" 'workflow_dispatch:'
assert_absent "$ci_workflow" '  push:'
assert_contains "$ci_workflow" "matrix:"
assert_contains "$ci_workflow" "php: ['7.4', '8.1', '8.3']"
assert_contains "$ci_workflow" 'storage: [legacy, hpos, hpos-sync]'
assert_contains "$ci_workflow" 'name: Required CI'
assert_contains "$ci_workflow" 'merge_candidate_tree_sha=$(git rev-parse HEAD^{tree})'
assert_contains "$ci_workflow" '.github/scripts/validate-distribution-contract.sh'
assert_absent "$ci_workflow" 'wc-order-splitter.zip'
assert_absent "$ci_workflow" 'actions/upload-artifact'

assert_contains "$main_workflow" 'name: Main attestation'
assert_contains "$main_workflow" '  push:'
assert_contains "$main_workflow" '      - main'
assert_contains "$main_workflow" 'git rev-parse HEAD^{tree}'
assert_contains "$main_workflow" '.github/scripts/validate-distribution-contract.sh'
assert_absent "$main_workflow" 'wc-order-splitter.zip'
assert_absent "$main_workflow" 'actions/upload-artifact'

assert_contains "$package_workflow" 'name: Manual package artifact'
assert_contains "$package_workflow" 'workflow_dispatch:'
assert_absent "$package_workflow" 'workflow_run:'
assert_absent "$package_workflow" '  push:'
assert_contains "$package_workflow" '.github/scripts/validate-distribution-contract.sh'
assert_contains "$package_workflow" 'actions/upload-artifact@v4'
assert_contains "$package_workflow" 'sha256sum wc-order-splitter.zip'

assert_contains "$repo_root/.distignore" '/.github'
assert_contains "$distribution_script" "set -euo pipefail"

echo 'workflow-contract-ok'
