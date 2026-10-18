#!/usr/bin/env python3
"""Safety checks for the byte-scoped live Blade separator installer."""
import contextlib
import importlib.util
import io
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('branch_view_installer',
    Path(__file__).resolve().parents[1] / 'deployment/apply_branch_view_directives.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)
REVISION = '1' * 40


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'application'
        self.root.mkdir()
        self.originals = {}
        self.caches = {}
        for index, relative in enumerate(installer.VIEWS):
            source = self.root / relative
            source.parent.mkdir(parents=True, exist_ok=True)
            body = (b'live-layout-prefix\n@endif@foreach($branches as $branch)\n'
                    b'@endforeach\nlive-layout-suffix\n') if index < 2 else b'unchanged expenses bytes\n'
            source.write_bytes(body)
            source.chmod(0o640)
            self.originals[relative] = body
            cache = self.root / 'storage/framework/views' / (str(index) * 40 + '.php')
            cache.parent.mkdir(parents=True, exist_ok=True)
            cache.write_bytes(b'compiled cache ' + str(index).encode())
            self.caches[relative] = cache

    def preflight(self, root, php, stage, candidates):
        self.assertEqual(root, self.root)
        self.assertEqual(set(candidates), set(self.originals))
        for relative, body in candidates.items():
            self.assertEqual(body, self.originals[relative].replace(installer.OLD, installer.NEW)
                             if relative in installer.VIEWS[:2] else self.originals[relative])
        return self.caches

    def run_install(self, check=False, preflight=None):
        with patch.object(installer, 'preflight', side_effect=preflight or self.preflight):
            with contextlib.redirect_stdout(io.StringIO()):
                installer.install(self.root, REVISION, '/mock/php', check)

    def test_check_preserves_source_and_all_caches(self):
        self.run_install(check=True)
        for relative, before in self.originals.items():
            self.assertEqual((self.root / relative).read_bytes(), before)
            self.assertTrue(self.caches[relative].exists())
        self.assertFalse(list(self.root.parent.glob('branch-view-directives-backups/*/receipt.json')))

    def test_apply_preserves_other_bytes_and_metadata_and_only_clears_changed_views(self):
        self.run_install()
        for index, relative in enumerate(installer.VIEWS):
            source = self.root / relative
            expected = self.originals[relative].replace(installer.OLD, installer.NEW) if index < 2 else self.originals[relative]
            self.assertEqual(source.read_bytes(), expected)
            self.assertEqual(stat.S_IMODE(source.stat().st_mode), 0o640)
            self.assertEqual(source.stat().st_gid, self.root.stat().st_gid)
            self.assertEqual(self.caches[relative].exists(), index == 2)
        backups = list(self.root.parent.glob('branch-view-directives-backups/*/receipt.json'))
        self.assertEqual(len(backups), 1)
        self.assertIn('"status": "applied"', backups[0].read_text())

    def test_newer_shift_and_rerun_leave_unmodified_caches(self):
        relative = installer.VIEWS[1]
        source = self.root / relative
        self.originals[relative] = source.read_bytes().replace(installer.OLD, installer.NEW)
        source.write_bytes(self.originals[relative])
        self.run_install()
        self.assertTrue(self.caches[relative].exists())
        self.originals[installer.VIEWS[0]] = (self.root / installer.VIEWS[0]).read_bytes()
        self.run_install()
        self.assertTrue(self.caches[relative].exists())
        self.assertEqual(len(list(self.root.parent.glob('branch-view-directives-backups/*/receipt.json'))), 1)

    def test_preflight_failure_changes_nothing(self):
        def fail(*args):
            raise RuntimeError('COMPILED_BLADE_PHP_INVALID')
        with self.assertRaisesRegex(RuntimeError, 'COMPILED_BLADE_PHP_INVALID'):
            self.run_install(preflight=fail)
        for relative, before in self.originals.items():
            self.assertEqual((self.root / relative).read_bytes(), before)
            self.assertTrue(self.caches[relative].exists())

    def test_live_change_during_preflight_is_preserved_and_install_stops(self):
        def race(*args):
            result = self.preflight(*args)
            (self.root / installer.VIEWS[0]).write_bytes(b'independent live edit')
            return result
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED_DURING_PREFLIGHT'):
            self.run_install(preflight=race)
        self.assertEqual((self.root / installer.VIEWS[0]).read_bytes(), b'independent live edit')
        self.assertEqual((self.root / installer.VIEWS[1]).read_bytes(), self.originals[installer.VIEWS[1]])
        self.assertTrue(all(path.exists() for path in self.caches.values()))

    def test_failure_after_apply_rolls_back_bytes_and_metadata(self):
        invalidate = installer.invalidate
        calls = 0
        def fail_once(paths):
            nonlocal calls
            calls += 1
            if calls == 1:
                raise RuntimeError('SIMULATED_CACHE_FAILURE')
            invalidate(paths)
        with patch.object(installer, 'invalidate', side_effect=fail_once):
            with self.assertRaisesRegex(RuntimeError, 'SIMULATED_CACHE_FAILURE'):
                self.run_install()
        for relative, before in self.originals.items():
            source = self.root / relative
            self.assertEqual(source.read_bytes(), before)
            self.assertEqual(stat.S_IMODE(source.stat().st_mode), 0o640)
        report = next(self.root.parent.glob('branch-view-directives-backups/*/receipt.json'))
        self.assertIn('"status": "rolled_back"', report.read_text())

    def test_symlink_source_is_rejected(self):
        relative = installer.VIEWS[0]
        source = self.root / relative
        target = self.root / 'other.blade.php'
        source.rename(target)
        source.symlink_to(target)
        with self.assertRaisesRegex(RuntimeError, 'UNSYMLINKED_PATH_REQUIRED'):
            self.run_install()
        self.assertEqual(target.read_bytes(), self.originals[relative])
        self.assertTrue(all(path.exists() for path in self.caches.values()))


if __name__ == '__main__':
    unittest.main()
