"""Run apply/complete shell modes with isolated exports and real result validators."""
import copy
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
WRAPPER=ROOT/'deployment/run_go_erp_button_flow_root.sh';TEXT=WRAPPER.read_text()
BUTTON_MARKER='GO_ERP_BUTTON_FLOW_READY_DOWNLOADS_CHECKED'
NATIVE_MARKER='GO_ERP_CLOSE_REOPEN_PUBLISHED_MANUAL_UPDATES_ONLY'
COMBINED='GO_ERP_BUTTON_FLOW_AND_CLOSE_REOPEN_PUBLISHED'
BUTTON={'format':1,'kind':'go-erp-button-flow-installed','ok':True,'source_changed':True,
    'verified42':{'version':'0.4.4-preview.42','verified':True,
        'source_revision':'26c6f8b6d80ab8da05b88a00789483a51a4c9a2a',
        'installer_sha256':'f918e811ca501612d75b196de56cfe18fad9fac547ad79dc791b38928aac5b9f',
        'installer_bytes':415949308,'in_download_slots':True,'binary_repaired':False,'manifest_repaired':False},
    'database_changed':False,'native_state_changed':False,'timer_changed':False,'automatic_updates_enabled':False}
NATIVE={'format':1,'kind':'go-erp-close-reopen-rollout','ok':True,'published':True,'prepared':True,
    'manual_path_paused':False,'version':'0.4.4-preview.45','candidate_revision':'a'*40,
    'archive':{'available':True,'current_version':'0.4.4-preview.45'},'database_changed':False,
    'credentials_disclosed':False,'automatic_updates_enabled':False}
MOCK=r'''#!/usr/bin/python3
import json,os,pathlib,sys
root=pathlib.Path(os.environ['GO_BUTTON_TEST']);cfg=json.loads((root/'config.json').read_text())
name=pathlib.Path(sys.argv[0]).name;args=sys.argv[1:]
with (root/'calls.jsonl').open('a') as f:f.write(json.dumps([name,*args])+'\n')
if name=='id':print(0)
elif name=='hostname':print('srv1836524')
elif name=='sha256sum':
    incoming=sys.stdin.read()
    if cfg.get('sha_fail') or not incoming.startswith('11982bda14275d1f5fc7cd23b3a7f523a97e7e5651d7c9c537bd326a686656cb  '):sys.exit(1)
elif name=='git':
    if 'rev-parse' in args:print(args[-1].split('^{')[0])
    elif 'show' in args:
        phase='native' if args[-1].endswith('upgrade_go_erp_close_reopen_root.py') else 'buttons'
        result=cfg[phase];code=cfg.get(phase+'_exit',0);marker=cfg[phase+'_marker']
        source='import json,os,pathlib,sys\nr=pathlib.Path(os.environ["GO_BUTTON_TEST"])\n'
        source+='with (r/"calls.jsonl").open("a") as f:f.write(json.dumps(["phase",'+repr(phase)+'])+"\\n")\n'
        if phase=='native':source+='print("GO_ERP_CLOSE_REOPEN_WAITING_WORKER seconds=30",flush=True)\n'
        source+='print(json.dumps('+repr(result)+'))\n'
        if not code:source+='print('+repr(marker)+')\n'
        source+='sys.exit('+str(code)+')\n';print(source)
'''


