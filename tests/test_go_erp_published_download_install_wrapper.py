"""Run the root installation handoff with isolated tools and real validators."""
import copy
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
WRAPPER=ROOT/'deployment/run_go_erp_published_downloads_root.sh'
TEXT=WRAPPER.read_text()
PYTHON=re.findall(r"-c '\n(.*?)\n'",TEXT,re.S)
MARKER='GO_ERP_PUBLISHED_DOWNLOADS_READY_MANUAL_UPDATES_ONLY'
READY={'format':1,'kind':'go-erp-published-downloads-installed','ok':True,
    'automatic_updates_enabled':False,'database_changed':False,'credentials_disclosed':False,
    'source_changed':True,'runtime':'/opt/go-erp-manual-updates/releases/'+'a'*64,
    'path_active':True,'archive':{'version':'0.4.4-preview.42'}}
MOCK=r'''#!/usr/bin/python3
import json,os,pathlib,sys
root=pathlib.Path(os.environ['MANUAL_INSTALL_TEST']);cfg=json.loads((root/'config.json').read_text())
name=pathlib.Path(sys.argv[0]).name;args=sys.argv[1:]
with (root/'events.jsonl').open('a') as f:f.write(json.dumps([name,*args])+'\n')
if name=='hostname':print('srv1836524')
elif name=='id':print('0')
elif name=='sleep':pass
elif name=='git':
    if 'rev-parse' in args:print(args[-1].split('^{')[0])
    elif 'show' in args:
        print('import json,sys\nprint(json.dumps('+repr(cfg['result'])+'))\nprint('+repr(cfg.get('marker','GO_ERP_PUBLISHED_DOWNLOADS_READY_MANUAL_UPDATES_ONLY'))+')\nsys.exit('+str(cfg.get('exit',0))+')')
elif name=='systemctl':
    if args[0]=='show':print('inactive' if args[1].endswith('.timer') else cfg.get('service','inactive'))
'''


class PublishedDownloadInstallWrapperTests(unittest.TestCase):
    def shell(self,**changes):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);bins=root/'bin';bins.mkdir()
            (root/'config.json').write_text(json.dumps({'result':copy.deepcopy(READY),**changes}))
            for command in ('git','systemctl','hostname','id','sleep'):
                path=bins/command;path.write_text(MOCK);path.chmod(0o755)
            text=TEXT.replace('/var/tmp/go-erp-published-downloads.',str(root/'package.'))
            result=subprocess.run(['bash','-c',text,'isolated','b'*40,'--apply'],
                env={**os.environ,'PATH':str(bins)+':'+os.environ['PATH'],'MANUAL_INSTALL_TEST':str(root)},
                capture_output=True,text=True,timeout=10)
            events=[json.loads(x) for x in (root/'events.jsonl').read_text().splitlines()]
            return result,events,len(list(root.glob('package.*')))

    def validate(self,value,marker=MARKER):
        with tempfile.TemporaryDirectory() as directory:
            p=Path(directory)/'result.txt';p.write_text(json.dumps(value)+'\n'+marker+'\n')
            return subprocess.run(['/usr/bin/python3','-I','-B','-c',PYTHON[0],str(p)],capture_output=True).returncode

    def test_shell_and_embedded_validator_compile(self):
        subprocess.run(['bash','-n',str(WRAPPER)],check=True)
        self.assertEqual(len(PYTHON),1);compile(PYTHON[0],str(WRAPPER),'exec')

    def test_success_wrapper_has_no_service_timer_or_native_controls(self):
        result,events,retained=self.shell()
        self.assertEqual(result.returncode,0,result.stderr)
        changes=[e[1:] for e in events if e[0]=='systemctl' and e[1]!='show']
        self.assertEqual(changes,[])
        self.assertEqual(retained,0);self.assertIn(MARKER,result.stdout)

    def test_failed_installation_keeps_private_package_and_cannot_report_ready(self):
        result,events,retained=self.shell(exit=1,result={**READY,'ok':False},marker='GO_ERP_INSTALL_FAILED')
        self.assertNotEqual(result.returncode,0);self.assertEqual(retained,1)
        self.assertNotIn(MARKER,result.stdout);self.assertIn('PACKAGE_RETAINED',result.stderr)

    def test_wrong_result_or_marker_cannot_report_success(self):
        for changes in ({'result':{**READY,'automatic_updates_enabled':True}},{'marker':'WRONG'},
                {'result':{**READY,'runtime':'/tmp/other'}},{'result':{**READY,'path_active':False}}):
            with self.subTest(changes=changes):
                result,events,retained=self.shell(**changes)
                self.assertNotEqual(result.returncode,0);self.assertEqual(retained,1)
                self.assertNotIn(MARKER,result.stdout)

    def test_protocol_requires_root_runtime_and_false_safety_flags(self):
        self.assertEqual(self.validate(READY),0)
        for key,bad in [('format',True),('ok',1),('kind','other'),('source_changed',1),
                ('automatic_updates_enabled',0),('database_changed',0),('credentials_disclosed',None),
                ('runtime','/opt/go-erp-manual-updates/releases/../../elsewhere')]:
            with self.subTest(key=key):self.assertNotEqual(self.validate({**READY,key:bad}),0)


if __name__=='__main__':unittest.main()
