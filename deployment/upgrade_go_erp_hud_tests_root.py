#!/usr/bin/env python3
"""Rebind the reviewed native transaction to the two current UI test fixes."""
import hashlib
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types

BASE_SHA256 = '11733872876fa123796e06d2c8ece2aa8e77a3eda5e8704df12b2341e2f34f92'
OLD_SEED = '6c261f7928a2d82e2906d7e59c1d6144ecf40296'
OLD_CORE = '53266b0596533b2752480d148608886404f307db'
CORE = 'd099f9b3889f7f2f71eeb7e778808a9430c13cdf'
NEW_SEED = 'ef60065c79c78dcec3a6ca28142dbb483ed9eed3'
OLD_RUNTIME = 'policy-ff615a55d98543685d65d3141b3780a9da9f485e99abddeab9d77f2e7faee30c'
OLD_CANDIDATE = 'a0b415ab5080e07aa40b6c43d6cd97baba79ee88'


def configure(base):
    """Keep the original transaction, verifier, source and rollback implementations."""
    base.OLD_SEED, base.OLD_CORE = OLD_SEED, OLD_CORE
    base.CORE, base.NEW_SEED = CORE, NEW_SEED
    base.OLD_RUNTIME, base.OLD_CANDIDATE = OLD_RUNTIME, OLD_CANDIDATE
    base.OLD_PINS = {**base.OLD_PINS,
        'go_erp_live_source.py': '4cae9880c798ba002254e58b5a0b6719acf9f0f3',
        'go_erp_live_updates.py': '6c225bcc3f0919d7d511743b065cb5ff957ab416'}
    cohort = {base.HUD_ADVANCED_INVENTORY: base.HUD_ADVANCED_SOURCE}
    base.CANDIDATE_SOURCES = dict(cohort)
    current = {'dd420d129539681ed0e11f629e5fc04976ed84f4a2b322c2c9a88c107a8ba6de': {'format': 1, 'files': 1352, 'framework': '8.83.29', 'sha256': '90cf0860d0a440e9217121622ff94bc1afa36aed1ee2aafc80fe70da83bcea56'}}
    base.LIVE_SOURCES = dict(current)
    base.PREPARED_SOURCES = dict(current)
    base.NAV_TARGETS = {}
    base.PREFIX = 'hud-test-recovery-rollout-'
    expected = base.hold_value(OLD_CORE, base.HUD_ADVANCED_SOURCE, base.HUD_ADVANCED_INVENTORY)
    base.old_hold_allowed = lambda value, raw: value == expected

    def adapt_payloads(old, new_seed=NEW_SEED):
        base.need(isinstance(old, dict) and set(old) == set(base.OLD_PINS)
            and new_seed == NEW_SEED, 'PAYLOAD_ROSTER_INVALID')
        result = {}
        for name, data in old.items():
            base.need(isinstance(data, bytes) and base.blob(data) == base.OLD_PINS[name], 'OLD_PAYLOAD_PIN_INVALID')
            if name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
                for before, after in ((OLD_SEED.encode(), NEW_SEED.encode()),
                    (b'-native-fullscreen-f12-seed-v1', b'-native-hud-fixture-recovery-v1')):
                    base.need(data.count(before) == 1, 'PAYLOAD_CONTEXT_INVALID')
                    data = data.replace(before, after)
            result[name] = data
        return result

    snapshot = base.Context.snapshot
    def current_snapshot(self, inventory, source, seed=OLD_SEED):
        return snapshot(self, inventory, source, seed)
    base.Context.snapshot = current_snapshot
    base.adapt_payloads = adapt_payloads
    migration = base.migrated_state

    def migrated_state(value, old_seed=OLD_SEED, new_seed=NEW_SEED, publication=None, reviewed=None):
        base.need(type(value.get('counter')) is int and value['counter'] >= 38
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
        print('{"ok":false,"code":"GO_ERP_HUD_TESTS_' + code
            + '","published":false,"timer_paused":true,"database_changed":false}')
        raise SystemExit(1)
