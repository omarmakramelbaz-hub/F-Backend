#!/usr/bin/env python3
"""Exercise real Git patches in disposable repositories; PHP calls are stubbed."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location(
    'phone_delivery_unified_installer',
    Path(__file__).parents[1] / 'deployment/apply_phone_delivery_unified.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class UnifiedInstallerTest(unittest.TestCase):
    @contextlib.contextmanager
    def fixture(self, php_hook=None):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'app'
            root.mkdir()
            def git(*arguments):
                result = subprocess.run(['git', '-C', str(root), *arguments],
                                        check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                return result.stdout.decode().strip()
            git('init', '--quiet')
            git('config', 'user.email', 'fixture@example.invalid')
            git('config', 'user.name', 'Installer Fixture')
            all_paths = tuple(dict.fromkeys((*INSTALLER.CONSENT_MANIFEST, *INSTALLER.FIRST_PRINT_MANIFEST)))
            for relative in all_paths:
                if INSTALLER.FIRST_PRINT_MANIFEST.get(relative) == 'A':
                    continue
                target = root / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                body = b''.join(b'// stable fixture line %02d\n' % line for line in range(30))
                body = body.replace(b'// stable fixture line 07', b'// pre-first-print behavior')
                body = body.replace(b'// stable fixture line 15', b'// pre-consent behavior')
                target.write_bytes(body)
            (root / 'artisan').write_text('<?php // fixture\n')
            git('add', '.')
            git('commit', '--quiet', '-m', 'Before first-print policy')
            first_print_base = git('rev-parse', 'HEAD')
            for relative, status in INSTALLER.FIRST_PRINT_MANIFEST.items():
                target = root / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                if status == 'A':
                    target.write_bytes(b'// first-print receipt helper\n')
                else:
                    target.write_bytes(target.read_bytes().replace(b'pre-first-print behavior', b'first-print behavior'))
            git('add', '.')
            git('commit', '--quiet', '-m', 'Pre-consent baseline')
            consent_base = git('rev-parse', 'HEAD')
            original_bodies = {relative: (root / relative).read_bytes() for relative in all_paths}
            before = dict(original_bodies)
            for relative in INSTALLER.CONSENT_MANIFEST:
                target = root / relative
                body = target.read_bytes().replace(b'pre-consent behavior', b'consent behavior')
                target.write_bytes(body)
                before[relative] = body
            git('add', '.')
            git('commit', '--quiet', '-m', 'Consent prerequisite')
            base = git('rev-parse', 'HEAD')
            after = dict(before)
            for relative in INSTALLER.SOURCE_MANIFEST:
                target = root / relative
                body = target.read_bytes().replace(b'consent behavior', b'consent unified behavior')
                target.write_bytes(body)
                after[relative] = body
            git('add', '.')
            git('commit', '--quiet', '-m', 'Unified source release')
            revision = git('rev-parse', 'HEAD')
            # Live deployment remains on the prerequisite, with future objects available.
            git('checkout', '--quiet', base)
            for relative in INSTALLER.CONSENT_MANIFEST:
                (root / relative).chmod(0o644)
            original_run = INSTALLER.run
            php_calls = []
            def runner(command, cwd=None):
                if command[0] != 'fixture-php':
                    return original_run(command, cwd=cwd)
                php_calls.append((command, cwd))
                if php_hook:
                    result = php_hook(command, cwd, root)
                    if result is not None:
                        return result
                return subprocess.CompletedProcess(command, 0, b'', b'')
            with patch.object(INSTALLER, 'BASE_REVISION', base), \
                 patch.object(INSTALLER, 'CONSENT_BASE_REVISION', consent_base), \
                 patch.object(INSTALLER, 'FIRST_PRINT_BASE_REVISION', first_print_base), \
                 patch.object(INSTALLER, 'run', runner), \
                 contextlib.redirect_stdout(io.StringIO()):
                yield root, revision, before, after, original_bodies, php_calls

    def receipts(self, root):
        return list((root.parent / 'phone-delivery-unified-backups').glob('*/receipt.json'))

    def test_preserves_unrelated_edit_and_rerun_is_idempotent(self):
        with self.fixture() as (root, revision, before, after, _, php_calls):
            relative = INSTALLER.SOURCE_MANIFEST[0]
            customize = lambda body: body.replace(b'stable fixture line 02', b'local live customization')
            (root / relative).write_bytes(customize(before[relative]))
            backup = INSTALLER.install(root, revision, 'fixture-php')
            for path in INSTALLER.SOURCE_MANIFEST:
                expected = customize(after[path]) if path == relative else after[path]
                self.assertEqual((root / path).read_bytes(), expected)
            self.assertEqual((backup / relative).read_bytes(), customize(before[relative]))
            receipt = json.loads((backup / 'receipt.json').read_text())
            self.assertEqual(receipt['status'], 'installed')
            entry = next(item for item in receipt['files'] if item['path'] == relative)
            self.assertEqual(entry['after'], hashlib.sha256(customize(after[relative])).hexdigest())
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php'))
            self.assertEqual(len(self.receipts(root)), 1)
            self.assertEqual(len([command for command, _ in php_calls if 'view:clear' in command]), 1)

    def test_last_file_conflict_does_not_publish_any_file(self):
        with self.fixture() as (root, revision, before, _, _, _):
            relative = INSTALLER.SOURCE_MANIFEST[-1]
            live = before[relative].replace(b'consent behavior', b'custom conflicting behavior')
            (root / relative).write_bytes(live)
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                INSTALLER.install(root, revision, 'fixture-php')
            for path in INSTALLER.SOURCE_MANIFEST:
                self.assertEqual((root / path).read_bytes(), live if path == relative else before[path])
            self.assertEqual(self.receipts(root), [])

    def test_missing_backend_consent_is_staged_with_source_group(self):
        with self.fixture() as (root, revision, before, _, original_bodies, _):
            relative = INSTALLER.CONSENT_MANIFEST[-1]
            (root / relative).write_bytes(original_bodies[relative])
            backup = INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / relative).read_bytes(), before[relative])
            receipt = json.loads((backup / 'receipt.json').read_text())
            self.assertEqual(len(receipt['files']), 6)
            self.assertEqual((backup / relative).read_bytes(), original_bodies[relative])

    def test_entire_pre_consent_deployment_updates_all_eight_and_replays(self):
        with self.fixture() as (root, revision, _, after, original_bodies, _):
            for relative, body in original_bodies.items():
                (root / relative).write_bytes(body)
            backup = INSTALLER.install(root, revision, 'fixture-php')
            receipt = json.loads((backup / 'receipt.json').read_text())
            self.assertEqual(len(receipt['files']), 8)
            for relative in INSTALLER.CONSENT_MANIFEST:
                self.assertEqual((root / relative).read_bytes(), after[relative])
            INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual(len(self.receipts(root)), 1)

    def test_missing_first_print_policy_fails_without_consent_or_screen_mutations(self):
        with self.fixture() as (root, revision, _, _, original_bodies, _):
            for relative, body in original_bodies.items():
                (root / relative).write_bytes(body)
            relative = 'app/Services/Dashboard/TakeawayAccess.php'
            live = (root / relative).read_bytes().replace(b'first-print behavior', b'pre-first-print behavior')
            (root / relative).write_bytes(live)
            with self.assertRaisesRegex(RuntimeError, 'PHONE_DELIVERY_UNIFIED_FIRST_PRINT_PREREQUISITE_REQUIRED'):
                INSTALLER.install(root, revision, 'fixture-php')
            for path in INSTALLER.CONSENT_MANIFEST:
                self.assertEqual((root / path).read_bytes(), original_bodies[path])
            self.assertEqual(self.receipts(root), [])

    def test_check_only_leaves_all_live_bytes_unchanged(self):
        with self.fixture() as (root, revision, before, _, _, php_calls):
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php', check_only=True))
            for path in INSTALLER.SOURCE_MANIFEST:
                self.assertEqual((root / path).read_bytes(), before[path])
            self.assertEqual(self.receipts(root), [])
            self.assertFalse(any('view:clear' in command for command, _ in php_calls))

    def test_partial_file_group_install_completes_and_replays(self):
        with self.fixture() as (root, revision, before, after, _, _):
            first = INSTALLER.SOURCE_MANIFEST[0]
            (root / first).write_bytes(after[first])
            backup = INSTALLER.install(root, revision, 'fixture-php')
            receipt = json.loads((backup / 'receipt.json').read_text())
            self.assertEqual(len(receipt['files']), 4)
            self.assertNotIn(first, [item['path'] for item in receipt['files']])
            for relative in INSTALLER.SOURCE_MANIFEST:
                self.assertEqual((root / relative).read_bytes(), after[relative])
            INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual(len(self.receipts(root)), 1)

    def test_lint_failure_in_staged_new_source_prevents_publication(self):
        def php_hook(command, _cwd, root):
            if '-l' in command:
                target = Path(command[-1])
                if root not in target.parents and b'consent unified behavior' in target.read_bytes():
                    return subprocess.CompletedProcess(command, 1, b'', b'fixture syntax failure')
        with self.fixture(php_hook) as (root, revision, before, _, _, _):
            with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_INVALID'):
                INSTALLER.install(root, revision, 'fixture-php')
            for relative in INSTALLER.SOURCE_MANIFEST:
                self.assertEqual((root / relative).read_bytes(), before[relative])
            self.assertEqual(self.receipts(root), [])

    def test_view_cache_failure_rolls_back_exact_source_and_modes(self):
        def php_hook(command, _cwd, _root):
            if 'view:clear' in command:
                return subprocess.CompletedProcess(command, 1, b'', b'fixture cache failure')
        with self.fixture(php_hook) as (root, revision, before, _, _, _):
            relative = INSTALLER.SOURCE_MANIFEST[0]
            (root / relative).chmod(0o640)
            with self.assertRaisesRegex(RuntimeError, 'VIEW_CACHE_CLEAR_FAILED'):
                INSTALLER.install(root, revision, 'fixture-php')
            for path in INSTALLER.SOURCE_MANIFEST:
                self.assertEqual((root / path).read_bytes(), before[path])
            self.assertEqual((root / relative).stat().st_mode & 0o777, 0o640)
            receipt = json.loads(self.receipts(root)[0].read_text())
            self.assertEqual(receipt['status'], 'rolled_back')
            self.assertTrue(receipt['view_cache_clear_failed_after_rollback'])

    def test_concurrent_edit_is_preserved_and_flagged_for_review(self):
        relative = INSTALLER.SOURCE_MANIFEST[0]
        def php_hook(command, _cwd, root):
            if 'view:clear' in command:
                target = root / relative
                target.write_bytes(target.read_bytes() + b'// concurrent owner edit\n')
                return subprocess.CompletedProcess(command, 1, b'', b'fixture failure')
        with self.fixture(php_hook) as (root, revision, _, after, _, _):
            with self.assertRaisesRegex(RuntimeError, 'VIEW_CACHE_CLEAR_FAILED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / relative).read_bytes(), after[relative] + b'// concurrent owner edit\n')
            receipt = json.loads(self.receipts(root)[0].read_text())
            self.assertEqual(receipt['status'], 'rollback_review_required')
            self.assertEqual(receipt['review'], [relative])
            self.assertEqual(receipt['retained_source_group'], list(INSTALLER.SOURCE_MANIFEST))

    def test_symlink_target_is_rejected_before_mutation(self):
        with self.fixture() as (root, revision, before, _, _, _):
            target = root / INSTALLER.SOURCE_MANIFEST[-1]
            outside = root.parent / 'outside.php'
            outside.write_bytes(target.read_bytes())
            target.unlink()
            target.symlink_to(outside)
            with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
                INSTALLER.install(root, revision, 'fixture-php')
            for relative in INSTALLER.SOURCE_MANIFEST[:-1]:
                self.assertEqual((root / relative).read_bytes(), before[relative])
            self.assertEqual(self.receipts(root), [])

    @unittest.skipUnless(os.environ.get('PHONE_INSTALLER_TEST_PHP'),
                         'Set PHONE_INSTALLER_TEST_PHP for actual-source integration and real PHP lint')
    def test_real_sources_install_from_both_pinned_baselines(self):
        project = Path(__file__).resolve().parents[1]
        revision = subprocess.check_output(['git', '-C', str(project), 'rev-parse', 'HEAD']).decode().strip()
        php = os.environ['PHONE_INSTALLER_TEST_PHP']
        original_run = INSTALLER.run
        for baseline in (INSTALLER.BASE_REVISION, INSTALLER.CONSENT_BASE_REVISION):
            with self.subTest(baseline=baseline), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary) / 'app'
                subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
                # Explicit alternates include fetched pinned commits that are not
                # reachable from the source repository's shallow branch refs.
                (root / '.git/objects/info/alternates').write_text(str(project / '.git/objects') + '\n')
                shallow = project / '.git/shallow'
                if shallow.exists():
                    (root / '.git/shallow').write_bytes(shallow.read_bytes())
                subprocess.run(['git', '-C', str(root), 'checkout', '--quiet', baseline], check=True)
                relative = INSTALLER.SOURCE_MANIFEST[0]
                local = b'// Independent deployment customization retained.\n'
                target = root / relative
                target.write_bytes(local + target.read_bytes())
                def runner(command, cwd=None):
                    # Only the application cache command is stubbed: no database,
                    # framework boot, or production cache is touched by this test.
                    if command[:3] == [php, 'artisan', 'view:clear']:
                        return subprocess.CompletedProcess(command, 0, b'', b'')
                    return original_run(command, cwd=cwd)
                with patch.object(INSTALLER, 'run', runner), contextlib.redirect_stdout(io.StringIO()):
                    live_paths = tuple(dict.fromkeys((*INSTALLER.CONSENT_MANIFEST, *INSTALLER.FIRST_PRINT_MANIFEST)))
                    prior = {path: (root / path).read_bytes() for path in live_paths}
                    conflict = root / 'resources/lang/en/phone_orders.php'
                    conflict.write_bytes(conflict.read_bytes().replace(
                        b'return [', b'return [ // Custom translation header conflicts with release context'))
                    conflicting = {path: (root / path).read_bytes() for path in live_paths}
                    with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                        INSTALLER.install(root, revision, php)
                    for path, body in conflicting.items():
                        self.assertEqual((root / path).read_bytes(), body)
                    self.assertEqual(self.receipts(root), [])
                    conflict.write_bytes(prior['resources/lang/en/phone_orders.php'])
                    INSTALLER.install(root, revision, php, check_only=True)
                    for path, body in prior.items():
                        self.assertEqual((root / path).read_bytes(), body)
                    self.assertEqual(self.receipts(root), [])
                    backup = INSTALLER.install(root, revision, php)
                    self.assertIsNotNone(backup)
                    expected_count = 5 if baseline == INSTALLER.BASE_REVISION else 8
                    receipt = json.loads((backup / 'receipt.json').read_text())
                    self.assertEqual(len(receipt['files']), expected_count)
                    for path in INSTALLER.CONSENT_MANIFEST:
                        source_revision = revision if path in INSTALLER.SOURCE_MANIFEST else INSTALLER.BASE_REVISION
                        expected = subprocess.check_output(['git', '-C', str(root), 'show', source_revision + ':' + path])
                        if path == relative:
                            expected = local + expected
                        self.assertEqual((root / path).read_bytes(), expected)
                    INSTALLER.install(root, revision, php)
                    self.assertEqual(len(self.receipts(root)), 1)


if __name__ == '__main__':
    unittest.main()
