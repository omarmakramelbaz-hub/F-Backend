import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import resource
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('pending_performance_diagnostic',
    ROOT / 'deployment/diagnose_go_erp_desktop_performance_pending_readonly_root.py')
diag = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(diag)


class Fixture:
    def __init__(self, root):
        self.root = root
        self.work = root / 'work'
        self.private = root / 'control/private'
        self.config = root / 'etc/live-updates.json'
        self.unit = root / 'systemd/go-erp-live-updates.service'
        self.manuals = root / 'manuals'
        self.bindings = {'WORK': self.work, 'PRIVATE': self.private, 'CONFIG': self.config,
                         'UNIT': self.unit, 'MANUAL_RELEASES': self.manuals}
        self.before = {'base_revision': diag.OLD_SEED, 'token': 'PRIVATE_CONFIG_TOKEN'}
        self.after = {'base_revision': diag.SEED, 'token': 'PRIVATE_CONFIG_TOKEN'}
        self.state = {'counter': 60, 'base_revision': diag.OLD_SEED, 'last_remote_sha': diag.CANDIDATE}
        for directory in (self.work, self.private, self.manuals, self.work / 'state'):
            directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        for name in ('install.lock', 'worker.lock'):
            self.write(self.private / name, b'')
        self.write(self.work / 'watcher.lock', b'')
        self.write(self.config, self.before)
        self.write(self.work / 'state/current.json', self.state)
        self.write(self.private / ('shared-workspace-' + diag.CORE + '.json'), {
            'kind': 'go-erp-warm-start-rollout', 'core': diag.CORE, 'seed': diag.SEED,
            'runtime': diag.RUNTIME, 'inventory': diag.INVENTORY, 'phase': 'preparing',
            'prepare_started': False, 'candidate': None})
        self.manual_bytes = {name: ('PUBLIC_CODE = ' + repr(name) + '\n').encode()
                             for name in diag.PROGRAMS}
        self.manual_id = hashlib.sha256(diag.canonical({name: hashlib.sha256(raw).hexdigest()
                                                       for name, raw in self.manual_bytes.items()})).hexdigest()
        manual = self.manuals / self.manual_id
        for name, raw in self.manual_bytes.items():
            self.write(manual / name, raw, 0o444)
        manual.chmod(0o555)
        self.write(self.private / 'installation.json', {
            'format': 1, 'kind': 'go-erp-manual-installation', 'runtime': self.manual_id})
        self.write(self.unit, self.unit_bytes(diag.OLD_RUNTIME), 0o644)
        self.audit = self.work / ('warm-start-seed-upgrade-' + 'a' * 32)
        self.write(self.audit / 'before-config.bin', self.before)
        self.write(self.audit / 'exchange-config', self.after)
        self.write(self.audit / 'before-state.bin', self.state)
        self.write(self.audit / 'exchange-unit.service', self.unit_bytes(diag.RUNTIME), 0o644)
        self.failure_path = self.audit / ('failed-' + 'b' * 32 + '.json')
        self.failure = {'format': 1, 'kind': 'go-erp-preview31-watcher-upgrade', 'ok': False,
            'phase': 'SEED_STAGE', 'reason': 'SEED_CORE_MISMATCH', 'rollback_complete': True,
            'configuration_changed': False, 'state_changed': False, 'checkout_changed': False,
            'unit_changed': False, 'audit_directory': 'PRIVATE_PATH_SHOULD_NOT_APPEAR'}
        self.write(self.failure_path, self.failure)

    def unit_bytes(self, runtime):
        return ('[Service]\nExecStart=/usr/bin/python3 -I -B /opt/go-erp-live-updates/releases/'
                + runtime + '/go_erp_live_updates.py --config ' + str(self.config) + ' --once\n').encode()

    def write(self, path, value, mode=0o600):
        path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        path.write_bytes(diag.canonical(value) if isinstance(value, dict) else value)
        path.chmod(mode)

    @contextlib.contextmanager
    def bound(self):
        with contextlib.ExitStack() as stack:
            for name, value in self.bindings.items():
                stack.enter_context(patch.object(diag, name, value))
            yield

    def inspect(self):
        with self.bound(), diag.Reader() as reader:
            return diag.inspect(reader)


