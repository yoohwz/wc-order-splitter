#!/usr/bin/env python3
"""One-shot SVN EOL cleanup contracts; production SVN is never contacted."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / '.github/scripts'))
import release_package as pkg
import wporg_eol_cleanup as cleanup


CONTROL = 'd9c5a282473460e93da4ec7d09bac41d565c8847'
RUN_ID = 33770000000


def run(*args):
    return subprocess.check_output(list(map(str, args)), stderr=subprocess.PIPE).decode().strip()


def yaml(path):
    return json.loads(run('ruby', '-ryaml', '-rjson', '-e',
                          'puts JSON.generate(YAML.load_file(ARGV[0]))', path))


class PolicyContracts(unittest.TestCase):
    def test_exact_repository_owned_policy(self):
        value = cleanup.cleanup_policy()
        self.assertEqual(len(value['trunk_paths']), 29)
        self.assertEqual(value['property'], {'name': 'svn:eol-style', 'value': 'native'})
        self.assertEqual(value['historical_tag'], '1.4.11')
        self.assertEqual(value['target_tag'], '1.5.0')
        self.assertEqual(len(cleanup.expected_properties(value)), 29)
        self.assertRegex(cleanup.policy_sha256(value), r'^[0-9a-f]{64}$')
        self.assertRegex(cleanup.property_inventory_sha256(value), r'^[0-9a-f]{64}$')

    def test_policy_drift_fails_closed(self):
        original = cleanup.cleanup_policy()
        cases = {
            'missing': dict(original, trunk_paths=original['trunk_paths'][:-1]),
            'duplicate': dict(original, trunk_paths=original['trunk_paths'][:-1] + [original['trunk_paths'][0]]),
            'unsorted': dict(original, trunk_paths=list(reversed(original['trunk_paths']))),
            'property': dict(original, property={'name': 'svn:eol-style', 'value': 'LF'}),
            'tag': dict(original, target_tag='1.5.1'),
            'author': dict(original, expected_author='someone-else'),
            'path-scope': dict(original, trunk_paths=original['trunk_paths'][:-1] + ['assets/icon.svg']),
        }
        with tempfile.TemporaryDirectory(prefix='wcos-eol-policy-') as directory:
            path = Path(directory) / 'policy.json'
            for name, value in cases.items():
                pkg.write_json(path, value)
                with self.subTest(name=name), self.assertRaises(ValueError):
                    cleanup.cleanup_policy(path)

    def test_commit_password_is_stdin_only_and_fixture_cannot_write(self):
        value = cleanup.cleanup_policy()
        repository = cleanup.CleanupSVN(Path('/mock-only-never-connected'))
        approved = {'trunk': {'files': []}}
        env = {'WPORG_SVN_USERNAME': 'yoohw', 'WPORG_SVN_PASSWORD': 'fixture-secret'}
        with tempfile.TemporaryDirectory(prefix='wcos-eol-attempt-') as directory, \
                patch.dict(os.environ, env), patch.object(repository, 'validate_staged'), \
                patch.object(cleanup.subprocess, 'run') as command:
            command.return_value.returncode = 0
            attempt = repository.atomic_cleanup(approved, value, CONTROL, RUN_ID,
                                                Path(directory) / 'attempt.json')
        args, kwargs = command.call_args
        self.assertEqual(args[0][:2], ['svn', 'commit'])
        self.assertEqual(len([item for item in args[0] if '/trunk/' in item]), 29)
        self.assertNotIn('fixture-secret', ' '.join(args[0]))
        self.assertNotIn('WPORG_SVN_PASSWORD', kwargs['env'])
        self.assertEqual(kwargs['input'], b'fixture-secret\n')
        self.assertEqual(attempt['state'], 'SVN_EOL_CLEANUP_OUTCOME_UNKNOWN')
        fixture = cleanup.CleanupSVN(Path('/fixture'), 'file:///fixture', fixture=True)
        with self.assertRaisesRegex(ValueError, 'fixture URL'):
            fixture.atomic_cleanup(approved, value, CONTROL, RUN_ID, Path(directory) / 'unused.json')


class LocalSVNContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.seed_case = tempfile.TemporaryDirectory(prefix='wcos-eol-svn-seed-')
        cls.seed_path = Path(cls.seed_case.name)
        run('svnadmin', 'create', cls.seed_path / 'repository')
        value = cleanup.cleanup_policy()
        repo = cleanup.CleanupSVN(cls.seed_path / 'initial',
                                  (cls.seed_path / 'repository').as_uri(), fixture=True).checkout()
        for name in ('trunk', 'assets', 'tags', 'tags/1.4.11'):
            (repo.working / name).mkdir()
        for index, path_value in enumerate(value['trunk_paths']):
            data = f'fixture-{index:02d}\n'.encode()
            for root in ('trunk', 'tags/1.4.11'):
                target = repo.working / root / path_value
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
        (repo.working / 'assets/icon.svg').write_text('<svg/>\n')
        (repo.working / 'trunk/unlisted.txt').write_text('allowed content without a property\n')
        (repo.working / 'tags/1.4.11/unlisted.txt').write_text('allowed content without a property\n')
        repo.run('add', repo.working / 'trunk', repo.working / 'assets', repo.working / 'tags')
        for path_value in value['trunk_paths']:
            for root in ('trunk', 'tags/1.4.11'):
                repo.run('propset', 'svn:eol-style', 'native', repo.working / root / path_value)
        repo.run('commit', repo.working, '--username', 'yoohw', '-m', 'fixture baseline')

    @classmethod
    def tearDownClass(cls):
        cls.seed_case.cleanup()

    def setUp(self):
        self.case = tempfile.TemporaryDirectory(prefix='wcos-eol-svn-')
        self.path = Path(self.case.name)
        run('svnadmin', 'hotcopy', self.seed_path / 'repository', self.path / 'repository')
        self.url = (self.path / 'repository').as_uri()
        self.value = cleanup.cleanup_policy()
        self.repo = cleanup.CleanupSVN(self.path / 'initial', self.url, fixture=True).checkout()

    def tearDown(self):
        self.case.cleanup()

    def fresh(self, name):
        return cleanup.CleanupSVN(self.path / name, self.url, fixture=True).checkout()

    def commit_working(self, message='fixture mutation'):
        self.repo.run('commit', self.repo.working, '--username', 'yoohw', '-m', message)
        self.repo.run('update', self.repo.working)

    def test_exact_cleanup_is_property_only_atomic_and_one_shot(self):
        approved = self.repo.snapshot(self.value)
        self.repo.require_approved_inventory(approved, self.value)
        before = pkg.files(self.repo.working / 'trunk')
        self.repo.apply(approved, self.value)
        self.assertEqual(pkg.files(self.repo.working / 'trunk'), before)
        targets = [self.repo.working / 'trunk' / path for path in self.value['trunk_paths']]
        self.repo.run('commit', *targets, '--username', 'yoohw',
                      '-m', cleanup.commit_message(self.value, CONTROL, RUN_ID))
        verified = self.fresh('verified').verify_cleanup(approved, self.value, CONTROL, RUN_ID)
        self.assertEqual(verified['before_hashes'], verified['after_hashes'])
        self.assertEqual(verified['revision'], 2)
        after = self.fresh('second-attempt').snapshot(self.value)
        with self.assertRaisesRegex(ValueError, 'exact inventory'):
            self.fresh('second-check').require_approved_inventory(after, self.value)

    def test_missing_property_fails_closed(self):
        self.repo.run('propdel', 'svn:eol-style', self.repo.working / 'trunk' / self.value['trunk_paths'][0])
        self.commit_working()
        current = self.fresh('missing').snapshot(self.value)
        with self.assertRaisesRegex(ValueError, 'exact inventory'):
            self.fresh('missing-check').require_approved_inventory(current, self.value)

    def test_extra_property_fails_closed(self):
        self.repo.run('propset', 'svn:mime-type', 'text/plain',
                      self.repo.working / 'trunk' / self.value['trunk_paths'][0])
        self.commit_working()
        current = self.fresh('extra').snapshot(self.value)
        with self.assertRaisesRegex(ValueError, 'exact inventory'):
            self.fresh('extra-check').require_approved_inventory(current, self.value)

    def test_changed_property_value_fails_closed(self):
        self.repo.run('propset', 'svn:eol-style', 'LF',
                      self.repo.working / 'trunk' / self.value['trunk_paths'][0])
        self.commit_working()
        current = self.fresh('value').snapshot(self.value)
        with self.assertRaisesRegex(ValueError, 'exact inventory'):
            self.fresh('value-check').require_approved_inventory(current, self.value)

    def test_unexpected_property_path_fails_closed(self):
        self.repo.run('propset', 'svn:eol-style', 'native', self.repo.working / 'trunk/unlisted.txt')
        self.commit_working()
        current = self.fresh('path').snapshot(self.value)
        with self.assertRaisesRegex(ValueError, 'exact inventory'):
            self.fresh('path-check').require_approved_inventory(current, self.value)

    def test_remote_content_drift_after_preflight_fails_closed(self):
        approved = self.repo.snapshot(self.value)
        target = self.repo.working / 'trunk' / self.value['trunk_paths'][0]
        target.write_bytes(target.read_bytes() + b'drift')
        self.commit_working()
        with self.assertRaisesRegex(ValueError, 'snapshot drift'):
            self.fresh('content-drift').compare(approved, self.value)

    def test_local_text_delta_is_rejected(self):
        approved = self.repo.snapshot(self.value)
        staged = self.fresh('local-text')
        staged.apply(approved, self.value)
        target = staged.working / 'trunk' / self.value['trunk_paths'][0]
        target.write_bytes(target.read_bytes() + b'local text mutation')
        with self.assertRaisesRegex(ValueError, 'property-only|bytes changed'):
            staged.validate_staged(approved, self.value)

    def test_assets_tags_and_unrelated_paths_are_rejected(self):
        approved = self.repo.snapshot(self.value)
        cases = {
            'assets': ('assets/icon.svg', b'changed asset'),
            'historical-tag': ('tags/1.4.11/unlisted.txt', b'changed tag'),
            'unrelated-trunk': ('trunk/intruder.txt', b'unversioned'),
        }
        for name, (relative, data) in cases.items():
            staged = self.fresh('scope-' + name)
            staged.apply(approved, self.value)
            target = staged.working / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
            with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'path set mismatch'):
                staged.validate_staged(approved, self.value)


class WorkflowContracts(unittest.TestCase):
    def test_secret_and_mutation_boundaries(self):
        flow = yaml(ROOT / '.github/workflows/cleanup-wordpress-org-eol.yml')
        self.assertEqual(set(flow['on']), {'workflow_dispatch'})
        self.assertEqual(flow['permissions'], {'contents': 'read'})
        self.assertIs(flow['concurrency']['cancel-in-progress'], False)
        self.assertEqual(set(flow['jobs']), {'preflight', 'cleanup'})
        self.assertNotIn('environment', flow['jobs']['preflight'])
        production = flow['jobs']['cleanup']
        self.assertEqual(production['environment'], 'wordpress-org-production')
        self.assertEqual(production['needs'], 'preflight')
        self.assertNotIn('permissions', production)
        secrets = []
        commands = {}
        for job_name, job in flow['jobs'].items():
            for step in job['steps']:
                raw = json.dumps(step)
                if 'secrets.' in raw or 'WPORG_SVN_USERNAME' in raw:
                    secrets.append((job_name, step))
                if step.get('run', '').startswith('python3 control/.github/scripts/wporg_eol_cleanup.py'):
                    commands[step['run'].rsplit(' ', 1)[-1]] = (job_name, step)
                if 'actions/checkout@' in step.get('uses', ''):
                    self.assertEqual(step['with']['ref'], '${{ github.sha }}')
                    self.assertIs(step['with']['persist-credentials'], False)
        self.assertEqual(set(commands), {'preflight', 'stage', 'commit', 'verify'})
        self.assertEqual(len(secrets), 1)
        self.assertEqual(secrets[0][0], 'cleanup')
        self.assertEqual(secrets[0][1]['run'],
                         'python3 control/.github/scripts/wporg_eol_cleanup.py commit')
        for command in ('preflight', 'stage', 'verify'):
            self.assertNotIn('env', commands[command][1])
        self.assertNotIn('contents: write',
                         (ROOT / '.github/workflows/cleanup-wordpress-org-eol.yml').read_text())
        helper = (ROOT / '.github/scripts/wporg_eol_cleanup.py').read_text()
        self.assertNotIn('git tag', helper)
        self.assertNotIn('github_release', helper)


if __name__ == '__main__':
    unittest.main(verbosity=2)
