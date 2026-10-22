#!/usr/bin/env python3
"""Move only the private watcher to the exact reviewed snapshot-transport seed.

The application and database are never opened for writing. Four inode-bound
exchanges run while holding the watcher's existing lock. The new runtime loads
while the old seed is still coherent; checkout/state mismatches then fail closed
until configuration changes last. A private intent permits rollback only while
both sides are still exactly recognized; concurrent state is never overwritten.
"""
import copy
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import socket
import stat
import subprocess
import sys
import types
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
RELEASES = Path('/opt/go-erp-live-updates/releases')
UNIT = Path('/etc/systemd/system/go-erp-live-updates.service')
CONFIG = Path('/etc/go-erp/live-updates.json')
REMOTE = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
OLD_SEED = '7523ff6888a6e3d0a6f5b7712c553909f12c043b'
CORE = 'a0178ead801e8d39185cd0830171804141767995'
NEW_SEED = '52f7f5d22e5a2026e5bd5732bbb7893125b6ef9d'
UTILITY_BLOB = '60df7ec876f9634e0a7dcb4c5a420d0d5ecd9ae9'
OLD_RUNTIME = 'policy-527cf33d554676980a804b3eb26e32e6c867577dd0d8b05122ad179ae1034dc4'
OLD_PINS = {
    'go_erp_live_source.py': '50ce1b066918361606132bd8f573b7db06808897',
    'go_erp_live_updates.py': 'fc2464969b61168724ec4e2584117354b8518aac',
    'publish_go_erp_update.py': 'f9a7353b0705383844a576ab6d0f5b1662912bfb',
    'go_erp_current_candidate_ci.yml': 'af4d9bb97572c84e26cb6750d380adde2f7e9423',
    'verify_go_erp_current_candidate_ci.py': 'fb2c6ee364fae14062148846b654df8c96bba0de',
}
PINS = {
    'go_erp_live_source.py': '4048b8b54573140763831924c56223ecc5213c15',
    'go_erp_live_updates.py': '88d38dfb257ff0cac0464c3b88929bcff4009fd5',
    'publish_go_erp_update.py': 'f9a7353b0705383844a576ab6d0f5b1662912bfb',
    'go_erp_current_candidate_ci.yml': 'af4d9bb97572c84e26cb6750d380adde2f7e9423',
    'verify_go_erp_current_candidate_ci.py': 'fb2c6ee364fae14062148846b654df8c96bba0de',
}
ROSTER = tuple(sorted(OLD_PINS))
REV = re.compile(r'[a-f0-9]{40}\Z')
SAFE = 9007199254740991
PREFIX = 'snapshot-seed-upgrade-'
SLOTS = ('unit', 'checkout', 'state', 'config')
MAX_STATE = 16 * 1024 * 1024
ENV = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8',
       'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
       'GIT_CONFIG_SYSTEM': '/dev/null', 'GIT_TERMINAL_PROMPT': '0',
       'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_OPTIONAL_LOCKS': '0'}


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop(reason)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')) + '\n').encode()


def parse(data):
    def unique(pairs):
        result = {}
        for name, value in pairs:
            need(name not in result, 'DUPLICATE_JSON_FIELD')
            result[name] = value
        return result
    try:
        return json.loads(data, object_pairs_hook=unique)
    except (ValueError, UnicodeError):
        raise Stop('JSON_INVALID') from None


