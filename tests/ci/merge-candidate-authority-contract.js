'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const { verifySnapshot, verifyNative, nativeContext, resolveTask, directScope, selectGate } = require('../../.github/scripts/merge-candidate-authority.js');

const repo = 'yoohwz/wc-order-splitter';
const base = 'a'.repeat(40), head = 'b'.repeat(40), tree = 'c'.repeat(40), candidate = 'd'.repeat(40);
const input = { repo, pr: '133', issue: '132', task: 'WOS-GOV-010', head, profile: 'HIGH_DEEP', assurance: 'HIGH', reviewFloor: 'REQUIRED', preReview: 'pr-review:42', gate: 'issue-comment:3' };
const actor = { user: { login: 'yoohwz', id: 152001663 }, author_association: 'OWNER' };
const time = second => `2026-09-02T08:00:${String(second).padStart(2, '0')}Z`;
const clone = value => JSON.parse(JSON.stringify(value));
input.context = { pr: input.pr, head, base, candidate, ref: 'refs/pull/133/merge', readyAt: time(24), run: 101 };

function fixture(selected = input) {
  const reviewed = selected.preReview ? 'true' : 'false';
  const common = { 'Record version': 'merge-authority-v1', 'Canonical Issue': '#132', PR: '#133', 'Exact base': base,
    'Exact head': head, 'Exact head tree': tree, 'CI profile': selected.profile, Assurance: selected.assurance,
    'Review required': reviewed, 'PRE_REVIEW authority': selected.preReview || 'none', 'FINAL run': '100', 'FINAL attempt': '1', Artifacts: '0' };
  const terminal = token => `${token}: WOS-GOV-010 / PR #133 / exact head ${head}`;
  const record = (id, name, role, fields, token) => ({ ...actor, id, issue_url: `https://api.github.com/repos/${repo}/issues/132`,
    created_at: time(id + 20), updated_at: time(id + 20), body: [`## Merge ${name} — WOS-GOV-010`, ...Object.entries({ ...common, Role: role, ...fields }).map(([key, value]) => `${key}: ${value}`), terminal(token)].join('\n') });
  const kind = reviewed === 'true' ? 'TECHNICAL_ACCEPTED' : 'EXECUTOR_EVIDENCE_READY';
  const binding = `FINAL binding / WOS-GOV-010 / ${selected.profile} / ${base} / ${selected.preReview || 'none'}`;
  return {
    repository: { full_name: repo, owner: actor.user, default_branch: 'main' },
    issue: { ...actor, number: 132, state: 'open', title: 'WOS-GOV-010 — Strict Merge-Candidate Required CI Authority',
      body: `- **Task:** \`WOS-GOV-010\`\n- **CI profile floor:** \`${selected.profile}\`\n- **Assurance floor:** \`${selected.assurance}\`\n- **Independent review floor:** \`${selected.reviewFloor}\`` },
    pr: { state: 'open', draft: false, body: '- Canonical Issue: #132\n- Task: `WOS-GOV-010`',
      base: { sha: base, ref: 'main', repo: { full_name: repo } }, head: { sha: head, ref: 'codex/task', repo: { full_name: repo } },
      merge_commit_sha: candidate, mergeable: true, mergeable_state: 'clean' },
    main: { object: { sha: base } }, candidate: { sha: candidate, tree: { sha: tree }, parents: [{ sha: base }, { sha: head }] },
    head: { sha: head, tree: { sha: tree } }, threads: 0,
    rules: { id: 21367637, name: 'Protect main', target: 'branch', source_type: 'Repository', source: repo,
      updated_at: '2026-08-25T10:09:48.838+07:00', enforcement: 'active', bypass_actors: [],
      conditions: { ref_name: { exclude: [], include: ['~DEFAULT_BRANCH'] } }, rules: [
        { type: 'deletion' }, { type: 'non_fast_forward' },
        { type: 'pull_request', parameters: { allowed_merge_methods: ['squash'], dismiss_stale_reviews_on_push: false,
          require_code_owner_review: false, require_extra_approval_for_unattributed_changes: true, require_last_push_approval: false,
          required_approving_review_count: 0, required_review_thread_resolution: true, required_reviewers: [] } },
        { type: 'required_status_checks', parameters: { strict_required_status_checks_policy: true, do_not_enforce_on_create: false,
          required_status_checks: [{ context: 'Required CI', integration_id: 15368 }] } },
      ] },
    classification: { profile: selected.profile, assurance: selected.assurance, review_required: reviewed },
    evidence: record(1, 'CI evidence', 'codex_executor', { 'Evidence kind': kind }, kind),
    acceptance: record(2, 'Acceptance', 'chatgpt_acceptance_reviewer', { 'Evidence authority': 'issue-comment:1' }, 'ACCEPTANCE_ACCEPTED'),
    gate: record(3, 'Human Gate', 'repository_owner', { 'Evidence authority': 'issue-comment:1', 'Acceptance authority': 'issue-comment:2',
      'Human command': 'Finalize WOS-GOV-010', 'Merge candidate': candidate, 'Merge candidate tree': tree, 'Unresolved review threads': '0', 'PR state': 'draft' }, 'HUMAN_GATE_APPROVED'),
    final: { id: 100, event: 'workflow_dispatch', path: '.github/workflows/ci.yml', repository: { full_name: repo }, head_sha: head,
      head_branch: 'codex/task', status: 'completed', conclusion: 'success', run_attempt: 1, check_suite_id: 88, created_at: time(11), updated_at: time(20) },
    jobs: [{ id: 11, run_id: 100, name: 'Required CI', status: 'completed', conclusion: 'success' },
      { id: 12, run_id: 100, name: binding, status: 'completed', conclusion: 'success' }],
    finalArtifacts: { total_count: 0 },
    finalCheck: { id: 11, name: 'Required CI', head_sha: head, app: { id: 15368 }, status: 'completed', conclusion: 'success', check_suite: { id: 88 } },
    review: selected.preReview ? { ...actor, commit_id: head, submitted_at: time(10) } : undefined, reviewVerified: Boolean(selected.preReview),
    bridge: { id: 101, event: 'pull_request', path: '.github/workflows/merge-authority.yml', head_sha: head,
      repository: { full_name: repo }, head_branch: 'codex/task', status: 'in_progress', run_attempt: 1, created_at: time(24), check_suite_id: 89,
      pull_requests: [{ number: 133, head: { sha: head }, base: { sha: base } }] },
    bridgeSuite: { id: 89, head_sha: head, app: { id: 15368 }, pull_requests: [{ number: 133, head: { sha: head }, base: { sha: base } }] },
    bridgeCheck: { id: 13, name: 'Required CI', head_sha: head, app: { id: 15368 }, check_suite: { id: 89 }, status: 'in_progress', conclusion: null },
    readyEvents: [{ event: 'ready_for_review', created_at: time(24), actor: actor.user }],
    bridgeArtifacts: { total_count: 0 }, comments: [], reviews: [],
  };
}

