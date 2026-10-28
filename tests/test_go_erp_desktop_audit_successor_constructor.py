"""Execute the configured frozen constructor and original manual transition.

Native child/service boundaries are isolated; these fixtures authorize nothing.
"""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

BASE = Path(__file__).resolve().parents[1]
ROOT = BASE.parent
FIXTURES = BASE / 'tests/fixtures/go_erp_desktop_performance'
FALLBACK = ROOT / 'perf_operator'


def fixture_file(name):
    target = FIXTURES / name
    return target if target.exists() else FALLBACK / 'tests/fixtures/go_erp_desktop_performance' / name


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value)
    return value


roll = load('audit_successor_configure_constructor', BASE / 'deployment/upgrade_go_erp_desktop_audit_successor_root.py')
h = load('audit_successor_constructor_plan', BASE / 'deployment/go_erp_desktop_performance_successor.py')
root_api = load('audit_successor_constructor_original_root',
    fixture_file('root_operator.py'))
payloads = load('audit_successor_constructor_original_manual_payloads',
    fixture_file('workspace_payloads.py'))
adapter = load('audit_successor_constructor_seed_adapter',
    (BASE / 'deployment/go_erp_desktop_performance_payloads.py')
    if (BASE / 'deployment/go_erp_desktop_performance_payloads.py').exists()
    else (FALLBACK / 'deployment/go_erp_desktop_performance_payloads.py'))
utility = load('audit_successor_constructor_original_utility',
    fixture_file('capacity_utility_root.py'))
data = load('audit_successor_constructor_data', BASE / 'tests/test_go_erp_desktop_performance_successor.py')


