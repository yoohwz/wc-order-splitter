#!/usr/bin/env python3
"""Narrow workflow entry points; all executable control comes from protected main."""
import html
import json
import os
from pathlib import Path
import re
import subprocess
import sys

import release_package as pkg
import release_github as gh
import wporg_release as wp


def settings():
    candidate, version = os.environ['CANDIDATE_SHA'], os.environ['VERSION']
    pkg.inputs(candidate, version)
    work = Path(os.environ['RUNNER_TEMP']) / 'wcos-release'
    work.mkdir(exist_ok=True)
    return candidate, version, work


def say(state, **evidence):
    value = {'state': state, **evidence}
    print(pkg.encoded(value).decode(), end='')
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as handle:
            handle.write('## ' + state + '\n\n```json\n' + pkg.encoded(value).decode() + '```\n')
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as handle:
            handle.write('state=' + state + '\n')


def candidate_tree(candidate, work, expected, *, validate=False, suffix='candidate-stage'):
    source = Path(os.environ['CANDIDATE_DIR']).resolve()
    pkg.require(source != pkg.ROOT and pkg.ROOT not in source.parents, 'candidate must be separate non-executable data')
    actual = subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD']).decode().strip()
    pkg.require(actual == candidate, 'candidate checkout SHA mismatch')
    digest = pkg.stage(source, work / suffix, validate=validate)
    pkg.require(digest == expected, 'candidate does not match certified product identity')


def prepare():
    candidate, version, work = settings()
    api = gh.API()
    gh.control_context(api, gh.PREPARE)
    digest, cert_run = os.environ['PRODUCT_TREE_SHA'], os.environ['RELEASE_CERT_RUN_ID']
    pkg.inputs(candidate, version, digest, cert_run)
    gh.accepted_source(api, candidate)
    gh.certificate(api, cert_run, digest)
    for suffix in ('a', 'b'):
        candidate_tree(candidate, work, digest, validate=True, suffix='stage-' + suffix)
        pkg.build(work / ('stage-' + suffix), work / ('rc-' + suffix), candidate, version,
                  digest, cert_run, os.environ['GITHUB_RUN_ID'])
    for name in (f'{pkg.SLUG}-{version}.zip', 'release-manifest.json'):
        pkg.require((work / 'rc-a' / name).read_bytes() == (work / 'rc-b' / name).read_bytes(), 'nondeterministic package build')
    say('DETERMINISTIC_PACKAGE_VERIFIED', candidate_sha=candidate, product_tree_sha=digest, EXTERNAL_MUTATION='NONE')


def parse_plugin_check(raw):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            pkg.require(key not in result, 'duplicate Plugin Check field')
            result[key] = value
        return result

    lines = [line.strip() for line in re.sub(r'\x1b\[[0-9;]*m', '', raw).splitlines()]
    matches = [json.loads(line, object_pairs_hook=unique) for line in lines if line.startswith('[')]
    clean = lines.count('Success: Checks complete. No errors found.')
    pkg.require(len(matches) == 1 and clean == 0 or not matches and clean == 1, 'Plugin Check report missing or ambiguous')
    report = matches[0] if matches else []
    pkg.require(isinstance(report, list) and all(isinstance(item, dict) and
                all(isinstance(item.get(key), str) for key in ('type', 'code', 'file', 'message')) and
                all(key not in item or isinstance(item[key], str) or type(item[key]) is int
                    for key in ('line', 'column')) and
                ('docs' not in item or isinstance(item['docs'], str)) for item in report), 'invalid Plugin Check report')
    pkg.require(all(item['type'].upper() in {'WARNING', 'ERROR'} for item in report), 'unexpected Plugin Check result type')
    return report