let assertions = 0;
let ignoredAdverse = 0;
function rejected(label, change) {
  const snapshot = fixture();
  change(snapshot);
  assert.throws(() => verifySnapshot(snapshot, input), /merge-authority-error/, label);
  assertions++;
}

assert.equal(verifySnapshot(fixture(), input).candidate, candidate);
const redacted = fixture();
delete redacted.rules.bypass_actors;
assert.equal(verifySnapshot(redacted, input).rulesetRevision, '2026-08-25T03:09:48.838Z');
redacted.rules.updated_at = '2026-08-25T03:09:48.838Z';
assert.equal(verifySnapshot(redacted, input).rulesetRevision, '2026-08-25T03:09:48.838Z');
for (const profile of ['LOW_FOCUSED', 'MEDIUM_DOMAIN']) {
  const selected = { ...input, profile, assurance: profile === 'LOW_FOCUSED' ? 'LOW' : 'MEDIUM', reviewFloor: 'OPTIONAL', preReview: '' };
  assert.equal(verifySnapshot(fixture(selected), selected).review, 'none');
}
const release = { ...input, profile: 'RELEASE_CERT' };
assert.equal(verifySnapshot(fixture(release), release).profile, 'RELEASE_CERT');
const financial = { ...input, profile: 'HIGH_FINANCIAL' };
assert.equal(verifySnapshot(fixture(financial), financial).profile, 'HIGH_FINANCIAL');

const env = { GITHUB_REPOSITORY: repo, GITHUB_EVENT_NAME: 'pull_request', GITHUB_REF: 'refs/pull/133/merge',
  GITHUB_SHA: candidate, GITHUB_RUN_ID: '101', GITHUB_RUN_ATTEMPT: '1',
  GITHUB_WORKFLOW_REF: `${repo}/.github/workflows/merge-authority.yml@refs/pull/133/merge`, GITHUB_WORKFLOW_SHA: candidate };
const event = { repository: { full_name: repo }, action: 'ready_for_review', number: 133, sender: actor.user,
  pull_request: { ...fixture().pr, number: 133, updated_at: time(24) } };
