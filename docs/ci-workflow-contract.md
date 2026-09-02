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
