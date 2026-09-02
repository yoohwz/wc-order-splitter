# CI Workflow Contract

## Authority model

Normal work uses focused Local evidence followed by the repository-owned exact-diff classifier in `.github/scripts/classify-pr-scope.sh`. Assurance and CI are separate dimensions:

- assurance decides whether fresh Independent Codex Review is required;
- CI profile and stage decide which deterministic checks run for the exact head.

The protected context remains exactly `Required CI`. Ruleset `21367637` remains strict, PR-only, squash-only, conversation-resolution-enabled, and without bypass actors. CI evidence is never ChatGPT Acceptance, Human Gate, release, or publication authority.

The machine profiles are `DIRECT_FAST`, `LOW_FOCUSED`, `MEDIUM_DOMAIN`, `HIGH_DEEP`, `HIGH_FINANCIAL`, and `RELEASE_CERT`. `FULL` is accepted only as a source-base compatibility alias for bootstrap PRs whose exact base still owns the pre-WOS-GOV-009 classifier/aggregator.

## Fail-closed selection

The classifier derives minimum CI, assurance, and Independent Review requirements from the complete exact base-to-head diff. PR title/body, task ID, labels, actor claims, and ordinary branch names cannot lower any floor. Dispatch inputs are accepted only when the authenticated owner-authored canonical Issue contains exact machine-readable `CI profile floor`, `Assurance floor`, and `Independent review floor` list fields. Each requested value may only raise its corresponding machine minimum. Mixed scope selects the strongest applicable floor; unknown or malformed authority selects a reviewed HIGH/RELEASE path.

`DIRECT_FAST` requires a non-empty exact diff containing only status-`M` changes to existing Git-tracked regular-text mode-`100644` `*.css` files beneath `css/`. The changed lines may modify only an existing `border-radius` declaration, one declaration per line, with numeric zero or a non-negative numeric `px`, `rem`, `em`, or `%` value. Selector/rule changes, every other property, and reachability/state/accessibility semantics leave DIRECT. Each resulting blob is scanned without comment stripping, escape decoding, or line-continuation normalization. Any backslash, comment delimiter, non-canonical control byte, `@`, `url(...)`, `expression(...)`, `://`, `//`, binary/LFS replacement, object ambiguity, addition/deletion/rename/copy, or non-CSS path leaves DIRECT. Canonical Direct branch and human authority are separate governance requirements; the classifier does not consume them, so a branch/task claim cannot lower the complete-diff floor.

Typical floors are:

- deterministic direct CSS: `DIRECT_FAST`;
- deterministic translation or non-semantic presentation outside the strict Direct envelope: `LOW_FOCUSED`;
- bounded client runtime: `MEDIUM_DOMAIN`;
- mutation/persistence/governance/workflow/unknown authority: `HIGH_DEEP`;
- money/tax/payment/refund/price/stock authority, detected by explicit sensitive paths or conservative changed-content guards: `HIGH_FINANCIAL`;
- release/version/package/distribution/Main-attestation authority: `RELEASE_CERT`.

MEDIUM semantic trigger scanning can only add Independent Review. The repository currently has no mechanically proven JavaScript no-trigger sub-envelope, so every JavaScript candidate is treated as ambiguous and review-required, including `fetch`, `XMLHttpRequest`, jQuery transport, `sendBeacon`, and project mutation helpers. A future no-trigger route must first gain a fail-closed proof contract; ambiguity selects review.

## Stages and review-first certification

`DIRECT_FAST` runs FINAL certification directly after its pre-edit authority. Every ordinary normal PR push runs unbound authority-discovery PRECHECK under a non-protected check whose identity contains `UNBOUND`; it never publishes a `Required CI` check and cannot be cited by PRE_REVIEW. Codex dispatches an exact-head task-bound PRECHECK for every review-required candidate, or task-bound FINAL for no-review work once all three Task Capsule floors are authenticated. This prevents an earlier cheaper machine-only result from appearing merge-ready before canonical task authority is applied.

Review-required candidates run discovery `PRECHECK` on each ordinary PR head, then an explicit stale-safe task-bound PRECHECK dispatch applies the canonical CI, assurance, and review floors before PRE_REVIEW. PRECHECK includes exact-diff validation, PHP 8.3 syntax/unit evidence, architecture/gate/governance/profile contracts, suite-completeness contracts, one canonical-storage affected-domain smoke where practical, cross-domain sentinels, and artifacts=0. It is engineering evidence only.

