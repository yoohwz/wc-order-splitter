# Engineering Review Authority

## Purpose

This contract separates implementation, technical review, acceptance governance, mechanical CI, and Human Gate authority. A canonical task Issue may add stricter task-specific requirements, but it may not collapse these roles or weaken exact-head, release, publication, or post-merge authority. The sole scoped exception to ChatGPT Acceptance is `TRIVIAL / CODEX_DIRECT` after canonical `POST_MERGE_ACCEPTED` for `WOS-GOV-007`, governed by `docs/codex-direct-workflow.md`.

After canonical `POST_MERGE_ACCEPTED` for WOS-GOV-005, the preferred operator lifecycle is `ChatGPT Create -> Codex Run -> ChatGPT Finalize`, governed by `docs/compressed-engineering-workflow.md`. The canonical recorded authority sequence remains:

`TASK_READY`
→ Codex Executor
→ `TECHNICAL_REVIEW_REQUIRED`
→ fresh Independent Codex Reviewer
→ persisted GitHub `TECHNICAL_ACCEPTED`
→ ChatGPT Acceptance Review
→ `ACCEPTANCE_ACCEPTED`
→ explicit conditional Human Gate
→ merge
→ exact-tree Main attestation and post-merge governance acceptance.

Compression is orchestration, not authority collapse. `Run` may dispatch the Executor and a fresh Independent Codex Reviewer and may route authenticated correction tranches between them, but those roles remain separate. `Finalize` may coordinate Acceptance, Human Gate, merge, and post-merge verification after one explicit human command, but it must record each authority separately and in order.

An eligible direct task instead records `DIRECT_HUMAN_AUTHORIZED` before source edits, retains separate Executor and fresh Independent Reviewer roles, requires exact-head `TECHNICAL_ACCEPTED` with explicit direct-eligibility attestation, records `HUMAN_GATE_APPROVED_DIRECT` only after unchanged-authority revalidation, and proves `POST_MERGE_ACCEPTED_DIRECT`. It does not require ChatGPT Acceptance or `Finalize`. Any scope ambiguity or drift leaves this exception and fails closed.

Any changes-required outcome returns only the authenticated, bounded correction tranche to the appropriate executor. A changed exact head invalidates every earlier head-bound Technical and Acceptance outcome for merge.

## Role boundaries

### Codex Executor

The executor owns repository discovery, file/class-level implementation planning, code/docs/tests changes, local or disposable runtime verification, complete-diff self-review, branch/PR preparation, and exact-head evidence.

The executor must not self-issue `TECHNICAL_ACCEPTED`. Its terminal readiness signal is:

`TECHNICAL_REVIEW_REQUIRED: <TASK_ID> <task-specific readiness statement>.`

When the compressed workflow is active and the current Codex surface can establish independent reviewer provenance, the Executor readiness signal may remain internal to the `Run` orchestration while a fresh Independent Codex Reviewer is dispatched. If that provenance cannot be established, Codex stops `INDEPENDENT_REVIEW_DISPATCH_REQUIRED: <TASK_ID>` and requires a manually opened fresh `Technical Review <TASK_ID>` context. Automatic technical correction is limited to three head-changing cycles before `TECHNICAL_ESCALATION_REQUIRED: <TASK_ID>`.

For `TRIVIAL / CODEX_DIRECT`, the executor additionally owns the pre-edit bootstrap and post-review orchestration in `docs/codex-direct-workflow.md`, but only while the direct record is authenticated and the complete diff remains eligible. Applying the human's persisted conditional authority does not make the executor the Human Gate. The executor cannot create `DIRECT_HUMAN_AUTHORIZED` after source edits, cannot self-review, and cannot expand an ineligible diff.

### Independent Codex Reviewer

The Independent Codex Reviewer is authoritative for technical and code correctness. It must use a fresh Codex conversation/agent that does not continue the executor conversation. GitHub Codex Code Review with distinct Codex provenance is preferred when configured; an isolated Codex review worktree or cloud environment is an acceptable fallback.

The reviewer must:

- resolve the canonical Issue, PR, exact base/head, complete diff, surrounding code, dependencies, CI, and applicable accepted contracts;
- default to read-only repository behavior;
- avoid commits, pushes, implementation fixes, or any modification of the PR head;
- use only uncommitted, disposable files or state for reproduction probes;
- ground blocking findings in concrete code paths or invariant violations and, where reasonably possible, reproduction or test evidence;
- report unvalidated hypotheses as non-blocking uncertainty rather than correction authority;
- review the complete PR after every head-changing correction;
- automatically persist the complete structured review to canonical GitHub authority and re-read/authenticate that new record before the cycle completes.

The authoritative persisted outcomes are:

- `TECHNICAL_ACCEPTED: <TASK_ID> / PR #N / exact head <SHA> ...`
- `TECHNICAL_CHANGES_REQUIRED: <TASK_ID> / PR #N / exact head <SHA> ...`

