# Codex Direct Workflow

## Purpose and activation

`TRIVIAL / CODEX_DIRECT` is a narrow workflow beneath `LOW` for presentation-only maintenance that can be completed in Codex without ChatGPT `Create` or `Finalize`. It is an explicit exception to the normal `LOW` / `MEDIUM` / `HIGH` lifecycle; it is not a cheaper label for ordinary work.

This workflow becomes active only after `WOS-GOV-007` has canonical `POST_MERGE_ACCEPTED`. Before that checkpoint, `Direct <request>` and `Quick <request>` have no merge authority.

The direct sequence is:

`explicit human Direct/Quick request -> persisted DIRECT_HUMAN_AUTHORIZED -> Codex Executor -> protected-branch Required CI -> fresh persisted Independent Codex TECHNICAL_ACCEPTED with direct-eligibility attestation -> HUMAN_GATE_APPROVED_DIRECT -> squash merge -> exact-tree Main attestation -> POST_MERGE_ACCEPTED_DIRECT`.

Executor and Independent Reviewer remain separate. Required CI, exact-head review, explicit human authority, merge-tree proof, Main attestation, and release/publication boundaries are never waived. The only waived checkpoint is ChatGPT Acceptance, and only while the complete diff remains inside this contract.

## Explicit entry and pre-edit authority

Direct mode starts only from an explicit `Direct <request>` or `Quick <request>` sent to Codex, or a repository-defined control with identical explicit semantics. An ordinary natural-language implementation request must use the normal governed workflow and never implies direct merge authorization.

Before the first source edit, Codex must:

1. fetch/prune and resolve current accepted `main`, its tree, the successful exact-SHA Main attestation with artifacts=`0`, active ruleset, and repository governance;
2. classify the bounded request and proposed paths against the allowlist and semantic boundary below;
3. if ineligible, make no source edit and stop `CODEX_DIRECT_NOT_ELIGIBLE: <reason> / proposed profile LOW|MEDIUM|HIGH`;
4. create a compact canonical GitHub Issue with a new `WOS-DIRECT-YYYYMMDD-HHMMSS` ID and bind the exact source SHA/tree, bounded request, `TRIVIAL / CODEX_DIRECT`, allowed changed paths, and no-release boundary;
5. persist `DIRECT_HUMAN_AUTHORIZED` in that Issue before source edits, binding the authenticated repository owner or task-designated human, the original explicit request, exact source SHA/tree, direct task ID, and allowlist;
6. re-read the Issue and authenticate the direct record's actor, owner/designated-human role, direct provenance, and exact source; and
7. only then create `codex/direct/<direct-task-id-lowercase>` from that exact source and implement.

The Codex/GitHub surface must be able to map the requesting human to the repository owner or an explicitly designated human authority. Actor identity alone or copied chat text is insufficient. If authentication or the GitHub write/re-read fails, stop `GOVERNANCE_AUTHORITY_REQUIRED` or `TASK_CONTRACT_UNAVAILABLE` as applicable and make no source edit.

The direct Issue and every later authority record are immutable evidence. Corrections add new comments/reviews; they do not rewrite earlier records.

## Initial allowlist

The initial direct allowlist is deliberately CSS-first. A direct diff may modify only existing, Git-tracked, regular-text `*.css` presentation files beneath `css/`. It may not create, delete, rename, replace with a binary, or traverse through a symlink.

Eligible CSS presentation changes include bounded:

- spacing, margins, padding, and gaps;
- typography size, weight, line height, and ordinary visual treatment;
- colors, borders, backgrounds, shadows, and radius;
- responsive layout and presentation;
- visual alignment and sizing.

Documentation, copy, and translation are not in the initial direct allowlist, even when non-executable. They remain at least `LOW` until a later governance milestone adds explicit non-normative paths and contract tests. File extensions alone never establish safety.

## Denylist and semantic CSS boundary

The following are always outside CODEX_DIRECT:

- any `*.php`, `inc/**`, `js/**`, `wc-order-splitter.php`, or PHP-rendered copy/markup;
- `readme.txt`, `changelog.txt`, `AGENTS.md`, this file, or any normative engineering/governance contract;
- `.github/workflows/**`, package/release scripts, branch/ruleset/security configuration, version/stable-tag state, tags, releases, publication, or deployment;
- tests, runtime behavior, mutation/domain/adapter/controller/service logic, APIs, schemas, database/persistence, authentication/authorization, security/privacy, feature/strategy gates, money, tax, stock, order/customer/transaction state;
- new external network resources, CSS `@import`, or any new remote `url(http...)` / `url(https...)` reference;
- destructive deletion, rename, generated/binary replacement, or any path outside the initial allowlist.

