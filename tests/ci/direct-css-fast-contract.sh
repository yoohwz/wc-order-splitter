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
printf '.fixture {\n  border-radius: 4px;\n}\n' > "$fixture_root/css/admin.css"
printf '.wcos-confirm {\n  display: none;\n}\n' > "$fixture_root/css/control.css"
printf '.wcos-panel {\n  filter: opacity(0);\n}\n' > "$fixture_root/css/control-filter.css"
printf 'window.WCOSView = { color: "black" };\n' > "$fixture_root/js/admin.js"
printf '<?php return true;\n' > "$fixture_root/inc/domain/class-runtime.php"
printf '<?php return true;\n' > "$fixture_root/inc/domain/class-financial-authority.php"
printf '<?php return true;\n' > "$fixture_root/inc/domain/class-wcos-merge-commercial-policy.php"
printf '<?php return true;\n' > "$fixture_root/inc/domain/class-wcos-merge-plan.php"
for financial_caller in \
  class-wcos-split-order-service.php \
  class-wcos-duplicate-order-service.php \
  class-wcos-return-order-service.php \
  class-wcos-bulk-return-orchestrator.php \
  class-wcos-merge-order-service.php; do
  printf '<?php\nif ($apply) { $order->calculate_totals(false); }\n' > "$fixture_root/inc/domain/$financial_caller"
done
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
  local assurance_floor=${6:-}
  local review_floor=${7:-}
  (cd "$fixture_root" && "$classifier" "$event_name" "$base" "$head" "$head_ref" '' "$hint" "$assurance_floor" "$review_floor")
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
  local assurance_floor=${11:-}
  local review_floor=${12:-}
  local output actual_profile actual_assurance actual_review actual_stage
  output=$(classify_output "$event_name" "$base" "$head" "$head_ref" "$hint" "$assurance_floor" "$review_floor")
  actual_profile=$(printf '%s\n' "$output" | sed -n 's/^ci_profile=//p')
  actual_assurance=$(printf '%s\n' "$output" | sed -n 's/^assurance_profile=//p')
  actual_review=$(printf '%s\n' "$output" | sed -n 's/^independent_review_required=//p')
  actual_stage=$(printf '%s\n' "$output" | sed -n 's/^ci_stage=//p')
  if [[ "$actual_profile/$actual_assurance/$actual_review/$actual_stage" != "$expected_profile/$expected_assurance/$expected_review/$expected_stage" ]]; then
    echo "profile-contract-error: $label expected $expected_profile/$expected_assurance/$expected_review/$expected_stage, got $actual_profile/$actual_assurance/$actual_review/$actual_stage" >&2
    exit 1
  fi
}

printf '.fixture {\n  border-radius: 6px;\n}\n' > "$fixture_root/css/admin.css"
commit_case direct-css >/dev/null
assert_facts DIRECT_FAST DIRECT false FINAL strict-direct pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000
assert_facts DIRECT_FAST DIRECT false FINAL branch-cannot-raise-or-lower pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-low-css

unproven_css_cases=(
  '@import "theme.css";'
  '/* presentation note */'
  '.fixture { background: url(/local.png); }'
)
for unsafe_css in "${unproven_css_cases[@]}"; do
  reset_fixture
  printf '%s\n' "$unsafe_css" > "$fixture_root/css/admin.css"
  commit_case unsafe-direct-css >/dev/null
  assert_facts HIGH_DEEP HIGH true PRECHECK unproven-css-reviews pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000
done

