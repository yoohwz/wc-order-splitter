# Compressed Engineering Workflow and Risk Profiles

## Purpose

This contract reduces operator interaction without collapsing implementation, technical review, Human Gate, CI, release, or publication authority. ChatGPT Acceptance remains mandatory for normal `LOW` / `MEDIUM` / `HIGH` work; the only exception is an exact, fail-closed `TRIVIAL / CODEX_DIRECT` task governed by `docs/codex-direct-workflow.md`.

After canonical `POST_MERGE_ACCEPTED` for `WOS-GOV-005`, the preferred successful path for a newly created implementation task is:

`ChatGPT Create -> Codex Run -> ChatGPT Finalize`

The recorded authority sequence remains:

`TASK_READY -> Executor evidence -> exact-head CI -> persisted GitHub Independent Codex TECHNICAL_ACCEPTED -> ChatGPT ACCEPTANCE_ACCEPTED -> HUMAN_GATE_APPROVED -> merge -> exact-tree Main attestation -> POST_MERGE_ACCEPTED`.

Compression changes orchestration only. Green CI is not review, Acceptance is not Technical Acceptance, and the conditional Human Gate inside `Finalize` is still a distinct authenticated checkpoint.

After canonical `POST_MERGE_ACCEPTED` for `WOS-GOV-007`, explicitly requested eligible direct work may instead use:

`Human Direct/Quick -> persisted DIRECT_HUMAN_AUTHORIZED -> Codex Executor -> Required CI -> fresh persisted Independent Codex TECHNICAL_ACCEPTED with direct eligibility confirmed -> HUMAN_GATE_APPROVED_DIRECT -> squash merge -> exact-tree Main attestation -> POST_MERGE_ACCEPTED_DIRECT`.

This path waives only ChatGPT `Create`, Acceptance, and `Finalize`; all other authority remains distinct. It must satisfy the CSS-first allowlist, semantic guard, pre-edit persistence, fail-closed escalation, and no-release boundary in `docs/codex-direct-workflow.md`.

## Create

`Create <TASK_ID>` is a ChatGPT command. A natural-language request is equivalent only when it clearly authorizes creation of the named task.

For normal work, ChatGPT must resolve current accepted `main`, create or update the canonical GitHub Issue, assign a `LOW`, `MEDIUM`, or `HIGH` risk profile, bind source and gate authority, define scope, invariants, evidence, stop conditions, and release/publication boundaries, and record `TASK_READY`. A `TRIVIAL / CODEX_DIRECT` task is bootstrapped in Codex only through an explicit `Direct <request>` / `Quick <request>` and the pre-edit authority sequence in `docs/codex-direct-workflow.md`.

Plan Review is exceptional. ChatGPT records `PLAN_REVIEW_REQUIRED` only when discovery exposes a product or architecture decision that cannot safely be bound in the task contract. It is not an automatic extra step.

## Run

`Run <TASK_ID>` / `Chạy <TASK_ID>` is a Codex command representing the complete engineering loop when the active Codex surface can create and authenticate a separate reviewer context:

1. Resolve the canonical Issue, comments, PR/branch, exact SHAs, CI, and current governance checkpoint.
2. The Codex Executor discovers, implements, tests, opens or updates the PR, waits for canonical exact-head CI, and performs complete-diff self-review.
3. Codex dispatches a fresh Independent Codex Reviewer in a separate conversation, agent, worktree, or cloud review context. Distinct GitHub Codex review provenance is preferred.
4. The reviewer stays source read-only/no-implementation-write and produces exact-head `TECHNICAL_ACCEPTED` or `TECHNICAL_CHANGES_REQUIRED` with the attestation required by `docs/engineering-review-authority.md`.
5. Before the review cycle is complete, the reviewer/orchestrating Codex surface automatically persists the complete structured Technical Review record to canonical GitHub authority and re-reads the new record to authenticate its actor, role, exact head, and outcome.
6. An authenticated persisted changes-required tranche may return to the Executor for correction, proportional Local evidence, exact-head CI, and a new complete Independent Review of the changed head.

