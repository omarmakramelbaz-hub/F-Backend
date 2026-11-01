#!/usr/bin/env python3
"""Package only the reviewed hologram path allowlist; no network or deployment."""
import base64
import difflib
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[1]
SPEC=importlib.util.spec_from_file_location('hologram_release',HERE/'apply_root.py')
DEPLOY=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(DEPLOY)


def digest(value):
    return hashlib.sha256(value).hexdigest()


def bounded(before,after):
    old=before.decode().replace('\r\n','\n');new=after.decode().replace('\r\n','\n')
    left,right=old.splitlines(keepends=True),new.splitlines(keepends=True)
    for context in [4,8,16,32,64,128,256]:
        edits=[]
        for group in difflib.SequenceMatcher(None,left,right,autojunk=False).get_grouped_opcodes(context):
            first,last=group[0],group[-1]
            prior=''.join(left[first[1]:last[2]]);updated=''.join(right[first[3]:last[4]])
            if not prior or not updated or old.count(prior)!=1 or new.count(updated)!=1:
                break
            edits.append({'before':prior,'after':updated})
        else:
            return edits
    raise RuntimeError('Cannot isolate unique reviewed source fragments')


def main():
    entries=[]
    for relative in sorted(DEPLOY.ALLOWED):
        path=ROOT/relative
        if not path.is_file():
            continue
        current=path.read_bytes()
        prior=subprocess.run(['git','show',DEPLOY.BASE_REVISION+':'+relative],cwd=ROOT,capture_output=True)
        if prior.returncode==0 and prior.stdout==current:
            continue
        item={'path':relative,'kind':'patch' if prior.returncode==0 else 'new',
              'before_sha256':digest(prior.stdout) if prior.returncode==0 else None,'after_sha256':digest(current)}
        if prior.returncode==0:
            item['edits']=bounded(prior.stdout,current)
        else:
            try:
                item.update(encoding='utf-8',content=current.decode('utf-8'))
            except UnicodeDecodeError:
                item.update(encoding='base64',content=base64.b64encode(current).decode('ascii'))
        entries.append(item)
    if not entries:
        raise RuntimeError('No approved hologram changes exist')
    manifest={'format':1,'feature':DEPLOY.FEATURE,'base_revision':DEPLOY.BASE_REVISION,'files':entries}
    # Verify the assembled fragments against actual immutable baseline bytes and
    # ensure a second deployment is a no-op before pinning this manifest.
    with tempfile.TemporaryDirectory(prefix='go-hologram-manifest-') as temporary:
        project=Path(temporary)
        for entry in entries:
            target=project/entry['path'];target.parent.mkdir(parents=True,exist_ok=True)
            if entry['kind']=='patch':
                target.write_bytes(subprocess.check_output(['git','show',DEPLOY.BASE_REVISION+':'+entry['path']],cwd=ROOT))
        for change in DEPLOY.plan(project,manifest):
            if change['after']!=(ROOT/change['path']).read_bytes():
                raise RuntimeError('Manifest differs from reviewed source: '+change['path'])
            target=project/change['path'];target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(change['after'])
        if any(change['before']!=change['after'] for change in DEPLOY.plan(project,manifest)):
            raise RuntimeError('Manifest is not idempotent')
    destination=HERE/'manifest.json'
    destination.write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
    # This launcher is pinned by the operator's entry command. Its two payload
    # hashes pin every application change; no moving branch is fetched on server.
    launcher=ROOT/'deployment/run_hologram_opening_root.sh'
    content=launcher.read_text()
    for label,source in [('APPLY_SHA256',HERE/'apply_root.py'),('MANIFEST_SHA256',destination)]:
        import re
        content,count=re.subn(r'^'+label+r'=[^\n]+',label+'='+digest(source.read_bytes()),content,flags=re.MULTILINE)
        if count!=1:
            raise RuntimeError('Pinned launcher hash slot missing: '+label)
    launcher.write_text(content)
    print(json.dumps({'status':'GO_HOLOGRAM_MANIFEST_BUILT','files':[entry['path'] for entry in entries],
                      'manifest_sha256':digest(destination.read_bytes()),'runner_sha256':digest(launcher.read_bytes())}))


if __name__=='__main__':
    main()