PRECHECK uses dynamically distinct non-protected job identities: discovery runs contain `UNBOUND`, while a task-bound dispatch emits `Risk-tiered PRECHECK / <TASK_ID> / <PROFILE>` and `PRECHECK authority only / <TASK_ID> / <PROFILE>`. The workflow emits no `Required CI` check at all for PRECHECK. `.github/scripts/verify-precheck-ci.sh` authenticates PRECHECK topology, while `.github/scripts/verify-required-ci.sh` rejects every non-`FINAL` stage. A skipped/neutral `Required CI` is forbidden because GitHub branch protection treats it as accepted. PRECHECK cannot satisfy branch protection.

After a fresh source-read-only Independent Reviewer persists exact-head `PRE_REVIEW_CLEAN`, FINAL is triggered through `workflow_dispatch` with canonical task ID and Issue number, PR number, expected head, all three Task Capsule floors, requested stage, and immutable `issue-comment:ID` or `pr-review:ID` authority. The workflow:

1. re-resolves the open PR and requires base `main`;
2. requires the dispatch ref/GitHub SHA and current PR head to equal `expected_head_sha`;
3. reruns the base-owned classifier, allowing the requested profile only to raise the machine floor;
4. loads the PRE_REVIEW validators from the exact PR base;
5. authenticates owner-structured independent-review provenance, exact base/head/tree, PR-review `commit_id` where applicable, source-read-only attestations, and one unfenced/unquoted canonical record whose unique terminal outcome is `PRE_REVIEW_CLEAN`;
6. authenticates the cited PRECHECK run as task-bound `workflow_dispatch` on the same head/profile/stage, with exactly one successful `Risk-tiered PRECHECK / <TASK_ID> / <PROFILE>` and `PRECHECK authority only / <TASK_ID> / <PROFILE>`, no `Required CI`, and artifacts=0;
7. runs FINAL certification and only then permits successful `Required CI`.

Any head-changing correction invalidates the earlier PRE_REVIEW and returns to PRECHECK plus a new complete-diff Independent Review. A green FINAL run on the unchanged clean-review head may be mechanically promoted to persisted `TECHNICAL_ACCEPTED` by binding the PRE_REVIEW authority ID, exact head/tree, final run/profile, and artifacts=0. No duplicate source reread is required; no Executor-authored conclusion can replace the prior Independent Review.

## Strict merge-candidate authority (WOS-GOV-010)

FINAL remains exact-head certification with protected `Required CI`; it is **not** by itself proof that GitHub considers a PR merge-ready. A workflow-dispatch check can remain successful while the PR rollup selects a different pull-request suite. A regenerated test-merge SHA also has no inherited check. Resolve the live state rather than inferring mergeability from an earlier run.

The lightweight `MERGE_AUTHORITY` stage runs through the existing `ci.yml` workflow only after ChatGPT Acceptance and a separate authenticated Human Gate. It never reruns certification. All ordinary normal profiles use this metadata stage; LOW/no-trigger MEDIUM binds `EXECUTOR_EVIDENCE_READY` and does not acquire Independent Review. DIRECT retains its existing pull-request FINAL and direct Human Gate lifecycle, with no normal bridge dispatch.

The dispatch uses the same PR/task/Issue/head/three floor inputs as FINAL, the same `pre_review_authority` (empty for no-review), `certification_stage=MERGE_AUTHORITY`, and `merge_authority=issue-comment:ID` naming the canonical Human Gate below. It must be a fresh dispatch (attempt 1) on the unchanged PR head. Rerunning a bridge is not authority renewal.

Only the bridge job has `checks: write`; its native job name is never `Required CI`, including when skipped. Certification jobs are disabled for this stage. A base-owned Node verifier and base-owned classifier/PRE_REVIEW validators authenticate:

- owner actor login plus numeric ID, owner-authored Task Capsule floors, exact PR/Issue binding, and direct structured roles; role separation remains the governance contract, not a cryptographic distinction between sessions using the owner's account;
- separate immutable Issue-comment evidence, Acceptance, and Human Gate records, their references and chronology; edited, duplicate, quoted, fenced, malformed, conflicting, or unrelated records fail closed;
- the base-derived profile/assurance/review requirement; required Independent Review is revalidated by the existing base-owned PRE_REVIEW verifier, never replaced by an Executor claim;
- successful exact-head `workflow_dispatch` FINAL, workflow path, branch, attempt, GitHub Actions app/check/suite identity, artifacts=0, and the successful `FINAL binding / TASK / PROFILE / BASE / PRE_REVIEW_REFERENCE_OR_none` job;
- current main/base/head, base ancestry, current candidate parents, candidate tree equal to the certified head tree, unchanged ruleset `21367637`, and all paginated review threads resolved;
- later direct adverse governance checkpoints, including a newer changes-required review before mechanical promotion.

