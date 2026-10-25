#!/usr/bin/env python3
"""Run an explicit manual tick against exactly one reviewed native cohort."""
import argparse
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types

RUNTIME = Path('/opt/go-erp-live-updates/releases/policy-6333cb471cd888a59ddb00b4e6cc51327c87197200bc28edbb9eef0a97828728')
SEED = '138bc2773bf8de099b029a18550e49f0692e9806'
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-startup-session-v1'
PINS = {'desktop_dashboard_server_preflight.py': ('4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
                                           10515,
                                           '21a8f2ffa16e47322853c33de07af855d8fcb4cc6759750dfda84ca5a0db33bb'),
 'diagnose_desktop_dashboard_current_source.py': ('e7a7a8271481013a1c16167d6f7a6ff429687ef5',
                                                  16743,
                                                  '3e3c852b5916f0cf38d7deb16656e0c13f855f0b5bf8fb326b2e25250d3be7ed'),
 'go_erp_current_candidate_ci.yml': ('af4d9bb97572c84e26cb6750d380adde2f7e9423',
                                     7231,
                                     '203ca21186223cacb7feb98dc8eb372bceb258c4e0f01e4c7a8b917b417271ea'),
 'go_erp_live_source.py': ('4827501daee2cfea7d41e41ea8ff15554360d48d',
                           38823,
                           '2855ad213a7adc130b1a34526050ad8a66f4f1d29c7d97b87bfe8b7ebaaf1239'),
 'go_erp_live_updates.py': ('aa181fe11d66d4ea463a6743fa1f35c19e20169f',
                            70025,
                            '7f0267f2606b64adbd552bd642ad5f3ab21ee80e8b62874cfe34c93aca21cea8'),
 'publish_go_erp_update.py': ('f9a7353b0705383844a576ab6d0f5b1662912bfb',
                              18089,
                              '3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490'),
 'verify_go_erp_current_candidate_ci.py': ('fb2c6ee364fae14062148846b654df8c96bba0de',
                                           10634,
                                           'e005df2cd2229188d79418754dedd1ea18cbfb5256727ee126a881dfa660461a')}
CODE = re.compile(r'GO_ERP_[A-Z0-9_]{1,140}\Z')
CONFIG = Path('/etc/go-erp/live-updates.json')
UNIT = Path('/etc/systemd/system/go-erp-live-updates.service')
OLD_COHORT = {'pins': {'desktop_dashboard_server_preflight.py': ('4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
                                                    10515,
                                                    '21a8f2ffa16e47322853c33de07af855d8fcb4cc6759750dfda84ca5a0db33bb'),
          'diagnose_desktop_dashboard_current_source.py': ('e7a7a8271481013a1c16167d6f7a6ff429687ef5',
                                                           16743,
                                                           '3e3c852b5916f0cf38d7deb16656e0c13f855f0b5bf8fb326b2e25250d3be7ed'),
          'go_erp_current_candidate_ci.yml': ('af4d9bb97572c84e26cb6750d380adde2f7e9423',
                                              7231,
                                              '203ca21186223cacb7feb98dc8eb372bceb258c4e0f01e4c7a8b917b417271ea'),
          'go_erp_live_source.py': ('4827501daee2cfea7d41e41ea8ff15554360d48d',
                                    38823,
                                    '2855ad213a7adc130b1a34526050ad8a66f4f1d29c7d97b87bfe8b7ebaaf1239'),
          'go_erp_live_updates.py': ('aa181fe11d66d4ea463a6743fa1f35c19e20169f',
                                     70025,
                                     '7f0267f2606b64adbd552bd642ad5f3ab21ee80e8b62874cfe34c93aca21cea8'),
          'publish_go_erp_update.py': ('f9a7353b0705383844a576ab6d0f5b1662912bfb',
                                       18089,
                                       '3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490'),
          'verify_go_erp_current_candidate_ci.py': ('fb2c6ee364fae14062148846b654df8c96bba0de',
                                                    10634,
                                                    'e005df2cd2229188d79418754dedd1ea18cbfb5256727ee126a881dfa660461a')},
 'policy': 'historical-baseline-v2-native-close-reopen-program-contract-startup-session-v1',
 'runtime': Path('/opt/go-erp-live-updates/releases/policy-6333cb471cd888a59ddb00b4e6cc51327c87197200bc28edbb9eef0a97828728'),
 'seed': '138bc2773bf8de099b029a18550e49f0692e9806',
 'unit_sha256': '17ae8f09f2c2af8f904abea37b05a3c33a17fafb6e661d0177fefde6cadcb6df'}
