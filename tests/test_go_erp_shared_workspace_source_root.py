"""Real filesystem mutation, crash-resume and owned rollback; no server or SQL."""
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('shared_workspace_source',
    ROOT / 'deployment/go_erp_shared_workspace_source_root.py')
subject = importlib.util.module_from_spec(spec)
spec.loader.exec_module(subject)
RELEASE = 'a' * 40
REPLACED = 'app/Existing.php'
ADDED = 'app/Workspace/Nested/New.php'
BLADE = 'resources/views/admin/owned.blade.php'


class Crash(BaseException):
    """Simulate process death without error cleanup."""


@unittest.skipUnless(os.geteuid() == 0, 'Root-owned audit/inode tests require a disposable root runtime')
class SourceTransactionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='workspace-source-proof-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.root = self.base / 'public_html'
        self.root.mkdir(mode=0o755)
        self.audits = self.base / 'audits'
        self.audits.mkdir(mode=0o700)
        self.uid = self.gid = 0
        self.before = {REPLACED: b'<?php /* before */\n'}
        self.after = {REPLACED: b'<?php /* reviewed replacement */\n',
            ADDED: b'<?php /* new reviewed source */\n'}
        self.pins = {name: {'before': subject.sha(self.before[name]) if name in self.before else None,
            'after': subject.sha(raw)} for name, raw in self.after.items()}
        self.directories = ['app/Workspace', 'app/Workspace/Nested']
        self.write(REPLACED, self.before[REPLACED], 0o640)
        self.write('.env', b'APP_KEY=synthetic-private-unrelated\n', 0o600)
        self.write('app/Unrelated.php', b'<?php /* unrelated */\n')
        self.protected = {name: (self.root / name).read_bytes() for name in ('.env', 'app/Unrelated.php')}
        self.checks = 0
        self.barriers = []

    def write(self, name, raw, mode=0o644):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
        path.write_bytes(raw)
        path.chmod(mode)
        return path

    def verify(self):
        self.checks += 1

    def transaction(self, **options):
        return subject.Transaction(self.root, self.audits, RELEASE, self.uid, self.gid,
            self.after, self.pins, self.directories, self.verify, **options)

    def apply(self, **options):
        return subject.apply(self.root, self.audits, RELEASE, self.uid, self.after,
            pins=self.pins, owner_gid=self.gid, new_directories=self.directories,
            verify_locks=self.verify, **options)

    def rollback(self, **options):
        options.setdefault('addition_removal_barrier', self.barrier)
        options.setdefault('expected_handler_sha256', 'b' * 64)
        return subject.rollback(self.root, self.audits, RELEASE, self.uid, self.after,
            pins=self.pins, owner_gid=self.gid, new_directories=self.directories,
            verify_locks=self.verify, **options)

    def barrier(self, pending):
        # Disposable fake actuator; no FPM/service action is performed here.
        self.assertEqual((self.root / REPLACED).read_bytes(), self.before[REPLACED])
        self.barriers.append(pending)
        return {'format': 1, 'kind': 'go-erp-shared-workspace-addition-removal-approved',
            'pending_sha256': subject.sha(subject.canonical(pending)),
            'php_handler_configuration_sha256': 'b' * 64,
            'fpm_unit': 'ea-php82-php-fpm.service', 'old_generation_captured_before_reload': True,
            'drained_before_addition_removal': True,
            'reload_completed': True, 'no_old_generation_requests': True,
            'restored_referrers_sha256': pending['restored_referrers_sha256'],
            'compiled_referrers_restored': True, 'route_cache_policy_verified': True,
            'workspace_enabled': False,
            'private_receipt_sha256': 'c' * 64}

    def assert_protected(self):
        self.assertEqual(self.protected, {name: (self.root / name).read_bytes() for name in self.protected})

    def crash_at(self, phase, name):
        def checkpoint(current_phase, current_name):
            if (phase, name) == (current_phase, current_name):
                raise Crash()
        return checkpoint

    def test_exact_add_replace_new_directories_metadata_private_audit_and_idempotence(self):
        result = self.apply()
        self.assertTrue(result['changed'])
        self.assertIsNone(subject.FINAL_RELEASE_PINS)
        for name, raw in self.after.items():
            path = self.root / name
            self.assertEqual(path.read_bytes(), raw)
            self.assertEqual(path.stat().st_nlink, 1)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o640 if name == REPLACED else 0o644)
        for name in self.directories:
            self.assertEqual(stat.S_IMODE((self.root / name).stat().st_mode), 0o755)
        self.assertFalse(result['schema_changed'])
        self.assertFalse(result['financial_data_changed'])
        self.assertFalse(result['feature_activated'])
        self.assertFalse(result['allocated'])
        self.assertFalse(result['published'])
        audit = Path(result['audit'])
        for item in audit.rglob('*'):
            self.assertEqual((item.stat().st_uid, item.stat().st_gid), (0, 0))
            self.assertEqual(stat.S_IMODE(item.stat().st_mode), 0o700 if item.is_dir() else 0o600)
        before_inodes = {name: subject.inode((self.root / name).stat()) for name in self.pins}
        second = self.apply()
        self.assertFalse(second['changed'])
        self.assertEqual(before_inodes, {name: subject.inode((self.root / name).stat()) for name in self.pins})
        self.assertGreater(self.checks, 20)
        self.assert_protected()

    def test_crash_after_each_owned_write_ahead_or_publish_resumes_same_attempt(self):
        boundaries = [('write-ahead', 'app/Workspace'), ('published', 'app/Workspace'),
            ('write-ahead', 'app/Workspace/Nested'), ('published', 'app/Workspace/Nested'),
            ('write-ahead', REPLACED), ('published', REPLACED),
            ('write-ahead', ADDED), ('published', ADDED)]
        # Tear down each successful iteration's new objects privately.
        for index, boundary in enumerate(boundaries):
            with self.subTest(boundary=boundary):
                if index:
                    self.setUp()
                with self.assertRaises(Crash):
                    self.apply(checkpoint=self.crash_at(*boundary))
                tx = self.transaction()
                first_attempt = tx.attempt
                self.assertFalse((first_attempt / 'committed.json').exists())
                result = tx.apply()
                self.assertEqual(result['attempt'], 'attempt-000000')
                self.assertEqual(tx.attempt, first_attempt)
                self.assertEqual((self.root / ADDED).read_bytes(), self.after[ADDED])
                self.assert_protected()

    def test_owned_rollback_restores_replacement_and_removes_only_new_owned_paths(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('published', REPLACED))
        result = self.rollback()
        self.assertTrue(result['restored'])
        self.assertEqual((self.root / REPLACED).read_bytes(), self.before[REPLACED])
        self.assertFalse((self.root / 'app/Workspace').exists())
        self.assert_protected()
        # The same rollback is idempotent and does not consume an attempt.
        again = self.rollback()
        self.assertEqual(again['attempt'], 'attempt-000000')
        # Retrying source installation gets a new source attempt, never a new
        # native candidate or altered previous evidence.
        second = self.apply()
        self.assertEqual(second['attempt'], 'attempt-000001')
        self.assertEqual((self.root / ADDED).read_bytes(), self.after[ADDED])

    def test_rollback_crash_after_restore_write_ahead_resumes(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('published', REPLACED))
        with self.assertRaises(Crash):
            self.rollback(checkpoint=self.crash_at('write-ahead', REPLACED))
        with self.assertRaisesRegex(subject.Stop, 'ROLLBACK_MUST_FINISH_FIRST'):
            self.apply()
        self.assertTrue(self.rollback()['restored'])
        self.assertEqual((self.root / REPLACED).read_bytes(), self.before[REPLACED])
        self.assert_protected()

    def test_rollback_keeps_unrelated_file_in_new_directory_and_requires_resume(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('published', ADDED))
        peer = self.write('app/Workspace/Nested/peer.php', b'<?php /* peer owned */')
        with self.assertRaisesRegex(subject.Stop, 'OWNED_DIRECTORY_NOT_EMPTY'):
            self.rollback()
        self.assertEqual(peer.read_bytes(), b'<?php /* peer owned */')
        self.assertFalse((self.root / ADDED).exists())
        with self.assertRaisesRegex(subject.Stop, 'ROLLBACK_MUST_FINISH_FIRST'):
            self.apply()
        # Operator can resolve the unrelated file independently, then resume
        # the original rollback without deleting any extra content.
        peer.rename(self.root / 'app/peer-retained.php')
        self.assertTrue(self.rollback()['restored'])
        self.assertEqual((self.root / 'app/peer-retained.php').read_bytes(), b'<?php /* peer owned */')

    def test_peer_replacement_with_identical_bytes_is_never_unlinked_or_restored(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('published', ADDED))
        target = self.root / ADDED
        peer = target.with_name('peer-replacement')
        peer.write_bytes(target.read_bytes())
        peer.chmod(0o644)
        os.replace(peer, target)
        peer_inode = subject.inode(target.stat())
        with self.assertRaisesRegex(subject.Stop, 'ROLLBACK_ADDED_TARGET_NOT_OWNED'):
            self.rollback()
        self.assertEqual(subject.inode(target.stat()), peer_inode)
        self.assertEqual(target.read_bytes(), self.after[ADDED])
        self.assert_protected()

    def test_appearance_between_absence_proof_and_publish_is_not_overwritten(self):
        def checkpoint(phase, name):
            if (phase, name) == ('write-ahead', ADDED):
                self.write(ADDED, b'peer appeared')
        with self.assertRaisesRegex(subject.Stop, 'ABSENT_TARGET_APPEARED'):
            self.apply(checkpoint=checkpoint)
        self.assertEqual((self.root / ADDED).read_bytes(), b'peer appeared')
        self.assert_protected()

    def test_unjournaled_stage_after_hard_crash_is_retained_for_review(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('staged', 'app/Workspace'))
        stages = list((self.root / 'app').glob('.go-erp-workspace-*'))
        self.assertEqual(len(stages), 1)
        stage_inode = subject.inode(stages[0].stat())
        with self.assertRaisesRegex(subject.Stop, 'UNOWNED_STAGE_REVIEW_REQUIRED'):
            self.apply()
        with self.assertRaisesRegex(subject.Stop, 'UNOWNED_STAGE_REVIEW_REQUIRED'):
            self.rollback()
        self.assertEqual(subject.inode(stages[0].stat()), stage_inode)
        self.assertEqual((self.root / REPLACED).read_bytes(), self.before[REPLACED])

    def test_committed_checkpoint_crash_resumes_without_repeating_cache_callback(self):
        calls = []
        with self.assertRaises(Crash):
            self.apply(after_apply=lambda: calls.append('cache'), checkpoint=self.crash_at('committed', ''))
        before = {name: subject.inode((self.root / name).stat()) for name in self.pins}
        self.assertFalse(self.apply(after_apply=lambda: calls.append('unexpected'))['changed'])
        self.assertEqual(calls, ['cache'])
        self.assertEqual(before, {name: subject.inode((self.root / name).stat()) for name in self.pins})

    def test_all_additions_precede_modified_entrypoints_and_rollback_referrers_precede_drain_and_delete(self):
        service = 'app/Services/ExistingService.php'
        controller = 'app/Http/Controllers/ExistingController.php'
        entrypoints = ('app/Http/Kernel.php', 'app/Http/Middleware/IsAdmin.php', 'desktop/control.php')
        for name in (service, controller, *entrypoints, BLADE):
            self.before[name] = b'original: ' + name.encode()
            self.after[name] = b'reviewed: ' + name.encode()
            self.pins[name] = {'before': subject.sha(self.before[name]), 'after': subject.sha(self.after[name])}
            self.write(name, self.before[name])
        (self.root / 'storage/framework/views').mkdir(parents=True, mode=0o755)
        events = []
        def checkpoint(phase, name):
            if phase == 'published' and name in self.pins:
                events.append(name)
        with self.assertRaises(Crash):
            self.apply(checkpoint=checkpoint, after_apply=lambda: (_ for _ in ()).throw(Crash()))
        self.assertEqual(events[0], ADDED)
        self.assertLess(events.index(service), events.index(controller))
        self.assertLess(events.index(controller), events.index(BLADE))
        events.clear()
        def barrier(pending):
            for name, raw in self.before.items():
                self.assertEqual((self.root / name).read_bytes(), raw)
            self.assertEqual((self.root / ADDED).read_bytes(), self.after[ADDED])
            events.append('drain/reload')
            return self.barrier(pending)
        def rollback_checkpoint(phase, name):
            if phase in ('published', 'removed') and name in self.pins:
                events.append(name)
        self.rollback(addition_removal_barrier=barrier, checkpoint=rollback_checkpoint)
        self.assertLess(events.index(BLADE), events.index(controller))
        self.assertLess(events.index(controller), events.index(service))
        self.assertLess(events.index(service), events.index('drain/reload'))
        self.assertLess(events.index('drain/reload'), events.index(ADDED))

    def test_visible_addition_rollback_requires_attested_drain_before_removal(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('published', REPLACED))
        with self.assertRaisesRegex(subject.Stop, 'FPM_DRAIN_BOUNDARY_REQUIRED'):
            self.rollback(addition_removal_barrier=None, expected_handler_sha256=None)
        self.assertEqual((self.root / REPLACED).read_bytes(), self.before[REPLACED])
        self.assertEqual((self.root / ADDED).read_bytes(), self.after[ADDED])
        self.assertTrue(self.rollback()['restored'])

    def test_reloaded_fpm_without_old_generation_drain_is_insufficient(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('published', REPLACED))
        def incomplete(pending):
            proof = self.barrier(pending)
            proof['no_old_generation_requests'] = False
            return proof
        with self.assertRaisesRegex(subject.Stop, 'FPM_DRAIN_BOUNDARY_PROOF_INVALID'):
            self.rollback(addition_removal_barrier=incomplete)
        self.assertTrue((self.root / ADDED).exists())
        self.assertTrue(self.rollback()['restored'])

    def test_drain_proof_requires_restored_cache_referrers_disabled_flag_and_exact_snapshot(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('published', REPLACED))
        for key, value in (('compiled_referrers_restored', False), ('route_cache_policy_verified', False),
                ('workspace_enabled', True), ('restored_referrers_sha256', 'd' * 64)):
            def invalid(pending, key=key, value=value):
                proof = self.barrier(pending)
                proof[key] = value
                return proof
            with self.subTest(key=key), self.assertRaisesRegex(subject.Stop, 'FPM_DRAIN_BOUNDARY_PROOF_INVALID'):
                self.rollback(addition_removal_barrier=invalid)
            self.assertEqual((self.root / ADDED).read_bytes(), self.after[ADDED])
        self.assertTrue(self.rollback()['restored'])

    def test_crash_after_drain_receipt_reuses_approved_boundary_without_new_reload(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('published', REPLACED))
        with self.assertRaises(Crash):
            self.rollback(checkpoint=self.crash_at('addition-removal-ready', ''))
        self.assertEqual(len(self.barriers), 1)
        self.assertTrue((self.root / ADDED).exists())
        self.assertTrue(self.rollback()['restored'])
        self.assertEqual(len(self.barriers), 1)

    def test_referrer_mutation_during_drain_keeps_all_owned_additions(self):
        with self.assertRaises(Crash):
            self.apply(checkpoint=self.crash_at('published', REPLACED))
        def changed(pending):
            proof = self.barrier(pending)
            target = self.root / REPLACED
            peer = target.with_name('peer-same-content.php')
            peer.write_bytes(target.read_bytes())
            peer.chmod(stat.S_IMODE(target.stat().st_mode))
            os.replace(peer, target)
            return proof
        with self.assertRaisesRegex(subject.Stop, 'REFERRER_CHANGED_DURING_DRAIN'):
            self.rollback(addition_removal_barrier=changed)
        self.assertTrue((self.root / ADDED).exists())
        self.assert_protected()

    def test_custom_replacement_order_cannot_publish_views_before_controllers(self):
        controller = 'app/Http/Controllers/ExistingController.php'
        for name in (controller, BLADE):
            self.before[name] = b'old'
            self.after[name] = b'new'
            self.pins[name] = {'before': subject.sha(b'old'), 'after': subject.sha(b'new')}
            self.write(name, b'old')
        with self.assertRaisesRegex(subject.Stop, 'CONTROLLERS_MUST_PRECEDE_VIEWS'):
            self.apply(replacement_order=[BLADE, controller, REPLACED])
        self.assertFalse(any(self.audits.iterdir()))
        self.assert_protected()

    def test_foreign_content_in_staged_directory_is_not_published_or_deleted(self):
        peer_paths = []
        def checkpoint(phase, name):
            if (phase, name) == ('write-ahead', 'app/Workspace'):
                stage = next((self.root / 'app').glob('.go-erp-workspace-*'))
                peer = stage / 'peer.php'
                peer.write_bytes(b'<?php /* unrelated staged peer */')
                peer_paths.append(peer)
        with self.assertRaisesRegex(subject.Stop, 'STAGED_DIRECTORY_NOT_EMPTY'):
            self.apply(checkpoint=checkpoint)
        self.assertFalse((self.root / 'app/Workspace').exists())
        self.assertEqual(peer_paths[0].read_bytes(), b'<?php /* unrelated staged peer */')
        with self.assertRaisesRegex(subject.Stop, 'OWNED_STAGE_DIRECTORY_NOT_EMPTY'):
            self.rollback()
        self.assertEqual(peer_paths[0].read_bytes(), b'<?php /* unrelated staged peer */')
        self.assert_protected()

    def test_foreign_content_in_owned_new_directory_blocks_commit_and_survives_rollback(self):
        def checkpoint(phase, name):
            if (phase, name) == ('published', ADDED):
                self.write('app/Workspace/Nested/peer.php', b'peer bytes')
        with self.assertRaisesRegex(subject.Stop, 'UNREVIEWED_CONTENT_IN_NEW_DIRECTORY'):
            self.apply(checkpoint=checkpoint)
        with self.assertRaisesRegex(subject.Stop, 'OWNED_DIRECTORY_NOT_EMPTY'):
            self.rollback()
        self.assertEqual((self.root / 'app/Workspace/Nested/peer.php').read_bytes(), b'peer bytes')
        self.assert_protected()

    def test_existing_added_target_or_new_directory_is_not_adopted(self):
        path = self.write(ADDED, self.after[ADDED])
        with self.assertRaisesRegex(subject.Stop, 'NEW_DIRECTORY_NOT_ABSENT'):
            self.apply()
        self.assertEqual(path.read_bytes(), self.after[ADDED])
        self.assert_protected()

    def test_boolean_integer_substitution_cannot_rewrite_source_intent_ownership(self):
        tx = self.transaction()
        path = tx.audit / 'intent.json'
        original = path.read_bytes()
        for section in ('plan', 'files'):
            value = subject.parse(original)
            if section == 'plan':
                value['plan']['format'] = True
            else:
                value['files'][REPLACED]['uid'] = False
            path.write_bytes(subject.canonical(value))
            with self.subTest(section=section), self.assertRaisesRegex(subject.Stop, 'INTENT_'):
                self.apply()
            path.write_bytes(original)
        self.assertEqual((self.root / REPLACED).read_bytes(), self.before[REPLACED])
        self.assertFalse((self.root / 'app/Workspace').exists())
        self.assert_protected()

    def test_unplanned_missing_directory_rejected_before_any_source_change(self):
        self.directories = ['app/Workspace/Nested']
        with self.assertRaisesRegex(subject.Stop, 'UNPLANNED_MISSING_DIRECTORY'):
            self.apply()
        self.assertFalse((self.root / 'app/Workspace').exists())
        self.assertEqual((self.root / REPLACED).read_bytes(), self.before[REPLACED])

    def test_unknown_lock_or_scope_source_mode_hardlink_and_symlink_fail_closed(self):
        with self.assertRaisesRegex(subject.Stop, 'HELD_LOCK_NOT_VERIFIED'):
            subject.apply(self.root, self.audits, RELEASE, 0, self.after,
                pins=self.pins, owner_gid=0, new_directories=self.directories, verify_locks=lambda: False)
        for name in ('.env', 'app/../.env', 'storage/x.php', 'database/migrations/other.php'):
            with self.subTest(name=name), self.assertRaises(subject.Stop):
                subject.source_name(name)
        target = self.root / REPLACED
        target.chmod(0o664)
        with self.assertRaisesRegex(subject.Stop, 'FILE_UNSAFE'):
            self.apply()
        target.chmod(0o640)
        os.link(target, self.root / 'app/hardlink.php')
        with self.assertRaisesRegex(subject.Stop, 'FILE_UNSAFE'):
            self.apply()
        (self.root / 'app/hardlink.php').unlink()
        target.unlink()
        os.symlink(self.root / '.env', target)
        with self.assertRaisesRegex(subject.Stop, 'FILE_UNSAFE'):
            self.apply()
        self.assert_protected()

    def test_cache_0775_same_gid_allowed_only_for_exact_cache_chain_and_marker(self):
        self.before[BLADE] = b'old blade\n'
        self.after[BLADE] = b'new blade\n'
        self.pins[BLADE] = {'before': subject.sha(self.before[BLADE]), 'after': subject.sha(self.after[BLADE])}
        self.write(BLADE, self.before[BLADE])
        views = self.root / 'storage/framework/views'
        views.mkdir(parents=True, mode=0o755)
        for path in (self.root / 'storage', self.root / 'storage/framework', views):
            path.chmod(0o775)
        key = subject.hashlib.sha1(('v2' + str(self.root / BLADE)).encode()).hexdigest()
        compiled = views / (key + '.php')
        compiled.write_bytes(b'<?php /* cached */ /**PATH ' + str(self.root / BLADE).encode() + b' ENDPATH**/ ?>\n')
        compiled.chmod(0o664)
        unrelated = views / ('f' * 40 + '.php')
        unrelated.write_bytes(b'<?php /* unrelated compiled */')
        result = self.apply()
        self.assertFalse(compiled.exists())
        self.assertEqual(unrelated.read_bytes(), b'<?php /* unrelated compiled */')
        self.assertTrue(list(Path(result['audit']).glob('compiled-before-*.bin')))
        (self.root / 'app').chmod(0o775)
        with self.assertRaisesRegex(subject.Stop, 'DIRECTORY_UNSAFE'):
            self.apply()

    def test_cache_wrong_gid_or_marker_is_rejected(self):
        views = self.root / 'storage/framework/views'
        views.mkdir(parents=True, mode=0o755)
        for path in (self.root / 'storage', self.root / 'storage/framework', views):
            path.chmod(0o775)
        # This runtime may expose only one mapped GID. Inspect a simulated
        # mismatching lstat result while retaining real directory traversal.
        original_lstat = subject.Path.lstat
        def different_gid(path, *args, **kwargs):
            info = original_lstat(path, *args, **kwargs)
            if path == views:
                values = list(info)
                values[5] = 3
                return os.stat_result(values)
            return info
        with patch.object(subject.Path, 'lstat', autospec=True, side_effect=different_gid):
            with self.assertRaisesRegex(subject.Stop, 'DIRECTORY_UNSAFE'):
                subject.directory(views, 0, 0, cache_root=self.root)
        self.after[BLADE] = b'new blade'
        self.before[BLADE] = b'old blade'
        self.pins[BLADE] = {'before': subject.sha(b'old blade'), 'after': subject.sha(b'new blade')}
        self.write(BLADE, b'old blade')
        key = subject.hashlib.sha1(('v2' + str(self.root / BLADE)).encode()).hexdigest()
        compiled = views / (key + '.php')
        compiled.write_bytes(b'<?php /* unreviewed marker */')
        with self.assertRaisesRegex(subject.Stop, 'COMPILED_MARKER_INVALID'):
            self.apply()
        self.assertEqual((self.root / REPLACED).read_bytes(), self.before[REPLACED])

    def test_after_apply_failure_can_be_rolled_back_without_touching_unrelated_content(self):
        def failed_cache():
            raise RuntimeError('Synthetic cache failure')
        with self.assertRaises(RuntimeError):
            self.apply(after_apply=failed_cache)
        calls = []
        self.assertTrue(self.rollback(after_rollback=lambda: calls.append('reloaded'))['restored'])
        self.assertEqual(calls, ['reloaded'])
        self.assert_protected()

    def test_committed_source_cannot_be_rolled_back_by_unreviewed_api(self):
        self.apply()
        with self.assertRaisesRegex(subject.Stop, 'COMMITTED_ROLLBACK_REQUIRES_REVIEW'):
            self.rollback()
        self.assertEqual((self.root / REPLACED).read_bytes(), self.after[REPLACED])
        self.assert_protected()

    def test_private_audit_symlink_and_source_parent_symlink_denied(self):
        audit_link = self.base / 'audit-link'
        os.symlink(self.audits, audit_link)
        with self.assertRaisesRegex(subject.Stop, 'DIRECTORY_PATH_INVALID'):
            subject.apply(self.root, audit_link, RELEASE, 0, self.after, pins=self.pins,
                owner_gid=0, new_directories=self.directories, verify_locks=self.verify)
        os.symlink(self.root / 'app', self.root / 'routes')
        self.after['routes/unsafe.php'] = b'<?php'
        self.pins['routes/unsafe.php'] = {'before': None, 'after': subject.sha(b'<?php')}
        with self.assertRaisesRegex(subject.Stop, 'DIRECTORY_PATH_INVALID'):
            self.apply()
        self.assert_protected()


if __name__ == '__main__':
    unittest.main()