The review record must bind the task ID, PR number, `independent_codex_reviewer` role, fresh-context attestation, explicit statement that the executor session was not reused, source read-only/no-implementation-write attestation, exact PR base/head, exact tested merge-candidate/tree where available, canonical CI state, findings, reproduction/test evidence, and exact terminal signal.

For `TRIVIAL / CODEX_DIRECT`, the record must also bind the pre-edit `DIRECT_HUMAN_AUTHORIZED` authority ID and explicitly attest that every changed path and semantic effect remains inside the direct allowlist. The exact terminal signal includes `TRIVIAL / CODEX_DIRECT` and `direct eligibility confirmed`. Without that scope attestation, Technical Acceptance cannot authorize direct merge.

Distinct GitHub Codex review provenance is the strongest preferred authority. If a fresh Codex app/CLI/cloud review is posted through the repository-owner account, the structured attestation is mandatory; actor identity alone does not establish reviewer independence. If independence or provenance cannot be established, stop with `INDEPENDENT_REVIEW_AUTHORITY_REQUIRED`.

A chat/session-only `TECHNICAL_ACCEPTED` or `TECHNICAL_CHANGES_REQUIRED` is evidence only and supplies no correction, Acceptance, Finalize, or merge authority. Before a review cycle is complete, the reviewer/orchestrating Codex surface must automatically persist it using either:

1. a real GitHub PR review containing the complete structured record plus a concise canonical task Issue comment that references the PR review ID/URL, exact head, outcome, and reviewer provenance; or
2. a new top-level canonical task Issue comment containing the complete structured record when a fresh Codex app/CLI/cloud context posts through the repository-owner account.

Every head-changing correction/re-review cycle creates a new immutable GitHub review/comment record. Earlier records must not be edited or replaced; a later record supersedes them only for its own exact head.

The reviewer/orchestrating surface must re-read GitHub and authenticate the persisted record before routing its outcome. If the write or authentication fails, stop `TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <TASK_ID> / exact head <SHA>` and do not route to Acceptance or `Finalize`.

Writing review/comment governance metadata does not violate source read-only/no-implementation-write review behavior. The reviewer still must not edit source, commit, push, fix, or modify the PR head.

### ChatGPT Acceptance Reviewer

ChatGPT owns product intent, external research, architecture and domain boundaries, canonical task contracts, plan review where required, contract/governance acceptance, Human-Gate coordination, and post-merge governance acceptance. It does not own authoritative technical/code-correctness review.

After persisted GitHub exact-head `TECHNICAL_ACCEPTED`, `Acceptance Review <TASK_ID>` verifies task-contract satisfaction, product/domain semantics, architecture alignment, changed-file scope, required evidence, independent review provenance, exact-head/tree CI authority, production gate expectations, security/privacy/release/package boundaries, and unresolved architecture or product decisions. Conversation/session-only review output must be rejected.

The authoritative outcomes are:

- `ACCEPTANCE_ACCEPTED: <TASK_ID> / PR #N / exact head <SHA> ...`
- `ACCEPTANCE_CHANGES_REQUIRED: <TASK_ID> / PR #N / exact head <SHA> ...`

The acceptance record must bind the `chatgpt_acceptance_reviewer` role, exact PR head, the authoritative Independent Codex review record for that head, canonical CI state, scope/architecture/governance findings, and the explicit acceptance outcome. When posted through the repository-owner account, the direct structured attestation supplies role provenance; actor identity or copied ChatGPT text alone is insufficient.

`ACCEPTANCE_CHANGES_REQUIRED` is limited to contract, scope, architecture, product, evidence, or governance defects. If ChatGPT identifies a plausible technical defect, it routes the bounded hypothesis back to an Independent Codex Reviewer as:

`TECHNICAL_REVIEW_FOLLOWUP_REQUIRED: <TASK_ID> / exact head <SHA> / <bounded hypothesis>.`

ChatGPT must not promote that hypothesis into authoritative technical correction work. A fresh or continuing independent reviewer must validate or dismiss it and update the technical outcome.

ChatGPT Acceptance is mandatory for every `LOW`, `MEDIUM`, and `HIGH` task. It is omitted only for an unchanged, successfully authenticated `TRIVIAL / CODEX_DIRECT` task whose Independent Reviewer explicitly confirms the complete direct eligibility envelope. Any product, architecture, runtime, state, public API, security, or release ambiguity invalidates that exception and requires normal governance.

### Finalize orchestration

`Finalize <TASK_ID>` is an explicit human command and conditional Human Gate for the exact currently technically accepted PR head. ChatGPT must independently resolve the persisted GitHub review/comment authority ID and authenticate exact-head `TECHNICAL_ACCEPTED`; session output or a copied/reposted token is insufficient. It then performs Acceptance Review and records `ACCEPTANCE_ACCEPTED` before the conditional Human Gate can take effect. It must re-resolve head, base, CI, merge candidate/tree, ruleset, and unresolved threads. Only an unchanged, fully authorized state may receive a separate `HUMAN_GATE_APPROVED` record and merge.

