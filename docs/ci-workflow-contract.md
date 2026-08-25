# CI and Package Workflow Contract

## Verification boundaries

Normal implementation work uses the active WordPress Local plugin worktree and runs focused PHP, unit, integration, or browser evidence appropriate to the changed scope. The complete legacy, HPOS-only, and HPOS compatibility/sync matrix runs on pull requests targeting `main`; its `Required CI` job is the mechanical merge-authority status.

`push/main` runs only the lightweight `Main attestation`. It records the exact commit and tree SHA, verifies the code-owned production gates and gateway smoke contract, runs representative PHP/unit checks, and validates the distributable tree. It does not create or upload an installable archive.

Fast main attestation is safe only while repository rules require changes to `main` to enter through a pull request and require the canonical `Required CI` status. Human Gate remains a separate governance requirement. If those mechanical rules cannot be proven, stop with the task-defined branch-rules signal and do not merge a topology that removes the full `push/main` matrix.

## Exact-tree post-merge authority

Post-merge acceptance must bind all of the following:

1. the exact PR head/base and successful full PR CI;
2. independent Technical Acceptance and Human Gate for that exact head;
3. the resulting `main` merge SHA and expected parent/base;
4. equality between the merged commit tree SHA and the exact merge-candidate tree tested by PR CI;
5. successful `Main attestation` for that exact main SHA.

If exact tree equivalence cannot be proven, fail closed with `POST_MERGE_TREE_AUTHORITY_REQUIRED` and run the full verification workflow manually for the exact main revision. Do not infer equivalence from similar file lists or commit messages.

## Distribution and archive discipline

`.github/scripts/validate-distribution-contract.sh` is the canonical repository-owned distribution-tree policy. Normal CI and the manual package workflow both use it, so required and forbidden runtime path lists have one source of truth.

Normal CI validates only the distributable tree. It does not create a ZIP, checksum, or retained artifact. `.github/workflows/build-plugin.yml` is manual-only and adds archive integrity, deterministic rebuild, SHA-256, and upload checks after the shared tree contract passes.

Manual dispatch and a successful artifact build are evidence only. They never imply release freeze, release, publication, deployment, or Human Gate authority. A sandbox artifact requires explicit authority in its canonical task; a release candidate requires the separate release workflow authority.

## Future task authority block

New task Issues should reference stable repository contracts and bind only the task-specific authority delta: task ID/classification, exact source SHA, code-owned gate files plus the exact expected gate map, scope delta, verification profile, task-specific invariants, stop signals, and independent-review/Human-Gate boundary.
