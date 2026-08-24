# Repository Execution Contract

## Mission

Maintain a WordPress.org-compatible WooCommerce order-splitting plugin without violating stock, financial, tax, privacy, or order-item ownership invariants.

## Source authority

1. Current repository code and tests.
2. `inc/domain/` as the single replacement mutation-engine source of truth.
3. WooCommerce public CRUD and order data-store APIs.
4. WordPress.org Plugin Guidelines and Plugin Check.
5. The contracts in `docs/order-mutation-v2-contract.md`.
6. Public product copy only after implementation evidence exists.

`inc/mutation-v2/`, duplicate implementations, marketing copy, historical changelog statements, and legacy behavior are not architecture authority.

## Short-command task resolution

Operator prompts may use the repository short-command protocol in `docs/codex-short-command-protocol.md`.

Examples:

- `Chạy WOS-MERGE-009`
- `Tiếp tục WOS-MERGE-009`
- `Review WOS-MERGE-009`
- `Sửa WOS-MERGE-009`
- `Verify WOS-MERGE-009`
- `Status WOS-MERGE-009`

A short command is only a task/action selector. Before substantive work, Codex must resolve the canonical GitHub Issue, its comments, associated PR/branch, exact SHAs, CI/check state, and latest explicit governance checkpoint. The Issue/PR contract supplies scope, invariants, tests, stop conditions, and completion signals; the short prompt does not duplicate or override them.

If the task contract cannot be retrieved, stop with `TASK_CONTRACT_UNAVAILABLE`. If resolution is ambiguous, stop with `TASK_RESOLUTION_REQUIRED`. `Continue` must recover and resume current state rather than restart work. `Review` is executor-side readiness review and never substitutes for independent ChatGPT Technical Review where the task requires it.

A short `Merge` or `Release` command never implies Human Gate. Merge/release authority must already exist explicitly in the canonical GitHub task/PR context and must satisfy any exact-head binding required by that task. Otherwise stop with `HUMAN_GATE_REQUIRED`.

## Approved production baseline

The repository no longer has an all-mutations-hard-off production baseline. The current approved runtime gate state is:

- `WCOS_Feature_Gates::SPLIT = true`;
- `WCOS_Feature_Gates::DUPLICATE = true`;
- `WCOS_Feature_Gates::MERGE = true`;
- `WCOS_Feature_Gates::RETURN_ORDER = false`;
- `WCOS_Feature_Gates::BULK_RETURN = false`.

The current approved Split strategy gate state is:

- `WCOS_Split_Strategy_Gates::MANUAL_QUANTITY = true`;
- `WCOS_Split_Strategy_Gates::CATEGORY = false`;
- `WCOS_Split_Strategy_Gates::STOCK_STATUS = false`.

`WC_Order_Splitter_Safety_Guard::mutations_enabled()` must reflect the canonical approved `WCOS_Feature_Gates` state rather than acting as a contradictory second gate.

Changing any production workflow or strategy gate from `false` to `true` is a separate enablement milestone. It requires its own exact-state CI acceptance, independent technical review, and explicit Human Gate. Foundation/planner code may be packaged and loaded while its corresponding production strategy gate remains hard-off, provided it registers no production write surface.

## Non-negotiable gates

- Preserve the approved production gate map above unless the change is the dedicated, explicitly accepted gate-changing milestone for that workflow/strategy.
- No constant, option, filter, mu-plugin, or external plugin may enable an unfinished mutation workflow or Split strategy.
- Never re-enable or bootstrap a legacy mutation handler to make a UI or integration test pass.
- Production mutation controllers must enter through `WCOS_Mutation_Gateway`; controller code must not instantiate mutation services directly.
- Read-only strategy planners must not become an implicit mutation route. Category and Stock-status remain planner-only while their strategy gates are hard-off.
- Never copy an existing persisted `WC_Order_Item` object to another order.
- Never identify a commercial line by `product_id` alone.
- Never recalculate historical tax as an implicit side effect of Split, Duplicate, Merge, or Return.
- Never copy `_reduced_stock`; allocate it explicitly and prove conservation.
- Never duplicate shipping, fee, coupon, refund, transaction, or payment ownership without an explicit tested policy.
- Never add outbound requests, telemetry, subscriptions, or data collection without informed opt-in and documented disclosure.
- Never use a global email-recipient filter for mutation scoping.

