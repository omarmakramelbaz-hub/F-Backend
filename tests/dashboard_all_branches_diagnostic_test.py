#!/usr/bin/env python3
"""Read-only diagnostic regression checks using disposable git and live sources."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    'dashboard_all_branches_diagnostic', ROOT / 'deployment/diagnose_dashboard_all_branches.py')
DIAGNOSTIC = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DIAGNOSTIC)


class DiagnosticTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'public_html'
        self.root.mkdir()
        self.git('init', '--quiet')
        self.git('config', 'user.name', 'Diagnostic fixture')
        self.git('config', 'user.email', 'diagnostic@example.invalid')
        self.base = b''.join(('line ' + str(index) + '\n').encode() for index in range(1, 41))
        self.release = self.base.replace(b'line 3\n', b'released all branches\n')
        for relative in DIAGNOSTIC.ALLOWED_SOURCE_PATHS:
            self.write(relative, self.base)
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Fixture baseline')
        self.base_revision = self.git('rev-parse', 'HEAD').strip().decode()
        for relative in DIAGNOSTIC.ALLOWED_SOURCE_PATHS:
            self.write(relative, self.release)
        installer = (ROOT / DIAGNOSTIC.INSTALLER_PATH).read_bytes()
        installer = installer.replace(b"BASE_REVISION = '754bf038af50dcade0d43f2f6b537a5b155de4d0'",
                                      ("BASE_REVISION = '" + self.base_revision + "'").encode(), 1)
        self.write(DIAGNOSTIC.INSTALLER_PATH, installer)
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Fixture release')
        self.revision = self.git('rev-parse', 'HEAD').strip().decode()
        for relative in DIAGNOSTIC.ALLOWED_SOURCE_PATHS:
            self.write(relative, self.base)
        self.write('.env', b'DIAGNOSTIC_MUST_NOT_READ_THIS_SECRET\n')
        self.installer = DIAGNOSTIC.load_installer(self.root, self.revision)

    def git(self, *arguments):
        result = subprocess.run(['git', '-C', str(self.root), *arguments],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout

    def write(self, relative, body):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)
        return path

    def report(self):
        return DIAGNOSTIC.build_report(self.root, self.revision, self.installer)

    def test_collects_every_conflict_and_all_source_hashes_without_writes(self):
        conflicts = ('public/dashboard/js/dining-pos.js', 'public/dashboard/js/takeaway-pos.js')
        for relative in conflicts:
            self.write(relative, self.base.replace(b'line 3\n', b'unknown live code\n'))
        self.write('public/dashboard/js/home-overview.js',
                   self.base.replace(b'line 20\n', b'independent live drift\n'))
        self.write('public/dashboard/js/branch-expenses.js', self.release)
        snapshot_paths = [self.root / relative for relative in DIAGNOSTIC.ALLOWED_SOURCE_PATHS]
        snapshot_paths += [self.root / '.env', self.root / DIAGNOSTIC.INSTALLER_PATH]
        snapshot = {path: (path.read_bytes(), path.stat().st_mtime_ns) for path in snapshot_paths}
        with mock.patch.object(self.installer, 'install', side_effect=AssertionError('MUTATION_FORBIDDEN')):
            report = self.report()
        self.assertIn('files=35 conflicts=2 unstable=0', report)
        self.assertEqual(report.count('--- FAILED_SOURCE '), 2)
        self.assertEqual(report.count('  live_sha256='), 35)
        self.assertEqual(report.count('  base_sha256='), 35)
        self.assertEqual(report.count('  release_sha256='), 35)
        self.assertIn('status=ALREADY_CURRENT', report)
        self.assertIn('status=APPLICABLE', report)
        self.assertNotIn('DIAGNOSTIC_MUST_NOT_READ_THIS_SECRET', report)
        for relative in conflicts:
            self.assertIn('--- FAILED_SOURCE ' + relative, report)
            self.assertIn('--- base/' + relative, report)
            self.assertIn('+++ live/' + relative, report)
            self.assertIn('+unknown live code', report)
        for path, original in snapshot.items():
            self.assertEqual((path.read_bytes(), path.stat().st_mtime_ns), original)
        self.assertFalse((self.root.parent / 'dashboard-all-branches-backups').exists())
        self.assertEqual(report, self.report())

    def test_reads_pinned_installer_instead_of_mutated_local_copy(self):
        self.write(DIAGNOSTIC.INSTALLER_PATH, b'raise RuntimeError("LOCAL_INSTALLER_MUST_NOT_EXECUTE")\n')
        pinned = DIAGNOSTIC.load_installer(self.root, self.revision)
        self.assertEqual(pinned.BASE_REVISION, self.base_revision)
        self.assertEqual(tuple(pinned.SOURCE_PATHS), DIAGNOSTIC.ALLOWED_SOURCE_PATHS)

    def test_symlink_source_is_reported_without_following_it(self):
        relative = 'public/dashboard/js/dining-pos.js'
        target = self.root / '.env'
        (self.root / relative).unlink()
        (self.root / relative).symlink_to(target)
        report = self.report()
        self.assertIn('files=35 conflicts=1', report)
        self.assertIn('status=SOURCE_UNAVAILABLE', report)
        self.assertIn('SYMLINK_REJECTED', report)
        self.assertNotIn('DIAGNOSTIC_MUST_NOT_READ_THIS_SECRET', report)
        self.assertTrue((self.root / relative).is_symlink())

    def test_rejects_source_allowlist_expansion(self):
        self.installer.SOURCE_PATHS += ('.env',)
        with self.assertRaisesRegex(RuntimeError, 'DIAGNOSTIC_SOURCE_ALLOWLIST_MISMATCH'):
            self.report()

    def test_invalid_utf8_keeps_hash_without_emitting_replacement_source(self):
        relative = 'public/dashboard/js/dining-pos.js'
        live = b'unknown invalid source \xff\n'
        self.write(relative, live)
        report = self.report()
        self.assertIn('status=SOURCE_UNAVAILABLE', report)
        self.assertIn('live_sha256=' + DIAGNOSTIC.sha256(live), report)
        self.assertNotIn('\ufffd', report)

    def test_no_php_lint_bootstrap_network_or_install_commands(self):
        original = self.installer.run
        commands = []
        def observe(command, cwd=None):
            commands.append(command)
            return original(command, cwd)
        with mock.patch.object(self.installer, 'run', side_effect=observe):
            report = self.report()
        self.assertIn('source_changes_performed=0', report)
        self.assertTrue(commands)
        self.assertTrue(all(command[0] == 'git' for command in commands))
        self.assertFalse(any('fetch' in command or 'apply' in command for command in commands))

    def test_full_compressed_js_difference_fits_normal_budget(self):
        relative = 'public/dashboard/js/dining-pos.js'
        long_live = ('liveFunction("' + 'ع' * 50000 + '");\n').encode('utf-8')
        self.write(relative, long_live)
        report = self.report()
        self.assertIn(long_live.decode(), report)
        self.assertNotIn('[DIFF_TRUNCATED:', report)
        self.assertLessEqual(len(report.encode('utf-8')), DIAGNOSTIC.MAX_REPORT_BYTES)

    def test_many_large_conflicts_are_bounded_utf8_and_explicitly_truncated(self):
        for relative in ('public/dashboard/js/dining-pos.js', 'public/dashboard/js/takeaway-pos.js',
                         'public/dashboard/js/home-overview.js', 'public/dashboard/js/branch-stock.js',
                         'public/dashboard/js/branch-recipes.js', 'public/dashboard/js/branch-expenses.js'):
            self.write(relative, ('ع' * 150000 + '\n').encode('utf-8'))
        report = self.report()
        encoded = report.encode('utf-8')
        self.assertEqual(encoded.decode('utf-8'), report)
        self.assertLessEqual(len(encoded), DIAGNOSTIC.MAX_REPORT_BYTES)
        self.assertEqual(report.count('--- FAILED_SOURCE '), 6)
        self.assertEqual(report.count('[DIFF_TRUNCATED:'), 6)
        self.assertIn('=== END DIAGNOSTIC ===', report)

    def test_short_or_unavailable_revision_is_rejected(self):
        for revision in ('1234567', 'a' * 40):
            with self.subTest(revision=revision):
                with self.assertRaisesRegex(RuntimeError, 'FULL_REVISION_REQUIRED|PINNED_COMMIT_UNAVAILABLE'):
                    DIAGNOSTIC.load_installer(self.root, revision)


if __name__ == '__main__':
    unittest.main()