The Executor must never review itself or issue `TECHNICAL_ACCEPTED`. If the surface cannot establish fresh reviewer separation and attestable provenance, stop:

`INDEPENDENT_REVIEW_DISPATCH_REQUIRED: <TASK_ID>`

The fallback next command is `Technical Review <TASK_ID>` in a new/fresh Codex reviewer context.

Automatic correction is bounded to at most three head-changing technical correction cycles per engineering loop. If a fourth correction would be required, or the same boundary remains unresolved, stop:

`TECHNICAL_ESCALATION_REQUIRED: <TASK_ID>`

## Technical Review persistence

Every Independent Codex Technical Review cycle must be persisted automatically before it can supply canonical authority. A chat/session-only `TECHNICAL_ACCEPTED` or `TECHNICAL_CHANGES_REQUIRED` is evidence only.

Persistence must use one of these forms:

1. When the reviewer surface can submit a real GitHub PR review, publish the complete structured Technical Review as that PR review and add a concise canonical task Issue comment referencing the PR review ID/URL, exact head, outcome, and reviewer provenance.
2. When a fresh Codex app/CLI/cloud reviewer runs under the repository-owner account, publish the complete structured review as a new top-level canonical task Issue comment.

Every record must bind task ID, PR number, exact base/head, tested merge-candidate/tree where available, canonical CI, `independent_codex_reviewer` role, fresh-context and executor-session-not-reused attestations, source read-only/no-implementation-write attestation, findings and reproduction evidence, and the exact terminal outcome.

Each correction/re-review cycle creates a new immutable GitHub review/comment. Never edit or replace an earlier review; the later persisted record supersedes it only for its own exact head.

GitHub review/comment persistence is governance metadata, not an implementation write. The Independent Reviewer must still make no source edits, commits, pushes, or PR-head changes.

If the automatic GitHub write fails, or the new record cannot be re-read and authenticated, stop:

`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`

Do not route to Acceptance or `Finalize` while this stop is active.

On exact-head Technical Acceptance for normal `LOW` / `MEDIUM` / `HIGH` work, the Codex engineering loop must re-read GitHub and verify the final persisted record is directly attributable to the Independent Codex Reviewer. Only then may Codex report:

`READY_TO_FINALIZE: <TASK_ID> / PR #N / exact head <SHA> / TECHNICAL_ACCEPTED / persisted authority <Issue comment ID or PR review ID>`

`READY_TO_FINALIZE` is routing evidence only; the identified persisted GitHub `TECHNICAL_ACCEPTED` record remains authoritative. The human operator must not need to copy/paste Technical Review results between Codex and GitHub.

An eligible direct task never reports `READY_TO_FINALIZE`. Its persisted Independent Review must explicitly confirm `TRIVIAL / CODEX_DIRECT` eligibility; Codex then applies only the pre-edit authenticated conditional Human Gate sequence in `docs/codex-direct-workflow.md`.

## Finalize

`Finalize <TASK_ID>` is a ChatGPT command and the authenticated human's explicit conditional Human Gate for the exact currently technically accepted PR head. It does not authorize merge unless every condition below succeeds in sequence.

ChatGPT must:

1. Resolve the canonical Issue/PR, current `main`, exact base/head, merge candidate/tree, canonical CI, artifacts, ruleset, and unresolved threads.
2. Independently resolve and authenticate the persisted GitHub fresh Independent Codex `TECHNICAL_ACCEPTED` review/comment authority ID for the exact unchanged head. Reject conversation/session-only review text.
3. Perform ChatGPT Acceptance Review for product intent, architecture, contract, scope, evidence, and governance.
4. If Acceptance fails, record `ACCEPTANCE_CHANGES_REQUIRED` or `TECHNICAL_REVIEW_FOLLOWUP_REQUIRED` and do not merge.
5. If Acceptance succeeds, record exact-head `ACCEPTANCE_ACCEPTED`.
6. Re-resolve head, base, CI, merge candidate, and authority. Any drift invalidates the conditional Human Gate.
7. Record `HUMAN_GATE_APPROVED` for that same unchanged head because the authenticated human explicitly issued `Finalize`.
8. Merge only when the task contract and repository rules permit it.
9. Prove merged commit parent/tree equivalence to the tested PR merge candidate/tree, verify successful exact-SHA `Main attestation` with zero unauthorized artifacts/workflows, record `POST_MERGE_ACCEPTED`, and only then close the task.

