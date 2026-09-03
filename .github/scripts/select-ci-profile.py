#!/usr/bin/env python3
"""Small, path-only PR profile table. Unknown paths require CRITICAL evidence."""
import json
import os
from pathlib import PurePosixPath
import subprocess
import sys

PROFILES = ('FAST', 'STANDARD', 'CRITICAL')
FAST_DIRS = {'.github', 'docs', 'tests'}
FAST_FILES = {'AGENTS.md', '.gitignore', '.wp-env.json', 'package.json', 'package-lock.json'}
STANDARD_FILES = {
    'readme.txt', 'changelog.txt',
    'inc/backend/class-wcos-premium-upsell.php',
    'inc/backend/yoohw-woo-settings-tabs-reorder.php',
    'js/post-action-tip.js',
}


def path_profile(path):
    parts = PurePosixPath(path).parts
    if not parts or path.startswith('/') or '..' in parts:
        return 'CRITICAL'
    if parts[0] in FAST_DIRS or path in FAST_FILES:
        return 'FAST'
    if parts[0] in {'css', 'languages'} or path in STANDARD_FILES:
        return 'STANDARD'
    return 'CRITICAL'


def select(paths):
    return max((path_profile(p) for p in paths), key=PROFILES.index, default='CRITICAL')


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit('usage: select-ci-profile.py BASE_SHA HEAD_SHA')
    # Disabling rename detection includes both old and new paths. NULs preserve filenames.
    raw = subprocess.check_output(['git', 'diff', '--no-renames', '--name-only', '-z',
                                   sys.argv[1], sys.argv[2], '--'])
    paths = [os.fsdecode(p) for p in raw.split(b'\0') if p]
    profile = select(paths)
    result = {
        'profile': profile,
        'php': json.dumps(['7.4', '8.1', '8.3'] if profile == 'CRITICAL' else ['8.3']),
        'storage': json.dumps(['legacy', 'hpos', 'hpos-sync'] if profile == 'CRITICAL' else ['hpos']),
    }
    print(json.dumps({'paths': paths, **result}, indent=2))
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
            output.write(''.join(f'{key}={value}\n' for key, value in result.items()))
