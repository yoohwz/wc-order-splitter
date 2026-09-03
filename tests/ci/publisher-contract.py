#!/usr/bin/env python3
"""Publisher contracts: temporary local SVN only; all GitHub calls are fakes."""
import copy
from contextlib import contextmanager, redirect_stdout
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import Mock, patch
import urllib.error
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / '.github/scripts'))
import release_package as pkg
import release_github as gh
import release_cli as cli
import wporg_release as wp

BASE = '4de67108045714415d5bc4708bd94e7ad871e9a1'
CERT_HEAD = '350e81177085c753cfdabb16c7fbf5547f0266e9'
DIGEST = '2e118657e4b44d7db7e536c8e1a3054e9f9af6bcd6112d45141a6b30f427f072'


def run(*args):
    return subprocess.check_output(list(map(str, args)), stderr=subprocess.PIPE).decode().strip()


def yaml(path):
    return json.loads(run('ruby', '-ryaml', '-rjson', '-e', 'puts JSON.generate(YAML.load_file(ARGV[0]))', path))


def archive(values):
    output = io.BytesIO()
    with zipfile.ZipFile(output, 'w') as handle:
        for name, value in values.items():
            handle.writestr(name, value)
    return output.getvalue()


class FakeAPI:
    def __init__(self, values):
        self.values, self.calls = values, []

    def get(self, endpoint, **kwargs):
        self.calls.append((endpoint, kwargs))
        value = self.values[endpoint]
        return copy.deepcopy(value)

    def pages(self, endpoint, key=None):
        return self.get(endpoint)


