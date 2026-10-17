"""Focused deployment checks; every git repository is isolated in a temp directory."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest


INSTALLER = Path(__file__).resolve().parents[1] / 'deployment/apply_pos_preparation_picker.py'
SPEC = importlib.util.spec_from_file_location('pos_preparation_installer', INSTALLER)
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class PreparationUpdateTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='pos-picker-test-')
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        self.root = self.parent / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Deployment Test')
        self.git('config', 'user.email', 'deployment-test@example.invalid')
        self.before = {}
        self.after = {}
        for index, relative in enumerate(installer.SOURCE_PATHS):
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            header = '<?php\n' if relative.endswith('.php') else ''
            content = header + ''.join('// Unchanged context %s\n' % n for n in range(15))
            content += '// Preparation: legacy-%s\n' % index
            content += ''.join('// Unchanged trailing %s\n' % n for n in range(15))
            self.before[relative] = content.encode()
            self.after[relative] = content.replace('legacy-%s' % index, 'picker-%s' % index).encode()
            path.write_bytes(self.before[relative])
        self.git('add', '.')
        self.git('commit', '-qm', 'Base fixture')
        self.base = self.git('rev-parse', 'HEAD').strip()
        self.previous_base = installer.BASE_REVISION
        installer.BASE_REVISION = self.base
        self.addCleanup(setattr, installer, 'BASE_REVISION', self.previous_base)
        for relative, content in self.after.items():
            (self.root / relative).write_bytes(content)
        self.git('add', '.')
        self.git('commit', '-qm', 'Preparation picker fixture')
        self.revision = self.git('rev-parse', 'HEAD').strip()
        for relative, content in self.before.items():
            (self.root / relative).write_bytes(content)
        self.php = self.parent / 'php-check'
        self.php.write_text('#!/usr/bin/env python3\nimport sys\nsys.exit(0)\n')
        self.php.chmod(0o755)

    def git(self, *arguments):
        return subprocess.check_output(['git', '-C', str(self.root), *arguments], text=True)

    def apply(self, **kwargs):
        with contextlib.redirect_stdout(io.StringIO()):
            return installer.install(self.root, self.revision, str(self.php), **kwargs)

    def test_preserves_unrelated_live_edits_and_reruns_without_backups(self):
        relative = installer.SOURCE_PATHS[0]
        custom = b'// A previously deployed unrelated live customization\n'
        (self.root / relative).write_bytes(self.before[relative] + custom)
        backup = self.apply()
        self.assertEqual((self.root / relative).read_bytes(), self.after[relative] + custom)
        self.assertEqual((backup / relative).read_bytes(), self.before[relative] + custom)
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(receipt['status'], 'installed')
        self.assertEqual({entry['path'] for entry in receipt['files']}, set(installer.SOURCE_PATHS))
        backups_before = sorted(backup.parent.iterdir())
        self.assertIsNone(self.apply())
        self.assertEqual(sorted(backup.parent.iterdir()), backups_before)

    def test_context_conflict_changes_no_live_files(self):
        relative = installer.SOURCE_PATHS[1]
        changed = self.before[relative].replace(b'legacy-1', b'other-release-1')
        (self.root / relative).write_bytes(changed)
        snapshots = {path: (self.root / path).read_bytes() for path in installer.SOURCE_PATHS}
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.apply()
        self.assertEqual(snapshots, {path: (self.root / path).read_bytes()
                                     for path in installer.SOURCE_PATHS})

    def test_live_php_failure_restores_all_three_files(self):
        # Stage validation succeeds; final validation deliberately fails after publication.
        self.php.write_text('#!/usr/bin/env python3\nimport sys\n'
                            'sys.exit(1 if sys.argv[-1].startswith(%r) else 0)\n'
                            % (str(self.root) + '/'))
        with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_INVALID'):
            self.apply()
        for relative, content in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), content)
        receipts = list((self.parent / 'pos-preparation-picker-backups').glob('*/receipt.json'))
        self.assertEqual(len(receipts), 1)
        self.assertEqual(json.loads(receipts[0].read_text())['status'], 'rolled_back')

    def test_check_only_leaves_live_files_unchanged(self):
        self.assertIsNone(self.apply(check_only=True))
        for relative, content in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), content)
        self.assertEqual(list((self.parent / 'pos-preparation-picker-backups').glob('*/receipt.json')), [])

    def test_partially_installed_files_can_finish_safely(self):
        relative = installer.SOURCE_PATHS[0]
        (self.root / relative).write_bytes(self.after[relative])
        backup = self.apply()
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(len(receipt['files']), 2)
        self.assertFalse((backup / relative).exists())
        for path, content in self.after.items():
            self.assertEqual((self.root / path).read_bytes(), content)


if __name__ == '__main__':
    unittest.main()
