#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
ci_workflow="$repo_root/.github/workflows/ci.yml"
main_workflow="$repo_root/.github/workflows/main-attestation.yml"
package_workflow="$repo_root/.github/workflows/build-plugin.yml"
agents_contract="$repo_root/AGENTS.md"
short_contract="$repo_root/docs/codex-short-command-protocol.md"
review_contract="$repo_root/docs/engineering-review-authority.md"
compressed_contract="$repo_root/docs/compressed-engineering-workflow.md"
direct_contract="$repo_root/docs/codex-direct-workflow.md"
ci_contract="$repo_root/docs/ci-workflow-contract.md"

assert_contains() {
  local file=$1 text=$2
  grep -Fq -- "$text" "$file" || { echo "workflow-contract-error: expected '$text' in $file" >&2; exit 1; }
}

assert_absent() {
  local file=$1 text=$2
  if grep -Fq -- "$text" "$file"; then echo "workflow-contract-error: forbidden '$text' in $file" >&2; exit 1; fi
}

assert_occurrences() {
  local file=$1 text=$2 expected=$3 actual
  actual=$(grep -Foc -- "$text" "$file" || true)
  [[ "$actual" -eq "$expected" ]] || { echo "workflow-contract-error: '$text' expected $expected times, found $actual" >&2; exit 1; }
}

assert_before() {
  local file=$1 first=$2 second=$3 first_line second_line
  first_line=$(grep -Fnm1 -- "$first" "$file" | cut -d: -f1 || true)
  second_line=$(grep -Fnm1 -- "$second" "$file" | cut -d: -f1 || true)
  [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] || {
    echo "workflow-contract-error: expected '$first' before '$second'" >&2
    exit 1
  }
}

ruby -e 'require "yaml"; ARGV.each { |file| Psych.parse_file(file) }' "$ci_workflow" "$main_workflow" "$package_workflow"

assert_contains "$ci_workflow" 'pull_request:'
assert_contains "$ci_workflow" 'workflow_dispatch:'
assert_absent "$ci_workflow" '  push:'
assert_contains "$ci_workflow" 'pr_number:'
assert_contains "$ci_workflow" 'task_id:'
assert_contains "$ci_workflow" 'task_issue_number:'
assert_contains "$ci_workflow" 'expected_head_sha:'
assert_contains "$ci_workflow" 'requested_profile:'
assert_contains "$ci_workflow" 'pre_review_authority:'
assert_contains "$ci_workflow" 'actions: read'
assert_contains "$ci_workflow" 'issues: read'
assert_contains "$ci_workflow" 'pull-requests: read'

assert_contains "$ci_workflow" 'name: Classify PR CI profile'
assert_contains "$ci_workflow" 'name: Resolve exact PR authority'
assert_contains "$ci_workflow" 'pr_json=$(gh api "repos/$GITHUB_REPOSITORY/pulls/$INPUT_PR_NUMBER")'
assert_contains "$ci_workflow" 'test "$(jq -r '\''.base.ref'\'' <<< "$pr_json")" = main'
assert_contains "$ci_workflow" 'test "$head_sha" = "$expected_head"'
assert_contains "$ci_workflow" 'test "$GITHUB_SHA" = "$expected_head"'
assert_contains "$ci_workflow" 'gh api "repos/$GITHUB_REPOSITORY/issues/$INPUT_TASK_ISSUE_NUMBER"'
assert_contains "$ci_workflow" 'git cat-file -e "$PR_BASE_SHA:.github/scripts/classify-pr-scope.sh"'
assert_contains "$ci_workflow" 'git show "$PR_BASE_SHA:.github/scripts/classify-pr-scope.sh" > "$base_classifier"'
assert_contains "$ci_workflow" '"$base_classifier" pull_request "$PR_BASE_SHA" "$PR_HEAD_SHA" "$PR_HEAD_REF" "$classifier_output" "$REQUESTED_PROFILE"'
assert_contains "$ci_workflow" 'REQUESTED_PROFILE: ${{ inputs.requested_profile }}'
assert_contains "$ci_workflow" 'stage=FINAL'
assert_contains "$ci_workflow" 'storage_matrix='
assert_contains "$ci_workflow" 'DIRECT_FAST|LOW_FOCUSED|MEDIUM_DOMAIN|HIGH_DEEP|HIGH_FINANCIAL|RELEASE_CERT'