assert.deepEqual(nativeContext(event, env), input.context);
for (const [label, change] of [
  ['dispatch', (e, v) => { v.GITHUB_EVENT_NAME = 'workflow_dispatch'; }],
  ['target event', (e, v) => { v.GITHUB_EVENT_NAME = 'pull_request_target'; }],
  ['discovery', e => { e.action = 'synchronize'; }],
  ['wrong actor', e => { e.sender = { login: 'stranger', id: 1 }; }],
  ['wrong PR', e => { e.number = 131; }],
  ['draft event', e => { e.pull_request.draft = true; }],
  ['closed event', e => { e.pull_request.state = 'closed'; }],
  ['fork', e => { e.pull_request.head.repo.full_name = 'someone/fork'; }],
  ['head ref', (e, v) => { v.GITHUB_REF = 'refs/heads/codex/task'; }],
  ['head instead of candidate', (e, v) => { v.GITHUB_SHA = head; }],
  ['wrong workflow source', (e, v) => { v.GITHUB_WORKFLOW_SHA = head; }],
  ['wrong workflow ref', (e, v) => { v.GITHUB_WORKFLOW_REF = `${repo}/.github/workflows/ci.yml@refs/pull/133/merge`; }],
  ['rerun', (e, v) => { v.GITHUB_RUN_ATTEMPT = '2'; }],
  ['missing event time', e => { delete e.pull_request.updated_at; }],
]) {
  const e = clone(event), v = clone(env); change(e, v);
  assert.throws(() => nativeContext(e, v), /merge-authority-error/, label); assertions++;
}
assert.deepEqual(resolveTask(fixture().pr, fixture().issue), { task: input.task, issue: input.issue });
const directTask = 'WOS-DIRECT-20260902-120000';
const directPr = { head: { ref: `codex/direct/${directTask.toLowerCase()}` } };
const directClass = { profile: 'DIRECT_FAST', assurance: 'DIRECT', review_required: 'false' };
assert(directScope(directTask, directPr, { body: '' }, directClass));
assert(directScope(directTask, directPr, { body: '- **CI profile floor:** `DIRECT_FAST`\n- **Assurance floor:** `DIRECT`\n- **Independent review floor:** `OPTIONAL`' }, directClass));
assert(!directScope(directTask, directPr, { body: '- **Assurance floor:** `HIGH`' }, directClass));
assert(!directScope(input.task, directPr, { body: '' }, directClass), 'normal task does not opt into DIRECT');
assert(!directScope(directTask, directPr, { body: '- **CI profile floor:** `LOW_FOCUSED`' }, directClass), 'transitioned normal task not excluded');
assert(!directScope(directTask, directPr, { body: '' }, { ...directClass, profile: 'HIGH_DEEP' }), 'branch claim cannot bypass semantic scope');
assert(!directScope(directTask, { head: { ref: 'codex/normal' } }, { body: '' }, directClass));
const gate = fixture().gate;
assert.equal(selectGate([gate], input).id, gate.id);
assert.throws(() => selectGate([], input), /missing current-head Human Gate/);
assert.throws(() => selectGate([gate, { ...gate, id: 4, created_at: time(24), updated_at: time(25) }], input), /edited/, 'no fallback to old approval');

function adverseRecord(token, at = 25) {
  const formats = {
    PRE_REVIEW_CHANGES_REQUIRED: ['Independent Codex PRE_REVIEW', 'independent_codex_reviewer'],
    TECHNICAL_CHANGES_REQUIRED: ['Independent Codex Technical Review', 'independent_codex_reviewer'],
    ACCEPTANCE_CHANGES_REQUIRED: ['Merge Acceptance', 'chatgpt_acceptance_reviewer'],
    HUMAN_GATE_REVOKED: ['Merge Human Gate', 'repository_owner'],
  };
  const [header, role] = formats[token];
  const fields = { Role: role, 'Canonical Issue': '#132', 'Exact base': base, 'Exact head': head, 'Exact head tree': tree };
  if (role === 'independent_codex_reviewer') Object.assign(fields, { 'Fresh context': 'yes', 'Executor session reused': 'no',
    'Source read-only/no-implementation-write': 'yes', 'Complete diff reviewed': 'yes' });
  else Object.assign(fields, { 'Record version': 'merge-authority-v1', PR: '#133' });
  return { ...actor, issue_url: `https://api.github.com/repos/${repo}/issues/132`, created_at: time(at), updated_at: time(at),
    body: [`## ${header} — WOS-GOV-010`, ...Object.entries(fields).map(([key, value]) => `${key}: ${value}`),
      `${token}: WOS-GOV-010 / PR #133 / exact head ${head}`].join('\n') };
}

