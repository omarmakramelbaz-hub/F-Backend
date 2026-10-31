import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('owner_mobile_deployment', ROOT/'deployment/apply_owner_mobile_fix_root.py')
DEPLOY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEPLOY)
MANIFEST = json.loads((ROOT/'deployment/owner_mobile_fix_manifest.json').read_text())

class OwnerMobileDeployment(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='owner-mobile-deployment-')
        self.base = Path(self.temporary.name)
        self.project = self.base/'project'
        self.project.mkdir()
        self.before = {}
        for entry in MANIFEST['files']:
            target = self.project/entry['path']
            target.parent.mkdir(parents=True, exist_ok=True)
            if entry['kind'] == 'patch':
                content = subprocess.check_output(['git','show','c060c734b8095b6db2c422cd636899bae397f0b3:'+entry['path']],cwd=ROOT)
                # These independent live edits must survive both apply and rollback.
                content += b'\n/* unrelated live release marker */\n'
                target.write_bytes(content)
                target.chmod(0o640)
                self.before[entry['path']] = content
        untouched = self.project/'application-unrelated.txt'
        untouched.write_text('keep unrelated state')

    def tearDown(self):
        self.temporary.cleanup()

    def test_scoped_apply_and_repeat(self):
        changes = DEPLOY.plan(self.project, MANIFEST)
        DEPLOY.apply_plan(self.project,changes,self.base/'backup',(os.getuid(),os.getgid()),lambda:None)
        for entry in MANIFEST['files']:
            content = (self.project/entry['path']).read_bytes()
            if entry['kind'] == 'patch':
                self.assertTrue(content.endswith(b'/* unrelated live release marker */\n'))
                self.assertEqual((self.project/entry['path']).stat().st_mode & 0o777,0o640)
                self.assertEqual((self.base/'backup'/entry['path']).read_bytes(),self.before[entry['path']])
        self.assertEqual((self.project/'application-unrelated.txt').read_text(),'keep unrelated state')
        self.assertTrue(all(item['before'] == item['after'] for item in DEPLOY.plan(self.project,MANIFEST)))

    def test_failed_verification_rolls_back_old_and_new_files(self):
        changes = DEPLOY.plan(self.project,MANIFEST)
        def failed():
            raise RuntimeError('fixture verification failure')
        with self.assertRaisesRegex(RuntimeError,'fixture verification failure'):
            DEPLOY.apply_plan(self.project,changes,self.base/'backup',(os.getuid(),os.getgid()),failed)
        for entry in MANIFEST['files']:
            path = self.project/entry['path']
            if entry['kind'] == 'new': self.assertFalse(path.exists())
            else: self.assertEqual(path.read_bytes(),self.before[entry['path']])

    def test_source_drift_stops_before_any_write(self):
        path = self.project/'app/Http/Controllers/Dashboard/HomeController.php'
        path.write_bytes(path.read_bytes().replace(b'public function signin(Request $request)',b'public function signin($customRequest)'))
        with self.assertRaisesRegex(DEPLOY.ReviewError,'GO_OWNER_MOBILE_SOURCE_CHANGED'):
            DEPLOY.plan(self.project,MANIFEST)
        self.assertFalse((self.project/'app/Services/Dashboard/OwnerMobile.php').exists())

    def test_crlf_live_source_is_preserved(self):
        for relative in self.before:
            target = self.project/relative
            target.write_bytes(target.read_bytes().replace(b'\n',b'\r\n'))
        changes = DEPLOY.plan(self.project,MANIFEST)
        for change in changes:
            if change['path'] in self.before:
                self.assertNotIn(b'\n',change['after'].replace(b'\r\n',b''))

    def test_existing_helper_and_symlink_are_refused(self):
        helper = self.project/'app/Services/Dashboard/OwnerMobile.php'
        helper.write_text('existing unrelated implementation')
        with self.assertRaises(DEPLOY.ReviewError): DEPLOY.plan(self.project,MANIFEST)
        helper.unlink(); helper.symlink_to(self.project/'application-unrelated.txt')
        with self.assertRaises(DEPLOY.ReviewError): DEPLOY.plan(self.project,MANIFEST)

    def test_concurrent_change_does_not_overwrite_live_data(self):
        changes = DEPLOY.plan(self.project,MANIFEST)
        last = self.project/changes[-1]['path']
        last.write_bytes(last.read_bytes()+b'concurrent source change\n')
        with self.assertRaisesRegex(DEPLOY.ReviewError,'GO_OWNER_MOBILE_CONCURRENT_SOURCE_CHANGE'):
            DEPLOY.apply_plan(self.project,changes,self.base/'backup',(os.getuid(),os.getgid()),lambda:None)
        self.assertTrue(last.read_bytes().endswith(b'concurrent source change\n'))
        self.assertFalse((self.project/'app/Services/Dashboard/OwnerMobile.php').exists())

if __name__ == '__main__':
    unittest.main()
