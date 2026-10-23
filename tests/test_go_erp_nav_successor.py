"""Real navigation/manifest/hold inode exchanges in an isolated root-owned fixture.

Only the installed control-plane, remote and service observations are synthetic.
No service, network, database or Windows acceptance proof is executed here.
"""
import copy
import fcntl
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import tempfile
import types
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class NavigationIdentityTests(unittest.TestCase):
    def test_payload_changes_only_the_pinned_navigation_source(self):
        m = module('navigation_identity_entry', ROOT / 'deployment/upgrade_go_erp_nav_successor_root.py')
        a = module('navigation_identity_application', ROOT / 'deployment/go_erp_program_application_patch.py')
        s = module('navigation_identity_scanner', ROOT / 'deployment/refresh_desktop_dashboard_server_manifest.py')
        self.assertEqual(a.blob((ROOT / 'deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py').read_bytes()), m.PRIOR_BLOB)
        before = s.scan(ROOT)
        self.assertEqual(s.digest(before), m.OLD_SOURCE)
        self.assertEqual(a.blob((ROOT / m.NAV).read_bytes()), m.BEFORE_NAV)
        payload = ROOT / 'deployment/program-navigation/dashboard-navigation.js'
        self.assertEqual(a.blob(payload.read_bytes()), m.AFTER_NAV)
        after = copy.deepcopy(before)
        after[m.NAV] = s.file_record(payload)
        self.assertEqual(s.digest(after), m.NEW_SOURCE)
        self.assertTrue((ROOT / 'resources/views/admin/employees/regulation.blade.php').is_file())
        self.assertFalse((ROOT / 'resources/views/admin/branch_operations/regulation.blade.php').exists())


