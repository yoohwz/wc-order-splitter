#!/usr/bin/env bash
set -euo pipefail
export PYTHONDONTWRITEBYTECODE=1
python3 - <<'PY'
from pathlib import Path
for folder in ('.github', 'tests'):
    for path in Path(folder).rglob('*.py'):
        compile(path.read_bytes(), str(path), 'exec')
PY
while IFS= read -r -d '' path; do bash -n "$path"; done < <(find .github tests -type f -name '*.sh' -print0)
while IFS= read -r -d '' path; do php -l "$path" >/dev/null; done < <(find tests -type f -name '*.php' -print0)
while IFS= read -r -d '' path; do node --check "$path"; done < <(find .github tests -type f -name '*.js' -print0)
python3 tests/ci/profile-contract.py
python3 tests/ci/product-tree-contract.py
python3 tests/ci/integration-suite-contract.py
python3 tests/ci/workflow-contract.py
python3 tests/ci/publisher-contract.py
python3 tests/ci/svn-eol-cleanup-contract.py
node tests/js/admin-error-output-contract.js
bash tests/ci/distribution-contract.sh
distribution=$(mktemp -d "${TMPDIR:-/tmp}/wcos-product.XXXXXX")
trap 'rm -rf "$distribution"' EXIT
bash .github/scripts/validate-distribution-contract.sh "$PWD" "$distribution/plugin"
