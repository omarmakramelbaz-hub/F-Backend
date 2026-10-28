#!/usr/bin/env python3
"""Read/reconstruct the exact currently installed shared-workspace cohort.

The separately pinned loader supplies the existing root Operator and held-lock
verifier. No Context is constructed here: its constructor installs a runtime.
No service is stopped, no file is written, and no application code is executed.
Returned underscore-prefixed fields contain public code kept in memory, never
credentials. The remaining observation belongs in the root-private journal.
"""
import ast
import copy
from contextlib import contextmanager, ExitStack
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
from types import SimpleNamespace

CORE = '052e69008da7e330815b376824cd8afc18cf04c1'
SEED = 'dc06af6f8e4ef9ea03a3fb9c728d26acb2f8193a'
CANDIDATE = 'd1976ce02c553939032241e7e724d26a9bbe7190'
VERSION = '0.4.4-preview.60'
INVENTORY = 'bb8960a67f6ef51039c33627e25cb509ba9bd455ef736c0787000340dbeaf99b'
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
ROOT_PINS_SHA256 = 'ed1fd7e640ac473aecc6c8a9171754f36bad048234ad28eddedb7c33130a7237'
ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
CONTROL = Path('/var/lib/go-erp-manual-updates')
MANUAL_RELEASES = Path('/opt/go-erp-manual-updates/releases')
CONFIG = Path('/etc/go-erp/live-updates.json')
SYSTEMD = Path('/etc/systemd/system')
TICK_PATH = 'deployment/go_erp_shared_workspace_tick_root.py'
STARTUP_ALIAS = 'deployment/go_erp_startup_session_tick_root.py'
REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_CURRENT_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def _literal(node):
    # The generated cohort literal uses only dict/list/tuple/scalars and one
    # Path literal. Never evaluate arbitrary Python from a manual helper.
    if isinstance(node, ast.Constant) and type(node.value) in (str, int, bool, type(None)):
        return node.value
    if isinstance(node, ast.Dict):
        keys = [_literal(key) for key in node.keys]
        need(all(isinstance(key, str) for key in keys) and len(keys) == len(set(keys)), 'COHORT_KEYS_CHANGED')
        return dict(zip(keys, (_literal(value) for value in node.values)))
    if isinstance(node, (ast.List, ast.Tuple)):
        values = [_literal(value) for value in node.elts]
        return tuple(values) if isinstance(node, ast.Tuple) else values
    if (isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id == 'Path'
            and len(node.args) == 1 and not node.keywords
            and isinstance(node.args[0], ast.Constant) and isinstance(node.args[0].value, str)):
        return Path(node.args[0].value)
    raise Stop('GO_ERP_DESKTOP_PERFORMANCE_CURRENT_COHORT_LITERAL_CHANGED')


def cohort_from_tick(raw):
    need(isinstance(raw, bytes) and 0 < len(raw) <= 2 * 1024**2, 'TICK_BYTES_INVALID')
    tree = ast.parse(raw.decode('utf-8'))
    rows = [node for node in tree.body if isinstance(node, ast.Assign) and len(node.targets) == 1
            and isinstance(node.targets[0], ast.Name) and node.targets[0].id == 'NEW_COHORT']
    need(len(rows) == 1, 'COHORT_ASSIGNMENT_CHANGED')
    value = _literal(rows[0].value)
    need(isinstance(value, dict) and set(value) == {'pins', 'seed', 'policy', 'runtime', 'unit_sha256'}
         and value['seed'] == SEED and value['policy'] == POLICY
         and isinstance(value['runtime'], Path)
         and re.fullmatch(r'/opt/go-erp-live-updates/releases/policy-[a-f0-9]{64}', str(value['runtime']))
         and isinstance(value['unit_sha256'], str) and SHA.fullmatch(value['unit_sha256']), 'COHORT_IDENTITY_CHANGED')
    return value


def _names(directory):
    return {entry.name for entry in directory.iterdir()}


