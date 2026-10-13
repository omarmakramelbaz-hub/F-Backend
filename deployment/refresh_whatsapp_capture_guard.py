#!/usr/bin/env python3
"""Refresh only the reviewed capture.service source guard while workers are quiet.

The caller owns worker restoration and its immutable original pipeline journal.
This module returns a replacement profile; it never starts a unit or consumes data.
"""
import ast
import ctypes
import hashlib
import json
import os
import pathlib
import re
import stat
import subprocess
import uuid

SYSTEM_UID = 0
UNIT_DIRECTORY = pathlib.Path('/etc/systemd/system')
SYSTEMCTL = '/usr/bin/systemctl'
SERVICE = 'fasakhansta-whatsapp-capture.service'
UNITS = tuple('fasakhansta-whatsapp-' + value for value in
              ('capture.timer', 'capture.service', 'orders.timer', 'orders.service'))
LEGACY_BLOB = 'c3571f9cf4ab37171c9d6cbf2fc8ddae9e067316'
OLD_CONSUMER_SHA = b'80253a13accf7a712130d99d73322e9d01ca73cd6f83c68db6cf5f0cd282948c'
NEW_CONSUMER_SHA = b'd82f368dfd383978cf3b67a2ac7b12493f9668eb0899ba5709716574ea0382cd'
REQUIRED_SOURCES = {
    'app/Services/Dashboard/WhatsAppInboxConsumer.php':
        ('e0909057496cb9f710928fd8c273b5c9d0adc97f', NEW_CONSUMER_SHA.decode()),
    'app/Console/Commands/ConsumeWhatsAppInbox.php':
        ('dbd621a5117f2e92fd2f80d004305fcbcc467d4a',
         'b942e8830acfc3fb9d291c1d953f0a80a6b4132027a945fb6656d3e2f589c9f6'),
}


class SafeError(Exception):
    """A fixed operational code. Unit activation is deliberately not asserted."""


def command(args):
    try:
        result = subprocess.run(args, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=60)
        return (result.returncode, result.stdout) if len(result.stdout) <= 65536 else (1, b'')
    except (OSError, subprocess.TimeoutExpired):
        return 1, b''


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def frozen_service(legacy_source):
    if not isinstance(legacy_source, bytes) or blob(legacy_source) != LEGACY_BLOB:
        raise SafeError('CAPTURE_GUARD_FROZEN_INSTALLER_MISMATCH')
    marker = b'exec /usr/bin/python3 - "$@" <<\'PY\'\n'
    try:
        payload = legacy_source.split(marker, 1)[1]
        program, tail = payload.rsplit(b'\nPY\n', 1)
        if tail not in (b'', b'\n'):
            raise ValueError()
        nodes = [node for node in ast.parse(program).body
                 if isinstance(node, ast.Assign) and len(node.targets) == 1
                 and isinstance(node.targets[0], ast.Name)
                 and node.targets[0].id == 'SERVICE_BYTES']
        if len(nodes) != 1:
            raise ValueError()
        before = ast.literal_eval(nodes[0].value)
        if not isinstance(before, bytes) or before.count(OLD_CONSUMER_SHA) != 1:
            raise ValueError()
        after = before.replace(OLD_CONSUMER_SHA, NEW_CONSUMER_SHA, 1)
        return before, after
    except (IndexError, ValueError, SyntaxError, TypeError):
        raise SafeError('CAPTURE_GUARD_FROZEN_SERVICE_INVALID') from None


def directory(path, owners, mode=None, allow_group_write=False):
    if not path.is_absolute() or '..' in path.parts:
        raise SafeError('CAPTURE_GUARD_PATH_INVALID')
    for parent in (*reversed(path.parents), path):
        info = parent.lstat()
        if (not stat.S_ISDIR(info.st_mode) or info.st_uid not in owners
                or stat.S_IMODE(info.st_mode) & (0o002 if allow_group_write else 0o022)):
            raise SafeError('CAPTURE_GUARD_DIRECTORY_UNSAFE')
    info = path.lstat()
    if mode is not None and (info.st_uid != SYSTEM_UID or stat.S_IMODE(info.st_mode) != mode):
        raise SafeError('CAPTURE_GUARD_PRIVATE_DIRECTORY_UNSAFE')
    return (info.st_dev, info.st_ino, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))


