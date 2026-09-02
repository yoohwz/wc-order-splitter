#!/usr/bin/env bash

set -euo pipefail

repo=${1:-}
pr_number=${2:-}
task_id=${3:-}
task_issue_number=${4:-}
base_sha=${5:-}
head_sha=${6:-}
head_tree=${7:-}
authority=${8:-}
output_file=${9:-}
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
trap 'rm -f "$record_json" "$body_file" "$jobs_json"' EXIT

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
fi

association=$(jq -r '.author_association // ""' "$record_json")
[[ "$association" == OWNER ]] || {
  echo "pre-review-authority-error: reviewer actor association is $association, expected OWNER structured provenance" >&2
  exit 1
}
jq -r '.body // ""' "$record_json" > "$body_file"
validation=$("$validator" "$body_file" "$task_id" "$pr_number" "$task_issue_number" "$base_sha" "$head_sha" "$head_tree")
run_id=$(printf '%s\n' "$validation" | sed -n 's/^precheck_run_id=//p')
[[ "$run_id" =~ ^[1-9][0-9]*$ ]] || { echo 'pre-review-authority-error: validator returned no PRECHECK run' >&2; exit 1; }

run_json=$(gh api "repos/$repo/actions/runs/$run_id")
require_value run-status "$(jq -r '.status' <<< "$run_json")" completed
require_value run-conclusion "$(jq -r '.conclusion' <<< "$run_json")" success
require_value run-head "$(jq -r '.head_sha' <<< "$run_json")" "$head_sha"
require_value run-event "$(jq -r '.event' <<< "$run_json")" pull_request
require_value workflow-path "$(jq -r '.path' <<< "$run_json")" .github/workflows/ci.yml
require_value artifact-count "$(gh api "repos/$repo/actions/runs/$run_id/artifacts" --jq '.total_count')" 0
jq -e --argjson pr "$pr_number" --arg base "$base_sha" --arg head "$head_sha" \
  '.pull_requests | any(.number == $pr and .base.sha == $base and .head.sha == $head)' \
  <<< "$run_json" >/dev/null || {
  echo 'pre-review-authority-error: PRECHECK run is not bound to the exact PR/base/head' >&2
  exit 1
}

gh api "repos/$repo/actions/runs/$run_id/jobs?per_page=100" > "$jobs_json"
jq -e '[.jobs[] | select(.name == "Risk-tiered PRECHECK / deterministic contracts")] | length == 1 and .[0].conclusion == "success"' "$jobs_json" >/dev/null || {
  echo 'pre-review-authority-error: PRECHECK job did not succeed' >&2
  exit 1
}
jq -e '[.jobs[] | select(.name == "Required CI")] | length == 1 and .[0].conclusion == "skipped"' "$jobs_json" >/dev/null || {
  echo 'pre-review-authority-error: PRECHECK run did not emit exactly one skipped Required CI' >&2
  exit 1
}

if [[ -n "$output_file" ]]; then
  {
    printf 'pre_review_authority=%s\n' "$authority"
    printf 'precheck_run_id=%s\n' "$run_id"
  } >> "$output_file"
fi
echo "pre-review-authority-ok authority=$authority run=$run_id head=$head_sha"
