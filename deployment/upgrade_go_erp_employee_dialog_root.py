#!/usr/bin/env python3
"""Recover the employee dialog test over the unchanged reviewed dashboard."""
import hashlib
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types

BASE_SHA256 = '11733872876fa123796e06d2c8ece2aa8e77a3eda5e8704df12b2341e2f34f92'
OLD_SEED = 'ef60065c79c78dcec3a6ca28142dbb483ed9eed3'
OLD_CORE = 'd099f9b3889f7f2f71eeb7e778808a9430c13cdf'
CORE = 'bfde969e1fb83197b9dad76ac66831b07b34a7c3'
NEW_SEED = 'ab6bf30cf6fa9cedd66422b6428e388f764eee35'
OLD_RUNTIME = 'policy-e842079d0274a2a5aab7c0d64a075a499c5ef8d5b3a626c64e230886b2c841cc'
OLD_CANDIDATE = '73828f6710eeb357271b4381c402a00247830efa'


def refresh_historical_index(base, context):
    """Refresh only clean historical index stat data before the original verifier.

    With optional Git locks disabled, status can prove unchanged bytes while
    diff-index still reports an old stat cache. A refresh may update that cache;
    it must never alter the staged paths, modes, stages, or blob identities.
    """
    historical = base.WORK / 'checkout'
    git = context.base
    git.checkout(historical, base.OLD_SEED, context.utility)
    staged = git.git(historical, 'ls-files', '--stage', '-z')
    git.git(historical, 'update-index', '--refresh')
    base.need(git.git(historical, 'ls-files', '--stage', '-z') == staged,
        'HISTORICAL_INDEX_ROSTER_CHANGED')
    git.checkout(historical, base.OLD_SEED, context.utility)


def configure(base):
    """Keep the original transaction, verifier, source and rollback implementations."""
    base.OLD_SEED, base.OLD_CORE = OLD_SEED, OLD_CORE
    base.CORE, base.NEW_SEED = CORE, NEW_SEED
    base.OLD_RUNTIME, base.OLD_CANDIDATE = OLD_RUNTIME, OLD_CANDIDATE
    base.OLD_PINS = {**base.OLD_PINS,
        'go_erp_live_source.py': '3b078fb9ee85a2e09cf61cb8a53cb70b85f9c5a0',
        'go_erp_live_updates.py': '37229957ea060e88379895f4f8708a0e680dba74'}
    current = {'8d8917aba049c8d9d0d1200296b925c078cc7516a231897b8d2d36a370fb794c': {'format': 1, 'files': 1355, 'framework': '8.83.29', 'sha256': '47e5e8e28183d4e551cbc9ed4ad2cba6710f613e983844d152a515da94b347a5'}}
    base.CANDIDATE_SOURCES = dict(current)
    base.LIVE_SOURCES = dict(current)
    base.PREPARED_SOURCES = dict(current)
    base.NAV_TARGETS = {}
    base.PREFIX = 'employee-dialog-recovery-rollout-'
    expected = base.hold_value(OLD_CORE, next(iter(current.values())), next(iter(current)))
    base.old_hold_allowed = lambda value, raw: value == expected

    def adapt_payloads(old, new_seed=NEW_SEED):
        base.need(isinstance(old, dict) and set(old) == set(base.OLD_PINS)
            and new_seed == NEW_SEED, 'PAYLOAD_ROSTER_INVALID')
        result = {}
        for name, data in old.items():
            base.need(isinstance(data, bytes) and base.blob(data) == base.OLD_PINS[name], 'OLD_PAYLOAD_PIN_INVALID')
            if name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
                for before, after in ((OLD_SEED.encode(), NEW_SEED.encode()),
                    (b'-native-hud-fixture-recovery-v1', b'-native-employee-dialog-fixture-v1')):
                    base.need(data.count(before) == 1, 'PAYLOAD_CONTEXT_INVALID')
                    data = data.replace(before, after)
            result[name] = data
        return result

    snapshot = base.Context.snapshot
    def current_snapshot(self, inventory, source, seed=OLD_SEED):
        return snapshot(self, inventory, source, seed)
    base.Context.snapshot = current_snapshot
    bind_candidate = base.Context.bind_candidate
    def current_bind_candidate(self, state):
        refresh_historical_index(base, self)
        return bind_candidate(self, state)
    base.Context.bind_candidate = current_bind_candidate
    base.adapt_payloads = adapt_payloads
    migration = base.migrated_state

    def migrated_state(value, old_seed=OLD_SEED, new_seed=NEW_SEED, publication=None, reviewed=None):
        base.need(type(value.get('counter')) is int and value['counter'] >= 39
            and reviewed is not None, 'RECOVERY_CANDIDATE_PROOF_REQUIRED')
        return migration(value, old_seed, new_seed, publication, reviewed)

    base.migrated_state = migrated_state
    return base


def load_base(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0
                or before.st_nlink != 1 or stat.S_IMODE(before.st_mode) != 0o644
                or not 0 < before.st_size < 2 * 1024**2):
            raise RuntimeError('BASE_MODULE_UNSAFE')
        raw = os.read(fd, 2 * 1024**2 + 1)
        key = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid,
            s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        if (len(raw) != before.st_size or hashlib.sha256(raw).hexdigest() != BASE_SHA256
                or key(before) != key(os.fstat(fd)) or key(before) != key(path.lstat())):
            raise RuntimeError('BASE_MODULE_PIN_INVALID')
    finally:
        os.close(fd)
    module = types.ModuleType('reviewed_fullscreen_transaction')
    module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(raw, str(path), 'exec'), module.__dict__)
    return configure(module)


if __name__ == '__main__':
    try:
        if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
            raise RuntimeError('ROOT_HOST_REQUIRED')
        load_base(Path(__file__).parent / 'upgrade_go_erp_fullscreen_watcher_root.py').main()
    except BaseException as error:
        message = str(error)
        code = message if re.fullmatch('[A-Z0-9_]{1,100}', message) else 'PRIVATE_FAILURE'
        print('{"ok":false,"code":"GO_ERP_EMPLOYEE_DIALOG_' + code
            + '","published":false,"timer_paused":true,"database_changed":false}')
        raise SystemExit(1)