Only then may GitHub Actions app `15368` create an in-progress `Required CI` **on the current test-merge SHA** through the Checks API. It revalidates authority before success and after writing success, re-reads the check's exact SHA/app/result/attestation, and requires live GitHub `mergeable_state=clean`. Detected drift or failure invalidates the created check with `failure`; `skipped`, `neutral`, missing, wrong-SHA, wrong-app, and unrecognized authority are rejected. The attestation binds the FINAL run/attempt/profile, base/head/tree/candidate, authority IDs and record digest, bridge run, artifacts=0 and unresolved threads=0.

There is no atomic API spanning mutable governance records, check creation, and merge. ChatGPT must independently re-read this same authority, the successful completed bridge run and candidate check immediately before squash merge. A cancelled/failed bridge run is never merge authority even if interrupted after a check write. Head/base changes require fresh task-appropriate certification/review/Acceptance/Human Gate; a regenerated candidate with unchanged base/head/tree requires a newly candidate-bound Human Gate and fresh bridge dispatch, not a second expensive FINAL. A stale candidate check is not copied to the replacement SHA. GitHub strict up-to-date and unresolved-thread protection remain active across the final merge race.

GitHub's [ruleset API](https://docs.github.com/en/rest/repos/rules#get-a-repository-ruleset) redacts `bypass_actors` unless the caller can write the ruleset. Actions must not gain Administration permission for this read. The verifier pins the owner-authenticated source ruleset revision `updated_at=2026-08-25T10:09:48.838+07:00` (history version `47541914`, owner actor `152001663`, verified empty bypass list) and all visible constraints; a missing/changed revision fails closed even when hidden fields are redacted. If the bypass field is visible it must be empty. Finalize must use owner authority to re-read the complete ruleset, including bypass actors, before Human Gate and immediately before merge. Future intentional ruleset edits require a separately reviewed pin transition, never an automatic revision update.

### Canonical bridge records

Each record is a new top-level comment on the canonical task Issue by the authenticated owner. Never edit an existing record. It starts with its exact header, contains one of each field and no other fields, and ends with its exact terminal. Records contain plain unfenced text, without backticks, indentation, HTML, quoted transcripts, or a footer. A separate navigation/reference comment may carry `NEXT_ACTION_HINT`.

All three records contain these common fields (replace placeholders with exact values):

```text
Record version: merge-authority-v1
Canonical Issue: #ISSUE
PR: #PR
Exact base: BASE_SHA
Exact head: HEAD_SHA
Exact head tree: TREE_SHA
CI profile: SELECTED_PROFILE
Assurance: SELECTED_ASSURANCE
Review required: true_OR_false
PRE_REVIEW authority: pr-review:ID_OR_issue-comment:ID_OR_none
FINAL run: RUN_ID
FINAL attempt: ATTEMPT
Artifacts: 0
```

Evidence: header `## Merge CI evidence — TASK`; fields `Role: codex_executor` and `Evidence kind: TECHNICAL_ACCEPTED` for reviewed work, or `Evidence kind: EXECUTOR_EVIDENCE_READY` for no-review work. Terminal `TECHNICAL_ACCEPTED: TASK / PR #PR / exact head HEAD_SHA` or the corresponding `EXECUTOR_EVIDENCE_READY` terminal. This is mechanical post-FINAL promotion of the separately authenticated review, not a new technical conclusion.

Acceptance: header `## Merge Acceptance — TASK`; fields `Role: chatgpt_acceptance_reviewer` and `Evidence authority: issue-comment:ID`. Terminal `ACCEPTANCE_ACCEPTED: TASK / PR #PR / exact head HEAD_SHA`. A separate accompanying comment may explain Acceptance findings; the Executor cannot issue this record.

Human Gate: header `## Merge Human Gate — TASK`; fields `Role: repository_owner`, `Evidence authority: issue-comment:ID`, `Acceptance authority: issue-comment:ID`, `Human command: Finalize TASK`, `Merge candidate: CANDIDATE_SHA`, `Merge candidate tree: TREE_SHA`, and `Unresolved review threads: 0`. Terminal `HUMAN_GATE_APPROVED: TASK / PR #PR / exact head HEAD_SHA`. This comes only from the authenticated human's conditional Finalize authority, not from `Run`, CI, a PR body, or a title/label.

### Source-bound self-bootstrap

WOS-GOV-010 itself still uses GOV-009 task-bound PRECHECK, fresh Independent PRE_REVIEW, and FINAL. Since its exact source base lacks the new verifier, the bridge loader has one explicit bootstrap: repository `yoohwz/wc-order-splitter`, task `WOS-GOV-010`, Issue `132`, source base `545b82b452adfc4d43fd4744f3f83d7a8f5e68fb`, HIGH assurance and REQUIRED review. Only there may it load the newly reviewed/accepted verifier from the exact dispatch head; the classifier and PRE_REVIEW validators remain source-base-owned. Other absent-verifier bases fail closed. This is not a general head-code fallback or a ruleset bypass.