def diagnostic_text(value):
    """Allowlisted report fields only; never inspect or serialize credential env."""
    value = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', value)
    value = re.sub(r'[\r\n\t]', ' ', value)
    value = re.sub(r'[\x00-\x1f\x7f-\x9f]', '', value)
    value = re.sub(r'\b(?:gh[pousr]_[A-Za-z0-9]+|github_pat_[A-Za-z0-9_]+)\b', '[REDACTED]', value)
    value = re.sub(r'(?i)\b(Bearer|Basic)\s+[^\s,;<>]+', r'\1 [REDACTED]', value)
    value = re.sub(r'''(?ix)(["']?\b(?:[a-z0-9_]*(?:token|password|secret|api_key|private_key)|authorization)["']?\s*[:=]\s*)
                      (?:"[^"]*"|'[^']*'|[^\s&,;<>]+)''', r'\1[REDACTED]', value)
    return re.sub(r'(?i)(https?://)[^/\s:@]+:[^/\s@]+@', r'\1[REDACTED]@', value)


def plugin_check_diagnostics(path, report):
    fields = ('type', 'code', 'file', 'line', 'column', 'message', 'docs')
    findings = [] if report is None else [
        {key: diagnostic_text(item[key]) if isinstance(item[key], str) else item[key]
         for key in fields if key in item} for item in report]
    findings.sort(key=pkg.encoded)
    errors = [item for item in findings if item['type'].upper() == 'ERROR']
    warnings = [item for item in findings if item['type'].upper() == 'WARNING']
    evidence = {'schema_version': 1, 'kind': 'PLUGIN_CHECK_DIAGNOSTICS',
                'status': 'INVALID_REPORT' if report is None else 'BLOCKED' if errors else 'PASSED',
                'error_count': None if report is None else len(errors),
                'warning_count': None if report is None else len(warnings), 'findings': findings}
    pkg.write_json(path, evidence)
    summary = ['Plugin Check report malformed or ambiguous; preparation blocked.'] if report is None else [
        f'Plugin Check: {len(errors)} ERROR(s), {len(warnings)} WARNING(s).',
        *('Plugin Check ERROR: ' + pkg.encoded(item).decode().strip().replace('##[', r'\u0023\u0023[') for item in errors),
        f'All {len(findings)} findings retained in sanitized diagnostic JSON.']
    text = '\n'.join(summary) + '\n'
    # Escape both newlines and legacy Actions markers without losing JSON round-trip.
    print(text, end='')
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as handle:
            handle.write('## Plugin Check diagnostics\n\n<pre>' + html.escape(text) + '</pre>\n')


def plugin_check_report(raw, diagnostic_path=None):
    try:
        report = parse_plugin_check(raw)
    except (ValueError, TypeError):
        if diagnostic_path is not None:
            plugin_check_diagnostics(diagnostic_path, None)
        raise ValueError('Plugin Check report malformed or ambiguous') from None
    if diagnostic_path is not None:
        plugin_check_diagnostics(diagnostic_path, report)
    pkg.require(not any(item['type'].upper() == 'ERROR' for item in report), 'Plugin Check Errors block preparation; no ignore baseline')
    return report


def plugin_check_evidence():
    _, _, work = settings()
    raw = work / 'plugin-check-raw.txt'
    plugin_check_report(raw.read_text() if raw.is_file() else '', work / 'plugin-check-diagnostics.json')


