#!/usr/bin/env python3
"""Prepare one exact derived baseline; this is not a new production measurement."""
import argparse
import copy
import json
from pathlib import Path
import re

import refresh_desktop_dashboard_server_manifest as release

BASE_SHA256='2ef5e2b7cae03101046094627e9f19d94165d75c7981cbff097de14eb13fab9f'
PATCH_COMMIT='18748810da33f42fed264540200b25328efe7688'
PATCH_BLOB='95e1e5d287f6b790caa061443eea43a78a5d588a'
PATHS={'public/dashboard/js/branch-expenses.js','public/dashboard/css/branch-expenses.css',
       'resources/lang/ar/expenses.php','resources/lang/en/expenses.php'}

def derive(root,base,known):
    if (known.get('format')!=1 or known.get('kind')!='derived-exact-patch-cohort'
        or known.get('base_source',{}).get('sha256')!=BASE_SHA256
        or base['source']!=known['base_source'] or release.digest(base['files'])!=base['source']
        or base['expected_head']!=known.get('base_head')
        or known.get('patch')!={'repository':'omarmakramelbaz-hub/F-Backend','commit':PATCH_COMMIT,
            'helper':'deployment/apply_expense_reject_button.py','helper_blob':PATCH_BLOB}
        or set(known.get('changes',{}))!=PATHS):
        raise ValueError('The measured base or the exact approved patch provenance differs')
    files=copy.deepcopy(base['files']);differences=dict(base['measured_source_differences'])
    for name,row in known['changes'].items():
        if(set(row)!={'before_blob','after_blob','after_sha256'} or files[name]['blob']!=row['before_blob']
           or not re.fullmatch('[a-f0-9]{40}',row['after_blob'])
           or not re.fullmatch('[a-f0-9]{64}',row['after_sha256']) or row['before_blob']==row['after_blob']):
            raise ValueError('The exact approved source transformation differs')
        files[name]={'blob':row['after_blob'],'sha256':row['after_sha256']};differences[name]=row['after_blob']
    provenance={'kind':'derived-exact-patch-cohort','base_source':base['source'],'patch':known['patch']}
    diagnosis={'actual_head':base['expected_head'],'actual_source':release.digest(files),
        'source_differences':[{'path':name,'actual':value}for name,value in sorted(differences.items())],
        'gui':[{'path':name,'actual':value}for name,value in base['gui'].items()],'provenance':provenance}
    primary,_=release.generate(root,base['files'],{'actual_head':base['expected_head'],'actual_source':base['source'],
        'source_differences':[{'path':name,'actual':value}for name,value in base['measured_source_differences'].items()],
        'gui':diagnosis['gui']})
    alternative,fixture=release.generate(root,files,diagnosis)
    # Already-updated selected source files still need explicit protection, so
    # the two complete cohorts select exactly the same final publication roster.
    present={row['path']for row in alternative['sources']+alternative['protected']}
    for row in primary['sources']:
        if row['path']not in present:
            if files.get(row['path'],{}).get('blob')!=row['after_blob']:
                raise ValueError('The approved cohorts disagree on the final target')
            alternative['protected'].append({'path':row['path'],'blob':row['after_blob']})
    alternative['protected'].sort(key=lambda row:row['path'])
    target=lambda value:{**{r['path']:r['after_blob']for r in value['sources']},
                         **{r['path']:r['blob']for r in value['protected']}}
    if primary['after_source']!=alternative['after_source']or target(primary)!=target(alternative):
        raise ValueError('The approved cohorts do not converge on exactly one target')
    fixture['derived_source_differences']=fixture.pop('measured_source_differences')
    return alternative,fixture

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--repo',required=True)
    args=parser.parse_args();root=Path(args.repo).resolve()
    base=json.loads((root/'tests/fixtures/desktop-actual-server-baseline.json').read_text())
    known=json.loads((root/'deployment/desktop_dashboard_known_expense_rejection.json').read_text())
    manifest,fixture=derive(root,base,known)
    (root/'deployment/desktop_dashboard_server_manifest_expense_rejection.json').write_text(json.dumps(manifest,indent=2)+'\n')
    (root/'tests/fixtures/desktop-derived-expense-rejection-baseline.json').write_text(json.dumps(fixture,sort_keys=True,indent=2)+'\n')
    print('VERIFIED_DERIVED_BASELINE',manifest['before_source']['sha256'])
    print('VERIFIED_REVIEWED_TARGET',manifest['after_source']['sha256'])

if __name__=='__main__':main()
