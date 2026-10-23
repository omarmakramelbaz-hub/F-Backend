import copy
from contextlib import redirect_stdout
import io
import importlib.util
import json
import os
from pathlib import Path
from types import SimpleNamespace
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('paymob_confirmation', REPO / 'deployment/apply_paymob_order_confirmation_root.py')
fix = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fix)


class PaymobOrderConfirmationPublicationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'app'
        self.root.mkdir()
        self.backup = Path(self.temporary.name) / 'backup'
        self.new = set(fix.PATHS[2:6])
        self.payloads = {name: ('reviewed:' + name).encode() for name in fix.PATHS}
        self.originals = {name: ('baseline:' + name).encode() for name in fix.PATHS if name not in self.new}
        self.alternates = {name: ('second-baseline:' + name).encode() for name in self.originals}
        self.manifest = {name: {'before': [] if name in self.new else [fix.digest(self.originals[name]), fix.digest(self.alternates[name])],
            'after': fix.digest(self.payloads[name])} for name in fix.PATHS}
        for name in fix.PATHS:
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            if name not in self.new:
                target.write_bytes(self.originals[name])
                target.chmod(0o640)
        self.unrelated = self.root / 'unrelated.txt'
        self.unrelated.write_text('Preserve this independent edit')

    def snapshot(self):
        return {name: (self.root / name).read_bytes() if (self.root / name).exists() else None for name in fix.PATHS}

    def test_exact_publication_new_files_backups_metadata_and_repeat(self):
        changes = fix.plan(self.root, self.manifest, self.payloads, owner_uid=os.geteuid())
        self.assertEqual(14, len(changes))
        fix.publish(self.root, changes, self.backup)
        metadata = json.loads((self.backup / 'metadata.json').read_text())
        self.assertEqual(0o700, self.backup.stat().st_mode & 0o777)
        self.assertEqual(0o600, (self.backup / 'metadata.json').stat().st_mode & 0o777)
        for name in fix.PATHS:
            target = self.root / name
            self.assertEqual(self.payloads[name], target.read_bytes())
            self.assertEqual(os.geteuid(), target.stat().st_uid)
            self.assertEqual(os.getegid(), target.stat().st_gid)
            self.assertEqual(0o644 if name in self.new else 0o640, target.stat().st_mode & 0o777)
            self.assertEqual(name not in self.new, metadata[name]['existed'])
            if name in self.new:
                self.assertIsNone(metadata[name]['before'])
                self.assertFalse((self.backup / name).exists())
            else:
                self.assertEqual(self.originals[name], (self.backup / name).read_bytes())
        self.assertEqual([], fix.plan(self.root, self.manifest, self.payloads, owner_uid=os.geteuid()))
        self.assertEqual('Preserve this independent edit', self.unrelated.read_text())

    def test_mixed_reviewed_baselines_and_previously_applied_files(self):
        for name in list(self.originals)[::2]:
            (self.root / name).write_bytes(self.alternates[name])
        installed = [fix.PATHS[0], *self.new]
        for name in installed:
            (self.root / name).write_bytes(self.payloads[name])
        changes = fix.plan(self.root, self.manifest, self.payloads)
        self.assertEqual(14 - len(installed), len(changes))
        self.assertTrue(set(installed).isdisjoint(change[0] for change in changes))
        fix.publish(self.root, changes, self.backup)
        self.assertEqual([], fix.plan(self.root, self.manifest, self.payloads))

    def test_unexpected_live_drift_rejects_entire_plan_without_writes(self):
        (self.root / fix.PATHS[-1]).write_text('Unreviewed live change')
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'BASELINE_MISMATCH'):
            fix.plan(self.root, self.manifest, self.payloads)
        self.assertEqual(before, self.snapshot())
        self.assertFalse(self.backup.exists())

    def test_absent_existing_file_and_unexpected_preexisting_new_file_are_rejected(self):
        name = fix.PATHS[0]
        (self.root / name).unlink()
        with self.assertRaisesRegex(RuntimeError, 'BASELINE_MISMATCH'):
            fix.plan(self.root, self.manifest, self.payloads)
        (self.root / name).write_bytes(self.originals[name])
        (self.root / fix.PATHS[2]).write_text('Unreviewed implementation')
        with self.assertRaisesRegex(RuntimeError, 'BASELINE_MISMATCH'):
            fix.plan(self.root, self.manifest, self.payloads)

    def test_payload_tampering_and_missing_or_extra_paths_are_rejected(self):
        payloads = dict(self.payloads)
        payloads[fix.PATHS[0]] += b'\n'
        with self.assertRaisesRegex(RuntimeError, 'PACKAGE_HASH_INVALID'):
            fix.plan(self.root, self.manifest, payloads)
        for payloads in [{}, {**self.payloads, 'unrelated.txt': b'overwrite'}]:
            with self.assertRaisesRegex(RuntimeError, 'PACKAGE_PATHS_INVALID'):
                fix.plan(self.root, self.manifest, payloads)

    def test_manifest_baseline_arrays_are_bounded_and_sha256_only(self):
        for before in ['not-an-array', [fix.digest(b'a')] * 2, ['not-a-hash'], [fix.digest(bytes([number])) for number in range(3)]]:
            manifest = copy.deepcopy(self.manifest)
            manifest[fix.PATHS[0]]['before'] = before
            with self.assertRaisesRegex(RuntimeError, 'MANIFEST_INVALID'):
                fix.plan(self.root, manifest, self.payloads)

    def test_existing_owner_and_source_file_metadata_are_checked(self):
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_OWNER_INVALID'):
            fix.plan(self.root, self.manifest, self.payloads, owner_uid=os.geteuid() + 1)
        changes = fix.plan(self.root, self.manifest, self.payloads)
        (self.root / fix.PATHS[0]).chmod(0o600)
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED'):
            fix.publish(self.root, changes, self.backup)
        self.assertFalse(self.backup.exists())

    @unittest.skipUnless(os.geteuid() == 0, 'testing a different new-file UID requires root')
    def test_new_sources_receive_requested_application_uid_gid_and_0644(self):
        with tempfile.TemporaryFile() as probe:
            try:
                os.fchown(probe.fileno(), 65534, 65534)
            except OSError as error:
                if error.errno in (1, 22):
                    self.skipTest('The container maps only its current UID; foreign-owner publication requires a normal root namespace')
                raise
        changes = fix.plan(self.root, self.manifest, self.payloads)
        fix.publish(self.root, changes, self.backup, new_uid=65534, new_gid=65534)
        for name in self.new:
            info = (self.root / name).stat()
            self.assertEqual((65534, 65534, 0o644), (info.st_uid, info.st_gid, info.st_mode & 0o777))
        for name in self.originals:
            self.assertEqual(os.geteuid(), (self.root / name).stat().st_uid)

    def test_symlinks_in_existing_new_and_parent_paths_are_rejected(self):
        name = fix.PATHS[0]
        original = (self.root / name).read_bytes()
        (self.root / name).unlink()
        (self.root / name).symlink_to(self.unrelated)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            fix.plan(self.root, self.manifest, self.payloads)
        (self.root / name).unlink()
        (self.root / name).write_bytes(original)
        name = fix.PATHS[2]
        (self.root / name).symlink_to(self.root / 'does-not-exist')
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            fix.plan(self.root, self.manifest, self.payloads)
        (self.root / name).unlink()
        parent = self.root / 'app'
        moved = self.root / 'moved-app'
        parent.rename(moved)
        parent.symlink_to(moved, target_is_directory=True)
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PARENT_INVALID'):
            fix.plan(self.root, self.manifest, self.payloads)

    def test_hardlinked_sources_are_rejected(self):
        os.link(self.root / fix.PATHS[0], self.root / 'second-link')
        with self.assertRaisesRegex(RuntimeError, 'FILE_TYPE_INVALID'):
            fix.plan(self.root, self.manifest, self.payloads)

    def test_write_failure_removes_new_sources_and_restores_exact_existing_bytes(self):
        changes = fix.plan(self.root, self.manifest, self.payloads)
        before = self.snapshot()
        original_replace = fix.replace_file
        count = 0
        def fail_after_new_files(*arguments):
            nonlocal count
            count += 1
            if count == 7:
                raise RuntimeError('SIMULATED_WRITE_FAILURE')
            original_replace(*arguments)
        with patch.object(fix, 'replace_file', fail_after_new_files):
            with self.assertRaisesRegex(RuntimeError, 'SIMULATED_WRITE_FAILURE'):
                fix.publish(self.root, changes, self.backup)
        self.assertEqual(before, self.snapshot())
        for name in self.originals:
            self.assertEqual(self.originals[name], (self.backup / name).read_bytes())
            self.assertEqual(0o640, (self.root / name).stat().st_mode & 0o777)

    def test_rollback_preserves_concurrent_edit_and_restores_other_owned_changes(self):
        changes = fix.plan(self.root, self.manifest, self.payloads)
        original_replace = fix.replace_file
        count = 0
        def concurrent_edit_then_fail(*arguments):
            nonlocal count
            count += 1
            if count == 7:
                (self.root / fix.PATHS[0]).write_text('Concurrent edit after publication')
                raise RuntimeError('SIMULATED_WRITE_FAILURE')
            original_replace(*arguments)
        with patch.object(fix, 'replace_file', concurrent_edit_then_fail):
            with self.assertRaisesRegex(RuntimeError, 'ROLLBACK_REVIEW_REQUIRED'):
                fix.publish(self.root, changes, self.backup)
        self.assertEqual('Concurrent edit after publication', (self.root / fix.PATHS[0]).read_text())
        self.assertEqual(self.originals[fix.PATHS[1]], (self.root / fix.PATHS[1]).read_bytes())
        for name in self.new:
            self.assertFalse((self.root / name).exists())

    def test_new_target_created_concurrently_is_preserved(self):
        changes = fix.plan(self.root, self.manifest, self.payloads)
        original_replace = fix.replace_file
        count = 0
        def create_concurrent_target(*arguments):
            nonlocal count
            count += 1
            original_replace(*arguments)
            if count == 2:
                (self.root / fix.PATHS[4]).write_text('Concurrent new implementation')
        with patch.object(fix, 'replace_file', create_concurrent_target):
            with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED'):
                fix.publish(self.root, changes, self.backup)
        self.assertEqual('Concurrent new implementation', (self.root / fix.PATHS[4]).read_text())
        for name in fix.PATHS[:2]:
            self.assertEqual(self.originals[name], (self.root / name).read_bytes())

    def test_new_classes_publish_before_existing_live_callers(self):
        changes = fix.plan(self.root, self.manifest, self.payloads)
        original_replace = fix.replace_file
        writes = []
        def record(target, *arguments):
            writes.append(str(target.relative_to(self.root)))
            original_replace(target, *arguments)
        with patch.object(fix, 'replace_file', record):
            fix.publish(self.root, changes, self.backup)
        self.assertEqual(self.new, set(writes[:len(self.new)]))
        self.assertTrue(set(writes[len(self.new):]).isdisjoint(self.new))

    def test_cache_and_optional_reconciliation_use_application_account_and_bounded_command(self):
        for reconcile, expected in [(False, ['route:clear', 'view:clear']), (True, ['route:clear', 'view:clear', 'order-payments:reconcile'])]:
            calls = []
            def run(command, **options):
                calls.append((command, options))
                return SimpleNamespace(returncode=0, stdout=self.receipt())
            output = io.StringIO()
            with redirect_stdout(output):
                fix.post_publication(self.root, reconcile, runner=run)
            self.assertEqual(expected, [command[10] for command, _ in calls])
            for command, options in calls:
                self.assertEqual(['/usr/sbin/runuser', '-u', 'fasakha', '--', fix.PHP, '-d', 'display_errors=0', '-d', 'log_errors=0', 'artisan'], command[:10])
                self.assertEqual(str(self.root), options['cwd'])
                self.assertEqual(fix.subprocess.PIPE if command[10] == 'order-payments:reconcile' else fix.subprocess.DEVNULL, options['stdout'])
                self.assertEqual(fix.subprocess.DEVNULL, options['stderr'])
            if reconcile:
                self.assertEqual(['--apply', '--limit=5'], calls[-1][0][11:])
                self.assertIn('"activated": 2', output.getvalue())
            else:
                self.assertEqual('', output.getvalue())

    def receipt(self, **extra):
        counts = dict(mode='apply', candidates=2, checked=2, activated=2, unchanged=0, closed=0, deferred=0, effects_deferred=0)
        counts.update(extra)
        return ('RESTAURANT_PAYMENT_RECONCILIATION ' + json.dumps(counts) + '\n').encode()

    def test_reconciliation_receipt_whitelists_counts_and_suppresses_unrelated_output(self):
        output = io.StringIO()
        receipt = b'Unexpected provider body credential-do-not-print\n' + self.receipt(customer_email='private@example.test', api_key='credential-do-not-print')
        def run(command, **options):
            return SimpleNamespace(returncode=0, stdout=receipt)
        with redirect_stdout(output):
            fix.post_publication(self.root, True, runner=run)
        result = output.getvalue()
        self.assertNotIn('credential', result)
        self.assertNotIn('private', result)
        self.assertEqual({'mode', 'candidates', 'checked', 'activated', 'unchanged', 'closed', 'deferred', 'effects_deferred'}, set(json.loads(result.split(' ', 1)[1])))

    def test_missing_malformed_or_inconsistent_reconciliation_receipt_is_explicit_failure(self):
        for receipt, error in [(b'Unrelated app output', 'MISSING'),
            (b'RESTAURANT_PAYMENT_RECONCILIATION {broken', 'INVALID'),
            (self.receipt() + self.receipt(), 'INVALID'),
            (self.receipt(mode='check'), 'INVALID'),
            (self.receipt(activated=True), 'INVALID'),
            (self.receipt(activated='2'), 'INVALID'),
            (self.receipt(candidates=6, checked=6, activated=6), 'INVALID'),
            (self.receipt(deferred=1), 'INVALID'),
            (self.receipt(effects_deferred=3), 'INVALID')]:
            def run(command, **options):
                return SimpleNamespace(returncode=0, stdout=receipt)
            with self.assertRaisesRegex(RuntimeError, '^POST_PUBLICATION_RECONCILIATION_RECEIPT_' + error + '$'):
                fix.post_publication(self.root, True, runner=run)

    def test_deferred_reconciliation_is_reported_separately_from_source_readiness(self):
        output = io.StringIO()
        def run(command, **options):
            return SimpleNamespace(returncode=0, stdout=self.receipt(candidates=5, checked=5, activated=0, deferred=5))
        with redirect_stdout(output):
            fix.post_publication(self.root, True, runner=run)
        counts = json.loads(output.getvalue().split(' ', 1)[1])
        self.assertEqual(0, counts['activated'])
        self.assertEqual(5, counts['deferred'])

    def test_post_publication_failure_reports_safe_code_and_never_rolls_back_source(self):
        fix.publish(self.root, fix.plan(self.root, self.manifest, self.payloads), self.backup)
        for failure_at, expected in [(1, 'CACHE_ROUTE'), (2, 'CACHE_VIEW'), (3, 'RECONCILIATION')]:
            calls = []
            def run(command, **options):
                calls.append(command)
                if len(calls) == failure_at:
                    raise RuntimeError('credential-and-customer-body-must-not-escape')
                return SimpleNamespace(returncode=0)
            with self.assertRaisesRegex(RuntimeError, '^POST_PUBLICATION_' + expected + '_FAILED$'):
                fix.post_publication(self.root, True, runner=run)
            self.assertEqual(self.payloads, self.snapshot())
            self.assertEqual(failure_at, len(calls))


if __name__ == '__main__':
    unittest.main()
