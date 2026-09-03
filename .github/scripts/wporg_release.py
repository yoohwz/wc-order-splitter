#!/usr/bin/env python3
"""SVN local staging, snapshot comparison and authenticated read-only verification.

Only atomic_commit() can write remotely. Its CLI caller is isolated in the
Environment-gated production step; file:// fixtures cannot use that method.
"""
import json
import os
from pathlib import Path
import subprocess
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

import release_package as pkg

SVN_URL = 'https://plugins.svn.wordpress.org/wc-order-splitter'


def policy(path=pkg.ROOT / '.github/release/wporg-policy.json'):
    value = pkg.read_json(path)
    pkg.require(set(value) == {'schema_version', 'slug', 'svn_url', 'assets_mode', 'release_confirmation'}, 'invalid policy fields')
    pkg.require(value['schema_version'] == 1 and value['slug'] == pkg.SLUG and value['svn_url'] == SVN_URL,
                'policy requires the exact canonical slug and HTTPS SVN URL')
    pkg.require(value['assets_mode'] == 'unchanged', 'assets changes are not authorized')
    confirmation = value['release_confirmation']
    pkg.require(set(confirmation) == {'mode', 'observed_at', 'source'} and confirmation['source'], 'confirmation provenance missing')
    pkg.require(confirmation['mode'] in {'unknown', 'enabled', 'disabled'}, 'invalid confirmation mode')
    if confirmation['mode'] == 'unknown':
        pkg.require(confirmation['observed_at'] is None, 'unknown confirmation cannot claim observation time')
    else:
        import datetime
        pkg.require(isinstance(confirmation['observed_at'], str), 'confirmation observation missing')
        observed = datetime.datetime.fromisoformat(confirmation['observed_at'].replace('Z', '+00:00'))
        pkg.require(observed.tzinfo is not None, 'confirmation observation requires timezone')
    return value


def without_credentials():
    return {key: value for key, value in os.environ.items()
            if key not in {'WPORG_SVN_PASSWORD', 'WPORG_SVN_USERNAME', 'GH_TOKEN', 'GITHUB_TOKEN'}}


