# CI Workflow Contract

## Authority model

Normal work uses focused Local evidence followed by the repository-owned exact-diff classifier in `.github/scripts/classify-pr-scope.sh`. Assurance and CI are separate dimensions:

- assurance decides whether fresh Independent Codex Review is required;
- CI profile and stage decide which deterministic checks run for the exact head.

The protected context remains exactly `Required CI`. Ruleset `21367637` remains strict, PR-only, squash-only, conversation-resolution-enabled, and without bypass actors. CI evidence is never ChatGPT Acceptance, Human Gate, release, or publication authority.

The machine profiles are `DIRECT_FAST`, `LOW_FOCUSED`, `MEDIUM_DOMAIN`, `HIGH_DEEP`, `HIGH_FINANCIAL`, and `RELEASE_CERT`. `FULL` is accepted only as a source-base compatibility alias for bootstrap PRs whose exact base still owns the pre-WOS-GOV-009 classifier/aggregator.

Changed-content financial scanning completes the diff producer and changed-line filter before a consuming file scan. A match raises `HIGH_FINANCIAL`; only a successful no-match scan may fall through. Producer, filter or scanner failure raises that same minimum with `unresolved_financial_content_scan`. `pipefail` and diagnostics remain enabled. Requested floors, including `RELEASE_CERT`, still apply after that result. Other path/context/direct-routing guards retain their existing semantics.

`tests/ci/classifier-determinism-contract.js` exercises large early/late tokens, no-match content, explicit producer/filter/scanner failures, requested floors, and normal/paced scheduling. Its frozen eight-path GOV-010 replacement diff binds base `545b82b452adfc4d43fd4744f3f83d7a8f5e68fb`, head `46ff3db88fc8be47853444c59c7e8d83f9033d20`, and SHA-256 `6a7a9ad8d477aa9a9e385ec6745afc85c7aee0ee4ec47172cad5c9cfcb2d7e3a`. The negative control requires the old guard to reproduce upstream SIGPIPE while grep reports a match. Existing direct/profile contracts retain DIRECT/LOW/MEDIUM coverage. Classifier audit found no other classification-affecting early-reader pipeline: the remaining quiet greps read regular files or here-strings, and other pipelines consume their input.

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

## Strict merge-candidate authority (WOS-GOV-011)

FINAL remains exact-head certification with protected `Required CI`; it is **not** by itself proof that GitHub considers a PR merge-ready. A workflow-dispatch check can remain successful while the PR rollup selects a different pull-request suite. A regenerated test-merge SHA also has no inherited check. Resolve the live state rather than inferring mergeability from an earlier run.

The metadata-only native bridge is `.github/workflows/merge-authority.yml`, triggered only by `pull_request: ready_for_review` against `main`. The failed custom Check Run architecture and `workflow_dispatch MERGE_AUTHORITY` input/path are retired: do not retry them, lengthen polling, manually patch a check, or create a status through another integration. Ordinary discovery CI and expensive FINAL certification are unchanged.

All governed non-DIRECT PRs remain draft through PRECHECK, required Independent Review, FINAL, task-appropriate evidence, and ChatGPT Acceptance. While the PR is still draft, ChatGPT persists a new Human Gate that binds the exact base/head/tree/current candidate and `PR state: draft`. Only after that record is authenticated may ChatGPT mark the unchanged PR ready once. That transition triggers the native bridge; no dispatch inputs or title/label claims grant authority.

GitHub's [native pull-request semantics](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#pull_request) supply `GITHUB_REF=refs/pull/PR/merge` and `GITHUB_SHA` equal to the event merge commit. This is distinct from the Actions REST run/suite/check `head_sha`, which identifies the PR branch head: the existing native discovery run `33615919165` demonstrates that API representation. Do not require these different fields to be identical or mistake a manually created unassociated candidate check for a PR-native check. The verifier binds both the runner's exact candidate/ref/workflow source and the live native run/suite/check's exact PR association, head, base, workflow path and app `15368`. Positive live recognition still has to be proven during Finalize.

A base-owned scope job resolves the owner-authored canonical Issue and complete-diff classification. Only canonical Direct task/branch plus proven `DIRECT_FAST / DIRECT / false` and no normal-task floor is excluded; this emits no protected check and grants no Direct approval. A normal task whose CSS happens to fit the direct lexical envelope still uses its Task Capsule floors and normal authority. The dependent native job uses `always()`: for governed, invalid, failed or missing scope it is named exactly `Required CI` and must run. Scope failure, invalid scope, or any failed verification exits failure, never skipped/neutral success. Proven DIRECT instead uses the non-protected name `Native bridge not applicable to DIRECT` and retains its existing lifecycle.

