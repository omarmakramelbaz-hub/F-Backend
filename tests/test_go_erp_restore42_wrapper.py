"""Exercise the restoration shell with isolated tools and no server operations."""
import copy
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
WRAPPER = ROOT / 'deployment/run_go_erp_restore42_root.sh'
TEXT = WRAPPER.read_text()
PYTHON = re.findall(r"-c '\n(.*?)\n'", TEXT, re.S)
RESTORED = {'format': 1, 'kind': 'go-erp-preview42-restoration', 'ok': True,
    'version': '0.4.4-preview.42', 'published': True,
    'application_contents_changed': False, 'database_changed': False, 'credentials_disclosed': False}
WEB = {'kind': 'go-erp-download-diagnostic', 'mode': 'read_only',
    'status': {'state': 'ready', 'version': '0.4.4-preview.42'}, 'source_matches': True,
    'gui': {'matches': True}, 'application_writes': False, 'database_accessed': False,
    'credentials_disclosed': False}
MOCK = r'''#!/usr/bin/python3
import json,os,pathlib,sys
root=pathlib.Path(os.environ['RESTORE42_TEST_ROOT']);cfg=json.loads((root/'config.json').read_text())
name=pathlib.Path(sys.argv[0]).name;args=sys.argv[1:]
with (root/'events.jsonl').open('a') as f:f.write(json.dumps([name,*args])+'\n')
if name=='hostname':print('srv1836524')
elif name=='id':print('0')
elif name=='sleep':pass
elif name=='git':
    if 'rev-parse' in args:print(args[-1].split('^{')[0])
    elif 'show' in args:
        filename=args[-1].split(':',1)[1]
        if filename.endswith('/restore_go_erp_preview42_root.py'):
            print('import json,sys\nprint(json.dumps('+repr(cfg['restored'])+'))\nsys.exit('+str(cfg.get('helper_exit',0))+')')
        elif filename.endswith('/diagnose_go_erp_download.php'):print('<?php /* isolated unexecuted web probe */')
        else:print('# isolated unused pinned dependency')
elif name=='systemctl':
    if args[0]=='stop':sys.exit(cfg.get('stop_exit',0))
    if args[0]=='show':
        if args[1]=='go-erp-live-updates.timer':print('inactive')
        else:print(cfg.get('service_state','inactive'))
elif name=='runuser':
    print(cfg.get('web_raw','GO_ERP_DOWNLOAD_DIAGNOSTIC '+json.dumps(cfg['web'])))
    sys.exit(cfg.get('web_exit',0))
'''


