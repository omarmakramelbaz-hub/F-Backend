#!/usr/bin/env python3
"""Exercise only compact-preview source deployment against disposable Git fixtures."""
import contextlib
import importlib.util
import io
import json
import os
import re
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock


SPEC = importlib.util.spec_from_file_location(
    'shift_compact_preview_installer',
    Path(__file__).resolve().parents[1] / 'deployment/apply_shift_compact_preview.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)
PHP = os.environ.get('SHIFT_PREVIEW_TEST_PHP', '/workspace/scratch/6d0f00b092cb/php-qa')


class CompactPreviewInstallerTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        self.root = self.parent / 'public_html'
        self.root.mkdir()
        self.git('init', '--quiet')
        self.git('config', 'user.name', 'Compact preview test')
        self.git('config', 'user.email', 'compact-preview@example.invalid')
        self.paths = INSTALLER.SOURCE_PATHS
        self.first, self.second = self.paths[:2]
        self.base, self.release = {}, {}
        for relative in self.paths:
            comments = ''.join(('/* line %d */\n' if relative.endswith('.css') else '// line %d\n') % n for n in range(1, 41))
            body = (('<?php\n' if relative.endswith('.php') else '') + comments).encode()
            self.base[relative] = body
            self.release[relative] = re.sub(rb'line 30\b', b'preview controls', re.sub(rb'line 3\b', b'compact page', body))
            self.write(relative, body)
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Fixture baseline')
        self.base_revision = self.git('rev-parse', 'HEAD').decode().strip()
        for relative, body in self.release.items():
            self.write(relative, body)
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Fixture compact preview')
        self.revision = self.git('rev-parse', 'HEAD').decode().strip()
        self.git('reset', '--hard', '--quiet', self.base_revision)
        self.base_patch = mock.patch.object(INSTALLER, 'BASE_REVISION', self.base_revision)
        self.base_patch.start()
        self.addCleanup(self.base_patch.stop)
        self.route_cache = self.root / 'bootstrap/cache/routes-v7.php'
        self.views_patch = mock.patch.object(INSTALLER, 'cache_artifacts', side_effect=self.cache_paths)
        self.views_patch.start()
        self.addCleanup(self.views_patch.stop)

    def git(self, *arguments):
        result = subprocess.run(['git', '-C', str(self.root), *arguments], capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout

    def write(self, relative, body):
        target = self.root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(body)
        return target

    def install(self, check_only=False):
        with contextlib.redirect_stdout(io.StringIO()):
            return INSTALLER.install(self.root, self.revision, PHP, check_only)

    def assert_sources(self, expected):
        for relative, body in expected.items():
            self.assertEqual((self.root / relative).read_bytes(), body, relative)

    def cache_paths(self, root, php, pending):
        return (), self.route_cache, INSTALLER.read_source(self.route_cache, absent_ok=True)

    def receipts(self):
        return list((self.parent / 'shift-compact-preview-backups').glob('*/receipt.json'))

    def test_apply_backup_metadata_atomic_replace_and_replay(self):
        target = self.root / self.first
        target.chmod(0o750)
        original_inode = target.stat().st_ino
        backup = self.install()
        self.assert_sources(self.release)
        self.assertNotEqual(target.stat().st_ino, original_inode)
        self.assertEqual(target.stat().st_mode & 0o777, 0o750)
        self.assertEqual((backup / self.first).read_bytes(), self.base[self.first])
        self.assertEqual((backup / self.first).stat().st_mode & 0o777, 0o600)
        self.assertEqual(json.loads((backup / 'receipt.json').read_text())['status'], 'installed')
        self.assertIsNone(self.install())
        self.assertEqual(len(self.receipts()), 1)

    def test_three_way_merge_preserves_independent_and_partial_live_changes(self):
        live = self.base[self.first].replace(b'line 3\n', b'compact page\n').replace(b'line 15', b'independent live behavior')
        self.write(self.first, live)
        self.install()
        expected = self.release | {self.first: self.release[self.first].replace(b'line 15', b'independent live behavior')}
        self.assert_sources(expected)
        self.assertIsNone(self.install())

    def test_conflict_checks_all_sources_before_writing(self):
        conflict = self.base[self.second].replace(b'line 30', b'unreviewed competing behavior')
        self.write(self.second, conflict)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.install()
        self.assert_sources(self.base | {self.second: conflict})
        self.assertEqual(self.receipts(), [])

    def test_check_only_retains_sources_and_compiled_cache(self):
        compiled = self.write('storage/framework/views/fixture.php', b'compiled original')
        with mock.patch.object(INSTALLER, 'cache_artifacts', return_value=((compiled,), self.route_cache, (None, None))):
            self.install(check_only=True)
        self.assert_sources(self.base)
        self.assertEqual(compiled.read_bytes(), b'compiled original')
        self.assertEqual(self.receipts(), [])

    def test_success_clears_only_selected_compiled_views(self):
        compiled = self.write('storage/framework/views/selected.php', b'compiled selected')
        other = self.write('storage/framework/views/other.php', b'compiled other')
        with mock.patch.object(INSTALLER, 'cache_artifacts', return_value=((compiled,), self.route_cache, (None, None))):
            self.install()
        self.assertFalse(compiled.exists())
        self.assertEqual(other.read_bytes(), b'compiled other')

    def test_success_backs_up_and_clears_only_the_named_route_cache(self):
        self.write('bootstrap/cache/routes-v7.php', b'<?php return ["old routes"];')
        other = self.write('bootstrap/cache/config.php', b'<?php return ["configuration"];')
        original = self.route_cache.read_bytes()
        backup = self.install()
        self.assertFalse(self.route_cache.exists())
        self.assertEqual(other.read_bytes(), b'<?php return ["configuration"];')
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(receipt['route_cache']['before_sha256'], INSTALLER.digest(original))
        saved = backup / receipt['route_cache']['backup_path']
        self.assertEqual(saved.read_bytes(), original)
        self.assertEqual(saved.stat().st_mode & 0o777, 0o600)

    def test_check_only_retains_existing_route_cache(self):
        self.write('bootstrap/cache/routes-v7.php', b'<?php return ["cached routes"];')
        original = self.route_cache.read_bytes()
        self.install(check_only=True)
        self.assertEqual(self.route_cache.read_bytes(), original)
        self.assert_sources(self.base)
        self.assertEqual(self.receipts(), [])

    def test_route_cache_location_and_symlink_validation(self):
        self.views_patch.stop()
        views = []
        for candidate in ['bootstrap/cache/config.php', 'storage/routes-v7.php', 'bootstrap/cache/nested/routes.php']:
            payload = {'views': views, 'routes': str(self.root / candidate)}
            response = subprocess.CompletedProcess([], 0, json.dumps(payload).encode(), b'')
            with self.subTest(candidate=candidate), mock.patch.object(INSTALLER, 'run', return_value=response):
                with self.assertRaisesRegex(RuntimeError, 'ROUTE_CACHE_LOCATION_NEEDS_REVIEW'):
                    INSTALLER.cache_artifacts(self.root, PHP, ())
        external = self.parent / 'outside-route-cache.php'
        external.write_bytes(b'outside cache')
        self.route_cache.parent.mkdir(parents=True)
        self.route_cache.symlink_to(external)
        payload = {'views': views, 'routes': str(self.route_cache)}
        response = subprocess.CompletedProcess([], 0, json.dumps(payload).encode(), b'')
        with mock.patch.object(INSTALLER, 'run', return_value=response):
            with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
                INSTALLER.cache_artifacts(self.root, PHP, ())
        self.assertEqual(external.read_bytes(), b'outside cache')

    def test_php_syntax_failure_changes_no_sources(self):
        invalid = self.base[self.first].replace(b'line 3\n', b'compact page\n') + b'broken PHP !!!'
        self.write(self.first, invalid)
        with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_INVALID'):
            self.install()
        self.assert_sources(self.base | {self.first: invalid})

    def test_postcheck_failure_atomically_restores_sources_and_metadata(self):
        target = self.root / self.first
        target.chmod(0o750)
        real_lint = INSTALLER.lint_sources
        def reject_live(php, directory):
            real_lint(php, directory)
            if directory == self.root:
                raise RuntimeError('SIMULATED_POSTCHECK_FAILURE')
        with mock.patch.object(INSTALLER, 'lint_sources', side_effect=reject_live):
            with self.assertRaisesRegex(RuntimeError, 'SIMULATED_POSTCHECK_FAILURE'):
                self.install()
        self.assert_sources(self.base)
        self.assertEqual(target.stat().st_mode & 0o777, 0o750)
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'code_restored')

    def test_concurrent_change_before_install_is_preserved(self):
        concurrent = self.base[self.first] + b'// independent concurrent change\n'
        def race(root, php, pending):
            self.write(self.first, concurrent)
            return self.cache_paths(root, php, pending)
        with mock.patch.object(INSTALLER, 'cache_artifacts', side_effect=race):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_BEFORE_INSTALL'):
                self.install()
        self.assert_sources(self.base | {self.first: concurrent})

    def test_rollback_never_overwrites_a_concurrent_unowned_edit(self):
        real_replace = INSTALLER.atomic_replace
        independent = self.release[self.first] + b'// unowned live edit\n'
        def race(path, body, original):
            if path == self.root / self.second:
                self.write(self.first, independent)
                raise RuntimeError('SIMULATED_SECOND_FILE_FAILURE')
            return real_replace(path, body, original)
        with mock.patch.object(INSTALLER, 'atomic_replace', side_effect=race):
            with self.assertRaisesRegex(RuntimeError, 'SIMULATED_SECOND_FILE_FAILURE'):
                self.install()
        self.assert_sources(self.base | {self.first: independent})
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertEqual(receipt['status'], 'rollback_review_required')
        self.assertEqual(receipt['review'], [self.first])

    def test_symlink_rejected_before_writing(self):
        external = self.parent / 'external.php'
        external.write_bytes(b'external data')
        target = self.root / self.second
        target.unlink()
        target.symlink_to(external)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.install()
        self.assertEqual(external.read_bytes(), b'external data')
        self.assertEqual((self.root / self.first).read_bytes(), self.base[self.first])

    def test_manifest_requires_every_modified_runtime_path(self):
        self.git('reset', '--hard', '--quiet', self.revision)
        self.write(self.paths[-1], self.base[self.paths[-1]])
        self.git('add', self.paths[-1])
        self.git('commit', '--quiet', '-m', 'Unexpected release missing one source change')
        self.revision = self.git('rev-parse', 'HEAD').decode().strip()
        self.git('reset', '--hard', '--quiet', self.base_revision)
        with self.assertRaisesRegex(RuntimeError, 'RELEASE_SOURCE_MANIFEST_MISMATCH'):
            self.install()
        self.assert_sources(self.base)

    def test_atomic_guard_rejects_changed_inode_even_with_identical_bytes(self):
        target = self.root / self.first
        original = INSTALLER.read_source(target)
        temporary = target.with_name('concurrent.php')
        temporary.write_bytes(original[0])
        os.replace(temporary, target)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_BEFORE_REPLACE'):
            INSTALLER.atomic_replace(target, self.release[self.first], original)
        self.assertEqual(target.read_bytes(), original[0])
        self.assertEqual(list(target.parent.glob('.*.shift-*')), [])


if __name__ == '__main__':
    unittest.main()
