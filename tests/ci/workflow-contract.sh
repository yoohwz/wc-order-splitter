#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
ci_workflow="$repo_root/.github/workflows/ci.yml"
main_workflow="$repo_root/.github/workflows/main-attestation.yml"
package_workflow="$repo_root/.github/workflows/build-plugin.yml"
distribution_script="$repo_root/.github/scripts/validate-distribution-contract.sh"
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
assert_contains "$ci_workflow" 'requested_assurance:'
assert_contains "$ci_workflow" 'independent_review_floor:'
assert_contains "$ci_workflow" 'certification_stage:'
assert_contains "$ci_workflow" 'pre_review_authority:'
assert_contains "$ci_workflow" 'merge_authority:'
assert_contains "$ci_workflow" '          - MERGE_AUTHORITY'
assert_contains "$ci_workflow" 'name: FINAL binding /'
assert_contains "$ci_workflow" 'name: Authenticate and attest merge candidate'
assert_occurrences "$ci_workflow" 'checks: write' 1
assert_occurrences "$ci_workflow" 'node tests/ci/merge-candidate-authority-contract.js' 3
assert_contains "$ci_workflow" "if: always() && inputs.certification_stage != 'MERGE_AUTHORITY'"
assert_contains "$ci_workflow" 'actions: read'
assert_contains "$ci_workflow" 'issues: read'
assert_contains "$ci_workflow" 'pull-requests: read'

assert_contains "$ci_workflow" 'name: Classify PR CI profile'
assert_contains "$ci_workflow" 'classify-pr-scope:'
assert_contains "$ci_workflow" 'name: Resolve exact PR authority'
assert_contains "$ci_workflow" 'pr_json=$(gh api "repos/$GITHUB_REPOSITORY/pulls/$INPUT_PR_NUMBER")'
assert_contains "$ci_workflow" 'test "$(jq -r '\''.base.ref'\'' <<< "$pr_json")" = main'
assert_contains "$ci_workflow" 'test "$head_sha" = "$expected_head"'
assert_contains "$ci_workflow" 'test "$GITHUB_SHA" = "$expected_head"'
assert_contains "$ci_workflow" 'grep -Fqx -- "- Canonical Issue: #$INPUT_TASK_ISSUE_NUMBER" <<< "$pr_body"'
assert_contains "$ci_workflow" 'grep -Fqx -- "- Task: \`$INPUT_TASK_ID\`" <<< "$pr_body"'
assert_contains "$ci_workflow" 'gh api "repos/$GITHUB_REPOSITORY/issues/$INPUT_TASK_ISSUE_NUMBER"'
assert_contains "$ci_workflow" 'grep -Fqx -- "- **Task:** \`$INPUT_TASK_ID\`" <<< "$issue_body"'
assert_contains "$ci_workflow" 'require_unique_issue_field()'
assert_contains "$ci_workflow" 'exact_count=$(grep -Fxc -- "- **$label:** \`$expected\`" <<< "$issue_body" || true)'
assert_contains "$ci_workflow" 'total_count=$(grep -Fc -- "**$label:**" <<< "$issue_body" || true)'
assert_contains "$ci_workflow" 'require_unique_issue_field '\''CI profile floor'\'' "$INPUT_REQUESTED_PROFILE"'
assert_contains "$ci_workflow" 'require_unique_issue_field '\''Assurance floor'\'' "$INPUT_REQUESTED_ASSURANCE"'
assert_contains "$ci_workflow" 'require_unique_issue_field '\''Independent review floor'\'' "$INPUT_REVIEW_FLOOR"'
assert_contains "$ci_workflow" 'git cat-file -e "$PR_BASE_SHA:.github/scripts/classify-pr-scope.sh"'
assert_contains "$ci_workflow" 'reason=classifier_not_present_in_base'
assert_contains "$ci_workflow" 'git show "$PR_BASE_SHA:.github/scripts/classify-pr-scope.sh" > "$base_classifier"'
assert_occurrences "$ci_workflow" 'PR_HEAD_REF: ${{ github.head_ref }}' 1
assert_contains "$ci_workflow" '"$base_classifier" pull_request "$PR_BASE_SHA" "$PR_HEAD_SHA" "$PR_HEAD_REF" "$classifier_output" "$REQUESTED_PROFILE" "$REQUESTED_ASSURANCE" "$REQUESTED_REVIEW_FLOOR"'
assert_contains "$ci_workflow" 'REQUESTED_PROFILE: ${{ inputs.requested_profile }}'
assert_contains "$ci_workflow" 'REQUESTED_ASSURANCE: ${{ inputs.requested_assurance }}'
assert_contains "$ci_workflow" 'REQUESTED_REVIEW_FLOOR: ${{ inputs.independent_review_floor }}'
assert_contains "$ci_workflow" 'REQUESTED_STAGE: ${{ inputs.certification_stage }}'
assert_contains "$ci_workflow" 'if [[ "$EVENT_NAME" == pull_request && "$profile" != FULL && "$profile" != DIRECT_CSS_FAST && "$profile" != DIRECT_FAST ]]'
assert_contains "$ci_workflow" 'if [[ "$REQUESTED_STAGE" == PRECHECK ]]'
assert_contains "$ci_workflow" 'test -z "$PRE_REVIEW_AUTHORITY"'
assert_contains "$ci_workflow" 'test -n "$PRE_REVIEW_AUTHORITY"'
assert_contains "$ci_workflow" 'stage=FINAL'
assert_contains "$ci_workflow" 'storage_matrix='
assert_contains "$ci_workflow" 'DIRECT_FAST|LOW_FOCUSED|MEDIUM_DOMAIN|HIGH_DEEP|HIGH_FINANCIAL|RELEASE_CERT'

