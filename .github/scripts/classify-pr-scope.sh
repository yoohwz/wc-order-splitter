#!/usr/bin/env bash

set -euo pipefail

event_name=${1:-}
base_sha=${2:-}
head_sha=${3:-}
head_ref=${4:-}
output_file=${5:-}
requested_profile=${6:-}

profile=HIGH_DEEP
reason=fail_closed_default
assurance=HIGH
review_required=true
domain=unknown
stage=PRECHECK
storage_matrix='["hpos"]'

emit_result() {
  printf 'ci_profile=%s\n' "$profile"
  printf 'ci_profile_reason=%s\n' "$reason"
  printf 'assurance_profile=%s\n' "$assurance"
  printf 'independent_review_required=%s\n' "$review_required"
  printf 'affected_domain=%s\n' "$domain"
  printf 'ci_stage=%s\n' "$stage"
  printf 'storage_matrix=%s\n' "$storage_matrix"
  if [[ -n "$output_file" ]]; then
    {
      printf 'profile=%s\n' "$profile"
      printf 'reason=%s\n' "$reason"
      printf 'assurance=%s\n' "$assurance"
      printf 'review_required=%s\n' "$review_required"
      printf 'domain=%s\n' "$domain"
      printf 'stage=%s\n' "$stage"
      printf 'storage_matrix=%s\n' "$storage_matrix"
    } >> "$output_file"
  fi
}

fail_closed() {
  reason=$1
  emit_result
  exit 0
}

if [[ "$event_name" != pull_request ]]; then
  profile=RELEASE_CERT
  reason=non_pull_request_event
  assurance=HIGH
  review_required=true
  domain=control-plane
  stage=PRECHECK
  storage_matrix='["hpos"]'
  emit_result
  exit 0
fi

if [[ ! "$base_sha" =~ ^[0-9a-fA-F]{40}$ || ! "$head_sha" =~ ^[0-9a-fA-F]{40}$ ]]; then
  fail_closed invalid_pr_authority
fi

base_sha=$(printf '%s' "$base_sha" | tr '[:upper:]' '[:lower:]')
head_sha=$(printf '%s' "$head_sha" | tr '[:upper:]' '[:lower:]')

git cat-file -e "$base_sha^{commit}" 2>/dev/null || fail_closed unresolved_base_commit
git cat-file -e "$head_sha^{commit}" 2>/dev/null || fail_closed unresolved_head_commit

diff_records=$(mktemp)
resulting_file=$(mktemp)
changed_paths=$(mktemp)
trap 'rm -f "$diff_records" "$resulting_file" "$changed_paths"' EXIT

if ! git diff --name-status --no-renames --no-ext-diff -z "$base_sha" "$head_sha" > "$diff_records"; then
  fail_closed unresolved_pr_diff
fi
[[ -s "$diff_records" ]] || fail_closed empty_pr_diff

