#!/usr/bin/env python3
"""GitHub-native provenance checks; no Issue parsing or alternative merge policy."""
import json
import os
from pathlib import Path
import re
import urllib.error
import urllib.request

import release_package as pkg

PREPARE = '.github/workflows/release-prepare.yml'
PUBLISH = '.github/workflows/publish-wordpress-org.yml'
CERT = '.github/workflows/release-cert.yml'


class SafeRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        pkg.require(newurl.startswith('https://'), 'non-HTTPS API redirect')
        redirected = super().redirect_request(request, fp, code, message, headers, newurl)
        if redirected is not None:
            redirected.remove_header('Authorization')
        return redirected


class API:
    def __init__(self):
        self.token = os.environ.get('GH_TOKEN', '')
        pkg.require(self.token, 'GitHub token is missing')
        self.opener = urllib.request.build_opener(SafeRedirect())

    def request(self, path, method='GET', value=None, binary=False, missing=False, upload=None):
        pkg.require(path.startswith(f'repos/{pkg.REPOSITORY}/') and '..' not in path, 'unsafe GitHub API path')
        host = 'https://uploads.github.com/' if upload is not None else 'https://api.github.com/'
        headers = {'Authorization': f'Bearer {self.token}', 'User-Agent': 'WOS-release-control',
                   'Accept': 'application/octet-stream' if binary and '/releases/assets/' in path else 'application/vnd.github+json',
                   'X-GitHub-Api-Version': '2022-11-28'}
        data = pkg.encoded(value) if value is not None else upload
        if data is not None:
            headers['Content-Type'] = 'application/octet-stream' if upload is not None else 'application/json'
        request = urllib.request.Request(host + path, data=data, headers=headers, method=method)
        try:
            with self.opener.open(request, timeout=60) as response:
                raw = response.read(pkg.LIMIT + 1)
                pkg.require(len(raw) <= pkg.LIMIT, 'GitHub response too large')
                return raw if binary else json.loads(raw)
        except urllib.error.HTTPError as error:
            if missing and error.code == 404:
                return None
            # Never print response bodies, credentials or redirect query strings.
            raise ValueError(f'GitHub API {method} failed: HTTP {error.code}') from None

    def get(self, endpoint, **kwargs):
        return self.request(f'repos/{pkg.REPOSITORY}/{endpoint}', **kwargs)

    def pages(self, endpoint, key=None):
        result = []
        for page in range(1, 101):
            separator = '&' if '?' in endpoint else '?'
            value = self.get(f'{endpoint}{separator}per_page=100&page={page}')
            batch = value[key] if key else value
            result.extend(batch)
            if len(batch) < 100:
                return result
        raise ValueError('GitHub pagination limit exceeded')


def workflow_run(run, path, success=True, main=True):
    pkg.require(run['repository']['full_name'] == pkg.REPOSITORY and
                run['head_repository']['full_name'] == pkg.REPOSITORY, 'run repository mismatch')
    pkg.require(run['path'] == path and run['event'] == 'workflow_dispatch', 'run workflow/event mismatch')
    if main:
        pkg.require(run['head_branch'] == 'main' and run['run_attempt'] == 1, 'untrusted control-plane run')
    if success:
        pkg.require(run['status'] == 'completed' and run['conclusion'] == 'success', 'run is not successful')


def ancestor(api, older, newer):
    pkg.inputs(older, '1.5.0')
    pkg.inputs(newer, '1.5.0')
    return api.get(f'compare/{older}...{newer}')['status'] in {'ahead', 'identical'}


def protected_main(api):
    branch = api.get('branches/main')
    pkg.require(branch['protected'] is True, 'main is not protected')
    return branch['commit']['sha']