Both jobs have read-only permissions, including `checks: read`, and check out the exact event merge SHA without persisted credentials. No Checks API write or second certification occurs. Before its native protected job may complete, the verifier authenticates twice:

- real owner-triggered `pull_request / ready_for_review`, first run attempt, exact event PR/base/head/candidate, merge ref and workflow ref/source; candidate parents current base/head, candidate tree equal to the reviewed head tree, current main unchanged;
- owner login/numeric ID, exact canonical PR/Issue/Task Capsule, independently raised CI/assurance/review floors;
- latest direct immutable current-head Human Gate, distinct CI evidence and Acceptance, their exact references, roles, schemas and chronology; malformed/edited latest positive authority cannot fall back to an older Gate;
- Gate's draft-state attestation and exactly one owner ready transition since that Gate, bound to the event timestamp; a repeat transition or rerun cannot reuse it;
- `EXECUTOR_EVIDENCE_READY` for LOW/no-trigger MEDIUM, or independently grounded `TECHNICAL_ACCEPTED` plus the exact-base PRE_REVIEW validator for reviewed work;
- successful exact-head FINAL, app/check/suite/run/attempt/profile/artifacts=0 and exact successful `FINAL binding / TASK / PROFILE / BASE / PRE_REVIEW_REFERENCE_OR_none`;
- native running protected check, app/suite/PR association, artifacts=0, pinned ruleset and all paginated unresolved threads=0;
- later direct PRE_REVIEW/Technical/Acceptance changes-required or Human Gate revocation, with authenticated role/provenance, all remain blocking.

The `native-merge-authority-verified` log attestation binds the immutable event merge ref/candidate, base/head/tree, FINAL and native run/check/suite identities, evidence/Acceptance/Gate/review IDs and record digest, artifacts=0 and threads=0. It is not a claim that its own running job is already successful or that GitHub is already clean.

After the job completes, ChatGPT must run the read-only `.github/scripts/verify-terminal-merge-readiness.js PR EXACT_HEAD POST_GATE_NATIVE_RUN` immediately before squash merge. It reuses the full native authority verifier in completed mode, then authenticates the exact successful post-Gate native run/job/check/suite (never skipped/neutral), attempt 1, app `15368`, native PR association, artifacts=0 and the timestamped candidate-bound log attestation. The live authority record digest must equal the logged digest. A successful head FINAL or an arbitrary latest green check with the name `Required CI` cannot replace this exact native result.

Terminal readiness requires `mergeable=true`, unchanged base/head/candidate/tree/current main, exact candidate parents, zero unresolved threads, the pinned owner-visible ruleset revision with no bypass actors, squash-only merge and all active PR rules. The helper enumerates the rules actually enforced on `main`, requires them to equal the pinned ruleset, and rejects additional or unreadable classic branch protection. It checks the current GraphQL PR/review decision against REST, then paginates all check runs, check suites and commit statuses on both the head and candidate. Each enforced context must have its exact completed/successful authoritative check from the expected integration. The supported topology has the native check on the PR head with its separate verified candidate attestation; additional candidate-specific statuses/checks/suites fail closed because they can change which commit GitHub evaluates.

`mergeable_state=clean` is sufficient only with all of those invariants. `unstable` is also acceptable when every enforced requirement is independently proven and the remaining pending/failing rollup entries are demonstrably non-required diagnostics or superseded pre-Gate history. An empty queued Render suite from app `14658`, outside the required integration, is diagnostic; it is not a required context. Completed pre-Gate `Required CI` history may be superseded only by the exact successful post-Gate native check authenticated above. Any competing post-Gate required-name check, pending/missing/failed required context, wrong-app required-name check, required-name legacy status with unproven integration, unresolved required-app suite, unexplained unstable state, or ambiguous inventory blocks merge. The verifier never selects required authority by latest-green-name heuristics. `blocked`, `behind`, `dirty`, `draft`, `unknown`, null mergeability and all other unproven states remain blocked. GitHub documents why [required checks, expected integrations and test-merge status identity](https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks) must be evaluated separately from optional results.

