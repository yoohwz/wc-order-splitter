'use strict';

// Read-only verification inside a native pull_request merge-ref job. GitHub
// supplies the check; this program never creates/patches checks or approvals.
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { createHash } = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');

const REPO = 'yoohwz/wc-order-splitter';
const OWNER = { login: 'yoohwz', id: 152001663 };
const APP = 15368;
const RULESET = 21367637;
const WORKFLOW = '.github/workflows/merge-authority.yml';
// Owner-authenticated source ruleset revision, history version 47541914.
// GitHub redacts bypass_actors without Administration:write; never grant that
// capability to Actions merely to read it. Any ruleset edit invalidates this pin.
const RULESET_UPDATED_AT = '2026-08-25T03:09:48.838Z';
const SHA = /^[0-9a-f]{40}$/;
const ID = /^[1-9][0-9]*$/;
const PROFILE = /^(LOW_FOCUSED|MEDIUM_DOMAIN|HIGH_DEEP|HIGH_FINANCIAL|RELEASE_CERT)$/;
const fail = message => { throw new Error(`merge-authority-error: ${message}`); };
const need = (condition, message) => { if (!condition) fail(message); };
const equal = (actual, expected, label) => need(isDeepStrictEqual(actual, expected), label);
const digest = value => createHash('sha256').update(JSON.stringify(value)).digest('hex');

function owner(record) {
  equal(record.user?.login, OWNER.login, 'record actor login');
  equal(record.user?.id, OWNER.id, 'record actor ID');
  equal(record.author_association, 'OWNER', 'record actor association');
}

