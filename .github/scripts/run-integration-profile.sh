#!/usr/bin/env bash
set -euo pipefail
profile=${1:-}
case "$profile" in STANDARD|CRITICAL|RELEASE_CERT) ;; *) echo 'unsupported integration profile' >&2; exit 1 ;; esac
repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
selected=0
while IFS='|' read -r kind profiles path; do
  [[ "$kind" == eval && ",$profiles," == *",$profile,"* ]] || continue
  selected=$((selected + 1))
  if [[ ${2:-} == --list ]]; then
    printf '%s\n' "$path"
  elif [[ "$path" == tests/integration/merge-service-adapter-smoke.php ]]; then
    # Separate processes preserve bounded memory and cover every crash/replay window.
    for suite in core crash_pre forward_before_forward_relations forward_after_one_reciprocal_relation \
      forward_after_both_relations_before_verification forward_after_verification_before_commit \
      forward_after_commit_before_complete response_loss lease_loss stock_guard_before \
      stock_guard_after drift_stock checkpoint_drift; do
      npx wp-env run cli wp eval-file "wp-content/plugins/wc-order-splitter/$path" "$suite"
    done
  else
    npx wp-env run cli wp eval-file "wp-content/plugins/wc-order-splitter/$path"
  fi
done < "$repo_root/tests/ci/integration-suites.tsv"
[[ "$selected" -gt 0 ]]