@contextmanager
def held_readonly(operator):
    """Acquire existing original locks without creating files/stopping units.

    The original utility's watcher_lock opens its existing descriptor read-only.
    Lock contention is a fixed busy failure, never a reason to stop a service.
    """
    operator.template.quiet(operator.engine)
    operator.io.directory(CONTROL / 'private', 0, 0, private=True)
    held = []
    with ExitStack() as stack:
        for name in ('install.lock', 'worker.lock'):
            path = CONTROL / 'private' / name
            parent = operator.io.parent_fd(path)
            stack.callback(os.close, parent)
            fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
            stack.callback(os.close, fd)
            before = os.fstat(fd)
            need(stat.S_ISREG(before.st_mode)
                 and (before.st_uid, before.st_gid, stat.S_IMODE(before.st_mode), before.st_nlink) == (0, 0, 0o600, 1),
                 'ORIGINAL_LOCK_UNSAFE')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise Stop('GO_ERP_DESKTOP_PERFORMANCE_CURRENT_ORIGINAL_LOCK_BUSY') from None
            held.append((name, parent, fd, operator.io.identity(before), operator.io.identity(os.fstat(parent))))
        watcher = stack.enter_context(operator.utility.watcher_lock(WORK))

        def verify():
            operator.io.directory(CONTROL / 'private', 0, 0, private=True)
            for name, parent, fd, before, parent_before in held:
                need(parent_before == operator.io.identity(os.fstat(parent))
                     == operator.io.identity((CONTROL / 'private').lstat()), 'ORIGINAL_LOCK_PARENT_CHANGED')
                need(before == operator.io.identity(os.fstat(fd))
                     == operator.io.identity(os.stat(name, dir_fd=parent, follow_symlinks=False)),
                     'ORIGINAL_LOCK_CHANGED')
            need(watcher() is not False, 'WATCHER_LOCK_CHANGED')
            operator.template.quiet(operator.engine)
            return True

        verify()
        yield verify
        verify()