unsafe_css_cases=(
  '.fixture { background: url(https://example.invalid/a.png); }'
  '.fixture { width: expression(alert(1)); }'
  '.fixture { color: n\61vy; }'
  '.wcos-confirm { display: none; }'
  '.wcos-action { pointer-events: none; }'
  '.wcos-warning[aria-hidden="true"] { opacity: 0; }'
  '.wcos-panel { filter: opacity(0); }'
  '.wcos-panel { clip-path: inset(100%); }'
  '.wcos-panel { scale: 0; }'
  '.wcos-panel { font-size: 0; }'
  '.wcos-panel { color: transparent; }'
)
for unsafe_css in "${unsafe_css_cases[@]}"; do
  reset_fixture
  printf '%s\n' "$unsafe_css" > "$fixture_root/css/admin.css"
  commit_case unsafe-direct-css >/dev/null
  assert_facts HIGH_DEEP HIGH true PRECHECK unsafe-direct-escalates-high pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000
done

reset_fixture
sed -i.bak '/border-radius/a\
  border-radius: 8px;' "$fixture_root/css/admin.css"
rm "$fixture_root/css/admin.css.bak"
commit_case added-declaration-css >/dev/null
assert_facts LOW_FOCUSED LOW false FINAL added-declaration-not-direct pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000

reset_fixture
printf '.added { color: red; }\n' > "$fixture_root/css/added.css"
commit_case added-css >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK added-css-unproven pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000

reset_fixture
printf '.added {\n  border-radius: 4px;\n}\n' > "$fixture_root/css/added.css"
commit_case added-safe-css >/dev/null
assert_facts LOW_FOCUSED LOW false FINAL added-safe-css-not-direct pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000

for added_control_css in \
  '.wcos-confirm { display: none; }' \
  '.wcos-action { pointer-events: none; }' \
  '.wcos-warning[aria-hidden="true"] { opacity: 0; }' \
  'button:disabled { visibility: hidden; }' \
  '.wcos-panel { filter: opacity(0); }' \
  '.wcos-panel { clip-path: inset(100%); }' \
  '.wcos-panel { scale: 0; }' \
  '.wcos-panel { font-size: 0; }' \
  '.wcos-panel { color: transparent; }'; do
  reset_fixture
  printf '%s\n' "$added_control_css" > "$fixture_root/css/added-control.css"
  commit_case added-control-css >/dev/null
  assert_facts HIGH_DEEP HIGH true PRECHECK added-control-css-reviews
done

reset_fixture
rm "$fixture_root/css/control.css"
commit_case deleted-control-css >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK deleted-control-css-reviews

reset_fixture
rm "$fixture_root/css/control-filter.css"
commit_case deleted-filter-control-css >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK deleted-filter-control-css-reviews

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
assert_facts MEDIUM_DOMAIN MEDIUM false FINAL canonical-floor-raises-low-to-medium pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-medium MEDIUM_DOMAIN MEDIUM OPTIONAL
assert_facts MEDIUM_DOMAIN MEDIUM true PRECHECK task-required-review-medium pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-medium MEDIUM_DOMAIN MEDIUM REQUIRED
assert_facts MEDIUM_DOMAIN HIGH true PRECHECK task-high-assurance-medium-ci pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-medium MEDIUM_DOMAIN HIGH REQUIRED

reset_fixture
printf '\nNormative authority disguised as a product note.\n' >> "$fixture_root/docs/product-note.md"
commit_case disguised-governance-doc >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK docs-fail-closed-without-name-heuristics

reset_fixture
printf '\nwindow.WCOSView.color = "navy";\n' >> "$fixture_root/js/admin.js"
commit_case medium-no-trigger >/dev/null
assert_facts MEDIUM_DOMAIN MEDIUM true PRECHECK medium-ambiguity-reviews

reset_fixture
printf '\nwindow.fetch(window.ajaxurl);\n' >> "$fixture_root/js/admin.js"
commit_case medium-trigger >/dev/null
assert_facts MEDIUM_DOMAIN MEDIUM true PRECHECK medium-trigger