The helper collects and verifies two complete live snapshots and emits `terminal-merge-readiness-verified` only when their authority and inventory agree. Load it and `merge-candidate-authority.js` from the accepted base in an isolated temporary directory, run with the repository as the working directory, and supply the exact native run ID selected from the current Gate/ready transition. The helper checks both executable source bytes against that trusted Git source and always extracts the classifier/PRE_REVIEW validators from the exact base. Only the source-bound GOV-011 tuple below may use the newly reviewed exact-head helpers. A terminal result is read-only evidence for ChatGPT's existing Finalize authority; it never creates Acceptance, Human Gate, a check/status, a merge or release authority. Failure stops before merge; no check is flipped manually.

There is no atomic API across mutable authority, job completion and merge. Head/base drift requires fresh task-appropriate certification/review/Acceptance/Human Gate. Candidate regeneration with unchanged base/head/tree requires a newly bound Gate while draft and a new ready transition, never copying prior authority or silently reusing the old event. Do not repeat expensive FINAL solely for candidate regeneration. Strict up-to-date and unresolved-thread protection remain active across the final merge race.

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

Human Gate: header `## Merge Human Gate — TASK`; fields `Role: repository_owner`, `Evidence authority: issue-comment:ID`, `Acceptance authority: issue-comment:ID`, `Human command: Finalize TASK`, `Merge candidate: CANDIDATE_SHA`, `Merge candidate tree: TREE_SHA`, `Unresolved review threads: 0`, and `PR state: draft`. Persist while draft and before the ready transition. Terminal `HUMAN_GATE_APPROVED: TASK / PR #PR / exact head HEAD_SHA`. This comes only from the authenticated human's conditional Finalize authority, not from `Run`, CI, a PR body, or a title/label.

### Source-bound self-bootstrap

WOS-GOV-011 uses GOV-009 task-bound PRECHECK, fresh Independent PRE_REVIEW, and FINAL. Since its exact source base lacks the new verifier/workflow, the native loader has one bootstrap: repository `yoohwz/wc-order-splitter`, task `WOS-GOV-011`, Issue `134`, PR `135`, source base `545b82b452adfc4d43fd4744f3f83d7a8f5e68fb`, HIGH_FINANCIAL floor, HIGH assurance and REQUIRED review. Only there may it load the newly reviewed/accepted verifier from the event's exact head; classifier and PRE_REVIEW validators remain base-owned. The native workflow comes from the exact event merge context. Source/tree proof, fresh review, green FINAL, new Acceptance and draft-bound Human Gate remain mandatory before protected success. After accepted main owns the verifier, the fallback is inert; other missing-verifier bases fail closed.

GOV-011 correction authority `issue-comment:5518266015` replaces the overbroad clean-only terminal rule with the ruleset-aware verifier above. Native run `33697620312` / check `100469858221` proved the old head's post-Gate bridge success while a non-required empty Render suite kept the aggregate unstable. That historical observation does not authorize the corrected head: the revoked Gate and old Acceptance/review/FINAL remain historical. This correction consumes source-changing cycle 1 of the GOV-011 maximum of 3 and requires fresh task-bound PRECHECK, complete-diff Independent PRE_REVIEW, FINAL, Technical Acceptance and ChatGPT Finalize.

Issue #134 authorizes convergence of the deterministic classifier correction and the native architecture from Issue #132 `issue-comment:5508357529`. The previous classifier defect and terminal GOV-010 stop are bound by `issue-comment:5509080079` and `pr-review:5089193739`. Before implementation, authenticate that accepted-base CI applies the owner-authored HIGH_FINANCIAL floor as a hard minimum in both PRECHECK and FINAL; otherwise stop `ARCHITECTURE_REVIEW_REQUIRED: WOS-GOV-011`. This conservative floor permits self-bootstrap while the classifier fix is still head-local. GOV-011 has its own maximum of three source-changing review corrections; it cannot extend GOV-010's exhausted architecture budget or reuse its approvals.

Positive native merge-context check and GitHub recognition occur only after new ChatGPT Acceptance and Human Gate while draft, then mark-ready during Finalize, **before merge**. `READY_TO_FINALIZE` does not claim live bridge success. Unrecognized native authority stops `ARCHITECTURE_REVIEW_REQUIRED: WOS-GOV-011`; do not merge or weaken protection.

After GOV-011 reaches `POST_MERGE_ACCEPTED`, GOV-010 Issue #132 / PR #133 is superseded and must not merge separately. WOS-COMPAT-007 requires explicit ChatGPT/Human rebind directly to the new main and a new `RELEASE_CERT` under the now-base-owned deterministic classifier and native bridge. Its old Acceptance/Human Gate cannot be reused. No consumer rebind or release authority is implicit in this bridge.

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
