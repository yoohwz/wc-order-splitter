#!/usr/bin/env python3
"""One-shot, exact-inventory WordPress.org trunk property reconciliation.

Read-only commands and local property staging never receive SVN credentials.
Only ``atomic_cleanup`` can write remotely, and fixture repositories cannot call it.
"""
import os
from pathlib import Path
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

import release_package as pkg
import release_github as gh
import wporg_release as wp


WORKFLOW = '.github/workflows/cleanup-wordpress-org-eol.yml'
POLICY_PATH = pkg.ROOT / '.github/release/wporg-eol-cleanup-policy.json'
ENVIRONMENT = 'wordpress-org-production'


def cleanup_policy(path=POLICY_PATH):
    value = pkg.read_json(path)
    pkg.require(set(value) == {
        'schema_version', 'repository', 'slug', 'svn_url', 'historical_tag',
        'target_tag', 'expected_author', 'property', 'trunk_paths',
    }, 'invalid EOL cleanup policy fields')
    pkg.require(value['schema_version'] == 1 and value['repository'] == pkg.REPOSITORY and
                value['slug'] == pkg.SLUG and value['svn_url'] == wp.SVN_URL,
                'EOL cleanup policy authority mismatch')
    pkg.require(value['historical_tag'] == '1.4.11' and value['target_tag'] == '1.5.0' and
                value['expected_author'] == 'yoohw', 'EOL cleanup target mismatch')
    pkg.require(value['property'] == {'name': 'svn:eol-style', 'value': 'native'},
                'EOL cleanup property mismatch')
    paths = value['trunk_paths']
    pkg.require(isinstance(paths, list) and len(paths) == 29 and
                paths == sorted(paths, key=lambda item: item.encode()) and len(set(paths)) == len(paths),
                'EOL cleanup requires 29 unique sorted paths')
    for path_value in paths:
        pkg.safe_path(path_value)
        pkg.require(not path_value.startswith(('assets/', 'tags/', 'trunk/')),
                    'EOL cleanup paths must be relative trunk files')
    return value


def policy_sha256(value):
    return pkg.sha(pkg.encoded(value))


def expected_properties(value):
    prop = value['property']
    return [{'path': path, 'name': prop['name'], 'encoding': None, 'value': prop['value']}
            for path in value['trunk_paths']]


def property_inventory_sha256(value):
    return pkg.sha(pkg.encoded(expected_properties(value)))


def file_hashes(surface, value):
    inventory = {item['path']: item['sha256'] for item in surface['files']}
    pkg.require(all(path in inventory for path in value['trunk_paths']),
                'approved property target is not a regular trunk file')
    return {path: inventory[path] for path in value['trunk_paths']}