function lines(body) {
  need(typeof body === 'string' && !/[`~<>\r]/.test(body), 'quoted/fenced/HTML record');
  return body.split('\n').filter(line => line !== '');
}

function recordFields(record, issue, header, role, terminal) {
  owner(record);
  equal(record.issue_url, `https://api.github.com/repos/${REPO}/issues/${issue}`, 'canonical Issue record');
  need(record.created_at && record.created_at === record.updated_at, 'edited/missing record timestamp');
  const body = lines(record.body);
  equal(body.shift(), header, 'canonical record header');
  equal(body.pop(), terminal, 'canonical record terminal');
  const fields = {};
  for (const line of body) {
    const match = /^([A-Za-z][A-Za-z _-]*): (\S(?:.*\S)?)$/.exec(line);
    need(match && !Object.hasOwn(fields, match[1]), 'malformed/duplicate record field');
    fields[match[1]] = match[2];
  }
  equal(fields['Record version'], 'merge-authority-v1', 'record version');
  equal(fields.Role, role, 'record role');
  return fields;
}

function commonFields(fields, common, extras) {
  const expected = { 'Record version': 'merge-authority-v1', ...common, ...extras };
  equal(Object.keys(fields).sort(), Object.keys(expected).sort(), 'unexpected/missing authority field');
  for (const [key, value] of Object.entries(expected)) equal(fields[key], value, `authority ${key}`);
}

function issueField(body, label) {
  const exact = body.split('\n').filter(line => line.startsWith(`- **${label}:** `));
  need(exact.length === 1 && body.split(`**${label}:**`).length === 2, `ambiguous Task Capsule ${label}`);
  const match = /^- \*\*[^*]+:\*\* `([^`]+)`$/.exec(exact[0]);
  need(match, `malformed Task Capsule ${label}`);
  return match[1];
}

function resolveTask(pr, issue) {
  const one = (pattern, label) => {
    const matches = [...(pr.body || '').matchAll(pattern)];
    need(matches.length === 1, `unique PR ${label}`);
    return matches[0][1];
  };
  const task = one(/^- Task: `(WOS-[A-Z0-9-]+)`$/gm, 'task');
  const issueNumber = one(/^- Canonical Issue: #([1-9][0-9]*)$/gm, 'Issue');
  if (issue) {
    owner(issue);
    equal(String(issue.number), issueNumber, 'resolved Issue number');
    need(!issue.pull_request && issue.state === 'open' && issue.title.includes(task), 'canonical open Issue');
    equal(issueField(issue.body, 'Task'), task, 'resolved Task Capsule');
  }
  return { task, issue: issueNumber };
}

function nativeContext(event, env) {
  equal(env.GITHUB_REPOSITORY, REPO, 'native repository');
  equal(env.GITHUB_EVENT_NAME, 'pull_request', 'native event');
  equal(event.action, 'ready_for_review', 'native action');
  equal(event.repository?.full_name, REPO, 'event repository');
  equal(event.sender?.login, OWNER.login, 'ready actor login');
  equal(event.sender?.id, OWNER.id, 'ready actor ID');
  const pr = event.pull_request;
  need(pr && ID.test(String(event.number)) && pr.number === event.number, 'event PR');
  equal(pr.state, 'open', 'event open PR');
  equal(pr.draft, false, 'event ready PR');
  equal(pr.base.ref, 'main', 'event base branch');
  equal(pr.base.repo.full_name, REPO, 'event base repository');
  equal(pr.head.repo.full_name, REPO, 'event head repository');
  const ref = `refs/pull/${event.number}/merge`;
  equal(env.GITHUB_REF, ref, 'native merge ref');
  need(SHA.test(env.GITHUB_SHA) && SHA.test(pr.head.sha) && SHA.test(pr.base.sha), 'native Git objects');
  equal(env.GITHUB_SHA, pr.merge_commit_sha, 'event merge candidate');
  equal(env.GITHUB_WORKFLOW_REF, `${REPO}/${WORKFLOW}@${ref}`, 'native workflow ref');
  equal(env.GITHUB_WORKFLOW_SHA, env.GITHUB_SHA, 'native workflow source');
  need(ID.test(env.GITHUB_RUN_ID), 'native run ID');
  equal(env.GITHUB_RUN_ATTEMPT, '1', 'fresh ready event, not rerun');
  need(Number.isFinite(Date.parse(pr.updated_at)), 'ready event timestamp');
  return { pr: String(event.number), head: pr.head.sha, base: pr.base.sha, candidate: env.GITHUB_SHA,
    ref, readyAt: pr.updated_at, run: Number(env.GITHUB_RUN_ID) };
}

function selectGate(comments, input) {
  const header = `## Merge Human Gate — ${input.task}`;
  const terminal = `HUMAN_GATE_APPROVED: ${input.task} / PR #${input.pr} / exact head ${input.head}`;
  // Select the latest direct positive record, then authenticate it completely.
  // Do not fall back to an older approval when that record is malformed/edited.
  const candidates = comments.filter(record => record.body?.split('\n')[0] === header &&
    record.body.trimEnd().endsWith(terminal) && record.user?.id === OWNER.id);
  candidates.sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at) || b.id - a.id);
  need(candidates.length > 0, 'missing current-head Human Gate');
  const selected = candidates[0];
  recordFields(selected, input.issue, header, 'repository_owner', terminal);
  return selected;
}

function checkResult(check, sha, suite) {
  equal(check.name, 'Required CI', 'protected check name');
  equal(check.head_sha, sha, 'protected check SHA');
  equal(check.app?.id, APP, 'protected check app');
  equal(check.status, 'completed', 'protected check status');
  equal(check.conclusion, 'success', 'protected check conclusion');
  if (suite !== undefined) equal(check.check_suite?.id, suite, 'protected check suite');
}

function verifyRules(rules) {
  equal(rules.id, RULESET, 'ruleset ID');
  equal(rules.name, 'Protect main', 'ruleset name');
  equal(rules.target, 'branch', 'ruleset target');
  equal(rules.source_type, 'Repository', 'ruleset source type');
  equal(rules.source, REPO, 'ruleset source');
  equal(Date.parse(rules.updated_at), Date.parse(RULESET_UPDATED_AT), 'source-bound ruleset revision');
  equal(rules.enforcement, 'active', 'ruleset enforcement');
  if (Object.hasOwn(rules, 'bypass_actors')) equal(rules.bypass_actors, [], 'ruleset bypass');
  equal(rules.conditions?.ref_name, { exclude: [], include: ['~DEFAULT_BRANCH'] }, 'ruleset branch selection');
  equal(rules.rules.map(rule => rule.type).sort(), ['deletion', 'non_fast_forward', 'pull_request', 'required_status_checks'], 'ruleset rule types');
  const status = rules.rules.find(rule => rule.type === 'required_status_checks').parameters;
  equal(status.strict_required_status_checks_policy, true, 'strict checks');
  equal(status.do_not_enforce_on_create, false, 'checks on create');
  equal(status.required_status_checks, [{ context: 'Required CI', integration_id: APP }], 'required integration');
  const pr = rules.rules.find(rule => rule.type === 'pull_request').parameters;
  equal(pr, { allowed_merge_methods: ['squash'], dismiss_stale_reviews_on_push: false,
    require_code_owner_review: false, require_extra_approval_for_unattributed_changes: true,
    require_last_push_approval: false, required_approving_review_count: 0,
    required_review_thread_resolution: true, required_reviewers: [] }, 'PR rules unchanged');
}

