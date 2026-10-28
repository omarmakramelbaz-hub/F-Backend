"""Fault-injected private source adoption; original rejected records never change."""
import contextlib
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from test_go_erp_desktop_performance_recovery import fixture, r as planner

BASE = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


effects = load('performance_recovery_effects', BASE / 'deployment/go_erp_desktop_performance_recovery_root.py')
source = load('performance_recovery_source', BASE / 'tests/fixtures/go_erp_desktop_performance/source_root.py')


class Filesystem:
    def __init__(self, root):
        self.root, self.data, self.writes, self.fail_after = root, fixture(), [], None
        self.private, self.work = root / 'control/private', root / 'work'
        self.config, self.unit = root / 'etc/config.json', root / 'systemd/native.service'
        for path in (self.private / 'audits', self.work / 'state', self.config.parent, self.unit.parent):
            path.mkdir(mode=0o700, parents=True)
        self.private.chmod(0o700); self.work.chmod(0o700)
        self.paths = {'PRIVATE': self.private, 'WORK': self.work, 'CONFIG': self.config, 'UNIT': self.unit,
            'AUDIT': self.private / 'audits/adoption',
            'OLD_PERFORMANCE_AUDIT': self.private / 'audits/old-performance',
            'PERFORMANCE_AUDIT': self.private / 'audits/new-performance',
            'OLD_PERFORMANCE': self.private / 'old-performance.json',
            'PERFORMANCE': self.private / 'new-performance.json',
            'NATIVE_JOB': self.private / 'old-native-job.json',
            'NEW_NATIVE_JOB': self.private / 'new-native-job.json'}
        self.paths['OLD_PERFORMANCE_AUDIT'].mkdir(mode=0o700)
        unit = b'ORIGINAL_NATIVE_UNIT\n'
        self.data['native_unit_sha256'] = effects.sha(unit)
        self.data['performance_journal']['current']['private_hashes'][planner.UNIT] = effects.sha(unit)
        self.data['performance_checkpoint_raw'] = effects.canonical(self.data['performance_journal'])
        self.write(self.work / 'state/current.json', self.data['native_state_raw'])
        self.write(self.config, self.data['config_raw']); self.write(self.unit, unit, 0o644)
        self.write(self.paths['NATIVE_JOB'], effects.canonical(self.data['native_job']))
        self.write(self.paths['OLD_PERFORMANCE'], effects.canonical(self.data['performance_journal']))
        self.write(self.paths['OLD_PERFORMANCE_AUDIT'] / 'captured-source.json', self.data['captured_metadata_raw'])
        self.before_native = self.paths['NATIVE_JOB'].read_bytes()
        self.before_performance = self.paths['OLD_PERFORMANCE'].read_bytes()
        self.check = Mock(return_value=True)
        self.rollout = SimpleNamespace(e=SimpleNamespace(read_file=self.read_file,
                atomic_write=Mock(side_effect=AssertionError('No mutable record writes are authorized')),
                canonical=effects.canonical), io=SimpleNamespace(directory=source.directory,
                    private_directory=source.private_directory, private_record=self.private_record, parse=source.parse),
            o=SimpleNamespace(base=SimpleNamespace(checkout=Mock()), utility=object()),
            bind=copy.deepcopy(self.data['new_binding']),
            bind_sha=effects.sha(effects.canonical(self.data['new_binding'])))

    def write(self, path, raw, mode=0o600):
        path.write_bytes(raw); path.chmod(mode)

    def read_file(self, path, maximum, uid, modes, optional=False):
        try: info = path.lstat()
        except FileNotFoundError:
            if optional: return None
            raise
        mode = info.st_mode & 0o777
        if mode not in modes: raise AssertionError('Unapproved mode')
        return source.read(path, 0, 0, private=mode == 0o600, limit=maximum)

    def private_record(self, path, value, verify):
        existed = path.exists()
        source.private_record(path, value, verify)
        if not existed and (path == self.paths['PERFORMANCE'] or path.parent == self.paths['PERFORMANCE_AUDIT']):
            self.writes.append(path)
            if self.fail_after == len(self.writes):
                raise RuntimeError('injected interruption after immutable target creation')

    @contextlib.contextmanager
    def bound(self):
        with contextlib.ExitStack() as stack:
            for name, path in self.paths.items(): stack.enter_context(patch.object(effects, name, path))
            yield

    def recover(self):
        with self.bound(): return effects.recover(self.rollout, planner, self.check, evidence=self.data)


