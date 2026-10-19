#!/usr/bin/env python3
"""Source preflight must fail before maintenance without editing application files."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/launch_reset'))
from bonus_owner_patch import SourcePatchError, failure_details, install, preflight

PHP = os.environ.get('LAUNCH_TEST_PHP') or shutil.which('php')


class SourcePreflightTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory(prefix='launch-preflight-tests-')
        self.root = Path(self.folder.name)
        self.path = self.root / 'service.php'
        self.original = b'<?php function existing_service() { return 1; }\n'
        self.path.write_bytes(self.original)

    def tearDown(self):
        self.folder.cleanup()

    def snapshot(self):
        return {str(path.relative_to(self.root)): path.read_bytes()
                for path in self.root.rglob('*') if path.is_file()}

    @unittest.skipUnless(PHP, 'PHP runtime required')
    def test_check_is_read_only_and_supports_already_patched_source(self):
        before = self.snapshot()
        transform = lambda text: text.replace('return 1;', 'return 2;')
        specs = [('service.php', transform)]
        report = preflight(self.root, PHP, specs)
        self.assertEqual(before, self.snapshot())
        self.assertNotEqual(report['service.php']['before'], report['service.php']['after'])
        install(self.root, self.root / 'backup', PHP, specs)
        installed = self.snapshot()
        resumed = preflight(self.root, PHP, specs)
        self.assertEqual(installed, self.snapshot())
        self.assertEqual(resumed['service.php']['before'], resumed['service.php']['after'])

    def test_transform_failure_reports_only_path_phase_code_and_digest(self):
        secret = 'PRIVATE_SOURCE_SENTINEL'
        self.path.write_text('<?php // ' + secret)
        before = self.snapshot()
        def fail(text):
            return text[text.index('missing static anchor'):]
        with self.assertRaises(SourcePatchError) as caught:
            preflight(self.root, PHP or 'php', [('service.php', fail)])
        detail = json.loads(failure_details(caught.exception))
        self.assertEqual(detail, {'path': 'service.php', 'phase': 'transform',
            'code': 'ANCHOR_NOT_FOUND', 'source_sha256': hashlib.sha256(self.path.read_bytes()).hexdigest()})
        self.assertNotIn(secret, failure_details(caught.exception))
        self.assertEqual(before, self.snapshot())

    def test_unknown_exception_message_is_not_exposed(self):
        message = 'PRIVATE_RUNTIME_VALUE'
        self.assertNotIn(message, failure_details(RuntimeError(message)))
        error = SourcePatchError('service.php', 'transform', ValueError('private source: ' + message))
        self.assertEqual(json.loads(failure_details(error))['code'], 'SOURCE_TRANSFORM_FAILED')
        self.assertNotIn(message, failure_details(error))

    @unittest.skipUnless(PHP, 'PHP runtime required')
    def test_invalid_php_rejected_before_backup_or_source_changes(self):
        before = self.snapshot()
        specs = [('service.php', lambda text: text + ' function broken( {')]
        with self.assertRaises(SourcePatchError) as caught:
            install(self.root, self.root / 'backup', PHP, specs)
        detail = json.loads(failure_details(caught.exception))
        self.assertEqual(detail['phase'], 'syntax')
        self.assertEqual(detail['code'], 'SOURCE_SYNTAX_CHECK_FAILED')
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.root / 'backup').exists())

    @unittest.skipUnless(PHP, 'PHP runtime required')
    def test_node_is_optional(self):
        javascript = self.root / 'view.js'
        javascript.write_text('function valid() { return 1; }\n')
        before = self.snapshot()
        with patch('bonus_owner_patch.shutil.which', return_value=None):
            preflight(self.root, PHP, [('service.php', lambda text: text), ('view.js', lambda text: text)])
        self.assertEqual(before, self.snapshot())

    def test_wrapper_preflights_all_patches_before_maintenance(self):
        wrapper = ROOT / 'deployment/apply_actual_launch_reset_root.sh'
        subprocess.run(['bash', '-n', str(wrapper)], check=True)
        source = wrapper.read_text()
        before_maintenance = source[:source.index("printf '%s\\n' 'ENTERING_MAINTENANCE")]
        for name in ('bonus_owner_patch.py', 'customer_global_patch.py', 'employee_profile_patch.py'):
            self.assertIn('"$launch_tmp/' + name + '"', before_maintenance)
        self.assertEqual(before_maintenance.count('--check'), 4)
        self.assertIn('"$launch_tmp/worker_control.py"', before_maintenance)
        schema_check = '"$launch_tmp/check_schema.php" --strict "$launch_root"'
        self.assertIn(schema_check, before_maintenance)
        self.assertLess(before_maintenance.index(schema_check), before_maintenance.rindex('"$launch_tmp/worker_control.py"'))
        self.assertIn('"$launch_tmp/bonus_owner_patch.py" \\\n  --root "$launch_root" --php "$launch_php" --check', before_maintenance)
        self.assertIn('"$launch_tmp/customer_global_patch.py" \\\n  --root "$launch_root" --php "$launch_php" --check', before_maintenance)


if __name__ == '__main__':
    unittest.main()
