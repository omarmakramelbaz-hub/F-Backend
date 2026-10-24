"""Execute the pinned root handoff with isolated Git and no systemd mutations."""
import copy
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
WRAPPER=ROOT/'deployment/run_go_erp_close_reopen_root.sh'
TEXT=WRAPPER.read_text()
READY='GO_ERP_CLOSE_REOPEN_PUBLISHED_MANUAL_UPDATES_ONLY'
PREPARED='GO_ERP_CLOSE_REOPEN_PREPARED_PUBLICATION_HELD'
RESULT={'format':1,'kind':'go-erp-close-reopen-rollout','ok':True,'published':True,'prepared':True,
    'manual_path_paused':False,'version':'0.4.4-preview.45','candidate_revision':'a'*40,
    'archive':{'available':True,'current_version':'0.4.4-preview.45'},
    'database_changed':False,'credentials_disclosed':False,'automatic_updates_enabled':False}
MOCK=r'''#!/usr/bin/python3
import json,os,pathlib,sys
root=pathlib.Path(os.environ['GO_CLOSE_WRAPPER_TEST']);config=json.loads((root/'config.json').read_text())
name=pathlib.Path(sys.argv[0]).name;args=sys.argv[1:]
with (root/'calls.jsonl').open('a') as f:f.write(json.dumps([name,*args])+'\n')
if name=='id':print(0)
elif name=='hostname':print('srv1836524')
elif name=='git':
    if 'rev-parse' in args:print(args[-1].split('^{')[0])
    elif 'show' in args:
        print('import json,sys\nprint("GO_ERP_CLOSE_REOPEN_WAITING_WORKER seconds=30",flush=True)\nprint(json.dumps('+repr(config['result'])+'))\nprint('+repr(config['marker'])+')\nsys.exit('+str(config.get('exit',0))+')')
'''


class CloseReopenWrapperTests(unittest.TestCase):
    def run_shell(self,mode='--complete',**changes):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);bins=root/'bin';bins.mkdir()
            config={'result':copy.deepcopy(RESULT),'marker':READY,**changes}
            (root/'config.json').write_text(json.dumps(config))
            for name in ('git','id','hostname'):
                path=bins/name;path.write_text(MOCK);path.chmod(0o755)
            source=TEXT.replace('/var/tmp/go-erp-close-reopen.',str(root/'package.'))
            result=subprocess.run(['bash','-c',source,'isolated','b'*40,mode],capture_output=True,text=True,timeout=10,
                env={**os.environ,'PATH':str(bins)+':'+os.environ['PATH'],'GO_CLOSE_WRAPPER_TEST':str(root)})
            calls=[json.loads(line) for line in (root/'calls.jsonl').read_text().splitlines()]
            return result,calls,len(list(root.glob('package.*')))
    def test_shell_and_embedded_stream_and_result_validators_compile(self):
        subprocess.run(['bash','-n',str(WRAPPER)],check=True)
        programs=re.findall(r"-c '\n(.*?)\n'",TEXT,re.S)
        self.assertEqual(len(programs),2)
        for program in programs:compile(program,str(WRAPPER),'exec')
    def test_complete_streams_heartbeat_then_proven_result_without_any_service_controls(self):
        result,calls,retained=self.run_shell()
        self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(retained,0)
        self.assertEqual(result.stdout.count(READY),1)
        self.assertTrue(result.stdout.startswith('GO_ERP_CLOSE_REOPEN_WAITING_WORKER seconds=30\n'))
        self.assertTrue(all(call[0] in ('git','hostname','id') for call in calls))
        fetch=next(call for call in calls if 'fetch' in call)
        self.assertEqual(fetch[-3:],['b'*40,'18e13894c1afcc9f3daa61df2446b02cf806cb7a','2d9e23a5157782882382c797a44ad15c317ae845'])
    def test_apply_reports_a_held_preparation_and_does_not_claim_a_download(self):
        value={**RESULT,'published':False,'manual_path_paused':True,'version':None}
        result,calls,retained=self.run_shell('--apply',result=value,marker=PREPARED)
        self.assertEqual(result.returncode,0,result.stderr);self.assertNotIn(READY,result.stdout)
        self.assertEqual(result.stdout.count(PREPARED),1);self.assertEqual(retained,0)
    def test_failed_child_retains_package_without_printing_a_ready_marker(self):
        result,calls,retained=self.run_shell(result={**RESULT,'ok':False},exit=1,marker='GO_ERP_CLOSE_FAILED')
        self.assertNotEqual(result.returncode,0);self.assertEqual(retained,1)
        self.assertNotIn(READY,result.stdout);self.assertIn('PACKAGE_RETAINED',result.stderr)
    def test_malformed_or_unverified_success_cannot_print_the_final_ready_marker(self):
        for change in ({'automatic_updates_enabled':True},{'archive':{'available':False}},
                {'version':'0.4.4-preview.46'},{'candidate_revision':'wrong'}, {'database_changed':0}):
            with self.subTest(change=change):
                result,calls,retained=self.run_shell(result={**RESULT,**change})
                self.assertNotEqual(result.returncode,0);self.assertEqual(retained,1)
                self.assertNotIn(READY,result.stdout)


if __name__=='__main__':unittest.main()
