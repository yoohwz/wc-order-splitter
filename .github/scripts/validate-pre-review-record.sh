#!/usr/bin/env bash

set -euo pipefail

body_file=${1:-}
task_id=${2:-}
pr_number=${3:-}
task_issue_number=${4:-}
base_sha=${5:-}
head_sha=${6:-}
head_tree=${7:-}
expected_profile=${8:-}

[[ -f "$body_file" ]] || { echo 'pre-review-record-error: body file is missing' >&2; exit 1; }
[[ "$task_id" =~ ^WOS-[A-Z0-9-]+$ ]] || { echo 'pre-review-record-error: invalid task ID' >&2; exit 1; }
[[ "$pr_number" =~ ^[1-9][0-9]*$ ]] || { echo 'pre-review-record-error: invalid PR number' >&2; exit 1; }
[[ "$task_issue_number" =~ ^[1-9][0-9]*$ ]] || { echo 'pre-review-record-error: invalid task Issue number' >&2; exit 1; }
for sha in "$base_sha" "$head_sha" "$head_tree"; do
  [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'pre-review-record-error: invalid exact SHA/tree' >&2; exit 1; }
done
case "$expected_profile" in MEDIUM_DOMAIN|HIGH_DEEP|HIGH_FINANCIAL|RELEASE_CERT) ;; *)
  echo 'pre-review-record-error: invalid expected PRECHECK profile' >&2
  exit 1
  ;;
esac

require_unique_line() {
  local line=$1
  local count
  count=$(grep -Fxc -- "$line" "$body_file" || true)
  [[ "$count" -eq 1 ]] || {
    if [[ "$count" -eq 0 ]]; then
      echo "pre-review-record-error: missing exact attestation: $line" >&2
    else
      echo "pre-review-record-error: duplicate exact attestation: $line" >&2
    fi
    exit 1
  }
}

require_unique_prefix() {
  local prefix=$1
  local count
  count=$(grep -Fc -- "$prefix" "$body_file" || true)
  [[ "$count" -eq 1 ]] || {
    echo "pre-review-record-error: ambiguous attestation prefix: $prefix" >&2
    exit 1
  }
}

header="## Independent Codex PRE_REVIEW — $task_id"
terminal="PRE_REVIEW_CLEAN: $task_id / PR #$pr_number / exact head $head_sha"
first_nonempty=$(awk 'NF { print; exit }' "$body_file")
last_nonempty=$(awk 'NF { line=$0 } END { print line }' "$body_file")
[[ "$first_nonempty" == "$header" ]] || {
  echo 'pre-review-record-error: canonical header must be the first nonempty line' >&2
  exit 1
}
[[ "$last_nonempty" == "$terminal" ]] || {
  echo 'pre-review-record-error: clean terminal must be the last nonempty line' >&2
  exit 1
}
if grep -Eiq '(`|~~~|[<>])' "$body_file"; then
  echo 'pre-review-record-error: quoted or fenced authority is not accepted' >&2
  exit 1
fi
if grep -Eq '(PRE_REVIEW_CHANGES_REQUIRED|TECHNICAL_ACCEPTED|TECHNICAL_CHANGES_REQUIRED):' "$body_file"; then
  echo 'pre-review-record-error: conflicting review outcome is present' >&2
  exit 1
fi

require_unique_line "$header"
require_unique_line 'Role: independent_codex_reviewer'
require_unique_line 'Fresh context: yes'
require_unique_line 'Executor session reused: no'
require_unique_line 'Source read-only/no-implementation-write: yes'
require_unique_line 'Complete diff reviewed: yes'
require_unique_line 'PRECHECK evidence reviewed: yes'
require_unique_line 'Blocking findings: none'
require_unique_line "Canonical Issue: #$task_issue_number"
require_unique_line "Exact base: $base_sha"
require_unique_line "Exact head: $head_sha"
require_unique_line "Exact head tree: $head_tree"
require_unique_line "PRECHECK profile: $expected_profile / stage PRECHECK"
require_unique_line "$terminal"

for prefix in \
  'Role:' \
  'Fresh context:' \
  'Executor session reused:' \
  'Source read-only/no-implementation-write:' \
  'Complete diff reviewed:' \
  'PRECHECK evidence reviewed:' \
  'Blocking findings:' \
  'Canonical Issue:' \
  'Exact base:' \
  'Exact head:' \
  'Exact head tree:' \
  'PRECHECK profile:' \
  'PRECHECK run:' \
  'PRE_REVIEW_CLEAN:'; do
  require_unique_prefix "$prefix"
done

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
