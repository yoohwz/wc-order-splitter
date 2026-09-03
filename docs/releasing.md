# WordPress.org publisher

This is a release-only control plane, not a normal PR merge gate. Native
`Required CI` and ChatGPT Finalize remain authoritative for merging. Explicit
owner publication authority is still required. No workflow parses Issue comments.

## Trust and identity

The manual workflows run only from protected `main`; executable helpers and
actions come from that workflow's exact control SHA. The candidate is a separate
checkout used as non-executable data by the publisher. Only secret-free Prepare
may load the candidate in an isolated Plugin Check container; the control plane
is not mounted in that container. The publisher never loads candidate PHP or
executes candidate hooks/build scripts.

`stage-distribution.sh` and `product-tree.py` remain the sole staging/product
identity primitives. There is no second product certificate. Prepare authenticates
the supplied successful `release-cert.yml` run, its single GitHub Actions
`RELEASE_CERT` final check, exact digest/baseline log evidence and zero artifacts.
It also authenticates accepted source/native `Required CI`. Squash provenance
requires a merged main PR with equal reviewed-head/merge trees. The certificate
head need not equal the publication candidate: their staged product identities
must agree. All references must resolve to accepted protected-main history.

ZIPs use sorted paths, a single `wc-order-splitter/` root, fixed 1980 timestamps,
regular-file mode 0644 and STORE compression (no host zlib variation). Two builds
must be byte-identical. ZIP identity is separate from `PRODUCT_TREE_SHA`.
Manifest entries bind every path, size and SHA-256. Artifact downloads are hashed
against the actual GitHub API digest before safe extraction, not merely recorded.

## One-time Human setup

A repository administrator and WordPress.org plugin committer must configure:

1. Environment `wordpress-org-production` with required Human reviewer(s),
   prevention of self-review where supported, and a deployment branch policy
   allowing protected `main` only.
2. Environment variable `WPORG_SVN_USERNAME=yoohw` and Environment secret
   `WPORG_SVN_PASSWORD`, using the WordPress.org **SVN-specific password**. Enter
   the secret directly in GitHub. Never send it to ChatGPT/Codex or put it in
   source, PRs, Issues, workflow inputs, artifacts, summaries or logs.
3. An active numeric release-tag ruleset (at minimum `1.5.0`) allowing authorized
   creation while preventing updates, force updates and deletion. Do not weaken
   `Protect main` or add bypass actors to make publication pass.
4. Verify the plugin's WordPress.org Release Management setting. Until a committer
   records the actual observation in `.github/release/wporg-policy.json`, its
   mode is `unknown` with no invented observation timestamp. `assets_mode` stays
   `unchanged`. Policy changes require their own appropriate review/authority.

The SVN password is referenced in exactly one step: the atomic production commit.
It is supplied on stdin, not a process argument, and is never cached. Dry-run and
verify-only jobs have no Environment or SVN credential. The separate GitHub
Release job also requires the production Environment, but receives no SVN secret.

## First 1.5.0 publication after publisher bootstrap is accepted

WOS-REL-002 implements and tests these controls only. Do not run the new manual
workflows, create `1.5.0`, or publish as part of that implementation task.
After Finalize/merge, use the existing WOS-REL-001 publication authority:

- Candidate: `4de67108045714415d5bc4708bd94e7ad871e9a1` (not the later control-plane SHA).
- Version: `1.5.0`.
- `PRODUCT_TREE_SHA`: `2e118657e4b44d7db7e536c8e1a3054e9f9af6bcd6112d45141a6b30f427f072`.
- RELEASE_CERT: `33727158970`; public baseline
  `1.4.11/e1d8aeb8eff38f4ce69dad1a08993e17521c6359`.

1. Run **Prepare WordPress.org Release Candidate** on current protected `main`
   with `candidate_sha`, `version`, `product_tree_sha`, `release_cert_run_id` above.
   Review `RC_PREPARED`, package/manifest identities and Plugin Check output.
   Plugin Check 2.1.0 checks the staged update without an error-ignore list; every
   Error blocks preparation. Warnings remain in the evidence. Resolve new Errors
   through an appropriately scoped task, never by silently changing certified
   product bytes or adding a broad ignore baseline.
