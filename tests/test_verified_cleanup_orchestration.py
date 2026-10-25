"""No server access: verify cleanup ordering and refusal before an invalid seal."""
from contextlib import contextmanager, ExitStack
import importlib.util
from pathlib import Path
import tempfile
import types
import unittest
from unittest import mock

SPEC = importlib.util.spec_from_file_location('cleanup_orchestration_test',
    Path(__file__).resolve().parents[1] / 'deployment/clean_verified_server_files_root.py')
worker = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(worker)


class OrchestrationTests(unittest.TestCase):
    def run_fixture(self, invalid_seal=False, pending_release=False, unknown_upgrade_state=False):
        temporary = tempfile.TemporaryDirectory(prefix='cleanup-order-fixture-')
        self.addCleanup(temporary.cleanup)
        base = Path(temporary.name)
        events = []
        class PointStop(RuntimeError):
            pass
        class BackupError(RuntimeError):
            pass
        class UpgradeStop(RuntimeError):
            pass
        verified = types.SimpleNamespace(latest_path=base / 'kept-backup',
                                         progress=types.SimpleNamespace(stage='verification'))
        verified.check = lambda: events.append('seal_rechecked')
        def prune_old(apply=False):
            self.assertTrue(apply)
            events.append('old_backup')
            return {'status': 'already_pruned'}
        verified.prune_old = prune_old
        @contextmanager
        def locked():
            events.append('seal_verified')
            if invalid_seal:
                raise RuntimeError('CORRUPT_SEAL_FIXTURE')
            yield verified
        def points_clean(current, split, apply=False):
            self.assertIs(current, verified)
            self.assertTrue(apply)
            events.append('point_backups')
            return {'point_backup_status': 'applied'}
        def upgrades_clean(current, points, split, apply=False, guard=None):
            self.assertIs(current, verified)
            self.assertTrue(apply)
            self.assertTrue(callable(guard))
            guard()
            if unknown_upgrade_state:
                events.append('upgrades_require_review')
                raise UpgradeStop('UNKNOWN_STATE_FIXTURE')
            events.append('closed_upgrades')
            return {'status': 'applied'}
        def caches(audit):
            self.assertEqual(audit.parent, base)
            events.append('caches')
            return {}
        def retain(paths, publisher, apply):
            if pending_release:
                raise BlockingIOError('CANDIDATE_PENDING')
            return {'keep_versions': ['latest', 'previous']}
        retention = types.SimpleNamespace(PUBLISHER_SHA256='a' * 64,
            load_publisher=lambda stack: object(), Paths=lambda: object(),
            run=retain,
            lock_file=lambda path, stack: object(),
            Directory=lambda path, stack: types.SimpleNamespace(fd=-1, check=lambda: events.append('publication_checked')),
            check_locks=lambda held: events.append('locks_checked'))
        modules = {'cleanup_retention': retention,
                   'cleanup_full_backup': types.SimpleNamespace(locked_backup=locked),
                   'cleanup_point_backups': types.SimpleNamespace(clean=points_clean, Stop=PointStop),
                   'cleanup_closed_upgrades': types.SimpleNamespace(clean=upgrades_clean, Stop=UpgradeStop),
                   'create_all_apps_backup_root': object(),
                   'cleanup_home_dependencies': types.SimpleNamespace(core=types.SimpleNamespace(BackupError=BackupError))}
        with ExitStack() as patches:
            for target, value in [('BASE', base), ('clean_caches', caches), ('sizes', lambda: {}),
                                  ('root_breakdown', lambda: {}), ('home_breakdown', lambda: {}),
                                  ('upgrade_staging_inventory', lambda: [])]:
                patches.enter_context(mock.patch.object(worker, target, value))
            patches.enter_context(mock.patch.object(worker, 'module', side_effect=lambda name, *args: modules[name]))
            patches.enter_context(mock.patch.object(worker.socket, 'gethostname', return_value='srv1836524'))
            patches.enter_context(mock.patch.object(worker.os, 'geteuid', return_value=0))
            patches.enter_context(mock.patch.object(worker.os, 'umask', return_value=0o077))
            patches.enter_context(mock.patch.object(worker.os, 'chdir'))
            patches.enter_context(mock.patch.object(worker.fcntl, 'flock'))
            patches.enter_context(mock.patch.object(worker.sys, 'argv', ['fixture', '--apply']))
            if invalid_seal:
                with self.assertRaisesRegex(RuntimeError, 'CORRUPT_SEAL_FIXTURE'):
                    worker.main()
            else:
                self.assertEqual(worker.main(), 0)
                self.assertEqual(len(list(base.glob('verified-cleanup-*/cleanup-result.json'))), 1)
        return events

    def test_all_destructive_scopes_follow_seal_and_update_checks(self):
        events = self.run_fixture()
        self.assertLess(events.index('seal_verified'), events.index('old_backup'))
        self.assertLess(events.index('old_backup'), events.index('point_backups'))
        self.assertLess(events.index('point_backups'), events.index('closed_upgrades'))
        self.assertLess(events.index('closed_upgrades'), events.index('caches'))
        self.assertIn('locks_checked', events[:events.index('closed_upgrades')])
        self.assertIn('publication_checked', events[:events.index('closed_upgrades')])

    def test_invalid_seal_prevents_every_backup_and_cache_deletion(self):
        events = self.run_fixture(invalid_seal=True)
        for action in ('old_backup', 'point_backups', 'closed_upgrades', 'caches'):
            self.assertNotIn(action, events)

    def test_pending_program_release_defers_installers_and_checks_independent_backups(self):
        events = self.run_fixture(pending_release=True)
        self.assertIn('point_backups', events)
        self.assertIn('closed_upgrades', events)

    def test_unknown_upgrade_state_is_reported_without_deleting_upgrade_trees(self):
        events = self.run_fixture(unknown_upgrade_state=True)
        self.assertIn('upgrades_require_review', events)
        self.assertNotIn('closed_upgrades', events)
        self.assertIn('caches', events)


if __name__ == '__main__':
    unittest.main()
