"""Fault-injected private journal effects, separate from pure authorization."""
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
        self.private = root / 'control/private'
        self.work = root / 'work'
        self.config, self.unit = root / 'etc/config.json', root / 'systemd/native.service'
        for path in (self.private / 'audits', self.work / 'state', self.config.parent, self.unit.parent):
            path.mkdir(mode=0o700, parents=True)
        self.private.chmod(0o700)
        self.work.chmod(0o700)
        self.paths = {'PRIVATE': self.private, 'WORK': self.work, 'CONFIG': self.config, 'UNIT': self.unit,
            'AUDIT': self.private / 'audits/correction', 'PERFORMANCE_AUDIT': self.private / 'audits/performance',
            'PERFORMANCE': self.private / 'performance.json', 'NATIVE_JOB': self.private / 'native-job.json'}
        self.paths['PERFORMANCE_AUDIT'].mkdir(mode=0o700)
        unit = b'ORIGINAL_NATIVE_UNIT\n'
        self.data['native_unit_sha256'] = effects.sha(unit)
        self.data['performance_journal']['current']['private_hashes'][planner.UNIT] = effects.sha(unit)
        self.data['performance_checkpoint_raw'] = effects.canonical(self.data['performance_journal'])
        self.write(self.work / 'state/current.json', self.data['native_state_raw'])
        self.write(self.config, self.data['config_raw'])
        self.write(self.unit, unit, 0o644)
        self.write(self.paths['NATIVE_JOB'], effects.canonical(self.data['native_job']))
        self.write(self.paths['PERFORMANCE'], effects.canonical(self.data['performance_journal']))
        self.before_native = self.paths['NATIVE_JOB'].read_bytes()
        self.before_performance = self.paths['PERFORMANCE'].read_bytes()
        self.check = Mock(return_value=True)
        self.rollout = SimpleNamespace(e=SimpleNamespace(read_file=self.read_file, atomic_write=self.atomic_write,
                canonical=effects.canonical), io=source,
            o=SimpleNamespace(base=SimpleNamespace(checkout=Mock()), utility=object()),
            bind_sha=effects.sha(effects.canonical(self.data['new_binding'])))

    def write(self, path, raw, mode=0o600):
        path.write_bytes(raw)
        path.chmod(mode)

    def read_file(self, path, maximum, uid, modes, optional=False):
        try:
            info = path.lstat()
        except FileNotFoundError:
            if optional:
                return None
            raise
        mode = info.st_mode & 0o777
        if mode not in modes:
            raise AssertionError('Unapproved file mode')
        raw, info = source.read(path, 0, 0, private=mode == 0o600, limit=maximum)
        return raw, info

    def atomic_write(self, path, raw, uid, gid, mode):
        self.assert_write_scope(path)
        stage = path.with_name('.test-atomic-' + str(len(self.writes)))
        self.write(stage, raw, mode)
        os.replace(stage, path)
        self.writes.append(path)
        if self.fail_after == len(self.writes):
            raise RuntimeError('injected process interruption after atomic replacement')

    def assert_write_scope(self, path):
        if path not in (self.paths['NATIVE_JOB'], self.paths['PERFORMANCE']):
            raise AssertionError('The correction attempted an unrelated mutable write')

    @contextlib.contextmanager
    def bound(self):
        with contextlib.ExitStack() as stack:
            for name, path in self.paths.items():
                stack.enter_context(patch.object(effects, name, path))
            yield

    def recover(self):
        with self.bound():
            return effects.recover(self.rollout, planner, self.check, evidence=self.data)