def control_context(api, path):
    expected = {'GITHUB_REPOSITORY': pkg.REPOSITORY, 'GITHUB_REF': 'refs/heads/main',
                'GITHUB_REF_PROTECTED': 'true', 'GITHUB_EVENT_NAME': 'workflow_dispatch',
                'GITHUB_RUN_ATTEMPT': '1', 'GITHUB_WORKFLOW_REF': f'{pkg.REPOSITORY}/{path}@refs/heads/main'}
    pkg.require(all(os.environ.get(key) == value for key, value in expected.items()), 'untrusted workflow context')
    control = os.environ['GITHUB_SHA']
    pkg.inputs(control, '1.5.0')
    pkg.require(os.environ.get('GITHUB_WORKFLOW_SHA') == control, 'workflow/control SHA mismatch')
    pkg.require(ancestor(api, control, protected_main(api)), 'control SHA is not on protected main')
    return control


def ci_check(api, head):
    checks = [check for check in api.pages(f'commits/{head}/check-runs', 'check_runs')
              if check['name'] == 'Required CI' and check.get('app', {}).get('slug') == 'github-actions']
    pkg.require(checks, 'native Required CI missing')
    check = max(checks, key=lambda item: item['id'])
    pkg.require(check['status'] == 'completed' and check['conclusion'] == 'success', 'latest Required CI did not pass')
    match = re.fullmatch(r'https://github.com/yoohwz/wc-order-splitter/actions/runs/([0-9]+)/job/[0-9]+', check['details_url'])
    pkg.require(match, 'Required CI has an unexpected run URL')
    run = api.get(f'actions/runs/{match[1]}')
    pkg.require(run['repository']['full_name'] == pkg.REPOSITORY and run['head_repository']['full_name'] == pkg.REPOSITORY
                and run['path'] == '.github/workflows/ci.yml' and run['event'] == 'pull_request'
                and run['head_sha'] == head and run['status'] == 'completed' and run['conclusion'] == 'success',
                'Required CI provenance mismatch')
    return int(match[1])


def accepted_source(api, candidate, allow_merged_head=False):
    """Squash merges inherit their PR's native CI, not a fictitious new head check."""
    main = protected_main(api)
    is_main = ancestor(api, candidate, main)
    pulls = api.pages(f'commits/{candidate}/pulls')
    for pull in pulls:
        if not pull.get('merged_at') or pull['base']['ref'] != 'main' or pull['base']['repo']['full_name'] != pkg.REPOSITORY:
            continue
        merge, head = pull['merge_commit_sha'], pull['head']['sha']
        if candidate != merge and not (allow_merged_head and candidate == head):
            continue
        if not ancestor(api, merge, main):
            continue
        # Require tree-preserving accepted provenance; ambiguous rebases fail closed.
        merge_tree = api.get(f'git/commits/{merge}')['tree']['sha']
        head_tree = api.get(f'git/commits/{head}')['tree']['sha']
        if merge_tree == head_tree and (is_main or allow_merged_head):
            return {'accepted_sha': merge, 'reviewed_head': head, 'required_ci_run_id': ci_check(api, head)}
    pkg.require(is_main, 'candidate is not an accepted ancestor of protected main')
    return {'accepted_sha': candidate, 'reviewed_head': candidate, 'required_ci_run_id': ci_check(api, candidate)}


def certificate(api, run_id, digest):
    pkg.inputs('0' * 40, '1.5.0', digest, run_id)
    run = api.get(f'actions/runs/{run_id}')
    workflow_run(run, CERT, main=False)
    accepted_source(api, run['head_sha'], allow_merged_head=True)
    jobs = api.pages(f'actions/runs/{run_id}/attempts/{run["run_attempt"]}/jobs', 'jobs')
    finals = [job for job in jobs if job['name'] == 'RELEASE_CERT']
    pkg.require(len(finals) == 1 and finals[0]['status'] == 'completed' and finals[0]['conclusion'] == 'success',
                'expected exactly one successful RELEASE_CERT job')
    job = finals[0]
    expected_prefix = f'https://api.github.com/repos/{pkg.REPOSITORY}/check-runs/'
    check_url = job['check_run_url']
    pkg.require(check_url.startswith(expected_prefix) and check_url[len(expected_prefix):].isdigit(), 'invalid certificate check URL')
    check = api.get('check-runs/' + check_url[len(expected_prefix):])
    pkg.require(check['name'] == 'RELEASE_CERT' and check['status'] == 'completed' and check['conclusion'] == 'success'
                and check['app']['slug'] == 'github-actions' and check['head_sha'] == run['head_sha'], 'untrusted certificate check')
    pkg.require(not api.pages(f'actions/runs/{run_id}/artifacts', 'artifacts'), 'RELEASE_CERT artifacts must be zero')
    raw = api.get(f'actions/jobs/{job["id"]}/logs', binary=True).decode('utf-8-sig')
    lines = [re.sub(r'^\d{4}-\d\d-\d\dT[0-9:.]+Z ', '', line) for line in raw.splitlines()]
    for marker in (f'PRODUCT_TREE_SHA={digest}', f'REPO_HEAD_SHA={run["head_sha"]}',
                   f'PUBLIC_BASELINE={pkg.BASELINE}; GENUINE_UPGRADE=passed',
                   'ARTIFACTS=0; no ZIP, tag, release, publication or deployment'):
        pkg.require(lines.count(marker) == 1, 'certificate evidence identity mismatch')
    return {'run_id': int(run_id), 'run_attempt': run['run_attempt'], 'head_sha': run['head_sha'],
            'product_tree_sha': digest, 'public_baseline': pkg.BASELINE, 'artifacts': 0}