class ButtonFlowWrapperTests(unittest.TestCase):
    def shell(self,mode='--apply',**changes):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);bins=root/'bin';bins.mkdir()
            cfg={'buttons':copy.deepcopy(BUTTON),'native':copy.deepcopy(NATIVE),
                'buttons_marker':BUTTON_MARKER,'native_marker':NATIVE_MARKER,**changes}
            (root/'config.json').write_text(json.dumps(cfg))
            for name in ('git','id','hostname','sha256sum'):
                path=bins/name;path.write_text(MOCK);path.chmod(0o755)
            source=TEXT.replace('/var/tmp/go-erp-button-flow.',str(root/'package.'))
            result=subprocess.run(['bash','-c',source,'isolated','b'*40,mode],capture_output=True,text=True,timeout=10,
                env={**os.environ,'PATH':str(bins)+':'+os.environ['PATH'],'GO_BUTTON_TEST':str(root)})
            calls=[json.loads(line) for line in (root/'calls.jsonl').read_text().splitlines()]
            return result,calls,len(list(root.glob('package.*')))
    def test_shell_and_three_embedded_validators_compile(self):
        subprocess.run(['bash','-n',str(WRAPPER)],check=True)
        programs=re.findall(r"-c '\n(.*?)\n'",TEXT,re.S)
        self.assertEqual(len(programs),3)
        for program in programs:compile(program,str(WRAPPER),'exec')
    def test_apply_verifies42_and_does_not_run_or_allocate_native_build(self):
        result,calls,retained=self.shell()
        self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(retained,0)
        self.assertEqual([call for call in calls if call[0]=='phase'],[['phase','buttons']])
        self.assertIn(BUTTON_MARKER,result.stdout);self.assertNotIn(COMBINED,result.stdout)
    def test_complete_applies_buttons_first_then_runs_same_release_native_helper(self):
        result,calls,retained=self.shell('--complete')
        self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(retained,0)
        self.assertEqual([call for call in calls if call[0]=='phase'],[['phase','buttons'],['phase','native']])
        self.assertEqual(result.stdout.count(COMBINED),1);self.assertEqual(result.stdout.count(NATIVE_MARKER),1)
        self.assertIn('WAITING_WORKER seconds=30',result.stdout)
    def test_unreferenced42_is_honest_and_does_not_require_old_bytes(self):
        proof={'version':'0.4.4-preview.42','verified':False,'in_download_slots':False,
            'binary_repaired':False,'manifest_repaired':False,'source_revision':None,
            'installer_sha256':None,'installer_bytes':None,'source_kind':'not-referenced'}
        result,calls,retained=self.shell(buttons={**BUTTON,'verified42':proof})
        self.assertEqual(result.returncode,0,result.stderr);self.assertIn('"verified": false',result.stdout)
    def test_bad42_identity_stops_before_native_phase_or_ready_marker(self):
        proof={**BUTTON['verified42'],'installer_sha256':'0'*64}
        result,calls,retained=self.shell('--complete',buttons={**BUTTON,'verified42':proof})
        self.assertNotEqual(result.returncode,0);self.assertEqual(retained,1)
        self.assertNotIn(BUTTON_MARKER,result.stdout);self.assertNotIn(COMBINED,result.stdout)
        self.assertEqual([call for call in calls if call[0]=='phase'],[['phase','buttons']])
    def test_failed_native_publication_keeps_applied_buttons_without_full_success_claim(self):
        result,calls,retained=self.shell('--complete',native={**NATIVE,'ok':False,'published':False},native_exit=1)
        self.assertNotEqual(result.returncode,0);self.assertEqual(retained,1)
        self.assertIn(BUTTON_MARKER,result.stdout);self.assertNotIn(COMBINED,result.stdout);self.assertNotIn(NATIVE_MARKER,result.stdout)
    def test_wrong_native_hash_or_unverified_success_cannot_claim_full_ready(self):
        for changes in ({'sha_fail':True},{'native':{**NATIVE,'archive':{'available':False}}}):
            with self.subTest(changes=changes):
                result,calls,retained=self.shell('--complete',**changes)
                self.assertNotEqual(result.returncode,0);self.assertEqual(retained,1)
                self.assertNotIn(COMBINED,result.stdout);self.assertNotIn(NATIVE_MARKER,result.stdout)
    def test_actual_native_coordinator_hash_matches_the_pinned_complete_phase(self):
        import hashlib
        expected=re.search(r'^go_native_sha=([a-f0-9]{64})$',TEXT,re.M)[1]
        self.assertEqual(hashlib.sha256((ROOT/'deployment/upgrade_go_erp_close_reopen_root.py').read_bytes()).hexdigest(),expected)


if __name__=='__main__':unittest.main()
