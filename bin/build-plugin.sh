#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_path="${1:-${repo_root}/build/wc-order-splitter.zip}"
source_date_epoch="${SOURCE_DATE_EPOCH:-946684800}"
work_root="$(mktemp -d)"
package_root="${work_root}/wc-order-splitter"

cleanup() {
  rm -rf "${work_root}"
}
trap cleanup EXIT

mkdir -p "${package_root}" "$(dirname "${output_path}")"

copy_path() {
  local relative_path="$1"

  if [[ ! -e "${repo_root}/${relative_path}" ]]; then
    echo "Required release path is missing: ${relative_path}" >&2
    exit 1
  fi

  mkdir -p "${package_root}/$(dirname "${relative_path}")"
  cp -R "${repo_root}/${relative_path}" "${package_root}/${relative_path}"
}

# Public release allowlist. Development, governance, test, and build files never
# enter the WordPress.org package.
copy_path "wc-order-splitter.php"
copy_path "readme.txt"
copy_path "changelog.txt"
copy_path "license.txt"
copy_path "css"
copy_path "js"
copy_path "languages"
copy_path "inc"

# Version 1.4.12 is an emergency fail-closed package. Remove the legacy mutation
# implementation physically as a second safety boundary in addition to the
# hardcoded runtime gate.
rm -rf "${package_root}/inc/backend/actions"
rm -f \
  "${package_root}/inc/backend/order-duplicate-option.php" \
  "${package_root}/inc/backend/order-merge-option.php" \
  "${package_root}/inc/backend/order-return-option.php" \
  "${package_root}/inc/backend/order-split-button.php" \
  "${package_root}/inc/backend/orders-bulk-return.php"

# Removed privacy-unsafe integration must never return in a release package.
rm -rf "${package_root}/inc/cores/api"

if find "${package_root}" -type l -print -quit | grep -q .; then
  echo "Release package must not contain symbolic links." >&2
  exit 1
fi

while IFS= read -r -d '' php_file; do
  php -l "${php_file}" >/dev/null
done < <(find "${package_root}" -type f -name '*.php' -print0)

if grep -R --line-number --fixed-strings 'yoexpress.top' "${package_root}"; then
  echo "Removed telemetry endpoint is present in the release package." >&2
  exit 1
fi

if find "${package_root}" \( \
  -path '*/tests/*' -o \
  -path '*/docs/*' -o \
  -name 'AGENTS.md' -o \
  -name '.wp-env.json' -o \
  -name 'package.json' -o \
  -name 'package-lock.json' -o \
  -path '*/.github/*' \
\) -print -quit | grep -q .; then
  echo "Development-only files leaked into the release package." >&2
  exit 1
fi

plugin_version="$(sed -n 's/^ \* Version: //p' "${package_root}/wc-order-splitter.php" | head -n 1)"
stable_tag="$(sed -n 's/^Stable tag: //p' "${package_root}/readme.txt" | head -n 1)"

if [[ -z "${plugin_version}" || "${plugin_version}" != "${stable_tag}" ]]; then
  echo "Plugin version and Stable tag must be present and identical." >&2
  exit 1
fi

if [[ "${plugin_version}" != '1.4.12' ]]; then
  echo "Emergency release builder expected version 1.4.12, found ${plugin_version}." >&2
  exit 1
fi

if ! grep -Fq "define('WC_ORDER_SPLITTER_MUTATIONS_ENABLED', false);" "${package_root}/wc-order-splitter.php"; then
  echo "Emergency release must hardcode mutation workflows to fail closed." >&2
  exit 1
fi

# Normalize ownership-independent timestamps, ordering, and ZIP metadata.
find "${package_root}" -exec touch -h -d "@${source_date_epoch}" {} +
rm -f "${output_path}"

(
  cd "${work_root}"
  find wc-order-splitter -type f -print | LC_ALL=C sort | zip -X -q "${output_path}" -@
)

sha256sum "${output_path}" > "${output_path}.sha256"
echo "Built ${output_path}"
cat "${output_path}.sha256"
