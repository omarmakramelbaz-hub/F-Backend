#!/usr/bin/env python3
"""Build deployment pins from a measured server map and reviewed local source."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re

ROOTS=('app','bootstrap','config','database','desktop','resources','routes','public/dashboard/js',
       'public/dashboard/css','public/dashboard/vendor/desktop-external')
SECRET=re.compile(r'(?:^|/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$',re.I)
EXTENSION=re.compile(r'\.(?:php|js|css|json|html|scss|vue)$',re.I)
SAFE=re.compile(r'[A-Za-z0-9_.@/-]+\Z')
MIGRATIONS=(
    'database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php',
    'database/migrations/2026_10_08_160000_create_desktop_dashboard_local_state.php',
    'database/migrations/2026_10_08_210000_create_desktop_dashboard_archived_commands.php',
    'database/migrations/2026_10_08_210000_create_desktop_dashboard_remote_attempts.php',
    'database/migrations/2026_10_08_220000_create_desktop_dashboard_media_manifest.php',
    'database/migrations/2026_10_08_230000_create_desktop_dashboard_source_manifest.php',
)

def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def selected(name):
    return ((name=='artisan'or any(name.startswith(prefix+'/')for prefix in ROOTS))
        and not name.startswith('bootstrap/cache/')and not SECRET.search(name)
        and(name=='artisan'or bool(EXTENSION.search(name))))
def digest(files):
    state=hashlib.sha256(b'desktop-dashboard-source:1\n')
    for name,value in sorted(files.items()):state.update(name.encode()+b'\0'+value['sha256'].encode()+b'\n')
    return {'format':1,'files':len(files),'sha256':state.hexdigest(),'framework':'8.83.29'}
def file_record(path):
    if path.is_symlink()or not path.is_file():raise ValueError('Reviewed target is not a regular file')
    data=path.read_bytes()
    if len(data)>8*1024*1024:raise ValueError('Reviewed target file exceeds source limit')
    return {'blob':blob(data),'sha256':hashlib.sha256(data.replace(b'\r\n',b'\n')).hexdigest()}
def scan(root):
    files={};cases=set();total=0
    def walk_error(_):raise ValueError('Reviewed source is unreadable')
    for prefix in ROOTS:
        folder=root/prefix
        for parent in(folder,*folder.parents):
            if parent==root.parent:break
            if parent.is_symlink():raise ValueError('Reviewed source contains a linked ancestor')
        if not folder.exists():continue
        if not folder.is_dir():raise ValueError('Reviewed source root is not a directory')
        for parent,directories,names in os.walk(folder,followlinks=False,onerror=walk_error):
            for name in directories+names:
                path=Path(parent)/name;relative=path.relative_to(root).as_posix()
                if not SECRET.search(relative)and path.is_symlink():raise ValueError('Reviewed source contains a link')
            if Path(parent)==root/'bootstrap':directories[:]=[name for name in directories if name!='cache']
            for name in names:
                path=Path(parent)/name;relative=path.relative_to(root).as_posix()
                if not selected(relative):continue
                if not SAFE.fullmatch(relative)or relative.lower()in cases:raise ValueError('Reviewed source path is ambiguous')
                total+=path.stat().st_size
                if total>64*1024*1024 or len(files)>=20000:raise ValueError('Reviewed source exceeds fingerprint limits')
                files[relative]=file_record(path);cases.add(relative.lower())
    if(root/'artisan').exists()or(root/'artisan').is_symlink():
        record=file_record(root/'artisan');total+=(root/'artisan').stat().st_size
        if total>64*1024*1024 or len(files)>=20000:raise ValueError('Reviewed source exceeds fingerprint limits')
        files['artisan']=record
    if not files:raise ValueError('Reviewed source is empty')
    return files
def publication_order(row):
    name=row['path']
    if row['phase']!=2:return(row['phase'],0,name)
    # Preserve the previously reviewed dependency order. Bootstrap configuration
    # is early; the installer still handles only its exact request bridge first.
    groups=('config/','bootstrap/','app/Interfaces/','app/Models/','app/Services/',
            'app/Repositories/','app/Http/Requests/','public/','app/Http/Middleware/',
            'app/Http/Controllers/','app/Providers/','routes/','resources/views/')
    rank=next((index for index,prefix in enumerate(groups)if name.startswith(prefix)),10)
    return(2,rank,name)
def generate(root,before,diagnosis):
    cases=set()
    for name,value in before.items():
        if(not selected(name)or not SAFE.fullmatch(name)or '..'in Path(name).parts or name.lower()in cases or set(value)!={'blob','sha256'}
            or not re.fullmatch('[a-f0-9]{40}',value['blob'])or not re.fullmatch('[a-f0-9]{64}',value['sha256'])):
            raise ValueError('Invalid measured source record')
        cases.add(name.lower())
    if digest(before)!=diagnosis['actual_source']:raise ValueError('Complete measured source map does not match observed fingerprint')
    if not re.fullmatch('[a-f0-9]{40}',diagnosis['actual_head']):raise ValueError('Invalid measured source HEAD')
    differences={row['path']:row['actual']for row in diagnosis['source_differences']}
    if len(differences)!=len(diagnosis['source_differences']):raise ValueError('Duplicate observed source difference')
    for name,pin in differences.items():
        if name not in before or before[name]['blob']!=pin:raise ValueError('Measured raw source blob disagrees with observed diagnosis')
    after=scan(root)
    if scan(root)!=after:raise ValueError('Reviewed source changed during manifest generation')
    if set(before)-set(after):raise ValueError('Measured source deletions need a separate reviewed release')
    gui={row['path']:row['actual']for row in diagnosis['gui']}
    if len(gui)!=len(diagnosis['gui']):raise ValueError('Duplicate measured GUI path')
    for name in gui:
        if(selected(name)or not name.startswith('public/')or not SAFE.fullmatch(name)or SECRET.search(name)
            or gui[name]is not None and not re.fullmatch('[a-f0-9]{40}',gui[name])):raise ValueError('Invalid measured GUI record')
    targets={name:file_record(root/name)['blob']for name in gui}
    rows=[];protected=[]
    for name in sorted(set(after)|set(gui)):
        old=before[name]['blob']if name in before else gui.get(name)
        target=after[name]['blob']if name in after else targets[name]
        if old==target:
            if name in gui:protected.append({'path':name,'blob':old})
            continue
        phase=0 if name in MIGRATIONS else 1 if old is None else 2
        if name.startswith(('routes/','resources/views/','app/Providers/'))or name in('app/Http/Kernel.php','app/Exceptions/Handler.php'):phase=2
        if name.startswith('database/migrations/')and name not in MIGRATIONS:raise ValueError('Unreviewed migration differs from measured server')
        rows.append({'path':name,'before_blob':old,'after_blob':target,'phase':phase})
    if not set(MIGRATIONS).issubset({row['path']for row in rows}):raise ValueError('Expected six additive desktop migrations are not new measured-source changes')
    manifest={'version':1,'expected_head':diagnosis['actual_head'],'before_source':digest(before),'after_source':digest(after),
              'sources':sorted(rows,key=publication_order),'protected':protected,'migrations':list(MIGRATIONS)}
    fixture={'version':1,'expected_head':diagnosis['actual_head'],'source':digest(before),'files':before,'gui':gui,
             'measured_source_differences':{row['path']:row['actual']for row in diagnosis['source_differences']}}
    if 'provenance'in diagnosis:fixture['provenance']=diagnosis['provenance']
    return manifest,fixture
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo',required=True);parser.add_argument('--source-map',required=True);parser.add_argument('--diagnosis',required=True)
    args=parser.parse_args();root=Path(args.repo).resolve()
    before=json.loads(Path(args.source_map).read_text());diagnosis=json.loads(Path(args.diagnosis).read_text())
    manifest,fixture=generate(root,before,diagnosis)
    (root/'deployment/desktop_dashboard_server_manifest.json').write_text(json.dumps(manifest,ensure_ascii=True,indent=2)+'\n')
    destination=root/'tests/fixtures/desktop-actual-server-baseline.json';destination.parent.mkdir(parents=True,exist_ok=True)
    destination.write_text(json.dumps(fixture,ensure_ascii=True,sort_keys=True,indent=2)+'\n')
    print('VERIFIED_MEASURED_BASELINE',manifest['before_source']['sha256'])
    print('VERIFIED_REVIEWED_TARGET',manifest['after_source']['sha256'])
    print('SOURCE_CHANGES',len(manifest['sources']),'PROTECTED_GUI',len(manifest['protected']))

if __name__=='__main__':main()