assert_contains "$ci_workflow" 'name: Risk-tiered PRECHECK / deterministic contracts'
assert_contains "$ci_workflow" "needs['classify-pr-scope'].outputs.stage == 'PRECHECK'"
assert_contains "$ci_workflow" "needs['classify-pr-scope'].outputs.review_required == 'true'"
assert_contains "$ci_workflow" 'precheck-artifacts=0'
assert_contains "$ci_workflow" 'name: Risk-tiered ${{ needs['"'"'classify-pr-scope'"'"'].outputs.stage }} domain / ${{ matrix.storage }}'
assert_contains "$ci_workflow" 'storage: ${{ fromJSON(needs['"'"'classify-pr-scope'"'"'].outputs.storage_matrix) }}'
assert_occurrences "$ci_workflow" 'ref: ${{ needs['"'"'classify-pr-scope'"'"'].outputs.head_sha }}' 4
assert_contains "$ci_workflow" '.github/scripts/run-integration-profile.sh "$CI_PROFILE" "$CI_STAGE"'
assert_contains "$ci_workflow" 'name: Verify HIGH final real-worker lease exclusion'
assert_contains "$ci_workflow" 'tests/integration/concurrency-lock-worker.php'

assert_contains "$ci_workflow" 'name: Authenticate unchanged PRE_REVIEW_CLEAN for FINAL'
assert_contains "$ci_workflow" "github.event_name == 'workflow_dispatch'"
assert_contains "$ci_workflow" 'git cat-file -e "$PR_BASE_SHA:.github/scripts/verify-pre-review-authority.sh"'
assert_contains "$ci_workflow" 'git cat-file -e "$PR_BASE_SHA:.github/scripts/validate-pre-review-record.sh"'
assert_contains "$ci_workflow" 'WCOS_PRE_REVIEW_VALIDATOR="$validator" "$verifier"'
assert_contains "$ci_workflow" 'final-authority-artifacts=0'

assert_contains "$ci_workflow" 'name: Focused deterministic profile contract'
assert_contains "$ci_workflow" 'tests/ci/direct-css-fast-contract.sh'
assert_contains "$ci_workflow" 'tests/ci/required-ci-profile-contract.sh'
assert_contains "$ci_workflow" 'tests/ci/integration-suite-contract.sh'
assert_contains "$ci_workflow" 'tests/ci/pre-review-authority-contract.sh'
assert_contains "$ci_workflow" 'tests/ci/merge-canonical-read-contract.sh'

# Source-base FULL compatibility is retained for WOS-GOV-009 itself.
assert_contains "$ci_workflow" "needs['classify-pr-scope'].outputs.profile == 'FULL'"
assert_contains "$ci_workflow" "php: ['7.4', '8.1', '8.3']"
assert_contains "$ci_workflow" 'storage: [legacy, hpos, hpos-sync]'
assert_contains "$ci_workflow" 'baseline_sha=e1d8aeb8eff38f4ce69dad1a08993e17521c6359'
assert_contains "$ci_workflow" 'baseline_tree=75140a414cd637d134f860d8a70e7f92cbe4853c'
assert_before "$ci_workflow" 'Create a genuine Split fixture with exact public 1.4.11' 'Activate Order Splitter after the in-place fixture upgrade'
assert_contains "$ci_workflow" 'compat-merge-financial-history-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-execute-race-worker.php'
assert_occurrences "$ci_workflow" 'docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker"' 11

assert_contains "$ci_workflow" 'name: Required CI'
assert_contains "$ci_workflow" "needs['classify-pr-scope'].outputs.stage == 'FINAL'"
assert_contains "$ci_workflow" 'CI_STAGE: ${{ needs['"'"'classify-pr-scope'"'"'].outputs.stage }}'
assert_contains "$ci_workflow" 'REVIEW_REQUIRED: ${{ needs['"'"'classify-pr-scope'"'"'].outputs.review_required }}'
assert_contains "$ci_workflow" 'PROFILE_INTEGRATION_RESULT:'
assert_contains "$ci_workflow" 'FINAL_AUTHORITY_RESULT:'
assert_contains "$ci_workflow" 'git show "$PR_BASE_SHA:.github/scripts/verify-required-ci.sh" > "$aggregator"'
assert_contains "$ci_workflow" "grep -Fq 'risk-tiered-v1' \"\$aggregator\""
assert_contains "$ci_workflow" 'DIRECT_CSS_FAST_RESULT:'
assert_absent "$ci_workflow" 'actions/upload-artifact'
assert_absent "$ci_workflow" 'wc-order-splitter.zip'

