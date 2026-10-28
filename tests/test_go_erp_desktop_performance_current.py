import copy
from contextlib import contextmanager
import hashlib
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


def pinned_fixture(name, digest):
    path = ROOT / 'tests/fixtures/go_erp_desktop_performance' / name
    raw = path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != digest:
        raise AssertionError('The legacy reader fixture differs from its approved public bytes')
    spec = importlib.util.spec_from_file_location('legacy_performance_test_' + path.stem, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


legacy_source = pinned_fixture('source_root.py', '0e6cade4b9fd20a6a73ce7a79a587e67c69b9cecb1b6c852a62c531fcd2c5375')
legacy_activation = pinned_fixture('activation_root.py', 'ce2894a4369d0896de323ad99d92e5bad2c48c3d986053586b16d13d0f4b013f')


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
            io=SimpleNamespace(parse=lambda raw: json.loads(raw), read=self.forbidden),
            template=SimpleNamespace(quiet=lambda _engine: None), _load=lambda: copy.deepcopy(self.flow),
            _native_configuration=self.factory,
            package=SimpleNamespace(repository=Path('/private/existing-release.git'), revision='1e651117deabb4c6935933ee424237d82bd316cb'))
        self.env_reader_calls = 0
        fixture = self
        class EnvironmentReader:
            @staticmethod
            def _read(scope, path):
                if scope.io is not fixture.operator.io or path != current.ROOT / '.env':
                    raise AssertionError('The pinned environment-reader delegation changed')
                fixture.env_reader_calls += 1
                return fixture.env, None
        self.operator.modules = {'activation': SimpleNamespace(Activation=EnvironmentReader)}

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
        self.assertEqual(fixture.env_reader_calls, 1)
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


