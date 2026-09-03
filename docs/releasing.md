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

## Local-first Plugin Check preflight (mandatory)

The release sequence is: **Codex stages and checks locally -> fixes/classifies
locally -> exact-head CI and Independent Review -> ChatGPT Acceptance -> fresh
RELEASE_CERT when product bytes changed -> GitHub Prepare independent check ->
separately authorized publication**. GitHub Actions is not the primary discovery
or fix loop, and local evidence never replaces the independent GitHub gate.

Before requesting Prepare:

1. Confirm the exact active WordPress Local worktree, branch/head, clean starting
   state, WordPress root, Local PHP 8.3 binary and installed checker/dependencies.
   Use Plugin Check **2.1.0** and WooCommerce **11.0.1**. Align local test tooling
   if needed; do not alter production or load a legacy handler to pass a check.
2. Stage that worktree with `stage-distribution.sh` / `.distignore`, validate with
   `validate-distribution-contract.sh`, and record `PRODUCT_TREE_SHA`. Do not
   create an installable ZIP for local diagnosis. Do not check the unfiltered
   repository and mistake test/development-file findings for payload findings.
3. Use the existing Local core/checker with a scratch content directory containing
   the staged `plugins/wc-order-splitter` and links to the installed
   `plugins/plugin-check` and `plugins/woocommerce`. A process-local WP-CLI
   `--require` bootstrap may define `WP_CONTENT_DIR` and `WP_PLUGIN_DIR` for that
   scratch directory. Do not edit live wp-config, active plugins, or switch the
   active worktree to the public baseline. Inspect the checker's temporary `pc_`
   tables/drop-in before and after; preserve pre-existing data. Concurrent Local
   activity prevents claims of full database invariance. Use a quiet or separately
   isolated database before mutation-runtime certification.
4. Run the same raw check as Prepare, putting `plugin check` immediately after
   `wp` so Plugin Check's early CLI bootstrap recognizes the command. Substitute
   the confirmed Local PHP binary/socket and scratch-bootstrap paths:

   ```sh
   "$WOS_LOCAL_PHP83" -d "mysqli.default_socket=$WOS_LOCAL_MYSQL_SOCKET" /usr/local/bin/wp \
     plugin check wc-order-splitter --format=strict-json --mode=update \
     --slug=wc-order-splitter --fields=file,line,column,type,code,message,docs \
     --path="$WOS_LOCAL_WP_ROOT" --require="$WOS_LOCAL_CHECK_BOOTSTRAP" \
     --require="$WOS_LOCAL_WP_ROOT/wp-content/plugins/plugin-check/cli.php" \
     > "$WOS_LOCAL_CHECK_WORK/raw.json" 2> "$WOS_LOCAL_CHECK_WORK/stderr.txt"
   python3 .github/scripts/local-plugin-check-report.py \
     "$WOS_LOCAL_CHECK_WORK/raw.json" "$WOS_LOCAL_CHECK_WORK/diagnostics.json"
   ```

   The local report command uses exactly the shared trusted WOS policy also used
   by Prepare and downloaded-preparation verification. It needs no GitHub/SVN
   credential and fails nonzero for any blocking ERROR or invalid/missing report.
   WP-CLI's own zero exit code does **not** mean the report contains no ERRORs.
   Keep raw checking unfiltered: no `--exclude-checks`, baseline or severity hack.
5. Fix genuine defects locally and rerun after each coherent batch. Retain the
   public-baseline comparison, pre/post reports, exact command/tool versions,
   source SHA/product digest, warning rationales, and raw/policy/blocking counts
   in the task/PR handoff. Redact machine paths, credentials and private runtime
   data before posting evidence externally. Zero **blocking** ERRORs and no
   unresolved actionable warnings are required before the later Prepare request.
6. Complete native exact-head CI and fresh Independent Review, then ChatGPT
   Acceptance. Product edits invalidate the old certificate for the changed tree;
   obtain explicit candidate/freeze authority and a fresh RELEASE_CERT, then bind
   the new accepted candidate/digest/certificate to publication Human authority.
   A policy decision or merge permission alone does not authorize certification,
   Prepare, packaging or publication.

### Exact WOS Plugin Check policy