class SVN:
    def __init__(self, working, url=SVN_URL, *, fixture=False):
        pkg.require(url == SVN_URL or (fixture and url.startswith('file://')), 'noncanonical SVN URL')
        self.working, self.url, self.fixture = Path(working).absolute(), url, fixture
        pkg.require(not self.working.is_symlink(), 'unsafe working-copy root')

    def run(self, *args):
        result = subprocess.run(['svn', '--non-interactive', '--no-auth-cache', *map(str, args)],
                                env=without_credentials(), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                timeout=120, check=False)
        pkg.require(result.returncode == 0, 'SVN read/local staging failed: ' + str(args[0]))
        return result.stdout.decode()

    def checkout(self):
        pkg.require(not self.working.exists(), 'fresh SVN checkout requires absent destination')
        self.run('checkout', '--quiet', '--ignore-externals', self.url, self.working)
        return self

    def entries(self):
        return ET.fromstring(self.run('status', '--xml', '--no-ignore', self.working)).findall('.//entry')

    def validate(self, clean=True):
        pkg.require(self.working.is_dir() and not self.working.is_symlink() and
                    (self.working / '.svn').is_dir(), 'expected SVN working-copy root')
        info = ET.fromstring(self.run('info', '--xml', self.working)).find('entry')
        pkg.require(info.findtext('url').rstrip('/') == self.url, 'unexpected SVN working-copy URL')
        present = {child.name for child in self.working.iterdir()} - {'.svn'}
        pkg.require({'trunk', 'tags', 'assets'} <= present <= {'trunk', 'tags', 'assets', 'branches'}, 'unexpected SVN layout')
        pkg.require(all((self.working / name).is_dir() and not (self.working / name).is_symlink() for name in present),
                    'unexpected SVN root entry')
        properties = ET.fromstring(self.run('proplist', '--xml', '--verbose', '--recursive', self.working))
        pkg.require(not properties.findall('.//property[@name="svn:externals"]'), 'SVN externals are forbidden')
        if clean:
            pkg.require(not self.entries(), 'SVN checkout is not clean')
        return int(info.attrib['revision'])

    def surface(self, name):
        root = self.working / name
        inventory = pkg.files(root)
        infos = ET.fromstring(self.run('info', '--xml', '--depth', 'infinity', root))
        revisions = {}
        for entry in infos.findall('entry'):
            path = Path(entry.attrib['path']).absolute().relative_to(root).as_posix()
            revisions[path] = int(entry.find('commit').attrib['revision'])
        props = []
        for target in ET.fromstring(self.run('proplist', '--xml', '--verbose', '--recursive', root)).findall('target'):
            relative = Path(target.attrib['path']).absolute().relative_to(root).as_posix()
            for item in target.findall('property'):
                props.append({'path': relative, 'name': item.attrib['name'], 'encoding': item.get('encoding'), 'value': item.text})
        return {'file_count': len(inventory), 'tree_sha256': pkg.sha(pkg.encoded(inventory)),
                'revisions': revisions, 'properties': sorted(props, key=lambda item: (item['path'], item['name']))}

    def snapshot(self, version, release_policy):
        pkg.inputs('0' * 40, version)
        revision = self.validate()
        return {'svn_url': self.url, 'working_copy_revision': revision, 'version': version,
                'layout': sorted({p.name for p in self.working.iterdir()} - {'.svn'}),
                'trunk': self.surface('trunk'), 'assets': self.surface('assets'),
                'target_tag_exists': (self.working / 'tags' / version).exists(),
                'release_confirmation': release_policy['release_confirmation']}

    def compare(self, approved, version, release_policy):
        current = self.snapshot(version, release_policy)
        pkg.require(approved['target_tag_exists'] is False and current['target_tag_exists'] is False,
                    'target SVN tag exists or appeared after approval')
        # WordPress.org's global repository revision changes for unrelated plugins.
        # Bind exact relevant node revisions/content/properties, not that global clock.
        for key in ('svn_url', 'version', 'layout', 'trunk', 'assets', 'release_confirmation'):
            pkg.require(current[key] == approved[key], 'approved SVN snapshot drift: ' + key)
        return current

    def validate_delta(self, version):
        entries = self.entries()
        pkg.require(entries, 'SVN staging produced no delta')
        for entry in entries:
            relative = Path(entry.attrib['path']).absolute().relative_to(self.working).as_posix()
            allowed = relative == 'trunk' or relative.startswith('trunk/') or relative == f'tags/{version}' or relative.startswith(f'tags/{version}/')
            status = entry.find('wc-status')
            pkg.require(allowed and status.attrib['item'] in {'added', 'deleted', 'replaced', 'modified', 'normal'}
                        and status.attrib.get('props') not in {'conflicted'}
                        and status.attrib.get('tree-conflicted') != 'true', 'SVN delta outside trunk/new tag or conflicted')

    def stage(self, payload, manifest, release_policy, approved=None):
        version = manifest['version']
        before = self.compare(approved, version, release_policy) if approved else self.snapshot(version, release_policy)
        pkg.require(before['target_tag_exists'] is False, 'target SVN tag already exists')
        # Properties that transform published bytes are not part of the package.
        pkg.require(not before['trunk']['properties'], 'unexpected existing trunk properties require Human review')
        pkg.verify_tree(payload, manifest)
        trunk = self.working / 'trunk'
        for child in trunk.iterdir():
            pkg.safe_path(child.name)
            self.run('delete', '--force', child)
        for item in manifest['files']:
            target = trunk / item['path']
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes((Path(payload) / item['path']).read_bytes())
            target.chmod(0o644)
        self.run('add', '--force', '--no-ignore', '--no-auto-props', trunk)
        self.run('copy', trunk, self.working / 'tags' / version)
        self.validate_delta(version)
        pkg.verify_tree(trunk, manifest)
        pkg.verify_tree(self.working / 'tags' / version, manifest)
        pkg.require(self.surface('assets') == before['assets'], 'assets changed during local staging')
        return before

    def verify(self, manifest, preflight, publish_run):
        self.validate()
        version = manifest['version']
        approved = preflight['snapshot']
        pkg.require(preflight['identity'] == pkg.manifest_identity(manifest) and approved['target_tag_exists'] is False,
                    'original preflight identity mismatch')
        pkg.require(approved['svn_url'] == self.url and approved['version'] == version, 'preflight SVN identity mismatch')
        pkg.verify_tree(self.working / 'trunk', manifest)
        pkg.verify_tree(self.working / 'tags' / version, manifest)
        pkg.require(self.surface('assets') == approved['assets'], 'published assets drifted')
        pkg.require(not self.surface('trunk')['properties'] and not self.surface(f'tags/{version}')['properties'],
                    'unexpected published payload properties')
        tag_log = ET.fromstring(self.run('log', '--xml', '--limit', '1', self.working / 'tags' / version)).find('logentry')
        pkg.require(tag_log is not None, 'SVN tag log missing')
        revision = int(tag_log.attrib['revision'])
        log = ET.fromstring(self.run('log', '--xml', '--verbose', '-r', revision, self.url)).find('logentry')
        pkg.require(log is not None and log.findtext('msg') == commit_message(manifest, publish_run)
                    and log.findtext('author') == 'yoohw', 'SVN commit log/revision identity mismatch')
        pkg.require(revision > approved['working_copy_revision'], 'SVN publication predates preflight')
        paths = log.findall('paths/path')
        prefix = '/wc-order-splitter' if not self.fixture else ''
        tag_root = prefix + f'/tags/{version}'
        pkg.require(any(p.text == tag_root and p.attrib['action'] == 'A' for p in paths), 'SVN tag was not created atomically')
        pkg.require(any(p.text == prefix + '/trunk' or p.text.startswith(prefix + '/trunk/') for p in paths), 'atomic trunk update missing')
        pkg.require(all(p.text == tag_root or p.text.startswith(tag_root + '/') or p.text == prefix + '/trunk'
                        or p.text.startswith(prefix + '/trunk/') for p in paths), 'SVN commit touched unauthorized paths')
        return {'svn_revision': revision, 'svn_url': self.url, 'identity': pkg.manifest_identity(manifest),
                'publish_run_id': int(publish_run), 'assets': approved['assets'], 'state': 'SVN_COMMITTED_VERIFIED'}

    def atomic_commit(self, manifest, publish_run, attempt_path):
        pkg.require(not self.fixture and self.url == SVN_URL, 'production commit cannot use a fixture URL')
        pkg.require(os.environ.get('WPORG_SVN_USERNAME') == 'yoohw' and os.environ.get('WPORG_SVN_PASSWORD'),
                    'SVN-specific Environment credentials missing')
        self.validate(clean=False)
        self.validate_delta(manifest['version'])
        pkg.verify_tree(self.working / 'trunk', manifest)
        pkg.verify_tree(self.working / 'tags' / manifest['version'], manifest)
        attempt = {'state': 'SVN_COMMIT_OUTCOME_UNKNOWN', 'identity': pkg.manifest_identity(manifest),
                   'publish_run_id': int(publish_run), 'recovery': 'verify-only; never automatically recommit'}
        pkg.write_json(attempt_path, attempt)
        try:
            result = subprocess.run([
                'svn', 'commit', str(self.working / 'trunk'), str(self.working / 'tags' / manifest['version']),
                '--non-interactive', '--no-auth-cache', '--username', 'yoohw', '--password-from-stdin',
                '--message', commit_message(manifest, publish_run)],
                input=(os.environ['WPORG_SVN_PASSWORD'] + '\n').encode(), env=without_credentials(),
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120, check=False)
            attempt['client_returncode'] = result.returncode
        except (subprocess.TimeoutExpired, OSError):
            attempt['client_returncode'] = None
        # Even exit 0 is only a hint. Fresh authenticated SVN verification owns success.
        pkg.write_json(attempt_path, attempt)
        return attempt