class JournalCorrection(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='performance-correction-test-')
        self.addCleanup(temporary.cleanup)
        self.fs = Filesystem(Path(temporary.name))

    def test_changes_only_two_owned_journals_and_preserves_every_original(self):
        old_data = copy.deepcopy(self.fs.data)
        result = self.fs.recover()
        self.assertEqual(result, effects.BAD_MANUAL_PINS)
        self.assertEqual(self.fs.data, old_data)
        self.assertEqual(self.fs.writes, [self.fs.paths['NATIVE_JOB'], self.fs.paths['PERFORMANCE']])
        after_job = json.loads(self.fs.paths['NATIVE_JOB'].read_bytes())
        after_performance = json.loads(self.fs.paths['PERFORMANCE'].read_bytes())
        self.assertEqual(planner.changed(self.fs.data['native_job'], after_job), {'seed', 'runtime'})
        self.assertEqual(planner.changed(self.fs.data['performance_journal'], after_performance), {'binding_sha256'})
        self.assertEqual((self.fs.paths['AUDIT'] / 'native-before.bin').read_bytes(), self.fs.before_native)
        self.assertEqual((self.fs.paths['AUDIT'] / 'performance-before.bin').read_bytes(), self.fs.before_performance)
        self.assertNotIn(b'SYNTHETIC_PRIVATE_TOKEN', (self.fs.paths['AUDIT'] / 'plan.json').read_bytes())
        with self.fs.bound():
            saved = effects.read_saved(self.fs.rollout, planner, self.fs.check)
        self.assertTrue(saved['completed'])

    def test_each_interrupted_record_write_resumes_using_immutable_preimages(self):
        for failed_write in (1, 2):
            with self.subTest(failed_write=failed_write), tempfile.TemporaryDirectory(prefix='correction-restart-') as root:
                fs = Filesystem(Path(root))
                fs.fail_after = failed_write
                with self.assertRaisesRegex(RuntimeError, 'injected process interruption'):
                    fs.recover()
                with fs.bound():
                    saved = effects.read_saved(fs.rollout, planner, fs.check)
                self.assertFalse(saved['completed'])
                self.assertEqual(saved['native_job'], fs.data['native_job'])
                self.assertEqual(saved['performance_journal'], fs.data['performance_journal'])
                fs.fail_after = None
                self.assertEqual(fs.recover(), effects.BAD_MANUAL_PINS)
                self.assertEqual(len(fs.writes), 2)
                self.assertEqual((fs.paths['AUDIT'] / 'native-before.bin').read_bytes(), fs.before_native)

    def test_foreign_native_record_after_interruption_is_retained(self):
        self.fs.fail_after = 1
        with self.assertRaises(RuntimeError):
            self.fs.recover()
        foreign = {**json.loads(self.fs.paths['NATIVE_JOB'].read_bytes()), 'phase': 'running'}
        foreign_raw = effects.canonical(foreign)
        self.fs.write(self.fs.paths['NATIVE_JOB'], foreign_raw)
        self.fs.fail_after = None
        with self.assertRaisesRegex(effects.Stop, 'TARGET_NOT_OWNED'):
            self.fs.recover()
        self.assertEqual(self.fs.paths['NATIVE_JOB'].read_bytes(), foreign_raw)
        self.assertEqual(self.fs.paths['PERFORMANCE'].read_bytes(), self.fs.before_performance)

    def test_foreign_second_record_is_classified_before_first_record_write(self):
        with patch.object(effects, '_cas', side_effect=RuntimeError('interrupted before first correction write')):
            with self.assertRaisesRegex(RuntimeError, 'before first correction write'):
                self.fs.recover()
        foreign = {**self.fs.data['performance_journal'], 'foreign_change': True}
        raw = effects.canonical(foreign)
        self.fs.write(self.fs.paths['PERFORMANCE'], raw)
        with self.assertRaisesRegex(effects.Stop, 'TARGET_NOT_OWNED'):
            self.fs.recover()
        self.assertEqual(self.fs.writes, [])
        self.assertEqual(self.fs.paths['NATIVE_JOB'].read_bytes(), self.fs.before_native)
        self.assertEqual(self.fs.paths['PERFORMANCE'].read_bytes(), raw)

    def test_native_slot_drift_stops_before_any_correction_write(self):
        self.fs.write(self.fs.config, b'{"base_revision":"foreign"}\n')
        with self.assertRaisesRegex(effects.Stop, 'ORIGINAL_NATIVE_SLOTS_CHANGED'):
            self.fs.recover()
        self.assertEqual(self.fs.writes, [])
        self.assertFalse(self.fs.paths['AUDIT'].exists())

    def test_completed_correction_allows_original_progress_without_rewriting_it(self):
        self.fs.recover()
        job = json.loads(self.fs.paths['NATIVE_JOB'].read_bytes())
        job.update(phase='running', prepare_started=True, candidate='a' * 40, version='0.4.4-preview.61')
        performance = json.loads(self.fs.paths['PERFORMANCE'].read_bytes())
        performance.update(phase='ready', published={'original': 'accepted'})
        self.fs.write(self.fs.paths['NATIVE_JOB'], effects.canonical(job))
        self.fs.write(self.fs.paths['PERFORMANCE'], effects.canonical(performance))
        checkpoint = self.fs.paths['PERFORMANCE_AUDIT'] / ('checkpoint-' + effects.sha(effects.canonical(performance)) + '.json')
        self.fs.write(checkpoint, effects.canonical(performance))
        self.fs.write(self.fs.config, b'{"base_revision":"advanced-native-state"}\n')
        before = self.fs.paths['NATIVE_JOB'].read_bytes()
        with self.fs.bound(), patch.object(planner, 'plan', side_effect=AssertionError('must not replan a completed correction')):
            result = effects.recover(self.fs.rollout, planner, self.fs.check)
        self.assertEqual(result, effects.BAD_MANUAL_PINS)
        self.assertEqual(self.fs.paths['NATIVE_JOB'].read_bytes(), before)
        self.assertEqual(len(self.fs.writes), 2)

    def test_tampered_correction_snapshot_is_rejected(self):
        self.fs.recover()
        self.fs.write(self.fs.paths['AUDIT'] / 'native-before.bin', b'{}\n')
        with self.assertRaisesRegex(effects.Stop, 'INTENT_CHANGED'):
            self.fs.recover()


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