def read_file(path, owners, mode=None, limit=1048576, parents=None, single_link=True,
              allow_public_group_write=False):
    directory(path.parent, owners if parents is None else parents,
              allow_group_write=allow_public_group_write)
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        raise SafeError('CAPTURE_GUARD_FILE_UNAVAILABLE') from None
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in owners or (single_link and info.st_nlink != 1)
                or stat.S_IMODE(info.st_mode) & 0o022 or not 0 < info.st_size <= limit
                or (mode is not None and stat.S_IMODE(info.st_mode) != mode)):
            raise SafeError('CAPTURE_GUARD_FILE_UNSAFE')
        data = b''
        while len(data) <= limit:
            part = os.read(fd, min(65536, limit + 1 - len(data)))
            if not part:
                break
            data += part
        after = os.fstat(fd)
        current = path.lstat()
        signature = lambda s: (s.st_dev, s.st_ino, s.st_uid, s.st_gid, s.st_mode,
                               s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        if (len(data) != info.st_size or signature(info) != signature(after)
                or signature(info) != signature(current)):
            raise SafeError('CAPTURE_GUARD_FILE_CHANGED')
        profile = (info.st_dev, info.st_ino, info.st_uid, info.st_gid,
                   stat.S_IMODE(info.st_mode), info.st_mtime_ns, info.st_ctime_ns,
                   hashlib.sha256(data).hexdigest())
        return data, profile
    finally:
        os.close(fd)


def unit_profile(name):
    if name not in UNITS:
        raise SafeError('CAPTURE_GUARD_UNKNOWN_UNIT')
    return read_file(UNIT_DIRECTORY / name, {SYSTEM_UID}, 0o644,
                     parents={0, SYSTEM_UID})[1]


def source_profiles(root, reviewed_source_pins, source_uid):
    if not isinstance(reviewed_source_pins, dict):
        raise SafeError('CAPTURE_GUARD_SOURCE_POLICY_INVALID')
    policy = {}
    for name, values in reviewed_source_pins.items():
        if (not isinstance(name, str) or not re.fullmatch(r'(?:app|config)/[A-Za-z0-9_./-]+\.php', name)
                or '..' in pathlib.PurePosixPath(name).parts):
            raise SafeError('CAPTURE_GUARD_SOURCE_POLICY_INVALID')
        if not isinstance(values, (str, list, tuple)):
            raise SafeError('CAPTURE_GUARD_SOURCE_POLICY_INVALID')
        values = (values,) if isinstance(values, str) else tuple(values)
        if not 1 <= len(values) <= 2 or any(not isinstance(v, str) or not re.fullmatch('[a-f0-9]{40}', v) for v in values):
            raise SafeError('CAPTURE_GUARD_SOURCE_POLICY_INVALID')
        policy[name] = tuple(sorted(set(values)))
    for name, (pin, _) in REQUIRED_SOURCES.items():
        if name in policy and policy[name] != (pin,):
            raise SafeError('CAPTURE_GUARD_REQUIRED_SOURCE_POLICY_MISMATCH')
        policy[name] = (pin,)
    directory(root, {0, source_uid}, allow_group_write=True)
    profiles = {}
    for name, pins in sorted(policy.items()):
        data, profile = read_file(root / name, {source_uid}, parents={0, source_uid},
                                  single_link=False, allow_public_group_write=True)
        if blob(data) not in pins or (name in REQUIRED_SOURCES
                and hashlib.sha256(data).hexdigest() != REQUIRED_SOURCES[name][1]):
            raise SafeError('CAPTURE_GUARD_REVIEWED_SOURCE_MISMATCH')
        profiles[name] = profile
    return policy, profiles


def observe_units(runner, quiet=False, require_loaded=True):
    result = {}
    for name in UNITS:
        fields = ['LoadState', 'ActiveState', 'FragmentPath', 'DropInPaths', 'NeedDaemonReload']
        if name.endswith('.service'):
            fields.append('MainPID')
        code, output = runner([SYSTEMCTL, 'show', '--no-pager',
                               *('--property=' + field for field in fields), name])
        try:
            lines = output.decode('ascii').strip().splitlines()
            state = dict(line.split('=', 1) for line in lines)
            if (code or len(lines) != len(state) or set(state) != set(fields)
                    or state['LoadState'] != 'loaded'
                    or state['ActiveState'] not in ('active', 'activating', 'inactive', 'failed')
                    or state['FragmentPath'] != str(UNIT_DIRECTORY / name)
                    or state['DropInPaths'] != '' or state['NeedDaemonReload'] not in ('yes', 'no')
                    or (require_loaded and state['NeedDaemonReload'] != 'no')
                    or ('MainPID' in state and not re.fullmatch('[0-9]+', state['MainPID']))
                    or (state['ActiveState'] in ('inactive', 'failed') and state.get('MainPID', '0') != '0')):
                raise ValueError()
            if quiet and (state['ActiveState'] not in ('inactive', 'failed')
                          or state.get('MainPID', '0') != '0'):
                raise SafeError('CAPTURE_GUARD_WORKERS_NOT_QUIESCENT')
            result[name] = state
        except (ValueError, UnicodeError, AttributeError):
            raise SafeError('CAPTURE_GUARD_MANAGER_STATE_UNVERIFIED') from None
    return result


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_new(path, content, mode=0o600):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'wb') as stream:
        os.fchmod(stream.fileno(), mode)
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())
    sync_directory(path.parent)


