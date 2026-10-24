#!/usr/bin/env python3
"""Run one original, pinned server-update tick for an explicit manual job."""
import argparse
import contextlib
import hashlib
import io
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types

RUNTIME = Path('/opt/go-erp-live-updates/releases/policy-acf5db0bbd0d415d753c0081a43de7b02b38509546a25382d69eb8aedbb7cc0a')
SEED = '1ce9bb30034e300e3ed693e28a4dcbd608fb66c4'
POLICY = 'historical-baseline-v2-native-windows-fixture-paths-v1'
PINS = {
    'desktop_dashboard_server_preflight.py': ('4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4', 10515, '21a8f2ffa16e47322853c33de07af855d8fcb4cc6759750dfda84ca5a0db33bb'),
    'diagnose_desktop_dashboard_current_source.py': ('e7a7a8271481013a1c16167d6f7a6ff429687ef5', 16743, '3e3c852b5916f0cf38d7deb16656e0c13f855f0b5bf8fb326b2e25250d3be7ed'),
    'go_erp_current_candidate_ci.yml': ('af4d9bb97572c84e26cb6750d380adde2f7e9423', 7231, '203ca21186223cacb7feb98dc8eb372bceb258c4e0f01e4c7a8b917b417271ea'),
    'go_erp_live_source.py': ('4025dc89701da92e66137579141bff34731bff3d', 38799, '120f1873f1c10b6560467db4792b23ede4f0d17b8a912e43285f6644b6307a5c'),
    'go_erp_live_updates.py': ('46ffe226124b83b50f65872c96c0634770314e63', 69831, '5ab35b610efd7d4dfce6e3f8bb10f8dc3a841767eb396c6a2b0dbcee13403e33'),
    'publish_go_erp_update.py': ('f9a7353b0705383844a576ab6d0f5b1662912bfb', 18089, '3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490'),
    'verify_go_erp_current_candidate_ci.py': ('fb2c6ee364fae14062148846b654df8c96bba0de', 10634, 'e005df2cd2229188d79418754dedd1ea18cbfb5256727ee126a881dfa660461a'),
}
CODE = re.compile(r'GO_ERP_[A-Z0-9_]{1,140}\Z')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
        value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def load_runtime(path=RUNTIME):
    """Validate every immutable runtime byte before importing the original worker."""
    need(path.is_absolute() and path.resolve() == path, 'RUNTIME_PATH_INVALID')
    parents = []
    for parent in (*reversed(path.parents), path):
        value = parent.lstat()
        sticky = parent != path and bool(value.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(value.st_mode) and value.st_uid == 0
            and (not value.st_mode & 0o022 or sticky), 'RUNTIME_DIRECTORY_UNSAFE')
        parents.append((parent, value))
    need(stat.S_IMODE(parents[-1][1].st_mode) == 0o755, 'RUNTIME_DIRECTORY_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    payloads = {}
    try:
        need(identity(os.fstat(fd)) == identity(parents[-1][1]) and set(os.listdir(fd)) == set(PINS),
            'RUNTIME_ROSTER_INVALID')
        for name, (blob, size, sha) in PINS.items():
            source = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
            try:
                before = os.fstat(source)
                need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid, before.st_nlink,
                    stat.S_IMODE(before.st_mode), before.st_size) == (0, 0, 1, 0o644, size), 'RUNTIME_FILE_UNSAFE')
                raw = os.read(source, size + 1)
                need(len(raw) == size and hashlib.sha256(raw).hexdigest() == sha
                    and hashlib.sha1(b'blob ' + str(size).encode() + b'\0' + raw).hexdigest() == blob
                    and identity(before) == identity(os.fstat(source))
                    == identity(os.stat(name, dir_fd=fd, follow_symlinks=False)), 'RUNTIME_PIN_INVALID')
                payloads[name] = raw
            finally:
                os.close(source)
        need(set(os.listdir(fd)) == set(PINS), 'RUNTIME_ROSTER_INVALID')
        for parent, before in parents:
            # Directory entry timestamps may change outside the fixed runtime;
            # the inode, owner, mode and link identity must remain the same.
            after = parent.lstat()
            need(identity(before)[:5] == identity(after)[:5], 'RUNTIME_DIRECTORY_CHANGED')
        need(identity(parents[-1][1]) == identity(os.fstat(fd)), 'RUNTIME_DIRECTORY_CHANGED')
    finally:
        os.close(fd)
    for name, raw in payloads.items():
        if name.endswith('.py'):
            compile(raw, str(path / name), 'exec')
    worker = types.ModuleType('go_erp_manual_pinned_worker')
    worker.__file__ = str(path / 'go_erp_live_updates.py')
    sys.modules[worker.__name__] = worker
    exec(compile(payloads['go_erp_live_updates.py'], worker.__file__, 'exec'), worker.__dict__)
    need(worker.PREVIEW31_SEED == SEED and worker.PREVIEW31_POLICY == POLICY
        and worker.ROOT == Path('/home/fasakha/public_html')
        and worker.WORK == Path('/var/lib/go-erp-live-updates')
        and worker.CONFIG == Path('/etc/go-erp/live-updates.json'), 'RUNTIME_BINDING_INVALID')
    return worker