## Change classification

Use one of these labels in plans and pull requests:

- `P0_RELEASE_SAFETY`: privacy, fatal error, corruption, or fail-closed release work.
- `P1_DOMAIN_CONTRACT`: planners, identities, allocators, snapshots, leases, journals, fingerprints, recovery contracts, governance boundaries, and invariants.
- `P2_WOOCOMMERCE_ADAPTER`: runtime mutation controllers, production workflow enablement, side-effect policy, HPOS/legacy adapter validation, and endpoint reintroduction.
- `P3_PRODUCT_QUALITY`: accessible UI, relation views, documentation, packaging, and release governance.

P1 may contain internal adapter/service/planner scaffolding used to prove domain contracts, but it must not create a new production write surface or alter an approved production gate unless the change is explicitly classified and reviewed as the corresponding P2 enablement milestone. Existing approved production workflows remain enabled while unrelated unfinished workflows/strategies stay hard-off.

## Required evidence

### Every change

- PHP syntax succeeds on PHP 7.4 and the current supported PHP versions.
- Existing domain and integration contracts remain green.
- No removed external endpoint or data collection returns.
- Plugin version and `Stable tag` stay aligned for release changes.
- No second mutation engine or competing lock/journal implementation is introduced.
- Canonical CI proves the exact approved production workflow and strategy gate state for the target branch.

### Mutation-domain changes

- Positive, negative, zero-decimal, and rounding-residual allocation cases.
- Quantity, monetary, per-rate tax, and `_reduced_stock` conservation.
- Stable fingerprint and explicit idempotency behavior.
- Exact line identity cases for variations and configured-product metadata.
- Protected operational metadata cannot be reclassified into commercial identity/copy state.
- Lease takeover, refresh, ownership, and release are concurrency-safe.
- Recovery snapshots are integrity-fingerprinted and exclude customer/payment PII.
- Compensation is resumable across persistence/checkpoint crash windows.

### WooCommerce adapter evidence before any later production enablement

- HPOS enabled, legacy storage, and compatibility/sync mode.
- Managed, unmanaged, variation, parent-managed, backorder, and fractional stock.
- Tax-inclusive and tax-exclusive prices, multiple rates, coupons, fees, and shipping.
- Deleted products, duplicate product lines, refunds, paid orders, and custom metadata.
- Double submission, stale lock, timeout, and injected failure recovery.
- Exact email, webhook, stock-hook, analytics, and order-note counts.
- Persisted invariants are verified after database re-read.
- A future Category/Stock-status production adapter must bind frozen planner evidence and confirmation authority, establish request-local `WCOS_Stock_Side_Effect_Guard` scope, and must not recompute live classification during Execute.

## Commands

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/unit/run.php
```

WooCommerce/WordPress integration contracts run through `.github/workflows/ci.yml` across legacy, HPOS, and HPOS-sync storage. The GitHub Actions workflows are the merge authority. Local success is necessary but not sufficient.

## Local-runtime worktree contract

When a task brief designates Local-runtime mode:

- Implementation and hands-on runtime validation must use the exact active Git worktree loaded by WordPress Local.
- Do not use a detached temporary implementation worktree for that task.
- Isolated worktrees remain allowed for independent review, experiments, and explicitly parallel non-runtime work.
- File copying or `rsync` between worktrees is not accepted as runtime evidence.
- Before changing worktree topology or switching the active Local-runtime branch, prove every affected worktree is clean and safe to switch. If that cannot be proven, stop with `LOCAL_RUNTIME_WORKTREE_SYNC_REQUIRED` without discarding uncommitted state.
- Canonical GitHub CI remains the merge authority.

## Review rules

- Review the complete diff, not only the newest commit.
- Treat money, tax, stock, refunds, and relation metadata as one transaction boundary.
- Reject hidden fallback behavior. Unsupported input must return a stable error and leave orders unchanged.
- Keep pull requests draft while required checks are absent, queued, or failing.
- Keep every unfinished workflow/strategy gate hard-off; do not revert already-approved production gates as a side effect of unrelated P1 work.
- Do not merge a new production mutation controller, strategy transport, or gate-changing diff without independent technical review and explicit Human Gate.
- Do not publish a WordPress.org ZIP unless the package/release workflow is green for the exact `main` state being released.