class CleanupSVN(wp.SVN):
    def surface(self, name):
        result = super().surface(name)
        result['files'] = pkg.files(self.working / name)
        return result

    def snapshot(self, value):
        revision = self.validate()
        historical = 'tags/' + value['historical_tag']
        pkg.require((self.working / historical).is_dir(), 'historical SVN tag missing')
        result = {
            'svn_url': self.url,
            'working_copy_revision': revision,
            'layout': sorted({path.name for path in self.working.iterdir()} - {'.svn'}),
            'target_tag_exists': (self.working / 'tags' / value['target_tag']).exists(),
            'trunk': self.surface('trunk'),
            'assets': self.surface('assets'),
            'tags': self.surface('tags'),
            'historical_tag': self.surface(historical),
        }
        return result

    def require_approved_inventory(self, snapshot, value):
        expected = expected_properties(value)
        pkg.require(snapshot['target_tag_exists'] is False, 'target SVN tag already exists')
        pkg.require(snapshot['trunk']['properties'] == expected,
                    'live trunk properties do not equal approved exact inventory')
        pkg.require(snapshot['historical_tag']['properties'] == expected,
                    'historical tag properties do not equal approved exact inventory')
        file_hashes(snapshot['trunk'], value)
        file_hashes(snapshot['historical_tag'], value)

    def compare(self, approved, value):
        current = self.snapshot(value)
        self.require_approved_inventory(approved, value)
        self.require_approved_inventory(current, value)
        # The global WordPress.org repository clock can move for unrelated plugins.
        for key in ('svn_url', 'layout', 'target_tag_exists', 'trunk', 'assets', 'tags', 'historical_tag'):
            pkg.require(current[key] == approved[key], 'approved cleanup snapshot drift: ' + key)
        return current

    def apply(self, approved, value):
        self.compare(approved, value)
        before_files = pkg.files(self.working / 'trunk')
        prop = value['property']['name']
        for path_value in value['trunk_paths']:
            target = self.working / 'trunk' / path_value
            pkg.require(target.is_file() and not target.is_symlink(), 'unsafe cleanup property target')
            self.run('propdel', '--quiet', prop, target)
        self.validate_staged(approved, value, before_files)
        return before_files

    def validate_staged(self, approved, value, before_files=None):
        self.validate(clean=False)
        expected_paths = {'trunk/' + path for path in value['trunk_paths']}
        actual = {}
        for entry in self.entries():
            relative = Path(entry.attrib['path']).absolute().relative_to(self.working).as_posix()
            status = entry.find('wc-status')
            actual[relative] = {
                'item': status.attrib['item'],
                'props': status.attrib.get('props'),
                'tree_conflicted': status.attrib.get('tree-conflicted'),
            }
        pkg.require(set(actual) == expected_paths, 'cleanup delta path set mismatch')
        pkg.require(all(item == {'item': 'normal', 'props': 'modified', 'tree_conflicted': None}
                        for item in actual.values()), 'cleanup delta is not property-only')

        summary = ET.fromstring(self.run('diff', '--summarize', '--xml', self.working))
        summarized = {}
        for item in summary.findall('.//path'):
            relative = Path(item.text).absolute().relative_to(self.working).as_posix()
            summarized[relative] = {
                'item': item.attrib.get('item'), 'props': item.attrib.get('props'),
                'kind': item.attrib.get('kind'),
            }
        pkg.require(set(summarized) == expected_paths, 'cleanup summarized delta path set mismatch')
        pkg.require(all(item == {'item': 'none', 'props': 'modified', 'kind': 'file'}
                        for item in summarized.values()), 'cleanup summarized delta is not property-only')

        trunk_files = pkg.files(self.working / 'trunk')
        if before_files is None:
            before_files = approved['trunk']['files']
        pkg.require(trunk_files == before_files == approved['trunk']['files'],
                    'trunk file bytes changed during property cleanup')
        staged_trunk = self.surface('trunk')
        pkg.require(staged_trunk == {**approved['trunk'], 'properties': []},
                    'staged trunk snapshot is not the exact property-only transition')
        pkg.require(self.surface('assets') == approved['assets'], 'assets changed during property cleanup')
        pkg.require(self.surface('tags') == approved['tags'], 'tags changed during property cleanup')
        pkg.require(self.surface('tags/' + value['historical_tag']) == approved['historical_tag'],
                    'historical tag changed during property cleanup')
        pkg.require(not (self.working / 'tags' / value['target_tag']).exists(),
                    'target SVN tag appeared during property cleanup')
        return trunk_files

    def atomic_cleanup(self, approved, value, control_sha, run_id, attempt_path):
        pkg.require(not self.fixture and self.url == wp.SVN_URL,
                    'production cleanup cannot use a fixture URL')
        pkg.require(os.environ.get('WPORG_SVN_USERNAME') == value['expected_author'] and
                    os.environ.get('WPORG_SVN_PASSWORD'),
                    'SVN-specific Environment credentials missing')
        self.validate_staged(approved, value)
        message = commit_message(value, control_sha, run_id)
        attempt = {
            'schema_version': 1,
            'kind': 'SVN_EOL_CLEANUP_COMMIT_ATTEMPT',
            'state': 'SVN_EOL_CLEANUP_OUTCOME_UNKNOWN',
            'repository': pkg.REPOSITORY,
            'workflow_path': WORKFLOW,
            'control_sha': control_sha,
            'run_id': int(run_id),
            'policy_sha256': policy_sha256(value),
            'inventory_sha256': property_inventory_sha256(value),
            'commit_message': message,
            'recovery': 'fresh read-only verification; never automatically recommit',
        }
        pkg.write_json(attempt_path, attempt)
        targets = [str(self.working / 'trunk' / path) for path in value['trunk_paths']]
        try:
            result = subprocess.run([
                'svn', 'commit', *targets, '--non-interactive', '--no-auth-cache',
                '--username', value['expected_author'], '--password-from-stdin', '--message', message,
            ], input=(os.environ['WPORG_SVN_PASSWORD'] + '\n').encode(), env=wp.without_credentials(),
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120, check=False)
            attempt['client_returncode'] = result.returncode
        except (subprocess.TimeoutExpired, OSError):
            attempt['client_returncode'] = None
        pkg.write_json(attempt_path, attempt)
        return attempt

    def verify_cleanup(self, approved, value, control_sha, run_id):
        current = self.snapshot(value)
        pkg.require(current['target_tag_exists'] is False, 'target SVN tag appeared during cleanup')
        pkg.require(not current['trunk']['properties'], 'trunk properties remain after cleanup')
        pkg.require(current['trunk']['files'] == approved['trunk']['files'] and
                    current['trunk']['file_count'] == approved['trunk']['file_count'] and
                    current['trunk']['tree_sha256'] == approved['trunk']['tree_sha256'],
                    'trunk bytes changed during cleanup')
        pkg.require(current['historical_tag'] == approved['historical_tag'],
                    'historical tag changed during cleanup')
        pkg.require(current['tags'] == approved['tags'], 'tags changed during cleanup')
        pkg.require(current['assets'] == approved['assets'], 'assets changed during cleanup')
        before_hashes = file_hashes(approved['trunk'], value)
        after_hashes = file_hashes(current['trunk'], value)
        pkg.require(after_hashes == before_hashes, 'cleanup target file hashes changed')

        revisions = set()
        for path_value in value['trunk_paths']:
            info = ET.fromstring(self.run('info', '--xml', self.working / 'trunk' / path_value)).find('entry')
            revisions.add(int(info.find('commit').attrib['revision']))
        pkg.require(len(revisions) == 1, 'cleanup targets do not share one atomic revision')
        revision = revisions.pop()
        log = ET.fromstring(self.run('log', '--xml', '--verbose', '-r', revision, self.url)).find('logentry')
        pkg.require(log is not None and log.findtext('author') == value['expected_author'] and
                    log.findtext('msg') == commit_message(value, control_sha, run_id),
                    'cleanup commit identity mismatch')
        prefix = '/wc-order-splitter' if not self.fixture else ''
        expected_paths = {prefix + '/trunk/' + path for path in value['trunk_paths']}
        changed = log.findall('paths/path')
        pkg.require({item.text for item in changed} == expected_paths and len(changed) == len(expected_paths),
                    'cleanup commit changed-path set mismatch')
        pkg.require(all(item.attrib.get('action') == 'M' and item.attrib.get('kind') == 'file' and
                        item.attrib.get('prop-mods') == 'true' and item.attrib.get('text-mods') == 'false'
                        for item in changed), 'cleanup commit was not exact property-only modification')
        return {
            'revision': revision,
            'author': log.findtext('author'),
            'message': log.findtext('msg'),
            'before_hashes': before_hashes,
            'after_hashes': after_hashes,
            'trunk_tree_sha256': current['trunk']['tree_sha256'],
            'assets_tree_sha256': current['assets']['tree_sha256'],
            'tags_tree_sha256': current['tags']['tree_sha256'],
            'changed_paths_sha256': pkg.sha(pkg.encoded(sorted(expected_paths))),
        }


