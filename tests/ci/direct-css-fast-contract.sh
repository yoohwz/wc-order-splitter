#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
classifier="$repo_root/.github/scripts/classify-pr-scope.sh"
fixture_root=$(mktemp -d)
trap 'rm -rf "$fixture_root"' EXIT

git -C "$fixture_root" init --quiet
git -C "$fixture_root" config user.name 'WOS CI Fixture'
git -C "$fixture_root" config user.email 'wos-ci@example.invalid'
mkdir -p "$fixture_root/css" "$fixture_root/js" "$fixture_root/inc" "$fixture_root/tests/ci" "$fixture_root/.github/workflows" "$fixture_root/.github/scripts" "$fixture_root/docs"
printf '.fixture { color: black; }\n' > "$fixture_root/css/admin.css"
printf '.second { margin: 0; }\n' > "$fixture_root/css/second.css"
printf '<?php return true;\n' > "$fixture_root/inc/runtime.php"
printf 'console.log("fixture");\n' > "$fixture_root/js/admin.js"
printf '# Fixture\n' > "$fixture_root/readme.txt"
printf 'name: Fixture\n' > "$fixture_root/.github/workflows/ci.yml"
printf '#!/usr/bin/env bash\n' > "$fixture_root/.github/scripts/classify-pr-scope.sh"
printf '#!/usr/bin/env bash\n' > "$fixture_root/.github/scripts/verify-required-ci.sh"
printf '#!/usr/bin/env bash\n' > "$fixture_root/tests/ci/fixture.sh"
printf '# Governance fixture\n' > "$fixture_root/docs/codex-direct-workflow.md"
git -C "$fixture_root" add .
git -C "$fixture_root" commit --quiet -m base
base_sha=$(git -C "$fixture_root" rev-parse HEAD)

classify() {
  local event_name=$1
  local base=$2
  local head=$3
  local head_ref=$4
  git -C "$fixture_root" -c advice.detachedHead=false show --quiet "$head" >/dev/null 2>&1 || true
  (cd "$fixture_root" && "$classifier" "$event_name" "$base" "$head" "$head_ref") | sed -n 's/^ci_profile=//p'
}

commit_case() {
  git -C "$fixture_root" add -A
  git -C "$fixture_root" commit --quiet -m "$1"
  git -C "$fixture_root" rev-parse HEAD
}

reset_fixture() {
  git -C "$fixture_root" reset --hard --quiet "$base_sha"
  git -C "$fixture_root" clean -fdq
}

assert_profile() {
  local expected=$1
  local label=$2
  local event_name=${3:-pull_request}
  local base=${4:-$base_sha}
  local head=${5:-$(git -C "$fixture_root" rev-parse HEAD)}
  local head_ref=${6:-codex/direct/wos-direct-20260827-095525}
  local actual
  actual=$(classify "$event_name" "$base" "$head" "$head_ref")
  if [[ "$actual" != "$expected" ]]; then
    echo "direct-css-fast-contract-error: $label expected $expected, got $actual" >&2
    exit 1
  fi
}

printf '.fixture { color: navy; }\n' > "$fixture_root/css/admin.css"
commit_case modified-css >/dev/null
assert_profile DIRECT_CSS_FAST modified-css
assert_profile FULL ordinary-css pull_request "$base_sha" "$(git -C "$fixture_root" rev-parse HEAD)" codex/wos-low-css-maintenance

reset_fixture
printf '.fixture { color: navy; }\n' > "$fixture_root/css/admin.css"
printf '.second { margin: 1px; }\n' > "$fixture_root/css/second.css"
commit_case multiple-css >/dev/null
assert_profile DIRECT_CSS_FAST multiple-css

for changed_path in \
  inc/runtime.php \
  js/admin.js \
  tests/ci/fixture.sh \
  .github/workflows/ci.yml \
  .github/scripts/classify-pr-scope.sh \
  .github/scripts/verify-required-ci.sh \
  docs/codex-direct-workflow.md \
  readme.txt; do
  reset_fixture
  printf '\nchanged\n' >> "$fixture_root/$changed_path"
  commit_case "non-css-$changed_path" >/dev/null
  assert_profile FULL "non-css-$changed_path"
done

reset_fixture
printf '.fixture { color: navy; }\n' > "$fixture_root/css/admin.css"
printf '\nchanged\n' >> "$fixture_root/inc/runtime.php"
commit_case mixed-scope >/dev/null
assert_profile FULL mixed-scope

reset_fixture
printf '.added { color: red; }\n' > "$fixture_root/css/added.css"
commit_case added-css >/dev/null
assert_profile FULL added-css

reset_fixture
rm "$fixture_root/css/admin.css"
commit_case deleted-css >/dev/null
assert_profile FULL deleted-css

reset_fixture
git -C "$fixture_root" mv css/admin.css css/renamed.css
commit_case renamed-css >/dev/null
assert_profile FULL renamed-css

reset_fixture
cp "$fixture_root/css/admin.css" "$fixture_root/css/copied.css"
commit_case copied-css >/dev/null
assert_profile FULL copied-css

reset_fixture
rm "$fixture_root/css/admin.css"
ln -s second.css "$fixture_root/css/admin.css"
commit_case symlink-css >/dev/null
assert_profile FULL symlink-css

reset_fixture
chmod +x "$fixture_root/css/admin.css"
commit_case executable-mode-css >/dev/null
assert_profile FULL executable-mode-css

reset_fixture
printf '\000\001\002\003' > "$fixture_root/css/admin.css"
commit_case binary-css >/dev/null
assert_profile FULL binary-css

reset_fixture
printf '.fixture { color: navy; }\n@import url("theme.css");\n' > "$fixture_root/css/admin.css"
commit_case css-import >/dev/null
assert_profile FULL css-import

reset_fixture
printf '.fixture { color: navy; }\n/*comment*/ @import url("theme.css");\n' > "$fixture_root/css/admin.css"
commit_case comment-obfuscated-css-import >/dev/null
assert_profile FULL comment-obfuscated-css-import

reset_fixture
printf '.fixture { background: url(https://example.invalid/a.png); }\n' > "$fixture_root/css/admin.css"
commit_case remote-css-url >/dev/null
assert_profile FULL remote-css-url

reset_fixture
printf '.fixture { background: url(\\68ttps://example.invalid/a.png); }\n' > "$fixture_root/css/admin.css"
commit_case escaped-remote-css-url >/dev/null
assert_profile FULL escaped-remote-css-url

reset_fixture
printf '.fixture { width: expression(alert(1)); }\n' > "$fixture_root/css/admin.css"
commit_case executable-css >/dev/null
assert_profile FULL executable-css

reset_fixture
printf '.fixture { width: expres\\73ion(alert(1)); }\n' > "$fixture_root/css/admin.css"
commit_case escaped-executable-css >/dev/null
assert_profile FULL escaped-executable-css

reset_fixture
printf 'version https://git-lfs.github.com/spec/v1\noid sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\nsize 10\n' > "$fixture_root/css/admin.css"
commit_case lfs-pointer-css >/dev/null
assert_profile FULL lfs-pointer-css

reset_fixture
assert_profile FULL empty-diff pull_request "$base_sha" "$base_sha"
assert_profile FULL non-pr workflow_dispatch "$base_sha" "$base_sha"
assert_profile FULL invalid-base pull_request 0000000000000000000000000000000000000000 "$base_sha"
assert_profile FULL malformed-base pull_request not-a-sha "$base_sha"

echo direct-css-fast-contract-ok
