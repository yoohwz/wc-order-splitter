'use strict';

// This program only materializes an already approved decision; it never creates
// review, Acceptance, Human Gate, merge, or publication authority.
const { execFileSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');

const REPO = 'yoohwz/wc-order-splitter';
const OWNER = { login: 'yoohwz', id: 152001663 };
const APP = 15368;
const RULESET = 21367637;
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
  equal(rules.enforcement, 'active', 'ruleset enforcement');
  equal(rules.bypass_actors, [], 'ruleset bypass');
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
    'Unresolved review threads': '0',
  });
  equal(`issue-comment:${s.gate.id}`, input.gate, 'dispatch Human Gate ID');
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
  const times = [s.final.updated_at, s.evidence.created_at, s.acceptance.created_at, s.gate.created_at, s.bridge.created_at].map(Date.parse);
  need(times.every(Number.isFinite) && times.every((time, i) => i === 0 || times[i - 1] <= time), 'authority chronology');
  equal(s.bridge.event, 'workflow_dispatch', 'bridge event');
  equal(s.bridge.path, '.github/workflows/ci.yml', 'bridge path');
  equal(s.bridge.head_sha, input.head, 'bridge dispatch head');
  equal(s.bridge.run_attempt, 1, 'bridge requires fresh dispatch, not rerun');
  equal(s.bridgeArtifacts.total_count, 0, 'bridge artifacts');
  for (const record of [...s.comments, ...s.reviews]) {
    if (record.user?.id !== OWNER.id || record.author_association !== 'OWNER') continue;
    const body = record.body || '';
    // Later direct adverse checkpoints invalidate the decision; quoted tokens
    // are not authority. Re-dispatch cannot erase a correction/revocation.
    for (const line of body.split('\n')) {
      const adverse = /^(PRE_REVIEW_CHANGES_REQUIRED|TECHNICAL_CHANGES_REQUIRED|ACCEPTANCE_CHANGES_REQUIRED|HUMAN_GATE_REVOKED): /.exec(line);
      if (!adverse || !line.includes(input.task) || !line.includes(input.head)) continue;
      const baseline = adverse[1] === 'ACCEPTANCE_CHANGES_REQUIRED' ? s.acceptance.created_at :
        adverse[1] === 'HUMAN_GATE_REVOKED' ? s.gate.created_at :
          (s.review?.submitted_at || s.review?.created_at || s.final.created_at);
      if (Date.parse(record.created_at || record.submitted_at) >= Date.parse(baseline)) fail('later adverse governance checkpoint');
    }
  }
  return { version: 'merge-authority-v1', repository: REPO, task: input.task, issue: input.issue, pr: input.pr,
    base: s.pr.base.sha, head: input.head, tree: s.head.tree.sha, candidate: s.candidate.sha,
    profile, assurance, review: reviewRef, final: s.final.id, finalAttempt: s.final.run_attempt,
    evidence: s.evidence.id, acceptance: s.acceptance.id, humanGate: s.gate.id,
    recordDigest: digest([s.evidence, s.acceptance, s.gate, s.review || null]), artifacts: 0, unresolvedThreads: 0,
    bridge: s.bridge.id, ruleset: RULESET, app: APP };
}

async function materialize(input, collect, api, wait = ms => new Promise(resolve => setTimeout(resolve, ms))) {
  let created;
  const attestation = verifySnapshot(await collect(), input);
  const external = `wcos-merge-authority-v1:${input.pr}:${attestation.bridge}:1`;
  const output = { title: 'Exact merge-candidate authority', summary: JSON.stringify(attestation) };
  try {
    created = await api(`repos/${REPO}/check-runs`, 'POST', { name: 'Required CI', head_sha: attestation.candidate,
      status: 'in_progress', external_id: external, details_url: `https://github.com/${REPO}/actions/runs/${attestation.bridge}`, output });
    need(ID.test(String(created.id)), 'created check identity');
    equal(created.app?.id, APP, 'writer must be GitHub Actions');
    equal(created.head_sha, attestation.candidate, 'created candidate SHA');
    equal(verifySnapshot(await collect(), input), attestation, 'authority drift before success');
    await api(`repos/${REPO}/check-runs/${created.id}`, 'PATCH', { status: 'completed', conclusion: 'success', output });
    const check = await api(`repos/${REPO}/check-runs/${created.id}`);
    checkResult(check, attestation.candidate);
    equal(check.external_id, external, 'bridge external ID');
    equal(check.output.summary, output.summary, 'bridge attestation');
    equal(verifySnapshot(await collect(), input), attestation, 'authority drift after success');
    let recognized = false;
    for (let attempt = 0; attempt < 10; attempt++) {
      const live = await api(`repos/${REPO}/pulls/${input.pr}`);
      equal(live.head.sha, attestation.head, 'live head drift');
      equal(live.base.sha, attestation.base, 'live base drift');
      equal(live.merge_commit_sha, attestation.candidate, 'live candidate regeneration');
      if (live.mergeable === true && live.mergeable_state === 'clean') { recognized = true; break; }
      await wait(1000);
    }
    need(recognized, 'GitHub has not recognized merge-candidate authority; do not merge');
    return { ...attestation, check: created.id, githubMergeState: 'clean' };
  } catch (error) {
    if (created?.id) {
      // Failure/cancellation never deliberately leaves a successful check.
      await api(`repos/${REPO}/check-runs/${created.id}`, 'PATCH', { status: 'completed', conclusion: 'failure',
        output: { title: 'Merge authority invalidated', summary: error.message } });
    }
    throw error;
  }
}

