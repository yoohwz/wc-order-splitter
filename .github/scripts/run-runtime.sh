#!/usr/bin/env bash
set -euo pipefail
profile=${1:-}
export STORAGE=${2:-}
case "$profile" in STANDARD|CRITICAL|RELEASE_CERT) ;; *) exit 1 ;; esac
case "$STORAGE" in legacy|hpos|hpos-sync) ;; *) exit 1 ;; esac
npx wp-env start
trap 'npx wp-env stop' EXIT
npx wp-env run cli wp plugin install https://downloads.wordpress.org/plugin/woocommerce.11.0.1.zip --activate
case "$STORAGE" in
  legacy) enabled=no; sync=no ;;
  hpos) enabled=yes; sync=no ;;
  hpos-sync) enabled=yes; sync=yes ;;
esac
npx wp-env run cli wp option update woocommerce_custom_orders_table_enabled "$enabled"
npx wp-env run cli wp option update woocommerce_custom_orders_table_data_sync_enabled "$sync"
if [[ "$profile" != STANDARD ]]; then
  bash tests/runtime/prepare-legacy-upgrade.sh
fi
bash tests/runtime/activate-and-verify.sh
bash .github/scripts/run-integration-profile.sh "$profile"
if [[ "$profile" != STANDARD && "$STORAGE" == hpos ]]; then
  bash tests/runtime/concurrency.sh
fi
