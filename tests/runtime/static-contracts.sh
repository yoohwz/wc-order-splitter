#!/usr/bin/env bash
# Product assertions preserved from the accepted pre-reset CI workflow.
set -euo pipefail

# Enforce persisted Merge authority reads
tests/ci/merge-canonical-read-contract.sh

# Reject removed telemetry integration
telemetry_host='yoexpress''.''top'
test ! -e inc/cores/api/push-subscription.php
if grep -R --line-number --fixed-strings "$telemetry_host" --include='*.php' --include='*.js' --include='*.txt' . --exclude-dir=.git; then
  echo 'Removed telemetry endpoint must not be present.' >&2
  exit 1
fi
if grep -R --line-number --fixed-strings 'WC_Order_Splitter_Push_Subscription' --include='*.php' . --exclude-dir=.git; then
  echo 'Removed telemetry class must not be referenced.' >&2
  exit 1
fi


# Verify approved production gate state
plugin_version=$(sed -n 's/^ \* Version: //p' wc-order-splitter.php | head -n 1)
stable_tag=$(sed -n 's/^Stable tag: //p' readme.txt | head -n 1)
test -n "$plugin_version"
test -n "$stable_tag"
test "$plugin_version" = "$stable_tag"
grep -Fq 'Requires at least: 6.5' wc-order-splitter.php
grep -Fq 'Requires at least: 6.5' readme.txt
grep -Fq 'return WCOS_Feature_Gates::any_enabled();' inc/cores/safety.php
grep -Fq 'self::SPLIT => true' inc/domain/class-wcos-feature-gates.php
grep -Fq 'self::DUPLICATE => true' inc/domain/class-wcos-feature-gates.php
grep -Fq 'self::MERGE => true' inc/domain/class-wcos-feature-gates.php
grep -Fq 'self::RETURN_ORDER => true' inc/domain/class-wcos-feature-gates.php
grep -Fq 'self::BULK_RETURN => true' inc/domain/class-wcos-feature-gates.php
grep -Fq 'self::MANUAL_QUANTITY => true' inc/domain/class-wcos-split-strategy-gates.php
grep -Fq 'self::CATEGORY => true' inc/domain/class-wcos-split-strategy-gates.php
grep -Fq 'self::STOCK_STATUS => true' inc/domain/class-wcos-split-strategy-gates.php
grep -Fq 'Legacy mutation handlers are deliberately never loaded here.' inc/cores/script.php

if grep -R --line-number -E 'WC_ORDER_SPLITTER_(MUTATIONS|SPLIT|DUPLICATE|MERGE|RETURN|BULK_RETURN)_ENABLED' \
  wc-order-splitter.php inc/cores inc/domain; then
  echo 'An externally overrideable mutation gate returned to runtime source.' >&2
  exit 1
fi
if grep -R --line-number --fixed-strings 'wc-merged' wc-order-splitter.php inc; then
  echo 'A custom production merged order status was introduced.' >&2
  exit 1
fi


# Enforce one mutation engine and mandatory controller gateway
test ! -d inc/mutation-v2
test ! -d tests/v2

for legacy_handler in \
  'orders-bulk-return.php' \
  'order-split-button.php' \
  'order-return-option.php' \
  'order-duplicate-option.php' \
  'order-merge-option.php'; do
  if grep -F "$legacy_handler" inc/cores/script.php; then
    echo "Legacy mutation handler must not be loaded: $legacy_handler" >&2
    exit 1
  fi
done

if grep -R --line-number -E 'new[[:space:]]+WCOS_((Split|Duplicate|Return)_Order_Service|Merge_(Order_Service|WooCommerce_Adapter))' inc/backend inc/cores; then
  echo 'A production controller bypasses WCOS_Mutation_Gateway.' >&2
  exit 1
fi

