'use strict';

// Read-only post-Gate verification for Finalize. This never merges, creates
// authority, or changes a check/status. The native running-job path is separate.
const { execFileSync, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { isDeepStrictEqual } = require('node:util');
const { verifySnapshot, collectLive, liveApi, paginate, resolveTask, selectGate, issueField, rawField } = require('./merge-candidate-authority');

const REPO = 'yoohwz/wc-order-splitter';
const need = (value, label) => { if (!value) throw new Error(`terminal-readiness-error: ${label}`); };
const equal = (actual, expected, label) => need(isDeepStrictEqual(actual, expected), label);
const id = value => Number.isSafeInteger(value) && value > 0;
const date = value => { const time = Date.parse(value); need(Number.isFinite(time), 'missing/invalid timestamp'); return time; };
const passed = record => record.status === 'completed' && ['success', 'neutral', 'skipped'].includes(record.conclusion);

function parseAttestation(log) {
  // Match actual timestamped output, never echoed shell source or a substring.
  const matches = log.split('\n').map(line => /^(\d{4}-\d\d-\d\dT[0-9:.]+Z) native-merge-authority-verified (\{.*\})\r?$/.exec(line)).filter(Boolean);
  need(matches.length === 1, 'one native candidate-bound log attestation');
  return { at: matches[0][1], value: JSON.parse(matches[0][2]) };
}

function verifyTerminalSnapshot(s, input) {
  const attestation = verifySnapshot(s, input, 'completed');
  need(['clean', 'unstable'].includes(s.pr.mergeable_state), 'unsupported/blocked aggregate merge state');
  equal(s.rules.bypass_actors, [], 'owner-visible empty bypass actors');
  equal(s.classicProtectionAbsent, true, 'unknown/additional classic branch protection');
  const normalized = s.activeRules.map(rule => {
    equal(rule.ruleset_id, s.rules.id, 'additional active ruleset');
    equal(rule.ruleset_source_type, 'Repository', 'active ruleset source type');
    equal(rule.ruleset_source, REPO, 'active ruleset source');
    const { ruleset_id, ruleset_source_type, ruleset_source, ...body } = rule;
    return body;
  });
  const sortRules = rules => [...rules].sort((a, b) => a.type.localeCompare(b.type));
  equal(sortRules(normalized), sortRules(s.rules.rules), 'all active branch requirements match pinned ruleset');
  const decision = s.prDecision;
  equal(decision.headRefOid, input.head, 'review decision head');
  equal(decision.baseRefOid, s.pr.base.sha, 'review decision base');
  equal(decision.state, 'OPEN', 'review decision open PR');
  equal(decision.isDraft, false, 'review decision ready PR');
  equal(decision.mergeable, 'MERGEABLE', 'review decision mergeability');
  equal(decision.mergeStateStatus, s.pr.mergeable_state.toUpperCase(), 'consistent aggregate state');
  need(decision.reviewDecision === null || decision.reviewDecision === 'APPROVED', 'outstanding required review decision');
  // verifySnapshot pins count=0, no code-owner/last-push/required-reviewer rules;
  // GraphQL must still report no outstanding review requirement or rejection.
  equal(s.bridgeSuite.status, 'completed', 'completed native suite');
  equal(s.bridgeSuite.conclusion, 'success', 'successful native suite');
  need(s.bridgeCheck.pull_requests?.some(pr => String(pr.number) === input.pr &&
    pr.head.sha === input.head && pr.base.sha === s.pr.base.sha), 'native check PR association');
  const jobs = s.bridgeJobs.filter(job => job.name === 'Required CI');
  need(jobs.length === 1 && jobs[0].id === s.bridgeCheck.id && jobs[0].run_id === s.bridge.id &&
    jobs[0].status === 'completed' && jobs[0].conclusion === 'success', 'exact completed native job');
  const logged = parseAttestation(s.bridgeLog);
  equal(logged.value, attestation, 'live authority differs from native candidate attestation');
  need(date(logged.at) >= date(s.bridgeCheck.started_at) && date(logged.at) <= date(s.bridgeCheck.completed_at), 'attestation within native check lifetime');
  need(date(s.bridgeCheck.started_at) >= date(input.context.readyAt), 'native check follows current ready event');

  const required = s.rules.rules.find(rule => rule.type === 'required_status_checks').parameters.required_status_checks;
  const requiredNames = new Set(required.map(rule => rule.context));
  const requiredApps = new Set(required.map(rule => rule.integration_id));
  equal(s.inventory.map(entry => entry.sha), [input.head, s.pr.merge_commit_sha], 'both head/candidate inventories');
  const diagnostics = [], history = [];
  for (const inventory of s.inventory) {
    need(Array.isArray(inventory.checks) && Array.isArray(inventory.suites) && Array.isArray(inventory.statuses), 'complete check/status inventory');
    // Candidate-native checks are represented by the PR head plus the verified
    // event attestation. Extra candidate statuses can change GitHub's evaluated
    // commit; reject that unproven topology instead of borrowing head success.
    if (inventory.sha !== input.head) {
      need(!inventory.checks.length && !inventory.suites.length && !inventory.statuses.length, 'ambiguous candidate-specific status authority');
      continue;
    }
    const suites = new Map();
    for (const suite of inventory.suites) {
      need(id(suite.id) && !suites.has(suite.id) && id(suite.app?.id), 'unique identified suite');
      equal(suite.head_sha, input.head, 'suite head');
      suites.set(suite.id, suite);
    }
    const checks = new Map();
    for (const check of inventory.checks) {
      need(id(check.id) && !checks.has(check.id) && typeof check.name === 'string' && check.name.length > 0, 'unique named check');
      equal(check.head_sha, input.head, 'inventory check head');
      const suite = suites.get(check.check_suite?.id);
      need(suite && id(check.app?.id) && check.app.id === suite.app.id, 'check/suite integration identity');
      checks.set(check.id, check);
      if (requiredNames.has(check.name)) {
        need(required.some(rule => rule.context === check.name && rule.integration_id === check.app.id), 'required context from wrong app');
        if (check.id === s.bridgeCheck.id) {
          equal(check, s.bridgeCheck, 'exact post-Gate check inventory');
        } else {
          // Only completed pre-Gate history is superseded, never a competing
          // post-Gate pending/failed/green check chosen merely by its name.
          need(check.status === 'completed' && date(check.completed_at) < date(s.gate.created_at), 'competing current required context');
          history.push({ kind: 'check', id: check.id, name: check.name, app: check.app.id, conclusion: check.conclusion });
        }
      } else if (!passed(check)) {
        diagnostics.push({ kind: 'check', id: check.id, name: check.name, app: check.app.id, status: check.status, conclusion: check.conclusion });
      }
    }
    for (const requirement of required) {
      const current = checks.get(s.bridgeCheck.id);
      need(current && current.name === requirement.context && current.app.id === requirement.integration_id &&
        current.status === 'completed' && current.conclusion === 'success', 'missing/non-success enforced context');
    }
    for (const suite of suites.values()) {
      const members = [...checks.values()].filter(check => check.check_suite.id === suite.id);
      need(Number.isInteger(suite.latest_check_runs_count) && suite.latest_check_runs_count >= 0 &&
        members.length >= suite.latest_check_runs_count, 'incomplete suite check inventory');
      if (!passed(suite)) {
        // A queued empty suite from an app outside every required integration
        // (e.g. Render) cannot represent the pinned required context. Unknown
        // pending work from a required app remains ambiguous and fails closed.
        need(!requiredApps.has(suite.app.id) || (suite.status === 'completed' && members.length > 0), 'unresolved required-app suite');
        diagnostics.push({ kind: 'suite', id: suite.id, app: suite.app.id, status: suite.status, conclusion: suite.conclusion,
          scope: requiredApps.has(suite.app.id) ? 'enumerated-optional-or-superseded-checks' : 'non-required-integration' });
      }
    }
    const latestStatuses = new Map();
    for (const status of inventory.statuses) {
      need(id(status.id) && typeof status.context === 'string' && status.context.length > 0 &&
        ['error', 'failure', 'pending', 'success'].includes(status.state), 'identified commit status');
      date(status.created_at);
      // A legacy status with a required name also participates in protection;
      // REST cannot authenticate its expected integration. Never ignore it.
      need(!requiredNames.has(status.context), 'ambiguous legacy required status');
      const current = latestStatuses.get(status.context);
      if (!current || status.id > current.id) latestStatuses.set(status.context, status);
    }
    for (const status of latestStatuses.values()) {
      if (status.state !== 'success') diagnostics.push({ kind: 'status', id: status.id, name: status.context, state: status.state });
    }
  }
  if (s.pr.mergeable_state === 'unstable') need(diagnostics.length > 0 || history.some(check => check.conclusion !== 'success'), 'unexplained unstable rollup');
  const sort = records => records.sort((a, b) => a.kind.localeCompare(b.kind) || a.id - b.id);
  return { ...attestation, terminal: 'ruleset-aware-v1', mergeableState: s.pr.mergeable_state,
    requiredContexts: required, diagnosticNonRequired: sort(diagnostics), supersededPreGateChecks: sort(history) };
}

async function verifyTerminal(input, collect) {
  const result = verifyTerminalSnapshot(await collect(), input);
  equal(verifyTerminalSnapshot(await collect(), input), result, 'terminal authority or inventory drift');
  return result;
}

function terminalSource(input, base, baseOwnsHelper) {
  if (baseOwnsHelper) return base;
  equal([input.repo, input.task, input.issue, input.pr, base, input.profile, input.assurance, input.reviewFloor],
    [REPO, 'WOS-GOV-011', '134', '135', '545b82b452adfc4d43fd4744f3f83d7a8f5e68fb', 'HIGH_FINANCIAL', 'HIGH', 'REQUIRED'], 'terminal source-bound bootstrap');
  return input.head;
}

function classicProtectionAbsent() {
  const result = spawnSync('gh', ['api', `repos/${REPO}/branches/main/protection`], { encoding: 'utf8', timeout: 30000 });
  // Only the authenticated endpoint's specific absence response is acceptable.
  // Permission/network failures and a present extra policy are not absence.
  if (result.error) throw result.error;
  const response = JSON.parse(result.stdout);
  return result.status === 1 && response.status === '404' && response.message === 'Branch not protected';
}

function collectTerminal(input) {
  const s = collectLive(input, 'completed');
  s.activeRules = paginate(`repos/${REPO}/rules/branches/main?per_page=100`);
  s.classicProtectionAbsent = classicProtectionAbsent();
  const result = liveApi('graphql', 'POST', { query: 'query($pr:Int!){repository(owner:"yoohwz",name:"wc-order-splitter"){pullRequest(number:$pr){state isDraft headRefOid baseRefOid mergeable mergeStateStatus reviewDecision}}}', variables: { pr: Number(input.pr) } });
  need(!result.errors, 'PR decision query');
  s.prDecision = result.data.repository.pullRequest;
  s.bridgeLog = execFileSync('gh', ['api', `repos/${REPO}/actions/jobs/${s.bridgeCheck.id}/logs`, '--allow-escape-sequences'], { encoding: 'utf8', timeout: 30000, maxBuffer: 16 * 1024 * 1024 });
  s.inventory = [input.head, s.pr.merge_commit_sha].map(sha => ({ sha,
    checks: paginate(`repos/${REPO}/commits/${sha}/check-runs?filter=all&per_page=100`, 'check_runs'),
    suites: paginate(`repos/${REPO}/commits/${sha}/check-suites?per_page=100`, 'check_suites'),
    statuses: paginate(`repos/${REPO}/commits/${sha}/statuses?per_page=100`) }));
  return s;
}

async function main(args) {
  const [prNumber, head, run] = args;
  need(args.length === 3 && /^[1-9][0-9]*$/.test(prNumber) && /^[0-9a-f]{40}$/.test(head) && /^[1-9][0-9]*$/.test(run), 'usage: PR EXACT_HEAD POST_GATE_NATIVE_RUN');
  const pr = liveApi(`repos/${REPO}/pulls/${prNumber}`);
  equal(pr.head.sha, head, 'requested head');
  need(/^[0-9a-f]{40}$/.test(pr.base.sha), 'exact base');
  const task = resolveTask(pr);
  const issue = liveApi(`repos/${REPO}/issues/${task.issue}`);
  resolveTask(pr, issue);
  const input = { repo: REPO, ...task, pr: prNumber, head, profile: issueField(issue.body, 'CI profile floor'),
    assurance: issueField(issue.body, 'Assurance floor'), reviewFloor: issueField(issue.body, 'Independent review floor') };
  const gate = selectGate(paginate(`repos/${REPO}/issues/${task.issue}/comments?per_page=100`), input);
  input.gate = `issue-comment:${gate.id}`;
  const review = rawField(gate, 'PRE_REVIEW authority');
  input.preReview = review === 'none' ? '' : review;
  const ready = paginate(`repos/${REPO}/issues/${prNumber}/timeline?per_page=100`).filter(event =>
    event.event === 'ready_for_review' && date(event.created_at) >= date(gate.created_at));
  need(ready.length === 1, 'one current post-Gate ready event');
  input.context = { pr: prNumber, head, base: pr.base.sha, candidate: pr.merge_commit_sha,
    ref: `refs/pull/${prNumber}/merge`, readyAt: ready[0].created_at, run: Number(run) };
  // Consume accepted-base helpers normally; only the exact reviewed GOV-011
  // bootstrap may execute head-owned terminal/native helpers.
  const helperPath = '.github/scripts/verify-terminal-merge-readiness.js';
  const source = terminalSource(input, pr.base.sha, spawnSync('git', ['cat-file', '-e', `${pr.base.sha}:${helperPath}`]).status === 0);
  for (const file of ['verify-terminal-merge-readiness.js', 'merge-candidate-authority.js']) {
    equal(fs.readFileSync(path.join(__dirname, file)), execFileSync('git', ['show', `${source}:.github/scripts/${file}`]), 'trusted helper source');
  }
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'wcos-terminal-'));
  try {
    for (const [variable, file] of Object.entries({ WCOS_BASE_CLASSIFIER: 'classify-pr-scope.sh', WCOS_PRE_REVIEW_VERIFIER: 'verify-pre-review-authority.sh', WCOS_PRE_REVIEW_VALIDATOR: 'validate-pre-review-record.sh' })) {
      const target = path.join(temp, file);
      fs.writeFileSync(target, execFileSync('git', ['show', `${pr.base.sha}:.github/scripts/${file}`]), { mode: 0o700 });
      process.env[variable] = target;
    }
    const result = await verifyTerminal(input, () => collectTerminal(input));
    console.log(`terminal-merge-readiness-verified ${JSON.stringify(result)}`);
  } finally { fs.rmSync(temp, { recursive: true, force: true }); }
}

if (require.main === module) main(process.argv.slice(2)).catch(error => { console.error(error.message); process.exitCode = 1; });
module.exports = { parseAttestation, verifyTerminalSnapshot, verifyTerminal, collectTerminal, terminalSource };