The implementation can prove deterministic and denied-dispatch cases during `Run`. Positive live candidate check and GitHub mergeability proof occur after ChatGPT records Acceptance/Human Gate during `Finalize`, **before any merge**. `READY_TO_FINALIZE` therefore does not claim that the live bridge has already succeeded. If GitHub cannot recognize the exact candidate check, stop `ARCHITECTURE_REVIEW_REQUIRED: WOS-GOV-010`; do not merge or weaken protection.

After GOV-010 is terminally accepted, WOS-COMPAT-007 requires explicit ChatGPT/Human rebind to the new main and fresh required certification authority. Its old Acceptance/Human Gate cannot be reused. No consumer rebind or release authority is implicit in this bridge.

## Profile topology

- `DIRECT_FAST`: exact authority revalidation, strict lexical/object guard, diff check, classifier/aggregator/workflow regressions, unchanged runtime/gates/version/package/control-plane proof, artifacts=0.
- `LOW_FOCUSED`: changed static syntax as applicable, exact diff, profile/aggregator/governance contracts, artifacts=0.
- `MEDIUM_DOMAIN`: focused contracts plus affected-domain integration in HPOS by default and cross-domain sentinels; storage-sensitive task authority may raise the profile.
- `HIGH_DEEP`: PHP 7.4/8.1/8.3, architecture/gates, package safety, affected deep/recovery/security suites across legacy/HPOS/HPOS-sync, HPOS real-worker lease exclusion, sentinels, artifacts=0.
- `HIGH_FINANCIAL`: a strict superset of `HIGH_DEEP` affected runtime/recovery/concurrency evidence plus money/tax/payment/refund/stock specialization across legacy/HPOS/HPOS-sync, HPOS real-worker lease exclusion, sentinels, artifacts=0.
- `RELEASE_CERT`: exhaustive certification equivalent to or stronger than the source-baseline FULL matrix, including every release-manifest suite artifact, all three storage modes, package/distribution/version authority, and artifacts=0.

`tests/ci/integration-suites.tsv` is the repository-owned suite manifest. `tests/ci/integration-suite-contract.sh` binds baseline source `ab7b1db49ff7b82ad1bb7fae3bbbafd56a5eb328` / tree `b44f54e597e8f03d6d83c30acf55eb162535e96d` and proves every integration artifact invoked by that FULL workflow remains in the `release` union. Focused profiles select tagged affected-domain suites and sentinels; they do not delete or weaken the release set.

The escaped-defect guard set must keep financial per-rate evidence, PII-free canonical authority, controller/confirmation replay, legacy/current production-boundary replay, presentation-vs-persisted authority separation, and fault-injection/recovery paths represented in profile or suite-routing contracts.

## Bootstrap and exact protected result

WOS-GOV-009 itself is a one-time source-base bootstrap. Its PR base owns the old `FULL` classifier and aggregator, so `.github/workflows/ci.yml` preserves the legacy job IDs and exact FULL PHP/architecture/package/legacy-HPOS-HPOS-sync matrix. The Required aggregator is loaded from the exact PR base. Therefore WOS-GOV-009 cannot use PRECHECK promotion, no-review DIRECT, LOW, or MEDIUM shortcuts to accept itself.

For prospective profiles, the risk-tiered aggregator requires the exact success/skipped topology. Missing classifier output, PRECHECK presented as FINAL, wrong/missing/skipped jobs, stale authority, or an impossible profile/review combination fails closed.

On this user-owned repository, `Required CI` is a GitHub Actions App status, not cryptographic proof of an immutable workflow file. Governance therefore re-authenticates exact diff, base-loaded control scripts, run identity/path/head, ruleset, review authority, unresolved threads, and post-merge tree. Workflow-changing PRs always require HIGH governance and ChatGPT Acceptance.

## Main and package boundary

`.github/workflows/main-attestation.yml` remains push/main plus authenticated exact-SHA manual attestation, verifies parent/tree/distribution/gates, and uploads no artifact. Fast attestation is safe only while ruleset `21367637` enforces PR entry and strict `Required CI`.

`.github/workflows/build-plugin.yml` remains manual-only. Neither `Run`, `Finalize`, `Required CI`, Technical Acceptance, Human Gate, nor post-merge acceptance authorizes package creation, tag, GitHub Release, WordPress.org publication, deployment, or production-gate change. `WOS-REL-001` remains separately frozen and publication-gated.