class RealPinnedEnvironmentReader(unittest.TestCase):
    """Exercise the exact approved reader against real filesystem metadata.

    Only the deployment root/application identity is mapped to the temporary
    test directory/current account. Both legacy fixture source files remain
    byte-identical to their compiled public pins.
    """
    @contextmanager
    def isolated(self, mode=0o600):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            environment = root / '.env'
            fixture = Fixture()
            environment.write_bytes(fixture.env)
            environment.chmod(mode)
            before = current.ROOT / 'storage/app/desktop-updates/current.json'
            fixture.files[root / 'storage/app/desktop-updates/current.json'] = fixture.files.pop(before)
            fixture.operator.io = legacy_source
            fixture.operator.modules['activation'] = legacy_activation
            with fixture.bound(), patch.object(current, 'ROOT', root), \
                    patch.object(legacy_activation, 'ROOT', root), \
                    patch.object(legacy_activation, 'APP_UID', os.geteuid()), \
                    patch.object(legacy_activation, 'APP_GID', os.getegid()):
                yield fixture, environment

    @contextmanager
    def forbid_writes(self):
        original_open = os.open
        original_fdopen = os.fdopen
        opened = []
        write_flags = os.O_WRONLY | os.O_RDWR | os.O_CREAT | os.O_TRUNC | os.O_APPEND

        def readonly_open(path, flags, *args, **kwargs):
            self.assertEqual(flags & write_flags, 0, 'Environment verification opened a writable descriptor')
            opened.append((path, flags))
            return original_open(path, flags, *args, **kwargs)

        def readonly_fdopen(fd, mode='r', *args, **kwargs):
            self.assertFalse(any(part in mode for part in ('w', 'a', '+', 'x')))
            return original_fdopen(fd, mode, *args, **kwargs)

        def forbidden(*_args, **_kwargs):
            raise AssertionError('A permission regression must not repair or rewrite the environment')

        with patch.object(os, 'open', readonly_open), patch.object(os, 'fdopen', readonly_fdopen), \
                patch.object(os, 'chmod', forbidden), patch.object(os, 'chown', forbidden), \
                patch.object(os, 'write', forbidden), patch.object(Path, 'write_bytes', forbidden), \
                patch.object(Path, 'write_text', forbidden):
            yield opened

    def snapshot(self, path):
        value = path.lstat()
        return ((value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
                 value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns), path.read_bytes())

    def test_original_reader_accepts_private_and_public_approved_modes_without_repairs(self):
        for mode in (0o600, 0o640, 0o644):
            with self.subTest(mode=oct(mode)), self.isolated(mode) as (fixture, environment):
                before = self.snapshot(environment)
                with self.forbid_writes() as opened:
                    observation = fixture.observe()
                self.assertGreater(len(opened), 0)
                self.assertEqual(observation['private_hashes'][str(environment)], current.sha(fixture.env))
                self.assertEqual(self.snapshot(environment), before)

    def test_general_public_source_reader_remains_strict_about_0600(self):
        with self.isolated(0o600) as (_fixture, environment):
            before = self.snapshot(environment)
            with self.forbid_writes(), self.assertRaisesRegex(legacy_source.Stop, 'FILE_UNSAFE'):
                legacy_source.read(environment, os.geteuid(), os.getegid())
            self.assertEqual(self.snapshot(environment), before)

    def test_original_reader_rejects_symlink_and_hardlink_without_repair(self):
        for kind in ('symlink', 'hardlink'):
            with self.subTest(kind=kind), self.isolated(0o600) as (fixture, environment):
                retained = environment.with_name('retained-environment')
                environment.rename(retained)
                if kind == 'symlink':
                    environment.symlink_to(retained)
                else:
                    os.link(retained, environment)
                before = self.snapshot(environment)
                retained_before = self.snapshot(retained)
                with self.forbid_writes(), self.assertRaisesRegex(legacy_activation.Stop, 'FILE_UNSAFE'):
                    fixture.observe()
                self.assertEqual(self.snapshot(environment), before)
                self.assertEqual(self.snapshot(retained), retained_before)

    def test_original_reader_rejects_writable_unapproved_modes_without_chmod(self):
        for mode in (0o602, 0o664, 0o666):
            with self.subTest(mode=oct(mode)), self.isolated(mode) as (fixture, environment):
                before = self.snapshot(environment)
                with self.forbid_writes(), self.assertRaisesRegex(legacy_activation.Stop, 'FILE_UNSAFE'):
                    fixture.observe()
                self.assertEqual(self.snapshot(environment), before)

    def test_original_reader_rejects_foreign_file_owner_metadata(self):
        with self.isolated(0o600) as (fixture, environment):
            # Managed/id-mapped filesystems can reject chown even for UID0.
            # Read the real inode, then fault-inject only its returned owner
            # metadata; all reader code and filesystem access remain original.
            original_stat = os.stat
            def foreign_owner(path, *args, **kwargs):
                value = original_stat(path, *args, **kwargs)
                if path == '.env' and 'dir_fd' in kwargs:
                    fields = {name: getattr(value, name) for name in dir(value) if name.startswith('st_')}
                    fields.update(st_uid=12345, st_gid=12345)
                    return SimpleNamespace(**fields)
                return value
            before = self.snapshot(environment)
            with patch.object(os, 'stat', foreign_owner), self.forbid_writes(), self.assertRaisesRegex(legacy_activation.Stop, 'FILE_UNSAFE'):
                fixture.observe()
            self.assertEqual(self.snapshot(environment), before)

    def test_accepted_0600_file_still_requires_exact_enabled_environment_hash(self):
        with self.isolated(0o600) as (fixture, environment):
            environment.write_bytes(fixture.env + b'UNREVIEWED_PRIVATE_CHANGE=1\n')
            before = self.snapshot(environment)
            with self.forbid_writes(), self.assertRaisesRegex(current.Stop, 'CURRENT_ENABLED_ENV_CHANGED'):
                fixture.observe()
            self.assertEqual(self.snapshot(environment), before)


if __name__ == '__main__':
    unittest.main()