A CSS path is necessary but not sufficient. Direct mode must reject or escalate any CSS that:

- hides or makes unreachable security, error, confirmation, or critical mutation controls;
- changes busy, disabled, blocked, or authorization semantics rather than appearance;
- creates click interception, overlays, pointer/focus manipulation, or stacking behavior that changes interaction authority;
- uses generated content to replace authoritative operational messaging;
- loads external resources or introduces tracking-like network behavior; or
- materially hides required state/information or creates an accessibility regression.

Ordinary responsive layout and visual presentation remain eligible. Any uncertainty about product intent, architecture, runtime semantics, public API, state, security, or release behavior is ineligible.

## Scope guard and escalation

Codex must inspect both the requested scope and the actual complete diff. Branch naming, labels, or a `.css` suffix never bypass the guard.

Before the first edit, an out-of-envelope request stops:

`CODEX_DIRECT_NOT_ELIGIBLE: <reason> / proposed profile LOW|MEDIUM|HIGH`

If discovery, implementation, testing, or review later requires or reveals wider scope, Codex must stop before widening the diff:

`CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED: <DIRECT_TASK_ID> / proposed profile LOW|MEDIUM|HIGH / <exact reason>`

The existing direct Issue may be transitioned by ChatGPT into the normal workflow. Do not create duplicate task history by default. No out-of-scope file may be edited first and justified afterward.

## CI and Independent Review

Codex opens a Draft PR to `main`, runs focused local evidence, waits for protected-branch `Required CI` success with artifacts=`0`, and performs complete-diff executor self-review. The repository-owned PR classifier selects only `DIRECT_CSS_FAST` or `FULL`, with `FULL` as the fail-closed default. FAST requires both a positive canonical direct branch signal matching `codex/direct/wos-direct-YYYYMMDD-HHMMSS` and strict diff facts; the branch signal alone has no authority. CI executes the classifier object from the exact PR base, and the initial no-base-classifier bootstrap selects `FULL`. `DIRECT_CSS_FAST` is limited to a non-empty exact base-to-head diff containing only status-`M` changes to existing tracked regular-text mode-`100644` CSS files beneath `css/`; additions, deletions, renames, copies, type/mode changes, symlinks, binary/LFS replacements, non-CSS or CI/control-plane paths, non-PR events, and any ambiguity select `FULL`.

The fast profile accepts only lexically simple presentation CSS. It scans each exact resulting changed CSS blob without attempting CSS comment stripping, escape decoding, or line-continuation normalization. Any backslash, comment delimiter, non-canonical ASCII control byte, at-rule marker, `url(...)` function of any kind, `://` or `//` network marker, or `expression(...)` function selects `FULL`. This intentionally sends richer but still legitimate CSS—such as local URLs, comments, at-rules, and escapes—through normal CI rather than trying to prove semantic safety with a partial CSS parser.

For `DIRECT_CSS_FAST`, focused CI binds exact PR and merge-candidate authority, revalidates the complete CSS-only diff, runs `git diff --check`, exercises classifier and aggregator regressions, proves relevant runtime/gate/distribution/workflow/version authority unchanged, and creates no artifact. `Required CI` validates the exact success/skipped job combination for the selected profile. Labels, PR text, task IDs, actor claims, and branch naming without strict diff facts never select fast CI. Normal LOW/MEDIUM/HIGH, runtime, test, governance, workflow, package/release, mixed-scope, malformed, and manually dispatched work uses `FULL` and retains the complete PHP, architecture, distribution, and WooCommerce storage matrix. Fresh Independent Codex Review remains mandatory for either direct CI cost profile and machine classification never substitutes for semantic eligibility review.

On the current user-owned GitHub repository, `Required CI` is a mechanical protected status from the GitHub Actions App, not standalone proof of an immutable workflow-file provenance. This repository contract does not claim that base-loaded helpers prevent a malicious workflow-changing PR from emitting the same App/context. Direct merge authority therefore remains multi-source: before `HUMAN_GATE_APPROVED_DIRECT`, Codex must authenticate the persisted pre-edit authority, exact CSS-only diff and object types, exact canonical run identity/path/head, unchanged CI/control-plane paths and ruleset/review state, and a fresh persisted exact-head Independent Review that explicitly confirms direct eligibility. A workflow/control-plane delta is never direct and routes to normal `FULL` governance with ChatGPT Acceptance and conditional Human Gate.

