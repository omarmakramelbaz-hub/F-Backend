#!/usr/bin/env python3
"""Publish only native unavailable-replay proof and the reviewed inbox consumer.

This filesystem-only installer does not bootstrap Laravel, query a database,
replay events, reset scans, create orders, send replies or replace systemd units.
"""
import hashlib
import importlib.util
import json
import os
import pathlib
import re
import signal
import stat
import sys
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
LIBRARY_BLOB = '65cad14ba90b505be88649da73da045746429abc'
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
# The proof is published before the consumer that calls it. These are the only
# application targets. Unknown and mixed target profiles always require review.
TARGET_PINS = {
    'app/Services/Dashboard/WhatsAppNativeUnavailableReplay.php': (None, 'bc6febf49c85947684a38c16d00bd5b62fb56e60'),
    'app/Services/Dashboard/WhatsAppInboxConsumer.php':
        ('705734c707b3dcee53ac66067fa52d0291eda39a', 'b169e92362531c1da565ef26bbc999a5a0fa73d8'),
}
# No unrelated source pin is replaced or broadened. Bind each installed file's
# exact bytes and metadata throughout publication, including independent CRM,
# POS, catalog, workflow and control releases.
UNCHANGED_DEPENDENCIES = (
    'app/Support/WhatsAppInboxProtocol.php',
    'app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php',
    'app/Services/Dashboard/WhatsAppInboxAccess.php',
    'app/Services/Dashboard/WhatsAppInboxMessage.php',
    'app/Services/Dashboard/WhatsAppInboxQuarantinedEvent.php',
    'app/Services/Dashboard/WhatsAppOrderWorkflow.php',
    'app/Support/WhatsAppCustomerOrderConfirmation.php',
    'app/Services/Dashboard/WhatsAppOrderSourceSelection.php',
    'app/Services/Dashboard/MetaAiControl.php',
    'app/Support/MetaAiSchedule.php',
    'app/Services/Dashboard/TakeawayAccess.php',
    'app/Services/Dashboard/TakeawayCatalog.php',
    'app/Services/Dashboard/PhoneDelivery.php',
    'app/Services/Dashboard/PosServiceTicket.php',
    'app/Services/Dashboard/BranchCustomers.php',
    'app/Services/Dashboard/PosServicePhone.php',
)


def blob(content):
    return hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()


