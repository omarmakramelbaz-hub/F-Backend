#!/usr/bin/env python3
"""Pinned adapter for the reviewed Preview31 transaction and diagnostic source.

No timer is changed here. The entry wrapper quiesces the worker first. The real
watcher lock stays held until both application and watcher commit or roll back.
"""
import argparse
import contextlib
import copy
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import sys
import types
import uuid

OLD_SEED = 'd6b2c6195e555a6b62a534709428daeedd0d015b'
NEW_SEED = '02dedf4d1f07a33349b66501806778cb330874cb'
CORE = '04950fe07bbbb69a759bb6eace79bf32c7a1a500'
BASELINE = 'aed9c93c22982cbc8cde7ecae3986c97736e50b5'
OLD_RUNTIME = 'policy-224354558d3f51405f711992fd285d445b8560189dc05b60bba906cc1176d202'
OLD_INVENTORY = '01e5c5b7d8c0db15fa29e165b17f3a83972b10adaef1f8a67cf5905cd62ff1f1'
NEW_INVENTORY = '459ac45362218641669ba8686045b40ee7b7dee1b824d0f26038abf5684974a1'
NEW_SOURCE = {'format': 1, 'files': 1327, 'framework': '8.83.29',
    'sha256': '39ea59e8027d71cb5282c7b7e0fffa23c30361f56f359688777cee1458742b48'}
OLD_SOURCE = {**NEW_SOURCE, 'files': 1326,
    'sha256': '08e84b198c6bbbd4d917226f96b73b6762f1d3a9d2a34d56446defab2f7ab44b'}
POLICY = 'historical-baseline-v2-native-import422-diagnostic-seed-v1'
BASE_MODULE = 'upgrade_go_erp_preview31_watcher_root.py'
BASE_BLOB = 'ede9af30ea42ba53a5376d5e1cf634d9b3032f2a'
APPLICATION_MODULE = 'go_erp_import422_application_patch.py'
APPLICATION_BLOB = '18884e836bf9f17281912cebf81aa3aba89ed95e'
OLD_PINS = {
    'desktop_dashboard_server_preflight.py': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
    'diagnose_desktop_dashboard_current_source.py': 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
    'go_erp_current_candidate_ci.yml': 'af4d9bb97572c84e26cb6750d380adde2f7e9423',
    'go_erp_live_source.py': '56e2601feca5970f0c79faaf4088268c4feae6bc',
    'go_erp_live_updates.py': '82923e335e646a852f3dac8e0e46f14dc9738e92',
    'publish_go_erp_update.py': 'f9a7353b0705383844a576ab6d0f5b1662912bfb',
    'verify_go_erp_current_candidate_ci.py': 'fb2c6ee364fae14062148846b654df8c96bba0de',
}
ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
SAFE = 9007199254740991
REV = re.compile(r'[a-f0-9]{40}\Z')
VERSIONS = ('desktop-pos/package.json', 'desktop-pos/package-lock.json',
            'deployment/go_erp_live_source_candidate.json')


class Stop(Exception):
    pass


def need(condition, reason):
    if not condition:
        raise Stop(reason)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def load_pinned(path, expected):
    need(REV.fullmatch(expected) and expected != '0' * 40, 'RELEASE_PIN_PENDING')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid,
             before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o644)
             and 0 < before.st_size <= 2 * 1024 ** 2, 'MODULE_UNSAFE')
        data = bytearray()
        while len(data) <= 2 * 1024 ** 2:
            part = os.read(fd, 65536)
            if not part:
                break
            data.extend(part)
        key = lambda item: (item.st_dev, item.st_ino, item.st_mode, item.st_uid,
                            item.st_gid, item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)
        need(key(before) == key(os.fstat(fd)) == key(path.lstat())
             and len(data) == before.st_size and blob(data) == expected, 'MODULE_PIN_INVALID')
    finally:
        os.close(fd)
    module = types.ModuleType('reviewed_' + path.stem)
    module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(bytes(data), str(path), 'exec'), module.__dict__)
    return module


def adapt_payloads(raw):
    need(set(raw) == set(OLD_PINS), 'PAYLOAD_ROSTER_INVALID')
    result = {}
    replacements = ((OLD_SEED.encode(), NEW_SEED.encode()),
        (b"'-native-import-memory-seed-v1'", b"'-native-import422-diagnostic-seed-v1'"))
    for name, value in raw.items():
        need(isinstance(value, bytes) and blob(value) == OLD_PINS[name], 'PAYLOAD_PIN_INVALID')
        if name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
            for before, after in replacements:
                need(value.count(before) == 1, 'PAYLOAD_PATCH_CONTEXT_INVALID')
                value = value.replace(before, after)
        result[name] = value
    return result