def finish_prepare():
    candidate, version, work = settings()
    api = gh.API()
    control = gh.control_context(api, gh.PREPARE)
    manifest = pkg.read_json(work / 'rc-a/release-manifest.json')
    pkg.require(manifest['candidate_sha'] == candidate and manifest['version'] == version and
                manifest['product_tree_sha'] == os.environ['PRODUCT_TREE_SHA'], 'prepared identity changed')
    pkg.verify_tree(work / 'stage-a', manifest)
    pkg.validate_payload((work / 'rc-a' / manifest['package_name']).read_bytes(), manifest, work / 'validated-package')
    report = plugin_check_report((work / 'plugin-check-raw.txt').read_text(), work / 'plugin-check-diagnostics.json')
    pkg.write_json(work / 'rc-a/plugin-check.json', report)
    name = f'{pkg.SLUG}-{version}-{candidate}'
    record = {'schema_version': 1, 'repository': pkg.REPOSITORY, 'workflow_path': gh.PREPARE,
              'control_sha': control, 'run_id': int(os.environ['GITHUB_RUN_ID']), 'run_attempt': 1,
              'manifest_sha256': pkg.sha(pkg.encoded(manifest)), 'artifact_name': name,
              'status': 'RC_PREPARED', 'external_mutation': 'NONE', **pkg.manifest_identity(manifest)}
    pkg.write_json(work / 'rc-a/preparation-record.json', record)
    with open(os.environ['GITHUB_OUTPUT'], 'a') as handle:
        handle.write('artifact_name=' + name + '\n')
    say('RC_PREPARED', **pkg.manifest_identity(manifest), file_count=manifest['file_count'], EXTERNAL_MUTATION='NONE')


def publication_context():
    candidate, version, work = settings()
    pkg.require(os.environ.get('OPERATION') in {'publish', 'verify-only'} and os.environ.get('DRY_RUN') in {'true', 'false'}, 'invalid publication operation')
    if os.environ['OPERATION'] == 'verify-only':
        pkg.inputs(candidate, version, run_id=os.environ['ORIGINAL_PUBLISH_RUN_ID'])
    api = gh.API()
    control = gh.control_context(api, gh.PUBLISH)
    manifest, provenance = gh.prepared(api, os.environ['PREPARATION_RUN_ID'], candidate, version, work / 'prepared')
    candidate_tree(candidate, work, manifest['product_tree_sha'])
    pkg.write_json(work / 'context.json', {'control_sha': control, 'artifact': provenance})
    return manifest, work


def current():
    candidate, version, work = settings()
    manifest = pkg.read_json(work / 'prepared/release-manifest.json')
    pkg.require(manifest['candidate_sha'] == candidate and manifest['version'] == version and
                manifest['preparation_run_id'] == int(os.environ['PREPARATION_RUN_ID']), 'current manifest/input mismatch')
    pkg.verify_tree(work / 'prepared/payload', manifest)
    return manifest, work


def record_base(manifest):
    return {'schema_version': 1, 'repository': pkg.REPOSITORY, 'workflow_path': gh.PUBLISH,
            'control_sha': os.environ['GITHUB_SHA'], 'run_id': int(os.environ['GITHUB_RUN_ID']),
            'identity': pkg.manifest_identity(manifest)}


def preflight():
    manifest, work = current()
    pkg.require(os.environ['OPERATION'] == 'publish', 'preflight is publish-only')
    release_policy = wp.policy()
    repo = wp.SVN(work / 'svn-preflight').checkout()
    before = repo.stage(work / 'prepared/payload', manifest, release_policy)
    record = {**record_base(manifest), 'state': 'READ_ONLY_PUBLICATION_PREFLIGHT',
              'dry_run': os.environ['DRY_RUN'] == 'true', 'snapshot': before,
              'preparation_artifact': pkg.read_json(work / 'context.json')['artifact']}
    pkg.write_json(work / 'preflight-record.json', record)
    say(record['state'], record=record, EXTERNAL_MUTATION='NONE')


