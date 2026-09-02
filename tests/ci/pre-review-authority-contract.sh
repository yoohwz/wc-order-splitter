#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
validator="$repo_root/.github/scripts/validate-pre-review-record.sh"
authority_verifier="$repo_root/.github/scripts/verify-pre-review-authority.sh"
classifier="$repo_root/.github/scripts/classify-pr-scope.sh"
precheck_verifier="$repo_root/.github/scripts/verify-precheck-ci.sh"
final_aggregator="$repo_root/.github/scripts/verify-required-ci.sh"
record=$(mktemp)
mock_bin=$(mktemp -d)
floor_fixture=$(mktemp -d)
classifier_output=$(mktemp)
authority_output=$(mktemp)
trap 'rm -f "$record" "$classifier_output" "$authority_output"; rm -rf "$mock_bin" "$floor_fixture"' EXIT

task=WOS-GOV-999
pr=321
issue=654
base=1111111111111111111111111111111111111111
head=2222222222222222222222222222222222222222
tree=3333333333333333333333333333333333333333
profile=HIGH_DEEP

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
    echo "PRECHECK profile: $profile / stage PRECHECK"
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
"$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile" | grep -Fq 'precheck_run_id=987654'
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" 4444444444444444444444444444444444444444 "$tree" "$profile"
expect_failure "$validator" "$record" WOS-OTHER-999 "$pr" "$issue" "$base" "$head" "$tree" "$profile"
expect_failure "$validator" "$record" "$task" "$pr" 999 "$base" "$head" "$tree" "$profile"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" HIGH_FINANCIAL

write_valid_record
sed '/Fresh context: yes/d' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
sed 's/completed\/success/completed\/failure/' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
printf '%s\n' 'PRECHECK run: 987655 / completed/success / artifacts=0' >> "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
printf '%s\n' 'Blocking findings: copied clean signal' >> "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
printf '%s\n' "PRE_REVIEW_CHANGES_REQUIRED: $task / PR #$pr / exact head $head" >> "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
printf '%s\n' "PRE_REVIEW_CLEAN: $task / PR #$pr / exact head $head" >> "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
{ echo '```text'; cat "$record"; echo '```'; } > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
sed 's/^Role:/> Role:/' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
awk '/^Role:/ { print "~~~text" } { print }' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
awk '/^Role:/ { print "<!--" } { print }' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
awk '/^Role:/ { print "<pre>" } { print }' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
awk -v conflict="  PRE_REVIEW_CHANGES_REQUIRED: $task / PR #$pr / exact head $head" \
  '/^PRE_REVIEW_CLEAN:/ { print conflict } { print }' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
awk '/^PRE_REVIEW_CLEAN:/ { print "  PRECHECK run: 123456 / completed/failure / artifacts=9" } { print }' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
awk '/^Role:/ { print "<details><summary>copied authority</summary></details>" } { print }' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
awk '/^Role:/ { print "<div><details>nested</details></div>" } { print }' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
awk '/^Role:/ { print "Copied `PRE_REVIEW_CLEAN` authority" } { print }' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

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
  repos/fixture/repo/pulls/321)
    jq -n --arg base "${MOCK_BASE:-1111111111111111111111111111111111111111}" --arg head "$MOCK_HEAD" \
      '{state:"open",base:{sha:$base},head:{sha:$head}}'
    ;;
  repos/fixture/repo/actions/runs/987654)
    jq -n \
      --argjson pr "${MOCK_PR_NUMBER:-321}" \
      --arg base "${MOCK_BASE:-1111111111111111111111111111111111111111}" \
      --arg head "$MOCK_HEAD" \
      --arg event "${MOCK_RUN_EVENT:-workflow_dispatch}" \
      '{status:"completed",conclusion:"success",head_sha:$head,event:$event,path:".github/workflows/ci.yml",pull_requests:[{number:$pr,base:{sha:$base},head:{sha:$head}}]}'
    ;;
  repos/fixture/repo/actions/runs/987654/artifacts)
    echo 0
    ;;
  'repos/fixture/repo/actions/runs/987654/jobs?per_page=100')
    jq -n \
      --arg protected "${MOCK_PROTECTED_RESULT:-missing}" \
      --arg authority "${MOCK_PRECHECK_AUTHORITY_RESULT:-success}" \
      --arg task "${MOCK_JOB_TASK:-WOS-GOV-999}" \
      --arg profile "${MOCK_JOB_PROFILE:-HIGH_DEEP}" \
      --arg stage "${MOCK_JOB_STAGE:-PRECHECK}" \
      '{jobs: ([{name:("Risk-tiered " + $stage + " / " + $task + " / " + $profile),conclusion:"success"}] + (if $authority == "missing" then [] else [{name:("PRECHECK authority only / " + $task + " / " + $profile),conclusion:$authority}] end) + (if $protected == "missing" then [] else [{name:"Required CI",conclusion:$protected}] end))}'
    ;;
  *)
    echo "unexpected mock endpoint: $endpoint" >&2
    exit 1
    ;;