class ReleaseGuard:
    """Accept version-only siblings of immutable Preview34, not arbitrary refs."""
    def __init__(self, base):
        self.base = base
        self.revision = None
        self.version = None
        self.number = None
        self.validate_release = None

    def candidate(self, checkout, revision):
        b = self.base
        need(isinstance(revision, str) and REV.fullmatch(revision), 'REMOTE_REVISION_INVALID')
        b.git(checkout, 'fetch', '--quiet', '--depth=1', '--no-tags', '--no-recurse-submodules',
              b.REMOTE, BASELINE, revision)
        parents = [line[7:].decode() for line in b.git(checkout, 'cat-file', 'commit', revision)
                   .split(b'\n\n', 1)[0].splitlines() if line.startswith(b'parent ')]
        need(parents == [OLD_SEED], 'REMOTE_PARENT_UNREVIEWED')
        names = b.git(checkout, 'diff', '--name-only', '--no-renames', '-z', BASELINE, revision, '--').split(b'\0')
        need(all(name.decode() in VERSIONS for name in names if name), 'REMOTE_CODE_UNREVIEWED')
        saved = b.parse(b.git(checkout, 'show', BASELINE + ':' + VERSIONS[2]))
        current = b.parse(b.git(checkout, 'show', revision + ':' + VERSIONS[2]))
        number = current.get('versionSequence')
        need(type(number) is int and 31 <= number <= SAFE and current.get('version') == '0.4.4-preview.' + str(number),
             'REMOTE_VERSION_UNREVIEWED')
        saved.update(versionSequence=number, version=current['version'])
        need(current == saved and current.get('baseRevision') == OLD_SEED
             and current.get('inventorySha256') == OLD_INVENTORY, 'REMOTE_INVENTORY_UNREVIEWED')
        for path in VERSIONS[:2]:
            expected = b.parse(b.git(checkout, 'show', BASELINE + ':' + path))
            actual = b.parse(b.git(checkout, 'show', revision + ':' + path))
            expected['version'] = current['version']
            if path.endswith('package-lock.json'):
                expected['packages']['']['version'] = current['version']
            need(actual == expected, 'REMOTE_PACKAGE_UNREVIEWED')
        return current['version'], number

    def __call__(self, checkout):
        b = self.base
        ref = 'refs/heads/codex/go-erp-live-source-20261021'
        raw = b.git(checkout, 'ls-remote', '--exit-code', b.REMOTE, ref, maximum=4096)
        match = re.fullmatch(rb'([a-f0-9]{40})\t' + ref.encode() + rb'\n', raw)
        need(match is not None, 'REMOTE_BRANCH_UNREVIEWED')
        revision = match[1].decode()
        if self.revision is None:
            self.version, self.number = self.candidate(checkout, revision)
            need(self.number >= 34, 'REMOTE_VERSION_UNREVIEWED')
            self.revision = revision
        need(revision == self.revision, 'REMOTE_BRANCH_CHANGED')