@unittest.skipUnless(os.geteuid() == 0, 'actual root-owned inode exchanges')
class NavigationSuccessorTests(unittest.TestCase):
    def setUp(self):
        self.m = module('navigation_successor_fixture', ROOT / 'deployment/upgrade_go_erp_nav_successor_root.py')
        self.a = module('navigation_successor_application', ROOT / 'deployment/go_erp_program_application_patch.py')
        self.g = module('navigation_successor_gate', ROOT / 'deployment/go_erp_program_rollout_gate.py')
        self.u = module('navigation_successor_utility', ROOT / 'deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py')
        self.p = module('navigation_successor_prior', ROOT / 'deployment/upgrade_go_erp_program_watcher_root.py')
        self.s = module('navigation_successor_scanner', ROOT / 'deployment/refresh_desktop_dashboard_server_manifest.py')
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-nav-successor-test-')
        self.base = Path(self.temp.name)
        self.live, self.work, self.package = [self.base / name for name in ('live', 'work', 'package')]
        self.live.mkdir(mode=0o755)
        self.work.mkdir(mode=0o700)
        self.package.mkdir(mode=0o700)
        self.nav = self.live / self.m.NAV
        self.nav.parent.mkdir(parents=True)
        self.before = b'window.navigation={desktopVisible:false};\n'
        self.after = b'window.navigation={desktopVisible:true};\n'
        self.nav.write_bytes(self.before)
        self.nav.chmod(0o640)
        (self.live / 'app').mkdir()
        (self.live / 'app/untouched.php').write_bytes(b'<?php // unchanged fixture\n')
        target = self.package / 'application' / self.m.NAV
        target.parent.mkdir(parents=True)
        target.write_bytes(self.after)
        shutil.copyfile(ROOT / 'deployment/refresh_desktop_dashboard_server_manifest.py',
                        self.package / 'refresh_desktop_dashboard_server_manifest.py')
        self.before_source = self.s.digest(self.s.scan(self.live))
        after_map = self.s.scan(self.live)
        after_map[self.m.NAV] = self.s.file_record(target)
        self.after_source = self.s.digest(after_map)
        self.m.ROOT, self.m.WORK = self.live, self.work
        self.m.OLD_SOURCE, self.m.NEW_SOURCE = self.before_source, self.after_source
        self.m.BEFORE_NAV, self.m.AFTER_NAV = self.a.blob(self.before), self.a.blob(self.after)
        self.a.configure(self.before_source, self.after_source,
                         {self.m.NAV: (self.m.BEFORE_NAV, self.m.AFTER_NAV)})
        (self.live / 'deployment').mkdir()
        self.manifest = self.live / self.a.MANIFEST
        self.manifest.write_text(json.dumps({'version': 1, 'expected_head': 'preserved-cohort',
            'before_source': {'historical': True}, 'after_source': self.before_source,
            'sources': [], 'protected': [{'path': self.m.NAV, 'blob': self.m.BEFORE_NAV}],
            'migrations': ['untouched-migration']}))
        self.old_manifest = self.manifest.read_bytes()
        self.state = {'counter': 36, 'desired': {'commit': self.m.OLD_CANDIDATE,
            'inventorySha256': self.m.OLD_INVENTORY, 'version': self.m.OLD_VERSION,
            'pushed': True, 'status': 'waiting-ci'}, 'published': None,
            'last_remote_sha': self.m.OLD_CANDIDATE, 'sequence_floor': 10}
        self.control_paths = {}
        for key, value in [('config', b'{"base_revision":"unchanged"}\n'),
                           ('state', self.m.canonical(self.state)), ('unit', b'[Service]\nExecStart=unchanged\n')]:
            path = self.work / (key + '.control')
            path.write_bytes(value)
            path.chmod(0o600)
            self.control_paths[key] = path
        self.controls_before = {key: path.read_bytes() for key, path in self.control_paths.items()}
        self.control_inodes = {key: path.stat().st_ino for key, path in self.control_paths.items()}
        self.old_expected = self.g.program_gate_expected(self.m.CORE, self.before_source, self.m.OLD_INVENTORY)
        self.expected = self.g.program_gate_expected(self.m.CORE, self.after_source, self.m.NEW_INVENTORY)
        self.hold_path = self.work / 'program-rollout-hold.json'
        self.g.program_gate_write(self.hold_path, self.m.hold_value(self.old_expected))
        self.old_hold = self.hold_path.read_bytes()
        self.inodes = {name: path.stat().st_ino for name, path in [('nav', self.nav), ('manifest', self.manifest), ('hold', self.hold_path)]}
        self.public = None
        self.remote_revision = self.m.OLD_CANDIDATE
        self.verify_calls = 0
        self.candidate_calls = []
        self.ctx = types.SimpleNamespace(package=self.package, application=self.a, gate=self.g,
            utility=self.u, owner=types.SimpleNamespace(pw_uid=0, pw_gid=0), expected=self.expected,
            old_expected=self.old_expected, base=object())
        self.ctx.prior = types.SimpleNamespace(held_lock=self.p.held_lock,
            assert_quiescent=lambda: None, require_no_pending=lambda *args: None)
        self.ctx.controls = self.controls
        self.ctx.publication = lambda: self.public
        self.ctx.remote = lambda: self.remote_revision
        self.ctx.candidate = self.candidate
        self.ctx.state_guard = types.MethodType(self.m.Context.state_guard, self.ctx)
        self.lock_fd = os.open(self.work / 'watcher.lock', os.O_RDWR | os.O_CREAT | os.O_EXCL, 0o600)

    def tearDown(self):
        os.close(self.lock_fd)
        self.temp.cleanup()

    def controls(self):
        return ({key: path.read_bytes() for key, path in self.control_paths.items()},
                copy.deepcopy(self.state))

    def candidate(self, revision, inventory, minimum):
        self.candidate_calls.append((revision, inventory, minimum))
        if revision == self.m.OLD_CANDIDATE:
            self.assertEqual((inventory, minimum), (self.m.OLD_INVENTORY, 36))
            return 36
        self.assertEqual((revision, inventory, minimum), ('7' * 40, self.m.NEW_INVENTORY, 37))
        return 37

    def verify(self):
        self.verify_calls += 1

    def run_transaction(self):
        return self.m.transaction(self.ctx, self.lock_fd, self.verify, *self.controls())

    def execute(self, mode='prepare', receipt=None):
        # Simulate the next process invocation: the direct transaction's open
        # descriptor must release its lock before execute opens its own one.
        fcntl.flock(self.lock_fd, fcntl.LOCK_UN)
        with mock.patch.object(self.m, 'Context', return_value=self.ctx), \
             mock.patch.object(self.m.socket, 'gethostname', return_value='srv1836524'):
            return self.m.execute(self.package, mode, receipt)

    def audits(self):
        return sorted(self.work.glob(self.m.PREFIX + '*'))

    def assert_controls_unchanged(self):
        self.assertEqual(self.controls()[0], self.controls_before)
        self.assertEqual({key: path.stat().st_ino for key, path in self.control_paths.items()}, self.control_inodes)

    def assert_rolled_back(self):
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assertEqual(self.manifest.read_bytes(), self.old_manifest)
        self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
        for key, path in [('nav', self.nav), ('manifest', self.manifest), ('hold', self.hold_path)]:
            self.assertEqual(path.stat().st_ino, self.inodes[key])
        self.assert_controls_unchanged()

    def successor_state(self):
        self.state['counter'] = 37
        self.state['desired'].update(commit='7' * 40, inventorySha256=self.m.NEW_INVENTORY,
                                     version='0.4.4-preview.37')
        self.state['last_remote_sha'] = self.remote_revision = '7' * 40
        self.control_paths['state'].write_bytes(self.m.canonical(self.state))

    def test_navigation_and_optional_manifest_commit_under_continuously_present_new_hold(self):
        old_exchange = self.a.rename
        seen = []
        def exchange(*args):
            seen.append(self.g.program_gate_json(self.hold_path))
            return old_exchange(*args)
        with mock.patch.object(self.a, 'rename', side_effect=exchange):
            result = self.run_transaction()
        self.assertTrue(result['ok'])
        self.assertEqual(result['worker_request'], 'candidate')
        self.assertTrue(result['publication_held'])
        self.assertEqual(len(seen), 2)
        self.assertTrue(all(value == self.m.hold_value(self.expected) for value in seen))
        self.assertEqual(self.nav.read_bytes(), self.after)
        self.assertEqual(self.nav.stat().st_mode & 0o777, 0o640)
        manifest = json.loads(self.manifest.read_bytes())
        self.assertEqual(manifest['after_source'], self.after_source)
        self.assertEqual(manifest['expected_head'], 'preserved-cohort')
        self.assertEqual(manifest['migrations'], ['untouched-migration'])
        self.assertTrue((self.audits()[0] / 'completed.json').is_file())
        self.assertFalse(list(self.base.glob('.go-erp-program-patch-*')))
        self.assert_controls_unchanged()

    def test_absent_manifest_directory_stays_absent(self):
        self.manifest.unlink()
        self.manifest.parent.rmdir()
        self.run_transaction()
        self.assertFalse(self.manifest.parent.exists())
        self.assertEqual(self.nav.read_bytes(), self.after)
        self.assert_controls_unchanged()

    def test_failure_before_hold_exchange_restores_exact_original_inodes(self):
        with mock.patch.object(self.u, 'exchange', side_effect=OSError('synthetic exchange fault')):
            with self.assertRaises(OSError):
                self.run_transaction()
        self.assert_rolled_back()
        self.assertTrue((self.audits()[0] / 'rolled-back.json').is_file())

    def test_failure_after_new_hold_before_source_exchange_restores_exact_inodes(self):
        with mock.patch.object(self.a.ApplicationPatch, 'apply', side_effect=OSError('synthetic apply fault')):
            with self.assertRaises(OSError):
                self.run_transaction()
        self.assert_rolled_back()

    def test_partial_source_exchange_is_reversed_before_old_hold_returns(self):
        original = self.a.rename
        calls = 0
        seen = []
        def exchange(*args):
            nonlocal calls
            calls += 1
            seen.append(self.g.program_gate_json(self.hold_path))
            if calls == 2:
                raise OSError('synthetic second source exchange fault')
            return original(*args)
        with mock.patch.object(self.a, 'rename', side_effect=exchange):
            with self.assertRaises(OSError):
                self.run_transaction()
        self.assertGreaterEqual(calls, 3)
        self.assertTrue(all(value == self.m.hold_value(self.expected) for value in seen))
        self.assert_rolled_back()

    def test_authorized_old_hold_is_never_superseded(self):
        hold = self.m.hold_value(self.old_expected)
        hold['authorization'] = {'candidate': self.m.OLD_CANDIDATE}
        self.g.program_gate_write(self.hold_path, hold)
        before = self.hold_path.read_bytes()
        with self.assertRaisesRegex(self.m.Stop, 'OLD_HOLD_DIFFERS_OR_AUTHORIZED'):
            self.run_transaction()
        self.assertEqual(self.hold_path.read_bytes(), before)
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assertFalse(self.audits())
        self.assert_controls_unchanged()

    def test_canonical_or_state_publication_of_old_candidate_refuses_before_source_changes(self):
        for channel in ('canonical', 'state'):
            with self.subTest(channel=channel):
                if channel == 'canonical': self.public = {'source_revision': self.m.OLD_CANDIDATE}
                else:
                    self.public = None
                    self.state['published'] = {'commit': self.m.OLD_CANDIDATE}
                with self.assertRaisesRegex(self.m.Stop, 'OLD_CANDIDATE_ALREADY_PUBLISHED'):
                    self.run_transaction()
                self.assertEqual(self.nav.read_bytes(), self.before)
                self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
                self.assertFalse(self.audits())

    def test_committed_retry_accepts_desired37_without_control_plane_rewrite(self):
        self.run_transaction()
        self.successor_state()
        controls = self.controls()[0]
        hold = self.hold_path.read_bytes()
        inodes = (self.nav.stat().st_ino, self.hold_path.stat().st_ino)
        result = self.execute()
        self.assertTrue(result['already_current'])
        self.assertFalse(result['application_contents_changed'])
        self.assertEqual(result['counter'], 37)
        self.assertEqual(len(self.audits()), 1)
        self.assertEqual(self.controls()[0], controls)
        self.assertEqual(self.hold_path.read_bytes(), hold)
        self.assertEqual((self.nav.stat().st_ino, self.hold_path.stat().st_ino), inodes)

    def test_inner_commit_survives_outer_receipt_failure_and_is_recovered_on_retry(self):
        original = self.u.json_at
        def write(directory, name, value):
            if name == 'completed.json': raise OSError('synthetic final receipt fault')
            return original(directory, name, value)
        with mock.patch.object(self.u, 'json_at', side_effect=write):
            with self.assertRaisesRegex(self.m.Stop, 'COMMIT_REQUIRES_REVIEW') as stopped:
                self.run_transaction()
        self.assertFalse(stopped.exception.rollback_complete)
        self.assertEqual(self.nav.read_bytes(), self.after)
        self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold_value(self.expected))
        audit = self.audits()[0]
        self.assertTrue((audit / 'application/application-committed.json').is_file())
        self.assertFalse((audit / 'completed.json').exists())
        self.successor_state()
        controls = self.controls()[0]
        result = self.execute()
        self.assertTrue(result['already_current'])
        self.assertTrue(self.g.program_gate_json(audit / 'completed.json')['recovered_commit'])
        self.assertEqual(self.controls()[0], controls)

    def test_uncommitted_pending_source_is_not_silently_adopted(self):
        self.run_transaction()
        audit = self.audits()[0]
        (audit / 'completed.json').unlink()
        (audit / 'application/application-committed.json').unlink()
        with self.assertRaisesRegex(self.m.Stop, 'PENDING_TRANSACTION_REQUIRES_REVIEW'):
            self.execute()
        self.assertFalse((audit / 'completed.json').exists())

    def test_old_source_receipt_cannot_authorize_successor(self):
        self.run_transaction()
        self.successor_state()
        old_receipt = {'format': 1, 'kind': 'go-erp-program-acceptance', **self.old_expected,
            'candidate_revision': self.m.OLD_CANDIDATE, 'version': self.m.OLD_VERSION,
            'installer': {}, 'ci': {}, 'employee_evidence': {}}
        path = self.work / 'old-acceptance.json'
        self.g.program_gate_write(path, old_receipt)
        self.ctx.worker = types.SimpleNamespace(verify_proof=mock.Mock(side_effect=AssertionError('old proof must not run')))
        self.ctx.publisher = object()
        with self.assertRaisesRegex(self.g.ProgramGateError, 'RECEIPT_BINDING_MISMATCH'):
            self.execute('publish', path)
        self.ctx.worker.verify_proof.assert_not_called()
        self.assertIsNone(self.g.program_gate_json(self.hold_path)['authorization'])
        self.assertFalse((self.live / 'storage').exists())

    def test_exact_successor_receipt_authorizes37_and_durable_publication_makes_retry_inert(self):
        # Synthetic acceptance records exercise only the entry/gate contract;
        # the separate gate suite executes the installed full CI verifier.
        cases = module('navigation_successor_synthetic_acceptance', ROOT / 'tests/test_go_erp_program_rollout_gate.py')
        self.run_transaction()
        self.successor_state()
        controls = self.controls()[0]
        desired = copy.deepcopy(self.state['desired'])
        ci = self.work / 'synthetic-ci'
        ci.mkdir(mode=0o700)
        release = {'version': desired['version'], 'source_revision': desired['commit'],
            'source': self.after_source, 'sequence': 101,
            'installer': {'sha256': 'a' * 64, 'bytes': 1234}}
        evidence = self.work / 'synthetic-employee.json'
        self.g.program_gate_write(evidence, cases.employee(self.expected, desired))
        receipt = {'format': 1, 'kind': 'go-erp-program-acceptance', **self.expected,
            'candidate_revision': desired['commit'], 'version': desired['version'],
            'installer': release['installer'], 'ci': {'directory': str(ci), 'run_id': 500, 'run_attempt': 1},
            'employee_evidence': {'path': str(evidence), 'sha256': self.m.digest(evidence.read_bytes())}}
        receipt_path = self.work / 'synthetic-acceptance.json'
        self.g.program_gate_write(receipt_path, receipt)
        verifier = mock.Mock(return_value=({}, release))
        self.ctx.worker = types.SimpleNamespace(verify_proof=verifier)
        # This only reads a fixture feed. It is not a publisher implementation
        # and the entry itself never writes a canonical publication.
        self.ctx.publisher = types.SimpleNamespace(read_json=lambda path, root_owned: json.loads(path.read_bytes()))
        report = self.execute('publish', receipt_path)
        self.assertEqual(report['worker_request'], 'authorized-publication')
        self.assertEqual(report['candidate'], '7' * 40)
        verifier.assert_called_once_with(ci, {'id': 500, 'run_attempt': 1}, desired, self.ctx.publisher)
        authorization = self.g.program_gate_json(self.hold_path)['authorization']
        self.assertEqual(authorization['candidate'], '7' * 40)
        self.assertEqual(authorization['version'], '0.4.4-preview.37')
        self.assertEqual(authorization['release']['source'], self.after_source)
        self.assertEqual(self.controls()[0], controls)
        self.assertFalse((self.live / 'storage').exists())

        # Emulate the already separately-tested worker's durable publication
        # records, then use the real retire/completed checks without bypassing
        # their retained receipt/evidence hashes or canonical feed equality.
        self.state['desired']['status'] = 'published'
        self.state['published'] = {'commit': desired['commit'], 'inventorySha256': self.m.NEW_INVENTORY,
                                   'release': release}
        self.state['published_history'] = [release]
        self.state['version_floor'] = release['version']
        self.state['sequence_floor'] = release['sequence']
        self.control_paths['state'].write_bytes(self.m.canonical(self.state))
        state_directory = self.work / 'state'
        state_directory.mkdir(mode=0o700)
        self.g.program_gate_write(state_directory / 'current.json', self.state)
        feed = self.live / 'storage/app/desktop-updates/current.json'
        feed.parent.mkdir(parents=True)
        feed.write_bytes(self.m.canonical(release))
        self.public = release
        self.assertTrue(self.g.program_gate_retire(self.work, self.live, self.ctx.publisher))
        self.assertFalse(self.hold_path.exists())
        self.assertTrue((self.work / ('program-rollout-completed-' + self.m.CORE + '.json')).is_file())
        controls = self.controls()[0]
        for mode in ('prepare', 'publish'):
            with self.subTest(mode=mode):
                completed = self.execute(mode, receipt_path if mode == 'publish' else None)
                self.assertEqual(completed['kind'], 'go-erp-program-already-completed')
                self.assertEqual(completed['worker_request'], 'none')
                self.assertTrue(completed['resume_previous_timer'])
                self.assertFalse(completed['publication_held'])
                self.assertEqual(self.controls()[0], controls)
                self.assertFalse(self.hold_path.exists())
        self.assertEqual(verifier.call_count, 1)

    def test_recovery_refuses_corrupt_intent_inner_commit_or_completion_identity(self):
        self.run_transaction()
        audit = self.audits()[0]
        for relative, key, wrong, reason in [
                ('intent.json', 'nav_after', 'f' * 40, 'JOURNAL_IDENTITY_INVALID'),
                ('intent.json', 'old_candidate', 'e' * 40, 'JOURNAL_IDENTITY_INVALID'),
                ('application/application-committed.json', 'source', self.before_source, 'INNER_COMMIT_INVALID'),
                ('application/application-committed.json', 'database_changed', True, 'INNER_COMMIT_INVALID'),
                ('completed.json', 'inventory_sha256', 'd' * 64, 'COMPLETION_RECORD_INVALID'),
                ('completed.json', 'source', self.before_source, 'COMPLETION_RECORD_INVALID')]:
            path = audit / relative
            original = path.read_bytes()
            value = json.loads(original)
            value[key] = wrong
            self.g.program_gate_write(path, value)
            with self.subTest(record=relative, key=key), self.assertRaisesRegex(self.m.Stop, reason):
                self.execute()
            self.assertEqual(self.nav.read_bytes(), self.after)
            self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold_value(self.expected))
            self.g.program_gate_write(path, json.loads(original))
        self.assert_controls_unchanged()

    def test_successor_retry_refuses_changed_config_even_with_valid_source_and_receipts(self):
        self.run_transaction()
        self.control_paths['config'].write_bytes(b'{"unexpected_config":true}\n')
        changed = self.control_paths['config'].read_bytes()
        with self.assertRaisesRegex(self.m.Stop, 'JOURNAL_CONTROLS_DIFFER'):
            self.execute()
        self.assertEqual(self.control_paths['config'].read_bytes(), changed)
        self.assertEqual(self.nav.read_bytes(), self.after)
        self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold_value(self.expected))

    def test_same_byte_old_hold_inode_replacement_is_detected_before_exchange(self):
        original = self.a.ApplicationPatch.prepare
        replacement = []
        def prepare(patch):
            result = original(patch)
            self.g.program_gate_write(self.hold_path, self.m.hold_value(self.old_expected))
            replacement.append(self.hold_path.stat().st_ino)
            return result
        with mock.patch.object(self.a.ApplicationPatch, 'prepare', new=prepare):
            with self.assertRaisesRegex(self.m.Stop, 'OLD_HOLD_CHANGED'):
                self.run_transaction()
        self.assertEqual(self.hold_path.stat().st_ino, replacement[0])
        self.assertNotEqual(replacement[0], self.inodes['hold'])
        self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assert_controls_unchanged()

    def test_concurrent_control_change_keeps_new_hold_and_requires_review(self):
        original = self.a.ApplicationPatch.apply
        def apply(patch):
            original(patch)
            self.control_paths['unit'].write_bytes(b'[Service]\nExecStart=concurrent-change\n')
        with mock.patch.object(self.a.ApplicationPatch, 'apply', new=apply):
            with self.assertRaisesRegex(self.m.Stop, 'ROLLBACK_REQUIRES_REVIEW') as stopped:
                self.run_transaction()
        self.assertFalse(stopped.exception.rollback_complete)
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assertEqual(self.manifest.read_bytes(), self.old_manifest)
        self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold_value(self.expected))
        self.assertIn(b'concurrent-change', self.control_paths['unit'].read_bytes())
        self.assertFalse((self.audits()[0] / 'rolled-back.json').exists())

    def test_foreign_hold_written_after_source_apply_is_preserved_during_failed_rollback(self):
        original = self.a.ApplicationPatch.apply
        foreign = {**self.m.hold_value(self.expected), 'authorization': {'concurrent': True}}
        def apply(patch):
            original(patch)
            self.g.program_gate_write(self.hold_path, foreign)
        with mock.patch.object(self.a.ApplicationPatch, 'apply', new=apply):
            with self.assertRaisesRegex(self.m.Stop, 'ROLLBACK_REQUIRES_REVIEW') as stopped:
                self.run_transaction()
        self.assertFalse(stopped.exception.rollback_complete)
        self.assertEqual(self.g.program_gate_json(self.hold_path), foreign)
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assert_controls_unchanged()

    def test_unknown_or_sensitive_failure_text_is_never_public(self):
        private = 'account@example.test password=fixture-private-value /private/account.json'
        for error in (RuntimeError(private), self.m.Stop(private)):
            output = io.StringIO()
            with self.subTest(type=type(error).__name__), \
                 mock.patch.object(self.m, 'execute', side_effect=error), \
                 mock.patch('sys.stdout', output):
                code = self.m.main(['--package', str(self.package), '--prepare'])
            self.assertEqual(code, 1)
            report = json.loads(output.getvalue())
            self.assertEqual(report['reason'], 'GO_ERP_NAV_SUCCESSOR_PRIVATE_FAILURE')
            self.assertFalse(report['credentials_disclosed'])
            self.assertNotIn(private, output.getvalue())

    def test_uppercase_foreign_error_is_private_and_nonboolean_change_flags_are_normalized(self):
        for error in (ValueError('GO_ERP_NAV_SUCCESSOR_OLD_HOLD_CHANGED'),
                      RuntimeError('SYNTHETIC_PRIVATE_ACCOUNT_IDENTIFIER')):
            error.application_contents_changed = 'private-account-value'
            error.rollback_complete = 'private-status-value'
            output = io.StringIO()
            with self.subTest(type=type(error).__name__), \
                 mock.patch.object(self.m, 'execute', side_effect=error), mock.patch('sys.stdout', output):
                code = self.m.main(['--package', str(self.package), '--prepare'])
            report = json.loads(output.getvalue())
            self.assertEqual(code, 78)
            self.assertEqual(report['reason'], 'GO_ERP_NAV_SUCCESSOR_PRIVATE_FAILURE')
            self.assertEqual(report['application_contents_changed'], 'unknown')
            self.assertIs(report['rollback_complete'], False)
            self.assertNotIn('private-account-value', output.getvalue())
            self.assertNotIn('private-status-value', output.getvalue())