def commit_message(value, control_sha, run_id):
    pkg.require(re.fullmatch('[0-9a-f]{40}', control_sha), 'invalid cleanup control SHA')
    pkg.inputs('0' * 40, value['target_tag'], run_id=run_id)
    return (f'WOS-REL-006 remove legacy svn:eol-style from trunk; '
            f'policy {policy_sha256(value)}; control {control_sha}; '
            f'run https://github.com/{pkg.REPOSITORY}/actions/runs/{run_id}')


def control_context(*, mutation=False):
    sha = os.environ.get('GITHUB_SHA', '')
    pkg.require(os.environ.get('GITHUB_REPOSITORY') == pkg.REPOSITORY and
                os.environ.get('GITHUB_EVENT_NAME') == 'workflow_dispatch' and
                os.environ.get('GITHUB_REF') == 'refs/heads/main' and
                os.environ.get('GITHUB_REF_PROTECTED') == 'true' and
                os.environ.get('GITHUB_WORKFLOW_REF') == f'{pkg.REPOSITORY}/{WORKFLOW}@refs/heads/main' and
                os.environ.get('GITHUB_WORKFLOW_SHA') == sha and
                os.environ.get('GITHUB_RUN_ATTEMPT') == '1' and
                re.fullmatch('[0-9a-f]{40}', sha or ''), 'untrusted cleanup control context')
    pkg.inputs('0' * 40, '1.5.0', run_id=os.environ.get('GITHUB_RUN_ID', ''))
    if mutation:
        pkg.require(os.environ.get('CLEANUP_ENVIRONMENT') == ENVIRONMENT and
                    os.environ.get('WPORG_SVN_USERNAME') == 'yoohw' and
                    os.environ.get('WPORG_SVN_PASSWORD'), 'cleanup mutation Environment boundary required')
    else:
        pkg.require(not os.environ.get('WPORG_SVN_USERNAME') and not os.environ.get('WPORG_SVN_PASSWORD'),
                    'read-only cleanup step must not receive SVN credentials')
    return sha, int(os.environ['GITHUB_RUN_ID'])


