'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { verifySnapshot, materialize, checkResult } = require('../../.github/scripts/merge-candidate-authority.js');

const repo = 'yoohwz/wc-order-splitter';
const base = 'a'.repeat(40), head = 'b'.repeat(40), tree = 'c'.repeat(40), candidate = 'd'.repeat(40);
const input = { repo, pr: '133', issue: '132', task: 'WOS-GOV-010', head, profile: 'HIGH_DEEP', assurance: 'HIGH', reviewFloor: 'REQUIRED', preReview: 'pr-review:42', gate: 'issue-comment:3' };
const actor = { user: { login: 'yoohwz', id: 152001663 }, author_association: 'OWNER' };
const time = second => `2026-09-02T08:00:${String(second).padStart(2, '0')}Z`;
const clone = value => JSON.parse(JSON.stringify(value));

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
    issue: { ...actor, state: 'open', title: 'WOS-GOV-010 — Strict Merge-Candidate Required CI Authority',
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
      'Human command': 'Finalize WOS-GOV-010', 'Merge candidate': candidate, 'Merge candidate tree': tree, 'Unresolved review threads': '0' }, 'HUMAN_GATE_APPROVED'),
    final: { id: 100, event: 'workflow_dispatch', path: '.github/workflows/ci.yml', repository: { full_name: repo }, head_sha: head,
      head_branch: 'codex/task', status: 'completed', conclusion: 'success', run_attempt: 1, check_suite_id: 88, created_at: time(11), updated_at: time(20) },
    jobs: [{ id: 11, run_id: 100, name: 'Required CI', status: 'completed', conclusion: 'success' },
      { id: 12, run_id: 100, name: binding, status: 'completed', conclusion: 'success' }],
    finalArtifacts: { total_count: 0 },
    finalCheck: { id: 11, name: 'Required CI', head_sha: head, app: { id: 15368 }, status: 'completed', conclusion: 'success', check_suite: { id: 88 } },
    review: selected.preReview ? { ...actor, commit_id: head, submitted_at: time(10) } : undefined, reviewVerified: Boolean(selected.preReview),
    bridge: { id: 101, event: 'workflow_dispatch', path: '.github/workflows/ci.yml', head_sha: head, run_attempt: 1, created_at: time(24) },
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
rejected('unbound FINAL profile', s => { s.jobs[1].name = s.jobs[1].name.replace('HIGH_DEEP', 'LOW_FOCUSED'); });
rejected('unbound FINAL base', s => { s.jobs[1].name = s.jobs[1].name.replace(base, head); });
rejected('unbound FINAL review', s => { s.jobs[1].name = s.jobs[1].name.replace('pr-review:42', 'none'); });
rejected('discovery event cannot be FINAL', s => { s.final.event = 'pull_request'; });
rejected('wrong workflow', s => { s.final.path = '.github/workflows/build-plugin.yml'; });
rejected('artifacts', s => { s.finalArtifacts.total_count = 1; });
rejected('bridge artifacts', s => { s.bridgeArtifacts.total_count = 1; });
rejected('rerun bridge', s => { s.bridge.run_attempt = 2; });
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
  let snapshot = fixture(selected), calls = [], check, collected = 0;
  const collect = async () => { collected++; change(snapshot, collected); return clone(snapshot); };
  const api = async (endpoint, method = 'GET', payload) => {
    calls.push({ endpoint, method, payload });
    if (method === 'POST') check = { ...payload, id: 200, app: { id: 15368 } };
    if (method === 'PATCH') check = { ...check, ...payload };
    return endpoint.includes('/pulls/') ? clone(snapshot.pr) : clone(check);
  };
  try { return { result: await materialize(selected, collect, api, async () => {}), calls, check }; }
  catch (error) { return { error, calls, check }; }
}

(async () => {
  let result = await simulation();
  assert.equal(result.result.candidate, candidate);
  checkResult(result.check, candidate);
  assert.equal(result.calls.filter(call => call.method === 'POST').length, 1);
  assert.equal(result.check.app.id, 15368);
  assert.equal(result.check.head_sha, candidate);
  assert(!result.calls.some(call => /dispatches|\/merge$|rulesets/.test(call.endpoint)), 'bridge cannot rerun certification, merge or mutate rules');
  for (const stage of [2, 3]) {
    result = await simulation((s, n) => { if (n === stage) s.pr.head.sha = 'e'.repeat(40); });
    assert(result.error, `drift at stage ${stage} must fail`);
    assert.equal(result.check.conclusion, 'failure');
  }
  result = await simulation(s => { s.gate.body = ''; });
  assert(result.error);
  assert.equal(result.calls.length, 0, 'FINAL alone cannot create even an in-progress candidate check');
  result = await simulation(s => { s.pr.mergeable_state = 'blocked'; });
  assert(result.error);
  assert.equal(result.check.conclusion, 'failure', 'unrecognized authority is invalidated');
  const low = { ...input, profile: 'LOW_FOCUSED', assurance: 'LOW', reviewFloor: 'OPTIONAL', preReview: '' };
  result = await simulation(() => {}, low);
  assert.equal(result.result.review, 'none', 'LOW adds no independent-review lifecycle');

  const workflow = fs.readFileSync(path.join(__dirname, '../../.github/workflows/ci.yml'), 'utf8');
  assert(workflow.includes("if: inputs.certification_stage != 'MERGE_AUTHORITY'"));
  assert(workflow.includes("if: always() && inputs.certification_stage != 'MERGE_AUTHORITY'"));
  assert.equal((workflow.match(/checks: write/g) || []).length, 1);
  const bridge = workflow.slice(workflow.indexOf('  merge-authority:\n'));
  assert(bridge.includes('persist-credentials: false'));
  assert(bridge.includes('git show "$authority_source:.github/scripts/merge-candidate-authority.js"'));
  assert(bridge.includes('test "$INPUT_TASK_ID" = WOS-GOV-010'));
  assert(bridge.includes('test "$INPUT_TASK_ISSUE_NUMBER" = 132'));
  assert(bridge.includes('test "$base_sha" = 545b82b452adfc4d43fd4744f3f83d7a8f5e68fb'));
  assert(!bridge.includes('name: Required CI'), 'skipped bridge jobs cannot publish a protected check');
  assert(!bridge.includes('npm ') && !bridge.includes('wp-env') && !bridge.includes('upload-artifact'), 'bridge is metadata-only');
  console.log(`merge-candidate-authority-contract-ok negative-cases=${assertions} untrusted-adverse-cases=${ignoredAdverse} live-writes=mocked`);
})().catch(error => { console.error(error); process.exitCode = 1; });
