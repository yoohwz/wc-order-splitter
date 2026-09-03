#!/usr/bin/env python3
"""Small native workflow/profile and failure-propagation regressions."""
import json
import os
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]


def workflow(name):
    raw = subprocess.check_output(['ruby', '-ryaml', '-rjson', '-e',
                                   'puts JSON.generate(YAML.load_file(ARGV[0]))',
                                   str(ROOT / '.github/workflows' / name)])
    return json.loads(raw)


ci, release = workflow('ci.yml'), workflow('release-cert.yml')
assert set(ci['on']) == {'pull_request'}
assert ci['on']['pull_request'] == {'branches': ['main']}
assert set(release['on']) == {'workflow_dispatch'}
assert set((ROOT / '.github/workflows').glob('*.yml')) == {
    ROOT / '.github/workflows/ci.yml', ROOT / '.github/workflows/release-cert.yml',
    ROOT / '.github/workflows/release-prepare.yml', ROOT / '.github/workflows/publish-wordpress-org.yml'}
assert len((ROOT / '.github/workflows/ci.yml').read_bytes()) <= 20000
for flow in (ci, release):
    assert flow['permissions'] == {'contents': 'read'}
    for job in flow['jobs'].values():
        assert 'permissions' not in job
        for step in job.get('steps', []):
            assert 'upload-artifact' not in step.get('uses', '')
            if step.get('uses', '').startswith('actions/checkout@'):
                assert step['with']['persist-credentials'] is False
assert [j['name'] for j in ci['jobs'].values()].count('Required CI') == 1
required = ci['jobs']['required']
assert required['if'] == 'always()'
assert set(required['needs']) == {'scope', 'php', 'integration'}
for job in ('php', 'integration'):
    assert ci['jobs'][job]['needs'] == 'scope'
    assert ci['jobs'][job]['if'] == "needs.scope.outputs.profile != 'FAST'"
assert release['jobs']['php']['strategy']['matrix']['php'] == ['7.4', '8.1', '8.3']
assert release['jobs']['integration']['strategy']['matrix']['storage'] == ['legacy', 'hpos', 'hpos-sync']
assert release['jobs']['certificate']['if'] == 'always()'
assert set(release['jobs']['certificate']['needs']) == {'identity', 'php', 'integration'}

def passes(script, **env):
    return subprocess.run(['bash', '-e', '-c', script], env=dict(os.environ, **env),
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0

script = required['steps'][0]['run']
for profile in ('FAST', 'STANDARD', 'CRITICAL'):
    expected = 'skipped' if profile == 'FAST' else 'success'
    for scope in ('success', 'failure', 'cancelled', 'skipped'):
        for php in ('success', 'failure', 'cancelled', 'skipped'):
            for integration in ('success', 'failure', 'cancelled', 'skipped'):
                assert passes(script, PROFILE=profile, SCOPE_RESULT=scope,
                              PHP_RESULT=php, INTEGRATION_RESULT=integration) == (
                    scope == 'success' and php == expected and integration == expected)
assert not passes(script, PROFILE='RELEASE_CERT', SCOPE_RESULT='success', PHP_RESULT='success', INTEGRATION_RESULT='success')
assert not passes(script, PROFILE='', SCOPE_RESULT='success', PHP_RESULT='success', INTEGRATION_RESULT='success')
print('native-workflow-contract-ok')
