# Engineering Review Authority

## Purpose

This contract separates implementation, technical review, acceptance governance, mechanical CI, and Human Gate authority. A canonical task Issue may add stricter task-specific requirements, but it may not collapse these roles or weaken exact-head, release, publication, or post-merge authority.

The canonical implementation sequence is:

`TASK_READY`
→ Codex Executor
→ `TECHNICAL_REVIEW_REQUIRED`
→ fresh Independent Codex Reviewer
→ `TECHNICAL_ACCEPTED`
→ ChatGPT Acceptance Review
→ `ACCEPTANCE_ACCEPTED`
→ explicit Human Gate
→ merge
→ exact-tree Main attestation and post-merge governance acceptance.

Any changes-required outcome returns only the authenticated, bounded correction tranche to the appropriate executor. A changed exact head invalidates every earlier head-bound Technical and Acceptance outcome for merge.

## Role boundaries

### Codex Executor

The executor owns repository discovery, file/class-level implementation planning, code/docs/tests changes, local or disposable runtime verification, complete-diff self-review, branch/PR preparation, and exact-head evidence.

The executor must not self-issue `TECHNICAL_ACCEPTED`. Its terminal readiness signal is:

`TECHNICAL_REVIEW_REQUIRED: <TASK_ID> <task-specific readiness statement>.`

### Independent Codex Reviewer

The Independent Codex Reviewer is authoritative for technical and code correctness. It must use a fresh Codex conversation/agent that does not continue the executor conversation. GitHub Codex Code Review with distinct Codex provenance is preferred when configured; an isolated Codex review worktree or cloud environment is an acceptable fallback.

The reviewer must:

- resolve the canonical Issue, PR, exact base/head, complete diff, surrounding code, dependencies, CI, and applicable accepted contracts;
- default to read-only repository behavior;
- avoid commits, pushes, implementation fixes, or any modification of the PR head;
- use only uncommitted, disposable files or state for reproduction probes;
- ground blocking findings in concrete code paths or invariant violations and, where reasonably possible, reproduction or test evidence;
- report unvalidated hypotheses as non-blocking uncertainty rather than correction authority;
- review the complete PR after every head-changing correction.

The authoritative outcomes are:

- `TECHNICAL_ACCEPTED: <TASK_ID> / PR #N / exact head <SHA> ...`
- `TECHNICAL_CHANGES_REQUIRED: <TASK_ID> / PR #N / exact head <SHA> ...`

The review record must bind the `independent_codex_reviewer` role, fresh-context attestation, explicit statement that the executor session was not reused, read-only/no-implementation-write attestation, exact PR base/head, exact tested merge-candidate/tree where available, canonical CI state, findings, and reproduction/test evidence.

Distinct GitHub Codex review provenance is the strongest preferred authority. If a fresh Codex app/CLI/cloud review is posted through the repository-owner account, the structured attestation is mandatory; actor identity alone does not establish reviewer independence. If independence or provenance cannot be established, stop with `INDEPENDENT_REVIEW_AUTHORITY_REQUIRED`.

### ChatGPT Acceptance Reviewer

ChatGPT owns product intent, external research, architecture and domain boundaries, canonical task contracts, plan review where required, contract/governance acceptance, Human-Gate coordination, and post-merge governance acceptance. It does not own authoritative technical/code-correctness review.

After exact-head `TECHNICAL_ACCEPTED`, `Acceptance Review <TASK_ID>` verifies task-contract satisfaction, product/domain semantics, architecture alignment, changed-file scope, required evidence, independent review provenance, exact-head/tree CI authority, production gate expectations, security/privacy/release/package boundaries, and unresolved architecture or product decisions.

The authoritative outcomes are:

- `ACCEPTANCE_ACCEPTED: <TASK_ID> / PR #N / exact head <SHA> ...`
- `ACCEPTANCE_CHANGES_REQUIRED: <TASK_ID> / PR #N / exact head <SHA> ...`

The acceptance record must bind the `chatgpt_acceptance_reviewer` role, exact PR head, the authoritative Independent Codex review record for that head, canonical CI state, scope/architecture/governance findings, and the explicit acceptance outcome. When posted through the repository-owner account, the direct structured attestation supplies role provenance; actor identity or copied ChatGPT text alone is insufficient.

`ACCEPTANCE_CHANGES_REQUIRED` is limited to contract, scope, architecture, product, evidence, or governance defects. If ChatGPT identifies a plausible technical defect, it routes the bounded hypothesis back to an Independent Codex Reviewer as:

`TECHNICAL_REVIEW_FOLLOWUP_REQUIRED: <TASK_ID> / exact head <SHA> / <bounded hypothesis>.`

ChatGPT must not promote that hypothesis into authoritative technical correction work. A fresh or continuing independent reviewer must validate or dismiss it and update the technical outcome.

### GitHub Actions and Human

GitHub Actions supplies deterministic exact-head merge-authority CI. Green CI is required evidence, not product, technical-review, acceptance, Human-Gate, release, or publication authority.

The authenticated human owns product decisions and explicit Human Gate, merge, release, and publication authority. Merge requires exact-head `TECHNICAL_ACCEPTED` and `ACCEPTANCE_ACCEPTED`, both still valid for the unchanged PR head, followed by a separate Human Gate from the repository owner or task-designated human approver. An implementation Human Gate never implies release or publication approval.

## Corrections and drift

- `Sửa/Fix <TASK_ID>` may apply only the latest authenticated technical or acceptance correction tranche authorized by the canonical task.
- Any PR-head change invalidates prior exact-head Technical and Acceptance outcomes for merge. A new complete Independent Codex Technical Review must bind the changed head before ChatGPT Acceptance Review can be issued again; test reruns remain proportional to the change plus the canonical task's required evidence.
- If base/head, merge candidate, CI, reviewer provenance, or authority cannot be resolved, fail closed using the task-defined stop signal.

## Historical and active-task transition

Completed milestones remain accepted and are not reopened solely because review roles changed. Historical Issue, PR, and review comments remain immutable evidence; their wording must not be rewritten to impersonate this workflow. Active tasks transition only through an explicit canonical task comment, and an existing Technical Acceptance may be grandfathered only when that comment says so.

`WOS-GOV-004` uses its one-time owner-approved bootstrap sequence: Codex Executor → fresh Independent Codex Technical Review → ChatGPT Acceptance Review → explicit Human Gate → exact-tree post-merge acceptance.

While `WOS-GOV-004` is active, `WOS-RETURN-004` / Issue #75 / PR #76 is paused. Its implementation must not change under the governance task. After `WOS-GOV-004` is post-merge accepted, PR #76 receives a fresh, zero-assumption Independent Codex review of its complete then-current head. Earlier ChatGPT code-review findings, including the completed-terminal-corruption finding recorded in review `5016242245`, are hypotheses only unless that independent reviewer validates them. `WOS-RETURN-005` must not begin until `WOS-RETURN-004` completes the new Technical Review → Acceptance Review → Human Gate → post-merge sequence.

## Release and post-merge boundary

Technical Acceptance, Acceptance Review, Human Gate, and successful CI do not grant release, publication, deployment, or package authority. Those remain separately governed by the canonical release task and `docs/ci-workflow-contract.md`.

Post-merge acceptance must bind the successful PR CI merge candidate/tree to the resulting `main` commit/tree and a successful exact-SHA Main attestation. Similar file lists, commit messages, or an unbound green run are insufficient.
