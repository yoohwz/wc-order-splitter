#!/usr/bin/env python3
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('product_tree', ROOT / '.github/scripts/product-tree.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ProductTree(unittest.TestCase):
    def test_distribution_identity(self):
        with tempfile.TemporaryDirectory() as tmp:
            tmp = Path(tmp)
            source = tmp / 'source'
            source.mkdir()
            shutil.copy(ROOT / '.distignore', source / '.distignore')
            (source / 'plugin.php').write_bytes(b'<?php // product\n')
            (source / 'empty.txt').touch()

            def stage(name):
                dest = tmp / name
                subprocess.run(['bash', str(ROOT / '.github/scripts/stage-distribution.sh'), str(source), str(dest)], check=True)
                return module.product_tree(dest)

            expected = stage('first')
            # Every literal exclusion and both wildcard exclusions must be inert.
            for rule in (source / '.distignore').read_text().splitlines():
                path = rule.lstrip('/')
                if path == '.distignore':
                    continue
                path = path.replace('*', 'fixture')
                target = source / path
                target.parent.mkdir(parents=True, exist_ok=True)
                if '.' not in target.name or path in {'.github', '.git', 'inc/backend/actions', 'inc/mutation-v2'}:
                    target.mkdir(exist_ok=True)
                    target = target / 'excluded.txt'
                target.write_text('excluded bytes')
            self.assertEqual(expected, stage('excluded'))
            for path in source.rglob('*'):
                os.utime(path, (1000000000, 1000000000))
            self.assertEqual(expected, stage('different-directory-and-times'))
            # A repository commit (excluded .git contents) is not product identity.
            (source / '.git/HEAD').write_text('different repository commit')
            self.assertEqual(expected, stage('different-commit'))
            (source / 'plugin.php').write_bytes(b'<?php // changed\n')
            self.assertNotEqual(expected, stage('changed-bytes'))
            (source / 'plugin.php').write_bytes(b'<?php // product\n')
            (source / 'plugin.php').rename(source / 'renamed.php')
            self.assertNotEqual(expected, stage('changed-path'))
            (source / 'renamed.php').rename(source / 'plugin.php')
            (source / 'empty.txt').unlink()
            self.assertNotEqual(expected, stage('deleted-file'))
            (source / 'empty.txt').touch()
            (source / 'added.txt').write_text('new')
            self.assertNotEqual(expected, stage('added-file'))

    def test_rejects_links_special_files_and_empty_tree(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            with self.assertRaises(ValueError):
                module.product_tree(root)
            (root / 'a').write_text('file')
            (root / 'link').symlink_to(root / 'a')
            with self.assertRaises(ValueError):
                module.product_tree(root)
            (root / 'link').unlink()
            os.mkfifo(root / 'fifo')
            with self.assertRaises(ValueError):
                module.product_tree(root)

    def test_framing_and_order(self):
        with tempfile.TemporaryDirectory() as tmp:
            a, b = Path(tmp) / 'a', Path(tmp) / 'b'
            a.mkdir()
            b.mkdir()
            (a / 'a').write_bytes(b'bc')
            (b / 'ab').write_bytes(b'c')
            self.assertNotEqual(module.product_tree(a), module.product_tree(b))
            (b / 'ab').unlink()
            (b / 'z\nline').write_bytes(b'\0')
            (b / 'a').write_bytes(b'bc')
            (a / 'z\nline').write_bytes(b'\0')
            self.assertEqual(module.product_tree(a), module.product_tree(b))


if __name__ == '__main__':
    unittest.main()
