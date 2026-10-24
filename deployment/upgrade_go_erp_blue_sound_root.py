#!/usr/bin/env python3
"""Recover native sound tests while capturing the reviewed blue solar HUD."""
import hashlib
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types

BASE_SHA256 = '11733872876fa123796e06d2c8ece2aa8e77a3eda5e8704df12b2341e2f34f92'
OLD_SEED = 'ab6bf30cf6fa9cedd66422b6428e388f764eee35'
OLD_CORE = 'bfde969e1fb83197b9dad76ac66831b07b34a7c3'
CORE = '329241f8d38ad15e0bb2629504ed020a2875bcd9'
NEW_SEED = 'f72793577c9365f391869e13f7877ff00bf32ba2'
OLD_RUNTIME = 'policy-acec2784a60f6ced80fd32c7e620df811f8de88389dfc56790afc15817acb4c2'
OLD_CANDIDATE = 'ddd1b5b36b23bbadbd4e2f077666587d24c173fd'


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
        'go_erp_live_source.py': '79137dea0b684a3e48c8a1ee30f119320f2ae4df',
        'go_erp_live_updates.py': 'e67089f70ebbd4a93383a32c2bfd0a65f1d772b0'}
    previous = {'8d8917aba049c8d9d0d1200296b925c078cc7516a231897b8d2d36a370fb794c': {'format': 1, 'files': 1355, 'framework': '8.83.29', 'sha256': '47e5e8e28183d4e551cbc9ed4ad2cba6710f613e983844d152a515da94b347a5'}}
    current = {'2249c62ae01adb65af35a21b252bb249aaaa5ec7022caabb1bbf32bd07deccde': {'format': 1, 'files': 1355, 'framework': '8.83.29', 'sha256': '89ab1a441e26e1d0893b1615fa64d3697d98f13d4afa0605b743b882374b65d1'}}
    base.CANDIDATE_SOURCES = dict(previous)
    base.LIVE_SOURCES = dict(current)
    base.PREPARED_SOURCES = dict(current)
    base.NAV_TARGETS = {}
    base.PREFIX = 'blue-sound-recovery-rollout-'
    expected = base.hold_value(OLD_CORE, next(iter(previous.values())), next(iter(previous)))
    base.old_hold_allowed = lambda value, raw: value == expected

    def adapt_payloads(old, new_seed=NEW_SEED):
        base.need(isinstance(old, dict) and set(old) == set(base.OLD_PINS)
            and new_seed == NEW_SEED, 'PAYLOAD_ROSTER_INVALID')
        result = {}
        for name, data in old.items():
            base.need(isinstance(data, bytes) and base.blob(data) == base.OLD_PINS[name], 'OLD_PAYLOAD_PIN_INVALID')
            if name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
                for before, after in ((OLD_SEED.encode(), NEW_SEED.encode()),
                    (b'-native-employee-dialog-fixture-v1', b'-native-browser-sound-entry-v1')):
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
        base.need(type(value.get('counter')) is int and value['counter'] >= 40
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
        print('{"ok":false,"code":"GO_ERP_BLUE_SOUND_' + code
            + '","published":false,"timer_paused":true,"database_changed":false}')
        raise SystemExit(1)