def install_guards(worker, inventory=None, candidate=None, allow_prepare=False):
    """Constrain a job without replacing any original tick or acceptance logic."""
    need(inventory is None or isinstance(inventory, str) and re.fullmatch(r'[a-f0-9]{64}', inventory),
        'INVENTORY_ARGUMENT_INVALID')
    need(candidate is None or isinstance(candidate, str) and re.fullmatch(r'[a-f0-9]{40}', candidate),
        'CANDIDATE_ARGUMENT_INVALID')
    need(type(allow_prepare) is bool and not (candidate is not None and allow_prepare), 'ALLOCATION_ARGUMENT_INVALID')
    need(candidate is None or inventory is not None, 'CANDIDATE_INVENTORY_REQUIRED')
    original_scan, original_tick, original_prepare = worker.Worker.scan, worker.Worker.tick, worker.Worker.prepare
    original_config = worker.read_config
    prepared = 0

    def read_config(*args, **kwargs):
        value = original_config(*args, **kwargs)
        worker.need(value.get('base_revision') == SEED, 'MANUAL_SEED_UNREVIEWED')
        return value

    def scan(self):
        value = original_scan(self)
        worker.need(inventory is None or worker.source_identity(value) == inventory, 'MANUAL_SOURCE_CHANGED')
        return value

    def tick(self):
        if candidate is not None:
            desired = self.state.get('desired')
            worker.need(isinstance(desired, dict) and desired.get('commit') == candidate
                and desired.get('inventorySha256') == inventory
                and desired.get('candidatePolicy') == POLICY,
                'MANUAL_CANDIDATE_CHANGED')
        return original_tick(self)

    def prepare(self, snapshot):
        nonlocal prepared
        worker.need(allow_prepare and candidate is None and prepared == 0, 'MANUAL_ALLOCATION_NOT_AUTHORIZED')
        worker.need(inventory is None or worker.source_identity(snapshot) == inventory, 'MANUAL_SOURCE_CHANGED')
        prepared += 1
        return original_prepare(self, snapshot)

    worker.read_config = read_config
    worker.Worker.scan, worker.Worker.tick, worker.Worker.prepare = scan, tick, prepare


def run_original(worker):
    """Keep the original lock/constructor/main and emit only bounded code lines."""
    output, errors = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(output), contextlib.redirect_stderr(errors):
        result = worker.main(['--once'])
    need(type(result) is int and result in (0, 1), 'ORIGINAL_EXIT_INVALID')
    stdout, stderr = output.getvalue().splitlines(), errors.getvalue().splitlines()
    need(len(stdout) + len(stderr) <= 16 and all(CODE.fullmatch(line) for line in stdout + stderr),
        'ORIGINAL_OUTPUT_UNREVIEWED')
    for line in stdout:
        print(line)
    for line in stderr:
        print(line, file=sys.stderr)
    return result


class Parser(argparse.ArgumentParser):
    def error(self, message):
        raise Stop('ARGUMENTS_INVALID')


def main(arguments=None):
    try:
        parser = Parser(add_help=False)
        parser.add_argument('--inventory')
        parser.add_argument('--candidate')
        parser.add_argument('--allow-prepare', action='store_true')
        options = parser.parse_args(arguments)
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
        os.umask(0o077)
        worker = load_runtime()
        install_guards(worker, options.inventory, options.candidate, options.allow_prepare)
        return run_original(worker)
    except Exception as error:
        if 'worker' in locals() and isinstance(error, worker.WatchError) and CODE.fullmatch(str(error)):
            print(str(error), file=sys.stderr)
            return 1
        code = str(error) if type(error) is Stop and re.fullmatch(r'[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        print('GO_ERP_MANUAL_TICK_' + code, file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
