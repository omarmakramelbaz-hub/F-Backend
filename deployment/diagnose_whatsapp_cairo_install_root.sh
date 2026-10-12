#!/usr/bin/env bash
set -euo pipefail
exec /usr/bin/python3 - "$@" <<'PY'
import hashlib
import json
import os
import pathlib
import pwd
import re
import signal
import stat
import subprocess
import sys
import tempfile

ROOT=pathlib.Path('/home/fasakha/public_html')
RUNUSER='/usr/sbin/runuser'
WRAPPER_BLOB='4a0b02fab7675b54d8b308bbf9fca1ca6aa9fa3f'
LEGACY_BLOB='d30c13bd13ca73e5aad54f558a259e18fb95147f'
DIAGNOSTIC='diagnose_whatsapp_cairo_install.py'
DIAGNOSTIC_BLOB='6a48276e4302b5204ff3f238d96f73e11678cb78'
STAGE='ARGUMENTS'
class SafeError(Exception):pass

def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def read_blob(digest):
    process=subprocess.Popen([RUNUSER,'-u','fasakha','--','/usr/bin/git','-C',str(ROOT),'cat-file','blob',digest],
        stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,start_new_session=True)
    try:
        data,_=process.communicate(timeout=30)
    except BaseException:
        try:os.killpg(process.pid,signal.SIGTERM)
        except ProcessLookupError:pass
        try:process.communicate(timeout=5)
        except subprocess.TimeoutExpired:
            try:os.killpg(process.pid,signal.SIGKILL)
            except ProcessLookupError:pass
            process.communicate(timeout=10)
        try:os.killpg(process.pid,signal.SIGKILL)
        except ProcessLookupError:pass
        raise
    if process.returncode!=0 or not data or len(data)>1048576 or blob(data)!=digest:
        raise SafeError('CAIRO_DIAGNOSTIC_BOOTSTRAP_PIN_FAILED')
    return data

def program(data):
    text=data.decode('utf-8');marker='exec /usr/bin/python3 - "$@" <<\'PY\'\n'
    if text.count(marker)!=1 or not text.endswith('\nPY\n'):
        raise SafeError('CAIRO_DIAGNOSTIC_WRAPPER_FORMAT_INVALID')
    namespace={'__name__':'verified_cairo_diagnostic_staging'}
    exec(compile(text.split(marker,1)[1][:-len('\nPY\n')],'verified-cairo-wrapper','exec'),namespace)
    return namespace

def validate_report(code,output):
    if not isinstance(output,bytes)or not output or len(output)>65536:
        raise SafeError('CAIRO_DIAGNOSTIC_REPORT_INVALID')
    try:report=json.loads(output)
    except (ValueError,UnicodeError):raise SafeError('CAIRO_DIAGNOSTIC_REPORT_INVALID')from None
    if not isinstance(report,dict)or report.get('diagnostic')!='CAIRO_INSTALL_READ_ONLY':
        raise SafeError('CAIRO_DIAGNOSTIC_REPORT_INVALID')
    if code!=0:
        if (set(report)!={'diagnostic','status','stage','exception_class'}or report['status']!='FAILED'
            or report['stage']not in('ARGUMENTS','VERIFIED_APP_LOAD','READ_ONLY_CHECKS')
            or not isinstance(report['exception_class'],str)
            or re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,63}',report['exception_class'])is None):
            raise SafeError('CAIRO_DIAGNOSTIC_REPORT_INVALID')
        return report
    expected={'diagnostic','configuration_writes','runtime_writes','worker_actions','network_requests',
        'ai_calls','orders_created','temporary_probe','temporary_files_only','checks_total','checks_truncated','checks'}
    if (set(report)!=expected or any(type(report[key])is not int or report[key]!=0 for key in
        ('configuration_writes','runtime_writes','worker_actions','network_requests','ai_calls','orders_created'))
        or type(report['temporary_probe'])is not bool or report['temporary_files_only']is not True
        or type(report['checks_total'])is not int or not 0<=report['checks_total']<=100
        or type(report['checks_truncated'])is not bool or not isinstance(report['checks'],list)
        or len(report['checks'])>42):raise SafeError('CAIRO_DIAGNOSTIC_REPORT_INVALID')
    return report

def main():
    global STAGE
    try:
        if os.geteuid()!=0 or len(sys.argv)!=3 or sys.argv[1]!='--release'or not re.fullmatch('[a-f0-9]{40}',sys.argv[2]):
            raise SafeError('RUN_ROOT_DIAGNOSTIC_WITH_PINNED_RELEASE')
        pwd.getpwnam('fasakha');temporary=pathlib.Path('/tmp');info=temporary.lstat()
        if not stat.S_ISDIR(info.st_mode)or info.st_uid!=0 or not info.st_mode&stat.S_ISVTX:
            raise SafeError('CAIRO_DIAGNOSTIC_STICKY_TEMP_UNAVAILABLE')
        os.umask(0o077)
        signal.signal(signal.SIGTERM,lambda *_:(_ for _ in ()).throw(SafeError('CAIRO_DIAGNOSTIC_INTERRUPTED')))
        STAGE='VERIFIED_BOOTSTRAP';wrapper=program(read_blob(WRAPPER_BLOB))
        legacy=wrapper['legacy_program'](read_blob(LEGACY_BLOB).decode('utf-8'))
        with tempfile.TemporaryDirectory(prefix='fasakhansta-cairo-diagnostic-',dir=temporary)as folder:
            payload=pathlib.Path(folder);payload.chmod(0o755);STAGE='PAYLOAD_STAGING'
            wrapper['stage_payload'](payload,sys.argv[2],legacy['command'])
            code,data=legacy['command']([RUNUSER,'-u','fasakha','--','/usr/bin/git','-C',str(ROOT),
                'show',sys.argv[2]+':deployment/'+DIAGNOSTIC])
            if code!=0 or not data or blob(data)!=DIAGNOSTIC_BLOB:
                raise SafeError('CAIRO_DIAGNOSTIC_HELPER_PIN_FAILED')
            path=payload/'deployment'/DIAGNOSTIC
            fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o644)
            with os.fdopen(fd,'wb')as stream:
                os.fchmod(stream.fileno(),0o644);stream.write(data);stream.flush();os.fsync(stream.fileno())
            STAGE='APP_USER_DIAGNOSTIC'
            code,output=legacy['command']([RUNUSER,'-u','fasakha','--','/usr/bin/python3','-B',str(path),
                '--root',str(ROOT),'--manifest',str(payload/'deployment'/'whatsapp_cairo_origins_manifest.json')],timeout=300)
            report=validate_report(code,output)
        print(json.dumps(report,sort_keys=True));return code
    except SafeError as error:print(str(error),file=sys.stderr)
    except BaseException as error:
        name=type(error).__name__;name=name if re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,63}',name)else'UNKNOWN'
        print('CAIRO_DIAGNOSTIC_STOPPED_WITHOUT_SECRET_OUTPUT; STAGE='+STAGE+'; EXCEPTION_CLASS='+name,file=sys.stderr)
    return 1

if __name__=='__main__':sys.exit(main())
PY
