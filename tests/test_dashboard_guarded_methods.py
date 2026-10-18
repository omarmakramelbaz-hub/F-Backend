import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch


REPOSITORY = Path(__file__).resolve().parents[1]
PHP = os.environ.get('DASHBOARD_TEST_PHP', 'php')
SPEC = importlib.util.spec_from_file_location('dashboard_guarded',
    REPOSITORY / 'deployment/apply_dashboard_guarded_methods.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class GuardedDashboardMethodsTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='dashboard-installer-test-')
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.root = self.directory / 'application'
        self.release = self.directory / 'release'
        manifest = json.loads((REPOSITORY / 'deployment/dashboard_guarded_methods.json').read_bytes())
        self.revision = manifest['reference_base']
        for relative in INSTALLER.TARGETS:
            target = self.root / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(subprocess.check_output(['git', '-C', str(REPOSITORY),
                'show', self.revision + ':' + relative]))
            target.chmod(0o640)
        for relative in (*INSTALLER.TARGETS, 'deployment/dashboard_method_tokens.php',
                         'deployment/dashboard_guarded_methods.json'):
            target = self.release / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(REPOSITORY / relative, target)
        self.helper = self.release / 'deployment/dashboard_method_tokens.php'

    def state(self):
        return {path: ((self.root / path).read_bytes(), (self.root / path).stat().st_mode & 0o777)
                for path in INSTALLER.TARGETS}

    def install(self, check=False):
        with contextlib.redirect_stdout(io.StringIO()):
            return INSTALLER.install(self.root, self.release, PHP, 'a' * 40, check)

    def receipts(self):
        return list((self.directory / 'dashboard-guarded-method-backups').glob('*/receipt.json'))

    def test_dry_run_checks_all_methods_without_changing_live_files_or_creating_backups(self):
        before = self.state()
        self.assertIsNone(self.install(True))
        self.assertEqual(before, self.state())
        self.assertFalse((self.directory / 'dashboard-guarded-method-backups').exists())

    def test_root_entry_pins_every_runtime_payload_to_its_exact_git_blob(self):
        entry = REPOSITORY / 'deployment/apply_dashboard_guarded_methods_root.sh'
        subprocess.run(['bash', '-n', str(entry)], check=True)
        pins = dict(re.findall(r'^([A-Za-z0-9_./-]+) ([0-9a-f]{40})$', entry.read_text(), re.M))
        self.assertEqual(set(INSTALLER.TARGETS) | {
            'deployment/apply_dashboard_guarded_methods.py', 'deployment/dashboard_method_tokens.php',
            'deployment/dashboard_guarded_methods.json'}, set(pins))
        for relative, expected in pins.items():
            actual = subprocess.check_output(['git', 'hash-object', '--no-filters',
                str(REPOSITORY / relative)]).decode().strip()
            self.assertEqual(expected, actual, relative)

    def test_install_is_idempotent_and_preserves_other_live_integrations_and_file_modes(self):
        target = self.root / INSTALLER.TARGETS[3]
        outside = b'\n// Unrelated later live integration, outside the selected methods.\n'
        target.write_bytes(target.read_bytes() + outside)
        backup = self.install()
        self.assertTrue(backup.is_dir())
        self.assertTrue(target.read_bytes().endswith(outside))
        self.assertEqual([], INSTALLER.build_plan(self.root, self.release, PHP))
        installed = self.state()
        self.assertIsNone(self.install())
        self.assertEqual(installed, self.state())
        self.assertEqual(1, len(self.receipts()))
        self.assertTrue(all(mode == 0o640 for _, mode in installed.values()))
        receipt = json.loads(self.receipts()[0].read_bytes())
        self.assertEqual('installed', receipt['status'])
        self.assertEqual(5, len(receipt['files']))

    def test_unrecognized_live_method_stops_the_whole_bundle_before_writing(self):
        target = self.root / INSTALLER.TARGETS[3]
        data = target.read_bytes()
        method = INSTALLER.inspect(target, PHP, self.helper)['forget_pass']
        body = data[method['start']:method['end']]
        changed = body.replace(b'random_int(1000, 9999)', b'random_int(1000, 9998)')
        self.assertNotEqual(body, changed)
        target.write_bytes(data[:method['start']] + changed + data[method['end']:])
        before = self.state()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_METHOD_CHANGED'):
            self.install()
        self.assertEqual(before, self.state())
        self.assertEqual([], self.receipts())

    def test_whitespace_and_comments_do_not_change_a_known_method_fingerprint(self):
        target = self.root / INSTALLER.TARGETS[3]
        data = target.read_bytes()
        data = data.replace(b'public function forget_pass', b'public /* formatting */ function\n        forget_pass')
        target.write_bytes(data)
        self.install(True)
        self.assertEqual(5, len(INSTALLER.build_plan(self.root, self.release, PHP)))

    def test_a_second_file_failure_restores_the_first_file_and_records_the_rollback(self):
        before = self.state()
        publish = INSTALLER.publish

        def failing_publish(path, *arguments):
            if path == self.root / INSTALLER.TARGETS[1]:
                raise OSError('Injected publish failure')
            return publish(path, *arguments)

        with patch.object(INSTALLER, 'publish', failing_publish):
            with self.assertRaises(OSError):
                self.install()
        self.assertEqual(before, self.state())
        receipt = json.loads(self.receipts()[0].read_bytes())
        self.assertEqual('rolled_back', receipt['status'])
        self.assertEqual([], receipt['review'])

    def test_rollback_keeps_a_concurrent_outside_edit_and_marks_it_for_review(self):
        publish = INSTALLER.publish
        outside = b'\n// Concurrent external edit.\n'

        def failing_publish(path, *arguments):
            if path == self.root / INSTALLER.TARGETS[1]:
                first = self.root / INSTALLER.TARGETS[0]
                first.write_bytes(first.read_bytes() + outside)
                raise OSError('Injected failure after an outside edit')
            return publish(path, *arguments)

        with patch.object(INSTALLER, 'publish', failing_publish):
            with self.assertRaises(OSError):
                self.install()
        self.assertTrue((self.root / INSTALLER.TARGETS[0]).read_bytes().endswith(outside))
        receipt = json.loads(self.receipts()[0].read_bytes())
        self.assertEqual('rollback_review_required', receipt['status'])
        self.assertEqual([INSTALLER.TARGETS[0]], receipt['review'])

    def test_failure_reading_immediately_after_rename_still_restores_the_published_file(self):
        before = self.state()
        read_state = INSTALLER.read_state
        first = self.root / INSTALLER.TARGETS[0]
        failed = False

        def failing_read(path):
            nonlocal failed
            if path == first and not failed and path.read_bytes() != before[INSTALLER.TARGETS[0]][0]:
                failed = True
                raise OSError('Injected read failure immediately after rename')
            return read_state(path)

        with patch.object(INSTALLER, 'read_state', failing_read):
            with self.assertRaises(OSError):
                self.install()
        self.assertTrue(failed)
        self.assertEqual(before, self.state())
        receipt = json.loads(self.receipts()[0].read_bytes())
        self.assertEqual('rolled_back', receipt['status'])
        self.assertEqual([], receipt['review'])

    def test_outside_edit_before_the_first_post_rename_read_is_preserved_for_review(self):
        publish = INSTALLER.publish
        first = self.root / INSTALLER.TARGETS[0]
        outside = b'\n// External edit before the post-rename read.\n'

        def concurrent_publish(path, *arguments):
            publish(path, *arguments)
            if path == first:
                path.write_bytes(path.read_bytes() + outside)

        with patch.object(INSTALLER, 'publish', concurrent_publish):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_DURING_INSTALL'):
                self.install()
        self.assertTrue(first.read_bytes().endswith(outside))
        receipt = json.loads(self.receipts()[0].read_bytes())
        self.assertEqual('rollback_review_required', receipt['status'])
        self.assertEqual([INSTALLER.TARGETS[0]], receipt['review'])

    def test_failure_writing_the_final_receipt_restores_all_published_files(self):
        before = self.state()
        receipt_write = INSTALLER.receipt_write

        def failing_receipt(path, receipt):
            if receipt['status'] == 'installed':
                raise OSError('Injected final receipt write failure')
            return receipt_write(path, receipt)

        with patch.object(INSTALLER, 'receipt_write', failing_receipt):
            with self.assertRaises(OSError):
                self.install()
        self.assertEqual(before, self.state())
        receipt = json.loads(self.receipts()[0].read_bytes())
        self.assertEqual('rolled_back', receipt['status'])
        self.assertEqual([], receipt['review'])

    def test_symlinks_and_changed_release_payloads_fail_before_writing(self):
        target = self.root / INSTALLER.TARGETS[0]
        saved = self.directory / 'outside.php'
        target.rename(saved)
        target.symlink_to(saved)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_NOT_ALLOWED'):
            self.install(True)
        target.unlink()
        saved.rename(target)
        payload = self.release / INSTALLER.TARGETS[0]
        payload.write_bytes(payload.read_bytes() + b'\n// Unverified payload.\n')
        before = self.state()
        with self.assertRaisesRegex(RuntimeError, 'PAYLOAD_HASH_MISMATCH'):
            self.install()
        self.assertEqual(before, self.state())

    def test_existing_helper_collision_and_missing_installed_helper_are_not_guessed(self):
        target = self.root / INSTALLER.TARGETS[0]
        original = target.read_bytes()
        position = original.rfind(b'}')
        target.write_bytes(original[:position] + b'    public function presentMany() { return []; }\n' + original[position:])
        with self.assertRaisesRegex(RuntimeError, 'LIVE_HELPER_COLLISION'):
            self.install()
        target.write_bytes(original)
        self.install()
        data = target.read_bytes()
        method = INSTALLER.inspect(target, PHP, self.helper)['presentation']
        target.write_bytes(data[:method['start']] + data[method['end']:])
        with self.assertRaisesRegex(RuntimeError, 'INSTALLED_HELPER_CHANGED'):
            self.install()


if __name__ == '__main__':
    unittest.main()