function adverseCheckpoint(record, input, base, tree) {
  // Apply the same direct-record boundary to negative authority as to approval.
  // A token in an example, copied transcript, reference comment, or a different
  // role's evidence is data, not an Independent Review or human decision.
  const formats = {
    PRE_REVIEW_CHANGES_REQUIRED: ['Independent Codex PRE_REVIEW', 'independent_codex_reviewer'],
    TECHNICAL_CHANGES_REQUIRED: ['Independent Codex Technical Review', 'independent_codex_reviewer'],
    ACCEPTANCE_CHANGES_REQUIRED: ['Merge Acceptance', 'chatgpt_acceptance_reviewer'],
    HUMAN_GATE_REVOKED: ['Merge Human Gate', 'repository_owner'],
  };
  try {
    owner(record);
    const body = lines(record.body);
    const terminal = body[body.length - 1];
    const token = terminal?.split(': ')[0];
    if (!Object.hasOwn(formats, token)) return null;
    const [header, role] = formats[token];
    equal(body[0], `## ${header} — ${input.task}`, 'direct adverse header');
    equal(terminal, `${token}: ${input.task} / PR #${input.pr} / exact head ${input.head}`, 'direct adverse terminal');
    const required = { Role: role, 'Canonical Issue': `#${input.issue}`,
      'Exact base': base, 'Exact head': input.head, 'Exact head tree': tree };
    if (role === 'independent_codex_reviewer') {
      Object.assign(required, { 'Fresh context': 'yes', 'Executor session reused': 'no',
        'Source read-only/no-implementation-write': 'yes', 'Complete diff reviewed': 'yes' });
    } else {
      Object.assign(required, { 'Record version': 'merge-authority-v1', PR: `#${input.pr}` });
    }
    for (const [key, value] of Object.entries(required)) {
      equal(body.filter(line => line.includes(`${key}:`)), [`${key}: ${value}`], `direct adverse ${key}`);
    }
    equal(body.filter(line => /^(PRE_REVIEW_CHANGES_REQUIRED|TECHNICAL_CHANGES_REQUIRED|ACCEPTANCE_CHANGES_REQUIRED|HUMAN_GATE_REVOKED):/.test(line)), [terminal], 'unique adverse outcome');
    if (record.commit_id !== undefined) {
      equal(role, 'independent_codex_reviewer', 'PR review role');
      equal(record.commit_id, input.head, 'adverse PR review commit');
      need(Number.isFinite(Date.parse(record.submitted_at)), 'adverse review timestamp');
    } else {
      equal(record.issue_url, `https://api.github.com/repos/${REPO}/issues/${input.issue}`, 'adverse canonical Issue');
      need(record.created_at && record.created_at === record.updated_at && Number.isFinite(Date.parse(record.created_at)), 'immutable adverse Issue record');
    }
    return token;
  } catch {
    return null; // Unauthenticated/quoted evidence is never promoted to a stop.
  }
}

