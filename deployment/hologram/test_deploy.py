"""Deployment invariants against disposable files; no application or server writes."""
import copy
import base64
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

HERE=Path(__file__).resolve().parent
SPEC=importlib.util.spec_from_file_location('hologram_deploy',HERE/'apply_root.py')
DEPLOY=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(DEPLOY)


class HologramDeployment(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='hologram-deploy-test-')
        self.root=Path(self.temp.name);self.project=self.root/'application';self.project.mkdir()
        self.existing='app/Http/Controllers/Dashboard/HomeController.php'
        self.new='app/Services/Dashboard/HomeHologramMetrics.php'
        target=self.project/self.existing;target.parent.mkdir(parents=True)
        self.old=b'<?php\n// unique reviewed marker\nreturn 1;\n'
        self.after=self.old.replace(b'return 1;',b'return 2;')
        target.write_bytes(self.old);target.chmod(0o640)
        helper=b'<?php\n// new reviewed hologram metrics\n'
        self.manifest={'format':1,'feature':DEPLOY.FEATURE,'base_revision':DEPLOY.BASE_REVISION,'files':[
            {'path':self.existing,'kind':'patch','before_sha256':DEPLOY.digest(self.old),
             'after_sha256':DEPLOY.digest(self.after),'edits':[{'before':self.old.decode(),'after':self.after.decode()}]},
            {'path':self.new,'kind':'new','before_sha256':None,'after_sha256':DEPLOY.digest(helper),
             'encoding':'utf-8','content':helper.decode()}]}

    def tearDown(self):
        self.temp.cleanup()

    def apply(self,manifest=None,verify=lambda:None):
        changes=DEPLOY.plan(self.project,manifest or self.manifest)
        DEPLOY.apply_plan(self.project,changes,self.root/'backup',(os.getuid(),os.getgid()),verify)
        return changes

    def test_apply_preserves_unrelated_live_changes_and_permissions_and_repeats(self):
        path=self.project/self.existing
        path.write_bytes(self.old+b'// unrelated live adjustment\n')
        self.apply()
        self.assertEqual(path.read_bytes(),self.after+b'// unrelated live adjustment\n')
        self.assertEqual(path.stat().st_mode&0o777,0o640)
        self.assertEqual((self.root/'backup'/self.existing).read_bytes(),self.old+b'// unrelated live adjustment\n')
        self.assertTrue(all(change['before']==change['after'] for change in DEPLOY.plan(self.project,self.manifest)))

    def test_failed_verification_restores_source_and_removes_new_files(self):
        def reject(): raise RuntimeError('fixture validation failure')
        with self.assertRaisesRegex(RuntimeError,'fixture validation failure'):
            self.apply(verify=reject)
        self.assertEqual((self.project/self.existing).read_bytes(),self.old)
        self.assertFalse((self.project/self.new).exists())
        self.assertFalse((self.project/self.new).parent.exists())

    def test_drift_inside_reviewed_fragment_stops_before_writes(self):
        (self.project/self.existing).write_bytes(self.old.replace(b'return 1;',b'return 3;'))
        with self.assertRaisesRegex(DEPLOY.ReviewError,'SOURCE_CHANGED'):
            DEPLOY.plan(self.project,self.manifest)
        self.assertFalse((self.project/self.new).exists())

    def test_unreviewed_and_duplicate_paths_are_refused(self):
        bad=copy.deepcopy(self.manifest);bad['files'][1]['path']='.env'
        with self.assertRaisesRegex(DEPLOY.ReviewError,'UNREVIEWED_PATH'):
            DEPLOY.plan(self.project,bad)
        bad=copy.deepcopy(self.manifest);bad['files'].append(bad['files'][0])
        with self.assertRaisesRegex(DEPLOY.ReviewError,'DUPLICATE_PATH'):
            DEPLOY.plan(self.project,bad)

    def test_symlink_parent_and_leaf_are_refused(self):
        target=self.project/self.existing;target.unlink();target.symlink_to(self.root/'outside')
        with self.assertRaisesRegex(DEPLOY.ReviewError,'SYMLINK'):
            DEPLOY.plan(self.project,self.manifest)
        target.unlink();target.write_bytes(self.old)
        (self.project/'app/Services').symlink_to(self.root)
        with self.assertRaisesRegex(DEPLOY.ReviewError,'SYMLINK'):
            DEPLOY.plan(self.project,self.manifest)

    def test_payload_tamper_and_wrong_baseline_result_are_refused(self):
        bad=copy.deepcopy(self.manifest);bad['files'][1]['content']+='tampered'
        with self.assertRaisesRegex(DEPLOY.ReviewError,'PAYLOAD_HASH'):
            DEPLOY.plan(self.project,bad)
        bad=copy.deepcopy(self.manifest);bad['files'][0]['after_sha256']='0'*64
        with self.assertRaisesRegex(DEPLOY.ReviewError,'PATCH_HASH'):
            DEPLOY.plan(self.project,bad)

    def test_binary_asset_is_verified_and_preserved_byte_for_byte(self):
        content=b'RIFF\x00\xffWEBP\x81\x00\xfe'
        manifest=copy.deepcopy(self.manifest)
        relative='public/dashboard/branding/go-hologram-bust.webp'
        manifest['files'].append({'path':relative,'kind':'new','before_sha256':None,
            'after_sha256':DEPLOY.digest(content),'encoding':'base64','content':base64.b64encode(content).decode()})
        self.apply(manifest)
        self.assertEqual((self.project/relative).read_bytes(),content)
        self.assertTrue(all(change['before']==change['after'] for change in DEPLOY.plan(self.project,manifest)))

    def test_crlf_production_source_retains_crlf(self):
        (self.project/self.existing).write_bytes(self.old.replace(b'\n',b'\r\n'))
        changes=DEPLOY.plan(self.project,self.manifest)
        self.assertEqual(changes[0]['after'],self.after.replace(b'\n',b'\r\n'))

    def test_concurrent_change_is_preserved_and_prior_files_roll_back(self):
        changes=DEPLOY.plan(self.project,self.manifest)
        target=self.project/self.new;target.parent.mkdir(parents=True);target.write_text('concurrent new implementation')
        with self.assertRaisesRegex(DEPLOY.ReviewError,'CONCURRENT_SOURCE_CHANGE'):
            DEPLOY.apply_plan(self.project,changes,self.root/'backup',(os.getuid(),os.getgid()),lambda:None)
        self.assertEqual((self.project/self.existing).read_bytes(),self.old)
        self.assertEqual(target.read_text(),'concurrent new implementation')

    def test_ambiguous_mixed_old_and_new_fragments_are_refused(self):
        (self.project/self.existing).write_bytes(self.old+self.after)
        with self.assertRaisesRegex(DEPLOY.ReviewError,'SOURCE_CHANGED'):
            DEPLOY.plan(self.project,self.manifest)

    def test_error_after_atomic_rename_still_rolls_back_that_file(self):
        original=DEPLOY.os.open
        failed=False
        def fail_first_directory_open(path,flags,*args,**kwargs):
            nonlocal failed
            if flags&os.O_DIRECTORY and not failed:
                failed=True
                raise OSError('fixture directory fsync unavailable')
            return original(path,flags,*args,**kwargs)
        with mock.patch.object(DEPLOY.os,'open',side_effect=fail_first_directory_open):
            with self.assertRaisesRegex(OSError,'fixture directory fsync unavailable'):
                self.apply()
        self.assertEqual((self.project/self.existing).read_bytes(),self.old)
        self.assertFalse((self.project/self.new).exists())

    def test_concurrent_symlink_during_rollback_does_not_skip_earlier_files(self):
        outside=self.root/'outside';outside.write_text('untouched unrelated content')
        def fail_with_concurrent_link():
            path=self.project/self.new;path.unlink();path.symlink_to(outside)
            raise RuntimeError('fixture concurrent link')
        with self.assertRaisesRegex(DEPLOY.ReviewError,'ROLLBACK_CONFLICT'):
            self.apply(verify=fail_with_concurrent_link)
        self.assertEqual((self.project/self.existing).read_bytes(),self.old)
        self.assertTrue((self.project/self.new).is_symlink())
        self.assertEqual(outside.read_text(),'untouched unrelated content')


if __name__=='__main__':
    unittest.main()
