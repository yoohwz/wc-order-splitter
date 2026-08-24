# Codex Short Command Protocol

## Purpose

Reduce operator prompts to short, stable commands without moving task scope, safety rules, acceptance criteria, or merge authority into chat text.

A short command is a **task selector**, never the task contract.

The canonical task contract remains on GitHub and must be resolved before Codex plans, edits, reviews, verifies, or resumes work.

## Supported operator commands

The protocol is language-tolerant. Vietnamese and English verbs below are equivalent.

### Start / execute

- `Chạy <TASK_ID>`
- `Run <TASK_ID>`
- `Thực hiện <TASK_ID>`
- `Execute <TASK_ID>`

Meaning: resolve the canonical task contract and execute from its current authorized starting state.

### Resume

- `Tiếp tục <TASK_ID>`
- `Continue <TASK_ID>`
- `Resume <TASK_ID>`

Meaning: recover the task's current GitHub/local state and continue from the latest valid checkpoint. Never restart the task by default.

### Self-review / prepare for independent review

- `Review <TASK_ID>`
- `Rà soát <TASK_ID>`

Meaning: perform the executor-side complete-diff/evidence review required by the task contract and prepare the task for independent ChatGPT Technical Review. This command never substitutes for independent ChatGPT acceptance.

### Verify

- `Verify <TASK_ID>`
- `Xác minh <TASK_ID>`
- `Kiểm tra <TASK_ID>`

Meaning: perform read-only verification required by the current task state, such as exact-head CI, post-merge CI, package artifact, or source-authority verification. Do not edit or rerun unless the task contract explicitly authorizes it.

### Status

- `Status <TASK_ID>`
- `Trạng thái <TASK_ID>`

Meaning: read-only recovery of issue/PR/branch/CI/current checkpoint and report what is complete, what is pending, and the next authorized action. Do not modify repository or GitHub state.

### Correct after review

- `Sửa <TASK_ID>`
- `Fix <TASK_ID>`
- `Correct <TASK_ID>`

Meaning: resolve the latest explicit review findings for the task and apply only the authorized correction tranche. If there is no unambiguous changes-required review, stop with `REVIEW_FINDINGS_REQUIRED`.

## Task ID

A task ID is a stable identifier present in the canonical GitHub Issue title/body, for example:

- `WOS-MERGE-009`
- `WOS-SPLIT-012`
- `WOS-GOV-001`

Do not infer a task from a vague noun when no stable task ID or unique issue/PR reference is supplied.

A direct GitHub issue/PR number may also be used when unambiguous, for example:

- `Review PR #50`
- `Status Issue #51`

If a numeric reference is ambiguous, require explicit `Issue #N` or `PR #N`.

## Mandatory resolution algorithm

Before doing substantive work for any short command:

1. Identify the repository from the active Git worktree and verify its `origin` remote.
2. Fetch remote refs without modifying local work.
3. Resolve `<TASK_ID>` against GitHub Issues in the active repository.
4. Require one canonical task issue. Prefer an exact title/body task-ID match; do not choose between multiple plausible issues by guesswork.
5. Read the full Issue body.
6. Read all task Issue comments, preserving chronological order.
7. Discover the associated PR/branch from the task contract, issue comments, PR search, or exact branch naming recorded by the task.
8. If a PR exists, read the PR body, exact base/head SHA, state, review threads/comments, and current CI/check state relevant to the task.
9. Resolve the latest explicit governance checkpoint/signals, including where applicable:
   - `PLAN_APPROVED`
   - `TECHNICAL_REVIEW_REQUIRED`
   - `TECHNICAL_ACCEPTED`
   - `TECHNICAL_CHANGES_REQUIRED`
   - `READY_FOR_HUMAN_GATE`
   - `HUMAN_GATE_APPROVED`
   - `POST_MERGE_*`
10. Compare the resolved GitHub authority with the local branch/HEAD/worktree state before editing.
11. Only then execute the semantic action selected by the short command.

If GitHub cannot be queried, do not reconstruct a task contract from memory or old local notes. Stop with:

`TASK_CONTRACT_UNAVAILABLE`

If task resolution is ambiguous, stop with:

`TASK_RESOLUTION_REQUIRED`

## Contract precedence

When instructions differ, apply this precedence:

