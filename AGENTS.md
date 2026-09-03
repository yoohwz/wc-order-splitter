# Repository Execution Contract

Maintain a WordPress.org-compatible WooCommerce order-splitting plugin without
violating stock, financial, tax, privacy or order-item ownership invariants.

## Authority and execution

Current code/tests, `inc/domain/`, WooCommerce public CRUD/data-store APIs and
`docs/order-mutation-v2-contract.md` own product architecture. There is one
mutation engine. WordPress.org guidelines and Plugin Check remain distribution
requirements. Public product claims require implementation evidence.

`docs/workflow.md` is the sole active engineering workflow contract. Resolve the
canonical GitHub Issue, comments, current PR/branch/head and CI before acting on
`Run`, `Continue`, `Review`, `Fix`, `Verify` or `Status`. The Issue supplies bounded
scope and acceptance criteria. Do not reconstruct unavailable task authority.

Use `ChatGPT Create -> Codex Run -> ChatGPT Finalize`. Codex implements and gathers
proportional evidence; a fresh source-read-only Independent Codex Reviewer reviews
CRITICAL work and safety-control changes. ChatGPT owns Acceptance. The owner must
explicitly authorize merge of the current accepted head. Use native Required CI,
resolve review threads and let GitHub enforce its unchanged ruleset at squash
merge. No Issue/comment parsing or second merge-policy engine belongs in CI.

Keep PRs draft while implementation, CI or required review is incomplete. Run
bounded corrections on the same task/PR. Never weaken product tests, production
gates or repository protection to make governance pass. Stop for architecture or
scope review if those boundaries would need to change. Merge permission does not
authorize release, package, tag, publication or deployment.

End meaningful task-state responses with exactly one NEXT_ACTION_HINT footer as
defined in `docs/workflow.md`.

## Production gate authority

Mutable production gate state is owned by `inc/domain/class-wcos-feature-gates.php` and `inc/domain/class-wcos-split-strategy-gates.php` at the exact source SHA under review. Each canonical task must bind its expected workflow and strategy gate map; this governance document defines gate-change policy and must not duplicate a mutable snapshot.

`WC_Order_Splitter_Safety_Guard::mutations_enabled()` must reflect the code-owned `WCOS_Feature_Gates` state rather than acting as a contradictory second gate.

Changing any production workflow or strategy gate from `false` to `true` is a separate enablement milestone. It requires its own exact-state CI acceptance, independent technical review, and explicit Human Gate. Foundation/planner code may be packaged and loaded while its corresponding production strategy gate remains hard-off, provided it registers no production write surface.

## Non-negotiable gates

- Preserve the exact code-owned production gate map bound by the canonical task unless the change is the dedicated, explicitly accepted gate-changing milestone for that workflow/strategy.
- No constant, option, filter, mu-plugin, or external plugin may enable an unfinished mutation workflow or Split strategy.
- Never re-enable or bootstrap a legacy mutation handler to make a UI or integration test pass.
- Production mutation controllers must enter through `WCOS_Mutation_Gateway`; controller code must not instantiate mutation services directly.
- Read-only strategy planners must not become an implicit mutation route. A strategy may register a production write surface only when its code-owned gate is enabled by an explicitly accepted milestone.
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
- `P1_GOVERNANCE`: engineering authority, task routing, acceptance boundaries, and governance invariants.
- `P1_DOMAIN_CONTRACT`: planners, identities, allocators, snapshots, leases, journals, fingerprints, recovery contracts, governance boundaries, and invariants.
- `P2_WOOCOMMERCE_ADAPTER`: runtime mutation controllers, production workflow enablement, side-effect policy, HPOS/legacy adapter validation, and endpoint reintroduction.
- `P3_PRODUCT_QUALITY`: accessible UI, relation views, documentation, packaging, and release governance.

P1 may contain internal adapter/service/planner scaffolding used to prove domain contracts, but it must not create a new production write surface or alter a code-owned production gate unless the change is explicitly classified and reviewed as the corresponding P2 enablement milestone. Existing accepted production workflows remain enabled while unrelated unfinished workflows/strategies stay hard-off.

## Required evidence

### Every change

- CRITICAL and RELEASE_CERT run the supported PHP 7.4/8.1/8.3 matrix; STANDARD uses PHP 8.3 and FAST uses focused static checks.
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

## Local validation

Run focused evidence from the active plugin worktree. Canonical PR CI runs the
selected FAST, STANDARD or CRITICAL profile; RELEASE_CERT is release-only.
Do not create an installable ZIP without explicit package/release authority.

```sh
php tests/unit/run.php
bash .github/scripts/run-fast.sh
bash .github/scripts/run-static.sh
```

## Local-runtime worktree contract

When a task brief designates Local-runtime mode:

- Implementation and hands-on runtime validation must use the exact active Git worktree loaded by WordPress Local.
- Do not use a detached temporary implementation worktree for that task.
- Isolated worktrees remain allowed for independent review, experiments, and explicitly parallel non-runtime work.
- File copying or `rsync` between worktrees is not accepted as runtime evidence.
- Before changing worktree topology or switching the active Local-runtime branch, prove every affected worktree is clean and safe to switch. If that cannot be proven, stop with `LOCAL_RUNTIME_WORKTREE_SYNC_REQUIRED` without discarding uncommitted state.
- Canonical GitHub CI remains the merge authority.

## Review invariants

- Review the complete diff and evidence; Executor self-review cannot replace required Independent Review.
- Treat money, tax, stock, refunds and relation metadata as one transaction boundary.
- Reject hidden fallbacks: unsupported input returns a stable error and leaves orders unchanged.
- Preserve the task-bound production gate map and all critical product suites.
- Never merge a production write surface or gate enablement without independent review and explicit Human permission.
- Release certification binds PRODUCT_TREE_SHA; excluded repository drift alone cannot invalidate the product certificate.
