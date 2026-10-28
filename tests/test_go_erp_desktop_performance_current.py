import copy
from contextlib import contextmanager
import importlib.util
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'deployment' / path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


current = load('current_for_test', 'go_erp_desktop_performance_current.py')
payloads = load('payloads_for_current_test', 'go_erp_desktop_performance_payloads.py')
NEXT_SEED = '11c739039e5c6320b3542d414682d755c7902790'


def target():
    result = dict(payloads.CURRENT_NATIVE_HASHES)
    for number, name in enumerate(sorted(payloads.CHANGED_NATIVE), 1):
        result[name] = str(number) * 64
    return result


class Fixture:
    """Synthetic root engine; this is not an installed-server receipt."""
    def __init__(self):
        self.reads, self.directory_calls = [], []
        self.factory_calls = 0
        worker = ("PREVIEW31_SEED = '" + current.SEED + "'\n"
                  "def verify_installed_startup_source(value):\n"
                  "    return (value.get('installedAsarCode') is True\n"
                  "         and value.get('packagedNativeHashes') == "
                  + repr(dict(sorted(payloads.CURRENT_NATIVE_HASHES.items()))) + ")\n").encode()
        self.native = {name: b'ORIGINAL = True\n' if name.endswith('.py') else b'name: original\n'
                       for name in payloads.CURRENT_PINS}
        self.native['go_erp_live_updates.py'] = worker
        self.native['go_erp_live_source.py'] = ("PREVIEW31_SEED = '" + current.SEED + "'\n").encode()
        self.native_pins = {name: {'blob': payloads.blob(raw), 'size': len(raw), 'sha256': payloads.sha(raw)}
                            for name, raw in self.native.items()}
        self.runtime = Path('/opt/go-erp-live-updates/releases/policy-' + 'a' * 64)
        self.native_unit = b'original native unit\n'
        self.cohort = {'seed': current.SEED, 'policy': current.POLICY, 'runtime': self.runtime,
                       'pins': {name: (pin['blob'], pin['size'], pin['sha256']) for name, pin in self.native_pins.items()},
                       'unit_sha256': current.sha(self.native_unit)}
        fields = {name: value for name, value in self.cohort.items() if name != 'runtime'}
        literal = repr(fields)[:-1] + ", 'runtime': Path(" + repr(str(self.runtime)) + ')}'
        self.manual = {'go_erp_manual_tick_root.py': ("raise RuntimeError('must not execute tick')\nNEW_COHORT = " + literal + '\n').encode(),
                       'go_erp_manual_updates_root.py': b'ORIGINAL = True\n',
                       'go_erp_published_download_root.py': b'ARCHIVE = True\n'}
        self.manual_pins = {name: current.sha(raw) for name, raw in self.manual.items()}
        self.manual_root = current.MANUAL_RELEASES / current.sha(current.canonical(dict(sorted(self.manual_pins.items()))))
        self.units = {'go-erp-manual-updates.service': b'original manual service\n',
                      'go-erp-manual-updates.path': b'original manual path\n'}
        self.env = b'PRIVATE_SECRET=must-not-leak\nDESKTOP_DASHBOARD_WORKSPACE_ENABLED=true\n'
        self.flow = {'phase': 'ready', 'activation': {'phase': 'active'}, 'pending_activation': None,
                     'prepared': {'binding': {'env_enabled_sha256': current.sha(self.env)}}}
        self.publication = {'version': current.VERSION, 'source_revision': current.CANDIDATE}
        self.state = {'counter': 60, 'base_revision': current.SEED, 'last_remote_sha': current.CANDIDATE,
                      'desired': {'commit': current.CANDIDATE, 'status': 'published', 'pushed': True,
                                  'candidatePolicy': current.POLICY, 'version': current.VERSION,
                                  'inventorySha256': current.INVENTORY},
                      'published': {'commit': current.CANDIDATE, 'inventorySha256': current.INVENTORY,
                                    'release': self.publication}}
        installation = {'format': 1, 'kind': 'go-erp-manual-installation', 'runtime': self.manual_root.name,
                        'units': {name: current.sha(raw) for name, raw in self.units.items()}}
        self.files = {current.WORK / 'state/current.json': current.canonical(self.state),
                      current.CONFIG: current.canonical({'base_revision': current.SEED, 'token': 'PRIVATE_CONFIG_TOKEN'}),
                      current.ROOT / 'storage/app/desktop-updates/current.json': current.canonical(self.publication),
                      current.CONTROL / 'private/installation.json': current.canonical(installation),
                      current.SYSTEMD / 'go-erp-live-updates.service': self.native_unit}
        self.files.update({self.manual_root / name: raw for name, raw in self.manual.items()})
        self.files.update({self.runtime / name: raw for name, raw in self.native.items()})
        self.files.update({current.SYSTEMD / name: raw for name, raw in self.units.items()})
        owner = self

        class Release:
            def __init__(self, repository, revision):
                self.repository, self.revision = repository, revision

            def file(self, path, revision=None):
                return owner.manual[Path(path).name]

        class Context:
            def __init__(self, *_args, **_kwargs):
                raise AssertionError('Context construction must never occur during preflight')

        self.configured = SimpleNamespace(Release=Release, Context=Context,
            approved_pins={'core': current.CORE, 'seed': current.SEED,
                           'manual_programs': {name: {'path': 'deployment/' + name, 'sha256': digest}
                                               for name, digest in self.manual_pins.items()}})
        self.pins = {'core': current.CORE, 'seed': current.SEED, 'native_payloads': self.native_pins}
        self.engine = SimpleNamespace(read_file=self.read_file, directory=self.directory,
                                      units=lambda _path: self.units,
                                      atomic_write=self.forbidden, run=self.forbidden)
        self.operator = SimpleNamespace(pins=self.pins, engine=self.engine,
            io=SimpleNamespace(parse=lambda raw: json.loads(raw), read=lambda *_args, **_kwargs: (self.env, None)),
            template=SimpleNamespace(quiet=lambda _engine: None), _load=lambda: copy.deepcopy(self.flow),
            _native_configuration=self.factory,
            package=SimpleNamespace(repository=Path('/private/existing-release.git'), revision='1e651117deabb4c6935933ee424237d82bd316cb'))

    def forbidden(self, *_args, **_kwargs):
        raise AssertionError('A read-only preflight must never mutate a file or service')

    def factory(self, verify, _acceptance):
        self.factory_calls += 1
        verify()
        return self.configured

    def read_file(self, path, maximum, **_kwargs):
        self.reads.append(Path(path))
        raw = self.files[Path(path)]
        if len(raw) > maximum:
            raise AssertionError('Bounded reader must be respected')
        return raw, None

    def directory(self, path, uid, gid, mode, create):
        self.directory_calls.append((path, uid, gid, mode, create))
        if create:
            raise AssertionError('A read-only preflight must not create directories')

    def names(self, path):
        return set(self.manual) if path == self.manual_root else set(self.native)

    @contextmanager
    def bound(self):
        with patch.object(current, 'ROOT_PINS_SHA256', current.sha(current.canonical(self.pins))), \
                patch.object(payloads, 'CURRENT_PINS', self.native_pins), \
                patch.object(current, '_names', self.names):
            yield self

    def observe(self):
        return current.observe(self.operator, payloads, verify_locks=lambda: True)


