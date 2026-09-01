#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)

# Deliberately bounded allowlist: only these files may constitute current/fresh
# Merge decision, plan, write, conservation, recovery, and admin reachability.
authority_surface_files=(
  inc/domain/class-wcos-merge-canonical-reader.php
  inc/domain/class-wcos-merge-preflight.php
  inc/domain/class-wcos-merge-financial-authority.php
  inc/domain/class-wcos-merge-commercial-policy.php
  inc/domain/class-wcos-merge-plan.php
  inc/domain/class-wcos-merge-context-signature.php
  inc/domain/class-wcos-merge-journal-context.php
  inc/domain/class-wcos-merge-recovery-snapshot.php
  inc/domain/class-wcos-merge-order-service.php
  inc/domain/class-wcos-merge-commit-guard.php
  inc/domain/class-wcos-merge-compensator.php
  inc/domain/class-wcos-merge-participation.php
  inc/domain/class-wcos-merge-woocommerce-adapter.php
  inc/domain/class-wcos-order-item-cloner.php
  inc/domain/class-wcos-order-totals-rebuilder.php
  inc/domain/class-wcos-tax-item-synchronizer.php
  inc/backend/class-wcos-merge-review-store.php
  inc/backend/class-wcos-merge-confirmation-store.php
  inc/backend/class-wcos-merge-admin-controller.php
)

# These files have no durable pre-WOS-COMPAT-006 view-projection branch. Bare
# item collections and authority getters are therefore always defects here.
strict_current_files=(
  inc/domain/class-wcos-merge-canonical-reader.php
  inc/domain/class-wcos-merge-preflight.php
  inc/domain/class-wcos-merge-financial-authority.php
  inc/domain/class-wcos-merge-plan.php
  inc/domain/class-wcos-merge-journal-context.php
  inc/domain/class-wcos-merge-order-service.php
  inc/domain/class-wcos-merge-commit-guard.php
  inc/domain/class-wcos-merge-participation.php
  inc/domain/class-wcos-merge-woocommerce-adapter.php
  inc/backend/class-wcos-merge-review-store.php
  inc/backend/class-wcos-merge-confirmation-store.php
  inc/backend/class-wcos-merge-admin-controller.php
)

canonical_reload_files=(
  inc/domain/class-wcos-merge-woocommerce-adapter.php
  inc/domain/class-wcos-merge-order-service.php
  inc/domain/class-wcos-merge-participation.php
  inc/backend/class-wcos-merge-review-store.php
  inc/backend/class-wcos-merge-confirmation-store.php
  inc/backend/class-wcos-merge-admin-controller.php
)

for relative in "${authority_surface_files[@]}"; do
  test -f "$repo_root/$relative"
done

for relative in "${strict_current_files[@]}"; do
  file="$repo_root/$relative"
  if grep -nE -- '->get_(items|item)\(' "$file"; then
    echo "merge-canonical-read-error: filtered item collection/reference read in $relative" >&2
    exit 1
  fi
  if grep -nE -- '->get_(status|currency|prices_include_tax|customer_id|billing_email|address|payment_method|payment_method_title|quantity|subtotal|subtotal_tax|total|total_tax|taxes|product_id|variation_id|tax_class)\([[:space:]]*\)' "$file"; then
    echo "merge-canonical-read-error: bare presentation getter in $relative" >&2
    exit 1
  fi
  if grep -nE -- "->get_[a-z_]+\([[:space:]]*'view'[[:space:]]*\)" "$file"; then
    echo "merge-canonical-read-error: explicit view getter in $relative" >&2
    exit 1
  fi
done

for relative in "${canonical_reload_files[@]}"; do
  file="$repo_root/$relative"
  test -f "$file"
  if grep -nF -- 'wc_get_order(' "$file"; then
    echo "merge-canonical-read-error: unscoped Merge order hydration in $relative" >&2
    exit 1
  fi
done

reader="$repo_root/inc/domain/class-wcos-merge-canonical-reader.php"
commercial="$repo_root/inc/domain/class-wcos-merge-commercial-policy.php"
context="$repo_root/inc/domain/class-wcos-merge-context-signature.php"
recovery="$repo_root/inc/domain/class-wcos-merge-recovery-snapshot.php"
commit_guard="$repo_root/inc/domain/class-wcos-merge-commit-guard.php"
compensator="$repo_root/inc/domain/class-wcos-merge-compensator.php"
participation="$repo_root/inc/domain/class-wcos-merge-participation.php"
adapter="$repo_root/inc/domain/class-wcos-merge-woocommerce-adapter.php"
admin="$repo_root/inc/backend/class-wcos-merge-admin-controller.php"
totals="$repo_root/inc/domain/class-wcos-order-totals-rebuilder.php"
tax_sync="$repo_root/inc/domain/class-wcos-tax-item-synchronizer.php"
cloner="$repo_root/inc/domain/class-wcos-order-item-cloner.php"
loader="$repo_root/inc/cores/script.php"