rejected('changes-required has no validated clean review', s => { s.reviewVerified = false; });
rejected('clean without FINAL', s => { s.final.status = 'queued'; });
rejected('failed FINAL', s => { s.final.conclusion = 'failure'; });
rejected('missing Human Gate', s => { s.gate.body = ''; });
rejected('missing Acceptance', s => { s.acceptance.body = ''; });
rejected('base drift', s => { s.pr.base.sha = 'e'.repeat(40); });
rejected('main drift', s => { s.main.object.sha = 'e'.repeat(40); });
rejected('head drift', s => { s.pr.head.sha = 'e'.repeat(40); });
rejected('candidate regeneration', s => { s.candidate.sha = s.pr.merge_commit_sha = 'e'.repeat(40); });
rejected('candidate tree differs from certified tree', s => { s.candidate.tree.sha = 'e'.repeat(40); });
rejected('wrong merge parents', s => { s.candidate.parents.reverse(); });
rejected('unresolved thread', s => { s.threads = 1; });
rejected('draft', s => { s.pr.draft = true; });
rejected('fork', s => { s.pr.head.repo.full_name = 'stranger/fork'; });
rejected('wrong app', s => { s.finalCheck.app.id = 1144995; });
rejected('wrong SHA', s => { s.finalCheck.head_sha = candidate; });
rejected('wrong suite', s => { s.finalCheck.check_suite.id++; });
rejected('wrong check', s => { s.finalCheck.id++; });
for (const conclusion of ['skipped', 'neutral', null, 'failure', 'cancelled', 'timed_out']) {
  rejected(`bad conclusion ${conclusion}`, s => { s.finalCheck.conclusion = conclusion; });
}
rejected('missing FINAL job', s => { s.jobs.shift(); });
rejected('skipped FINAL job', s => { s.jobs[0].conclusion = 'skipped'; });
rejected('duplicate FINAL', s => { s.jobs.push(s.jobs[0]); });
rejected('missing FINAL binding', s => { s.jobs.pop(); });
rejected('skipped FINAL binding', s => { s.jobs[1].conclusion = 'skipped'; });
rejected('neutral FINAL binding', s => { s.jobs[1].conclusion = 'neutral'; });
rejected('incomplete FINAL binding', s => { s.jobs[1].status = 'in_progress'; });
rejected('duplicate FINAL binding', s => { s.jobs.push(s.jobs[1]); });
rejected('raw-name FINAL binding', s => { s.jobs[1].name = 'FINAL binding / ${{ needs.classify-pr-scope.outputs.task_id }}'; });
rejected('unbound FINAL task', s => { s.jobs[1].name = s.jobs[1].name.replace('WOS-GOV-010', 'WOS-GOV-009'); });
rejected('unbound FINAL profile', s => { s.jobs[1].name = s.jobs[1].name.replace('HIGH_DEEP', 'LOW_FOCUSED'); });
rejected('unbound FINAL base', s => { s.jobs[1].name = s.jobs[1].name.replace(base, head); });
rejected('unbound FINAL review', s => { s.jobs[1].name = s.jobs[1].name.replace('pr-review:42', 'none'); });
rejected('discovery event cannot be FINAL', s => { s.final.event = 'pull_request'; });
rejected('wrong workflow', s => { s.final.path = '.github/workflows/build-plugin.yml'; });
rejected('artifacts', s => { s.finalArtifacts.total_count = 1; });
rejected('bridge artifacts', s => { s.bridgeArtifacts.total_count = 1; });
rejected('rerun bridge', s => { s.bridge.run_attempt = 2; });
rejected('old custom bridge event', s => { s.bridge.event = 'workflow_dispatch'; });
rejected('old custom bridge workflow', s => { s.bridge.path = '.github/workflows/ci.yml'; });
rejected('wrong native run', s => { s.bridge.id++; });
rejected('native run candidate mistaken for REST head', s => { s.bridge.head_sha = candidate; });
rejected('no native run PR association', s => { s.bridge.pull_requests = []; });
rejected('no native suite PR association', s => { s.bridgeSuite.pull_requests = []; });
rejected('wrong native suite app', s => { s.bridgeSuite.app.id = 1; });
rejected('wrong native check app', s => { s.bridgeCheck.app.id = 1; });
rejected('wrong native check suite', s => { s.bridgeCheck.check_suite.id++; });
rejected('skipped native job', s => { s.bridgeCheck.status = 'completed'; s.bridgeCheck.conclusion = 'skipped'; });
rejected('precompleted native job', s => { s.bridgeCheck.conclusion = 'success'; });
rejected('Gate must attest draft', s => { s.gate.body = s.gate.body.replace('PR state: draft', 'PR state: ready'); });
rejected('missing draft Gate binding', s => { s.gate.body = s.gate.body.replace('PR state: draft\n', ''); });
rejected('Gate cannot follow ready event', s => { s.gate.created_at = s.gate.updated_at = time(25); });
rejected('missing native ready transition', s => { s.readyEvents = []; });
rejected('same Gate cannot authorize another ready transition', s => { s.readyEvents.push({ ...s.readyEvents[0], created_at: time(25) }); });
rejected('wrong ready timeline event', s => { s.readyEvents[0].created_at = time(25); });
rejected('wrong ready timeline actor', s => { s.readyEvents[0].actor = { id: 1 }; });
for (const key of ['head', 'base', 'candidate', 'ref']) {
  const changed = { ...input, context: { ...input.context, [key]: 'e'.repeat(40) } };
  assert.throws(() => verifySnapshot(fixture(), changed), /merge-authority-error/, `native context ${key}`); assertions++;
}
rejected('quoted authority', s => { s.gate.body = s.gate.body.split('\n').map(line => `> ${line}`).join('\n'); });
rejected('fenced authority', s => { s.gate.body = `\`\`\`\n${s.gate.body}\n\`\`\``; });
rejected('edited authority', s => { s.gate.updated_at = time(25); });
rejected('wrong role', s => { s.acceptance.body = s.acceptance.body.replace('chatgpt_acceptance_reviewer', 'codex_executor'); });
rejected('wrong actor', s => { s.gate.user = { login: 'yoohwz', id: 123 }; });
rejected('wrong canonical Issue', s => { s.gate.issue_url = s.gate.issue_url.replace('132', '131'); });
rejected('duplicate field', s => { s.gate.body = s.gate.body.replace('Role: repository_owner', 'Role: repository_owner\nRole: repository_owner'); });
rejected('unknown field', s => { s.gate.body = s.gate.body.replace('Role: repository_owner', 'Role: repository_owner\nOverride: yes'); });
rejected('duplicate task floor', s => { s.issue.body += '\n- **CI profile floor:** `LOW_FOCUSED`'; });
rejected('task claim cannot lower machine floor', s => { s.classification.profile = 'HIGH_FINANCIAL'; });
rejected('Human Gate before FINAL', s => { s.gate.created_at = s.gate.updated_at = time(5); });
rejected('review after FINAL', s => { s.review.submitted_at = time(15); });
rejected('bypass', s => { s.rules.bypass_actors.push({ actor_id: 1 }); });
rejected('redacted bypass with changed revision', s => { delete s.rules.bypass_actors; s.rules.updated_at = time(15); });
rejected('missing revision', s => { delete s.rules.updated_at; });
rejected('wrong ruleset target', s => { s.rules.target = 'tag'; });
rejected('non-strict', s => { s.rules.rules[3].parameters.strict_required_status_checks_policy = false; });
rejected('wrong required app', s => { s.rules.rules[3].parameters.required_status_checks[0].integration_id = 1144995; });
rejected('thread rule removed', s => { s.rules.rules[2].parameters.required_review_thread_resolution = false; });
for (const token of ['PRE_REVIEW_CHANGES_REQUIRED', 'TECHNICAL_CHANGES_REQUIRED', 'ACCEPTANCE_CHANGES_REQUIRED', 'HUMAN_GATE_REVOKED']) {
  rejected(`later direct ${token}`, s => { s.comments.push(adverseRecord(token)); });
  const direct = adverseRecord(token);
  const rawEvidence = fixture();
  rawEvidence.comments.push({ ...direct, body: `Role: codex_executor\n${token}: WOS-GOV-010 / PR #133 / exact head ${head}` });
  assert.doesNotThrow(() => verifySnapshot(rawEvidence, input), `raw Executor ${token} is not authority`);
  ignoredAdverse++;
  for (const [label, change] of [
    ['backtick fence', record => { record.body = `\`\`\`text\n${record.body}\n\`\`\``; }],
    ['tilde fence', record => { record.body = `~~~text\n${record.body}\n~~~`; }],
    ['HTML comment', record => { record.body = `<!--\n${record.body}\n-->`; }],
    ['HTML wrapper', record => { record.body = `<details>\n${record.body}\n</details>`; }],
    ['blockquote', record => { record.body = record.body.split('\n').map(line => `> ${line}`).join('\n'); }],
    ['copied transcript', record => { record.body = `Copied evidence only:\n${record.body}`; }],
    ['Executor role', record => { record.body = record.body.replace(/^Role: .+$/m, 'Role: codex_executor'); }],
    ['missing role', record => { record.body = record.body.replace(/^Role: .+\n/m, ''); }],
    ['duplicate role', record => { record.body = record.body.replace(/^Role: (.+)$/m, 'Role: $1\nRole: $1'); }],
    ['different Issue', record => { record.issue_url = record.issue_url.replace('/132', '/131'); }],
    ['edited evidence', record => { record.updated_at = time(26); }],
    ['different actor', record => { record.user = { login: 'someone', id: 1 }; }],
    ['different task', record => { record.body = record.body.replaceAll('WOS-GOV-010', 'WOS-GOV-0100'); }],
  ]) {
    const snapshot = fixture(), record = clone(direct);
    change(record);
    snapshot.comments.push(record);
    assert.doesNotThrow(() => verifySnapshot(snapshot, input), `${label} ${token} must remain evidence, not authority`);
    ignoredAdverse++;
  }
}
rejected('adverse PR review before mechanical evidence still blocks', s => {
  const record = adverseRecord('PRE_REVIEW_CHANGES_REQUIRED', 15);
  delete record.issue_url;
  delete record.created_at;
  delete record.updated_at;
  s.reviews.push({ ...record, commit_id: head, submitted_at: time(15) });
});
const wrongCommit = fixture();
wrongCommit.reviews.push({ ...adverseRecord('PRE_REVIEW_CHANGES_REQUIRED'), commit_id: base, submitted_at: time(25) });
assert.doesNotThrow(() => verifySnapshot(wrongCommit, input), 'PR review must bind its actual commit');
ignoredAdverse++;
for (const [label, change] of [
  ['reused Executor session', record => { record.body = record.body.replace('Executor session reused: no', 'Executor session reused: yes'); }],
  ['non-fresh reviewer', record => { record.body = record.body.replace('Fresh context: yes', 'Fresh context: no'); }],
  ['incomplete diff', record => { record.body = record.body.replace('Complete diff reviewed: yes', 'Complete diff reviewed: no'); }],
]) {
  const snapshot = fixture(), record = adverseRecord('PRE_REVIEW_CHANGES_REQUIRED');
  change(record);
  snapshot.comments.push(record);
  assert.doesNotThrow(() => verifySnapshot(snapshot, input), `${label} is not Independent Review authority`);
  ignoredAdverse++;
}

