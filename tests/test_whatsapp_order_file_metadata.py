#!/usr/bin/env python3
"""Read-only owner/type diagnostics must not follow links or read configuration."""
import contextlib
import importlib.util
import io
import json
import os
import pathlib
import tempfile
import types
import unittest
from unittest import mock


SOURCE = pathlib.Path(__file__).resolve().parents[1]/'deployment/whatsapp_order_file_metadata.py'
SPEC = importlib.util.spec_from_file_location('order_file_metadata', SOURCE)
diagnostic = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(diagnostic)


class DiagnosticTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = pathlib.Path(self.temporary.name)/'project'
        self.root.mkdir()
        (self.root/'.git').mkdir()
        (self.root/'config').mkdir()
        for name in diagnostic.FILES:
            if name in diagnostic.OPTIONAL_FILES:
                continue
            path = self.root/name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('secret-content-must-never-be-read')
        (self.root/'config/app.php').write_text('secret-config-value')
        self.uid = os.getuid()

    def run_probe(self):
        # The implementation has no reason to open anything, including FIFOs.
        with mock.patch('builtins.open', side_effect=AssertionError('content read')), \
             mock.patch.object(os, 'open', side_effect=AssertionError('content open')), \
             mock.patch.object(pathlib.Path, 'read_bytes', side_effect=AssertionError('content read')), \
             mock.patch.object(pathlib.Path, 'read_text', side_effect=AssertionError('content read')):
            return diagnostic.inspect(self.root, self.uid)

    def test_clean_optional_absent_and_no_reads(self):
        result = self.run_probe()
        self.assertEqual(result['status'], 'NO_OWNER_OR_TYPE_ISSUES_FOUND')
        self.assertEqual(result['issues'], [])
        self.assertTrue(result['config_scan_complete'])
        self.assertNotIn('secret', json.dumps(result))

    def test_reports_wrong_owner_without_root_privileges(self):
        target = self.root/'config/app.php'
        original = diagnostic._metadata
        def fake_metadata(path):
            info = original(path)
            if pathlib.Path(path) == target:
                return types.SimpleNamespace(st_uid=self.uid+1234, st_gid=info.st_gid, st_mode=info.st_mode)
            return info
        with mock.patch.object(diagnostic, '_metadata', side_effect=fake_metadata):
            result = self.run_probe()
        self.assertEqual(result['status'], 'ISSUES_FOUND')
        self.assertEqual(result['issues'], [{'path': 'config/app.php', 'reason': 'OWNER_OR_TYPE',
            'uid': self.uid+1234, 'gid': target.lstat().st_gid,
            'mode': format(target.lstat().st_mode & 0o7777, '04o'), 'type': 'regular'}])

    def test_file_symlink_is_reported_without_following_it(self):
        target = self.root/'config/app.php'
        target.unlink()
        target.symlink_to('/unreadable/nonexistent/customer-data')
        result = self.run_probe()
        issue = next(i for i in result['issues'] if i['path'] == 'config/app.php')
        self.assertEqual(issue['type'], 'symlink')
        self.assertNotIn('customer-data', json.dumps(result))

    def test_config_symlink_is_never_listed(self):
        (self.root/'config/app.php').unlink()
        (self.root/'config').rmdir()
        (self.root/'config').symlink_to('/unreadable/nonexistent/private-directory')
        with mock.patch.object(pathlib.Path, 'iterdir', side_effect=AssertionError('link traversal')):
            result = self.run_probe()
        self.assertEqual(result['status'], 'INCOMPLETE')
        self.assertTrue(any(i['path'] == 'config' and i['type'] == 'symlink' for i in result['issues']))

    def test_root_symlink_blocks_all_project_traversal(self):
        alias = self.root.parent/'alias'
        alias.symlink_to(self.root, target_is_directory=True)
        with mock.patch.object(pathlib.Path, 'iterdir', side_effect=AssertionError('link traversal')):
            result = diagnostic.inspect(alias, self.uid)
        self.assertEqual(result['status'], 'INCOMPLETE')
        self.assertEqual(result['issues'][0]['type'], 'symlink')
        self.assertEqual(result['checked'], 1)

    def test_foreign_project_ancestor_is_deduplicated(self):
        target = self.root/'app/Services'
        original = diagnostic._metadata
        def fake_metadata(path):
            info = original(path)
            if pathlib.Path(path) == target:
                return types.SimpleNamespace(st_uid=self.uid+1234, st_gid=info.st_gid, st_mode=info.st_mode)
            return info
        with mock.patch.object(diagnostic, '_metadata', side_effect=fake_metadata):
            result = self.run_probe()
        matching = [i for i in result['issues'] if i['path'] == 'app/Services']
        self.assertEqual(len(matching), 1)
        self.assertEqual(matching[0]['reason'], 'ANCESTOR_OWNER_OR_TYPE')

    @unittest.skipUnless(hasattr(os, 'mkfifo'), 'requires FIFO support')
    def test_fifo_does_not_block_or_get_opened(self):
        target = self.root/'config/app.php'
        target.unlink()
        os.mkfifo(target)
        result = self.run_probe()
        self.assertEqual(result['issues'][0]['type'], 'fifo')

    def test_directory_cannot_masquerade_as_code_file(self):
        target = self.root/'config/app.php'
        target.unlink()
        target.mkdir()
        self.assertEqual(self.run_probe()['issues'][0]['type'], 'directory')

    def test_missing_required_file_is_reported(self):
        (self.root/'.env').unlink()
        self.assertIn({'path': '.env', 'reason': 'REQUIRED_PATH_MISSING'}, self.run_probe()['issues'])

    def test_bounded_config_scan_reports_incomplete(self):
        with mock.patch.object(diagnostic, 'MAX_CONFIG_ENTRIES', 0):
            result = self.run_probe()
        self.assertEqual(result['status'], 'INCOMPLETE')
        self.assertIn({'path': 'config', 'reason': 'CONFIG_ENTRY_LIMIT_REACHED'}, result['issues'])

    def test_cli_rejects_root_and_arguments_without_printing_input(self):
        for args in ([], ['--root', '/private/SECRET'], ['--root', '/home/fasakha/public_html', '--SECRET']):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                code = diagnostic.main(args)
            result = json.loads(output.getvalue())
            self.assertEqual(code, 1)
            self.assertEqual(result['status'], 'FAILED')
            self.assertNotIn('SECRET', output.getvalue())

    def test_cli_suppresses_exception_text(self):
        output = io.StringIO()
        with mock.patch.object(diagnostic.pwd, 'getpwnam', side_effect=RuntimeError('SECRET')), \
             contextlib.redirect_stdout(output):
            code = diagnostic.main(['--root', str(diagnostic.APP_ROOT)])
        self.assertEqual(code, 1)
        self.assertEqual(json.loads(output.getvalue())['reason'], 'DIAGNOSTIC_STOPPED')
        self.assertNotIn('SECRET', output.getvalue())


if __name__ == '__main__':
    unittest.main()
