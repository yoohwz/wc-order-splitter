# Engineering Review Authority

## Purpose and activation

This contract separates implementation, deterministic CI, Independent Codex Review where required, ChatGPT Acceptance, Human Gate, merge, and post-merge authority. Risk tiering changes which changes require an Independent Reviewer and when expensive certification runs; it never collapses roles or weakens exact-head, branch-protection, Main-attestation, release, or publication authority.

The WOS-GOV-009 rules are prospective only after canonical `POST_MERGE_ACCEPTED: WOS-GOV-009`. WOS-GOV-009 itself uses the previous source-base FULL + fresh Independent Technical Review + ChatGPT Finalize workflow. Pre-existing active tasks retain their own authority unless explicitly transitioned.

## Assurance profiles

### DIRECT

DIRECT is deterministic, mechanically proven non-semantic scope under `docs/codex-direct-workflow.md`. It omits ChatGPT Create, ChatGPT Acceptance, and Independent Codex Review. It still requires pre-edit authenticated `DIRECT_HUMAN_AUTHORIZED`, protected exact-head `Required CI`, derived `HUMAN_GATE_APPROVED_DIRECT`, squash merge, Main attestation, and `POST_MERGE_ACCEPTED_DIRECT`.

No DIRECT Executor evidence is `TECHNICAL_ACCEPTED`. Any ambiguity or semantic expansion leaves DIRECT before merge.

### LOW

LOW uses Codex Executor adversarial self-review plus persisted exact-head `EXECUTOR_EVIDENCE_READY`; fresh Independent Codex Review is not mandatory by default. ChatGPT Acceptance, Human Gate, protected `Required CI`, exact-tree merge proof, Main attestation, and post-merge acceptance remain mandatory.

Executor evidence is not Technical Acceptance and must never be presented as independent review.

### MEDIUM

MEDIUM is semantic-triggered. ChatGPT Create binds separate machine-readable `Assurance floor` and `Independent review floor` Task Capsule fields, while the complete machine-classified diff can only escalate either one. AJAX/REST/client-server mutation authority, nonce/capability/security, persistence/meta/customer/order writes, status/eligibility, operational compatibility adapters, webhook/email/outbound effects, replay/recovery/state-machine authority, changed expected test behavior, CI/workflow/normative governance, or package/release control makes Independent Review mandatory. Ambiguity selects review.

A no-trigger MEDIUM candidate may follow LOW assurance with stronger domain CI. A triggered MEDIUM candidate follows the review-first lifecycle below.

### HIGH

HIGH is mandatory for mutation, money/tax/stock/refund/payment, recovery/idempotency/concurrency, security/privacy, destructive persistence/migration, production gates, release/package/publication integrity, and governance changing review/merge authority. HIGH always requires fresh Independent Codex Review and ChatGPT Acceptance.

## Role boundaries

### Codex Executor

The Executor owns discovery, implementation, tests, focused Local evidence, branch/PR preparation, exact-head CI orchestration, and complete-diff adversarial self-review. It may persist:

- `EXECUTOR_EVIDENCE_READY` for LOW/no-trigger MEDIUM; or
- readiness for fresh `PRE_REVIEW` on review-required MEDIUM/HIGH.

The Executor must not invent `PRE_REVIEW_CLEAN`, validate its own source review, or issue a new technical conclusion. Automatic corrections remain bounded to three head-changing cycles.

After a separately persisted exact-head `PRE_REVIEW_CLEAN` and unchanged green FINAL certification, the Codex orchestration may mechanically persist `TECHNICAL_ACCEPTED` only by binding the exact independent authority ID, unchanged head/tree, FINAL run/profile, artifacts=0, and no drift. This is promotion of an existing Independent Reviewer conclusion after mechanical evidence, not Executor self-review. Missing or unauthenticated independent authority stops `TECHNICAL_REVIEW_PERSISTENCE_REQUIRED`.

### Independent Codex Reviewer