direct_safe=true
css_security_trigger=false
css_ambiguity_trigger=false
changed_count=0
while IFS= read -r -d '' status; do
  if ! IFS= read -r -d '' path; then
    fail_closed malformed_pr_diff
  fi
  if [[ "$path" == *$'\n'* || "$path" == *$'\r'* || "$path" == *$'\t'* ]]; then
    fail_closed noncanonical_changed_path
  fi
  changed_count=$((changed_count + 1))
  printf '%s\t%s\n' "$status" "$path" >> "$changed_paths"

  if [[ "$path" =~ ^css/ && "$status" != D ]]; then
    generic_head_entry=$(git ls-tree "$head_sha" -- "$path" || true)
    generic_head_mode=${generic_head_entry%% *}
    if [[ -z "$generic_head_entry" || "$generic_head_mode" != 100644 || "$(git cat-file -t "$head_sha:$path" 2>/dev/null || true)" != blob ]]; then
      css_ambiguity_trigger=true
    else
      generic_numstat=$(git diff --numstat --no-ext-diff --no-textconv "$base_sha" "$head_sha" -- "$path" || true)
      generic_additions=${generic_numstat%%$'\t'*}
      generic_rest=${generic_numstat#*$'\t'}
      generic_deletions=${generic_rest%%$'\t'*}
      if [[ ! "$generic_additions" =~ ^[0-9]+$ || ! "$generic_deletions" =~ ^[0-9]+$ ]]; then
        css_ambiguity_trigger=true
      elif git show "$head_sha:$path" > "$resulting_file"; then
        if [[ "$(sed -n '1p' "$resulting_file")" == 'version https://git-lfs.github.com/spec/v1' ]]; then
          css_ambiguity_trigger=true
        fi
        if LC_ALL=C grep -Fq -- '://' "$resulting_file" \
          || LC_ALL=C grep -Fq -- '//' "$resulting_file" \
          || LC_ALL=C grep -Eiq -- 'expression[[:blank:]]*\(' "$resulting_file"; then
          css_security_trigger=true
        fi
        if LC_ALL=C grep -Fq -- '\' "$resulting_file" \
          || perl -0777 -ne '$unsafe ||= /[\x00-\x08\x0b-\x1f\x7f]/; END { exit($unsafe ? 0 : 1) }' "$resulting_file"; then
          css_ambiguity_trigger=true
        fi
      else
        css_ambiguity_trigger=true
      fi
    fi
  fi

  if [[ "$status" != M || ! "$path" =~ ^css/[^[:cntrl:]]+\.css$ ]]; then
    direct_safe=false
    if [[ "$path" =~ ^css/ && "$status" != A && "$status" != D ]]; then
      css_ambiguity_trigger=true
    fi
    continue
  fi

  base_entry=$(git ls-tree "$base_sha" -- "$path") || direct_safe=false
  head_entry=$(git ls-tree "$head_sha" -- "$path") || direct_safe=false
  if [[ -z "$base_entry" || -z "$head_entry" ]]; then
    direct_safe=false
    css_ambiguity_trigger=true
    continue
  fi
  base_mode=${base_entry%% *}
  head_mode=${head_entry%% *}
  if [[ "$base_mode" != 100644 || "$head_mode" != 100644 ]]; then
    direct_safe=false
    css_ambiguity_trigger=true
    continue
  fi
  if [[ "$(git cat-file -t "$base_sha:$path" 2>/dev/null || true)" != blob || "$(git cat-file -t "$head_sha:$path" 2>/dev/null || true)" != blob ]]; then
    direct_safe=false
    css_ambiguity_trigger=true
    continue
  fi

  numstat=$(git diff --numstat --no-ext-diff --no-textconv "$base_sha" "$head_sha" -- "$path") || direct_safe=false
  additions=${numstat%%$'\t'*}
  rest=${numstat#*$'\t'}
  deletions=${rest%%$'\t'*}
  if [[ ! "$additions" =~ ^[0-9]+$ || ! "$deletions" =~ ^[0-9]+$ ]]; then
    direct_safe=false
    css_ambiguity_trigger=true
    continue
  fi
  if ! git show "$head_sha:$path" > "$resulting_file"; then
    direct_safe=false
    continue
  fi
  first_line=$(sed -n '1p' "$resulting_file")
  if [[ "$first_line" == 'version https://git-lfs.github.com/spec/v1' ]]; then
    direct_safe=false
    css_ambiguity_trigger=true
    continue
  fi
  if LC_ALL=C grep -Fq -- '://' "$resulting_file" \
    || LC_ALL=C grep -Fq -- '//' "$resulting_file" \
    || LC_ALL=C grep -Eiq -- 'expression[[:blank:]]*\(' "$resulting_file"; then
    direct_safe=false
    css_security_trigger=true
    continue
  fi
  if LC_ALL=C grep -Fq -- '\' "$resulting_file"; then
    direct_safe=false
    css_ambiguity_trigger=true
    continue
  fi
  if LC_ALL=C grep -Fq -- '/*' "$resulting_file" \
    || LC_ALL=C grep -Fq -- '*/' "$resulting_file" \
    || LC_ALL=C grep -Fq -- '@' "$resulting_file" \
    || LC_ALL=C grep -Eiq -- 'url[[:blank:]]*\(' "$resulting_file"; then
    direct_safe=false
    continue
  fi
  if perl -0777 -ne '$unsafe ||= /[\x00-\x08\x0b-\x1f\x7f]/; END { exit($unsafe ? 0 : 1) }' "$resulting_file"; then
    direct_safe=false
    css_ambiguity_trigger=true
  else
    scan_status=$?
    [[ "$scan_status" -eq 1 ]] || direct_safe=false
  fi
done < "$diff_records"

[[ "$changed_count" -gt 0 ]] || fail_closed malformed_pr_diff

if [[ "$direct_safe" == true ]]; then
  profile=DIRECT_FAST
  reason=strict_existing_css_modifications
  assurance=DIRECT
  review_required=false
  domain=presentation
  stage=FINAL
  storage_matrix='["none"]'
else
  rank=0
  reason=low_focused_diff
  domain=documentation
  medium_review_trigger=false

  raise_floor() {
    local candidate_rank=$1
    local candidate_reason=$2
    local candidate_domain=$3
    if [[ "$candidate_rank" -gt "$rank" ]]; then
      rank=$candidate_rank
      reason=$candidate_reason
      domain=$candidate_domain
    elif [[ "$candidate_rank" -eq "$rank" && "$domain" != "$candidate_domain" ]]; then
      domain=mixed
    fi
  }

  while IFS=$'\t' read -r status path; do
    case "$path" in
      wc-order-splitter.php|readme.txt|.distignore|package.json|package-lock.json|.github/workflows/build-plugin.yml|.github/workflows/main-attestation.yml|.github/scripts/validate-distribution-contract.sh|tests/ci/distribution-contract.sh)
        raise_floor 5 release_or_package_authority release
        ;;
      *financial*|*refund*|*payment*|*tax*|*price*|*money*|*reduced-stock*|*stock-marker*)
        raise_floor 4 financial_or_stock_authority financial
        ;;
      .github/*|tests/ci/*|AGENTS.md|docs/*)
        raise_floor 3 governance_or_ci_control_plane control-plane
        ;;
      inc/*|tests/integration/*|tests/unit/*)
        raise_floor 3 mutation_or_persistence_runtime runtime
        ;;
      js/*)
        raise_floor 2 bounded_client_runtime client-ui
        if git diff --unified=0 --no-ext-diff "$base_sha" "$head_sha" -- "$path" \
          | LC_ALL=C grep -Eiq '(ajax|rest|fetch|nonce|capabilit|permission|persist|storage|meta|status|webhook|email|replay|recovery|state[_ -]?machine|expected|assert)'; then
          medium_review_trigger=true
        fi
        ;;
      css/*|languages/*)
        if [[ "$css_security_trigger" == true ]]; then
          raise_floor 3 css_security_semantics security
        elif [[ "$css_ambiguity_trigger" == true ]]; then
          raise_floor 3 css_object_or_escape_ambiguity unknown
        else
          raise_floor 1 low_non_normative_or_presentation presentation
        fi
        ;;
      *)
        raise_floor 3 unknown_scope_fail_closed unknown
        ;;
    esac
  done < "$changed_paths"

  case "$rank" in
    1)
      profile=LOW_FOCUSED
      assurance=LOW
      review_required=false
      stage=FINAL
      storage_matrix='["none"]'
      ;;
    2)
      profile=MEDIUM_DOMAIN
      assurance=MEDIUM
      review_required=$medium_review_trigger
      if [[ "$review_required" == true ]]; then stage=PRECHECK; else stage=FINAL; fi
      storage_matrix='["hpos"]'
      ;;
    3)
      profile=HIGH_DEEP
      assurance=HIGH
      review_required=true
      stage=PRECHECK
      storage_matrix='["hpos"]'
      ;;
    4)
      profile=HIGH_FINANCIAL
      assurance=HIGH
      review_required=true
      stage=PRECHECK
      storage_matrix='["hpos"]'
      ;;
    5)
      profile=RELEASE_CERT
      assurance=HIGH
      review_required=true
      stage=PRECHECK
      storage_matrix='["hpos"]'
      ;;
    *)
      fail_closed invalid_profile_rank
      ;;
  esac
fi

profile_rank() {
  case "$1" in
    DIRECT_FAST) echo 0 ;;
    LOW_FOCUSED) echo 1 ;;
    MEDIUM_DOMAIN) echo 2 ;;
    HIGH_DEEP) echo 3 ;;
    HIGH_FINANCIAL) echo 4 ;;
    RELEASE_CERT|FULL) echo 5 ;;
    *) echo 99 ;;
  esac
}

if [[ -n "$requested_profile" ]]; then
  requested_rank=$(profile_rank "$requested_profile")
  machine_rank=$(profile_rank "$profile")
  if [[ "$requested_rank" -eq 99 ]]; then
    profile=RELEASE_CERT
    reason=invalid_requested_profile_fail_closed
    assurance=HIGH
    review_required=true
    stage=PRECHECK
    storage_matrix='["hpos"]'
  elif [[ "$requested_rank" -gt "$machine_rank" ]]; then
    case "$requested_profile" in
      FULL) profile=RELEASE_CERT ;;
      *) profile=$requested_profile ;;
    esac
    reason=requested_profile_raise
    if [[ "$requested_rank" -ge 3 ]]; then
      assurance=HIGH
      review_required=true
      stage=PRECHECK
      storage_matrix='["hpos"]'
    elif [[ "$requested_rank" -eq 2 ]]; then
      assurance=MEDIUM
      storage_matrix='["hpos"]'
    fi
  fi
fi

emit_result