def work_root():
    root = Path(os.environ['RUNNER_TEMP']) / 'wcos-eol-cleanup'
    root.mkdir(exist_ok=True)
    return root


def say(state, **evidence):
    value = {'state': state, **evidence}
    print(pkg.encoded(value).decode(), end='')
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as handle:
            handle.write('## ' + state + '\n\n```json\n' + pkg.encoded(value).decode() + '```\n')
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as handle:
            handle.write('state=' + state + '\n')


def preflight_record(sha, run_id, value, snapshot):
    return {
        'schema_version': 1,
        'kind': 'SVN_EOL_CLEANUP_PREFLIGHT',
        'state': 'SVN_EOL_CLEANUP_PREFLIGHT_APPROVAL_REQUIRED',
        'repository': pkg.REPOSITORY,
        'workflow_path': WORKFLOW,
        'control_sha': sha,
        'run_id': run_id,
        'run_attempt': 1,
        'policy_sha256': policy_sha256(value),
        'inventory_sha256': property_inventory_sha256(value),
        'approved_file_hashes': file_hashes(snapshot['trunk'], value),
        'snapshot': snapshot,
        'external_mutation': 'NONE',
    }


def read_approved(path, sha, run_id, value):
    record = pkg.read_json(path)
    pkg.require(set(record) == {
        'schema_version', 'kind', 'state', 'repository', 'workflow_path', 'control_sha',
        'run_id', 'run_attempt', 'policy_sha256', 'inventory_sha256',
        'approved_file_hashes', 'snapshot', 'external_mutation',
    }, 'invalid cleanup preflight record fields')
    pkg.require(record['schema_version'] == 1 and record['kind'] == 'SVN_EOL_CLEANUP_PREFLIGHT' and
                record['state'] == 'SVN_EOL_CLEANUP_PREFLIGHT_APPROVAL_REQUIRED' and
                record['repository'] == pkg.REPOSITORY and record['workflow_path'] == WORKFLOW and
                record['control_sha'] == sha and record['run_id'] == run_id and
                record['run_attempt'] == 1 and record['policy_sha256'] == policy_sha256(value) and
                record['inventory_sha256'] == property_inventory_sha256(value) and
                record['approved_file_hashes'] == file_hashes(record['snapshot']['trunk'], value) and
                record['external_mutation'] == 'NONE', 'cleanup preflight provenance mismatch')
    CleanupSVN(Path('/unused'), fixture=True, url='file:///unused').require_approved_inventory(record['snapshot'], value)
    return record


def preflight():
    sha, run_id = control_context()
    value, work = cleanup_policy(), work_root()
    repo = CleanupSVN(work / 'svn-preflight').checkout()
    snapshot = repo.snapshot(value)
    repo.require_approved_inventory(snapshot, value)
    repo.apply(snapshot, value)
    record = preflight_record(sha, run_id, value, snapshot)
    pkg.write_json(work / 'preflight-record.json', record)
    say(record['state'], policy_sha256=record['policy_sha256'],
        inventory_sha256=record['inventory_sha256'], properties=len(value['trunk_paths']),
        trunk_tree_sha256=snapshot['trunk']['tree_sha256'], EXTERNAL_MUTATION='NONE')