def load_utility(path):
    """No adjacent module executes until its exact reviewed Git blob matches."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and
             (before.st_uid, before.st_gid, before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o644)
             and 0 < before.st_size <= 2 * 1024 * 1024, 'UTILITY_UNSAFE')
        data = bytearray()
        while len(data) <= 2 * 1024 * 1024:
            chunk = os.read(fd, 65536)
            if not chunk:
                break
            data.extend(chunk)
        identity = lambda item: (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid,
            item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)
        need(identity(before) == identity(os.fstat(fd)) == identity(os.stat(path, follow_symlinks=False))
             and len(data) == before.st_size and blob(data) == UTILITY_BLOB,
             'UTILITY_PIN_INVALID')
    finally:
        os.close(fd)
    module = types.ModuleType('reviewed_snapshot_watcher_utility')
    module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(bytes(data), str(path), 'exec'), module.__dict__)
    return module


def checked_config(value, seed):
    need(isinstance(value, dict) and set(value) ==
         {'format', 'repository', 'repository_id', 'branch', 'base_revision', 'token'}
         and type(value['format']) is int and value['format'] == 1
         and value['repository'] == 'omarmakramelbaz-hub/F-Backend'
         and type(value['repository_id']) is int and value['repository_id'] == 1311412084
         and value['branch'] == 'codex/go-erp-live-source-20261021'
         and value['base_revision'] == seed, 'CONFIGURATION_INVALID')
    token = value['token']
    need(isinstance(token, str) and 20 <= len(token) <= 1024
         and all(33 <= ord(char) <= 126 for char in token), 'CONFIGURATION_INVALID')
    return value


def checked_state(value, seed):
    need(isinstance(value, dict) and value.get('format') == 1 and type(value.get('format')) is int
         and value.get('base_revision') == seed and type(value.get('counter')) is int
         and 0 <= value['counter'] <= SAFE, 'STATE_IDENTITY_INVALID')
    floor = value.get('version_floor')
    need(floor is None or isinstance(floor, str) and len(floor) <= 40 and
         re.fullmatch(r'0\.4\.4-preview\.[1-9][0-9]*', floor), 'VERSION_FLOOR_INVALID')
    need(type(value.get('sequence_floor', 0)) is int and 0 <= value.get('sequence_floor', 0) <= SAFE,
         'SEQUENCE_FLOOR_INVALID')
    need(isinstance(value.get('published_history', []), list)
         and (value.get('last_remote_sha') is None or isinstance(value.get('last_remote_sha'), str)
              and REV.fullmatch(value['last_remote_sha'])), 'STATE_HISTORY_INVALID')
    if floor:
        need(int(floor.rsplit('.', 1)[1]) <= SAFE, 'VERSION_FLOOR_INVALID')
    return value


def migrated_state(value, old_seed=OLD_SEED, new_seed=NEW_SEED):
    checked_state(value, old_seed)
    need(REV.fullmatch(new_seed) and new_seed != old_seed, 'NEW_SEED_INVALID')
    result = copy.deepcopy(value)
    floor = int(value['version_floor'].rsplit('.', 1)[1]) if value.get('version_floor') else 0
    result.update(base_revision=new_seed, observed=None, desired=None, published=None,
                  counter=max(25, floor, value['counter']),
                  version_floor='0.4.4-preview.' + str(max(25, floor)))
    return result


def git(root, *arguments, maximum=16 * 1024 * 1024):
    result = subprocess.run(['/usr/bin/git', '-c', 'credential.helper=', '-c', 'core.hooksPath=/dev/null',
        '-c', 'core.autocrlf=false', '-c', 'core.fsmonitor=false', '-c', 'http.followRedirects=false',
        '-c', 'http.sslVerify=true', '-C', str(root), *arguments],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        timeout=180, env=ENV)
    need(result.returncode == 0 and len(result.stdout) <= maximum, 'GIT_OPERATION_FAILED')
    return result.stdout


def checkout(root, revision, utility):
    directory = utility.Directory(root)
    internal = None
    try:
        internal = utility.Directory(root / '.git')
        need(git(root, 'rev-parse', 'HEAD').decode().strip() == revision
             and git(root, 'remote', 'get-url', 'origin').decode().strip() == REMOTE
             and not git(root, 'status', '--porcelain', '--untracked-files=all'), 'CHECKOUT_CHANGED')
        directory.verify(); internal.verify()
    finally:
        if internal is not None:
            internal.close()
        directory.close()


def stage_seed(target, revision, core, utility):
    need(REV.fullmatch(revision) and REV.fullmatch(core), 'RELEASE_PINS_PENDING')
    git(target, 'init', '--quiet', '--template=')
    git(target, 'remote', 'add', 'origin', REMOTE)
    git(target, 'fetch', '--quiet', '--depth=32', '--no-tags', '--no-recurse-submodules', 'origin', revision)
    git(target, 'checkout', '--quiet', '--detach', '--no-recurse-submodules', revision)
    checkout(target, revision, utility)
    directory = utility.Directory(target / 'deployment')
    try:
        name = 'verify_go_erp_media_capacity_seed.py'
        pin = git(target, 'rev-parse', 'HEAD:deployment/' + name).decode().strip()
        # A root entry uses umask 077, so Git may create public source as 0600.
        # Normalize only this exact pinned verifier inside the private staging
        # tree; the reviewed module loader keeps its strict 0644 requirement.
        fd = os.open(name, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory.fd)
        try:
            before = os.fstat(fd)
            mode = stat.S_IMODE(before.st_mode)
            need(mode in (0o600, 0o644), 'SEED_VERIFIER_MODE_INVALID')
            data = utility.read_fd(fd, utility.MAX_FILE, mode)
            need(blob(data) == pin and utility.exact_key(before) ==
                 utility.exact_key(os.stat(name, dir_fd=directory.fd, follow_symlinks=False)),
                 'SEED_VERIFIER_PIN_INVALID')
            if mode == 0o600:
                os.fchmod(fd, 0o644); os.fsync(fd); os.fsync(directory.fd)
        finally:
            os.close(fd)
        verifier = utility.module_at(directory, name, pin)
        need(getattr(verifier, 'CORE', None) == core, 'SEED_CORE_MISMATCH')
        result = verifier.validate_seed(target, revision, core=core)
        need(result.get('core') == core and result.get('seed') == revision, 'SEED_PROOF_INVALID')
    finally:
        directory.close()


def probe_runtime(target, live, seed, state, pins, revision, utility, web_uid=1001):
    directory = utility.Directory(target, 0o755)
    try:
        utility.validate_runtime(directory, pins)
        source = utility.module_at(directory, 'go_erp_live_source.py', pins['go_erp_live_source.py'])
        worker = utility.module_at(directory, 'go_erp_live_updates.py', pins['go_erp_live_updates.py'])
        need(getattr(source, 'TRANSPORT_SEED', None) == revision ==
             getattr(worker, 'TRANSPORT_SEED', None), 'TRANSPORT_POLICY_UNREVIEWED')
        policy = worker.Worker.candidate_policy(types.SimpleNamespace(
            config={'base_revision': revision}, source=source))
        need(policy == getattr(source, 'TRANSPORT_POLICY', None) ==
             getattr(worker, 'TRANSPORT_POLICY', None), 'TRANSPORT_POLICY_UNREVIEWED')
        dependencies = worker.dependency_snapshot(live, web_uid)
        need(state.get('dependency_guard') in (None, dependencies), 'DEPENDENCY_GUARD_MISMATCH')
        snapshot = source.scan_snapshot(live, seed, web_uid, None)
        need(isinstance(snapshot, dict) and isinstance(snapshot.get('metadata'), dict)
             and isinstance(snapshot['metadata'].get('inventory'), dict), 'SOURCE_PROBE_INVALID')
        utility.validate_runtime(directory, pins)
        return snapshot['metadata']
    finally:
        directory.close()


def unit_bytes(utility, runtime, live, work, config):
    # Reuse the reviewed unit text, replacing only the three fixed deployment
    # roots for isolated test fixtures. Production roots are exactly unchanged.
    value = utility.service_unit(runtime)
    for original, replacement in ((utility.CONFIG, config), (utility.WORK, work), (utility.ROOT, live)):
        value = value.replace(str(original).encode(), str(replacement).encode())
    return value


def stamp(utility, item, is_directory=False):
    return list(utility.directory_key(item) if is_directory else utility.key(item))


def slot_record(utility, parent, name, is_directory=False):
    parent.verify()
    item = os.stat(name, dir_fd=parent.fd, follow_symlinks=False)
    if is_directory:
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == 0 and not item.st_mode & 0o022,
             'CHECKOUT_DIRECTORY_UNSAFE')
        return {'identity': stamp(utility, item, True)}
    mode = 0o644 if name.endswith('.service') else 0o600
    data = utility.read_at(parent, name, MAX_STATE, mode)
    return {'identity': stamp(utility, item), 'sha256': digest(data)}


def recognize(utility, parent, name, wanted, is_directory=False):
    try:
        return slot_record(utility, parent, name, is_directory) == wanted
    except (OSError, Stop, utility.Stop):
        return False


def read_record(utility, parent, name, maximum, mode):
    before = os.stat(name, dir_fd=parent.fd, follow_symlinks=False)
    data = utility.read_at(parent, name, maximum, mode)
    need(utility.exact_key(before) == utility.exact_key(os.stat(name, dir_fd=parent.fd,
         follow_symlinks=False)), 'BEFORE_FILE_CHANGED')
    return data, {'identity': stamp(utility, before), 'sha256': digest(data)}


def exchange_slot(utility, audit, slot, parents, names):
    utility.exchange(audit.fd, 'exchange-' + slot + ('.service' if slot == 'unit' else ''),
                     parents[slot].fd, names[slot])
    os.fsync(audit.fd); os.fsync(parents[slot].fd)


def exchange_name(slot):
    return 'exchange-' + slot + ('.service' if slot == 'unit' else '')


def rollback(utility, audit, intent, parents, names, check_checkout):
    """Classify every side before changing any side; never erase unknown state."""
    switched = []
    for slot in SLOTS:
        directory = slot == 'checkout'
        before, after = intent['slots'][slot]['before'], intent['slots'][slot]['after']
        active_old = recognize(utility, parents[slot], names[slot], before, directory)
        held_new = recognize(utility, audit, exchange_name(slot), after, directory)
        active_new = recognize(utility, parents[slot], names[slot], after, directory)
        held_old = recognize(utility, audit, exchange_name(slot), before, directory)
        need((active_old and held_new) or (active_new and held_old), 'RECOVERY_REQUIRES_REVIEW')
        if slot == 'checkout':
            check_checkout(parents[slot].path / names[slot], intent['new_seed'] if active_new else intent['old_seed'], utility)
            check_checkout(audit.path / exchange_name(slot), intent['old_seed'] if active_new else intent['new_seed'], utility)
        if active_new:
            switched.append(slot)
    with utility.critical():
        for slot in reversed(switched):
            exchange_slot(utility, audit, slot, parents, names)
    return switched


def pending_audit(utility, work_directory):
    result = []
    for name in os.listdir(work_directory.fd):
        if re.fullmatch(re.escape(PREFIX) + r'[a-f0-9]{32}', name):
            directory = utility.Directory(work_directory.path / name, 0o700)
            try:
                roster = set(os.listdir(directory.fd))
                if 'intent.json' in roster and not roster.intersection({'completed.json', 'rolled-back.json'}):
                    result.append(name)
            finally:
                directory.close()
    need(len(result) <= 1, 'MULTIPLE_PENDING_MIGRATIONS')
    return result[0] if result else None


def upgrade(payloads, pins=PINS, *, utility=None, live=ROOT, work=WORK, releases=RELEASES,
            unit=UNIT, config=CONFIG, new_seed=NEW_SEED, core=CORE, old_pins=OLD_PINS,
            stage=stage_seed, probe=probe_runtime, check_checkout=checkout,
            command=None, verify_unit=None, event=None):
    """Run the scoped migration. Injected operations exist only for root FS tests."""
    u = utility or load_utility(Path(__file__).with_name('upgrade_go_erp_capacity_watcher_root.py'))
    command = command or u.system_command
    verify_unit = verify_unit or u.effective_unit
    report = {'format': 1, 'kind': 'go-erp-snapshot-watcher-upgrade', 'ok': False,
              'application_contents_changed': False, 'database_changed': False,
              'configuration_changed': False, 'state_changed': False, 'checkout_changed': False,
              'unit_changed': False, 'credentials_disclosed': False, 'rollback_complete': True}
    handles = []
    audit = None; intent = None; lock_entered = False; lock_manager = None
    parents = {}; names = {}; changed = False; phase = 'PREFLIGHT'
    try:
        need(REV.fullmatch(new_seed) and REV.fullmatch(core) and new_seed != OLD_SEED,
             'RELEASE_PINS_PENDING')
        need(set(payloads) == set(pins) == set(old_pins) == set(ROSTER), 'PAYLOAD_ROSTER_INVALID')
        for name, data in payloads.items():
            need(isinstance(data, bytes) and 0 < len(data) <= u.MAX_FILE and
                 REV.fullmatch(pins[name]) and blob(data) == pins[name], 'PAYLOAD_PIN_INVALID')
        old = u.Directory(releases / OLD_RUNTIME, 0o755)
        try:
            u.validate_runtime(old, old_pins)
        finally:
            old.close()
        lock_manager = u.watcher_lock(work); lock = lock_manager.__enter__(); lock_entered = True
        for slot, path, mode in [('unit', unit.parent, 0o755), ('checkout', work, 0o700),
                                 ('state', work / 'state', 0o700), ('config', config.parent, 0o700)]:
            parents[slot] = u.Directory(path, mode); handles.append(parents[slot])
        names = {'unit': unit.name, 'checkout': 'checkout', 'state': 'current.json', 'config': config.name}
        target = releases / u.runtime_name(payloads)
        before_unit = unit_bytes(u, releases / OLD_RUNTIME, live, work, config)
        after_unit = unit_bytes(u, target, live, work, config)
        verify_unit(unit, config, (releases / OLD_RUNTIME, target), allow_pending_reload=True)
        pending = pending_audit(u, parents['checkout'])
        if pending:
            phase = 'RECOVERY'
            audit = u.Directory(work / pending, 0o700); handles.append(audit)
            intent = parse(u.read_at(audit, 'intent.json', MAX_STATE, 0o600))
            need(isinstance(intent, dict) and intent.get('format') == 1 and
                 intent.get('old_seed') == OLD_SEED and intent.get('new_seed') == new_seed
                 and intent.get('core') == core and intent.get('runtime') == str(target)
                 and set(intent.get('slots', {})) == set(SLOTS), 'RECOVERY_INTENT_INVALID')
            # Recovery is deliberately exact. Higher state written by another
            # process is retained and requires review instead of a stale reset.
            rollback(u, audit, intent, parents, names, check_checkout)
            command(['/usr/bin/systemctl', 'daemon-reload'])
            verify_unit(unit, config, (releases / OLD_RUNTIME,))
            u.json_at(audit, 'rolled-back.json', {'format': 1, 'recovered': True})
            report['recovered_previous_interruption'] = True
            audit = None; intent = None
        lock()
        expected_before = {}
        config_bytes, expected_before['config'] = read_record(u, parents['config'], config.name, 16384, 0o600)
        state_bytes, expected_before['state'] = read_record(u, parents['state'], 'current.json', MAX_STATE, 0o600)
        active_unit, expected_before['unit'] = read_record(u, parents['unit'], unit.name, 65536, 0o644)
        cfg = parse(config_bytes); state = parse(state_bytes)
        current_seed = cfg.get('base_revision') if isinstance(cfg, dict) else None
        need(current_seed in (OLD_SEED, new_seed), 'CONFIGURATION_SEED_UNREVIEWED')
        checked_config(cfg, current_seed); checked_state(state, current_seed)
        checkout_directory = u.Directory(work / 'checkout')
        try:
            expected_before['checkout'] = {'identity': stamp(u, os.fstat(checkout_directory.fd), True)}
            check_checkout(work / 'checkout', current_seed, u)
            checkout_directory.verify()
        finally:
            checkout_directory.close()
        need(active_unit == (after_unit if current_seed == new_seed else before_unit), 'SERVICE_UNIT_UNREVIEWED')
        verify_unit(unit, config, (target,) if current_seed == new_seed else (releases / OLD_RUNTIME,),
                    allow_pending_reload=current_seed == new_seed)
        phase = 'RUNTIME_INSTALL'
        installed = u.install_runtime(releases, payloads, pins)
        need(installed == target, 'RUNTIME_PATH_CHANGED')
        if current_seed == new_seed:
            need(state['counter'] >= 25 and state.get('version_floor') is not None and
                 int(state['version_floor'].rsplit('.', 1)[1]) >= 25, 'CURRENT_HIGH_WATER_INVALID')
            phase = 'CURRENT_PROBE'
            proof = probe(target, live, work / 'checkout', state, pins, new_seed, u)
            command(['/usr/bin/systemctl', 'daemon-reload'])
            verify_unit(unit, config, (target,))
            lock()
            need(all(recognize(u, parents[slot], names[slot], expected_before[slot], slot == 'checkout')
                     for slot in SLOTS), 'CURRENT_PATH_CHANGED')
            report.update(ok=True, already_current=True, phase='DONE', counter=state['counter'],
                          version_floor=state['version_floor'], sequence_floor=state.get('sequence_floor', 0),
                          source_files=len(proof['inventory']), runtime_directory=str(target))
            return report
        next_state = migrated_state(state, OLD_SEED, new_seed)
        next_config = copy.deepcopy(cfg); next_config['base_revision'] = new_seed
        phase = 'AUDIT'
        name = PREFIX + uuid.uuid4().hex
        os.mkdir(name, 0o700, dir_fd=parents['checkout'].fd); os.fsync(parents['checkout'].fd)
        audit = u.Directory(work / name, 0o700); handles.append(audit)
        report['audit_directory'] = str(audit.path)
        need(len({os.fstat(parent.fd).st_dev for parent in [*parents.values(), audit]}) == 1,
             'FILESYSTEM_MISMATCH')
        for slot, data in [('unit', active_unit), ('state', state_bytes), ('config', config_bytes)]:
            u.write_at(audit, 'before-' + slot + '.bin', data)
        for slot, data in [('unit', after_unit), ('state', canonical(next_state)), ('config', canonical(next_config))]:
            u.write_at(audit, exchange_name(slot), data, 0o644 if slot == 'unit' else 0o600)
        os.mkdir(exchange_name('checkout'), 0o700, dir_fd=audit.fd); os.fsync(audit.fd)
        phase = 'SEED_STAGE'
        stage(audit.path / exchange_name('checkout'), new_seed, core, u)
        phase = 'SOURCE_PROBE'
        proof = probe(target, live, audit.path / exchange_name('checkout'), next_state, pins, new_seed, u)
        command(['/usr/bin/systemd-analyze', 'verify', str(audit.path / exchange_name('unit'))])
        intent = {'format': 1, 'old_seed': OLD_SEED, 'new_seed': new_seed, 'core': core,
                  'runtime': str(target), 'slots': {}}
        for slot in SLOTS:
            intent['slots'][slot] = {'before': expected_before[slot],
                                    'after': slot_record(u, audit, exchange_name(slot), slot == 'checkout')}
        u.json_at(audit, 'intent.json', intent)
        def guard():
            lock(); audit.verify()
            for slot in SLOTS:
                need(recognize(u, parents[slot], names[slot], intent['slots'][slot]['before'], slot == 'checkout'),
                     'BEFORE_PATH_CHANGED')
                need(recognize(u, audit, exchange_name(slot), intent['slots'][slot]['after'], slot == 'checkout'),
                     'STAGED_PATH_CHANGED')
            check_checkout(work / 'checkout', OLD_SEED, u)
            check_checkout(audit.path / exchange_name('checkout'), new_seed, u)
        guard()
        if event is not None:
            event('before-exchange', None)
        guard()
        phase = 'PATH_SWITCH'
        with u.critical():
            # The new runtime understands both seeds. An intermediate checkout
            # or state mismatch stops it; config changes only after all others.
            for slot in SLOTS:
                changed = True
                exchange_slot(u, audit, slot, parents, names)
                if slot == 'unit':
                    # The old loaded runtime must never see the new seed.
                    # Reload while all private paths still name the old seed;
                    # this reviewed runtime understands both exact seeds.
                    phase = 'DAEMON_RELOAD'
                    command(['/usr/bin/systemctl', 'daemon-reload'])
                    verify_unit(unit, config, (target,))
                    phase = 'PATH_SWITCH'
                if event is not None:
                    event('after-exchange', slot)
        phase = 'UNIT_VERIFY'
        verify_unit(unit, config, (target,))
        phase = 'POSTFLIGHT'
        lock()
        for slot in SLOTS:
            need(recognize(u, parents[slot], names[slot], intent['slots'][slot]['after'], slot == 'checkout')
                 and recognize(u, audit, exchange_name(slot), intent['slots'][slot]['before'], slot == 'checkout'),
                 'AFTER_PATH_CHANGED')
        checked_config(parse(u.read_at(parents['config'], config.name, 16384, 0o600)), new_seed)
        need(parse(u.read_at(parents['state'], 'current.json', MAX_STATE, 0o600)) == next_state, 'STATE_CHANGED')
        check_checkout(work / 'checkout', new_seed, u)
        need(probe(target, live, work / 'checkout', next_state, pins, new_seed, u) == proof, 'SOURCE_CHANGED')
        u.json_at(audit, 'completed.json', {'format': 1, 'ok': True, 'new_seed': new_seed,
                  'counter': next_state['counter'], 'version_floor': next_state['version_floor'],
                  'sequence_floor': next_state.get('sequence_floor', 0)})
        report.update(ok=True, already_current=False, phase='DONE', configuration_changed=True,
                      state_changed=True, checkout_changed=True, unit_changed=True,
                      counter=next_state['counter'], version_floor=next_state['version_floor'],
                      sequence_floor=next_state.get('sequence_floor', 0), source_files=len(proof['inventory']),
                      runtime_directory=str(target))
        return report
    except BaseException as error:
        reason = str(error) if isinstance(error, (Stop, u.Stop)) and re.fullmatch('[A-Z_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        report.update(phase=phase, reason=reason)
        if phase == 'RECOVERY':
            report.update(rollback_complete=False, recovery_required=True)
        if changed and audit is not None and intent is not None:
            try:
                rollback(u, audit, intent, parents, names, check_checkout)
                command(['/usr/bin/systemctl', 'daemon-reload'])
                verify_unit(unit, config, (releases / OLD_RUNTIME,))
                u.json_at(audit, 'rolled-back.json', {'format': 1, 'recovered': False})
            except BaseException:
                report['rollback_complete'] = False
                report['recovery_required'] = True
                for slot in SLOTS:
                    report[('configuration' if slot == 'config' else slot) + '_changed'] = not recognize(
                        u, parents[slot], names[slot], intent['slots'][slot]['before'], slot == 'checkout')
        elif phase != 'RECOVERY' and audit is not None and intent is not None:
            # No exchange was attempted. Keep any concurrent external changes,
            # and do not turn a rejected preflight into a future stale rollback.
            try:
                u.json_at(audit, 'rolled-back.json', {'format': 1, 'exchanges_started': False})
            except BaseException:
                report['audit_write_failed'] = True
        if audit is not None:
            try:
                u.json_at(audit, 'failed-' + uuid.uuid4().hex + '.json', report)
            except BaseException:
                report['audit_write_failed'] = True
        return report
    finally:
        for directory in reversed(handles):
            directory.close()
        if lock_entered:
            try:
                lock_manager.__exit__(None, None, None)
            except BaseException:
                report.update(ok=False, phase='LOCK_RELEASE', reason='PRIVATE_FAILURE')


def execute(arguments):
    need(len(arguments) == 2 and arguments[1] == '--upgrade', 'INVOCATION_INVALID')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    need(REV.fullmatch(NEW_SEED) and all(REV.fullmatch(pin) for pin in PINS.values()), 'RELEASE_PINS_PENDING')
    u = load_utility(Path(__file__).with_name('upgrade_go_erp_capacity_watcher_root.py'))
    directory = u.Directory(Path(arguments[0]), 0o700)
    try:
        need(set(os.listdir(directory.fd)) == set(ROSTER), 'PAYLOAD_ROSTER_INVALID')
        payloads = {name: u.read_at(directory, name, u.MAX_FILE, 0o600) for name in ROSTER}
    finally:
        directory.close()
    return upgrade(payloads, utility=u)


def main(arguments=None):
    def interrupted(_number, _frame):
        raise Stop('INTERRUPTED')
    previous = {number: signal.signal(number, interrupted) for number in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP)}
    try:
        report = execute(sys.argv[1:] if arguments is None else arguments)
    except BaseException as error:
        report = {'format': 1, 'kind': 'go-erp-snapshot-watcher-upgrade', 'ok': False, 'phase': 'ENTRY',
                  'reason': str(error) if isinstance(error, Stop) and re.fullmatch('[A-Z_]{1,100}', str(error)) else 'PRIVATE_FAILURE',
                  'application_contents_changed': False, 'database_changed': False, 'credentials_disclosed': False}
    finally:
        for number, handler in previous.items():
            signal.signal(number, handler)
    print(json.dumps(report, sort_keys=True))
    return 0 if report.get('ok') is True else 1


if __name__ == '__main__':
    sys.exit(main())