assert_contains "$ci_workflow" "name: Risk-tiered PRECHECK / \${{ needs['classify-pr-scope'].outputs.task_id || 'UNBOUND' }} / \${{ needs['classify-pr-scope'].outputs.profile }}"
assert_contains "$ci_workflow" "needs['classify-pr-scope'].outputs.stage == 'PRECHECK'"
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
assert_contains "$ci_workflow" 'direct-css-fast:'
assert_contains "$ci_workflow" 'name: CODEX_DIRECT focused CSS contract'
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
assert_contains "$ci_workflow" 'compat-legacy-1-4-11-create.php'
assert_contains "$ci_workflow" 'compat-legacy-1-4-11-seal.php'
assert_contains "$ci_workflow" 'compat-legacy-return-upgrade-smoke.php'
assert_before "$ci_workflow" 'Create a genuine Split fixture with exact public 1.4.11' 'Activate Order Splitter after the in-place fixture upgrade'
assert_before "$ci_workflow" 'Remove exact public 1.4.11 before current runtime validation' 'Activate Order Splitter after the in-place fixture upgrade'
assert_contains "$ci_workflow" 'wp plugin delete wcos-legacy-1-4-11'
assert_contains "$ci_workflow" 'compat-merge-financial-history-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-hard-off-coordinator-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-fail-stop-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-near-limit-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-confirm-race-worker.php'
assert_contains "$ci_workflow" 'bulk-return-enabled-controller-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-execute-race-worker.php'
assert_contains "$ci_workflow" 'Verify real concurrent overlapping Bulk Return current-row authority'
assert_contains "$ci_workflow" 'name: Resolve wp-env CLI container for concurrent workers'
assert_contains "$ci_workflow" "--filter 'label=com.docker.compose.service=cli'"
assert_contains "$ci_workflow" 'docker inspect --format '\''{{.State.Status}}'\'' "$cli_container"'
assert_occurrences "$ci_workflow" 'docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker"' 11
assert_occurrences "$ci_workflow" 'worker_a_status=$?' 5
assert_occurrences "$ci_workflow" 'worker_b_status=$?' 5
assert_occurrences "$ci_workflow" 'printf '\''worker-a-status=%s\nworker-b-status=%s\n'\'' "$worker_a_status" "$worker_b_status"' 5
assert_absent "$ci_workflow" 'npx wp-env run cli wp eval-file "$worker"'
assert_contains "$ci_workflow" 'bulk-return-ui-readiness-smoke.php'

