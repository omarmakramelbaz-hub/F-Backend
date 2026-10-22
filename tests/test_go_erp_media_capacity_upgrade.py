import copy
import importlib.util
import json
import fcntl
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
def load(name,file):
    spec=importlib.util.spec_from_file_location(name,file);value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value
upgrade=load('capacity_upgrade',ROOT/'deployment/apply_go_erp_media_capacity_root.py')
worker=load('capacity_upgrade_worker',ROOT/'deployment/go_erp_live_updates.py')
refresh=load('capacity_upgrade_refresh',ROOT/'deployment/refresh_desktop_dashboard_server_manifest.py')

class DurableStateTests(unittest.TestCase):
    def test_seed_upgrade_keeps_credentials_and_monotonic_floors_and_only_restarts_capture_specific_state(self):
        state={'format':1,'base_revision':'a'*40,'counter':30,'version_floor':'0.4.4-preview.30',
               'sequence_floor':999,'published_history':[{'retained':True}],'dependency_guard':{'hash':'kept'},
               'last_remote_sha':'b'*40,'desired':{'old':True},'observed':{'old':True},'published':{'old':True}}
        original=copy.deepcopy(state);result=upgrade.migrated_state(state,'a'*40,'c'*40)
        self.assertEqual(state,original)
        for key in ['counter','version_floor','sequence_floor','published_history','dependency_guard','last_remote_sha']:
            self.assertEqual(result[key],state[key])
        for key in ['desired','observed','published']:self.assertIsNone(result[key])
        self.assertEqual(result['base_revision'],'c'*40)
        with self.assertRaisesRegex(RuntimeError,'STATE_IDENTITY'):upgrade.migrated_state(state,'d'*40,'c'*40)
        state['version_floor']='1.0.0'
        with self.assertRaisesRegex(RuntimeError,'VERSION_FLOOR'):upgrade.migrated_state(state,'a'*40,'c'*40)
    def test_cli_refuses_another_host_before_accessing_a_live_profile(self):
        result=subprocess.run(['python3',str(ROOT/'deployment/apply_go_erp_media_capacity_root.py'),
                               '--release','a'*40,'--apply'],capture_output=True)
        self.assertNotEqual(result.returncode,0)
        self.assertIn(b'ROOT_AND_HOST_REQUIRED',result.stdout)

