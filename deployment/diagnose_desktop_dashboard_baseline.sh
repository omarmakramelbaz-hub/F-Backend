#!/usr/bin/env bash
set -euo pipefail
umask 077
timeout --signal=TERM --kill-after=5s 180s runuser -u fasakha -- /usr/bin/python3 -B - <<'PY'
import hashlib,json,os,pathlib,re,stat,subprocess,sys
ROOT=pathlib.Path('/home/fasakha/public_html')
REV='ff047e297541652632b3c8e32b7c6ce7b6781ce0'
READER='deployment/desktop_dashboard_server_preflight.py'
READER_PIN='4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4'
PINS={
 'deployment/desktop_dashboard_server_manifest.json':'d5004affa55123f35eedc64411ca663e308d5e3a',
 'deployment/desktop_dashboard_server_manifest_attachments.json':'a5e4af59de8141d0bb1a616fda063a609c869b91',
}
ENV={**os.environ,'GIT_NO_LAZY_FETCH':'1','GIT_OPTIONAL_LOCKS':'0','GIT_NO_REPLACE_OBJECTS':'1'}
def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def git(*args):
 p=subprocess.run(['/usr/bin/git','--no-optional-locks','-c','core.fsmonitor=false','-C',str(ROOT),*args],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=45,env=ENV)
 if p.returncode or len(p.stdout)>8*1024*1024:raise RuntimeError('PINNED_GIT_READ_FAILED')
 return p.stdout
def saved(path,pin):
 data=git('show',REV+':'+path)
 if blob(data)!=pin:raise RuntimeError('PINNED_DIAGNOSTIC_HASH_FAILED')
 return data
def source_path(name,ns):return (name=='artisan' or any(name.startswith(root+'/')for root in ns['ROOTS']))and ns['included'](name)
def metadata(path):
 try:item=path.lstat()
 except FileNotFoundError:return None
 return {'uid':item.st_uid,'gid':item.st_gid,'mode':format(stat.S_IMODE(item.st_mode),'04o'),'links':item.st_nlink,'regular':stat.S_ISREG(item.st_mode)}
try:
 if ROOT.resolve()!=ROOT or ROOT.is_symlink()or(ROOT/'.git').is_symlink():raise RuntimeError('PROJECT_PATH_INVALID')
 namespace={'__name__':'pinned_read_only_source_inspection'}
 exec(compile(saved(READER,READER_PIN),'<pinned-read-only-source-reader>','exec'),namespace)
 manifests=[json.loads(saved(path,pin))for path,pin in PINS.items()]
 target={}
 for row in filter(None,git('ls-tree','-r','-z',REV).split(b'\0')):
  header,name=row.split(b'\t',1);name=name.decode();mode,kind,sha=header.decode().split()
  if source_path(name,namespace):
   if mode not in('100644','100755')or kind!='blob'or not namespace['SAFE'].fullmatch(name):raise RuntimeError('TARGET_SOURCE_PATH_INVALID')
   target[name]=sha
 expected=[];gui=[]
 for manifest in manifests:
  before=dict(target);outside={}
  for row in manifest['sources']:
   name=row['path']
   if source_path(name,namespace):
    if row['before_blob']is None:before.pop(name,None)
    else:before[name]=row['before_blob']
   else:outside[name]=row['before_blob']
  for row in manifest['protected']:
   name=row['path']
   if source_path(name,namespace):before[name]=row['blob']
   else:outside[name]=row['blob']
  if len(before)!=manifest['before_source']['files']:raise RuntimeError('BASELINE_SOURCE_MAP_COUNT_INVALID')
  expected.append(before);gui.append(outside)
 def outside_source():
  result={}
  for name in sorted(set(gui[0])|set(gui[1])):
   if not namespace['SAFE'].fullmatch(name)or namespace['SECRET'].search(name):raise RuntimeError('GUI_PATH_INVALID')
   path=ROOT/name
   if not path.exists()and not path.is_symlink():result[name]=None;continue
   if path.is_symlink():raise RuntimeError('GUI_LINK_NEEDS_REVIEW')
   for parent in path.parents:
    if parent==ROOT.parent:break
    if parent.is_symlink():raise RuntimeError('GUI_ANCESTOR_LINK_NEEDS_REVIEW')
   descriptor=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
   with os.fdopen(descriptor,'rb')as stream:
    first=os.fstat(stream.fileno())
    if not stat.S_ISREG(first.st_mode)or first.st_size>8*1024*1024:raise RuntimeError('GUI_TYPE_OR_LIMIT_INVALID')
    data=stream.read(8*1024*1024+1);last=os.fstat(stream.fileno())
   key=lambda item:(item.st_dev,item.st_ino,item.st_size,item.st_mtime_ns,item.st_ctime_ns)
   if len(data)>8*1024*1024 or key(first)!=key(last)or key(last)!=key(path.lstat()):raise RuntimeError('GUI_CHANGED_DURING_READ')
   result[name]=blob(data)
  return result
 first_head=git('rev-parse','HEAD').decode().strip();first=namespace['live_source'](ROOT);first_gui=outside_source()
 second=namespace['live_source'](ROOT);second_gui=outside_source();second_head=git('rev-parse','HEAD').decode().strip()
 if first!=second or first_gui!=second_gui or first_head!=second_head:raise RuntimeError('SOURCE_CHANGED_DURING_DIAGNOSIS')
 actual={name:value['blob']for name,value in second.items()};fingerprint=namespace['digest'](second)
 rows=[];counts=[0,0]
 for name in sorted(set(actual)|set(expected[0])|set(expected[1])):
  values=[actual.get(name),expected[0].get(name),expected[1].get(name)]
  for index in(0,1):counts[index]+=values[0]!=values[index+1]
  if values[0]!=values[1]or values[0]!=values[2]:rows.append({'path':name,'actual':values[0],'previous':values[1],'attachments':values[2],'metadata':metadata(ROOT/name)})
 gui_rows=[{'path':name,'actual':second_gui[name],'previous':gui[0].get(name),'attachments':gui[1].get(name),'metadata':metadata(ROOT/name)}for name in sorted(second_gui)]
 comparisons=[]
 for index,manifest in enumerate(manifests):
  comparisons.append({'cohort':'previous'if index==0 else'attachments','expected_head':manifest['expected_head'],'head_matches':second_head==manifest['expected_head'],'expected_source':manifest['before_source'],'fingerprint_matches':all(fingerprint[key]==manifest['before_source'][key]for key in fingerprint),'source_blob_differences':counts[index],'gui_blob_differences':sum(second_gui[name]!=gui[index].get(name)for name in second_gui)})
 report={'kind':'desktop-baseline-read-only-diagnosis','release':REV,'actual_head':second_head,'actual_source':fingerprint,'cohorts':comparisons,'source_difference_count':len(rows),'source_differences':rows[:1000],'source_differences_truncated':len(rows)>1000,'gui':gui_rows,'deployment_performed':False,'php_bootstrapped':False,'database_read':False}
 output=json.dumps(report,ensure_ascii=True,separators=(',',':'))
 if len(output.encode())>1024*1024:raise RuntimeError('DIAGNOSTIC_OUTPUT_LIMIT')
 print('DESKTOP_BASELINE_DIAGNOSIS_BEGIN');print(output);print('DESKTOP_BASELINE_DIAGNOSIS_END; DEPLOYMENT_PERFORMED=false')
except BaseException:
 print('DESKTOP_READ_ONLY_DIAGNOSIS_STOPPED_WITHOUT_PRIVATE_OUTPUT',file=sys.stderr);sys.exit(1)
PY