def artifact(api, run, name, destination, allowed):
    matches = [item for item in api.pages(f'actions/runs/{run["id"]}/artifacts', 'artifacts') if item['name'] == name]
    pkg.require(len(matches) == 1 and not matches[0]['expired'], 'immutable artifact missing/ambiguous/expired')
    item = matches[0]
    pkg.require(item['workflow_run']['id'] == run['id'] and item['workflow_run']['head_sha'] == run['head_sha'],
                'artifact run identity mismatch')
    pkg.require(re.fullmatch('sha256:[0-9a-f]{64}', item.get('digest', '')), 'artifact API digest missing')
    raw = api.get(f'actions/artifacts/{item["id"]}/zip', binary=True)
    pkg.require('sha256:' + pkg.sha(raw) == item['digest'], 'downloaded artifact API digest mismatch')
    contents = pkg.zip_data(raw)
    pkg.require(set(contents) == set(allowed), 'unexpected artifact file set')
    destination = Path(destination)
    pkg.require(not destination.exists(), 'artifact destination already exists')
    destination.mkdir(parents=True)
    for filename, data in contents.items():
        (destination / filename).write_bytes(data)
    return {'id': item['id'], 'digest': item['digest'], 'name': name, 'run_id': run['id']}


def prepared(api, run_id, candidate, version, destination):
    pkg.inputs(candidate, version, run_id=run_id)
    run = api.get(f'actions/runs/{run_id}')
    workflow_run(run, PREPARE)
    pkg.require(ancestor(api, run['head_sha'], protected_main(api)), 'preparation control not on protected main')
    accepted_source(api, candidate)
    name = f'{pkg.SLUG}-{version}-{candidate}'
    expected = {f'{pkg.SLUG}-{version}.zip', 'release-manifest.json', 'preparation-record.json', 'plugin-check.json'}
    provenance = artifact(api, run, name, destination, expected)
    manifest = pkg.read_json(Path(destination) / 'release-manifest.json')
    pkg.validate_manifest(manifest)
    pkg.require(manifest['candidate_sha'] == candidate and manifest['version'] == version
                and manifest['preparation_run_id'] == int(run_id), 'preparation manifest identity mismatch')
    record = pkg.read_json(Path(destination) / 'preparation-record.json')
    expected_record = {'schema_version': 1, 'repository': pkg.REPOSITORY, 'workflow_path': PREPARE,
                       'control_sha': run['head_sha'], 'run_id': int(run_id), 'run_attempt': 1,
                       'manifest_sha256': pkg.sha(pkg.encoded(manifest)), 'artifact_name': name,
                       'status': 'RC_PREPARED', 'external_mutation': 'NONE', **pkg.manifest_identity(manifest)}
    pkg.require(record == expected_record, 'preparation record mismatch')
    report = pkg.read_json(Path(destination) / 'plugin-check.json')
    pkg.require(isinstance(report, list) and all(item.get('type', '').upper() != 'ERROR' for item in report),
                'Plugin Check contains Errors')
    certificate(api, manifest['release_cert_run_id'], manifest['product_tree_sha'])
    pkg.validate_payload((Path(destination) / manifest['package_name']).read_bytes(), manifest, Path(destination) / 'payload')
    return manifest, provenance


