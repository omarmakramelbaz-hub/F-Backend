"""Known payment metadata reconciliation without touching approved payment code.

Real root-owned navigation/manifest/hold exchanges reuse the independently tested
successor lifecycle. Services, remote observations and acceptance CI are fixtures.
"""
import copy
import fcntl
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


CASES = module('payment_navigation_previous_cases', ROOT / 'tests/test_go_erp_nav_successor.py')


@unittest.skipUnless(os.geteuid() == 0, 'actual root-owned inode exchanges')
class PaymentNavigationSuccessorTests(CASES.NavigationSuccessorTests):
    def setUp(self):
        super().setUp()
        self.old_entry, self.old_helper = self.m, self.a
        self.m = module('payment_navigation_successor_fixture', ROOT / 'deployment/upgrade_go_erp_payment_nav_successor_root.py')
        self.a = module('payment_navigation_application_fixture', ROOT / 'deployment/go_erp_payment_nav_application_patch.py')
        self.payment_after, self.payment_before = {}, {}
        for number, name in enumerate(self.m.PAYMENT_PINS):
            path = self.live / name
            path.parent.mkdir(parents=True, exist_ok=True)
            before = ('/* old payment fixture %s */\n' % number).encode()
            after = ('/* approved payment fixture %s */\n' % number).encode()
            path.write_bytes(after)
            path.chmod(0o640)
            self.payment_before[name], self.payment_after[name] = before, after
        pins = {name: (self.a.blob(self.payment_before[name]), self.a.blob(data))
                for name, data in self.payment_after.items()}
        payment_map = self.s.scan(self.live)
        old_map = copy.deepcopy(payment_map)
        for name, data in self.payment_before.items():
            old_map[name] = {'blob': self.a.blob(data), 'sha256': self.m.digest(data)}
        old_source = self.s.digest(old_map)
        self.before_source = self.s.digest(payment_map)
        new_map = copy.deepcopy(payment_map)
        new_map[self.m.NAV] = self.s.file_record(self.package / 'application' / self.m.NAV)
        self.after_source = self.s.digest(new_map)
        self.nav_only_source = copy.deepcopy(old_map)
        self.nav_only_source[self.m.NAV] = new_map[self.m.NAV]
        self.nav_only_source = self.s.digest(self.nav_only_source)
        self.m.ROOT, self.m.WORK = self.live, self.work
        self.m.OLD_SOURCE, self.m.BEFORE_SOURCE, self.m.NEW_SOURCE = old_source, self.before_source, self.after_source
        self.m.BEFORE_NAV, self.m.AFTER_NAV = self.a.blob(self.before), self.a.blob(self.after)
        self.m.PAYMENT_PINS = pins
        self.a.PAYMENT_BASE_SOURCE, self.a.PAYMENT_SOURCE, self.a.PAYMENT_PINS = old_source, self.before_source, pins
        self.a.configure(self.before_source, self.after_source,
                         {self.m.NAV: (self.m.BEFORE_NAV, self.m.AFTER_NAV)})
        value = json.loads(self.manifest.read_bytes())
        value['after_source'] = old_source
        # Exercise both real manifest representations and preserve their
        # original historical before_blob/phase fields where already present.
        for index, (name, (old, _)) in enumerate(pins.items()):
            if index % 2:
                value['sources'].append({'path': name, 'before_blob': 'f' * 40, 'after_blob': old, 'phase': 3})
            else:
                value['protected'].append({'path': name, 'blob': old})
        value['protected'].append({'path': 'app/untouched.php', 'blob': payment_map['app/untouched.php']['blob']})
        self.manifest.write_bytes(self.m.canonical(value))
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
        controls, state = super().controls()
        if hasattr(self, 'payment_after'):
            records = []
            for name, wanted in self.payment_after.items():
                path = self.live / name
                info = path.stat()
                data = path.read_bytes()
                self.m.need(data == wanted, 'APPROVED_PAYMENT_FILE_DIFFERS')
                records.append({'path': name, 'blob': self.a.blob(data), 'identity': self.u.exact_key(info)})
            controls['payment'] = self.m.canonical(records)
        return controls, state

    def assert_payments_unchanged(self):
        for name, data in self.payment_after.items():
            path = self.live / name
            self.assertEqual(path.read_bytes(), data)
            self.assertEqual(path.stat().st_ino, self.payment_inodes[name])
            self.assertEqual(path.stat().st_mode & 0o777, 0o640)

    def assert_controls_unchanged(self):
        super().assert_controls_unchanged()
        self.assert_payments_unchanged()

    def assert_rolled_back(self):
        super().assert_rolled_back()
        self.assert_payments_unchanged()

    def test_absent_manifest_directory_stays_absent(self):
        self.manifest.unlink()
        self.manifest.parent.rmdir()
        with self.assertRaisesRegex(self.a.Stop, 'PAYMENT_MANIFEST_MISSING'):
            self.run_transaction()
        self.assertFalse(self.manifest.parent.exists())
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
        self.assertFalse(self.audits())
        self.assert_controls_unchanged()

    def test_manifest_metadata_records_known_three_source_chain_without_payment_exchanges(self):
        renamed = []
        original = self.a.rename
        def rename(left_fd, left, right_fd, right, flags):
            renamed.append(str(Path(os.readlink('/proc/self/fd/' + str(right_fd))) / right))
            self.assert_payments_unchanged()
            return original(left_fd, left, right_fd, right, flags)
        with mock.patch.object(self.a, 'rename', side_effect=rename):
            result = self.run_transaction()
        self.assertEqual(result['kind'], 'go-erp-payment-nav-successor-prepared')
        self.assertEqual(set(renamed), {str(self.nav), str(self.manifest)})
        value = json.loads(self.manifest.read_bytes())
        self.assertEqual(value['after_source'], self.after_source)
        before = json.loads(self.old_manifest)
        self.assertEqual(value['expected_head'], before['expected_head'])
        self.assertEqual(value['before_source'], before['before_source'])
        self.assertEqual(value['migrations'], before['migrations'])
        for name, (old, wanted) in self.m.PAYMENT_PINS.items():
            rows = [row for row in value['sources'] if row['path'] == name]
            self.assertEqual(len(rows), 1)
            self.assertEqual(rows[0]['after_blob'], wanted)
            original_rows = [row for row in before['sources'] if row['path'] == name]
            self.assertEqual(rows[0]['before_blob'], original_rows[0]['before_blob'] if original_rows else old)
            self.assertEqual(rows[0]['phase'], original_rows[0]['phase'] if original_rows else 2)
            self.assertFalse(any(row['path'] == name for row in value['protected']))
        self.assertEqual(value['protected'], [row for row in before['protected'] if row['path'] == 'app/untouched.php'])
        intent = self.g.program_gate_json(self.audits()[0] / 'intent.json')
        self.assertEqual(intent['manifest_source_chain'], [self.m.OLD_SOURCE, self.before_source, self.after_source])
        self.assertEqual(intent['payment_pins'], {name: list(pair) for name, pair in self.m.PAYMENT_PINS.items()})
        self.assertEqual(intent['payment_release'], self.m.PAYMENT_RELEASE)
        self.assertEqual(set(intent['control_hashes']), {'config', 'state', 'unit', 'payment'})
        self.assert_controls_unchanged()

    def test_readonly_preflight_and_prepare_do_not_reconcile_live_metadata_early(self):
        before_hold = self.hold_path.read_bytes()
        self.a.verify_before(self.live, self.package, 0)
        audit = self.work / 'read-only-prepare'
        audit.mkdir(mode=0o700)
        patch = self.a.ApplicationPatch(self.live, self.package, audit, 0, 0, self.lock_fd)
        try:
            summary = patch.prepare()
            self.assertEqual(summary['application_files'], 1)
            self.assertEqual([row['name'] for row in patch.slots], [self.m.NAV, self.a.MANIFEST])
            self.assertEqual(self.nav.read_bytes(), self.before)
            self.assertEqual(self.manifest.read_bytes(), self.old_manifest)
            self.assertEqual(self.hold_path.read_bytes(), before_hold)
            self.assert_controls_unchanged()
            patch.rollback()
        finally:
            patch.close()
        self.assert_rolled_back()

    def test_payment_files_cannot_enter_mutation_roster(self):
        name = next(iter(self.m.PAYMENT_PINS))
        with self.assertRaisesRegex(self.a.Stop, 'PAYMENT_NAVIGATION_SCOPE_INVALID'):
            self.a.configure(self.before_source, self.after_source,
                {self.m.NAV: (self.m.BEFORE_NAV, self.m.AFTER_NAV), name: self.m.PAYMENT_PINS[name]})
        self.assertEqual(set(self.a.PINS), {self.m.NAV})
        self.assert_controls_unchanged()

    def test_partial_or_unrecognized_old_payment_metadata_refuses_before_any_exchange(self):
        old_value = json.loads(self.old_manifest)
        name = next(iter(self.m.PAYMENT_PINS))
        for fault in ('missing', 'duplicate', 'already-updated', 'wrong-pin', 'wrong-base-source'):
            value = copy.deepcopy(old_value)
            row = next(row for row in value['protected'] if row['path'] == name)
            if fault == 'missing': value['protected'].remove(row)
            elif fault == 'duplicate': value['protected'].append(copy.deepcopy(row))
            elif fault == 'already-updated': row['blob'] = self.m.PAYMENT_PINS[name][1]
            elif fault == 'wrong-pin': row['blob'] = 'f' * 40
            else: value['after_source'] = self.before_source
            self.manifest.write_bytes(self.m.canonical(value))
            with self.subTest(fault=fault), self.assertRaisesRegex(self.a.Stop, 'PAYMENT_MANIFEST|MANIFEST_PATHS_INVALID'):
                self.run_transaction()
            self.assertEqual(self.nav.read_bytes(), self.before)
            self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
            self.assertFalse(self.audits())
            self.assert_controls_unchanged()
        self.manifest.write_bytes(self.old_manifest)

    def test_live_payment_drift_is_rejected_without_downgrading_any_payment_file(self):
        name = next(iter(self.payment_after))
        path = self.live / name
        changed = b'/* concurrent approved payment edit */\n'
        path.write_bytes(changed)
        with self.assertRaisesRegex((self.m.Stop, self.a.Stop), 'APPROVED_PAYMENT_FILE_DIFFERS|BEFORE_SOURCE_UNREVIEWED'):
            self.run_transaction()
        self.assertEqual(path.read_bytes(), changed)
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assertEqual(self.manifest.read_bytes(), self.old_manifest)
        self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
        self.assertFalse(self.audits())

    def test_old_navigation_entry_cannot_claim_postpayment_cohort(self):
        self.old_entry.ROOT, self.old_entry.WORK = self.live, self.work
        self.old_entry.OLD_SOURCE, self.old_entry.NEW_SOURCE = self.m.OLD_SOURCE, self.nav_only_source
        with mock.patch.object(self.old_entry, 'Context', return_value=self.ctx), \
             mock.patch.object(self.old_entry.socket, 'gethostname', return_value='srv1836524'):
            with self.assertRaisesRegex(self.old_entry.Stop, 'SOURCE_UNREVIEWED'):
                self.old_entry.execute(self.package, 'prepare')
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
        self.assert_controls_unchanged()

    def test_unfinished_previous_navigation_transaction_blocks_new_cohort(self):
        audit = self.work / ('navigation-successor-' + 'a' * 32)
        audit.mkdir(mode=0o700)
        self.g.program_gate_write(audit / 'intent.json', {'synthetic': 'unfinished-old-navigation'})
        with self.assertRaisesRegex(self.m.Stop, 'PREVIOUS_NAVIGATION_TRANSACTION_REQUIRES_REVIEW') as stopped:
            self.execute()
        self.assertFalse(stopped.exception.rollback_complete)
        self.assertEqual(self.nav.read_bytes(), self.before)
        self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
        self.assert_controls_unchanged()

    def test_nav_only_source_receipt_cannot_authorize_payment_and_nav_successor(self):
        self.run_transaction()
        self.successor_state()
        receipt = {'format': 1, 'kind': 'go-erp-program-acceptance', 'core': self.m.CORE,
            'source': self.nav_only_source, 'inventory_sha256': self.old_entry.NEW_INVENTORY,
            'candidate_revision': self.state['desired']['commit'], 'version': self.state['desired']['version'],
            'installer': {}, 'ci': {}, 'employee_evidence': {}}
        path = self.work / 'stale-nav-only-receipt.json'
        self.g.program_gate_write(path, receipt)
        self.ctx.worker = types.SimpleNamespace(verify_proof=mock.Mock(side_effect=AssertionError('stale proof must not run')))
        self.ctx.publisher = object()
        with self.assertRaisesRegex(self.g.ProgramGateError, 'RECEIPT_BINDING_MISMATCH'):
            self.execute('publish', path)
        self.ctx.worker.verify_proof.assert_not_called()
        self.assertIsNone(self.g.program_gate_json(self.hold_path)['authorization'])
        self.assert_payments_unchanged()


@unittest.skipUnless(os.geteuid() == 0, 'the real shell explicitly requires root')
class PaymentNavigationWrapperTests(CASES.NavigationWrapperTests):
    def invoke(self, *args, **kwargs):
        fixtures = CASES.WRAPPER_CASES
        git = fixtures.GIT.replace(':deployment/upgrade_go_erp_program_watcher_root.py',
                                   ':deployment/upgrade_go_erp_payment_nav_successor_root.py')
        with mock.patch.object(fixtures, 'WRAPPER', ROOT / 'deployment/run_go_erp_payment_nav_successor_root.sh'), \
             mock.patch.object(fixtures, 'GIT', git):
            return fixtures.ProgramWrapperTests.invoke(self, *args, **kwargs)


if __name__ == '__main__': unittest.main()