def migrated_state(base, guard, checkout, value, old_seed, new_seed, publication):
    base.checked_state(value, old_seed)
    need(old_seed == OLD_SEED and new_seed == NEW_SEED and guard.revision is not None,
         'STATE_SEED_UNREVIEWED')
    need(value.get('last_remote_sha') == guard.revision, 'REMOTE_HISTORY_UNREVIEWED')
    observed = value.get('observed')
    need(observed is None or isinstance(observed, dict) and observed.get('seedRevision') == OLD_SEED
         and observed.get('inventorySha256') == OLD_INVENTORY, 'OBSERVED_SOURCE_UNREVIEWED')
    for name in ('desired', 'published'):
        item = value.get(name)
        if item is None:
            continue
        need(isinstance(item, dict) and item.get('inventorySha256') == OLD_INVENTORY,
             'CANDIDATE_STATE_UNREVIEWED')
        revision = item.get('commit')
        if name == 'desired':
            need(revision == guard.revision, 'UNPUBLISHED_CANDIDATE_REQUIRES_REVIEW')
        else:
            # A previous accepted Preview31-33 publication is preserved in
            # history, but its reference must not be silently reinterpreted.
            old_version, old_number = guard.candidate(checkout, revision)
            need(callable(guard.validate_release), 'RELEASE_VALIDATOR_UNAVAILABLE')
            release = guard.validate_release(item.get('release'))
            need(old_number <= guard.number and release.get('version') == old_version
                 and release.get('source_revision') == revision and release.get('source') == OLD_SOURCE,
                 'PUBLISHED_HISTORY_UNREVIEWED')
    floor = int(value['version_floor'].rsplit('.', 1)[1]) if value.get('version_floor') else 0
    need(guard.number <= value['counter'] and floor <= value['counter'], 'COUNTER_HIGH_WATER_INVALID')
    sequence = value.get('sequence_floor', 0)
    if publication is not None:
        version = re.fullmatch(r'0\.4\.4-preview\.([1-9][0-9]*)', publication.get('version', ''))
        need(version is not None and int(version[1]) <= value['counter']
             and publication.get('source') == OLD_SOURCE, 'PUBLIC_RELEASE_UNREVIEWED')
        accepted_version, _ = guard.candidate(checkout, publication.get('source_revision'))
        need(accepted_version == publication['version'], 'PUBLIC_RELEASE_UNREVIEWED')
        sequence = max(sequence, publication['sequence'])
    result = copy.deepcopy(value)
    counter = max(value['counter'], floor, guard.number)
    result.update(base_revision=NEW_SEED, observed=None, desired=None, published=None,
        counter=counter, version_floor='0.4.4-preview.' + str(counter), sequence_floor=sequence)
    return result


@contextlib.contextmanager
def held_lock(utility, work):
    directory = utility.Directory(work, 0o700)
    fd = None
    try:
        fd = os.open('watcher.lock', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory.fd)
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid,
             before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o600), 'LOCK_UNSAFE')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('WATCHER_BUSY') from None
        def verify():
            directory.verify()
            need(utility.exact_key(before) == utility.exact_key(os.fstat(fd))
                 == utility.exact_key(os.stat('watcher.lock', dir_fd=directory.fd, follow_symlinks=False)), 'LOCK_CHANGED')
        verify()
        yield fd, verify
        verify()
    finally:
        if fd is not None:
            os.close(fd)
        directory.close()


class LockedUtility:
    def __init__(self, original, work, verify):
        self.original, self.work, self.verify = original, work, verify
    def __getattr__(self, name):
        return getattr(self.original, name)
    @contextlib.contextmanager
    def watcher_lock(self, work):
        need(work == self.work, 'LOCK_SCOPE_INVALID')
        self.verify()
        yield self.verify
        self.verify()


def assert_quiescent():
    for name, accepted in [('go-erp-live-updates.timer', ('inactive',)),
                           ('go-erp-live-updates.service', ('inactive', 'failed'))]:
        result = subprocess.run(['/usr/bin/systemctl', 'show', name, '-p', 'ActiveState', '--value'],
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            timeout=10, check=False)
        need(result.returncode == 0 and result.stdout.decode('ascii', errors='replace').strip() in accepted,
             'WORKER_NOT_QUIESCENT')


def require_no_pending(base, utility, work):
    directory = utility.Directory(work, 0o700)
    try:
        need(base.pending_audit(utility, directory) is None, 'PRIOR_MIGRATION_REQUIRES_REVIEW')
        for name in os.listdir(directory.fd):
            if not re.fullmatch(r'import422-application-[a-f0-9]{32}', name):
                continue
            audit = utility.Directory(work / name, 0o700)
            try:
                roster = set(os.listdir(audit.fd))
                need('application-intent.json' not in roster or bool(roster.intersection({
                    'application-committed.json', 'application-rolled-back.json'})),
                    'PRIOR_MIGRATION_REQUIRES_REVIEW')
            finally:
                audit.close()
    finally:
        directory.close()


def verify_watcher_rollback(base, utility, config, state):
    """Independently recognize all old private slots before reverting source."""
    for parent, name, mode, expected in (
            (base.CONFIG.parent, base.CONFIG.name, 0o600, config),
            (WORK / 'state', 'current.json', 0o600, state)):
        directory = utility.Directory(parent, 0o700)
        try:
            actual = base.parse(utility.read_at(directory, name, base.MAX_STATE, mode))
            need(actual == expected, 'WATCHER_ROLLBACK_REQUIRES_REVIEW')
        finally:
            directory.close()
    directory = utility.Directory(base.UNIT.parent, 0o755)
    try:
        need(utility.read_at(directory, base.UNIT.name, 65536, 0o644)
             == base.unit_bytes(utility, base.RELEASES / OLD_RUNTIME, ROOT, WORK, base.CONFIG),
             'WATCHER_ROLLBACK_REQUIRES_REVIEW')
    finally:
        directory.close()
    base.checkout(WORK / 'checkout', OLD_SEED, utility)
    utility.effective_unit(base.UNIT, base.CONFIG, (base.RELEASES / OLD_RUNTIME,))