def final_record(approved_path, approved, sha, run_id, value):
    return {
        'schema_version': 1,
        'kind': 'SVN_EOL_CLEANUP_FINAL_RECHECK',
        'state': 'SVN_EOL_CLEANUP_FINAL_RECHECK',
        'repository': pkg.REPOSITORY,
        'workflow_path': WORKFLOW,
        'control_sha': sha,
        'run_id': run_id,
        'policy_sha256': policy_sha256(value),
        'inventory_sha256': property_inventory_sha256(value),
        'preflight_sha256': pkg.sha(Path(approved_path).read_bytes()),
        'trunk_tree_sha256': approved['snapshot']['trunk']['tree_sha256'],
        'external_mutation': 'NONE',
    }


def stage():
    sha, run_id = control_context()
    value, work = cleanup_policy(), work_root()
    approved_path = work / 'approved/preflight-record.json'
    approved = read_approved(approved_path, sha, run_id, value)
    repo = CleanupSVN(work / 'svn-final').checkout()
    repo.apply(approved['snapshot'], value)
    record = final_record(approved_path, approved, sha, run_id, value)
    pkg.write_json(work / 'final-record.json', record)
    say(record['state'], policy_sha256=record['policy_sha256'],
        inventory_sha256=record['inventory_sha256'], properties=len(value['trunk_paths']),
        EXTERNAL_MUTATION='NONE')


def commit():
    sha, run_id = control_context(mutation=True)
    value, work = cleanup_policy(), work_root()
    approved_path = work / 'approved/preflight-record.json'
    approved = read_approved(approved_path, sha, run_id, value)
    expected_final = final_record(approved_path, approved, sha, run_id, value)
    pkg.require(pkg.read_json(work / 'final-record.json') == expected_final,
                'final cleanup recheck record mismatch')
    # Recheck once more inside the credential-scoped step. Every read/local SVN
    # child still receives the credential-stripped environment. Updating the
    # staged WC then binds its base revisions immediately before the only write.
    CleanupSVN(work / 'svn-before-commit').checkout().compare(approved['snapshot'], value)
    staged = CleanupSVN(work / 'svn-final')
    staged.run('update', staged.working)
    staged.validate_staged(approved['snapshot'], value)
    attempt = staged.atomic_cleanup(
        approved['snapshot'], value, sha, run_id, work / 'commit-attempt.json')
    say(attempt['state'], policy_sha256=attempt['policy_sha256'],
        inventory_sha256=attempt['inventory_sha256'], properties=len(value['trunk_paths']))


def verify_record(approved_path, approved, cleanup_sha, cleanup_run_id,
                  verification_sha, verification_run_id, value, work):
    result = CleanupSVN(work / 'svn-verification').checkout().verify_cleanup(
        approved['snapshot'], value, cleanup_sha, cleanup_run_id)
    record = {
        'schema_version': 1,
        'kind': 'SVN_EOL_CLEANUP_VERIFICATION',
        'state': 'SVN_EOL_CLEANUP_VERIFIED',
        'repository': pkg.REPOSITORY,
        'workflow_path': WORKFLOW,
        'cleanup_control_sha': cleanup_sha,
        'cleanup_run_id': cleanup_run_id,
        'verification_control_sha': verification_sha,
        'verification_run_id': verification_run_id,
        'policy_sha256': policy_sha256(value),
        'inventory_sha256': property_inventory_sha256(value),
        'preflight_sha256': pkg.sha(approved_path.read_bytes()),
        'before_content_hashes_sha256': pkg.sha(pkg.encoded(result['before_hashes'])),
        'after_content_hashes_sha256': pkg.sha(pkg.encoded(result['after_hashes'])),
        'before_trunk_tree_sha256': approved['snapshot']['trunk']['tree_sha256'],
        'after_trunk_tree_sha256': result['trunk_tree_sha256'],
        'assets_tree_sha256': result['assets_tree_sha256'],
        'tags_tree_sha256': result['tags_tree_sha256'],
        'changed_paths_sha256': result['changed_paths_sha256'],
        'svn_cleanup_revision': result['revision'],
        'commit_author': result['author'],
        'commit_message': result['message'],
        'properties_removed': len(value['trunk_paths']),
        'historical_tag': value['historical_tag'],
        'target_tag_absent': value['target_tag'],
        'out_of_scope_changes': 0,
        'evidence_scope': 'metadata-cleanup-only; never an RC preparation or publication artifact',
    }
    pkg.require(record['before_content_hashes_sha256'] == record['after_content_hashes_sha256'] and
                record['before_trunk_tree_sha256'] == record['after_trunk_tree_sha256'],
                'cleanup verification content identity mismatch')
    pkg.write_json(work / 'verification-record.json', record)
    say(record['state'], svn_cleanup_revision=record['svn_cleanup_revision'],
        inventory_sha256=record['inventory_sha256'], properties_removed=record['properties_removed'],
        out_of_scope_changes=0)