class ConfiguredConstructor(unittest.TestCase):
    def fixture(self):
        value = object.__new__(roll.Successor)
        value.h = h; value.holder = {}; value.outer = Mock(return_value=True); value._review_seed = Mock()
        native_names = ('go_erp_live_source.py', 'go_erp_live_updates.py', 'publish_go_erp_update.py',
            'desktop_dashboard_server_preflight.py', 'diagnose_desktop_dashboard_current_source.py',
            'go_erp_current_candidate_ci.yml', 'verify_go_erp_current_candidate_ci.py')
        failed = {name: ('ISOLATED_FIXTURE = ' + repr(name) + '\n').encode() for name in native_names}
        for name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
            failed[name] = ("PREVIEW31_SEED = '" + h.FAILED_SEED + "'\n"
                + 'PREVIEW31_POLICY = ' + repr(h.POLICY) + '\n').encode()
        value.modules = {'payloads': adapter}
        value.failed_values = failed
        value.new_values = {name: adapter.rebind_seed(raw, h.FAILED_SEED, roll.SEED)
            if name in ('go_erp_live_source.py', 'go_erp_live_updates.py') else raw
            for name, raw in failed.items()}
        value.new_native_pins = value.new_cohort_pins(value.new_values)
        old_runtime = Path('/opt/go-erp-live-updates/releases') / utility.runtime_name(failed)
        value.new_runtime = Path('/opt/go-erp-live-updates/releases') / utility.runtime_name(value.new_values)
        old_cohort = {'seed': 'a' * 40, 'policy': h.POLICY,
            'runtime': Path('/opt/go-erp-live-updates/releases/policy-' + 'b' * 64),
            'unit_sha256': 'c' * 64, 'pins': value.new_cohort_pins(failed)}
        failed_cohort = {'seed': h.FAILED_SEED, 'policy': h.POLICY,
            'runtime': old_runtime, 'unit_sha256': roll.sha(utility.service_unit(old_runtime)),
            'pins': value.new_cohort_pins(failed)}
        tick = fixture_file('manual_tick_template.py').read_bytes()
        broker = fixture_file('manual_broker_template.py').read_bytes()
        value.failed_manual = payloads.manual(tick, broker, previous=old_cohort, new=failed_cohort)
        value.failed_manual['go_erp_published_download_root.py'] = b'ARCHIVE_FIXTURE = True\n'
        value.failed = SimpleNamespace(runtime=old_runtime)
        pins, _job, _state = data.fixture(); pins['old_runtime'] = old_runtime.name
        value.pins = pins
        actual_template = fixture_file('warm_start_root.py')
        base_raw = fixture_file('preview31_base_root.py').read_bytes()
        utility_raw = fixture_file('capacity_utility_root.py').read_bytes()
        reads = []
        def file(name, revision=None):
            reads.append((name, revision))
            if name == 'deployment/upgrade_go_erp_warm_start_root.py': return actual_template.read_bytes()
            if name.endswith('import422-prior/upgrade_go_erp_preview31_watcher_root.py'): return base_raw
            if name.endswith('import422-prior/upgrade_go_erp_capacity_watcher_root.py'): return utility_raw
            if name == 'deployment/go_erp_owner_fast_open_tick_root.py': return tick
            if name == 'deployment/go_erp_owner_fast_open_manual_root.py': return broker
            return b'UNRELATED_PACKAGE_BYTES\n'
        value.package = SimpleNamespace(file=file, repository=Path('/private/release.git'), revision=roll.ROOT_RELEASE)
        factory = Mock(return_value=Mock())
        value.o = SimpleNamespace(utility=utility,
            modules={'payloads': payloads, 'acceptance': SimpleNamespace(WorkspaceAcceptance=factory)},
            pins={'helpers': {'native': 'd' * 64}})
        value.old = SimpleNamespace(root_api=root_api,
            native=SimpleNamespace(TEMPLATE_RELEASE='12d74b4f35737a2b1e271657b159137538c3edaf',
                TEMPLATE_SHA256='70bfb190e3b53fcb63ee76eb3ebfe16621be524e65ce623b4418386550081861'),
            acceptance_pins={'contract_sha256': 'e' * 64})
        value.io = SimpleNamespace(); value.e = SimpleNamespace()
        template = value.configure()
        return SimpleNamespace(value=value, template=template, reads=reads, factory=factory)

    def test_original_constructor_reads_old_tick_only_through_startup_alias(self):
        f = self.fixture(); template = f.template; value = f.value
        release = template.Release(value.package.repository, roll.ROOT_RELEASE)
        old_tick = value.failed_manual['go_erp_manual_tick_root.py']
        self.assertEqual(release.file('deployment/go_erp_startup_session_tick_root.py', roll.ROOT_RELEASE), old_tick)
        target_tick = release.file('deployment/go_erp_shared_workspace_tick_root.py', roll.ROOT_RELEASE)
        self.assertNotEqual(target_tick, old_tick)
        new_tick = template.module(target_tick, 'test_successor_target_tick', template.MANUAL_PINS['go_erp_manual_tick_root.py'])
        self.assertEqual(new_tick.NEW_COHORT['seed'], roll.SEED)
        self.assertEqual(new_tick.NEW_COHORT['runtime'], value.new_runtime)
        self.assertEqual(new_tick.OLD_COHORT['seed'], h.FAILED_SEED)
        original_module = template.module
        loaded = []
        class Directory:
            def __init__(self, path, *_args): self.path = path
            def close(self): pass
        def module(raw, name, expected, git_blob=False):
            item = original_module(raw, name, expected, git_blob)
            loaded.append(name)
            if name == 'close_native_utility':
                item.install_runtime = Mock(return_value=value.new_runtime)
                item.Directory = Directory
                item.validate_runtime = Mock()
                def module_at(folder, filename, pin):
                    payload = value.failed_values if folder.path == value.failed.runtime else value.new_values
                    self.assertEqual(template.blob(payload[filename]), pin)
                    return original_module(payload[filename], 'actual-loaded-' + filename, pin, True)
                item.module_at = module_at
            elif name == 'warm_start_previous_native_tick':
                item.load_runtime = lambda _cohort: SimpleNamespace()
            return item
        read = Mock(side_effect=lambda path, *_args, **_kwargs: (value.failed_values[Path(path).name], None))
        with patch.object(template, 'module', module), \
                patch.object(template.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=1001, pw_gid=1003)):
            context = template.Context(release, SimpleNamespace(read_file=read))
        self.assertEqual(context.payloads, value.new_values)
        self.assertEqual(context.runtime, value.new_runtime)
        self.assertEqual(context.old_tick.NEW_COHORT['seed'], h.FAILED_SEED)
        self.assertEqual(context.old_payloads, value.failed_values)
        self.assertEqual(context.base.OLD_SEED, h.FAILED_SEED)
        self.assertEqual(context.base.NEW_SEED, roll.SEED)
        self.assertIn('close_native_transaction', loaded)
        self.assertIn('warm_start_previous_native_tick', loaded)
        f.factory.assert_called_once()
        self.assertEqual(context.approved_worker_sha256, roll.sha(value.new_values['go_erp_live_updates.py']))

    def test_original_manual_uses_new_shared_paths_and_preserves_old_receipt_audit(self):
        f = self.fixture(); template = f.template; value = f.value
        release = template.Release(value.package.repository, roll.ROOT_RELEASE)
        target = {name: release.file(path) for name, path in template.PACKAGE_PROGRAM_PATHS.items()}
        self.assertNotEqual(target['go_erp_manual_tick_root.py'], value.failed_manual['go_erp_manual_tick_root.py'])
        with tempfile.TemporaryDirectory(prefix='go-erp-successor-original-manual-') as temporary:
            root = Path(temporary); control = root / 'control'; systemd = root / 'systemd'; releases = root / 'releases'
            (control / 'private/audits').mkdir(parents=True); systemd.mkdir(); releases.mkdir()
            def install(values, _folder):
                folder = releases / roll.sha(roll.canonical(dict(sorted({name: roll.sha(raw) for name, raw in values.items()}.items()))))
                folder.mkdir(exist_ok=True)
                for name, raw in values.items(): (folder / name).write_bytes(raw)
                return folder
            old_runtime = install(value.failed_manual, releases)
            def units(folder): return {template.WATCH: b'FIXED_MANUAL_PATH\n',
                template.SERVICE: ('ExecStart=' + str(folder / 'go_erp_manual_updates_root.py') + '\n').encode()}
            previous = {'format': 1, 'kind': 'go-erp-manual-installation', 'runtime': old_runtime.name,
                'units': {name: roll.sha(raw) for name, raw in units(old_runtime).items()}}
            receipt = control / 'private/installation.json'; receipt.write_bytes(roll.canonical(previous))
            for name, raw in units(old_runtime).items(): (systemd / name).write_bytes(raw)
            commands = []
            engine = SimpleNamespace(sha=roll.sha, canonical=roll.canonical, install_runtime=install,
                directory=lambda *_args: None, units=units, run=lambda args: commands.append(args),
                read_file=lambda path, *_args, **_kwargs: (path.read_bytes(), None),
                atomic_write=lambda path, raw, *_args: path.write_bytes(raw))
            context = SimpleNamespace(e=engine, release=release, base=SimpleNamespace(parse=json.loads))
            with patch.multiple(template, CONTROL=control, MANUAL_RELEASES=releases, SYSTEMD=systemd):
                # Bind uses the actual original general method; no manual body is replaced.
                template.Context.manual(context)
            self.assertEqual(json.loads(receipt.read_bytes())['runtime'], context.manual_runtime.name)
            self.assertNotEqual(context.manual_runtime, old_runtime)
            self.assertEqual((context.manual_runtime / 'go_erp_manual_tick_root.py').read_bytes(), target['go_erp_manual_tick_root.py'])
            audit = next((control / 'private/audits').glob('warm-start-manual-*'))
            self.assertEqual((audit / 'before-receipt').read_bytes(), roll.canonical(previous))
            self.assertEqual(commands, [['/usr/bin/systemctl', 'daemon-reload']])


if __name__ == '__main__': unittest.main()