The reviewer must run in a fresh conversation/agent/worktree/cloud context that does not reuse the Executor session. It is source read-only/no-implementation-write; governance metadata persistence is allowed. It resolves the complete exact base/head/tree/diff, Task Capsule and stable contract blobs, task-bound PRECHECK evidence, CI/assurance/review floors, review triggers, relevant runtime/tests, and prior correction delta.

For review-first candidates it persists one immutable structured GitHub record per exact head:

- `PRE_REVIEW_CLEAN: <TASK_ID> / PR #N / exact head <SHA>`; or
- `PRE_REVIEW_CHANGES_REQUIRED: <TASK_ID> / PR #N / exact head <SHA>`.

The record must begin with the canonical `## Independent Codex PRE_REVIEW — <TASK_ID>` header and end with exactly one canonical outcome. It must include exactly one `Role: independent_codex_reviewer`, `Canonical Issue: #N`, fresh-context and executor-session-not-reused attestations, source read-only/no-implementation-write, complete-diff and PRECHECK-evidence-reviewed attestations, exact base/head/tree, exact `PRECHECK profile: <PROFILE> / stage PRECHECK`, task-bound PRECHECK run completed/success/artifacts=0, blocking findings, and reproduction evidence. The cited run must be `workflow_dispatch` and expose exactly one successful `Risk-tiered PRECHECK / <TASK_ID> / <PROFILE>` plus `PRECHECK authority only / <TASK_ID> / <PROFILE>`, with no `Required CI`. Conflicting, duplicate, indented, quoted, backtick/tilde-fenced, or HTML-wrapped authority fields are invalid. A PR review record must bind GitHub's immutable `commit_id` to the exact reviewed head; any head change invalidates it.

After clean review, FINAL CI is mechanical. A second complete source reread is unnecessary when the head/tree is unchanged. FINAL failure requiring source change returns to new PRECHECK plus complete fresh PRE_REVIEW. A failure caused only by transient infrastructure may be rerun only after exact authority is revalidated.

For WOS-GOV-009 bootstrap and untransitioned legacy tasks, the reviewer instead persists the pre-existing full exact-head `TECHNICAL_ACCEPTED` or `TECHNICAL_CHANGES_REQUIRED` structure after current FULL CI.

Every review cycle must be automatically persisted to a PR review plus Issue reference, or a top-level canonical Issue comment posted by the fresh reviewer under owner-structured provenance. Chat/session-only text is evidence only. Failure to persist/re-read/authenticate stops:

`TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>`.

### ChatGPT Acceptance Reviewer

ChatGPT owns product intent, architecture, task contract, profile/trigger acceptance, evidence sufficiency, governance, Human-Gate orchestration, and post-merge acceptance. It does not substitute for technical review when the assurance profile requires one.

For LOW/no-trigger MEDIUM, ChatGPT Finalize authenticates persisted `EXECUTOR_EVIDENCE_READY`, exact-head `Required CI`, profile/trigger classification, and scope before Acceptance. No `TECHNICAL_ACCEPTED` is fabricated.

For reviewed MEDIUM/HIGH, ChatGPT authenticates exact-head `TECHNICAL_ACCEPTED` and its bound `PRE_REVIEW_CLEAN`/FINAL evidence. Conversation-only or copied/reposted tokens are rejected.

Outcomes are `ACCEPTANCE_ACCEPTED`, `ACCEPTANCE_CHANGES_REQUIRED`, or a bounded `TECHNICAL_REVIEW_FOLLOWUP_REQUIRED` hypothesis. ChatGPT cannot promote its own technical hypothesis into correction authority.

### Human and GitHub Actions

GitHub Actions supplies deterministic exact-head evidence and the protected `Required CI` context. It is not review, Acceptance, Human Gate, release, or publication authority.

The authenticated repository owner or task-designated human owns Human Gate, release, and publication authority. `Finalize <TASK_ID>` is conditional Human Gate only after ChatGPT Acceptance succeeds and exact head/base/CI/review authority remains unchanged. A separate `HUMAN_GATE_APPROVED` record precedes squash merge. Successful merge still requires exact-tree Main attestation and `POST_MERGE_ACCEPTED`.

