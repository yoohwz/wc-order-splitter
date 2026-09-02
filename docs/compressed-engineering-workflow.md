# Compressed Engineering Workflow and Risk-Tiered Assurance

## Purpose and operator UX

The operator UX remains:

`ChatGPT Create -> Codex Run -> ChatGPT Finalize`

plus explicit `Direct` / `Quick` for deterministic DIRECT work. Risk-tiered assurance changes the internal engine, not the operator command sequence. Exact-head CI, ChatGPT Acceptance for normal work, Human Gate, squash merge, Main attestation, and release/publication separation remain mandatory.

These rules become prospective only after canonical `POST_MERGE_ACCEPTED` for `WOS-GOV-009`. WOS-GOV-009 itself bootstraps through the previous FULL + fresh Independent Technical Review workflow and cannot use its own shortcuts.

## Two independent dimensions

Assurance profile decides who must independently review:

- `DIRECT`: deterministic non-semantic proof; no ChatGPT Create/Acceptance and no Independent Review.
- `LOW`: Executor evidence + ChatGPT Acceptance; no Independent Review by default.
- `MEDIUM`: Independent Review only when semantic trigger surfaces or ambiguity are present.
- `HIGH`: Independent Review and ChatGPT Acceptance always mandatory.

CI profile/stage decides deterministic certification cost:

- `DIRECT_FAST`
- `LOW_FOCUSED`
- `MEDIUM_DOMAIN`
- `HIGH_DEEP`
- `HIGH_FINANCIAL`
- `RELEASE_CERT`
- stage `PRECHECK` or `FINAL` for review-required candidates.

Task/label/title/body/actor claims cannot lower the machine-derived minimum. Canonical task authority separately binds CI, assurance, and review floors; each may only raise its corresponding machine-derived minimum.

## Create

`Create <TASK_ID>` is a ChatGPT command. ChatGPT resolves accepted source, creates/updates the owner-authored canonical Issue Task Capsule, and records three independent exact machine-readable list fields before `TASK_READY`: `CI profile floor` (`LOW_FOCUSED` through `RELEASE_CERT`), `Assurance floor` (`LOW`, `MEDIUM`, or `HIGH`), and `Independent review floor` (`OPTIONAL` or `REQUIRED`). It also binds MEDIUM review triggers, scope/invariants/evidence/stop conditions/release boundary. The complete diff may only raise these floors.

Task Capsules reference stable repository contracts by exact path plus blob SHA/version and store only the task-specific delta. Plan Review remains exceptional for an unresolved product or architecture decision.

DIRECT is bootstrapped only by an explicit Codex `Direct` / `Quick` request under `docs/codex-direct-workflow.md`.

## Run

`Run <TASK_ID>` / `Chạy <TASK_ID>` remains one Codex command.

Codex resolves the complete Issue/comments/PR/branch/exact SHAs/CI/governance state, executes implementation and proportional Local evidence, opens or updates one Draft PR, and adversarially self-reviews the complete diff.

### DIRECT

DIRECT follows its pre-edit authority and deterministic merge lifecycle in `docs/codex-direct-workflow.md`; it never reports `READY_TO_FINALIZE`.

### LOW and no-trigger MEDIUM

The ordinary push first proves the machine floor under non-protected `PRECHECK authority only`. Codex dispatches stale-safe task-bound FINAL using the authenticated Task Capsule floor, waits for exact-head `Required CI`, and persists/re-reads structured executor evidence:

`EXECUTOR_EVIDENCE_READY: <TASK_ID> / PR #N / exact head <SHA> / profile <PROFILE> / Required CI <RUN_ID> / artifacts=0 / persisted authority <Issue comment ID>`.

This is not `TECHNICAL_ACCEPTED`. After authenticating it, Codex reports:

`READY_TO_FINALIZE: <TASK_ID> / PR #N / exact head <SHA> / EXECUTOR_EVIDENCE_READY / persisted authority <Issue comment ID>`.

### Review-required MEDIUM and HIGH