Failed Acceptance records only `ACCEPTANCE_CHANGES_REQUIRED` or `TECHNICAL_REVIEW_FOLLOWUP_REQUIRED` and must not merge. Any head/base/authority drift invalidates the conditional Human Gate. Successful merge still requires exact-tree Main attestation and a distinct `POST_MERGE_ACCEPTED` record. `Finalize` never grants release, publication, deployment, or public-package authority.

### GitHub Actions and Human

GitHub Actions supplies deterministic exact-head merge-authority CI. Green CI is required evidence, not product, technical-review, acceptance, Human-Gate, release, or publication authority.

The authenticated human owns product decisions and explicit Human Gate, merge, release, and publication authority. Normal merge requires persisted GitHub exact-head `TECHNICAL_ACCEPTED` and `ACCEPTANCE_ACCEPTED`, both still valid for the unchanged PR head, followed by a distinct Human Gate record from the repository owner or task-designated human approver. For an eligible compressed-flow task, issuing `Finalize` supplies that explicit Human Gate conditionally and only after ChatGPT Acceptance succeeds and authority is revalidated.

For an eligible direct task, the authenticated owner's or designated human's explicit `Direct` / `Quick` request supplies only conditional bounded authority after it is persisted as `DIRECT_HUMAN_AUTHORIZED` before source edits. Codex may derive and persist `HUMAN_GATE_APPROVED_DIRECT` only after exact-head CI and independently persisted Technical Acceptance explicitly confirm unchanged direct eligibility. The Executor is applying the earlier human authority, not self-authorizing. An implementation Human Gate never implies release or publication approval.

Risk profiles control the depth of task planning, evidence, and Independent Review. No profile can waive protected-branch `Required CI`, exact-head Technical Acceptance, explicit Human Gate semantics, post-merge proof, or release/publication boundaries. `LOW`, `MEDIUM`, and `HIGH` cannot waive ChatGPT Acceptance. `TRIVIAL / CODEX_DIRECT` is the only exception and omits it solely under `docs/codex-direct-workflow.md`.

## Corrections and drift

- `Sửa/Fix <TASK_ID>` may apply only the latest authenticated technical or acceptance correction tranche authorized by the canonical task.
- Any PR-head change invalidates prior exact-head Technical and Acceptance outcomes for merge. A new complete Independent Codex Technical Review must bind the changed head before ChatGPT Acceptance Review can be issued again; test reruns remain proportional to the change plus the canonical task's required evidence.
- Any direct-task head change invalidates its prior Technical Acceptance. A new complete Independent Review must reconfirm direct eligibility; any out-of-envelope finding stops before widening with `CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED`.
- If base/head, merge candidate, CI, reviewer provenance, or authority cannot be resolved, fail closed using the task-defined stop signal.

## Historical and active-task transition

Completed milestones remain accepted and are not reopened solely because review roles changed. Historical Issue, PR, and review comments remain immutable evidence; their wording must not be rewritten to impersonate this workflow. Active tasks transition only through an explicit canonical task comment, and an existing Technical Acceptance may be grandfathered only when that comment says so.

`WOS-GOV-004` used its one-time owner-approved bootstrap sequence: Codex Executor → fresh Independent Codex Technical Review → ChatGPT Acceptance Review → explicit Human Gate → exact-tree post-merge acceptance. That historical completion remains accepted.

`WOS-GOV-005` also uses the pre-existing WOS-GOV-004 sequence for its own bootstrap. The compressed lifecycle becomes active only after WOS-GOV-005 receives canonical `POST_MERGE_ACCEPTED`. Tasks created earlier may opt into compressed `Finalize` only through an explicit canonical transition comment; historical records are not rewritten.

`WOS-GOV-007` itself uses the normal WOS-GOV-005 `Create -> Run -> Finalize` sequence. `TRIVIAL / CODEX_DIRECT` becomes active only after WOS-GOV-007 receives canonical `POST_MERGE_ACCEPTED`; it cannot be used to accept its own governance change.

After that transition, the forward single-Return roadmap is `WOS-RETURN-006 — Return UI + Sandbox Readiness` followed by the separate HIGH-risk `WOS-RETURN-007 — Return Production Enablement`. Bulk Return remains audit-first and milestone-minimal as defined in `docs/compressed-engineering-workflow.md`.

## Release and post-merge boundary

Technical Acceptance, Acceptance Review where applicable, Human Gate, and successful CI do not grant release, publication, deployment, or package authority. Direct-mode acceptance has the same release boundary. Those actions remain separately governed by the canonical release task and `docs/ci-workflow-contract.md`.

Post-merge acceptance must bind the successful PR CI merge candidate/tree to the resulting `main` commit/tree and a successful exact-SHA Main attestation. Similar file lists, commit messages, or an unbound green run are insufficient.