The distinct `ACCEPTANCE_ACCEPTED`, `HUMAN_GATE_APPROVED`, and `POST_MERGE_ACCEPTED` records remain mandatory even though one operator command coordinates them.

Failed Acceptance or drift cannot merge.

`Finalize` never grants tag, release, publication, deployment, or public-package authority. A release-bookkeeping PR may be finalized, but `WOS-REL-001 / 1.5.0` still requires `RELEASE_FREEZE_APPROVED` before candidate work and a separate explicit `Publish WOS-REL-001` authority bound to the exact verified SHA/artifact.

## Risk profiles

Risk controls planning, Local evidence, and independent-review depth. No profile may waive exact-head Technical Acceptance, protected-branch `Required CI`, explicit Human Gate semantics, post-merge proof, or release/publication boundaries. `LOW`, `MEDIUM`, and `HIGH` also never waive ChatGPT Acceptance. Only `TRIVIAL / CODEX_DIRECT` omits ChatGPT Acceptance under the exact scope and authority guards below.

### TRIVIAL / CODEX_DIRECT

Use only after canonical `POST_MERGE_ACCEPTED` for `WOS-GOV-007`, and only for an explicitly requested direct task that stays within `docs/codex-direct-workflow.md`.

The initial allowlist is limited to modifications of existing Git-tracked regular-text `*.css` presentation files beneath `css/`. Direct tasks cannot add/delete/rename files and cannot touch PHP, JavaScript, runtime/state/security/release/package/gate/test or normative-governance scope. CSS that changes control reachability, busy/disabled/blocked semantics, interaction authority, authoritative messaging, accessibility-critical state, or external network loading is not TRIVIAL.

Codex must persist and authenticate `DIRECT_HUMAN_AUTHORIZED` before the first source edit, use a fresh branch, run protected-branch `Required CI`, obtain a fresh persisted exact-head Independent Codex `TECHNICAL_ACCEPTED` that explicitly confirms direct eligibility, revalidate unchanged authority, persist `HUMAN_GATE_APPROVED_DIRECT`, squash-merge, and prove exact-tree `POST_MERGE_ACCEPTED_DIRECT`. The Executor cannot self-accept. Any ambiguity or wider actual diff stops `CODEX_DIRECT_NOT_ELIGIBLE` before edits or `CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED` before widening.

### LOW

Use for documentation, copy, translation, non-semantic presentation changes, isolated admin UX polish without authority/state changes, or narrow governance/test/build maintenance without runtime semantic expansion.

Use concise task contracts, focused Local evidence, protected-branch CI, and a focused but still independent review. Reclassify upward if the work changes authorization, persistence semantics, mutation logic, destructive state, release/package security, or public API compatibility.

### MEDIUM

Use for bounded runtime features, adapter/controller/UI wiring over accepted backend contracts, compatibility work without new destructive mutation semantics, or behavior-preserving refactors needing meaningful runtime evidence.

Require explicit invariants, focused Local runtime evidence, canonical PR CI, and complete Independent Codex PR review.

### HIGH

Mandatory when work materially involves commercial order/customer mutation; money, totals, tax, historical pricing; stock or `_reduced_stock`; journals, recovery, compensation, idempotency, or reconciliation; concurrency, leases, or single-consumption; authentication, authorization, secrets, security, or privacy; migrations or destructive persistence; production feature-gate enablement; payment/transaction state; or release/package/publication integrity.

