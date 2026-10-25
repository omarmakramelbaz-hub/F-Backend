"""Exercise actual bounded cache deletion and preservation on unsafe/in-use paths."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

SPEC = importlib.util.spec_from_file_location('server_cleanup_test',
    Path(__file__).resolve().parents[1] / 'deployment/clean_verified_server_files_root.py')
worker = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(worker)


@unittest.skipUnless(os.geteuid() == 0 and Path('/root').is_dir(), 'requires private root fixture')
class CacheCleanupTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='cache-clean-fixture-', dir='/root')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.cache = self.root / 'composer'
        self.cache.mkdir(mode=0o700)
        (self.cache / 'package.zip').write_bytes(b'regenerable-package-fixture')
        (self.cache / 'package.zip').chmod(0o600)
        self.audit = self.root / 'audit'
        self.audit.mkdir(mode=0o700)
        self.current = self.root / 'current-app'
        self.current.write_bytes(b'production-fixture')
        patcher = mock.patch.object(worker, 'CACHES', (self.cache,))
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_actual_deletion_is_limited_to_named_cache(self):
        result = worker.clean_caches(self.audit)
        self.assertEqual(result['removed_cache_paths'], [str(self.cache)])
        self.assertFalse(self.cache.exists())
        self.assertEqual(self.current.read_bytes(), b'production-fixture')

    def test_symlink_cannot_delete_its_target(self):
        (self.cache / 'linked-source').symlink_to(self.current)
        result = worker.clean_caches(self.audit)
        self.assertEqual(result['removed_cache_paths'], [])
        self.assertTrue(self.cache.exists())
        self.assertEqual(self.current.read_bytes(), b'production-fixture')

    def test_active_cache_is_preserved(self):
        with mock.patch.object(worker, 'process_uses', return_value=True):
            result = worker.clean_caches(self.audit)
        self.assertEqual(result['removed_cache_paths'], [])
        self.assertTrue((self.cache / 'package.zip').is_file())

    def test_changed_quarantined_cache_is_restored(self):
        original = worker.cache_fingerprint
        def changed(path):
            result = original(path)
            if path.parent == self.audit:
                (path / 'new-unreviewed-package.zip').write_bytes(b'concurrent')
                return original(path)
            return result
        with mock.patch.object(worker, 'cache_fingerprint', side_effect=changed):
            result = worker.clean_caches(self.audit)
        self.assertEqual(result['removed_cache_paths'], [])
        self.assertEqual((self.cache / 'new-unreviewed-package.zip').read_bytes(), b'concurrent')
        self.assertEqual(self.current.read_bytes(), b'production-fixture')


if __name__ == '__main__':
    unittest.main()