class Restore42WrapperTests(unittest.TestCase):
    def validate(self, index, value):
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / 'result.txt'
            path.write_text(('GO_ERP_DOWNLOAD_DIAGNOSTIC ' if index == 1 else '') + json.dumps(value))
            return subprocess.run(['/usr/bin/python3', '-I', '-B', '-c', PYTHON[index], str(path)], capture_output=True).returncode

    def shell(self, **changes):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name);work=root/'work';work.mkdir(mode=0o700)
            tools=root/'bin';tools.mkdir()
            config={'restored':copy.deepcopy(RESTORED),'web':copy.deepcopy(WEB),**changes}
            (root/'config.json').write_text(json.dumps(config))
            for command in ('git','systemctl','runuser','hostname','id','sleep'):
                path=tools/command;path.write_text(MOCK);path.chmod(0o755)
            text=TEXT.replace('go_work=/var/lib/go-erp-live-updates','go_work='+str(work))
            text=text.replace('/var/tmp/go-erp-download-check.',str(root/'web-probe.'))
            result=subprocess.run(['bash','-c',text,'isolated-wrapper','a'*40,'--complete'],
                env={**os.environ,'PATH':str(tools)+':'+os.environ['PATH'],'RESTORE42_TEST_ROOT':str(root)},
                capture_output=True,text=True,timeout=10)
            events=[json.loads(line) for line in (root/'events.jsonl').read_text().splitlines()]
            retained=list(work.glob('preview42-restore.*'))
            return result,events,len(retained)

    def test_shell_and_embedded_python_parse(self):
        subprocess.run(['bash','-n',str(WRAPPER)],check=True)
        self.assertEqual(len(PYTHON),2)
        for program in PYTHON:compile(program,str(WRAPPER),'exec')

    def test_success_cancels_old_worker_probes_as_web_user_and_never_reenables_timer(self):
        result,events,retained=self.shell()
        self.assertEqual(result.returncode,0,result.stderr)
        system=[event[1:] for event in events if event[0]=='systemctl']
        mutations=[event for event in system if event[0]!='show']
        self.assertEqual(mutations,[['disable','--now','go-erp-live-updates.timer'],['stop','go-erp-live-updates.service']])
        self.assertFalse(any(event[0] in ('start','enable','restart') for event in system))
        probe,=[event for event in events if event[0]=='runuser']
        self.assertEqual(probe[1:5],['-u','fasakha','--','/opt/cpanel/ea-php82/root/usr/bin/php'])
        self.assertIn('GO_ERP_PREVIEW42_DOWNLOAD_READY_AUTOMATIC_UPDATES_DISABLED',result.stdout)
        self.assertEqual(retained,0)

    def test_failed_restore_keeps_package_and_never_runs_php_probe(self):
        result,events,retained=self.shell(helper_exit=1,restored={**RESTORED,'ok':False})
        self.assertNotEqual(result.returncode,0)
        self.assertFalse(any(event[0]=='runuser' for event in events))
        self.assertEqual(retained,1)
        self.assertIn('GO_ERP_PREVIEW42_RESTORE_PACKAGE_RETAINED',result.stderr)
        self.assertIn('GO_ERP_PREVIEW42_TIMER_PAUSED_PUBLICATION_NOT_CONFIRMED',result.stderr)

    def test_wrong_restoration_output_stops_before_php_or_success(self):
        result,events,retained=self.shell(restored={**RESTORED,'version':'0.4.4-preview.43'})
        self.assertNotEqual(result.returncode,0)
        self.assertFalse(any(event[0]=='runuser' for event in events))
        self.assertEqual(retained,1)
        self.assertNotIn('DOWNLOAD_READY_AUTOMATIC_UPDATES_DISABLED',result.stdout)

    def test_restore_protocol_requires_exact42_publication_and_no_app_or_db_writes(self):
        self.assertEqual(self.validate(0,RESTORED),0)
        for key,bad in [('kind','other'),('ok',False),('version','0.4.4-preview.43'),
                ('published',False),('application_contents_changed',True),
                ('database_changed',True),('credentials_disclosed',True)]:
            with self.subTest(key=key):self.assertNotEqual(self.validate(0,{**RESTORED,key:bad}),0)

    def test_web_protocol_requires_the_real42_source_gui_readiness(self):
        self.assertEqual(self.validate(1,WEB),0)
        for key,bad in [('kind','other'),('mode','apply'),('source_matches',False),
                ('gui',{'matches':False}),('application_writes',True),('database_accessed',True),
                ('credentials_disclosed',True),('status',{'state':'ready','version':'0.4.4-preview.43'}),
                ('status',{'state':'unavailable','version':None})]:
            with self.subTest(key=key):self.assertNotEqual(self.validate(1,{**WEB,key:bad}),0)

    def test_web_failure_never_reports_success_or_prints_untrusted_probe_text(self):
        result,events,retained=self.shell(web_exit=1,web_raw='PRIVATE_SENTINEL_DO_NOT_PRINT')
        self.assertNotEqual(result.returncode,0)
        self.assertEqual(retained,1)
        self.assertNotIn('PRIVATE_SENTINEL_DO_NOT_PRINT',result.stdout+result.stderr)
        self.assertIn('GO_ERP_PREVIEW42_WEB_CHECK_FAILED',result.stderr)
        self.assertNotIn('DOWNLOAD_READY_AUTOMATIC_UPDATES_DISABLED',result.stdout)

    def test_unready_php_result_cannot_become_success(self):
        result,events,retained=self.shell(web={**WEB,'source_matches':False})
        self.assertNotEqual(result.returncode,0)
        self.assertEqual(retained,1)
        self.assertNotIn('DOWNLOAD_READY_AUTOMATIC_UPDATES_DISABLED',result.stdout)

    def test_still_running_old_worker_prevents_restore(self):
        result,events,retained=self.shell(service_state='active')
        self.assertEqual(result.returncode,75)
        self.assertFalse(any(event[0]=='runuser' for event in events))
        self.assertIn('GO_ERP_EXISTING_WORKER_STILL_RUNNING_RETRY_LATER',result.stdout)
        self.assertEqual(retained,1)

    def test_fixed_exports_do_not_include_application_or_manual_ui_source(self):
        self.assertIn('go_prior=2d9e23a5157782882382c797a44ad15c317ae845',TEXT)
        self.assertNotIn('go_entry lease',TEXT)
        self.assertNotIn('go_worker',TEXT)
        self.assertNotIn('go-erp-releases.js',TEXT)
        self.assertNotIn('DesktopDashboardManualRelease.php',TEXT)
        self.assertNotIn('systemctl start',TEXT)
        self.assertNotIn('systemctl enable',TEXT)


if __name__=='__main__':unittest.main()
