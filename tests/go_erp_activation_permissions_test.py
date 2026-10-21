#!/usr/bin/env python3
"""Read-only, isolated tests for the pinned activation metadata audit."""
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'deployment/diagnose_go_erp_activation_permissions_root.sh'
BODY = SCRIPT.read_text().split("<<'PY'\n", 1)[1].rsplit('\nPY', 1)[0]
subject = {'__name__': 'test_read_only_activation_metadata'}
exec(compile(BODY, str(SCRIPT), 'exec'), subject)


def functions(name):
    namespace = {'__name__': 'test_pinned_source_functions'}
    exec(compile((ROOT / 'deployment' / name).read_bytes(), name, 'exec'), namespace)
    return namespace


class ActivationPermissionsTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory(prefix='go-erp-permissions-test-')
        self.addCleanup(self.folder.cleanup)
        self.root = Path(self.folder.name) / 'project'
        (self.root / 'app').mkdir(parents=True)
        (self.root / 'storage/framework/views').mkdir(parents=True)
        (self.root / 'bootstrap/cache').mkdir(parents=True)
        self.backups = Path(self.folder.name) / 'backups'
        self.uid = os.geteuid()
        self.scanner = functions('diagnose_desktop_dashboard_current_source.py')
        self.source = functions('desktop_dashboard_server_preflight.py')
        self.first = self.root / 'app/First.php'
        self.second = self.root / 'app/Second.php'
        self.first.write_bytes(b'<?php /* PRIVATE_SOURCE_MARKER_DO_NOT_PRINT */\n')
        self.second.write_bytes(b'<?php echo 2;\n')
        for path in (self.first, self.second):
            path.chmod(0o644)
        self.env = self.root / '.env'
        self.env.write_bytes(b'PASSWORD=PRIVATE_ENV_MARKER_DO_NOT_READ\n')
        self.env.chmod(0o600)
        (self.root / 'bootstrap/cache/config.php').write_bytes(b'PRIVATE_CACHE_MARKER_DO_NOT_READ')
        self.manifest = {'sources': [
            {'path': 'app/First.php', 'before_blob': subject['blob'](self.first.read_bytes())},
            {'path': 'app/Second.php', 'before_blob': subject['blob'](self.second.read_bytes())},
            {'path': 'app/New.php', 'before_blob': None}],
            'before_source': self.fingerprint(), 'after_source': {
                'format': 1, 'files': 3, 'sha256': 'a' * 64, 'framework': '8.83.29'}}

    def fingerprint(self):
        snapshot = self.scanner['snapshot'](self.root, self.source, [], [], lambda: 'read-only-metadata')
        return {**self.source['digest'](snapshot['files']), 'framework': '8.83.29'}

    def audit(self):
        return subject['audit'](self.root, self.backups, self.uid, self.manifest, self.scanner, self.source)

    def record(self, name='server-fixture123', receipt=None, activated=None):
        self.backups.mkdir(mode=0o700, exist_ok=True)
        directory = self.backups / name
        directory.mkdir(mode=0o700)
        for filename, value in (('receipt.json', receipt), ('activated.json', activated)):
            if value is None:
                continue
            path = directory / filename
            path.write_text(json.dumps(value))
            path.chmod(0o600)
        return directory

    def identities(self):
        return {path.relative_to(self.root).as_posix(): self.scanner['identity'](path.lstat())
                for path in [self.root, *self.root.rglob('*')]}

    def test_safe_roster_outputs_no_safe_file_rows_and_preserves_metadata(self):
        before = self.identities()
        report = self.audit()
        self.assertEqual(report['publication_paths_checked'], 3)
        self.assertEqual(report['unsafe_publication_paths'], [])
        self.assertEqual(report['publication_ancestor_issues'], [])
        self.assertTrue(report['source_matches_reviewed_before'])
        self.assertEqual(report['prior_backups'], {'directory_exists': False, 'records': []})
        self.assertEqual(before, self.identities())
        encoded = json.dumps(report)
        for secret in ('PRIVATE_SOURCE_MARKER', 'PRIVATE_ENV_MARKER', 'PRIVATE_CACHE_MARKER'):
            self.assertNotIn(secret, encoded)

    def test_writable_source_reports_exact_metadata_and_before_blob_match(self):
        self.second.chmod(0o664)
        before = self.identities()
        row, = self.audit()['unsafe_publication_paths']
        self.assertEqual(row, {'path': 'app/Second.php', 'uid': self.uid,
            'gid': self.second.stat().st_gid, 'mode': '0664', 'links': 1,
            'before_blob_matches': True, 'issues': ['group_or_other_writable']})
        self.assertEqual(before, self.identities())

    def test_world_writable_source_is_reported_without_chmod(self):
        self.second.chmod(0o646)
        self.assertEqual(self.audit()['unsafe_publication_paths'][0]['mode'], '0646')
        self.assertEqual(stat.S_IMODE(self.second.stat().st_mode), 0o646)

    def test_unknown_raw_source_and_unexpected_new_file_are_reported(self):
        self.second.write_bytes(b'<?php /* PRIVATE_UNKNOWN_CONTENT */\n')
        new = self.root / 'app/New.php'
        new.write_bytes(b'<?php /* PRIVATE_NEW_CONTENT */\n')
        report = self.audit()
        self.assertFalse(report['source_matches_reviewed_before'])
        rows = {row['path']: row for row in report['unsafe_publication_paths']}
        self.assertEqual(set(rows), {'app/Second.php', 'app/New.php'})
        self.assertTrue(all(row['before_blob_matches'] is False for row in rows.values()))
        self.assertNotIn('PRIVATE_UNKNOWN_CONTENT', json.dumps(report))
        self.assertNotIn('PRIVATE_NEW_CONTENT', json.dumps(report))

    def test_missing_required_file_is_reported(self):
        self.second.unlink()
        row, = self.audit()['unsafe_publication_paths']
        self.assertEqual(row['path'], 'app/Second.php')
        self.assertEqual(row['issues'], ['required_file_missing', 'reviewed_before_blob_mismatch'])

    def test_wrong_ancestor_owner_is_metadata_only_and_not_a_recursive_repair(self):
        original = subject['named_metadata']
        def different(fd, relative, scanner):
            value = original(fd, relative, scanner)
            if relative == 'app':
                fields = list(value)
                fields[4] = self.uid + 1
                return os.stat_result(fields)
            return value
        before = self.identities()
        with patch.dict(subject, named_metadata=different):
            row, = self.audit()['publication_ancestor_issues']
        self.assertEqual(row['path'], 'app')
        self.assertEqual(row['uid'], self.uid + 1)
        self.assertEqual(row['issues'], ['owner_mismatch'])
        self.assertEqual(before, self.identities())

    def test_private_checkpoint_metadata_never_opens_env_or_cache(self):
        self.env.chmod(0o660)
        opened = []
        original = self.scanner['read_file']
        def record(fd, name, maximum):
            opened.append(name)
            return original(fd, name, maximum)
        with patch.dict(self.scanner, read_file=record):
            report = self.audit()
        row, = report['next_checkpoint_metadata_issues']
        self.assertEqual(row['path'], '.env')
        self.assertIs(row['contents_read'], False)
        self.assertNotIn('.env', opened)
        self.assertNotIn('config.php', opened)
        self.assertNotIn('PRIVATE_ENV_MARKER', json.dumps(report))

    def test_fifo_and_linked_sources_stop_without_reading_target(self):
        self.second.unlink()
        os.mkfifo(self.second)
        with self.assertRaises(self.scanner['DiagnosisError']):
            self.audit()
        self.second.unlink()
        self.second.symlink_to(self.env)
        with self.assertRaises(self.scanner['DiagnosisError']):
            self.audit()

    def test_hardlinked_publication_source_is_reported(self):
        os.link(self.first, Path(self.folder.name) / 'retained-hardlink')
        row, = self.audit()['unsafe_publication_paths']
        self.assertEqual(row['path'], 'app/First.php')
        self.assertIn('hardlinked_file', row['issues'])
        self.assertTrue(row['before_blob_matches'])

    def test_concurrent_source_change_discards_report(self):
        original = self.scanner['snapshot']
        calls = 0
        def changing(*args):
            nonlocal calls
            calls += 1
            if calls == 2:
                self.second.write_bytes(b'<?php echo 99;\n')
            return original(*args)
        with patch.dict(self.scanner, snapshot=changing):
            with self.assertRaises(subject['AuditError']):
                self.audit()

    def test_concurrent_private_metadata_change_discards_report(self):
        original = self.scanner['snapshot']
        calls = 0
        def changing(*args):
            nonlocal calls
            calls += 1
            if calls == 2:
                self.env.chmod(0o644)
            return original(*args)
        with patch.dict(self.scanner, snapshot=changing):
            with self.assertRaises(subject['AuditError']):
                self.audit()

    def test_prior_receipts_are_whitelisted_and_not_attributed_to_current_failure(self):
        receipt = {'manifest_blob': subject['PINS']['deployment/desktop_dashboard_server_manifest.json'],
            'before_source': self.manifest['before_source'],
            'database': {'password': 'PRIVATE_DATABASE_MARKER_DO_NOT_PRINT'},
            'files': {'.env': {'content': 'PRIVATE_ENV_BACKUP_MARKER'}}}
        directory = self.record(receipt=receipt, activated={'source': self.manifest['after_source']})
        (directory / 'code.tar').write_bytes(b'PRIVATE_ARCHIVE_DO_NOT_READ')
        (directory / 'database.sql').write_bytes(b'PRIVATE_DATABASE_DUMP_DO_NOT_READ')
        opened = []
        original = self.scanner['read_file']
        def record(fd, name, maximum):
            opened.append(name)
            return original(fd, name, maximum)
        with patch.dict(self.scanner, read_file=record):
            report = self.audit()
        row, = report['prior_backups']['records']
        self.assertTrue(row['receipt_manifest_matches'])
        self.assertTrue(row['receipt_before_source_matches'])
        self.assertTrue(row['activated_source_matches'])
        self.assertFalse(row['attributed_to_reported_failure'])
        self.assertTrue(row['code_archive_exists'])
        self.assertTrue(row['database_dump_exists'])
        self.assertNotIn('code.tar', opened)
        self.assertNotIn('database.sql', opened)
        self.assertNotIn('PRIVATE_', json.dumps(report))

    def test_malformed_prior_receipt_does_not_echo_content_or_block_metadata(self):
        directory = self.record()
        receipt = directory / 'receipt.json'
        receipt.write_bytes(b'PRIVATE_MALFORMED_RECEIPT')
        receipt.chmod(0o600)
        report = self.audit()
        row, = report['prior_backups']['records']
        self.assertFalse(row['receipt_json_valid'])
        self.assertNotIn('PRIVATE_MALFORMED_RECEIPT', json.dumps(report))

    def test_symlinked_backup_and_receipt_are_not_followed(self):
        self.backups.mkdir(mode=0o700)
        (self.backups / 'server-external123').symlink_to(self.root, target_is_directory=True)
        directory = self.record()
        (directory / 'receipt.json').symlink_to(self.env)
        rows = {row['path']: row for row in self.audit()['prior_backups']['records']}
        self.assertFalse(rows['server-external123']['safe_to_inspect'])
        self.assertFalse(rows['server-fixture123']['receipt_metadata_safe'])

    def test_unsafe_backup_parent_is_reported_without_opening_children(self):
        self.record(receipt={'never': 'PRIVATE_UNSAFE_PARENT_CONTENT'})
        self.backups.chmod(0o777)
        report = self.audit()
        self.assertFalse(report['prior_backups']['safe_to_inspect'])
        self.assertEqual(report['prior_backups']['records'], [])

    def test_only_latest_five_strictly_named_backup_records_are_inspected(self):
        for index in range(7):
            directory = self.record('server-fixture00' + str(index))
            os.utime(directory, ns=(index + 1, index + 1))
        (self.backups / 'not-a-release').mkdir()
        rows = self.audit()['prior_backups']['records']
        self.assertEqual([row['path']for row in rows], ['server-fixture00' + str(i)for i in (6, 5, 4, 3, 2)])

    def test_source_writable_failure_precedes_env_backup_and_all_publications(self):
        text = (ROOT / 'deployment/apply_desktop_dashboard_server.py').read_text()
        start = text.index("STAGE='SOURCES';before={}")
        failure = text.index("raise SafeError('DESKTOP_SOURCE_WRITABLE_BY_OTHER_USER')", start)
        self.assertLess(failure, text.index("before['.env']=publication_snapshot", start))
        self.assertLess(failure, text.index("backup=Path(tempfile.mkdtemp", start))
        self.assertLess(failure, text.index("STAGE='PUBLICATION'", start))
        report = self.audit()['reported_failure']
        self.assertFalse(report['application_changes_started'])
        self.assertFalse(report['server_backup_directory_created_by_that_attempt'])

    def test_manifest_scope_and_all_immutable_payload_pins(self):
        self.assertIsNotNone(subject['SHA'].fullmatch(subject['RELEASE']))
        manifest = json.loads((ROOT / 'deployment/desktop_dashboard_server_manifest.json').read_text())
        self.assertEqual(len(subject['validate_manifest'](manifest)['sources']), 266)
        for name, pin in subject['PINS'].items():
            self.assertEqual(subject['blob']((ROOT / name).read_bytes()), pin, name)
        for bad in ('.env', 'app/.env', 'app/secret.key', 'app/../.env', 'bootstrap/cache/config.php'):
            changed = copy.deepcopy(manifest)
            changed['sources'][0]['path'] = bad
            with self.assertRaises(subject['AuditError']):
                subject['validate_manifest'](changed)

    def test_payload_pin_mismatch_stops_without_private_output(self):
        fake = subprocess.CompletedProcess([], 0, b'PRIVATE_UNTRUSTED_PAYLOAD', b'')
        with patch.object(subprocess, 'run', return_value=fake):
            with self.assertRaises(subject['AuditError']):
                subject['reviewed_payload'](self.root)

    def test_wrong_host_cli_stops_before_git_or_source_access(self):
        output = subprocess.run(['bash', str(SCRIPT)], capture_output=True, check=False)
        self.assertEqual(output.returncode, 1)
        self.assertEqual(output.stdout, b'')
        self.assertEqual(output.stderr, b'GO_ERP_ACTIVATION_PERMISSIONS_STOPPED_WITHOUT_PRIVATE_OUTPUT\n')


