'use strict';

const assert = require('node:assert/strict');
const { verifySnapshot } = require('../../.github/scripts/merge-candidate-authority');
const { parseAttestation, verifyTerminalSnapshot, verifyTerminal, terminalSource } = require('../../.github/scripts/verify-terminal-merge-readiness');

// Reuse the native authority fixture: the terminal path must authenticate the
// same independent review, FINAL, Acceptance and Gate before inspecting rollup.
module.exports = async function terminalContracts(nativeFixture, input) {
  const clone = value => JSON.parse(JSON.stringify(value));
  const time = second => `2026-09-02T08:00:${String(second).padStart(2, '0')}Z`;
  let positive = 0, negative = 0;
  function fixture() {
    const s = nativeFixture();
    Object.assign(s.bridge, { status: 'completed', conclusion: 'success' });
    Object.assign(s.bridgeSuite, { status: 'completed', conclusion: 'success', latest_check_runs_count: 1 });
    Object.assign(s.bridgeCheck, { status: 'completed', conclusion: 'success', started_at: time(25), completed_at: time(29), pull_requests: clone(s.bridge.pull_requests) });
    s.bridgeJobs = [{ id: s.bridgeCheck.id, run_id: s.bridge.id, name: 'Required CI', status: 'completed', conclusion: 'success' }];
    s.activeRules = s.rules.rules.map(rule => ({ ...clone(rule), ruleset_id: s.rules.id, ruleset_source_type: 'Repository', ruleset_source: input.repo }));
    s.classicProtectionAbsent = true;
    s.prDecision = { headRefOid: input.head, baseRefOid: s.pr.base.sha, state: 'OPEN', isDraft: false,
      mergeable: 'MERGEABLE', mergeStateStatus: 'CLEAN', reviewDecision: null };
    s.bridgeLog = `${time(28)} native-merge-authority-verified ${JSON.stringify(verifySnapshot(s, input, 'completed'))}\n`;
    const finalSuite = { id: s.final.check_suite_id, app: { id: 15368 }, head_sha: input.head, status: 'completed', conclusion: 'success', latest_check_runs_count: 1 };
    s.inventory = [{ sha: input.head, checks: [{ ...clone(s.finalCheck), completed_at: time(20) }, clone(s.bridgeCheck)],
      suites: [finalSuite, clone(s.bridgeSuite)], statuses: [] }, { sha: s.candidate.sha, checks: [], suites: [], statuses: [] }];
    return s;
  }
  const unstable = s => { s.pr.mergeable_state = 'unstable'; s.prDecision.mergeStateStatus = 'UNSTABLE'; };
  const optional = (s, status = 'queued', conclusion = null, app = 14658) => {
    s.inventory[0].suites.push({ id: 90, app: { id: app }, head_sha: input.head, status, conclusion, latest_check_runs_count: 1 });
    s.inventory[0].checks.push({ id: 14, name: 'Preview deployment', app: { id: app }, head_sha: input.head,
      check_suite: { id: 90 }, status, conclusion });
  };
  const historicalFailure = s => {
    s.inventory[0].suites.push({ id: 91, app: { id: 15368 }, head_sha: input.head, status: 'completed', conclusion: 'failure', latest_check_runs_count: 1 });
    s.inventory[0].checks.push({ id: 15, name: 'Required CI', app: { id: 15368 }, head_sha: input.head,
      check_suite: { id: 91 }, status: 'completed', conclusion: 'failure', completed_at: time(22) });
  };
  const accept = (label, change = () => {}) => {
    const s = fixture(); change(s);
    const result = verifyTerminalSnapshot(s, input);
    assert.equal(result.nativeCheck, s.bridgeCheck.id, label);
    assert.equal(result.terminal, 'ruleset-aware-v1', label);
    positive++;
    return result;
  };
  const reject = (label, change) => {
    const s = fixture(); change(s);
    assert.throws(() => verifyTerminalSnapshot(s, input), /(?:merge-authority|terminal-readiness)-error/, label);
    negative++;
  };
  accept('clean with complete enforced authority');
  accept('unstable optional queued', s => { unstable(s); optional(s); });
  accept('unstable optional failure', s => { unstable(s); optional(s, 'completed', 'failure'); });
  accept('unstable enumerated optional Actions failure', s => { unstable(s); optional(s, 'completed', 'failure', 15368); });
  accept('optional Actions check pending in already successful suite', s => {
    unstable(s); optional(s, 'queued', null, 15368); Object.assign(s.inventory[0].suites.at(-1), { status: 'completed', conclusion: 'success' });
  });
  const render = accept('Render-like empty queued suite is non-required', s => {
    unstable(s);
    s.inventory[0].suites.push({ id: 90, app: { id: 14658 }, head_sha: input.head, status: 'queued', conclusion: null, latest_check_runs_count: 0 });
  });
  assert.equal(render.diagnosticNonRequired[0].scope, 'non-required-integration');
  const historical = accept('historical pre-Gate failure cannot override exact post-Gate success', s => { unstable(s); historicalFailure(s); });
  assert(historical.supersededPreGateChecks.some(c => c.id === 15 && c.conclusion === 'failure'));
  accept('optional legacy commit status pending', s => {
    unstable(s); s.inventory[0].statuses.push({ id: 99, context: 'Preview', state: 'pending', created_at: time(25) });
  });
  accept('old optional failure superseded by newer optional status', s => {
    s.inventory[0].statuses.push({ id: 99, context: 'Preview', state: 'failure', created_at: time(25) },
      { id: 100, context: 'Preview', state: 'success', created_at: time(26) });
  });
  for (const state of ['blocked', 'behind', 'dirty', 'draft', 'unknown', 'has_hooks', '', null]) {
    reject(`aggregate ${state}`, s => { s.pr.mergeable_state = state; s.prDecision.mergeStateStatus = state?.toUpperCase(); });
  }
  for (const mergeable of [false, null]) reject('mergeability not established', s => { s.pr.mergeable = mergeable; });
  for (const state of ['pending', 'queued', 'in_progress']) {
    reject(`unstable required ${state}`, s => { unstable(s); Object.assign(s.bridgeCheck, { status: state, conclusion: null }); });
  }
  for (const conclusion of ['failure', 'cancelled', 'neutral', 'skipped', null]) {
    reject(`required non-success ${conclusion}`, s => { unstable(s); s.bridgeCheck.conclusion = conclusion; });
  }
  for (const [label, change] of [
    ['missing required check', s => { s.inventory[0].checks = s.inventory[0].checks.filter(c => c.id !== s.bridgeCheck.id); }],
    ['wrong native app', s => { s.bridgeCheck.app.id = 14658; }],
    ['foreign check with enforced name', s => { optional(s); s.inventory[0].checks.at(-1).name = 'Required CI'; }],
    ['completed phase cannot accept running native run', s => { s.bridge.status = 'in_progress'; }],
    ['native run failure', s => { s.bridge.conclusion = 'failure'; }],
    ['native rerun', s => { s.bridge.run_attempt++; }],
    ['native run wrong PR', s => { s.bridge.pull_requests[0].number++; }],
    ['native check wrong PR', s => { s.bridgeCheck.pull_requests[0].number++; }],
    ['native suite failed', s => { s.bridgeSuite.conclusion = 'failure'; }],
    ['native suite pending', s => { s.bridgeSuite.status = 'queued'; }],
    ['duplicate native required job', s => { s.bridgeJobs.push(clone(s.bridgeJobs[0])); }],
    ['wrong native job identity', s => { s.bridgeJobs[0].id++; }],
    ['native job not successful', s => { s.bridgeJobs[0].conclusion = 'failure'; }],
    ['head drift', s => { s.pr.head.sha = 'e'.repeat(40); }],
    ['base drift', s => { s.pr.base.sha = 'e'.repeat(40); }],
    ['candidate regeneration', s => { s.pr.merge_commit_sha = 'e'.repeat(40); }],
    ['main drift', s => { s.main.object.sha = 'e'.repeat(40); }],
    ['unresolved thread', s => { s.threads++; }],
    ['draft', s => { s.pr.draft = true; }],
    ['ruleset revision drift', s => { s.rules.updated_at = time(1); }],
    ['bypass', s => { s.rules.bypass_actors.push({ actor_id: 1 }); }],
    ['hidden owner bypass', s => { delete s.rules.bypass_actors; }],
    ['additional active ruleset', s => { s.activeRules.push({ type: 'required_signatures', ruleset_id: 99, ruleset_source_type: 'Repository', ruleset_source: input.repo }); }],
    ['incomplete active rules', s => { s.activeRules.pop(); }],
    ['unknown classic protection', s => { delete s.classicProtectionAbsent; }],
    ['additional classic protection', s => { s.classicProtectionAbsent = false; }],
    ['outstanding PR review', s => { s.prDecision.reviewDecision = 'REVIEW_REQUIRED'; }],
    ['changes requested', s => { s.prDecision.reviewDecision = 'CHANGES_REQUESTED'; }],
    ['inconsistent GraphQL head', s => { s.prDecision.headRefOid = 'e'.repeat(40); }],
    ['inconsistent GraphQL mergeability', s => { s.prDecision.mergeable = 'UNKNOWN'; }],
    ['inconsistent rollup', s => { s.prDecision.mergeStateStatus = 'UNSTABLE'; }],
    ['missing native attestation', s => { s.bridgeLog = ''; }],
    ['duplicate native attestation', s => { s.bridgeLog += s.bridgeLog; }],
    ['echoed shell source not an attestation', s => { s.bridgeLog = `echo '${s.bridgeLog.trim()}'`; }],
    ['wrong logged candidate', s => { s.bridgeLog = s.bridgeLog.replace(s.candidate.sha, 'e'.repeat(40)); }],
    ['log outside check lifetime', s => { s.bridgeLog = s.bridgeLog.replace(time(28), time(30)); }],
    ['check predates current ready event', s => { s.bridgeCheck.started_at = time(20); }],
    ['record changed since native verification', s => { s.acceptance.id = 200; }],
    ['candidate-specific check', s => { s.inventory[1].checks.push(clone(s.bridgeCheck)); }],
    ['candidate-specific suite', s => { s.inventory[1].suites.push(clone(s.bridgeSuite)); }],
    ['candidate-specific status', s => { s.inventory[1].statuses.push({ id: 99, context: 'Preview', state: 'pending', created_at: time(25) }); }],
    ['missing candidate inventory', s => { s.inventory.pop(); }],
    ['inventory check differs from selected check', s => { s.inventory[0].checks.at(-1).conclusion = 'failure'; }],
    ['incomplete suite pagination', s => { s.inventory[0].suites[0].latest_check_runs_count = 2; }],
    ['missing required suite', s => { s.inventory[0].suites.pop(); }],
    ['duplicate check', s => { s.inventory[0].checks.push(clone(s.bridgeCheck)); }],
    ['duplicate suite', s => { s.inventory[0].suites.push(clone(s.bridgeSuite)); }],
    ['unidentified suite app', s => { s.inventory[0].suites[0].app = {}; }],
    ['empty queued required-app suite', s => { s.inventory[0].suites.push({ id: 90, app: { id: 15368 }, head_sha: input.head, status: 'queued', conclusion: null, latest_check_runs_count: 0 }); }],
    ['empty failed required-app suite', s => { s.inventory[0].suites.push({ id: 90, app: { id: 15368 }, head_sha: input.head, status: 'completed', conclusion: 'failure', latest_check_runs_count: 0 }); }],
    ['unexplained unstable', s => { unstable(s); }],
  ]) reject(label, change);
  for (const conclusion of ['success', 'failure', 'cancelled']) {
    reject('competing post-Gate required check cannot be chosen by name', s => {
      historicalFailure(s); Object.assign(s.inventory[0].checks.at(-1), { completed_at: time(29), conclusion });
    });
  }
  reject('old required check still pending', s => {
    historicalFailure(s); Object.assign(s.inventory[0].checks.at(-1), { status: 'queued', conclusion: null, completed_at: null });
  });
  for (const state of ['pending', 'failure', 'success']) {
    reject('legacy required-name status has ambiguous integration authority', s => {
      s.inventory[0].statuses.push({ id: 99, context: 'Required CI', state, created_at: time(25) });
    });
  }
  for (const change of [s => { s.threads++; }, s => { s.pr.head.sha = 'e'.repeat(40); }, s => { optional(s); },
    s => { s.pr.mergeable_state = 'unstable'; s.prDecision.mergeStateStatus = 'UNSTABLE'; }]) {
    let reads = 0;
    await assert.rejects(verifyTerminal(input, () => { const s = fixture(); if (++reads === 2) change(s); return s; }), /(?:merge-authority|terminal-readiness)-error/);
    assert.equal(reads, 2); negative++;
  }
  let reads = 0;
  await verifyTerminal(input, () => { reads++; return fixture(); });
  assert.equal(reads, 2); positive++;
  assert.equal(parseAttestation(fixture().bridgeLog).value.nativeCheck, 13);
  const bootstrap = { ...input, task: 'WOS-GOV-011', issue: '134', pr: '135', profile: 'HIGH_FINANCIAL' };
  const bootstrapBase = '545b82b452adfc4d43fd4744f3f83d7a8f5e68fb';
  assert.equal(terminalSource(input, input.context.base, true), input.context.base); positive++;
  assert.equal(terminalSource(bootstrap, bootstrapBase, false), input.head); positive++;
  for (const key of ['repo', 'task', 'issue', 'pr', 'profile', 'assurance', 'reviewFloor']) {
    assert.throws(() => terminalSource({ ...bootstrap, [key]: 'other' }, bootstrapBase, false), /source-bound bootstrap/); negative++;
  }
  assert.throws(() => terminalSource(bootstrap, input.context.base, false), /source-bound bootstrap/); negative++;
  console.log(`terminal-merge-readiness-contract-ok positive=${positive} negative=${negative} live-writes=none`);
};