@unittest.skipUnless(os.geteuid()==0,'root-owned deployment fixture')
class CapacityTransactionTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.base=Path(self.temporary.name);self.root=self.base/'project';self.work=self.base/'work';self.stage=self.base/'stage'
        for p in [self.root,self.work,self.stage,self.work/'state',self.work/'checkout']:p.mkdir(mode=0o700,parents=True,exist_ok=True)
        self.target=self.root/upgrade.SOURCE;self.target.parent.mkdir(parents=True)
        self.old=b'<?php // exact before source\n';self.target.write_bytes(self.old);self.target.chmod(0o644)
        self.wanted=(ROOT/upgrade.SOURCE).read_bytes();self.release='e'*40
        self.config=self.base/'config.json'
        self.before_config={'format':1,'repository':'omarmakramelbaz-hub/F-Backend','repository_id':1311412084,
            'branch':'codex/go-erp-live-source-20261021','base_revision':upgrade.OLD_SEED,'token':'fixture-private-token-retained'}
        self.state_path=self.work/'state/current.json'
        self.before_state={'format':1,'base_revision':upgrade.OLD_SEED,'counter':21,'version_floor':'0.4.4-preview.21',
            'sequence_floor':55,'last_remote_sha':'f'*40,'published_history':[],'dependency_guard':{'unchanged':True}}
        for path,value in [(self.config,self.before_config),(self.state_path,self.before_state)]:worker.atomic_json(path,value)
        (self.work/'checkout/old-marker').write_text('old trusted checkout')
        self.calls=[]
        def git(root,*args,**kw):
            self.calls.append(args)
            if args[:2]==('rev-parse','HEAD'):
                return (json.loads(self.config.read_text())['base_revision']+'\n').encode()
            if args[0]=='checkout':
                p=Path(root)/upgrade.SOURCE;p.parent.mkdir(parents=True);p.write_bytes(self.wanted)
                p=Path(root)/'deployment/apply_go_erp_media_capacity_root.py';p.parent.mkdir(parents=True)
                p.write_bytes((ROOT/'deployment/apply_go_erp_media_capacity_root.py').read_bytes())
            return b''
        self.policy=types.SimpleNamespace(git=git,REMOTE='fixed-public-repository',validate_seed=lambda root,rev:None)
        self.patches=[patch.object(upgrade,'PACKAGE',ROOT),patch.object(upgrade,'BEFORE_BLOB',upgrade.blob(self.old)),
            patch.object(worker,'read_config',lambda path:json.loads(path.read_bytes())),
            patch.object(upgrade.subprocess,'run',lambda *a,**kw:types.SimpleNamespace(returncode=0))]
        for item in self.patches:item.start();self.addCleanup(item.stop)
    def run_upgrade(self,apply=True):
        return upgrade.upgrade(self.root,self.work,self.config,self.stage,self.release,os.getuid(),worker,self.policy,refresh,apply)
    def test_default_plan_changes_no_source_config_or_checkout(self):
        result=self.run_upgrade(False)
        self.assertEqual(result['result'],'GO_ERP_MEDIA_CAPACITY_PLAN')
        self.assertEqual(self.target.read_bytes(),self.old)
        self.assertEqual(json.loads(self.config.read_bytes()),self.before_config)
        self.assertEqual(list(self.stage.iterdir()),[])
        self.assertFalse((self.work/'capacity-upgrades').exists())
    def test_apply_preserves_original_source_secret_and_checkout_in_private_audit_and_is_idempotent(self):
        result=self.run_upgrade();audit=Path(result['backup'])
        self.assertEqual(self.target.read_bytes(),self.wanted)
        self.assertEqual((audit/'source.before.php').read_bytes(),self.old)
        self.assertEqual(json.loads((audit/'config.before.json').read_bytes()),self.before_config)
        self.assertEqual((audit/'checkout.before/old-marker').read_text(),'old trusted checkout')
        current=json.loads(self.config.read_bytes());self.assertEqual(current['token'],self.before_config['token'])
        self.assertEqual(current['base_revision'],self.release)
        self.assertEqual(self.target.stat().st_mode&0o777,0o644)
        self.assertEqual(audit.stat().st_mode&0o777,0o700)
        self.assertEqual((audit/'config.before.json').stat().st_mode&0o777,0o600)
        self.assertEqual(json.loads((audit/'committed.json').read_bytes()),result)
        self.assertEqual(self.run_upgrade()['result'],'GO_ERP_MEDIA_CAPACITY_ALREADY_READY')
    def test_failure_after_source_copy_restores_source_config_state_and_old_checkout(self):
        original_atomic=worker.atomic_json;failed=False
        def atomic(path,value):
            nonlocal failed
            if path==self.state_path and value.get('base_revision')==self.release and not failed:
                failed=True;raise OSError('fixture write failure')
            return original_atomic(path,value)
        with patch.object(worker,'atomic_json',atomic),self.assertRaisesRegex(RuntimeError,'FAILED_BACKUP'):
            self.run_upgrade()
        self.assertEqual(self.target.read_bytes(),self.old)
        self.assertEqual(json.loads(self.config.read_bytes()),self.before_config)
        self.assertEqual(json.loads(self.state_path.read_bytes()),self.before_state)
        self.assertTrue((self.work/'checkout/old-marker').is_file())
        failed_files=list((self.work/'capacity-upgrades').glob('*/failed.json'))
        self.assertEqual(len(failed_files),1);self.assertTrue(json.loads(failed_files[0].read_bytes())['restored'])
    def test_unknown_before_source_and_linked_source_refuse_before_any_state_change(self):
        self.target.write_bytes(b'<?php // concurrent source edit\n')
        with self.assertRaisesRegex(RuntimeError,'UNREVIEWED_BEFORE_STATE'):self.run_upgrade()
        self.target.unlink();self.target.symlink_to(self.config)
        with self.assertRaisesRegex(RuntimeError,'SOURCE_IDENTITY'):self.run_upgrade()
        self.assertEqual(json.loads(self.config.read_bytes()),self.before_config)
    def test_failed_write_retains_an_unexpected_concurrent_source_edit_instead_of_overwriting_it(self):
        original_atomic=worker.atomic_json;concurrent=b'<?php // independently changed source\n'
        def atomic(path,value):
            if path==self.state_path and value.get('base_revision')==self.release:
                self.target.write_bytes(concurrent);raise OSError('write failed after another deployment')
            return original_atomic(path,value)
        with patch.object(worker,'atomic_json',atomic),self.assertRaisesRegex(RuntimeError,'FAILED_BACKUP'):
            self.run_upgrade()
        self.assertEqual(self.target.read_bytes(),concurrent)
        self.assertEqual(json.loads(self.config.read_bytes()),self.before_config)
        receipt=list((self.work/'capacity-upgrades').glob('*/failed.json'))[0]
        self.assertFalse(json.loads(receipt.read_bytes())['restored'])
    def test_active_source_watcher_refuses_the_upgrade_before_source_or_config_changes(self):
        (self.work/'staging').mkdir(mode=0o700)
        lock=os.open(self.work/'watcher.lock',os.O_RDWR|os.O_CREAT,0o600)
        try:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with patch.object(upgrade,'WORK',self.work),patch.object(upgrade.socket,'gethostname',lambda:'srv1836524'),\
                 patch.object(sys,'argv',['helper','--release',self.release,'--apply']),\
                 self.assertRaisesRegex(RuntimeError,'UPDATE_WORKER_BUSY'):
                upgrade.main()
        finally:os.close(lock)
        self.assertEqual(self.target.read_bytes(),self.old)
        self.assertEqual(json.loads(self.config.read_bytes()),self.before_config)

if __name__=='__main__':unittest.main()