def tag_message(manifest):
    return 'Order Splitter release identity\n' + pkg.encoded(pkg.manifest_identity(manifest)).decode()


def git_tag(api, manifest, create=False):
    version = manifest['version']
    ref = api.get(f'git/ref/tags/{version}', missing=True)
    if ref is None:
        pkg.require(create, 'immutable Git tag is missing')
        obj = api.get('git/tags', method='POST', value={'tag': version, 'message': tag_message(manifest),
                      'object': manifest['candidate_sha'], 'type': 'commit'})
        api.get('git/refs', method='POST', value={'ref': f'refs/tags/{version}', 'sha': obj['sha']})
        # An uncertain response is not retried. Future invocations start with GET.
        ref = api.get(f'git/ref/tags/{version}')
    pkg.require(ref['ref'] == f'refs/tags/{version}' and ref['object']['type'] == 'tag', 'Git tag is not annotated')
    obj = api.get('git/tags/' + ref['object']['sha'])
    pkg.require(obj['tag'] == version and obj['object'] == {'type': 'commit', 'sha': manifest['candidate_sha'],
                'url': f'https://api.github.com/repos/{pkg.REPOSITORY}/git/commits/{manifest["candidate_sha"]}'}
                and obj['message'] == tag_message(manifest), 'immutable Git tag identity mismatch')
    return ref['object']['sha']


def github_release(api, manifest, prepared_dir, verification):
    pkg.require(verification['state'] == 'WPORG_PUBLIC_RELEASE_VERIFIED'
                and verification['identity'] == pkg.manifest_identity(manifest), 'public verification required before GitHub Release')
    git_tag(api, manifest)
    version = manifest['version']
    prepared_dir = Path(prepared_dir)
    notes = pkg.release_notes(prepared_dir / 'payload', version)
    title = f'Order Splitter {version}'
    release = api.get(f'releases/tags/{version}', missing=True)
    if release is None:
        release = api.get('releases', method='POST', value={'tag_name': version, 'target_commitish': manifest['candidate_sha'],
                          'name': title, 'body': notes, 'draft': True, 'prerelease': False})
    pkg.require(release['tag_name'] == version and release['name'] == title and release['body'] == notes
                and release['prerelease'] is False, 'existing GitHub Release mismatch: Human review required')
    assets = api.pages(f'releases/{release["id"]}/assets')
    expected = {manifest['package_name'], 'release-manifest.json'}
    pkg.require(len({a['name'] for a in assets}) == len(assets) and {a['name'] for a in assets} <= expected,
                'existing Release assets mismatch: Human review required')
    for asset in assets:
        raw = api.get(f'releases/assets/{asset["id"]}', binary=True)
        pkg.require(raw == (prepared_dir / asset['name']).read_bytes(), 'existing Release asset content mismatch')
    missing = expected - {a['name'] for a in assets}
    pkg.require(not missing or release['draft'], 'incomplete public Release requires Human review')
    for name in sorted(missing):
        api.get(f'releases/{release["id"]}/assets?name={name}', method='POST', upload=(prepared_dir / name).read_bytes())
    uploaded = api.pages(f'releases/{release["id"]}/assets')
    pkg.require(len(uploaded) == len(expected) and {asset['name'] for asset in uploaded} == expected,
                'GitHub Release upload set mismatch')
    for asset in uploaded:
        pkg.require(api.get(f'releases/assets/{asset["id"]}', binary=True) == (prepared_dir / asset['name']).read_bytes(),
                    'GitHub Release upload verification failed')
    if release['draft']:
        api.get(f'releases/{release["id"]}', method='PATCH', value={'draft': False, 'make_latest': 'true'})
    final = api.get(f'releases/{release["id"]}')
    pkg.require(final['tag_name'] == version and final['draft'] is False and final['body'] == notes,
                'GitHub Release publication response mismatch')
    return 'GITHUB_RELEASE_PUBLISHED'
