#!/usr/bin/env bash

set -euo pipefail

body_file=${1:-}
task_id=${2:-}
pr_number=${3:-}
task_issue_number=${4:-}
base_sha=${5:-}
head_sha=${6:-}
head_tree=${7:-}

[[ -f "$body_file" ]] || { echo 'pre-review-record-error: body file is missing' >&2; exit 1; }
[[ "$task_id" =~ ^WOS-[A-Z0-9-]+$ ]] || { echo 'pre-review-record-error: invalid task ID' >&2; exit 1; }
[[ "$pr_number" =~ ^[1-9][0-9]*$ ]] || { echo 'pre-review-record-error: invalid PR number' >&2; exit 1; }
[[ "$task_issue_number" =~ ^[1-9][0-9]*$ ]] || { echo 'pre-review-record-error: invalid task Issue number' >&2; exit 1; }
for sha in "$base_sha" "$head_sha" "$head_tree"; do
  [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'pre-review-record-error: invalid exact SHA/tree' >&2; exit 1; }
done

require_line() {
  local line=$1
  grep -Fqx -- "$line" "$body_file" || {
    echo "pre-review-record-error: missing exact attestation: $line" >&2
    exit 1
  }
}

require_line 'Role: independent_codex_reviewer'
require_line 'Fresh context: yes'
require_line 'Executor session reused: no'
require_line 'Source read-only/no-implementation-write: yes'
require_line 'Complete diff reviewed: yes'
require_line 'PRECHECK evidence reviewed: yes'
require_line 'Blocking findings: none'
require_line "Canonical Issue: #$task_issue_number"
require_line "Exact base: $base_sha"
require_line "Exact head: $head_sha"
require_line "Exact head tree: $head_tree"
require_line "PRE_REVIEW_CLEAN: $task_id / PR #$pr_number / exact head $head_sha"

precheck_line=$(grep -E '^PRECHECK run: [1-9][0-9]* / completed/success / artifacts=0$' "$body_file" || true)
[[ -n "$precheck_line" ]] || {
  echo 'pre-review-record-error: missing successful exact PRECHECK run attestation' >&2
  exit 1
}
[[ "$(printf '%s\n' "$precheck_line" | wc -l | tr -d ' ')" -eq 1 ]] || {
  echo 'pre-review-record-error: ambiguous PRECHECK run attestation' >&2
  exit 1
}
run_id=$(printf '%s\n' "$precheck_line" | sed -E 's/^PRECHECK run: ([0-9]+) \/.*/\1/')
printf 'precheck_run_id=%s\n' "$run_id"
printf 'pre_review_record_ok=1\n'