def encode(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode()


def exchange(source, target):
    library = ctypes.CDLL(None, use_errno=True)
    operation = library.renameat2
    operation.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    operation.restype = ctypes.c_int
    if operation(-100, os.fsencode(source), -100, os.fsencode(target), 2) != 0:
        raise SafeError('CAPTURE_GUARD_ATOMIC_EXCHANGE_FAILED')


def checkpoint_state(root, checkpoint, original, policy, before, after, source_uid):
    """Authenticate an explicit checkpoint; never search or rewrite its records."""
    owners = {0, source_uid, SYSTEM_UID}
    if not checkpoint.exists():
        directory(checkpoint.parent, owners, 0o700)
        return None
    directory(checkpoint, owners, 0o700)
    data, _ = read_file(checkpoint / 'before.service', {SYSTEM_UID}, 0o600, parents=owners)
    intent_bytes, intent_profile = read_file(checkpoint / 'intent.json', {SYSTEM_UID}, 0o600, parents=owners)
    try:
        intent = json.loads(intent_bytes)
        fixed = {'version', 'root', 'checkpoint', 'unit_directory', 'original_profile', 'source_policy',
                 'before_sha256', 'after_sha256', 'stage_name', 'stage_profile', 'other_profiles'}
        if (set(intent) != fixed or intent['version'] != 1 or intent['root'] != str(root)
                or intent['checkpoint'] != str(checkpoint) or intent['unit_directory'] != str(UNIT_DIRECTORY)
                or intent['original_profile'] != list(original)
                or intent['source_policy'] != {k: list(v) for k, v in policy.items()}
                or data != before or intent['before_sha256'] != hashlib.sha256(before).hexdigest()
                or intent['after_sha256'] != hashlib.sha256(after).hexdigest()
                or not re.fullmatch(r'\.wa-capture-guard-[a-f0-9]{32}', intent['stage_name'])
                or set(intent['other_profiles']) != set(UNITS) - {SERVICE}):
            raise ValueError()
        stage = tuple(intent['stage_profile'])
        if (len(stage) != 8 or any(type(v) is not int for v in stage[:7])
                or stage[2] != SYSTEM_UID or stage[4] != 0o644
                or stage[7] != hashlib.sha256(after).hexdigest()):
            raise ValueError()
    except (ValueError, TypeError, KeyError):
        raise SafeError('CAPTURE_GUARD_CHECKPOINT_PROOF_INVALID') from None
    for name, profile in intent['other_profiles'].items():
        if unit_profile(name) != tuple(profile):
            raise SafeError('CAPTURE_GUARD_OTHER_UNIT_CHANGED')
    return intent, intent_profile


def _validate(root, checkpoint, expected_original_profile, reviewed_source_pins, runner, legacy_source, source_uid):
    if os.geteuid() != SYSTEM_UID:
        raise SafeError('CAPTURE_GUARD_ROOT_REQUIRED')
    root, checkpoint = pathlib.Path(root), pathlib.Path(checkpoint)
    original = tuple(expected_original_profile)
    before, after = frozen_service(legacy_source)
    if (len(original) != 8 or any(type(v) is not int for v in original[:7])
            or original[2] != SYSTEM_UID or original[4] != 0o644
            or original[7] != hashlib.sha256(before).hexdigest()):
        raise SafeError('CAPTURE_GUARD_ORIGINAL_PROFILE_INVALID')
    unit_dir = directory(UNIT_DIRECTORY, {0, SYSTEM_UID})
    policy, sources = source_profiles(root, reviewed_source_pins, source_uid)
    observed = {name: unit_profile(name) for name in UNITS}
    data, profile = read_file(UNIT_DIRECTORY / SERVICE, {SYSTEM_UID}, 0o644, parents={0, SYSTEM_UID})
    state = checkpoint_state(root, checkpoint, original, policy, before, after, source_uid)
    if state is None:
        if data != before or profile != original:
            raise SafeError('CAPTURE_GUARD_UNEXPLAINED_LIVE_PROFILE')
        observe_units(runner)
    else:
        intent, _ = state
        stage_path = UNIT_DIRECTORY / intent['stage_name']
        staged, staged_profile = read_file(stage_path, {SYSTEM_UID}, 0o644, parents={0, SYSTEM_UID})
        if data == before and profile == original:
            if staged != after or staged_profile != tuple(intent['stage_profile']):
                raise SafeError('CAPTURE_GUARD_STAGE_CHANGED')
        elif data == after:
            if (profile[:6] != tuple(intent['stage_profile'])[:6]
                    or staged != before or staged_profile[:6] != original[:6]
                    or staged_profile[7] != original[7]):
                raise SafeError('CAPTURE_GUARD_PUBLISHED_IDENTITY_UNVERIFIED')
        else:
            raise SafeError('CAPTURE_GUARD_UNEXPLAINED_LIVE_PROFILE')
        receipt = checkpoint / 'complete.json'
        if receipt.exists():
            raw, _ = read_file(receipt, {SYSTEM_UID}, 0o600, parents={0, source_uid, SYSTEM_UID})
            try:
                if json.loads(raw) != {'version': 1, 'profile': list(profile), 'daemon_reloaded': True} or data != after:
                    raise ValueError()
            except (ValueError, TypeError):
                raise SafeError('CAPTURE_GUARD_COMPLETION_PROOF_INVALID') from None
            observe_units(runner)
        else:
            observe_units(runner, require_loaded=False)
    return root, checkpoint, original, before, after, policy, sources, observed, unit_dir, state, profile


def validate_capture_guard(root, checkpoint, expected_original_profile, reviewed_source_pins,
                           runner=command, *, legacy_source, source_uid):
    """Return only an authenticated original or checkpoint-authorized profile."""
    return _validate(root, checkpoint, expected_original_profile, reviewed_source_pins,
                     runner, legacy_source, source_uid)[-1]


def refresh_capture_guard(root, checkpoint, expected_original_profile, reviewed_source_pins,
                          runner=command, *, legacy_source, source_uid):
    """Atomically replace one SHA literal and return its fully reloaded unit profile."""
    values = _validate(root, checkpoint, expected_original_profile, reviewed_source_pins,
                       runner, legacy_source, source_uid)
    root, checkpoint, original, before, after, policy, sources, observed, unit_dir, state, profile = values
    observe_units(runner, quiet=True, require_loaded=state is None)
    if state is None:
        checkpoint.mkdir(mode=0o700)
        sync_directory(checkpoint.parent)
        directory(checkpoint, {0, source_uid, SYSTEM_UID}, 0o700)
        stage_path = UNIT_DIRECTORY / ('.wa-capture-guard-' + uuid.uuid4().hex)
        write_new(stage_path, after, 0o644)
        staged, stage_profile = read_file(stage_path, {SYSTEM_UID}, 0o644, parents={0, SYSTEM_UID})
        if staged != after:
            raise SafeError('CAPTURE_GUARD_STAGE_INVALID')
        # The old bytes and full publication intent are durable before exchange.
        write_new(checkpoint / 'before.service', before)
        intent = {'version': 1, 'root': str(root), 'checkpoint': str(checkpoint),
                  'unit_directory': str(UNIT_DIRECTORY), 'original_profile': list(original),
                  'source_policy': {k: list(v) for k, v in policy.items()},
                  'before_sha256': hashlib.sha256(before).hexdigest(),
                  'after_sha256': hashlib.sha256(after).hexdigest(),
                  'stage_name': stage_path.name, 'stage_profile': list(stage_profile),
                  'other_profiles': {k: list(v) for k, v in observed.items() if k != SERVICE}}
        write_new(checkpoint / 'intent.json', encode(intent))
        state = checkpoint_state(root, checkpoint, original, policy, before, after, source_uid)
    intent = state[0]
    stage_path = UNIT_DIRECTORY / intent['stage_name']
    checkpoint_identity = directory(checkpoint, {0, source_uid, SYSTEM_UID}, 0o700)
    # Recheck every read dependency after the durable intent and before mutation.
    if (directory(UNIT_DIRECTORY, {0, SYSTEM_UID}) != unit_dir
            or source_profiles(root, reviewed_source_pins, source_uid)[1] != sources
            or any(unit_profile(name) != value for name, value in observed.items())):
        raise SafeError('CAPTURE_GUARD_DEPENDENCY_CHANGED')
    current = _validate(root, checkpoint, original, reviewed_source_pins, runner, legacy_source, source_uid)
    if current[-1] != profile or current[-2] != state:
        raise SafeError('CAPTURE_GUARD_CHECKPOINT_CHANGED')
    observe_units(runner, quiet=True, require_loaded=False)
    if profile == original:
        exchange(stage_path, UNIT_DIRECTORY / SERVICE)
        sync_directory(UNIT_DIRECTORY)
        displaced, displaced_profile = read_file(stage_path, {SYSTEM_UID}, 0o644, parents={0, SYSTEM_UID})
        published, published_profile = read_file(UNIT_DIRECTORY / SERVICE, {SYSTEM_UID}, 0o644, parents={0, SYSTEM_UID})
        if displaced != before or displaced_profile[:6] != original[:6] or displaced_profile[7] != original[7]:
            # Preserve a writer that raced the final CAS. Only undo our own exact
            # candidate; a later unknown live file is retained for explicit review.
            if published == after and published_profile[:6] == tuple(intent['stage_profile'])[:6]:
                exchange(stage_path, UNIT_DIRECTORY / SERVICE)
                sync_directory(UNIT_DIRECTORY)
            raise SafeError('CAPTURE_GUARD_CONCURRENT_UNIT_RETAINED')
    # No rollback is guessed after publication. Explicit checkpoint resume is safe.
    current = _validate(root, checkpoint, original, reviewed_source_pins, runner, legacy_source, source_uid)
    refreshed = current[-1]
    if refreshed[7] != hashlib.sha256(after).hexdigest():
        raise SafeError('CAPTURE_GUARD_POST_PUBLICATION_UNVERIFIED')
    code, _ = runner([SYSTEMCTL, 'daemon-reload'])
    if code:
        raise SafeError('CAPTURE_GUARD_DAEMON_RELOAD_UNVERIFIED')
    observe_units(runner, quiet=True)
    if (directory(checkpoint, {0, source_uid, SYSTEM_UID}, 0o700) != checkpoint_identity
            or directory(UNIT_DIRECTORY, {0, SYSTEM_UID}) != unit_dir
            or source_profiles(root, reviewed_source_pins, source_uid)[1] != sources
            or unit_profile(SERVICE) != refreshed
            or any(unit_profile(name) != value for name, value in observed.items() if name != SERVICE)
            or checkpoint_state(root, checkpoint, original, policy, before, after, source_uid) != state):
        raise SafeError('CAPTURE_GUARD_POST_RELOAD_DEPENDENCY_CHANGED')
    receipt = checkpoint / 'complete.json'
    if not receipt.exists():
        write_new(receipt, encode({'version': 1, 'profile': list(refreshed), 'daemon_reloaded': True}))
    return validate_capture_guard(root, checkpoint, original, reviewed_source_pins,
                                  runner, legacy_source=legacy_source, source_uid=source_uid)
