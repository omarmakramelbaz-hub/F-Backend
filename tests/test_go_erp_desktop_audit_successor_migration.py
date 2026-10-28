"""Real four-slot engine migration and hard-crash recovery on isolated Git/files.

Systemd and live publication are explicit local boundaries. The original file
engine, seed staging/verifier, exchanges, recovery and allocator execute intact.
"""
import copy
import importlib.util
import json
import os
from pathlib import Path
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
FALLBACK = BASE.parent / 'perf_operator'


def fixture_path(name):
    target = BASE / 'tests/fixtures/go_erp_desktop_performance' / name
    return target if target.exists() else FALLBACK / 'tests/fixtures/go_erp_desktop_performance' / name


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value)
    return value


pipeline_test = load('successor_physical_pipeline_fixture',
    BASE / 'tests/test_go_erp_desktop_performance_successor_pipeline.py')
pipeline = pipeline_test.pipeline
b = load('successor_frozen_four_slot_engine', fixture_path('preview31_base_root.py'))
u = load('successor_frozen_filesystem_utility', fixture_path('capacity_utility_root.py'))
warm = load('successor_frozen_four_slot_migration', fixture_path('warm_start_root.py'))


@unittest.skipUnless(os.geteuid() == 0 and hasattr(os, 'fork'), 'original root file transaction requires Linux root')
class OriginalFourSlotSuccessor(unittest.TestCase):
    write = pipeline.Pipeline.write
    commit = pipeline.Pipeline.commit
    fixture = pipeline.Pipeline.fixture

    def physical(self):
        f = self.fixture(60); root = f.checkout.parent
        work, releases, config_dir, systemd = [root / name for name in ('work', 'releases', 'config', 'systemd')]
        for path, mode in [(work, 0o700), (releases, 0o755), (config_dir, 0o700), (systemd, 0o755),
                           (work / 'state', 0o700), (work / 'staging', 0o700)]: path.mkdir(mode=mode)
        (work / 'watcher.lock').write_bytes(b''); (work / 'watcher.lock').chmod(0o600)
        checkout = work / 'checkout'
        b.git(f.checkout, 'clone', '--quiet', '--no-local', '--no-hardlinks', str(f.checkout), str(checkout))
        pipeline.source._git(checkout, ['checkout', '--quiet', '--detach', f.core]); checkout.chmod(0o700)
        old = {'go_erp_live_updates.py': b'ISOLATED_OLD_NATIVE_RUNTIME = True\n'}
        new = {'go_erp_live_updates.py': b'ISOLATED_REVIEWED_NATIVE_RUNTIME = True\n'}
        old_pins = {name: u.blob(raw) for name, raw in old.items()}
        new_pins = {name: u.blob(raw) for name, raw in new.items()}
        old_runtime = u.install_runtime(releases, old, old_pins)
        inventory = f.snapshot['metadata']['inventory']
        state = {'format': 1, 'base_revision': f.core, 'counter': 61,
            'version_floor': '0.4.4-preview.60', 'sequence_floor': 123,
            'last_remote_sha': '7' * 40,
            'observed': pipeline.source._metadata(f.core, inventory),
            'desired': {'commit': '7' * 40, 'version': '0.4.4-preview.61', 'status': 'retry-build',
                        'failures': 1, 'retry_at': 900, 'pushed': True},
            'published': {'commit': '6' * 40, 'release': {'version': '0.4.4-preview.60'}},
            'published_history': [{'version': '0.4.4-preview.60'}],
            'dependency_guard': {'synthetic': 'unchanged live dependency digest'}}
        config = {'format': 1, 'repository': 'omarmakramelbaz-hub/F-Backend', 'repository_id': 1311412084,
            'branch': 'codex/go-erp-live-source-20261021', 'base_revision': f.core,
            'token': 'SYNTHETIC_NON_NETWORK_TOKEN_000000'}
        state_path, config_path, unit = work / 'state/current.json', config_dir / 'live-updates.json', systemd / 'go-erp-live-updates.service'
        for path, raw, mode in [(state_path, b.canonical(state), 0o600),
            (config_path, b.canonical(config), 0o600),
            (unit, b.unit_bytes(u, old_runtime, f.worker.live, work, config_path), 0o644)]:
            path.write_bytes(raw); path.chmod(mode)
        constants = {'OLD_SEED': f.core, 'OLD_RUNTIME': old_runtime.name, 'OLD_PINS': old_pins,
            'OLD_ROSTER': tuple(old_pins), 'ROSTER': tuple(new_pins), 'NEW_SEED': f.seed, 'CORE': f.core,
            'PREFIX': 'successor-isolated-engine-', 'REMOTE': str(f.checkout),
            'EXPECTED_SOURCE': {'sha256': 'a' * 64}, 'EXPECTED_INVENTORY': f.snapshot['metadata']['inventorySha256']}
        commands = []
        def migration(value, old_seed, new_seed, publication=None):
            self.assertEqual(old_seed, f.core); self.assertEqual(new_seed, f.seed)
            with patch.multiple(warm, OLD_SEED=f.core, SEED=f.seed):
                return warm.migrate_state(value, pipeline.source, pipeline.source)
        constants['migrated_state'] = migration
        def probe(_target, _live, _checkout, value, _pins, revision, _utility):
            self.assertEqual(value['counter'], 61); self.assertEqual(revision, f.seed)
            self.assertEqual(value['desired'], state['desired'])
            return {'inventory': inventory}
        def run(event=None):
            with patch.multiple(b, **constants):
                return b.upgrade(new, new_pins, utility=u, live=f.worker.live, work=work,
                    releases=releases, unit=unit, config=config_path, new_seed=f.seed, core=f.core,
                    old_pins=old_pins, probe=probe, publication=lambda *_args: None,
                    remote=lambda *_args: None, command=lambda args: commands.append(args),
                    verify_unit=lambda *_args, **_kwargs: None, event=event)
        return type('PhysicalFixture', (), {'git': f, 'work': work, 'checkout': checkout,
            'state': state, 'state_path': state_path, 'config_path': config_path, 'unit': unit,
            'old_runtime': old_runtime, 'new_runtime': releases / u.runtime_name(new), 'run': staticmethod(run)})()

    def assert_migrated_and_naturally_allocate62(self, f, result):
        self.assertTrue(result.get('ok'), result)
        current = json.loads(f.state_path.read_bytes())
        original = copy.deepcopy(f.state)
        self.assertEqual(current['base_revision'], f.git.seed)
        self.assertEqual(current['counter'], 61)
        self.assertEqual({key: value for key, value in current.items() if key not in ('base_revision', 'observed')},
                         {key: value for key, value in original.items() if key not in ('base_revision', 'observed')})
        self.assertEqual(json.loads(f.config_path.read_bytes())['base_revision'], f.git.seed)
        self.assertEqual(pipeline.source._git(f.checkout, ['rev-parse', 'HEAD']).decode().strip(), f.git.seed)
        self.assertIn(str(f.new_runtime).encode(), f.unit.read_bytes())
        audits = list(f.work.glob('successor-isolated-engine-*'))
        self.assertTrue(any((audit / 'completed.json').exists() for audit in audits))
        f.git.worker.checkout = f.checkout; f.git.worker.state_file = f.state_path; f.git.worker.state = current
        self.assertEqual(f.git.worker.next_counter(), 62)
        self.assertEqual(json.loads(f.state_path.read_bytes())['counter'], 62)
        self.assertEqual(json.loads(f.state_path.read_bytes())['desired'], original['desired'])

    def test_original_engine_changes_four_slots_without_rewinding_failed61(self):
        f = self.physical()
        self.assert_migrated_and_naturally_allocate62(f, f.run())

    def test_hard_exit_after_each_exchange_recovers_via_original_engine(self):
        for failed_slot in b.SLOTS:
            with self.subTest(failed_slot=failed_slot):
                f = self.physical()
                child = os.fork()
                if child == 0:
                    def crash(event, slot):
                        if event == 'after-exchange' and slot == failed_slot: os._exit(75)
                    try: f.run(crash)
                    except BaseException: os._exit(76)
                    os._exit(77)
                _pid, status = os.waitpid(child, 0)
                self.assertTrue(os.WIFEXITED(status)); self.assertEqual(os.WEXITSTATUS(status), 75)
                self.assertTrue(any((audit / 'intent.json').exists()
                    and not (audit / 'completed.json').exists() for audit in f.work.glob('successor-isolated-engine-*')))
                result = f.run()
                self.assertTrue(result.get('recovered_previous_interruption'), result)
                self.assert_migrated_and_naturally_allocate62(f, result)


if __name__ == '__main__': unittest.main()