async function simulation(change = () => {}, selected = input) {
  let snapshot = fixture(selected), collected = 0;
  const collect = async () => { collected++; change(snapshot, collected); return clone(snapshot); };
  try { return { result: await verifyNative(selected, collect), collected }; }
  catch (error) { return { error, collected }; }
}

function verifyFinalBindingScheduling(workflow) {
  const sections = workflow.split(/^  final-binding:\n/m);
  assert.equal(sections.length, 2, 'exactly one FINAL binding job definition');
  const job = sections[1].split(/^  [\w-]+:\n/m)[0];
  assert(job.includes('    needs: [classify-pr-scope, required]\n'));
  const condition = job.match(/^    if: >-\n((?:      .+\n)+)/m)?.[1].trim();
  // This exact boolean-only subset is shared by GitHub expressions and JS.
  // Evaluate the source predicate, not a separately maintained copy of it.
  assert.deepEqual(condition?.split(/\s*&&\s*/), [
    '!cancelled()', "github.event_name == 'workflow_dispatch'", "inputs.certification_stage == 'FINAL'",
    "needs['classify-pr-scope'].result == 'success'", "needs['classify-pr-scope'].outputs.stage == 'FINAL'",
    "needs.required.result == 'success'",
  ]);
  const name = job.match(/^    name: (.+)$/m)[1];
  const initial = { event: 'workflow_dispatch', requested: 'FINAL', stage: 'FINAL', classifier: 'success',
    required: 'success', cancelled: false, optional: ['skipped', 'skipped', 'skipped', 'skipped'] };
  const schedule = (state, predicate = condition) => {
    const context = { github: { event_name: state.event },
      inputs: { certification_stage: state.requested, pre_review_authority: input.preReview },
      needs: { 'classify-pr-scope': { result: state.classifier,
        outputs: { stage: state.stage, task_id: input.task, profile: input.profile, base_sha: base } },
      required: { result: state.required } }, cancelled: () => state.cancelled };
    // Model GitHub's implicit success()/ancestor-skip guard. A live FINAL must
    // still prove the real scheduler; this local model is not CI authority.
    const explicitStatus = /\b(?:success|failure|always|cancelled)\s*\(/.test(predicate);
    const implicitSuccess = !state.cancelled && [state.classifier, state.required, ...state.optional].every(value => value === 'success');
    if ((!explicitStatus && !implicitSuccess) || !vm.runInNewContext(predicate, context)) return [];
    return [{ id: 12, run_id: 100, status: 'completed', conclusion: 'success',
      name: name.replace(/\$\{\{\s*(.*?)\s*\}\}/g, (_, expression) => vm.runInNewContext(expression, context)) }];
  };
  const binding = schedule(initial);
  assert.equal(binding.length, 1, 'optional ancestor skips must not suppress successful FINAL binding');
  const snapshot = fixture();
  snapshot.jobs = [snapshot.jobs[0], ...binding];
  assert.equal(verifySnapshot(snapshot, input).final, 100, 'scheduled exact binding satisfies the unchanged verifier');
  assert.equal(schedule({ ...initial, optional: ['success'] }).length, 1);
  assert.equal(schedule(initial, condition.replace(/^!cancelled\(\)\s*&&\s*/, '')).length, 0,
    'regression reproduces the implicit skip that blocked live FINAL 33613591709');
  const negatives = [
    { event: 'push' }, { event: 'pull_request' }, { cancelled: true },
    { requested: 'PRECHECK' }, { requested: 'MERGE_AUTHORITY' },
    { stage: 'PRECHECK' }, { stage: 'MERGE_AUTHORITY' }, { stage: '' },
  ];
  for (const result of ['failure', 'skipped', 'cancelled', '', undefined]) {
    negatives.push({ classifier: result }, { required: result });
  }
  for (const change of negatives) assert.equal(schedule({ ...initial, ...change }).length, 0, `binding must reject ${JSON.stringify(change)}`);
  return { positive: 2, negative: negatives.length, implicitSkipReproduction: 1 };
}

(async () => {
  await require('./terminal-merge-readiness-contract')(fixture, input);
  let result = await simulation();
  assert.equal(result.result.candidate, candidate);
  assert.equal(result.collected, 2);
  for (const change of [
    s => { s.pr.head.sha = 'e'.repeat(40); },
    s => { s.comments.push(adverseRecord('HUMAN_GATE_REVOKED')); },
    s => { s.threads++; },
    s => { s.candidate.sha = 'e'.repeat(40); },
  ]) {
    result = await simulation((s, n) => { if (n === 2) change(s); });
    assert(result.error, 'drift during read-only verification must fail');
  }
  result = await simulation(s => { s.gate.body = ''; });
  assert(result.error);
  result = await simulation(s => { s.pr.mergeable_state = 'blocked'; });
  assert(!result.error, 'running native job cannot yet make GitHub clean; Finalize must inspect after completion');
  const low = { ...input, profile: 'LOW_FOCUSED', assurance: 'LOW', reviewFloor: 'OPTIONAL', preReview: '' };
  result = await simulation(() => {}, low);
  assert.equal(result.result.review, 'none', 'LOW adds no independent-review lifecycle');

  const workflow = fs.readFileSync(path.join(__dirname, '../../.github/workflows/ci.yml'), 'utf8');
  const scheduling = verifyFinalBindingScheduling(workflow);
  assert(!workflow.includes('MERGE_AUTHORITY') && !workflow.includes('merge_authority:'), 'retired dispatch path absent');
  assert(!workflow.includes('checks: write'));
  const bridgePath = path.join(__dirname, '../../.github/workflows/merge-authority.yml');
  const bridge = fs.readFileSync(bridgePath, 'utf8');
  const parsed = JSON.parse(execFileSync('ruby', ['-rjson', '-ryaml', '-e', 'puts YAML.load_file(ARGV[0]).to_json', bridgePath], { encoding: 'utf8' }));
  assert.deepEqual(parsed.on || parsed.true, { pull_request: { branches: ['main'], types: ['ready_for_review'] } });
  assert.deepEqual(parsed.permissions, { actions: 'read', contents: 'read', issues: 'read', 'pull-requests': 'read', checks: 'read' });
  assert.equal(parsed.jobs.required.if, 'always()');
  assert.equal(parsed.jobs.required.needs, 'scope');
  const expression = parsed.jobs.required.name.slice(3, -2).trim();
  for (const status of ['success', 'failure', 'skipped', 'cancelled', '']) {
    for (const route of ['governed', 'direct', '', undefined, 'invalid']) {
      const name = vm.runInNewContext(expression, { needs: { scope: { result: status, outputs: { route } } } });
      assert.equal(name, status === 'success' && route === 'direct' ? 'Native bridge not applicable to DIRECT' : 'Required CI');
    }
  }
  const guard = parsed.jobs.required.steps[0];
  assert(!guard.if && !guard['continue-on-error']);
  for (const status of ['success', 'failure', 'skipped', 'cancelled', '']) {
    for (const route of ['governed', 'direct', '', 'invalid']) {
      let passes = false;
      try { execFileSync('bash', ['-e', '-c', guard.run], { env: { ...process.env, SCOPE_RESULT: status, SCOPE_ROUTE: route }, stdio: 'ignore' }); passes = true; } catch {}
      assert.equal(passes, status === 'success' && ['governed', 'direct'].includes(route));
    }
  }
  const normal = parsed.jobs.required.steps.at(-1);
  assert.equal(normal.if, "needs.scope.outputs.route != 'direct'");
  assert(!normal['continue-on-error'] && normal.run.endsWith('--verify\n'));
  const scope = parsed.jobs.scope.steps.at(-1).run;
  // Both jobs load exactly the same trusted source before their different mode.
  assert.equal(scope.slice(0, scope.lastIndexOf('node ')), normal.run.slice(0, normal.run.lastIndexOf('node ')));
  // Execute the source-bound fallback itself. The original GOV-010 tuple must
  // not select head-owned authority, nor may a partial/wrong GOV-011 tuple.
  const bootstrap = scope.slice(scope.indexOf('authority_source=$base_sha'), scope.indexOf('git show "$authority_source:'));
  assert(bootstrap && !bootstrap.includes('GOV-010') && !bootstrap.includes('HIGH_DEEP'));
  const bootstrapRoot = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'wcos-bootstrap-'));
  let bootstrapCases = 0;
  try {
    const eventFile = path.join(bootstrapRoot, 'event.json');
    const issue = { ...actor, state: 'open', body: '- **Task:** `WOS-GOV-011`\n- **CI profile floor:** `HIGH_FINANCIAL`\n- **Assurance floor:** `HIGH`\n- **Independent review floor:** `REQUIRED`' };
    const event = { pull_request: { body: '- Task: `WOS-GOV-011`\n- Canonical Issue: #134' } };
    const checkBootstrap = (change = {}, valid = false) => {
      const currentIssue = clone(issue), currentEvent = clone(event);
      change.issue?.(currentIssue);
      change.event?.(currentEvent);
      fs.writeFileSync(eventFile, JSON.stringify(currentEvent));
      const sourceBase = change.base || '545b82b452adfc4d43fd4744f3f83d7a8f5e68fb';
      const script = `set -euo pipefail
git() { test "$*" = "cat-file -e $base_sha:.github/scripts/merge-candidate-authority.js" || exit 9; return 1; }
gh() { test "$*" = "api repos/yoohwz/wc-order-splitter/issues/134" || exit 9; printf '%s' "$ISSUE_JSON"; }
${bootstrap}
test "$authority_source" = "$head_sha"
`;
      const result = require('node:child_process').spawnSync('bash', ['-c', script], { encoding: 'utf8',
        env: { ...process.env, base_sha: sourceBase, head_sha: head, pr_number: change.pr || '135',
          GITHUB_REPOSITORY: repo, GITHUB_EVENT_PATH: eventFile, ISSUE_JSON: JSON.stringify(currentIssue) } });
      assert.equal(result.status === 0, valid, result.stderr || JSON.stringify(change));
      bootstrapCases++;
    };
    checkBootstrap({}, true);
    for (const change of [
      { base }, { pr: '133' }, { pr: '136' },
      { event: e => { e.pull_request.body = e.pull_request.body.replace('GOV-011', 'GOV-010'); } },
      { event: e => { e.pull_request.body = e.pull_request.body.replace('#134', '#132'); } },
      { issue: i => { i.user.login = 'other'; } }, { issue: i => { i.user.id++; } },
      { issue: i => { i.author_association = 'CONTRIBUTOR'; } }, { issue: i => { i.state = 'closed'; } },
      { issue: i => { i.pull_request = {}; } },
      { issue: i => { i.body = i.body.replace('GOV-011', 'GOV-010'); } },
      { issue: i => { i.body = i.body.replace('HIGH_FINANCIAL', 'HIGH_DEEP'); } },
      { issue: i => { i.body = i.body.replace('`HIGH`', '`LOW`'); } },
      { issue: i => { i.body = i.body.replace('REQUIRED', 'OPTIONAL'); } },
    ]) checkBootstrap(change);
  } finally { fs.rmSync(bootstrapRoot, { recursive: true, force: true }); }
  for (const snippet of ['persist-credentials: false', 'git show "$authority_source:.github/scripts/merge-candidate-authority.js"',
    'test "$pr_number" = 135', 'test "$base_sha" = 545b82b452adfc4d43fd4744f3f83d7a8f5e68fb',
    'authority_source=$head_sha', 'verify-pre-review-authority.sh', 'validate-pre-review-record.sh']) assert(bridge.includes(snippet));
  assert(!bridge.includes('workflow_dispatch') && !bridge.includes('checks: write') && !bridge.includes('continue-on-error'));
  assert(!bridge.includes('npm ') && !bridge.includes('wp-env') && !bridge.includes('upload-artifact'));
  const verifier = fs.readFileSync(path.join(__dirname, '../../.github/scripts/merge-candidate-authority.js'), 'utf8');
  assert(!/\bmaterialize\b|['"]PATCH['"]|['"]DELETE['"]/.test(verifier));
  assert(verifier.includes("method === 'GET' || (endpoint === 'graphql' && method === 'POST'"));
  assert(!/liveApi\([^;]*check-runs[^;]*['"]POST['"]/.test(verifier));
  console.log('merge-candidate-authority-contract-ok negative-cases=' + assertions + ' untrusted-adverse-cases=' + ignoredAdverse +
    ' final-binding-scheduling=' + JSON.stringify(scheduling) + ' native-scope-topology=20-cases bootstrap-cases=' + bootstrapCases + ' live-writes=none');
})().catch(error => { console.error(error); process.exitCode = 1; });