for client_mutation in \
  'const request = new XMLHttpRequest(); request.open("POST", endpoint); request.send(payload);' \
  'jQuery.post(endpoint, payload);' \
  'navigator.sendBeacon(endpoint, payload);' \
  'window.WCOSMutation.submit(payload);'; do
  reset_fixture
  printf '\n%s\n' "$client_mutation" >> "$fixture_root/js/admin.js"
  commit_case medium-client-mutation >/dev/null
  assert_facts MEDIUM_DOMAIN MEDIUM true PRECHECK medium-client-mutation-reviews
done

reset_fixture
printf '\nchanged\n' >> "$fixture_root/inc/domain/class-runtime.php"
commit_case high-runtime >/dev/null
assert_facts HIGH_DEEP HIGH true PRECHECK high-runtime pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000
assert_facts HIGH_DEEP HIGH true PRECHECK low-hint-cannot-lower-runtime pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/direct/wos-direct-20260902-120000 LOW_FOCUSED
assert_facts HIGH_DEEP HIGH true PRECHECK optional-review-floor-cannot-lower-runtime pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-high HIGH_DEEP MEDIUM OPTIONAL
assert_facts HIGH_FINANCIAL HIGH true PRECHECK canonical-floor-raises-deep-to-financial pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-financial HIGH_FINANCIAL
assert_facts RELEASE_CERT HIGH true PRECHECK canonical-floor-raises-deep-to-release pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-release RELEASE_CERT

reset_fixture
printf '\n$order->set_total("10.00");\n' >> "$fixture_root/inc/domain/class-runtime.php"
commit_case generic-path-financial >/dev/null
assert_facts HIGH_FINANCIAL HIGH true PRECHECK generic-path-financial-content

reset_fixture
printf '\nWCOS_Merge_Financial_Authority::target_has_history($authority);\n' >> "$fixture_root/inc/domain/class-runtime.php"
commit_case generic-financial-caller >/dev/null
assert_facts HIGH_FINANCIAL HIGH true PRECHECK generic-financial-caller-content

for financial_caller in \
  class-wcos-split-order-service.php \
  class-wcos-duplicate-order-service.php \
  class-wcos-return-order-service.php \
  class-wcos-bulk-return-orchestrator.php \
  class-wcos-merge-order-service.php; do
  reset_fixture
  sed 's/if ($apply)/if (false)/' "$fixture_root/inc/domain/$financial_caller" > "$fixture_root/inc/domain/$financial_caller.tmp"
  mv "$fixture_root/inc/domain/$financial_caller.tmp" "$fixture_root/inc/domain/$financial_caller"
  commit_case generic-financial-context >/dev/null
  assert_facts HIGH_FINANCIAL HIGH true PRECHECK "generic-financial-context-$financial_caller"
done

reset_fixture
printf '\nchanged\n' >> "$fixture_root/inc/domain/class-wcos-merge-commercial-policy.php"
commit_case explicit-commercial-financial-caller-path >/dev/null
assert_facts HIGH_FINANCIAL HIGH true PRECHECK explicit-commercial-financial-caller-path

reset_fixture
printf '\nchanged\n' >> "$fixture_root/inc/domain/class-wcos-merge-plan.php"
commit_case explicit-financial-caller-path >/dev/null
assert_facts HIGH_FINANCIAL HIGH true PRECHECK explicit-financial-caller-path

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
assert_facts RELEASE_CERT HIGH true PRECHECK invalid-assurance-fails-closed pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-low LOW_FOCUSED UNKNOWN OPTIONAL
assert_facts RELEASE_CERT HIGH true PRECHECK invalid-review-floor-fails-closed pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-low LOW_FOCUSED LOW NEVER

reset_fixture
assert_facts HIGH_DEEP HIGH true PRECHECK empty-diff pull_request "$base_sha" "$base_sha"
assert_facts RELEASE_CERT HIGH true PRECHECK non-pr workflow_dispatch "$base_sha" "$base_sha"
assert_facts HIGH_DEEP HIGH true PRECHECK invalid-base pull_request 0000000000000000000000000000000000000000 "$base_sha"

echo direct-css-fast-contract-ok
