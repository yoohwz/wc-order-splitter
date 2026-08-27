# CI and Package Workflow Contract

## Verification boundaries

Normal implementation work uses the active WordPress Local plugin worktree and runs focused PHP, unit, integration, or browser evidence appropriate to the changed scope. The complete legacy, HPOS-only, and HPOS compatibility/sync matrix runs on pull requests targeting `main`; its `Required CI` job is the mechanical merge-authority status.

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