class CurrentCohort(unittest.TestCase):
    def test_observe_reconstructs_without_context_or_effects(self):
        fixture = Fixture()
        with fixture.bound():
            result = fixture.observe()
        self.assertEqual(result['_native_payloads'], fixture.native)
        self.assertEqual(result['_manual_payloads'], fixture.manual)
        self.assertEqual(result['profile']['job_prefix'], 'shared-workspace-')
        self.assertEqual(result['profile']['seed'], current.SEED)
        self.assertEqual(fixture.factory_calls, 1)
        self.assertTrue(all(call[-1] is False for call in fixture.directory_calls))
        serialized = json.dumps(current.record(result))
        self.assertNotIn('PRIVATE_CONFIG_TOKEN', serialized)
        self.assertNotIn('must-not-leak', serialized)
        self.assertNotIn('must not execute tick', serialized)

    def test_real_factory_pins_and_activation_are_required(self):
        fixture = Fixture()
        with fixture.bound(), patch.object(current, 'ROOT_PINS_SHA256', '0' * 64):
            with self.assertRaisesRegex(current.Stop, 'CURRENT_CODE_BINDING_CHANGED'):
                fixture.observe()
        for phase in ['observed', 'prepared', 'running']:
            fixture = Fixture()
            fixture.flow['phase'] = phase
            with fixture.bound(), self.assertRaisesRegex(current.Stop, 'CURRENT_ACTIVATION_NOT_COMPLETE'):
                fixture.observe()

    def test_changed_publication_or_config_is_rejected(self):
        for path, value in [(current.WORK / 'state/current.json', {'counter': 61}),
                            (current.CONFIG, {'base_revision': 'f' * 40}),
                            (current.ROOT / 'storage/app/desktop-updates/current.json', {'version': 'other'})]:
            fixture = Fixture()
            fixture.files[path] = current.canonical(value)
            with fixture.bound(), self.assertRaisesRegex(current.Stop, 'CURRENT_PUBLICATION_CHANGED'):
                fixture.observe()

    def test_changed_installed_manual_native_and_units_are_rejected(self):
        fixture = Fixture()
        targets = [fixture.manual_root / 'go_erp_manual_tick_root.py',
                   fixture.runtime / 'go_erp_live_updates.py',
                   current.SYSTEMD / 'go-erp-manual-updates.service',
                   current.SYSTEMD / 'go-erp-live-updates.service']
        for path in targets:
            fixture = Fixture()
            fixture.files[path] += b'changed\n'
            with fixture.bound(), self.subTest(path=path), self.assertRaises(current.Stop):
                fixture.observe()

    def test_original_enabled_environment_is_preserved_and_checked(self):
        fixture = Fixture()
        fixture.env += b'UNREVIEWED_CHANGE=true\n'
        with fixture.bound(), self.assertRaisesRegex(current.Stop, 'CURRENT_ENABLED_ENV_CHANGED'):
            fixture.observe()

    def test_tick_parser_never_evaluates_a_call(self):
        for raw in [b"NEW_COHORT = __import__('os').system('false')\n",
                    b"NEW_COHORT = {'runtime': Path(__import__('os').getcwd())}\n"]:
            with self.assertRaisesRegex(current.Stop, 'COHORT_LITERAL_CHANGED'):
                current.cohort_from_tick(raw)
        with self.assertRaisesRegex(current.Stop, 'COHORT_ASSIGNMENT_CHANGED'):
            current.cohort_from_tick(b'NEW_COHORT = {}\nNEW_COHORT = {}\n')

    def test_exact_tick_bridge_handles_both_frozen_aliases_only(self):
        fixture = Fixture()
        with fixture.bound():
            observation = fixture.observe()
        class Original:
            def __init__(self, *args):
                pass
            def file(self, name, revision=None):
                return ('original', name, revision)
        configured = SimpleNamespace(Release=Original, approved_pins={'predecessor': {
            'counter': 60, 'candidate': current.CANDIDATE, 'version': current.VERSION}})
        current.bind_configured_release(configured, observation)
        release = configured.Release()
        old_revision = observation['profile']['operator_release']
        self.assertEqual(release.file(current.TICK_PATH, old_revision), fixture.manual['go_erp_manual_tick_root.py'])
        self.assertEqual(release.file(current.STARTUP_ALIAS, old_revision), fixture.manual['go_erp_manual_tick_root.py'])
        self.assertEqual(release.file(current.TICK_PATH, None), ('original', current.TICK_PATH, None))
        self.assertEqual(release.file('unrelated.py', old_revision), ('original', 'unrelated.py', old_revision))

    def test_preflight_runs_real_transformation_but_returns_no_private_material(self):
        fixture = Fixture()
        @contextmanager
        def held(_operator):
            yield lambda: True
        with fixture.bound(), patch.object(current, 'held_readonly', held):
            proof = current.preflight(fixture.operator, payloads, seed=NEXT_SEED, packaged_native_hashes=target())
        self.assertEqual(fixture.factory_calls, 2)
        self.assertTrue(proof['read_only'])
        self.assertTrue(proof['current_manual_helpers_reconstructed'])
        self.assertEqual(set(proof['derived_native_pins']), set(fixture.native))
        serialized = json.dumps(proof)
        for forbidden in ['PRIVATE_CONFIG_TOKEN', 'PRIVATE_SECRET', 'private_hashes', 'must not execute tick', 'must-not-leak']:
            self.assertNotIn(forbidden, serialized)

    def test_preflight_rejects_changed_observation(self):
        fixture = Fixture()
        original = fixture.operator._load
        count = 0
        def load_flow():
            nonlocal count
            count += 1
            if count > 1:
                fixture.files[current.CONFIG] = current.canonical({'base_revision': 'f' * 40})
            return original()
        fixture.operator._load = load_flow
        @contextmanager
        def held(_operator):
            yield lambda: True
        with fixture.bound(), patch.object(current, 'held_readonly', held), self.assertRaises(current.Stop):
            current.preflight(fixture.operator, payloads, seed=NEXT_SEED, packaged_native_hashes=target())

    @unittest.skipUnless(os.geteuid() == 0, 'Existing root-owned lock behavior requires root')
    def test_readonly_locks_open_existing_files_and_never_create_missing_lock(self):
        with tempfile.TemporaryDirectory() as directory:
            control = Path(directory)
            private = control / 'private'
            private.mkdir(mode=0o700)
            for name in ['install.lock', 'worker.lock']:
                (private / name).touch(mode=0o600)
            calls = []
            def identity(value):
                return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid, value.st_nlink)
            def directory_check(*_args, **_kwargs):
                return None
            def parent_fd(path):
                return os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            @contextmanager
            def watcher(_work):
                yield lambda: True
            operator = SimpleNamespace(template=SimpleNamespace(quiet=lambda _engine: calls.append('quiet')),
                engine=SimpleNamespace(), io=SimpleNamespace(directory=directory_check, parent_fd=parent_fd, identity=identity),
                utility=SimpleNamespace(watcher_lock=watcher))
            before = {name: (private / name).stat().st_mtime_ns for name in ['install.lock', 'worker.lock']}
            with patch.object(current, 'CONTROL', control):
                with current.held_readonly(operator) as verify:
                    self.assertTrue(verify())
                    for name in ['install.lock', 'worker.lock']:
                        self.assertEqual((private / name).stat().st_mtime_ns, before[name])
                (private / 'worker.lock').unlink()
                with self.assertRaises(FileNotFoundError):
                    with current.held_readonly(operator):
                        self.fail('Missing original lock must not be created')
            self.assertFalse((private / 'worker.lock').exists())
            self.assertGreater(len(calls), 1)


if __name__ == '__main__':
    unittest.main()
