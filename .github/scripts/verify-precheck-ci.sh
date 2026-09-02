#!/usr/bin/env bash

set -euo pipefail

# risk-tiered-precheck-v1: this verifier runs only under a non-protected job name.
classifier_result=${1:-}
ci_profile=${2:-}
ci_stage=${3:-}
review_required=${4:-}
php_syntax_result=${5:-}
foundation_result=${6:-}
package_result=${7:-}
release_integration_result=${8:-}
focused_result=${9:-}
profile_integration_result=${10:-}
precheck_result=${11:-}
direct_result=${12:-}
final_authority_result=${13:-}

require_result() {
  local label=$1 actual=$2 expected=$3
  if [[ "$actual" != "$expected" ]]; then
    echo "precheck-ci-error: $label result is $actual, expected $expected" >&2
    exit 1
  fi
}

require_result classifier "$classifier_result" success
[[ "$ci_stage" == PRECHECK ]] || {
  echo "precheck-ci-error: expected PRECHECK stage, got $ci_stage" >&2
  exit 1
}
case "$review_required" in true|false) ;; *)
  echo "precheck-ci-error: invalid review-required value: $review_required" >&2
  exit 1
  ;;
esac

require_result precheck "$precheck_result" success
require_result php-syntax "$php_syntax_result" skipped
require_result foundation-contract "$foundation_result" skipped
require_result package-check "$package_result" skipped
require_result release-integration "$release_integration_result" skipped
require_result focused "$focused_result" skipped
require_result direct-fast "$direct_result" skipped
require_result final-authority "$final_authority_result" skipped

case "$ci_profile" in
  LOW_FOCUSED)
    require_result profile-integration "$profile_integration_result" skipped
    ;;
  MEDIUM_DOMAIN)
    require_result profile-integration "$profile_integration_result" success
    ;;
  HIGH_DEEP|HIGH_FINANCIAL|RELEASE_CERT)
    [[ "$review_required" == true ]] || {
      echo "precheck-ci-error: $ci_profile must require review" >&2
      exit 1
    }
    require_result profile-integration "$profile_integration_result" success
    ;;
  *)
    echo "precheck-ci-error: invalid PRECHECK profile: $ci_profile" >&2
    exit 1
    ;;
esac

echo "precheck-ci-ok profile=$ci_profile stage=$ci_stage"