2. Run **Publish Order Splitter to WordPress.org**, `operation=publish`, using
   that `preparation_run_id`, candidate/version, and `dry_run=true`. Review the
   exact read-only SVN snapshot and final recheck. No tag, SVN or Release write
   is reachable on this path.
3. After confirming one-time setup and existing owner authority, dispatch a new
   publish run with `dry_run=false`. Its new preflight artifact is the approval
   target. Approve the Environment only when candidate, product/package digests,
   trunk/assets node revisions/trees, tag absence and confirmation mode match.
4. The approved job reauthenticates preparation/candidate data, performs a fresh
   SVN checkout, compares the approved relevant-path snapshot, restages, seals
   the annotated immutable Git tag, rechecks SVN again and attempts exactly one
   atomic commit of `trunk` plus new `tags/<version>`. Assets cannot change.
5. Fresh read-only verification authenticates the exact SVN author, revision,
   traceability message, atomic changed-path set, trunk/tag product identity and
   unchanged assets. `unknown` or `enabled` confirmation stops at
   `WPORG_RELEASE_CONFIRMATION_PENDING`. A plugin committer confirms through
   WordPress.org Release Management/email as needed. Never store tokenized
   confirmation URLs or emails in GitHub.
6. Run `operation=verify-only` with the original production publish run ID.
   `dry_run=true` performs verification only; `dry_run=false` additionally permits
   the separately Environment-gated GitHub Release job after public verification.
   Verify-only never receives the SVN password and never commits SVN.
7. Only `WPORG_PUBLIC_RELEASE_VERIFIED` permits GitHub Release creation. The
   release job rechecks remote identities after its approval, binds the existing
   immutable tag and attaches only the authenticated ZIP and manifest. Notes
   come from the public version's `changelog.txt` entry, not governance prose.
   ChatGPT performs post-publication acceptance and closes WOS-REL-001 separately.

## Pending and recovery states

- `WPORG_RELEASE_CONFIRMATION_PENDING`: SVN publication is authenticated; Human
  confirmation may be needed. Confirm there, then verify-only. No recommit.
- `WPORG_PROPAGATION_PENDING`: one bounded public API/download observation is not
  ready. Use a new verify-only run later; do not treat this as an SVN failure.
- `SVN_COMMIT_OUTCOME_UNKNOWN`: no automatic retry, even after a timeout or lost
  successful response. Verify-only authenticates the original immutable preflight,
  tag and exact SVN log/tree identity; it reconciles a durable publication record
  if present or reconstructs it read-only if the original runner lost the record.
- Existing SVN target tag: publish preflight fails closed. Use the original run's
  verify-only recovery, not a fresh publish attempt.
- Existing Git tag: only the exact annotated candidate/product/package/preparation
  identity is idempotent. A mismatch never triggers update/deletion/recreation.
- Existing GitHub Release: exact draft state/assets can be completed; unexpected
  notes, tag, asset bytes/names or incomplete public Release require Human review.
- SVN trunk/assets content, properties or node-revision drift invalidates approval.
  WordPress.org's global revision is recorded, but unrelated plugin commits do
  not invalidate the relevant-path snapshot. Post-publication payload mismatch
  is a hard verification failure, not a reason to recommit.
- Preparation/preflight/publication artifacts are immutable and retained 90 days.
  Missing/expired artifact or certificate logs fail closed; do not substitute an
  unverified local file. Runs cannot be rerun under the same run ID/attempt;
  dispatch a new run with the correct operation and original publication ID.

## Local checks and boundaries

`python3 tests/ci/publisher-contract.py` uses temporary `file://` SVN repositories
and fake GitHub APIs, including deterministic packaging and negative security
cases. It never accesses production SVN, creates a real Git tag/Release or reads
production secrets. The normal FAST runner includes these checks. Independent
review is required even though all publisher paths are excluded development paths.

Bootstrap stages both accepted source and current worktree with the canonical
stager and proves the certified digest is unchanged. No new RELEASE_CERT is
needed for excluded-only drift. Any included product-byte change requires scope
review and its own product certification authority.

Architecture reference: the human-gated publisher in
[`yoohwz/thumbnail-manager`](https://github.com/yoohwz/thumbnail-manager).
Protocol references: [GitHub artifact API](https://docs.github.com/en/rest/actions/artifacts),
[WordPress.org SVN](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/),
[Release Confirmation](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/).