function verifySnapshot(s, input) {
  equal(input.repo, REPO, 'repository');
  need(ID.test(input.pr) && ID.test(input.issue) && /^WOS-[A-Z0-9-]+$/.test(input.task), 'task identifiers');
  need(SHA.test(input.head), 'expected head');
  equal(s.repository.full_name, REPO, 'repository identity');
  equal(s.repository.owner.login, OWNER.login, 'repository owner');
  equal(s.repository.owner.id, OWNER.id, 'repository owner ID');
  equal(s.repository.default_branch, 'main', 'default branch');
  owner(s.issue);
  need(!s.issue.pull_request && s.issue.state === 'open' && s.issue.title.includes(input.task), 'canonical task Issue');
  equal(issueField(s.issue.body, 'Task'), input.task, 'task ID');
  equal(issueField(s.issue.body, 'CI profile floor'), input.profile, 'CI floor');
  equal(issueField(s.issue.body, 'Assurance floor'), input.assurance, 'assurance floor');
  equal(issueField(s.issue.body, 'Independent review floor'), input.reviewFloor, 'review floor');
  need(s.pr.body.split('\n').filter(line => line === `- Canonical Issue: #${input.issue}`).length === 1, 'PR Issue binding');
  need(s.pr.body.split('\n').filter(line => line === `- Task: \`${input.task}\``).length === 1, 'PR task binding');
  equal(s.pr.state, 'open', 'open PR');
  equal(s.pr.draft, false, 'PR must be ready before binding candidate');
  equal(s.pr.base.ref, 'main', 'PR base branch');
  equal(s.pr.base.repo.full_name, REPO, 'PR base repository');
  equal(s.pr.head.repo.full_name, REPO, 'same-repository head');
  equal(s.pr.head.sha, input.head, 'unchanged PR head');
  equal(input.context.pr, input.pr, 'event PR binding');
  equal(input.context.head, input.head, 'event head binding');
  equal(input.context.base, s.pr.base.sha, 'event base drift');
  equal(input.context.candidate, s.pr.merge_commit_sha, 'event candidate regeneration');
  equal(input.context.ref, `refs/pull/${input.pr}/merge`, 'native ref binding');
  equal(s.main.object.sha, s.pr.base.sha, 'current main/base');
  equal(s.pr.mergeable, true, 'mergeable PR');
  equal(s.candidate.sha, s.pr.merge_commit_sha, 'current merge candidate');
  need(SHA.test(s.candidate.sha) && SHA.test(s.pr.base.sha) && SHA.test(s.head.tree.sha), 'exact Git objects');
  equal(s.candidate.parents.map(parent => parent.sha), [s.pr.base.sha, input.head], 'merge candidate parents');
  equal(s.candidate.tree.sha, s.head.tree.sha, 'candidate must equal certified source tree');
  equal(s.head.sha, input.head, 'head Git object');
  equal(s.threads, 0, 'unresolved review threads');
  verifyRules(s.rules);

  const { profile, assurance, review_required: reviewed } = s.classification;
  need(PROFILE.test(profile) && /^(LOW|MEDIUM|HIGH)$/.test(assurance) && /^(true|false)$/.test(reviewed), 'base classification');
  need(!profile.startsWith('HIGH_') && profile !== 'RELEASE_CERT' || reviewed === 'true', 'HIGH requires review');
  const reviewRef = reviewed === 'true' ? input.preReview : 'none';
  need(reviewed === 'true' ? /^(issue-comment|pr-review):[1-9][0-9]*$/.test(reviewRef) : !input.preReview, 'review authority selection');
  const gateTerminal = `HUMAN_GATE_APPROVED: ${input.task} / PR #${input.pr} / exact head ${input.head}`;
  const gate = recordFields(s.gate, input.issue, `## Merge Human Gate — ${input.task}`, 'repository_owner', gateTerminal);
  const evidenceKind = reviewed === 'true' ? 'TECHNICAL_ACCEPTED' : 'EXECUTOR_EVIDENCE_READY';
  const evidenceTerminal = `${evidenceKind}: ${input.task} / PR #${input.pr} / exact head ${input.head}`;
  const evidence = recordFields(s.evidence, input.issue, `## Merge CI evidence — ${input.task}`, 'codex_executor', evidenceTerminal);
  const acceptance = recordFields(s.acceptance, input.issue, `## Merge Acceptance — ${input.task}`, 'chatgpt_acceptance_reviewer', `ACCEPTANCE_ACCEPTED: ${input.task} / PR #${input.pr} / exact head ${input.head}`);
  const common = {
    'Canonical Issue': `#${input.issue}`, PR: `#${input.pr}`, 'Exact base': s.pr.base.sha,
    'Exact head': input.head, 'Exact head tree': s.head.tree.sha, 'CI profile': profile,
    Assurance: assurance, 'Review required': reviewed, 'PRE_REVIEW authority': reviewRef,
    'FINAL run': String(s.final.id), 'FINAL attempt': String(s.final.run_attempt), Artifacts: '0',
  };
  commonFields(evidence, common, { Role: 'codex_executor', 'Evidence kind': evidenceKind });
  commonFields(acceptance, common, { Role: 'chatgpt_acceptance_reviewer', 'Evidence authority': `issue-comment:${s.evidence.id}` });
  commonFields(gate, common, {
    Role: 'repository_owner', 'Evidence authority': `issue-comment:${s.evidence.id}`,
    'Acceptance authority': `issue-comment:${s.acceptance.id}`, 'Human command': `Finalize ${input.task}`,
    'Merge candidate': s.candidate.sha, 'Merge candidate tree': s.candidate.tree.sha,
    'Unresolved review threads': '0', 'PR state': 'draft',
  });
  equal(`issue-comment:${s.gate.id}`, input.gate, 'selected Human Gate ID');
  equal(s.final.event, 'workflow_dispatch', 'FINAL event');
  equal(s.final.path, '.github/workflows/ci.yml', 'FINAL workflow path');
  equal(s.final.head_sha, input.head, 'FINAL head');
  equal(s.final.head_branch, s.pr.head.ref, 'FINAL branch');
  equal(s.final.repository.full_name, REPO, 'FINAL repository');
  equal(s.final.status, 'completed', 'FINAL status');
  equal(s.final.conclusion, 'success', 'FINAL conclusion');
  equal(s.finalArtifacts.total_count, 0, 'FINAL artifacts');
  const required = s.jobs.filter(job => job.name === 'Required CI');
  need(required.length === 1 && required[0].conclusion === 'success' && required[0].status === 'completed', 'exact successful FINAL Required CI');
  equal(required[0].run_id, s.final.id, 'FINAL job run');
  const binding = `FINAL binding / ${input.task} / ${profile} / ${s.pr.base.sha} / ${reviewRef}`;
  need(s.jobs.filter(job => job.name === binding && job.status === 'completed' && job.conclusion === 'success').length === 1, 'FINAL exact task/profile/base/review binding');
  checkResult(s.finalCheck, input.head, s.final.check_suite_id);
  equal(s.finalCheck.id, required[0].id, 'FINAL check identity');
  if (reviewed === 'true') {
    owner(s.review);
    equal(s.review.commit_id || input.head, input.head, 'review commit');
    need(s.reviewVerified === true, 'base-owned PRE_REVIEW validator');
    const reviewedAt = s.review.submitted_at || s.review.created_at;
    need(Date.parse(reviewedAt) <= Date.parse(s.final.created_at), 'FINAL must follow review');
  }
  const times = [s.final.updated_at, s.evidence.created_at, s.acceptance.created_at, s.gate.created_at, input.context.readyAt, s.bridge.created_at].map(Date.parse);
  need(times.every(Number.isFinite) && times.every((time, i) => i === 0 || times[i - 1] <= time), 'authority chronology');
  const ready = s.readyEvents.filter(event => Date.parse(event.created_at) >= Date.parse(s.gate.created_at));
  need(ready.length === 1, 'Human Gate requires exactly one ready transition');
  equal(ready[0].created_at, input.context.readyAt, 'ready timeline/event binding');
  equal(ready[0].actor?.id, OWNER.id, 'ready timeline actor');
  equal(s.bridge.id, input.context.run, 'native run identity');
  equal(s.bridge.event, 'pull_request', 'bridge event');
  equal(s.bridge.path, WORKFLOW, 'bridge path');
  equal(s.bridge.repository?.full_name, REPO, 'native run repository');
  // Actions REST identifies a PR-native run/suite/check by the branch head.
  // The immutable event/runner context above separately binds its merge SHA.
  equal(s.bridge.head_sha, input.head, 'native run head');
  equal(s.bridge.head_branch, s.pr.head.ref, 'native run branch');
  equal(s.bridge.run_attempt, 1, 'bridge requires fresh ready event, not rerun');
  equal(s.bridge.status, 'in_progress', 'native job must be running');
  const associated = records => need(records?.some(pr => String(pr.number) === input.pr &&
    pr.head.sha === input.head && pr.base.sha === s.pr.base.sha), 'native PR association');
  associated(s.bridge.pull_requests);
  equal(s.bridgeSuite.id, s.bridge.check_suite_id, 'native suite identity');
  equal(s.bridgeSuite.app?.id, APP, 'native suite app');
  equal(s.bridgeSuite.head_sha, input.head, 'native suite head');
  associated(s.bridgeSuite.pull_requests);
  equal(s.bridgeCheck.name, 'Required CI', 'native protected job');
  equal(s.bridgeCheck.head_sha, input.head, 'native check PR head');
  equal(s.bridgeCheck.app?.id, APP, 'native check app');
  equal(s.bridgeCheck.check_suite?.id, s.bridgeSuite.id, 'native check suite');
  equal(s.bridgeCheck.status, 'in_progress', 'native check must run, not skip');
  equal(s.bridgeCheck.conclusion, null, 'native check not pre-completed');
  equal(s.bridgeArtifacts.total_count, 0, 'bridge artifacts');
  for (const record of [...s.comments, ...s.reviews]) {
    const adverse = adverseCheckpoint(record, input, s.pr.base.sha, s.head.tree.sha);
    if (!adverse) continue;
    const baseline = adverse === 'ACCEPTANCE_CHANGES_REQUIRED' ? s.acceptance.created_at :
      adverse === 'HUMAN_GATE_REVOKED' ? s.gate.created_at :
        (s.review?.submitted_at || s.review?.created_at || s.final.created_at);
    if (Date.parse(record.submitted_at || record.created_at) >= Date.parse(baseline)) fail('later adverse governance checkpoint');
  }
  return { version: 'merge-authority-v1', repository: REPO, task: input.task, issue: input.issue, pr: input.pr,
    base: s.pr.base.sha, head: input.head, tree: s.head.tree.sha, candidate: s.candidate.sha,
    profile, assurance, review: reviewRef, final: s.final.id, finalAttempt: s.final.run_attempt,
    evidence: s.evidence.id, acceptance: s.acceptance.id, humanGate: s.gate.id,
    recordDigest: digest([s.evidence, s.acceptance, s.gate, s.review || null]), artifacts: 0, unresolvedThreads: 0,
    bridge: s.bridge.id, nativeRef: input.context.ref, nativeCheck: s.bridgeCheck.id, nativeSuite: s.bridgeSuite.id,
    ruleset: RULESET, rulesetRevision: RULESET_UPDATED_AT, app: APP };
}

