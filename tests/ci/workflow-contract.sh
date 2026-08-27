#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
ci_workflow="$repo_root/.github/workflows/ci.yml"
main_workflow="$repo_root/.github/workflows/main-attestation.yml"
package_workflow="$repo_root/.github/workflows/build-plugin.yml"
distribution_script="$repo_root/.github/scripts/validate-distribution-contract.sh"
agents_contract="$repo_root/AGENTS.md"
short_command_contract="$repo_root/docs/codex-short-command-protocol.md"
review_authority_contract="$repo_root/docs/engineering-review-authority.md"
compressed_workflow_contract="$repo_root/docs/compressed-engineering-workflow.md"
direct_workflow_contract="$repo_root/docs/codex-direct-workflow.md"
ci_contract="$repo_root/docs/ci-workflow-contract.md"

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

assert_occurrences() {
  local file=$1
  local text=$2
  local expected=$3
  local actual
  actual=$(grep -Foc -- "$text" "$file" || true)
  if [[ "$actual" -ne "$expected" ]]; then
    echo "workflow-contract-error: expected '$text' exactly $expected times in $file, found $actual" >&2
    exit 1
  fi
}

assert_before() {
  local file=$1
  local first=$2
  local second=$3
  local first_line
  local second_line
  first_line=$(grep -Fnm1 -- "$first" "$file" | cut -d: -f1 || true)
  second_line=$(grep -Fnm1 -- "$second" "$file" | cut -d: -f1 || true)
  if [[ -z "$first_line" || -z "$second_line" || "$first_line" -ge "$second_line" ]]; then
    echo "workflow-contract-error: expected '$first' before '$second' in $file" >&2
    exit 1
  fi
}

ruby -e 'require "yaml"; ARGV.each { |file| Psych.parse_file(file) }' \
  "$ci_workflow" "$main_workflow" "$package_workflow"

assert_contains "$ci_workflow" 'pull_request:'
assert_contains "$ci_workflow" 'workflow_dispatch:'
assert_absent "$ci_workflow" '  push:'
assert_contains "$ci_workflow" 'classify-pr-scope:'
assert_contains "$ci_workflow" 'name: Classify PR CI profile'
assert_contains "$ci_workflow" 'git cat-file -e "$PR_BASE_SHA:.github/scripts/classify-pr-scope.sh"'
assert_contains "$ci_workflow" 'reason=classifier_not_present_in_base'
assert_contains "$ci_workflow" 'git show "$PR_BASE_SHA:.github/scripts/classify-pr-scope.sh" > "$base_classifier"'
assert_occurrences "$ci_workflow" 'PR_HEAD_REF: ${{ github.head_ref }}' 2
assert_contains "$ci_workflow" '"$base_classifier" "$EVENT_NAME" "$PR_BASE_SHA" "$PR_HEAD_SHA" "$PR_HEAD_REF" "$GITHUB_OUTPUT"'
assert_contains "$ci_workflow" "if: needs['classify-pr-scope'].outputs.profile == 'FULL'"
assert_contains "$ci_workflow" 'direct-css-fast:'
assert_contains "$ci_workflow" 'name: CODEX_DIRECT focused CSS contract'
assert_contains "$ci_workflow" "if: needs['classify-pr-scope'].outputs.profile == 'DIRECT_CSS_FAST'"
assert_contains "$ci_workflow" 'tests/ci/direct-css-fast-contract.sh'
assert_contains "$ci_workflow" 'tests/ci/required-ci-profile-contract.sh'
assert_contains "$ci_workflow" '.github/scripts/classify-pr-scope.sh pull_request "$PR_BASE_SHA" "$PR_HEAD_SHA" "$PR_HEAD_REF"'
assert_contains "$ci_workflow" 'git cat-file -e "$PR_BASE_SHA:.github/scripts/verify-required-ci.sh"'
assert_contains "$ci_workflow" 'git show "$PR_BASE_SHA:.github/scripts/verify-required-ci.sh" > "$aggregator"'
assert_contains "$ci_workflow" 'cp .github/scripts/verify-required-ci.sh "$aggregator"'
assert_contains "$ci_workflow" '"$aggregator" \'
assert_contains "$ci_workflow" "matrix:"
assert_contains "$ci_workflow" "php: ['7.4', '8.1', '8.3']"
assert_contains "$ci_workflow" 'storage: [legacy, hpos, hpos-sync]'
assert_contains "$ci_workflow" 'bulk-return-hard-off-coordinator-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-fail-stop-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-near-limit-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-confirm-race-worker.php'
assert_contains "$ci_workflow" 'bulk-return-enabled-controller-smoke.php'
assert_contains "$ci_workflow" 'bulk-return-execute-race-worker.php'
assert_contains "$ci_workflow" 'Verify real concurrent overlapping Bulk Return current-row authority'
assert_contains "$ci_workflow" 'bulk-return-ui-readiness-smoke.php'
assert_contains "$ci_workflow" 'name: Required CI'
assert_contains "$ci_workflow" 'CLASSIFIER_RESULT: ${{ needs['"'"'classify-pr-scope'"'"'].result }}'
assert_contains "$ci_workflow" 'CI_PROFILE: ${{ needs['"'"'classify-pr-scope'"'"'].outputs.profile }}'
assert_contains "$ci_workflow" 'CI_PROFILE_REASON: ${{ needs['"'"'classify-pr-scope'"'"'].outputs.reason }}'
assert_contains "$ci_workflow" 'DIRECT_CSS_FAST_RESULT: ${{ needs['"'"'direct-css-fast'"'"'].result }}'
assert_contains "$ci_workflow" 'merge_candidate_tree_sha=$(git rev-parse HEAD^{tree})'
assert_contains "$ci_workflow" '.github/scripts/validate-distribution-contract.sh'
assert_absent "$ci_workflow" 'wc-order-splitter.zip'
assert_absent "$ci_workflow" 'actions/upload-artifact'

