#!/usr/bin/env bash

set -euo pipefail

classifier_result=${1:-}
ci_profile=${2:-}
ci_profile_reason=${3:-}
php_syntax_result=${4:-}
foundation_result=${5:-}
package_result=${6:-}
integration_result=${7:-}
direct_css_fast_result=${8:-}

require_result() {
  local label=$1
  local actual=$2
  local expected=$3
  if [[ "$actual" != "$expected" ]]; then
    echo "required-ci-error: $label result is $actual, expected $expected" >&2
    exit 1
  fi
}

require_result classifier "$classifier_result" success

case "$ci_profile" in
  DIRECT_CSS_FAST)
    [[ "$ci_profile_reason" == strict_existing_css_modifications ]] || {
      echo "required-ci-error: DIRECT_CSS_FAST has invalid reason: $ci_profile_reason" >&2
      exit 1
    }
    require_result direct-css-fast "$direct_css_fast_result" success
    require_result php-syntax "$php_syntax_result" skipped
    require_result foundation-contract "$foundation_result" skipped
    require_result package-check "$package_result" skipped
    require_result integration-smoke "$integration_result" skipped
    ;;
  FULL)
    [[ -n "${ci_profile_reason//[[:space:]]/}" && "$ci_profile_reason" != strict_existing_css_modifications ]] || {
      echo "required-ci-error: FULL has missing or contradictory reason: $ci_profile_reason" >&2
      exit 1
    }
    require_result direct-css-fast "$direct_css_fast_result" skipped
    require_result php-syntax "$php_syntax_result" success
    require_result foundation-contract "$foundation_result" success
    require_result package-check "$package_result" success
    require_result integration-smoke "$integration_result" success
    ;;
  *)
    echo "required-ci-error: missing or invalid CI profile: $ci_profile" >&2
    exit 1
    ;;
esac

echo "required-ci-ok profile=$ci_profile"
