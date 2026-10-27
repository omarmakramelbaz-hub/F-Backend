import hashlib
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

SPEC=importlib.util.spec_from_file_location('mode_repair',Path(__file__).resolve().parents[1]/'deployment/repair_go_erp_shared_workspace_modes_root.py')
m=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(m)

class RepairTests(unittest.TestCase):
    def setUp(self):
        # Use the runner's mapped identity for actual files; production constants
        # remain 1001:1003 in the published helper.
        owners=patch.multiple(m,APP_UID=os.getuid(),APP_GID=os.getgid());owners.start();self.addCleanup(owners.stop)
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.base=Path(self.tmp.name);self.root=self.base/'app';self.private=self.base/'private'
        self.root.mkdir(mode=0o755);self.private.mkdir(mode=0o700);self.io=m.source_io();self.rows=[]
        for name,old,target in [('one.php',0o755,0o644),('two.php',0o600,0o640),('three.php',0o775,0o644)]:
            raw=('reviewed '+name).encode();p=self.root/name;p.write_bytes(raw);p.chmod(old)
            self.rows.append({'path':name,'size':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'old_mode':old,'target_mode':target})
    def run_repair(self,verify=lambda:None,prepare=lambda:None):
        return m.repair(self.io,self.root,self.private,self.rows,verify,prepare)
    def modes(self):return [stat.S_IMODE((self.root/r['path']).stat().st_mode) for r in self.rows]
    def test_all_modes_and_content_preserved(self):
        before=[(self.root/r['path']).read_bytes() for r in self.rows];result=self.run_repair()
        self.assertEqual(result['changed'],3);self.assertEqual(self.modes(),[0o644,0o640,0o644])
        self.assertEqual(before,[(self.root/r['path']).read_bytes() for r in self.rows]);self.assertTrue(m.completed(self.io,self.private,self.rows))
    def test_later_hash_failure_causes_zero_chmods(self):
        (self.root/'three.php').write_bytes(b'foreign bytes')
        with self.assertRaises(m.Stop):self.run_repair()
        self.assertEqual(self.modes(),[0o755,0o600,0o775]);self.assertFalse(m.names(self.private)[0].exists())
    def test_foreign_owner_causes_zero_chmods(self):
        with patch.object(m,'APP_UID',1001):
            with self.assertRaises(m.Stop):self.run_repair()
        self.assertEqual(self.modes(),[0o755,0o600,0o775])
    def test_hard_link_rejected(self):
        os.link(self.root/'two.php',self.root/'alias.php')
        with self.assertRaises(m.Stop):self.run_repair()
        self.assertEqual(self.modes(),[0o755,0o600,0o775])
    def test_symlink_rejected_without_touching_destination(self):
        target=self.root/'two.php';target.rename(self.root/'external.php');target.symlink_to(self.root/'external.php')
        with self.assertRaises(OSError):self.run_repair()
        self.assertEqual(stat.S_IMODE((self.root/'external.php').stat().st_mode),0o600)
    def interrupted(self):
        def verify():
            if m.names(self.private)[0].exists():raise RuntimeError('simulated interruption after durable intent')
        with self.assertRaises(RuntimeError):self.run_repair(verify)
        self.assertTrue(m.names(self.private)[0].exists());self.assertFalse(m.names(self.private)[1].exists())
    def test_interrupted_partial_mode_change_resumes_same_wal(self):
        self.interrupted();(self.root/'one.php').chmod(0o644)
        self.assertEqual(self.run_repair()['changed'],2);self.assertEqual(self.modes(),[0o644,0o640,0o644])
    def test_same_bytes_replaced_inode_rejected_after_intent(self):
        self.interrupted();p=self.root/'one.php';q=self.root/'other.php';q.write_bytes(p.read_bytes());q.chmod(0o755);q.replace(p)
        with self.assertRaises(m.Stop):self.run_repair()
        self.assertEqual(self.modes(),[0o755,0o600,0o775])
    def test_completed_repair_allows_original_source_upgrade_resume(self):
        self.run_repair();(self.root/'one.php').write_bytes(b'legitimately upgraded by original source transaction')
        def no_prepare():raise AssertionError('must delegate current source validation to original operator')
        self.assertTrue(self.run_repair(prepare=no_prepare)['already_complete'])
    def test_no_transaction_intent_may_exist(self):
        flow={'release_pins_sha256':m.PINS_SHA256,'phase':'observed','prepared':None,'activation':None}
        self.io.private_record(self.private/('shared-workspace-root-'+m.CORE+'.json'),flow,lambda:None)
        audits=self.private/'audits';audits.mkdir(mode=0o700);folder=audits/m.AUDITS[0];folder.mkdir(mode=0o700)
        m.check_preparation(self.io,self.private);(folder/'intent.json').write_text('{}')
        with self.assertRaises(m.Stop):m.check_preparation(self.io,self.private)
    def test_prepared_root_checkpoint_rejected(self):
        flow={'release_pins_sha256':m.PINS_SHA256,'phase':'prepared','prepared':{},'activation':None}
        self.io.private_record(self.private/('shared-workspace-root-'+m.CORE+'.json'),flow,lambda:None)
        with self.assertRaises(m.Stop):m.check_preparation(self.io,self.private)

if __name__=='__main__':unittest.main()