NEW_COHORT = {'pins': {'desktop_dashboard_server_preflight.py': ('4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
                                                    10515,
                                                    '21a8f2ffa16e47322853c33de07af855d8fcb4cc6759750dfda84ca5a0db33bb'),
          'diagnose_desktop_dashboard_current_source.py': ('e7a7a8271481013a1c16167d6f7a6ff429687ef5',
                                                           16743,
                                                           '3e3c852b5916f0cf38d7deb16656e0c13f855f0b5bf8fb326b2e25250d3be7ed'),
          'go_erp_current_candidate_ci.yml': ('af4d9bb97572c84e26cb6750d380adde2f7e9423',
                                              7231,
                                              '203ca21186223cacb7feb98dc8eb372bceb258c4e0f01e4c7a8b917b417271ea'),
          'go_erp_live_source.py': ('5473cf760abd6f3a8dd00bbe97ef97b95a9aff05',
                                    38827,
                                    'c22c8115cf7d79023e156d85eda5b5878b0b083a4a7dec1c926bfb5bd30da6d3'),
          'go_erp_live_updates.py': ('ae4a7ea69ba11277597439c6fa5b4e25cd8d39d4',
                                     70029,
                                     'c2c35f0002fada3c91b7434267a4b2346afd54b7bcbc01ad7fb4a75588b085ac'),
          'publish_go_erp_update.py': ('f9a7353b0705383844a576ab6d0f5b1662912bfb',
                                       18089,
                                       '3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490'),
          'verify_go_erp_current_candidate_ci.py': ('fb2c6ee364fae14062148846b654df8c96bba0de',
                                                    10634,
                                                    'e005df2cd2229188d79418754dedd1ea18cbfb5256727ee126a881dfa660461a')},
 'policy': 'historical-baseline-v2-native-close-reopen-program-contract-warm-start-accounts-v1',
 'runtime': Path('/opt/go-erp-live-updates/releases/policy-228fbc5bf869c5ca4ffadf47a34968639035dd8b34e965d841778f918608ac3c'),
 'seed': '9fbfa4a7bf5c5bfafb471265edc7429ec4b4f636',
 'unit_sha256': 'a1bc726ed2b270cd96f1e3ffe881afb4371828274941dd7f19f487177a6ab7f8'}


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
        value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def load_runtime(cohort, path=None):
    """Validate every immutable runtime byte before importing the original worker."""
    path = cohort['runtime'] if path is None else Path(path)
    pins = cohort['pins']
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
        need(identity(os.fstat(fd)) == identity(parents[-1][1]) and set(os.listdir(fd)) == set(pins),
            'RUNTIME_ROSTER_INVALID')
        for name, (blob, size, sha) in pins.items():
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
        need(set(os.listdir(fd)) == set(pins), 'RUNTIME_ROSTER_INVALID')
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
    need(worker.PREVIEW31_SEED == cohort['seed'] and worker.PREVIEW31_POLICY == cohort['policy']
        and worker.ROOT == Path('/home/fasakha/public_html')
        and worker.WORK == Path('/var/lib/go-erp-live-updates')
        and worker.CONFIG == Path('/etc/go-erp/live-updates.json'), 'RUNTIME_BINDING_INVALID')
    source = types.ModuleType('go_erp_warm_start_pinned_source')
    source.__file__ = str(path / 'go_erp_live_source.py')
    sys.modules[source.__name__] = source
    exec(compile(payloads['go_erp_live_source.py'], source.__file__, 'exec'), source.__dict__)
    need(source.PREVIEW31_SEED == cohort['seed'] and source.PREVIEW31_POLICY == cohort['policy']
        and worker.Worker.candidate_policy(types.SimpleNamespace(config={'base_revision': cohort['seed']},
            source=source)) == cohort['policy'], 'RUNTIME_POLICY_BINDING_INVALID')
    return worker


