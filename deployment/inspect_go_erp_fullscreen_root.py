#!/usr/bin/env python3
"""Fixed read-only GO ERP native-release identities; never prints private configuration."""
import hashlib,json,os,re,socket,stat,subprocess
from pathlib import Path
if os.geteuid()!=0 or socket.gethostname().split('.')[0]!='srv1836524':
    raise SystemExit('GO_ERP_FULLSCREEN_INSPECT_ROOT_AND_HOST_REQUIRED')
work=Path('/var/lib/go-erp-live-updates')
errors=[]
def read(path,tag,limit=16*1024*1024):
    try:
        fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
        try:
            before=os.fstat(fd)
            if not stat.S_ISREG(before.st_mode) or before.st_nlink!=1 or before.st_size>limit: raise ValueError()
            data=b''
            while len(data)<=limit:
                part=os.read(fd,min(65536,limit+1-len(data)))
                if not part: break
                data+=part
            after=os.fstat(fd)
            if len(data)!=before.st_size or (before.st_ino,before.st_size,before.st_mtime_ns,before.st_ctime_ns)!=(after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns): raise ValueError()
            return data
        finally: os.close(fd)
    except Exception:
        errors.append(tag);return None
def document(path,tag):
    raw=read(path,tag)
    if raw is None:return {}
    try:
        value=json.loads(raw)
        if not isinstance(value,dict):raise ValueError()
        return value
    except Exception:
        errors.append(tag+'_JSON');return {}
def digest(value,n=40):
    return value if isinstance(value,str) and re.fullmatch('[a-f0-9]{'+str(n)+'}',value) else None
def version(value):
    return value if isinstance(value,str) and re.fullmatch(r'0\.4\.4-preview\.[1-9][0-9]*',value) else None
def number(value):
    return value if type(value) is int and 0<=value<10**12 else None
def source(value):
    value=value if isinstance(value,dict) else {}
    return {'sha256':digest(value.get('sha256'),64),'files':number(value.get('files'))}
def release(value):
    value=value if isinstance(value,dict) else {}
    return {'version':version(value.get('version')),'sequence':number(value.get('sequence')),'source_revision':digest(value.get('source_revision')),'source':source(value.get('source'))}
config=document(Path('/etc/go-erp/live-updates.json'),'CONFIG')
state=document(work/'state/current.json','STATE')
hold=document(work/'program-rollout-hold.json','HOLD')
feed=document(Path('/home/fasakha/public_html/storage/app/desktop-updates/current.json'),'FEED')
desired=state.get('desired') if isinstance(state.get('desired'),dict) else {}
published=state.get('published') if isinstance(state.get('published'),dict) else {}
authorization=hold.get('authorization') if isinstance(hold.get('authorization'),dict) else {}
out={'config_seed':digest(config.get('base_revision')),'state_seed':digest(state.get('base_revision')),
     'counter':number(state.get('counter')),'version_floor':version(state.get('version_floor')),
     'last_remote_sha':digest(state.get('last_remote_sha')),'sequence_floor':number(state.get('sequence_floor')),
     'desired':{'commit':digest(desired.get('commit')),'version':version(desired.get('version')),'inventory_sha256':digest(desired.get('inventorySha256'),64),'pushed':desired.get('pushed') is True,'status':desired.get('status') if desired.get('status') in ('prepared','waiting-ci','retry-build','blocked','published') else None},
     'published':{'commit':digest(published.get('commit')),'run_id':number(published.get('run_id')),'run_attempt':number(published.get('run_attempt')),'inventory_sha256':digest(published.get('inventorySha256'),64),'release':release(published.get('release'))},
     'hold':{'core':digest(hold.get('core')),'source':source(hold.get('source')),'inventory_sha256':digest(hold.get('inventory_sha256'),64),'authorization_present':hold.get('authorization') is not None,'authorized_candidate':digest(authorization.get('candidate')),'authorized_version':version(authorization.get('version'))},
     'feed':release(feed)}
native=['desktop-pos/src/main.cjs','desktop-pos/src/dashboard.cjs','desktop-pos/package.json','desktop-pos/package-lock.json','.github/workflows/desktop-dashboard-tests.yml','.github/workflows/go-erp-update-tests.yml','.github/workflows/whatsapp-integrated-regressions.yml']
gui=feed.get('gui') if isinstance(feed.get('gui'),dict) else {}
out['feed_native_sha256']={name:digest(gui.get(name),64) for name in native}
env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_CONFIG_SYSTEM':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_OPTIONAL_LOCKS':'0','GIT_NO_LAZY_FETCH':'1','GIT_TERMINAL_PROMPT':'0'}
def git(*args):
    try:
        result=subprocess.run(['/usr/bin/git','--no-optional-locks','-c','core.fsmonitor=false','-c','core.hooksPath=/dev/null','-c','credential.helper=','-C',str(work/'checkout'),*args],env=env,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=15,check=True)
        if len(result.stdout)>65536:raise ValueError()
        return result.stdout.decode('ascii')
    except Exception:
        errors.append('SEED_GIT');return ''
out['checkout_head']=digest(git('rev-parse','--verify','HEAD').strip())
out['seed_native_blobs']={}
for row in git('ls-tree','HEAD','--',*native).splitlines():
    try:
        metadata,name=row.split('\t',1);mode,kind,sha=metadata.split()
        if name in native and mode in ('100644','100755') and kind=='blob' and digest(sha):
            out['seed_native_blobs'][name]={'mode':mode,'blob':sha}
    except Exception:errors.append('SEED_TREE')
unit=read(Path('/etc/systemd/system/go-erp-live-updates.service'),'UNIT',65536)
out['unit_sha256']=hashlib.sha256(unit).hexdigest() if unit is not None else None
out['runtime_hashes']={}
match=re.search(rb'/opt/go-erp-live-updates/releases/([A-Za-z0-9._-]{1,100})/go_erp_live_updates\.py',unit or b'')
if match and match[1] not in (b'.',b'..'):
    out['runtime_revision']=digest(match[1].decode('ascii'))
    runtime=Path('/opt/go-erp-live-updates/releases')/match[1].decode('ascii')
    for name in ['go_erp_live_source.py','go_erp_live_updates.py','publish_go_erp_update.py','go_erp_current_candidate_ci.yml','verify_go_erp_current_candidate_ci.py']:
        raw=read(runtime/name,'RUNTIME_'+name)
        out['runtime_hashes'][name]=hashlib.sha256(raw).hexdigest() if raw is not None else None
out['errors']=sorted(set(errors))
print('GO_ERP_FULLSCREEN_INSPECTION '+json.dumps(out,sort_keys=True,separators=(',',':')))
