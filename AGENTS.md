# Repository Execution Contract

## Mission

Maintain a WordPress.org-compatible WooCommerce order-splitting plugin without violating stock, financial, tax, privacy, or order-item ownership invariants.

## Source authority

1. Current repository code and tests.
2. `inc/domain/` as the single replacement mutation-engine source of truth.
3. WooCommerce public CRUD and order data-store APIs.
4. WordPress.org Plugin Guidelines and Plugin Check.
5. The contracts in `docs/order-mutation-v2-contract.md`.
6. The engineering authority contract in `docs/engineering-review-authority.md`.
7. The direct-workflow contract in `docs/codex-direct-workflow.md` when `TRIVIAL / CODEX_DIRECT` is explicitly invoked.
8. Public product copy only after implementation evidence exists.

`inc/mutation-v2/`, duplicate implementations, marketing copy, historical changelog statements, and legacy behavior are not architecture authority.

## Short-command task resolution

Operator prompts may use the repository short-command protocol in `docs/codex-short-command-protocol.md`.

Examples:

- `Create WOS-MERGE-009`
- `Chạy WOS-MERGE-009`
- `Finalize WOS-MERGE-009`
- `Tiếp tục WOS-MERGE-009`
- `Review WOS-MERGE-009`
- `Sửa WOS-MERGE-009`
- `Verify WOS-MERGE-009`
- `Status WOS-MERGE-009`
- `Direct Make the admin card spacing more compact`
- `Quick Adjust the admin button colors`

A short existing-task command is only a task/action selector. Before substantive work, Codex must resolve the canonical GitHub Issue, its comments, associated PR/branch, exact SHAs, CI/check state, and latest explicit governance checkpoint. The Issue/PR contract supplies scope, invariants, tests, stop conditions, and completion signals; the short prompt does not duplicate or override them. `Direct <request>` / `Quick <request>` is the sole bootstrap exception and must persist/authenticate its own bounded canonical Issue plus `DIRECT_HUMAN_AUTHORIZED` before the first source edit under `docs/codex-direct-workflow.md`.

The preferred normal lifecycle remains `ChatGPT Create -> Codex Run -> ChatGPT Finalize`, governed by `docs/compressed-engineering-workflow.md`. After canonical `POST_MERGE_ACCEPTED` for WOS-GOV-009, assurance and CI are separate dimensions: LOW uses persisted Executor evidence without Independent Review by default; MEDIUM requires Independent Review on explicit semantic triggers or ambiguity; HIGH always requires Independent Review; deterministic DIRECT omits ChatGPT Create/Acceptance and Independent Review. These rules are prospective. WOS-GOV-009 itself and untransitioned active tasks use their source-bound prior workflow.

Review-required MEDIUM/HIGH uses `PRECHECK -> fresh Independent PRE_REVIEW -> FINAL`. PRECHECK must not satisfy protected `Required CI`. Each exact-head `PRE_REVIEW_CLEAN` or `PRE_REVIEW_CHANGES_REQUIRED` cycle must be automatically persisted as a new structured GitHub record and re-read/authenticated. A changed head reruns PRECHECK and complete review. An unchanged clean review may be mechanically promoted after green FINAL to `TECHNICAL_ACCEPTED` only by binding that Independent Review authority ID, exact head/tree, final run/profile, and artifacts=0; the Executor cannot manufacture the underlying conclusion.

Chat/session-only review text is evidence only. If a required Independent Review or mechanical promotion record cannot be persisted and re-read with authenticated exact-head provenance, stop `TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>` and do not route to Acceptance or `Finalize`.

If the active Codex surface cannot establish fresh separate reviewer provenance for required review, stop `INDEPENDENT_REVIEW_DISPATCH_REQUIRED` rather than allowing the Executor to self-review. Automatic correction/re-review remains capped by `TECHNICAL_ESCALATION_REQUIRED`.

Deterministic DIRECT remains limited to paired numeric `border-radius` declaration edits in existing Git-tracked regular-text mode-`100644` CSS presentation files beneath `css/`, with no selector/rule/other-property/reachability semantics, creation/deletion/rename/copy/object ambiguity, or breach of the strict repository lexical envelope. It requires pre-edit `DIRECT_HUMAN_AUTHORIZED`, `DIRECT_FAST` Required CI, revalidated `HUMAN_GATE_APPROVED_DIRECT`, squash merge, and `POST_MERGE_ACCEPTED_DIRECT`; it has no `TECHNICAL_ACCEPTED` checkpoint. Any ambiguity or wider scope stops `CODEX_DIRECT_NOT_ELIGIBLE` or `CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED` before widening.

`Finalize <TASK_ID>` is the authenticated human's conditional Human Gate for the exact current head. ChatGPT must authenticate task-appropriate evidence (`EXECUTOR_EVIDENCE_READY` for no-review LOW/MEDIUM or independent-review-bound `TECHNICAL_ACCEPTED` for reviewed work), perform distinct `ACCEPTANCE_ACCEPTED`, revalidate head/base/CI/review authority, record `HUMAN_GATE_APPROVED`, squash-merge, and prove exact-tree `POST_MERGE_ACCEPTED`. Failed Acceptance or drift cannot merge. `Finalize` never authorizes release, publication, deployment, or a public package.

