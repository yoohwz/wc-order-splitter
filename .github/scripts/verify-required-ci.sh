#!/usr/bin/env bash

set -euo pipefail

# risk-tiered-v1: PRECHECK is deliberately never a protected Required CI success.
classifier_result=${1:-}
ci_profile=${2:-}
ci_profile_reason=${3:-}
ci_stage=${4:-}
review_required=${5:-}
php_syntax_result=${6:-}
foundation_result=${7:-}
package_result=${8:-}
release_integration_result=${9:-}
focused_result=${10:-}
profile_integration_result=${11:-}
precheck_result=${12:-}
direct_result=${13:-}
final_authority_result=${14:-}

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
[[ -n "${ci_profile_reason//[[:space:]]/}" ]] || {
  echo 'required-ci-error: missing CI profile reason' >&2
  exit 1
}

if [[ "$ci_stage" != FINAL ]]; then
  echo "required-ci-error: $ci_profile stage $ci_stage cannot satisfy protected Required CI" >&2
  exit 1
fi

case "$review_required" in true|false) ;; *)
  echo "required-ci-error: invalid review-required value: $review_required" >&2
  exit 1
esac

case "$ci_profile" in
  DIRECT_FAST)
    [[ "$ci_profile_reason" == strict_existing_css_modifications ]] || {
      echo "required-ci-error: DIRECT_FAST has invalid reason: $ci_profile_reason" >&2
      exit 1
    }
    [[ "$review_required" == false ]] || {
      echo 'required-ci-error: deterministic DIRECT_FAST cannot require a deferred review path' >&2
      exit 1
    }
    require_result direct-fast "$direct_result" success
    require_result focused "$focused_result" skipped
    require_result php-syntax "$php_syntax_result" skipped
    require_result foundation-contract "$foundation_result" skipped
    require_result package-check "$package_result" skipped
    require_result release-integration "$release_integration_result" skipped
    require_result profile-integration "$profile_integration_result" skipped
    require_result precheck "$precheck_result" skipped
    require_result final-authority "$final_authority_result" skipped
    ;;
  LOW_FOCUSED)
    [[ "$review_required" == false ]] || {
      echo 'required-ci-error: LOW_FOCUSED unexpectedly requires Independent Review' >&2
      exit 1
    }
    require_result focused "$focused_result" success
    require_result direct-fast "$direct_result" skipped
    require_result php-syntax "$php_syntax_result" skipped
    require_result foundation-contract "$foundation_result" skipped
    require_result package-check "$package_result" skipped
    require_result release-integration "$release_integration_result" skipped
    require_result profile-integration "$profile_integration_result" skipped
    require_result precheck "$precheck_result" skipped
    require_result final-authority "$final_authority_result" skipped
    ;;
  MEDIUM_DOMAIN)
    require_result focused "$focused_result" success
    require_result profile-integration "$profile_integration_result" success
    require_result direct-fast "$direct_result" skipped
    require_result php-syntax "$php_syntax_result" skipped
    require_result foundation-contract "$foundation_result" skipped
    require_result package-check "$package_result" skipped
    require_result release-integration "$release_integration_result" skipped
    require_result precheck "$precheck_result" skipped
    if [[ "$review_required" == true ]]; then
      require_result final-authority "$final_authority_result" success
    else
      require_result final-authority "$final_authority_result" skipped
    fi
    ;;
  HIGH_DEEP|HIGH_FINANCIAL)
    [[ "$review_required" == true ]] || {
      echo "required-ci-error: $ci_profile must require Independent Review" >&2
      exit 1
    }
    require_result final-authority "$final_authority_result" success
    require_result php-syntax "$php_syntax_result" success
    require_result foundation-contract "$foundation_result" success
    require_result package-check "$package_result" success
    require_result profile-integration "$profile_integration_result" success
    require_result direct-fast "$direct_result" skipped
    require_result focused "$focused_result" skipped
    require_result release-integration "$release_integration_result" skipped
    require_result precheck "$precheck_result" skipped
    ;;
  RELEASE_CERT)
    [[ "$review_required" == true ]] || {
      echo 'required-ci-error: RELEASE_CERT must require Independent Review' >&2
      exit 1
    }
    require_result final-authority "$final_authority_result" success
    require_result php-syntax "$php_syntax_result" success
    require_result foundation-contract "$foundation_result" success
    require_result package-check "$package_result" success
    require_result release-integration "$release_integration_result" success
    require_result direct-fast "$direct_result" skipped
    require_result focused "$focused_result" skipped
    require_result profile-integration "$profile_integration_result" skipped
    require_result precheck "$precheck_result" skipped
    ;;
  FULL)
    require_result php-syntax "$php_syntax_result" success
    require_result foundation-contract "$foundation_result" success
    require_result package-check "$package_result" success
    require_result release-integration "$release_integration_result" success
    require_result direct-fast "$direct_result" skipped
    require_result focused "$focused_result" skipped
    require_result profile-integration "$profile_integration_result" skipped
    require_result precheck "$precheck_result" skipped
    require_result final-authority "$final_authority_result" skipped
    ;;
  *)
    echo "required-ci-error: missing or invalid CI profile: $ci_profile" >&2
    exit 1
    ;;
esac

echo "required-ci-ok profile=$ci_profile stage=$ci_stage"
