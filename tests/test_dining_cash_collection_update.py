"""Check incremental publication, repeats, conflicts, and rollback on real Git fixtures."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


installer = module('dining_cash_installer', ROOT / 'deployment/apply_dining_cash_collection.py')


class DiningCashCollectionDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='dining-cash-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        self.path = self.root / next(iter(installer.SOURCE_MANIFEST))
        self.path.parent.mkdir(parents=True)
        self.view = self.root / list(installer.SOURCE_MANIFEST)[1]
        self.view.parent.mkdir(parents=True)
        self.before = b''.join(b'// stable prefix %d\n' % n for n in range(15)) + b'// Before kitchen Enter\n' + b''.join(b'// stable suffix %d\n' % n for n in range(15))
        self.after = self.before.replace(b'Before kitchen Enter', b'After kitchen Enter')
        self.path.write_bytes(self.before)
        self.view.write_bytes(self.before)
        self.git('add', '.')
        self.git('commit', '-qm', 'Baseline')
        base = self.git('rev-parse', 'HEAD').strip()
        self.path.write_bytes(self.after)
        self.view.write_bytes(self.after)
        self.git('commit', '-qam', 'Release')
        self.revision = self.git('rev-parse', 'HEAD').strip()
        self.path.write_bytes(self.before)
        self.view.write_bytes(self.before)
        self.common = module('dining_cash_helpers', ROOT / 'deployment/apply_pos_inline_quantity.py')
        self.addCleanup(mock.patch.stopall)
        mock.patch.object(installer, 'BASE_REVISION', base).start()
        mock.patch.object(installer, 'UTILITY_REVISION', base).start()
        mock.patch.object(installer, 'load_common', return_value=self.common).start()

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], text=True)

    def install(self, **kwargs):
        with contextlib.redirect_stdout(io.StringIO()):
            return installer.install(self.root, self.revision, **kwargs)

    def test_dry_run_preserves_live_customization_then_apply_and_repeat(self):
        extra = b'// Independent live dashboard customization\n'
        self.path.write_bytes(self.before + extra)
        self.assertIsNone(self.install(check_only=True))
        self.assertEqual(self.path.read_bytes(), self.before + extra)
        backup = self.install()
        self.assertEqual(self.path.read_bytes(), self.after + extra)
        self.assertEqual(self.view.read_bytes(), self.after)
        self.assertEqual((backup / self.path.relative_to(self.root)).read_bytes(), self.before + extra)
        self.assertEqual(json.loads((backup / 'receipt.json').read_text())['status'], 'installed')
        self.assertIsNone(self.install())
        self.assertEqual(self.path.read_bytes(), self.after + extra)
        self.assertEqual(self.view.read_bytes(), self.after)

    def test_partial_install_finishes_remaining_file(self):
        self.path.write_bytes(self.after)
        backup = self.install()
        self.assertEqual(self.path.read_bytes(), self.after)
        self.assertEqual(self.view.read_bytes(), self.after)
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual([x['path'] for x in receipt['files']], [self.view.relative_to(self.root).as_posix()])
        self.assertIsNone(self.install())

    def test_conflict_preserves_every_live_byte(self):
        live = self.before.replace(b'Before kitchen Enter', b'Overlapping operator change')
        self.path.write_bytes(live)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.install()
        self.assertEqual(self.path.read_bytes(), live)
        self.assertEqual(self.view.read_bytes(), self.before)

    def test_post_publication_failure_restores_owned_change(self):
        original = self.common.atomic
        count = 0

        def interrupted(path, body, mode):
            nonlocal count
            count += 1
            original(path, body, mode)
            if count == 1:
                raise OSError('Publication interrupted')

        with mock.patch.object(self.common, 'atomic', side_effect=interrupted):
            with self.assertRaisesRegex(OSError, 'Publication interrupted'):
                self.install()
        self.assertEqual(self.path.read_bytes(), self.before)
        self.assertEqual(self.view.read_bytes(), self.before)
        self.assertEqual(count, 2)

    def test_concurrent_operator_edit_is_preserved(self):
        original = self.common.atomic
        live = self.after + b'// Concurrent operator edit\n'

        def concurrent(path, body, mode):
            original(path, body, mode)
            path.write_bytes(live)

        with mock.patch.object(self.common, 'atomic', side_effect=concurrent):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_DURING_INSTALL'):
                self.install()
        self.assertEqual(self.path.read_bytes(), live)
        self.assertEqual(self.view.read_bytes(), self.before)

    def test_symlink_target_rejected(self):
        destination = self.root / 'operator.js'
        destination.write_bytes(self.before)
        self.path.unlink()
        self.path.symlink_to(destination)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.install()
        self.assertEqual(destination.read_bytes(), self.before)


if __name__ == '__main__':
    unittest.main()
