#!/usr/bin/env python3
"""Resume only the existing accepted .59 build through the frozen .59 operator."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import sys
import time
import types

ORIGINAL_RELEASE = '33e08c9fec93501d2376eb88d9fd402043f8d792'
OPERATOR_SHA256 = '49d065ed05a033c616101fdd9220de1d07260eb4b35285bb5b52d4a65bce6b81'
TICK_SHA256 = 'b34a7b87e716acedc50c38a871b1888113b8d10095b4fb0d9f6325ae8f192931'
COMPAT_SHA256 = 'ab2267fb8ec29188a39b9d1a44b643a95c7e483b1ef50ffc2b6724961268bad2'
FIXED_REPOSITORY = Path('/var/tmp/go-erp-navigation.p5p1DAmt/release.git')
CORE = '13fd8b038bd1a853a30ba9b4140a053088efa73d'
SEED = '0885a3b550f92df2b74bcd2c16d27954d8b44b7b'
RUNTIME = 'policy-4d1bb8ea0a62b0be48dcaed76dc6e5bdabc15e7c6c123c921eb211c5ca7fab71'
FIXED_JOB = Path('/var/lib/go-erp-manual-updates/private/navigation-' + CORE + '.json')
CANDIDATE = '5becb9bab38a91998d1b1341670b2b398e4ba70f'
VERSION = '0.4.4-preview.59'
INVENTORY = '76bd768b032994d0cb3db624c0b2ef0e5bafedb3a2cbffd394b66e31ce53c225'
CODE = re.compile(r'GO_ERP_[A-Z0-9_]{1,140}\Z')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def secure_directory(path):
    need(path.is_absolute() and path.resolve() == path, 'REPOSITORY_PATH_INVALID')
    for parent in (*reversed(path.parents), path):
        info = parent.lstat()
        sticky = parent != path and bool(info.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0
            and (not info.st_mode & 0o022 or sticky), 'REPOSITORY_DIRECTORY_UNSAFE')


def regular_bytes(path, maximum=128*1024):
    path = Path(path)
    secure_directory(path.parent)
    need(path.is_absolute() and path.resolve() == path, 'HELPER_PATH_INVALID')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
            and stat.S_IMODE(before.st_mode) == 0o600 and 0 < before.st_size <= maximum,
            'HELPER_FILE_UNSAFE')
        raw = b''
        while len(raw) <= maximum:
            part = os.read(fd, min(65536, maximum+1-len(raw)))
            if not part: break
            raw += part
        after = os.fstat(fd); named = path.lstat()
        identity = lambda value:(value.st_dev,value.st_ino,value.st_mode,value.st_uid,
            value.st_gid,value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
        need(len(raw) == before.st_size and identity(before) == identity(after) == identity(named),
            'HELPER_FILE_CHANGED')
        return raw
    finally:
        os.close(fd)


def module(raw, name, expected):
    need(hashlib.sha256(raw).hexdigest() == expected, 'HELPER_SHA256_MISMATCH')
    value = types.ModuleType(name)
    value.__file__ = '<pinned-' + name + '>'
    sys.modules[name] = value
    exec(compile(raw, value.__file__, 'exec'), value.__dict__)
    return value


def git_bytes(repository, name):
    secure_directory(repository)
    need(stat.S_IMODE(repository.parent.lstat().st_mode) == 0o700, 'RETAINED_PACKAGE_NOT_PRIVATE')
    result = subprocess.run(['/usr/bin/git','--git-dir='+str(repository),
        '-c','credential.helper=','-c','core.hooksPath=/dev/null',
        'show',ORIGINAL_RELEASE+':'+name], stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=180,
        env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8','GIT_CONFIG_NOSYSTEM':'1',
            'GIT_CONFIG_GLOBAL':'/dev/null','GIT_CONFIG_SYSTEM':'/dev/null',
            'GIT_NO_REPLACE_OBJECTS':'1','GIT_NO_LAZY_FETCH':'1',
            'GIT_OPTIONAL_LOCKS':'0','GIT_TERMINAL_PROMPT':'0'})
    need(result.returncode == 0 and 0 < len(result.stdout) <= 2*1024**2,
        'ORIGINAL_OPERATOR_READ_FAILED')
    return result.stdout


def parse_job(raw):
    def pairs(items):
        value = {}
        for key,item in items:
            need(key not in value,'JOB_DUPLICATE_KEY')
            value[key] = item
        return value
    def invalid(value):
        raise Stop('JOB_NONFINITE')
    return check_job(json.loads(raw,object_pairs_hook=pairs,parse_constant=invalid))


def check_job(job):
    need(isinstance(job,dict) and job.get('kind') == 'go-erp-warm-start-rollout'
        and type(job.get('format')) is int and job['format'] == 1
        and job.get('core') == CORE and job.get('seed') == SEED
        and job.get('runtime') == RUNTIME and job.get('phase') in ('prepared','running','ready')
        and job.get('prepare_started') is True and job.get('candidate') == CANDIDATE
        and job.get('version') == VERSION and job.get('inventory') == INVENTORY,
        'EXISTING_59_JOB_REQUIRED')
    return job


def read_job(tick):
    return parse_job(tick.read_control(FIXED_JOB,16*1024**2,0o600)[0])


def run_tick(tick, compat, inventory, candidate):
    need(inventory == INVENTORY and candidate == CANDIDATE, 'TICK_BINDING_INVALID')
    original_load = tick.load_runtime
    def load_runtime(cohort, *args, **kwargs):
        need(cohort == tick.NEW_COHORT, 'EXACT_59_COHORT_REQUIRED')
        worker = original_load(cohort,*args,**kwargs)
        compat.install(worker)
        return worker
    tick.load_runtime = load_runtime
    try:
        return tick.main(['--inventory',inventory,'--candidate',candidate])
    finally:
        tick.load_runtime = original_load


def child_tick(context, compat_path, repository, allow, budget=2700):
    need(allow is False, 'NEW_ALLOCATION_PROHIBITED')
    check_job(context.job)
    args = ['/usr/bin/python3','-I','-B',str(Path(__file__).resolve()),
        '--tick','--git-dir',str(repository),'--release',ORIGINAL_RELEASE,
        '--compat-file',str(compat_path),'--inventory',INVENTORY,'--candidate',CANDIDATE]
    started = time.monotonic(); deadline = started+min(2700,budget)
    with subprocess.Popen(args,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE) as child:
        while True:
            remaining = deadline-time.monotonic()
            if remaining <= 0:
                child.kill(); child.communicate()
                raise Stop('WORKER_TIMED_OUT_RETRY_SAME_COMMAND')
            try:
                stdout,stderr = child.communicate(timeout=min(30,remaining)); break
            except subprocess.TimeoutExpired:
                print('GO_ERP_WARM_START_WAITING_WORKER seconds='+str(int(time.monotonic()-started)),flush=True)
        result = types.SimpleNamespace(stdout=stdout,stderr=stderr,returncode=child.returncode)
    need(len(result.stdout)+len(result.stderr) <= 8192,'TICK_OUTPUT_INVALID')
    lines = (result.stdout+result.stderr).decode('utf8','strict').splitlines()
    need(result.returncode in (0,1) and 1 <= len(lines) <= 16
        and all(CODE.fullmatch(value) for value in lines),'TICK_OUTPUT_INVALID')
    return result.returncode,lines[-1]


def configure_parent(operator, compat, compat_path, repository):
    original_configure = operator.configure
    def configure(template, *args, **kwargs):
        value = original_configure(template,*args,**kwargs)
        previous = value.Context
        class Context(previous):
            def __init__(self,*arguments,**keywords):
                super().__init__(*arguments,**keywords)
                compat.install(self.worker)

            def job_record(self):
                need(self.job_path == FIXED_JOB,'JOB_PATH_CHANGED')
                existing = self.e.read_file(self.job_path,16*1024**2,uid=0,modes=(0o600,))
                need(existing is not None,'EXISTING_59_JOB_REQUIRED')
                parse_job(existing[0])
                result = super().job_record()
                check_job(self.job)
                return result

            def tick(self,allow,budget=2700):
                return child_tick(self,compat_path,repository,allow,budget)
        value.Context = Context
        return value
    operator.configure = configure


def main(arguments=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir',default=str(FIXED_REPOSITORY))
    parser.add_argument('--release',default=ORIGINAL_RELEASE)
    parser.add_argument('--compat-file',required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--complete',action='store_true'); mode.add_argument('--tick',action='store_true')
    parser.add_argument('--inventory'); parser.add_argument('--candidate')
    options = parser.parse_args(arguments)
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
        'ROOT_HOST_REQUIRED')
    os.umask(0o077)
    repository = Path(options.git_dir); compat_path = Path(options.compat_file)
    need(repository == FIXED_REPOSITORY and options.release == ORIGINAL_RELEASE,
        'ORIGINAL_RELEASE_REQUIRED')
    # Keep our executable stable before launching the identical child driver.
    regular_bytes(Path(__file__).absolute())
    compat = module(regular_bytes(compat_path),'go_erp_navigation_zip_compat',COMPAT_SHA256)
    tick = module(git_bytes(repository,'deployment/go_erp_navigation_tick_root.py'),
        'go_erp_navigation_zip_original_tick',TICK_SHA256)
    read_job(tick)
    if options.tick:
        return run_tick(tick,compat,options.inventory,options.candidate)
    need(options.inventory is None and options.candidate is None,'PARENT_ARGUMENTS_INVALID')
    operator = module(git_bytes(repository,'deployment/upgrade_go_erp_navigation_root.py'),
        'go_erp_navigation_zip_original_operator',OPERATOR_SHA256)
    configure_parent(operator,compat,compat_path,repository)
    print('GO_ERP_NAVIGATION_RESUMING_SAME_59_ARTIFACT',flush=True)
    previous_argv = sys.argv
    try:
        sys.argv = ['pinned-navigation-operator','--git-dir',str(repository),
            '--release',ORIGINAL_RELEASE,'--complete']
        operator.main()
    finally:
        sys.argv = previous_argv
    return 0


if __name__ == '__main__':
    try:
        result = main()
    except BaseException as error:
        code = str(error)
        code = code if (isinstance(error,Stop) or code.startswith('GO_ERP_')) \
            and re.fullmatch(r'(?:GO_ERP_)?[A-Z0-9_]{1,160}',code) else 'PRIVATE_FAILURE'
        print(json.dumps({'format':1,'kind':'go-erp-navigation-rollout','ok':False,
            'code':'GO_ERP_NAVIGATION_ZIP_RESUME_'+code,'published':False,
            'database_changed':False,'credentials_disclosed':False,'automatic_updates_enabled':False}),flush=True)
        result = 1
    raise SystemExit(result)