async function verifyNative(input, collect) {
  const attestation = verifySnapshot(await collect(), input);
  equal(verifySnapshot(await collect(), input), attestation, 'authority drift before native completion');
  // The current job cannot already be successful/clean while it is running.
  // Finalize must authenticate its completed result and live clean state.
  return attestation;
}

function liveApi(endpoint, method = 'GET', payload) {
  need(method === 'GET' || (endpoint === 'graphql' && method === 'POST' && /^query\b/.test(payload?.query || '')), 'read-only API');
  const args = ['api', endpoint, '--method', method];
  if (payload) args.push('--input', '-');
  return JSON.parse(execFileSync('gh', args, { input: payload ? JSON.stringify(payload) : undefined, encoding: 'utf8', timeout: 30000, maxBuffer: 16 * 1024 * 1024 }));
}

function paginate(endpoint, field) {
  const pages = JSON.parse(execFileSync('gh', ['api', endpoint, '--paginate', '--slurp'], { encoding: 'utf8', timeout: 30000, maxBuffer: 16 * 1024 * 1024 }));
  return pages.flatMap(page => field ? page[field] : page);
}

function comment(ref) {
  need(/^issue-comment:[1-9][0-9]*$/.test(ref), 'canonical Issue comment reference');
  return liveApi(`repos/${REPO}/issues/comments/${ref.split(':')[1]}`);
}

