#!/usr/bin/env python3
"""Hash staged distributable paths and bytes, never archive or filesystem timestamps."""
import hashlib
import os
from pathlib import Path
import stat
import sys


def product_tree(root):
    root = Path(root)
    if root.is_symlink() or not root.is_dir():
        raise ValueError('expected a staged distribution directory')
    entries = []
    for directory, dirs, files in os.walk(root, followlinks=False):
        for name in dirs + files:
            path = Path(directory) / name
            mode = path.lstat().st_mode
            if not (stat.S_ISREG(mode) or stat.S_ISDIR(mode)):
                raise ValueError('unsupported distribution entry: ' + str(path))
            if stat.S_ISREG(mode):
                entries.append((os.fsencode(path.relative_to(root).as_posix()), path))
    if not entries:
        raise ValueError('empty distribution')
    digest = hashlib.sha256(b'WCOS_PRODUCT_TREE_V1\0')
    for name, path in sorted(entries):
        data = path.read_bytes()
        # Length framing makes path/content boundaries unambiguous, including newlines.
        digest.update(len(name).to_bytes(8, 'big'))
        digest.update(name)
        digest.update(len(data).to_bytes(8, 'big'))
        digest.update(data)
    return digest.hexdigest()


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('usage: product-tree.py STAGED_DISTRIBUTION')
    print('PRODUCT_TREE_SHA=' + product_tree(sys.argv[1]))
