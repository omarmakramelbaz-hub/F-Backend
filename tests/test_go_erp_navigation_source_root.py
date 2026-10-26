"""Real-file source-only transaction guards; no server, DB or publication."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('navigation_source_subject',
    ROOT / 'deployment/go_erp_navigation_source_root.py')
subject = importlib.util.module_from_spec(spec)
spec.loader.exec_module(subject)
BLADE = 'resources/views/admin/index.blade.php'
SCRIPT = 'public/dashboard/js/dashboard-spa.js'
BALANCES = 'app/Services/Dashboard/BranchShiftClosing.php'
RELEASE = 'a' * 40


def real_owner_mapping():
    if os.geteuid() != 0:
        return False
    with tempfile.TemporaryDirectory(prefix='navigation-owner-capability-') as folder:
        file = Path(folder) / 'owner-probe'
        file.write_bytes(b'owner capability only')
        try:
            os.chown(file, 60001, 60002)
        except OSError:
            return False
        return (file.stat().st_uid, file.stat().st_gid) == (60001, 60002)


REAL_OWNERS = real_owner_mapping()


class SourceTransactionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-navigation-source-')
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.root = self.folder / 'public_html'
        self.root.mkdir(mode=0o755)
        self.audits = self.folder / 'audits'
        self.audits.mkdir(mode=0o700)
        self.before = {BLADE: b"@include('old.shell')\n", SCRIPT: b"old navigation\n", BALANCES: b"<?php /* original cash formula fixture */\n"}
        self.after = {BLADE: b"@include('reviewed.shell')\n", SCRIPT: b"reviewed navigation\n", BALANCES: b"<?php /* reviewed equivalent cash formula fixture */\n"}
        self.pins = {name: {'before': subject.sha(raw), 'after': subject.sha(self.after[name])}
            for name, raw in self.before.items()}
        for name, raw in self.before.items():
            file = self.root / name
            file.parent.mkdir(parents=True, mode=0o755)
            file.write_bytes(raw)
            file.chmod(0o640 if name == SCRIPT else 0o644)
        (self.root / 'storage/framework/views').mkdir(parents=True, mode=0o755)
        self.env = self.root / '.env'
        self.env.write_bytes(b'PROTECTED_SYNTHETIC_CONFIGURATION\n')
        self.env.chmod(0o600)
        self.peer = self.root / 'storage/framework/views' / ('f' * 40 + '.php')
        self.peer.write_bytes(b'<?php /* unrelated compiled view retained */ ?>\n')
        self.peer.chmod(0o644)
        self.verifications = 0
        self.protected_before = (subject.identity(self.env.stat()), self.env.read_bytes(),
            subject.identity(self.peer.stat()), self.peer.read_bytes())

    def verify(self):
        self.verifications += 1

    def apply(self, **options):
        return subject.apply(self.root, self.audits, RELEASE, 0, self.after,
            pins=self.pins, verify_locks=self.verify, **options)

    def assert_protected(self):
        self.assertEqual((subject.identity(self.env.stat()), self.env.read_bytes(),
            subject.identity(self.peer.stat()), self.peer.read_bytes()), self.protected_before)

    def assert_original(self):
        for name, raw in self.before.items():
            self.assertEqual((self.root / name).read_bytes(), raw)
        self.assert_protected()

    def create_compiled(self, content=b'old compiled body'):
        file = subject.compiled_path(self.root, BLADE)
        file.write_bytes(b'<?php /* ' + content + b' */ /**PATH ' +
            str(self.root / BLADE).encode() + b' ENDPATH**/ ?>\n')
        file.chmod(0o644)
        return file

    def test_exact_source_plan_is_backed_up_before_owner_mode_preserving_commit(self):
        facts = {name: (file.stat().st_uid, file.stat().st_gid,
            stat.S_IMODE(file.stat().st_mode), file.stat().st_mtime_ns)
            for name in self.before for file in [self.root / name]}
        result = self.apply()
        self.assertTrue(result['changed'])
        self.assertFalse(result['business_data_changed'])
        audit = Path(result['audit'])
        self.assertEqual(stat.S_IMODE(audit.stat().st_mode), 0o700)
        self.assertEqual(audit.stat().st_uid, 0)
        for name, raw in self.after.items():
            file = self.root / name
            self.assertEqual(file.read_bytes(), raw)
            info = file.stat()
            self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)), facts[name][:3])
            self.assertGreaterEqual(info.st_mtime_ns, (facts[name][3] // 1000000000 + 1) * 1000000000)
            backup = audit / ('before-' + subject.sha(name.encode()) + '.bin')
            self.assertEqual(backup.read_bytes(), self.before[name])
        self.assertTrue((audit / 'intent.json').exists())
        self.assertTrue((audit / 'committed.json').exists())
        for file in audit.iterdir():
            self.assertEqual((file.stat().st_uid, stat.S_IMODE(file.stat().st_mode)), (0, 0o600))
        self.assertGreater(self.verifications, 10)
        self.assert_protected()

    def test_already_committed_transaction_is_idempotent_without_source_rewrite(self):
        first = self.apply()
        facts = {name: subject.identity((self.root / name).stat()) for name in self.before}
        second = self.apply()
        self.assertFalse(second['changed'])
        self.assertEqual(second['audit'], first['audit'])
        self.assertEqual(len(list(self.audits.iterdir())), 1)
        self.assertEqual(facts, {name: subject.identity((self.root / name).stat()) for name in self.before})
        self.assert_protected()

    def test_unreviewed_scope_or_mismatching_payload_stops_before_audit_or_sources(self):
        for pins, payload in [
            ({'.env': {'before': 'a' * 64, 'after': 'b' * 64}}, {'.env': b'no'}),
            ({'resources/views/admin/../index.blade.php': {'before': 'a' * 64, 'after': 'b' * 64}},
                {'resources/views/admin/../index.blade.php': b'no'}),
            (self.pins, {**self.after, SCRIPT: b'unreviewed payload'}),
            (self.pins, {BLADE: self.after[BLADE]}),
        ]:
            with self.subTest(paths=list(pins)), self.assertRaises(subject.Stop):
                subject.apply(self.root, self.audits, RELEASE, 0, payload,
                    pins=pins, verify_locks=self.verify)
            self.assertEqual(list(self.audits.iterdir()), [])
            self.assert_original()

    def test_pending_default_pins_and_unverified_lock_never_apply(self):
        with self.assertRaisesRegex(subject.Stop, 'PINS_PENDING'):
            subject.apply(self.root, self.audits, RELEASE, 0, self.after,
                verify_locks=self.verify)
        for verifier in (None, lambda: False):
            with self.subTest(verifier=verifier), self.assertRaises(subject.Stop):
                subject.apply(self.root, self.audits, RELEASE, 0, self.after,
                    pins=self.pins, verify_locks=verifier)
        self.assertEqual(list(self.audits.iterdir()), [])
        self.assert_original()

    def test_unknown_source_bytes_are_not_overwritten(self):
        file = self.root / SCRIPT
        peer = b'concurrent different source\n'
        file.write_bytes(peer)
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_BYTES_UNREVIEWED'):
            self.apply()
        self.assertEqual(file.read_bytes(), peer)
        self.assertEqual((self.root / BLADE).read_bytes(), self.before[BLADE])
        self.assert_protected()

    def test_already_after_without_owned_intent_is_not_adopted(self):
        (self.root / SCRIPT).write_bytes(self.after[SCRIPT])
        with self.assertRaisesRegex(subject.Stop, 'ALREADY_CHANGED_WITHOUT_INTENT'):
            self.apply()
        self.assertEqual((self.root / SCRIPT).read_bytes(), self.after[SCRIPT])
        self.assertEqual((self.root / BLADE).read_bytes(), self.before[BLADE])
        self.assert_protected()

    def test_source_symlink_hardlink_or_writable_by_others_stops(self):
        target = self.root / SCRIPT
        target.unlink()
        target.symlink_to(self.env)
        with self.assertRaises(subject.Stop):
            self.apply()
        self.assertTrue(target.is_symlink())
        target.unlink()
        target.write_bytes(self.before[SCRIPT])
        outside = self.folder / 'hardlink-source'
        os.link(target, outside)
        with self.assertRaises(subject.Stop):
            self.apply()
        outside.unlink()
        target.chmod(0o666)
        with self.assertRaises(subject.Stop):
            self.apply()
        target.chmod(0o640)
        self.assert_original()

    def test_symlinked_source_ancestor_and_shared_audit_parent_stop(self):
        original = self.root / 'public'
        moved = self.root / 'public-original'
        original.rename(moved)
        original.symlink_to(moved, target_is_directory=True)
        with self.assertRaises((subject.Stop, OSError)):
            self.apply()
        self.assertTrue(original.is_symlink())
        original.unlink()
        moved.rename(original)
        self.audits.chmod(0o770)
        with self.assertRaises(subject.Stop):
            self.apply()
        self.audits.chmod(0o700)
        self.assert_original()

    def test_targeted_compiled_view_is_invalidated_and_unrelated_cache_is_retained(self):
        compiled = self.create_compiled()
        raw = compiled.read_bytes()
        result = self.apply()
        self.assertFalse(compiled.exists())
        backups = list(Path(result['audit']).glob('compiled-before-*.bin'))
        self.assertEqual([p.read_bytes() for p in backups], [raw])
        self.assert_protected()

    def test_unrecognized_compiled_marker_or_symlink_stops_before_source_replacement(self):
        compiled = self.create_compiled()
        compiled.write_bytes(b'<?php /* unrelated path */ ?>')
        with self.assertRaisesRegex(subject.Stop, 'COMPILED_MARKER_INVALID'):
            self.apply()
        self.assertTrue(compiled.exists())
        self.assert_original()
        compiled.unlink()
        compiled.symlink_to(self.env)
        with self.assertRaises(subject.Stop):
            self.apply()
        self.assertTrue(compiled.is_symlink())
        self.assert_original()

    def test_failure_after_own_writes_restores_only_sources_and_can_retry(self):
        compiled = self.create_compiled()
        def fail():
            raise RuntimeError('private failure is not exposed')
        with self.assertRaisesRegex(subject.Stop, 'PRIVATE_FAILURE_OWN_SOURCES_RESTORED'):
            self.apply(after_apply=fail)
        self.assertFalse(compiled.exists())
        self.assert_original()
        first_audit = next(self.audits.iterdir())
        self.assertTrue(list(first_audit.glob('failed-*.json')))
        self.assertFalse((first_audit / 'committed.json').exists())
        result = self.apply()
        self.assertEqual(result['audit'], str(first_audit))
        self.assertTrue(result['changed'])
        self.assert_protected()

    def test_source_directory_fsync_failure_after_rename_restores_that_owned_inode(self):
        original_fsync = os.fsync
        failures = []
        callbacks = []
        def fsync(fd):
            if (not failures and stat.S_ISDIR(os.fstat(fd).st_mode)
                    and (self.root / BALANCES).read_bytes() == self.after[BALANCES]):
                failures.append('source renamed before directory durability')
                raise OSError('injected real directory fsync failure')
            return original_fsync(fd)
        with patch.object(subject.os, 'fsync', side_effect=fsync):
            with self.assertRaisesRegex(subject.Stop, 'OWN_SOURCES_RESTORED'):
                self.apply(after_rollback=lambda: callbacks.append('restored-runtime'))
        self.assertEqual(len(failures), 1)
        self.assertEqual(callbacks, ['restored-runtime'])
        self.assert_original()
        audit = next(self.audits.iterdir())
        failure = json.loads(next(audit.glob('failed-*.json')).read_bytes())
        self.assertTrue(failure['restored_own_sources'])
        self.assertTrue(failure['uncommitted_receipt_removed'])
        self.assertFalse((audit / 'committed.json').exists())
        retry = self.apply()
        self.assertEqual(retry['audit'], str(audit))
        self.assertTrue(retry['changed'])

    def test_committed_directory_fsync_failure_removes_owned_receipt_before_rollback(self):
        original_fsync = os.fsync
        failures = []
        def fsync(fd):
            receipts = list(self.audits.glob('navigation-source-*/committed.json'))
            if not failures and stat.S_ISDIR(os.fstat(fd).st_mode) and receipts:
                self.assertTrue(all((self.root / name).read_bytes() == raw
                    for name, raw in self.after.items()))
                failures.append(receipts[0])
                raise OSError('injected committed record directory fsync failure')
            return original_fsync(fd)
        def restore_runtime():
            self.assert_original()
            self.assertFalse(list(self.audits.glob('navigation-source-*/committed.json')))
        with patch.object(subject.os, 'fsync', side_effect=fsync):
            with self.assertRaisesRegex(subject.Stop, 'OWN_SOURCES_RESTORED'):
                self.apply(after_rollback=restore_runtime)
        self.assertEqual(len(failures), 1)
        self.assert_original()
        audit = next(self.audits.iterdir())
        self.assertFalse((audit / 'committed.json').exists())
        failure = json.loads(next(audit.glob('failed-*.json')).read_bytes())
        self.assertTrue(failure['restored_own_sources'])
        self.assertTrue(failure['uncommitted_receipt_removed'])
        retry = self.apply()
        self.assertEqual(retry['audit'], str(audit))
        self.assertTrue(retry['changed'])

    def test_failed_commit_never_removes_same_bytes_from_a_peer_receipt_inode(self):
        original_fsync = os.fsync
        failures = []
        runtime = []
        peer_identity = []
        def fsync(fd):
            receipts = list(self.audits.glob('navigation-source-*/committed.json'))
            if not failures and stat.S_ISDIR(os.fstat(fd).st_mode) and receipts:
                marker = receipts[0]
                raw = marker.read_bytes()
                peer = marker.parent / 'peer-receipt.tmp'
                peer.write_bytes(raw)
                peer.chmod(0o600)
                peer.replace(marker)
                peer_identity.append(subject.identity(marker.stat()))
                failures.append(marker)
                raise OSError('peer receipt replaced after owned rename')
            return original_fsync(fd)
        with patch.object(subject.os, 'fsync', side_effect=fsync):
            with self.assertRaisesRegex(subject.Stop, 'COMMIT_RECEIPT_REVIEW_REQUIRED'):
                self.apply(after_rollback=lambda: runtime.append('must not reload'))
        self.assertEqual(len(failures), 1)
        self.assertEqual(runtime, [])
        self.assertEqual(subject.identity(failures[0].stat()), peer_identity[0])
        self.assert_original()
        audit = next(self.audits.iterdir())
        failure = json.loads(next(audit.glob('failed-*.json')).read_bytes())
        self.assertTrue(failure['restored_own_sources'])
        self.assertFalse(failure['uncommitted_receipt_removed'])
        with self.assertRaisesRegex(subject.Stop, 'COMMITTED_SOURCE_CHANGED'):
            self.apply()

    def test_cache_regenerated_during_failed_activation_is_targeted_on_rollback(self):
        compiled = self.create_compiled()
        def fail():
            self.create_compiled(b'new compiled while source was active')
            raise RuntimeError('activation failed')
        with self.assertRaisesRegex(subject.Stop, 'OWN_SOURCES_RESTORED'):
            self.apply(after_apply=fail)
        self.assertFalse(compiled.exists())
        self.assert_original()
        audit = next(self.audits.iterdir())
        self.assertEqual(len(list(audit.glob('compiled-before-*.bin'))), 2)

    def test_concurrent_peer_change_is_preserved_and_requires_review(self):
        peer = b'peer source after this deployment\n'
        original_replace = subject.replace_expected
        seen = []
        def replace(path, raw, expected, verify, advance=False, on_renamed=None):
            seen.append(str(path))
            if len(seen) == 2:
                path.write_bytes(peer)
            return original_replace(path, raw, expected, verify, advance, on_renamed)
        with patch.object(subject, 'replace_expected', side_effect=replace):
            with self.assertRaisesRegex(subject.Stop, 'CONCURRENT_SOURCE_REVIEW_REQUIRED'):
                self.apply()
        self.assertEqual((self.root / SCRIPT).read_bytes(), peer)
        self.assertEqual((self.root / BLADE).read_bytes(), self.before[BLADE])
        self.assertEqual((self.root / BALANCES).read_bytes(), self.before[BALANCES])
        self.assert_protected()
        failure = json.loads(next(next(self.audits.iterdir()).glob('failed-*.json')).read_bytes())
        self.assertTrue(failure['concurrent_source_retained'])

    def test_committed_source_or_audit_tampering_is_not_repaired_silently(self):
        result = self.apply()
        audit = Path(result['audit'])
        (self.root / SCRIPT).write_bytes(b'peer after successful commit')
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_BYTES_UNREVIEWED'):
            self.apply()
        (self.root / SCRIPT).write_bytes(self.after[SCRIPT])
        intent = json.loads((audit / 'intent.json').read_bytes())
        intent['files'][SCRIPT]['mode'] = 0o600
        (audit / 'intent.json').write_bytes(subject.canonical(intent))
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_METADATA_CHANGED'):
            self.apply()
        self.assert_protected()

    def test_process_interruption_partial_after_state_resumes_same_intent(self):
        class ProcessLost(BaseException):
            pass
        original_replace = subject.replace_expected
        calls = 0
        def replace(path, raw, expected, verify, advance=False, on_renamed=None):
            nonlocal calls
            calls += 1
            if calls == 2:
                raise ProcessLost()
            return original_replace(path, raw, expected, verify, advance, on_renamed)
        # A real kill cannot run Python rollback. Reproduce its disk checkpoint
        # by retaining the first reviewed replacement after rollback completed.
        with patch.object(subject, 'replace_expected', side_effect=replace):
            with self.assertRaises(subject.Stop):
                self.apply()
        (self.root / SCRIPT).write_bytes(self.after[SCRIPT])
        result = self.apply()
        self.assertTrue(result['changed'])
        self.assertEqual(len(list(self.audits.iterdir())), 1)
        for name, raw in self.after.items():
            self.assertEqual((self.root / name).read_bytes(), raw)
        self.assert_protected()

    def test_restored_runtime_callback_runs_only_after_source_and_cache_rollback(self):
        compiled = self.create_compiled()
        events = []
        def activate():
            self.assertTrue(all((self.root / name).read_bytes() == raw for name, raw in self.after.items()))
            self.assertFalse(compiled.exists())
            events.append('activate')
            self.create_compiled(b'compiled during failed reload')
            raise RuntimeError('reload failed')
        def restore_runtime():
            self.assert_original()
            self.assertFalse(compiled.exists())
            events.append('restore-runtime')
        with self.assertRaisesRegex(subject.Stop, 'OWN_SOURCES_RESTORED'):
            self.apply(after_apply=activate, after_rollback=restore_runtime)
        self.assertEqual(events, ['activate', 'restore-runtime'])
        failure = json.loads(next(next(self.audits.iterdir()).glob('failed-*.json')).read_bytes())
        self.assertTrue(failure['restored_own_sources'])
        self.assertTrue(failure['runtime_policy_restored'])

    def test_failed_runtime_rollback_is_a_review_receipt_not_a_restored_claim(self):
        def fail():
            raise RuntimeError('reviewed reload could not complete')
        with self.assertRaisesRegex(subject.Stop, 'RESTORED_RUNTIME_REVIEW_REQUIRED'):
            self.apply(after_apply=fail, after_rollback=fail)
        self.assert_original()
        failure = json.loads(next(next(self.audits.iterdir()).glob('failed-*.json')).read_bytes())
        self.assertTrue(failure['restored_own_sources'])
        self.assertFalse(failure['runtime_policy_restored'])

    def test_no_runtime_callbacks_before_mutation_or_over_concurrent_source(self):
        events = []
        (self.root / SCRIPT).write_bytes(b'unreviewed peer before any write')
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_BYTES_UNREVIEWED'):
            self.apply(after_apply=lambda: events.append('activate'),
                after_rollback=lambda: events.append('restore'))
        self.assertEqual(events, [])
        (self.root / SCRIPT).write_bytes(self.before[SCRIPT])
        def peer_change():
            events.append('activate')
            (self.root / BLADE).write_bytes(b'peer during reload')
            raise RuntimeError('reload failed')
        with self.assertRaisesRegex(subject.Stop, 'CONCURRENT_SOURCE_REVIEW_REQUIRED'):
            self.apply(after_apply=peer_change, after_rollback=lambda: events.append('restore'))
        self.assertEqual(events, ['activate'])
        self.assertEqual((self.root / BLADE).read_bytes(), b'peer during reload')
        self.assert_protected()

    @unittest.skipUnless(REAL_OWNERS, 'Real mapped owner UID60001/GID60002 required')
    def test_application_uid_gid_and_existing_mode_survive_real_replacement(self):
        for name in self.before:
            os.chown(self.root / name, 60001, 60002)
        result = subject.apply(self.root, self.audits, RELEASE, 60001, self.after,
            pins=self.pins, verify_locks=self.verify)
        self.assertTrue(result['changed'])
        for name in self.before:
            info = (self.root / name).stat()
            self.assertEqual((info.st_uid, info.st_gid), (60001, 60002))
            self.assertEqual(stat.S_IMODE(info.st_mode), 0o640 if name == SCRIPT else 0o644)
        for file in Path(result['audit']).iterdir():
            self.assertEqual((file.stat().st_uid, file.stat().st_gid), (0, 0))
        self.assert_protected()

    def test_all_three_reviewed_paths_are_required_and_special_file_modes_stop(self):
        pins = {key: value for key, value in self.pins.items() if key != BALANCES}
        payload = {key: value for key, value in self.after.items() if key != BALANCES}
        with self.assertRaisesRegex(subject.Stop, 'PLAN_SCOPE_INVALID'):
            subject.apply(self.root, self.audits, RELEASE, 0, payload,
                pins=pins, verify_locks=self.verify)
        self.assertEqual(list(self.audits.iterdir()), [])
        (self.root / SCRIPT).chmod(0o4640)
        with self.assertRaisesRegex(subject.Stop, 'FILE_UNSAFE'):
            self.apply()
        (self.root / SCRIPT).chmod(0o640)
        self.assert_original()

    def test_cli_cannot_apply_a_plan(self):
        result = subprocess.run(['python3', '-I', '-B', str(ROOT / 'deployment/go_erp_navigation_source_root.py')],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, b'GO_ERP_NAVIGATION_SOURCE_USE_PINNED_COORDINATOR\n')
        self.assertEqual(result.stderr, b'')
        self.assert_original()


if __name__ == '__main__':
    unittest.main()
