#!/usr/bin/env python3
"""Build the bounded release from the inspected owner-fix baseline; no network or deployment."""
import difflib
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]
BASE = '7669ce9fea088d2acb25461227d58ab0c703e751'
helpers = ['deployment/owner_dashboards/' + name for name in ['central_setup.php','tenant_http.php','tenant_console.php']]
changed = subprocess.check_output(['git','diff','--name-only',BASE],cwd=ROOT,text=True).splitlines()
untracked = subprocess.check_output(['git','ls-files','--others','--exclude-standard'],cwd=ROOT,text=True).splitlines()
paths = sorted({p for p in changed+untracked if p.startswith(('app/','routes/','resources/views/admin/','public/dashboard/js/'))} | set(helpers))
items=[]
for relative in paths:
    result=subprocess.run(['git','show',BASE+':'+relative],cwd=ROOT,capture_output=True)
    after=(ROOT/relative).read_text()
    if result.returncode:
        items.append({'path':relative,'kind':'new','content':after});continue
    before=result.stdout.decode().replace('\r\n','\n')
    if before==after:continue
    a,b=before.splitlines(keepends=True),after.splitlines(keepends=True)
    edits=None
    for context in [4,8,16,32,64,128,256]:
        candidate=[]
        for group in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(context):
            start,end=group[0],group[-1]
            old=''.join(a[start[1]:end[2]]);new=''.join(b[start[3]:end[4]])
            if not old or not new or before.count(old)!=1 or after.count(new)!=1:break
            candidate.append({'before':old,'after':new})
        else:
            edits=candidate;break
    if edits is None:raise RuntimeError('No unique bounded fragment for '+relative)
    items.append({'path':relative,'kind':'patch','edits':edits})
destination=Path(__file__).with_name('manifest.json')
destination.write_text(json.dumps({'format':1,'feature':'independent-owner-dashboards-20261101','files':items},ensure_ascii=False,indent=2)+'\n')
print(str(len(items))+' bounded application files prepared')