[WOS-REL-005 policy decision](https://github.com/yoohwz/wc-order-splitter/issues/147#issuecomment-5525485308)
authorizes only the full code
`WordPress.Security.EscapeOutput.ExceptionNotEscaped` as `policy_accepted` for
the pinned Plugin Check 2.1.0 path. Internal exception parameters include typed
Throwables, statuses and structured data, not just HTML output. Do not pre-escape
internal messages or add mass source ignores. This is a semantic class policy,
not an enumerated location/count baseline. Exact equality is mandatory: every
other ERROR, including `WordPress.Security.EscapeOutput.OutputNotEscaped`, blocks.

`release_plugin_check_policy.py` owns the classification shared by the local
reporter, Prepare and publisher artifact revalidation. Diagnostic schema 2 retains
every raw finding's upstream `type`, adds a separate `policy_classification`, and
exposes `raw_error_count`, `policy_accepted_error_count`, `blocking_error_count`
and `warning_count`. The legacy `error_count` remains the raw ERROR total. Status
`PASSED_WITH_POLICY_EXCEPTIONS` means the **WOS policy gate** passed; it never
claims upstream Plugin Check returned zero errors. Invalid reports use null
counts and still fail closed. Accepted findings remain in logs/step summaries and
in raw `plugin-check.json` inside an independently authenticated RC artifact.

The direct-access guard findings have no gate exception. One exact, documented
inline PHPCS ignore preserves `suppress_filters=true` in
`WCOS_Merge_Canonical_Reader::order_ids()`; no file-wide/global waiver applies.
Current warnings remain visible with the reviewed per-code rationale; any newly
actionable finding still requires remediation. PHP HTML escaping checks remain
enabled, and `tests/js/admin-error-output-contract.js` guards the six admin
JSON-to-text error boundaries (including negative raw-HTML sink fixtures) in FAST
and product static checks. Changing that safety contract requires review.

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

## First 1.5.0 publication — rebind authority after remediation

WOS-REL-005 changes product bytes. The original WOS-REL-001 inputs below are
**historical only**, not authorization to prepare or publish the modified tree:

- Candidate: `4de67108045714415d5bc4708bd94e7ad871e9a1` (not the later control-plane SHA).
- Version: `1.5.0`.
- `PRODUCT_TREE_SHA`: `2e118657e4b44d7db7e536c8e1a3054e9f9af6bcd6112d45141a6b30f427f072`.
- RELEASE_CERT: `33727158970`; public baseline
  `1.4.11/e1d8aeb8eff38f4ce69dad1a08993e17521c6359`.

Only resume this sequence after the local-first requirements, accepted exact head,
new certificate and explicit rebound WOS-REL-001 Human authority are present:

1. Run **Prepare WordPress.org Release Candidate** on current protected `main`
   with the newly authorized `candidate_sha`, `version`, `product_tree_sha` and
   `release_cert_run_id` (not the historical tuple above).
   Review `RC_PREPARED`, package/manifest identities and Plugin Check output.
   Plugin Check 2.1.0 checks the staged update without raw-check exclusions. The
   exact WOS policy above classifies all findings; every blocking ERROR stops
   preparation. Accepted ERRORs and warnings remain visible. Resolve new defects
   through an appropriately scoped task, never by silently changing certified
   product bytes or adding a broad ignore baseline.
   Failed runs retain sanitized findings in `plugin-check-diagnostics-<run_id>`
   (14 days), with exact Error counts/details in the log and step summary. This
   diagnostic-only JSON is never an RC artifact and cannot authorize publishing;
   raw checker output, credentials and runner environment are not uploaded.
   After a correction is accepted/merged, dispatch a new Prepare run with the
   correct newly bound inputs, not another attempt of the failed run. Classify visible Errors
   before resuming: product defects need a separate task/new product certificate;
   suspected false positives need explicit Human/Architecture review; checker
   integration defects need a separate bounded publisher correction.
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

The publisher contracts preserve the historical certified fixture and prove its
manifest rejects changed current product bytes. Current staging must bind its own
digest, not keep asserting equality with the old bootstrap product. No new
RELEASE_CERT is needed for excluded-only drift; included product-byte changes
need their own explicit product certification authority.

Architecture reference: the human-gated publisher in
[`yoohwz/thumbnail-manager`](https://github.com/yoohwz/thumbnail-manager).
Protocol references: [GitHub artifact API](https://docs.github.com/en/rest/actions/artifacts),
[WordPress.org SVN](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/),
[Release Confirmation](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/).
