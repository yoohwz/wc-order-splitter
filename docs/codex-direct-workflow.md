# Codex Direct Workflow

## Activation and boundary

The risk-tiered DIRECT contract becomes authoritative only after canonical `POST_MERGE_ACCEPTED` for `WOS-GOV-009`. Historical direct tasks remain immutable under their accepted GOV-007/GOV-008 authority. Active pre-GOV-009 tasks do not transition unless ChatGPT/Human governance explicitly records that transition.

DIRECT is the only path that omits ChatGPT Create, ChatGPT Acceptance, and fresh Independent Codex Review. It does so only when deterministic repository guards prove the complete diff is non-semantic. The sequence is:

`explicit human Direct/Quick request -> persisted DIRECT_HUMAN_AUTHORIZED -> Codex Executor -> DIRECT_FAST Required CI -> HUMAN_GATE_APPROVED_DIRECT -> squash merge -> exact-tree Main attestation -> POST_MERGE_ACCEPTED_DIRECT`.

Protected CI, exact source/head/tree authority, authenticated human authority, squash-only merge, Main attestation, post-merge acceptance, and release/publication boundaries are never waived.

## Pre-edit bootstrap

An ordinary implementation request never opts into DIRECT. For an explicit `Direct <request>` or `Quick <request>`, Codex must before the first source edit:

1. fetch and resolve current accepted `main`, tree, successful exact-SHA Main attestation with artifacts=0, ruleset `21367637`, and repository authority;
2. prove the bounded request is presentation-only and identify the exact existing CSS paths;
3. generate one collision-checked `WOS-DIRECT-YYYYMMDD-HHMMSS` task ID;
4. create the canonical Issue and persist direct owner/designated-human `DIRECT_HUMAN_AUTHORIZED` binding task/request/source/tree/paths/no-release boundary;
5. re-read and authenticate that direct record;
6. create `codex/direct/wos-direct-YYYYMMDD-HHMMSS` from the exact source.

If classification is ineligible, edit nothing and stop:

`CODEX_DIRECT_NOT_ELIGIBLE: <reason> / proposed profile LOW|MEDIUM|HIGH`.

If wider actual scope appears after bootstrap, stop before widening:

`CODEX_DIRECT_SCOPE_ESCALATION_REQUIRED: <DIRECT_TASK_ID> / proposed profile LOW|MEDIUM|HIGH / <exact reason>`.

The existing direct Issue is the transition record; do not create duplicate task history.

## Deterministic allowlist

The initial allowlist remains modifications to existing Git-tracked regular-text mode-`100644` `*.css` presentation files beneath `css/`. No file may be created, deleted, renamed, copied, retyped, converted to a symlink, made executable, replaced by binary/LFS content, or moved outside that subtree.

The complete exact diff may change only existing `border-radius` declaration lines, one declaration per changed line, with a value of numeric zero or a non-negative numeric `px`, `rem`, `em`, or `%` length. Selector/rule changes, declaration additions/removals that cannot be paired as that narrow property edit, every other property, and reachability/state/accessibility semantics such as `display`, `visibility`, `opacity`, `pointer-events`, `:focus`, `[aria-*]`, warnings, confirmations, buttons, or submit controls leave DIRECT. The complete exact resulting blobs must also pass the repository lexical safe-subset. Any backslash, comment delimiter, non-canonical control byte, at-rule marker, `url(...)`, `expression(...)`, `://`, `//`, malformed object/diff, or scan ambiguity leaves DIRECT. Richer legitimate CSS pays normal LOW/MEDIUM/HIGH governance rather than being semantically guessed.

DIRECT forbids PHP, JavaScript, runtime/state/auth/security/privacy, tests or expected outcomes, normative governance/review/merge authority, CI/workflow scripts, gates, version/stable tag, distribution/package/release/publication, dependencies/lockfiles, external loading, control reachability, busy/disabled/blocked semantics, authoritative status/error messaging, and accessibility-critical state.

Documentation, translation, copy, test-only edits, mechanical maintenance, and governance text are not auto-DIRECT by extension or intent. New direct classes may be added only by a later accepted governance milestone with equally deterministic path/object/semantic proof.

## Executor and CI

The Executor may edit only the authenticated paths, runs `git diff --check` and proportionate local checks, opens one Draft PR to `main`, and performs adversarial exact-head self-review.

The base-owned classifier must select `DIRECT_FAST` with reason `strict_existing_css_modifications`, assurance `DIRECT`, review-required=`false`, and stage `FINAL` entirely from the exact complete-diff facts. The canonical Direct branch and persisted human authority remain necessary governance evidence, but they are validated outside profile selection and never lower the machine-derived floor. `DIRECT_FAST` revalidates exact base/head and the complete object/lexical envelope, proves runtime/gates/version/package/distribution/workflow authority unchanged, runs classifier/aggregator/workflow regressions, creates no artifact, and emits protected `Required CI` only for the exact successful topology.

No Executor evidence is called `TECHNICAL_ACCEPTED`. DIRECT has no technical-review checkpoint; its authority is deterministic CI plus the prior authenticated human request. Any classifier/profile ambiguity or FULL/LOW/MEDIUM/HIGH selection ends the direct merge path.

## Conditional direct Human Gate and merge

After `Required CI` succeeds, Codex must re-resolve:

- canonical Issue and exact pre-edit `DIRECT_HUMAN_AUTHORIZED` ID;
- unchanged request/source/base/head/tree and strict DIRECT diff;
- exact workflow run/path/head/profile/stage, `Required CI`, artifacts=0;
- active ruleset `21367637`, squash-only merge, and unresolved threads;
- unchanged runtime/gates/version/package/release/publication authority.

Only an unchanged state may derive and persist:

`HUMAN_GATE_APPROVED_DIRECT: <DIRECT_TASK_ID> / PR #N / exact head <SHA> / derived from DIRECT_HUMAN_AUTHORIZED <ID> / DIRECT_FAST Required CI <RUN_ID>`.

Codex then squash-merges, proves tested merge-candidate/tree equivalence to resulting `main`, waits for successful exact-SHA Main attestation with artifacts=0, and persists:

`POST_MERGE_ACCEPTED_DIRECT: <DIRECT_TASK_ID> / PR #N / main <SHA> / tree <TREE_SHA> / Main attestation <RUN_ID> / artifacts=0`.

Any drift invalidates the conditional direct Human Gate and stops fail-closed. DIRECT never routes to `Finalize` and never reports `READY_TO_FINALIZE`.

## Release boundary

DIRECT never authorizes version/stable-tag changes, package or release candidates, tags, GitHub Releases, WordPress.org/public ZIP publication, deployment, production-gate changes, or follow-on tasks. `WOS-REL-001` and every publication action retain separate exact-SHA/artifact human authority.