@unittest.skipUnless(os.geteuid() == 0, 'Descriptor fchmod tests require root-owned private audit fixtures.')
class PermissionRepairTests(unittest.TestCase):
    setUp = ActivationPermissionsTests.setUp
    fingerprint = ActivationPermissionsTests.fingerprint
    audit = ActivationPermissionsTests.audit
    identities = ActivationPermissionsTests.identities
    record = ActivationPermissionsTests.record

    def repair(self):
        return subject['repair'](self.root, self.backups, self.uid, os.getegid(),
            self.manifest, self.scanner, self.source, Path(self.folder.name) / 'audits')

    def test_exact_pinned_source_mode_is_only_tightened_and_receipts_are_private(self):
        self.first.chmod(0o775)
        before = self.first.stat()
        contents = self.first.read_bytes()
        report = self.repair()
        self.assertFalse(report['repair']['blocked'])
        self.assertEqual(report['repair']['modes_tightened'], 1)
        after = self.first.stat()
        self.assertEqual(stat.S_IMODE(after.st_mode), 0o755)
        self.assertEqual((after.st_dev, after.st_ino, after.st_uid, after.st_gid, after.st_size, after.st_mtime_ns),
            (before.st_dev, before.st_ino, before.st_uid, before.st_gid, before.st_size, before.st_mtime_ns))
        self.assertEqual(self.first.read_bytes(), contents)
        self.assertTrue(report['metadata_changed'])
        self.assertFalse(report['application_file_contents_changed'])
        directory = Path(report['repair']['audit_directory'])
        self.assertEqual(stat.S_IMODE(directory.stat().st_mode), 0o700)
        self.assertEqual(directory.stat().st_uid, 0)
        self.assertEqual({path.name for path in directory.iterdir()}, {'planned.json', 'changed-000.json', 'completed.json'})
        for path in directory.iterdir():
            self.assertEqual(path.stat().st_uid, 0)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
            self.assertNotIn('PRIVATE_', path.read_text())
            self.assertNotIn('<?php', path.read_text())

    def test_multiple_candidates_are_planned_before_first_change(self):
        self.first.chmod(0o664)
        self.second.chmod(0o2664)
        real = os.fchmod
        with patch.object(os, 'fchmod', wraps=real) as operation:
            report = self.repair()
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(operation.call_count, 0)
        self.assertEqual(stat.S_IMODE(self.first.stat().st_mode), 0o664)
        self.assertEqual(stat.S_IMODE(self.second.stat().st_mode), 0o2664)

    def test_complete_unknown_source_cohort_refuses_every_change(self):
        self.first.chmod(0o664)
        self.second.write_bytes(b'<?php /* concurrent source */\n')
        before = self.identities()
        report = self.repair()
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(report['repair']['modes_tightened'], 0)
        self.assertEqual(before, self.identities())
        self.assertFalse((Path(self.folder.name) / 'audits').exists())

    def test_private_environment_metadata_is_not_repaired_and_blocks_partial_plan(self):
        self.first.chmod(0o664)
        self.env.chmod(0o660)
        before = self.identities()
        report = self.repair()
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(report['repair']['reason'], 'unreviewed_cohort_or_metadata')
        self.assertEqual(before, self.identities())

    def test_owner_or_group_mismatch_blocks_whole_plan_without_chown(self):
        self.first.chmod(0o664)
        original = subject['audit']
        for field in ('uid', 'gid'):
            def changed(*args):
                report = original(*args)
                report['unsafe_publication_paths'][0][field] += 1
                return report
            before = self.identities()
            with patch.dict(subject, audit=changed):
                report = self.repair()
            self.assertTrue(report['repair']['blocked'])
            self.assertEqual(before, self.identities())

    def test_inode_replacement_after_plan_is_rejected_before_fchmod(self):
        self.first.chmod(0o664)
        original = subject['private_audit']
        def replace(*args):
            result = original(*args)
            self.first.rename(Path(self.folder.name) / 'retained-previous.php')
            self.first.write_bytes(b'<?php /* PRIVATE_SOURCE_MARKER_DO_NOT_PRINT */\n')
            self.first.chmod(0o664)
            return result
        real = os.fchmod
        with patch.dict(subject, private_audit=replace), patch.object(os, 'fchmod', wraps=real) as operation:
            report = self.repair()
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(operation.call_count, 0)
        self.assertEqual(stat.S_IMODE(self.first.stat().st_mode), 0o664)
        self.assertTrue((Path(report['repair']['audit_directory']) / 'stopped.json').exists())

    def test_concurrent_change_after_first_tightening_never_restores_unsafe_bits(self):
        self.first.chmod(0o664)
        self.second.chmod(0o664)
        original = os.fchmod
        calls = 0
        def concurrent(fd, mode):
            nonlocal calls
            original(fd, mode)
            calls += 1
            if calls == 1:
                self.second.write_bytes(self.second.read_bytes())
        with patch.object(os, 'fchmod', side_effect=concurrent):
            report = self.repair()
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(report['repair']['modes_tightened'], 1)
        self.assertFalse(report['repair']['unsafe_permissions_restored'])
        self.assertEqual(stat.S_IMODE(self.first.stat().st_mode), 0o644)
        self.assertEqual(stat.S_IMODE(self.second.stat().st_mode), 0o664)
        stopped = json.loads((Path(report['repair']['audit_directory']) / 'stopped.json').read_text())
        self.assertEqual(stopped['completed_paths'], ['app/First.php'])

    def test_peer_source_change_after_fchmod_is_retained_and_stops_activation(self):
        self.first.chmod(0o664)
        original = os.fchmod
        def concurrent(fd, mode):
            original(fd, mode)
            self.first.write_bytes(b'<?php /* peer-owned source retained */\n')
        with patch.object(os, 'fchmod', side_effect=concurrent):
            report = self.repair()
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(stat.S_IMODE(self.first.stat().st_mode), 0o644)
        self.assertEqual(self.first.read_bytes(), b'<?php /* peer-owned source retained */\n')
        self.assertFalse(report['repair']['unsafe_permissions_restored'])

    def test_unsafe_existing_audit_directory_is_not_chmodded(self):
        self.first.chmod(0o664)
        parent = Path(self.folder.name) / 'audits'
        parent.mkdir(mode=0o755)
        report = self.repair()
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(stat.S_IMODE(parent.stat().st_mode), 0o755)
        self.assertEqual(stat.S_IMODE(self.first.stat().st_mode), 0o664)

    def test_protected_raw_source_and_external_gui_must_match(self):
        self.first.chmod(0o664)
        retained = self.root / 'app/Retained.php'
        retained.write_bytes(b'<?php /* retained source */\n')
        self.manifest['before_source'] = self.fingerprint()
        self.manifest['protected'] = [{'path': 'app/Retained.php', 'blob': 'a' * 40}]
        report = self.repair()
        self.assertTrue(report['source_matches_reviewed_before'])
        self.assertFalse(report['protected_raw_pins_match'])
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(stat.S_IMODE(self.first.stat().st_mode), 0o664)

    def test_new_external_gui_is_not_silently_accepted(self):
        self.first.chmod(0o664)
        directory = self.root / 'public/js'
        directory.mkdir(parents=True)
        (directory / 'dashboard-extra.js').write_bytes(b'newGui();\n')
        report = self.repair()
        self.assertTrue(report['source_matches_reviewed_before'])
        self.assertFalse(report['complete_external_gui_roster_matches'])
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(stat.S_IMODE(self.first.stat().st_mode), 0o664)

    def test_known_external_gui_is_pinned_and_repair_is_idempotent(self):
        self.first.chmod(0o664)
        directory = self.root / 'public/js'
        directory.mkdir(parents=True)
        gui = directory / 'dashboard-known.js'
        gui.write_bytes(b'knownGui();\n')
        self.manifest['protected'] = [{'path': 'public/js/dashboard-known.js', 'blob': subject['blob'](gui.read_bytes())}]
        before_gui = self.scanner['identity'](gui.stat())
        report = self.repair()
        self.assertFalse(report['repair']['blocked'])
        self.assertEqual(self.scanner['identity'](gui.stat()), before_gui)
        report = self.repair()
        self.assertFalse(report['repair']['blocked'])
        self.assertEqual(report['repair']['reason'], 'already_safe')
        self.assertEqual(report['repair']['modes_tightened'], 0)
        self.assertEqual(len(list((Path(self.folder.name) / 'audits').iterdir())), 1)

    def test_late_source_drift_during_final_audit_never_reports_success(self):
        self.first.chmod(0o664)
        original = subject['audit']
        calls = 0
        def late_change(*args):
            nonlocal calls
            calls += 1
            if calls == 2:
                self.second.write_bytes(b'<?php /* late peer source retained */\n')
            return original(*args)
        with patch.dict(subject, audit=late_change):
            report = self.repair()
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(report['repair']['modes_tightened'], 1)
        self.assertEqual(stat.S_IMODE(self.first.stat().st_mode), 0o644)
        self.assertEqual(self.second.read_bytes(), b'<?php /* late peer source retained */\n')
        directory = Path(report['repair']['audit_directory'])
        self.assertTrue((directory / 'stopped.json').exists())
        self.assertFalse((directory / 'completed.json').exists())

    def test_late_private_metadata_issue_during_final_audit_never_reports_success(self):
        self.first.chmod(0o664)
        original = subject['audit']
        calls = 0
        def late_change(*args):
            nonlocal calls
            calls += 1
            if calls == 2:
                self.env.chmod(0o660)
            return original(*args)
        with patch.dict(subject, audit=late_change):
            report = self.repair()
        self.assertTrue(report['repair']['blocked'])
        self.assertEqual(stat.S_IMODE(self.first.stat().st_mode), 0o644)
        self.assertEqual(stat.S_IMODE(self.env.stat().st_mode), 0o660)
        directory = Path(report['repair']['audit_directory'])
        self.assertTrue((directory / 'stopped.json').exists())
        self.assertFalse((directory / 'completed.json').exists())


if __name__ == '__main__':
    unittest.main()
