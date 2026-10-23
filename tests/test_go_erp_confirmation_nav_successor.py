"""Navigation-only inode exchanges preserve all17 approved payment files.

The original native rollout transaction and shell cases are reused with a
separate later-confirmation cohort. No live service, network or DB is touched.
"""
import copy
import importlib.util
import json
import os
from pathlib import Path
import types
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


CASES = module('confirmation_original_navigation_cases', ROOT / 'tests/test_go_erp_nav_successor.py')


@unittest.skipUnless(os.geteuid() == 0, 'actual root-owned inode exchanges')
class ConfirmationNavigationSuccessorTests(CASES.NavigationSuccessorTests):
    def setUp(self):
        super().setUp()
        self.old_entry, self.old_helper = self.m, self.a
        self.m = module('confirmation_navigation_entry', ROOT / 'deployment/upgrade_go_erp_confirmation_nav_successor_root.py')
        self.a = module('confirmation_navigation_helper', ROOT / 'deployment/go_erp_confirmation_nav_application_patch.py')
        original_pins = self.m.PAYMENT_PINS
        self.assertEqual(len(original_pins), 17)
        self.payment_after, self.payment_before = {}, {}
        for number, (name, (old, _)) in enumerate(original_pins.items()):
            path = self.live / name
            path.parent.mkdir(parents=True, exist_ok=True)
            before = ('/* original payment fixture %s */\n' % number).encode() if old is not None else None
            after = ('/* approved confirmation fixture %s */\n' % number).encode()
            path.write_bytes(after); path.chmod(0o640)
            self.payment_before[name], self.payment_after[name] = before, after
        pins = {name: (self.a.blob(self.payment_before[name]) if self.payment_before[name] is not None else None,
                       self.a.blob(data)) for name, data in self.payment_after.items()}
        current_map = self.s.scan(self.live)
        old_map = copy.deepcopy(current_map)
        for name, data in self.payment_before.items():
            if data is None: old_map.pop(name)
            else: old_map[name] = {'blob': self.a.blob(data), 'sha256': self.m.digest(data)}
        old_source = self.s.digest(old_map)
        self.before_source = self.s.digest(current_map)
        after_map = copy.deepcopy(current_map)
        after_map[self.m.NAV] = self.s.file_record(self.package / 'application' / self.m.NAV)
        self.after_source = self.s.digest(after_map)
        self.m.ROOT, self.m.WORK = self.live, self.work
        self.m.OLD_SOURCE, self.m.BEFORE_SOURCE, self.m.NEW_SOURCE = old_source, self.before_source, self.after_source
        self.m.BEFORE_NAV, self.m.AFTER_NAV = self.a.blob(self.before), self.a.blob(self.after)
        self.m.PAYMENT_PINS = pins
        self.a.PAYMENT_BASE_SOURCE, self.a.PAYMENT_SOURCE, self.a.PAYMENT_PINS = old_source, self.before_source, pins
        self.a.configure(self.before_source, self.after_source,
                         {self.m.NAV: (self.m.BEFORE_NAV, self.m.AFTER_NAV)})
        manifest = json.loads(self.manifest.read_bytes())
        manifest['after_source'] = old_source
        for index, (name, (old, _)) in enumerate(pins.items()):
            if old is None: continue
            if index % 2:
                manifest['sources'].append({'path': name, 'before_blob': 'e' * 40, 'after_blob': old, 'phase': 3})
            else: manifest['protected'].append({'path': name, 'blob': old})
        manifest['protected'].append({'path': 'app/untouched.php', 'blob': current_map['app/untouched.php']['blob']})
        self.manifest.write_bytes(self.m.canonical(manifest))
        self.old_manifest = self.manifest.read_bytes()
        self.old_expected = self.g.program_gate_expected(self.m.CORE, old_source, self.m.OLD_INVENTORY)
        self.expected = self.g.program_gate_expected(self.m.CORE, self.after_source, self.m.NEW_INVENTORY)
        self.g.program_gate_write(self.hold_path, self.m.hold_value(self.old_expected))
        self.old_hold = self.hold_path.read_bytes()
        self.inodes = {name: path.stat().st_ino for name, path in [('nav', self.nav), ('manifest', self.manifest), ('hold', self.hold_path)]}
        self.payment_inodes = {name: (self.live / name).stat().st_ino for name in pins}
        self.ctx.application, self.ctx.expected, self.ctx.old_expected = self.a, self.expected, self.old_expected
        self.ctx.state_guard = types.MethodType(self.m.Context.state_guard, self.ctx)
        self.controls_before = self.controls()[0]

    def controls(self):
        observations, state = super().controls()
        if hasattr(self, 'payment_after'):
            rows = []
            for name, wanted in self.payment_after.items():
                path = self.live / name
                info = path.stat()
                data = path.read_bytes()
                self.m.need(data == wanted, 'APPROVED_PAYMENT_FILE_DIFFERS')
                rows.append({'path': name, 'blob': self.a.blob(data), 'identity': self.u.exact_key(info)})
            observations['payment'] = self.m.canonical(rows)
        return observations, state

    def assert_payments_unchanged(self):
        self.assertEqual(len(self.payment_after), 17)
        for name, data in self.payment_after.items():
            path = self.live / name
            self.assertEqual(path.read_bytes(), data)
            self.assertEqual(path.stat().st_ino, self.payment_inodes[name])
            self.assertEqual(path.stat().st_mode & 0o777, 0o640)

    def assert_controls_unchanged(self):
        super().assert_controls_unchanged()
        self.assert_payments_unchanged()

    def remove_manifest(self):
        self.manifest.unlink(); self.manifest.parent.rmdir()

    def test_absent_manifest_directory_stays_absent(self):
        self.remove_manifest()
        renamed = []
        original = self.a.rename
        def rename(left_fd, left, right_fd, right, flags):
            renamed.append(str(Path(os.readlink('/proc/self/fd/' + str(right_fd))) / right))
            self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold_value(self.expected))
            self.assert_payments_unchanged()
            return original(left_fd, left, right_fd, right, flags)
        with mock.patch.object(self.a, 'rename', side_effect=rename):
            report = self.run_transaction()
        self.assertTrue(report['ok'])
        self.assertEqual(renamed, [str(self.nav)])
        self.assertFalse(self.manifest.parent.exists())
        self.assertEqual(self.nav.read_bytes(), self.after)
        receipt = self.g.program_gate_json(self.audits()[0] / 'application/application-committed.json')
        self.assertIs(receipt['manifest_refreshed'], False)
        self.assertIs(receipt['manifest_preserved_absent'], True)
        self.successor_state()
        controls = self.controls()[0]
        self.assertTrue(self.execute()['already_current'])
        self.assertEqual(self.controls()[0], controls)
        self.assertFalse(self.manifest.parent.exists())
        self.assertTrue(self.a.verify_current(self.live, self.package, 0, 0, self.lock_fd)['manifest_preserved_absent'])
        self.assert_payments_unchanged()

    def test_absent_manifest_rollback_restores_navigation_and_hold_without_creating_metadata(self):
        self.remove_manifest()
        original = self.a.ApplicationPatch.apply
        def apply(patch):
            original(patch)
            raise OSError('synthetic error after absent-manifest application')
        with mock.patch.object(self.a.ApplicationPatch, 'apply', new=apply):
            with self.assertRaises(OSError): self.run_transaction()
        self.assertFalse(self.manifest.parent.exists())
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assertEqual(self.nav.stat().st_ino, self.inodes['nav'])
        self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
        self.assertEqual(self.hold_path.stat().st_ino, self.inodes['hold'])
        self.assert_controls_unchanged()

    def test_absent_manifest_file_preserves_existing_deployment_directory(self):
        self.manifest.unlink()
        directory_inode = self.manifest.parent.stat().st_ino
        self.run_transaction()
        self.assertFalse(self.manifest.exists())
        self.assertEqual(self.manifest.parent.stat().st_ino, directory_inode)
        summary = self.a.verify_current(self.live, self.package, 0, 0, self.lock_fd)
        self.assertIs(summary['manifest_preserved_absent'], True)
        self.assert_controls_unchanged()

    def test_current_validation_does_not_adopt_or_rewrite_stale_manifest_created_later(self):
        self.remove_manifest()
        self.run_transaction()
        self.manifest.parent.mkdir()
        self.manifest.write_bytes(self.old_manifest)
        foreign_inode = self.manifest.stat().st_ino
        with self.assertRaisesRegex(self.a.Stop, 'PAYMENT_MANIFEST_PIN_UNREVIEWED'):
            self.a.verify_current(self.live, self.package, 0, 0, self.lock_fd)
        self.assertEqual(self.manifest.read_bytes(), self.old_manifest)
        self.assertEqual(self.manifest.stat().st_ino, foreign_inode)
        self.assert_payments_unchanged()

    def test_absent_manifest_remains_absent_when_outer_commit_receipt_is_recovered(self):
        self.remove_manifest()
        original = self.u.json_at
        def write(directory, name, value):
            if name == 'completed.json': raise OSError('synthetic final receipt fault')
            return original(directory, name, value)
        with mock.patch.object(self.u, 'json_at', side_effect=write):
            with self.assertRaisesRegex(self.m.Stop, 'COMMIT_REQUIRES_REVIEW') as stopped:
                self.run_transaction()
        self.assertFalse(stopped.exception.rollback_complete)
        self.successor_state()
        self.assertTrue(self.execute()['already_current'])
        self.assertFalse(self.manifest.parent.exists())
        self.assertTrue(self.g.program_gate_json(self.audits()[0] / 'completed.json')['recovered_commit'])
        self.assert_payments_unchanged()

    def test_absent_inner_commit_cannot_flip_mode_when_finishing_an_incomplete_audit(self):
        self.remove_manifest()
        self.run_transaction()
        audit = self.audits()[0]
        (audit / 'completed.json').unlink()
        receipt = audit / 'application/application-committed.json'
        value = self.g.program_gate_json(receipt)
        value.update(manifest_refreshed=True, manifest_preserved_absent=False)
        self.g.program_gate_write(receipt, value)
        with self.assertRaisesRegex(self.m.Stop, 'PENDING_TRANSACTION_REQUIRES_REVIEW') as stopped:
            self.execute()
        self.assertFalse(stopped.exception.rollback_complete)
        self.assertTrue(audit.is_dir())
        self.assertFalse((audit / 'completed.json').exists())
        self.assertFalse(self.manifest.parent.exists())
        self.assertEqual(self.nav.read_bytes(), self.after)
        self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold_value(self.expected))
        self.assert_controls_unchanged()

    def test_present_inner_commit_cannot_be_retried_after_manifest_was_removed(self):
        self.run_transaction()
        audit = self.audits()[0]
        completion = (audit / 'completed.json').read_bytes()
        self.manifest.unlink()
        with self.assertRaisesRegex(self.m.Stop, 'INNER_COMMIT_INVALID'):
            self.execute()
        self.assertEqual((audit / 'completed.json').read_bytes(), completion)
        self.assertFalse(self.manifest.exists())
        self.assertEqual(self.nav.read_bytes(), self.after)
        self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold_value(self.expected))
        self.assert_controls_unchanged()

    def test_present_manifest_history_change_cannot_reuse_exact_committed_blob_proof(self):
        self.run_transaction()
        audit = self.audits()[0]
        completion = (audit / 'completed.json').read_bytes()
        value = json.loads(self.manifest.read_bytes())
        value['expected_head'] = 'external-history-only-change'
        self.manifest.write_bytes(self.m.canonical(value))
        changed = self.manifest.read_bytes()
        changed_inode = self.manifest.stat().st_ino
        # Source and all17 approved payment/header pins remain valid. Only
        # the outer transaction's exact manifest blob proof must reject this.
        self.assertTrue(self.a.verify_current(self.live, self.package, 0, 0, self.lock_fd)['already_current'])
        with self.assertRaisesRegex(self.m.Stop, 'INNER_COMMIT_INVALID'):
            self.execute()
        self.assertEqual(self.manifest.read_bytes(), changed)
        self.assertEqual(self.manifest.stat().st_ino, changed_inode)
        self.assertEqual((audit / 'completed.json').read_bytes(), completion)
        self.assertEqual(self.nav.read_bytes(), self.after)
        self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold_value(self.expected))
        self.assert_controls_unchanged()

    def test_present_manifest_adds_four_new_known_paths_but_never_exchanges_payment_code(self):
        moved = []
        original = self.a.rename
        def rename(left_fd, left, right_fd, right, flags):
            moved.append(str(Path(os.readlink('/proc/self/fd/' + str(right_fd))) / right))
            self.assert_payments_unchanged()
            return original(left_fd, left, right_fd, right, flags)
        with mock.patch.object(self.a, 'rename', side_effect=rename): self.run_transaction()
        self.assertEqual(set(moved), {str(self.nav), str(self.manifest)})
        value = json.loads(self.manifest.read_bytes())
        self.assertEqual(value['after_source'], self.after_source)
        self.assertEqual(sum(old is None for old, _ in self.m.PAYMENT_PINS.values()), 4)
        for name, (old, wanted) in self.m.PAYMENT_PINS.items():
            rows = [row for row in value['sources'] if row['path'] == name]
            self.assertEqual(len(rows), 1)
            self.assertEqual(rows[0]['after_blob'], wanted)
            if old is None: self.assertIsNone(rows[0]['before_blob'])
        before = json.loads(self.old_manifest)
        for key in ('expected_head', 'before_source', 'migrations'):
            self.assertEqual(value[key], before[key])
        self.assert_controls_unchanged()

    def test_protected_confirmation_files_cannot_enter_physical_mutation_roster(self):
        name = next(iter(self.m.PAYMENT_PINS))
        with self.assertRaisesRegex(self.a.Stop, 'PAYMENT_NAVIGATION_SCOPE_INVALID'):
            self.a.configure(self.before_source, self.after_source,
                {self.m.NAV: (self.m.BEFORE_NAV, self.m.AFTER_NAV), name: self.m.PAYMENT_PINS[name]})
        self.assertEqual(set(self.a.PINS), {self.m.NAV})
        self.assert_controls_unchanged()


@unittest.skipUnless(os.geteuid() == 0, 'the real shell explicitly requires root')
class ConfirmationNavigationWrapperTests(CASES.NavigationWrapperTests):
    def invoke(self, *args, **kwargs):
        fixtures = CASES.WRAPPER_CASES
        git = fixtures.GIT.replace(':deployment/upgrade_go_erp_program_watcher_root.py',
                                   ':deployment/upgrade_go_erp_confirmation_nav_successor_root.py')
        with mock.patch.object(fixtures, 'WRAPPER', ROOT / 'deployment/run_go_erp_confirmation_nav_successor_root.sh'), \
             mock.patch.object(fixtures, 'GIT', git):
            return fixtures.ProgramWrapperTests.invoke(self, *args, **kwargs)


if __name__ == '__main__': unittest.main()