def observe(operator, payload_helper, *, verify_locks):
    """Read the current cohort through the original engine under held locks.

    The existing source-bound Operator._native_configuration factory only
    derives code from verified retained inputs; constructing its Context would
    install a runtime and is deliberately excluded from this read-only path.
    """
    need(callable(verify_locks) and verify_locks() is not False, 'HELD_LOCK_REQUIRED')
    need(sha(canonical(operator.pins)) == ROOT_PINS_SHA256
         and operator.pins['core'] == CORE and operator.pins['seed'] == SEED
         and payload_helper.CURRENT_CORE == CORE and payload_helper.CURRENT_SEED == SEED
         and payload_helper.CURRENT_PINS == operator.pins['native_payloads'], 'CURRENT_CODE_BINDING_CHANGED')
    engine = operator.engine
    operator.template.quiet(engine)
    flow = operator._load()
    need(isinstance(flow, dict) and flow.get('phase') == 'ready'
         and (flow.get('activation') or {}).get('phase') == 'active'
         and flow.get('pending_activation') is None, 'CURRENT_ACTIVATION_NOT_COMPLETE')
    operator.flow = copy.deepcopy(flow)

    def forbidden_acceptance(*_args, **_kwargs):
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_CURRENT_NO_ACCEPTANCE_EFFECT_ALLOWED')

    configured = operator._native_configuration(verify_locks, forbidden_acceptance)
    need(configured.approved_pins['core'] == CORE and configured.approved_pins['seed'] == SEED,
         'CURRENT_FACTORY_BINDING_CHANGED')
    release = configured.Release(operator.package.repository, operator.package.revision)
    manual = {name: release.file(pin['path'])
              for name, pin in configured.approved_pins['manual_programs'].items()}
    manual_pins = {name: pin['sha256'] for name, pin in configured.approved_pins['manual_programs'].items()}
    need(set(manual) == {'go_erp_manual_tick_root.py', 'go_erp_manual_updates_root.py', 'go_erp_published_download_root.py'}
         and all(isinstance(raw, bytes) and sha(raw) == manual_pins[name] for name, raw in manual.items()),
         'GENERATED_MANUAL_BYTES_CHANGED')
    cohort = cohort_from_tick(manual['go_erp_manual_tick_root.py'])
    wanted = {name: (pin['blob'], pin['size'], pin['sha256']) for name, pin in payload_helper.CURRENT_PINS.items()}
    need(cohort['pins'] == wanted, 'CURRENT_NATIVE_PINS_CHANGED')
    private_hashes = {}

    def read(path, maximum=16 * 1024**2, modes=(0o600,)):
        raw = engine.read_file(path, maximum, uid=0, modes=modes)[0]
        private_hashes[str(path)] = sha(raw)
        return raw

    state = operator.io.parse(read(WORK / 'state/current.json'))
    config = operator.io.parse(read(CONFIG, 16384))
    publication = operator.io.parse(read(ROOT / 'storage/app/desktop-updates/current.json', 512 * 1024, (0o600, 0o644)))
    desired, published = state.get('desired') or {}, state.get('published') or {}
    need(type(state.get('counter')) is int and state['counter'] == 60
         and state.get('base_revision') == config.get('base_revision') == SEED
         and state.get('last_remote_sha') == desired.get('commit') == published.get('commit') == CANDIDATE
         and desired.get('status') == 'published' and desired.get('pushed') is True
         and desired.get('candidatePolicy') == POLICY
         and desired.get('version') == publication.get('version') == VERSION
         and desired.get('inventorySha256') == published.get('inventorySha256') == INVENTORY
         and publication == published.get('release') and publication.get('source_revision') == CANDIDATE,
         'CURRENT_PUBLICATION_CHANGED')
    installation = operator.io.parse(read(CONTROL / 'private/installation.json', 8192))
    manual_id = sha(canonical(dict(sorted(manual_pins.items()))))
    need(installation.get('format') == 1 and installation.get('kind') == 'go-erp-manual-installation'
         and installation.get('runtime') == manual_id, 'CURRENT_MANUAL_RECEIPT_CHANGED')
    manual_root = MANUAL_RELEASES / manual_id
    engine.directory(manual_root, 0, 0, 0o555, False)
    need(_names(manual_root) == set(manual), 'CURRENT_MANUAL_ROSTER_CHANGED')
    for name, raw in manual.items():
        need(read(manual_root / name, 2 * 1024**2, (0o444,)) == raw, 'CURRENT_MANUAL_BYTES_CHANGED')
    units = engine.units(manual_root)
    need(installation.get('units') == {name: sha(raw) for name, raw in units.items()}, 'CURRENT_MANUAL_UNITS_CHANGED')
    for name, raw in units.items():
        need(read(SYSTEMD / name, 65536, (0o644,)) == raw, 'CURRENT_MANUAL_UNIT_BYTES_CHANGED')
    engine.directory(cohort['runtime'], 0, 0, 0o755, False)
    need(_names(cohort['runtime']) == set(wanted), 'CURRENT_NATIVE_ROSTER_CHANGED')
    native = {}
    for name, pin in wanted.items():
        raw = read(cohort['runtime'] / name, 2 * 1024**2, (0o644,))
        need((payload_helper.blob(raw), len(raw), sha(raw)) == pin, 'CURRENT_NATIVE_BYTES_CHANGED')
        native[name] = raw
    need(sha(read(SYSTEMD / 'go-erp-live-updates.service', 65536, (0o644,))) == cohort['unit_sha256'],
         'CURRENT_NATIVE_UNIT_CHANGED')
    # The general source reader intentionally excludes private mode 0600.
    # Reuse the pinned activation reader's original environment policy without
    # constructing an activation adapter or changing any file permissions.
    env_raw, _env_info = operator.modules['activation'].Activation._read(
        SimpleNamespace(io=operator.io), ROOT / '.env')
    env_hash = sha(env_raw)
    need(env_hash == flow['prepared']['binding']['env_enabled_sha256'], 'CURRENT_ENABLED_ENV_CHANGED')
    private_hashes[str(ROOT / '.env')] = env_hash
    need(verify_locks() is not False, 'HELD_LOCK_CHANGED')
    operator.template.quiet(engine)
    profile = {'core': CORE, 'seed': SEED, 'runtime': cohort['runtime'].name, 'policy': POLICY,
               'operator_release': operator.package.revision, 'tick_path': TICK_PATH,
               'job_prefix': 'shared-workspace-', 'manual_pins': manual_pins}
    need(isinstance(profile['operator_release'], str) and REV.fullmatch(profile['operator_release']), 'CURRENT_RELEASE_INVALID')
    return {'format': 1, 'kind': 'go-erp-desktop-performance-current-observation',
            'candidate': CANDIDATE, 'version': VERSION, 'profile': profile,
            'private_hashes': private_hashes, '_native_payloads': native, '_manual_payloads': manual}


