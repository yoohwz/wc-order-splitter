#!/usr/bin/env bash

set -euo pipefail

event_name=${1:-}
base_sha=${2:-}
head_sha=${3:-}
head_ref=${4:-}
output_file=${5:-}

profile=FULL
reason=fail_closed_default

emit_result() {
  printf 'ci_profile=%s\n' "$profile"
  printf 'ci_profile_reason=%s\n' "$reason"
  if [[ -n "$output_file" ]]; then
    {
      printf 'profile=%s\n' "$profile"
      printf 'reason=%s\n' "$reason"
    } >> "$output_file"
  fi
}

fallback() {
  reason=$1
  emit_result
  exit 0
}

fallback_if_fixed_token() {
  local token=$1
  local hit_reason=$2
  local scan_status

  if LC_ALL=C grep -Fq -- "$token" "$resulting_css"; then
    fallback "$hit_reason"
  else
    scan_status=$?
    [[ "$scan_status" -eq 1 ]] || fallback css_lexical_scan_failed
  fi
}

fallback_if_case_insensitive_pattern() {
  local pattern=$1
  local hit_reason=$2
  local scan_status

  if LC_ALL=C grep -Eiq -- "$pattern" "$resulting_css"; then
    fallback "$hit_reason"
  else
    scan_status=$?
    [[ "$scan_status" -eq 1 ]] || fallback css_lexical_scan_failed
  fi
}

if [[ "$event_name" != pull_request ]]; then
  fallback non_pull_request_event
fi

if [[ ! "$head_ref" =~ ^codex/direct/wos-direct-[0-9]{8}-[0-9]{6}$ ]]; then
  fallback non_direct_head_ref
fi

if [[ ! "$base_sha" =~ ^[0-9a-fA-F]{40}$ || ! "$head_sha" =~ ^[0-9a-fA-F]{40}$ ]]; then
  fallback invalid_pr_authority
fi

base_sha=$(printf '%s' "$base_sha" | tr '[:upper:]' '[:lower:]')
head_sha=$(printf '%s' "$head_sha" | tr '[:upper:]' '[:lower:]')

git cat-file -e "$base_sha^{commit}" 2>/dev/null || fallback unresolved_base_commit
git cat-file -e "$head_sha^{commit}" 2>/dev/null || fallback unresolved_head_commit

diff_records=$(mktemp)
resulting_css=$(mktemp)
trap 'rm -f "$diff_records" "$resulting_css"' EXIT

if ! git diff --name-status --no-renames --no-ext-diff -z "$base_sha" "$head_sha" > "$diff_records"; then
  fallback unresolved_pr_diff
fi

if [[ ! -s "$diff_records" ]]; then
  fallback empty_pr_diff
fi

changed_count=0
while IFS= read -r -d '' status; do
  if ! IFS= read -r -d '' path; then
    fallback malformed_pr_diff
  fi
  changed_count=$((changed_count + 1))

  [[ "$status" == M ]] || fallback non_modified_path_status
  [[ "$path" =~ ^css/[^[:cntrl:]]+\.css$ ]] || fallback path_outside_direct_css_allowlist

  base_entry=$(git ls-tree "$base_sha" -- "$path") || fallback unresolved_base_path
  head_entry=$(git ls-tree "$head_sha" -- "$path") || fallback unresolved_head_path
  [[ -n "$base_entry" && -n "$head_entry" ]] || fallback missing_css_object

  base_mode=${base_entry%% *}
  head_mode=${head_entry%% *}
  [[ "$base_mode" == 100644 && "$head_mode" == 100644 ]] || fallback non_regular_or_mode_changed_css

  [[ "$(git cat-file -t "$base_sha:$path" 2>/dev/null || true)" == blob ]] || fallback invalid_base_css_object
  [[ "$(git cat-file -t "$head_sha:$path" 2>/dev/null || true)" == blob ]] || fallback invalid_head_css_object

  numstat=$(git diff --numstat --no-ext-diff --no-textconv "$base_sha" "$head_sha" -- "$path") || fallback unresolved_css_numstat
  [[ -n "$numstat" ]] || fallback empty_css_diff
  additions=${numstat%%$'\t'*}
  rest=${numstat#*$'\t'}
  deletions=${rest%%$'\t'*}
  [[ "$additions" =~ ^[0-9]+$ && "$deletions" =~ ^[0-9]+$ ]] || fallback binary_or_non_text_css

  first_line=$(git show "$head_sha:$path" | sed -n '1p') || fallback unresolved_css_blob
  if [[ "$first_line" == 'version https://git-lfs.github.com/spec/v1' ]]; then
    fallback lfs_pointer_css
  fi

  if ! git show "$head_sha:$path" > "$resulting_css"; then
    fallback unresolved_css_blob
  fi

  fallback_if_fixed_token '\' css_escape_or_continuation_present
  fallback_if_fixed_token '/*' css_comment_delimiter_present
  fallback_if_fixed_token '*/' css_comment_delimiter_present

  if perl -0777 -ne '$unsafe ||= /[\x00-\x08\x0b-\x1f\x7f]/; END { exit($unsafe ? 0 : 1) }' "$resulting_css"; then
    fallback noncanonical_css_control_present
  else
    control_scan_status=$?
    [[ "$control_scan_status" -eq 1 ]] || fallback css_control_scan_failed
  fi

  fallback_if_fixed_token '@' css_at_rule_marker_present
  fallback_if_case_insensitive_pattern 'url[[:blank:]]*\(' css_url_function_present
  fallback_if_fixed_token '://' css_network_marker_present
  fallback_if_fixed_token '//' css_network_marker_present
  fallback_if_case_insensitive_pattern 'expression[[:blank:]]*\(' executable_css_expression_present
done < "$diff_records"

[[ "$changed_count" -gt 0 ]] || fallback malformed_pr_diff

profile=DIRECT_CSS_FAST
reason=strict_existing_css_modifications
emit_result