class Product(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='wcos-publisher-contract-')
        cls.root = Path(cls.tmp.name)
        cls.source = cls.root / 'base'
        cls.source.mkdir()
        raw = subprocess.check_output(['git', '-C', str(ROOT), 'archive', BASE])
        with tarfile.open(fileobj=io.BytesIO(raw)) as handle:
            handle.extractall(cls.source, filter='data')
        cls.staged = cls.root / 'base-product'
        cls.base_digest = pkg.stage(cls.source, cls.staged)
        cls.manifest = pkg.build(cls.staged, cls.root / 'package', BASE, '1.5.0', DIGEST, 33727158970, 100)
        cls.zip = (cls.root / 'package' / cls.manifest['package_name']).read_bytes()

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def test_01_bootstrap_preserves_certified_product(self):
        staged = self.root / 'head-product'
        digest = pkg.stage(ROOT, staged)
        self.assertEqual(self.base_digest, DIGEST)
        self.assertEqual(digest, DIGEST)
        self.assertEqual(pkg.files(staged), pkg.files(self.staged))
        self.assertFalse((staged / '.github').exists())
        self.assertFalse((staged / 'tests').exists())
        self.assertFalse((staged / 'docs').exists())
        print(f'publisher-product-invariance-ok base={BASE} source={DIGEST} final={digest}')

    def test_02_deterministic_metadata_and_one_root(self):
        os.utime(self.staged / 'readme.txt', (2000000000, 2000000000))
        manifest = pkg.build(self.staged, self.root / 'package-b', BASE, '1.5.0', DIGEST, 33727158970, 100)
        self.assertEqual(manifest, self.manifest)
        self.assertEqual((self.root / 'package-b' / manifest['package_name']).read_bytes(), self.zip)
        with zipfile.ZipFile(io.BytesIO(self.zip)) as handle:
            self.assertEqual(handle.namelist(), sorted(handle.namelist()))
            for item in handle.infolist():
                self.assertTrue(item.filename.startswith('wc-order-splitter/'))
                self.assertEqual(item.date_time, pkg.FIXED_TIME)
                self.assertEqual(item.external_attr >> 16, stat.S_IFREG | 0o644)

    def test_03_package_manifest_and_product_tamper(self):
        payload = pkg.zip_data(self.zip, pkg.SLUG + '/')
        for kind in ('missing', 'extra', 'changed'):
            with self.subTest(kind=kind):
                values = dict(payload)
                if kind == 'missing':
                    values.pop('readme.txt')
                elif kind == 'extra':
                    values['intruder.php'] = b'not allowed'
                else:
                    values['readme.txt'] += b'changed'
                bad = archive({pkg.SLUG + '/' + key: value for key, value in values.items()})
                manifest = copy.deepcopy(self.manifest)
                manifest['package_sha256'] = pkg.sha(bad)
                with self.assertRaisesRegex(ValueError, 'file set/content'):
                    pkg.validate_payload(bad, manifest, self.root / ('bad-' + kind))
        manifest = dict(self.manifest, product_tree_sha='f' * 64)
        with self.assertRaisesRegex(ValueError, 'PRODUCT_TREE_SHA'):
            pkg.validate_payload(self.zip, manifest, self.root / 'wrong-product')

    def test_04_zip_traversal_alias_special_and_collision(self):
        for name in ('../escape', '/absolute', 'wc-order-splitter/../escape', 'wc-order-splitter//alias',
                     'wc-order-splitter/.hidden', 'wc-order-splitter/back\\slash', 'other/file'):
            with self.subTest(name=name), self.assertRaises(ValueError):
                pkg.zip_data(archive({name: b'data'}), pkg.SLUG + '/')
        with self.assertRaises(ValueError):
            pkg.zip_data(archive({'a.php': b'a', 'A.php': b'b'}))
        raw = io.BytesIO()
        with zipfile.ZipFile(raw, 'w') as handle:
            info = zipfile.ZipInfo('link')
            info.create_system = 3
            info.external_attr = (stat.S_IFLNK | 0o777) << 16
            handle.writestr(info, '/etc/passwd')
        with self.assertRaisesRegex(ValueError, 'special ZIP'):
            pkg.zip_data(raw.getvalue())

    def test_05_candidate_tools_never_execute(self):
        script = self.source / '.github/scripts/stage-distribution.sh'
        original = script.read_bytes()
        script.write_text('exit 97\n')
        try:
            self.assertEqual(pkg.stage(self.source, self.root / 'untrusted-helper'), DIGEST)
        finally:
            script.write_bytes(original)

    def test_06_policy_fail_closed(self):
        good = wp.policy()
        cases = [('slug', 'thumbnail-manager'), ('svn_url', 'file:///tmp/repo'),
                 ('svn_url', 'https://example.test/wc-order-splitter'), ('assets_mode', 'sync')]
        for field, value in cases:
            candidate = copy.deepcopy(good)
            candidate[field] = value
            pkg.write_json(self.root / 'policy.json', candidate)
            with self.subTest(field=field, value=value), self.assertRaises(ValueError):
                wp.policy(self.root / 'policy.json')
        good['release_confirmation']['observed_at'] = '2026-09-03T00:00:00Z'
        pkg.write_json(self.root / 'policy.json', good)
        with self.assertRaisesRegex(ValueError, 'unknown confirmation'):
            wp.policy(self.root / 'policy.json')

    def test_07_input_metadata_and_manifest_mismatches(self):
        for candidate, version, digest, run_id in ((BASE[:-1], '1.5.0', DIGEST, 1),
                (BASE, '1.5.0;echo', DIGEST, 1), (BASE, '1.4.15', DIGEST, 1),
                (BASE, '1.5.0', 'z' * 64, 1), (BASE, '1.5.0', DIGEST, '../bad')):
            with self.assertRaises(ValueError):
                pkg.inputs(candidate, version, digest, run_id)
        with self.assertRaisesRegex(ValueError, 'Version / Stable'):
            pkg.metadata(self.staged, '1.6.0')
        for field, value in (('file_count', 1), ('public_baseline', 'other'), ('package_name', '../bad')):
            with self.assertRaises(ValueError):
                pkg.validate_manifest(dict(self.manifest, **{field: value}))

    def test_08_public_confirmation_pending_and_expanded_zip(self):
        downloader = Mock()
        self.assertEqual(wp.public_state(self.manifest, self.root / 'public-no', confirmation='unknown', download=downloader),
                         'WPORG_RELEASE_CONFIRMATION_PENDING')
        downloader.assert_not_called()
        responses = [pkg.encoded({'slug': pkg.SLUG, 'version': '1.4.11'})]
        self.assertEqual(wp.public_state(self.manifest, self.root / 'public-old', confirmation='disabled', download=lambda _: responses.pop()),
                         'WPORG_PROPAGATION_PENDING')
        responses = [pkg.encoded({'slug': pkg.SLUG, 'version': '1.5.0'}), self.zip]
        self.assertEqual(wp.public_state(self.manifest, self.root / 'public-ok', confirmation='unknown', verify_only=True,
                                        download=lambda _: responses.pop(0)), 'WPORG_PUBLIC_RELEASE_VERIFIED')
        responses = [pkg.encoded({'slug': pkg.SLUG, 'version': '1.5.0'}), archive({'wc-order-splitter/bad': b'bad'})]
        with self.assertRaisesRegex(ValueError, 'file set/content'):
            wp.public_state(self.manifest, self.root / 'public-bad', confirmation='disabled', download=lambda _: responses.pop(0))

    def test_09_plugin_check_does_not_hide_errors(self):
        warning = {'file': 'readme.txt', 'type': 'WARNING', 'code': 'example', 'message': 'message []'}
        self.assertEqual(cli.plugin_check_report(json.dumps([warning])), [warning])
        self.assertEqual(cli.plugin_check_report('Success: Checks complete. No errors found.'), [])
        for raw in ('', '[]\n[]', json.dumps([dict(warning, type='ERROR')]), json.dumps([{'code': 'unknown'}])):
            with self.assertRaises(ValueError):
                cli.plugin_check_report(raw)

    def test_10_ambiguous_commit_attempt_is_single_and_secret_is_stdin_only(self):
        repository = wp.SVN(self.root / 'mock-only-never-connected')
        env = {'WPORG_SVN_USERNAME': 'yoohw', 'WPORG_SVN_PASSWORD': 'fake-fixture-password'}
        with patch.dict(os.environ, env), patch.object(repository, 'validate'), patch.object(repository, 'validate_delta'), \
                patch.object(pkg, 'verify_tree'), patch.object(wp.subprocess, 'run', side_effect=subprocess.TimeoutExpired('svn', 120)) as command:
            result = repository.atomic_commit(self.manifest, 200, self.root / 'attempt.json')
        self.assertEqual(result['state'], 'SVN_COMMIT_OUTCOME_UNKNOWN')
        self.assertEqual(command.call_count, 1)
        args, kwargs = command.call_args
        self.assertEqual(args[0][:2], ['svn', 'commit'])
        self.assertNotIn('fake-fixture-password', ' '.join(args[0]))
        self.assertNotIn('WPORG_SVN_PASSWORD', kwargs['env'])
        self.assertEqual(kwargs['input'], b'fake-fixture-password\n')
        self.assertNotIn('fake-fixture-password', (self.root / 'attempt.json').read_text())

    @contextmanager
    def preparation_fixture(self, report):
        with tempfile.TemporaryDirectory(prefix='wcos-diagnostics-') as directory:
            root = Path(directory)
            work = root / 'wcos-release'
            (work / 'rc-a').mkdir(parents=True)
            shutil.copytree(self.staged, work / 'stage-a')
            pkg.write_json(work / 'rc-a/release-manifest.json', self.manifest)
            (work / 'rc-a' / self.manifest['package_name']).write_bytes(self.zip)
            (work / 'plugin-check-raw.txt').write_text(report)
            env = {'RUNNER_TEMP': directory, 'CANDIDATE_SHA': BASE, 'VERSION': '1.5.0',
                   'PRODUCT_TREE_SHA': DIGEST, 'GITHUB_RUN_ID': '100',
                   'PYTHONDONTWRITEBYTECODE': '1',
                   'GITHUB_OUTPUT': str(root / 'outputs'), 'GITHUB_STEP_SUMMARY': str(root / 'summary')}
            with patch.dict(os.environ, env), patch.object(gh, 'API'), \
                    patch.object(gh, 'control_context', return_value=BASE):
                yield work, root / 'outputs', root / 'summary'

    def test_11_error_persists_diagnostics_before_preparation_fails(self):
        error = {'type': 'ERROR', 'code': 'blocked_code', 'file': 'inc/example.php',
                 'line': 12, 'column': 3, 'message': 'Exact blocking message', 'docs': 'https://example.test/docs'}
        with self.preparation_fixture(json.dumps([error])) as (work, outputs, summary), redirect_stdout(io.StringIO()) as log:
            with self.assertRaisesRegex(ValueError, 'Errors block preparation; no ignore baseline'):
                cli.finish_prepare()
            diagnostic = pkg.read_json(work / 'plugin-check-diagnostics.json')
            self.assertEqual(diagnostic['findings'], [error])
            self.assertEqual((diagnostic['status'], diagnostic['error_count'], diagnostic['warning_count']), ('BLOCKED', 1, 0))
            self.assertIn('1 ERROR(s), 0 WARNING(s)', log.getvalue())
            for value in ('blocked_code', 'inc/example.php', '12', 'Exact blocking message'):
                self.assertIn(value, log.getvalue())
                self.assertIn(value, summary.read_text())
            self.assertFalse((work / 'rc-a/plugin-check.json').exists())
            self.assertFalse((work / 'rc-a/preparation-record.json').exists())
            self.assertFalse(outputs.exists())
            self.assertNotIn('RC_PREPARED', log.getvalue())
            self.assertEqual(set(path.name for path in (work / 'rc-a').iterdir()),
                             {'release-manifest.json', self.manifest['package_name']})

    def test_12_all_errors_and_warnings_have_deterministic_evidence(self):
        first = {'type': 'ERROR', 'code': 'first', 'file': 'a.php', 'line': 2, 'message': 'First error'}
        second = {'type': 'ERROR', 'code': 'second', 'file': 'b.php', 'line': 9, 'message': 'Second error'}
        warning = {'type': 'WARNING', 'code': 'notice', 'file': 'readme.txt', 'message': 'Still retained'}
        results = []
        for report in ([second, warning, first], [first, second, warning]):
            with self.preparation_fixture(json.dumps(report)) as (work, outputs, summary), redirect_stdout(io.StringIO()) as log:
                with self.assertRaisesRegex(ValueError, 'Errors block preparation'):
                    cli.plugin_check_evidence()
                raw = (work / 'plugin-check-diagnostics.json').read_bytes()
                evidence = json.loads(raw)
                self.assertEqual(evidence['error_count'], 2)
                self.assertEqual(evidence['warning_count'], 1)
                self.assertCountEqual(evidence['findings'], [first, second, warning])
                self.assertEqual(log.getvalue().count('Plugin Check ERROR: '), 2)
                self.assertIn('2 ERROR(s), 1 WARNING(s)', log.getvalue())
                self.assertIn('First error', log.getvalue())
                self.assertIn('Second error', log.getvalue())
                self.assertFalse(outputs.exists())
                results.append((raw, log.getvalue(), summary.read_bytes()))
        self.assertEqual(results[0], results[1])

    def test_13_clean_and_warning_reports_keep_successful_preparation(self):
        warning = {'type': 'WARNING', 'code': 'notice', 'file': 'readme.txt', 'line': 0, 'message': 'Warning only'}
        for report in ([], [warning]):
            with self.subTest(report=report), self.preparation_fixture(json.dumps(report)) as (work, outputs, summary), \
                    redirect_stdout(io.StringIO()) as log:
                cli.plugin_check_evidence()
                cli.finish_prepare()
                self.assertEqual(pkg.read_json(work / 'rc-a/plugin-check.json'), report)
                record = pkg.read_json(work / 'rc-a/preparation-record.json')
                self.assertEqual(record['status'], 'RC_PREPARED')
                self.assertEqual(record['artifact_name'], f'{pkg.SLUG}-1.5.0-{BASE}')
                self.assertEqual(pkg.manifest_identity(record), pkg.manifest_identity(self.manifest))
                self.assertIn('state=RC_PREPARED', outputs.read_text())
                self.assertIn('artifact_name=' + record['artifact_name'], outputs.read_text())
                diagnostic = pkg.read_json(work / 'plugin-check-diagnostics.json')
                self.assertEqual((diagnostic['status'], diagnostic['error_count'], diagnostic['warning_count']),
                                 ('PASSED', 0, len(report)))
                self.assertEqual(set(path.name for path in (work / 'rc-a').iterdir()),
                                 {'plugin-check.json', 'preparation-record.json', 'release-manifest.json', self.manifest['package_name']})

    def test_14_malformed_ambiguous_and_missing_reports_fail_closed_without_raw_dump(self):
        duplicate = '[{"type":"ERROR","type":"WARNING","code":"x","file":"x.php","message":"x"}]'
        for raw in ('', '[bad secret=fixture-private]', '[]\n[]', '[]\nSuccess: Checks complete. No errors found.',
                    '[{}]', duplicate, '[{"type":false}]'):
            with self.subTest(raw=raw), self.preparation_fixture(raw) as (work, outputs, summary), redirect_stdout(io.StringIO()) as log:
                with self.assertRaisesRegex(ValueError, 'report malformed or ambiguous'):
                    cli.plugin_check_evidence()
                evidence = pkg.read_json(work / 'plugin-check-diagnostics.json')
                self.assertEqual(evidence['status'], 'INVALID_REPORT')
                self.assertIsNone(evidence['error_count'])
                self.assertEqual(evidence['findings'], [])
                self.assertNotIn('fixture-private', (work / 'plugin-check-diagnostics.json').read_text() + log.getvalue() + summary.read_text())
                self.assertFalse(outputs.exists())
                self.assertFalse((work / 'rc-a/preparation-record.json').exists())
        with self.preparation_fixture('unused') as (work, outputs, summary), redirect_stdout(io.StringIO()):
            (work / 'plugin-check-raw.txt').unlink()
            with self.assertRaisesRegex(ValueError, 'report malformed or ambiguous'):
                cli.plugin_check_evidence()
            self.assertEqual(pkg.read_json(work / 'plugin-check-diagnostics.json')['status'], 'INVALID_REPORT')

    def test_15_diagnostics_allowlist_redacts_credentials_and_escapes_summary(self):
        env = {'GH_TOKEN': 'fixture-env-token', 'WPORG_SVN_PASSWORD': 'fixture-env-password', 'UNRELATED_VALUE': 'fixture-env-unrelated'}
        error = {'type': 'ERROR', 'code': 'unsafe_example', 'file': 'inc/example.php', 'line': '12', 'column': 3,
                 'message': '\x1b[31mFound <script>bad</script>\n::warning:: GH_TOKEN=fixture-message-token '
                            'Bearer fixture-bearer github_pat_fixturepat WPORG_SVN_PASSWORD="fixture-quoted-password" '
                            'metadata {"CUSTOM_SECRET":"fixture-json-secret"} Basic fixture-basic '
                            "'GH_TOKEN' = 'fixture-singlequote-token' Authorization: Basic Zml4dHVyZTpzZWNyZXQ= "
                            'Authorization: fixture-authorization Bearer\nfixture-newline-token api_key:\tfixture-tab-secret',
                 'docs': 'https://fixture-user:fixture-url-password@example.test/docs?token=fixture-url-token',
                 'environment': env, 'raw': 'fixture-unknown-field'}
        with self.preparation_fixture(json.dumps([error])) as (work, outputs, summary), patch.dict(os.environ, env), \
                redirect_stdout(io.StringIO()) as log:
            with self.assertRaisesRegex(ValueError, 'Errors block preparation'):
                cli.plugin_check_evidence()
            raw = (work / 'plugin-check-diagnostics.json').read_text()
            text = raw + log.getvalue() + summary.read_text()
            for secret in (*env.values(), 'fixture-message-token', 'fixture-bearer', 'github_pat_fixturepat',
                           'fixture-quoted-password', 'fixture-url-password', 'fixture-url-token', 'fixture-unknown-field'):
                self.assertNotIn(secret, text)
            for secret in ('fixture-json-secret', 'fixture-basic', 'fixture-authorization', 'fixture-newline-token', 'fixture-tab-secret'):
                self.assertNotIn(secret, text)
            self.assertNotIn('fixture-singlequote-token', text)
            self.assertNotIn('Zml4dHVyZTpzZWNyZXQ=', text)
            finding = json.loads(raw)['findings'][0]
            self.assertEqual(set(finding), {'type', 'code', 'file', 'line', 'column', 'message', 'docs'})
            self.assertEqual(finding['type'], 'ERROR')
            self.assertIn('[REDACTED]', text)
            self.assertNotIn('\x1b', text)
            self.assertNotIn('<script>', summary.read_text())
            self.assertIn('&lt;script&gt;', summary.read_text())
            self.assertFalse(any(line.startswith('::') for line in log.getvalue().splitlines()))

    def test_16_evidence_command_exits_nonzero_after_persisting_errors(self):
        error = {'type': 'ERROR', 'code': 'checker_failed', 'file': 'example.php', 'line': 4, 'message': 'Blocked'}
        with self.preparation_fixture(json.dumps([error])) as (work, outputs, summary):
            result = subprocess.run([sys.executable, str(ROOT / '.github/scripts/release_cli.py'), 'plugin-check-evidence'],
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertEqual(result.returncode, 1)
            self.assertIn(b'1 ERROR(s)', result.stdout)
            self.assertIn(b'Errors block preparation; no ignore baseline', result.stderr)
            self.assertEqual(pkg.read_json(work / 'plugin-check-diagnostics.json')['findings'], [error])
            self.assertFalse(outputs.exists())
            self.assertFalse((work / 'rc-a/preparation-record.json').exists())


class LocalSVN(Product):
    # Reuse only fixture data, not inherited test methods.
    def setUp(self):
        self.case = tempfile.TemporaryDirectory(prefix='wcos-svn-fixture-')
        self.path = Path(self.case.name)
        run('svnadmin', 'create', self.path / 'repository')
        self.url = (self.path / 'repository').as_uri()
        self.repo = wp.SVN(self.path / 'initial', self.url, fixture=True).checkout()
        for name in ('trunk', 'assets', 'tags'):
            (self.repo.working / name).mkdir()
        (self.repo.working / 'trunk/readme.txt').write_text('public baseline 1.4.11')
        (self.repo.working / 'assets/icon.svg').write_text('<svg/>')
        self.repo.run('add', self.repo.working / 'trunk', self.repo.working / 'assets', self.repo.working / 'tags')
        self.repo.run('commit', self.repo.working, '--username', 'yoohw', '-m', 'fixture baseline')
        self.repo.run('update', self.repo.working)

    def tearDown(self):
        self.case.cleanup()

    def fresh(self, name):
        return wp.SVN(self.path / name, self.url, fixture=True).checkout()

    def test_svn_atomic_exact_payload_and_authenticated_recovery(self):
        before = self.repo.stage(self.staged, self.manifest, wp.policy())
        approved = {'snapshot': before, 'identity': pkg.manifest_identity(self.manifest)}
        pkg.verify_tree(self.repo.working / 'trunk', self.manifest)
        pkg.verify_tree(self.repo.working / 'tags/1.5.0', self.manifest)
        self.repo.run('commit', self.repo.working / 'trunk', self.repo.working / 'tags/1.5.0', '--username', 'yoohw',
                      '-m', wp.commit_message(self.manifest, 200))
        verified = self.fresh('verify').verify(self.manifest, approved, 200)
        self.assertEqual(verified['state'], 'SVN_COMMITTED_VERIFIED')
        self.assertEqual(verified['svn_revision'], 2)
        with self.assertRaisesRegex(ValueError, 'log/revision'):
            self.fresh('wrong-run').verify(self.manifest, approved, 201)
        with self.assertRaisesRegex(ValueError, 'tag already exists'):
            self.fresh('existing').stage(self.staged, self.manifest, wp.policy())

    def test_svn_assets_and_trunk_drift_after_approval(self):
        approved = self.repo.snapshot('1.5.0', wp.policy())
        for name in ('assets/icon.svg', 'trunk/readme.txt'):
            (self.repo.working / name).write_text('changed')
            self.repo.run('commit', self.repo.working / name, '-m', 'external fixture drift')
            with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'snapshot drift'):
                self.fresh('drift-' + name.split('/')[0]).compare(approved, '1.5.0', wp.policy())

    def test_svn_tag_appearing_after_approval(self):
        approved = self.repo.snapshot('1.5.0', wp.policy())
        self.repo.run('copy', self.repo.working / 'trunk', self.repo.working / 'tags/1.5.0')
        self.repo.run('commit', self.repo.working / 'tags/1.5.0', '-m', 'racing tag')
        with self.assertRaisesRegex(ValueError, 'tag exists or appeared'):
            self.fresh('after-tag').compare(approved, '1.5.0', wp.policy())

    def test_svn_wrong_layout_url_and_extra_delta(self):
        with self.assertRaisesRegex(ValueError, 'URL'):
            wp.SVN(self.repo.working).validate()
        (self.repo.working / 'unexpected').write_text('bad')
        with self.assertRaisesRegex(ValueError, 'layout'):
            self.repo.validate()
        (self.repo.working / 'unexpected').unlink()
        self.repo.stage(self.staged, self.manifest, wp.policy())
        (self.repo.working / 'assets/icon.svg').write_text('not authorized')
        with self.assertRaisesRegex(ValueError, 'outside trunk/new tag'):
            self.repo.validate_delta('1.5.0')

    def test_svn_forbids_externals_and_fixture_production_commit(self):
        self.repo.run('propset', 'svn:externals', '^/other external', self.repo.working / 'trunk')
        with self.assertRaisesRegex(ValueError, 'externals'):
            self.repo.validate(clean=False)
        with self.assertRaisesRegex(ValueError, 'fixture URL'):
            self.repo.atomic_commit(self.manifest, 200, self.path / 'attempt.json')