assert_contains "$main_workflow" 'name: Main attestation'
assert_contains "$main_workflow" '  push:'
assert_contains "$main_workflow" '      - main'
assert_contains "$main_workflow" '  workflow_dispatch:'
assert_contains "$main_workflow" 'expected_sha:'
assert_contains "$main_workflow" 'authority:'
assert_contains "$main_workflow" 'test "$checked_out_sha" = "$expected_sha"'
assert_contains "$main_workflow" 'git rev-parse HEAD^{tree}'
assert_contains "$main_workflow" 'main_parent_shas=$(git show -s --format=%P HEAD)'
assert_contains "$main_workflow" '.github/scripts/validate-distribution-contract.sh'
assert_absent "$main_workflow" 'actions/upload-artifact'

assert_contains "$package_workflow" 'name: Manual package artifact'
assert_contains "$package_workflow" 'workflow_dispatch:'
assert_absent "$package_workflow" 'workflow_run:'
assert_absent "$package_workflow" '  push:'
assert_contains "$package_workflow" 'actions/upload-artifact@v4'

assert_contains "$agents_contract" 'LOW uses persisted Executor evidence without Independent Review by default'
assert_contains "$agents_contract" '`PRECHECK -> fresh Independent PRE_REVIEW -> FINAL`'
assert_contains "$agents_contract" '`DIRECT_FAST` Required CI'
assert_contains "$agents_contract" 'it has no `TECHNICAL_ACCEPTED` checkpoint'
assert_contains "$agents_contract" '`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`'

assert_contains "$compressed_contract" '`EXECUTOR_EVIDENCE_READY:'
assert_contains "$compressed_contract" '`PRE_REVIEW_CLEAN`'
assert_contains "$compressed_contract" '`HIGH_FINANCIAL`'
assert_contains "$compressed_contract" '`RELEASE_CERT`'
assert_contains "$compressed_contract" 'WOS-GOV-009 itself bootstraps through the previous FULL'
assert_contains "$compressed_contract" 'does not create or start `WOS-COMPAT-007`'

assert_contains "$review_contract" 'PRECHECK must not make `Required CI` green.'
assert_contains "$review_contract" 'mechanically persist `TECHNICAL_ACCEPTED` only by binding the exact independent authority ID'
assert_contains "$review_contract" 'Cache stable content, never a technical conclusion.'
assert_contains "$review_contract" '`EXECUTOR_EVIDENCE_READY` for no-review LOW/MEDIUM'

assert_contains "$direct_contract" 'DIRECT is the only path that omits ChatGPT Create, ChatGPT Acceptance, and fresh Independent Codex Review.'
assert_contains "$direct_contract" 'The base-owned classifier must select `DIRECT_FAST`'
assert_contains "$direct_contract" 'DIRECT has no technical-review checkpoint'
assert_contains "$direct_contract" '`HUMAN_GATE_APPROVED_DIRECT:'
assert_contains "$direct_contract" '`POST_MERGE_ACCEPTED_DIRECT:'
assert_contains "$direct_contract" 'DIRECT never authorizes version/stable-tag changes'

assert_contains "$ci_contract" 'The protected context remains exactly `Required CI`.'
assert_contains "$ci_contract" '`DIRECT_FAST`, `LOW_FOCUSED`, `MEDIUM_DOMAIN`, `HIGH_DEEP`, `HIGH_FINANCIAL`, and `RELEASE_CERT`'
assert_contains "$ci_contract" 'PRECHECK cannot satisfy branch protection'
assert_contains "$ci_contract" '`tests/ci/integration-suites.tsv`'
assert_contains "$ci_contract" 'WOS-GOV-009 itself is a one-time source-base bootstrap.'
assert_contains "$ci_contract" 'WOS-REL-001` remains separately frozen'

assert_contains "$short_contract" '`EXECUTOR_EVIDENCE_READY`'
assert_contains "$short_contract" '`PRE_REVIEW_CLEAN`'
assert_contains "$short_contract" 'DIRECT has no Independent Review or `TECHNICAL_ACCEPTED`'
assert_contains "$short_contract" 'Command: Finalize <TASK_ID>'
assert_contains "$short_contract" 'NEXT_ACTION_HINT'

echo workflow-contract-ok
