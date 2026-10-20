import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest import mock

DEPLOYMENT = Path(__file__).resolve().parents[1] / 'deployment'
sys.path.insert(0, str(DEPLOYMENT))
import audit_remaining_backup_candidates_root as audit
import cleanup_unused_cpanel_copies_root as cleanup


class CleanupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.backup = self.base / 'full-backups' / 'verified-current'
        self.backup.mkdir(parents=True)
        (self.backup / 'home-current.tar.gz').write_bytes(b'preserved current backup')
        self.live = self.base / 'live'
        self.live.mkdir()
        (self.live / 'customer-data').write_bytes(b'preserved live data')
        self.directory = self.base / 'old-extract'
        self.directory.mkdir()
        (self.directory / 'old-data').write_bytes(b'old copy')
        (self.directory / 'outward-link').symlink_to(self.live, target_is_directory=True)
        self.archive1 = self.base / 'home' / 'cpmove-fasakha.tar.gz'
        self.archive1.parent.mkdir()
        self.archive1.write_bytes(b'old archive one')
        self.archive2 = self.base / 'root' / 'cpmove-fasakha.tar.gz'
        self.archive2.parent.mkdir()
        self.archive2.write_bytes(b'old archive two')
        self.paths = [str(self.directory), str(self.archive1), str(self.archive2)]
        self.manifest = {'layout': 'split-home', 'account': 'fasakha',
                         'started_utc': '2099-01-01T00:00:00.000000Z'}
        self.cutoff = cleanup.cutoff_from_manifest(self.manifest)
        self.patches = contextlib.ExitStack()
        self.patches.enter_context(mock.patch.object(audit, 'CANDIDATES', tuple(self.paths)))
        self.patches.enter_context(mock.patch.object(audit, 'BACKUP', str(self.backup)))
        self.verify = self.patches.enter_context(mock.patch.object(audit, 'verify_backup', return_value=self.manifest))
        self.dependencies = self.patches.enter_context(mock.patch.object(audit, 'dependency_check', return_value=[]))

    def tearDown(self):
        self.patches.close()
        self.temp.cleanup()

    def plan(self):
        return [cleanup.snapshot(path, self.cutoff) for path in self.paths]

    def apply(self, plan=None):
        with contextlib.redirect_stdout(io.StringIO()):
            cleanup.apply_plan(self.plan() if plan is None else plan, self.cutoff)

    def assert_preserved(self):
        self.assertEqual((self.live / 'customer-data').read_bytes(), b'preserved live data')
        self.assertEqual((self.backup / 'home-current.tar.gz').read_bytes(), b'preserved current backup')

    def test_only_exact_approved_copies_removed_and_outward_link_not_followed(self):
        self.apply()
        self.assertTrue(all(not os.path.lexists(path) for path in self.paths))
        self.assert_preserved()
        self.assertTrue(self.verify.called)
        self.assertGreaterEqual(self.dependencies.call_count, 4)
        reports = list(self.backup.glob('extra-cleanup-staging-*.json'))
        self.assertEqual(len(reports), 1)
        report = json.loads(reports[0].read_text())
        self.assertEqual(report['status'], 'complete')
        self.assertEqual(set(report['removed_paths']), set(self.paths))
        self.assertTrue(reports[0].with_name(reports[0].name + '.sha256').is_file())

    def test_unlisted_path_cannot_enter_plan(self):
        plan = self.plan()
        plan[0]['path'] = str(self.live)
        with self.assertRaises(audit.AuditError):
            self.apply(plan)
        self.assert_preserved()

    def test_corrupt_backup_prevents_any_removal(self):
        self.verify.side_effect = audit.AuditError('checksum failed')
        with self.assertRaises(audit.AuditError):
            self.apply()
        self.assertTrue(all(os.path.lexists(path) for path in self.paths))

    def test_active_reference_prevents_any_removal(self):
        self.dependencies.return_value = ['process-cwd:/proc/123']
        with self.assertRaises(audit.AuditError):
            self.apply()
        self.assertTrue(all(os.path.lexists(path) for path in self.paths))

    def test_new_candidate_data_after_backup_cutoff_is_retained(self):
        with self.assertRaises(audit.AuditError):
            cleanup.snapshot(self.directory, int(time.time() - 100) * 10 ** 9)
        self.assertTrue((self.directory / 'old-data').exists())

    def test_changed_child_between_plan_and_apply_is_retained(self):
        plan = self.plan()
        (self.directory / 'old-data').write_bytes(b'changed and should be retained')
        with self.assertRaises(audit.AuditError):
            self.apply(plan)
        self.assertEqual((self.directory / 'old-data').read_bytes(), b'changed and should be retained')

    def test_symlink_replacement_is_retained_and_live_data_safe(self):
        plan = self.plan()
        self.archive1.unlink()
        self.archive1.symlink_to(self.live / 'customer-data')
        with self.assertRaises(audit.AuditError):
            self.apply(plan)
        self.assertTrue(self.archive1.is_symlink())
        self.assert_preserved()

    def test_process_opened_staged_copy_aborts_and_returns_it(self):
        def refs(paths):
            if any('/extra-cleanup-staging-' in path for path in paths):
                return ['process-fd:/proc/456']
            return []
        self.dependencies.side_effect = refs
        with self.assertRaises(audit.AuditError):
            self.apply()
        self.assertTrue(all(os.path.lexists(path) for path in self.paths))
        self.assert_preserved()

    def test_child_added_after_rename_aborts_and_returns_directory(self):
        original_rename = os.rename
        def rename(source, destination):
            result = original_rename(source, destination)
            if source == str(self.directory):
                (Path(destination) / 'new-child').write_bytes(b'keep this new data')
            return result
        with mock.patch.object(cleanup.os, 'rename', side_effect=rename):
            with self.assertRaises(audit.AuditError):
                self.apply()
        self.assertTrue((self.directory / 'new-child').is_file())
        self.assertTrue(self.archive1.is_file())
        self.assert_preserved()

    def test_candidate_archive_structure_must_match_cpanel(self):
        with self.assertRaises(audit.AuditError):
            cleanup.require_cpanel_copy(self.archive1,
                                        {'type': 'file', 'archive_root': 'unrelated-private-data'})
        cleanup.require_cpanel_copy(self.archive1,
                                    {'type': 'file', 'archive_root': 'cpmove-fasakha'})

    def test_extracted_directory_needs_cpanel_markers(self):
        with self.assertRaises(audit.AuditError):
            cleanup.require_cpanel_copy(self.directory, {'type': 'directory'})
        (self.directory / 'mysql').mkdir()
        (self.directory / 'homedir').mkdir()
        cleanup.require_cpanel_copy(self.directory, {'type': 'directory'})

    def test_no_apply_flag_does_not_delete(self):
        records = {'type': 'file', 'archive_root': 'cpmove-fasakha'}
        with mock.patch.object(cleanup.os, 'geteuid', return_value=0), \
             mock.patch.object(cleanup.socket, 'gethostname', return_value='srv1836524'), \
             mock.patch.object(cleanup.os, 'chdir'), \
             mock.patch('builtins.open', mock.mock_open()), \
             mock.patch.object(cleanup.fcntl, 'flock'), \
             mock.patch.object(audit, 'candidates', return_value=self.paths), \
             mock.patch.object(audit, 'inspect_candidate', return_value=records), \
             mock.patch.object(cleanup, 'require_cpanel_copy'), \
             contextlib.redirect_stdout(io.StringIO()):
            cleanup.main(['--check'])
        self.assertTrue(all(os.path.lexists(path) for path in self.paths))
        self.assert_preserved()


if __name__ == '__main__':
    unittest.main()
