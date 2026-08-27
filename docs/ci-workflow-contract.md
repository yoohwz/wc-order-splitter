# CI and Package Workflow Contract

## Verification boundaries

Normal implementation work uses the active WordPress Local plugin worktree and runs focused PHP, unit, integration, or browser evidence appropriate to the changed scope. Pull requests targeting `main` first run the repository-owned exact-diff classifier and receive one of two profiles: `DIRECT_CSS_FAST` or `FULL`. The exact protected context name remains `Required CI` and is the mechanical merge-authority status for both profiles.

`DIRECT_CSS_FAST` is available only when a `pull_request` has both a positive canonical direct branch signal matching `codex/direct/wos-direct-YYYYMMDD-HHMMSS` and a non-empty exact diff that modifies existing Git-tracked regular-text mode-`100644` `*.css` files beneath `css/` and nothing else. The branch signal is necessary but never sufficient. Classification executes the classifier object from the exact PR base; the bootstrap case where the base has no classifier selects `FULL`. Every path must have exact status `M`; additions, deletions, renames, copies, type/mode changes, symlinks, binary/LFS replacements, malformed authority, and non-PR events select `FULL`.

FAST is a deliberately conservative lexical safe-subset for simple presentation CSS, not a CSS parser or an entitlement. The exact resulting blobs of every changed CSS file are inspected without stripping comments, joining line continuations, or decoding escapes. Any backslash, CSS comment delimiter, non-canonical ASCII control byte, `@` marker, case-insensitive `url(...)` or `expression(...)` token, `://`, or `//` marker selects `FULL`; scan failure also selects `FULL`. Richer but legitimate CSS—including local `url(...)`, at-rules, comments, and escape syntax—therefore pays the normal FULL CI cost by design. Ordinary horizontal tabs and newline framing remain permitted.

The focused profile revalidates exact base/head and merge-candidate/tree authority, reruns the strict classifier, runs `git diff --check`, deterministic classifier/aggregator regressions, workflow topology assertions, and proves runtime, gate, distribution, workflow, version, and direct-governance objects unchanged. It creates and uploads no artifact. Hands-on visual evidence and fresh Independent Codex Review remain mandatory outside CI.

`FULL` remains the fail-closed default and retains PHP 7.4/8.1/8.3 syntax/unit contracts, architecture and production-gate contracts, package/distribution safety, and WooCommerce legacy/HPOS/HPOS-sync integration evidence. CI/governance/runtime/test/release/package changes—including changes to `.github/workflows/**`, classifier/aggregator scripts, tests, or this governance contract—therefore run the full matrix even when a branch is named like a direct branch. `Required CI` succeeds only for the exact expected success/skipped combination for the machine-selected profile; a missing classifier output, failed/cancelled job, or impossible combination fails closed.

On this user-owned repository, `Required CI` is the existing mechanical protected status from the GitHub Actions App; it is not a cryptographically distinct proof that one immutable workflow file produced the status. Repository code does not claim to solve same-App status-name spoofing for a malicious workflow-changing PR. Direct merge authority is instead multi-source: the candidate diff must mechanically exclude every CI/control-plane and non-CSS path, and Codex must authenticate the pre-edit direct authority, exact run identity/path/head, unchanged ruleset and review state, plus fresh exact-head Independent Technical Acceptance explicitly confirming direct eligibility and unchanged control-plane paths before recording `HUMAN_GATE_APPROVED_DIRECT`. Any drift fails closed. Workflow-changing PRs remain normal `FULL` work with ChatGPT Acceptance and conditional Human Gate.

`push/main` runs only the lightweight `Main attestation`. It records the exact commit and tree SHA, verifies the code-owned production gates and gateway smoke contract, runs representative PHP/unit checks, and validates the distributable tree. It does not create or upload an installable archive.

Fast main attestation is safe only while repository rules require changes to `main` to enter through a pull request and require the canonical `Required CI` status. Independent Codex Technical Review, ChatGPT Acceptance Review where applicable, and Human Gate remain separate governance requirements under `docs/engineering-review-authority.md`. The compressed `Finalize` command may coordinate Acceptance and the user's explicit conditional Human Gate, but it must record them separately and cannot merge after failed Acceptance or head/base/CI drift. The sole `TRIVIAL / CODEX_DIRECT` exception omits ChatGPT Acceptance only under `docs/codex-direct-workflow.md`; it still requires pre-edit `DIRECT_HUMAN_AUTHORIZED`, protected-branch CI, independently persisted exact-head direct eligibility, `HUMAN_GATE_APPROVED_DIRECT`, and exact-tree post-merge proof. If those mechanical rules cannot be proven, stop with the task-defined branch-rules signal and do not merge a topology that removes the full `push/main` matrix.

## Exact-tree post-merge authority

Post-merge acceptance must bind all of the following:

1. the exact PR head/base and successful full PR CI;
2. independent Codex `TECHNICAL_ACCEPTED`, ChatGPT `ACCEPTANCE_ACCEPTED` where applicable, and Human Gate for that exact unchanged head; an eligible direct task instead binds pre-edit `DIRECT_HUMAN_AUTHORIZED`, direct-eligibility-confirmed `TECHNICAL_ACCEPTED`, and `HUMAN_GATE_APPROVED_DIRECT`;
3. the resulting `main` merge SHA and expected parent/base;
4. equality between the merged commit tree SHA and the exact merge-candidate tree tested by PR CI;
5. successful `Main attestation` for that exact main SHA.

If exact tree equivalence cannot be proven, fail closed with `POST_MERGE_TREE_AUTHORITY_REQUIRED` and run the full verification workflow manually for the exact main revision. Do not infer equivalence from similar file lists or commit messages.

## Distribution and archive discipline

`.github/scripts/validate-distribution-contract.sh` is the canonical repository-owned distribution-tree policy. Normal CI and the manual package workflow both use it, so required and forbidden runtime path lists have one source of truth.

Normal CI validates only the distributable tree. It does not create a ZIP, checksum, or retained artifact. `.github/workflows/build-plugin.yml` is manual-only and adds archive integrity, deterministic rebuild, SHA-256, and upload checks after the shared tree contract passes.

Manual dispatch and a successful artifact build are evidence only. They never imply release freeze, release, publication, deployment, or Human Gate authority. A sandbox artifact requires explicit authority in its canonical task; a release candidate requires the separate release workflow authority.

`Finalize <TASK_ID>` may merge an accepted implementation or release-bookkeeping PR, but it never invokes the manual package workflow and never authorizes tagging, publishing, deploying, or uploading a public artifact. Release freeze and publication remain separate exact-SHA/artifact authorities.

## Future task authority block

New normal task Issues should reference stable repository contracts and bind only the task-specific authority delta: task ID/classification and `LOW`/`MEDIUM`/`HIGH` risk profile, exact source SHA, code-owned gate files plus the exact expected gate map, scope delta, verification profile, task-specific invariants, stop signals, and Independent Codex Technical Review / ChatGPT Acceptance Review / Human Gate boundary. Risk changes evidence depth, not protected-branch or authority requirements. A direct Issue is the narrow exception and must instead use the compact pre-edit authority block in `docs/codex-direct-workflow.md`; it cannot alter CI, package, release, or publication authority.