def verify():
    sha, run_id = control_context()
    value, work = cleanup_policy(), work_root()
    approved_path = work / 'approved/preflight-record.json'
    approved = read_approved(approved_path, sha, run_id, value)
    attempt = pkg.read_json(work / 'commit-attempt.json')
    pkg.require(attempt['kind'] == 'SVN_EOL_CLEANUP_COMMIT_ATTEMPT' and
                attempt['control_sha'] == sha and attempt['run_id'] == run_id and
                attempt['policy_sha256'] == policy_sha256(value) and
                attempt['inventory_sha256'] == property_inventory_sha256(value),
                'cleanup commit attempt provenance mismatch')
    verify_record(approved_path, approved, sha, run_id, sha, run_id, value, work)


def original_cleanup_run(api, run_id):
    pkg.inputs('0' * 40, '1.5.0', run_id=run_id)
    run = api.get(f'actions/runs/{run_id}')
    gh.workflow_run(run, WORKFLOW, success=False)
    pkg.require(run['status'] == 'completed' and run['conclusion'] in {
        'success', 'failure', 'cancelled', 'timed_out',
    }, 'original cleanup run is not terminal')
    pkg.require(gh.ancestor(api, run['head_sha'], gh.protected_main(api)),
                'original cleanup control is not on protected main')
    jobs = api.pages(f'actions/runs/{run_id}/attempts/1/jobs', 'jobs')
    preflight_jobs = [job for job in jobs if job['name'] == 'Read-only exact property preflight']
    cleanup_jobs = [job for job in jobs if job['name'] == 'Human-gated exact trunk property cleanup']
    pkg.require(len(preflight_jobs) == 1 and preflight_jobs[0]['status'] == 'completed' and
                preflight_jobs[0]['conclusion'] == 'success', 'original cleanup preflight did not pass')
    pkg.require(len(cleanup_jobs) == 1 and cleanup_jobs[0]['status'] == 'completed',
                'original Environment-gated cleanup job did not complete')
    commit_steps = [step for step in cleanup_jobs[0].get('steps', [])
                    if step['name'] == 'Single exact SVN property-only commit attempt']
    pkg.require(len(commit_steps) == 1 and commit_steps[0]['status'] == 'completed' and
                commit_steps[0]['conclusion'] in {'success', 'failure', 'cancelled'},
                'original cleanup commit step was not reached')
    return run


def recover():
    verification_sha, verification_run_id = control_context()
    original_run_id = int(os.environ.get('ORIGINAL_CLEANUP_RUN_ID', '0'))
    pkg.require(original_run_id != verification_run_id, 'recovery requires a prior cleanup run')
    value, work = cleanup_policy(), work_root()
    api = gh.API()
    pkg.require(gh.control_context(api, WORKFLOW) == verification_sha,
                'recovery control authentication mismatch')
    original = original_cleanup_run(api, original_run_id)
    approved_path = work / 'approved/preflight-record.json'
    approved = read_approved(approved_path, original['head_sha'], original_run_id, value)
    verify_record(approved_path, approved, original['head_sha'], original_run_id,
                  verification_sha, verification_run_id, value, work)


COMMANDS = {'preflight': preflight, 'stage': stage, 'commit': commit,
            'verify': verify, 'recover': recover}


if __name__ == '__main__':
    try:
        pkg.require(len(sys.argv) == 2 and sys.argv[1] in COMMANDS, 'unknown EOL cleanup command')
        COMMANDS[sys.argv[1]]()
    except (ValueError, KeyError, OSError, subprocess.SubprocessError) as error:
        sys.exit('svn-eol-cleanup-error: ' + str(error))
