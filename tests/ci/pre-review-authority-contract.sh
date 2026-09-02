#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
validator="$repo_root/.github/scripts/validate-pre-review-record.sh"
authority_verifier="$repo_root/.github/scripts/verify-pre-review-authority.sh"
record=$(mktemp)
mock_bin=$(mktemp -d)
trap 'rm -f "$record"; rm -rf "$mock_bin"' EXIT

task=WOS-GOV-999
pr=321
issue=654
base=1111111111111111111111111111111111111111
head=2222222222222222222222222222222222222222
tree=3333333333333333333333333333333333333333

write_valid_record() {
  {
    echo "## Independent Codex PRE_REVIEW — $task"
    echo
    echo 'Role: independent_codex_reviewer'
    echo 'Fresh context: yes'
    echo 'Executor session reused: no'
    echo 'Source read-only/no-implementation-write: yes'
    echo 'Complete diff reviewed: yes'
    echo 'PRECHECK evidence reviewed: yes'
    echo 'Blocking findings: none'
    echo "Canonical Issue: #$issue"
    echo "Exact base: $base"
    echo "Exact head: $head"
    echo "Exact head tree: $tree"
    echo 'PRECHECK run: 987654 / completed/success / artifacts=0'
    echo "PRE_REVIEW_CLEAN: $task / PR #$pr / exact head $head"
  } > "$record"
}

expect_failure() {
  if "$@" >/dev/null 2>&1; then
    echo "pre-review-authority-contract-error: unexpectedly accepted: $*" >&2
    exit 1
  fi
}

write_valid_record
"$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" | grep -Fq 'precheck_run_id=987654'
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" 4444444444444444444444444444444444444444 "$tree"
expect_failure "$validator" "$record" WOS-OTHER-999 "$pr" "$issue" "$base" "$head" "$tree"
expect_failure "$validator" "$record" "$task" "$pr" 999 "$base" "$head" "$tree"

write_valid_record
sed '/Fresh context: yes/d' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree"

write_valid_record
sed 's/completed\/success/completed\/failure/' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree"

write_valid_record
printf '%s\n' 'PRECHECK run: 987655 / completed/success / artifacts=0' >> "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree"

write_valid_record
printf '%s\n' 'Blocking findings: copied clean signal' >> "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree"

write_valid_record
printf '%s\n' "PRE_REVIEW_CHANGES_REQUIRED: $task / PR #$pr / exact head $head" >> "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree"

write_valid_record
printf '%s\n' "PRE_REVIEW_CLEAN: $task / PR #$pr / exact head $head" >> "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree"

write_valid_record
{ echo '```text'; cat "$record"; echo '```'; } > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree"

write_valid_record
sed 's/^Role:/> Role:/' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree"

write_valid_record
cat > "$mock_bin/gh" <<'MOCK_GH'
#!/usr/bin/env bash
set -euo pipefail
test "${1:-}" = api
endpoint=${2:-}
case "$endpoint" in
  repos/fixture/repo/issues/comments/42)
    jq -n --rawfile body "$MOCK_RECORD" '{author_association:"OWNER",issue_url:"https://api.github.test/repos/fixture/repo/issues/654",body:$body}'
    ;;
  repos/fixture/repo/pulls/321/reviews/43)
    jq -n --rawfile body "$MOCK_RECORD" --arg commit_id "${MOCK_REVIEW_COMMIT:-2222222222222222222222222222222222222222}" \
      '{author_association:"OWNER",commit_id:$commit_id,body:$body}'
    ;;
  repos/fixture/repo/actions/runs/987654)
    jq -n \
      --argjson pr "${MOCK_PR_NUMBER:-321}" \
      --arg base "${MOCK_BASE:-1111111111111111111111111111111111111111}" \
      --arg head "$MOCK_HEAD" \
      '{status:"completed",conclusion:"success",head_sha:$head,event:"pull_request",path:".github/workflows/ci.yml",pull_requests:[{number:$pr,base:{sha:$base},head:{sha:$head}}]}'
    ;;
  repos/fixture/repo/actions/runs/987654/artifacts)
    echo 0
    ;;
  'repos/fixture/repo/actions/runs/987654/jobs?per_page=100')
    jq -n --arg required "${MOCK_REQUIRED_RESULT:-skipped}" \
      '{jobs: ([{name:"Risk-tiered PRECHECK / deterministic contracts",conclusion:"success"}] + (if $required == "missing" then [] else [{name:"Required CI",conclusion:$required}] end))}'
    ;;
  *)
    echo "unexpected mock endpoint: $endpoint" >&2
    exit 1
    ;;
esac
MOCK_GH
chmod +x "$mock_bin/gh"

PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" issue-comment:42 \
  | grep -Fq 'pre-review-authority-ok'
PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" pr-review:43 \
  | grep -Fq 'pre-review-authority-ok'

expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_REVIEW_COMMIT=4444444444444444444444444444444444444444 WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" pr-review:43

expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD=4444444444444444444444444444444444444444 WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_PR_NUMBER=999 WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_REQUIRED_RESULT=success WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_REQUIRED_RESULT=missing WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" issue-comment:42

echo pre-review-authority-contract-ok