function rawField(record, key) {
  const matches = (record.body || '').split('\n').filter(line => line.startsWith(`${key}: `));
  need(matches.length === 1, `missing/ambiguous ${key}`);
  return matches[0].slice(key.length + 2);
}

function classify(pr, input, raised = true) {
  const args = [process.env.WCOS_BASE_CLASSIFIER, 'pull_request', pr.base.sha, input.head, pr.head.ref, '',
    raised ? input.profile : '', raised ? input.assurance : '', raised ? input.reviewFloor : ''];
  const text = execFileSync('bash', args, { encoding: 'utf8' });
  const values = Object.fromEntries(text.trim().split('\n').map(line => {
    const at = line.indexOf('='); return [line.slice(0, at), line.slice(at + 1)];
  }));
  return { profile: values.ci_profile, assurance: values.assurance_profile, review_required: values.independent_review_required };
}

function directScope(task, pr, issue, classification) {
  // Exclusion is not Direct approval and emits no protected check. A branch or
  // label alone cannot exclude semantic/governed work from the native bridge.
  const optionalDirectField = (label, value) => !issue.body.includes(`**${label}:**`) || issueField(issue.body, label) === value;
  return /^WOS-DIRECT-[0-9]{8}-[0-9]{6}$/.test(task) &&
    pr.head.ref === `codex/direct/${task.toLowerCase()}` &&
    optionalDirectField('CI profile floor', 'DIRECT_FAST') && optionalDirectField('Assurance floor', 'DIRECT') &&
    optionalDirectField('Independent review floor', 'OPTIONAL') &&
    classification.profile === 'DIRECT_FAST' && classification.assurance === 'DIRECT' && classification.review_required === 'false';
}