# unittest inheritance would otherwise run the Product tests twice in this class.
for inherited in list(Product.__dict__):
    if inherited.startswith('test_'):
        setattr(LocalSVN, inherited, None)


class GithubContracts(unittest.TestCase):
    def http_api(self, responses):
        with patch.dict(os.environ, {'GH_TOKEN': 'fixture-not-a-real-token'}):
            api = gh.API()

        def respond(request, timeout):
            self.assertEqual(timeout, 60)
            return io.BytesIO(pkg.encoded(responses[request.full_url]))

        # Keep production request/get/pages validation; replace only HTTP transport.
        api.opener.open = Mock(side_effect=respond)
        return api

    def test_api_path_guard_allows_compare_repository_routes_and_queries(self):
        prefix = f'repos/{pkg.REPOSITORY}/'
        paths = [prefix + endpoint for endpoint in (
            f'compare/{BASE}...{CERT_HEAD}', 'branches/main', 'git/ref/tags/1.5.0',
            'actions/runs/33727158970/artifacts?per_page=100&page=2',
            'releases/1/assets?name=release..zip', 'releases?note=../query-only')]
        api = self.http_api({'https://api.github.com/' + path: {'ok': True} for path in paths})
        for path in paths:
            with self.subTest(path=path):
                self.assertEqual(api.request(path), {'ok': True})
                request = api.opener.open.call_args.args[0]
                self.assertEqual(request.full_url, 'https://api.github.com/' + path)
                self.assertEqual(request.get_method(), 'GET')
        self.assertEqual(api.opener.open.call_count, len(paths))

        upload = prefix + 'releases/1/assets?name=release..zip'
        api = self.http_api({'https://uploads.github.com/' + upload: {'id': 1}})
        self.assertEqual(api.request(upload, method='POST', upload=b'fixture'), {'id': 1})
        request = api.opener.open.call_args.args[0]
        self.assertEqual(request.full_url, 'https://uploads.github.com/' + upload)
        self.assertEqual(request.data, b'fixture')

    def test_api_path_guard_rejects_traversal_and_non_repository_before_http(self):
        prefix = f'repos/{pkg.REPOSITORY}/'
        paths = ['../' + prefix + 'branches/main', '/repos/' + pkg.REPOSITORY + '/branches/main',
                 'repos/attacker/repository/branches/main', prefix[:-1] + '-other/branches/main',
                 'users/yoohwz', 'https://example.test/' + prefix + 'branches/main',
                 'http://api.github.com/' + prefix + 'branches/main', '//example.test/' + prefix]
        paths += [prefix + endpoint for endpoint in (
            '../outside', 'git/../outside', 'git/..', './branches/main', 'git/./refs', 'git/.',
            '%2e%2e/outside', 'git/%2E%2e/refs', 'git/.%2e', '%2e/branches/main',
            'git%2f..%2foutside', 'git/%2E%2E%2Foutside', 'git/%2e%2e?per_page=100')]
        api = self.http_api({})
        for path in paths:
            for kwargs in ({}, {'method': 'POST', 'upload': b'fixture'}):
                with self.subTest(path=path, upload=bool(kwargs)), self.assertRaisesRegex(ValueError, 'unsafe GitHub API path'):
                    api.request(path, **kwargs)
        api.opener.open.assert_not_called()

    def test_api_pages_preserves_existing_query(self):
        prefix = f'https://api.github.com/repos/{pkg.REPOSITORY}/'
        first = [{'id': number} for number in range(100)]
        second = [{'id': 100}]
        urls = [prefix + f'pulls?state=closed&per_page=100&page={page}' for page in (1, 2)]
        api = self.http_api(dict(zip(urls, (first, second))))
        self.assertEqual(api.pages('pulls?state=closed'), first + second)
        self.assertEqual([call.args[0].full_url for call in api.opener.open.call_args_list], urls)

    def test_failed_prepare_provenance_through_production_http_guard(self):
        # Run 33744352719 failed before HTTP on this protected-main Compare route.
        control = 'f7a6ad93a597a58cedd8db654f572fe4d9dfbabe'
        tree = '0c3e2ba937b21a24100e1402f11e267531501abc'
        repository = {'full_name': pkg.REPOSITORY}
        pull = {'merged_at': '2026-09-03T07:47:46Z', 'base': {'ref': 'main', 'repo': repository},
                'merge_commit_sha': BASE, 'head': {'sha': CERT_HEAD}}
        check = {'id': 1, 'name': 'Required CI', 'app': {'slug': 'github-actions'},
                 'status': 'completed', 'conclusion': 'success',
                 'details_url': f'https://github.com/{pkg.REPOSITORY}/actions/runs/33726798296/job/1'}
        responses = {
            'branches/main': {'protected': True, 'commit': {'sha': control}},
            f'compare/{control}...{control}': {'status': 'identical'},
            f'compare/{BASE}...{control}': {'status': 'ahead'},
            f'commits/{BASE}/pulls?per_page=100&page=1': [pull],
            f'git/commits/{BASE}': {'tree': {'sha': tree}},
            f'git/commits/{CERT_HEAD}': {'tree': {'sha': tree}},
            f'commits/{CERT_HEAD}/check-runs?per_page=100&page=1': {'check_runs': [check]},
            'actions/runs/33726798296': {'repository': repository, 'head_repository': repository,
                'path': '.github/workflows/ci.yml', 'event': 'pull_request', 'head_sha': CERT_HEAD,
                'status': 'completed', 'conclusion': 'success'},
        }
        prefix = f'https://api.github.com/repos/{pkg.REPOSITORY}/'
        api = self.http_api({prefix + endpoint: value for endpoint, value in responses.items()})
        env = {'GITHUB_REPOSITORY': pkg.REPOSITORY, 'GITHUB_REF': 'refs/heads/main', 'GITHUB_REF_PROTECTED': 'true',
               'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_RUN_ATTEMPT': '1', 'GITHUB_SHA': control,
               'GITHUB_WORKFLOW_SHA': control, 'GITHUB_WORKFLOW_REF': f'{pkg.REPOSITORY}/{gh.PREPARE}@refs/heads/main'}
        with patch.dict(os.environ, env):
            self.assertEqual(gh.control_context(api, gh.PREPARE), control)
        self.assertEqual(gh.accepted_source(api, BASE), {
            'accepted_sha': BASE, 'reviewed_head': CERT_HEAD, 'required_ci_run_id': 33726798296})
        requests = [call.args[0] for call in api.opener.open.call_args_list]
        compares = [request.full_url for request in requests if '/compare/' in request.full_url]
        self.assertEqual(compares, [prefix + f'compare/{control}...{control}'] +
                         [prefix + f'compare/{BASE}...{control}'] * 2)
        self.assertTrue(all(request.get_method() == 'GET' for request in requests))

    def test_api_binary_download_headers_and_error_classification(self):
        with patch.dict(os.environ, {'GH_TOKEN': 'fixture-not-a-real-token'}):
            api = gh.API()
        response = Mock()
        response.read.return_value = b'raw'
        context = Mock()
        context.__enter__ = Mock(return_value=response)
        context.__exit__ = Mock(return_value=False)
        api.opener.open = Mock(return_value=context)
        for endpoint in ('actions/jobs/20/logs', 'actions/artifacts/1/zip', 'releases/assets/2'):
            self.assertEqual(api.get(endpoint, binary=True), b'raw')
            request = api.opener.open.call_args.args[0]
            expected = 'application/octet-stream' if endpoint.startswith('releases/') else 'application/vnd.github+json'
            self.assertEqual(request.get_header('Accept'), expected)
        for code in (404, 403, 415, 500):
            api.opener.open = Mock(side_effect=urllib.error.HTTPError('https://api.github.com/test', code, 'fixture', {}, None))
            if code == 404:
                self.assertIsNone(api.get('git/ref/tags/1.5.0', missing=True))
            else:
                with self.assertRaisesRegex(ValueError, str(code)):
                    api.get('git/ref/tags/1.5.0', missing=True)
            self.assertEqual(api.opener.open.call_count, 1)

    def test_redirect_does_not_forward_authorization(self):
        request = urllib.request.Request('https://api.github.com/test', headers={'Authorization': 'Bearer fixture'})
        redirect = gh.SafeRedirect().redirect_request(request, None, 302, 'redirect', {}, 'https://example.test/download')
        self.assertIsNone(redirect.get_header('Authorization'))
        with self.assertRaisesRegex(ValueError, 'non-HTTPS'):
            gh.SafeRedirect().redirect_request(request, None, 302, 'redirect', {}, 'http://example.test/download')

    def certificate_api(self):
        repository = {'full_name': pkg.REPOSITORY}
        run = {'id': 33727158970, 'repository': repository, 'head_repository': repository, 'path': gh.CERT,
               'event': 'workflow_dispatch', 'status': 'completed', 'conclusion': 'success', 'head_sha': CERT_HEAD,
               'head_branch': 'release/order-splitter-1.5.0', 'run_attempt': 1}
        job = {'id': 20, 'name': 'RELEASE_CERT', 'status': 'completed', 'conclusion': 'success',
               'check_run_url': f'https://api.github.com/repos/{pkg.REPOSITORY}/check-runs/30'}
        check = {'name': 'RELEASE_CERT', 'status': 'completed', 'conclusion': 'success',
                 'app': {'slug': 'github-actions'}, 'head_sha': CERT_HEAD}
        log = '\n'.join('2026-09-03T07:36:22.123Z ' + marker for marker in (
            f'PRODUCT_TREE_SHA={DIGEST}', f'REPO_HEAD_SHA={CERT_HEAD}',
            f'PUBLIC_BASELINE={pkg.BASELINE}; GENUINE_UPGRADE=passed',
            'ARTIFACTS=0; no ZIP, tag, release, publication or deployment')).encode()
        return FakeAPI({'actions/runs/33727158970': run, 'actions/runs/33727158970/attempts/1/jobs': [job],
                        'check-runs/30': check, 'actions/runs/33727158970/artifacts': [], 'actions/jobs/20/logs': log})

    def test_certificate_product_identity_not_candidate_sha_equality(self):
        api = self.certificate_api()
        with patch.object(gh, 'accepted_source') as accepted:
            result = gh.certificate(api, 33727158970, DIGEST)
        accepted.assert_called_once_with(api, CERT_HEAD, allow_merged_head=True)
        self.assertNotEqual(CERT_HEAD, BASE)
        self.assertEqual(result['product_tree_sha'], DIGEST)

    def test_certificate_rejects_mismatches_duplicate_checks_and_artifacts(self):
        for field, value in (('path', gh.PREPARE), ('event', 'push'), ('conclusion', 'failure'),
                             ('repository', {'full_name': 'attacker/repository'})):
            api = self.certificate_api()
            api.values['actions/runs/33727158970'][field] = value
            with self.subTest(field=field), patch.object(gh, 'accepted_source'), self.assertRaises(ValueError):
                gh.certificate(api, 33727158970, DIGEST)
        for mutation in ('duplicate', 'artifact', 'app', 'digest', 'baseline'):
            api = self.certificate_api()
            if mutation == 'duplicate':
                api.values['actions/runs/33727158970/attempts/1/jobs'] *= 2
            elif mutation == 'artifact':
                api.values['actions/runs/33727158970/artifacts'] = [{'id': 1}]
            elif mutation == 'app':
                api.values['check-runs/30']['app']['slug'] = 'fake'
            else:
                api.values['actions/jobs/20/logs'] = api.values['actions/jobs/20/logs'].replace(
                    DIGEST.encode() if mutation == 'digest' else pkg.BASELINE.encode(), b'wrong')
            with self.subTest(mutation=mutation), patch.object(gh, 'accepted_source'), self.assertRaises(ValueError):
                gh.certificate(api, 33727158970, DIGEST)

    def test_unrelated_candidate_is_not_accepted(self):
        api = FakeAPI({'branches/main': {'protected': True, 'commit': {'sha': BASE}},
                       f'compare/{CERT_HEAD}...{BASE}': {'status': 'diverged'}, f'commits/{CERT_HEAD}/pulls': []})
        with self.assertRaisesRegex(ValueError, 'accepted ancestor'):
            gh.accepted_source(api, CERT_HEAD)

    def test_squash_candidate_authenticates_native_ci_without_fake_head_equality(self):
        pull = {'merged_at': '2026-09-03T07:47:46Z', 'base': {'ref': 'main', 'repo': {'full_name': pkg.REPOSITORY}},
                'merge_commit_sha': BASE, 'head': {'sha': CERT_HEAD}}
        api = FakeAPI({'branches/main': {'protected': True, 'commit': {'sha': BASE}},
                       f'compare/{BASE}...{BASE}': {'status': 'identical'},
                       f'commits/{BASE}/pulls': [pull], f'git/commits/{BASE}': {'tree': {'sha': 'a' * 40}},
                       f'git/commits/{CERT_HEAD}': {'tree': {'sha': 'a' * 40}}})
        with patch.object(gh, 'ci_check', return_value=33726798296) as check:
            result = gh.accepted_source(api, BASE)
        self.assertEqual(result['reviewed_head'], CERT_HEAD)
        check.assert_called_once_with(api, CERT_HEAD)

    def test_main_control_plane_guard(self):
        env = {'GITHUB_REPOSITORY': pkg.REPOSITORY, 'GITHUB_REF': 'refs/heads/main', 'GITHUB_REF_PROTECTED': 'true',
               'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_RUN_ATTEMPT': '1', 'GITHUB_SHA': BASE,
               'GITHUB_WORKFLOW_SHA': BASE, 'GITHUB_WORKFLOW_REF': f'{pkg.REPOSITORY}/{gh.PUBLISH}@refs/heads/main'}
        with patch.dict(os.environ, env), patch.object(gh, 'protected_main', return_value=BASE), patch.object(gh, 'ancestor', return_value=True):
            self.assertEqual(gh.control_context(FakeAPI({}), gh.PUBLISH), BASE)
            for key, value in (('GITHUB_REF', 'refs/heads/untrusted'), ('GITHUB_RUN_ATTEMPT', '2'),
                               ('GITHUB_WORKFLOW_SHA', 'f' * 40), ('GITHUB_REF_PROTECTED', 'false')):
                with self.subTest(key=key), patch.dict(os.environ, {key: value}), self.assertRaises(ValueError):
                    gh.control_context(FakeAPI({}), gh.PUBLISH)

    def test_downloaded_artifact_digest_is_verified_not_merely_recorded(self):
        raw = archive({'manifest.json': b'{}'})
        run_data = {'id': 100, 'head_sha': BASE}
        item = {'id': 1, 'name': 'prepared', 'expired': False, 'digest': 'sha256:' + pkg.sha(raw),
                'workflow_run': {'id': 100, 'head_sha': BASE}}
        api = FakeAPI({'actions/runs/100/artifacts': [item], 'actions/artifacts/1/zip': raw})
        with tempfile.TemporaryDirectory() as directory:
            gh.artifact(api, run_data, 'prepared', Path(directory) / 'valid', {'manifest.json'})
            api.values['actions/artifacts/1/zip'] = archive({'manifest.json': b'changed'})
            with self.assertRaisesRegex(ValueError, 'API digest mismatch'):
                gh.artifact(api, run_data, 'prepared', Path(directory) / 'bad', {'manifest.json'})

    def test_diagnostic_artifact_cannot_authenticate_as_prepared_candidate(self):
        repository = {'full_name': pkg.REPOSITORY}
        run_data = {'id': 100, 'repository': repository, 'head_repository': repository, 'path': gh.PREPARE,
                    'event': 'workflow_dispatch', 'status': 'completed', 'conclusion': 'failure',
                    'head_sha': BASE, 'head_branch': 'main', 'run_attempt': 1}
        raw = archive({'plugin-check-diagnostics.json': pkg.encoded({'kind': 'PLUGIN_CHECK_DIAGNOSTICS', 'status': 'BLOCKED'})})
        item = {'id': 1, 'name': 'plugin-check-diagnostics-100', 'expired': False, 'digest': 'sha256:' + pkg.sha(raw),
                'workflow_run': {'id': 100, 'head_sha': BASE}}
        for case, message in (('failed-run', 'run is not successful'), ('wrong-name', 'immutable artifact missing'),
                              ('forged-name', 'unexpected artifact file set')):
            api = FakeAPI({'actions/runs/100': copy.deepcopy(run_data), 'actions/runs/100/artifacts': [copy.deepcopy(item)],
                           'actions/artifacts/1/zip': raw})
            if case != 'failed-run':
                api.values['actions/runs/100']['conclusion'] = 'success'
            if case == 'forged-name':
                api.values['actions/runs/100/artifacts'][0]['name'] = f'{pkg.SLUG}-1.5.0-{BASE}'
            with self.subTest(case=case), tempfile.TemporaryDirectory() as directory, \
                    patch.object(gh, 'ancestor', return_value=True), patch.object(gh, 'protected_main', return_value=BASE), \
                    patch.object(gh, 'accepted_source'), patch.object(gh, 'certificate') as certificate, \
                    self.assertRaisesRegex(ValueError, message):
                gh.prepared(api, 100, BASE, '1.5.0', Path(directory) / 'candidate')
            certificate.assert_not_called()

    def test_immutable_tag_mismatch_never_updates_or_deletes(self):
        manifest = {'candidate_sha': BASE, 'version': '1.5.0', 'product_tree_sha': DIGEST,
                    'package_sha256': 'a' * 64, 'release_cert_run_id': 1, 'preparation_run_id': 2}
        ref = {'ref': 'refs/tags/1.5.0', 'object': {'type': 'tag', 'sha': 'b' * 40}}
        obj = {'tag': '1.5.0', 'object': {'sha': CERT_HEAD, 'type': 'commit'}, 'message': 'different'}
        api = FakeAPI({'git/ref/tags/1.5.0': ref, 'git/tags/' + 'b' * 40: obj})
        with self.assertRaisesRegex(ValueError, 'tag identity mismatch'):
            gh.git_tag(api, manifest, create=True)
        self.assertTrue(all(not kwargs for _, kwargs in api.calls if _ != 'git/ref/tags/1.5.0'))
        self.assertFalse(any(kwargs.get('method') in {'POST', 'PATCH', 'DELETE'} for _, kwargs in api.calls))

    def test_release_cannot_precede_public_verification(self):
        with self.assertRaisesRegex(ValueError, 'public verification required'):
            gh.github_release(FakeAPI({}), {}, Path('/unused'), {'state': 'WPORG_PROPAGATION_PENDING'})

    def test_release_creation_and_exact_reconciliation(self):
        manifest = {'candidate_sha': BASE, 'version': '1.5.0', 'product_tree_sha': DIGEST,
                    'package_sha256': 'a' * 64, 'release_cert_run_id': 1, 'preparation_run_id': 2,
                    'package_name': 'wc-order-splitter-1.5.0.zip'}
        class ReleaseAPI:
            def __init__(self):
                self.release, self.assets, self.calls = None, {}, []
            def get(self, endpoint, **kwargs):
                self.calls.append((endpoint, kwargs))
                if endpoint == 'releases/tags/1.5.0':
                    return copy.deepcopy(self.release)
                if endpoint == 'releases':
                    self.release = dict(kwargs['value'], id=1)
                    return copy.deepcopy(self.release)
                if endpoint == 'releases/1':
                    if kwargs.get('method') == 'PATCH':
                        self.release.update(kwargs['value'])
                    return copy.deepcopy(self.release)
                if endpoint.startswith('releases/1/assets?name='):
                    self.assets[endpoint.split('=', 1)[1]] = kwargs['upload']
                    return {}
                if endpoint.startswith('releases/assets/'):
                    return self.assets[endpoint.split('/')[-1]]
                raise AssertionError(endpoint)
            def pages(self, endpoint, key=None):
                return [{'id': name, 'name': name} for name in self.assets]
        with tempfile.TemporaryDirectory() as directory, patch.object(gh, 'git_tag'):
            root = Path(directory)
            (root / 'payload').mkdir()
            (root / 'payload/changelog.txt').write_text('= 1.5.0 =\n\n* Public release notes.\n\n= 1.4.11 =\n* Older.\n')
            (root / manifest['package_name']).write_bytes(b'authenticated fixture package')
            pkg.write_json(root / 'release-manifest.json', manifest)
            api = ReleaseAPI()
            verification = {'state': 'WPORG_PUBLIC_RELEASE_VERIFIED', 'identity': pkg.manifest_identity(manifest)}
            self.assertEqual(gh.github_release(api, manifest, root, verification), 'GITHUB_RELEASE_PUBLISHED')
            self.assertFalse(api.release['draft'])
            self.assertEqual(api.release['body'], '* Public release notes.\n')
            self.assertEqual(set(api.assets), {manifest['package_name'], 'release-manifest.json'})
            api.calls.clear()
            gh.github_release(api, manifest, root, verification)
            self.assertFalse(any(kwargs.get('method') for _, kwargs in api.calls))
            api.assets[manifest['package_name']] = b'wrong package'
            api.calls.clear()
            with self.assertRaisesRegex(ValueError, 'asset content mismatch'):
                gh.github_release(api, manifest, root, verification)
            self.assertFalse(any(kwargs.get('method') for _, kwargs in api.calls))