grep -Fq 'read_items($order, $item_type)' "$reader"
grep -Fq '$data_store->query($query_vars)' "$reader"
grep -Fq "'status' => 'any'" "$reader"
grep -Fq "\$query_vars['cache_results'] = false;" "$reader"
grep -Fq "array('woocommerce_order_get_', 'woocommerce_order_refund_get_', 'woocommerce_order_item_get_')" "$reader"
grep -Fq "'woocommerce_order_data_store_cpt_get_orders_query'" "$reader"
grep -Fq "'woocommerce_orders_table_datastore_get_orders_query'" "$reader"
grep -Fq "'woocommerce_hpos_pre_query'" "$reader"
grep -Fq "'parse_query'" "$reader"
grep -Fq "'pre_get_posts'" "$reader"
grep -Fq "'posts_pre_query'" "$reader"
grep -Fq 'new $class($order_id)' "$reader"
grep -Fq 'without_presentation_filters' "$reader"
grep -Fq 'unset($wp_filter[$hook]);' "$reader"
grep -Fq "get_order_id('edit')" "$reader"
grep -Fq "get_status('edit')" "$reader"
grep -Fq "get_currency('edit')" "$reader"
if grep -nF -- 'get_refunds(' "$reader"; then
  echo 'merge-canonical-read-error: canonical refund authority re-entered filtered wc_get_orders' >&2
  exit 1
fi
if grep -nF -- 'get_stock_managed_by_id' "$reader"; then
  echo 'merge-canonical-read-error: canonical stock authority re-entered view-filtered stock routing' >&2
  exit 1
fi
grep -Fq 'WCOS_Merge_Canonical_Reader::order_ids(' "$admin"
if grep -nF -- 'wc_get_orders(' "$admin"; then
  echo 'merge-canonical-read-error: admin reachability uses filterable order query' >&2
  exit 1
fi
grep -Fq 'uses_current_projection' "$commercial"
grep -Fq 'WCOS_Order_Contract_Snapshot::aggregate' "$commercial"
grep -Fq 'self::is_current_schema($schema_version)' "$recovery"
grep -Fq "self::order(\$pair['source_order_id'], \$schema_version)" "$recovery"
grep -Fq '$snapshot_schema = (int) $snapshot[' "$compensator"
grep -Fq 'participant_order($order_id, $snapshot_schema)' "$compensator"
grep -Fq "\$snapshot['schema_version']" "$commit_guard"
grep -Fq 'participant_order($order_id, $snapshot_schema)' "$commit_guard"
grep -Fq 'WCOS_Merge_Canonical_Reader::order(' "$participation"
grep -Fq 'WCOS_Merge_Canonical_Reader::order(' "$adapter"
grep -Fq "WCOS_Merge_Canonical_Reader::address(\$order, \$type)" "$context"
grep -Fq 'const PREVIOUS_SCHEMA_VERSION = 2;' "$context"
grep -Fq 'previous_disposition' "$context"
grep -Fq "\$context = \$canonical_merge ? 'edit' : 'view';" "$totals"
grep -Fq 'WCOS_Merge_Canonical_Reader::items($order, $item_type)' "$totals"
grep -Fq 'WCOS_Merge_Canonical_Reader::items($order' "$tax_sync"
grep -Fq 'WCOS_Merge_Canonical_Reader::without_presentation_filters($write)' "$tax_sync"
grep -Fq '$persist_directly && absint($item->get_id()) !== absint($item->save())' "$tax_sync"
grep -Fq "\$read_context = \$canonical_merge ? 'edit' : 'view';" "$cloner"
grep -Fq 'WCOS_Merge_Canonical_Reader::without_presentation_filters($write)' "$cloner"
grep -Fq 'WCOS_Merge_Canonical_Reader::without_presentation_filters($write)' "$totals"
grep -Fq "class-wcos-merge-canonical-reader.php" "$loader"

echo 'merge-canonical-read-contract: pass'
