#!/usr/bin/env bash
# Only invoked by secret-free Prepare. Never mount the control plane in Docker.
set -euo pipefail
test -z "${WPORG_SVN_PASSWORD:-}"
test -z "${GH_TOKEN:-}"
control_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
release_work="$RUNNER_TEMP/wcos-release"
check_root="$release_work/plugin-check-runtime"
mkdir "$check_root"
python3 - "$check_root" "$release_work/stage-a" <<'PY'
import json
from pathlib import Path
import sys
root, payload = map(Path, sys.argv[1:])
config = {
    'core': None, 'phpVersion': '8.3', 'testsEnvironment': False,
    'plugins': ['https://downloads.wordpress.org/plugin/woocommerce.11.0.1.zip',
                'https://downloads.wordpress.org/plugin/plugin-check.2.1.0.zip'],
    'mappings': {'wp-content/plugins/wc-order-splitter': str(payload)},
    'config': {'WP_DEBUG': False},
}
(root / '.wp-env.json').write_text(json.dumps(config) + '\n')
PY
cd "$check_root"
wp_env="$control_root/node_modules/.bin/wp-env"
trap '"$wp_env" stop >/dev/null 2>&1 || true' EXIT
"$wp_env" start
"$wp_env" run cli wp plugin activate woocommerce plugin-check wc-order-splitter
"$wp_env" run cli wp plugin check wc-order-splitter --format=strict-json --mode=update \
  --slug=wc-order-splitter --fields=file,line,column,type,code,message,docs \
  --require=./wp-content/plugins/plugin-check/cli.php > "$release_work/plugin-check-raw.txt"
# The trusted Python entry point parses the report and fails on every ERROR.
# No ignore list/baseline is introduced by this bootstrap.