def record(observation):
    return copy.deepcopy({name: value for name, value in observation.items() if not name.startswith('_')})


def bootstrap_release(package, observation):
    """Supply only the reconstructed exact old tick to the predecessor reader."""
    profile = observation['profile']
    raw = observation['_manual_payloads']['go_erp_manual_tick_root.py']
    expected = profile['manual_pins']['go_erp_manual_tick_root.py']

    class Release:
        def __init__(self):
            self.repository, self.revision = package.repository, package.revision

        def git(self, *args):
            return package.git(*args)

        def file(self, name, revision=None):
            if name == TICK_PATH and revision == profile['operator_release']:
                need(sha(raw) == expected, 'CURRENT_TICK_BYTES_CHANGED')
                return raw
            return package.file(name, revision)

    return Release()


def bind_configured_release(configured, observation):
    """Retain the frozen Context while resolving its exact predecessor alias."""
    profile = observation['profile']
    raw = observation['_manual_payloads']['go_erp_manual_tick_root.py']
    expected = profile['manual_pins']['go_erp_manual_tick_root.py']
    predecessor = configured.approved_pins['predecessor']
    need(predecessor.get('counter') == 60 and predecessor.get('candidate') == CANDIDATE
         and predecessor.get('version') == VERSION, 'CONFIGURED_PREDECESSOR_CHANGED')
    original_release = configured.Release

    class Release(original_release):
        def file(self, name, revision=None):
            if revision == profile['operator_release'] and name in (TICK_PATH, STARTUP_ALIAS):
                need(sha(raw) == expected, 'CURRENT_TICK_BYTES_CHANGED')
                return raw
            return super().file(name, revision)

    configured.Release = Release
    return configured


def preflight(operator, payload_helper, *, seed, packaged_native_hashes):
    """Prove the real transformation on installed bytes with zero deployment.

    This returns only fixed status fields and public code hashes, never private
    observations, raw source, environment bytes, configuration or credentials.
    The final coordinator still requires current-source/candidate/CI receipts.
    """
    with held_readonly(operator) as verify:
        current = observe(operator, payload_helper, verify_locks=verify)
        derived = payload_helper.native(current['_native_payloads'], seed=seed,
                                        packaged_native_hashes=packaged_native_hashes)
        pins = {name: {'blob': payload_helper.blob(raw), 'bytes': len(raw), 'sha256': sha(raw)}
                for name, raw in sorted(derived.items())}
        # Repeat the read-only reconstruction and private-state hashes before
        # returning a proof from a long or contested read.
        repeated = observe(operator, payload_helper, verify_locks=verify)
        need(record(repeated) == record(current)
             and repeated['_native_payloads'] == current['_native_payloads']
             and repeated['_manual_payloads'] == current['_manual_payloads'], 'CURRENT_OBSERVATION_CHANGED')
        verify()
        return {'format': 1, 'kind': 'go-erp-desktop-performance-read-only-preflight',
                'current_candidate': CANDIDATE, 'current_version': VERSION,
                'current_core': CORE, 'current_seed': SEED, 'new_seed': seed,
                'read_only': True, 'original_locks_held': True, 'manual_only': True,
                'workspace_enabled_env_unchanged': True, 'current_native_payloads_verified': True,
                'current_manual_helpers_reconstructed': True, 'derived_native_pins': pins}