def run_current(base, utility, application, package, owner, lock_fd, verify, payloads, pins, guard):
    application.verify_current(ROOT, package, owner.pw_uid, owner.pw_gid, lock_fd)
    verify()
    result = base.upgrade(payloads, pins=pins, utility=LockedUtility(utility, WORK, verify),
        new_seed=NEW_SEED, core=CORE, old_pins=OLD_PINS, remote=guard)
    application.verify_current(ROOT, package, owner.pw_uid, owner.pw_gid, lock_fd)
    verify()
    result.update(kind='go-erp-import422-watcher-upgrade', application_contents_changed=False)
    return result


def run_transaction(base, utility, application, package, owner, lock_fd, verify, payloads, pins, guard,
                    confirm_rollback):
    audit = WORK / ('import422-application-' + uuid.uuid4().hex)
    os.mkdir(audit, 0o700)
    patch = application.ApplicationPatch(ROOT, package, audit, owner.pw_uid, owner.pw_gid, lock_fd)
    result = None
    watcher_started = False
    rollback_attempted = False
    try:
        patch.prepare(); verify(); patch.apply(); patch.verify(); verify()
        watcher_started = True
        result = base.upgrade(payloads, pins=pins, utility=LockedUtility(utility, WORK, verify),
            new_seed=NEW_SEED, core=CORE, old_pins=OLD_PINS, remote=guard)
        if result.get('ok') is not True:
            if result.get('rollback_complete') is False:
                return result
            try:
                confirm_rollback()
            except BaseException:
                raise Stop('WATCHER_ROLLBACK_REQUIRES_REVIEW') from None
            rollback_attempted = True
            patch.rollback()
            result['application_contents_changed'] = False
            return result
        patch.verify(); verify(); patch.commit()
        result.update(kind='go-erp-import422-watcher-upgrade', application_contents_changed=True,
            next_version='0.4.4-preview.' + str(result['counter'] + 1))
        return result
    except BaseException as error:
        if result is not None and result.get('ok') is True:
            raise Stop('APPLICATION_COMMIT_REQUIRES_REVIEW') from None
        if watcher_started:
            if result is None or str(error) == 'WATCHER_ROLLBACK_REQUIRES_REVIEW':
                raise Stop('WATCHER_ROLLBACK_REQUIRES_REVIEW') from None
        if rollback_attempted:
            raise Stop('APPLICATION_ROLLBACK_REQUIRES_REVIEW') from None
        try:
            rollback_attempted = True
            patch.rollback()
        except BaseException:
            raise Stop('APPLICATION_ROLLBACK_REQUIRES_REVIEW') from None
        raise


