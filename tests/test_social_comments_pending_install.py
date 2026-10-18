"""Private filesystem fixtures: no database access or live Meta requests."""
import importlib.util
import subprocess
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('pending_installer',ROOT/'deployment/apply_social_comments_pending.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class PendingInstallerTests(unittest.TestCase):
    def setUp(self):
        self.names=[m.CSS,m.SERVICE,m.CONTROLLER,m.JS,m.VIEW]
        self.new=[(ROOT/p).read_bytes() for p in self.names]
        self.service_block=m.fragment(self.new[1],b'    // BEGIN META_PENDING_COMMENT_COUNTS\n',b'    // END META_PENDING_COMMENT_COUNTS\n\n')
        self.js_block=m.fragment(self.new[3],b'        // BEGIN META_PENDING_BADGES\n',b'        // END META_PENDING_BADGES\n')
        self.old_controller=self.new[2]
        for before,after in reversed(m.CONTROLLER_EDITS):self.old_controller=self.old_controller.replace(after,before,1)
        self.old_js=subprocess.check_output(['git','-C',str(ROOT),'cat-file','blob','05c25714397be7ea54d1a0568e4a6277af9f1b9d'])
        self.old=[None,self.new[1].replace(self.service_block,b'',1),self.old_controller,self.old_js,self.new[4].replace(m.style_link(self.new[4]),b'',1)]
    def fixture(self,folder):
        root=Path(folder)/'application'
        for name,data in zip(self.names,self.old):
            p=root/name;p.parent.mkdir(parents=True,exist_ok=True)
            if data is not None:p.write_bytes(data);p.chmod(0o640)
        args=[str(root),'fixture-php',str(ROOT/m.VIEW),str(ROOT/m.JS),str(ROOT/m.CSS),str(ROOT/m.SERVICE),str(ROOT/m.CONTROLLER),[m.sha(v) for v in self.new]]
        return root,args
    def test_frozen_upgrade_and_unrelated_logic_preserved(self):
        self.assertEqual(m.surgical(self.old_js,self.js_block,b"        var emojiCategory = 'reactions', caret = {start:0,end:0};",m.JS_EDITS,b'BEGIN META_PENDING_BADGES'),self.new[3])
        self.assertEqual(m.controller(self.old_controller),self.new[2])
        suffix=b'\n// Independent production correction\n'
        live=self.old[1]+suffix
        changed=m.surgical(live,self.service_block,b'    private function commentList(',[],b'BEGIN META_PENDING_COMMENT_COUNTS')
        self.assertEqual(changed.replace(self.service_block,b'',1),live)
        self.assertEqual(m.surgical(changed,self.service_block,b'    private function commentList(',[],b'BEGIN META_PENDING_COMMENT_COUNTS'),changed)
    def test_apply_idempotence_backups_and_no_database_command(self):
        calls=[]
        def command(argv,cwd=None,input=None):
            calls.append(argv)
            if argv[0]=='fixture-php':
                self.assertTrue(argv==['fixture-php','-l'] or argv==['fixture-php','artisan','view:clear']);return b''
            return subprocess.run(argv,input=input,capture_output=True,check=True).stdout
        with tempfile.TemporaryDirectory() as folder:
            root,args=self.fixture(folder)
            with patch.object(m,'compile_check'),patch.object(m,'command',command):
                result=m.apply(*args);self.assertEqual(result['files'],5);self.assertEqual(result['database_changes'],0)
                for path,data in zip(self.names,self.new):self.assertEqual((root/path).read_bytes(),data)
                for path,data in zip(self.names,self.old):
                    if data is not None:self.assertEqual((Path(result['backup'])/Path(path).name).read_bytes(),data)
                self.assertEqual(m.apply(*args)['status'],'ALREADY_APPLIED')
    def test_failure_restores_all_five_targets(self):
        def command(argv,cwd=None,input=None):
            if argv==['fixture-php','artisan','view:clear']:raise m.Refused('CACHE_CLEAR_FAILED')
            return b''
        with tempfile.TemporaryDirectory() as folder:
            root,args=self.fixture(folder)
            with patch.object(m,'compile_check'),patch.object(m,'command',command):
                with self.assertRaisesRegex(m.Refused,'ROLLED_BACK'):m.apply(*args)
            for path,data in zip(self.names,self.old):self.assertEqual((root/path).read_bytes() if (root/path).exists() else None,data)
    def test_partial_live_change_refuses_before_writes(self):
        with tempfile.TemporaryDirectory() as folder:
            root,args=self.fixture(folder);p=root/m.JS;p.write_bytes(self.old_js.replace(b'timelineVersion:2',b'timelineVersion:99'))
            with patch.object(m,'compile_check') as compiler:
                with self.assertRaises(m.Refused):m.apply(*args)
                compiler.assert_not_called()
            self.assertFalse((root/m.CSS).exists())
if __name__=='__main__':unittest.main()