Require a detailed threat/failure model, exact invariants, the strongest relevant Local/integration/storage/concurrency evidence, full canonical PR CI, and adversarial Independent Codex Review with reproduction where reasonably possible. The same maximum of three automatic technical correction cycles applies.

## Exception routing and compatibility commands

Normal `LOW` / `MEDIUM` / `HIGH` work uses `Create -> Run -> Finalize`. Eligible `TRIVIAL / CODEX_DIRECT` is the sole no-ChatGPT exception and follows `docs/codex-direct-workflow.md`. Additional operator interactions are reserved for real exceptions:

- `PLAN_REVIEW_REQUIRED`, `ARCHITECTURE_REVIEW_REQUIRED`, `PRODUCT_DECISION_REQUIRED`, or `SCOPE_REVIEW_REQUIRED` returns the unresolved decision to ChatGPT/Human governance.
- `INDEPENDENT_REVIEW_DISPATCH_REQUIRED` routes to a manually opened fresh `Technical Review <TASK_ID>` context.
- `TECHNICAL_REVIEW_PERSISTENCE_REQUIRED` blocks Acceptance/Finalize until the reviewer/orchestrating Codex surface persists and authenticates the structured exact-head GitHub record.
- `TECHNICAL_ESCALATION_REQUIRED` returns repeated technical failure or ambiguity to ChatGPT/Human governance.
- `ACCEPTANCE_CHANGES_REQUIRED` routes the bounded correction tranche to Codex.
- `TECHNICAL_REVIEW_FOLLOWUP_REQUIRED` routes the bounded hypothesis to an Independent Codex Reviewer before it may become correction authority.
- `TASK_BRANCH_SYNC_REQUIRED`, `LOCAL_RUNTIME_WORKTREE_SYNC_REQUIRED`, and release/publication-specific signals remain fail-closed.
- `CODEX_DIRECT_NOT_ELIGIBLE` makes no source edit and routes the request to normal `LOW`, `MEDIUM`, or `HIGH` task creation.
- `CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED` stops before widening the diff and preserves the existing direct Issue for an explicit normal-workflow transition.

`Review`, `Sửa/Fix`, `Technical Review`, `Acceptance Review`, and `Human Gate` remain supported lower-level, fallback, and compatibility commands. They are not mandatory operator steps when the compressed lifecycle is active and automatic reviewer provenance is available.

Tasks created before `WOS-GOV-005` may use compressed `Finalize` only through an explicit canonical transition comment. `WOS-GOV-005` itself uses the pre-existing WOS-GOV-004 bootstrap sequence and does not activate this contract until its own canonical `POST_MERGE_ACCEPTED`.

## Roadmap compression

Combine adjacent future milestones when they serve one coherent product outcome, intermediate state stays safely hard-off/non-production, evidence can distinguish phases without a production merge between them, and no unresolved architecture, gate, migration, destructive-state, or publication boundary requires separate exact-state authority.

After `WOS-GOV-005` is terminally accepted, the remaining single-Return roadmap is:

1. `WOS-RETURN-006 — Return UI + Sandbox Readiness`: shared WooCommerce Backbone launcher/modal, UI/accessibility, and sandbox-only enabled-state evidence derived from the exact hard-off candidate; the production PR remains `RETURN_ORDER=false`.
2. `WOS-RETURN-007 — Return Production Enablement`: a fresh minimal current-main HIGH-risk candidate for the explicit `RETURN_ORDER=false -> true` transition; `BULK_RETURN=false` remains unchanged.

Do not create or implement `WOS-RETURN-006` while `WOS-GOV-005` is active. Historical references to earlier Return numbering remain immutable evidence.

Bulk Return starts with one zero-assumption parity/architecture audit. Prefer at most one hard-off backend/Review/Confirm/UI milestone and one sandbox/production-enablement boundary unless the audit proves additional atomicity or recovery milestones are necessary.