def read_control(path, maximum, mode):
    need(path.is_absolute() and path.resolve() == path, 'CONTROL_PATH_INVALID')
    for parent in path.parents:
        item = parent.lstat()
        sticky = bool(item.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == 0
            and (not item.st_mode & 0o022 or sticky), 'CONTROL_PARENT_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid, before.st_nlink,
            stat.S_IMODE(before.st_mode)) == (0, 0, 1, mode) and 0 < before.st_size <= maximum,
            'CONTROL_FILE_UNSAFE')
        data, size = [], 0
        while size <= maximum:
            part = os.read(fd, min(65536, maximum + 1 - size))
            if not part:break
            data.append(part);size += len(part)
        need(size == before.st_size and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()),
            'CONTROL_FILE_CHANGED')
        return b''.join(data), identity(before)
    finally:
        os.close(fd)


def select_cohort(config_path=CONFIG, unit_path=UNIT, cohorts=None):
    choices = (OLD_COHORT, NEW_COHORT) if cohorts is None else cohorts
    controls = {'config': read_control(config_path, 16384, 0o600),
        'unit': read_control(unit_path, 65536, 0o644)}
    def unique(items):
        result = {}
        for key, value in items:
            need(key not in result, 'CONFIGURATION_JSON_INVALID');result[key] = value
        return result
    def nonfinite(value):
        raise Stop('CONFIGURATION_JSON_INVALID')
    value = json.loads(controls['config'][0], object_pairs_hook=unique, parse_constant=nonfinite)
    need(isinstance(value, dict) and isinstance(value.get('base_revision'), str), 'CONFIGURATION_SEED_UNREVIEWED')
    matching = [cohort for cohort in choices if cohort['seed'] == value['base_revision']]
    need(len(matching) == 1, 'CONFIGURATION_SEED_UNREVIEWED')
    cohort = matching[0]
    need(hashlib.sha256(controls['unit'][0]).hexdigest() == cohort['unit_sha256'], 'NATIVE_UNIT_COHORT_MISMATCH')
    def verify():
        need(read_control(config_path, 16384, 0o600) == controls['config']
            and read_control(unit_path, 65536, 0o644) == controls['unit'], 'CONTROL_COHORT_CHANGED')
    verify()
    return cohort, verify


def install_guards(worker, inventory=None, candidate=None, allow_prepare=False, cohort=None, verify_controls=lambda:None):
    """Constrain a job without replacing any original tick or acceptance logic."""
    need(inventory is None or isinstance(inventory, str) and re.fullmatch(r'[a-f0-9]{64}', inventory),
        'INVENTORY_ARGUMENT_INVALID')
    need(candidate is None or isinstance(candidate, str) and re.fullmatch(r'[a-f0-9]{40}', candidate),
        'CANDIDATE_ARGUMENT_INVALID')
    need(type(allow_prepare) is bool and not (candidate is not None and allow_prepare), 'ALLOCATION_ARGUMENT_INVALID')
    need(candidate is None or inventory is not None, 'CANDIDATE_INVENTORY_REQUIRED')
    original_scan, original_tick, original_prepare = worker.Worker.scan, worker.Worker.tick, worker.Worker.prepare
    original_config = worker.read_config
    cohort = OLD_COHORT if cohort is None else cohort
    prepared = 0

    def read_config(*args, **kwargs):
        value = original_config(*args, **kwargs)
        worker.need(value.get('base_revision') == cohort['seed'], 'MANUAL_SEED_UNREVIEWED')
        verify_controls()
        return value

    def scan(self):
        value = original_scan(self)
        worker.need(inventory is None or worker.source_identity(value) == inventory, 'MANUAL_SOURCE_CHANGED')
        return value

    def tick(self):
        verify_controls()
        worker.need(self.config.get('base_revision') == cohort['seed'], 'MANUAL_SEED_UNREVIEWED')
        if candidate is not None:
            desired = self.state.get('desired')
            worker.need(isinstance(desired, dict) and desired.get('commit') == candidate
                and desired.get('inventorySha256') == inventory
                and desired.get('candidatePolicy') == cohort['policy'],
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
        cohort, verify_controls = select_cohort()
        worker = load_runtime(cohort)
        install_guards(worker, options.inventory, options.candidate, options.allow_prepare, cohort, verify_controls)
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