assert_contains "$main_workflow" 'name: Main attestation'
assert_contains "$main_workflow" '  push:'
assert_contains "$main_workflow" '      - main'
assert_contains "$main_workflow" '  workflow_dispatch:'
assert_contains "$main_workflow" '      expected_sha:'
assert_contains "$main_workflow" '      authority:'
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
assert_contains "$distribution_script" "set -euo pipefail"

assert_contains "$agents_contract" 'docs/engineering-review-authority.md'
assert_contains "$agents_contract" 'docs/compressed-engineering-workflow.md'
assert_contains "$agents_contract" 'fresh Independent Codex Technical Review'
assert_contains "$agents_contract" 'ChatGPT Create -> Codex Run -> ChatGPT Finalize'
assert_contains "$agents_contract" 'docs/codex-direct-workflow.md'
assert_contains "$agents_contract" '`TRIVIAL / CODEX_DIRECT` is the only no-ChatGPT exception.'
assert_contains "$agents_contract" '`DIRECT_HUMAN_AUTHORIZED` before the first source edit'
assert_contains "$agents_contract" '`HUMAN_GATE_APPROVED_DIRECT`'
assert_contains "$agents_contract" '`POST_MERGE_ACCEPTED_DIRECT`'
assert_contains "$agents_contract" '`INDEPENDENT_REVIEW_DISPATCH_REQUIRED`'
assert_contains "$agents_contract" '`TECHNICAL_ESCALATION_REQUIRED`'
assert_contains "$agents_contract" '`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`'
assert_contains "$agents_contract" 'Chat/session-only `TECHNICAL_ACCEPTED` or `TECHNICAL_CHANGES_REQUIRED` is evidence only.'
assert_absent "$agents_contract" 'independent ChatGPT Technical Review'