def commit_message(manifest, publish_run):
    pkg.inputs(manifest['candidate_sha'], manifest['version'], run_id=publish_run)
    return (f'Release {manifest["version"]} from {manifest["candidate_sha"]}; '
            f'PRODUCT_TREE_SHA {manifest["product_tree_sha"]}; package {manifest["package_sha256"]}; '
            f'preparation {manifest["preparation_run_id"]}; publish https://github.com/{pkg.REPOSITORY}/actions/runs/{publish_run}')


def public_state(manifest, output, *, confirmation, verify_only=False, download=None):
    if confirmation != 'disabled' and not verify_only:
        return 'WPORG_RELEASE_CONFIRMATION_PENDING'
    if download is None:
        def download(url):
            with urllib.request.urlopen(url, timeout=30) as response:
                raw = response.read(pkg.LIMIT + 1)
                pkg.require(len(raw) <= pkg.LIMIT, 'public response too large')
                return raw
    try:
        raw = download('https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=wc-order-splitter')
        info = json.loads(raw)
        if info.get('slug') != pkg.SLUG or info.get('version') != manifest['version']:
            return 'WPORG_PROPAGATION_PENDING'
        raw = download(f'https://downloads.wordpress.org/plugin/{pkg.SLUG}.{manifest["version"]}.zip')
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError):
        return 'WPORG_PROPAGATION_PENDING'
    # Once the public API claims this version, different bytes are a hard identity
    # failure, never silently downgraded to success or an SVN recommit instruction.
    pkg.validate_payload(raw, manifest, output, exact_zip=False)
    return 'WPORG_PUBLIC_RELEASE_VERIFIED'