assert_contains "$ci_workflow" "format('PRECHECK authority only / {0} / {1}'"
assert_contains "$ci_workflow" 'name: Verify PRECHECK topology without publishing protected authority'
assert_contains "$ci_workflow" 'git cat-file -e "$PR_BASE_SHA:.github/scripts/verify-precheck-ci.sh"'
assert_contains "$ci_workflow" 'git show "$PR_BASE_SHA:.github/scripts/verify-precheck-ci.sh" > "$verifier"'
assert_contains "$ci_workflow" 'if: always()'
assert_contains "$ci_workflow" "needs['classify-pr-scope'].outputs.stage == 'FINAL'"
assert_contains "$ci_workflow" 'CI_STAGE: ${{ needs['"'"'classify-pr-scope'"'"'].outputs.stage }}'
assert_contains "$ci_workflow" 'CLASSIFIER_RESULT: ${{ needs['"'"'classify-pr-scope'"'"'].result }}'
assert_contains "$ci_workflow" 'CI_PROFILE: ${{ needs['"'"'classify-pr-scope'"'"'].outputs.profile }}'
assert_contains "$ci_workflow" 'CI_PROFILE_REASON: ${{ needs['"'"'classify-pr-scope'"'"'].outputs.reason }}'
assert_contains "$ci_workflow" 'REVIEW_REQUIRED: ${{ needs['"'"'classify-pr-scope'"'"'].outputs.review_required }}'
assert_contains "$ci_workflow" 'PROFILE_INTEGRATION_RESULT:'
assert_contains "$ci_workflow" 'FINAL_AUTHORITY_RESULT:'
assert_contains "$ci_workflow" 'git show "$PR_BASE_SHA:.github/scripts/verify-required-ci.sh" > "$aggregator"'
assert_contains "$ci_workflow" 'cp .github/scripts/verify-required-ci.sh "$aggregator"'
assert_contains "$ci_workflow" '"$aggregator" \'
assert_contains "$ci_workflow" "grep -Fq 'risk-tiered-v1' \"\$aggregator\""
assert_contains "$ci_workflow" 'DIRECT_CSS_FAST_RESULT:'
assert_contains "$ci_workflow" 'merge_candidate_tree_sha=$(git rev-parse HEAD^{tree})'
assert_contains "$ci_workflow" '.github/scripts/validate-distribution-contract.sh'
assert_absent "$ci_workflow" 'actions/upload-artifact'
assert_absent "$ci_workflow" 'wc-order-splitter.zip'