If the task contract cannot be retrieved, stop with `TASK_CONTRACT_UNAVAILABLE`. If resolution is ambiguous, stop with `TASK_RESOLUTION_REQUIRED`. `Continue` must recover and resume current state rather than restart work. `Review` is executor-side readiness review and never substitutes for a fresh Independent Codex Technical Review where the task requires it.

Governance signal text is not authority by itself. Before accepting `DIRECT_HUMAN_AUTHORIZED`, `EXECUTOR_EVIDENCE_READY`, `PRE_REVIEW_CLEAN`, `PRE_REVIEW_CHANGES_REQUIRED`, `TECHNICAL_ACCEPTED`, `TECHNICAL_CHANGES_REQUIRED`, `ACCEPTANCE_ACCEPTED`, `ACCEPTANCE_CHANGES_REQUIRED`, `RELEASE_FREEZE_APPROVED`, `HUMAN_GATE_APPROVED`, `HUMAN_GATE_APPROVED_DIRECT`, publication approval, or an equivalent checkpoint, Codex must authenticate the GitHub actor and required role/provenance against the task contract and repository ownership. Quoted/copied/reposted text is never authority. Executor evidence cannot become an independent conclusion or Human Gate; mechanical post-FINAL promotion is valid only when it binds a prior authenticated exact-head Independent Review.

A short `Merge` or `Release` command never implies Human Gate. Merge/release authority must already exist explicitly in the canonical GitHub task/PR context and must satisfy any exact-head binding required by that task. The only direct conditional Human Gate originates from an explicit `Direct` / `Quick` request persisted as `DIRECT_HUMAN_AUTHORIZED` before source edits and may become `HUMAN_GATE_APPROVED_DIRECT` only after every direct invariant is revalidated. Otherwise stop with `HUMAN_GATE_REQUIRED`.

### Mandatory operator handoff

Every meaningful Codex task-state response and every deterministic stop signal must end with exactly one `NEXT_ACTION_HINT` footer in the canonical format defined by `docs/codex-short-command-protocol.md`. The footer must identify who acts next, whether the command belongs in ChatGPT, Codex, or the GitHub UI, the exact copy/paste-ready command, and the expected signal or outcome.

The footer is navigation only. It must not widen authority, bypass profile/CI/review/Human Gate, or imply release. Authenticated `EXECUTOR_EVIDENCE_READY` for no-review normal work and `TECHNICAL_ACCEPTED` for reviewed work route to `Finalize`; missing required Independent Review routes to a fresh reviewer; persistence failure blocks Finalize. Eligible DIRECT continues internally through unchanged-authority `HUMAN_GATE_APPROVED_DIRECT` and never routes to Finalize. Older/source-bound tasks retain their explicit workflow.

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

- PHP-touching and HIGH/RELEASE certification succeeds on PHP 7.4 and the current supported PHP versions; non-PHP LOW/DIRECT runs the exact focused static profile.
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

WooCommerce/WordPress integration contracts run through `.github/workflows/ci.yml`. MEDIUM uses affected-domain HPOS by default; HIGH final profiles use affected domains and sentinels across storage-sensitive modes; `RELEASE_CERT` retains the exhaustive legacy/HPOS/HPOS-sync baseline union. Local success is necessary but not sufficient.

Normal Local implementation evidence is scope-focused: use the exact active plugin worktree and pushed task HEAD, run relevant PHP/unit checks, add focused WooCommerce integration evidence when it establishes task-specific behavior, and use hands-on browser/UI evidence only for behavior CI cannot establish. The full legacy/HPOS/HPOS-sync regression matrix belongs to canonical PR CI unless a task explicitly requires Local reproduction of a storage defect or broader Local evidence. Normal development does not create an installable ZIP; package artifacts require explicit task or release authority.

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
- Keep Codex Executor, fresh Independent Codex Reviewer where required, and ChatGPT Acceptance Reviewer distinct under `docs/engineering-review-authority.md`.
- LOW/no-trigger MEDIUM Executor evidence is never `TECHNICAL_ACCEPTED`. A mechanical post-FINAL Technical Acceptance must bind prior exact-head `PRE_REVIEW_CLEAN`; ChatGPT cannot substitute Acceptance for required technical review.
- The Independent Reviewer must persist a new immutable structured GitHub PRE_REVIEW/legacy Technical Review record for every exact-head cycle; metadata writes do not relax source read-only behavior.
- Automatic technical correction/re-review orchestration is limited to three head-changing cycles per engineering loop; then stop with `TECHNICAL_ESCALATION_REQUIRED`.
- No assurance profile waives protected FINAL `Required CI`, task-appropriate exact-head evidence, explicit Human Gate, post-merge proof, or release/publication authority. LOW/MEDIUM/HIGH cannot waive ChatGPT Acceptance. Only deterministic DIRECT omits it under `docs/codex-direct-workflow.md`.
- Treat money, tax, stock, refunds, and relation metadata as one transaction boundary.
- Reject hidden fallback behavior. Unsupported input must return a stable error and leave orders unchanged.
- Keep pull requests draft while required checks are absent, queued, or failing.
- Preserve the task-bound code-owned gate map; do not revert already accepted production gates or enable unfinished workflows/strategies as a side effect of unrelated work.
- Do not merge a new production mutation controller, strategy transport, or gate-changing diff without independent technical review and explicit Human Gate.
- Do not publish a WordPress.org ZIP unless the package/release workflow is green for the exact `main` state being released.