grep -Fq 'WCOS_Feature_Gates::assert_enabled' inc/domain/class-wcos-mutation-gateway.php
grep -Fq 'WCOS_Order_Mutation_Authorizer::assert_workflow' inc/domain/class-wcos-mutation-gateway.php
grep -Fq 'new WCOS_Duplicate_WooCommerce_Adapter' inc/domain/class-wcos-mutation-gateway.php
grep -Fq 'class-wcos-duplicate-preflight.php' inc/cores/script.php
grep -Fq 'class-wcos-duplicate-woocommerce-adapter.php' inc/cores/script.php
grep -Fq 'class-wcos-duplicate-confirmation-store.php' inc/cores/script.php
grep -Fq 'class-wcos-duplicate-admin-controller.php' inc/cores/script.php
grep -Fq 'class-wcos-split-strategy-review-store.php' inc/cores/script.php
grep -Fq 'class-wcos-split-strategy-confirmation-store.php' inc/cores/script.php
grep -Fq 'class-wcos-split-strategy-admin-controller.php' inc/cores/script.php
grep -Fq 'WCOS_Split_Strategy_Admin_Controller::bootstrap();' inc/cores/script.php
grep -Fq 'class-wcos-multi-order-lease.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-financial-authority.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-plan.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-context-signature.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-journal-context.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-participation.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-recovery-snapshot.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-recovery-state-graph.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-commit-guard.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-compensator.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-preflight.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-retirement-policy.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-order-service.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-woocommerce-adapter.php' inc/cores/script.php
grep -Fq 'new WCOS_Merge_WooCommerce_Adapter' inc/domain/class-wcos-mutation-gateway.php
grep -Fq 'const APPROVED = self::NON_FORCE_TRASH_ARCHIVE;' inc/domain/class-wcos-merge-retirement-policy.php
grep -Fq 'class-wcos-merge-review-store.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-confirmation-store.php' inc/cores/script.php
grep -Fq 'class-wcos-merge-admin-controller.php' inc/cores/script.php
grep -Fq 'WCOS_Merge_Admin_Controller::bootstrap();' inc/cores/script.php
grep -Fq 'class-wcos-return-participation.php' inc/cores/script.php
grep -Fq 'class-wcos-return-plan.php' inc/cores/script.php
grep -Fq 'class-wcos-return-lineage-authority.php' inc/cores/script.php
grep -Fq 'class-wcos-return-preflight.php' inc/cores/script.php
grep -Fq 'class-wcos-return-journal-context.php' inc/cores/script.php
grep -Fq 'class-wcos-return-recovery-snapshot.php' inc/cores/script.php
grep -Fq 'class-wcos-return-recovery-state-graph.php' inc/cores/script.php
grep -Fq 'class-wcos-return-commit-guard.php' inc/cores/script.php
grep -Fq 'class-wcos-return-compensator.php' inc/cores/script.php
grep -Fq 'class-wcos-return-retirement-policy.php' inc/cores/script.php
grep -Fq 'class-wcos-return-source-evolution-authority.php' inc/cores/script.php
grep -Fq 'class-wcos-legacy-return-compatibility-authority.php' inc/cores/script.php
grep -Fq "'return' === \$type" inc/domain/class-wcos-mutation-recovery-coordinator.php
grep -Fq 'const APPROVED = self::NON_FORCE_TRASH_ARCHIVE;' inc/domain/class-wcos-return-retirement-policy.php
grep -Fq 'class-wcos-return-order-service.php' inc/cores/script.php
grep -Fq 'class-wcos-return-woocommerce-adapter.php' inc/cores/script.php
grep -Fq 'new WCOS_Return_WooCommerce_Adapter' inc/domain/class-wcos-mutation-gateway.php
grep -Fq 'class-wcos-return-review-store.php' inc/cores/script.php
grep -Fq 'class-wcos-return-confirmation-store.php' inc/cores/script.php
grep -Fq 'class-wcos-return-admin-controller.php' inc/cores/script.php
grep -Fq 'WCOS_Return_Admin_Controller::bootstrap();' inc/cores/script.php
test -e js/p2-return-admin.js
test -e css/p2-return-admin.css
grep -Fq "'wcos-return-admin'" inc/backend/class-wcos-admin-backbone-modal-assets.php
grep -Fq 'window.WCOSBackboneModal.open' js/p2-return-admin.js
grep -Fq "const SEARCH_ACTION = 'wcos_merge_target_search';" inc/backend/class-wcos-merge-admin-controller.php
grep -Fq "'wcos-merge-admin'" inc/backend/class-wcos-admin-backbone-modal-assets.php
grep -Fq 'window.WCOSBackboneModal.open' js/p2-merge-admin.js
grep -Fq 'inc/domain/' AGENTS.md
grep -Fq 'inc/domain/' docs/order-mutation-v2-contract.md
