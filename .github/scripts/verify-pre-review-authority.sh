#!/usr/bin/env bash

set -euo pipefail

repo=${1:-}
pr_number=${2:-}
task_id=${3:-}
task_issue_number=${4:-}
base_sha=${5:-}
head_sha=${6:-}
head_tree=${7:-}
expected_profile=${8:-}
authority=${9:-}
output_file=${10:-}
repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
validator=${WCOS_PRE_REVIEW_VALIDATOR:-$repo_root/.github/scripts/validate-pre-review-record.sh}

[[ "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || { echo 'pre-review-authority-error: invalid repository' >&2; exit 1; }
[[ "$task_issue_number" =~ ^[1-9][0-9]*$ ]] || { echo 'pre-review-authority-error: invalid task Issue number' >&2; exit 1; }
[[ "$authority" =~ ^(issue-comment|pr-review):([1-9][0-9]*)$ ]] || { echo 'pre-review-authority-error: invalid authority reference' >&2; exit 1; }
kind=${BASH_REMATCH[1]}
record_id=${BASH_REMATCH[2]}

record_json=$(mktemp)
body_file=$(mktemp)
jobs_json=$(mktemp)
pr_json=$(mktemp)
trap 'rm -f "$record_json" "$body_file" "$jobs_json" "$pr_json"' EXIT

require_value() {
  local label=$1 actual=$2 expected=$3
  if [[ "$actual" != "$expected" ]]; then
    echo "pre-review-authority-error: $label is $actual, expected $expected" >&2
    exit 1
  fi
}

if [[ "$kind" == issue-comment ]]; then
  gh api "repos/$repo/issues/comments/$record_id" > "$record_json"
  [[ "$(jq -r '.issue_url' "$record_json")" == */issues/$task_issue_number ]] || {
    echo 'pre-review-authority-error: issue comment is not on the canonical task Issue' >&2
    exit 1
  }
else
  gh api "repos/$repo/pulls/$pr_number/reviews/$record_id" > "$record_json"
  require_value review-commit "$(jq -r '.commit_id // ""' "$record_json")" "$head_sha"
fi

association=$(jq -r '.author_association // ""' "$record_json")
[[ "$association" == OWNER ]] || {
  echo "pre-review-authority-error: reviewer actor association is $association, expected OWNER structured provenance" >&2
  exit 1
}
jq -r '.body // ""' "$record_json" > "$body_file"
validation=$("$validator" "$body_file" "$task_id" "$pr_number" "$task_issue_number" "$base_sha" "$head_sha" "$head_tree" "$expected_profile")
run_id=$(printf '%s\n' "$validation" | sed -n 's/^precheck_run_id=//p')
[[ "$run_id" =~ ^[1-9][0-9]*$ ]] || { echo 'pre-review-authority-error: validator returned no PRECHECK run' >&2; exit 1; }

run_json=$(gh api "repos/$repo/actions/runs/$run_id")
require_value run-status "$(jq -r '.status' <<< "$run_json")" completed
require_value run-conclusion "$(jq -r '.conclusion' <<< "$run_json")" success
require_value run-head "$(jq -r '.head_sha' <<< "$run_json")" "$head_sha"
run_event=$(jq -r '.event' <<< "$run_json")
if [[ "$run_event" != workflow_dispatch ]]; then
  echo "pre-review-authority-error: run-event is $run_event, expected task-bound workflow_dispatch" >&2
  exit 1
fi
require_value workflow-path "$(jq -r '.path' <<< "$run_json")" .github/workflows/ci.yml
require_value artifact-count "$(gh api "repos/$repo/actions/runs/$run_id/artifacts" --jq '.total_count')" 0
gh api "repos/$repo/pulls/$pr_number" > "$pr_json"
require_value pr-state "$(jq -r '.state' "$pr_json")" open
require_value pr-base "$(jq -r '.base.sha' "$pr_json")" "$base_sha"
require_value pr-head "$(jq -r '.head.sha' "$pr_json")" "$head_sha"

gh api "repos/$repo/actions/runs/$run_id/jobs?per_page=100" > "$jobs_json"
expected_precheck_job="Risk-tiered PRECHECK / $task_id / $expected_profile"
expected_authority_job="PRECHECK authority only / $task_id / $expected_profile"
jq -e --arg expected "$expected_precheck_job" \
  '[.jobs[] | select(.name | startswith("Risk-tiered PRECHECK / "))] | length == 1 and .[0].name == $expected and .[0].conclusion == "success"' \
  "$jobs_json" >/dev/null || {
  echo "pre-review-authority-error: exact task/profile/stage PRECHECK job did not succeed: $expected_precheck_job" >&2
  exit 1
}
jq -e '[.jobs[] | select(.name == "Required CI")] | length == 0' "$jobs_json" >/dev/null || {
  echo 'pre-review-authority-error: PRECHECK run published the protected Required CI context' >&2
  exit 1
}
jq -e --arg expected "$expected_authority_job" \
  '[.jobs[] | select(.name | startswith("PRECHECK authority only / "))] | length == 1 and .[0].name == $expected and .[0].conclusion == "success"' \
  "$jobs_json" >/dev/null || {
  echo "pre-review-authority-error: exact task/profile/stage authority topology did not succeed: $expected_authority_job" >&2
  exit 1
}

if [[ -n "$output_file" ]]; then
  {
    printf 'pre_review_authority=%s\n' "$authority"
    printf 'precheck_run_id=%s\n' "$run_id"
  } >> "$output_file"
fi
echo "pre-review-authority-ok authority=$authority run=$run_id head=$head_sha"
