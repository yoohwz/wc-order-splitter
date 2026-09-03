#!/usr/bin/env python3
"""Trusted, data-only packaging. PRODUCT_TREE_SHA remains owned by product-tree.py."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[2]
SLUG = 'wc-order-splitter'
REPOSITORY = 'yoohwz/wc-order-splitter'
BASELINE = '1.4.11/e1d8aeb8eff38f4ce69dad1a08993e17521c6359'
LIMIT = 100 * 1024 * 1024
FIXED_TIME = (1980, 1, 1, 0, 0, 0)
spec = importlib.util.spec_from_file_location('wcos_product_tree', ROOT / '.github/scripts/product-tree.py')
product = importlib.util.module_from_spec(spec)
spec.loader.exec_module(product)


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def encoded(value):
    return (json.dumps(value, sort_keys=True, ensure_ascii=True, separators=(',', ':')) + '\n').encode()


def write_json(path, value):
    Path(path).write_bytes(encoded(value))


def read_json(path):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            require(key not in result, 'duplicate JSON key')
            result[key] = value
        return result
    return json.loads(Path(path).read_bytes(), object_pairs_hook=unique)


def inputs(candidate, version, digest=None, run_id=None):
    require(isinstance(candidate, str) and re.fullmatch('[0-9a-f]{40}', candidate), 'invalid candidate SHA')
    require(isinstance(version, str) and re.fullmatch(r'(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*)){1,2}', version), 'invalid version')
    require(version not in {'1.4.12', '1.4.13', '1.4.14', '1.4.15'}, 'unpublished intermediate version')
    if digest is not None:
        require(isinstance(digest, str) and re.fullmatch('[0-9a-f]{64}', digest), 'invalid product digest')
    if run_id is not None:
        require(re.fullmatch('[1-9][0-9]*', str(run_id)), 'invalid run ID')


def safe_path(name):
    require(isinstance(name, str) and name and len(name) < 512, 'invalid payload path')
    require(all(re.fullmatch('[A-Za-z0-9_][A-Za-z0-9_. -]*', part) and part not in {'.', '..'}
                for part in name.split('/')), 'unsafe payload path')
    return name


def files(root):
    root = Path(root)
    require(root.is_dir() and not root.is_symlink(), 'unsafe tree root')
    result = []
    for directory, dirs, names in os.walk(root, followlinks=False):
        for name in dirs + names:
            path = Path(directory) / name
            relative = safe_path(path.relative_to(root).as_posix())
            mode = path.lstat().st_mode
            require(stat.S_ISREG(mode) or stat.S_ISDIR(mode), 'non-regular tree entry')
            if stat.S_ISREG(mode):
                data = path.read_bytes()
                result.append({'path': relative, 'size': len(data), 'sha256': sha(data)})
    result.sort(key=lambda item: item['path'].encode())
    require(len({item['path'].casefold() for item in result}) == len(result), 'case-colliding paths')
    require(sum(item['size'] for item in result) <= LIMIT, 'payload too large')
    return result


def metadata(root, version):
    for file, pattern in ((SLUG + '.php', r'^ \* Version: (\S+)\s*$'),
                          ('readme.txt', r'^Stable tag: (\S+)\s*$')):
        matches = re.findall(pattern, (Path(root) / file).read_text(), re.MULTILINE)
        require(matches == [version], 'Version / Stable tag mismatch')


def stage(source, destination, validate=False):
    """Never invoke a candidate-owned helper, PHP entrypoint, hook or build script."""
    source, destination = Path(source).resolve(), Path(destination)
    require(not (source / '.distignore').is_symlink(), 'unsafe .distignore')
    require((source / '.distignore').read_bytes() == (ROOT / '.distignore').read_bytes(), 'distribution exclusions drifted')
    script = 'validate-distribution-contract.sh' if validate else 'stage-distribution.sh'
    subprocess.run(['bash', str(ROOT / '.github/scripts' / script), str(source), str(destination)], check=True)
    files(destination)  # Reject special entries before any package construction.
    return product.product_tree(destination)


def build(staged, output, candidate, version, digest, cert_run, prepare_run):
    inputs(candidate, version, digest, cert_run)
    inputs(candidate, version, digest, prepare_run)
    staged, output = Path(staged), Path(output)
    require(product.product_tree(staged) == digest, 'candidate product identity mismatch')
    metadata(staged, version)
    require(not output.exists(), 'package output already exists')
    output.mkdir(parents=True)
    inventory = files(staged)
    package_name = f'{SLUG}-{version}.zip'
    # STORED deliberately avoids dependence on zlib/host compression versions.
    with zipfile.ZipFile(output / package_name, 'x', compression=zipfile.ZIP_STORED) as archive:
        for item in inventory:
            info = zipfile.ZipInfo(f'{SLUG}/{item["path"]}', FIXED_TIME)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(info, (staged / item['path']).read_bytes())
    manifest = {
        'schema_version': 1, 'repository': REPOSITORY, 'slug': SLUG,
        'candidate_sha': candidate, 'version': version, 'product_tree_sha': digest,
        'release_cert_run_id': int(cert_run), 'preparation_run_id': int(prepare_run),
        'public_baseline': BASELINE, 'package_name': package_name,
        'package_sha256': sha((output / package_name).read_bytes()),
        'file_count': len(inventory), 'files': inventory,
    }
    write_json(output / 'release-manifest.json', manifest)
    return manifest


def manifest_identity(manifest):
    return {key: manifest[key] for key in (
        'candidate_sha', 'version', 'product_tree_sha', 'package_sha256',
        'release_cert_run_id', 'preparation_run_id')}


def validate_manifest(manifest):
    inputs(manifest['candidate_sha'], manifest['version'], manifest['product_tree_sha'], manifest['release_cert_run_id'])
    inputs(manifest['candidate_sha'], manifest['version'], run_id=manifest['preparation_run_id'])
    require(manifest['schema_version'] == 1 and manifest['repository'] == REPOSITORY and
            manifest['slug'] == SLUG and manifest['public_baseline'] == BASELINE, 'manifest authority mismatch')
    require(manifest['package_name'] == f'{SLUG}-{manifest["version"]}.zip', 'package filename mismatch')
    require(re.fullmatch('[0-9a-f]{64}', manifest['package_sha256']), 'invalid package digest')
    inventory = manifest['files']
    require(0 < len(inventory) == manifest['file_count'] <= 10000, 'invalid file count')
    for item in inventory:
        safe_path(item['path'])
        require(set(item) == {'path', 'size', 'sha256'} and type(item['size']) is int and
                0 <= item['size'] <= LIMIT and re.fullmatch('[0-9a-f]{64}', item['sha256']), 'invalid file manifest')
    require(inventory == sorted(inventory, key=lambda item: item['path'].encode()), 'unsorted manifest')
    require(len({item['path'].casefold() for item in inventory}) == len(inventory), 'duplicate manifest path')
    require(sum(item['size'] for item in inventory) <= LIMIT, 'manifest too large')


def zip_data(raw, prefix=''):
    """Parse before extraction: no traversal, aliases, links, duplicates or ZIP bombs."""
    require(len(raw) <= LIMIT, 'archive too large')
    result, seen, total = {}, set(), 0
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        require(len(archive.infolist()) <= 15000, 'archive has too many entries')
        for item in archive.infolist():
            name = item.filename
            require(name not in seen, 'duplicate ZIP entry')
            seen.add(name)
            safe_path(name.rstrip('/'))
            require(name.startswith(prefix) and name != prefix.rstrip('/'), 'unexpected archive root')
            mode = item.external_attr >> 16
            require(stat.S_IFMT(mode) in (0, stat.S_IFREG, stat.S_IFDIR), 'special ZIP entry')
            require(not item.flag_bits & 1, 'encrypted ZIP entry')
            total += item.file_size
            require(total <= LIMIT, 'expanded archive too large')
            if item.is_dir():
                require(item.file_size == 0, 'nonempty ZIP directory')
                continue
            relative = safe_path(name[len(prefix):])
            result[relative] = archive.read(item)
    require(len({name.casefold() for name in result}) == len(result), 'case-colliding ZIP paths')
    return result


def validate_payload(raw, manifest, destination, exact_zip=True):
    validate_manifest(manifest)
    if exact_zip:
        require(sha(raw) == manifest['package_sha256'], 'package digest mismatch')
    data = zip_data(raw, SLUG + '/')
    actual = [{'path': name, 'size': len(value), 'sha256': sha(value)} for name, value in sorted(data.items())]
    require(actual == manifest['files'], 'package file set/content mismatch')
    destination = Path(destination)
    require(not destination.exists(), 'payload destination must not exist')
    destination.mkdir(parents=True)
    for name, value in data.items():
        target = destination / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(value)
        target.chmod(0o644)
    verify_tree(destination, manifest)
    return destination


def verify_tree(root, manifest):
    validate_manifest(manifest)
    require(files(root) == manifest['files'], 'staged file set/content mismatch')
    require(product.product_tree(root) == manifest['product_tree_sha'], 'PRODUCT_TREE_SHA mismatch')
    metadata(root, manifest['version'])


def release_notes(root, version):
    text = (Path(root) / 'changelog.txt').read_text()
    parts = re.split(r'^= ([0-9]+(?:\.[0-9]+){1,2})(?: \([^\n]*\))? =\s*$', text, flags=re.MULTILINE)
    require(len(parts) >= 3 and parts[1] == version, 'public changelog version mismatch')
    notes = parts[2].strip() + '\n'
    require(notes.strip() and not re.search(r'WOS-[A-Z]|NEXT_ACTION_HINT|HUMAN_GATE', notes), 'non-public release notes')
    return notes
