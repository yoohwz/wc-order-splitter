#!/usr/bin/env bash
set -euo pipefail
while IFS= read -r -d '' path; do php -l "$path" >/dev/null; done < <(find inc tests -type f -name '*.php' -print0)
php -l wc-order-splitter.php
php tests/unit/run.php
bash tests/runtime/static-contracts.sh
while IFS= read -r -d '' path; do node --check "$path"; done < <(find js -type f -name '*.js' -print0)
for test_file in tests/js/*.js; do node "$test_file"; done