assert_contains "$main_workflow" 'name: Main attestation'
assert_contains "$main_workflow" '  push:'
assert_contains "$main_workflow" '      - main'
assert_contains "$main_workflow" '  workflow_dispatch:'
assert_contains "$main_workflow" 'expected_sha:'
assert_contains "$main_workflow" 'authority:'
assert_occurrences "$main_workflow" '        required: true' 2
assert_occurrences "$main_workflow" '        type: string' 2
assert_contains "$main_workflow" 'group: main-attestation-${{ github.event_name }}-${{ github.sha }}'
assert_contains "$main_workflow" "if: github.event_name == 'workflow_dispatch'"
assert_occurrences "$main_workflow" "test \"\$GITHUB_REF\" = 'refs/heads/main'" 2
assert_occurrences "$main_workflow" '[[ "$EXPECTED_SHA" =~ ^[0-9a-fA-F]{40}$ ]]' 2
assert_occurrences "$main_workflow" 'expected_sha="$(printf '\''%s'\'' "$EXPECTED_SHA" | tr '\''[:upper:]'\'' '\''[:lower:]'\'')"' 2
assert_occurrences "$main_workflow" 'test "$GITHUB_SHA" = "$expected_sha"' 2
assert_contains "$main_workflow" 'test "$checked_out_sha" = "$expected_sha"'
assert_occurrences "$main_workflow" 'test -n "${ATTESTATION_AUTHORITY//[[:space:]]/}"' 2
assert_contains "$main_workflow" 'git rev-parse HEAD^{tree}'
assert_contains "$main_workflow" 'main_parent_shas=$(git show -s --format=%P HEAD)'
assert_contains "$main_workflow" 'attestation_event_name=$GITHUB_EVENT_NAME'
assert_contains "$main_workflow" 'attestation_run_attempt=$GITHUB_RUN_ATTEMPT'
assert_contains "$main_workflow" 'attestation_authority=$ATTESTATION_AUTHORITY'
assert_contains "$main_workflow" '.github/scripts/validate-distribution-contract.sh'
assert_contains "$main_workflow" "grep -Fq 'self::BULK_RETURN => true' inc/domain/class-wcos-feature-gates.php"
assert_absent "$main_workflow" "grep -Fq 'self::BULK_RETURN => false' inc/domain/class-wcos-feature-gates.php"
assert_contains "$main_workflow" "test ! -e inc/backend/actions/return-order-bulk-action.php"
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
assert_contains "$distribution_script" 'set -euo pipefail'

assert_contains "$agents_contract" 'LOW uses persisted Executor evidence without Independent Review by default'
assert_contains "$agents_contract" 'docs/engineering-review-authority.md'
assert_contains "$agents_contract" 'docs/compressed-engineering-workflow.md'
assert_contains "$agents_contract" 'ChatGPT Create -> Codex Run -> ChatGPT Finalize'
assert_contains "$agents_contract" 'docs/codex-direct-workflow.md'
assert_contains "$agents_contract" '`DIRECT_HUMAN_AUTHORIZED`'
assert_contains "$agents_contract" '`HUMAN_GATE_APPROVED_DIRECT`'
assert_contains "$agents_contract" '`POST_MERGE_ACCEPTED_DIRECT`'
assert_contains "$agents_contract" '`INDEPENDENT_REVIEW_DISPATCH_REQUIRED`'
assert_contains "$agents_contract" '`TECHNICAL_ESCALATION_REQUIRED`'
assert_contains "$agents_contract" '`PRECHECK -> fresh Independent PRE_REVIEW -> FINAL`'
assert_contains "$agents_contract" '`DIRECT_FAST` Required CI'
assert_contains "$agents_contract" 'it has no `TECHNICAL_ACCEPTED` checkpoint'
assert_contains "$agents_contract" '`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`'
assert_contains "$agents_contract" 'Automatic technical correction/re-review orchestration is limited to three head-changing cycles per engineering loop'
assert_contains "$agents_contract" 'Failed Acceptance or drift cannot merge.'
assert_contains "$agents_contract" '`Finalize` never authorizes release, publication, deployment, or a public package.'
assert_contains "$agents_contract" 'Executor evidence cannot become an independent conclusion or Human Gate'

assert_contains "$compressed_contract" '`EXECUTOR_EVIDENCE_READY:'
assert_contains "$compressed_contract" '`PRE_REVIEW_CLEAN`'
assert_contains "$compressed_contract" '`HIGH_FINANCIAL`'
assert_contains "$compressed_contract" '`RELEASE_CERT`'
assert_contains "$compressed_contract" 'WOS-GOV-009 itself bootstraps through the previous FULL'
assert_contains "$compressed_contract" 'does not create or start `WOS-COMPAT-007`'
assert_contains "$compressed_contract" 'Automatic head-changing correction is limited to three cycles; a fourth stops `TECHNICAL_ESCALATION_REQUIRED`.'
assert_contains "$compressed_contract" 'The Executor cannot supply the independent conclusion being promoted.'
assert_contains "$compressed_contract" 'Scope drift from a no-review route into a trigger fails Acceptance and cannot merge.'
assert_contains "$compressed_contract" '`Finalize` never publishes, tags, deploys, creates a public package, or changes production gates.'

