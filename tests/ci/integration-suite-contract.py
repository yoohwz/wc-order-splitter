#!/usr/bin/env python3
"""Preserve the accepted product-suite inventory; no GitHub metadata is consulted."""
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]
BASE = '545b82b452adfc4d43fd4744f3f83d7a8f5e68fb'
old = subprocess.check_output(['git', 'show', f'{BASE}:tests/ci/integration-suites.tsv'], cwd=ROOT).decode()
rows = [line.split('|') for line in (ROOT / 'tests/ci/integration-suites.tsv').read_text().splitlines() if line and not line.startswith('#')]
old_rows = [line.split('|') for line in old.splitlines() if line and not line.startswith('#')]
assert len({row[2] for row in rows}) == len(rows), 'duplicate suite'
assert {row[2] for row in old_rows} <= {row[2] for row in rows}, 'lost accepted product suite'
for kind, profiles, path in rows:
    assert kind in {'eval', 'support'} and (ROOT / path).is_file(), path
    assert 'RELEASE_CERT' in profiles.split(','), path
    assert set(profiles.split(',')) <= {'STANDARD', 'CRITICAL', 'RELEASE_CERT'}
for kind, tags, path in old_rows:
    current = next(row for row in rows if row[2] == path)
    assert current[0] == kind, path
    if kind == 'eval' and set(tags.split(',')) & {'deep', 'financial', 'sentinel'}:
        assert 'CRITICAL' in current[1].split(','), path
    if kind == 'eval' and set(tags.split(',')) & {'medium', 'sentinel'}:
        assert 'STANDARD' in current[1].split(','), path

# Exercise actual routing with a child that consumes stdin, as wp-env does.
import json
import os
import tempfile
with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    npx = tmp / 'npx'
    npx.write_text(
        '#!/usr/bin/env python3\nimport json,os,sys\nsys.stdin.read()\n'
        'call = sys.argv[1:]\n'
        'with open(os.environ["CALL_LOG"], "a") as f: f.write(json.dumps(call)+"\\n")\n'
        'if call == json.loads(os.environ.get("FAIL_CALL", "null")): sys.exit(23)\n'
    )
    npx.chmod(0o755)
    env = dict(os.environ, PATH=str(tmp) + os.pathsep + os.environ['PATH'], CALL_LOG=str(tmp / 'calls'))
    merge_cases = (
        'core', 'crash_pre', 'forward_before_forward_relations',
        'forward_after_one_reciprocal_relation',
        'forward_after_both_relations_before_verification',
        'forward_after_verification_before_commit',
        'forward_after_commit_before_complete', 'response_loss', 'lease_loss',
        'stock_guard_before', 'stock_guard_after', 'drift_stock', 'checkpoint_drift',
    )
    for profile in ('STANDARD', 'CRITICAL', 'RELEASE_CERT'):
        expected = []
        for kind, profiles, path in rows:
            if kind != 'eval' or profile not in profiles.split(','):
                continue
            call = ['wp-env', 'run', 'cli', 'wp', 'eval-file', 'wp-content/plugins/wc-order-splitter/' + path]
            expected.extend([call + [case] for case in merge_cases] if path.endswith('/merge-service-adapter-smoke.php') else [call])
        assert (len({call[5] for call in expected}), len(expected)) == {
            'STANDARD': (11, 11), 'CRITICAL': (32, 44), 'RELEASE_CERT': (36, 48),
        }[profile], profile
        (tmp / 'calls').write_text('')
        command = ['/bin/bash', str(ROOT / '.github/scripts/run-integration-profile.sh'), profile]
        subprocess.run(command, env=env, stdin=subprocess.DEVNULL, check=True)
        calls = [json.loads(line) for line in (tmp / 'calls').read_text().splitlines()]
        assert calls == expected, (profile, calls, expected)

        # A late child failure must stop routing and preserve its nonzero status.
        (tmp / 'calls').write_text('')
        failure_index = len(expected) - 2
        failed = subprocess.run(command, env=dict(env, FAIL_CALL=json.dumps(expected[failure_index])), stdin=subprocess.DEVNULL)
        assert failed.returncode == 23, (profile, failed.returncode)
        calls = [json.loads(line) for line in (tmp / 'calls').read_text().splitlines()]
        assert calls == expected[:failure_index + 1], (profile, calls)

    for arguments in ([], [''], ['FAST'], ['unknown']):
        (tmp / 'calls').write_text('')
        rejected = subprocess.run(
            ['/bin/bash', str(ROOT / '.github/scripts/run-integration-profile.sh'), *arguments],
            env=env, stdin=subprocess.DEVNULL, capture_output=True, text=True,
        )
        assert rejected.returncode == 1 and 'unsupported integration profile' in rejected.stderr, arguments
        assert not (tmp / 'calls').read_text(), arguments

    # The integrated upgrade owns the same legacy fixture option as the retained
    # Return suite. Run it only for release, before seeding that suite's fixture.
    bash = tmp / 'bash'
    bash.write_text('#!/usr/bin/env python3\nimport json,os,sys\nwith open(os.environ["CALL_LOG"], "a") as f: f.write(json.dumps(sys.argv[1:])+"\\n")\n')
    bash.chmod(0o755)
    upgrade = 'tests/runtime/run-compat-upgrade-fixture.sh'
    prepare = 'tests/runtime/prepare-legacy-upgrade.sh'
    for profile in ('STANDARD', 'CRITICAL', 'RELEASE_CERT'):
        for storage in ('legacy', 'hpos', 'hpos-sync'):
            (tmp / 'calls').write_text('')
            subprocess.run(['/bin/bash', str(ROOT / '.github/scripts/run-runtime.sh'), profile, storage], env=env, stdin=subprocess.DEVNULL, check=True)
            routed = [json.loads(line) for line in (tmp / 'calls').read_text().splitlines()]
            upgrade_calls = [call for call in routed if call[0] == upgrade]
            assert upgrade_calls == ([[upgrade, storage]] if profile == 'RELEASE_CERT' else []), (profile, storage)
            if profile == 'RELEASE_CERT':
                assert routed.index([upgrade, storage]) < routed.index([prepare])
runtime = '\n'.join(p.read_text() for p in (ROOT / 'tests/runtime').glob('*.sh'))
for kind, _, path in rows:
    if kind == 'support':
        assert path in runtime, path
print(f'integration-suite-contract-ok: {len(rows)} preserved release entries')