class SourceAdoption(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='performance-adoption-test-')
        self.addCleanup(temporary.cleanup)
        self.fs = Filesystem(Path(temporary.name))

    def assert_old_records_unchanged(self, fs):
        self.assertEqual(fs.paths['NATIVE_JOB'].read_bytes(), fs.before_native)
        self.assertEqual(fs.paths['OLD_PERFORMANCE'].read_bytes(), fs.before_performance)
        self.assertFalse(fs.paths['NEW_NATIVE_JOB'].exists())
        fs.rollout.e.atomic_write.assert_not_called()

    def test_creates_only_new_source_records_and_preserves_rejected_rollout(self):
        data = copy.deepcopy(self.fs.data)
        self.assertEqual(self.fs.recover(), effects.BAD_MANUAL_PINS)
        self.assertEqual(self.fs.data, data)
        self.assert_old_records_unchanged(self.fs)
        self.assertEqual(len(self.fs.writes), 3)
        self.assertEqual(self.fs.writes[0], self.fs.paths['PERFORMANCE_AUDIT'] / 'captured-source.json')
        self.assertEqual(self.fs.writes[-1], self.fs.paths['PERFORMANCE'])
        after = json.loads(self.fs.paths['PERFORMANCE'].read_bytes())
        self.assertEqual(planner.changed(data['performance_journal'], after), {'binding_sha256'})
        with self.fs.bound(): saved = effects.read_saved(self.fs.rollout, planner, self.fs.check)
        self.assertTrue(saved['completed'])
        self.assertEqual(saved['native_job'], data['native_job'])
        self.assertNotIn(b'SYNTHETIC_PRIVATE_TOKEN', (self.fs.paths['AUDIT'] / 'plan.json').read_bytes())

    def test_each_interrupted_target_creation_resumes_without_overwriting_old_records(self):
        for failed in (1, 2, 3):
            with self.subTest(failed=failed), tempfile.TemporaryDirectory(prefix='adoption-restart-') as root:
                fs = Filesystem(Path(root)); fs.fail_after = failed
                with self.assertRaisesRegex(RuntimeError, 'injected interruption'): fs.recover()
                with fs.bound(): saved = effects.read_saved(fs.rollout, planner, fs.check)
                self.assertFalse(saved['completed'])
                fs.fail_after = None
                self.assertEqual(fs.recover(), effects.BAD_MANUAL_PINS)
                self.assert_old_records_unchanged(fs)
                self.assertEqual(len(fs.writes), 3)

    def test_preexisting_new_native_or_source_record_stops_before_any_target_create(self):
        for key in ('NEW_NATIVE_JOB', 'PERFORMANCE'):
            with self.subTest(key=key), tempfile.TemporaryDirectory(prefix='adoption-foreign-') as root:
                fs = Filesystem(Path(root)); foreign = b'{"foreign":true}\n'; fs.write(fs.paths[key], foreign)
                with self.assertRaisesRegex(effects.Stop, 'ALREADY_EXISTS'): fs.recover()
                self.assertEqual(fs.writes, [])
                self.assertEqual(fs.paths[key].read_bytes(), foreign)
                self.assertEqual(fs.paths['NATIVE_JOB'].read_bytes(), fs.before_native)

    def test_foreign_new_source_after_interruption_is_retained(self):
        self.fs.fail_after = 1
        with self.assertRaises(RuntimeError): self.fs.recover()
        self.fs.write(self.fs.paths['PERFORMANCE'], b'{"foreign":true}\n')
        self.fs.fail_after = None
        with self.assertRaisesRegex(effects.Stop, 'NEW_PERFORMANCE_NOT_OWNED'): self.fs.recover()
        self.assertEqual(self.fs.paths['PERFORMANCE'].read_bytes(), b'{"foreign":true}\n')
        self.assert_old_records_unchanged(self.fs)

    def test_old_record_or_native_slot_drift_stops_without_creating_new_source_records(self):
        for path in ('config', 'old_native'):
            with self.subTest(path=path), tempfile.TemporaryDirectory(prefix='adoption-drift-') as root:
                fs = Filesystem(Path(root))
                if path == 'config': fs.write(fs.config, b'{"base_revision":"foreign"}\n')
                else: fs.write(fs.paths['NATIVE_JOB'], b'{"foreign":true}\n')
                with self.assertRaises(effects.Stop): fs.recover()
                self.assertEqual(fs.writes, [])
                self.assertFalse(fs.paths['AUDIT'].exists())

    def test_completed_adoption_allows_original_progress_without_recreating_native_job(self):
        self.fs.recover()
        after = json.loads(self.fs.paths['PERFORMANCE'].read_bytes())
        after.update(phase='ready', published={'original': 'accepted'})
        self.fs.write(self.fs.paths['PERFORMANCE'], effects.canonical(after))
        checkpoint = self.fs.paths['PERFORMANCE_AUDIT'] / ('checkpoint-' + effects.sha(effects.canonical(after)) + '.json')
        self.fs.write(checkpoint, effects.canonical(after))
        self.fs.write(self.fs.paths['NEW_NATIVE_JOB'], effects.canonical({'core': effects.CORE,
            'seed': effects.NEW_SEED, 'runtime': planner.runtime_name(self.fs.data['new_binding']['native_payloads']),
            'phase': 'running', 'candidate': 'a' * 40, 'version': '0.4.4-preview.61'}))
        self.fs.write(self.fs.config, b'{"base_revision":"advanced"}\n')
        with self.fs.bound(), patch.object(planner, 'plan', side_effect=AssertionError('No repeated completed adoption')):
            self.assertEqual(effects.recover(self.fs.rollout, planner, self.fs.check), effects.BAD_MANUAL_PINS)
        self.assertEqual(len(self.fs.writes), 3)
        self.assertEqual(self.fs.paths['NATIVE_JOB'].read_bytes(), self.fs.before_native)
        self.fs.rollout.e.atomic_write.assert_not_called()

    def test_tampered_immutable_snapshot_is_rejected(self):
        self.fs.recover()
        self.fs.write(self.fs.paths['AUDIT'] / 'native-before.bin', b'{}\n')
        with self.assertRaisesRegex(effects.Stop, 'INTENT_CHANGED'): self.fs.recover()