def original_preflight(api, run_id, manifest, work, active=False):
    run = api.get(f'actions/runs/{run_id}')
    gh.workflow_run(run, gh.PUBLISH, success=False)
    if not active:
        pkg.require(run['status'] == 'completed', 'original publisher is still running')
    pkg.require(gh.ancestor(api, run['head_sha'], gh.protected_main(api)), 'original publisher control not accepted')
    jobs = api.pages(f'actions/runs/{run_id}/attempts/1/jobs', 'jobs')
    found = [job for job in jobs if job['name'] == 'Read-only publication preflight']
    pkg.require(len(found) == 1 and found[0]['conclusion'] == 'success', 'original preflight job did not pass')
    gh.artifact(api, run, f'wporg-preflight-{run_id}', work / 'approved', {'preflight-record.json'})
    approved = pkg.read_json(work / 'approved/preflight-record.json')
    expected = {'schema_version': 1, 'repository': pkg.REPOSITORY, 'workflow_path': gh.PUBLISH,
                'control_sha': run['head_sha'], 'run_id': int(run_id), 'identity': pkg.manifest_identity(manifest),
                'state': 'READ_ONLY_PUBLICATION_PREFLIGHT',
                'preparation_artifact': pkg.read_json(work / 'context.json')['artifact']}
    pkg.require(all(approved.get(key) == value for key, value in expected.items()), 'original preflight provenance mismatch')
    pkg.require(approved['snapshot']['target_tag_exists'] is False, 'preflight did not prove tag absence')
    if not active:
        pkg.require(approved['dry_run'] is False, 'dry-run evidence cannot authorize publication recovery')
    return approved, run


def recheck():
    manifest, work = current()
    api = gh.API()
    approved, _ = original_preflight(api, os.environ['GITHUB_RUN_ID'], manifest, work, active=True)
    pkg.require(approved['dry_run'] == (os.environ['DRY_RUN'] == 'true'), 'preflight dry-run intent changed')
    repo = wp.SVN(work / 'svn-final').checkout()
    repo.stage(work / 'prepared/payload', manifest, wp.policy(), approved['snapshot'])
    pkg.write_json(work / 'final-record.json', {**record_base(manifest), 'state': 'FINAL_PRE_MUTATION_REMOTE_RECHECK',
                   'preflight_sha256': pkg.sha(pkg.encoded(approved))})
    say('FINAL_PRE_MUTATION_REMOTE_RECHECK', identity=pkg.manifest_identity(manifest), EXTERNAL_MUTATION='NONE')


def mutation_guard(manifest, work):
    pkg.require(os.environ.get('OPERATION') == 'publish' and os.environ.get('DRY_RUN') == 'false'
                and os.environ.get('PUBLISH_ENVIRONMENT') == 'wordpress-org-production', 'production Environment boundary required')
    final = pkg.read_json(work / 'final-record.json')
    pkg.require(final == {**record_base(manifest), 'state': 'FINAL_PRE_MUTATION_REMOTE_RECHECK',
                'preflight_sha256': pkg.sha((work / 'approved/preflight-record.json').read_bytes())}, 'final recheck record mismatch')


def seal():
    manifest, work = current()
    mutation_guard(manifest, work)
    tag = gh.git_tag(gh.API(), manifest, create=True)
    pkg.write_json(work / 'tag-record.json', {'identity': pkg.manifest_identity(manifest), 'tag_object': tag})
    say('TAG_SEALED', identity=pkg.manifest_identity(manifest), tag_object=tag)


def commit():
    manifest, work = current()
    mutation_guard(manifest, work)
    pkg.require(pkg.read_json(work / 'tag-record.json')['identity'] == pkg.manifest_identity(manifest), 'Git tag seal missing')
    approved = pkg.read_json(work / 'approved/preflight-record.json')
    # Recheck again after tag creation, before the only SVN write attempt.
    wp.SVN(work / 'svn-before-commit').checkout().compare(approved['snapshot'], manifest['version'], wp.policy())
    wp.SVN(work / 'svn-final').atomic_commit(manifest, os.environ['GITHUB_RUN_ID'], work / 'commit-attempt.json')
    say('SVN_COMMIT_REQUIRES_VERIFICATION', identity=pkg.manifest_identity(manifest))