1. Repository safety/governance instructions in `AGENTS.md` and binding architecture contracts.
2. Canonical task Issue body.
3. Later explicit independent-review or Human-Gate comments that intentionally change the task's checkpoint or correction requirements.
4. Associated PR body and executor evidence, where consistent with higher authority.
5. The short operator command.

The short command may select an action, but it may not widen scope, waive tests, change architecture, authorize merge/release, or override a stop condition.

## Resume semantics

`Continue/Resume` is deliberately stateful.

Codex must first determine where the task stopped and continue from there. Examples:

- Issue exists but branch does not: create/switch only as authorized by the task contract.
- Branch exists with work but no PR: inspect current diff/tests and continue implementation.
- Draft PR exists with failing CI: inspect failures and follow the task's failure boundary.
- Task is `TECHNICAL_REVIEW_REQUIRED`: do not keep coding; report that independent review is the next gate unless the operator explicitly issued `Review` for executor self-review.
- Task has `TECHNICAL_CHANGES_REQUIRED`: resume only the authorized correction tranche.
- Task has `TECHNICAL_ACCEPTED` but no Human Gate: do not merge.
- Task has Human Gate bound to an exact head: verify head has not drifted before any merge action.
- Task was merged but requires post-merge verification: `Continue` means perform that verification, not start the next milestone.
- Task is closed/completed: report completion and resolve the next milestone only if the task contract explicitly names it; do not start unrelated work automatically.

Never create a fresh implementation simply because the prompt says `Continue`.

## Review semantics

`Review <TASK_ID>` means executor-side readiness review unless the active role is explicitly an independent reviewer.

For normal Codex execution it must:

- inspect the complete `origin/base...HEAD` diff;
- verify changed-file scope;
- verify task-specific tests/evidence;
- verify exact branch/head and CI authority;
- check unresolved PR review threads/findings;
- correct only executor-owned defects already within scope;
- update task/PR evidence if authorized;
- stop at the task's independent-review signal.

It must not self-issue `TECHNICAL_ACCEPTED` when the workflow requires independent ChatGPT review.

## Merge and release safety

Short commands do not imply Human Gate.

`Merge <TASK_ID>` or `Release <TASK_ID>` is not sufficient authorization by itself.

Before merge, Codex must find an explicit Human Gate in the canonical GitHub task/PR context, bound to the exact accepted PR head when the task requires exact-head authority. If absent, stop with:

`HUMAN_GATE_REQUIRED`

If the accepted/head SHA has drifted, stop with:

`TECHNICAL_REVIEW_REQUIRED`

Before release/tag/publication, Codex must find the separate explicit release Human Gate required by the task contract. A prior implementation or merge Human Gate never implicitly authorizes release.

## Examples

Operator:

`Chạy WOS-MERGE-009`

Codex resolves the Issue, reads its full contract, creates/uses the authorized branch, executes validation, opens/updates the PR as required, and stops at the contract's review boundary.

Operator:

`Tiếp tục WOS-MERGE-009`

Codex discovers the current Issue/PR/branch/CI state and resumes from the last valid checkpoint instead of replaying the original long prompt.

Operator:

`Review WOS-MERGE-009`

Codex performs complete-diff/evidence self-review and, if ready, emits the task's exact `TECHNICAL_REVIEW_REQUIRED` signal for ChatGPT.

Operator:

`Sửa WOS-MERGE-009`

Codex reads the latest changes-required review, applies only those findings, reruns required evidence, updates the same PR, and returns to technical review.

Operator:

`Status WOS-MERGE-009`

Codex reports the exact issue/PR/branch/head/CI/checkpoint and next authorized action without changing anything.

## Design rule for future ChatGPT task creation

When ChatGPT creates a new implementation/release task, the GitHub Issue must be sufficiently self-contained that Codex can execute it from only:

`Run <TASK_ID>`

The Issue should therefore contain, as applicable:

- classification;
- mission;
- dependencies/source authority;
- branch/worktree requirements;
- in-scope and out-of-scope boundaries;
- invariants;
- acceptance criteria;
- required tests/evidence;
- PR/merge/release rules;
- stop conditions;
- exact completion signal.

ChatGPT should avoid requiring the operator to carry hidden task instructions from chat into Codex. If a critical instruction exists only in chat, update the canonical GitHub task contract before relying on a short command.