Ordinary pushes run unbound discovery PRECHECK only and emit no `Required CI` check. Every review-required candidate then receives a stale-safe task-bound PRECHECK dispatch, even when the Task Capsule does not raise the machine profile. Its exact job identities bind `PRECHECK`, task ID, and selected profile; dispatch resolution separately authenticates the Task Capsule's CI, assurance, and review floors. Only this run may be cited by PRE_REVIEW. After its success, Codex dispatches a fresh source-read-only Independent Reviewer.

The reviewer automatically persists and re-reads one immutable record:

- `PRE_REVIEW_CLEAN`; or
- `PRE_REVIEW_CHANGES_REQUIRED`.

Changes-required returns only the bounded tranche to the Executor. Each head change reruns PRECHECK and complete-diff fresh PRE_REVIEW. Automatic head-changing correction is limited to three cycles; a fourth stops `TECHNICAL_ESCALATION_REQUIRED`.

For unchanged `PRE_REVIEW_CLEAN`, Codex triggers explicit exact-head FINAL certification with the persisted authority ID. FINAL re-resolves PR/head/profile and validates the independent review plus PRECHECK run from base-owned scripts. When FINAL is green and artifacts=0, orchestration persists a mechanical Technical Acceptance binding the Independent Review record:

`TECHNICAL_ACCEPTED: <TASK_ID> / PR #N / exact head <SHA> / PRE_REVIEW_CLEAN <ID> / FINAL <RUN_ID> / profile <PROFILE> / artifacts=0`.

No second source reread is required because the head/tree is unchanged. The Executor cannot supply the independent conclusion being promoted.

Codex re-reads/authenticates that record and reports:

`READY_TO_FINALIZE: <TASK_ID> / PR #N / exact head <SHA> / TECHNICAL_ACCEPTED / persisted authority <Issue comment ID or PR review ID>`.

For the WOS-GOV-009 bootstrap and untransitioned tasks, `Run` retains the source-base FULL then fresh exact-head Independent Technical Review sequence.

If fresh reviewer separation is unavailable, stop `INDEPENDENT_REVIEW_DISPATCH_REQUIRED: <TASK_ID>`. If GitHub persistence/authentication fails, stop `TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`.

## Finalize

`Finalize <TASK_ID>` is a ChatGPT command and conditional Human Gate for the exact current head.

ChatGPT resolves the canonical task, PR/base/head/tree/merge candidate, profile/stage, ruleset, canonical `Required CI`, artifacts, unresolved threads, and the task-appropriate evidence:

- authenticated `EXECUTOR_EVIDENCE_READY` for LOW/no-trigger MEDIUM; or
- authenticated Independent-review-bound `TECHNICAL_ACCEPTED` for reviewed MEDIUM/HIGH and bootstrap tasks.

ChatGPT independently verifies scope/profile/review-trigger correctness, product intent, architecture, contracts, evidence, and governance. Scope drift from a no-review route into a trigger fails Acceptance and cannot merge.

On failure it records `ACCEPTANCE_CHANGES_REQUIRED` or `TECHNICAL_REVIEW_FOLLOWUP_REQUIRED`. On success it records exact-head `ACCEPTANCE_ACCEPTED`, re-resolves unchanged authority, records separate `HUMAN_GATE_APPROVED` because the authenticated human explicitly issued `Finalize`, squash-merges, proves exact-tree equivalence and successful exact-SHA Main attestation with artifacts=0, then records `POST_MERGE_ACCEPTED`.

For WOS-GOV-010 and later normal tasks, squash merge is additionally gated by the native `pull_request: ready_for_review` bridge in `docs/ci-workflow-contract.md`. Keep the PR draft through technical evidence and Acceptance; persist the exact candidate-bound Human Gate with `PR state: draft`, then mark the unchanged PR ready once. The dedicated native merge-ref workflow verifies prior authority in its running `Required CI` job, without Checks API writes or another FINAL. After completion, re-read the successful native run/check/app/PR association, its exact candidate/ref attestation, no-drift authority and live `mergeable=true`/`mergeable_state=clean` before merge. REST run/check `head_sha` identifies the PR head; the native event/runner SHA separately binds the merge candidate. Head FINAL success alone is not merge readiness. LOW/no-trigger MEDIUM uses Executor evidence with no added Independent Review. DIRECT remains separate. The retired custom-check `workflow_dispatch MERGE_AUTHORITY` path must not be retried.

