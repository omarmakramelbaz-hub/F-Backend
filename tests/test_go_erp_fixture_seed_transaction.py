"""Five private inode exchanges; fixture Git/runtime observations are synthetic.

Real filesystem tests preserve live source, executable modes, database sentinels
and historical proofs. No daemon, Git remote, PHP, SQL or CI proof is executed.
"""
import copy
import fcntl
import importlib.util
import json
import os
from pathlib import Path
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


@unittest.skipUnless(os.geteuid() == 0, 'actual root-owned inode exchanges')
class FixtureSeedTransactionTests(unittest.TestCase):
    def setUp(self):
        self.m = module('fixture_seed_transaction_entry', ROOT / 'deployment/upgrade_go_erp_fixture_seed_root.py')
        self.b = module('fixture_seed_transaction_base', ROOT / 'deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py')
        self.u = module('fixture_seed_transaction_utility', ROOT / 'deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py')
        self.g = module('fixture_seed_transaction_gate', ROOT / 'deployment/go_erp_program_rollout_gate.py')
        self.prior_module = module('fixture_seed_transaction_prior', ROOT / 'deployment/upgrade_go_erp_program_watcher_root.py')
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-fixture-seed-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.live, self.work, self.releases = [self.root / name for name in ('live', 'work', 'releases')]
        self.live.mkdir(mode=0o755); self.work.mkdir(mode=0o700); self.releases.mkdir(mode=0o755)
        (self.root / 'config').mkdir(mode=0o700); (self.root / 'units').mkdir(mode=0o755)
        self.config, self.unit = self.root / 'config/live-updates.json', self.root / 'units/go-erp-live-updates.service'
        self.state_dir = self.work / 'state'; self.state_dir.mkdir(mode=0o700)
        self.state_path, self.hold_path = self.state_dir / 'current.json', self.work / 'program-rollout-hold.json'
        self.checkout = self.work / 'checkout'; self.checkout.mkdir(mode=0o700)
        self.write(self.checkout / 'seed.txt', self.m.OLD_SEED.encode())
        self.old_runtime, self.runtime = self.releases / 'old-runtime', self.releases / 'new-runtime'
        self.old_runtime.mkdir(mode=0o755); self.runtime.mkdir(mode=0o755)
        self.write(self.old_runtime / 'runtime.py', b'# unchanged old runtime\n', 0o644)
        self.write(self.runtime / 'runtime.py', b'# new fixture policy only\n', 0o644)
        self.m.ROOT, self.m.WORK, self.m.RELEASES = self.live, self.work, self.releases
        self.m.CONFIG, self.m.UNIT, self.m.CORE, self.m.SEED = self.config, self.unit, 'a' * 40, 'b' * 40
        self.inventory = {'fixture/source.php': {'mode': '100755', 'present': True,
                                                 'sha256': 'd' * 64, 'bytes': 42}}
        self.m.INVENTORY = self.m.sha(json.dumps(self.inventory, ensure_ascii=False, sort_keys=True,
                                                separators=(',', ':'), allow_nan=False).encode())
        self.state = {'format': 1, 'base_revision': self.m.OLD_SEED, 'counter': 37,
            'version_floor': '0.4.4-preview.37', 'sequence_floor': 987654321,
            'last_remote_sha': self.m.OLD_CANDIDATE,
            'observed': {'seedRevision': self.m.OLD_SEED, 'inventorySha256': self.m.INVENTORY},
            'desired': {'commit': self.m.OLD_CANDIDATE, 'version': '0.4.4-preview.37',
                'inventorySha256': self.m.INVENTORY, 'pushed': True, 'status': 'blocked'},
            'published': None, 'published_history': [{'version': '0.4.4-preview.35', 'retained': True}],
            'dependency_guard': {'retained': 'original dependency guard'}}
        self.cfg = {'format': 1, 'repository': 'omarmakramelbaz-hub/F-Backend', 'repository_id': 1311412084,
            'branch': 'codex/go-erp-live-source-20261021', 'base_revision': self.m.OLD_SEED,
            'token': 'synthetic-private-token-never-output'}
        self.expected_old = self.g.program_gate_expected(self.m.OLD_CORE, self.m.SOURCE, '1' * 64)
        self.expected = self.g.program_gate_expected(self.m.CORE, self.m.SOURCE, self.m.INVENTORY)
        self.write(self.config, self.m.canonical(self.cfg))
        self.write(self.state_path, self.m.canonical(self.state))
        self.write(self.hold_path, self.m.canonical(self.hold(self.expected_old)))
        self.write(self.unit, self.b.unit_bytes(self.u, self.old_runtime, self.live, self.work, self.config), 0o644)
        self.historical = self.work / ('confirmation-navigation-successor-' + 'e' * 32)
        self.historical.mkdir(mode=0o700)
        for name in ('intent.json', 'completed.json'):
            self.write(self.historical / name, self.m.canonical({'original_source_proof': name}))
        for name, mode, data in [('app/Checkout.php', 0o755, b'<?php // immutable checkout\n'),
                                 ('app/Payment.php', 0o755, b'<?php // immutable payment\n'),
                                 ('database/business.ibd', 0o600, b'untouched binary business sentinel')]:
            path = self.live / name; path.parent.mkdir(parents=True, exist_ok=True)
            self.write(path, data, mode)
        self.ctx = types.SimpleNamespace(utility=self.u, base=self.b, gate=self.g,
            old=types.SimpleNamespace(runtime=self.old_runtime, prepared_expected=self.expected_old,
                expected=self.g.program_gate_expected(self.m.OLD_CORE, self.m.SOURCE, self.m.INVENTORY)),
            runtime=self.runtime, pins={'runtime.py': 'f' * 40},
            mode=types.SimpleNamespace(hold=self.hold), expected=self.expected,
            prior=types.SimpleNamespace(assert_quiescent=lambda: None, held_lock=self.prior_module.held_lock),
            prepare_target=self.prepare_target, capture_old=self.capture_old, check_before=self.check_before,
            check_after=self.check_after, check_rollback=lambda observation, lock_fd, intent, audit:
                self.check_before(observation, lock_fd), check_protected=lambda observation:
                self.assertEqual(self.protected_snapshot(), self.protected),
            remote=lambda: self.m.OLD_CANDIDATE, publication=lambda: None)
        self.system_calls = []
        self.commands = mock.patch.object(self.u, 'system_command', side_effect=lambda args: self.system_calls.append(args))
        self.units = mock.patch.object(self.u, 'effective_unit', return_value=None)
        self.git_check = mock.patch.object(self.b, 'checkout', side_effect=self.check_checkout)
        for patcher in (self.commands, self.units, self.git_check):
            patcher.start(); self.addCleanup(patcher.stop)
        self.lock_fd = os.open(self.work / 'watcher.lock', os.O_RDWR | os.O_CREAT | os.O_EXCL, 0o600)
        self.addCleanup(os.close, self.lock_fd)
        fcntl.flock(self.lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.protected = self.protected_snapshot()
        self.observation = self.capture_old(self.lock_fd)
        self.slots_before = self.slot_snapshot()

    @staticmethod
    def write(path, data, mode=0o600):
        path.write_bytes(data); path.chmod(mode)

    @staticmethod
    def hold(expected):
        return {'format': 1, 'kind': 'go-erp-program-rollout-hold', **expected, 'authorization': None}

    def protected_snapshot(self):
        paths = [*self.live.rglob('*'), *self.historical.rglob('*'), *self.old_runtime.rglob('*')]
        return {str(path): (path.read_bytes(), self.u.exact_key(path.stat())) for path in paths if path.is_file()}

    def slot_snapshot(self):
        slots = {'unit': self.unit, 'state': self.state_path, 'hold': self.hold_path, 'config': self.config}
        # Rename exchange changes ctime even when the exact old inode returns.
        # The pinned base record deliberately binds the other inode metadata.
        result = {name: {'bytes': path.read_bytes(), 'identity': self.u.key(path.stat())} for name, path in slots.items()}
        result['checkout'] = {'identity': self.u.directory_key(self.checkout.stat()),
                              'seed': (self.checkout / 'seed.txt').read_bytes(),
                              'seed_inode': (self.checkout / 'seed.txt').stat().st_ino}
        return result

    def capture_old(self, lock_fd):
        self.assertEqual(self.protected_snapshot(), getattr(self, 'protected', self.protected_snapshot()))
        self.b.checked_config(json.loads(self.config.read_bytes()), self.m.OLD_SEED)
        state = json.loads(self.state_path.read_bytes())
        self.m.next_state(self.b, state)
        self.check_checkout(self.checkout, self.m.OLD_SEED, self.u)
        return {'state': state, 'hold': json.loads(self.hold_path.read_bytes()),
            'controls': {name: self.m.sha(path.read_bytes()) for name, path in
                         [('config', self.config), ('unit', self.unit), ('state', self.state_path)]},
            'evidence': {path.name: self.m.sha(path.read_bytes()) for path in self.historical.iterdir()},
            'payment': [list(value[1]) for value in self.protected_snapshot().values()],
            'mode_proof': None, 'snapshot': {'seedRevision': self.m.OLD_SEED, 'inventorySha256': self.m.INVENTORY,
                                           'inventory': copy.deepcopy(self.inventory)}}

    def check_before(self, observation, lock_fd, *args, **kwargs):
        self.assertEqual(self.capture_old(lock_fd), observation)

    def check_checkout(self, path, seed, utility):
        self.assertEqual((Path(path) / 'seed.txt').read_bytes(), seed.encode())

    def prepare_target(self, audit, state):
        target = audit / self.b.exchange_name('checkout')
        self.assertEqual(list(target.iterdir()), [])
        self.write(target / 'seed.txt', self.m.SEED.encode())
        return {'seedRevision': self.m.SEED, 'inventorySha256': self.m.INVENTORY,
                'inventory': copy.deepcopy(self.inventory)}

    def check_after(self, intent, lock_fd, state_exact=True):
        self.assertEqual(self.protected_snapshot(), self.protected)
        self.check_checkout(self.checkout, self.m.SEED, self.u)
        cfg = copy.deepcopy(self.cfg); cfg['base_revision'] = self.m.SEED
        self.assertEqual(json.loads(self.config.read_bytes()), cfg)
        if state_exact:
            self.assertEqual(json.loads(self.state_path.read_bytes()), self.m.next_state(self.b, self.state))
        self.assertEqual(json.loads(self.hold_path.read_bytes()), self.hold(self.expected))
        self.assertEqual(self.unit.read_bytes(), self.b.unit_bytes(self.u, self.runtime, self.live, self.work, self.config))
        return json.loads(self.state_path.read_bytes())

    def transaction(self, event=None):
        return self.m.transaction(self.ctx, self.lock_fd, lambda: None, self.observation, event=event)

    def audits(self):
        return sorted(self.work.glob(self.m.PREFIX + '*'))

    def assert_original(self):
        self.assertEqual(self.slot_snapshot(), self.slots_before)
        self.assertEqual(self.protected_snapshot(), self.protected)

    def test_success_switches_only_five_private_slots_and_configuration_is_last(self):
        events = []
        def event(phase, slot):
            if phase == 'after-exchange':
                events.append(slot)
                if slot != 'config': self.assertEqual(json.loads(self.config.read_bytes()), self.cfg)
        result = self.transaction(event)
        self.assertTrue(result['ok']); self.assertEqual(result['next_version'], '0.4.4-preview.38')
        self.assertEqual(events, ['unit', 'checkout', 'state', 'hold', 'config'])
        self.assertEqual(self.protected_snapshot(), self.protected)
        state = json.loads(self.state_path.read_bytes())
        for name in self.state:
            if name not in ('base_revision', 'observed', 'desired'): self.assertEqual(state[name], self.state[name])
        self.assertIsNone(state['desired']); self.assertIsNone(state['observed'])
        self.assertEqual(state['counter'], 37)
        self.assertTrue((self.audits()[0] / 'completed.json').exists())
        self.assertTrue(all(args[0] in ('/usr/bin/systemctl', '/usr/bin/systemd-analyze') for args in self.system_calls))
        self.assertFalse(any('start' in args or 'stop' in args for args in self.system_calls))

    def test_observation_drift_before_first_exchange_never_replaces_active_slots(self):
        original = self.ctx.check_before
        calls = 0
        def check(observation, lock_fd):
            nonlocal calls
            calls += 1
            if calls == 2: raise RuntimeError('synthetic old observation changed')
            original(observation, lock_fd)
        with mock.patch.object(self.ctx, 'check_before', side_effect=check):
            with self.assertRaises(RuntimeError): self.transaction()
        self.assert_original()
        self.assertTrue((self.audits()[0] / 'rolled-back.json').exists())

    def test_foreign_active_inode_is_not_overwritten_by_rollback(self):
        foreign = b'[Service]\nExecStart=foreign writer\n'
        def event(phase, slot):
            if phase == 'after-exchange' and slot == 'unit':
                replacement = self.unit.with_name('foreign.service')
                self.write(replacement, foreign, 0o644)
                os.replace(replacement, self.unit)
                raise RuntimeError('concurrent replacement')
        with self.assertRaisesRegex(self.m.Stop, 'ROLLBACK_REQUIRES_REVIEW') as stopped:
            self.transaction(event)
        self.assertFalse(stopped.exception.rollback_complete)
        self.assertEqual(self.unit.read_bytes(), foreign)
        self.assertEqual(self.protected_snapshot(), self.protected)
        self.assertFalse((self.audits()[0] / 'rolled-back.json').exists())

    def leave_interrupted(self, slot):
        def event(phase, name):
            if phase == 'after-exchange' and name == slot: raise RuntimeError('emulated process interruption')
        # Leave exactly the real durable intent and half-exchanged inode pairs
        # a terminated process would leave, without killing the test runner.
        with mock.patch.object(self.m, 'rollback', side_effect=RuntimeError('process unavailable')):
            with self.assertRaisesRegex(self.m.Stop, 'ROLLBACK_REQUIRES_REVIEW') as stopped:
                self.transaction(event)
        self.assertFalse(stopped.exception.rollback_complete)
        self.assertFalse((self.audits()[0] / 'rolled-back.json').exists())

    def test_completed_replay_is_readonly_and_preserves_the_retained_original_inodes(self):
        self.transaction()
        before = {str(path): (path.read_bytes(), self.u.exact_key(path.stat()))
                  for path in self.work.rglob('*') if path.is_file()}
        active = self.slot_snapshot()
        path, intent, state = self.m.completed_upgrade(self.ctx, self.lock_fd, lambda: None)
        self.assertEqual(path, self.audits()[0]); self.assertEqual(state['counter'], 37)
        self.assertEqual({str(path): (path.read_bytes(), self.u.exact_key(path.stat()))
                          for path in self.work.rglob('*') if path.is_file()}, before)
        self.assertEqual(self.slot_snapshot(), active)
        self.assertEqual(self.protected_snapshot(), self.protected)

    def test_recovery_classifies_every_pair_before_replacing_any_foreign_inode(self):
        self.leave_interrupted('state')
        replacement = self.unit.with_name('external.service')
        self.write(replacement, b'[Service]\nExecStart=foreign\n', 0o644)
        os.replace(replacement, self.unit)
        before = self.slot_snapshot()
        with self.assertRaisesRegex(self.m.Stop, 'RECOVERY_REQUIRES_REVIEW'):
            self.m.recover(self.ctx, self.lock_fd, lambda: None)
        self.assertEqual(self.slot_snapshot(), before)
        self.assertEqual(self.protected_snapshot(), self.protected)
        self.assertFalse((self.audits()[0] / 'rolled-back.json').exists())

    def test_completed_replay_rejects_a_replaced_retained_original_even_with_identical_bytes(self):
        self.transaction()
        held = self.audits()[0] / self.b.exchange_name('state')
        replacement = held.with_name('foreign-original')
        self.write(replacement, held.read_bytes())
        os.replace(replacement, held)
        before = self.slot_snapshot()
        with self.assertRaisesRegex(self.m.Stop, 'RETAINED_SLOT_CHANGED'):
            self.m.completed_upgrade(self.ctx, self.lock_fd, lambda: None)
        self.assertEqual(self.slot_snapshot(), before)
        self.assertEqual(self.protected_snapshot(), self.protected)

    def test_rollback_control_comparison_ignores_only_renamed_control_ctime(self):
        identity = [1, 2, 0o100600, 0, 0, 1, 30, 100, 200]
        sealed = {'config': {'sha256': 'a' * 64, 'identity': identity},
                  'payment': [{'identity': copy.deepcopy(identity), 'blob': 'b' * 40}]}
        changed = copy.deepcopy(sealed); changed['config']['identity'][-1] += 1
        actual = {name: self.m.canonical(value) for name, value in changed.items()}
        self.assertTrue(self.m.controls_equal(actual, sealed, True))
        self.assertFalse(self.m.controls_equal(actual, sealed, False))
        changed['payment'][0]['identity'][-1] += 1
        self.assertFalse(self.m.controls_equal({name: self.m.canonical(value) for name, value in changed.items()}, sealed, True))


def interruption_case(slot):
    def case(self):
        def event(phase, name):
            if phase == 'after-exchange' and name == slot:
                raise RuntimeError('synthetic interruption after ' + slot)
        with self.assertRaises(RuntimeError): self.transaction(event)
        self.assert_original()
        self.assertTrue((self.audits()[0] / 'rolled-back.json').exists())
        self.assertFalse((self.audits()[0] / 'completed.json').exists())
    return case


for _slot in ('unit', 'checkout', 'state', 'hold', 'config'):
    setattr(FixtureSeedTransactionTests, 'test_interruption_after_' + _slot + '_restores_original_inodes', interruption_case(_slot))


def recovery_case(slot):
    def case(self):
        self.leave_interrupted(slot)
        self.assertTrue(self.m.recover(self.ctx, self.lock_fd, lambda: None))
        self.assert_original()
        self.assertTrue((self.audits()[0] / 'rolled-back.json').exists())
        self.assertFalse(self.m.recover(self.ctx, self.lock_fd, lambda: None))
    return case


for _slot in ('unit', 'checkout', 'state', 'hold', 'config'):
    setattr(FixtureSeedTransactionTests, 'test_pending_recovery_after_' + _slot + '_restores_original_inodes', recovery_case(_slot))


def pending_lease_case(slot):
    def case(self):
        self.leave_interrupted(slot)
        before = {str(path): (path.read_bytes(), self.u.exact_key(path.stat()))
                  for path in self.root.rglob('*') if path.is_file()}
        fcntl.flock(self.lock_fd, fcntl.LOCK_UN)
        with mock.patch.object(self.m, 'Context', return_value=self.ctx), \
             mock.patch.object(self.m.socket, 'gethostname', return_value='srv1836524'), \
             mock.patch.object(self.ctx, 'capture_old', side_effect=AssertionError('lease must not inspect mixed active control slots')):
            result = self.m.execute(self.root, 'lease')
        self.assertEqual(result['worker_request'], 'prepare')
        self.assertFalse(result['published'])
        self.assertNotIn('candidate_revision', result)
        self.assertEqual({str(path): (path.read_bytes(), self.u.exact_key(path.stat()))
                          for path in self.root.rglob('*') if path.is_file()}, before)
        self.assertFalse((self.audits()[0] / 'completed.json').exists())
        self.assertFalse((self.audits()[0] / 'rolled-back.json').exists())
    return case


for _slot in ('unit', 'config'):
    setattr(FixtureSeedTransactionTests, 'test_first_lease_routes_pending_' + _slot + '_to_recovery_without_writes', pending_lease_case(_slot))


if __name__ == '__main__': unittest.main()
