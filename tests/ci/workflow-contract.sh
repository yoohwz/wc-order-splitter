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

ruby -e 'require "yaml"; ARGV.each { |file| Psych.parse_file(file) }' \
  "$ci_workflow" "$main_workflow" "$package_workflow"

assert_contains "$ci_workflow" 'pull_request:'
assert_contains "$ci_workflow" 'workflow_dispatch:'
assert_absent "$ci_workflow" '  push:'
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
assert_contains "$ci_workflow" 'merge_candidate_tree_sha=$(git rev-parse HEAD^{tree})'
assert_contains "$ci_workflow" '.github/scripts/validate-distribution-contract.sh'
assert_absent "$ci_workflow" 'wc-order-splitter.zip'
assert_absent "$ci_workflow" 'actions/upload-artifact'

assert_contains "$main_workflow" 'name: Main attestation'
assert_contains "$main_workflow" '  push:'
assert_contains "$main_workflow" '      - main'
assert_contains "$main_workflow" 'git rev-parse HEAD^{tree}'
assert_contains "$main_workflow" 'main_parent_shas=$(git show -s --format=%P HEAD)'
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
assert_contains "$agents_contract" '`INDEPENDENT_REVIEW_DISPATCH_REQUIRED`'
assert_contains "$agents_contract" '`TECHNICAL_ESCALATION_REQUIRED`'
assert_contains "$agents_contract" '`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`'
assert_contains "$agents_contract" 'Chat/session-only `TECHNICAL_ACCEPTED` or `TECHNICAL_CHANGES_REQUIRED` is evidence only.'
assert_absent "$agents_contract" 'independent ChatGPT Technical Review'

assert_contains "$short_command_contract" '| Independent Codex Reviewer (fresh context) | `Technical Review <TASK_ID>` |'
assert_contains "$short_command_contract" '| ChatGPT | `Create <TASK_ID>` |'
assert_contains "$short_command_contract" '| ChatGPT | `Finalize <TASK_ID>` |'
assert_contains "$short_command_contract" '| ChatGPT | `Acceptance Review <TASK_ID>` |'
assert_contains "$short_command_contract" 'Command: Acceptance Review <TASK_ID>'
assert_contains "$short_command_contract" 'Command: Finalize <TASK_ID>'
assert_contains "$short_command_contract" '`Create <TASK_ID> -> Run <TASK_ID> -> Finalize <TASK_ID>`'
assert_contains "$short_command_contract" '`INDEPENDENT_REVIEW_DISPATCH_REQUIRED: <TASK_ID>`'
assert_contains "$short_command_contract" '`TECHNICAL_ESCALATION_REQUIRED: <TASK_ID>`'
assert_contains "$short_command_contract" '## Create bootstrap resolution'
assert_contains "$short_command_contract" 'If there are zero canonical matches, permit ChatGPT to create the named canonical Issue only after binding the accepted source and authority.'
assert_contains "$short_command_contract" 'If there is exactly one canonical match, read its complete body and comments and permit only an authorized update consistent with current authority.'
assert_contains "$short_command_contract" 'If multiple plausible matches exist, stop `TASK_RESOLUTION_REQUIRED`'
assert_contains "$short_command_contract" 'This bootstrap exception applies only to `Create`.'
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
assert_contains "$compressed_workflow_contract" '### LOW'
assert_contains "$compressed_workflow_contract" '### MEDIUM'
assert_contains "$compressed_workflow_contract" '### HIGH'
assert_contains "$compressed_workflow_contract" '`WOS-RETURN-006 — Return UI + Sandbox Readiness`'
assert_contains "$compressed_workflow_contract" '`WOS-RETURN-007 — Return Production Enablement`'
assert_contains "$compressed_workflow_contract" 'Bulk Return starts with one zero-assumption parity/architecture audit.'

assert_contains "$ci_contract" 'Independent Codex Technical Review, ChatGPT Acceptance Review, and Human Gate'
assert_contains "$ci_contract" 'independent Codex `TECHNICAL_ACCEPTED`, ChatGPT `ACCEPTANCE_ACCEPTED`, and Human Gate'
assert_contains "$ci_contract" '`Finalize <TASK_ID>` may merge an accepted implementation or release-bookkeeping PR, but it never invokes the manual package workflow'

echo 'workflow-contract-ok'