WOS-GOV-010's own positive live bridge proof belongs to this pre-merge Finalize step; `Run` cannot invent Acceptance/Human Gate to obtain it early. A failed bridge stops merge. A missing safe self-bootstrap or unrecognized live candidate authority stops `ARCHITECTURE_REVIEW_REQUIRED: WOS-GOV-010`.

`Finalize` never publishes, tags, deploys, creates a public package, or changes production gates.

## Profile evidence

### DIRECT_FAST

Strict base/head object and lexical direct envelope, exact diff limited to paired numeric `border-radius` declaration edits, classifier/aggregator/workflow regressions, unchanged runtime/gate/version/package/control-plane proof, artifacts=0.

### LOW_FOCUSED

Changed static syntax, focused relevant contracts, exact diff/profile proof, artifacts=0.

### MEDIUM_DOMAIN

Touched-language syntax/unit contracts, affected-domain smoke in HPOS by default, cross-domain sentinels, and extra storage modes when raised by actual semantics.

### HIGH_DEEP

PRECHECK then Independent PRE_REVIEW; FINAL includes PHP/architecture/package evidence, affected recovery/security/concurrency domain across legacy/HPOS/HPOS-sync, real-worker lease exclusion in HPOS, sentinels, artifacts=0.

### HIGH_FINANCIAL

PRECHECK then Independent PRE_REVIEW; FINAL preserves every `HIGH_DEEP` affected runtime/recovery/concurrency suite and adds money/tax/payment/refund/stock specialization across legacy/HPOS/HPOS-sync, real-worker lease exclusion, sentinels, artifacts=0. Classification uses explicit actual financial caller paths plus conservative changed-content guards so generic runtime filenames cannot bypass this profile.

### RELEASE_CERT

Exhaustive source-of-truth certification equivalent to or stronger than the pre-WOS-GOV-009 FULL matrix, including complete manifest union, all storage modes, package/distribution/version authority, artifacts=0.

## Direct transition

After WOS-GOV-009 terminal acceptance, new direct tasks use deterministic no-review DIRECT. The existing CSS safe subset migrates from `DIRECT_CSS_FAST` to `DIRECT_FAST`; any breach routes to normal LOW/MEDIUM/HIGH before widening. Historical reviewed direct tasks remain accepted history.

## Exception routing

- unresolved product/architecture/profile boundary: `PLAN_REVIEW_REQUIRED`, `ARCHITECTURE_REVIEW_REQUIRED`, `PRODUCT_DECISION_REQUIRED`, or `SCOPE_REVIEW_REQUIRED`;
- no fresh reviewer: `INDEPENDENT_REVIEW_DISPATCH_REQUIRED`;
- unauthenticated review provenance: `INDEPENDENT_REVIEW_AUTHORITY_REQUIRED`;
- review not persisted/re-readable: `TECHNICAL_REVIEW_PERSISTENCE_REQUIRED`;
- fourth head-changing correction: `TECHNICAL_ESCALATION_REQUIRED`;
- direct boundary breach: `CODEX_DIRECT_NOT_ELIGIBLE` or `CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED`;
- stale source/head/worktree: `TASK_BRANCH_SYNC_REQUIRED` or `LOCAL_RUNTIME_WORKTREE_SYNC_REQUIRED`;
- release/publication authority remains separate and fail-closed.

## Roadmap and release boundary

This governance task does not create or start `WOS-COMPAT-007`. `WOS-COMPAT-007` is expected to use `RELEASE_CERT` only after WOS-GOV-009 is terminally active and separately created.

`WOS-REL-001` remains open and unfrozen. No Task Ready, CI profile, review, Acceptance, Human Gate, merge, or post-merge acceptance implies release freeze, version, package, tag, GitHub Release, WordPress.org publication, or deployment authority.
