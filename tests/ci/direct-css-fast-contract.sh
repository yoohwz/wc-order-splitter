#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
classifier="$repo_root/.github/scripts/classify-pr-scope.sh"
fixture_root=$(mktemp -d)
trap 'rm -rf "$fixture_root"' EXIT

git -C "$fixture_root" init --quiet
git -C "$fixture_root" config user.name 'WOS CI Fixture'
git -C "$fixture_root" config user.email 'wos-ci@example.invalid'
mkdir -p "$fixture_root/css" "$fixture_root/js" "$fixture_root/inc/domain" "$fixture_root/tests/ci" "$fixture_root/tests/integration" "$fixture_root/.github/workflows" "$fixture_root/.github/scripts" "$fixture_root/docs" "$fixture_root/languages"
printf '.fixture { color: black; }\n' > "$fixture_root/css/admin.css"
printf 'window.WCOSView = { color: "black" };\n' > "$fixture_root/js/admin.js"
printf '<?php return true;\n' > "$fixture_root/inc/domain/class-runtime.php"
printf '<?php return true;\n' > "$fixture_root/inc/domain/class-financial-authority.php"
printf '# Fixture\n' > "$fixture_root/readme.txt"
printf 'name: Fixture\n' > "$fixture_root/.github/workflows/ci.yml"
printf '#!/usr/bin/env bash\n' > "$fixture_root/tests/ci/fixture.sh"
printf '<?php return true;\n' > "$fixture_root/tests/integration/runtime-smoke.php"
printf '# Governance fixture\n' > "$fixture_root/docs/codex-direct-workflow.md"
printf '# Product note\n' > "$fixture_root/docs/product-note.md"
printf 'msgid "Black"\nmsgstr ""\n' > "$fixture_root/languages/fixture.pot"
git -C "$fixture_root" add .
git -C "$fixture_root" commit --quiet -m base
base_sha=$(git -C "$fixture_root" rev-parse HEAD)

reset_fixture() {
  git -C "$fixture_root" reset --hard --quiet "$base_sha"
  git -C "$fixture_root" clean -fdq
}

commit_case() {
  git -C "$fixture_root" add -A
  git -C "$fixture_root" commit --quiet -m "$1"
  git -C "$fixture_root" rev-parse HEAD
}

classify_output() {
  local event_name=$1
  local base=$2
  local head=$3
  local head_ref=$4
  local hint=${5:-}
  (cd "$fixture_root" && "$classifier" "$event_name" "$base" "$head" "$head_ref" '' "$hint")
}

assert_facts() {
  local expected_profile=$1
  local expected_assurance=$2
  local expected_review=$3
  local expected_stage=$4
  local label=$5
  local event_name=${6:-pull_request}
  local base=${7:-$base_sha}
  local head=${8:-$(git -C "$fixture_root" rev-parse HEAD)}
  local head_ref=${9:-codex/wos-fixture}
  local hint=${10:-}
  local output actual_profile actual_assurance actual_review actual_stage
  output=$(classify_output "$event_name" "$base" "$head" "$head_ref" "$hint")
  actual_profile=$(printf '%s\n' "$output" | sed -n 's/^ci_profile=//p')
  actual_assurance=$(printf '%s\n' "$output" | sed -n 's/^assurance_profile=//p')
  actual_review=$(printf '%s\n' "$output" | sed -n 's/^independent_review_required=//p')
  actual_stage=$(printf '%s\n' "$output" | sed -n 's/^ci_stage=//p')
  if [[ "$actual_profile/$actual_assurance/$actual_review/$actual_stage" != "$expected_profile/$expected_assurance/$expected_review/$expected_stage" ]]; then
    echo "profile-contract-error: $label expected $expected_profile/$expected_assurance/$expected_review/$expected_stage, got $actual_profile/$actual_assurance/$actual_review/$actual_stage" >&2
    exit 1
  fi
}

printf '.fixture { color: navy; }\n' > "$fixture_root/css/admin.css"
commit_case direct-css >/dev/null
assert_facts DIRECT_FAST DIRECT false FINAL strict-direct pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000
assert_facts DIRECT_FAST DIRECT false FINAL branch-cannot-raise-or-lower pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-low-css