class PendingReadOnly(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='performance-pending-test-')
        self.addCleanup(self.temporary.cleanup)
        self.fixture = Fixture(Path(self.temporary.name))

    def test_actual_private_audit_reports_only_bound_public_facts_without_writes(self):
        before = {str(path): path.read_bytes() for path in self.fixture.root.rglob('*') if path.is_file()}
        open_file = diag.os.open
        def read_only_open(path, flags, *args, **kwargs):
            self.assertFalse(flags & (os.O_WRONLY | os.O_RDWR | os.O_CREAT | os.O_TRUNC | os.O_APPEND))
            return open_file(path, flags, *args, **kwargs)
        with patch.object(diag.os, 'open', read_only_open):
            result = self.fixture.inspect()
        self.assertEqual(result['configuration_base'], 'current')
        self.assertEqual(result['state_base'], 'current')
        self.assertEqual(result['active_unit'], 'current')
        self.assertEqual(result['manual_runtime'], self.fixture.manual_id)
        self.assertTrue(result['original_locks_held'])
        self.assertEqual(len(result['matched_audits']), 1)
        record = result['matched_audits'][0]
        self.assertFalse(record['intent_present'])
        self.assertEqual(record['failures'][0]['phase'], 'SEED_STAGE')
        self.assertEqual(record['failures'][0]['reason'], 'SEED_CORE_MISMATCH')
        self.assertNotIn('PRIVATE_', json.dumps(result))
        self.assertEqual(before, {str(path): path.read_bytes() for path in self.fixture.root.rglob('*') if path.is_file()})

    def test_many_retained_audits_stay_within_bounded_descriptor_budget(self):
        for number in range(64):
            audit = self.fixture.work / ('warm-start-seed-upgrade-' + format(number, '032x'))
            self.fixture.write(audit / 'before-config.bin', {'base_revision': '0' * 40})
            self.fixture.write(audit / 'exchange-config', {'base_revision': '1' * 40})
        old = resource.getrlimit(resource.RLIMIT_NOFILE)
        try:
            resource.setrlimit(resource.RLIMIT_NOFILE, (128, old[1]))
            result = self.fixture.inspect()
        finally:
            resource.setrlimit(resource.RLIMIT_NOFILE, old)
        self.assertEqual(len(result['matched_audits']), 1)

    def test_untrusted_reason_is_replaced_and_extra_private_fields_are_ignored(self):
        value = {**self.fixture.failure, 'reason': 'PRIVATE_SECRET_TOKEN', 'phase': 'PRIVATE_PHASE'}
        result = diag.bounded_failure(value)
        self.assertEqual(result['reason'], 'OTHER_REVIEW_REQUIRED')
        self.assertEqual(result['phase'], 'OTHER_REVIEW_REQUIRED')
        self.assertNotIn('PRIVATE_', json.dumps(result))

    def test_foreign_audit_and_symlink_are_not_accepted(self):
        self.fixture.write(self.fixture.audit / 'before-state.bin', {**self.fixture.state, 'counter': 59})
        self.assertEqual(self.fixture.inspect()['matched_audits'], [])
        self.fixture.write(self.fixture.audit / 'before-state.bin', self.fixture.state)
        self.fixture.failure_path.unlink()
        self.fixture.failure_path.symlink_to(self.fixture.config)
        with self.assertRaises((diag.Stop, OSError)):
            self.fixture.inspect()

    def test_partially_switched_owned_audit_remains_visible(self):
        self.fixture.write(self.fixture.audit / 'exchange-config', self.fixture.before)
        self.fixture.write(self.fixture.audit / 'exchange-unit.service', self.fixture.unit_bytes(diag.OLD_RUNTIME), 0o644)
        self.fixture.write(self.fixture.audit / 'intent.json', {
            'old_seed': diag.OLD_SEED, 'new_seed': diag.SEED, 'core': diag.CORE,
            'runtime': '/opt/go-erp-live-updates/releases/' + diag.RUNTIME})
        result = self.fixture.inspect()
        self.assertEqual(len(result['matched_audits']), 1)
        self.assertTrue(result['matched_audits'][0]['intent_present'])

    def test_host_and_arguments_stop_before_any_filesystem_read(self):
        for uid, host, arguments in [(1, 'srv1836524', ['diag']),
                                     (0, 'other-host', ['diag']), (0, 'srv1836524', ['diag', '--apply'])]:
            out = io.StringIO()
            with patch.object(diag.os, 'geteuid', return_value=uid), \
                    patch.object(diag.socket, 'gethostname', return_value=host), \
                    patch.object(diag.sys, 'argv', arguments), \
                    patch.object(diag, 'inspect', side_effect=AssertionError('must not inspect')), \
                    contextlib.redirect_stdout(out):
                self.assertEqual(diag.main(), 1)
            self.assertFalse(json.loads(out.getvalue())['ok'])

    def test_private_exception_text_reaches_neither_stdout_nor_stderr(self):
        out, err = io.StringIO(), io.StringIO()
        with patch.object(diag.os, 'geteuid', return_value=0), \
                patch.object(diag.socket, 'gethostname', return_value='srv1836524.example.invalid'), \
                patch.object(diag.sys, 'argv', ['diag']), \
                patch.object(diag, 'inspect', side_effect=RuntimeError('PRIVATE_TOKEN=credential')), \
                contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            self.assertEqual(diag.main(), 1)
        self.assertEqual(err.getvalue(), '')
        self.assertNotIn('PRIVATE_TOKEN', out.getvalue())
        self.assertEqual(json.loads(out.getvalue())['code'],
                         'GO_ERP_DESKTOP_PERFORMANCE_PENDING_PRIVATE_FAILURE')

    def test_runtime_and_command_bindings_match_frozen_factories(self):
        def load(name, path):
            spec = importlib.util.spec_from_file_location(name, path)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            return module
        rollout = load('pending_rollout_binding', ROOT / 'deployment/upgrade_go_erp_desktop_performance_root.py')
        payloads = load('pending_payload_binding', ROOT / 'deployment/go_erp_desktop_performance_payloads.py')
        def runtime(pins):
            value = {name: {'blob': pin[0], 'sha256': pin[2]} for name, pin in sorted(pins.items())}
            return 'policy-' + hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
        self.assertEqual(diag.RUNTIME, runtime(rollout.DERIVED_PINS))
        self.assertEqual(diag.OLD_RUNTIME, runtime({name: (pin['blob'], pin['size'], pin['sha256'])
                                                 for name, pin in payloads.CURRENT_PINS.items()}))
        path = ROOT / 'tests/fixtures/go_erp_desktop_performance/capacity_utility_root.py'
        raw = path.read_bytes()
        self.assertEqual(hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest(),
                         '60df7ec876f9634e0a7dcb4c5a420d0d5ecd9ae9')
        self.assertIn(b"os.open('watcher.lock',os.O_RDONLY", raw)
        utility = load('pending_frozen_utility', path)
        lines = utility.service_unit(Path('/opt/go-erp-live-updates/releases') / diag.RUNTIME).splitlines()
        expected = ('ExecStart=/usr/bin/python3 -I -B /opt/go-erp-live-updates/releases/' + diag.RUNTIME
                    + '/go_erp_live_updates.py --config /etc/go-erp/live-updates.json --once').encode()
        self.assertIn(expected, lines)


if __name__ == '__main__':
    unittest.main()
