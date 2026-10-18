#!/usr/bin/env python3
"""Exercise real scoped Git hunks using fixtures, never a production database."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('basic_recipes_installer', ROOT / 'deployment/apply_branch_basic_recipes.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class Fixture:
    def __init__(self, folder):
        self.root = Path(folder) / 'app'
        self.root.mkdir()
        self.calls = []
        self.checks = []
        self.state = {'present': False, 'recorded': False}
        self.frozen = False
        self.fail_migration = False
        self.fail_freeze = False
        self.fail_post_lint = False
        self.fail_route_clear = False
        self.schema_bad = False
        self.source_bad = False
        self.source_changes = False
        self.source_changes_during_capture = False
        self.source_hash = 'a' * 64
        self.before = {}
        self.after = {}
        self.real_run = installer.run
        self.git('init', '-q')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        for index, relative in enumerate(installer.MODIFIED_PATHS):
            before = ('prefix-' + str(index) + '\n' + ''.join('context-' + str(i) + '\n' for i in range(10))
                      + 'old-behavior\n' + ''.join('tail-' + str(i) + '\n' for i in range(10))).encode()
            self.before[relative] = before
            self.after[relative] = before.replace(b'old-behavior', b'new-reviewed-behavior')
            self.write(relative, before)
        self.git('add', '.')
        self.git('commit', '-qm', 'base')
        self.base = self.git('rev-parse', 'HEAD').strip()
        for relative, after in self.after.items():
            self.write(relative, after)
        for relative in installer.NEW_PATHS:
            self.after[relative] = b'new reviewed service command or migration\n'
            self.write(relative, self.after[relative])
        self.git('add', '.')
        self.git('commit', '-qm', 'reviewed release')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        for relative in installer.MODIFIED_PATHS:
            (self.root / relative).chmod(0o640)

    def git(self, *args):
        result = subprocess.run(['git', '-C', str(self.root), *args], check=True,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        return result.stdout.decode()

    def write(self, relative, body):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)

    def run(self, command, cwd=None):
        if command[:2] == ['fixture-php', 'artisan']:
            self.calls.append(tuple(command))
            # Freeze precedes callers; route-cache clear follows their publication.
            for relative, before in self.before.items():
                expected = self.after[relative] if command[2] == 'route:clear' else before
                assert (self.root / relative).read_bytes().startswith(expected)
            for relative in installer.NEW_PATHS:
                assert (self.root / relative).read_bytes() == self.after[relative]
            if command[2] == 'migrate':
                if not self.fail_migration:
                    self.state = {'present': True, 'recorded': True}
                failed = self.fail_migration
            elif command[2] == 'route:clear':
                assert self.frozen
                failed = self.fail_route_clear
            else:
                assert command[2:] == ['inventory:freeze-basic-recipes', '--expected-content-hash=' + 'a' * 64]
                assert all(self.state.values())
                if self.source_changes_during_capture:
                    self.source_hash = 'b' * 64
                failed = self.fail_freeze or command[3] != '--expected-content-hash=' + self.source_hash
                if not failed:
                    self.frozen = True
            return subprocess.CompletedProcess(command, int(failed),
                                               stdout=b'PRIVATE_DATABASE_DETAIL', stderr=b'')
        return self.real_run(command, cwd)

    def schema(self, root, php):
        if self.schema_bad:
            raise RuntimeError('BASIC_RECIPES_SCHEMA_NEEDS_REVIEW')
        return dict(self.state)

    def freeze(self, root, php, source):
        self.checks.append(source)
        assert (source / installer.NEW_PATHS[0]).is_file()
        if self.source_bad:
            raise RuntimeError('BASIC_RECIPES_SOURCE_NEEDS_REVIEW')
        result = {'success': True, 'check': True, 'frozen': self.frozen,
                  'source': {'branch': 'f:94', 'name': 'المحلة', 'frozen_at': None,
                             'recipe_count': 4}, 'content_hash': self.source_hash}
        if self.source_changes:
            self.source_hash = 'b' * 64
        return result

    def lint(self, php, stage, root, cache):
        for relative in installer.SOURCE_PATHS:
            assert (stage / relative).is_file()
        if stage == root and self.fail_post_lint:
            raise RuntimeError('PHP_PARSE_INVALID')

    def apply(self, check=False):
        with (mock.patch.object(installer, 'BASE_REVISION', self.base),
              mock.patch.object(installer, 'run', self.run),
              mock.patch.object(installer, 'schema_state', self.schema),
              mock.patch.object(installer, 'freeze_state', self.freeze),
              mock.patch.object(installer, 'lint_sources', self.lint),
              contextlib.redirect_stdout(io.StringIO())):
            return installer.install(self.root, self.release, 'fixture-php', check_only=check)

    def originals(self, case):
        for relative, before in self.before.items():
            case.assertEqual((self.root / relative).read_bytes(), before)
        for relative in installer.NEW_PATHS:
            case.assertFalse((self.root / relative).exists())

    def receipt(self):
        return json.loads(next((self.root.parent / 'branch-basic-recipes-backups').glob('*/receipt.json')).read_text())


class BasicRecipesInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = Fixture(self.temporary.name)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def test_check_does_not_publish_migrate_or_freeze(self):
        self.assertIsNone(self.fixture.apply(check=True))
        self.fixture.originals(self)
        self.assertEqual(self.fixture.calls, [])
        self.assertFalse(self.fixture.frozen)
        self.assertEqual(len(self.fixture.checks), 1)
        private = self.fixture.root.parent / 'branch-basic-recipes-backups'
        self.assertEqual([path.name for path in private.iterdir()], ['install.lock'])

    def test_install_preserves_unrelated_live_edits_and_modes(self):
        relative = installer.MODIFIED_PATHS[0]
        suffix = b'\nunrelated live production correction\n'
        self.fixture.write(relative, self.fixture.before[relative] + suffix)
        (self.fixture.root / relative).chmod(0o640)
        backup = self.fixture.apply()
        self.assertTrue(backup.is_dir())
        for path, after in self.fixture.after.items():
            self.assertEqual((self.fixture.root / path).read_bytes(), after + (suffix if path == relative else b''))
            self.assertEqual(stat.S_IMODE((self.fixture.root / path).stat().st_mode),
                             0o644 if path in installer.NEW_PATHS else 0o640)
        self.assertEqual(self.fixture.calls, [
            ('fixture-php', 'artisan', 'migrate', '--force', '--path=' + installer.MIGRATION),
            ('fixture-php', 'artisan', 'inventory:freeze-basic-recipes', '--expected-content-hash=' + 'a' * 64),
            ('fixture-php', 'artisan', 'route:clear')])
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'installed')
        self.assertFalse(receipt['database_rollback_attempted'])
        self.assertTrue(receipt['freeze_after']['frozen'])
        self.assertEqual((backup / relative).read_bytes(), self.fixture.before[relative] + suffix)

    def test_complete_rerun_does_not_remigrate_or_refreeze(self):
        self.fixture.apply()
        contents = {relative: (self.fixture.root / relative).read_bytes() for relative in installer.SOURCE_PATHS}
        self.assertIsNone(self.fixture.apply())
        self.assertEqual(len(self.fixture.calls), 3)
        self.assertEqual(contents, {relative: (self.fixture.root / relative).read_bytes() for relative in installer.SOURCE_PATHS})

    def test_recorded_empty_snapshot_table_is_frozen_without_migration(self):
        self.fixture.state = {'present': True, 'recorded': True}
        self.fixture.apply()
        self.assertEqual(self.fixture.calls, [('fixture-php', 'artisan', 'inventory:freeze-basic-recipes', '--expected-content-hash=' + 'a' * 64),
                                              ('fixture-php', 'artisan', 'route:clear')])
        self.assertTrue(self.fixture.frozen)

    def test_existing_frozen_snapshot_is_never_overwritten(self):
        self.fixture.state = {'present': True, 'recorded': True}
        self.fixture.frozen = True
        self.fixture.apply()
        self.assertEqual(self.fixture.calls, [('fixture-php', 'artisan', 'route:clear')])
        self.assertFalse(self.fixture.receipt()['freeze_attempted'])

    def test_existing_unrecorded_snapshot_only_records_scoped_migration(self):
        self.fixture.state = {'present': True, 'recorded': False}
        self.fixture.frozen = True
        self.fixture.apply()
        self.assertEqual(self.fixture.calls, [
            ('fixture-php', 'artisan', 'migrate', '--force', '--path=' + installer.MIGRATION),
            ('fixture-php', 'artisan', 'route:clear')])

    def test_hunk_conflict_stops_before_any_live_write(self):
        relative = installer.MODIFIED_PATHS[-1]
        conflicting = self.fixture.before[relative].replace(b'old-behavior', b'unreviewed custom behavior')
        self.fixture.write(relative, conflicting)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / relative).read_bytes(), conflicting)
        self.assertFalse((self.fixture.root / installer.NEW_PATHS[0]).exists())
        self.assertEqual(self.fixture.calls, [])

    def test_new_file_collision_is_never_replaced(self):
        relative = installer.NEW_PATHS[0]
        self.fixture.write(relative, b'local custom service\n')
        with self.assertRaisesRegex(RuntimeError, 'NEW_SOURCE_COLLISION_NEEDS_REVIEW'):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / relative).read_bytes(), b'local custom service\n')
        self.assertEqual(self.fixture.calls, [])

    def test_exact_existing_new_file_is_not_rolled_back(self):
        relative = installer.NEW_PATHS[0]
        self.fixture.write(relative, self.fixture.after[relative])
        self.fixture.fail_migration = True
        with self.assertRaisesRegex(RuntimeError, 'SCOPED_BASIC_RECIPES_MIGRATION_FAILED'):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / relative).read_bytes(), self.fixture.after[relative])
        self.assertFalse((self.fixture.root / installer.NEW_PATHS[1]).exists())

    def test_schema_failure_leaves_all_sources_untouched(self):
        self.fixture.schema_bad = True
        with self.assertRaisesRegex(RuntimeError, 'SCHEMA_NEEDS_REVIEW'):
            self.fixture.apply()
        self.fixture.originals(self)
        self.assertEqual(self.fixture.calls, [])

    def test_invalid_mahalla_source_leaves_sources_and_database_untouched(self):
        self.fixture.source_bad = True
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_NEEDS_REVIEW'):
            self.fixture.apply()
        self.fixture.originals(self)
        self.assertEqual(self.fixture.calls, [])

    def test_source_change_during_preflight_stops_before_publication(self):
        self.fixture.source_changes = True
        with self.assertRaisesRegex(RuntimeError, 'RECIPES_CHANGED_DURING_PREFLIGHT'):
            self.fixture.apply()
        self.fixture.originals(self)
        self.assertEqual(self.fixture.calls, [])

    def test_source_change_inside_capture_stops_before_snapshot_and_callers(self):
        self.fixture.source_changes_during_capture = True
        with self.assertRaisesRegex(RuntimeError, 'BASIC_RECIPES_FREEZE_FAILED'):
            self.fixture.apply()
        self.fixture.originals(self)
        self.assertFalse(self.fixture.frozen)
        self.assertTrue(all(self.fixture.state.values()))
        self.assertEqual(len(self.fixture.calls), 2)
        self.assertEqual(self.fixture.receipt()['status'], 'code_restored')

    def test_failed_migration_restores_code_without_database_rollback(self):
        self.fixture.fail_migration = True
        with self.assertRaisesRegex(RuntimeError, 'SCOPED_BASIC_RECIPES_MIGRATION_FAILED'):
            self.fixture.apply()
        self.fixture.originals(self)
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertTrue(receipt['migration_attempted'])
        self.assertFalse(receipt['freeze_attempted'])
        self.assertTrue(receipt['database_changes_may_remain'])
        self.assertFalse(receipt['database_rollback_attempted'])
        self.assertEqual(len(self.fixture.calls), 1)

    def test_failed_snapshot_freeze_restores_code_and_keeps_additive_schema(self):
        self.fixture.fail_freeze = True
        with self.assertRaisesRegex(RuntimeError, 'BASIC_RECIPES_FREEZE_FAILED'):
            self.fixture.apply()
        self.fixture.originals(self)
        receipt = self.fixture.receipt()
        self.assertTrue(all(self.fixture.state.values()))
        self.assertTrue(receipt['freeze_attempted'])
        self.assertTrue(receipt['database_changes_may_remain'])
        self.assertFalse(receipt['database_rollback_attempted'])

    def test_post_freeze_failure_restores_code_and_preserves_immutable_snapshot(self):
        self.fixture.fail_post_lint = True
        with self.assertRaisesRegex(RuntimeError, 'PHP_PARSE_INVALID'):
            self.fixture.apply()
        self.fixture.originals(self)
        self.assertTrue(self.fixture.frozen)
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertTrue(receipt['database_changes_may_remain'])
        self.assertEqual(len(self.fixture.calls), 2)

    def test_route_cache_failure_restores_code_and_keeps_frozen_snapshot(self):
        self.fixture.fail_route_clear = True
        with self.assertRaisesRegex(RuntimeError, 'BASIC_RECIPES_ROUTE_CACHE_CLEAR_FAILED'):
            self.fixture.apply()
        self.fixture.originals(self)
        self.assertTrue(self.fixture.frozen)
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertTrue(receipt['route_cache_clear_attempted'])
        self.assertFalse(receipt['route_cache_cleared'])
        self.assertEqual(len(self.fixture.calls), 3)

    def test_retry_after_post_freeze_failure_reuses_original_snapshot(self):
        self.fixture.fail_post_lint = True
        with self.assertRaisesRegex(RuntimeError, 'PHP_PARSE_INVALID'):
            self.fixture.apply()
        self.fixture.fail_post_lint = False
        self.fixture.apply()
        self.assertEqual(len(self.fixture.calls), 3)
        self.assertTrue(self.fixture.frozen)

    def test_rollback_refuses_to_overwrite_concurrent_live_edit(self):
        relative = installer.MODIFIED_PATHS[0]
        before, info = installer.read_source(self.fixture.root / relative)
        after = self.fixture.after[relative]
        self.fixture.write(relative, after)
        patch = self.fixture.root.parent / 'rollback.diff'
        with mock.patch.object(installer, 'BASE_REVISION', self.fixture.base):
            patch.write_bytes(installer.release_patch(self.fixture.root, self.fixture.release, relative))
        self.fixture.write(relative, after + b'concurrent edit\n')
        review = installer.rollback(self.fixture.root, [(relative, patch, after)], {relative: (before, info)})
        self.assertEqual(review, [relative])
        self.assertEqual((self.fixture.root / relative).read_bytes(), after + b'concurrent edit\n')

    def test_symlink_sources_are_rejected(self):
        relative = installer.MODIFIED_PATHS[0]
        target = self.fixture.root / relative
        source = target.with_name('outside.php')
        target.rename(source)
        target.symlink_to(source)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.fixture.apply(check=True)
        self.assertEqual(self.fixture.calls, [])

    def test_manifest_contains_only_one_new_migration(self):
        self.assertEqual([relative for relative in installer.SOURCE_PATHS if relative.startswith('database/')],
                         [installer.MIGRATION])
        self.assertFalse(any(relative == '.env' or relative.startswith('config/') for relative in installer.SOURCE_PATHS))
        wrapper = (ROOT / 'deployment/apply_branch_basic_recipes_root.sh').read_text()
        self.assertIn(installer.BASE_REVISION, wrapper)
        self.assertIn('runuser -u fasakha -- python3', wrapper)
        self.assertIn('--revision "$basic_revision"', wrapper)
        self.assertIn('basic_source=deployment/apply_branch_basic_recipes.py', wrapper)

    def test_schema_reply_requires_exact_boolean_fields(self):
        for reply in (b'PRIVATE_SQL_EXCEPTION', b'{}', b'{"present":1,"recorded":false}'):
            with self.subTest(reply=reply), mock.patch.object(installer, 'run', return_value=
                    subprocess.CompletedProcess([], 0, stdout=reply, stderr=b'PRIVATE_DETAIL')):
                with self.assertRaisesRegex(RuntimeError, '^BASIC_RECIPES_SCHEMA_NEEDS_REVIEW$'):
                    installer.schema_state(self.fixture.root, 'fixture-php')

    def test_recorded_migration_requires_table(self):
        with mock.patch.object(installer, 'run', return_value=subprocess.CompletedProcess(
                [], 0, stdout=b'{"present":false,"recorded":true}', stderr=b'')):
            with self.assertRaisesRegex(RuntimeError, 'RECORDED_MIGRATION_INCOMPLETE'):
                installer.schema_state(self.fixture.root, 'fixture-php')

    def test_readonly_freeze_reply_requires_mahalla_and_nonempty_recipes(self):
        good = {'success': True, 'check': True, 'frozen': False, 'content_hash': 'a' * 64,
                'source': {'branch': 'f:94', 'recipe_count': 2}}
        replies = [b'PRIVATE_DETAIL', b'{}']
        for field, value in [('check', False), ('frozen', 1), ('success', False),
                             ('content_hash', ''), ('content_hash', 1), ('content_hash', 'A' * 64)]:
            replies.append(json.dumps(good | {field: value}).encode())
        replies.append(json.dumps(good | {'source': {'branch': 'f:93', 'recipe_count': 2}}).encode())
        replies.append(json.dumps(good | {'source': {'branch': 'f:94', 'recipe_count': 0}}).encode())
        for reply in replies:
            with self.subTest(reply=reply), mock.patch.object(installer, 'run', return_value=
                    subprocess.CompletedProcess([], 0, stdout=reply, stderr=b'PRIVATE_DETAIL')):
                with self.assertRaisesRegex(RuntimeError, '^BASIC_RECIPES_SOURCE_NEEDS_REVIEW$'):
                    installer.freeze_state(self.fixture.root, 'fixture-php', self.fixture.root)
        with mock.patch.object(installer, 'run', return_value=
                subprocess.CompletedProcess([], 0, stdout=json.dumps(good).encode(), stderr=b'')):
            self.assertEqual(installer.freeze_state(self.fixture.root, 'fixture-php', self.fixture.root), good)


if __name__ == '__main__':
    unittest.main()
