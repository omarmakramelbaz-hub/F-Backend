#!/usr/bin/env python3
"""Run only the pinned additive employee migration while the build watcher stays paused.

The package adapter may call execute() under its existing watcher lock. The CLI
acquires that same lock itself. Nothing here starts a service, publishes a feed,
rolls a migration back, or prints private framework/PHP output.
"""
import argparse
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
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
RUNUSER = '/usr/sbin/runuser'
MIGRATION = 'database/migrations/2026_10_23_200000_add_employee_identity_and_regulation.php'
PROBE = 'verify_go_erp_employee_schema.php'
MAX_OUTPUT = 64 * 1024


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_EMPLOYEE_MIGRATION_' + reason)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def identity(item):
    return item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid, item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns


def ancestry(path, owners):
    for parent in (path, *path.parents):
        item = parent.lstat()
        need(stat.S_ISDIR(item.st_mode) and item.st_uid in owners
             and (not item.st_mode & 0o022 or parent != path and bool(item.st_mode & stat.S_ISVTX)), 'DIRECTORY_UNSAFE')


def pinned_file(path, expected, owners):
    need(isinstance(expected,str) and re.fullmatch(r'[a-f0-9]{40}',expected), 'PIN_INVALID')
    ancestry(path.parent,owners)
    fd = os.open(path,os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_uid in owners and before.st_nlink == 1
             and not before.st_mode & 0o022 and 0 < before.st_size <= 2*1024*1024, 'FILE_UNSAFE')
        data = bytearray()
        while len(data) <= 2*1024*1024:
            piece = os.read(fd,65536)
            if not piece: break
            data.extend(piece)
        need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd)) == identity(path.lstat())
             and blob(data) == expected, 'FILE_PIN_INVALID')
        return identity(before),bytes(data)
    finally:
        os.close(fd)


def quiescent(runner):
    for unit, states in [('go-erp-live-updates.timer',('inactive',)),
                         ('go-erp-live-updates.service',('inactive','failed'))]:
        result = runner(['/usr/bin/systemctl','show',unit,'-p','ActiveState','--value'],
            stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=10,check=False)
        need(result.returncode == 0 and result.stdout.decode('ascii',errors='replace').strip() in states, 'WATCHER_NOT_PAUSED')