def execute(package, apply=False):
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    package = Path(package).absolute()
    need(package.resolve() == package, 'PACKAGE_PATH_UNSAFE')
    base = load_pinned(package / BASE_MODULE, BASE_BLOB)
    utility = base.load_utility(package / 'upgrade_go_erp_capacity_watcher_root.py')
    application = load_pinned(package / APPLICATION_MODULE, APPLICATION_BLOB)
    directory = utility.Directory(package / 'payload', 0o700)
    try:
        need(set(os.listdir(directory.fd)) == set(OLD_PINS), 'PAYLOAD_ROSTER_INVALID')
        raw = {name: utility.read_at(directory, name, utility.MAX_FILE, 0o600) for name in OLD_PINS}
    finally:
        directory.close()
    payloads = adapt_payloads(raw)
    pins = {name: blob(data) for name, data in payloads.items()}
    base.OLD_SEED, base.CORE, base.NEW_SEED = OLD_SEED, CORE, NEW_SEED
    base.OLD_RUNTIME = OLD_RUNTIME
    base.OLD_PINS, base.OLD_ROSTER = OLD_PINS, tuple(sorted(OLD_PINS))
    base.PINS, base.ROSTER = pins, tuple(sorted(pins))
    base.EXPECTED_SOURCE, base.EXPECTED_INVENTORY = NEW_SOURCE, NEW_INVENTORY
    base.PREFIX = 'import422-seed-upgrade-'
    guard = ReleaseGuard(base)
    base.migrated_state = lambda value, old_seed, new_seed, publication=None: migrated_state(
        base, guard, WORK / 'checkout', value, old_seed, new_seed, publication)
    with held_lock(utility, WORK) as (lock_fd, verify):
        assert_quiescent()
        try:
            require_no_pending(base, utility, WORK)
        except BaseException:
            raise Stop('PRIOR_MIGRATION_REQUIRES_REVIEW') from None
        cfg_dir = utility.Directory(base.CONFIG.parent, 0o700)
        state_dir = utility.Directory(WORK / 'state', 0o700)
        try:
            config = base.parse(utility.read_at(cfg_dir, base.CONFIG.name, 16384, 0o600))
            state = base.parse(utility.read_at(state_dir, 'current.json', base.MAX_STATE, 0o600))
        finally:
            state_dir.close(); cfg_dir.close()
        current_seed = config.get('base_revision') if isinstance(config, dict) else None
        need(current_seed in (OLD_SEED, NEW_SEED), 'CONFIGURATION_SEED_UNREVIEWED')
        base.checked_config(config, current_seed)
        base.checked_state(state, current_seed)
        base.checkout(WORK / 'checkout', current_seed, utility)
        owner = pwd.getpwnam('fasakha')
        if current_seed == NEW_SEED:
            application.verify_current(ROOT, package, owner.pw_uid, owner.pw_gid, lock_fd)
            target = base.RELEASES / utility.runtime_name(payloads)
            current_runtime = utility.Directory(target, 0o755)
            try:
                utility.validate_runtime(current_runtime, pins)
            finally:
                current_runtime.close()
            need(state['counter'] >= 34 and state.get('version_floor') is not None
                 and 34 <= int(state['version_floor'].rsplit('.', 1)[1]) <= state['counter'],
                 'CURRENT_HIGH_WATER_INVALID')
            if not apply:
                utility.effective_unit(base.UNIT, base.CONFIG, (target,))
                return {'format': 1, 'kind': 'go-erp-import422-upgrade-check', 'ok': True,
                    'already_current': True, 'counter': state['counter'], 'rollback_complete': True,
                    'application_contents_changed': False, 'database_changed': False, 'credentials_disclosed': False}
            return run_current(base, utility, application, package, owner, lock_fd, verify, payloads, pins, guard)
        guard(WORK / 'checkout')
        old_runtime = utility.Directory(base.RELEASES / OLD_RUNTIME, 0o755)
        try:
            utility.validate_runtime(old_runtime, OLD_PINS)
            publisher = utility.module_at(old_runtime, 'publish_go_erp_update.py', OLD_PINS['publish_go_erp_update.py'])
            guard.validate_release = publisher.validate_release
        finally:
            old_runtime.close()
        public = base.publication_snapshot(base.RELEASES / OLD_RUNTIME, ROOT, OLD_PINS, utility)
        next_state = migrated_state(base, guard, WORK / 'checkout', state, OLD_SEED, NEW_SEED, public)
        if not apply:
            return {'format': 1, 'kind': 'go-erp-import422-upgrade-check', 'ok': True,
                'counter': state['counter'], 'next_version': '0.4.4-preview.' + str(next_state['counter'] + 1),
                'application_contents_changed': False, 'database_changed': False,
                'credentials_disclosed': False, 'rollback_complete': True}
        return run_transaction(base, utility, application, package, owner, lock_fd, verify, payloads, pins, guard,
            lambda: verify_watcher_rollback(base, utility, config, state))


def main(arguments=None):
    parser = argparse.ArgumentParser()
    parser.add_argument('--package', required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--check', action='store_true')
    args = parser.parse_args(arguments)
    try:
        report = execute(args.package, args.apply)
    except BaseException as error:
        reason = str(error) if re.fullmatch(r'[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        report = {'format': 1, 'kind': 'go-erp-import422-watcher-upgrade', 'ok': False,
            'reason': reason, 'credentials_disclosed': False, 'database_changed': False,
            'rollback_complete': reason not in ('APPLICATION_ROLLBACK_REQUIRES_REVIEW', 'APPLICATION_COMMIT_REQUIRES_REVIEW',
                'WATCHER_ROLLBACK_REQUIRES_REVIEW', 'PRIOR_MIGRATION_REQUIRES_REVIEW', 'LOCK_CHANGED')}
    print(json.dumps(report, sort_keys=True))
    return 0 if report.get('ok') is True else 78 if report.get('rollback_complete') is False else 75 if report.get('reason') == 'WATCHER_BUSY' else 1


if __name__ == '__main__':
    raise SystemExit(main())
