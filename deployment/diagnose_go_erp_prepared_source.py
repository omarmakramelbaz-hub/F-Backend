#!/usr/bin/env python3
"""Read-only hashes of reviewed program files after a rejected upgrade lease.

No control-plane values, account rows, source contents, environment, unknown
filenames or exception text are emitted. The retained package is never changed.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types

PACKAGE = Path('/var/lib/go-erp-live-updates/fixture-seed-download.fWonhuhN/package')
MODE_NAME = 'upgrade_go_erp_mode_inventory_root.py'
MODE_PIN = '1e2ae5f131032bd81bac11052442434bd231061b'
CANDIDATE = '5f2b0fecafec1c3803499284b0abcca4ecfed7e4'
METADATA_BLOB = '072014bf37391223fd7f700a1a59c3178a57901d'
SEED = 'cd35ee36d77bc35593cac3627a33307b71309325'
INVENTORY = 'de5ac407604249df2198aa71c2d7b4ca0a88ac16bba3cf3f028c61e3077111d8'
SOURCE = {'format':1,'files':1346,'framework':'8.83.29',
          'sha256':'41172badb00910203115a45b26c695d47757ee1cc4ac26d07ba556a7f657ea63'}
NAV = 'public/dashboard/js/dashboard-navigation.js'
NAV_BLOB = '5817024fb309d86ccaae2c79ad21391aa8507ecb'
KNOWN_VARIANTS = {
    'public/dashboard/js/branch-operations.js': {
        'pr115-a51':'af3d3571c673f157a8d340b419d20f6b05749f26',
        'employee-installer-8a72990':'d7d4855cf5c438f5e6cab1119ac32a1d7925355a'},
    'public/dashboard/css/branch-operations.css': {
        'pr115-a51':'07993031cc5f9fb48ca405d70a8adc670850f63c',
        'employee-installer-8a72990':'36f45de37836540927ec9c752bf35be0345273f8'},
}
MAX_DIFFERENCES = 100


class DiagnosticStop(Exception):
    pass


def need(value):
    if not value:raise DiagnosticStop()


def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()


def read_mode(package):
    """Execute only the exact retained public helper with stable private ancestry."""
    path=package/MODE_NAME
    for parent in (path.parent,*path.parent.parents):
        item=parent.lstat()
        need(stat.S_ISDIR(item.st_mode) and item.st_uid==0 and not item.st_mode&0o022)
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before=os.fstat(fd)
        key=lambda value:(value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,
                          value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,
             stat.S_IMODE(before.st_mode))==(0,0,1,0o644) and 0<before.st_size<=2*1024*1024)
        data=bytearray()
        while len(data)<=2*1024*1024:
            part=os.read(fd,min(65536,2*1024*1024+1-len(data)))
            if not part:break
            data.extend(part)
        need(len(data)==before.st_size and key(before)==key(os.fstat(fd))==key(path.lstat())
             and blob(data)==MODE_PIN)
    finally:os.close(fd)
    module=types.ModuleType('readonly_prepared_source_mode');module.__file__=str(path)
    sys.modules[module.__name__]=module
    exec(compile(bytes(data),str(path),'exec'),module.__dict__)
    return module


def expected_files(ctx,mode):
    """Only exact pinned helpers decide which public names can be reported."""
    prepared=dict(ctx.prior.TARGET_PINS)
    overrides=ctx.old.PREPARED_OVERRIDES
    need(len(prepared)==42 and set(overrides)=={'routes/api.php'}
         and overrides['routes/api.php']==mode.APPROVED_PINS['routes/api.php'][1]
         and set(overrides).issubset(prepared) and len(mode.APPROVED_PINS)==17)
    prepared.update(overrides)
    payment={name:pair[1] for name,pair in mode.APPROVED_PINS.items()}
    need(NAV not in prepared and NAV not in payment)
    groups={'prepared':prepared,'payment':payment,'navigation':{NAV:NAV_BLOB}}
    allowed={}
    for values in groups.values():
        for name,wanted in values.items():
            need(isinstance(name,str) and re.fullmatch(r'[A-Za-z0-9_./-]+',name)
                 and not any(part in ('','.','..') for part in name.split('/'))
                 and isinstance(wanted,str) and re.fullmatch(r'[a-f0-9]{40}',wanted)
                 and (name not in allowed or allowed[name]==wanted))
            allowed[name]=wanted
    need(len(allowed)==59 and set(KNOWN_VARIANTS).issubset(allowed))
    return groups,allowed


def candidate_baseline(ctx,mode,allowed):
    """Read fixed public objects from the disposable cache; never fetch Git."""
    repository=ctx.candidate_root
    header=ctx.base.git(repository,'cat-file','commit',CANDIDATE).split(b'\n\n',1)[0]
    need([line[7:].decode() for line in header.splitlines() if line.startswith(b'parent ')]==[SEED])
    raw=ctx.base.git(repository,'show',CANDIDATE+':'+ctx.prior.VERSIONS[2])
    need(blob(raw)==METADATA_BLOB)
    metadata=ctx.base.parse(raw);rows=metadata.get('inventory')
    need(isinstance(rows,dict) and len(rows)==2558 and metadata==ctx.prior.candidate_metadata(SEED,rows,37)
         and metadata.get('inventorySha256')==INVENTORY==mode.TARGET_INVENTORY)
    records=ctx.base.git(repository,'ls-tree','-z',CANDIDATE,'--',*sorted(allowed)).split(b'\0')
    expected={}
    for record in records:
        if not record:continue
        values,name=record.split(b'\t',1);git_mode,kind,identifier=values.decode().split()
        name=name.decode()
        need(name in allowed and name not in expected and kind=='blob' and git_mode in ('100644','100755')
             and identifier==allowed[name])
        expected[name]={'blob':identifier,'mode':git_mode}
    need(set(expected)==set(allowed))
    return rows,expected


def target_report(ctx,mode,groups,expected):
    actual={}
    # Each read failure is a fixed status so one bad path cannot hide the rest.
    with ctx.application.PinnedSourceReader(mode.ROOT,ctx.owner.pw_uid) as reader:
        for name in sorted(expected):
            try:
                data,info=reader._read(name)
                if data is None:actual[name]={'status':'missing','blob':None,'mode':None}
                else:
                    need(info is not None and stat.S_ISREG(info.st_mode))
                    actual[name]={'status':'available','blob':blob(data),
                                  'mode':'100755' if info.st_mode&0o111 else '100644'}
            except BaseException:actual[name]={'status':'unreadable','blob':None,'mode':None}
    result={}
    for group,names in groups.items():
        differences=[];matched=0
        for name in sorted(names):
            value=actual[name];wanted=expected[name]
            if value['status']=='available' and value['blob']==wanted['blob'] and value['mode']==wanted['mode']:
                matched+=1;continue
            row={'path':name,'status':value['status'] if value['status']!='available' else 'different',
                 'expected_blob':wanted['blob'],'actual_blob':value['blob'],
                 'expected_mode':wanted['mode'],'actual_mode':value['mode']}
            variant=KNOWN_VARIANTS.get(name,{})
            row['known_variants']=[label for label,pin in sorted(variant.items()) if value['blob']==pin]
            differences.append(row)
        result[group]={'files':len(names),'matches':matched,'difference_count':len(differences),
                       'differences':differences[:MAX_DIFFERENCES]}
    return result


def source_report(ctx,mode,rows):
    scanner=ctx.application.pinned_scanner(ctx.package)
    actual=scanner.scan(mode.ROOT)
    # Names discovered on the live host are never output, including additions.
    baseline={name for name,row in rows.items() if row.get('present') and scanner.selected(name)}
    aggregate=scanner.digest(actual)
    return {'actual':aggregate,'expected':SOURCE,'matches_expected':aggregate==SOURCE,
            'unknown_selected_file_count':len(set(actual)-baseline)}


def run(package=PACKAGE):
    result={'format':1,'kind':'go-erp-prepared-source-diagnostic','ok':False,
            'application_writes':False,'database_accessed':False,'control_values_read':False,
            'service_changed':False,'candidate_fetch_performed':False,'credentials_disclosed':False}
    step='pinned-context'
    try:
        need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524')
        package=Path(package).absolute();need(package.resolve()==package)
        mode=read_mode(package)
        need((mode.CANDIDATE,mode.SEED,mode.SOURCE,mode.TARGET_INVENTORY)==(CANDIDATE,SEED,SOURCE,INVENTORY))
        # Constructor validates pinned modules/runtime/cache only. Never call
        # controls(), observe(), capture_old(), execute() or any transaction.
        ctx=mode.Context(package)
        step='reviewed-rosters';groups,allowed=expected_files(ctx,mode)
        step='fixed-public-candidate';rows,expected=candidate_baseline(ctx,mode,allowed)
        step='public-file-hashes';result['targets']=target_report(ctx,mode,groups,expected)
        step='source-aggregate'
        try:result['source']=source_report(ctx,mode,rows)
        except BaseException:result['source']={'status':'unavailable','reason':'READ_ONLY_CHECK_FAILED'}
        result['ok']=True
    except BaseException:
        result.update(failed_step=step,reason='READ_ONLY_CHECK_FAILED')
    return result


if __name__=='__main__':print(json.dumps(run(),sort_keys=True))