def write_private(path, value):
    fd = os.open(path,os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb',closefd=False) as output:
            output.write((json.dumps(value,sort_keys=True)+'\n').encode()); output.flush(); os.fsync(fd)
    finally:
        os.close(fd)


def execute(package, audit_parent, migration_blob, probe_blob, apply, lock_fd, verify=lambda:None,
            runner=subprocess.run):
    """The passed descriptor must be the held, exact production watcher lock."""
    changed = False; attempted = False; audit = None; before = None; after = None
    report = {'format':1,'kind':'go-erp-employee-migration','ok':False,'database_changed':False,
              'watcher_started':False,'published':False,'schema_rollback_attempted':False,'credentials_disclosed':False}
    try:
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524','ROOT_AND_HOST_REQUIRED')
        owner = pwd.getpwnam('fasakha'); package = Path(package).absolute(); audit_parent = Path(audit_parent).absolute()
        ancestry(WORK,(0,)); ancestry(audit_parent,(0,)); ancestry(package,(0,))
        parent = audit_parent.lstat(); need(stat.S_IMODE(parent.st_mode) == 0o700,'AUDIT_PARENT_NOT_PRIVATE')
        lock = os.fstat(lock_fd)
        need(stat.S_ISREG(lock.st_mode) and (lock.st_uid,lock.st_gid,lock.st_nlink,stat.S_IMODE(lock.st_mode)) == (0,0,1,0o600)
             and identity(lock) == identity((WORK/'watcher.lock').lstat()), 'LOCK_UNSAFE')
        fcntl.flock(lock_fd,fcntl.LOCK_EX | fcntl.LOCK_NB)
        def check():
            verify(); need(identity(lock) == identity(os.fstat(lock_fd)) == identity((WORK/'watcher.lock').lstat()),'LOCK_CHANGED')
            quiescent(runner)
        check()
        migration = ROOT/MIGRATION; probe = package/PROBE
        migration_identity,_ = pinned_file(migration,migration_blob,(0,owner.pw_uid))
        probe_identity,probe_data = pinned_file(probe,probe_blob,(0,))
        audit = audit_parent/('employee-migration-'+uuid.uuid4().hex); audit.mkdir(mode=0o700)
        report['audit_directory'] = str(audit)
        write_private(audit/'intent.json',{'format':1,'migration':MIGRATION,'migration_blob':migration_blob,
            'probe_blob':probe_blob,'apply':bool(apply),'database_changed':False})
        # Send the root-verified probe over STDIN. The service account never
        # needs traversal access to the private deployment package or audits.
        def command(args,name,timeout,source=None):
            check()
            need(identity(migration.lstat()) == migration_identity and identity(probe.lstat()) == probe_identity,'PINNED_FILE_CHANGED')
            path = audit/name
            fd = os.open(path,os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,0o600)
            try:
                options = {'stdin':subprocess.DEVNULL} if source is None else {'input':source}
                result = runner([RUNUSER,'-u','fasakha','--',PHP,'-d','display_errors=0','-d','log_errors=0',*args],
                    cwd=str(ROOT),stdout=fd,stderr=subprocess.STDOUT,timeout=timeout,check=False,**options)
                os.fsync(fd)
                return result.returncode,path
            finally:
                os.close(fd)
        def inspect(name):
            code,path = command([],name,60,probe_data)
            need(code == 0 and path.stat().st_size <= MAX_OUTPUT,'SCHEMA_PROBE_FAILED')
            value = json.loads(path.read_bytes())
            need(isinstance(value,dict) and set(value) == {'format','kind','ok','column_count','complete','ledger','signature','business_rows_read'}
                 and value['format'] == 1 and value['kind'] == 'go-erp-employee-schema' and value['ok'] is True
                 and type(value['column_count']) is int and 0 <= value['column_count'] <= 9
                 and type(value['ledger']) is int and value['ledger'] in (0,1)
                 and type(value['complete']) is bool and value['complete'] == (value['column_count'] == 9)
                 and value['business_rows_read'] is False and isinstance(value['signature'],str)
                 and re.fullmatch(r'[a-f0-9]{64}',value['signature'])
                 and not (value['ledger'] == 1 and not value['complete']),'SCHEMA_REPORT_INVALID')
            return value
        before = inspect('before.private.log')
        report.update(column_count=before['column_count'],migration_recorded=before['ledger'] == 1)
        if not apply or before['complete'] and before['ledger'] == 1:
            check(); report.update(ok=True,already_current=before['complete'] and before['ledger'] == 1,mode='apply' if apply else 'check')
        else:
            # MySQL DDL can commit even when Artisan later fails. Persist the
            # attempt first and never claim a transactional schema rollback.
            write_private(audit/'attempt.json',{'migration':MIGRATION,'database_changed':'unknown'})
            attempted = True; changed = 'unknown'
            code,_ = command([str(ROOT/'artisan'),'migrate','--force','--no-interaction','--path='+MIGRATION],
                             'artisan.private.log',180)
            after = inspect('after.private.log')
            if after['signature'] != before['signature']: changed = True
            need(code == 0 and after['complete'] and after['ledger'] == 1,'MIGRATION_NOT_VERIFIED')
            changed = after['signature'] != before['signature']
            check(); report.update(ok=True,already_current=False,mode='apply',column_count=9,migration_recorded=True)
    except BaseException as error:
        reason = str(error)
        report['reason'] = reason if re.fullmatch(r'GO_ERP_EMPLOYEE_MIGRATION_[A-Z_]+',reason) else 'GO_ERP_EMPLOYEE_MIGRATION_PRIVATE_FAILURE'
    report['database_changed'] = changed
    report['migration_attempted'] = attempted
    if audit is not None:
        # Keep raw logs and receipts on every outcome. No business value or
        # exception message is copied into the public result.
        try:
            write_private(audit/'result.json',report)
        except BaseException:
            report.update(ok=False,reason='GO_ERP_EMPLOYEE_MIGRATION_AUDIT_RESULT_FAILED')
    return report


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--package',required=True); parser.add_argument('--audit-parent',required=True)
    parser.add_argument('--migration-blob',required=True); parser.add_argument('--probe-blob',required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--apply',action='store_true'); mode.add_argument('--check',action='store_true')
    args = parser.parse_args()
    fd = None; entered = False
    try:
        fd = os.open(WORK/'watcher.lock',os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        entered = True
        value = execute(args.package,args.audit_parent,args.migration_blob,args.probe_blob,args.apply,fd)
    except BaseException:
        value = {'format':1,'kind':'go-erp-employee-migration','ok':False,
            'reason':'GO_ERP_EMPLOYEE_MIGRATION_PRIVATE_FAILURE','database_changed':'unknown' if entered else False,
            'watcher_started':False,'published':False,'credentials_disclosed':False}
    finally:
        if fd is not None: os.close(fd)
    print(json.dumps(value,sort_keys=True))
    return 0 if value['ok'] else 78 if value['database_changed'] in (True,'unknown') else 1


if __name__ == '__main__':
    raise SystemExit(main())