def verification(manifest, work, approved, original_run, *, verify_only):
    api = gh.API()
    gh.git_tag(api, manifest)
    result = wp.SVN(work / 'svn-verification').checkout().verify(manifest, approved, original_run)
    durable = {**record_base(manifest), **result}
    pkg.write_json(work / 'publication-record.json', durable)
    state = wp.public_state(manifest, work / 'public-payload',
                            confirmation=approved['snapshot']['release_confirmation']['mode'], verify_only=verify_only)
    durable['state'] = state
    pkg.write_json(work / 'publication-record.json', durable)
    say(state, identity=pkg.manifest_identity(manifest), svn_revision=result['svn_revision'],
        next_step='verify-only; never recommit SVN' if state != 'WPORG_PUBLIC_RELEASE_VERIFIED' else 'GitHub Release may follow')
    return durable


def verify():
    manifest, work = current()
    pkg.require((work / 'commit-attempt.json').exists(), 'no SVN commit attempt to verify')
    verification(manifest, work, pkg.read_json(work / 'approved/preflight-record.json'), os.environ['GITHUB_RUN_ID'], verify_only=False)


def recover():
    manifest, work = current()
    pkg.require(os.environ['OPERATION'] == 'verify-only' and not os.environ.get('WPORG_SVN_PASSWORD'), 'verify-only must have no SVN password')
    api, run_id = gh.API(), os.environ['ORIGINAL_PUBLISH_RUN_ID']
    approved, run = original_preflight(api, run_id, manifest, work)
    result = verification(manifest, work, approved, run_id, verify_only=True)
    artifacts = api.pages(f'actions/runs/{run_id}/artifacts', 'artifacts')
    if any(item['name'] == f'wporg-publication-{run_id}' for item in artifacts):
        gh.artifact(api, run, f'wporg-publication-{run_id}', work / 'original-publication', {'publication-record.json'})
        original = pkg.read_json(work / 'original-publication/publication-record.json')
        for key in ('identity', 'svn_revision', 'svn_url', 'publish_run_id', 'assets'):
            pkg.require(original[key] == result[key], 'durable publication record mismatch: ' + key)
    # Absence alone is recoverable from the authenticated immutable preflight +
    # tag + exact SVN author/message/revision/atomic path set, never by recommit.


def release():
    manifest, work = current()
    pkg.require(os.environ['DRY_RUN'] == 'false' and os.environ.get('PUBLISH_ENVIRONMENT') == 'wordpress-org-production'
                and not os.environ.get('WPORG_SVN_PASSWORD'), 'GitHub Release boundary violated')
    api = gh.API()
    run_id = os.environ['GITHUB_RUN_ID']
    run = api.get(f'actions/runs/{run_id}')
    gh.workflow_run(run, gh.PUBLISH, success=False)
    gh.artifact(api, run, f'wporg-publication-{run_id}', work / 'verified', {'publication-record.json'})
    record = pkg.read_json(work / 'verified/publication-record.json')
    pkg.require(all(record.get(key) == value for key, value in record_base(manifest).items())
                and record['state'] == 'WPORG_PUBLIC_RELEASE_VERIFIED', 'authenticated public verification required')
    original_run = str(record['publish_run_id'])
    approved, _ = original_preflight(api, original_run, manifest, work, active=original_run == run_id)
    # Revalidate public/SVN identity after any Environment wait, not just an output token.
    refreshed = verification(manifest, work, approved, original_run, verify_only=True)
    pkg.require(refreshed['svn_revision'] == record['svn_revision'], 'SVN publication revision drifted')
    say(gh.github_release(api, manifest, work / 'prepared', refreshed), identity=pkg.manifest_identity(manifest))


COMMANDS = {'prepare': prepare, 'plugin-check-evidence': plugin_check_evidence,
            'finish-prepare': finish_prepare, 'context': publication_context,
            'preflight': preflight, 'recheck': recheck, 'seal': seal, 'commit': commit,
            'verify': verify, 'recover': recover, 'release': release}

if __name__ == '__main__':
    try:
        pkg.require(len(sys.argv) == 2 and sys.argv[1] in COMMANDS, 'unknown release command')
        COMMANDS[sys.argv[1]]()
    except (ValueError, KeyError, OSError, subprocess.SubprocessError) as error:
        sys.exit('release-control-error: ' + str(error))
