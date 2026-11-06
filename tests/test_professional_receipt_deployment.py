"""Exercise scoped receipt installation and rollback without a live server."""
import copy
import importlib.util
import json
import os
import stat
import subprocess
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
RELEASE = ROOT/'deployment/professional-receipts-20261106'
SPEC = importlib.util.spec_from_file_location('receipt_release', RELEASE/'apply_root.py')
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)


class ReceiptReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='receipt-release-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()/'project'
        self.root.mkdir()
        self.manifest = json.loads((RELEASE/'manifest.json').read_text())
        self.original = {}
        for index, item in enumerate(self.manifest['files']):
            path = self.root/item['path']
            if item['before_sha256'] is not None:
                path.parent.mkdir(parents=True, exist_ok=True)
                content = ('original receipt '+str(index)).encode()
                path.write_bytes(content)
                item['before_sha256'] = release.digest(content)
                self.original[item['path']] = content
            else:
                if path.parent != self.root/'resources/views/admin/printing':
                    path.parent.mkdir(parents=True, exist_ok=True)
                self.original[item['path']] = None
        self.backup = Path(self.temp.name)/'backup'
        self.owner = (os.getuid(),os.getgid())

    def state(self):
        return {name:(self.root/name).read_bytes() if (self.root/name).exists() else None for name in release.PATHS}

    def test_apply_and_idempotent_repeat(self):
        changes = release.plan(self.root,self.manifest)
        proof = []
        count = release.apply_plan(self.root,changes,self.backup,self.owner,lambda:proof.append(True))
        self.assertEqual(count,len(release.PATHS))
        self.assertTrue(proof)
        for item in self.manifest['files']:
            self.assertEqual((self.root/item['path']).read_bytes(),item['content'].encode())
        count = release.apply_plan(self.root,release.plan(self.root,self.manifest),Path(self.temp.name)/'unneeded',self.owner,lambda:None)
        self.assertEqual(count,0)
        self.assertFalse((Path(self.temp.name)/'unneeded').exists())

    def test_failed_verification_restores_original_files(self):
        changes=release.plan(self.root,self.manifest)
        def fail(): raise RuntimeError('verification intentionally failed')
        with self.assertRaisesRegex(RuntimeError,'intentionally failed'):
            release.apply_plan(self.root,changes,self.backup,self.owner,fail)
        self.assertEqual(self.state(),self.original)
        self.assertTrue((self.backup/'receipt.json').is_file())

    def test_changed_server_source_is_never_overwritten(self):
        name=next(name for name,value in self.original.items() if value is not None)
        (self.root/name).write_bytes(b'newer server edit')
        before=self.state()
        with self.assertRaisesRegex(RuntimeError,'RECEIPT_SOURCE_CHANGED'):
            release.plan(self.root,self.manifest)
        self.assertEqual(self.state(),before)

    def test_concurrent_edit_survives_apply_attempt(self):
        changes=release.plan(self.root,self.manifest)
        existing=next(item for item in changes if item['before'] is not None)
        (self.root/existing['path']).write_bytes(b'concurrent edit')
        before=self.state()
        with self.assertRaisesRegex(RuntimeError,'RECEIPT_CONCURRENT_SOURCE_CHANGE'):
            release.apply_plan(self.root,changes,self.backup,self.owner,lambda:None)
        self.assertEqual(self.state(),before)

    def test_unreviewed_path_is_rejected(self):
        manifest=copy.deepcopy(self.manifest)
        manifest['files'][0]['path']='app/Services/Dashboard/TakeawayService.php'
        with self.assertRaisesRegex(RuntimeError,'RECEIPT_MANIFEST_CHANGED'):
            release.plan(self.root,manifest)

    def test_tampered_candidate_is_rejected(self):
        manifest=copy.deepcopy(self.manifest)
        manifest['files'][0]['content']+='tampered'
        with self.assertRaisesRegex(RuntimeError,'RECEIPT_CANDIDATE_CHANGED'):
            release.plan(self.root,manifest)

    def test_symlink_destination_is_rejected(self):
        name=next(name for name,value in self.original.items() if value is not None)
        path=self.root/name; path.unlink()
        outside=Path(self.temp.name)/'unrelated'; outside.write_bytes(b'preserve')
        path.symlink_to(outside)
        with self.assertRaisesRegex(RuntimeError,'RECEIPT_SYMLINK_PATH'):
            release.plan(self.root,self.manifest)
        self.assertEqual(outside.read_bytes(),b'preserve')

    def test_staged_source_is_readable_with_production_umask(self):
        stage=Path(self.temp.name)/'stage'
        stage.mkdir(mode=0o755)
        changes=release.plan(self.root,self.manifest)
        previous=os.umask(0o077)
        try:
            candidate=release.stage_candidate(stage,changes)
            for path in [candidate,*candidate.rglob('*')]:
                mode=stat.S_IMODE(path.stat().st_mode)
                self.assertEqual(mode,0o755 if path.is_dir() else 0o444,str(path))
                if path.is_dir():
                    self.assertTrue(mode & stat.S_IXOTH,'site account cannot traverse '+str(path))
                else:
                    self.assertTrue(mode & stat.S_IROTH,'site account cannot read '+str(path))
            release.apply_plan(self.root,changes,self.backup,self.owner,lambda:None)
            self.assertEqual(stat.S_IMODE(self.backup.stat().st_mode),0o700)
            self.assertEqual(stat.S_IMODE((self.backup/'receipt.json').stat().st_mode),0o600)
            self.assertEqual(stat.S_IMODE((self.root/'resources/views/admin/printing').stat().st_mode),0o755)
        finally:
            os.umask(previous)

    def test_verification_failure_identifies_phase_without_raw_stderr(self):
        record={'status':'RECEIPT_RENDER_FAILED','fixture':'takeaway','locale':'ar','exception':'RuntimeException','check':'compact paper takeaway'}
        output=subprocess.CompletedProcess(['php'],1,stdout='',stderr=json.dumps(record)+'\nSENSITIVE-UNSTRUCTURED-DETAIL\n')
        with patch.object(release.subprocess,'run',return_value=output):
            with self.assertRaises(RuntimeError) as caught:
                release.run(['php'],self.root,phase='preflight')
        message=str(caught.exception)
        self.assertIn('preflight',message)
        self.assertIn('compact paper takeaway',message)
        self.assertNotIn('SENSITIVE-UNSTRUCTURED-DETAIL',message)

    def test_verification_timeout_identifies_phase(self):
        with patch.object(release.subprocess,'run',side_effect=subprocess.TimeoutExpired(['php'],60)):
            with self.assertRaisesRegex(RuntimeError,'RECEIPT_RUNTIME_TIMEOUT: verified'):
                release.run(['php'],self.root,phase='verified')


if __name__ == '__main__':
    unittest.main()
