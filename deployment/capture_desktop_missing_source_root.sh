#!/usr/bin/env bash
bash <<'SH'
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
capture_root=/home/fasakha/public_html
capture_rev=9961cb80e55dcfaa67f063172f486d7e1f0228a6
capture_tmp="$(mktemp -d /tmp/desktop-source-bytes.XXXXXXXX)"
trap 'rm -rf -- "$capture_tmp"' EXIT
runuser -u fasakha -- git -C "$capture_root" show \
  "$capture_rev:deployment/diagnose_desktop_dashboard_current_source.py" > "$capture_tmp/reader.py"
test "$(git hash-object --no-filters "$capture_tmp/reader.py")" = \
  e7a7a8271481013a1c16167d6f7a6ff429687ef5
chmod 0644 "$capture_tmp/reader.py"
chmod 0755 "$capture_tmp"
timeout --signal=TERM --kill-after=5s 90s \
  runuser -u fasakha -- /usr/bin/python3 -B - "$capture_tmp/reader.py" <<'PY'
import base64,gzip,hashlib,importlib.util,json,os,pwd,stat,sys
from pathlib import Path
class CaptureStopped(Exception):
    def __init__(self,stage,relative=None,metadata=None):
        self.stage=stage;self.relative=relative;self.metadata=metadata

def read(root,relative,expected_uid,expected_gid,reader):
    fd=None
    try:
        fd=reader.open_root(root)
        for part in Path(relative).parts[:-1]:
            child=reader.open_directory(fd,part);os.close(fd);fd=child
        data,item=reader.read_file(fd,Path(relative).name,2*1024*1024)
        metadata=reader.public_metadata(item)
        if (item.st_uid!=expected_uid or item.st_gid!=expected_gid
                or item.st_nlink!=1 or stat.S_IMODE(item.st_mode)!=0o644):
            raise CaptureStopped('METADATA',relative,metadata)
        return data,reader.identity(item)
    except CaptureStopped:raise
    except BaseException:raise CaptureStopped('READ',relative) from None
    finally:
        if fd is not None:os.close(fd)

def capture_files(root,rows,reader):
    first={};files=[]
    for relative,pin,normalized_pin,uid,gid in rows:
        data,identity=read(root,relative,uid,gid,reader)
        actual=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
        if actual!=pin or hashlib.sha256(data.replace(b'\r\n',b'\n')).hexdigest()!=normalized_pin:
            raise CaptureStopped('SOURCE_CHANGED',relative)
        first[relative]=(data,identity)
        files.append({'path':relative,'blob':actual,'normalizedSha256':normalized_pin,
                      'sha256':hashlib.sha256(data).hexdigest(),'bytes':len(data),
                      'base64':base64.b64encode(data).decode('ascii')})
    for relative,_,_,uid,gid in rows:
        if read(root,relative,uid,gid,reader)!=first[relative]:
            raise CaptureStopped('REREAD_CHANGED',relative)
    return files

stage='READER'
try:
    assert os.geteuid()==pwd.getpwnam('fasakha').pw_uid==1001
    spec=importlib.util.spec_from_file_location('pinned_reader',sys.argv[1])
    reader=importlib.util.module_from_spec(spec);spec.loader.exec_module(reader)
    rows=[
        ('app/Console/Kernel.php','4cb562144eb1f9e6fc5857aa56ce8221be2974e3','1426ee83cbcc26b8664515397dcf2eca49559c8776a0bc7e62ec7efd190eb05a',1001,1003),
        ('app/Services/Dashboard/MetaCommentAiProvider.php','1896cc2f107336cf6bf94505276600b6fbf46975','86a8daaad64d0885dba6ffdb2470bd8ab32eac11f3a47af9c6c8a737120ca2bb',1001,1003),
        ('database/migrations/2026_10_20_045322_create_cache_table.php','88cd44590c029964801da3f058d89413f728ceb1','1166af1eaec2164cc869ad41d6c622b7c8aadda5d8009bdb05519c1438ffd197',0,0),
    ]
    stage='CAPTURE'
    files=capture_files(Path('/home/fasakha/public_html'),rows,reader)
    payload={'format':1,'kind':'desktop-missing-source-bytes','files':files,
             'matchesReportedBlobs':True,'fullSourceRechecked':False,
             'applicationChanged':False,'databaseRead':False}
    stage='PACK'
    packed=gzip.compress(json.dumps(payload,separators=(',',':')).encode(),mtime=0)
    print('DESKTOP_SOURCE_BYTES_BEGIN')
    print(base64.b64encode(packed).decode('ascii'))
    print('DESKTOP_SOURCE_BYTES_END; APPLICATION_CHANGED=false')
except CaptureStopped as error:
    print('DESKTOP_SOURCE_CAPTURE_STOPPED '+json.dumps({'stage':error.stage,
          'file':error.relative,'metadata':error.metadata,'applicationChanged':False},separators=(',',':')),file=sys.stderr)
    sys.exit(1)
except BaseException as error:
    print('DESKTOP_SOURCE_CAPTURE_STOPPED '+json.dumps({'stage':stage,
          'class':type(error).__name__,'applicationChanged':False},separators=(',',':')),file=sys.stderr)
    sys.exit(1)
PY
SH