class OriginalManualTransition(unittest.TestCase):
    def test_actual_frozen_manual_uses_bad_predecessor_without_altering_native_profile(self):
        path = BASE / 'tests/fixtures/go_erp_desktop_performance/warm_start_root.py'
        raw = path.read_bytes()
        self.assertEqual(hashlib.sha256(raw).hexdigest(), '70bfb190e3b53fcb63ee76eb3ebfe16621be524e65ce623b4418386550081861')
        template = load('correction_frozen_manual', path)
        native_constants = {name: getattr(template, name) for name in ('OLD_CORE', 'OLD_SEED', 'OLD_RUNTIME', 'OLD_TICK_SHA')}
        with tempfile.TemporaryDirectory(prefix='performance-manual-transition-') as temporary:
            root = Path(temporary)
            control = root / 'control'
            (control / 'private/audits').mkdir(parents=True, mode=0o700)
            releases, systemd = root / 'releases', root / 'systemd'
            releases.mkdir(); systemd.mkdir()
            bad = {name: ('BAD = ' + repr(name) + '\n').encode() for name in effects.BAD_MANUAL_PINS}
            target = {name: ('TARGET = ' + repr(name) + '\n').encode() for name in bad}
            hashes = lambda values: {name: effects.sha(data) for name, data in values.items()}
            bad_pins, target_pins = hashes(bad), hashes(target)
            def install(values, _releases):
                folder = releases / effects.sha(effects.canonical(dict(sorted(hashes(values).items()))))
                folder.mkdir(exist_ok=True)
                for name, data in values.items():
                    (folder / name).write_bytes(data)
                return folder
            old_runtime = install(bad, releases)
            def units(folder):
                return {template.WATCH: b'FIXED_MANUAL_PATH\n',
                        template.SERVICE: ('ExecStart=' + str(folder / 'go_erp_manual_updates_root.py') + '\n').encode()}
            receipt = {'format': 1, 'kind': 'go-erp-manual-installation', 'runtime': old_runtime.name,
                       'units': {name: effects.sha(data) for name, data in units(old_runtime).items()}}
            receipt_path = control / 'private/installation.json'
            receipt_path.write_bytes(effects.canonical(receipt))
            for name, data in units(old_runtime).items():
                (systemd / name).write_bytes(data)
            commands = []
            engine = SimpleNamespace(sha=effects.sha, canonical=effects.canonical, install_runtime=install,
                directory=lambda *_args: None, units=units, run=lambda command: commands.append(command),
                read_file=lambda file, *args, **kwargs: (file.read_bytes(), None),
                atomic_write=lambda file, data, *_args: file.write_bytes(data))
            release = SimpleNamespace(revision='1' * 40,
                file=lambda name, revision=None: target[Path(name).name])
            context = SimpleNamespace(e=engine, release=release, base=SimpleNamespace(parse=json.loads))
            paths = {name: 'deployment/' + name for name in bad}
            with patch.multiple(template, CONTROL=control, MANUAL_RELEASES=releases, SYSTEMD=systemd,
                                OLD_MANUAL_PINS=bad_pins, MANUAL_PINS=target_pins, PACKAGE_PROGRAM_PATHS=paths):
                template.Context.manual(context)
            self.assertEqual(json.loads(receipt_path.read_bytes())['runtime'], context.manual_runtime.name)
            self.assertNotEqual(context.manual_runtime.name, old_runtime.name)
            self.assertEqual(commands, [['/usr/bin/systemctl', 'daemon-reload']])
            self.assertEqual(native_constants, {name: getattr(template, name) for name in native_constants})
            audits = list((control / 'private/audits').glob('warm-start-manual-*'))
            self.assertEqual(len(audits), 1)
            self.assertEqual((audits[0] / 'before-receipt').read_bytes(), effects.canonical(receipt))


if __name__ == '__main__':
    unittest.main()