def load_library():
    path = pathlib.Path(__file__).absolute().with_name('apply_meta_ai_control.py')
    for parent in reversed(path.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, os.geteuid()):
            raise RuntimeError('WA_UNAVAILABLE_REPLAY_LIBRARY_PATH_UNSAFE')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(descriptor); content = os.read(descriptor, 1048577); after = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid())
                or info.st_nlink != 1 or info.st_mode & 0o022 or len(content) > 1048576
                or blob(content) != LIBRARY_BLOB
                or (info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
                != (after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
            raise RuntimeError('WA_UNAVAILABLE_REPLAY_LIBRARY_HASH_OR_METADATA_CHANGED')
    finally:
        os.close(descriptor)
    name = 'verified_unavailable_replay_library'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path); sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


library = load_library()
base = library.base
SafeError = base.SafeError

def dependency_snapshot(root, uid):
    return {path: library.guarded_snapshot(root/path, uid, root)
            for path in UNCHANGED_DEPENDENCIES}


def fingerprint(values):
    data = [(path, signature, blob(item.content)) for path, (item, signature) in sorted(values.items())]
    return hashlib.sha256(json.dumps(data, separators=(',', ':')).encode()).hexdigest()


def sources_snapshot(root, uid):
    return {path: library.guarded_snapshot(root/path, uid, root, absent_ok=old is None)
            for path, (old, target) in TARGET_PINS.items()}


def source_hashes(values):
    return {path: None if item is None else blob(item.content) for path, (item, _) in values.items()}


def profiles():
    return {'BASELINE': {path: pair[0] for path, pair in TARGET_PINS.items()},
            'CURRENT': {path: pair[1] for path, pair in TARGET_PINS.items()}}


def current_state(root, uid, runner):
    configuration = library.configuration_snapshot(root, uid)
    dependencies = dependency_snapshot(root, uid)
    sources = sources_snapshot(root, uid); observed = source_hashes(sources)
    profile = next((name for name, pins in profiles().items() if pins == observed), None)
    if profile is None:
        raise SafeError('WA_UNAVAILABLE_REPLAY_LIVE_SOURCE_NEEDS_REVIEW')
    return {'configuration': configuration, 'dependencies': dependencies,
            'sources': sources, 'profile': profile}


def public_state(value):
    return {'sources': source_hashes(value['sources']),
            'configuration_fingerprint': library.configuration_fingerprint(value['configuration']),
            'dependencies_fingerprint': fingerprint(value['dependencies']),
            'source_profile': value['profile']}


def require_unchanged(root, uid, runner, value, sources=True):
    if (library.configuration_snapshot(root, uid) != value['configuration']
            or dependency_snapshot(root, uid) != value['dependencies']
            or sources and sources_snapshot(root, uid) != value['sources']):
        raise SafeError('WA_UNAVAILABLE_REPLAY_PUBLICATION_STATE_CHANGED')


def verify_state(root, uid, expected, held_locks=None, runner=base.command):
    if (not isinstance(expected, dict) or set(expected) != {'sources', 'configuration_fingerprint',
            'dependencies_fingerprint', 'source_profile'}
            or expected['sources'] not in profiles().values()):
        raise SafeError('WA_UNAVAILABLE_REPLAY_RESTART_SCOPE_INVALID')
    base.validate_root(root, uid)
    with library.locks(root, uid, held_locks):
        value = current_state(root, uid, runner)
        require_unchanged(root, uid, runner, value)
        observed = public_state(value)
        if observed != expected:
            raise SafeError('WA_UNAVAILABLE_REPLAY_RESTART_STATE_CHANGED')
        return dict(observed, status='WA_UNAVAILABLE_REPLAY_STATE_READY')


def release_sources(root, release, runner):
    if not re.fullmatch('[a-f0-9]{40}', release):
        raise SafeError('WA_UNAVAILABLE_REPLAY_RELEASE_INVALID')
    code, kind = runner(root, ['git', 'cat-file', '-t', release], capture=True)
    if code or kind.strip() != b'commit':
        raise SafeError('WA_UNAVAILABLE_REPLAY_RELEASE_NOT_COMMIT')
    content = {}
    for path, (_, target) in TARGET_PINS.items():
        if not re.fullmatch('[a-f0-9]{40}', target):
            raise SafeError('WA_UNAVAILABLE_REPLAY_REVIEWED_PIN_REQUIRED')
        code, tree = runner(root, ['git', 'ls-tree', '-z', release, path], capture=True)
        if code or tree != b'100644 blob '+target.encode()+b'\t'+path.encode()+b'\0':
            raise SafeError('WA_UNAVAILABLE_REPLAY_RELEASE_TREE_MISMATCH')
        code, data = runner(root, ['git', 'show', release+':'+path], capture=True)
        if code or not data or len(data) > 8388608 or blob(data) != target:
            raise SafeError('WA_UNAVAILABLE_REPLAY_RELEASE_BLOB_MISMATCH')
        content[path] = data
    return content


def apply(root, uid, release, check_only=False, expected=None, held_locks=None, runner=base.command):
    base.validate_root(root, uid)
    with library.locks(root, uid, held_locks):
        before = current_state(root, uid, runner); public_before = public_state(before)
        if expected is not None and public_before != expected:
            raise SafeError('WA_UNAVAILABLE_REPLAY_PREFLIGHT_STATE_CHANGED')
        candidates = release_sources(root, release, runner)
        parent = root.parent/'whatsapp-release-backups'; base.private_parent(parent, uid)
        with tempfile.TemporaryDirectory(prefix='.wa-unavailable-replay-lint-', dir=parent) as directory:
            for index, (path, content) in enumerate(candidates.items()):
                candidate = pathlib.Path(directory)/(str(index)+'.php')
                library.private_write(candidate, content)
                code, _ = runner(root, [PHP, '-l', str(candidate)])
                if code or candidate.read_bytes() != content:
                    raise SafeError('WA_UNAVAILABLE_REPLAY_CANDIDATE_LINT_FAILED')
        require_unchanged(root, uid, runner, before)
        if check_only:
            return dict(public_before, status='WA_UNAVAILABLE_REPLAY_PREFLIGHT_READY',
                        changed=before['profile'] != 'CURRENT')
        library.require_quiet(root, runner)
        if before['profile'] == 'CURRENT':
            return dict(public_before, status='WA_UNAVAILABLE_REPLAY_ALREADY_CURRENT', backup=None)
        backup = pathlib.Path(tempfile.mkdtemp(prefix='whatsapp-unavailable-replay-', dir=parent))
        backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('WA_UNAVAILABLE_REPLAY_BACKUP_FILESYSTEM_MISMATCH')
        publications = backup/'publications'; publications.mkdir(mode=0o700)
        base.probe_atomic_exchange(publications)
        library.private_write(backup/'receipt.json', (json.dumps(dict(public_before,
            release=release, targets=profiles()['CURRENT'], publication_targets=list(TARGET_PINS),
            changed_targets=[path for path in TARGET_PINS if public_before['sources'][path] != TARGET_PINS[path][1]]),
            sort_keys=True)+'\n').encode())
        written = {}; created = []
        try:
            for path, content in candidates.items():
                if public_before['sources'][path] == TARGET_PINS[path][1]:
                    if library.guarded_snapshot(root/path, uid, root) != before['sources'][path]:
                        raise SafeError('WA_UNAVAILABLE_REPLAY_CURRENT_TARGET_CHANGED')
                    continue
                require_unchanged(root, uid, runner, before, sources=False)
                base.replace_file(root/path, content, before['sources'][path][0], uid, root, created,
                    journal=written, relative=path, private_directory=publications)
            library.require_quiet(root, runner)
            require_unchanged(root, uid, runner, before, sources=False)
            after = current_state(root, uid, runner)
            if after['profile'] != 'CURRENT':
                raise SafeError('WA_UNAVAILABLE_REPLAY_FINAL_RUNTIME_OR_CONFIGURATION_CHANGED')
            for path in TARGET_PINS:
                if path in written:
                    base.assert_snapshot(root/path, written[path].after, uid, root)
                elif library.guarded_snapshot(root/path, uid, root) != before['sources'][path]:
                    raise SafeError('WA_UNAVAILABLE_REPLAY_CURRENT_TARGET_CHANGED')
            require_unchanged(root, uid, runner, after)
        except BaseException:
            restored = True
            with base.blocked_signals():
                for publication in reversed(list(written.values())):
                    try:
                        if not base.recover_publication(publication, root, uid):
                            restored = False; break
                    except BaseException:
                        restored = False; break
            # A worker may have loaded a new class even after byte rollback.
            # Never resume automatic orders after any partial source exposure.
            status = 'WA_UNAVAILABLE_REPLAY_ROLLBACK_REVIEW_REQUIRED' if written else 'WA_UNAVAILABLE_REPLAY_PREPUBLICATION_STOPPED'
            if not restored:
                status = 'WA_UNAVAILABLE_REPLAY_RECOVERY_REVIEW_REQUIRED'
            raise SafeError(status+'; BACKUP='+str(backup)) from None
        return dict(public_state(after), status='WHATSAPP_UNAVAILABLE_REPLAY_REPAIR_READY', backup=str(backup))


if __name__ == '__main__':
    import argparse
    import pwd
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument('--root', required=True); parser.add_argument('--release', required=True)
    parser.add_argument('--check-only', action='store_true'); arguments = parser.parse_args()
    try:
        def interrupted(signum, frame): raise KeyboardInterrupt()
        signal.signal(signal.SIGTERM, interrupted); signal.signal(signal.SIGINT, interrupted)
        uid = pwd.getpwnam('fasakha').pw_uid; root = pathlib.Path(arguments.root)
        if os.geteuid() != uid or root != APP_ROOT:
            raise SafeError('WA_UNAVAILABLE_REPLAY_EXECUTION_CONTEXT_INVALID')
        print(json.dumps(apply(root, uid, arguments.release, arguments.check_only), sort_keys=True))
    except BaseException as error:
        print(str(error) if isinstance(error, SafeError) else 'WA_UNAVAILABLE_REPLAY_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
        raise SystemExit(1)