assert_contains "$short_command_contract" '| Independent Codex Reviewer (fresh context) | `Technical Review <TASK_ID>` |'
assert_contains "$short_command_contract" '| ChatGPT | `Create <TASK_ID>` |'
assert_contains "$short_command_contract" '| Codex | `Direct <request>` / `Quick <request>` |'
assert_contains "$short_command_contract" '| ChatGPT | `Finalize <TASK_ID>` |'
assert_contains "$short_command_contract" '| ChatGPT | `Acceptance Review <TASK_ID>` |'
assert_contains "$short_command_contract" 'Command: Acceptance Review <TASK_ID>'
assert_contains "$short_command_contract" 'Command: Finalize <TASK_ID>'
assert_contains "$short_command_contract" '`Create <TASK_ID> -> Run <TASK_ID> -> Finalize <TASK_ID>`'
assert_contains "$short_command_contract" '## Direct bootstrap resolution'
assert_contains "$short_command_contract" '`CODEX_DIRECT_NOT_ELIGIBLE: <reason> / proposed profile LOW|MEDIUM|HIGH`'
assert_contains "$short_command_contract" '`CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED: <DIRECT_TASK_ID> / proposed profile LOW|MEDIUM|HIGH / <exact reason>`'
assert_contains "$short_command_contract" 'It must never emit `READY_TO_FINALIZE` for a direct task.'
assert_contains "$short_command_contract" '`INDEPENDENT_REVIEW_DISPATCH_REQUIRED: <TASK_ID>`'
assert_contains "$short_command_contract" '`TECHNICAL_ESCALATION_REQUIRED: <TASK_ID>`'
assert_contains "$short_command_contract" '## Create bootstrap resolution'
assert_contains "$short_command_contract" 'If there are zero canonical matches, permit ChatGPT to create the named canonical Issue only after binding the accepted source and authority.'
assert_contains "$short_command_contract" 'If there is exactly one canonical match, read its complete body and comments and permit only an authorized update consistent with current authority.'
assert_contains "$short_command_contract" 'If multiple plausible matches exist, stop `TASK_RESOLUTION_REQUIRED`'
assert_contains "$short_command_contract" 'This normal-workflow bootstrap exception applies only to `Create`.'
assert_contains "$short_command_contract" 'Before doing substantive work for any command that requires an existing task'
assert_contains "$short_command_contract" '`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`'
assert_contains "$short_command_contract" 'Chat/session-only review output is evidence only and cannot authorize correction, Acceptance, or `Finalize`.'
assert_contains "$short_command_contract" 'persisted authority <Issue comment ID or PR review ID>'
assert_absent "$short_command_contract" '| ChatGPT | `Technical Review <TASK_ID>` |'
assert_absent "$short_command_contract" 'independent ChatGPT Technical Review'

assert_contains "$review_authority_contract" 'The executor must not self-issue `TECHNICAL_ACCEPTED`.'
assert_contains "$review_authority_contract" '`INDEPENDENT_REVIEW_AUTHORITY_REQUIRED`'
assert_contains "$review_authority_contract" 'exact-head `TECHNICAL_ACCEPTED` and `ACCEPTANCE_ACCEPTED`'
assert_contains "$review_authority_contract" '`Finalize <TASK_ID>` is an explicit human command and conditional Human Gate'
assert_contains "$review_authority_contract" '`TRIVIAL / CODEX_DIRECT` is the only exception'
assert_contains "$review_authority_contract" 'The executor cannot create `DIRECT_HUMAN_AUTHORIZED` after source edits, cannot self-review'
assert_contains "$review_authority_contract" '`HUMAN_GATE_APPROVED_DIRECT`'
assert_contains "$review_authority_contract" '`WOS-RETURN-006 — Return UI + Sandbox Readiness`'
assert_contains "$review_authority_contract" 'automatically persist the complete structured review to canonical GitHub authority'
assert_contains "$review_authority_contract" 'A chat/session-only `TECHNICAL_ACCEPTED` or `TECHNICAL_CHANGES_REQUIRED` is evidence only'
assert_contains "$review_authority_contract" '`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`'

assert_contains "$compressed_workflow_contract" '`ChatGPT Create -> Codex Run -> ChatGPT Finalize`'
assert_contains "$compressed_workflow_contract" '`INDEPENDENT_REVIEW_DISPATCH_REQUIRED: <TASK_ID>`'
assert_contains "$compressed_workflow_contract" '`TECHNICAL_ESCALATION_REQUIRED: <TASK_ID>`'
assert_contains "$compressed_workflow_contract" 'automatically persists the complete structured Technical Review record to canonical GitHub authority'
assert_contains "$compressed_workflow_contract" 'A chat/session-only `TECHNICAL_ACCEPTED` or `TECHNICAL_CHANGES_REQUIRED` is evidence only.'
assert_contains "$compressed_workflow_contract" '`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`'
assert_contains "$compressed_workflow_contract" 'persisted authority <Issue comment ID or PR review ID>'
assert_contains "$compressed_workflow_contract" 'Reject conversation/session-only review text.'
assert_contains "$compressed_workflow_contract" 'at most three head-changing technical correction cycles'
assert_contains "$compressed_workflow_contract" 'The Executor must never review itself or issue `TECHNICAL_ACCEPTED`.'
assert_contains "$compressed_workflow_contract" 'Failed Acceptance or drift cannot merge.'
assert_contains "$compressed_workflow_contract" '`Finalize` never grants tag, release, publication, deployment, or public-package authority.'
assert_contains "$compressed_workflow_contract" '### TRIVIAL / CODEX_DIRECT'
assert_contains "$compressed_workflow_contract" '### LOW'
assert_contains "$compressed_workflow_contract" '### MEDIUM'
assert_contains "$compressed_workflow_contract" '### HIGH'
assert_contains "$compressed_workflow_contract" '`WOS-RETURN-006 — Return UI + Sandbox Readiness`'
assert_contains "$compressed_workflow_contract" '`WOS-RETURN-007 — Return Production Enablement`'
assert_contains "$compressed_workflow_contract" 'Bulk Return starts with one zero-assumption parity/architecture audit.'
assert_before "$compressed_workflow_contract" '### TRIVIAL / CODEX_DIRECT' '### LOW'