assert_contains "$review_contract" 'PRECHECK must not make `Required CI` green.'
assert_contains "$review_contract" 'mechanically persist `TECHNICAL_ACCEPTED` only by binding the exact independent authority ID'
assert_contains "$review_contract" 'Cache stable content, never a technical conclusion.'
assert_contains "$review_contract" '`EXECUTOR_EVIDENCE_READY` for no-review LOW/MEDIUM'
assert_contains "$review_contract" 'The Executor must not invent `PRE_REVIEW_CLEAN`, validate its own source review, or issue a new technical conclusion.'
assert_contains "$review_contract" 'Automatic technical correction is limited to three head-changing cycles; a fourth stops `TECHNICAL_ESCALATION_REQUIRED`.'
assert_contains "$review_contract" 'LOW/no-trigger MEDIUM scope drift into a trigger stops Finalize until reclassified and independently reviewed.'
assert_contains "$review_contract" 'No implementation profile, review, Acceptance, Human Gate, merge, or post-merge record grants tag, package, GitHub Release, WordPress.org publication, deployment, or production-gate authority.'

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
assert_contains "$short_contract" '| Independent Codex Reviewer (fresh context) | `Technical Review <TASK_ID>` |'
assert_contains "$short_contract" '| ChatGPT | `Create <TASK_ID>` |'
assert_contains "$short_contract" '| Codex | `Direct <request>` / `Quick <request>` |'
assert_contains "$short_contract" '| ChatGPT | `Finalize <TASK_ID>` |'
assert_contains "$short_contract" '| ChatGPT | `Acceptance Review <TASK_ID>` |'
assert_contains "$short_contract" 'Command: Acceptance Review <TASK_ID>'
assert_contains "$short_contract" 'Command: Finalize <TASK_ID>'
assert_contains "$short_contract" '`Create <TASK_ID> -> Run <TASK_ID> -> Finalize <TASK_ID>`'
assert_contains "$short_contract" '## Direct bootstrap resolution'
assert_contains "$short_contract" '`CODEX_DIRECT_NOT_ELIGIBLE: <reason> / proposed profile LOW|MEDIUM|HIGH`'
assert_contains "$short_contract" '`CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED: <DIRECT_TASK_ID> / proposed profile LOW|MEDIUM|HIGH / <exact reason>`'
assert_contains "$short_contract" 'INDEPENDENT_REVIEW_DISPATCH_REQUIRED: <TASK_ID>'
assert_contains "$short_contract" '`TECHNICAL_ESCALATION_REQUIRED`'
assert_contains "$short_contract" '## Create bootstrap resolution'
assert_contains "$short_contract" 'If there are zero canonical matches, permit ChatGPT to create the named canonical Issue only after binding the accepted source and authority.'
assert_contains "$short_contract" 'If there is exactly one canonical match, read its complete body and comments and permit only an authorized update consistent with current authority.'
assert_contains "$short_contract" 'If multiple plausible matches exist, stop `TASK_RESOLUTION_REQUIRED`'
assert_contains "$short_contract" 'This normal-workflow bootstrap exception applies only to `Create`.'
assert_contains "$short_contract" 'Before doing substantive work for any command that requires an existing task'
assert_contains "$short_contract" '`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`'
assert_contains "$short_contract" 'persisted authority <Issue comment ID or PR review ID>'
assert_absent "$short_contract" '| ChatGPT | `Technical Review <TASK_ID>` |'
assert_absent "$short_contract" 'independent ChatGPT Technical Review'
assert_absent "$short_contract" 'direct CI/review/merge/post-merge sequence'
assert_absent "$short_contract" 'Executor/CI/Independent Review/direct Human Gate'
assert_contains "$short_contract" 'NEXT_ACTION_HINT'

echo workflow-contract-ok