rich_but_low_css_cases=(
  '@import "theme.css";'
  '/* presentation note */'
  '.fixture { background: url(/local.png); }'
)
for unsafe_css in "${rich_but_low_css_cases[@]}"; do
  reset_fixture
  printf '%s\n' "$unsafe_css" > "$fixture_root/css/admin.css"
  commit_case unsafe-direct-css >/dev/null
  assert_facts LOW_FOCUSED LOW false FINAL rich-direct-escalates-to-low pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000
done

unsafe_css_cases=(
  '.fixture { background: url(https://example.invalid/a.png); }'
  '.fixture { width: expression(alert(1)); }'
  '.fixture { color: n\61vy; }'
)
for unsafe_css in "${unsafe_css_cases[@]}"; do
  reset_fixture
  printf '%s\n' "$unsafe_css" > "$fixture_root/css/admin.css"
  commit_case unsafe-direct-css >/dev/null
  assert_facts HIGH_DEEP HIGH true PRECHECK unsafe-direct-escalates-high pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000
done

reset_fixture
printf '.added { color: red; }\n' > "$fixture_root/css/added.css"
commit_case added-css >/dev/null
assert_facts LOW_FOCUSED LOW false FINAL added-css-not-direct pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000

reset_fixture
rm "$fixture_root/css/admin.css"
ln -s missing.css "$fixture_root/css/admin.css"
commit_case symlink-css >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK symlink-css-fails-closed pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000

reset_fixture
printf '\000\001\002\003' > "$fixture_root/css/admin.css"
commit_case binary-css >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK binary-css-fails-closed pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000

reset_fixture
printf '\nmsgid "Navy"\nmsgstr ""\n' >> "$fixture_root/languages/fixture.pot"
commit_case low-translation >/dev/null
assert_facts LOW_FOCUSED LOW false FINAL low-translation

reset_fixture
printf '\nNormative authority disguised as a product note.\n' >> "$fixture_root/docs/product-note.md"
commit_case disguised-governance-doc >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK docs-fail-closed-without-name-heuristics

reset_fixture
printf '\nwindow.WCOSView.color = "navy";\n' >> "$fixture_root/js/admin.js"
commit_case medium-no-trigger >/dev/null
assert_facts MEDIUM_DOMAIN MEDIUM false FINAL medium-no-trigger

reset_fixture
printf '\nwindow.fetch(window.ajaxurl);\n' >> "$fixture_root/js/admin.js"
commit_case medium-trigger >/dev/null
assert_facts MEDIUM_DOMAIN MEDIUM true PRECHECK medium-trigger

reset_fixture
printf '\nchanged\n' >> "$fixture_root/inc/domain/class-runtime.php"
commit_case high-runtime >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK high-runtime pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000
assert_facts HIGH_DEEP HIGH true PRECHECK low-hint-cannot-lower-runtime pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000 LOW_FOCUSED

reset_fixture
printf '\nchanged\n' >> "$fixture_root/inc/domain/class-financial-authority.php"
commit_case high-financial >/dev/null
assert_facts HIGH_FINANCIAL HIGH true PRECHECK high-financial

reset_fixture
printf '\nchanged\n' >> "$fixture_root/.github/workflows/ci.yml"
commit_case governance >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK governance

reset_fixture
printf '\nchanged\n' >> "$fixture_root/readme.txt"
commit_case release >/dev/null
assert_facts RELEASE_CERT HIGH true PRECHECK release

reset_fixture
printf '\nwindow.WCOSView.color = "navy";\n' >> "$fixture_root/js/admin.js"
printf '\nchanged\n' >> "$fixture_root/inc/domain/class-financial-authority.php"
commit_case mixed >/dev/null
assert_facts HIGH_FINANCIAL HIGH true PRECHECK mixed

reset_fixture
printf '\nmsgid "Navy"\nmsgstr ""\n' >> "$fixture_root/languages/fixture.pot"
commit_case hint-raise >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK hint-raise pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-low HIGH_DEEP
assert_facts RELEASE_CERT HIGH true PRECHECK invalid-hint-fails-closed pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-low NOT_A_PROFILE

reset_fixture
assert_facts HIGH_DEEP HIGH true PRECHECK empty-diff pull_request "$base_sha" "$base_sha"
assert_facts RELEASE_CERT HIGH true PRECHECK non-pr workflow_dispatch "$base_sha" "$base_sha"
assert_facts HIGH_DEEP HIGH true PRECHECK invalid-base pull_request 0000000000000000000000000000000000000000 "$base_sha"

echo direct-css-fast-contract-ok
