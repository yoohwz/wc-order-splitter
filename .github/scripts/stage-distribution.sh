#!/usr/bin/env bash
# This is the single staging operation for validation and PRODUCT_TREE_SHA.
set -euo pipefail
fail() { echo "distribution-contract-error: $*" >&2; exit 1; }
[[ $# -eq 2 ]] || fail 'usage: stage-distribution.sh SOURCE_ROOT DISTRIBUTION_ROOT'
source_root=$(cd "$1" && pwd -P)
mkdir -p "$(dirname "$2")"
distribution_root="$(cd "$(dirname "$2")" && pwd -P)/$(basename "$2")"
case "$distribution_root/" in "$source_root/"*) fail 'distribution root must be outside the source tree' ;; esac
[[ ! -L "$distribution_root" ]] || fail 'distribution root must not be a symbolic link'
if [[ -e "$distribution_root" ]] && find "$distribution_root" -mindepth 1 -print -quit | grep -q .; then
  fail "distribution root must be absent or empty: $distribution_root"
fi
test -f "$source_root/.distignore" || fail 'missing .distignore'
mkdir -p "$distribution_root"
rsync -a "$source_root/" "$distribution_root/" --exclude-from="$source_root/.distignore"