function resolveLive(context) {
  const pr = liveApi(`repos/${REPO}/pulls/${context.pr}`);
  equal(pr.head.sha, context.head, 'resolution head drift');
  equal(pr.base.sha, context.base, 'resolution base drift');
  equal(pr.merge_commit_sha, context.candidate, 'resolution candidate drift');
  const task = resolveTask(pr);
  const issue = liveApi(`repos/${REPO}/issues/${task.issue}`);
  resolveTask(pr, issue);
  const input = { repo: REPO, ...task, pr: context.pr, head: context.head, context };
  if (directScope(task.task, pr, issue, classify(pr, input, false))) return { route: 'direct', input };
  Object.assign(input, { profile: issueField(issue.body, 'CI profile floor'), assurance: issueField(issue.body, 'Assurance floor'),
    reviewFloor: issueField(issue.body, 'Independent review floor') });
  need(PROFILE.test(input.profile) && /^(LOW|MEDIUM|HIGH)$/.test(input.assurance) && /^(OPTIONAL|REQUIRED)$/.test(input.reviewFloor), 'normal task floors');
  return { route: 'governed', input };
}

function collectLive(input) {
  const repository = liveApi(`repos/${REPO}`);
  const pr = liveApi(`repos/${REPO}/pulls/${input.pr}`);
  const rules = liveApi(`repos/${REPO}/rulesets/${RULESET}`);
  verifyRules(rules);
  console.log(`merge-authority-rules-read-ok revision=${rules.updated_at} bypass-field=${Object.hasOwn(rules, 'bypass_actors') ? 'visible' : 'redacted'}`);
  const comments = paginate(`repos/${REPO}/issues/${input.issue}/comments?per_page=100`);
  const gate = selectGate(comments, input);
  equal(`issue-comment:${gate.id}`, input.gate, 'latest Human Gate unchanged');
  const evidence = comment(rawField(gate, 'Evidence authority'));
  const acceptance = comment(rawField(gate, 'Acceptance authority'));
  const finalId = rawField(gate, 'FINAL run');
  need(ID.test(finalId), 'FINAL run ID');
  const final = liveApi(`repos/${REPO}/actions/runs/${finalId}`);
  const jobs = paginate(`repos/${REPO}/actions/runs/${finalId}/attempts/${final.run_attempt}/jobs?per_page=100`, 'jobs');
  const required = jobs.filter(job => job.name === 'Required CI');
  need(required.length === 1, 'FINAL protected job count');
  const classification = classify(pr, input);
  const head = liveApi(`repos/${REPO}/git/commits/${input.head}`);
  execFileSync('git', ['merge-base', '--is-ancestor', pr.base.sha, input.head]);
  let review;
  let reviewVerified = false;
  if (input.preReview) {
    need(/^(issue-comment|pr-review):[1-9][0-9]*$/.test(input.preReview), 'review reference');
    review = input.preReview.startsWith('issue-comment:') ? comment(input.preReview) : liveApi(`repos/${REPO}/pulls/${input.pr}/reviews/${input.preReview.split(':')[1]}`);
    execFileSync('bash', [process.env.WCOS_PRE_REVIEW_VERIFIER, REPO, input.pr, input.task, input.issue, pr.base.sha, input.head, head.tree.sha, classification.profile, input.preReview], { stdio: ['ignore', 'pipe', 'inherit'] });
    reviewVerified = true;
  }
  let cursor = null;
  let threads = 0;
  do {
    const query = 'query($pr:Int!,$cursor:String){repository(owner:"yoohwz",name:"wc-order-splitter"){pullRequest(number:$pr){reviewThreads(first:100,after:$cursor){nodes{isResolved}pageInfo{hasNextPage endCursor}}}}}';
    const result = liveApi('graphql', 'POST', { query, variables: { pr: Number(input.pr), cursor } });
    need(!result.errors, 'review thread query');
    const page = result.data.repository.pullRequest.reviewThreads;
    threads += page.nodes.filter(thread => !thread.isResolved).length;
    cursor = page.pageInfo.hasNextPage ? page.pageInfo.endCursor : null;
  } while (cursor);
  const bridge = liveApi(`repos/${REPO}/actions/runs/${input.context.run}`);
  const bridgeJobs = paginate(`repos/${REPO}/actions/runs/${bridge.id}/attempts/1/jobs?per_page=100`, 'jobs');
  const native = bridgeJobs.filter(job => job.name === 'Required CI');
  need(native.length === 1 && native[0].run_id === bridge.id && native[0].status === 'in_progress', 'one executing native protected job');
  return { repository, pr, gate, evidence, acceptance, final, jobs, head, review, reviewVerified, threads, classification,
    issue: liveApi(`repos/${REPO}/issues/${input.issue}`), main: liveApi(`repos/${REPO}/git/ref/heads/main`),
    candidate: liveApi(`repos/${REPO}/git/commits/${pr.merge_commit_sha}`), rules,
    finalArtifacts: liveApi(`repos/${REPO}/actions/runs/${finalId}/artifacts`), finalCheck: liveApi(`repos/${REPO}/check-runs/${required[0].id}`),
    bridge, bridgeArtifacts: liveApi(`repos/${REPO}/actions/runs/${bridge.id}/artifacts`),
    bridgeSuite: liveApi(`repos/${REPO}/check-suites/${bridge.check_suite_id}`),
    bridgeCheck: liveApi(`repos/${REPO}/check-runs/${native[0].id}`),
    readyEvents: paginate(`repos/${REPO}/issues/${input.pr}/timeline?per_page=100`).filter(event => event.event === 'ready_for_review'),
    comments, reviews: paginate(`repos/${REPO}/pulls/${input.pr}/reviews?per_page=100`) };
}

if (require.main === module) {
  (async () => {
    const context = nativeContext(JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8')), process.env);
    const { route, input } = resolveLive(context);
    if (process.argv[2] === '--scope') { console.log(`route=${route}`); return; }
    equal(process.argv[2], '--verify', 'native verifier mode');
    equal(route, 'governed', 'DIRECT must not enter normal bridge');
    const gate = selectGate(paginate(`repos/${REPO}/issues/${input.issue}/comments?per_page=100`), input);
    input.gate = `issue-comment:${gate.id}`;
    const review = rawField(gate, 'PRE_REVIEW authority');
    input.preReview = review === 'none' ? '' : review;
    const result = await verifyNative(input, () => collectLive(input));
    console.log(`native-merge-authority-verified ${JSON.stringify(result)}`);
    console.log('artifacts=0; Finalize must verify completed native check and GitHub clean before merge');
  })().catch(error => { console.error(error.message); process.exitCode = 1; });
}

module.exports = { verifySnapshot, verifyNative, nativeContext, resolveTask, directScope, selectGate, checkResult, recordFields, issueField, verifyRules };