esac
MOCK_GH
chmod +x "$mock_bin/gh"

PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42 \
  | grep -Fq 'pre-review-authority-ok'
PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" pr-review:43 \
  | grep -Fq 'pre-review-authority-ok'

expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_REVIEW_COMMIT=4444444444444444444444444444444444444444 WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" pr-review:43

expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD=4444444444444444444444444444444444444444 WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_RUN_EVENT=pull_request WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_JOB_PROFILE=HIGH_FINANCIAL WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_JOB_TASK=WOS-GOV-OTHER WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_JOB_STAGE=FINAL WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_PROTECTED_RESULT=success WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_PROTECTED_RESULT=skipped WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_PRECHECK_AUTHORITY_RESULT=missing WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_PRECHECK_AUTHORITY_RESULT=failure WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_RUN_EVENT=push WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_RUN_EVENT=workflow_dispatch MOCK_BASE=9999999999999999999999999999999999999999 WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42

# Prove the independent Task Capsule review floor can require review without
# inflating a mechanically LOW CI profile, and that the same LOW_FOCUSED
# authority survives PRECHECK record authentication into FINAL topology.
git -C "$floor_fixture" init -q
git -C "$floor_fixture" config user.name 'Contract Fixture'
git -C "$floor_fixture" config user.email 'fixture@example.test'
printf '%s\n' 'fixture baseline' > "$floor_fixture/README.md"
git -C "$floor_fixture" add README.md
git -C "$floor_fixture" commit -qm baseline
floor_base=$(git -C "$floor_fixture" rev-parse HEAD)
mkdir -p "$floor_fixture/css"
printf '%s\n' '.wcos-card {' '  border-radius: 4px;' '}' > "$floor_fixture/css/low.css"
git -C "$floor_fixture" add css/low.css
git -C "$floor_fixture" commit -qm low-css
floor_head=$(git -C "$floor_fixture" rev-parse HEAD)

(
  cd "$floor_fixture"
  "$classifier" pull_request "$floor_base" "$floor_head" codex/fixture "$classifier_output"
)
grep -Fqx 'profile=LOW_FOCUSED' "$classifier_output"
grep -Fqx 'assurance=LOW' "$classifier_output"
grep -Fqx 'review_required=false' "$classifier_output"
grep -Fqx 'stage=FINAL' "$classifier_output"

: > "$classifier_output"
(
  cd "$floor_fixture"
  "$classifier" pull_request "$floor_base" "$floor_head" codex/fixture "$classifier_output" LOW_FOCUSED LOW REQUIRED
)
grep -Fqx 'profile=LOW_FOCUSED' "$classifier_output"
grep -Fqx 'assurance=LOW' "$classifier_output"
grep -Fqx 'review_required=true' "$classifier_output"
grep -Fqx 'stage=PRECHECK' "$classifier_output"
if grep -Eq '^profile=(MEDIUM_DOMAIN|HIGH_DEEP|HIGH_FINANCIAL|RELEASE_CERT)$' "$classifier_output"; then
  echo 'pre-review-authority-contract-error: review floor inflated the LOW_FOCUSED CI profile' >&2
  exit 1
fi

"$precheck_verifier" success LOW_FOCUSED PRECHECK true skipped skipped skipped skipped skipped skipped success skipped skipped >/dev/null

profile=LOW_FOCUSED
write_valid_record
"$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile" | grep -Fq 'precheck_run_id=987654'
: > "$authority_output"
PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_JOB_PROFILE=LOW_FOCUSED WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42 "$authority_output" \
  | grep -Fq 'pre-review-authority-ok'
grep -Fqx 'pre_review_authority=issue-comment:42' "$authority_output"
grep -Fqx 'precheck_run_id=987654' "$authority_output"
"$final_aggregator" success LOW_FOCUSED low_review_floor FINAL true skipped skipped skipped skipped success skipped skipped skipped success >/dev/null

# LOW_FOCUSED cannot manufacture review authority without the same exact
# canonical fields and task/profile-bound PRECHECK run required elsewhere.
write_valid_record
sed '/PRECHECK evidence reviewed: yes/d' "$record" > "$record.tmp"
mv "$record.tmp" "$record"
expect_failure "$validator" "$record" "$task" "$pr" "$issue" "$base" "$head" "$tree" "$profile"

write_valid_record
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_JOB_PROFILE=MEDIUM_DOMAIN WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42
expect_failure env PATH="$mock_bin:$PATH" MOCK_RECORD="$record" MOCK_HEAD="$head" MOCK_JOB_PROFILE=LOW_FOCUSED MOCK_PRECHECK_AUTHORITY_RESULT=missing WCOS_PRE_REVIEW_VALIDATOR="$validator" \
  "$authority_verifier" fixture/repo "$pr" "$task" "$issue" "$base" "$head" "$tree" "$profile" issue-comment:42

echo pre-review-authority-contract-ok