assert_contains "$direct_workflow_contract" 'This workflow becomes active only after `WOS-GOV-007` has canonical `POST_MERGE_ACCEPTED`.'
assert_contains "$direct_workflow_contract" 'persist `DIRECT_HUMAN_AUTHORIZED` in that Issue before source edits'
assert_contains "$direct_workflow_contract" 'The executor cannot self-accept.'
assert_contains "$direct_workflow_contract" 'existing, Git-tracked, regular-text `*.css` presentation files beneath `css/`'
assert_contains "$direct_workflow_contract" 'Documentation, copy, and translation are not in the initial direct allowlist'
assert_contains "$direct_workflow_contract" '`CODEX_DIRECT_NOT_ELIGIBLE: <reason> / proposed profile LOW|MEDIUM|HIGH`'
assert_contains "$direct_workflow_contract" '`CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED: <DIRECT_TASK_ID> / proposed profile LOW|MEDIUM|HIGH / <exact reason>`'
assert_contains "$direct_workflow_contract" 'protected-branch `Required CI` success with artifacts=`0`'
assert_contains "$direct_workflow_contract" '`DIRECT_CSS_FAST`'
assert_contains "$direct_workflow_contract" '`FULL`'
assert_contains "$direct_workflow_contract" 'Fresh Independent Codex Review remains mandatory'
assert_contains "$direct_workflow_contract" '`TECHNICAL_ACCEPTED: <DIRECT_TASK_ID> / TRIVIAL / CODEX_DIRECT / PR #N / exact head <SHA> / direct eligibility confirmed / persisted authority <ID>`'
assert_contains "$direct_workflow_contract" '`HUMAN_GATE_APPROVED_DIRECT: <DIRECT_TASK_ID> / PR #N / exact head <SHA> / derived from DIRECT_HUMAN_AUTHORIZED <ID>`'
assert_contains "$direct_workflow_contract" '`POST_MERGE_ACCEPTED_DIRECT: <DIRECT_TASK_ID> / PR #N / main <SHA> / tree <TREE_SHA> / Main attestation <RUN_ID> / artifacts=0`'
assert_contains "$direct_workflow_contract" 'requires no ChatGPT `Acceptance Review`, `ACCEPTANCE_ACCEPTED`, or `Finalize`.'
assert_contains "$direct_workflow_contract" 'CODEX_DIRECT never authorizes version or stable-tag changes'
assert_absent "$direct_workflow_contract" 'Executor may self-issue `TECHNICAL_ACCEPTED`'

assert_contains "$ci_contract" 'Independent Codex Technical Review, ChatGPT Acceptance Review where applicable, and Human Gate'
assert_contains "$ci_contract" 'independent Codex `TECHNICAL_ACCEPTED`, ChatGPT `ACCEPTANCE_ACCEPTED` where applicable, and Human Gate'
assert_contains "$ci_contract" '`Finalize <TASK_ID>` may merge an accepted implementation or release-bookkeeping PR, but it never invokes the manual package workflow'
assert_contains "$ci_contract" 'The sole `TRIVIAL / CODEX_DIRECT` exception omits ChatGPT Acceptance only under `docs/codex-direct-workflow.md`'
assert_contains "$ci_contract" '`DIRECT_HUMAN_AUTHORIZED`, direct-eligibility-confirmed `TECHNICAL_ACCEPTED`, and `HUMAN_GATE_APPROVED_DIRECT`'
assert_contains "$ci_contract" '`DIRECT_CSS_FAST`'
assert_contains "$ci_contract" '`FULL`'
assert_contains "$ci_contract" 'exact protected context name remains `Required CI`'

echo 'workflow-contract-ok'
