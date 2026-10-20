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
try:
    assert os.geteuid()==pwd.getpwnam('fasakha').pw_uid
    spec=importlib.util.spec_from_file_location('pinned_reader',sys.argv[1])
    reader=importlib.util.module_from_spec(spec);spec.loader.exec_module(reader)
    rows=[
        ('app/Console/Kernel.php','4cb562144eb1f9e6fc5857aa56ce8221be2974e3','1426ee83cbcc26b8664515397dcf2eca49559c8776a0bc7e62ec7efd190eb05a'),
        ('app/Services/Dashboard/MetaCommentAiProvider.php','1896cc2f107336cf6bf94505276600b6fbf46975','86a8daaad64d0885dba6ffdb2470bd8ab32eac11f3a47af9c6c8a737120ca2bb'),
        ('database/migrations/2026_10_20_045322_create_cache_table.php','88cd44590c029964801da3f058d89413f728ceb1','1166af1eaec2164cc869ad41d6c622b7c8aadda5d8009bdb05519c1438ffd197'),
    ]
    def read(relative):
        fd=reader.open_root(Path('/home/fasakha/public_html'))
        try:
            for part in Path(relative).parts[:-1]:
                child=reader.open_directory(fd,part);os.close(fd);fd=child
            data,item=reader.read_file(fd,Path(relative).name,2*1024*1024)
            assert item.st_uid==os.geteuid() and item.st_nlink==1
            assert stat.S_IMODE(item.st_mode)==0o644
            return data,reader.identity(item)
        finally:os.close(fd)
    first={};files=[]
    for relative,pin,normalized_pin in rows:
        data,identity=read(relative)
        actual=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
        assert actual==pin
        assert hashlib.sha256(data.replace(b'\r\n',b'\n')).hexdigest()==normalized_pin
        first[relative]=(data,identity)
        files.append({'path':relative,'blob':actual,'normalizedSha256':normalized_pin,
                      'sha256':hashlib.sha256(data).hexdigest(),'bytes':len(data),
                      'base64':base64.b64encode(data).decode('ascii')})
    for relative,_,_ in rows:assert read(relative)==first[relative]
    payload={'format':1,'kind':'desktop-missing-source-bytes','files':files,
             'matchesReportedBlobs':True,'fullSourceRechecked':False,
             'applicationChanged':False,'databaseRead':False}
    packed=gzip.compress(json.dumps(payload,separators=(',',':')).encode(),mtime=0)
    print('DESKTOP_SOURCE_BYTES_BEGIN')
    print(base64.b64encode(packed).decode('ascii'))
    print('DESKTOP_SOURCE_BYTES_END; APPLICATION_CHANGED=false')
except BaseException:
    print('DESKTOP_SOURCE_CAPTURE_STOPPED_NO_APPLICATION_CHANGES',file=sys.stderr)
    sys.exit(1)
PY
SH