WRAPPER_CASES = module('navigation_successor_real_wrapper_cases', ROOT / 'tests/test_go_erp_program_wrapper.py')


@unittest.skipUnless(os.geteuid() == 0, 'the real shell explicitly requires root')
class NavigationWrapperTests(WRAPPER_CASES.ProgramWrapperTests):
    """Run all real shell failure/lifecycle cases against the successor wrapper."""
    def invoke(self, *args, **kwargs):
        git = WRAPPER_CASES.GIT.replace(
            ':deployment/upgrade_go_erp_program_watcher_root.py',
            ':deployment/upgrade_go_erp_nav_successor_root.py')
        self.assertNotEqual(git, WRAPPER_CASES.GIT)
        with mock.patch.object(WRAPPER_CASES, 'WRAPPER', ROOT / 'deployment/run_go_erp_nav_successor_root.sh'), \
             mock.patch.object(WRAPPER_CASES, 'GIT', git):
            return super().invoke(*args, **kwargs)

    def test_completed_prepare_and_publish_preserve_previously_inactive_timer(self):
        # A completed publication command is an explicit request to resume the
        # automatic timer, even when an earlier preparation left it paused.
        # A preparation-only retry preserves that prior pause.
        for mode in ('--prepare', '--publish'):
            for prior in ('inactive', 'failed'):
                with self.subTest(mode=mode, prior=prior):
                    completed, state, calls, stages = self.invoke(self.completed_result(), mode, prior)
                    self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
                    self.assertEqual(state, {'timer': 'active' if mode == '--publish' else 'inactive',
                                             'service': 'inactive'})
                    self.assert_no_worker(calls)
                    self.assertEqual(calls.count(['start', 'go-erp-live-updates.timer']),
                                     1 if mode == '--publish' else 0)
                    self.assertEqual(stages, [])
                    self.assertIn('GO_ERP_PROGRAM_ALREADY_COMPLETED_NO_WORKER', completed.stdout)
                    self.assertNotIn('GO_ERP_TIMER_LEFT_PAUSED_PUBLICATION_HELD', completed.stderr)


if __name__ == '__main__': unittest.main()