function liveApi(endpoint, method = 'GET', payload) {
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

function collectLive(input) {
  const repository = liveApi(`repos/${REPO}`);
  const pr = liveApi(`repos/${REPO}/pulls/${input.pr}`);
  const gate = comment(input.gate);
  const evidence = comment(rawField(gate, 'Evidence authority'));
  const acceptance = comment(rawField(gate, 'Acceptance authority'));
  const finalId = rawField(gate, 'FINAL run');
  need(ID.test(finalId), 'FINAL run ID');
  const final = liveApi(`repos/${REPO}/actions/runs/${finalId}`);
  const jobs = paginate(`repos/${REPO}/actions/runs/${finalId}/attempts/${final.run_attempt}/jobs?per_page=100`, 'jobs');
  const required = jobs.filter(job => job.name === 'Required CI');
  need(required.length === 1, 'FINAL protected job count');
  const classificationText = execFileSync('bash', [process.env.WCOS_BASE_CLASSIFIER, 'pull_request', pr.base.sha, input.head, pr.head.ref, '', input.profile, input.assurance, input.reviewFloor], { encoding: 'utf8' });
  const classification = Object.fromEntries(classificationText.trim().split('\n').filter(line => line.startsWith('ci_') || line.startsWith('assurance_') || line.startsWith('independent_review_')).map(line => {
    const at = line.indexOf('='); return [line.slice(0, at), line.slice(at + 1)];
  }));
  const head = liveApi(`repos/${REPO}/git/commits/${input.head}`);
  execFileSync('git', ['merge-base', '--is-ancestor', pr.base.sha, input.head]);
  let review;
  let reviewVerified = false;
  if (input.preReview) {
    need(/^(issue-comment|pr-review):[1-9][0-9]*$/.test(input.preReview), 'review reference');
    review = input.preReview.startsWith('issue-comment:') ? comment(input.preReview) : liveApi(`repos/${REPO}/pulls/${input.pr}/reviews/${input.preReview.split(':')[1]}`);
    execFileSync('bash', [process.env.WCOS_PRE_REVIEW_VERIFIER, REPO, input.pr, input.task, input.issue, pr.base.sha, input.head, head.tree.sha, classification.ci_profile, input.preReview], { stdio: ['ignore', 'pipe', 'inherit'] });
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
  return { repository, pr, gate, evidence, acceptance, final, jobs, head, review, reviewVerified, threads,
    classification: { profile: classification.ci_profile, assurance: classification.assurance_profile, review_required: classification.independent_review_required },
    issue: liveApi(`repos/${REPO}/issues/${input.issue}`), main: liveApi(`repos/${REPO}/git/ref/heads/main`),
    candidate: liveApi(`repos/${REPO}/git/commits/${pr.merge_commit_sha}`), rules: liveApi(`repos/${REPO}/rulesets/${RULESET}`),
    finalArtifacts: liveApi(`repos/${REPO}/actions/runs/${finalId}/artifacts`), finalCheck: liveApi(`repos/${REPO}/check-runs/${required[0].id}`),
    bridge: liveApi(`repos/${REPO}/actions/runs/${process.env.GITHUB_RUN_ID}`), bridgeArtifacts: liveApi(`repos/${REPO}/actions/runs/${process.env.GITHUB_RUN_ID}/artifacts`),
    comments: paginate(`repos/${REPO}/issues/${input.issue}/comments?per_page=100`), reviews: paginate(`repos/${REPO}/pulls/${input.pr}/reviews?per_page=100`) };
}

if (require.main === module) {
  const input = { repo: process.env.GITHUB_REPOSITORY, pr: process.env.INPUT_PR_NUMBER, issue: process.env.INPUT_TASK_ISSUE_NUMBER,
    task: process.env.INPUT_TASK_ID, head: process.env.EXPECTED_HEAD_SHA, profile: process.env.INPUT_REQUESTED_PROFILE,
    assurance: process.env.INPUT_REQUESTED_ASSURANCE, reviewFloor: process.env.INPUT_REVIEW_FLOOR,
    preReview: process.env.PRE_REVIEW_AUTHORITY || '', gate: process.env.MERGE_AUTHORITY };
  need(process.env.GITHUB_EVENT_NAME === 'workflow_dispatch' && process.env.GITHUB_SHA === input.head && process.env.GITHUB_RUN_ATTEMPT === '1', 'exact first-attempt dispatch');
  materialize(input, () => collectLive(input), liveApi).then(result => console.log(`merge-authority-ok ${JSON.stringify(result)}`)).catch(error => { console.error(error.message); process.exitCode = 1; });
}

module.exports = { verifySnapshot, materialize, checkResult, recordFields, issueField };
