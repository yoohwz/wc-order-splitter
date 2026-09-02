#!/usr/bin/env bash

set -euo pipefail

profile=${1:-}
stage=${2:-}
list_only=${3:-}
repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
manifest="$repo_root/tests/ci/integration-suites.tsv"

case "$profile/$stage" in
  MEDIUM_DOMAIN/FINAL) wanted='medium,sentinel' ;;
  HIGH_DEEP/PRECHECK) wanted='precheck,sentinel' ;;
  HIGH_DEEP/FINAL) wanted='deep,sentinel' ;;
  HIGH_FINANCIAL/PRECHECK) wanted='precheck,precheck-financial,sentinel' ;;
  HIGH_FINANCIAL/FINAL) wanted='deep,financial,sentinel' ;;
  RELEASE_CERT/PRECHECK) wanted='precheck,precheck-financial,sentinel' ;;
  *)
    echo "integration-profile-error: unsupported profile/stage $profile/$stage" >&2
    exit 1
    ;;
esac

selected=0
while IFS='|' read -r kind tags path; do
  [[ -n "$kind" && "$kind" != \#* ]] || continue
  [[ "$kind" == eval ]] || continue
  matched=false
  old_ifs=$IFS
  IFS=','
  for tag in $wanted; do
    case ",$tags," in
      *",$tag,"*) matched=true; break ;;
    esac
  done
  IFS=$old_ifs
  [[ "$matched" == true ]] || continue
  selected=$((selected + 1))
  if [[ "$list_only" == --list ]]; then
    printf '%s\n' "$path"
  else
    npx wp-env run cli wp eval-file "wp-content/plugins/wc-order-splitter/$path"
  fi
done < "$manifest"

[[ "$selected" -gt 0 ]] || {
  echo "integration-profile-error: no suites selected for $profile/$stage" >&2
  exit 1
}

if [[ "$list_only" != --list ]]; then
  echo "integration-profile-ok profile=$profile stage=$stage selected=$selected"
fi