class WorkflowContracts(unittest.TestCase):
    def test_manual_secret_and_mutation_boundaries(self):
        prepare = yaml(ROOT / '.github/workflows/release-prepare.yml')
        publish = yaml(ROOT / '.github/workflows/publish-wordpress-org.yml')
        for flow in (prepare, publish):
            self.assertEqual(set(flow['on']), {'workflow_dispatch'})
            self.assertEqual(flow['permissions'], {'actions': 'read', 'checks': 'read', 'contents': 'read'})
            self.assertIs(flow['concurrency']['cancel-in-progress'], False)
        self.assertIs(publish['on']['workflow_dispatch']['inputs']['dry_run']['default'], True)
        raw_prepare = json.dumps(prepare)
        self.assertNotIn('secrets.', raw_prepare)
        self.assertNotIn('environment', prepare['jobs']['prepare'])
        uploads = [step for step in prepare['jobs']['prepare']['steps'] if 'actions/upload-artifact@' in step.get('uses', '')]
        self.assertEqual(len(uploads), 2)
        canonical = [step for step in uploads if step['with']['name'] == '${{ steps.record.outputs.artifact_name }}']
        self.assertEqual(len(canonical), 1)
        self.assertEqual(canonical[0].get('if', 'success()'), 'success()')
        self.assertEqual(canonical[0]['with']['path'], '${{ runner.temp }}/wcos-release/rc-a/*')
        self.assertEqual(canonical[0]['with']['if-no-files-found'], 'error')
        secrets = []
        for job_name, job in publish['jobs'].items():
            for step in job['steps']:
                if 'secrets.' in json.dumps(step):
                    secrets.append((job_name, step))
            if job_name in {'preflight', 'dry-run', 'verify-only'}:
                self.assertNotIn('environment', job)
                self.assertNotIn('permissions', job)
                self.assertNotRegex(json.dumps(job), r'release_cli.py (commit|seal|release)')
            for step in job['steps']:
                if 'actions/checkout@' in step.get('uses', ''):
                    self.assertEqual(step['with']['ref'], '${{ github.sha }}')
                    self.assertIs(step['with']['persist-credentials'], False)
        self.assertEqual(len(secrets), 1)
        self.assertEqual(secrets[0][0], 'production')
        self.assertEqual(secrets[0][1]['run'], 'python3 control/.github/scripts/release_cli.py commit')
        production = publish['jobs']['production']
        self.assertEqual(production['environment'], 'wordpress-org-production')
        self.assertEqual(production['if'], "inputs.operation == 'publish' && !inputs.dry_run")
        self.assertNotIn('GH_TOKEN', secrets[0][1]['env'])
        release = publish['jobs']['github-release']
        self.assertIn('!inputs.dry_run', release['if'])
        self.assertIn('WPORG_PUBLIC_RELEASE_VERIFIED', release['if'])
        self.assertIn("needs.verify-only.result == 'success'", release['if'])
        self.assertEqual(release['environment'], 'wordpress-org-production')
        shared = yaml(ROOT / '.github/actions/publisher-context/action.yml')
        self.assertNotIn('secrets.', json.dumps(shared))
        checkouts = [step for step in shared['runs']['steps'] if 'actions/checkout@' in step.get('uses', '')]
        self.assertEqual(checkouts[0]['with']['path'], 'candidate-data')
        self.assertIs(checkouts[0]['with']['persist-credentials'], False)

    def test_failed_prepare_uploads_only_sanitized_diagnostics(self):
        prepare = yaml(ROOT / '.github/workflows/release-prepare.yml')
        steps = prepare['jobs']['prepare']['steps']
        checker = next(step for step in steps if step.get('id') == 'plugin_check')
        evidence = next(step for step in steps if step.get('run', '').endswith('release_cli.py plugin-check-evidence'))
        record = next(step for step in steps if step.get('id') == 'record')
        diagnostic = next(step for step in steps if step.get('with', {}).get('name') == 'plugin-check-diagnostics-${{ github.run_id }}')
        self.assertEqual(evidence['if'], "${{ !cancelled() && (steps.plugin_check.outcome == 'success' || steps.plugin_check.outcome == 'failure') }}")
        self.assertLess(steps.index(checker), steps.index(evidence))
        self.assertLess(steps.index(evidence), steps.index(record))
        self.assertNotIn('env', evidence)
        self.assertNotIn('GH_TOKEN', prepare['env'])
        self.assertEqual(record.get('if', 'success()'), 'success()')
        self.assertTrue(all(not step.get('continue-on-error') for step in steps))
        self.assertEqual(diagnostic['if'], 'failure()')
        self.assertEqual(diagnostic['uses'], 'actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02')
        self.assertEqual(diagnostic['with'], {
            'name': 'plugin-check-diagnostics-${{ github.run_id }}',
            'path': '${{ runner.temp }}/wcos-release/plugin-check-diagnostics.json',
            'if-no-files-found': 'ignore', 'retention-days': 14, 'overwrite': False})
        shell = (ROOT / '.github/scripts/release-plugin-check.sh').read_text()
        self.assertIn('plugin-check.2.1.0.zip', shell)
        self.assertIn('--format=strict-json --mode=update', shell)
        self.assertIn('--fields=file,line,column,type,code,message,docs', shell)
        self.assertNotRegex(shell, r'--(?:exclude|ignore|skip-checks)')


if __name__ == '__main__':
    unittest.main(verbosity=2)
