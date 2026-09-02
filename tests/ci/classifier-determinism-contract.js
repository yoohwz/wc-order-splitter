'use strict';

// Exercise real git/sed/grep and pipe scheduling, including the exact complete
// GOV-010 zero-context diff. No network or unmerged Git objects are required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { execFileSync, spawnSync } = require('node:child_process');

const repo = path.resolve(__dirname, '../..');
const classifier = path.join(repo, '.github/scripts/classify-pr-scope.sh');
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'wcos-classifier-'));
const fixture = path.join(root, 'repo');
const bin = path.join(root, 'bin');
const pattern = '(^|[^[:alnum:]])(financial|settlement|total|totals|subtotal|fee|fees|coupon|coupons|transaction|currency|amount|tax|taxes|refund|payment|price|money|_reduced_stock|stock)([^[:alnum:]]|$)';
const tools = Object.fromEntries(['git', 'sed', 'grep'].map(tool => [tool,
  execFileSync('bash', ['-c', 'command -v "$1"', 'resolve', tool], { encoding: 'utf8' }).trim()]));
let assertions = 0;
const git = (...args) => execFileSync(tools.git, args, { cwd: fixture, encoding: 'utf8' }).trim();

try {
  fs.mkdirSync(fixture);
  fs.mkdirSync(bin);
  fs.mkdirSync(path.join(fixture, 'docs'));
  git('init', '--quiet');
  git('config', 'user.name', 'WOS CI Fixture');
  git('config', 'user.email', 'wos-ci@example.invalid');
  fs.writeFileSync(path.join(fixture, 'docs/scan.md'), 'Baseline\n');
  git('add', '.');
  git('commit', '--quiet', '-m', 'base');
  const base = git('rev-parse', 'HEAD');

  // Wrappers only alter scheduling or inject an explicit process failure. All
  // ordinary commands and the consuming sed/grep reader are the real tools.
  const wrapper = `#!${process.execPath}
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const tool = path.basename(process.argv[1]);
const args = process.argv.slice(2);
const real = ${JSON.stringify(tools)}[tool];
const fault = process.env.WCOS_SCAN_FAULT;
const diff = tool === 'git' && args[0] === 'diff' && args.includes('--unified=0');
if (fault === 'filter' && tool === 'sed' && args.includes('/^[+-][^+-]/p')) {
  fs.writeSync(2, 'injected-filter-failure\\n'); process.exit(2);
}
if (fault === 'scan' && tool === 'grep' && args.some(arg => arg.includes('financial|settlement'))) {
  fs.writeSync(2, 'injected-scan-failure\\n'); process.exit(2);
}
let bytes;
if (process.env.WCOS_SNAPSHOT && tool === 'git' && args[0] === 'diff') {
  const snapshot = JSON.parse(fs.readFileSync(process.env.WCOS_SNAPSHOT));
  if (args.includes('--name-status')) bytes = Buffer.from(snapshot.status);
  else if (diff) {
    const file = args[args.indexOf('--') + 1];
    if (!Object.hasOwn(snapshot.diffs, file)) throw new Error('missing snapshot path ' + file);
    bytes = Buffer.from(snapshot.diffs[file]);
  }
}
if (!bytes && !diff) {
  const result = spawnSync(real, args, { stdio: 'inherit' });
  if (result.error) throw result.error;
  process.exit(result.status === null ? 128 + require('node:os').constants.signals[result.signal] : result.status);
}
if (!bytes) {
  const result = spawnSync(real, args, { maxBuffer: 32 * 1024 * 1024 });
  if (result.error) throw result.error;
  fs.writeSync(2, result.stderr);
  if (result.status !== 0) process.exit(result.status === null ? 128 : result.status);
  bytes = result.stdout;
}
if (diff && fault === 'producer') {
  fs.writeSync(1, bytes.subarray(0, 1024));
  fs.writeSync(2, 'injected-producer-failure\\n'); process.exit(2);
}
const paced = diff && process.env.WCOS_PACED === '1';
const chunk = paced ? 512 : 65536;
try {
  for (let offset = 0; offset < bytes.length;) {
    offset += fs.writeSync(1, bytes.subarray(offset, offset + chunk));
    if (paced) Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 1);
  }
} catch (error) {
  if (error.code === 'EPIPE') process.exit(141);
  throw error;
}
`;
  for (const tool of Object.keys(tools)) fs.writeFileSync(path.join(bin, tool), wrapper, { mode: 0o755 });

  function run(head, options = {}) {
    const outputFile = path.join(root, 'output');
    fs.writeFileSync(outputFile, '');
    const result = spawnSync('bash', [classifier, 'pull_request', base, head, 'codex/wos-fixture', outputFile,
      options.floor || '', options.assurance || '', options.review || ''], {
      cwd: fixture, encoding: 'utf8', timeout: 60000,
      env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, WCOS_SCAN_FAULT: '', WCOS_PACED: '', WCOS_SNAPSHOT: '', ...options.env },
    });
    assert.equal(result.status, 0, result.stderr);
    const facts = Object.fromEntries(result.stdout.trim().split('\n').map(line => line.split('=')));
    assert.equal(Object.keys(facts).length, 7, result.stdout);
    const written = Object.fromEntries(fs.readFileSync(outputFile, 'utf8').trim().split('\n').map(line => line.split('=')));
    assert.deepEqual(Object.values(written), Object.values(facts), 'stdout and workflow output agree');
    if (options.env?.WCOS_SCAN_FAULT) assert.match(result.stderr, /injected-.*-failure/, 'fault was exercised');
    else assert.equal(result.stderr, '', 'successful scan must not generate broken-pipe diagnostics');
    return facts;
  }
  function expect(head, profile, reason, options = {}, count = 1) {
    let first;
    for (let i = 0; i < count; i++) {
      const facts = run(head, options);
      assert.equal(facts.ci_profile, profile);
      assert.equal(facts.ci_profile_reason, reason);
      assert.equal(facts.assurance_profile, 'HIGH');
      assert.equal(facts.independent_review_required, 'true');
      assert.equal(facts.ci_stage, 'PRECHECK');
      assert.equal(facts.storage_matrix, '["hpos"]');
      assert.equal(facts.affected_domain, options.domain || 'financial');
      if (first) assert.deepEqual(facts, first, 'all seven fields are deterministic');
      first = facts;
      assertions++;
    }
    return first;
  }
  function commit(content) {
    fs.writeFileSync(path.join(fixture, 'docs/scan.md'), content);
    git('add', '.');
    git('commit', '--quiet', '-m', 'case');
    return git('rev-parse', 'HEAD');
  }

  const padding = 'Governance verification detail without domain tokens.\n'.repeat(20000);
  const early = commit('stock\n' + padding);
  const normal = expect(early, 'HIGH_FINANCIAL', 'financial_or_stock_authority', {}, 8);
  const paced = expect(early, 'HIGH_FINANCIAL', 'financial_or_stock_authority', { env: { WCOS_PACED: '1' } }, 3);
  assert.deepEqual(paced, normal, 'producer scheduling cannot change output');
  const late = commit(padding + 'stock\n');
  expect(late, 'HIGH_FINANCIAL', 'financial_or_stock_authority', {}, 3);
  const noMatch = commit(padding);
  expect(noMatch, 'HIGH_DEEP', 'governance_or_ci_control_plane', { domain: 'control-plane' }, 3);
  expect(noMatch, 'HIGH_DEEP', 'governance_or_ci_control_plane', { domain: 'control-plane', env: { WCOS_PACED: '1' } });
  for (const floor of ['HIGH_FINANCIAL', 'RELEASE_CERT']) {
    expect(noMatch, floor, 'requested_profile_raise', { floor, assurance: 'HIGH', review: 'REQUIRED', domain: 'control-plane' }, 3);
  }
  for (const fault of ['producer', 'filter', 'scan']) {
    for (const head of [early, noMatch]) {
      expect(head, 'HIGH_FINANCIAL', 'unresolved_financial_content_scan', { env: { WCOS_SCAN_FAULT: fault } });
    }
    expect(noMatch, 'RELEASE_CERT', 'requested_profile_raise', { floor: 'RELEASE_CERT', env: { WCOS_SCAN_FAULT: fault } });
  }

  // Frozen bytes from base 545b82b4.. to replacement head 46ff3db8.., including
  // all eight paths. The hash proves tests do not silently rewrite the incident.
  const incident = fs.readFileSync(path.join(__dirname, 'fixtures/gov-010-replacement.diff'));
  assert.equal(createHash('sha256').update(incident).digest('hex'), '6a7a9ad8d477aa9a9e385ec6745afc85c7aee0ee4ec47172cad5c9cfcb2d7e3a');
  const diffs = Object.fromEntries(incident.toString().split(/(?=^diff --git )/m).filter(Boolean).map(section => {
    const file = /^diff --git a\/(\S+) b\/\1\n/.exec(section)?.[1];
    assert(file, 'canonical frozen diff path');
    return [file, section];
  }));
  assert.equal(Object.keys(diffs).length, 8);
  const snapshot = path.join(root, 'snapshot.json');
  fs.writeFileSync(snapshot, JSON.stringify({ diffs, status: Object.entries(diffs).map(([file, data]) =>
    `${data.includes('\nnew file mode ') ? 'A' : 'M'}\0${file}\0`).join('') }));
  const incidentNormal = expect(early, 'HIGH_FINANCIAL', 'financial_or_stock_authority', { env: { WCOS_SNAPSHOT: snapshot } }, 5);
  const incidentPaced = expect(early, 'HIGH_FINANCIAL', 'financial_or_stock_authority', { env: { WCOS_SNAPSHOT: snapshot, WCOS_PACED: '1' } }, 3);
  assert.deepEqual(incidentNormal, incidentPaced);

  // A faithful negative control retains the old early-reader guard. Require
  // grep success AND upstream SIGPIPE, not merely an arbitrary pipeline error.
  const oldGuard = `set -uo pipefail
git diff --unified=0 --no-ext-diff "$1" "$2" -- "$3" | sed -n '/^[+-][^+-]/p' | LC_ALL=C grep -Eiq "$4"
printf '%s\\n' "\${PIPESTATUS[*]}"
`;
  for (const file of ['docs/scan.md', '.github/scripts/merge-candidate-authority.js', 'tests/ci/merge-candidate-authority-contract.js']) {
    const result = execFileSync('bash', ['-c', oldGuard, 'guard', base, early, file, pattern], {
      cwd: fixture, encoding: 'utf8', env: { ...process.env, PATH: `${bin}:${process.env.PATH}`,
        WCOS_PACED: '1', WCOS_SCAN_FAULT: '', WCOS_SNAPSHOT: file === 'docs/scan.md' ? '' : snapshot },
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    const statuses = result.trim().split(' ').map(Number);
    assert.equal(statuses.length, 3);
    assert.equal(statuses[2], 0, 'old reader found a financial token');
    assert(statuses.slice(0, 2).includes(141), `old pipeline must reproduce upstream SIGPIPE: ${result}`);
    assertions++;
  }
  console.log(`classifier-determinism-contract-ok observations=${assertions} legacy-sigpipe=3 exact-gov010-paths=8`);
} finally {
  fs.rmSync(root, { recursive: true, force: true });
}
