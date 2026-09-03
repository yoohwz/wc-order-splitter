#!/usr/bin/env python3
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / '.github/scripts/select-ci-profile.py'
spec = importlib.util.spec_from_file_location('profiles', SCRIPT)
profiles = importlib.util.module_from_spec(spec)
spec.loader.exec_module(profiles)


class Profiles(unittest.TestCase):
    def test_path_table(self):
        cases = {
            'docs/workflow.md': 'FAST', '.github/workflows/ci.yml': 'FAST',
            'tests/integration/stock-marker-validation-smoke.php': 'FAST',
            'AGENTS.md': 'FAST', 'package-lock.json': 'FAST', '.wp-env.json': 'FAST',
            'css/p2-split-admin.css': 'STANDARD', 'js/post-action-tip.js': 'STANDARD',
            'inc/backend/class-wcos-premium-upsell.php': 'STANDARD', 'readme.txt': 'STANDARD',
            'inc/domain/class-wcos-feature-gates.php': 'CRITICAL',
            'inc/domain/class-wcos-order-item-cloner.php': 'CRITICAL',
            'inc/backend/settings.php': 'CRITICAL', 'inc/cores/script.php': 'CRITICAL',
            'js/p2-split-admin.js': 'CRITICAL', 'js/orders.js': 'CRITICAL',
            '.distignore': 'CRITICAL', 'wc-order-splitter.php': 'CRITICAL',
            'new-runtime/file.php': 'CRITICAL', 'docs/../inc/file.php': 'CRITICAL',
        }
        for path, expected in cases.items():
            with self.subTest(path=path):
                self.assertEqual(profiles.path_profile(path), expected)
        self.assertEqual(profiles.select([]), 'CRITICAL')
        self.assertEqual(profiles.select(['docs/a.md', 'inc/domain/new.php']), 'CRITICAL')
        self.assertEqual(profiles.select(['css/style.css', 'docs/a.md']), 'STANDARD')

    def test_real_diff_handles_rename_deletion_and_unusual_names(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            def git(*args):
                return subprocess.check_output(['git', '-C', tmp, *args], stderr=subprocess.DEVNULL).decode().strip()
            git('init', '-q')
            git('config', 'user.name', 'Fixture')
            git('config', 'user.email', 'fixture@example.test')
            (root / 'inc').mkdir()
            (root / 'docs').mkdir()
            old = root / 'inc/critical.php'
            old.write_text('same bytes')
            git('add', '.')
            git('commit', '-qm', 'base')
            base = git('rev-parse', 'HEAD')
            old.rename(root / 'docs/renamed\nfile.md')
            git('add', '-A')
            git('commit', '-qm', 'move')
            result = subprocess.check_output(['python3', str(SCRIPT), base, 'HEAD'], cwd=tmp).decode()
            self.assertIn('"profile": "CRITICAL"', result)
            self.assertIn('inc/critical.php', result)


if __name__ == '__main__':
    unittest.main()