Codex must then dispatch a fresh source-read-only Independent Codex Reviewer. If fresh separation is unavailable, stop `INDEPENDENT_REVIEW_DISPATCH_REQUIRED: <DIRECT_TASK_ID>`. The reviewer must verify and persist:

- the exact explicit request and pre-edit `DIRECT_HUMAN_AUTHORIZED` authority ID;
- exact source/base/head and complete diff;
- every changed path satisfies the initial allowlist;
- the CSS change remains presentation-only under the semantic boundary;
- no PHP, JavaScript, runtime, state, security, release, package, gate, or normative-governance expansion exists;
- the requested visual result is sufficiently established;
- canonical CI, merge-candidate/tree where available, and artifacts state;
- fresh-context, executor-session-not-reused, and source read-only/no-implementation-write attestations; and
- no unresolved blocking finding.

The exact accepted outcome is:

`TECHNICAL_ACCEPTED: <DIRECT_TASK_ID> / TRIVIAL / CODEX_DIRECT / PR #N / exact head <SHA> / direct eligibility confirmed / persisted authority <ID>`

Each head-changing correction requires proportional evidence, fresh exact-head CI, and a new complete persisted Independent Review. The normal three-cycle bound applies. A review record that omits direct eligibility or cannot be re-read/authenticated has no merge authority and stops `TECHNICAL_REVIEW_PERSISTENCE_REQUIRED: <DIRECT_TASK_ID> / exact head <SHA>`.

## Conditional Human Gate, merge, and post-merge proof

The explicit human `Direct` / `Quick` request supplies conditional bounded Human Gate authority only through the authenticated pre-edit `DIRECT_HUMAN_AUTHORIZED` record. The Executor does not become the Human Gate. The executor cannot self-accept.

After exact-head persisted Technical Acceptance, Codex must re-resolve and verify:

1. the request, source, allowlist, direct classification, and pre-edit human authority are unchanged;
2. current `main`, exact base/head, merge candidate/tree, protected-branch `Required CI`, artifacts=`0`, active ruleset, and unresolved review threads;
3. the complete diff still passes both path and semantic guards; and
4. the Technical Acceptance is fresh, independent, persisted, authenticated, exact-head, and explicitly confirms direct eligibility.

Any source/base/head/authority drift invalidates the conditional merge path. Stop with the applicable `TASK_BRANCH_SYNC_REQUIRED`, `TECHNICAL_REVIEW_REQUIRED`, or governance signal; do not silently rebase or reinterpret the original human authority.

Only an unchanged eligible head may receive a distinct persisted:

`HUMAN_GATE_APPROVED_DIRECT: <DIRECT_TASK_ID> / PR #N / exact head <SHA> / derived from DIRECT_HUMAN_AUTHORIZED <ID>`

Codex may then squash-merge only that head under repository rules. It must prove that the resulting `main` commit has the expected sole base parent and the exact tested merge-candidate tree, then verify successful exact-SHA `Main attestation` with artifacts=`0`. WOS-GOV-006 manual exact-SHA recovery may be used only when the automatic push attestation ghosts and its authority requirements are satisfied.

The terminal record is:

`POST_MERGE_ACCEPTED_DIRECT: <DIRECT_TASK_ID> / PR #N / main <SHA> / tree <TREE_SHA> / Main attestation <RUN_ID> / artifacts=0`

Only after persisting and re-reading that record may Codex close the direct Issue and report completion. A successful direct task requires no ChatGPT `Acceptance Review`, `ACCEPTANCE_ACCEPTED`, or `Finalize`.

## Release and publication boundary

CODEX_DIRECT never authorizes version or stable-tag changes, a package or release candidate, tags, GitHub Releases, WordPress.org/public ZIP publication, deployment, production feature-gate enablement, or any other release action. Required CI, Technical Acceptance, `HUMAN_GATE_APPROVED_DIRECT`, merge, and post-merge acceptance are implementation authority only. Release freeze and publication retain their separate explicit exact-SHA/artifact Human Gates.
