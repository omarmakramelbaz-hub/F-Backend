#!/usr/bin/env python3
"""Replay the complete pinned payload against disposable immutable-baseline files."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[1]
SPEC=importlib.util.spec_from_file_location('hologram_release',HERE/'apply_root.py')
DEPLOY=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(DEPLOY)


def verify():
    manifest=json.loads((HERE/'manifest.json').read_text())
    if {entry['path'] for entry in manifest['files']}!=DEPLOY.ALLOWED:
        raise RuntimeError('Manifest does not match the exact release allowlist')
    marker=b'\n// Independent production adjustment retained by bounded activation.\n'
    marked='app/Http/Controllers/Dashboard/HomeController.php'
    for rollback in (False,True):
        with tempfile.TemporaryDirectory(prefix='go-hologram-full-manifest-') as temporary:
            stage=Path(temporary);project=stage/'project';project.mkdir();originals={}
            for entry in manifest['files']:
                target=project/entry['path'];target.parent.mkdir(parents=True,exist_ok=True)
                if entry['kind']=='patch':
                    content=subprocess.check_output(['git','show',DEPLOY.BASE_REVISION+':'+entry['path']],cwd=ROOT)
                    if entry['path']==marked:content+=marker
                    target.write_bytes(content);originals[entry['path']]=content
            changes=DEPLOY.plan(project,manifest)
            def check_installed():
                for change in changes:
                    if (project/change['path']).read_bytes()!=change['after']:
                        raise RuntimeError('Installed candidate bytes differ: '+change['path'])
                if rollback:raise RuntimeError('GO_HOLOGRAM_FIXTURE_ROLLBACK')
            try:
                DEPLOY.apply_plan(project,changes,stage/'backup',(os.getuid(),os.getgid()),check_installed)
            except RuntimeError as error:
                if not rollback or str(error)!='GO_HOLOGRAM_FIXTURE_ROLLBACK':raise
            if rollback:
                for entry in manifest['files']:
                    target=project/entry['path']
                    if entry['kind']=='new':
                        if target.exists():raise RuntimeError('Rollback retained a new file: '+entry['path'])
                    elif target.read_bytes()!=originals[entry['path']]:
                        raise RuntimeError('Rollback changed original source: '+entry['path'])
            else:
                for change in DEPLOY.plan(project,manifest):
                    if change['before']!=change['after']:
                        raise RuntimeError('Repeat activation is not a no-op: '+change['path'])
                for entry in manifest['files']:
                    expected=(ROOT/entry['path']).read_bytes()
                    if entry['path']==marked:expected+=marker
                    if (project/entry['path']).read_bytes()!=expected:
                        raise RuntimeError('Payload is stale or changed unrelated source: '+entry['path'])
    print(json.dumps({'status':'GO_HOLOGRAM_MANIFEST_REPLAY_VERIFIED','feature':DEPLOY.FEATURE,
                      'base_revision':DEPLOY.BASE_REVISION,'files':len(manifest['files']),
                      'apply':True,'repeat':True,'rollback':True,'unrelated_source_retained':True}))


if __name__=='__main__':
    verify()
