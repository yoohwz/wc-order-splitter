# Engineering workflow

WOS keeps product assurance proportional to the changed paths. GitHub owns merge
policy. CI does not interpret Issues, review prose, approval comments or rulesets.

## Create, Run, Finalize

1. **ChatGPT — `Create <TASK_ID>`:** resolve accepted source and record one Issue
   with scope, invariants, evidence, review needs and completion criteria.
2. **Codex — `Run <TASK_ID>`:** read the Issue and comments, inspect the current
   branch/PR/head and resume existing work. Implement on one branch, open a draft
   PR, run proportional local checks, and wait for native `Required CI`. Obtain
   fresh Independent Codex Review when required. Keep corrections in that PR.
   Report `READY_TO_FINALIZE: <TASK_ID>` with evidence; do not merge.
3. **ChatGPT — `Finalize <TASK_ID>`:** verify the exact current head, successful
   native CI, scope, product evidence and required independent review. Resolve
   review threads and perform Acceptance. The repository owner's explicit
   `Finalize` is conditional permission to squash-merge that unchanged accepted
   head. Mark the PR ready and attempt squash merge with the expected head.
   GitHub's ruleset/merge API makes the final enforcement decision. Confirm the
   actual merged commit; do not invent a second merge-policy engine.

`Continue`, `Review`, `Fix`, `Verify` and `Status` select the existing task; recover
its current state first. `Review` by the Executor is self-review. `Technical
Review <TASK_ID>` belongs in a fresh independent context, source read-only.
Direct/Quick requests follow this same normal flow; there is no special merge
bypass. If the task cannot be retrieved or is ambiguous, ask for its canonical
Issue before editing.

Independent review is required for CRITICAL product changes and changes to safety
controls (CI coverage, distribution or release validation, workflow/merge policy),
even when their path-selected CI is FAST. Review the complete diff and relevant
evidence, and record the result on the PR. ChatGPT checks the review during
Finalize; CI never parses it. A changed head requires the reviewer to assess the
correction and refresh the result. Bounded corrections remain in the same task.

## One Required CI

`.github/workflows/ci.yml` runs on every `pull_request` to `main`, including drafts.
It checks the native merge ref. The sole protected context is **Required CI** from
GitHub Actions. It runs even when dependencies fail and accepts only the expected
successful jobs (or the explicitly unused FAST runtime jobs). No normal PR needs
a dispatch, a special ready-for-review event or a machine approval comment.

`.github/scripts/select-ci-profile.py` uses this ordered path table; the highest
matching profile wins. Rename detection is disabled so both paths are checked.

| Paths | Profile | Evidence |
| --- | --- | --- |
| `.github/**`, `docs/**`, `tests/**`, `AGENTS.md`, `.gitignore`, `.wp-env.json`, `package.json`, `package-lock.json` | FAST | Workflow/shell/Python syntax, profile and distribution/digest contracts, product-suite inventory. No WooCommerce environment. |
| `css/**`, `languages/**`, `readme.txt`, `changelog.txt`, `inc/backend/class-wcos-premium-upsell.php`, `inc/backend/yoohw-woo-settings-tabs-reorder.php`, `js/post-action-tip.js` | STANDARD | FAST plus PHP 8.3 lint/unit, static product and JS tests, focused integration/sentinels in HPOS. |
| All other paths, including `.distignore`, entrypoint, remaining PHP/JS, domain, controllers, persistence, stock, money, recovery, migration and gates; empty/ambiguous input | CRITICAL | FAST plus PHP 7.4/8.1/8.3 lint/unit, product static/JS tests, critical integration union in legacy/HPOS/HPOS-sync, genuine legacy upgrade and HPOS concurrent workers. |

CRITICAL conservatively treats the transaction domains as coupled; it preserves
the accepted deep/financial/sentinel union. `tests/ci/integration-suites.tsv` lists
the profiles for each existing suite. `tests/runtime/` retains the product checks
formerly embedded in CI: production activation/gates, genuine 1.4.11 fixture,
lease exclusion and simultaneous strategy/Return/Bulk Return operations. The
Merge service runner invokes each crash and replay window in a separate process.

Local work runs focused checks from the active plugin worktree. The full storage
matrix belongs to CI unless reproducing a specific storage defect. Useful commands:

```sh
bash .github/scripts/run-fast.sh
bash .github/scripts/run-static.sh
bash .github/scripts/run-runtime.sh STANDARD hpos
```

## Product identity and release certification

`stage-distribution.sh SOURCE DESTINATION` applies the existing `.distignore` via
rsync. Both validation and hashing use that exact staged tree. `product-tree.py
STAGED_DIRECTORY` emits **PRODUCT_TREE_SHA**: SHA-256 over a version prefix and
byte-sorted relative file paths plus file bytes, each framed with an eight-byte
big-endian length. Links/special entries and an empty tree are rejected. Empty
directories, file modes and timestamps do not affect distributable file contents.
No ZIP is needed. `REPO_HEAD_SHA` records provenance, not product identity.

`.github/workflows/release-cert.yml` is the sole manual certification entry point.
Use it only after explicit release-candidate/freeze authority, with the frozen
`product_tree_sha` and selected revision. It verifies the digest, PHP 7.4/8.1/8.3,
WooCommerce 11.0.1 on runtime PHP 8.3 in all three storage modes, the complete
release inventory, genuine public 1.4.11 baseline
`e1d8aeb8eff38f4ce69dad1a08993e17521c6359`, concurrent workers and distribution
validation. **Artifacts=0:** logs and the run summary hold the certificate; there
is no archive upload, tag, publication or deployment. RELEASE_CERT never serves
as a normal PR profile and is not repeated on every CRITICAL PR.

A successful certificate remains product-valid across excluded repository-only
changes when PRODUCT_TREE_SHA is identical. A product change needs certification
of its new tree. Changes to tests may warrant new evidence if they expose a gap;
repository SHA drift alone does not invalidate the product certificate.

Merge permission never grants release permission. Version changes, package
creation, release freeze, tags, GitHub/WordPress.org publication and deployment
each need explicit task/release authority. WOS-REL-001 stays held until that
authority exists. WOS-COMPAT-007 resumes separately on accepted lean main;
preserve its genuine upgrade fixtures and certify the frozen product tree once.

## Operator handoff

Release-only preparation, Human-gated WordPress.org publication and read-only
recovery are documented in [releasing.md](releasing.md). These manual workflows
do not add normal PR merge requirements or replace RELEASE_CERT. Publisher
bootstrap changes excluded control-plane files only; publication still requires
its own owner authority and the production Environment gate.

Before any release Prepare dispatch, Codex must stage/validate the candidate and
run pinned-equivalent Plugin Check locally, then fix/classify findings and hand
off exact source/product identities plus raw, policy-accepted and blocking ERROR
counts. Normal CI, Independent Review and ChatGPT Acceptance follow; changed
product bytes need a fresh explicitly authorized RELEASE_CERT before publication
authority is rebound. GitHub Prepare remains the independent fail-closed check,
not the primary edit/test loop. See the exact WOS policy and local recipe in
[releasing.md](releasing.md); no Issue parsing or extra normal-PR merge gate is added.

End meaningful task-state responses with one navigation footer. It grants no
authority. Use `Human / ChatGPT / Finalize <TASK_ID>` only after evidence and
required review are ready; otherwise identify the actual next action or blocker.

```text
NEXT_ACTION_HINT
Who: <Human | ChatGPT | Codex | None>
Where: <ChatGPT | Codex | GitHub UI | None>
Command: <copy/paste command | None>
Expected: <outcome or missing authority>
```