## Review-first lifecycle

For review-required MEDIUM/HIGH:

`Executor -> PRECHECK -> fresh PRE_REVIEW -> correction if required -> PRE_REVIEW_CLEAN -> exact-head FINAL -> mechanically bound TECHNICAL_ACCEPTED -> ChatGPT Finalize`.

PRECHECK must not make `Required CI` green. FINAL is explicit and stale-safe: it re-resolves the PR and exact expected head, reruns the base-owned classifier, authenticates the immutable Independent Review record and bound successful PRECHECK run, and only then runs the selected final profile.

The complete final Technical Acceptance record must bind task, PR, independent reviewer authority ID, exact base/head/tree, final CI run/profile, artifacts=0, findings outcome, and no-drift attestation. The orchestrating surface re-reads and authenticates the record before reporting `READY_TO_FINALIZE`.

WOS-GOV-010 and later normal tasks use the versioned merge CI evidence, Acceptance and Human Gate records in `docs/ci-workflow-contract.md`. The CI evidence remains Executor evidence or mechanical promotion only; Acceptance remains ChatGPT-owned, and Human Gate remains conditional human authority. After those distinct checkpoints, GitHub Actions may materialize `Required CI` on the current merge candidate through the metadata-only `MERGE_AUTHORITY` stage. No successful head FINAL, dispatch input, title/label/PR-body claim, or Executor record substitutes for these upstream roles. Finalize must re-read both successful FINAL and successful completed bridge/candidate authority before merge. The source-bound GOV-010 self-bootstrap has the same role boundaries; positive live proof is obtained only after its Acceptance/Human Gate and before its merge.

## Corrections, drift, and routing

- A head-changing correction invalidates PRE_REVIEW, FINAL, Technical Acceptance, and Acceptance for the old head.
- Automatic technical correction is limited to three head-changing cycles; a fourth stops `TECHNICAL_ESCALATION_REQUIRED`.
- LOW/no-trigger MEDIUM scope drift into a trigger stops Finalize until reclassified and independently reviewed.
- DIRECT drift stops before widening under `CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED`.
- Missing reviewer separation stops `INDEPENDENT_REVIEW_DISPATCH_REQUIRED` or `INDEPENDENT_REVIEW_AUTHORITY_REQUIRED`.
- Missing persisted review authority stops `TECHNICAL_REVIEW_PERSISTENCE_REQUIRED`; it never routes directly to Acceptance.
- Exact-head FINAL cannot be triggered by a stale review or stale expected SHA.

## Task Capsule and reviewer packet

New normal Issues should store a compact Task Capsule: exact source main/tree/attestation, dependency authority IDs, machine-readable `CI profile floor`, `Assurance floor`, and `Independent review floor`, product/behavior delta, changed invariants, acceptance delta, stop conditions, and release boundary. Stable repository contracts are referenced by path plus blob SHA/version rather than copied.

Reviewer packets contain exact base/head/tree/merge candidate, changed paths/domains, Task Capsule, stable contract blobs, PRECHECK run, prior findings and correction delta, and selected CI profile/stage/test delta.

Cache stable content, never a technical conclusion. Do not create a second governance database or external conclusion store.

## Merge, Main, and release boundary

Normal merge requires the task-appropriate exact-head evidence (`EXECUTOR_EVIDENCE_READY` for no-review LOW/MEDIUM or `TECHNICAL_ACCEPTED` for reviewed work), ChatGPT `ACCEPTANCE_ACCEPTED`, explicit `HUMAN_GATE_APPROVED`, strict `Required CI`, resolved conversations, and unchanged authority.

Post-merge acceptance binds tested merge-candidate/tree to resulting `main` and successful exact-SHA Main attestation with artifacts=0. Similar file lists or an unbound green run are insufficient.

No implementation profile, review, Acceptance, Human Gate, merge, or post-merge record grants tag, package, GitHub Release, WordPress.org publication, deployment, or production-gate authority. Those remain separately governed by `WOS-REL-001` and `docs/ci-workflow-contract.md`.
