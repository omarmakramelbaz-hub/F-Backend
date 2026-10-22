#!/usr/bin/env python3
"""Preserve the original candidate proof while admitting its reviewed capacity seed."""
import argparse
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile

CORE = 'd27b2942848714c455f55c9bf2c3dc52f7b89c25'
REMOTE = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
POLICY_FILES = frozenset((
    '.github/workflows/go-erp-update-tests.yml',
    'deployment/verify_go_erp_media_capacity_seed.py',
    'deployment/apply_go_erp_media_capacity_root.py',
    'tests/test_go_erp_media_capacity_seed.py',
    'tests/test_go_erp_media_capacity_upgrade.py',
    'tests/test_go_erp_live_updates.py',
    'docs/go-erp-media-capacity-seed-20261022.md',
))
REV = re.compile('[a-f0-9]{40}\\Z')
ENV = {'PATH':'/usr/bin:/bin','LANG':'C.UTF-8','LC_ALL':'C.UTF-8',
       'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_CONFIG_SYSTEM':'/dev/null',
       'GIT_TERMINAL_PROMPT':'0','GIT_NO_REPLACE_OBJECTS':'1','GIT_OPTIONAL_LOCKS':'0'}

def need(value, reason):
    if not value: raise RuntimeError('GO_ERP_CAPACITY_SEED_'+reason)

def git(root, *args, maximum=16*1024**2):
    result=subprocess.run(['git','-c','credential.helper=','-c','core.hooksPath=/dev/null',
        '-c','core.autocrlf=false','-c','core.fsmonitor=false','-C',str(root),*args],
        env=ENV,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=180,check=False)
    need(result.returncode==0 and len(result.stdout)<=maximum,'GIT_FAILED')
    return result.stdout

def validate_seed(root, revision, core=CORE):
    need(bool(REV.fullmatch(revision)) and bool(REV.fullmatch(core)),'REVISION')
    need(revision!=core,'POLICY_SEED_REQUIRED')
    git(root,'merge-base','--is-ancestor',core,revision)
    changes=git(root,'diff','--raw','--no-abbrev','--no-renames',core,revision,'--').decode().splitlines()
    seen=set()
    for line in changes:
        match=re.fullmatch(r':(000000|100644) 100644 [a-f0-9]{40} [a-f0-9]{40} [AM]\t(.+)',line)
        need(match is not None and match[2] in POLICY_FILES and match[2] not in seen,'UNREVIEWED_SEED_DELTA')
        seen.add(match[2])
    need({'deployment/verify_go_erp_media_capacity_seed.py','deployment/apply_go_erp_media_capacity_root.py',
          '.github/workflows/go-erp-update-tests.yml'}<=seen,'POLICY_INCOMPLETE')
    # All application, native, dependency and build bytes must equal the fixed core.
    return {'core':core,'seed':revision,'policy_files':sorted(seen)}

def module(file):
    spec=importlib.util.spec_from_file_location('original_candidate_verifier',file)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value

def verify(root, historical):
    root,historical=Path(root).absolute(),Path(historical).absolute()
    original=module(Path(__file__).with_name('verify_go_erp_current_candidate_ci.py'))
    candidate=json.loads((root/'deployment/go_erp_live_source_candidate.json').read_bytes())
    base=candidate.get('baseRevision','')
    if base==original.BASE_REVISION:
        return original.validate_checkout(root,historical)
    need(bool(REV.fullmatch(base)),'CANDIDATE_BASE')
    git(root,'fetch','--quiet','--depth=16','--no-tags','--no-recurse-submodules',REMOTE,base)
    # The bounded lineage must contain the exact capacity core object.
    proof=validate_seed(root,base,core=CORE)
    with tempfile.TemporaryDirectory(prefix='go-erp-capacity-ci-') as temporary:
        seed=Path(temporary)/'seed'
        git(root,'worktree','add','--quiet','--detach',str(seed),base)
        try: result=original.validate_checkout(root,seed,expected_base=base)
        finally:
            git(root,'worktree','remove','--force',str(seed))
    result['policy']='media-capacity-seed-v1';result['reviewed_capacity_core']=proof['core']
    return result

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root',required=True);parser.add_argument('--historical-root',required=True)
    options=parser.parse_args()
    try: print(json.dumps(verify(options.root,options.historical_root),sort_keys=True))
    except Exception as error:
        print(json.dumps({'ok':False,'code':str(error) if str(error).startswith('GO_ERP_') else 'GO_ERP_CAPACITY_CANDIDATE_FAILED',
                          'application_changed':False,'database_read':False}));raise SystemExit(1)
